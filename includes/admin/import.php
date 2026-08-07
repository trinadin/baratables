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
 *                        ordering:bool|null, sort_column_index:int|null, sort_direction:'asc'|'desc'|null ]
 *     'warnings'    => string[]
 *   ]
 *
 * The builder is the ONE place that turns it into a custom_data definition.
 */
class BaraTables_Import_Builder {
	// No MAX_COLS/MAX_ROWS of its own on purpose. A second, lower pair of ceilings lived here and
	// silently threw away importable data: the manual grid accepts MAX_CUSTOM_COLUMNS columns and
	// MAX_CUSTOM_ROWS rows, but the importer stopped at 50/500. 1.2.2 widened the gap by raising
	// MAX_CUSTOM_CELLS 5,000 -> 25,000 and leaving these behind. The caps are now derived from the
	// service constants at the point of use, so they cannot drift apart again.

	public static function blank_settings(): array {
		return [
			'page_length' => null,
			'paging' => null,
			'search' => null,
			'ordering' => null,
			'sort_column_index' => null,
			'sort_direction' => null,
		];
	}

	/** Create the one normalized-table contract shared by every manual-data adapter. */
	public static function normalized(string $name, array $columns, array $rows, bool $has_header, array $settings = [], array $warnings = []): array {
		return [
			'name' => $name,
			'columns' => array_values($columns),
			'rows' => array_values($rows),
			'has_header' => $has_header,
			'settings' => array_merge(self::blank_settings(), $settings),
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
			$columns[] = $service->normalize_column_record([
				'slug' => $slug,
				'label' => $label,
				'filter_label' => $label,
				'auto_label' => $auto_label,
			]);
		}

		$table_options = self::apply_table_settings($service->get_default_table_options(), $settings);

		// Default sort column (0-based), if the source declared one and ordering is on.
		$sort_idx = $settings['sort_column_index'];
		if ($sort_idx !== null && isset($columns[(int) $sort_idx]) && !empty($table_options['ordering'])) {
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
			'filter_order' => [],
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
		$dropped_col = false;
		$dropped_row = false;

		// Classify each row by its ORIGINAL index (header / footer / body) while applying row +
		// column visibility. Splitting on the original index -- not on a position in the compacted,
		// visibility-filtered grid -- is what keeps a hidden header row from promoting a body row,
		// and a hidden header row from eating one off the front of the body.
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
				if (isset($col_vis[$c]) && (int) $col_vis[$c] === 0) {
					$dropped_col = true;
					continue;
				}
				$out_row[] = self::clean_cell($cell);
			}
			if ($has_header && $r < $table_head) {
				$header_rows[] = $out_row;
			} elseif ($r >= $foot_start) {
				continue; // footer row -- not imported as data
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

		if ($dropped_col) {
			$warnings[] = __('Hidden columns from the source were skipped.', 'baratables');
		}
		if ($dropped_row) {
			$warnings[] = __('Hidden rows from the source were skipped.', 'baratables');
		}

		return BaraTables_Import_Builder::normalized($name, $labels, $body, $has_header, self::map_settings($options), $warnings);
	}

	private static function map_settings(array $options): array {
		$settings = BaraTables_Import_Builder::blank_settings();
		$use_dt = array_key_exists('use_datatables', $options) ? BaraTables_Import_Util::to_bool($options['use_datatables'], true) : true;
		if (!$use_dt) {
			$settings['paging'] = false;
			$settings['search'] = false;
			$settings['ordering'] = false;
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
		return $settings;
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
		if (is_array($cell) || is_object($cell)) {
			return '';
		}
		return str_replace(self::SPAN_MARKERS, '', (string) $cell);
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
		foreach ($columns as $i => $col) {
			if (!is_array($col)) {
				continue;
			}
			$key = isset($col['key']) && $col['key'] !== '' ? (string) $col['key'] : ('col_' . ($i + 1));
			$keys[] = $key;
			$name = isset($col['name']) && $col['name'] !== '' ? (string) $col['name'] : $key;
			$labels[] = $name;
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
				$cells[] = is_scalar($cell) ? (string) $cell : '';
			}
			$rows[] = $cells;
		}

		$settings_raw = isset($decoded['settings']) && is_array($decoded['settings']) ? $decoded['settings'] : [];
		$settings = self::map_display_settings($settings_raw);

		$name = isset($decoded['post']['post_title']) ? (string) $decoded['post']['post_title'] : '';

		return BaraTables_Import_Builder::normalized($name, $labels, $rows, true, $settings);
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

	private static function map_display_settings(array $settings_raw): array {
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
		return $settings;
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

/**
 * Generic spreadsheet (CSV) export -> custom_data. Covers any plugin whose export is a plain
 * data file (the common case for the data-only exporters), plus hand-rolled CSVs.
 */
class BaraTables_Import_Spreadsheet {
	public static function to_normalized(string $raw, string $filename = ''): array {
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

		$name = self::name_from_filename($filename);
		$labels = [];
		$body = $rows;
		if (!empty($rows)) {
			$labels = array_map('strval', array_values($rows[0]));
			$body = array_slice($rows, 1);
		}

		return BaraTables_Import_Builder::normalized($name, $labels, $body, true);
	}

	private static function sniff_delimiter(string $raw): string {
		$line = strtok($raw, "\r\n");
		if ($line === false) {
			return ',';
		}
		$candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
		foreach ($candidates as $delim => $_) {
			$candidates[$delim] = substr_count($line, $delim);
		}
		arsort($candidates);
		$best = key($candidates);
		return $candidates[$best] > 0 ? $best : ',';
	}

	private static function parse_csv(string $raw, string $delimiter): array {
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
			if ($row === [null]) {
				continue; // blank physical line
			}
			$rows[] = array_map(static function ($cell) {
				return $cell === null ? '' : (string) $cell;
			}, $row);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the in-memory stream.
		fclose($handle);
		return $rows;
	}

	private static function name_from_filename(string $filename): string {
		$base = $filename !== '' ? pathinfo($filename, PATHINFO_FILENAME) : '';
		$base = trim(str_replace(['_', '-'], ' ', (string) $base));
		return $base !== '' ? $base : __('Imported Table', 'baratables');
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
				foreach ($table->data->record as $record) {
					$content = isset($record->content) ? (string) $record->content : '';
					$cells = json_decode($content, true);
					if (!is_array($cells)) {
						$cells = [];
					}
					$grid[] = array_map(static function ($cell) {
						return is_scalar($cell) ? (string) $cell : '';
					}, array_values($cells));
				}
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
	public static function analyze(string $raw, string $filename, BaraTables_Service $service): array {
		$detected = self::detect($raw);
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
			'spreadsheet' => static fn() => [BaraTables_Import_Spreadsheet::to_normalized($raw, $filename)],
			'league_table' => static fn() => BaraTables_Import_LeagueTable::to_normalized_list($detected['decoded']),
		];
		if (isset($manual_adapters[$format])) {
			return self::build_manual($result, $manual_adapters[$format](), $service);
		}

		switch ($format) {

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
		return __('This file is not a supported table export. Use JSON, XML, CSV, or TXT. A spreadsheet needs a header row followed by data rows.', 'baratables');
	}

	/**
	 * Sniff the raw upload and return ['format'=>id, 'decoded'=>mixed, 'reason'=>string].
	 * Detection never throws; an unrecognized file returns format 'unknown'.
	 */
	public static function detect(string $raw): array {
		$trimmed = ltrim(BaraTables_Import_Util::normalize_text($raw));
		if ($trimmed === '') {
			return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
		}

		// Binary spreadsheet/archive: "PK" (XLSX/ZIP zip magic) or the OLE2 header (legacy XLS).
		// We don't unpack these -- read against the raw, not the normalized text, since the magic
		// bytes are not valid UTF-8.
		$raw_head = ltrim($raw);
		if (
			strncmp($raw_head, 'PK', 2) === 0
			|| strncmp($raw_head, "\xD0\xCF\x11\xE0", 4) === 0
		) {
			return [
				'format' => 'unsupported',
				'decoded' => null,
				'reason' => __('Spreadsheet and ZIP files cannot be read directly. Export a single sheet as CSV, then import that file.', 'baratables'),
			];
		}

		$first = $trimmed[0];

		// XML container -- but only commit if it actually parses. A CSV whose first cell starts
		// with '<' (e.g. an HTML cell) is not XML, so on a parse failure we fall through to the
		// JSON and CSV paths instead of rejecting it.
		if ($first === '<') {
			$xml_result = self::detect_xml($trimmed);
			if ($xml_result !== null) {
				return $xml_result;
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
		$is_list = array_keys($decoded) === range(0, count($decoded) - 1);

		// Bare top-level array of rows -> TablePress "simple" data-only JSON. Scan for the first
		// array element rather than testing index 0, so a leading null (or scalar) doesn't veto an
		// otherwise-importable grid; a list of only scalars is not a grid and stays unknown.
		if ($is_list) {
			$has_array_row = false;
			foreach ($decoded as $element) {
				if (is_array($element)) {
					$has_array_row = true;
					break;
				}
			}
			if ($has_array_row) {
				return ['format' => 'tablepress_simple', 'decoded' => $decoded, 'reason' => ''];
			}
			return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
		}

		// Ninja Tables classic export.
		$post_type = isset($decoded['post']['post_type']) ? (string) $decoded['post']['post_type'] : '';
		$looks_ninja = isset($decoded['columns']) && is_array($decoded['columns'])
			&& (isset($decoded['settings']) || isset($decoded['data_provider']) || $post_type === 'ninja-table');
		if ($looks_ninja) {
			$metas = isset($decoded['metas']) && is_array($decoded['metas']) ? $decoded['metas'] : [];
			if (!empty($metas['_ninja_table_wpposts_ds_post_types'])) {
				return ['format' => 'ninja_wpposts', 'decoded' => $decoded, 'reason' => ''];
			}
			$provider = isset($decoded['data_provider']) ? (string) $decoded['data_provider'] : 'default';
			// Rows that live in an external system (a form, a linked CSV, a connected sheet)
			// aren't in the file, so there's nothing to import.
			if (in_array($provider, ['fluent-form', 'csv', 'google-csv'], true)) {
				return [
					'format' => 'unsupported',
					'decoded' => null,
					'reason' => __('This table pulls its rows from an external source, so it has no stored rows to import. Export its data as CSV and import that file instead.', 'baratables'),
				];
			}
			$has_rows = (!empty($decoded['original_rows']) && is_array($decoded['original_rows']))
				|| (!empty($decoded['rows']) && is_array($decoded['rows']));
			if ($has_rows) {
				return ['format' => 'ninja_manual', 'decoded' => $decoded, 'reason' => ''];
			}
			// Columns-only classic export with no stored rows: treat it as a WP-Posts query
			// table (the original importer's behavior, preserved for backward compatibility).
			return ['format' => 'ninja_wpposts', 'decoded' => $decoded, 'reason' => ''];
		}

		// Ninja drag-and-drop builder export (different schema, no resolvable grid).
		if (isset($decoded['table_data']) && isset($decoded['table_html']) && !isset($decoded['columns'])) {
			return [
				'format' => 'unsupported',
				'decoded' => null,
				'reason' => __('This is a layout export with no data grid, so its rows cannot be imported. Export the data as CSV and import that instead.', 'baratables'),
			];
		}

		// TablePress full export: a "data" 2D array, with sibling options/visibility.
		if (isset($decoded['data']) && is_array($decoded['data']) && (isset($decoded['options']) || isset($decoded['visibility']))) {
			$data = $decoded['data'];
			$first = reset($data);
			if (is_array($first)) {
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

		return ['format' => 'unknown', 'decoded' => null, 'reason' => ''];
	}
}
