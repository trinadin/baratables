<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Chart definitions and render context. Split out of services.php so that file holds a single
 * class; BaraTables_Chart_Service composes BaraTables_Service for the underlying table data.
 */
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

	public function build_form_context(?array $chart_definition, ?string $selected_table_id = null): array {
		$chart_definition = $chart_definition ?? [];
		$table_definition = null;
		$requested_table_id = $selected_table_id ?: ($chart_definition['table_id'] ?? '');
		if ($requested_table_id !== '') {
			$table_definition = $this->table_repo->find_definition($requested_table_id);
		}
		if (!$table_definition) {
			$table_definition = $this->table_repo->find_first_definition();
		}
		$table_choices = [];
		if ($table_definition) {
			$id = (string) ($table_definition['id'] ?? '');
			if ($id !== '') {
				$table_choices[$id] = (string) ($table_definition['name'] ?? $id);
			}
		}

		$columns = $table_definition['columns'] ?? [];
		$chart_options_raw = isset($chart_definition['chart']) && is_array($chart_definition['chart'])
			? $chart_definition['chart']
			: $this->table_service->get_default_chart_options();
		$chart_options = $this->table_service->sanitize_chart_options($chart_options_raw, $columns);

		// When showing the chart's own table (not a deliberate ?table switch), report any
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
			foreach (BaraTables_Chart_Types::referenced_columns($chart_options_raw) as $slug) {
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
			'column_choices'   => $this->table_service->build_column_slug_label_map($columns),
			'dropped_columns'  => $dropped_columns,
			'active_tab'       => 'btbl-tab-chart',
		];
	}

	public function search_table_choices(string $search, int $page = 1): array {
		return $this->table_repo->search_definition_choices($search, $page);
	}

	public function prepare_chart_definition(array $request, ?array $existing_chart = null): array {
		$errors = [];
		$name = isset($request['name']) ? sanitize_text_field($request['name']) : '';
		$table_id = isset($request['table_id']) ? sanitize_text_field($request['table_id']) : '';
		$chart_raw = isset($request['chart']) && is_array($request['chart']) ? $request['chart'] : [];

		$table_definition = $table_id !== '' ? $this->table_repo->find_definition($table_id) : null;
		if (!$table_definition) {
			$errors[] = __('Selected table not found.', 'baratables');
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
		$has_stored_columns = !empty($table['columns']);
		if ($has_stored_columns && !BaraTables_Chart_Types::is_configured($chart_options)) {
			return [
				'chart' => $chart,
				'table' => $table,
				'chart_options' => $chart_options,
				'rows' => [],
				'row_result' => new BaraTables_Row_Result(),
			];
		}

		$source_table = $has_stored_columns
			? $this->project_table_for_chart($table, $chart_options)
			: $table;
		$row_result = $this->table_service->get_row_result($source_table);
		$source_table = $this->table_service->definition_with_inferred_columns($source_table, $row_result);
		if (!$has_stored_columns) {
			$chart_options = $this->table_service->sanitize_chart_options($chart['chart'] ?? [], $source_table['columns'] ?? []);
		}

		return [
			'chart'         => $chart,
			'table'         => $source_table,
			'chart_options' => $chart_options,
			'rows'          => $row_result->rows(),
			'row_result'    => $row_result,
		];
	}

	/** Keep plotted columns plus row-token dependencies needed by their override rules. */
	private function project_table_for_chart(array $table, array $chart_options): array {
		$wanted = array_fill_keys(BaraTables_Chart_Types::referenced_columns($chart_options), true);
		if (empty($wanted)) {
			return $table;
		}

		$columns = isset($table['columns']) && is_array($table['columns']) ? $table['columns'] : [];
		$token_to_slug = [];
		foreach ($this->table_service->column_slugs_in_order($columns) as $slug) {
			if ($slug === '') {
				continue;
			}
			$token_to_slug[strtolower($slug)] = $slug;
			$separator = strpos($slug, ':');
			if ($separator !== false && substr($slug, $separator + 1) !== '') {
				$token_to_slug[strtolower(substr($slug, $separator + 1))] = $slug;
			}
		}

		foreach ((array) ($table['value_overrides'] ?? []) as $rule) {
			if (!is_array($rule) || !isset($rule['column']) || ($rule['column'] !== '*' && !isset($wanted[$rule['column']]))) {
				continue;
			}
			$replace = isset($rule['replace']) ? (string) $rule['replace'] : '';
			if (!preg_match_all('/{{\\s*(?:row\\.)?([a-z0-9_:-]+)\\s*}}/i', $replace, $matches)) {
				continue;
			}
			foreach ($matches[1] as $token) {
				$key = strtolower((string) $token);
				if (isset($token_to_slug[$key])) {
					$wanted[$token_to_slug[$key]] = true;
				}
			}
		}

		$projected = [];
		foreach ($columns as $column) {
			$slug = is_array($column) ? (string) ($column['slug'] ?? '') : '';
			if ($slug !== '' && isset($wanted[$slug])) {
				$projected[] = $column;
			}
		}
		if (!empty($projected) && count($projected) < count($columns)) {
			$table['columns'] = $projected;
		}
		return $table;
	}

}
