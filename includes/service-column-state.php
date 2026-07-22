<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Column-state assembly for BaraTables_Service: turning a stored column definition or an
 * editor request into the per-column flag/label state the admin form and renderer consume.
 * Extracted from the service class; runs in the class scope via the trait.
 */
trait BaraTables_Column_State_Trait {
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
		foreach ($columns as $index => $col) {
			if (!is_array($col) || !isset($col['key'])) {
				continue;
			}
			$slug = $this->resolve_column_slug($col);
			if ($slug === '') {
				continue;
			}
			// An auto-labeled manual column stores its "Column N" default as a literal string in
			// whatever locale saved it. Re-localize to the visitor's language here, matching the
			// <th> (display_column_label) and the filter heading, so a chart's series and axis
			// names are not the only place that shows "Column 3" untranslated.
			if (($col['source'] ?? '') === 'custom' && !empty($col['auto_label'])) {
				/* translators: %d is the column number. */
				$label = sprintf(__('Column %d', 'baratables'), (int) $index + 1);
			} else {
				$label = $col['label'] ?? $col['key'];
			}
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
