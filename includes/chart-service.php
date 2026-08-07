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
		// Labels only: the dropdown needs id => name, not every table's stored definition.
		$table_choices = $this->table_repo->get_definition_choices();

		$table_definition = null;
		$requested_table_id = $selected_table_id ?: ($chart_definition['table_id'] ?? '');
		if ($requested_table_id !== '') {
			$table_definition = $this->table_repo->find_definition($requested_table_id);
		}
		if (!$table_definition && !empty($table_choices)) {
			// No (or unknown) selection: fall back to the first table, matching the previous
			// title-ordered behaviour -- but hydrate just that one.
			$first_id = (string) array_key_first($table_choices);
			$table_definition = $first_id !== '' ? $this->table_repo->find_definition($first_id) : null;
		}

		$columns = $table_definition['columns'] ?? [];
		$chart_options_raw = isset($chart_definition['chart']) && is_array($chart_definition['chart'])
			? $chart_definition['chart']
			: $this->table_service->get_default_chart_options();
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
		$row_result = $this->table_service->get_row_result($table);
		$table = $this->table_service->definition_with_inferred_columns($table, $row_result);
		$chart_options = $this->table_service->sanitize_chart_options($chart['chart'] ?? [], $table['columns'] ?? []);

		return [
			'chart'         => $chart,
			'table'         => $table,
			'chart_options' => $chart_options,
			'rows'          => $row_result->rows(),
		];
	}

}
