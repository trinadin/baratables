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
	/**
	 * Build the canonical editor state for each configured column, keyed by column slug.
	 *
	 * The HTML form still posts separate WordPress-style namespaces (btbl_filters[],
	 * btbl_searchable[], etc.), but those transport details stop at the request boundary. From
	 * this point onward one record owns every setting for a column.
	 */
	public function build_editor_column_records_from_definition(array $columns): array {
		$records = [];
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

			$stored_filter = (string) ($col['filter'] ?? 'none');
			$filter = $stored_filter;
			$dropdown_multi = false;
			$dropdown_search = false;
			if (in_array($stored_filter, ['dropdown', 'dropdown_multi', 'dropdown_plain', 'dropdown_plain_multi'], true)) {
				$filter = 'dropdown';
				$dropdown_multi = in_array($stored_filter, ['dropdown_multi', 'dropdown_plain_multi'], true);
				$dropdown_search = in_array($stored_filter, ['dropdown', 'dropdown_multi'], true);
			}

			$filter_sort = (string) ($col['filter_sort'] ?? 'asc');
			if ($filter_sort === 'none') {
				$filter_sort = 'custom';
			}
			if (!in_array($filter_sort, ['asc', 'desc', 'custom'], true)) {
				$filter_sort = 'asc';
			}
			$priority = max(0, (int) ($col['sort_priority'] ?? 0));

			$records[$slug] = [
				'slug' => $slug,
				'label' => (string) ($col['label'] ?? ''),
				'filter' => $filter,
				'dropdown_multi' => $dropdown_multi,
				'dropdown_search' => $dropdown_search,
				'filter_sort' => $filter_sort,
				'filter_values' => !empty($col['filter_values']) && is_array($col['filter_values']) ? array_values($col['filter_values']) : [],
				'custom_label' => !empty($col['auto_label']) ? '' : (string) ($col['label'] ?? ''),
				'auto_label' => !empty($col['auto_label']),
				'filter_label' => array_key_exists('filter_label', $col) ? (string) $col['filter_label'] : null,
				'filter_type_priority' => !empty($col['filter_type_priority']) && is_array($col['filter_type_priority'])
					? $this->normalize_data_type_priority_list($col['filter_type_priority'])
					: [],
				'format_date' => !empty($col['format_date']),
				'date_format' => !empty($col['date_format']) ? (string) $col['date_format'] : '',
				'hide_title' => !empty($col['hide_title']),
				'hidden' => !empty($col['hidden']),
				'searchable' => array_key_exists('searchable', $col) ? (bool) $col['searchable'] : true,
				'sort_priority' => $priority,
				'sort_direction' => isset($col['sort_direction']) && $col['sort_direction'] === 'desc' ? 'desc' : 'asc',
				'sort_enabled' => !empty($col['sort_enabled']) || $priority > 0,
				'sortable' => array_key_exists('sortable', $col) ? (bool) $col['sortable'] : true,
			];
		}
		return $records;
	}

	public function filter_editor_column_records_by_slug_map(array $records, array $slug_map): array {
		return empty($slug_map) ? $records : array_intersect_key($records, $slug_map);
	}

	public function apply_editor_column_record_defaults(array $records, array $selected_columns): array {
		foreach ($selected_columns as $slug) {
			if (!isset($records[$slug])) {
				$records[$slug] = $this->default_editor_column_record((string) $slug);
			}
		}
		return $records;
	}

	/**
	 * Convert the form's parallel namespaces into canonical per-column records.
	 */
	public function build_column_records_from_request(array $raw, array $columns): array {
		return $this->build_column_records_from_maps(
			$columns,
			$this->sanitize_column_request_maps($raw, $columns),
			true
		);
	}

	/** Convert transport-specific parallel maps into canonical per-column records. */
	private function build_column_records_from_maps(array $columns, array $maps, bool $sanitize_slugs = false): array {
		$records = [];
		foreach ($columns as $raw_slug) {
			$slug = $sanitize_slugs ? sanitize_text_field((string) $raw_slug) : (string) $raw_slug;
			if ($slug === '') {
				continue;
			}
			$priority = (int) ($maps['sort_priority'][$slug] ?? 0);
			$sort_enabled = array_key_exists($slug, $maps['sort_enabled'])
				? (bool) $maps['sort_enabled'][$slug]
				: $priority > 0;
			if (!$sort_enabled) {
				$priority = 0;
			}
			$filter = (string) ($maps['filter_types'][$slug] ?? 'none');
			$filter_values = isset($maps['filter_values'][$slug]) && is_array($maps['filter_values'][$slug])
				? array_values($maps['filter_values'][$slug])
				: [];
			if ($filter === 'none') {
				$filter_values = [];
			}
			$date_format = (string) ($maps['date_formats'][$slug] ?? '');
			$records[$slug] = [
				'slug' => $slug,
				'filter' => $filter,
				'filter_sort' => (string) ($maps['filter_sorts'][$slug] ?? 'asc'),
				'filter_type_priority' => isset($maps['filter_type_priority'][$slug]) && is_array($maps['filter_type_priority'][$slug])
					? array_values($maps['filter_type_priority'][$slug])
					: [],
				'filter_values' => $filter_values,
				'custom_label' => (string) ($maps['custom_labels'][$slug] ?? ''),
				'filter_label' => array_key_exists($slug, $maps['filter_labels']) ? $maps['filter_labels'][$slug] : null,
				'hide_title' => !empty($maps['hide_titles'][$slug]),
				'hidden' => !empty($maps['hidden_columns'][$slug]),
				'searchable' => array_key_exists($slug, $maps['searchable']) ? (bool) $maps['searchable'][$slug] : true,
				'sort_priority' => $priority,
				'sort_direction' => (string) ($maps['sort_direction'][$slug] ?? 'asc'),
				'sort_enabled' => $sort_enabled,
				'sortable' => array_key_exists($slug, $maps['sortable']) ? (bool) $maps['sortable'][$slug] : true,
				'format_date' => $date_format !== '' || !empty($maps['format_date_flags'][$slug]),
				'date_format' => $date_format,
			];
		}
		return $records;
	}

	private function default_editor_column_record(string $slug): array {
		return [
			'slug' => $slug,
			'label' => '',
			'filter' => 'none',
			'dropdown_multi' => false,
			'dropdown_search' => false,
			'filter_sort' => 'asc',
			'filter_values' => [],
			'custom_label' => '',
			'auto_label' => false,
			'filter_label' => null,
			'filter_type_priority' => [],
			'format_date' => false,
			'date_format' => '',
			'hide_title' => false,
			'hidden' => false,
			'searchable' => true,
			'sort_priority' => 0,
			'sort_direction' => 'asc',
			'sort_enabled' => false,
			'sortable' => true,
		];
	}

	private function sanitize_column_request_maps(array $raw, array $columns): array {
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

		return compact(
			'filter_types',
			'filter_sorts',
			'filter_type_priority',
			'filter_values',
			'custom_labels',
			'filter_labels',
			'searchable',
			'hide_titles',
			'hidden_columns',
			'sort_priority',
			'sort_direction',
			'sort_enabled',
			'sortable',
			'date_formats',
			'format_date_flags'
		);
	}

	/**
	 * Legacy parallel-map view retained for integrations written against the pre-record API.
	 *
	 * All normalization belongs to build_editor_column_records_from_definition(); this method is
	 * now only a projection and can no longer drift from the editor's canonical state.
	 *
	 * @deprecated Use build_editor_column_records_from_definition().
	 */
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

		$raw_by_slug = [];
		foreach ($columns as $column) {
			if (is_array($column) && isset($column['key'])) {
				$source = isset($column['source']) ? sanitize_key((string) $column['source']) : 'core';
				$raw_by_slug[self::build_slug($source !== '' ? $source : 'core', (string) $column['key'])] = $column;
			}
		}
		$records = $this->build_editor_column_records_from_definition($columns);
		foreach ($records as $slug => $record) {
			$raw_column = $raw_by_slug[$slug] ?? [];
			foreach ([
				'selected_filters' => 'filter',
				'selected_filter_sort' => 'filter_sort',
				'selected_searchable' => 'searchable',
				'selected_sortable' => 'sortable',
				'selected_auto_labels' => 'auto_label',
			] as $state_key => $record_key) {
				$state[$state_key][$slug] = $record[$record_key];
			}
			if ($record['filter'] === 'dropdown') {
				$state['selected_dropdown_multi'][$slug] = $record['dropdown_multi'];
				$state['selected_dropdown_search'][$slug] = $record['dropdown_search'];
			}
			foreach ([
				'selected_filter_values' => 'filter_values',
				'selected_custom_labels' => 'label',
				'selected_filter_type_priority' => 'filter_type_priority',
				'selected_date_format' => 'date_format',
				'selected_sort_priority' => 'sort_priority',
			] as $state_key => $record_key) {
				if ($record[$record_key] !== '' && $record[$record_key] !== [] && $record[$record_key] !== 0) {
					$state[$state_key][$slug] = $record[$record_key];
				}
			}
			if (array_key_exists('filter_label', $raw_column)) {
				$state['selected_filter_labels'][$slug] = (string) $raw_column['filter_label'];
			}
			foreach ([
				'selected_format_date' => 'format_date',
				'selected_hide_titles' => 'hide_title',
				'selected_hidden_columns' => 'hidden',
				'selected_sort_enabled' => 'sort_enabled',
			] as $state_key => $record_key) {
				if ($record[$record_key]) {
					$state[$state_key][$slug] = true;
				}
			}
			if (isset($raw_column['sort_direction'])) {
				$state['selected_sort_direction'][$slug] = $record['sort_direction'];
			}
		}

		return $state;
	}

	/** @deprecated Filter canonical records with filter_editor_column_records_by_slug_map(). */
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

	/** @deprecated Apply defaults with apply_editor_column_record_defaults(). */
	public function apply_column_state_defaults(array $state, array $selected_columns): array {
		$defaults = [
			'selected_searchable' => true,
			'selected_sortable' => true,
			'selected_sort_direction' => 'asc',
		];
		foreach ($defaults as $key => $default) {
			if (empty($state[$key])) {
				foreach ($selected_columns as $slug) {
					$state[$key][$slug] = $default;
				}
			}
		}
		return $state;
	}

	/** @deprecated Use build_column_records_from_request(). */
	public function build_column_state_from_request(array $raw, array $columns): array {
		return $this->sanitize_column_request_maps($raw, $columns);
	}

	public function build_column_choices(array $display_columns, array $definition_columns): array {
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
