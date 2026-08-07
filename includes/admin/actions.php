<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin_Action_Handler {
	private const INPUT_FIELDS = [
		'name' => ['text', 'btbl_name'],
		'post_title' => ['text', 'post_title'],
		'post_types' => ['array_raw', 'btbl_post_type'],
		'source_type' => ['raw', 'btbl_source_type'],
		'csv_attachment_id' => ['int', 'btbl_csv_attachment_id'],
		'csv_has_header' => ['bool', 'btbl_csv_has_header'],
		'csv_delimiter' => ['text', 'btbl_csv_delimiter'],
		'columns' => ['array_text', 'btbl_columns'],
		'column_order' => ['text', 'btbl_column_order'],
		'custom_columns' => ['array_raw', 'btbl_custom_columns'],
		'custom_rows' => ['array_raw', 'btbl_custom_data'],
		'custom_rows_count' => ['int', 'btbl_custom_rows_count'],
		'custom_cols_count' => ['int', 'btbl_custom_columns_count'],
		'filter_order' => ['text', 'btbl_filter_order'],
		'custom_meta' => ['text', 'btbl_custom_meta'],
		'taxonomy' => ['array_raw', 'btbl_taxonomy'],
		'taxonomy_terms' => ['array_raw', 'btbl_tax_terms'],
		'custom_query_raw' => ['raw', 'btbl_custom_query_json'],
		'value_overrides_raw' => ['raw', 'btbl_value_overrides_json'],
		'table_options' => ['array_raw', 'btbl_table_options'],
	];
	private const COLUMN_INPUT_FIELDS = [
		'filters' => ['array_raw', 'btbl_filters'],
		'dropdown_multi' => ['array_raw', 'btbl_dropdown_multi'],
		'dropdown_search' => ['array_raw', 'btbl_dropdown_search'],
		'filter_sorts' => ['array_raw', 'btbl_filter_sort'],
		'filter_type_priority' => ['array_raw', 'btbl_filter_type_priority'],
		'filter_values' => ['array_raw', 'btbl_filter_values'],
		'custom_labels' => ['array_raw', 'btbl_custom_labels'],
		'filter_labels' => ['array_raw', 'btbl_filter_labels'],
		'searchable' => ['array_raw', 'btbl_searchable'],
		'hide_titles' => ['array_raw', 'btbl_hide_title'],
		'hidden_columns' => ['array_raw', 'btbl_hide_column'],
		'sort_priority' => ['array_raw', 'btbl_sort_priority'],
		'sort_direction' => ['array_raw', 'btbl_sort_direction'],
		'sort_enabled' => ['array_raw', 'btbl_sort_enabled'],
		'sortable' => ['array_raw', 'btbl_sortable'],
		'format_date_flags' => ['array_raw', 'btbl_format_date'],
		'date_formats' => ['array_raw', 'btbl_date_format'],
	];
	private const ACCESS_INPUT_FIELDS = [
		'access_user_meta' => ['raw', 'btbl_access_user_meta'],
		'access_post_meta' => ['raw', 'btbl_access_post_meta'],
		'access_csv_column' => ['raw', 'btbl_access_csv_column'],
		'access_external_column' => ['raw', 'btbl_access_external_column'],
		'access_logged_out' => ['raw', 'btbl_access_logged_out'],
	];
	private const EXTERNAL_INPUT_FIELDS = [
		'external_host' => ['raw', 'btbl_external_host'],
		'external_name' => ['raw', 'btbl_external_name'],
		'external_user' => ['raw', 'btbl_external_user'],
		'external_pass' => ['raw', 'btbl_external_pass'],
		'external_table' => ['raw', 'btbl_external_table'],
		'external_charset' => ['raw', 'btbl_external_charset'],
		'external_port' => ['raw', 'btbl_external_port'],
	];
	private BaraTables_Service $service;

	public function __construct(BaraTables_Service $service) {
		$this->service = $service;
	}

	/**
	 * Sanitize a raw JSON textarea string for safe storage in postmeta.
	 * Preserves JSON syntax and string contents (including angle brackets and
	 * HTML-like values used by value_overrides search/replace rules); only
	 * strips null bytes and ensures valid UTF-8. The parsed JSON used for
	 * actual queries is sanitized separately downstream.
	 */
	public static function sanitize_json_textarea($value): string {
		if (!is_scalar($value)) {
			return '';
		}
		$clean = str_replace("\0", '', (string) $value);
		return (string) wp_check_invalid_utf8($clean, true);
	}

	private function collect_table_input(): array {
		return BaraTables_Post_Input::collect(array_merge(
			self::INPUT_FIELDS,
			self::COLUMN_INPUT_FIELDS,
			self::ACCESS_INPUT_FIELDS,
			self::EXTERNAL_INPUT_FIELDS
		));
	}

	private function build_custom_request(array $input, string $source_type): array {
		$dataset = $this->service->sanitize_custom_data(
			$input['custom_columns'],
			$input['custom_rows'],
			$input['custom_rows_count'],
			$input['custom_cols_count']
		);
		$columns_raw = $input['columns'];
		if (BaraTables_Source_Type::is_custom_data($source_type) && !empty($dataset['slugs'])) {
			$columns_raw = array_values(array_intersect($columns_raw, $dataset['slugs']));
			foreach ($dataset['slugs'] as $slug) {
				if (!in_array($slug, $columns_raw, true)) {
					$columns_raw[] = $slug;
				}
			}
		}
		return ['dataset' => $dataset, 'columns_raw' => $columns_raw];
	}

	private function build_requested_column_records(array $input, array $columns, array $custom_dataset, string $source_type): array {
		$records = $this->service->build_column_records_from_request(
			array_intersect_key($input, self::COLUMN_INPUT_FIELDS),
			$columns
		);
		if (BaraTables_Source_Type::is_custom_data($source_type)) {
			foreach ($custom_dataset['slugs'] as $index => $slug) {
				if (isset($records[$slug]) && $records[$slug]['custom_label'] === '') {
					$records[$slug]['custom_label'] = $custom_dataset['columns'][$index] ?? '';
				}
			}
		}
		return $records;
	}

	public function collect_table_request_data(): array {
		$input = $this->collect_table_input();
		$name = $input['name'] !== '' ? $input['name'] : $input['post_title'];
		$source_type = BaraTables_Source_Type::normalize($input['source_type'], BaraTables_Source_Type::WP_QUERY);
		$post_types = $this->service->sanitize_post_types($input['post_types'], $source_type);
		$custom = $this->build_custom_request($input, $source_type);
		$columns = $this->service->prepare_columns_from_request(
			$custom['columns_raw'],
			$input['custom_meta'],
			$input['column_order']
		);
		$column_records = $this->build_requested_column_records($input, $columns, $custom['dataset'], $source_type);
		$csv_delimiter = $input['csv_delimiter'] !== '' ? substr($input['csv_delimiter'], 0, 1) : ',';

		return [
			'name' => $name,
			'post_types' => $post_types,
			'post_type' => reset($post_types) ?: 'post',
			'source_type' => $source_type,
			'csv_attachment_id' => $input['csv_attachment_id'],
			'csv_has_header' => $input['csv_has_header'],
			'csv_delimiter' => $csv_delimiter,
			'columns' => $columns,
			'column_records' => $column_records,
			'taxonomy_filter' => $this->service->sanitize_taxonomy_filter($post_types, $input['taxonomy'], $input['taxonomy_terms']),
			'custom_query' => $this->service->sanitize_custom_query_json($input['custom_query_raw']),
			'custom_query_raw' => $input['custom_query_raw'],
			'value_overrides' => $this->service->sanitize_value_overrides($input['value_overrides_raw']),
			'value_overrides_raw_input' => $input['value_overrides_raw'],
			'table_options' => $this->service->sanitize_table_options($input['table_options']),
			'filter_order' => $this->service->sanitize_order_list($input['filter_order']),
			'access_control' => $this->service->sanitize_access_control([
				'user_meta_key' => $input['access_user_meta'],
				'post_meta_key' => $input['access_post_meta'],
				'csv_column' => $input['access_csv_column'],
				'external_column' => $input['access_external_column'],
				'logged_out' => $input['access_logged_out'],
			], $source_type),
			'external_db' => $this->service->sanitize_external_db_config([
				'host' => $input['external_host'],
				'name' => $input['external_name'],
				'user' => $input['external_user'],
				'pass' => $input['external_pass'],
				'table' => $input['external_table'],
				'charset' => $input['external_charset'],
				'port' => $input['external_port'],
			]),
			'custom_data' => [
				'columns' => $custom['dataset']['columns'],
				'rows' => $custom['dataset']['rows'],
				'slugs' => $custom['dataset']['slugs'],
			],
		];
	}

	public function apply_request_to_definition(array $request, ?array $definition = null, bool $is_update = false): array {
		$defn = $definition ?? [];

		$defn['name'] = $request['name'] !== '' ? $request['name'] : __('Untitled Table', 'baratables');
		$defn['post_type'] = $request['post_type'];
		$defn['post_types'] = $request['post_types'];
		$defn['source_type'] = $request['source_type'];
		$defn['csv_attachment_id'] = $request['csv_attachment_id'];
		$defn['csv_has_header'] = $request['csv_has_header'];
		$defn['csv_delimiter'] = $request['csv_delimiter'];
		if (empty($defn['status'])) {
			$defn['status'] = 'publish';
		}

		$request_columns = $request['columns'];
		// R1: a brand-new WP Query table saved with no columns gets a default Title column,
		// so the most common first action (pick a source -> Publish) yields a working table.
		// Gated to genuinely new tables (no prior definition) so deselecting every column on
		// an existing table still saves as empty.
		if (
			empty($request_columns)
			&& $request['source_type'] === BaraTables_Source_Type::WP_QUERY
			&& empty($definition)
		) {
			$request_columns = ['core:post_title'];
		}

		$defn['columns'] = $this->service->build_columns_from_records($request_columns, $request['column_records']);

		if ($request['source_type'] === BaraTables_Source_Type::EXTERNAL_DB && !empty($request['external_db'])) {
			$external_db = $request['external_db'];
			if (empty($external_db['pass']) && !empty($defn['external_db']['pass'])) {
				$external_db['pass'] = $defn['external_db']['pass'];
			}
			$defn['external_db'] = $external_db;
		}

		if (!empty($request['access_control'])) {
			$defn['access_control'] = $request['access_control'];
		} else {
			unset($defn['access_control']);
		}

		if ($is_update || $request['custom_query_raw'] !== '') {
			$defn['custom_query_raw'] = self::sanitize_json_textarea($request['custom_query_raw']);
		} else {
			unset($defn['custom_query_raw']);
		}
		if ($request['value_overrides_raw_input'] !== '') {
			$defn['value_overrides_raw'] = self::sanitize_json_textarea($request['value_overrides_raw_input']);
		} else {
			unset($defn['value_overrides_raw']);
		}

		$defn['table_options'] = $request['table_options'];
		$defn['filter_order'] = $request['filter_order'];

		// searchable_raw was dead state: nothing ever read the top-level map (per-column
		// $col['searchable'] is the source of truth). Never write it, and strip any copy
		// carried over from a table saved by an older version.
		unset($defn['searchable_raw']);

		if (!empty($request['taxonomy_filter'])) {
			$defn['taxonomy_filter'] = $request['taxonomy_filter'];
		} else {
			unset($defn['taxonomy_filter']);
		}

		if (!empty($request['custom_query'])) {
			$defn['custom_query'] = $request['custom_query'];
		} else {
			unset($defn['custom_query']);
		}

		if (!empty($request['value_overrides'])) {
			$defn['value_overrides'] = $request['value_overrides'];
		} else {
			unset($defn['value_overrides']);
		}

		unset($defn['columns_by_source'], $defn['filter_order_by_source']);
		if ($request['source_type'] !== BaraTables_Source_Type::WP_QUERY) {
			unset($defn['taxonomy_filter'], $defn['post_types'], $defn['post_type']);
		}
		if ($request['source_type'] !== BaraTables_Source_Type::CUSTOM_QUERY) {
			unset($defn['custom_query'], $defn['custom_query_raw']);
		}
		if ($request['source_type'] !== BaraTables_Source_Type::CUSTOM_DATA) {
			unset($defn['custom_data']);
		} else {
			$defn['custom_data'] = [
				'columns' => $request['custom_data']['columns'],
				'rows' => $request['custom_data']['rows'],
			];
		}
		if ($request['source_type'] !== BaraTables_Source_Type::CSV) {
			unset($defn['csv_attachment_id'], $defn['csv_has_header'], $defn['csv_delimiter']);
		}
		if ($request['source_type'] !== BaraTables_Source_Type::EXTERNAL_DB) {
			unset($defn['external_db']);
		}

		return $defn;
	}

}
