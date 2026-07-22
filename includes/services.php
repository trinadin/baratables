<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Service {
	// Concern clusters split into their own files to keep this class from being a single 4,000-line
	// god-object. Traits run in this class's scope, so every $this->, self:: constant and private
	// property below is shared with them exactly as if the methods were still declared inline.
	use BaraTables_Query_Sanitize_Trait;
	use BaraTables_Filter_Options_Trait;
	use BaraTables_Value_Format_Trait;
	use BaraTables_Fields_Discovery_Trait;
	use BaraTables_Column_State_Trait;

	private const TABLE_OPTION_SCHEMA = [
		'paging' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'pagingNumbers' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'pagingFirstLast' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'pagingPreviousNext' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'lengthChange' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'searchBox' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'searchColumns' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'info' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'infoText' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'infoEmpty' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'infoFiltered' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'layoutTopStart' => [
			'type' => 'checkbox_multi',
			'default' => ['pagelength', 'buttons'],
			'choices' => [
				'pagelength' => null,
				'buttons' => null,
				'search' => null,
				'info' => null,
				'paging' => null,
			],
			'label' => null,
			'description' => null,
		],
		'layoutTopEnd' => [
			'type' => 'checkbox_multi',
			'default' => ['search'],
			'choices' => [
				'pagelength' => null,
				'buttons' => null,
				'search' => null,
				'info' => null,
				'paging' => null,
			],
			'label' => null,
			'description' => null,
		],
		'layoutBottomStart' => [
			'type' => 'checkbox_multi',
			'default' => ['info'],
			'choices' => [
				'pagelength' => null,
				'buttons' => null,
				'search' => null,
				'info' => null,
				'paging' => null,
			],
			'label' => null,
			'description' => null,
		],
		'layoutBottomEnd' => [
			'type' => 'checkbox_multi',
			'default' => ['paging'],
			'choices' => [
				'pagelength' => null,
				'buttons' => null,
				'search' => null,
				'info' => null,
				'paging' => null,
			],
			'label' => null,
			'description' => null,
		],
		'filtersTitle' => [
			'type' => 'checkbox',
			'default' => false,
			'label' => null,
		],
		'filtersTitleText' => [
			'type' => 'text_html',
			'default' => 'Filters',
			'label' => null,
			'description' => null,
		],
		'ordering' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'colReorder' => [
			'type' => 'checkbox',
			'default' => false,
			'label' => null,
		],
		'stateSave' => [
			'type' => 'checkbox',
			'default' => false,
			'label' => null,
		],
		'autoWidth' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'scrollX' => [
			'type' => 'checkbox',
			'default' => false,
			'label' => null,
		],
		'scrollY' => [
			'type' => 'number',
			'default' => 0,
			'min' => 0,
			'max' => 2000,
			'label' => null,
			'description' => null,
		],
		'scrollCollapse' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'stripe' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'rowBorder' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'cellBorder' => [
			'type' => 'checkbox',
			'default' => false,
			'label' => null,
		],
		'hover' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'orderColumn' => [
			'type' => 'checkbox',
			'default' => true,
			'label' => null,
		],
		'compact' => [
			'type' => 'checkbox',
			'default' => false,
			'label' => null,
		],
		'pageLength' => [
			'type' => 'number',
			'default' => 25,
			'min' => 1,
			'max' => 500,
			'label' => null,
			'description' => null,
		],
		'rowLimit' => [
			'type' => 'number',
			'default' => 1000,
			'min' => 1,
			'max' => 10000,
			'label' => null,
			'description' => null,
		],
		'lengthMenuPrefix' => [
			'type' => 'text_html',
			'default' => 'Show',
			'label' => null,
			'description' => null,
		],
		'lengthMenuSuffix' => [
			'type' => 'text_html',
			'default' => 'entries',
			'label' => null,
			'description' => null,
		],
		'paginateFirst' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'paginatePrevious' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'paginateNext' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'paginateLast' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'searchText' => [
			'type' => 'text_html',
			'default' => 'Search:',
			'label' => null,
			'description' => null,
		],
		'searchPlaceholder' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'searchColumnsLabel' => [
			'type' => 'text_html',
			'default' => 'Columns',
			'label' => null,
			'description' => null,
		],
		'searchColumnsHeading' => [
			'type' => 'text_html',
			'default' => 'Search in',
			'label' => null,
			'description' => null,
		],
		'buttons' => [
			'type' => 'checkbox_multi',
			'default' => [],
			'choices' => [
				'copy' => null,
				'csv' => null,
				'excel' => null,
				'print' => null,
				'colvis' => null,
				'pagelength' => null,
			],
			'label' => null,
			'description' => null,
		],
		'buttonTextCopy' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'buttonTextCsv' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'buttonTextExcel' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'buttonTextPrint' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'buttonTextColvis' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
		'buttonTextPagelength' => [
			'type' => 'text_html',
			'default' => '',
			'label' => null,
			'description' => null,
		],
	];
	private const ALLOWED_INLINE_HTML = [
		'span' => [
			'class' => [],
			'style' => [],
			'aria-hidden' => [],
			'aria-label' => [],
			'title' => [],
			'role' => [],
			'data-icon' => [],
		],
		'i' => [
			'class' => [],
			'style' => [],
			'aria-hidden' => [],
			'aria-label' => [],
			'title' => [],
			'role' => [],
		],
		'b' => [
			'class' => [],
			'aria-hidden' => [],
		],
		'strong' => [
			'class' => [],
			'aria-hidden' => [],
		],
		'em' => [
			'class' => [],
			'aria-hidden' => [],
		],
		'small' => [
			'class' => [],
		],
		'sup' => [
			'class' => [],
		],
		'sub' => [
			'class' => [],
		],
		'svg' => [
			'class' => [],
			'width' => [],
			'height' => [],
			'viewBox' => [],
			'fill' => [],
			'stroke' => [],
			'stroke-width' => [],
			'aria-hidden' => [],
			'aria-label' => [],
			'focusable' => [],
			'role' => [],
			'xmlns' => [],
		],
		'path' => [
			'd' => [],
			'fill' => [],
			'stroke' => [],
			'stroke-width' => [],
			'class' => [],
			'transform' => [],
		],
	];
	public const TABLE_STYLE_CLASS_MAP = [
		'stripe' => 'stripe',
		'rowBorder' => 'row-border',
		'cellBorder' => 'cell-border',
		'hover' => 'hover',
		'orderColumn' => 'order-column',
		'compact' => 'compact',
	];
	private const CHART_OPTION_DEFAULTS = [
		'type' => 'bar',
		'x_axis' => '',
		'series' => [],
		'stack' => false,
		'height' => 360,
		'position' => 'above',
		'gantt_label' => '',
		'gantt_start' => '',
		'gantt_end' => '',
		'gantt_group' => '',
		'gantt_progress' => '',
	];
	private const MAX_CSV_BYTES = 5242880;
	private const MAX_CSV_LINE_LENGTH = 1048576;
	private const CSV_MIME_TYPES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'application/vnd.ms-excel',
		'text/comma-separated-values',
	];
	public const MAX_CUSTOM_COLUMNS = 100;
	public const MAX_CUSTOM_ROWS = 1000;
	// Cell budget matches the pre-1.2.1 maximum (50 cols x 500 rows) so no manual grid that was
	// legal in an earlier version is ever silently truncated when its editor is opened or saved;
	// the per-dimension caps above remain the real guard against pathologically large grids.
	public const MAX_CUSTOM_CELLS = 25000;
	// Nesting depth stringify_cell_value() will flatten before giving up. ACF Repeater/Group
	// fields nest arbitrarily; two levels covers Relationship and a flat Repeater row without
	// letting a pathological structure recurse.
	private const MAX_VALUE_FLATTEN_DEPTH = 2;
	// Ceiling on terms fetched per taxonomy for the editor's term picker. Already-selected terms
	// are always merged in on top of this, so the cap can never drop an existing selection.
	public const MAX_TERM_PICKER_TERMS = 200;
	private ?array $last_inferred_columns = null;
	private BaraTables_Repository $repo;

	public function __construct(BaraTables_Repository $repo) {
		$this->repo = $repo;
	}

	public static function allowed_inline_html(): array {
		return self::ALLOWED_INLINE_HTML;
	}

	private function sanitize_inline_html($value): string {
		if (!is_scalar($value)) {
			return '';
		}
		$value = (string) $value;
		if ($value === '') {
			return '';
		}
		$clean = wp_kses($value, self::ALLOWED_INLINE_HTML);
		return trim($clean);
	}

	private function sanitize_inline_label_map(array $labels_raw): array {
		$out = [];
		foreach ($labels_raw as $key => $label) {
			$clean_key = sanitize_text_field($key);
			$clean_label = $this->sanitize_inline_html($label);
			if ($clean_key === '' || $clean_label === '') {
				continue;
			}
			$out[$clean_key] = $clean_label;
		}
		return $out;
	}

	private function sanitize_bool_flags(array $raw, ?array $slugs = null, bool $default = false): array {
		$out = [];
		if ($slugs === null) {
			foreach ($raw as $slug => $value) {
				$clean_slug = sanitize_text_field($slug);
				if ($clean_slug === '') {
					continue;
				}
				$out[$clean_slug] = !empty($value);
			}
			return $out;
		}

		foreach ($slugs as $slug) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$out[$clean_slug] = array_key_exists($clean_slug, $raw) ? (bool) $raw[$clean_slug] : $default;
		}
		return $out;
	}

	public function sanitize_filter_types(array $filters_raw, array $dropdown_multi_raw, array $dropdown_search_raw): array {
		$allowed = ['dropdown', 'checkbox', 'radio'];
		$out = [];
		foreach ($filters_raw as $key => $type) {
			$clean_key = sanitize_text_field($key);
			$clean_type = sanitize_key($type);
			if (in_array($clean_type, $allowed, true)) {
				if ($clean_type === 'dropdown') {
					$multi  = !empty($dropdown_multi_raw[$clean_key]);
					$search = !empty($dropdown_search_raw[$clean_key]);
					if ($search && $multi) {
						$out[$clean_key] = 'dropdown_multi';
					} elseif ($search) {
						$out[$clean_key] = 'dropdown';
					} elseif ($multi) {
						$out[$clean_key] = 'dropdown_plain_multi';
					} else {
						$out[$clean_key] = 'dropdown_plain';
					}
				} else {
					$out[$clean_key] = $clean_type;
				}
			}
		}
		return $out;
	}

	public function sanitize_filter_sorts(array $filter_sorts_raw): array {
		$allowed = ['asc', 'desc', 'custom', 'none'];
		$out = [];
		foreach ($filter_sorts_raw as $key => $sort) {
			$clean_key = sanitize_text_field($key);
			$clean_sort = sanitize_key($sort);
			if ($clean_sort === 'none') {
				$clean_sort = 'custom';
			}
			$out[$clean_key] = in_array($clean_sort, $allowed, true) ? $clean_sort : 'asc';
		}
		return $out;
	}

	public function sanitize_filter_type_priority(array $priority_raw): array {
		$out = [];
		foreach ($priority_raw as $key => $raw_value) {
			$clean_key = sanitize_text_field($key);
			if ($clean_key === '') {
				continue;
			}
			$priority = $this->parse_data_type_priority($raw_value);
			if (!empty($priority)) {
				$out[$clean_key] = $priority;
			}
		}
		return $out;
	}

	public function sanitize_custom_labels(array $labels_raw): array {
		return $this->sanitize_inline_label_map($labels_raw);
	}

	public function sanitize_filter_labels(array $labels_raw): array {
		$out = [];
		foreach ($labels_raw as $key => $label) {
			$clean_key = sanitize_text_field($key);
			if ($clean_key === '') {
				continue;
			}
			$out[$clean_key] = $this->sanitize_inline_html($label);
		}
		return $out;
	}

	public function sanitize_filter_values(array $filter_values_raw): array {
		$out = [];
		foreach ($filter_values_raw as $slug => $raw_values) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$raw_string = is_array($raw_values) ? implode("\n", array_map('strval', $raw_values)) : (string) $raw_values;
			$lines = preg_split('/[\r\n]+/', $raw_string);
			$lines = $lines === false ? [] : $lines;
			$values = [];
			foreach ($lines as $line) {
				$line = trim($line);
				if ($line === '') {
					continue;
				}
				$label = $line;
				$search_source = $line;

				if (strpos($line, '=>') !== false) {
					[$label_part, $search_part] = array_pad(explode('=>', $line, 2), 2, '');
					$label = trim($label_part);
					$search_source = $search_part;
				} elseif (strpos($line, '|') !== false) {
					[$label_part, $search_part] = array_pad(explode('|', $line, 2), 2, '');
					$label = trim($label_part);
					$search_source = $search_part;
				}

				$search_chunks = array_map('trim', explode(',', $search_source));
				$search_terms = [];
				foreach ($search_chunks as $chunk) {
					if ($chunk === '') {
						$search_terms[] = '';
						continue;
					}
					$search_terms[] = sanitize_text_field($chunk);
				}
				if (empty($search_terms)) {
					$search_terms[] = sanitize_text_field($label);
				}
				$label = $label !== '' ? $label : (string) ($search_terms[0] ?? '');
				if ($label === '') {
					continue;
				}
				$first_term = (string) ($search_terms[0] ?? '');
				$value = $first_term !== '' ? $first_term : $label;
				$values[] = [
					'label' => $label,
					'value' => $value,
					'search_terms' => $search_terms,
				];
			}
			if (!empty($values)) {
				$out[$clean_slug] = $values;
			}
		}
		return $out;
	}

	public function sanitize_table_options($options_raw): array {
		$options_raw = is_array($options_raw) ? $options_raw : [];
		return $this->merge_table_options($options_raw);
	}

	public function sanitize_chart_options($options_raw, array $columns): array {
		$options_raw = is_array($options_raw) ? $options_raw : [];
		return $this->merge_chart_options($options_raw, $columns);
	}

	public function get_table_options(array $definition): array {
		$saved = isset($definition['table_options']) && is_array($definition['table_options'])
			? $definition['table_options']
			: [];
		return $this->merge_table_options($saved);
	}

	public function get_chart_options(array $definition): array {
		$saved = isset($definition['chart']) && is_array($definition['chart'])
			? $definition['chart']
			: [];
		return $this->merge_chart_options($saved, $definition['columns'] ?? []);
	}

	/**
	 * Fill empty front-end control labels with their translated defaults before the options are
	 * serialized for the renderer. The stored/editor value stays '' (so the editor shows a blank
	 * field with the default as a placeholder); only the front-end payload gets the localized
	 * string, so non-English sites no longer fall back to the hardcoded English text in JS.
	 * The source strings match the JS fallbacks exactly, so en_US output is unchanged.
	 */
	public function localize_frontend_table_labels(array $options): array {
		$label_defaults = [
			'searchColumnsLabel'   => __('Columns', 'baratables'),
			'searchColumnsHeading' => __('Search in', 'baratables'),
			'buttonTextCopy'       => __('Copy', 'baratables'),
			'buttonTextCsv'        => __('Export CSV', 'baratables'),
			'buttonTextExcel'      => __('Export Excel', 'baratables'),
			'buttonTextPrint'      => __('Print', 'baratables'),
			'buttonTextColvis'     => __('Column visibility', 'baratables'),
			'buttonTextPagelength' => __('Page length', 'baratables'),
		];
		foreach ($label_defaults as $key => $default) {
			if (!isset($options[$key]) || $options[$key] === '') {
				$options[$key] = $default;
			}
		}
		return $options;
	}

	public function get_default_table_options(): array {
		return $this->get_table_option_defaults();
	}

	public function get_default_chart_options(): array {
		return self::CHART_OPTION_DEFAULTS;
	}

	private function merge_table_options(array $options_raw): array {
		$schema = self::get_table_option_schema();
		$options = $this->get_table_option_defaults();

		foreach ($schema as $key => $config) {
			if (!array_key_exists($key, $options_raw)) {
				continue;
			}
			$type = $config['type'] ?? '';
			if ($type === 'checkbox') {
				$options[$key] = !empty($options_raw[$key]);
			} elseif ($type === 'number') {
				$min = isset($config['min']) ? (int) $config['min'] : 0;
				$max = isset($config['max']) ? (int) $config['max'] : PHP_INT_MAX;
				$value = (int) $options_raw[$key];
				if ($value <= 0 && $min > 0) {
					$options[$key] = $config['default'];
				} else {
					$options[$key] = min(max($value, $min), $max);
				}
			} elseif ($type === 'text_html') {
				$options[$key] = $this->sanitize_inline_html($options_raw[$key]);
			} elseif ($type === 'checkbox_multi') {
				$choices = isset($config['choices']) && is_array($config['choices']) ? array_keys($config['choices']) : [];
				$options[$key] = $this->sanitize_checkbox_multi($options_raw[$key], $choices);
			}
		}

		return $options;
	}

	private function sanitize_checkbox_multi($raw, array $allowed): array {
		if (!is_array($raw)) {
			$raw = [$raw];
		}
		$clean = [];
		foreach ($raw as $val) {
			$slug = sanitize_key((string) $val);
			if ($slug !== '' && in_array($slug, $allowed, true) && !in_array($slug, $clean, true)) {
				$clean[] = $slug;
			}
		}
		return $clean;
	}

	private function merge_chart_options(array $options_raw, array $columns): array {
		$options = self::CHART_OPTION_DEFAULTS;
		$slug_map = [];
		foreach ($columns as $col) {
			if (is_array($col)) {
				$slug = $this->resolve_column_slug($col);
			} else {
				$slug = sanitize_text_field((string) $col);
			}
			if ($slug === '') {
				continue;
			}
			$slug_map[$slug] = true;
		}

		if (!empty($options_raw['type']) && in_array($options_raw['type'], BaraTables_Chart_Types::slugs(), true)) {
			$options['type'] = $options_raw['type'];
		}
		if (!empty($options_raw['stack'])) {
			$options['stack'] = true;
		}
		if (!empty($options_raw['position']) && in_array($options_raw['position'], ['above', 'below'], true)) {
			$options['position'] = $options_raw['position'];
		}
		if (isset($options_raw['height'])) {
			$height = (int) $options_raw['height'];
			if ($height >= 120) {
				$options['height'] = min($height, 2000);
			}
		}

		$x_axis = isset($options_raw['x_axis']) ? sanitize_text_field((string) $options_raw['x_axis']) : '';
		if ($x_axis !== '' && isset($slug_map[$x_axis])) {
			$options['x_axis'] = $x_axis;
		}

		$series_raw = isset($options_raw['series']) ? (array) $options_raw['series'] : [];
		$series = [];
		foreach ($series_raw as $slug) {
			$clean = sanitize_text_field((string) $slug);
			if ($clean !== '' && isset($slug_map[$clean]) && $clean !== $options['x_axis'] && !in_array($clean, $series, true)) {
				$series[] = $clean;
			}
		}
		$options['series'] = $series;

		// Pie, donut, and funnel are single-series: if the user picked an X-axis but no series,
		// auto-pick the first other column so the chart renders instead of showing "not configured".
		if (in_array($options['type'], ['pie', 'donut', 'funnel'], true) && !empty($options['x_axis']) && empty($options['series']) && !empty($slug_map)) {
			foreach (array_keys($slug_map) as $slug) {
				if ($slug !== $options['x_axis']) {
					$options['series'] = [$slug];
					break;
				}
			}
		}

		// Stacking is meaningless for pie-like, point (scatter/bubble), and Gantt charts; force it
		// off so the saved flag can't diverge from what the renderer actually does.
		if (in_array($options['type'], ['pie', 'donut', 'funnel', 'scatter', 'bubble', 'gantt'], true)) {
			$options['stack'] = false;
		}

		$gantt_keys = [
			'gantt_label',
			'gantt_start',
			'gantt_end',
			'gantt_group',
			'gantt_progress',
		];
		foreach ($gantt_keys as $key) {
			if (!empty($options_raw[$key])) {
				$slug = sanitize_text_field((string) $options_raw[$key]);
				if ($slug !== '' && isset($slug_map[$slug])) {
					$options[$key] = $slug;
				}
			}
		}

		return $options;
	}


	public function sanitize_taxonomy_filter(array $post_types, $taxonomy_raw, array $terms_raw): array {
		$post_types = $this->sanitize_post_types($post_types, 'wp_query');
		$taxonomies_raw = is_array($taxonomy_raw) ? $taxonomy_raw : [$taxonomy_raw];
		$out = [];
		foreach ($taxonomies_raw as $tax_raw) {
			$taxonomy = sanitize_key($tax_raw);
			if ($taxonomy === '') {
				continue;
			}
			$valid_pt = false;
			foreach ($post_types as $pt) {
				if (taxonomy_exists($taxonomy) && is_object_in_taxonomy($pt, $taxonomy)) {
					$valid_pt = true;
					break;
				}
			}
			if (!$valid_pt) {
				continue;
			}
			$taxonomy_terms_raw = isset($terms_raw[$taxonomy]) ? (array) $terms_raw[$taxonomy] : [];
			$term_ids = array_values(array_unique(array_filter(array_map('intval', $taxonomy_terms_raw))));
			if (empty($term_ids)) {
				continue;
			}

			$out[] = [
				'taxonomy' => $taxonomy,
				'terms'    => $term_ids,
				'field'    => 'term_id',
				'operator' => 'IN',
			];
		}

		return $out;
	}

	public function sanitize_post_types(array $post_types_raw, string $source_type): array {
		if (!BaraTables_Source_Type::supports_post_type_selection($source_type)) {
			return ['post'];
		}
		return $this->sanitize_public_post_types($post_types_raw, true);
	}

	public function prepare_columns_from_request(array $columns_raw, string $custom_meta_raw, string $column_order_raw = ''): array {
		$columns = $columns_raw;

		$custom_meta = array_filter(array_map('trim', explode(',', $custom_meta_raw)));
		foreach ($custom_meta as $meta_key) {
			$columns[] = 'meta:' . sanitize_key($meta_key);
		}

		$columns = array_unique(array_filter($columns));

		$order_list = $this->sanitize_order_list($column_order_raw);
		$order_map = [];
		foreach ($order_list as $idx => $slug) {
			$order_map[$slug] = $idx;
		}

		if (!empty($order_map)) {
			usort($columns, static function ($a, $b) use ($order_map) {
				$posA = array_key_exists($a, $order_map) ? $order_map[$a] : PHP_INT_MAX;
				$posB = array_key_exists($b, $order_map) ? $order_map[$b] : PHP_INT_MAX;
				if ($posA === $posB) {
					return 0;
				}
				return $posA < $posB ? -1 : 1;
			});
		}

		return $columns;
	}

	public function sanitize_order_list(string $raw): array {
		$order_list = array_filter(array_map('trim', explode(',', $raw)));
		$out = [];
		foreach ($order_list as $slug) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug !== '' && !in_array($clean_slug, $out, true)) {
				$out[] = $clean_slug;
			}
		}
		return $out;
	}

	public function build_columns(array $columns, array $filter_types, array $filter_sorts = [], array $filter_type_priority = [], array $custom_labels = [], array $filter_labels = [], array $hide_titles = [], array $hidden_columns = [], array $searchable = [], array $sort_priority = [], array $sort_direction = [], array $sort_enabled = [], array $sortable = [], array $filter_values = [], array $format_date_flags = [], array $date_formats = []): array {
		$out = [];
		foreach ($columns as $raw) {
			$filter_type = isset($filter_types[$raw]) ? $filter_types[$raw] : 'none';
			$filter_sort = isset($filter_sorts[$raw]) ? $filter_sorts[$raw] : 'asc';
			$data_type_priority = isset($filter_type_priority[$raw]) && is_array($filter_type_priority[$raw]) ? array_values($filter_type_priority[$raw]) : [];
			$custom_label = isset($custom_labels[$raw]) ? $custom_labels[$raw] : '';
			$filter_label = array_key_exists($raw, $filter_labels) ? $filter_labels[$raw] : null;
			$hide_title = !empty($hide_titles[$raw]);
			$hidden = !empty($hidden_columns[$raw]);
			$is_searchable = array_key_exists($raw, $searchable) ? (bool) $searchable[$raw] : true;
			$priority = isset($sort_priority[$raw]) ? (int) $sort_priority[$raw] : 0;
			$direction = isset($sort_direction[$raw]) ? $sort_direction[$raw] : 'asc';
			$sort_is_enabled = array_key_exists($raw, $sort_enabled) ? (bool) $sort_enabled[$raw] : ($priority > 0);
			$is_sortable = array_key_exists($raw, $sortable) ? (bool) $sortable[$raw] : true;
			if (!$sort_is_enabled) {
				$priority = 0;
			}
			$custom_filter_values = isset($filter_values[$raw]) && is_array($filter_values[$raw]) ? array_values($filter_values[$raw]) : [];
			if ($filter_type === 'none') {
				$custom_filter_values = [];
			}
			$date_format = isset($date_formats[$raw]) ? (string) $date_formats[$raw] : '';
			$format_date = $date_format !== '' || !empty($format_date_flags[$raw]);
			$out[] = $this->normalize_column($raw, $filter_type, $filter_sort, $custom_label, $filter_label, $hide_title, $hidden, $is_searchable, $priority, $direction, $sort_is_enabled, $is_sortable, $custom_filter_values, $data_type_priority, $format_date, $date_format);
		}
		return $out;
	}

	public function normalize_column(string $raw, string $filter_type = 'none', string $filter_sort = 'asc', string $custom_label = '', ?string $filter_label = null, bool $hide_title = false, bool $hidden = false, bool $searchable = true, int $sort_priority = 0, string $sort_direction = 'asc', bool $sort_enabled = false, bool $sortable = true, array $filter_values = [], array $filter_type_priority = [], bool $format_date = false, string $date_format = ''): array {
		$parts = explode(':', $raw);
		$source_raw = count($parts) > 1 ? array_shift($parts) : 'core';
		$source = sanitize_key($source_raw);
		if ($source === '') {
			$source = 'core';
		}
		$allowed_sources = ['core', 'meta', 'csv', 'tax', 'external', 'custom'];
		if (!in_array($source, $allowed_sources, true)) {
			$source = 'core';
		}
		$key    = implode(':', $parts);

		// Manual (custom) columns default to a positional "Column N" -- matching the picker
		// and the grid -- rather than the key-derived name used by other sources.
		$default_label = ucwords(str_replace(['_', '-'], ' ', $key));
		if ($source === 'custom' && preg_match('/^col_(\d+)$/', $key, $auto_match)) {
			$default_label = sprintf('Column %d', (int) $auto_match[1]);
		}

		// Auto-label is decided purely by whether the user supplied a heading: the gear
		// field submits an empty string when left at its placeholder default. No
		// string-matching of the label text -- the flag is the single source of truth that
		// display_column_label reads at render.
		$auto_label = ($source === 'custom' && $custom_label === '');

		$label_raw = $custom_label !== '' ? $custom_label : $default_label;
		$label = $this->sanitize_inline_html($label_raw);
		if ($label === '') {
			$label = $default_label;
			$auto_label = ($source === 'custom');
		}
		$filter_label_raw = $filter_label === null ? $label : $filter_label;
		$filter_label_clean = $this->sanitize_inline_html($filter_label_raw);
		$filter_label_value = $filter_label === null ? ($filter_label_clean !== '' ? $filter_label_clean : $label) : $filter_label_clean;

		$filter_sort = $filter_sort === 'none' ? 'custom' : $filter_sort;
		$filter_sort = in_array($filter_sort, ['asc', 'desc', 'custom'], true) ? $filter_sort : 'asc';

		return [
			'key'    => $key,
			'label'  => $label,
			'auto_label' => $auto_label,
			'filter_label' => $filter_label_value,
			'source' => $source,
			'filter' => in_array($filter_type, ['dropdown', 'dropdown_multi', 'dropdown_plain', 'dropdown_plain_multi', 'checkbox', 'radio'], true) ? $filter_type : 'none',
			'filter_sort' => $filter_sort,
			'slug'   => $source . ':' . $key,
			'hide_title' => $hide_title,
			'hidden' => $hidden,
			'searchable' => $searchable,
			'sort_priority' => $sort_priority > 0 ? $sort_priority : 0,
			'sort_direction' => in_array($sort_direction, ['asc', 'desc'], true) ? $sort_direction : 'asc',
			'sort_enabled' => $sort_enabled,
			'sortable' => $sortable,
			'filter_values' => array_values($filter_values),
			'filter_type_priority' => $this->normalize_data_type_priority_list($filter_type_priority),
			'format_date' => $format_date,
			'date_format' => $date_format,
		];
	}

	private function resolve_column_slug(array $col): string {
		if (!empty($col['slug'])) {
			return (string) $col['slug'];
		}
		$source = isset($col['source']) ? (string) $col['source'] : 'core';
		$key = isset($col['key']) ? (string) $col['key'] : '';
		return self::build_slug($source, $key);
	}

	/**
	 * Drop only empty-string entries, re-indexed.
	 *
	 * array_filter() with no callback also drops "0", which silently broke shareable filter
	 * links against 0/1 columns (?btbl_filter[csv:in_stock]=0 became an empty selection).
	 * The rest of this file already uses an explicit `!== ''` test for the same reason.
	 *
	 * @param array $values
	 * @return array
	 */
	private static function filter_non_empty(array $values): array {
		return array_values(array_filter($values, static function ($value) {
			return $value !== '';
		}));
	}

	public static function build_slug(string $source, string $key): string {
		$clean_source = sanitize_key($source);
		$source_part = $clean_source !== '' ? $clean_source : 'core';
		return $source_part . ':' . $key;
	}

	public static function normalize_csv_column_sources(array &$columns): void {
		foreach ($columns as &$col) {
			$col_source = isset($col['source']) ? sanitize_key((string) $col['source']) : 'core';
			if ($col_source === '' || $col_source === 'core') {
				$col['source'] = 'csv';
			}
			if (!empty($col['slug']) && strpos($col['slug'], 'core:') === 0) {
				$col['slug'] = 'csv:' . substr((string) $col['slug'], 5);
			} elseif (empty($col['slug']) && !empty($col['key'])) {
				$col['slug'] = 'csv:' . $col['key'];
			}
		}
		unset($col);
	}

	public function sanitize_column_flags(array $raw, array $columns = [], bool $default = false): array {
		$slugs = !empty($columns) ? $columns : null;
		return $this->sanitize_bool_flags($raw, $slugs, $default);
	}

	public function sanitize_date_formats(array $formats_raw): array {
		$out = [];
		foreach ($formats_raw as $slug => $format) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$clean_format = sanitize_text_field((string) $format);
			if ($clean_format !== '') {
				$out[$clean_slug] = $clean_format;
			}
		}
		return $out;
	}

	public function sanitize_sort_enabled(array $enabled_raw, array $columns): array {
		return $this->sanitize_bool_flags($enabled_raw, $columns, false);
	}

	public function sanitize_sort_priority(array $priorities_raw): array {
		$out = [];
		foreach ($priorities_raw as $slug => $priority) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$prio = (int) $priority;
			if ($prio > 0) {
				$out[$clean_slug] = $prio;
			}
		}
		return $out;
	}

	public function sanitize_sort_direction(array $directions_raw): array {
		$out = [];
		foreach ($directions_raw as $slug => $dir) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$clean_dir = in_array(sanitize_key($dir), ['asc', 'desc'], true) ? sanitize_key($dir) : 'asc';
			$out[$clean_slug] = $clean_dir;
		}
		return $out;
	}

	public function sanitize_custom_data(array $column_labels_raw, $rows_raw, int $rows_count = 0, int $cols_count = 0): array {
		$dataset = $this->build_custom_dataset($column_labels_raw, $rows_raw, $rows_count, $cols_count);

		return [
			'columns' => $dataset['columns'],
			'rows' => $dataset['rows'],
			'slugs' => $dataset['slugs'],
		];
	}

	public function build_custom_dataset(array $column_labels_raw, $rows_raw, int $rows_count = 0, int $cols_count = 0): array {
		$rows_raw = is_array($rows_raw) ? $rows_raw : [];

		$column_count = $cols_count > 0 ? $cols_count : count($column_labels_raw);
		if ($column_count <= 0) {
			$column_count = 3;
		}
		$column_count = min($column_count, self::MAX_CUSTOM_COLUMNS);

		$columns = [];
		for ($i = 0; $i < $column_count; $i++) {
			$label_raw = $column_labels_raw[$i] ?? '';
			// Store an empty string for unnamed columns rather than baking "Column N":
			// the positional default is supplied at render. Keeping it empty preserves the
			// "the user gave no name" signal so the column is flagged auto_label at save.
			$columns[] = $this->sanitize_inline_html((string) $label_raw);
		}

		$target_rows = $rows_count > 0 ? $rows_count : count($rows_raw);
		if ($target_rows <= 0) {
			$target_rows = 5;
		}
		$target_rows = min($target_rows, self::MAX_CUSTOM_ROWS);
		$target_rows = min($target_rows, max(1, intdiv(self::MAX_CUSTOM_CELLS, $column_count)));

		$rows = [];
		for ($r = 0; $r < $target_rows; $r++) {
			$row_source = isset($rows_raw[$r]) && is_array($rows_raw[$r]) ? $rows_raw[$r] : [];
			$row = [];
			for ($c = 0; $c < $column_count; $c++) {
				$cell_raw = $row_source[$c] ?? '';
				$cell = is_scalar($cell_raw) ? wp_kses_post((string) $cell_raw) : '';
				$row[] = $cell;
			}
			$rows[] = $row;
		}

		$slugs = [];
		for ($i = 0; $i < $column_count; $i++) {
			$slugs[] = 'custom:col_' . ($i + 1);
		}

		return [
			'columns' => $columns,
			'rows' => $rows,
			'slugs' => $slugs,
			'rows_count' => $target_rows,
			'cols_count' => $column_count,
		];
	}

	/**
	 * Resolve a column's display header, localized at render time.
	 *
	 * Auto-ness comes solely from the explicit `auto_label` flag (set at save when the user
	 * leaves a manual column's heading blank) or a genuinely empty label -- never from
	 * pattern-matching the label text.
	 *
	 * On en_US "Column %d" returns the identical string, so English tables render unchanged;
	 * user-named labels and non-manual sources are never touched.
	 */
	public function display_column_label(array $col, int $index, string $source_type = ''): string {
		$label = (string) ($col['label'] ?? '');
		if ($label === '') {
			/* translators: %d is the column number. */
			return sprintf(__('Column %d', 'baratables'), $index + 1);
		}
		if (BaraTables_Source_Type::is_custom_data($source_type) && !empty($col['auto_label'])) {
			/* translators: %d is the column number. */
			return sprintf(__('Column %d', 'baratables'), $index + 1);
		}
		return $label;
	}

	/**
	 * Forward-fix chart links after a table's id changes. Charts store their link as the
	 * table's id (slug); this rewrites any chart pointing at $old_id to $new_id so the
	 * link survives the rename without leaving an alias behind. Returns the count updated.
	 */
	public function rewrite_chart_table_id(string $old_id, string $new_id): int {
		if ($old_id === '' || $new_id === '' || $old_id === $new_id) {
			return 0;
		}
		// Include 'trash' (which get_posts excludes under 'any'): a trashed chart that is
		// later restored must still point at the renamed table, not a dead id.
		// get_posts() already defaults suppress_filters to true, so this internal maintenance
		// lookup is not altered by third-party query filters without setting it explicitly.
		$ids = get_posts([
			'post_type' => BaraTables_Chart_Repository::CPT,
			'post_status' => ['publish', 'draft', 'pending', 'future', 'private', 'trash'],
			'numberposts' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
		]);
		// Prime the meta cache in one query instead of letting the loop below issue a separate
		// get_post_meta() lookup per chart. Same pattern (and reason) as
		// BaraTables_Base_Repository::query_items_common().
		if (!empty($ids)) {
			_prime_post_caches($ids, false, true);
		}
		$updated = 0;
		foreach ($ids as $id) {
			$chart = get_post_meta((int) $id, BaraTables_Chart_Repository::META_KEY, true);
			if (!is_array($chart) || ($chart['table_id'] ?? '') !== $old_id) {
				continue;
			}
			$chart['table_id'] = $new_id;
			update_post_meta((int) $id, BaraTables_Chart_Repository::META_KEY, $chart);
			$updated++;
		}
		return $updated;
	}


	public function build_custom_display_columns(array $labels): array {
		$labels = array_values($labels);
		if (empty($labels)) {
			// One empty label: build_columns_from_keys_and_labels() turns it into the
			// translated "Column 1". A literal here would ship untranslated.
			$labels = [''];
		}
		$keys = [];
		foreach ($labels as $idx => $_) {
			$keys[] = 'col_' . ($idx + 1);
		}
		return $this->build_columns_from_keys_and_labels($keys, $labels, 'custom');
	}

	private function build_columns_from_keys_and_labels(array $keys, array $labels, string $source): array {
		$columns = [];
		$used_keys = [];
		foreach ($keys as $idx => $key_raw) {
			$key = sanitize_key((string) $key_raw);
			if ($key === '') {
				$key = 'col_' . ($idx + 1);
			}
			// Two headers that sanitize to the same key (e.g. "Region"/"region", "Q1"/"q1") would
			// otherwise share a slug, so row data keyed by slug collapses onto one column. Suffix
			// duplicates so each column keeps a distinct slug (csv:region, csv:region-2, ...).
			if (isset($used_keys[$key])) {
				$base = $key;
				$n = 2;
				do {
					$key = $base . '-' . $n;
					$n++;
				} while (isset($used_keys[$key]));
			}
			$used_keys[$key] = true;
			$label_raw = $labels[$idx] ?? '';
			/* translators: %d is the column number. */
			$label = $label_raw !== '' ? (string) $label_raw : sprintf(__('Column %d', 'baratables'), $idx + 1);
			$columns[] = [
				'key' => $key,
				'label' => $label,
				'filter' => 'none',
				'filter_sort' => 'asc',
				'slug' => $source . ':' . $key,
				'source' => $source,
				'hide_title' => false,
				'hidden' => false,
				'searchable' => true,
				'sort_priority' => 0,
				'sort_direction' => 'asc',
				'sort_enabled' => false,
				'sortable' => true,
			];
		}
		return $columns;
	}

	private function build_column_definitions_from_assoc(array $keys, string $source): array {
		$labels = [];
		foreach ($keys as $key) {
			$key_safe = sanitize_key((string) $key);
			// $labels is positional against $keys, so an unlabelable key must still occupy its
			// slot. Skipping it shifted every later label up by one, so an external table with a
			// non-ASCII column name rendered the remaining columns under the wrong headings.
			// build_columns_from_keys_and_labels() turns '' into the translated "Column N".
			$labels[] = $key_safe === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $key_safe));
		}
		return $this->build_columns_from_keys_and_labels($keys, $labels, $source);
	}


	/**
	 * Which row-token field the given source actually reads. Empty string means the source has no
	 * row-level access control at all (manual data is filtered by no branch of get_rows()).
	 */
	public static function access_token_field_for_source(string $source_type): string {
		if (BaraTables_Source_Type::is_custom_data($source_type)) {
			return '';
		}
		if (BaraTables_Source_Type::is_csv($source_type)) {
			return 'csv_column';
		}
		if (BaraTables_Source_Type::is_external_db($source_type)) {
			return 'external_column';
		}
		return 'post_meta_key';
	}

	public function sanitize_access_control(array $raw, string $source_type = ''): array {
		$user_meta_key = isset($raw['user_meta_key']) ? sanitize_text_field($raw['user_meta_key']) : '';
		$post_meta_key = isset($raw['post_meta_key']) ? sanitize_text_field($raw['post_meta_key']) : '';
		$csv_column = isset($raw['csv_column']) ? sanitize_text_field($raw['csv_column']) : '';
		$external_column = isset($raw['external_column']) ? sanitize_text_field($raw['external_column']) : '';
		// Store only the row-token field the active source can enforce. The editor hides the other
		// fields with CSS but never disables them, so they re-POST their saved values: a policy set
		// up under one source used to survive a switch to another, where no branch of get_rows()
		// reads it. The Advanced tab still showed it configured while every row served to the
		// public. Dropping the inapplicable fields keeps stored state and enforced state identical.
		if ($source_type !== '') {
			$token_field = self::access_token_field_for_source($source_type);
			$post_meta_key = $token_field === 'post_meta_key' ? $post_meta_key : '';
			$csv_column = $token_field === 'csv_column' ? $csv_column : '';
			$external_column = $token_field === 'external_column' ? $external_column : '';
		}
		// Fail closed. This whole block only runs once a row-token field is set, i.e. the admin
		// has asked for row-level restriction -- defaulting anonymous visitors to 'all' made the
		// feature a no-op for the public until a second, separate dropdown was also changed, so
		// restricted rows stayed world-readable. An explicitly saved 'all' is still honoured.
		$logged_out = isset($raw['logged_out']) && in_array($raw['logged_out'], ['all', 'public_only', 'none'], true)
			? $raw['logged_out']
			: 'public_only';
		if ($post_meta_key === '' && $csv_column === '' && $external_column === '') {
			return [];
		}
		return [
			'user_meta_key' => $user_meta_key,
			'post_meta_key' => $post_meta_key,
			'csv_column' => $csv_column,
			'external_column' => $external_column,
			'logged_out' => $logged_out,
		];
	}

	public function sanitize_external_db_config(array $raw): array {
		$host = isset($raw['host']) ? sanitize_text_field($raw['host']) : '';
		$dbname = isset($raw['name']) ? sanitize_text_field($raw['name']) : '';
		$user = isset($raw['user']) ? sanitize_text_field($raw['user']) : '';
		$password = isset($raw['pass']) ? (string) $raw['pass'] : '';
		$table = isset($raw['table']) ? $this->sanitize_external_identifier((string) $raw['table']) : '';
		$charset = isset($raw['charset']) ? sanitize_text_field($raw['charset']) : '';
		$port = isset($raw['port']) ? min(max((int) $raw['port'], 0), 65535) : 0;
		if ($host === '' || $dbname === '' || $user === '' || $table === '') {
			return [];
		}
		$config = [
			'host' => $host,
			'name' => $dbname,
			'user' => $user,
			'table' => $table,
			'charset' => $charset,
			'port' => $port,
		];
		if ($password !== '') {
			$encrypted = BaraTables_Crypto::encrypt($password);
			if ($encrypted !== '') {
				$config['pass'] = $encrypted;
			}
		}
		return $config;
	}

	private function sanitize_external_identifier(string $identifier): string {
		$identifier = trim($identifier);
		if ($identifier === '') {
			return '';
		}
		$identifier = trim($identifier, " \t\n\r\0\x0B`");
		if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
			return '';
		}
		return $identifier;
	}

	private function create_external_db_connection(string $user, string $password, string $dbname, string $host) {
		if (!class_exists('wpdb')) {
			return null;
		}

		$external_db = new class($user, $password, $dbname, $host) extends wpdb {
			public function __construct($dbuser, $dbpassword, $dbname, $dbhost) {
				$this->dbuser = $dbuser;
				$this->dbpassword = $dbpassword;
				$this->dbname = $dbname;
				$this->dbhost = $dbhost;
				$this->hide_errors();
				$this->db_connect(false);
			}

			// Overrides wpdb::bail(); the signature is fixed by core, and neither argument is
			// wanted here -- a failed external connection must fail silently rather than print
			// WordPress's database-error page over the site.
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- core signature.
			public function bail($message, $error_code = '500') {
				$this->ready = false;
				return false;
			}
		};

		return !empty($external_db->dbh) && !empty($external_db->ready) ? $external_db : null;
	}

	public function find_definition(string $id, bool $require_publish = false): ?array {
		$defn = $this->repo->find_definition($id);
		if (!$defn) {
			return null;
		}
		if (empty($defn['source_type'])) {
			$defn['source_type'] = BaraTables_Source_Type::WP_QUERY;
		}
		if (!isset($defn['columns']) || !is_array($defn['columns'])) {
			$defn['columns'] = [];
		}
		if (empty($defn['status'])) {
			$defn['status'] = 'publish';
		}
		if ($defn['status'] === 'trash') {
			return null;
		}
		if (BaraTables_Source_Type::is_csv($defn['source_type']) && !empty($defn['columns']) && is_array($defn['columns'])) {
			self::normalize_csv_column_sources($defn['columns']);
		}
		if ($require_publish && $defn['status'] !== 'publish') {
			return null;
		}
		return $defn;
	}

	public function get_definition_post_id(string $id): int {
		return $this->repo->get_post_id_by_slug($id);
	}

	public function get_rows(array $definition, int $limit = -1): array {
		$this->last_inferred_columns = null;
		$definition['source_type'] = BaraTables_Source_Type::normalize($definition['source_type'] ?? BaraTables_Source_Type::WP_QUERY, BaraTables_Source_Type::WP_QUERY);
		$definition['columns'] = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		$access = isset($definition['access_control']) && is_array($definition['access_control']) ? $definition['access_control'] : [];
		// Definitions saved before sanitize_access_control() became source-aware can still hold a
		// policy whose row-token field belongs to a different source. No branch below reads it, so
		// the rows would publish unrestricted. Serve nothing instead: the admin asked for row-level
		// restriction, and an empty table is a visible problem where a quietly unguarded one is not.
		// Re-saving the table clears the orphaned field and restores normal rendering.
		if (!empty($access)) {
			$token_field = self::access_token_field_for_source($definition['source_type']);
			if ($token_field === '' || empty($access[$token_field])) {
				return [];
			}
		}
		$access_policy = $this->build_access_policy($access);
		$table_options = $this->get_table_options($definition);
		$configured_limit = max(1, (int) ($table_options['rowLimit'] ?? self::TABLE_OPTION_SCHEMA['rowLimit']['default']));
		$row_limit = $limit > 0 ? min($limit, $configured_limit) : $configured_limit;

		if (BaraTables_Source_Type::is_custom_data($definition['source_type'])) {
			return $this->get_rows_from_custom($definition, $row_limit);
		}

		if (BaraTables_Source_Type::is_external_db($definition['source_type'])) {
			return $this->get_rows_from_external($definition, $row_limit, $access_policy);
		}

		if (BaraTables_Source_Type::is_csv($definition['source_type'])) {
			$csv_access_enabled = !empty($access_policy['csv_column']);
			return $this->get_rows_from_csv($definition, $row_limit, $access_policy, $csv_access_enabled);
		}

		$per_page = $row_limit;

		$post_types_raw = isset($definition['post_types']) && is_array($definition['post_types']) && !empty($definition['post_types'])
			? array_values(array_filter($definition['post_types']))
			: [$definition['post_type'] ?? 'post'];
		$post_types = $this->sanitize_public_post_types($post_types_raw, true);
		$query_args = [
			'post_type'      => $post_types,
			'posts_per_page' => $per_page,
			'no_found_rows'  => true,
			'post_status'    => 'publish',
		];

		if ($definition['source_type'] === BaraTables_Source_Type::CUSTOM_QUERY) {
			if (empty($definition['custom_query']) || !is_array($definition['custom_query'])) {
				return [];
			}
			$query_args = $this->sanitize_wp_query_args($definition['custom_query']);
			if (empty($query_args)) {
				return [];
			}
			if ($per_page > 0) {
				$query_args['posts_per_page'] = isset($query_args['posts_per_page'])
					? min((int) $query_args['posts_per_page'], $per_page)
					: $per_page;
			}
		}

		if (!empty($access_policy['post_meta_key'])) {
			$meta_query = $this->build_access_meta_query($access_policy['post_meta_key'], $access_policy);
			if ($meta_query === 'none') {
				return [];
			}
			if (!empty($meta_query)) {
				$query_args = $this->append_meta_query($query_args, $meta_query);
			}
		}

		if ($definition['source_type'] !== BaraTables_Source_Type::CUSTOM_QUERY) {
			$tax_query = $this->build_tax_query($definition);
			if (!empty($tax_query)) {
				if (!empty($query_args['tax_query']) && is_array($query_args['tax_query'])) {
					$query_args['tax_query'] = array_merge($query_args['tax_query'], $tax_query); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for taxonomy filtering.
				} else {
					$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for taxonomy filtering.
				}
			}
		}

		$query = new WP_Query($query_args);

		// If an author column is selected, prime the author user-caches in one query so the
		// get_the_author_meta() call in the row loop is not a per-author lookup (mild N+1).
		$wants_author = false;
		foreach ($definition['columns'] as $col) {
			if (($col['source'] ?? '') === 'core' && ($col['key'] ?? '') === 'post_author') {
				$wants_author = true;
				break;
			}
		}
		if ($wants_author && !empty($query->posts)) {
			cache_users(array_unique(array_map('intval', wp_list_pluck($query->posts, 'post_author'))));
		}

		$rows = [];
		foreach ($query->posts as $post) {
			if (!empty($access_policy['post_meta_key']) && !$this->post_passes_access_policy($post, $access_policy)) {
				continue;
			}
			$row = [];
			foreach ($definition['columns'] as $col) {
				$raw_value = $this->resolve_value($post, $col);
				$slug = $this->resolve_column_slug($col);
				$row[] = $this->apply_overrides($raw_value, $slug, $definition['value_overrides'] ?? [], $post);
			}
			$rows[] = $row;
		}

		wp_reset_postdata();
		return $rows;
	}

	private function get_rows_from_custom(array $definition, int $limit = -1): array {
		$custom = isset($definition['custom_data']) && is_array($definition['custom_data']) ? $definition['custom_data'] : [];
		$labels = isset($custom['columns']) && is_array($custom['columns']) ? array_values($custom['columns']) : [];
		$rows_raw = isset($custom['rows']) && is_array($custom['rows']) ? $custom['rows'] : [];

		$column_defs = $this->build_custom_display_columns($labels);
		$column_slugs = [];
		foreach ($column_defs as $col) {
			$column_slugs[] = $this->resolve_column_slug($col);
		}
		$overrides = isset($definition['value_overrides']) && is_array($definition['value_overrides'])
			? $definition['value_overrides']
			: [];
		// Date formatting lives on $definition['columns'] (the configured columns carrying the
		// "Format as date" toggle), keyed by slug. The wp_query path applies it via resolve_value();
		// mirror that here so manual-data date columns format too. slug => date_format string.
		$date_format_map = [];
		$definition_columns = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		foreach ($definition_columns as $col) {
			if (!is_array($col) || empty($col['format_date'])) {
				continue;
			}
			$slug = $this->resolve_column_slug($col);
			if ($slug !== '') {
				$date_format_map[$slug] = isset($col['date_format']) ? (string) $col['date_format'] : '';
			}
		}
		$this->last_inferred_columns = $column_defs;
		$column_count = count($column_defs);
		if ($column_count === 0) {
			return [];
		}

		$rows = [];
		foreach ($rows_raw as $row) {
			$values = is_array($row) ? $row : [];
			$normalized = [];
			for ($i = 0; $i < $column_count; $i++) {
				$value = $values[$i] ?? '';
				$normalized[] = is_scalar($value) ? (string) $value : '';
			}
			if (!empty($date_format_map)) {
				foreach ($column_slugs as $idx => $slug) {
					if ($slug === '' || !array_key_exists($slug, $date_format_map)) {
						continue;
					}
					$normalized[$idx] = $this->format_date_value($normalized[$idx] ?? '', $date_format_map[$slug]);
				}
			}
			if (!empty($overrides)) {
				$row_tokens = [];
				foreach ($column_slugs as $idx => $slug) {
					if ($slug === '') {
						continue;
					}
					$value = $normalized[$idx] ?? '';
					$lower_slug = strtolower($slug);
					$row_tokens[$lower_slug] = $value;
					if (strpos($slug, ':') !== false) {
						$key = substr($slug, strpos($slug, ':') + 1);
						if ($key !== '') {
							$row_tokens[strtolower($key)] = $value;
						}
					}
				}
				foreach ($column_slugs as $idx => $slug) {
					if ($slug === '') {
						continue;
					}
					$normalized[$idx] = $this->apply_overrides_for_row($normalized[$idx] ?? '', $slug, $overrides, $row_tokens);
				}
			}
			$rows[] = $normalized;
			if ($limit > 0 && count($rows) >= $limit) {
				break;
			}
		}

		if (!empty($definition['columns'])) {
			$slug_map = $this->build_slug_index_map($column_defs);
			return $this->reorder_rows_by_slug_map($rows, $definition['columns'], $slug_map);
		}

		return $rows;
	}

	private function get_rows_from_csv(array $definition, int $limit = -1, array $access_policy = [], bool $access_enabled = false): array {
		$attachment_id = isset($definition['csv_attachment_id']) ? (int) $definition['csv_attachment_id'] : 0;
		if ($attachment_id <= 0) {
			return [];
		}
		if (!$this->is_valid_csv_attachment($attachment_id)) {
			return [];
		}
		$path = get_attached_file($attachment_id);
		if (!$path || !file_exists($path) || !is_readable($path)) {
			return [];
		}
		$file_size = filesize($path);
		if ($file_size === false || $file_size > self::MAX_CSV_BYTES) {
			return [];
		}

		$has_header = !empty($definition['csv_has_header']);
		$delimiter = isset($definition['csv_delimiter']) && is_string($definition['csv_delimiter']) && strlen($definition['csv_delimiter']) === 1
			? $definition['csv_delimiter']
			: ',';

		// With row-level access control the limit must apply to the rows the visitor may SEE, not
		// to the file's first N lines -- otherwise a visitor whose permitted rows sit past line N
		// gets a short or empty table. So when access is enforced, read the whole file (already
		// bounded to MAX_CSV_BYTES above) and apply the limit after filtering, below. Without
		// access control, keep stopping at the limit so a large file is never fully read.
		$defer_limit = $access_enabled && !empty($access_policy['csv_column']);
		$rows = [];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fgetcsv requires a file handle; WP_Filesystem has no CSV parsing equivalent.
		$handle = fopen($path, 'rb');
		if ($handle !== false) {
			$count = 0;
			while (true) {
				$data = fgetcsv($handle, self::MAX_CSV_LINE_LENGTH, $delimiter, '"', '\\');
				if ($data === false) {
					break;
				}
				// fgetcsv() yields [null] for a blank physical line (a trailing newline, or a
				// spacer row). Counting it would render an all-empty <tr> and, worse, spend one
				// of the row-limit slots on it. The importer's own reader already skips this.
				if ($data === [null]) {
					continue;
				}
				if ($has_header && $count === 0) {
					$this->infer_columns_from_header($data);
					$count++;
					continue;
				}
				$rows[] = $data;
				$count++;
				if (!$defer_limit && $limit > 0 && $count >= $limit + ($has_header ? 1 : 0)) {
					break;
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle opened for fgetcsv; WP_Filesystem has no CSV parsing equivalent.
			fclose($handle);
		}

		if (empty($this->last_inferred_columns)) {
			$maxCols = 0;
			foreach ($rows as $row) {
				$maxCols = max($maxCols, is_array($row) ? count($row) : 0);
			}
			if ($maxCols > 0) {
				// Only the count matters: infer_columns_from_header() with $is_header = false
				// derives its own labels and ignores these values.
				$headers = array_fill(0, $maxCols, '');
				$this->infer_columns_from_header($headers, false);
			}
		}

		$inferred = $this->last_inferred_columns ?: [];
		$csv_index_map = $this->build_slug_index_map($inferred);

		// Access control is enforced regardless of whether display columns are configured, so
		// a CSV table with access control but no selected columns never returns unfiltered
		// rows (matching the external-DB path).
		if ($access_enabled && !empty($access_policy['csv_column'])) {
			$access_index = $this->resolve_csv_access_column_index($csv_index_map, (string) $access_policy['csv_column']);
			if ($access_index === null) {
				return [];
			}
			$rows = array_values(array_filter($rows, static function ($row) use ($access_index) {
				return is_array($row) && array_key_exists($access_index, $row);
			}));
			$rows = $this->filter_rows_by_access($rows, function ($row) use ($access_index) {
				return $row[$access_index];
			}, $access_policy);
		}

		// Deferred limit: now that only visible rows remain, trim to the configured ceiling.
		if ($defer_limit && $limit > 0 && count($rows) > $limit) {
			$rows = array_slice($rows, 0, $limit);
		}

		if (!empty($definition['columns'])) {
			$rows = $this->reorder_rows_by_slug_map($rows, $definition['columns'], $csv_index_map);
			$rows = $this->apply_ordered_date_formats($rows, $this->build_ordered_date_formats($definition['columns']));
			$rows = $this->apply_ordered_overrides($rows, $definition['columns'], $definition['value_overrides'] ?? []);
		}

		return $rows;
	}

	private function is_valid_csv_attachment(int $attachment_id): bool {
		$attachment = get_post($attachment_id);
		if (!$attachment || $attachment->post_type !== 'attachment') {
			return false;
		}

		$file = get_attached_file($attachment_id);
		if (!$file || !file_exists($file)) {
			// The attachment row can outlive its file (e.g. the file was deleted but the post
			// lingered); a missing file is not a usable CSV source.
			return false;
		}
		$file_type = wp_check_filetype((string) $file, ['csv' => 'text/csv']);
		if (($file_type['ext'] ?? '') !== 'csv') {
			return false;
		}

		$mime_type = (string) get_post_mime_type($attachment_id);
		return $mime_type === '' || in_array($mime_type, self::CSV_MIME_TYPES, true);
	}

	/**
	 * Match a configured access column against a lookup map's keys, returning the KEY that matched
	 * (or null). Shared by the CSV and external-DB resolvers, which ran the same four-step match --
	 * try raw/normalized/sanitized candidates directly, then via normalize_access_column_key() --
	 * against different lookups (a csv:-prefixed slug->index map, or a raw DB row). The candidate
	 * set is the union of both: extra candidates can only fail to match, never mis-match, because a
	 * bare candidate cannot key a prefixed map and vice versa, and the normalized pass reduces every
	 * form to the same token regardless.
	 *
	 * @param array<string,mixed> $lookup_map Keyed by the column identifiers to match against.
	 */
	private function resolve_access_column_key(array $lookup_map, string $column, string $source): ?string {
		$column = trim($column);
		$prefix = $source . ':';
		$normalized_column = (string) preg_replace('/^' . preg_quote($prefix, '/') . '/i', '', $column);
		$sanitized_column = sanitize_key($column);
		$sanitized_normalized = sanitize_key($normalized_column);
		$candidates = array_values(array_unique(array_filter([
			$column,
			$normalized_column,
			$sanitized_column,
			$sanitized_normalized,
			$normalized_column !== '' ? $prefix . $normalized_column : '',
			$sanitized_normalized !== '' ? $prefix . $sanitized_normalized : '',
		], static function ($candidate) {
			return $candidate !== '';
		})));

		foreach ($candidates as $candidate) {
			if (array_key_exists($candidate, $lookup_map)) {
				return (string) $candidate;
			}
		}

		$normalized_lookup = [];
		foreach (array_keys($lookup_map) as $key) {
			$normalized_key = $this->normalize_access_column_key((string) $key, $source);
			if ($normalized_key !== '' && !isset($normalized_lookup[$normalized_key])) {
				$normalized_lookup[$normalized_key] = (string) $key;
			}
		}

		foreach ($candidates as $candidate) {
			$normalized_candidate = $this->normalize_access_column_key((string) $candidate, $source);
			if ($normalized_candidate !== '' && isset($normalized_lookup[$normalized_candidate])) {
				return $normalized_lookup[$normalized_candidate];
			}
		}

		return null;
	}

	private function resolve_csv_access_column_index(array $csv_index_map, string $column): ?int {
		$key = $this->resolve_access_column_key($csv_index_map, $column, 'csv');
		return $key === null ? null : (int) $csv_index_map[$key];
	}

	private function get_rows_from_external(array $definition, int $limit = -1, array $access_policy = []): array {
		$config = isset($definition['external_db']) && is_array($definition['external_db']) ? $definition['external_db'] : [];
		$host = $config['host'] ?? '';
		$dbname = $config['name'] ?? '';
		$user = $config['user'] ?? '';
		$password = BaraTables_Crypto::decrypt($config['pass'] ?? '');
		$table = $config['table'] ?? '';
		$charset = $config['charset'] ?? '';
		$port = isset($config['port']) ? (int) $config['port'] : 0;
		if ($host === '' || $dbname === '' || $user === '' || $table === '') {
			return [];
		}
		$host_with_port = $port > 0 ? $host . ':' . $port : $host;
		$ext_db = $this->create_external_db_connection($user, $password, $dbname, $host_with_port);
		if (!$ext_db) {
			return [];
		}
		if ($charset !== '') {
			$ext_db->set_charset($ext_db->dbh, $charset);
		}
		$per_page = $limit > 0 ? $limit : (int) self::TABLE_OPTION_SCHEMA['rowLimit']['default'];
		$table = $this->sanitize_external_identifier((string) $table);
		if ($table === '' || !method_exists($ext_db, 'has_cap') || !$ext_db->has_cap('identifier_placeholders')) {
			return [];
		}

		// With row-level access control the LIMIT must bound the rows the visitor may SEE, not the
		// table's first N physical rows -- otherwise a visitor whose permitted rows sit past row N
		// gets a short or empty table. The rows are filtered in PHP after the fetch, and the source
		// table has no ORDER BY we can page on, so instead of LIMIT $per_page we fetch a bounded
		// superset (capped at the schema's 10,000-row maximum), filter, then slice to $per_page
		// below. Without access control the plain LIMIT is kept so a large table is never overread.
		$access_active = !empty($access_policy['external_column']);
		$fetch_limit = $access_active ? max($per_page, (int) self::TABLE_OPTION_SCHEMA['rowLimit']['max']) : $per_page;

		$sql = $ext_db->prepare('SELECT * FROM %i LIMIT %d', $table, $fetch_limit);
		if (!is_string($sql) || $sql === '') {
			return [];
		}
		$results = $ext_db->get_results($sql, ARRAY_A);
		if (!is_array($results) || empty($results)) {
			return [];
		}

		$columns_for_mapping = $definition['columns'];
		if (empty($columns_for_mapping)) {
			// An external table saved with no columns selected falls back to SELECT * -- which
			// would publish the access-token column itself to every visitor who can see the row.
			// Drop it from the inferred set; row-level filtering still uses it via $results.
			$result_keys = array_keys($results[0]);
			$token_column = (string) ($access_policy['external_column'] ?? '');
			if ($token_column !== '') {
				// Probing a single-key row reuses the reader's own resolution rules verbatim, so
				// the column dropped here is exactly the one the access filter reads.
				$result_keys = array_values(array_filter($result_keys, function ($key) use ($token_column) {
					return $this->resolve_external_row_key([$key => null], $token_column) === null;
				}));
			}
			$inferred = $this->build_column_definitions_from_assoc($result_keys, 'external');
			$this->last_inferred_columns = $inferred;
			$columns_for_mapping = $inferred;
		}

		$map = $this->build_slug_map($columns_for_mapping, function ($col) {
			return $col['key'] ?? '';
		});

		$eligible_rows = $results;

		if (!empty($access_policy['external_column'])) {
			$first_row = reset($eligible_rows);
			// Resolve the token column's real key once here; the key set is the same for every
			// row, and this doubles as the "column is missing -> deny everything" check.
			$token_key = is_array($first_row)
				? $this->resolve_external_row_key($first_row, (string) $access_policy['external_column'])
				: null;
			if ($token_key === null) {
				return [];
			}
			$eligible_rows = $this->filter_rows_by_access(
				$eligible_rows,
				static function ($row) use ($token_key) {
					return is_array($row) && array_key_exists($token_key, $row) ? $row[$token_key] : '';
				},
				$access_policy
			);
			// Deferred limit: the fetch pulled a superset, so trim to the configured ceiling now
			// that only visible rows remain.
			if ($per_page > 0 && count($eligible_rows) > $per_page) {
				$eligible_rows = array_slice($eligible_rows, 0, $per_page);
			}
		}

		$ordered = $this->reorder_external_rows_by_slug_map($eligible_rows, $columns_for_mapping, $map);
		$ordered = $this->apply_ordered_date_formats($ordered, $this->build_ordered_date_formats($columns_for_mapping));
		return $this->apply_ordered_overrides($ordered, $columns_for_mapping, $definition['value_overrides'] ?? []);
	}

	/**
	 * Resolve which key of an external result row corresponds to $column, or null if none does.
	 *
	 * Inferred column keys are sanitize_key()'d (build_columns_from_keys_and_labels) while the
	 * database's own keys are not, so a column named "Order Total" is stored as "ordertotal" and
	 * none of the direct candidates match -- resolve_access_column_key()'s normalized pass is the
	 * NORMAL path for any column with capitals, spaces or dots, not a rare edge case. That makes it
	 * expensive enough that callers must hoist it out of per-cell loops (see
	 * reorder_external_rows_by_slug_map).
	 */
	private function resolve_external_row_key(array $row, string $column): ?string {
		return $this->resolve_access_column_key($row, $column, 'external');
	}

	private function normalize_access_column_key(string $key, string $source): string {
		$key = preg_replace('/^' . preg_quote($source, '/') . ':/i', '', $key);
		$key = sanitize_key((string) $key);
		return preg_replace('/[^a-z0-9]/', '', $key);
	}

	private function reorder_external_rows_by_slug_map(array $rows, array $definition_columns, array $slug_map): array {
		if (empty($definition_columns) || empty($rows)) {
			return $rows;
		}
		// Every row in a SQL result set carries the identical key set, so resolve each definition
		// column to its actual row key ONCE against a probe row instead of re-running the
		// regex/sanitize_key-heavy matcher for every cell. At 10,000 rows x 12 columns that is
		// 12 resolutions instead of 120,000 (~554ms -> ~2ms).
		$probe = null;
		foreach ($rows as $row) {
			if (is_array($row)) {
				$probe = $row;
				break;
			}
		}
		if ($probe === null) {
			return $rows;
		}

		$resolved_keys = [];
		foreach ($definition_columns as $col) {
			$slug = $this->resolve_column_slug($col);
			$source_key = $slug_map[$slug] ?? null;
			$resolved_keys[] = $source_key !== null
				? $this->resolve_external_row_key($probe, (string) $source_key)
				: null;
		}

		$ordered_rows = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$ordered_row = [];
			foreach ($resolved_keys as $key) {
				// array_key_exists still guards a ragged row, matching the old per-row behaviour.
				$ordered_row[] = ($key !== null && array_key_exists($key, $row)) ? $row[$key] : '';
			}
			$ordered_rows[] = $ordered_row;
		}
		return $ordered_rows;
	}

	private function build_slug_map(array $columns, callable $value_resolver): array {
		$map = [];
		foreach ($columns as $idx => $col) {
			$slug = $this->resolve_column_slug($col);
			$map[$slug] = $value_resolver($col, $idx);
		}
		return $map;
	}

	private function build_slug_index_map(array $columns): array {
		// Built directly rather than through build_slug_map(): the resolver would only ever be
		// `fn($col, $idx) => $idx`, and three separate copies of that closure existed before.
		$map = [];
		foreach ($columns as $idx => $col) {
			$map[$this->resolve_column_slug($col)] = $idx;
		}
		return $map;
	}

	private function reorder_rows_by_slug_map(array $rows, array $definition_columns, array $slug_map): array {
		if (empty($definition_columns) || empty($rows)) {
			return $rows;
		}
		$ordered_rows = [];
		foreach ($rows as $row) {
			$ordered_row = [];
			foreach ($definition_columns as $col) {
				$slug = $this->resolve_column_slug($col);
				$source_index = $slug_map[$slug] ?? null;
				$ordered_row[] = ($source_index !== null && array_key_exists($source_index, $row)) ? $row[$source_index] : '';
			}
			$ordered_rows[] = $ordered_row;
		}
		return $ordered_rows;
	}

	private function build_access_policy(array $access): array {
		// Same fail-closed default as sanitize_access_control(): a definition stored before the
		// logged_out key existed must not expose restricted rows to anonymous visitors.
		$logged_out_policy_raw = $access['logged_out'] ?? 'public_only';
		$logged_out_policy = in_array($logged_out_policy_raw, ['all', 'public_only', 'none'], true) ? $logged_out_policy_raw : 'public_only';
		$post_meta_key = isset($access['post_meta_key']) ? sanitize_text_field($access['post_meta_key']) : '';
		$csv_column = isset($access['csv_column']) ? sanitize_text_field($access['csv_column']) : '';
		$external_column_raw = $access['external_column'] ?? '';
		$external_column = $external_column_raw !== '' ? sanitize_text_field($external_column_raw) : '';
		$needs_tokens = $post_meta_key !== '' || $csv_column !== '' || $external_column !== '';
		$user_tokens = $needs_tokens ? $this->get_user_tokens($access['user_meta_key'] ?? '') : [];

		return [
			'logged_out_policy' => $logged_out_policy,
			'user_tokens' => $user_tokens,
			'post_meta_key' => $post_meta_key,
			'csv_column' => $csv_column,
			'external_column' => $external_column,
		];
	}

	private function passes_access_tokens(array $row_tokens, array $user_tokens, string $logged_out_policy): bool {
		$is_logged_in = is_user_logged_in();
		$allow_public = $logged_out_policy !== 'none';
		if (empty($row_tokens)) {
			return $allow_public;
		}
		if (!$is_logged_in) {
			return $logged_out_policy === 'all';
		}
		if (empty($user_tokens)) {
			// A logged-in user with no matching tokens must not see a restricted (tokened) row.
			// The logged_out policy governs anonymous visitors only -- applying it here leaked
			// restricted CSV/external rows to logged-in users under logged_out='all'. Denying
			// matches build_access_meta_query (the WP_Query path), so all three sources agree.
			return false;
		}
		return (bool) array_intersect($row_tokens, $user_tokens);
	}

	private function post_passes_access_policy($post, array $access_policy): bool {
		$meta_key = isset($access_policy['post_meta_key']) ? sanitize_text_field($access_policy['post_meta_key']) : '';
		if ($meta_key === '' || empty($post->ID)) {
			return true;
		}
		$tokens = $this->normalize_tokens(get_post_meta((int) $post->ID, $meta_key, true));
		return $this->passes_access_tokens(
			$tokens,
			$access_policy['user_tokens'] ?? [],
			$access_policy['logged_out_policy'] ?? 'public_only'
		);
	}

	private function infer_columns_from_header(array $header_row, bool $is_header = true): void {
		$keys = [];
		$labels = [];
		foreach ($header_row as $idx => $label) {
			$key = $is_header ? sanitize_title((string) $label) : 'col_' . ($idx + 1);
			if ($key === '') {
				$key = 'col_' . ($idx + 1);
			}
			$keys[] = $key;
			// Empty (not a literal "Column N") so build_columns_from_keys_and_labels() supplies
			// the translated default -- a hardcoded English label here would survive to the
			// front end untranslated, because display_column_label() only localizes blank
			// labels and auto-labelled manual columns.
			$labels[] = $is_header ? (string) $label : '';
		}
		$this->last_inferred_columns = $this->build_columns_from_keys_and_labels($keys, $labels, 'csv');
	}

	public function get_last_inferred_columns(): ?array {
		return $this->last_inferred_columns;
	}

	public function ensure_columns_inferred(array $definition): array {
		$definition = is_array($definition) ? $definition : [];
		if (!empty($definition['columns'])) {
			return $definition;
		}
		$source = $definition['source_type'] ?? 'wp_query';
		if (BaraTables_Source_Type::is_csv($source)) {
			return $definition;
		}
		$inferred = $this->get_last_inferred_columns();
		if (!empty($inferred)) {
			$definition['columns'] = $inferred;
		}
		return $definition;
	}

	/**
	 * Same result as ensure_columns_inferred(), but without requiring the caller to have fetched
	 * rows first.
	 *
	 * ensure_columns_inferred() can only report what the LAST get_rows() call left behind, so its
	 * answer silently depends on whether something earlier in the same request happened to fetch
	 * rows. Callers that get that order wrong were told a table has no columns when the front end
	 * renders every one of them: an external-database table with nothing explicitly selected shows
	 * all of its columns publicly, while the editor's "Refresh preview" reported "No columns
	 * selected yet" and saving it warned "This table has no columns". Use this when rows are not
	 * being fetched anyway; use ensure_columns_inferred() directly straight after a get_rows().
	 */
	public function resolve_columns(array $definition): array {
		$definition = is_array($definition) ? $definition : [];
		if (!empty($definition['columns'])) {
			return $definition;
		}
		// CSV columns come from the stored header, not from a row fetch; ensure_columns_inferred()
		// deliberately declines to infer for that source, so there is nothing to prime.
		if (BaraTables_Source_Type::is_csv($definition['source_type'] ?? BaraTables_Source_Type::WP_QUERY)) {
			return $definition;
		}
		// One row is enough to learn the shape. The rows themselves are discarded.
		$this->get_rows($definition, 1);
		return $this->ensure_columns_inferred($definition);
	}

	public static function get_table_option_schema(): array {
		static $schema_with_labels = null;
		if ($schema_with_labels !== null) {
			return $schema_with_labels;
		}

		$schema = self::TABLE_OPTION_SCHEMA;
		$schema['paging']['label'] = __('Enable pagination', 'baratables');
		$schema['lengthChange']['label'] = __('Show per page selector', 'baratables');
		$schema['pagingNumbers']['label'] = __('Show page numbers', 'baratables');
		$schema['pagingFirstLast']['label'] = __('Show first/last', 'baratables');
		$schema['pagingPreviousNext']['label'] = __('Show previous/next', 'baratables');
		$schema['searchBox']['label'] = __('Show search box', 'baratables');
		$schema['searchColumns']['label'] = __('Show "Search In" dropdown', 'baratables');
		$schema['info']['label'] = __('Show result summary', 'baratables');
		$schema['infoText']['label'] = __('Summary text', 'baratables');
		$schema['infoEmpty']['label'] = __('Summary (no results)', 'baratables');
		$schema['infoFiltered']['label'] = __('Summary (filtered)', 'baratables');
		$schema['layoutTopStart']['label'] = __('Layout: top left', 'baratables');
		$schema['layoutTopEnd']['label'] = __('Layout: top right', 'baratables');
		$schema['layoutBottomStart']['label'] = __('Layout: bottom left', 'baratables');
		$schema['layoutBottomEnd']['label'] = __('Layout: bottom right', 'baratables');
		$schema['filtersTitle']['label'] = __('Show filters title', 'baratables');
		$schema['filtersTitleText']['label'] = __('Filters title text', 'baratables');
		$schema['ordering']['label'] = __('Allow column sorting', 'baratables');
		$schema['colReorder']['label'] = __('Allow column reordering', 'baratables');
		$schema['stateSave']['label'] = __('Remember table state', 'baratables');
		$schema['autoWidth']['label'] = __('Auto-size columns', 'baratables');
		$schema['scrollX']['label'] = __('Enable horizontal scrolling', 'baratables');
		$schema['scrollY']['label'] = __('Vertical scroll height (px)', 'baratables');
		$schema['scrollCollapse']['label'] = __('Collapse vertical scroll when shorter', 'baratables');
		$schema['stripe']['label'] = __('Show zebra stripes', 'baratables');
		$schema['rowBorder']['label'] = __('Show row borders', 'baratables');
		$schema['cellBorder']['label'] = __('Show cell borders', 'baratables');
		$schema['hover']['label'] = __('Highlight rows on hover', 'baratables');
		$schema['orderColumn']['label'] = __('Highlight sorted column', 'baratables');
		$schema['compact']['label'] = __('Compact density', 'baratables');
		$schema['pageLength']['label'] = __('Rows per page', 'baratables');
		$schema['rowLimit']['label'] = __('Maximum rows to load', 'baratables');
		$schema['rowLimit']['description'] = __('Rows fetched and rendered, on every data source. Maximum 10,000. Pre-filter larger datasets at the source.', 'baratables');
		$schema['lengthMenuPrefix']['label'] = __('Selector prefix', 'baratables');
		$schema['lengthMenuSuffix']['label'] = __('Selector suffix', 'baratables');
		$schema['paginateFirst']['label'] = __('Pagination label: First', 'baratables');
		$schema['paginatePrevious']['label'] = __('Pagination label: Previous', 'baratables');
		$schema['paginateNext']['label'] = __('Pagination label: Next', 'baratables');
		$schema['paginateLast']['label'] = __('Pagination label: Last', 'baratables');
		$schema['searchText']['label'] = __('Search text', 'baratables');
		$schema['searchPlaceholder']['label'] = __('Search placeholder', 'baratables');
		$schema['searchColumnsLabel']['label'] = __('Dropdown button text', 'baratables');
		$schema['searchColumnsHeading']['label'] = __('Dropdown heading', 'baratables');

		$schema['buttons']['label'] = __('Table buttons', 'baratables');
		$schema['buttons']['description'] = __('Add export and column-visibility buttons to the table.', 'baratables');
		$schema['buttons']['choices'] = [
			'copy' => __('Copy', 'baratables'),
			'csv' => __('Export CSV', 'baratables'),
			'excel' => __('Export Excel', 'baratables'),
			'print' => __('Print', 'baratables'),
			'colvis' => __('Column visibility', 'baratables'),
			'pagelength' => __('Page length button', 'baratables'),
		];
		$schema['buttonTextCopy']['label'] = __('Copy button text', 'baratables');
		$schema['buttonTextCsv']['label'] = __('CSV button text', 'baratables');
		$schema['buttonTextExcel']['label'] = __('Excel button text', 'baratables');
		$schema['buttonTextPrint']['label'] = __('Print button text', 'baratables');
		$schema['buttonTextColvis']['label'] = __('Column visibility button text', 'baratables');
		$schema['buttonTextPagelength']['label'] = __('Page length button text', 'baratables');

		$schema_with_labels = $schema;
		return $schema_with_labels;
	}

	private function get_table_option_defaults(): array {
		$defaults = [];
		foreach (self::TABLE_OPTION_SCHEMA as $key => $config) {
			$defaults[$key] = $config['default'];
		}
		return $defaults;
	}

	public function get_non_sortable_indices(array $definition): array {
		return $this->collect_column_indices($definition, static function ($col): bool {
			return isset($col['sortable']) && $col['sortable'] === false;
		});
	}

	private function build_tax_query(array $definition): array {
		$filters = BaraTables_Taxonomy_Filters::normalize($definition['taxonomy_filter'] ?? []);
		if (empty($filters)) {
			return [];
		}
		$tax_queries = [];
		foreach ($filters as $filter) {
			$taxonomy = isset($filter['taxonomy']) ? sanitize_key($filter['taxonomy']) : '';
			$terms = isset($filter['terms']) ? array_values(array_unique(array_filter(array_map('intval', (array) $filter['terms'])))) : [];
			if ($taxonomy === '' || empty($terms)) {
				continue;
			}
			$field = isset($filter['field']) && in_array($filter['field'], ['term_id', 'slug', 'name'], true)
				? $filter['field']
				: 'term_id';
			$operator = isset($filter['operator']) && in_array($filter['operator'], ['IN', 'NOT IN', 'AND'], true)
				? $filter['operator']
				: 'IN';
			$tax_queries[] = [
				'taxonomy' => $taxonomy,
				'field'    => $field,
				'terms'    => $terms,
				'operator' => $operator,
			];
		}
		if (empty($tax_queries)) {
			return [];
		}
		if (count($tax_queries) === 1) {
			return [$tax_queries[0]];
		}
		return array_merge(['relation' => 'AND'], $tax_queries);
	}

	/**
	 * WP_Query OR-group that admits every "public"/token-less post so the authoritative per-row
	 * post_passes_access_policy() can see it: the access meta is absent, an empty string, or an
	 * empty serialized array (a blank multi-select stored as array() => 'a:0:{}'). Without the
	 * 'a:0:{}' arm, such a post is excluded by the pre-filter before the per-row check runs, so a
	 * row the access model considers public is silently hidden.
	 */
	private function public_token_meta_clause(string $meta_key): array {
		return [
			'relation' => 'OR',
			[
				'key' => $meta_key,
				'compare' => 'NOT EXISTS',
			],
			[
				'key' => $meta_key,
				'value' => '',
				'compare' => '=',
			],
			[
				'key' => $meta_key,
				'value' => 'a:0:{}',
				'compare' => '=',
			],
		];
	}

	private function build_access_meta_query(string $meta_key, array $access_policy) {
		$meta_key = sanitize_text_field($meta_key);
		if ($meta_key === '') {
			return [];
		}
		$logged_out_policy = $access_policy['logged_out_policy'] ?? 'public_only';
		$user_tokens = $access_policy['user_tokens'] ?? [];
		$is_logged_in = is_user_logged_in();
		$allow_public = $logged_out_policy !== 'none';

		if (!$is_logged_in) {
			if ($logged_out_policy === 'none') {
				return 'none';
			}
			if ($logged_out_policy === 'public_only') {
				return [
					$this->public_token_meta_clause($meta_key),
				];
			}
			return [];
		}

		$clauses = [];
		if (!empty($user_tokens)) {
			foreach ($user_tokens as $token) {
				$token = (string) $token;
				if ($token === '') {
					continue;
				}
				$clauses[] = [
					'key' => $meta_key,
					'value' => $token,
					'compare' => 'LIKE',
				];
				$clauses[] = [
					'key' => $meta_key,
					'value' => '"' . $token . '"',
					'compare' => 'LIKE',
				];
			}
		}

		if (empty($clauses)) {
			if ($allow_public) {
				return [
					$this->public_token_meta_clause($meta_key),
				];
			}
			return 'none';
		}

		$or_group = array_merge(['relation' => 'OR'], $clauses);

		$meta_query = [
			'relation' => 'OR',
		];
		if ($allow_public) {
			$meta_query[] = $this->public_token_meta_clause($meta_key);
		}
		$meta_query[] = $or_group;
		return $meta_query;
	}

	private function append_meta_query(array $query_args, array $meta_query): array {
		if (empty($meta_query)) {
			return $query_args;
		}
		if (!empty($query_args['meta_query']) && is_array($query_args['meta_query'])) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to combine configured filters with access-policy filtering.
				'relation' => 'AND',
				$query_args['meta_query'],
				$meta_query,
			];
			return $query_args;
		}
		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for access-policy filtering.
		return $query_args;
	}

	private function filter_rows_by_access(array $rows, callable $token_resolver, array $access_policy): array {
		$logged_out_policy = $access_policy['logged_out_policy'] ?? 'public_only';
		$user_tokens = $access_policy['user_tokens'] ?? [];
		return array_values(array_filter($rows, function ($row) use ($token_resolver, $user_tokens, $logged_out_policy) {
			$raw_tokens = $token_resolver($row);
			$tokens = $this->normalize_tokens($raw_tokens);
			return $this->passes_access_tokens($tokens, $user_tokens, $logged_out_policy);
		}));
	}

	private function get_user_tokens(string $user_meta_key): array {
		if (!is_user_logged_in()) {
			return [];
		}
		$user_meta_key = sanitize_text_field($user_meta_key);
		if ($user_meta_key === '') {
			$user = wp_get_current_user();
			return is_array($user->roles) ? array_values(array_map('strval', $user->roles)) : [];
		}
		$value = get_user_meta(get_current_user_id(), $user_meta_key, true);
		return $this->normalize_tokens($value);
	}

}
