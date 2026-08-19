<?php
/**
 * BaraTables import subsystem.
 *
 * Turns a table export from another plugin into a BaraTables definition. A single detector
 * sniffs the uploaded file's format, dispatches to a per-format adapter, and the adapters
 * funnel manual/static tables through one normalized representation -> custom_data builder.
 * Query-based exports (WP-Posts) bypass the normalizer and build a wp_query definition directly.
 *
 * No vendor names are surfaced to users; format ids here are internal wire identifiers only.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Small shared helpers used across adapters.
 */
class BaraTables_Import_Util {
	/** PHP 7.4-compatible equivalent of array_is_list(). */
	public static function is_list(array $value): bool {
		$expected = 0;
		foreach ($value as $key => $_item) {
			if ($key !== $expected) {
				return false;
			}
			$expected++;
		}
		return true;
	}

	/** Preserve nested export values as readable cell text instead of silently erasing them. */
	public static function stringify_cell($value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_scalar($value)) {
			return (string) $value;
		}
		if (!is_array($value)) {
			return '';
		}
		$parts = [];
		foreach ($value as $item) {
			$parts[] = self::stringify_cell($item);
		}
		return implode(', ', array_values(array_filter($parts, static function ($part) {
			return $part !== '';
		})));
	}

	public static function to_bool($value, bool $default = false): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return (int) $value !== 0;
		}
		$clean = strtolower(trim((string) $value));
		if ($clean === '') {
			return $default;
		}
		if (in_array($clean, ['1', 'true', 'yes', 'y', 'on'], true)) {
			return true;
		}
		if (in_array($clean, ['0', 'false', 'no', 'n', 'off'], true)) {
			return false;
		}
		return $default;
	}

	public static function sort_dir($value, string $default = 'asc'): string {
		$clean = sanitize_key((string) $value);
		return $clean === 'desc' ? 'desc' : $default;
	}

	/** Strip a leading BOM and normalize to UTF-8 so cells don't mojibake. */
	public static function normalize_text(string $raw): string {
		// The mb_convert_encoding() calls in this method are silenced on purpose. The input is an
		// arbitrary uploaded file, so a malformed byte sequence is an expected outcome rather than
		// a programming error; the function warns on those, and each call site already handles a
		// failed conversion by falling back to the original bytes. Letting the warning through
		// would print PHP notices over the admin screen for a file the user simply mis-saved.
		if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
			$raw = substr($raw, 3);
		} elseif (strncmp($raw, "\xFF\xFE", 2) === 0 && function_exists('mb_convert_encoding')) {
			// UTF-16 little-endian (BOM FF FE) -- used by some exporters; convert before parsing.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted upload may be malformed.
			$raw = (string) @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
		} elseif (strncmp($raw, "\xFE\xFF", 2) === 0 && function_exists('mb_convert_encoding')) {
			// UTF-16 big-endian (BOM FE FF).
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted upload may be malformed.
			$raw = (string) @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
		}
		if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8') && function_exists('mb_convert_encoding')) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted upload may be malformed; failure falls back below.
			$converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
			if (is_string($converted) && $converted !== '') {
				$raw = $converted;
			}
		}
		return $raw;
	}
}

/**
 * The NormalizedTable contract is a plain associative array produced by manual-data adapters:
 *   [
 *     'name'        => string,
 *     'columns'     => string[]   header labels, positional (may be [])
 *     'rows'        => array[]     body rows, each a positional array of cell strings (pre-sanitize)
 *     'has_header'  => bool        false => synthesize labels, treat every row as body
	 *     'settings'    => [ page_length:int|null, paging:bool|null, search:bool|null,
	 *                        ordering:bool|null, length_change:bool|null, info:bool|null,
	 *                        scroll_x:bool|null, scroll_y_enabled:bool|null, scroll_y:string|null,
	 *                        responsive:bool|null, stripe:bool|null, hover:bool|null,
	 *                        sort_column_index:int|null, sort_direction:'asc'|'desc'|null ]
 *     'column_meta' => array[]     positional BaraTables column-record fields
 *     'warnings'    => string[]
 *   ]
 *
 * The builder is the ONE place that turns it into a custom_data definition.
 */
class BaraTables_Import_Builder {

	public static function blank_settings(): array {
		return [
			'page_length' => null,
			'paging' => null,
			'search' => null,
			'ordering' => null,
			'length_change' => null,
			'info' => null,
			'scroll_x' => null,
			'scroll_y_enabled' => null,
			'scroll_y' => null,
			'responsive' => null,
			'stripe' => null,
			'hover' => null,
			'sort_column_index' => null,
			'sort_direction' => null,
		];
	}

	/** Create the one normalized-table contract shared by every manual-data adapter. */
	public static function normalized(string $name, array $columns, array $rows, bool $has_header, array $settings = [], array $warnings = [], array $column_meta = []): array {
		return [
			'name' => $name,
			'columns' => array_values($columns),
			'rows' => array_values($rows),
			'has_header' => $has_header,
			'settings' => array_merge(self::blank_settings(), $settings),
			'column_meta' => array_values($column_meta),
			'warnings' => array_values($warnings),
		];
	}

	/** Cheap shape check used when only the number of importable tables is needed. */
	public static function is_usable_normalized(array $norm): bool {
		if (!empty($norm['columns']) && is_array($norm['columns'])) {
			return true;
		}
		foreach (isset($norm['rows']) && is_array($norm['rows']) ? $norm['rows'] : [] as $row) {
			if (is_array($row) && !empty($row)) {
				return true;
			}
		}
		return false;
	}

	/** Apply the normalized importer settings contract to a table-options array. */
	public static function apply_table_settings(array $table_options, array $settings): array {
		$settings = array_merge(self::blank_settings(), $settings);
		if ($settings['page_length'] !== null && (int) $settings['page_length'] > 0) {
			$table_options['pageLength'] = (int) $settings['page_length'];
		}
		foreach (['paging' => 'paging', 'search' => 'searchBox', 'ordering' => 'ordering'] as $setting_key => $option_key) {
			if ($settings[$setting_key] !== null) {
				$table_options[$option_key] = (bool) $settings[$setting_key];
			}
		}
		foreach (
			[
				'length_change' => 'lengthChange',
				'info' => 'info',
				'scroll_x' => 'scrollX',
				'scroll_y_enabled' => 'scrollYEnabled',
				'responsive' => 'responsive',
				'stripe' => 'stripe',
				'hover' => 'hover',
			] as $setting_key => $option_key
		) {
			if ($settings[$setting_key] !== null) {
				$table_options[$option_key] = (bool) $settings[$setting_key];
			}
		}
		if ($settings['scroll_y'] !== null && (int) $settings['scroll_y'] > 0) {
			$table_options['scrollY'] = (int) $settings['scroll_y'];
		}
		return $table_options;
	}

	/**
	 * Build a ready-to-persist custom_data definition (id left blank for the controller to fill).
	 *
	 * @return array{definition:array,warnings:string[]}
	 */
	public static function from_normalized(array $norm, BaraTables_Service $service): array {
		$warnings = isset($norm['warnings']) && is_array($norm['warnings']) ? array_values($norm['warnings']) : [];
		$has_header = !empty($norm['has_header']);
		$labels_in = isset($norm['columns']) && is_array($norm['columns']) ? array_values($norm['columns']) : [];
		$rows_in = isset($norm['rows']) && is_array($norm['rows']) ? array_values($norm['rows']) : [];
		$settings = array_merge(self::blank_settings(), isset($norm['settings']) && is_array($norm['settings']) ? $norm['settings'] : []);
		$column_meta = isset($norm['column_meta']) && is_array($norm['column_meta']) ? array_values($norm['column_meta']) : [];

		// Width = widest of the header and any row, so ragged rows are padded, not dropped.
		$width = $has_header ? count($labels_in) : 0;
		foreach ($rows_in as $row) {
			if (is_array($row)) {
				$width = max($width, count($row));
			}
		}
		if ($width <= 0) {
			$width = 1;
		}
		$pre_cols = $width;
		$pre_rows = count($rows_in);

		// The cell budget is applied here as well as in build_custom_dataset(). It has to be:
		// that method clamps rows to intdiv(MAX_CUSTOM_CELLS, $column_count) regardless, so
		// computing it up front is what keeps the "only the first N of M rows" warning below
		// reporting the number of rows actually kept rather than a larger number that is then
		// silently trimmed downstream.
		$cols_count = min($width, BaraTables_Service::MAX_CUSTOM_COLUMNS);
		$rows_count = min(
			count($rows_in),
			BaraTables_Service::MAX_CUSTOM_ROWS,
			max(1, intdiv(BaraTables_Service::MAX_CUSTOM_CELLS, $cols_count))
		);

		// Labels: when there is no header row, leave them blank so render supplies "Column N".
		$labels = [];
		for ($i = 0; $i < $cols_count; $i++) {
			$labels[] = $has_header ? (string) ($labels_in[$i] ?? '') : '';
		}

		$dataset = $service->build_custom_dataset($labels, $rows_in, $rows_count, $cols_count);
		$clean_labels = $dataset['columns'];
		// A header-only / empty source has no body rows; build_custom_dataset would otherwise
		// synthesize the new-table default of 5 blank rows. Import it as an empty (header-only)
		// table instead, so the preview row count is accurate and the user isn't handed phantom rows.
		$clean_rows = empty($rows_in) ? [] : $dataset['rows'];

		$columns = [];
		for ($i = 0; $i < $cols_count; $i++) {
			$label = (string) ($clean_labels[$i] ?? '');
			$key = 'col_' . ($i + 1);
			$slug = BaraTables_Service::build_slug('custom', $key);
			$auto_label = trim(wp_strip_all_tags($label)) === '';
			$record = isset($column_meta[$i]) && is_array($column_meta[$i]) ? $column_meta[$i] : [];
			$record['slug'] = $slug;
			$record['label'] = $label;
			$record['auto_label'] = $auto_label;
			if (!array_key_exists('filter_label', $record)) {
				$record['filter_label'] = $label;
			}
			$columns[] = $service->normalize_column_record($record);
		}

		$table_options = self::apply_table_settings($service->get_default_table_options(), $settings);

		// Default sort column (0-based), if the source declared one and ordering is on.
		$sort_idx = $settings['sort_column_index'];
		if (
			$sort_idx !== null
			&& isset($columns[(int) $sort_idx])
			&& !empty($table_options['ordering'])
			&& !empty($columns[(int) $sort_idx]['sortable'])
		) {
			$columns[(int) $sort_idx]['sort_enabled'] = true;
			$columns[(int) $sort_idx]['sort_priority'] = 1;
			$columns[(int) $sort_idx]['sort_direction'] = $settings['sort_direction'] === 'desc' ? 'desc' : 'asc';
		}

		if ($pre_cols > $cols_count) {
			$warnings[] = sprintf(
				/* translators: 1: number of columns kept, 2: number of columns in the source file. */
				__('Only the first %1$d of %2$d columns were imported (maximum %1$d).', 'baratables'),
				$cols_count,
				$pre_cols
			);
		}
		if ($pre_rows > $rows_count) {
			$warnings[] = sprintf(
				/* translators: 1: number of rows kept, 2: number of rows in the source file. */
				__('Only the first %1$d of %2$d rows were imported (maximum %1$d).', 'baratables'),
				$rows_count,
				$pre_rows
			);
		}

		$name = isset($norm['name']) ? sanitize_text_field((string) $norm['name']) : '';
		if ($name === '') {
			$name = __('Imported Table', 'baratables');
		}

		$definition = [
			'id' => '',
			'name' => $name,
			'status' => 'publish',
			'source_type' => BaraTables_Source_Type::CUSTOM_DATA,
			'post_type' => 'post',
			'post_types' => [],
			'columns' => $columns,
			'custom_data' => [
				'columns' => $clean_labels,
				'rows' => $clean_rows,
			],
			'table_options' => $table_options,
			'filter_order' => array_values(array_map(static function ($column) {
				return (string) $column['slug'];
			}, array_filter($columns, static function ($column) {
				return isset($column['filter']) && $column['filter'] !== 'none';
			}))),
			'access_control' => [],
			'value_overrides' => [],
		];

		return ['definition' => $definition, 'warnings' => $warnings];
	}
}

/**
 * TablePress full-JSON and simple bare-array exports -> custom_data.
 */
class BaraTables_Import_TablePress {
	private const SPAN_MARKERS = ['#colspan#', '#rowspan#', '#span#'];

	public static function to_normalized(array $decoded, bool $is_simple): array {
		$warnings = [];
		$name = isset($decoded['name']) ? (string) $decoded['name'] : '';

		if ($is_simple) {
			// Bare top-level array of rows; no options/visibility, no header convention.
			$grid = self::clean_grid(array_values($decoded));
			return BaraTables_Import_Builder::normalized($name, [], $grid, false, [], $warnings);
		}

		$data = isset($decoded['data']) && is_array($decoded['data']) ? array_values($decoded['data']) : [];
		$options = isset($decoded['options']) && is_array($decoded['options']) ? $decoded['options'] : [];
		$visibility = isset($decoded['visibility']) && is_array($decoded['visibility']) ? $decoded['visibility'] : [];

		$col_vis = isset($visibility['columns']) && is_array($visibility['columns']) ? array_values($visibility['columns']) : [];
		$row_vis = isset($visibility['rows']) && is_array($visibility['rows']) ? array_values($visibility['rows']) : [];

		$table_head = isset($options['table_head']) ? (int) $options['table_head'] : 1;
		$table_foot = isset($options['table_foot']) ? (int) $options['table_foot'] : 0;
		$has_header = $table_head >= 1;
		$total_rows = count($data);
		// Footer rows are the last $table_foot ORIGINAL rows.
		$foot_start = $table_foot > 0 ? max(0, $total_rows - $table_foot) : $total_rows;
		$dropped_row = false;
		$preserved_footer = false;
		$had_spans = false;
		$had_formulas = false;
		$had_shortcodes = false;
		$column_meta = [];
		foreach ($col_vis as $c => $visible) {
			$column_meta[$c] = ['hidden' => (int) $visible === 0];
		}

		// Classify rows by their original indexes before removing hidden rows. This prevents a hidden
		// header from promoting a body row. Hidden columns remain in column_meta for BaraTables.
		$header_rows = [];
		$body = [];
		foreach ($data as $r => $row) {
			if (!is_array($row)) {
				continue;
			}
			if (isset($row_vis[$r]) && (int) $row_vis[$r] === 0) {
				$dropped_row = true;
				continue;
			}
			$out_row = [];
			foreach (array_values($row) as $c => $cell) {
				self::scan_cell($cell, $had_spans, $had_formulas, $had_shortcodes);
				$out_row[] = self::clean_cell($cell);
			}
			if ($has_header && $r < $table_head) {
				$header_rows[] = $out_row;
			} elseif ($r >= $foot_start) {
				// BaraTables has no footer-row semantic. Keeping the values as ordinary rows is
				// safer than silently deleting data, and the warning below makes the change clear.
				$body[] = $out_row;
				$preserved_footer = true;
			} else {
				$body[] = $out_row;
			}
		}

		$labels = [];
		if ($has_header && !empty($header_rows)) {
			$labels = $header_rows[0];
			if ($table_head > 1) {
				$warnings[] = __('The source had multiple header rows; the first was used as the column headings.', 'baratables');
			}
		}

		if ($dropped_row) {
			$warnings[] = __('Hidden rows from the source were skipped.', 'baratables');
		}
		if ($preserved_footer) {
			$warnings[] = __('Footer rows were preserved as regular table rows.', 'baratables');
		}
		if ($had_spans) {
			$warnings[] = __('Merged cells were flattened because BaraTables does not support row or column spans.', 'baratables');
		}
		if ($had_formulas) {
			$warnings[] = __('Formula text was preserved, but BaraTables does not calculate spreadsheet formulas.', 'baratables');
		}
		if ($had_shortcodes) {
			$warnings[] = __('Shortcode text was preserved, but shortcodes inside imported cells are not executed.', 'baratables');
		}

		return BaraTables_Import_Builder::normalized($name, $labels, $body, $has_header, self::map_settings($options), $warnings, $column_meta);
	}

	private static function map_settings(array $options): array {
		$settings = BaraTables_Import_Builder::blank_settings();
		foreach (['alternating_row_colors' => 'stripe', 'row_hover' => 'hover'] as $source_key => $setting_key) {
			if (array_key_exists($source_key, $options)) {
				$settings[$setting_key] = BaraTables_Import_Util::to_bool($options[$source_key], false);
			}
		}
		$use_dt = array_key_exists('use_datatables', $options) ? BaraTables_Import_Util::to_bool($options['use_datatables'], true) : true;
		if (!$use_dt) {
			$settings['paging'] = false;
			$settings['search'] = false;
			$settings['ordering'] = false;
			$settings['length_change'] = false;
			$settings['info'] = false;
			$settings['scroll_x'] = false;
			$settings['scroll_y_enabled'] = false;
			$settings['responsive'] = false;
			return $settings;
		}
		if (array_key_exists('datatables_paginate', $options)) {
			$settings['paging'] = BaraTables_Import_Util::to_bool($options['datatables_paginate'], true);
		}
		if (array_key_exists('datatables_paginate_entries', $options)) {
			$entries = (int) $options['datatables_paginate_entries'];
			if ($entries > 0) {
				$settings['page_length'] = $entries;
			}
		}
		if (array_key_exists('datatables_filter', $options)) {
			$settings['search'] = BaraTables_Import_Util::to_bool($options['datatables_filter'], true);
		}
		if (array_key_exists('datatables_sort', $options)) {
			$settings['ordering'] = BaraTables_Import_Util::to_bool($options['datatables_sort'], true);
		}
		foreach (
			[
				'datatables_lengthchange' => 'length_change',
				'datatables_info' => 'info',
				'datatables_scrollx' => 'scroll_x',
			] as $source_key => $setting_key
		) {
			if (array_key_exists($source_key, $options)) {
				$settings[$setting_key] = BaraTables_Import_Util::to_bool($options[$source_key], false);
			}
		}
		// TablePress stores responsive as a breakpoint keyword ('none', '', 'phone', 'tablet',
		// 'phone-tablet', 'all', ...) rather than a boolean; every value that names a breakpoint
		// means stacking was on.
		if (array_key_exists('datatables_responsive', $options)) {
			$responsive = $options['datatables_responsive'];
			$settings['responsive'] = is_string($responsive)
				? !in_array($responsive, ['', 'none', 'false'], true)
				: BaraTables_Import_Util::to_bool($responsive, false);
		}
		if (isset($options['datatables_scrolly']) && $options['datatables_scrolly'] !== false) {
			$scroll_y = trim((string) $options['datatables_scrolly']);
			if (preg_match('/^(\d+)(?:px)?$/i', $scroll_y, $matches)) {
				$settings['scroll_y_enabled'] = true;
				$settings['scroll_y'] = max(1, min(2000, (int) $matches[1]));
			}
		}
		return $settings;
	}

	private static function scan_cell($cell, bool &$had_spans, bool &$had_formulas, bool &$had_shortcodes): void {
		if (!is_scalar($cell)) {
			return;
		}
		$value = (string) $cell;
		foreach (self::SPAN_MARKERS as $marker) {
			if (strpos($value, $marker) !== false) {
				$had_spans = true;
				break;
			}
		}
		if (preg_match('/^\s*=/', $value)) {
			$had_formulas = true;
		}
		if (preg_match('/\[[A-Za-z][A-Za-z0-9_-]*(?:\s|\]|\/)/', $value)) {
			$had_shortcodes = true;
		}
	}

	private static function clean_grid(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$clean = [];
			foreach (array_values($row) as $cell) {
				$clean[] = self::clean_cell($cell);
			}
			$out[] = $clean;
		}
		return $out;
	}

	private static function clean_cell($cell): string {
		return str_replace(self::SPAN_MARKERS, '', BaraTables_Import_Util::stringify_cell($cell));
	}
}

/**
 * Ninja Tables classic export. Manual (data_provider='default') static tables -> custom_data;
 * WP-Posts query tables -> wp_query.
 */
class BaraTables_Import_NinjaTables {
	/** @return array{name:string,columns:array,rows:array,has_header:bool,settings:array,warnings:array} */
	public static function to_normalized(array $decoded): array {
		$columns = isset($decoded['columns']) && is_array($decoded['columns']) ? array_values($decoded['columns']) : [];
		$labels = [];
		$keys = [];
		$column_meta = [];
		foreach ($columns as $i => $col) {
			if (!is_array($col)) {
				continue;
			}
			$key = isset($col['key']) && $col['key'] !== '' ? (string) $col['key'] : ('col_' . ($i + 1));
			$keys[] = $key;
			$name = isset($col['name']) && $col['name'] !== '' ? (string) $col['name'] : $key;
			$labels[] = $name;
			$date_format = self::convert_ninja_date_format(isset($col['dateFormat']) ? (string) $col['dateFormat'] : '');
			$show_time = BaraTables_Import_Util::to_bool($col['showTime'] ?? false, false);
			if ($show_time) {
				$time_format = self::convert_ninja_time_format(isset($col['timeFormat']) ? (string) $col['timeFormat'] : '');
				if ($time_format !== '') {
					$date_format = trim($date_format !== '' ? ($date_format . ' ' . $time_format) : $time_format);
				}
			}
			$breakpoints = isset($col['breakpoints']) ? strtolower((string) $col['breakpoints']) : '';
			$column_meta[] = [
				'hidden' => preg_match('/\bhidden\b/', $breakpoints) === 1,
				'sortable' => !BaraTables_Import_Util::to_bool($col['unsortable'] ?? false, false),
				'searchable' => !BaraTables_Import_Util::to_bool($col['unsearchable'] ?? false, false),
				'format_date' => (($col['data_type'] ?? '') === 'date') || $date_format !== '',
				'date_format' => $date_format,
			];
		}

		$rows = [];
		$source_rows = [];
		if (!empty($decoded['original_rows']) && is_array($decoded['original_rows'])) {
			$source_rows = $decoded['original_rows'];
		} elseif (!empty($decoded['rows']) && is_array($decoded['rows'])) {
			$source_rows = $decoded['rows'];
		}
		foreach ($source_rows as $row) {
			$value = is_array($row) && array_key_exists('value', $row) ? $row['value'] : $row;
			if (is_string($value)) {
				$decoded_value = json_decode($value, true);
				$value = is_array($decoded_value) ? $decoded_value : [];
			}
			if (!is_array($value)) {
				$value = [];
			}
			$cells = [];
			foreach ($keys as $key) {
				$cell = $value[$key] ?? '';
				$cells[] = BaraTables_Import_Util::stringify_cell($cell);
			}
			$rows[] = $cells;
		}

		$settings_raw = isset($decoded['settings']) && is_array($decoded['settings']) ? $decoded['settings'] : [];
		$settings = self::map_display_settings($settings_raw, $keys);
		self::apply_manual_filters($column_meta, $keys, $decoded['metas'] ?? []);

		$name = isset($decoded['post']['post_title']) ? (string) $decoded['post']['post_title'] : '';
		$warnings = [];
		if (!array_key_exists('data_provider', $decoded)) {
			$warnings[] = __('The export did not identify its data source, so it was imported safely as a manual table.', 'baratables');
		}

		return BaraTables_Import_Builder::normalized($name, $labels, $rows, true, $settings, $warnings, $column_meta);
	}

	/**
	 * WP-Posts query export -> wp_query definition (id left blank). Boolean display settings are
	 * read through BaraTables_Import_Util::to_bool() so a string "false" is not treated as true.
	 *
	 * @return array{definition:array}|array{error:string}
	 */
	public static function to_wpposts_definition(array $export, BaraTables_Service $service): array {
		$columns = $export['columns'] ?? [];
		if (!is_array($columns) || empty($columns)) {
			return ['error' => __('No columns found to import.', 'baratables')];
		}
		$settings = isset($export['settings']) && is_array($export['settings']) ? $export['settings'] : [];
		$title = isset($export['post']['post_title']) ? sanitize_text_field((string) $export['post']['post_title']) : '';
		$name = $title !== '' ? $title : __('Imported Table', 'baratables');
		$table_options = BaraTables_Import_Builder::apply_table_settings(
			$service->get_default_table_options(),
			self::map_display_settings($settings)
		);
		$mapped = self::map_wpposts_columns($columns, $service);
		$mapped_columns = $mapped['columns'];
		$column_key_map = $mapped['key_map'];
		if (empty($mapped_columns)) {
			return ['error' => __('No valid columns were mapped from the export.', 'baratables')];
		}

		$post_types = self::map_wpposts_post_types($export['metas'] ?? []);
		$mapped_columns = self::apply_wpposts_sort($mapped_columns, $column_key_map, $settings, $table_options);
		$filter_result = self::map_wpposts_filters($mapped_columns, $column_key_map, $export['metas'] ?? []);

		$definition = [
			'id' => '',
			'name' => $name,
			'post_type' => $post_types[0],
			'post_types' => $post_types,
			'source_type' => 'wp_query',
			'columns' => $filter_result['columns'],
			'table_options' => $table_options,
			'filter_order' => $filter_result['filter_order'],
			'status' => 'publish',
		];

		return ['definition' => $definition];
	}

	private static function map_display_settings(array $settings_raw, array $column_keys = []): array {
		$settings = BaraTables_Import_Builder::blank_settings();
		if (isset($settings_raw['perPage']) && (int) $settings_raw['perPage'] > 0) {
			$settings['page_length'] = (int) $settings_raw['perPage'];
		}
		if (array_key_exists('enable_search', $settings_raw)) {
			$settings['search'] = BaraTables_Import_Util::to_bool($settings_raw['enable_search'], true);
		}
		if (array_key_exists('column_sorting', $settings_raw)) {
			$settings['ordering'] = BaraTables_Import_Util::to_bool($settings_raw['column_sorting'], true);
		}
		if (array_key_exists('show_all', $settings_raw)) {
			$settings['paging'] = !BaraTables_Import_Util::to_bool($settings_raw['show_all'], false);
		}
		$sorting_type = isset($settings_raw['sorting_type']) ? sanitize_key((string) $settings_raw['sorting_type']) : '';
		$sorting_column = isset($settings_raw['sorting_column']) ? (string) $settings_raw['sorting_column'] : '';
		if (($sorting_type === '' || $sorting_type === 'by_column') && $sorting_column !== '') {
			$sort_index = array_search($sorting_column, $column_keys, true);
			if ($sort_index !== false) {
				$settings['sort_column_index'] = (int) $sort_index;
				$settings['sort_direction'] = BaraTables_Import_Util::sort_dir($settings_raw['sorting_column_by'] ?? 'asc');
			}
		}
		return $settings;
	}

	private static function apply_manual_filters(array &$column_meta, array $keys, $metas): void {
		if (!is_array($metas) || empty($metas['_ninja_table_custom_filters']) || !is_array($metas['_ninja_table_custom_filters'])) {
			return;
		}
		foreach ($metas['_ninja_table_custom_filters'] as $filter) {
			if (!is_array($filter)) {
				continue;
			}
			$target = isset($filter['dynamic_select_column']) ? (string) $filter['dynamic_select_column'] : '';
			if ($target === '' && !empty($filter['columns']) && is_array($filter['columns'])) {
				$first = reset($filter['columns']);
				$target = is_scalar($first) ? (string) $first : '';
			}
			$index = array_search($target, $keys, true);
			if ($index === false || !isset($column_meta[(int) $index])) {
				continue;
			}
			$type = sanitize_key((string) ($filter['type'] ?? 'select'));
			$type_map = [
				'select' => 'dropdown',
				'dropdown' => 'dropdown',
				'multi-select' => 'dropdown_multi',
				'multiselect' => 'dropdown_multi',
				'checkbox' => 'checkbox',
				'radio' => 'radio',
			];
			if (!isset($type_map[$type])) {
				continue;
			}
			$column_meta[(int) $index]['filter'] = $type_map[$type];
			if (!empty($filter['title'])) {
				$column_meta[(int) $index]['filter_label'] = (string) $filter['title'];
			}
			if (!empty($filter['values']) && is_array($filter['values'])) {
				$manual_values = [];
				foreach ($filter['values'] as $value) {
					$text = BaraTables_Import_Util::stringify_cell($value);
					if ($text === '') {
						continue;
					}
					// The render path (build_filter_definitions) only reads the
					// label/value/search_terms shape -- the same shape the wp_query branch below
					// saves. A flat string list is silently skipped there, and the dropdown
					// falls back to the column's row values.
					$manual_values[] = ['label' => $text, 'value' => $text, 'search_terms' => [$text]];
				}
				if (!empty($manual_values)) {
					$column_meta[(int) $index]['filter_values'] = $manual_values;
				}
			}
		}
	}

	/** @return array{columns:array,key_map:array} */
	private static function map_wpposts_columns(array $columns, BaraTables_Service $service): array {
		$mapped_columns = [];
		$column_key_map = [];
		foreach ($columns as $col) {
			if (!is_array($col) || empty($col['name'])) {
				continue;
			}
			$label = sanitize_text_field((string) $col['name']);
			$source = 'core';
			$key = '';
			$source_type = isset($col['source_type']) ? sanitize_key((string) $col['source_type']) : '';
			if ($source_type === 'custom') {
				$source = 'meta';
				if (!empty($col['wp_post_custom_data_value'])) {
					// WordPress metadata keys are case-sensitive, free-form strings. Keep the
					// stored key byte-for-byte apart from the same control-character/tag cleanup
					// used by the manual metadata-key field. Ninja's filter/sort identifiers are
					// normalized separately in $column_key_map below.
					$key = sanitize_text_field((string) $col['wp_post_custom_data_value']);
				}
			} elseif ($source_type === 'tax_data') {
				$source = 'tax';
				if (!empty($col['original_name'])) {
					$key = (string) $col['original_name'];
				} elseif (!empty($col['key'])) {
					$key = (string) $col['key'];
				}
				$key = preg_replace('/^post\\./', '', (string) $key);
				$key = sanitize_key((string) $key);
			} else {
				if (!empty($col['original_name'])) {
					$key = sanitize_key((string) $col['original_name']);
				} elseif (!empty($col['key'])) {
					$key = sanitize_key((string) $col['key']);
				} else {
					$key = sanitize_key($label);
				}
			}
			if ($key === '') {
				$key = 'col_' . (count($mapped_columns) + 1);
			}
			$slug = BaraTables_Service::build_slug($source, $key);
			$is_date = isset($col['data_type']) && $col['data_type'] === 'date';
			$date_format_raw = isset($col['dateFormat']) ? (string) $col['dateFormat'] : '';
			$time_format_raw = isset($col['timeFormat']) ? (string) $col['timeFormat'] : '';
			$date_format = self::convert_ninja_date_format($date_format_raw);
			$time_format = self::convert_ninja_time_format($time_format_raw);
			$show_time = BaraTables_Import_Util::to_bool($col['showTime'] ?? false, false);
			if ($show_time && $time_format !== '') {
				$date_format = trim($date_format !== '' ? ($date_format . ' ' . $time_format) : $time_format);
			}
			$breakpoints = isset($col['breakpoints']) ? strtolower(trim((string) $col['breakpoints'])) : '';
			$hidden = $breakpoints !== '' && preg_match('/\\bhidden\\b/', $breakpoints);
			$unsortable = BaraTables_Import_Util::to_bool($col['unsortable'] ?? false, false);
			$mapped_columns[] = $service->normalize_column_record([
				'slug' => $slug,
				'label' => $label,
				'hide_title' => !empty($col['classes']) && strpos((string) $col['classes'], 'hide-title') !== false,
				'hidden' => $hidden,
				'sortable' => !$unsortable,
				'format_date' => $is_date || $date_format !== '',
				'date_format' => $date_format,
			]);
			$col_index = count($mapped_columns) - 1;
			$column_key_map[$key] = $col_index;
			$normalized_key = sanitize_key($key);
			if ($normalized_key !== '') {
				$column_key_map[$normalized_key] = $col_index;
			}
			if (!empty($col['key'])) {
				$column_key_map[sanitize_key((string) $col['key'])] = $col_index;
			}
			if (!empty($col['original_name'])) {
				$column_key_map[sanitize_key((string) $col['original_name'])] = $col_index;
			}
		}

		return ['columns' => $mapped_columns, 'key_map' => $column_key_map];
	}

	private static function map_wpposts_post_types($metas): array {
		$post_types = [];
		$metas = is_array($metas) ? $metas : [];
		if (!empty($metas['_ninja_table_wpposts_ds_post_types']) && is_array($metas['_ninja_table_wpposts_ds_post_types'])) {
			foreach ($metas['_ninja_table_wpposts_ds_post_types'] as $pt) {
				$clean = sanitize_key((string) $pt);
				if ($clean !== '') {
					$post_types[] = $clean;
				}
			}
		}
		if (empty($post_types)) {
			$post_types[] = 'post';
		}
		return $post_types;
	}

	private static function apply_wpposts_sort(array $mapped_columns, array $column_key_map, array $settings, array $table_options): array {
		$sorting_column = isset($settings['sorting_column']) ? sanitize_key((string) $settings['sorting_column']) : '';
		$sorting_direction = isset($settings['sorting_column_by'])
			? BaraTables_Import_Util::sort_dir($settings['sorting_column_by'], 'asc')
			: 'asc';
		$sorting_type = isset($settings['sorting_type']) ? sanitize_key((string) $settings['sorting_type']) : '';
		if ($sorting_type !== '' && $sorting_type !== 'by_column') {
			$sorting_column = '';
		}
		if (!empty($table_options['ordering']) && $sorting_column !== '' && isset($column_key_map[$sorting_column])) {
			$sort_idx = $column_key_map[$sorting_column];
			if (isset($mapped_columns[$sort_idx]) && !empty($mapped_columns[$sort_idx]['sortable'])) {
				$mapped_columns[$sort_idx]['sort_enabled'] = true;
				$mapped_columns[$sort_idx]['sort_priority'] = 1;
				$mapped_columns[$sort_idx]['sort_direction'] = $sorting_direction;
			}
		}
		return $mapped_columns;
	}

	/** @return array{columns:array,filter_order:array} */
	private static function map_wpposts_filters(array $mapped_columns, array $column_key_map, $metas): array {
		$filter_order = [];
		$metas = is_array($metas) ? $metas : [];
		$custom_filters = $metas['_ninja_table_custom_filters'] ?? [];
		if (is_array($custom_filters)) {
			foreach ($custom_filters as $filter) {
				if (!is_array($filter)) {
					continue;
				}
				$target_key = isset($filter['dynamic_select_column']) ? sanitize_key((string) $filter['dynamic_select_column']) : '';
				if ($target_key === '' && !empty($filter['columns']) && is_array($filter['columns'])) {
					$first = reset($filter['columns']);
					$target_key = sanitize_key((string) $first);
				}
				if ($target_key === '' || !isset($column_key_map[$target_key])) {
					continue;
				}
				$col_idx = $column_key_map[$target_key];
				if (!isset($mapped_columns[$col_idx])) {
					continue;
				}

				$filter_type = self::map_filter_type($filter);
				if ($filter_type !== '') {
					$mapped_columns[$col_idx]['filter'] = $filter_type;
				}

				$filter_label = isset($filter['title']) ? sanitize_text_field((string) $filter['title']) : '';
				if ($filter_label !== '') {
					$mapped_columns[$col_idx]['filter_label'] = $filter_label;
				}

				$disable_auto = BaraTables_Import_Util::to_bool($filter['disable_auto_sorting'] ?? false, false);
				$filter_sort = $disable_auto ? 'custom' : BaraTables_Import_Util::sort_dir($filter['sorting_type'] ?? 'asc', 'asc');
				$mapped_columns[$col_idx]['filter_sort'] = $filter_sort;

				$type_priority = self::map_filter_type_priority($filter['sorting_method'] ?? '');
				if (!empty($type_priority)) {
					$mapped_columns[$col_idx]['filter_type_priority'] = $type_priority;
				}

				$select_value_type = isset($filter['select_value_type']) ? sanitize_key((string) $filter['select_value_type']) : '';
				if ($select_value_type === 'manual' && !empty($filter['options']) && is_array($filter['options'])) {
					$manual_values = [];
					foreach ($filter['options'] as $option) {
						if (!is_array($option)) {
							continue;
						}
						$label_opt = isset($option['label']) ? sanitize_text_field((string) $option['label']) : '';
						$value_opt = isset($option['value']) ? sanitize_text_field((string) $option['value']) : '';
						if ($label_opt === '' && $value_opt === '') {
							continue;
						}
						if ($value_opt === '') {
							$value_opt = $label_opt;
						}
						if ($label_opt === '') {
							$label_opt = $value_opt;
						}
						$manual_values[] = [
							'label' => $label_opt,
							'value' => $value_opt,
							'search_terms' => [$label_opt, $value_opt],
						];
					}
					if (!empty($manual_values)) {
						$mapped_columns[$col_idx]['filter_values'] = $manual_values;
					}
				}

				$filter_order[] = $mapped_columns[$col_idx]['slug'];
			}
		}

		return [
			'columns' => $mapped_columns,
			'filter_order' => array_values(array_unique(array_filter($filter_order))),
		];
	}

	public static function convert_ninja_date_format(string $format): string {
		return self::convert_ninja_format($format, [
			'YYYY' => 'Y',
			'YY' => 'y',
			'MMMM' => 'F',
			'MMM' => 'M',
			'MM' => 'm',
			'M' => 'n',
			'DD' => 'd',
			'D' => 'j',
			'dddd' => 'l',
			'ddd' => 'D',
		]);
	}

	public static function convert_ninja_time_format(string $format): string {
		return self::convert_ninja_format($format, [
			'HH' => 'H',
			'H' => 'G',
			'hh' => 'h',
			'h' => 'g',
			'mm' => 'i',
			'm' => 'i',
			'ss' => 's',
			's' => 's',
			'A' => 'A',
			'a' => 'a',
		]);
	}

	private static function map_filter_type(array $filter): string {
		$type = isset($filter['type']) ? sanitize_key((string) $filter['type']) : '';
		$is_multi = BaraTables_Import_Util::to_bool($filter['is_multi_select'] ?? false, false);
		if ($type === 'checkbox') {
			return 'checkbox';
		}
		if ($type === 'radio') {
			return 'radio';
		}
		if ($type === 'select' || $type === 'dropdown') {
			return $is_multi ? 'dropdown_multi' : 'dropdown';
		}
		return '';
	}

	private static function map_filter_type_priority($method): array {
		$clean = sanitize_key((string) $method);
		if ($clean === '') {
			return [];
		}
		$map = [
			'numeric' => 'number',
			'number' => 'number',
			'date' => 'date',
			'text' => 'text',
			'string' => 'text',
		];
		if (!isset($map[$clean])) {
			return [];
		}
		return [
			[
				'type' => $map[$clean],
				'direction' => 'asc',
			],
		];
	}

	private static function convert_ninja_format(string $format, array $map): string {
		if ($format === '') {
			return '';
		}

		$tokens = array_keys($map);

		$has_tokens = false;
		foreach ($tokens as $token) {
			if (strpos($format, $token) !== false) {
				$has_tokens = true;
				break;
			}
		}
		if (!$has_tokens) {
			return $format;
		}

		$parts = preg_split('/(\\[[^\\]]*\\])/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			$parts = [$format];
		}

		$out = '';
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			if ($part[0] === '[' && substr($part, -1) === ']') {
				$literal = substr($part, 1, -1);
				$out .= self::escape_php_date_literal($literal);
				continue;
			}
			// strtr replaces longest keys first in a single pass and never re-scans replaced
			// text, so a replacement output that equals a later token (e.g. HH -> H, then the
			// H token) is NOT cascaded -- unlike sequential str_replace.
			$out .= strtr($part, $map);
		}
		return $out;
	}

	private static function escape_php_date_literal(string $text): string {
		if ($text === '') {
			return '';
		}
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if ($chars === false) {
			return '';
		}
		$out = '';
		foreach ($chars as $char) {
			$out .= '\\' . $char;
		}
		return $out;
	}
}

/** Current Ninja Tables drag-and-drop builder JSON -> a data-only manual table. */
class BaraTables_Import_NinjaBuilder {
	public static function to_normalized(array $decoded): array {
		$table_data = isset($decoded['table_data']) && is_array($decoded['table_data']) ? $decoded['table_data'] : [];
		$headers = isset($table_data['headers']) && is_array($table_data['headers']) ? array_values($table_data['headers']) : [];
		$source_rows = isset($table_data['data']) && is_array($table_data['data']) ? array_values($table_data['data']) : [];
		$rows = [];
		$had_layout = false;
		foreach ($source_rows as $source_row) {
			$row_cells = isset($source_row['rows']) && is_array($source_row['rows']) ? $source_row['rows'] : [];
			$row = [];
			foreach ($headers as $header) {
				$cell = isset($row_cells[$header]) && is_array($row_cells[$header]) ? $row_cells[$header] : [];
				$widgets = isset($cell['columns']) && is_array($cell['columns']) ? $cell['columns'] : [];
				$values = [];
				foreach ($widgets as $widget) {
					$data = isset($widget['data']) && is_array($widget['data']) ? $widget['data'] : [];
					if (array_key_exists('value', $data)) {
						$value = BaraTables_Import_Util::stringify_cell($data['value']);
						if ($value !== '') {
							$values[] = $value;
						}
					}
				}
				$row[] = trim(implode(' ', $values));
				$style = isset($cell['style']) && is_array($cell['style']) ? $cell['style'] : [];
				if ((int) ($style['rowspan'] ?? 1) !== 1 || (int) ($style['colspan'] ?? 1) !== 1 || count($widgets) > 1) {
					$had_layout = true;
				}
			}
			$rows[] = $row;
		}

		$warnings = [__('The table content was imported without its drag-and-drop styling or interactive elements.', 'baratables')];
		if ($had_layout) {
			$warnings[] = __('Merged cells and multiple elements in one cell were flattened into a regular grid.', 'baratables');
		}
		$name = isset($decoded['table_name']) ? str_replace('-', ' ', (string) $decoded['table_name']) : '';
		// The builder's "headers" are internal column ids (column_0, column_1, ...), not
		// user-facing headings. Preserve every exported row and let BaraTables name the columns.
		return BaraTables_Import_Builder::normalized($name, [], $rows, false, [], $warnings);
	}
}

/** Associative JSON records, including the current Supsystic REST export envelope. */
class BaraTables_Import_Records {
	public static function to_normalized(array $records, string $name = '', array $declared_columns = []): array {
		$keys = [];
		$labels = [];
		foreach ($declared_columns as $column) {
			if (!is_scalar($column)) {
				continue;
			}
			$key = (string) $column;
			if ($key !== '' && !in_array($key, $keys, true)) {
				$keys[] = $key;
				$labels[] = $key;
			}
		}
		foreach ($records as $record) {
			if (!is_array($record)) {
				continue;
			}
			foreach (array_keys($record) as $key) {
				$key = (string) $key;
				if (!in_array($key, $keys, true)) {
					$keys[] = $key;
					$labels[] = $key;
				}
			}
		}
		$rows = [];
		foreach ($records as $record) {
			if (!is_array($record)) {
				continue;
			}
			$row = [];
			foreach ($keys as $key) {
				$row[] = BaraTables_Import_Util::stringify_cell($record[$key] ?? '');
			}
			$rows[] = $row;
		}
		return BaraTables_Import_Builder::normalized($name, $labels, $rows, true);
	}
}

/**
 * Generic spreadsheet (CSV) export -> custom_data. Covers any plugin whose export is a plain
 * data file (the common case for the data-only exporters), plus hand-rolled CSVs.
 */
class BaraTables_Import_Spreadsheet {
	public static function to_normalized(string $raw, string $filename = '', string $name_override = ''): array {
		$raw = BaraTables_Import_Util::normalize_text($raw);
		$delimiter = self::sniff_delimiter($raw);
		$rows = self::parse_csv($raw, $delimiter);

		// Trim trailing fully-empty rows (common with a trailing newline).
		while (!empty($rows)) {
			$last = end($rows);
			$joined = trim(implode('', array_map('strval', is_array($last) ? $last : [])));
			if ($joined === '') {
				array_pop($rows);
			} else {
				break;
			}
		}

		$name = $name_override !== '' ? $name_override : self::name_from_filename($filename);
		$labels = [];
		$body = $rows;
		if (!empty($rows)) {
			$labels = array_map('strval', array_values($rows[0]));
			$body = array_slice($rows, 1);
		}
		$warnings = [];
		if (self::has_visualizer_type_row($labels, $body)) {
			array_shift($body);
			$warnings[] = __('The chart data-type row was recognized and removed from the imported table.', 'baratables');
		}

		return BaraTables_Import_Builder::normalized($name, $labels, $body, true, [], $warnings);
	}

	private static function sniff_delimiter(string $raw): string {
		$best = ',';
		$best_score = -1;
		foreach ([',', ';', "\t", '|'] as $priority => $delimiter) {
			$sample = array_slice(self::parse_csv($raw, $delimiter, 12), 0, 12);
			$widths = [];
			foreach ($sample as $row) {
				if ($row === ['']) {
					continue;
				}
				$widths[] = count($row);
			}
			if (empty($widths)) {
				continue;
			}
			$frequency = array_count_values($widths);
			arsort($frequency);
			$mode_width = (int) key($frequency);
			$consistent_rows = (int) reset($frequency);
			// Prefer a stable multi-column parse across logical CSV records. Candidate order
			// breaks exact ties in favor of comma, without counting delimiters inside quotes.
			$score = ($mode_width > 1 ? 100000 : 0) + ($consistent_rows * 1000) + ($mode_width * 10) - $priority;
			if ($score > $best_score) {
				$best_score = $score;
				$best = $delimiter;
			}
		}
		return $best;
	}

	private static function parse_csv(string $raw, string $delimiter, int $row_limit = 0): array {
		$rows = [];
		// php://temp is an in-memory stream, not a filesystem path -- WP_Filesystem cannot provide
		// a stream handle, and fgetcsv() is needed to parse quoted fields (embedded delimiters and
		// newlines) correctly rather than a naive explode().
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream, not a real file.
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return $rows;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to the in-memory stream opened above.
		fwrite($handle, $raw);
		rewind($handle);
		while (true) {
			$row = fgetcsv($handle, 0, $delimiter, '"', '\\');
			if ($row === false) {
				break;
			}
			$rows[] = array_map(static function ($cell) {
				return $cell === null ? '' : (string) $cell;
			}, $row);
			if ($row_limit > 0 && count($rows) >= $row_limit) {
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the in-memory stream.
		fclose($handle);
		return $rows;
	}

	private static function has_visualizer_type_row(array $labels, array $body): bool {
		if (count($labels) < 2 || empty($body[0]) || !is_array($body[0]) || count($body[0]) !== count($labels)) {
			return false;
		}
		$allowed = ['string', 'number', 'boolean', 'date', 'datetime', 'timeofday'];
		$has_typed_column = false;
		foreach ($body[0] as $type) {
			$type = strtolower(trim((string) $type));
			if (!in_array($type, $allowed, true)) {
				return false;
			}
			if ($type !== 'string') {
				$has_typed_column = true;
			}
		}
		return $has_typed_column;
	}

	private static function name_from_filename(string $filename): string {
		$base = $filename !== '' ? pathinfo($filename, PATHINFO_FILENAME) : '';
		$base = trim(str_replace(['_', '-'], ' ', (string) $base));
		return $base !== '' ? $base : __('Imported Table', 'baratables');
	}
}

/** HTML table exports, including WP Table Builder XML and TablePress HTML. */
class BaraTables_Import_HtmlTable {
	public static function contains_table(string $raw): bool {
		return preg_match('/<table\b[^>]*>[\s\S]*?<\/(?:table)\s*>/i', $raw) === 1;
	}

	/** @return array[] list of normalized tables */
	public static function to_normalized_list(string $raw, string $filename = ''): array {
		if (!class_exists('DOMDocument')) {
			return [];
		}
		$previous = libxml_use_internal_errors(true);
		$dom = new DOMDocument('1.0', 'UTF-8');
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . $raw,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			return [];
		}

		$results = [];
		foreach ($dom->getElementsByTagName('table') as $table) {
			$parsed = self::parse_table($dom, $table, $filename);
			if ($parsed !== null) {
				$results[] = $parsed;
			}
		}
		return $results;
	}

	private static function parse_table(DOMDocument $dom, DOMElement $table, string $filename): ?array {
		$row_records = [];
		$rowspans = [];
		$had_spans = false;
		foreach ($table->getElementsByTagName('tr') as $tr) {
			if (self::nearest_table($tr) !== $table) {
				continue; // a row from a table nested inside this cell
			}
			$cells = [];
			foreach ($tr->childNodes as $child) {
				if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['th', 'td'], true)) {
					$cells[] = $child;
				}
			}
			if (empty($cells) && empty($rowspans)) {
				continue;
			}

			$row = [];
			$column = 0;
			$consume_span = static function () use (&$rowspans, &$row, &$column): void {
				while (!empty($rowspans[$column])) {
					$row[$column] = '';
					$rowspans[$column]--;
					if ($rowspans[$column] <= 0) {
						unset($rowspans[$column]);
					}
					$column++;
				}
			};
			$consume_span();
			$has_heading_cell = false;
			foreach ($cells as $cell) {
				$consume_span();
				$has_heading_cell = $has_heading_cell || strtolower($cell->tagName) === 'th';
				$colspan = max(1, (int) $cell->getAttribute('colspan'));
				$rowspan = max(1, (int) $cell->getAttribute('rowspan'));
				$had_spans = $had_spans || $colspan > 1 || $rowspan > 1;
				$row[$column] = self::inner_html($dom, $cell);
				for ($offset = 0; $offset < $colspan; $offset++) {
					if ($offset > 0) {
						$row[$column + $offset] = '';
					}
					if ($rowspan > 1) {
						$rowspans[$column + $offset] = $rowspan - 1;
					}
				}
				$column += $colspan;
			}
			$consume_span();
			if (!empty($row)) {
				ksort($row);
				$section = self::nearest_section($tr, $table);
				$row_records[] = [
					'cells' => array_values($row),
					// A <th> in <tbody> is commonly a row heading, and <tfoot> often uses
					// <th> for totals. Neither is a column-header row. Only infer a header
					// from <th> when the source omitted an explicit table section.
					'header' => $section === 'thead' || ($section === '' && $has_heading_cell),
					'footer' => $section === 'tfoot',
				];
			}
		}
		if (empty($row_records)) {
			return null;
		}

		$header_rows = [];
		$body = [];
		$had_footer = false;
		foreach ($row_records as $record) {
			if ($record['header']) {
				$header_rows[] = $record['cells'];
			} else {
				$body[] = $record['cells'];
			}
			$had_footer = $had_footer || $record['footer'];
		}
		if (empty($header_rows)) {
			$header_rows[] = array_shift($body);
		}
		$labels = array_shift($header_rows);
		if (!empty($header_rows)) {
			$body = array_merge($header_rows, $body);
		}

		$name = trim((string) $table->getAttribute('data-wptb-table-title'));
		if ($name === '') {
			$name = trim(str_replace(['_', '-'], ' ', (string) pathinfo($filename, PATHINFO_FILENAME)));
		}
		$warnings = [__('Table styling and layout were not imported.', 'baratables')];
		if ($had_spans) {
			$warnings[] = __('Merged cells were flattened; their values were kept in the first covered cell.', 'baratables');
		}
		if (!empty($header_rows)) {
			$warnings[] = __('Additional header rows were preserved as regular table rows.', 'baratables');
		}
		if ($had_footer) {
			$warnings[] = __('Footer rows were preserved as regular table rows.', 'baratables');
		}
		return BaraTables_Import_Builder::normalized($name, $labels, $body, true, [], $warnings);
	}

	private static function nearest_table(DOMNode $node): ?DOMElement {
		$parent = $node->parentNode;
		while ($parent instanceof DOMElement) {
			if (strtolower($parent->tagName) === 'table') {
				return $parent;
			}
			$parent = $parent->parentNode;
		}
		return null;
	}

	private static function nearest_section(DOMNode $node, DOMElement $table): string {
		$parent = $node->parentNode;
		while ($parent instanceof DOMElement && $parent !== $table) {
			$tag = strtolower($parent->tagName);
			if (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
				return $tag;
			}
			$parent = $parent->parentNode;
		}
		return '';
	}

	private static function inner_html(DOMDocument $dom, DOMElement $cell): string {
		$html = '';
		foreach ($cell->childNodes as $child) {
			$html .= (string) $dom->saveHTML($child);
		}
		return trim($html);
	}
}

/**
 * League Table XML export -> one custom_data NormalizedTable per <table> element.
 */
class BaraTables_Import_LeagueTable {
	/** @return array[] list of NormalizedTable arrays */
	public static function to_normalized_list(SimpleXMLElement $root): array {
		$results = [];
		foreach ($root->table as $table) {
			$name = isset($table->name) ? (string) $table->name : '';
			$show_header = isset($table->show_header) ? BaraTables_Import_Util::to_bool((string) $table->show_header, true) : true;

			$grid = [];
			if (isset($table->data)) {
				$fallback_index = 0;
				foreach ($table->data->record as $record) {
					$content = isset($record->content) ? (string) $record->content : '';
					$cells = json_decode($content, true);
					if (!is_array($cells)) {
						$cells = [];
					}
					$row_index = isset($record->row_index) ? max(0, (int) $record->row_index) : $fallback_index;
					while (array_key_exists($row_index, $grid)) {
						$row_index++;
					}
					$grid[$row_index] = array_map(static function ($cell) {
						return BaraTables_Import_Util::stringify_cell($cell);
					}, array_values($cells));
					$fallback_index = $row_index + 1;
				}
				ksort($grid);
				$grid = array_values($grid);
			}

			// Inline per-cell link/image decorations (custom_data has no per-cell metadata).
			if (isset($table->cell)) {
				foreach ($table->cell->record as $deco) {
					$r = isset($deco->row_index) ? (int) $deco->row_index : -1;
					$c = isset($deco->column_index) ? (int) $deco->column_index : -1;
					if ($r < 0 || $c < 0 || !isset($grid[$r][$c])) {
						continue;
					}
					$grid[$r][$c] = self::decorate_cell((string) $grid[$r][$c], $deco);
				}
			}

			$labels = [];
			$body = $grid;
			if ($show_header && !empty($grid)) {
				$labels = $grid[0];
				$body = array_slice($grid, 1);
			}

			$settings = BaraTables_Import_Builder::blank_settings();
			if (isset($table->enable_manual_sorting)) {
				$settings['ordering'] = BaraTables_Import_Util::to_bool((string) $table->enable_manual_sorting, false);
			}
			$enable_sorting = isset($table->enable_sorting) ? BaraTables_Import_Util::to_bool((string) $table->enable_sorting, false) : false;
			if ($enable_sorting && isset($table->order_by)) {
				$order_by = (int) $table->order_by; // 1-based
				if ($order_by > 0) {
					$settings['sort_column_index'] = $order_by - 1;
					$settings['sort_direction'] = (isset($table->order_desc_asc) && (int) $table->order_desc_asc === 1) ? 'desc' : 'asc';
					$settings['ordering'] = true;
				}
			}

			$results[] = BaraTables_Import_Builder::normalized($name, $labels, $body, $show_header, $settings);
		}
		return $results;
	}

	private static function decorate_cell(string $text, SimpleXMLElement $deco): string {
		$link = isset($deco->link) ? trim((string) $deco->link) : '';
		$image_left = isset($deco->image_left) ? trim((string) $deco->image_left) : '';
		$image_right = isset($deco->image_right) ? trim((string) $deco->image_right) : '';
		$inner = $text;
		if ($image_left !== '') {
			$inner = '<img src="' . esc_url($image_left) . '" alt="" /> ' . $inner;
		}
		if ($image_right !== '') {
			$inner = $inner . ' <img src="' . esc_url($image_right) . '" alt="" />';
		}
		if ($link !== '') {
			$inner = '<a href="' . esc_url($link) . '">' . $inner . '</a>';
		}
		return $inner;
	}
}

/**
 * Top-level facade: detect a file's format and turn it into ready-to-persist definitions.
 */
class BaraTables_Importer {
	/**
	 * @return array{
	 *   ok:bool, format:string, definition:?array, preview:?array, usable_table_count:int,
	 *   definitions:array[], previews:array[], warnings:string[], message:string
	 * }
	 */
	public static function analyze(string $raw, string $filename, BaraTables_Service $service, int $depth = 0): array {
		$detected = self::detect($raw, $filename);
		$format = $detected['format'];

		$result = [
			'ok' => false,
			'format' => $format,
			'definition' => null,
			'preview' => null,
			'usable_table_count' => 0,
			// Compatibility aliases for integrations using the original importer contract. They
			// contain at most the selected first table; discarded tables are never retained.
			'definitions' => [],
			'previews' => [],
			'warnings' => [],
			'message' => '',
		];

		$manual_adapters = [
			'tablepress_full' => static fn() => [BaraTables_Import_TablePress::to_normalized($detected['decoded'], false)],
			'tablepress_simple' => static fn() => [BaraTables_Import_TablePress::to_normalized($detected['decoded'], true)],
			'ninja_manual' => static fn() => [BaraTables_Import_NinjaTables::to_normalized($detected['decoded'])],
			'ninja_builder' => static fn() => [BaraTables_Import_NinjaBuilder::to_normalized($detected['decoded'])],
			'json_records' => static fn() => [BaraTables_Import_Records::to_normalized($detected['decoded'])],
			'supsystic_json' => static fn() => [BaraTables_Import_Records::to_normalized(
				isset($detected['decoded']['data']) && is_array($detected['decoded']['data']) ? $detected['decoded']['data'] : [],
				isset($detected['decoded']['title']) ? (string) $detected['decoded']['title'] : '',
				isset($detected['decoded']['columns']) && is_array($detected['decoded']['columns']) ? $detected['decoded']['columns'] : []
			)],
			'supsystic_csv' => static fn() => [BaraTables_Import_Spreadsheet::to_normalized(
				(string) $detected['decoded']['csv'],
				isset($detected['decoded']['filename']) ? (string) $detected['decoded']['filename'] : $filename,
				isset($detected['decoded']['title']) ? (string) $detected['decoded']['title'] : ''
			)],
			'spreadsheet' => static fn() => [BaraTables_Import_Spreadsheet::to_normalized($raw, $filename)],
			'html_table' => static fn() => BaraTables_Import_HtmlTable::to_normalized_list((string) $detected['decoded'], $filename),
			'league_table' => static fn() => BaraTables_Import_LeagueTable::to_normalized_list($detected['decoded']),
		];
		if (isset($manual_adapters[$format])) {
			return self::build_manual($result, $manual_adapters[$format](), $service);
		}

		switch ($format) {
			case 'archive':
				if ($depth > 0) {
					// A ZIP entry that is itself a ZIP recurses through analyze() with no
					// natural base case, so nesting deeper than the outer archive is rejected.
					$result['message'] = __('Nested ZIP archives are not supported.', 'baratables');
					return $result;
				}
				return self::analyze_archive($result, $raw, $service);

			case 'ninja_wpposts':
				$built = BaraTables_Import_NinjaTables::to_wpposts_definition($detected['decoded'], $service);
				if (!empty($built['error'])) {
					$result['message'] = $built['error'];
					return $result;
				}
				$result['ok'] = true;
				self::select_definition($result, $built['definition']);
				$result['usable_table_count'] = 1;
				return $result;

			case 'unsupported':
				$result['message'] = $detected['reason'];
				return $result;

			default:
				$result['message'] = self::unknown_message();
				return $result;
		}
	}

	/** Select the first usable manual table and count, but do not retain, later tables. */
	private static function build_manual(array $result, array $norms, BaraTables_Service $service): array {
		$norms = array_values(array_filter($norms, 'is_array'));
		if (empty($norms)) {
			$result['message'] = self::unknown_message();
			return $result;
		}
		foreach ($norms as $norm) {
			if (!BaraTables_Import_Builder::is_usable_normalized($norm)) {
				continue;
			}
			$result['usable_table_count']++;
			// Only the selected table owns warnings. A discarded table's truncation notice beside
			// the selected table's preview is both confusing and unactionable.
			if ($result['definition'] === null) {
				$built = BaraTables_Import_Builder::from_normalized($norm, $service);
				$result['warnings'] = array_merge($result['warnings'], $built['warnings']);
				self::select_definition($result, $built['definition']);
			}
		}
		if ($result['definition'] === null) {
			$result['message'] = __('The file was recognized but contained no table rows to import.', 'baratables');
			return $result;
		}
		if ($result['usable_table_count'] > 1) {
			$result['warnings'][] = sprintf(
				/* translators: %d is the number of tables found in the file. */
				__('The file contained %d tables. Only the first was imported.', 'baratables'),
				$result['usable_table_count']
			);
		}
		$result['ok'] = true;
		return $result;
	}

	/** Safely inspect a multi-table export ZIP without extracting paths onto the filesystem. */
	private static function analyze_archive(array $result, string $raw, BaraTables_Service $service, int $depth = 0): array {
		if (!class_exists('ZipArchive')) {
			$result['message'] = __('ZIP imports are not available because this server does not have ZIP support.', 'baratables');
			return $result;
		}
		if (!function_exists('wp_tempnam')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$temp_file = wp_tempnam('baratables-import.zip');
		if (!is_string($temp_file) || $temp_file === '') {
			$result['message'] = __('The ZIP file could not be opened safely.', 'baratables');
			return $result;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary binary input for ZipArchive; WP_Filesystem cannot expose an archive handle.
		$written = file_put_contents($temp_file, $raw);
		if ($written !== strlen($raw)) {
			wp_delete_file($temp_file);
			$result['message'] = __('The ZIP file could not be opened safely.', 'baratables');
			return $result;
		}

		$zip = new ZipArchive();
		if ($zip->open($temp_file) !== true) {
			wp_delete_file($temp_file);
			$result['message'] = __('The ZIP file is invalid or damaged.', 'baratables');
			return $result;
		}
		if ($zip->numFiles > 50) {
			$zip->close();
			wp_delete_file($temp_file);
			$result['message'] = __('The ZIP file contains too many entries. Import a ZIP with at most 50 files.', 'baratables');
			return $result;
		}

		$entry_names = [];
		$total_size = 0;
		$is_xlsx = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (!is_array($stat) || !isset($stat['name'])) {
				continue;
			}
			$name = str_replace('\\', '/', (string) $stat['name']);
			if ($name === '[Content_Types].xml' || strpos($name, 'xl/') === 0) {
				$is_xlsx = true;
			}
			if (substr($name, -1) === '/') {
				continue;
			}
			$size = isset($stat['size']) ? (int) $stat['size'] : 0;
			$total_size += max(0, $size);
			if ($size > 5242880 || $total_size > 20971520) {
				$zip->close();
				wp_delete_file($temp_file);
				$result['message'] = __('The ZIP expands beyond the safe import limit. Each file must be 5 MB or less and the archive total must be 20 MB or less.', 'baratables');
				return $result;
			}
			$extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
			if (in_array($extension, ['json', 'xml', 'html', 'htm', 'csv', 'txt', 'tsv'], true)) {
				$entry_names[$i] = basename($name);
			}
		}
		if ($is_xlsx) {
			$zip->close();
			wp_delete_file($temp_file);
			$result['message'] = __('Spreadsheet files cannot be read directly. Export the table as CSV, then import that file.', 'baratables');
			return $result;
		}

		$selected_warnings = [];
		foreach ($entry_names as $index => $entry_name) {
			$content = $zip->getFromIndex($index);
			if (!is_string($content)) {
				continue;
			}
			if (strlen($content) > 5242880) {
				$zip->close();
				wp_delete_file($temp_file);
				$result['message'] = __('A file inside the ZIP exceeded the 5 MB import limit.', 'baratables');
				return $result;
			}
			$entry_result = self::analyze($content, $entry_name, $service, $depth + 1);
			if (empty($entry_result['ok']) || empty($entry_result['definition'])) {
				continue;
			}
			$result['usable_table_count'] += max(1, (int) ($entry_result['usable_table_count'] ?? 1));
			if ($result['definition'] === null) {
				self::select_definition($result, $entry_result['definition']);
				$selected_warnings = isset($entry_result['warnings']) && is_array($entry_result['warnings']) ? $entry_result['warnings'] : [];
			}
		}
		$zip->close();
		wp_delete_file($temp_file);

		if ($result['definition'] === null) {
			$result['message'] = __('The ZIP file did not contain a supported table export.', 'baratables');
			return $result;
		}
		$result['warnings'] = $selected_warnings;
		if ($result['usable_table_count'] > 1) {
			$result['warnings'][] = sprintf(
				/* translators: %d is the number of tables found in the ZIP file. */
				__('The ZIP contained %d tables. Only the first was imported.', 'baratables'),
				$result['usable_table_count']
			);
		}
		$result['ok'] = true;
		return $result;
	}

	private static function select_definition(array &$result, array $definition): void {
		$result['definition'] = $definition;
		$result['preview'] = self::preview($definition);
		$result['definitions'] = [$definition];
		$result['previews'] = [$result['preview']];
	}

	private static function preview(array $definition): array {
		$columns = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		$labels = [];
		foreach ($columns as $col) {
			$label = isset($col['label']) ? trim(wp_strip_all_tags((string) $col['label'])) : '';
			$labels[] = $label !== '' ? $label : __('(unnamed)', 'baratables');
		}
		$is_custom = ($definition['source_type'] ?? '') === BaraTables_Source_Type::CUSTOM_DATA;
		$row_count = $is_custom && isset($definition['custom_data']['rows']) && is_array($definition['custom_data']['rows'])
			? count($definition['custom_data']['rows'])
			: null;
		$options = isset($definition['table_options']) && is_array($definition['table_options']) ? $definition['table_options'] : [];
		return [
			'title' => (string) ($definition['name'] ?? ''),
			'data_type' => $is_custom ? __('Manual data', 'baratables') : __('WordPress query', 'baratables'),
			'column_count' => count($columns),
			'columns' => $labels,
			'row_count' => $row_count,
			'per_page' => isset($options['pageLength']) && (int) $options['pageLength'] > 0 ? (string) (int) $options['pageLength'] : __('Default', 'baratables'),
			'search_enabled' => !empty($options['searchBox']),
			'ordering_enabled' => !empty($options['ordering']),
		];
	}

	private static function unknown_message(): string {
		return __('This file is not a supported table export. Use JSON, XML, HTML, CSV, TXT, or ZIP. A spreadsheet needs a header row followed by data rows.', 'baratables');
	}

	/**
	 * Sniff the raw upload and return ['format'=>id, 'decoded'=>mixed, 'reason'=>string].
	 * Detection never throws; an unrecognized file returns format 'unknown'.
	 */
	public static function detect(string $raw, string $filename = ''): array {
		$trimmed = ltrim(BaraTables_Import_Util::normalize_text($raw));
		if ($trimmed === '') {
			return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
		}
		$extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

		// Read binary signatures against the original bytes, before text normalization.
		$raw_head = ltrim($raw);
		if (strncmp($raw_head, "\xD0\xCF\x11\xE0", 4) === 0) {
			return [
				'format' => 'unsupported',
				'decoded' => null,
				'reason' => __('Legacy spreadsheet files cannot be read directly. Export the table as CSV, then import that file.', 'baratables'),
			];
		}
		if (strncmp($raw_head, 'PK', 2) === 0) {
			return ['format' => 'archive', 'decoded' => null, 'reason' => ''];
		}

		$first = $trimmed[0];
		if ($extension === 'json') {
			$decoded = json_decode($trimmed, true, 64);
			if (!is_array($decoded)) {
				return [
					'format' => 'unsupported',
					'decoded' => null,
					'reason' => __('The file could not be parsed as valid JSON.', 'baratables'),
				];
			}
			return self::detect_json($decoded);
		}
		if ($extension === 'xml') {
			$xml_result = self::detect_xml($trimmed);
			return $xml_result !== null
				? $xml_result
				: [
					'format' => 'unsupported',
					'decoded' => null,
					'reason' => __('The file could not be parsed as valid XML.', 'baratables'),
				];
		}
		if (in_array($extension, ['html', 'htm'], true)) {
			return BaraTables_Import_HtmlTable::contains_table($trimmed)
				? ['format' => 'html_table', 'decoded' => $trimmed, 'reason' => '']
				: [
					'format' => 'unsupported',
					'decoded' => null,
					'reason' => __('The HTML file did not contain a table.', 'baratables'),
				];
		}
		if (in_array($extension, ['csv', 'txt', 'tsv'], true)) {
			return ['format' => 'spreadsheet', 'decoded' => null, 'reason' => ''];
		}

		// XML container -- but only commit if it actually parses. A CSV whose first cell starts
		// with '<' (e.g. an HTML cell) is not XML, so on a parse failure we fall through to the
		// JSON and CSV paths instead of rejecting it.
		if ($first === '<') {
			$xml_result = self::detect_xml($trimmed);
			if ($xml_result !== null) {
				return $xml_result;
			}
			if (BaraTables_Import_HtmlTable::contains_table($trimmed)) {
				return ['format' => 'html_table', 'decoded' => $trimmed, 'reason' => ''];
			}
		}

		// JSON container.
		$decoded = json_decode($trimmed, true, 64);
		if (is_array($decoded)) {
			return self::detect_json($decoded);
		}

		// Otherwise treat as CSV/spreadsheet if it has a plausible row.
		$line = strtok($trimmed, "\r\n");
		if ($line !== false && trim($line) !== '') {
			return ['format' => 'spreadsheet', 'decoded' => null, 'reason' => ''];
		}

		return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
	}

	private static function detect_json(array $decoded): array {
		$is_list = BaraTables_Import_Util::is_list($decoded);

		// A bare list can be either a positional TablePress grid or generic associative records.
		// Do not blur the two: importing records as a grid loses every object key and heading.
		if ($is_list) {
			$has_row = false;
			$all_positional = true;
			$all_records = true;
			foreach ($decoded as $element) {
				if (!is_array($element)) {
					continue;
				}
				$has_row = true;
				$all_positional = $all_positional && BaraTables_Import_Util::is_list($element);
				$all_records = $all_records && !BaraTables_Import_Util::is_list($element);
			}
			if ($has_row && $all_positional) {
				return ['format' => 'tablepress_simple', 'decoded' => $decoded, 'reason' => ''];
			}
			if ($has_row && $all_records) {
				return ['format' => 'json_records', 'decoded' => $decoded, 'reason' => ''];
			}
			return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
		}

		// Current Data Tables Generator by Supsystic REST export envelopes.
		if (!empty($decoded['success']) && isset($decoded['csv']) && is_string($decoded['csv'])) {
			return ['format' => 'supsystic_csv', 'decoded' => $decoded, 'reason' => ''];
		}
		if (
			!empty($decoded['success'])
			&& isset($decoded['columns']) && is_array($decoded['columns'])
			&& isset($decoded['data']) && is_array($decoded['data'])
			&& (isset($decoded['table_id']) || isset($decoded['count']))
		) {
			return ['format' => 'supsystic_json', 'decoded' => $decoded, 'reason' => ''];
		}

		// Ninja drag-and-drop builder export. The headers are internal positional keys and the
		// rows contain one or more builder elements per cell, all of which can be flattened safely.
		if (
			isset($decoded['table_data']['headers']) && is_array($decoded['table_data']['headers'])
			&& isset($decoded['table_data']['data']) && is_array($decoded['table_data']['data'])
			&& !isset($decoded['columns'])
		) {
			return ['format' => 'ninja_builder', 'decoded' => $decoded, 'reason' => ''];
		}

		// Ninja Tables classic export.
		$post_type = isset($decoded['post']['post_type']) ? (string) $decoded['post']['post_type'] : '';
		$looks_ninja = isset($decoded['columns']) && is_array($decoded['columns'])
			&& (isset($decoded['settings']) || isset($decoded['data_provider']) || $post_type === 'ninja-table');
		if ($looks_ninja) {
			$metas = isset($decoded['metas']) && is_array($decoded['metas']) ? $decoded['metas'] : [];
			$provider = isset($decoded['data_provider']) ? strtolower(trim((string) $decoded['data_provider'])) : '';
			if (!empty($metas['_ninja_table_wpposts_ds_post_types']) || in_array($provider, ['wp-posts', 'wpposts', 'wp_posts'], true)) {
				return ['format' => 'ninja_wpposts', 'decoded' => $decoded, 'reason' => ''];
			}
			if ($provider === '' || $provider === 'default') {
				return ['format' => 'ninja_manual', 'decoded' => $decoded, 'reason' => ''];
			}
			// Any other provider is external or plugin-backed. Its export has column configuration
			// but not a portable row snapshot, so guessing a different live source is never safe.
			return [
				'format' => 'unsupported',
				'decoded' => null,
				'reason' => __('This table pulls its rows from an external source, so it has no stored rows to import. Export its data as CSV and import that file instead.', 'baratables'),
			];
		}

		// TablePress full export: a "data" 2D array, with sibling options/visibility.
		if (isset($decoded['data']) && is_array($decoded['data']) && (isset($decoded['options']) || isset($decoded['visibility']))) {
			$data = $decoded['data'];
			$first = reset($data);
			if (is_array($first) && BaraTables_Import_Util::is_list($first)) {
				return ['format' => 'tablepress_full', 'decoded' => $decoded, 'reason' => ''];
			}
		}

		return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
	}

	/**
	 * Parse and classify an XML upload. Returns null when the input does not parse as XML at all
	 * (so the caller can fall through to JSON/CSV); returns a format struct otherwise.
	 */
	private static function detect_xml(string $raw): ?array {
		$previous = libxml_use_internal_errors(true);
		// SECURITY: pass only LIBXML_NONET (blocks network DTD/entity fetches). NEVER add
		// LIBXML_NOENT -- that flag turns ON general-entity substitution, which would re-enable
		// classic file:// XXE on an uploaded file. Modern libxml does not substitute external
		// entities by default, so omitting LIBXML_NOENT keeps uploads safe.
		$xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!($xml instanceof SimpleXMLElement)) {
			return null; // not well-formed XML -- let JSON/CSV detection try
		}

		$root = strtolower($xml->getName());

		// League Table XML: <root><plugin_edition>…</plugin_edition><table>…</table></root>.
		// Require the plugin_edition marker so a generic <root><table> file isn't mis-claimed.
		if ($root === 'root' && isset($xml->plugin_edition) && isset($xml->table)) {
			return ['format' => 'league_table', 'decoded' => $xml, 'reason' => ''];
		}

		// WordPress eXtended RSS (WXR) -- post data, not a table.
		if ($root === 'rss') {
			return [
				'format' => 'unsupported',
				'decoded' => null,
				'reason' => __('This is a WordPress content export (posts), not a table. Import it from Tools > Import, then build a table from a WordPress query.', 'baratables'),
			];
		}

		// WP Table Builder calls its HTML <table> fragment an XML export. TablePress can also
		// export a complete HTML document. Treat only actual table markup as a table format.
		$table_nodes = $root === 'table' ? [$xml] : $xml->xpath('.//table');
		if (is_array($table_nodes) && !empty($table_nodes) && BaraTables_Import_HtmlTable::contains_table($raw)) {
			return ['format' => 'html_table', 'decoded' => $raw, 'reason' => ''];
		}

		return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
	}
}
