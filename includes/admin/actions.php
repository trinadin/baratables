<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin_Action_Handler {
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

	public function collect_table_request_data(): array {
		$p = BaraTables_Post_Input::class;

		$name = $p::text('btbl_name');
		if ($name === '') {
			$name = $p::text('post_title');
		}
		$post_types_raw = $p::array_raw('btbl_post_type');
		$source_type = BaraTables_Source_Type::normalize($p::raw('btbl_source_type', BaraTables_Source_Type::WP_QUERY), BaraTables_Source_Type::WP_QUERY);
		$csv_attachment_id = $p::int('btbl_csv_attachment_id');
		$csv_has_header = $p::bool('btbl_csv_has_header');
		$csv_delimiter_raw = $p::text('btbl_csv_delimiter');
		$csv_delimiter = $csv_delimiter_raw !== '' ? substr($csv_delimiter_raw, 0, 1) : ',';
		$columns_raw = $p::array_text('btbl_columns');
		$column_order_raw = $p::text('btbl_column_order');
		$custom_columns_raw = $p::array_raw('btbl_custom_columns');
		$custom_rows_raw = $p::array_raw('btbl_custom_data');
		$custom_rows_count_raw = $p::int('btbl_custom_rows_count');
		$custom_cols_count_raw = $p::int('btbl_custom_columns_count');
		$filter_order_raw = $p::text('btbl_filter_order');
		$custom_meta_raw = $p::text('btbl_custom_meta');
		$filters_raw = $p::array_raw('btbl_filters');
		$dropdown_multi_raw = $p::array_raw('btbl_dropdown_multi');
		$dropdown_search_raw = $p::array_raw('btbl_dropdown_search');
		$filter_sorts_raw = $p::array_raw('btbl_filter_sort');
		$filter_type_priority_raw = $p::array_raw('btbl_filter_type_priority');
		$filter_values_raw = $p::array_raw('btbl_filter_values');
		$filter_strict_raw = $p::array_raw('btbl_filter_strict');
		$custom_labels_raw = $p::array_raw('btbl_custom_labels');
		$filter_labels_raw = $p::array_raw('btbl_filter_labels');
		$searchable_raw = $p::array_raw('btbl_searchable');
		$hide_titles_raw = $p::array_raw('btbl_hide_title');
		$hide_columns_raw = $p::array_raw('btbl_hide_column');
		$sort_priority_raw = $p::array_raw('btbl_sort_priority');
		$sort_direction_raw = $p::array_raw('btbl_sort_direction');
		$sort_enabled_raw = $p::array_raw('btbl_sort_enabled');
		$sortable_raw = $p::array_raw('btbl_sortable');
		$format_date_raw = $p::array_raw('btbl_format_date');
		$date_format_raw = $p::array_raw('btbl_date_format');
		$taxonomy_raw = $p::raw('btbl_taxonomy');
		$taxonomy_terms_raw = $p::array_raw('btbl_tax_terms');
		$custom_query_raw = $p::raw('btbl_custom_query_json');
		$value_overrides_raw = $p::raw('btbl_value_overrides_json');
		$table_options_raw = $p::array_raw('btbl_table_options');
		$access_user_meta_raw = $p::raw('btbl_access_user_meta');
		$access_post_meta_raw = $p::raw('btbl_access_post_meta');
		$access_csv_column_raw = $p::raw('btbl_access_csv_column');
		$access_external_column_raw = $p::raw('btbl_access_external_column');
		$access_logged_out_raw = $p::raw('btbl_access_logged_out');
		$external_host_raw = $p::raw('btbl_external_host');
		$external_name_raw = $p::raw('btbl_external_name');
		$external_user_raw = $p::raw('btbl_external_user');
		$external_pass_raw = $p::raw('btbl_external_pass');
		$external_table_raw = $p::raw('btbl_external_table');
		$external_charset_raw = $p::raw('btbl_external_charset');
		$external_port_raw = $p::raw('btbl_external_port');
		$active_tab = $p::key('btbl_active_tab');

		foreach ($custom_labels_raw as $slug => $label) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$label_value = is_array($label) ? implode(' ', array_map('strval', $label)) : (string) $label;
			$label_value = trim(wp_strip_all_tags($label_value));
			if ($label_value === '') {
				$hide_titles_raw[$clean_slug] = 1;
			}
		}

		$post_types = $this->service->sanitize_post_types($post_types_raw, $source_type);
		$post_type = reset($post_types) ?: 'post';

		$custom_dataset = $this->service->sanitize_custom_data($custom_columns_raw, $custom_rows_raw, $custom_rows_count_raw, $custom_cols_count_raw);
		$custom_columns = $custom_dataset['columns'];
		$custom_rows = $custom_dataset['rows'];
		$custom_slugs = $custom_dataset['slugs'];
		if (BaraTables_Source_Type::is_custom_data($source_type) && !empty($custom_slugs)) {
			$columns_raw = array_values(array_intersect($columns_raw, $custom_slugs));
			if (empty($columns_raw)) {
				$columns_raw = $custom_slugs;
			} else {
				foreach ($custom_slugs as $slug) {
					if (!in_array($slug, $columns_raw, true)) {
						$columns_raw[] = $slug;
					}
				}
			}
		}
		$columns = $this->service->prepare_columns_from_request($columns_raw, $custom_meta_raw, $column_order_raw);
		$column_state = $this->service->build_column_state_from_request([
			'filters' => $filters_raw,
			'dropdown_multi' => $dropdown_multi_raw,
			'dropdown_search' => $dropdown_search_raw,
			'filter_sorts' => $filter_sorts_raw,
			'filter_type_priority' => $filter_type_priority_raw,
			'filter_values' => $filter_values_raw,
			'filter_strict' => $filter_strict_raw,
			'custom_labels' => $custom_labels_raw,
			'filter_labels' => $filter_labels_raw,
			'searchable' => $searchable_raw,
			'hide_titles' => $hide_titles_raw,
			'hidden_columns' => $hide_columns_raw,
			'sort_priority' => $sort_priority_raw,
			'sort_direction' => $sort_direction_raw,
			'sort_enabled' => $sort_enabled_raw,
			'sortable' => $sortable_raw,
			'date_formats' => $date_format_raw,
		], $columns);
		$custom_labels = $column_state['custom_labels'];
		if (BaraTables_Source_Type::is_custom_data($source_type) && !empty($custom_slugs)) {
			foreach ($custom_slugs as $idx => $slug) {
				if (!isset($custom_labels[$slug]) || $custom_labels[$slug] === '') {
					$default_label = $custom_columns[$idx] ?? '';
					if ($default_label !== '') {
						$custom_labels[$slug] = $default_label;
					}
				}
			}
		}
		$column_state['custom_labels'] = $custom_labels;
		$taxonomy_filter = $this->service->sanitize_taxonomy_filter($post_types, $taxonomy_raw, $taxonomy_terms_raw);
		$custom_query = $this->service->sanitize_custom_query_json($custom_query_raw);
		$value_overrides = $this->service->sanitize_value_overrides($value_overrides_raw);
		$table_options = $this->service->sanitize_table_options($table_options_raw);
		$filter_order = $this->service->sanitize_order_list($filter_order_raw);
		$access_control = $this->service->sanitize_access_control([
			'user_meta_key' => $access_user_meta_raw,
			'post_meta_key' => $access_post_meta_raw,
			'csv_column' => $access_csv_column_raw,
			'external_column' => $access_external_column_raw,
			'logged_out' => $access_logged_out_raw,
		]);
		$external_db = $this->service->sanitize_external_db_config([
			'host' => $external_host_raw,
			'name' => $external_name_raw,
			'user' => $external_user_raw,
			'pass' => $external_pass_raw,
			'table' => $external_table_raw,
			'charset' => $external_charset_raw,
			'port' => $external_port_raw,
		]);

		return [
			'name' => $name,
			'post_types' => $post_types,
			'post_type' => $post_type,
			'source_type' => $source_type,
			'csv_attachment_id' => $csv_attachment_id,
			'csv_has_header' => $csv_has_header,
			'csv_delimiter' => $csv_delimiter,
			'columns' => $columns,
			'filter_types' => $column_state['filter_types'],
			'filter_sorts' => $column_state['filter_sorts'],
			'filter_type_priority' => $column_state['filter_type_priority'],
			'filter_values' => $column_state['filter_values'],
			'filter_strict' => $column_state['filter_strict'],
			'custom_labels' => $column_state['custom_labels'],
			'filter_labels' => $column_state['filter_labels'],
			'hide_titles' => $column_state['hide_titles'],
			'hidden_columns' => $column_state['hidden_columns'],
			'searchable' => $column_state['searchable'],
			'sort_priority' => $column_state['sort_priority'],
			'sort_direction' => $column_state['sort_direction'],
			'sort_enabled' => $column_state['sort_enabled'],
			'sortable' => $column_state['sortable'],
			'date_formats' => $column_state['date_formats'],
			'format_date_flags' => $column_state['format_date_flags'],
			'taxonomy_filter' => $taxonomy_filter,
			'custom_query' => $custom_query,
			'custom_query_raw' => $custom_query_raw,
			'value_overrides' => $value_overrides,
			'value_overrides_raw_input' => $value_overrides_raw,
			'table_options' => $table_options,
			'filter_order' => $filter_order,
			'access_control' => $access_control,
			'external_db' => $external_db,
			'active_tab' => $active_tab,
			'custom_data' => [
				'columns' => $custom_columns,
				'rows' => $custom_rows,
				'slugs' => $custom_slugs,
			],
			'searchable_raw' => $searchable_raw,
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

		$defn['columns'] = $this->service->build_columns(
			$request['columns'],
			$request['filter_types'],
			$request['filter_sorts'],
			$request['filter_type_priority'],
			$request['custom_labels'],
			$request['filter_labels'],
			$request['hide_titles'],
			$request['hidden_columns'],
			$request['searchable'],
			$request['sort_priority'],
			$request['sort_direction'],
			$request['sort_enabled'],
			$request['sortable'],
			$request['filter_values'],
			$request['filter_strict'],
			$request['format_date_flags'],
			$request['date_formats']
		);

		if (BaraTables_Source_Type::is_custom_data($request['source_type'])) {
			$defn['custom_data'] = [
				'columns' => $request['custom_data']['columns'],
				'rows' => $request['custom_data']['rows'],
			];
		} else {
			unset($defn['custom_data']);
		}

		if (!empty($request['external_db'])) {
			$external_db = $request['external_db'];
			if (empty($external_db['pass']) && !empty($defn['external_db']['pass'])) {
				$external_db['pass'] = $defn['external_db']['pass'];
			}
			$defn['external_db'] = $external_db;
			$defn['source_type'] = BaraTables_Source_Type::is_external_db($request['source_type'])
				? BaraTables_Source_Type::EXTERNAL_DB
				: $defn['source_type'];
		} else {
			unset($defn['external_db']);
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

		if ($is_update) {
			if (!empty($request['searchable_raw'])) {
				$searchable_clean = [];
				foreach ((array) $request['searchable_raw'] as $slug => $val) {
					if (!is_scalar($slug)) {
						continue;
					}
					$clean_slug = sanitize_text_field((string) $slug);
					if ($clean_slug !== '') {
						$searchable_clean[$clean_slug] = !empty($val) ? 1 : 0;
					}
				}
				$defn['searchable_raw'] = $searchable_clean;
			} else {
				unset($defn['searchable_raw']);
			}
		}

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
