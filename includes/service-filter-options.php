<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Filter-option building for BaraTables_Service: turning column definitions + row data into
 * the dropdown/checkbox/date filter option sets, their sort order and data-type detection.
 * Extracted from the service class; runs in the class scope via the trait.
 */
trait BaraTables_Filter_Options_Trait {
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
			$filter_label = array_key_exists('filter_label', $col) ? (string) $col['filter_label'] : (string) ($col['label'] ?? '');
			// An auto-labeled manual column stores its "Column N" default as a literal string in
			// whatever locale saved it. The <th> re-localizes via display_column_label() at render
			// so it follows the visitor's language; the filter heading did not, and showed
			// "Column 3" untranslated. Re-localize here too, but only when the user never set a
			// distinct filter label (i.e. it still equals the column's own auto default).
			if (!empty($col['auto_label']) && $filter_label === (string) ($col['label'] ?? '')) {
				$filter_label = $this->display_column_label($col, (int) $index, (string) ($definition['source_type'] ?? ''));
			}
			$filters[$index] = [
				'column_index' => $index,
				'label'        => $filter_label,
				'type'         => $col['filter'],
				'options'      => $has_custom_values ? $custom_values : [],
				'slug'         => $this->resolve_column_slug($col),
				'filter_sort'  => $col['filter_sort'] ?? 'asc',
				'has_custom_values' => $has_custom_values,
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
						// only the first occurrence of each distinct value: at the configured row limit
						// (up to 10,000) a low-cardinality column would otherwise rebuild the same
						// option once per row.
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

			// Decorate each option once with its sort keys -- the detected type and (for dates) the
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

	public function get_preset_filters(): array {
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
				$filters[$slug] = self::filter_non_empty(array_map('sanitize_text_field', $value));
			} else {
				$parts = array_map('trim', explode(',', (string) $value));
				$filters[$slug] = self::filter_non_empty(array_map('sanitize_text_field', $parts));
			}
		}
		return $filters;
	}

	public function get_preset_search(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public frontend search parameters for shareable URLs; no state change.
		$term = isset($_GET['btbl_search']) ? sanitize_text_field(wp_unslash($_GET['btbl_search'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public frontend search parameters for shareable URLs; no state change.
		$raw_cols = isset($_GET['btbl_search_cols']) ? map_deep(wp_unslash($_GET['btbl_search_cols']), 'sanitize_text_field') : (isset($_GET['btbl_search_columns']) ? map_deep(wp_unslash($_GET['btbl_search_columns']), 'sanitize_text_field') : []);
		$columns = [];
		if (!empty($raw_cols)) {
			if (is_array($raw_cols)) {
				// Already unslashed + sanitized via map_deep() above; a second wp_unslash() here
				// would strip backslashes out of legitimate values.
				$columns = self::filter_non_empty($raw_cols);
			} else {
				$parts = array_map('trim', explode(',', (string) $raw_cols));
				$columns = self::filter_non_empty(array_map('sanitize_text_field', $parts));
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
		return $this->build_slug_index_map($definition['columns'] ?? []);
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

}
