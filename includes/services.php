<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Service {
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
	private const MAX_QUERY_ROWS = 500;
	private const MAX_CSV_BYTES = 5242880;
	private const MAX_CSV_LINE_LENGTH = 1048576;
	private const CSV_MIME_TYPES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'application/vnd.ms-excel',
		'text/comma-separated-values',
	];
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
			} elseif ($type === 'select') {
				$choices = isset($config['choices']) && is_array($config['choices']) ? array_keys($config['choices']) : [];
				$value = sanitize_key((string) $options_raw[$key]);
				if (!in_array($value, $choices, true)) {
					$value = $config['default'] ?? ($choices[0] ?? '');
				}
				$options[$key] = $value;
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

		if (!empty($options_raw['type']) && in_array($options_raw['type'], ['bar', 'line', 'area', 'pie', 'gantt'], true)) {
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

		if ($options['type'] === 'pie' && !empty($options['x_axis']) && empty($options['series']) && !empty($slug_map)) {
			foreach (array_keys($slug_map) as $slug) {
				if ($slug !== $options['x_axis']) {
					$options['series'] = [$slug];
					break;
				}
			}
		}

		if ($options['type'] === 'pie' || $options['type'] === 'gantt') {
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

	public function sanitize_custom_query_json(string $raw_json): array {
		return $this->sanitize_wp_query_args($this->decode_json_array($raw_json));
	}

	private function sanitize_public_post_types(array $post_types_raw, bool $fallback_to_post = true): array {
		$post_types = [];
		foreach ($post_types_raw as $post_type_raw) {
			$post_type = sanitize_key((string) $post_type_raw);
			$post_type_obj = $post_type !== '' ? get_post_type_object($post_type) : null;
			if ($post_type_obj && !empty($post_type_obj->public)) {
				$post_types[] = $post_type;
			}
		}

		$post_types = array_values(array_unique($post_types));
		if (empty($post_types) && $fallback_to_post) {
			$post_types[] = 'post';
		}

		return $post_types;
	}

	private function sanitize_wp_query_args(array $args): array {
		if (empty($args)) {
			return [];
		}

		$clean = [
			'post_status' => 'publish',
			'no_found_rows' => true,
			'ignore_sticky_posts' => true,
		];
		$has_supported_arg = false;

		$post_type_requested = array_key_exists('post_type', $args);
		$post_types_raw = $args['post_type'] ?? ['post'];
		$post_types_raw = is_array($post_types_raw) ? $post_types_raw : [$post_types_raw];
		$post_types = $this->sanitize_public_post_types($post_types_raw, false);
		if (empty($post_types) && $post_type_requested) {
			return [];
		}
		$clean['post_type'] = count($post_types) === 1 ? $post_types[0] : (!empty($post_types) ? $post_types : 'post');
		if ($post_type_requested && !empty($post_types)) {
			$has_supported_arg = true;
		}

		if (isset($args['posts_per_page'])) {
			$posts_per_page = (int) $args['posts_per_page'];
			if ($posts_per_page !== 0) {
				$clean['posts_per_page'] = $posts_per_page < 0
					? self::MAX_QUERY_ROWS
					: min(max($posts_per_page, 1), self::MAX_QUERY_ROWS);
				$has_supported_arg = true;
			}
		}

		foreach (['paged', 'page', 'offset', 'p', 'page_id', 'author', 'post_parent', 'year', 'monthnum', 'day', 'w'] as $int_key) {
			if (isset($args[$int_key])) {
				$clean[$int_key] = absint($args[$int_key]);
				$has_supported_arg = true;
			}
		}

		foreach (['s', 'name', 'pagename', 'meta_key', 'meta_value'] as $text_key) {
			if (isset($args[$text_key]) && is_scalar($args[$text_key])) {
				$value = sanitize_text_field((string) $args[$text_key]);
				if ($value !== '') {
					$clean[$text_key] = $value;
					$has_supported_arg = true;
				}
			}
		}

		foreach (['post__in', 'post__not_in', 'post_parent__in', 'post_parent__not_in', 'author__in', 'author__not_in', 'category__in', 'category__not_in', 'tag__in', 'tag__not_in'] as $id_list_key) {
			if (isset($args[$id_list_key])) {
				$ids = $this->sanitize_int_list($args[$id_list_key]);
				if (!empty($ids)) {
					$clean[$id_list_key] = $ids;
					$has_supported_arg = true;
				}
			}
		}

		if (isset($args['order'])) {
			$clean['order'] = $this->sanitize_query_order($args['order']);
			$has_supported_arg = true;
		}

		if (isset($args['orderby'])) {
			$orderby = $this->sanitize_query_orderby($args['orderby']);
			if ($orderby !== null && $orderby !== []) {
				$clean['orderby'] = $orderby;
				$has_supported_arg = true;
			}
		}

		if (isset($args['meta_compare'])) {
			$compare = $this->sanitize_meta_compare($args['meta_compare']);
			if ($compare !== '') {
				$clean['meta_compare'] = $compare;
				$has_supported_arg = true;
			}
		}

		if (isset($args['meta_type'])) {
			$type = $this->sanitize_meta_type($args['meta_type']);
			if ($type !== '') {
				$clean['meta_type'] = $type;
				$has_supported_arg = true;
			}
		}

		if (isset($args['meta_query']) && is_array($args['meta_query'])) {
			$meta_query = $this->sanitize_meta_query($args['meta_query']);
			if (!empty($meta_query)) {
				$clean['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- User-configured table source.
				$has_supported_arg = true;
			}
		}

		if (isset($args['tax_query']) && is_array($args['tax_query'])) {
			$tax_query = $this->sanitize_custom_tax_query($args['tax_query']);
			if (!empty($tax_query)) {
				$clean['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- User-configured table source.
				$has_supported_arg = true;
			}
		}

		if (isset($args['date_query']) && is_array($args['date_query'])) {
			$date_query = $this->sanitize_date_query($args['date_query']);
			if (!empty($date_query)) {
				$clean['date_query'] = $date_query;
				$has_supported_arg = true;
			}
		}

		return $has_supported_arg ? $clean : [];
	}

	private function sanitize_int_list($raw): array {
		$items = is_array($raw) ? $raw : explode(',', (string) $raw);
		$ids = [];
		foreach ($items as $item) {
			$id = absint($item);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	private function sanitize_query_order($order): string {
		return strtoupper((string) $order) === 'DESC' ? 'DESC' : 'ASC';
	}

	private function sanitize_query_orderby($orderby) {
		$allowed = [
			'none' => 'none',
			'id' => 'ID',
			'author' => 'author',
			'title' => 'title',
			'name' => 'name',
			'type' => 'type',
			'date' => 'date',
			'modified' => 'modified',
			'parent' => 'parent',
			'rand' => 'rand',
			'comment_count' => 'comment_count',
			'menu_order' => 'menu_order',
			'meta_value' => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- User-configured custom query ordering; row count is capped.
			'meta_value_num' => 'meta_value_num',
			'post__in' => 'post__in',
			'relevance' => 'relevance',
		];

		if (is_array($orderby)) {
			$out = [];
			foreach ($orderby as $key => $direction) {
				if (is_int($key)) {
					if (!is_scalar($direction)) {
						continue;
					}
					$raw_key = $direction;
					$order = 'ASC';
				} else {
					$raw_key = $key;
					$order = is_scalar($direction) ? $this->sanitize_query_order($direction) : 'ASC';
				}
				$clean_key = sanitize_key((string) $raw_key);
				if (!isset($allowed[$clean_key])) {
					continue;
				}
				$out[$allowed[$clean_key]] = $order;
			}
			return $out;
		}

		$clean = sanitize_key((string) $orderby);
		return $allowed[$clean] ?? null;
	}

	private function sanitize_meta_query(array $query, int $depth = 0): array {
		if ($depth > 2) {
			return [];
		}
		$out = [];
		if (isset($query['relation'])) {
			$out['relation'] = strtoupper((string) $query['relation']) === 'OR' ? 'OR' : 'AND';
		}

		foreach ($query as $key => $clause) {
			if ($key === 'relation' || !is_array($clause)) {
				continue;
			}
			if (array_key_exists('key', $clause)) {
				$clean_clause = $this->sanitize_meta_clause($clause);
			} else {
				$clean_clause = $this->sanitize_meta_query($clause, $depth + 1);
			}
			if (!empty($clean_clause)) {
				$out[] = $clean_clause;
			}
		}

		return count($out) > (isset($out['relation']) ? 1 : 0) ? $out : [];
	}

	private function sanitize_meta_clause(array $clause): array {
		$key = isset($clause['key']) ? sanitize_text_field((string) $clause['key']) : '';
		if ($key === '') {
			return [];
		}

		$out = ['key' => $key];
		if (array_key_exists('value', $clause)) {
			$out['value'] = $this->sanitize_query_value($clause['value']);
		}
		if (isset($clause['compare'])) {
			$compare = $this->sanitize_meta_compare($clause['compare']);
			if ($compare !== '') {
				$out['compare'] = $compare;
			}
		}
		if (isset($clause['type'])) {
			$type = $this->sanitize_meta_type($clause['type']);
			if ($type !== '') {
				$out['type'] = $type;
			}
		}
		return $out;
	}

	private function sanitize_meta_compare($compare): string {
		$compare = strtoupper(trim((string) $compare));
		$allowed = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE'];
		return in_array($compare, $allowed, true) ? $compare : '';
	}

	private function sanitize_meta_type($type): string {
		$type = strtoupper(sanitize_key((string) $type));
		$allowed = ['NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED'];
		return in_array($type, $allowed, true) ? $type : '';
	}

	private function sanitize_query_value($value) {
		if (is_array($value)) {
			$values = [];
			foreach ($value as $item) {
				if (is_scalar($item)) {
					$values[] = sanitize_text_field((string) $item);
				}
			}
			return $values;
		}
		return is_scalar($value) ? sanitize_text_field((string) $value) : '';
	}

	private function sanitize_custom_tax_query(array $query, int $depth = 0): array {
		if ($depth > 2) {
			return [];
		}
		$out = [];
		if (isset($query['relation'])) {
			$out['relation'] = strtoupper((string) $query['relation']) === 'OR' ? 'OR' : 'AND';
		}

		foreach ($query as $key => $clause) {
			if ($key === 'relation' || !is_array($clause)) {
				continue;
			}
			if (array_key_exists('taxonomy', $clause)) {
				$clean_clause = $this->sanitize_tax_clause($clause);
			} else {
				$clean_clause = $this->sanitize_custom_tax_query($clause, $depth + 1);
			}
			if (!empty($clean_clause)) {
				$out[] = $clean_clause;
			}
		}

		return count($out) > (isset($out['relation']) ? 1 : 0) ? $out : [];
	}

	private function sanitize_tax_clause(array $clause): array {
		$taxonomy = isset($clause['taxonomy']) ? sanitize_key((string) $clause['taxonomy']) : '';
		if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
			return [];
		}
		$field = isset($clause['field']) && in_array($clause['field'], ['term_id', 'slug', 'name', 'term_taxonomy_id'], true)
			? $clause['field']
			: 'term_id';
		$terms_raw = $clause['terms'] ?? [];
		$terms_raw = is_array($terms_raw) ? $terms_raw : [$terms_raw];
		$terms = [];
		foreach ($terms_raw as $term) {
			$terms[] = $field === 'term_id' || $field === 'term_taxonomy_id' ? absint($term) : sanitize_text_field((string) $term);
		}
		$terms = array_values(array_filter(array_unique($terms), static function ($term) {
			return $term !== '' && $term !== 0;
		}));
		if (empty($terms)) {
			return [];
		}
		$operator = isset($clause['operator']) ? strtoupper((string) $clause['operator']) : 'IN';
		$operator = in_array($operator, ['IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS'], true) ? $operator : 'IN';
		return [
			'taxonomy' => $taxonomy,
			'field' => $field,
			'terms' => $terms,
			'operator' => $operator,
			'include_children' => !empty($clause['include_children']),
		];
	}

	private function sanitize_date_query(array $query, int $depth = 0): array {
		if ($depth > 2) {
			return [];
		}
		$out = [];
		if (isset($query['relation'])) {
			$out['relation'] = strtoupper((string) $query['relation']) === 'OR' ? 'OR' : 'AND';
		}
		foreach ($query as $key => $clause) {
			if ($key === 'relation' || !is_array($clause)) {
				continue;
			}
			$clean_clause = $this->sanitize_date_clause($clause);
			if (empty($clean_clause)) {
				$clean_clause = $this->sanitize_date_query($clause, $depth + 1);
			}
			if (!empty($clean_clause)) {
				$out[] = $clean_clause;
			}
		}
		return count($out) > (isset($out['relation']) ? 1 : 0) ? $out : [];
	}

	private function sanitize_date_clause(array $clause): array {
		$out = [];
		foreach (['year', 'month', 'monthnum', 'week', 'w', 'day', 'hour', 'minute', 'second'] as $int_key) {
			if (isset($clause[$int_key])) {
				$out[$int_key] = absint($clause[$int_key]);
			}
		}
		foreach (['before', 'after'] as $date_key) {
			if (isset($clause[$date_key]) && is_scalar($clause[$date_key])) {
				$out[$date_key] = sanitize_text_field((string) $clause[$date_key]);
			}
		}
		if (isset($clause['inclusive'])) {
			$out['inclusive'] = !empty($clause['inclusive']);
		}
		if (isset($clause['compare'])) {
			$compare = $this->sanitize_meta_compare($clause['compare']);
			if ($compare !== '') {
				$out['compare'] = $compare;
			}
		}
		return $out;
	}

	public function sanitize_value_overrides(string $raw_json): array {
		$decoded = $this->decode_json_array($raw_json);
		if (empty($decoded)) {
			return [];
		}

		$clean = [];
		foreach ($decoded as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			// value_overrides is a raw-JSON textarea; a malformed rule can carry an array where a
			// string is expected. Guard with is_scalar (as the rest of this file does) so an array
			// search/replace skips the rule cleanly instead of emitting an "Array to string" warning.
			$column = (isset($rule['column']) && is_scalar($rule['column'])) ? sanitize_text_field((string) $rule['column']) : '';
			$search = (isset($rule['search']) && is_scalar($rule['search'])) ? (string) $rule['search'] : '';
			$replace = (isset($rule['replace']) && is_scalar($rule['replace'])) ? (string) $rule['replace'] : '';
			$regex = !empty($rule['regex']);
			if ($column === '' || $search === '') {
				continue;
			}
			$clean[] = [
				'column'  => $column,
				'search'  => $search,
				'replace' => $replace,
				'regex'   => $regex,
			];
		}
		return $clean;
	}

	private function decode_json_array(string $raw_json): array {
		$raw_json = trim($raw_json);
		if ($raw_json === '') {
			return [];
		}
		$decoded = json_decode($raw_json, true);
		if (!is_array($decoded)) {
			$decoded = json_decode(stripslashes($raw_json), true);
		}
		return is_array($decoded) ? $decoded : [];
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

	public function build_columns(array $columns, array $filter_types, array $filter_sorts = [], array $filter_type_priority = [], array $custom_labels = [], array $filter_labels = [], array $hide_titles = [], array $hidden_columns = [], array $searchable = [], array $sort_priority = [], array $sort_direction = [], array $sort_enabled = [], array $sortable = [], array $filter_values = [], array $filter_strict = [], array $format_date_flags = [], array $date_formats = []): array {
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
			$filter_strict_flag = array_key_exists($raw, $filter_strict) ? (bool) $filter_strict[$raw] : false;
			$out[] = $this->normalize_column($raw, $filter_type, $filter_sort, $custom_label, $filter_label, $hide_title, $hidden, $is_searchable, $priority, $direction, $sort_is_enabled, $is_sortable, $custom_filter_values, $data_type_priority, $format_date, $date_format, $filter_strict_flag);
		}
		return $out;
	}

	public function normalize_column(string $raw, string $filter_type = 'none', string $filter_sort = 'asc', string $custom_label = '', ?string $filter_label = null, bool $hide_title = false, bool $hidden = false, bool $searchable = true, int $sort_priority = 0, string $sort_direction = 'asc', bool $sort_enabled = false, bool $sortable = true, array $filter_values = [], array $filter_type_priority = [], bool $format_date = false, string $date_format = '', bool $filter_strict = false): array {
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

		// Manual (custom) columns default to a positional "Column N" — matching the picker
		// and the grid — rather than the key-derived name used by other sources.
		$default_label = ucwords(str_replace(['_', '-'], ' ', $key));
		if ($source === 'custom' && preg_match('/^col_(\d+)$/', $key, $auto_match)) {
			$default_label = sprintf('Column %d', (int) $auto_match[1]);
		}

		// Auto-label is decided purely by whether the user supplied a heading: the gear
		// field submits an empty string when left at its placeholder default. No
		// string-matching of the label text — the flag is the single source of truth that
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
			'filter_strict' => $filter_strict,
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
		$max_cols = 50;
		$max_rows = 500;

		$column_count = $cols_count > 0 ? $cols_count : count($column_labels_raw);
		if ($column_count <= 0) {
			$column_count = 3;
		}
		$column_count = min($column_count, $max_cols);

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
		$target_rows = min($target_rows, $max_rows);

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
	 * leaves a manual column's heading blank) or a genuinely empty label — never from
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
			$labels = ['Column 1'];
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
			if ($key_safe === '') {
				continue;
			}
			$labels[] = ucwords(str_replace(['_', '-'], ' ', $key_safe));
		}
		return $this->build_columns_from_keys_and_labels($keys, $labels, $source);
	}

	public function get_definitions(): array {
		return $this->repo->get_definitions(false);
	}

	public function sanitize_access_control(array $raw): array {
		$user_meta_key = isset($raw['user_meta_key']) ? sanitize_text_field($raw['user_meta_key']) : '';
		$post_meta_key = isset($raw['post_meta_key']) ? sanitize_text_field($raw['post_meta_key']) : '';
		$csv_column = isset($raw['csv_column']) ? sanitize_text_field($raw['csv_column']) : '';
		$external_column = isset($raw['external_column']) ? sanitize_text_field($raw['external_column']) : '';
		$logged_out = isset($raw['logged_out']) && in_array($raw['logged_out'], ['all', 'public_only', 'none'], true)
			? $raw['logged_out']
			: 'all';
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
		$access_policy = $this->build_access_policy($access);
		$row_limit = $limit > 0 ? min($limit, self::MAX_QUERY_ROWS) : self::MAX_QUERY_ROWS;

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

		$rows = [];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fgetcsv requires a file handle; WP_Filesystem has no CSV parsing equivalent.
		if (($handle = fopen($path, 'rb')) !== false) {
			$count = 0;
			while (($data = fgetcsv($handle, self::MAX_CSV_LINE_LENGTH, $delimiter, '"', '\\')) !== false) {
				if ($has_header && $count === 0) {
					$this->infer_columns_from_header($data, $definition);
					$count++;
					continue;
				}
				$rows[] = $data;
				$count++;
				if ($limit > 0 && $count >= $limit + ($has_header ? 1 : 0)) {
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
				$headers = [];
				for ($i = 0; $i < $maxCols; $i++) {
					$headers[] = 'Column ' . ($i + 1);
				}
				$this->infer_columns_from_header($headers, $definition, false);
			}
		}

		$inferred = $this->last_inferred_columns ?: [];
		$csv_index_map = $this->build_slug_map($inferred, function ($col, $idx) {
			return $idx;
		});

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

		if (!empty($definition['columns'])) {
			$rows = $this->reorder_rows_by_slug_map($rows, $definition['columns'], $csv_index_map);
			$rows = $this->apply_ordered_date_formats($rows, $this->build_ordered_date_formats($definition['columns']));
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

	private function resolve_csv_access_column_index(array $csv_index_map, string $column): ?int {
		$column = trim($column);
		$normalized_column = preg_replace('/^csv:/i', '', $column);
		$sanitized_column = sanitize_key($column);
		$sanitized_normalized = sanitize_key((string) $normalized_column);
		$candidates = array_values(array_unique(array_filter([
			$column,
			$normalized_column !== '' ? 'csv:' . $normalized_column : '',
			$sanitized_column,
			$sanitized_normalized !== '' ? 'csv:' . $sanitized_normalized : '',
		], static function ($candidate) {
			return $candidate !== '';
		})));

		foreach ($candidates as $candidate) {
			if (array_key_exists($candidate, $csv_index_map)) {
				return (int) $csv_index_map[$candidate];
			}
		}

		$normalized_map = [];
		foreach ($csv_index_map as $slug => $index) {
			$normalized_slug = $this->normalize_access_column_key((string) $slug, 'csv');
			if ($normalized_slug !== '' && !isset($normalized_map[$normalized_slug])) {
				$normalized_map[$normalized_slug] = (int) $index;
			}
		}

		foreach ($candidates as $candidate) {
			$normalized_candidate = $this->normalize_access_column_key((string) $candidate, 'csv');
			if ($normalized_candidate !== '' && isset($normalized_map[$normalized_candidate])) {
				return $normalized_map[$normalized_candidate];
			}
		}

		return null;
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
		$per_page = $limit > 0 ? min($limit, self::MAX_QUERY_ROWS) : self::MAX_QUERY_ROWS;
		$table = $this->sanitize_external_identifier((string) $table);
		if ($table === '' || !method_exists($ext_db, 'has_cap') || !$ext_db->has_cap('identifier_placeholders')) {
			return [];
		}

		$sql = $ext_db->prepare('SELECT * FROM %i LIMIT %d', $table, $per_page);
		if (!is_string($sql) || $sql === '') {
			return [];
		}
		$results = $ext_db->get_results($sql, ARRAY_A);
		if (!is_array($results) || empty($results)) {
			return [];
		}

		$columns_for_mapping = $definition['columns'];
		if (empty($columns_for_mapping)) {
			$inferred = $this->build_column_definitions_from_assoc(array_keys($results[0]), 'external');
			$this->last_inferred_columns = $inferred;
			$columns_for_mapping = $inferred;
		}

		$map = $this->build_slug_map($columns_for_mapping, function ($col) {
			return $col['key'] ?? '';
		});

		$eligible_rows = $results;

		if (!empty($access_policy['external_column'])) {
			$first_row = reset($eligible_rows);
			if (!is_array($first_row) || !$this->external_row_has_column($first_row, (string) $access_policy['external_column'])) {
				return [];
			}
			$eligible_rows = $this->filter_rows_by_access(
				$eligible_rows,
				function ($row) use ($access_policy) {
					return is_array($row) ? $this->get_external_row_value($row, (string) $access_policy['external_column']) : '';
				},
				$access_policy
			);
		}

		$ordered = $this->reorder_external_rows_by_slug_map($eligible_rows, $columns_for_mapping, $map);
		return $this->apply_ordered_date_formats($ordered, $this->build_ordered_date_formats($columns_for_mapping));
	}

	private function get_external_row_value(array $row, string $column, $missing_value = '') {
		$column = trim($column);
		$normalized_column = preg_replace('/^external:/i', '', $column);
		$sanitized_column = sanitize_key($column);
		$sanitized_normalized = sanitize_key((string) $normalized_column);
		$candidates = array_values(array_unique(array_filter([
			$column,
			(string) $normalized_column,
			$sanitized_column,
			$sanitized_normalized,
			$normalized_column !== '' ? 'external:' . $normalized_column : '',
			$sanitized_normalized !== '' ? 'external:' . $sanitized_normalized : '',
		], static function ($candidate) {
			return $candidate !== '';
		})));

		foreach ($candidates as $candidate) {
			if (array_key_exists($candidate, $row)) {
				return $row[$candidate];
			}
		}

		$normalized_row_keys = [];
		foreach (array_keys($row) as $row_key) {
			$normalized_key = $this->normalize_access_column_key((string) $row_key, 'external');
			if ($normalized_key !== '' && !isset($normalized_row_keys[$normalized_key])) {
				$normalized_row_keys[$normalized_key] = $row_key;
			}
		}

		foreach ($candidates as $candidate) {
			$normalized_candidate = $this->normalize_access_column_key((string) $candidate, 'external');
			if ($normalized_candidate !== '' && isset($normalized_row_keys[$normalized_candidate])) {
				$row_key = $normalized_row_keys[$normalized_candidate];
				return $row[$row_key];
			}
		}

		return $missing_value;
	}

	private function external_row_has_column(array $row, string $column): bool {
		$sentinel = new stdClass();
		return $this->get_external_row_value($row, $column, $sentinel) !== $sentinel;
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
		$ordered_rows = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$ordered_row = [];
			foreach ($definition_columns as $col) {
				$slug = $this->resolve_column_slug($col);
				$source_key = $slug_map[$slug] ?? null;
				$ordered_row[] = $source_key !== null ? $this->get_external_row_value($row, (string) $source_key) : '';
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
		return $this->build_slug_map($columns, static function ($col, $idx) {
			return $idx;
		});
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
		$logged_out_policy_raw = $access['logged_out'] ?? 'all';
		$logged_out_policy = in_array($logged_out_policy_raw, ['all', 'public_only', 'none'], true) ? $logged_out_policy_raw : 'all';
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
			// The logged_out policy governs anonymous visitors only — applying it here leaked
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
			$access_policy['logged_out_policy'] ?? 'all'
		);
	}

	private function infer_columns_from_header(array $header_row, array $definition, bool $is_header = true): void {
		$keys = [];
		$labels = [];
		foreach ($header_row as $idx => $label) {
			$key = $is_header ? sanitize_title((string) $label) : 'col_' . ($idx + 1);
			if ($key === '') {
				$key = 'col_' . ($idx + 1);
			}
			$keys[] = $key;
			$labels[] = $is_header ? (string) $label : 'Column ' . ($idx + 1);
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
		$schema['stripe']['label'] = __('Show zebra stripes', 'baratables');
		$schema['rowBorder']['label'] = __('Show row borders', 'baratables');
		$schema['cellBorder']['label'] = __('Show cell borders', 'baratables');
		$schema['hover']['label'] = __('Highlight rows on hover', 'baratables');
		$schema['orderColumn']['label'] = __('Highlight sorted column', 'baratables');
		$schema['compact']['label'] = __('Compact density', 'baratables');
		$schema['pageLength']['label'] = __('Rows per page', 'baratables');
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
			'print' => __('Print', 'baratables'),
			'colvis' => __('Column visibility', 'baratables'),
			'pagelength' => __('Page length button', 'baratables'),
		];
		$schema['buttonTextCopy']['label'] = __('Copy button text', 'baratables');
		$schema['buttonTextCsv']['label'] = __('CSV button text', 'baratables');
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

	public function build_filter_options(array $definition, array $rows): array {
		$definition['columns'] = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];

		$filters = [];
		foreach ($definition['columns'] as $index => $col) {
			if (!isset($col['filter']) || $col['filter'] === 'none') {
				continue;
			}
			$custom_values = [];
			if (!empty($col['filter_values']) && is_array($col['filter_values'])) {
				foreach ($col['filter_values'] as $item) {
					if (!is_array($item)) {
						continue;
					}
					$label = isset($item['label']) ? (string) $item['label'] : '';
					$value = isset($item['value']) ? (string) $item['value'] : $label;
					$search_terms = isset($item['search_terms']) && is_array($item['search_terms']) ? array_values($item['search_terms']) : [$value];
					if ($label === '' && $value === '') {
						continue;
					}
					if ($value === '') {
						$value = $label;
					}
					$custom_values[] = $this->normalize_filter_option([
						'label' => $label !== '' ? $label : $value,
						'value' => $value,
						'search_terms' => array_map('strval', $search_terms),
					]);
				}
			}
			$has_custom_values = !empty($custom_values);
			$filters[$index] = [
				'column_index' => $index,
				'label'        => array_key_exists('filter_label', $col) ? $col['filter_label'] : $col['label'],
				'type'         => $col['filter'],
				'options'      => $has_custom_values ? $custom_values : [],
				'slug'         => $this->resolve_column_slug($col),
				'filter_sort'  => $col['filter_sort'] ?? 'asc',
				'has_custom_values' => $has_custom_values,
				'filter_strict' => !empty($col['filter_strict']),
				'data_type_priority' => isset($col['filter_type_priority']) && is_array($col['filter_type_priority'])
					? $this->normalize_data_type_priority_list($col['filter_type_priority'])
					: [],
			];
		}

		if (empty($filters)) {
			return [];
		}

		foreach ($rows as $row) {
			foreach ($filters as $idx => &$filter) {
				if (!empty($filter['has_custom_values'])) {
					continue;
				}
				if (!isset($row[$idx])) {
					continue;
				}
				$value = trim(wp_strip_all_tags((string) $row[$idx]));
				if ($value === '') {
					continue;
				}

				$is_multi = strpos($value, ',') !== false;
				if ($is_multi) {
					$parts = array_filter(array_map('trim', explode(',', $value)), static function ($part) {
						return $part !== '';
					});
					foreach ($parts as $part) {
						// Options are keyed by value, so duplicates collapse to one slot. Normalize
						// only the first occurrence of each distinct value — over the 500-row ceiling a
						// low-cardinality column otherwise rebuilds the same option once per row.
						if (!isset($filter['options'][$part])) {
							$filter['options'][$part] = $this->normalize_filter_option([
								'label' => $part,
								'value' => $part,
								'search_terms' => [$part],
							]);
						}
					}
				} elseif (!isset($filter['options'][$value])) {
					$filter['options'][$value] = $this->normalize_filter_option([
						'label' => $value,
						'value' => $value,
						'search_terms' => [$value],
					]);
				}
			}
		}
		unset($filter);

		foreach ($filters as &$filter) {
			$filter['options'] = array_values(array_map([$this, 'normalize_filter_option'], $filter['options']));
			$sortOrder = $filter['filter_sort'] ?? 'custom';
			if ($sortOrder === 'none') {
				$sortOrder = 'custom';
			}
			$type_priority = isset($filter['data_type_priority']) && is_array($filter['data_type_priority'])
				? $this->normalize_data_type_priority_list($filter['data_type_priority'])
				: [];

			$should_sort = !($sortOrder === 'custom' && empty($type_priority));
			if (!$should_sort || empty($filter['options'])) {
				continue;
			}

			// Decorate each option once with its sort keys — the detected type and (for dates) the
			// parsed timestamp. detect_option_type()/parse_date_option() are regex/strtotime-heavy and
			// depend only on the option itself, so computing them once here instead of inside the
			// O(U log U) usort comparator keeps the front-end render off a redundant-regex hot path.
			foreach ($filter['options'] as $idx => &$option) {
				$option['_btbl_index'] = $idx;
				$option['_btbl_type'] = $this->detect_option_type($option);
				$option['_btbl_time'] = $option['_btbl_type'] === 'date' ? $this->parse_date_option($this->option_label($option)) : null;
			}
			unset($option);

			$type_rank = [];
			$type_direction = [];
			foreach ($type_priority as $idx => $config) {
				if (!is_array($config)) {
					continue;
				}
				$type = $config['type'] ?? null;
				if ($type === null) {
					continue;
				}
				$type_rank[$type] = $idx;
				$type_direction[$type] = $this->canonicalize_sort_direction($config['direction'] ?? 'asc');
			}
			$default_type_rank = count($type_rank);
			$fallback_direction = $sortOrder === 'desc' ? 'desc' : 'asc';

			usort($filter['options'], function ($a, $b) use ($sortOrder, $type_rank, $default_type_rank, $type_direction, $fallback_direction) {
				$typeA = $a['_btbl_type'];
				$typeB = $b['_btbl_type'];

				$rankA = $type_rank[$typeA] ?? $default_type_rank;
				$rankB = $type_rank[$typeB] ?? $default_type_rank;
				if ($rankA !== $rankB) {
					return $rankA <=> $rankB;
				}

				$direction = $fallback_direction;
				if ($sortOrder === 'custom' && $typeA === $typeB) {
					$direction = $type_direction[$typeA] ?? 'asc';
				}

				if ($typeA === 'date' && $typeB === 'date') {
					$timeA = $a['_btbl_time'];
					$timeB = $b['_btbl_time'];
					if ($timeA !== $timeB) {
						return $direction === 'desc' ? ($timeB <=> $timeA) : ($timeA <=> $timeB);
					}
				} else {
					$labelA = $this->option_label($a);
					$labelB = $this->option_label($b);
					if ($direction === 'desc') {
						$cmp = strnatcasecmp((string) $labelB, (string) $labelA);
						if ($cmp !== 0) {
							return $cmp;
						}
					} elseif ($direction === 'asc') {
						$cmp = strnatcasecmp((string) $labelA, (string) $labelB);
						if ($cmp !== 0) {
							return $cmp;
						}
					}
				}

				return ((int) $a['_btbl_index']) <=> ((int) $b['_btbl_index']);
			});

			foreach ($filter['options'] as &$option) {
				unset($option['_btbl_index'], $option['_btbl_type'], $option['_btbl_time']);
			}
			unset($option);
		}
		unset($filter);

		$filters = array_values($filters);

		if (!empty($definition['filter_order']) && is_array($definition['filter_order'])) {
			$filters = $this->order_filters($filters, $definition['filter_order']);
		}

		return $filters;
	}

	public function normalize_filter_option($option): array {
		if (is_array($option)) {
			$label = isset($option['label']) ? (string) $option['label'] : '';
			$value = isset($option['value']) ? (string) $option['value'] : $label;
			$search_terms_raw = isset($option['search_terms']) && is_array($option['search_terms']) ? $option['search_terms'] : [$value];
		} else {
			$label = (string) $option;
			$value = (string) $option;
			$search_terms_raw = [$value];
		}

		if ($value === '') {
			$value = $label;
		}
		if (empty($search_terms_raw)) {
			$search_terms_raw = [$value];
		}

		$search_terms = array_values(array_map('strval', $search_terms_raw));

		return [
			'label' => $label,
			'value' => $value,
			'search_terms' => $search_terms,
		];
	}

	public function get_preset_filters(array $definition): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public frontend filter parameters for shareable URLs; no state change.
		if (!isset($_GET['btbl_filter']) || !is_array($_GET['btbl_filter'])) {
			return [];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public frontend filter parameters for shareable URLs; no state change.
		$raw = map_deep(wp_unslash($_GET['btbl_filter']), 'sanitize_text_field');
		$filters = [];
		foreach ($raw as $key => $value) {
			$slug = sanitize_text_field($key);
			if (is_array($value)) {
				$filters[$slug] = array_filter(array_map('sanitize_text_field', $value));
			} else {
				$parts = array_map('trim', explode(',', (string) $value));
				$filters[$slug] = array_filter(array_map('sanitize_text_field', $parts));
			}
		}
		return $filters;
	}

	public function get_preset_search(array $definition): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public frontend search parameters for shareable URLs; no state change.
		$term = isset($_GET['btbl_search']) ? sanitize_text_field(wp_unslash($_GET['btbl_search'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public frontend search parameters for shareable URLs; no state change.
		$raw_cols = isset($_GET['btbl_search_cols']) ? map_deep(wp_unslash($_GET['btbl_search_cols']), 'sanitize_text_field') : (isset($_GET['btbl_search_columns']) ? map_deep(wp_unslash($_GET['btbl_search_columns']), 'sanitize_text_field') : []);
		$columns = [];
		if (!empty($raw_cols)) {
			if (is_array($raw_cols)) {
				$columns = array_filter(array_map('sanitize_text_field', wp_unslash($raw_cols)));
			} else {
				$parts = array_map('trim', explode(',', (string) $raw_cols));
				$columns = array_filter(array_map('sanitize_text_field', $parts));
			}
		}

		return [
			'term'    => $term,
			'columns' => $columns,
		];
	}

	public function get_default_sort_order(array $definition): array {
		if (empty($definition['columns'])) {
			return [];
		}
		$order = [];
		foreach ($definition['columns'] as $col) {
			$priority = isset($col['sort_priority']) ? (int) $col['sort_priority'] : 0;
			$direction = isset($col['sort_direction']) && in_array($col['sort_direction'], ['asc', 'desc'], true)
				? $col['sort_direction']
				: 'asc';
			$enabled = isset($col['sort_enabled']) ? (bool) $col['sort_enabled'] : ($priority > 0);
			$sortable = isset($col['sortable']) ? (bool) $col['sortable'] : true;
			if ($enabled && $priority > 0 && $sortable) {
				$order[] = [
					'slug' => $this->resolve_column_slug($col),
					'priority' => $priority,
					'direction' => $direction,
				];
			}
		}

		usort($order, static function ($a, $b) {
			if ($a['priority'] === $b['priority']) {
				return 0;
			}
			return ($a['priority'] < $b['priority']) ? -1 : 1;
		});

		return $order;
	}

	public function map_column_slug_to_index(array $definition): array {
		$columns = $definition['columns'] ?? [];
		return $this->build_slug_map($columns, function ($col, $idx) {
			return $idx;
		});
	}

	private function collect_column_indices(array $definition, callable $predicate): array {
		$indices = [];
		$columns = $definition['columns'] ?? [];
		foreach ($columns as $idx => $col) {
			if ($predicate($col)) {
				$indices[] = $idx;
			}
		}
		return $indices;
	}

	public function get_hidden_column_indices(array $definition): array {
		return $this->collect_column_indices($definition, static function ($col): bool {
			return !empty($col['hidden']);
		});
	}

	private function order_filters(array $filters, array $order): array {
		$order_map = [];
		foreach ($order as $idx => $slug) {
			$order_map[$slug] = $idx;
		}

		usort($filters, static function ($a, $b) use ($order_map) {
			$slugA = $a['slug'] ?? '';
			$slugB = $b['slug'] ?? '';
			$posA = array_key_exists($slugA, $order_map) ? $order_map[$slugA] : PHP_INT_MAX;
			$posB = array_key_exists($slugB, $order_map) ? $order_map[$slugB] : PHP_INT_MAX;
			if ($posA === $posB) {
				return 0;
			}
			return $posA < $posB ? -1 : 1;
		});

		return $filters;
	}

	private function option_label($option): string {
		if (is_array($option)) {
			return isset($option['label']) ? (string) $option['label'] : '';
		}
		return (string) $option;
	}

	private function normalize_data_type_priority_list(array $priority): array {
		$normalized = [];
		$seen = [];
		foreach ($priority as $key => $item) {
			$type_raw = null;
			$direction_raw = 'asc';

			if (is_array($item)) {
				if (array_key_exists('type', $item)) {
					$type_raw = $item['type'];
					$direction_raw = $item['direction'] ?? 'asc';
				} elseif (array_key_exists('data_type', $item)) {
					$type_raw = $item['data_type'];
					$direction_raw = $item['direction'] ?? 'asc';
				} elseif (count($item) === 1) {
					foreach ($item as $inner_key => $inner_value) {
						$type_raw = $inner_key;
						$direction_raw = $inner_value;
					}
				}
			} else {
				$type_raw = $item;
			}

			$token = $this->canonicalize_data_type_token($type_raw);
			if ($token === null || isset($seen[$token])) {
				continue;
			}
			$normalized[] = [
				'type' => $token,
				'direction' => $this->canonicalize_sort_direction($direction_raw),
			];
			$seen[$token] = true;
		}
		return $normalized;
	}

	private function parse_data_type_priority($raw_value): array {
		$raw_string = is_array($raw_value)
			? implode("\n", array_map('strval', $raw_value))
			: (string) $raw_value;
		$raw_string = trim($raw_string);
		if ($raw_string === '') {
			return [];
		}
		$lines = preg_split('/[\r\n]+/', $raw_string);
		if ($lines === false) {
			return [];
		}
		$priority = [];
		$seen = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$type_part = $line;
			$direction_part = 'asc';
			if (strpos($line, '=>') !== false) {
				[$type_part, $direction_part] = array_pad(explode('=>', $line, 2), 2, 'asc');
				$type_part = trim($type_part);
				$direction_part = trim($direction_part);
			}
			$token = $this->canonicalize_data_type_token($type_part);
			if ($token === null || isset($seen[$token])) {
				continue;
			}
			$priority[] = [
				'type' => $token,
				'direction' => $this->canonicalize_sort_direction($direction_part),
			];
			$seen[$token] = true;
		}
		return $priority;
	}

	private function canonicalize_data_type_token($token): ?string {
		$clean = sanitize_key((string) $token);
		if ($clean === '') {
			return null;
		}
		$map = [
			'int' => 'number',
			'integer' => 'number',
			'number' => 'number',
			'numeric' => 'number',
			'float' => 'number',
			'decimal' => 'number',
			'date' => 'date',
			'string' => 'text',
			'text' => 'text',
		];
		return $map[$clean] ?? null;
	}

	private function canonicalize_sort_direction($direction, string $default = 'asc'): string {
		$clean = sanitize_key((string) $direction);
		return in_array($clean, ['asc', 'desc'], true) ? $clean : $default;
	}

	private function detect_option_type($option): string {
		$value = '';
		if (is_array($option)) {
			$value = isset($option['value']) ? (string) $option['value'] : $this->option_label($option);
		} else {
			$value = (string) $option;
		}
		$value = trim(wp_strip_all_tags($value));
		if ($value === '') {
			return 'text';
		}

		$has_letters = preg_match('/[a-z]/i', $value);
		$date_like = preg_match('/^(?:\\d{4}[-\\/]\\d{1,2}[-\\/]\\d{1,2}|\\d{1,2}[-\\/]\\d{1,2}[-\\/]\\d{4})(?:[ T]\\d{1,2}:\\d{2}(?::\\d{2})?)?$/', $value);
		if (!$date_like && $has_letters) {
			$date_like = preg_match('/\\b\\d{4}\\b/', $value);
		}
		if ($date_like && $this->parse_date_option($value) !== null) {
			return 'date';
		}

		if (!$has_letters && preg_match('/^[+-]?\\d+(?:\\.\\d+)?$/', $value)) {
			// `?` (not `*`): a single optional decimal part. `*` matched multi-dot strings like
			// IP addresses ("10.0.0.1") and version numbers ("1.2.3"), mis-sorting them numerically.
			return 'number';
		}

		return 'text';
	}

	private function parse_date_option($value): ?int {
		$value = trim(wp_strip_all_tags((string) $value));
		if ($value === '') {
			return null;
		}
		$timestamp = strtotime($value);
		if ($timestamp !== false) {
			return $timestamp;
		}
		if (preg_match('/\\b(\\d{4})\\b/', $value, $matches)) {
			$fallback = strtotime($matches[1] . '-01-01');
			if ($fallback !== false) {
				return $fallback;
			}
		}
		return null;
	}

	public function resolve_value(WP_Post $post, array $column): string {
		$value = '';

		if ($column['source'] === 'core') {
			$value = $this->get_core_value($post, $column['key']);
		} elseif ($column['source'] === 'tax') {
			$value = $this->get_taxonomy_value($post, $column['key']);
		} else {
			$value = $this->get_meta_value($post->ID, $column['key']);
		}

		if (is_array($value)) {
			$value = implode(', ', array_map('wp_kses_post', $value));
		}

		if (!empty($column['format_date'])) {
			$format = isset($column['date_format']) ? (string) $column['date_format'] : '';
			$value = $this->format_date_value($value, $format);
		}

		return wp_kses_post((string) $value);
	}

	/**
	 * Build a list of date_format strings aligned to a column list's order: one entry per
	 * column, a string when that column has the "Format as date" toggle on, or null when it
	 * does not. Returns [] when no column is date-formatted so callers can skip cheaply.
	 *
	 * Used by the CSV and external-DB paths, whose rows are emitted already ordered to match
	 * the column list, so the index-aligned list maps straight onto each row's cells. (The
	 * custom-data path applies formatting earlier, before its value-override stage, so it keeps
	 * its own slug-keyed builder.)
	 */
	private function build_ordered_date_formats(array $columns): array {
		$has_any = false;
		$formats = [];
		foreach (array_values($columns) as $col) {
			if (is_array($col) && !empty($col['format_date'])) {
				$formats[] = isset($col['date_format']) ? (string) $col['date_format'] : '';
				$has_any = true;
			} else {
				$formats[] = null;
			}
		}
		return $has_any ? $formats : [];
	}

	/**
	 * Apply an index-aligned date_format list (from build_ordered_date_formats) to rows that are
	 * already ordered to match the same column list. Cells whose format entry is null are left as-is.
	 */
	private function apply_ordered_date_formats(array $rows, array $formats): array {
		if (empty($formats)) {
			return $rows;
		}
		foreach ($rows as &$row) {
			if (!is_array($row)) {
				continue;
			}
			foreach ($formats as $idx => $format) {
				if ($format === null || !array_key_exists($idx, $row)) {
					continue;
				}
				$row[$idx] = $this->format_date_value((string) $row[$idx], $format);
			}
		}
		unset($row);
		return $rows;
	}

	private function format_date_value($value, string $format): string {
		$format = $format !== '' ? $format : get_option('date_format');
		if ($value === '' || $format === '') {
			return (string) $value;
		}

		$timestamp = null;
		if (is_numeric($value)) {
			$intVal = (int) $value;
			// Treat very large integers as JS millisecond timestamps. The threshold
			// (1e11) sits well above any plausible seconds-timestamp date (1e11 s ~ year
			// 5138) and below any modern ms timestamp (~1.7e12), so a seconds-timestamp
			// for a post-2033 date is no longer misread as milliseconds.
			if ($intVal > 100000000000) {
				$intVal = (int) ($intVal / 1000);
			}
			// Without a lower bound, a small integer (a year like 2024, an age, a count,
			// an ID) would be read as epoch seconds and render as a 1970-era date. Only
			// treat integers large enough to be a plausible real timestamp (|n| >= 1e8,
			// ~year 1973) as epoch; leave smaller numbers as their raw value.
			if (abs($intVal) < 100000000) {
				return (string) $value;
			}
			$timestamp = $intVal;
		} else {
			$timestamp = strtotime((string) $value);
		}

		if ($timestamp === false || $timestamp === null) {
			return (string) $value;
		}

		return date_i18n($format, $timestamp);
	}

	private function apply_overrides_with(string $value, string $column_slug, array $overrides, callable $resolve_replace): string {
		if ($value === '' || empty($overrides)) {
			return $value;
		}

		foreach ($overrides as $rule) {
			if (!is_array($rule) || !isset($rule['column']) || $rule['column'] === '') {
				continue;
			}
			if ($rule['column'] !== $column_slug && $rule['column'] !== '*') {
				continue;
			}
			$search = isset($rule['search']) ? (string) $rule['search'] : '';
			$replace = isset($rule['replace']) ? (string) $rule['replace'] : '';
			if ($search === '') {
				continue;
			}
			$resolved_replace = $resolve_replace($replace);
			if (!empty($rule['regex'])) {
				$pattern = $search;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Suppresses warnings from user-supplied regex patterns; handler is immediately restored.
				set_error_handler(static function () {});
				$result = preg_replace($pattern, $resolved_replace, $value);
				restore_error_handler();
				if (is_string($result)) {
					$value = $result;
				}
			} else {
				$value = str_replace($search, $resolved_replace, $value);
			}
		}

		return $value;
	}

	private function apply_overrides(string $value, string $column_slug, array $overrides, WP_Post $post): string {
		return $this->apply_overrides_with($value, $column_slug, $overrides, function (string $replace) use ($post): string {
			return $this->replace_merge_tags($replace, $post);
		});
	}

	private function apply_overrides_for_row(string $value, string $column_slug, array $overrides, array $row_tokens): string {
		return $this->apply_overrides_with($value, $column_slug, $overrides, function (string $replace) use ($row_tokens): string {
			return $this->replace_row_tokens($replace, $row_tokens);
		});
	}

	private function replace_merge_tags(string $text, WP_Post $post): string {
		if ($text === '') {
			return $text;
		}

		$pattern = '/{{\s*(core|meta):([^}]+)\s*}}/i';
		$text = preg_replace_callback($pattern, function ($matches) use ($post) {
			$source = strtolower($matches[1]);
			$raw_key = trim($matches[2]);
			$key = sanitize_key($raw_key);
			if ($key === '') {
				return '';
			}

			$value = '';
			if ($source === 'core') {
				$value = $this->get_core_value($post, $key);
			} else {
				$value = $this->get_meta_value($post->ID, $key);
			}

			if (is_array($value)) {
				$value = implode(', ', array_map('wp_kses_post', $value));
			}

			return wp_kses_post((string) $value);
		}, $text);

		return $text;
	}

	private function replace_row_tokens(string $text, array $row_tokens): string {
		if ($text === '' || empty($row_tokens)) {
			return $text;
		}
		return preg_replace_callback('/{{\\s*(?:row\\.)?([a-z0-9_:-]+)\\s*}}/i', function ($matches) use ($row_tokens) {
			$token = strtolower($matches[1]);
			return array_key_exists($token, $row_tokens) ? $row_tokens[$token] : $matches[0];
		}, $text);
	}

	public function get_core_value(WP_Post $post, string $key) {
		switch ($key) {
			case 'ID':
				return $post->ID;
			case 'post_title':
				return get_the_title($post);
			case 'post_excerpt':
				return get_the_excerpt($post);
			case 'post_content':
				return wp_trim_words($post->post_content, 40);
			case 'post_date':
				return get_the_date('', $post);
			case 'post_modified':
				return get_the_modified_date('', $post);
			case 'post_author':
				return get_the_author_meta('display_name', $post->post_author);
			case 'post_status':
				return $post->post_status;
			case 'permalink':
				return get_permalink($post);
			default:
				return isset($post->$key) ? $post->$key : '';
		}
	}

	private function get_taxonomy_value(WP_Post $post, string $taxonomy): string {
		$taxonomy = sanitize_key($taxonomy);
		if ($taxonomy === '' || !taxonomy_exists($taxonomy) || !is_object_in_taxonomy($post->post_type, $taxonomy)) {
			return '';
		}
		$terms = get_the_terms($post, $taxonomy);
		if (is_wp_error($terms) || empty($terms)) {
			return '';
		}
		$names = array_map(static function ($term) {
			return $term->name;
		}, $terms);
		return implode(', ', $names);
	}

	public function get_meta_value(int $post_id, string $key) {
		if (function_exists('get_field')) {
			$acf_value = get_field($key, $post_id);
			if ($acf_value !== null) {
				return $acf_value;
			}
		}

		return get_post_meta($post_id, $key, true);
	}

	public function get_supported_post_types(): array {
		$pts = get_post_types(['public' => true], 'objects');
		$out = [];
		foreach ($pts as $pt) {
			$out[$pt->name] = $pt->labels->singular_name;
		}
		return $out;
	}

	public function get_taxonomies_for_post_type(string $post_type): array {
		$taxonomies = get_object_taxonomies($post_type, 'objects');
		$out = [];
		foreach ($taxonomies as $tax_obj) {
			if (!$tax_obj->show_ui) {
				continue;
			}
			$terms = get_terms([
				'taxonomy'   => $tax_obj->name,
				'hide_empty' => false,
			]);
			$term_items = [];
			if (!is_wp_error($terms)) {
				foreach ($terms as $term) {
					$term_items[] = [
						'id'   => (int) $term->term_id,
						'name' => $term->name,
					];
				}
			}
			$out[] = [
				'slug'  => $tax_obj->name,
				'label' => $tax_obj->labels->singular_name,
				'terms' => $term_items,
			];
		}

		usort($out, static function ($a, $b) {
			return strcasecmp((string) $a['label'], (string) $b['label']);
		});

		return $out;
	}

	public function get_taxonomies_for_post_types(array $post_types): array {
		$post_types = $this->sanitize_post_types($post_types, 'wp_query');
		$combined = [];
		foreach ($post_types as $pt) {
			$items = $this->get_taxonomies_for_post_type($pt);
			foreach ($items as $item) {
				$slug = $item['slug'];
				if (!isset($combined[$slug])) {
					$item['sources'] = [$pt];
					$combined[$slug] = $item;
				} else {
					$combined[$slug]['sources'][] = $pt;
				}
			}
		}
		$merged = array_values($combined);
		usort($merged, static function ($a, $b) {
			return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
		});
		return $merged;
	}

	public function get_available_fields(string $post_type): array {
		$core_fields = [
			'ID'            => __('ID', 'baratables'),
			'post_title'    => __('Title', 'baratables'),
			'post_excerpt'  => __('Excerpt', 'baratables'),
			'post_content'  => __('Content', 'baratables'),
			'post_date'     => __('Published date', 'baratables'),
			'post_modified' => __('Modified date', 'baratables'),
			'post_author'   => __('Author', 'baratables'),
			'post_status'   => __('Status', 'baratables'),
			'permalink'     => __('Permalink', 'baratables'),
		];

		$wc_allowed_meta = [];
		if ($post_type === 'product' && class_exists('WooCommerce')) {
			$wc_allowed_meta = [
				'_price',
				'_regular_price',
				'_sale_price',
				'_sale_price_dates_from',
				'_sale_price_dates_to',
				'_sku',
				'_stock',
				'_stock_status',
				'_manage_stock',
				'_backorders',
				'total_sales',
				'_tax_class',
				'_weight',
				'_length',
				'_width',
				'_height',
				'_virtual',
				'_downloadable',
				'_product_image_gallery',
				'_thumbnail_id',
				'_product_url',
				'_button_text',
			];
		}

		$cache_key = 'available_meta_keys_' . md5($post_type);
		$meta_keys = wp_cache_get($cache_key, 'baratables');
		if (!is_array($meta_keys)) {
			$meta_key_map = [];
			$post_ids = get_posts([
				'post_type' => $post_type,
				'post_status' => 'any',
				'posts_per_page' => 50,
				'fields' => 'ids',
				'no_found_rows' => true,
				'orderby' => 'modified',
				'order' => 'DESC',
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			]);
			foreach ($post_ids as $post_id) {
				$post_meta = get_post_meta((int) $post_id);
				if (!is_array($post_meta)) {
					continue;
				}
				foreach (array_keys($post_meta) as $meta_key) {
					$meta_key = (string) $meta_key;
					if ($meta_key === '' || strpos($meta_key, '_') === 0) {
						continue;
					}
					$meta_key_map[$meta_key] = true;
					if (count($meta_key_map) >= 50) {
						break 2;
					}
				}
			}
			$meta_keys = array_keys($meta_key_map);
			natcasesort($meta_keys);
			$meta_keys = array_values($meta_keys);
			wp_cache_set($cache_key, $meta_keys, 'baratables', 5 * MINUTE_IN_SECONDS);
		}

		$meta_keys = array_map(static function ($key) {
			return (string) $key;
		}, (array) $meta_keys);

		if (!empty($wc_allowed_meta)) {
			$meta_keys = array_values(array_unique(array_merge($meta_keys, $wc_allowed_meta)));
		}
		natcasesort($meta_keys);
		$meta_keys = array_values($meta_keys);

		$tax_fields = [];
		$tax_objects = get_object_taxonomies($post_type, 'objects');
		if (is_array($tax_objects)) {
			foreach ($tax_objects as $tax_obj) {
				if (!$tax_obj->show_ui) {
					continue;
				}
				$tax_fields[$tax_obj->name] = $tax_obj->labels->singular_name;
			}
		}

		return [
			'core' => $core_fields,
			'meta' => $meta_keys,
			'tax'  => $tax_fields,
		];
	}

	public function get_available_fields_for_post_types(array $post_types): array {
		$post_types = $this->sanitize_post_types($post_types, 'wp_query');
		if (empty($post_types)) {
			return ['core' => [], 'meta' => [], 'tax' => [], 'meta_sources' => [], 'tax_sources' => []];
		}
		$core = [];
		$meta = [];
		$meta_sources = [];
		$tax = [];
		$tax_sources = [];
		foreach ($post_types as $idx => $pt) {
			$fields = $this->get_available_fields($pt);
			if ($idx === 0) {
				$core = $fields['core'];
			}
			foreach ($fields['meta'] as $meta_key) {
				$meta[] = $meta_key;
				$meta_sources[$meta_key][] = $pt;
			}
			if (!empty($fields['tax'])) {
				foreach ($fields['tax'] as $slug => $label) {
					if (!isset($tax[$slug])) {
						$tax[$slug] = $label;
					}
					$tax_sources[$slug][] = $pt;
				}
			}
		}
		$meta = array_values(array_unique($meta));
		return [
			'core' => $core,
			'meta' => $meta,
			'tax'  => $tax,
			'meta_sources' => $meta_sources,
			'tax_sources' => $tax_sources,
		];
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
		$logged_out_policy = $access_policy['logged_out_policy'] ?? 'all';
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
		$logged_out_policy = $access_policy['logged_out_policy'] ?? 'all';
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

	public function build_column_state_from_definition(array $columns): array {
		$state = [
			'selected_filters' => [],
			'selected_dropdown_multi' => [],
			'selected_dropdown_search' => [],
			'selected_filter_sort' => [],
			'selected_filter_values' => [],
			'selected_custom_labels' => [],
			'selected_filter_labels' => [],
			'selected_filter_type_priority' => [],
			'selected_filter_strict' => [],
			'selected_date_format' => [],
			'selected_format_date' => [],
			'selected_hide_titles' => [],
			'selected_searchable' => [],
			'selected_hidden_columns' => [],
			'selected_sort_priority' => [],
			'selected_sort_direction' => [],
			'selected_sort_enabled' => [],
			'selected_sortable' => [],
			'selected_auto_labels' => [],
		];

		foreach ($columns as $col) {
			if (!is_array($col) || !isset($col['key'])) {
				continue;
			}
			$source = isset($col['source']) ? sanitize_key((string) $col['source']) : 'core';
			$source = $source !== '' ? $source : 'core';
			$slug = self::build_slug($source, (string) $col['key']);
			if ($slug === '') {
				continue;
			}

			$filter_type = $col['filter'] ?? 'none';
			$filter_sort = $col['filter_sort'] ?? 'asc';
			if ($filter_sort === 'none') {
				$filter_sort = 'custom';
			}
			if (!in_array($filter_sort, ['asc', 'desc', 'custom'], true)) {
				$filter_sort = 'asc';
			}
			$state['selected_filter_sort'][$slug] = $filter_sort;
			if (!empty($col['filter_values']) && is_array($col['filter_values'])) {
				$state['selected_filter_values'][$slug] = array_values($col['filter_values']);
			}
			if (!empty($col['label'])) {
				$state['selected_custom_labels'][$slug] = $col['label'];
			}
			$state['selected_auto_labels'][$slug] = !empty($col['auto_label']);
			if (array_key_exists('filter_label', $col)) {
				$state['selected_filter_labels'][$slug] = (string) $col['filter_label'];
			}
			if (!empty($col['filter_type_priority']) && is_array($col['filter_type_priority'])) {
				$state['selected_filter_type_priority'][$slug] = $this->normalize_data_type_priority_list($col['filter_type_priority']);
			}
			if (!empty($col['filter_strict'])) {
				$state['selected_filter_strict'][$slug] = true;
			}
			if (!empty($col['format_date'])) {
				$state['selected_format_date'][$slug] = true;
			}
			if (!empty($col['date_format'])) {
				$state['selected_date_format'][$slug] = (string) $col['date_format'];
			}
			if (!empty($col['hide_title'])) {
				$state['selected_hide_titles'][$slug] = true;
				$state['selected_custom_labels'][$slug] = '';
			}
			if (array_key_exists('searchable', $col)) {
				$state['selected_searchable'][$slug] = (bool) $col['searchable'];
			} else {
				$state['selected_searchable'][$slug] = true;
			}
			if (isset($col['sort_priority'])) {
				$priority = (int) $col['sort_priority'];
				if ($priority > 0) {
					$state['selected_sort_priority'][$slug] = $priority;
				}
			}
			if (isset($col['sort_direction'])) {
				$state['selected_sort_direction'][$slug] = in_array($col['sort_direction'], ['asc', 'desc'], true) ? $col['sort_direction'] : 'asc';
			}
			if (!empty($col['sort_enabled'])) {
				$state['selected_sort_enabled'][$slug] = true;
			} elseif (isset($col['sort_priority']) && (int) $col['sort_priority'] > 0) {
				$state['selected_sort_enabled'][$slug] = true;
			}
			if (!empty($col['hidden'])) {
				$state['selected_hidden_columns'][$slug] = true;
			}
			if (array_key_exists('sortable', $col)) {
				$state['selected_sortable'][$slug] = (bool) $col['sortable'];
			} else {
				$state['selected_sortable'][$slug] = true;
			}

			if (in_array($filter_type, ['dropdown', 'dropdown_multi', 'dropdown_plain', 'dropdown_plain_multi'], true)) {
				$state['selected_filters'][$slug] = 'dropdown';
				$state['selected_dropdown_multi'][$slug] = in_array($filter_type, ['dropdown_multi', 'dropdown_plain_multi'], true);
				$state['selected_dropdown_search'][$slug] = in_array($filter_type, ['dropdown', 'dropdown_multi'], true);
			} else {
				$state['selected_filters'][$slug] = $filter_type;
			}
		}

		return $state;
	}

	public function filter_column_state_by_slug_map(array $state, array $slug_map): array {
		if (empty($slug_map)) {
			return $state;
		}
		foreach ($state as $key => $values) {
			if (is_array($values)) {
				$state[$key] = array_intersect_key($values, $slug_map);
			}
		}
		return $state;
	}

	public function apply_column_state_defaults(array $state, array $selected_columns): array {
		if (empty($state['selected_searchable'])) {
			foreach ($selected_columns as $slug) {
				$state['selected_searchable'][$slug] = true;
			}
		}
		if (empty($state['selected_sortable']) && !empty($selected_columns)) {
			foreach ($selected_columns as $slug) {
				$state['selected_sortable'][$slug] = true;
			}
		}
		if (empty($state['selected_sort_direction']) && !empty($selected_columns)) {
			foreach ($selected_columns as $slug) {
				$state['selected_sort_direction'][$slug] = 'asc';
			}
		}
		return $state;
	}

	public function build_column_state_from_request(array $raw, array $columns): array {
		$filter_types = $this->sanitize_filter_types($raw['filters'] ?? [], $raw['dropdown_multi'] ?? [], $raw['dropdown_search'] ?? []);
		$filter_sorts = $this->sanitize_filter_sorts($raw['filter_sorts'] ?? []);
		$filter_type_priority = $this->sanitize_filter_type_priority($raw['filter_type_priority'] ?? []);
		$filter_values = $this->sanitize_filter_values($raw['filter_values'] ?? []);
		$filter_strict = $this->sanitize_column_flags($raw['filter_strict'] ?? [], $columns, false);
		$custom_labels = $this->sanitize_custom_labels($raw['custom_labels'] ?? []);
		$filter_labels = $this->sanitize_filter_labels($raw['filter_labels'] ?? []);
		$searchable = $this->sanitize_column_flags($raw['searchable'] ?? [], $columns, false);
		$hide_titles = $this->sanitize_column_flags($raw['hide_titles'] ?? []);
		$hidden_columns = $this->sanitize_column_flags($raw['hidden_columns'] ?? []);
		$sort_priority = $this->sanitize_sort_priority($raw['sort_priority'] ?? []);
		$sort_direction = $this->sanitize_sort_direction($raw['sort_direction'] ?? []);
		$sort_enabled = $this->sanitize_sort_enabled($raw['sort_enabled'] ?? [], $columns);
		$sortable = $this->sanitize_column_flags($raw['sortable'] ?? [], $columns, true);
		$date_formats = $this->sanitize_date_formats($raw['date_formats'] ?? []);
		$format_date_flags = $this->sanitize_column_flags($raw['format_date_flags'] ?? [], $columns, false);
		foreach ($date_formats as $slug => $fmt) {
			$format_date_flags[$slug] = true;
		}

		return [
			'filter_types' => $filter_types,
			'filter_sorts' => $filter_sorts,
			'filter_type_priority' => $filter_type_priority,
			'filter_values' => $filter_values,
			'filter_strict' => $filter_strict,
			'custom_labels' => $custom_labels,
			'filter_labels' => $filter_labels,
			'searchable' => $searchable,
			'hide_titles' => $hide_titles,
			'hidden_columns' => $hidden_columns,
			'sort_priority' => $sort_priority,
			'sort_direction' => $sort_direction,
			'sort_enabled' => $sort_enabled,
			'sortable' => $sortable,
			'date_formats' => $date_formats,
			'format_date_flags' => $format_date_flags,
		];
	}

	public function build_column_choices(array $display_columns, array $definition_columns): array {
		$display_columns = is_array($display_columns) ? $display_columns : [];
		$definition_columns = is_array($definition_columns) ? $definition_columns : [];
		$columns = !empty($display_columns) ? $display_columns : $definition_columns;
		return $this->build_column_slug_label_map($columns);
	}

	public function build_column_slug_label_map(array $columns): array {
		$map = [];
		foreach ($columns as $col) {
			if (!is_array($col) || !isset($col['key'])) {
				continue;
			}
			$slug = $this->resolve_column_slug($col);
			if ($slug === '') {
				continue;
			}
			$label = $col['label'] ?? $col['key'];
			$map[$slug] = (string) $label;
		}
		return $map;
	}

	public function build_column_slug_label_list(array $columns): array {
		$map = $this->build_column_slug_label_map($columns);
		$list = [];
		foreach ($map as $slug => $label) {
			$list[] = [
				'slug' => $slug,
				'label' => $label,
			];
		}
		return $list;
	}

	private function normalize_tokens($value): array {
		if (is_array($value)) {
			$flat = [];
			array_walk_recursive($value, function ($item) use (&$flat) {
				$flat[] = is_string($item) ? $item : (is_scalar($item) ? (string) $item : '');
			});
			$value = implode(',', $flat);
		}
		if (!is_string($value)) {
			$value = (string) $value;
		}
		$parts = array_filter(array_map('trim', explode(',', $value)), static function ($part) {
			return $part !== '';
		});
		return array_values(array_unique($parts));
	}
}


class BaraTables_Chart_Service {
	private BaraTables_Repository $table_repo;
	private BaraTables_Chart_Repository $chart_repo;
	private BaraTables_Service $table_service;

	public function __construct(BaraTables_Repository $table_repo, BaraTables_Chart_Repository $chart_repo, BaraTables_Service $table_service) {
		$this->table_repo = $table_repo;
		$this->chart_repo = $chart_repo;
		$this->table_service = $table_service;
	}

	public function find_chart(string $id, bool $include_trash = false): ?array {
		return $this->chart_repo->find_chart($id, $include_trash);
	}

	public function get_chart_post_id(string $slug): int {
		return $this->chart_repo->get_post_id_by_slug($slug);
	}

	public function build_form_context(?array $chart_definition, ?string $selected_table_id = null): array {
		$chart_definition = $chart_definition ?? [];
		$tables = $this->table_repo->get_definitions();
		$table_choices = [];
		$tables_by_id = [];
		foreach ($tables as $table) {
			if (!is_array($table) || empty($table['id'])) {
				continue;
			}
			$label = $table['name'] ?? $table['id'];
			$table_choices[$table['id']] = $label;
			$tables_by_id[$table['id']] = $table;
		}

		$table_definition = null;
		$requested_table_id = $selected_table_id ?: ($chart_definition['table_id'] ?? '');
		if ($requested_table_id !== '') {
			// get_definitions() already returned every (non-trashed) table's full
			// definition via the same mapper find_definition() uses, and slugs/ids are
			// unique, so the in-memory entry is identical to a fresh lookup. Reuse it and
			// only fall back to a DB round trip if the id isn't in the loaded set.
			$table_definition = $tables_by_id[$requested_table_id]
				?? $this->table_repo->find_definition($requested_table_id);
		}
		if (!$table_definition && !empty($tables)) {
			$table_definition = $tables[0];
		}

		$columns = $table_definition['columns'] ?? [];
		$chart_options_raw = isset($chart_definition['chart']) && is_array($chart_definition['chart'])
			? $chart_definition['chart']
			: $this->table_service->get_default_chart_options();
		if (empty($chart_definition)) {
			$chart_options_raw['enabled'] = true;
		}
		$chart_options = $this->table_service->sanitize_chart_options($chart_options_raw, $columns);

		// R28: when showing the chart's OWN table (not a deliberate ?table switch), report any
		// saved column choices that no longer exist on that table so the user isn't left guessing.
		$dropped_columns = [];
		$is_own_table = !empty($chart_definition) && $requested_table_id === ($chart_definition['table_id'] ?? '');
		if ($is_own_table && !empty($columns)) {
			$slug_set = [];
			foreach ($columns as $col) {
				if (!empty($col['slug'])) {
					$slug_set[$col['slug']] = true;
				}
			}
			$check = [];
			if (!empty($chart_options_raw['x_axis'])) {
				$check[] = (string) $chart_options_raw['x_axis'];
			}
			foreach ((array) ($chart_options_raw['series'] ?? []) as $series_slug) {
				$check[] = (string) $series_slug;
			}
			foreach (['gantt_label', 'gantt_start', 'gantt_end', 'gantt_group', 'gantt_progress'] as $gantt_key) {
				if (!empty($chart_options_raw[$gantt_key])) {
					$check[] = (string) $chart_options_raw[$gantt_key];
				}
			}
			foreach (array_unique(array_filter($check)) as $slug) {
				if (!isset($slug_set[$slug])) {
					$dropped_columns[] = $slug;
				}
			}
		}

		return [
			'definition'       => $chart_definition,
			'chart_options'    => $chart_options,
			'table_choices'    => $table_choices,
			'table_definition' => $table_definition,
			'selected_table'   => $table_definition['id'] ?? '',
			'column_choices'   => $this->table_service->build_column_choices($columns, $columns),
			'dropped_columns'  => $dropped_columns,
			'active_tab'       => 'btbl-tab-chart',
		];
	}

	public function prepare_chart_definition(array $request, ?array $existing_chart = null): array {
		$errors = [];
		$name = isset($request['name']) ? sanitize_text_field($request['name']) : '';
		$table_id = isset($request['table_id']) ? sanitize_text_field($request['table_id']) : '';
		$chart_raw = isset($request['chart']) && is_array($request['chart']) ? $request['chart'] : [];

		$table_definition = $table_id !== '' ? $this->table_repo->find_definition($table_id) : null;
		if (!$table_definition) {
			$errors[] = __('Selected table not found.', 'baratables');
			$table_definition = null;
		}
		$columns = $table_definition['columns'] ?? [];
		$chart_options = $this->table_service->sanitize_chart_options($chart_raw, $columns);

		$chart = $existing_chart ?? [];
		$chart['name'] = $name !== '' ? $name : __('Untitled Chart', 'baratables');
		$chart['table_id'] = $table_definition['id'] ?? $table_id;
		$chart['chart'] = $chart_options;
		if (empty($chart['id'])) {
			$chart['id'] = BaraTables_Id_Generator::generate_chart_id();
		}
		if (empty($chart['status'])) {
			$chart['status'] = 'publish';
		}

		return [
			'definition'       => $chart,
			'table_definition' => $table_definition,
			'errors'           => $errors,
		];
	}

	public function get_render_context(string $chart_id): ?array {
		$chart = $this->find_chart($chart_id);
		if (!$chart || ($chart['status'] ?? '') !== 'publish') {
			return null;
		}
		$table = $this->table_service->find_definition($chart['table_id'] ?? '', true);
		if (!$table) {
			return null;
		}
		$chart_options = $this->table_service->sanitize_chart_options($chart['chart'] ?? [], $table['columns'] ?? []);
		$rows = $this->table_service->get_rows($table);

		return [
			'chart'         => $chart,
			'table'         => $table,
			'chart_options' => $chart_options,
			'rows'          => $rows,
		];
	}

}
