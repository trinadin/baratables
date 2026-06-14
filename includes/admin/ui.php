<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin_Form_Context {
	private BaraTables_Service $service;

	public function __construct(BaraTables_Service $service) {
		$this->service = $service;
	}

	public function build(?array $editing_defn): array {
		$is_edit = !empty($editing_defn);
		$editing_defn = $editing_defn ?? [];
		if (!isset($editing_defn['columns']) || !is_array($editing_defn['columns'])) {
			$editing_defn['columns'] = [];
		}
		$post_types = $this->service->get_supported_post_types();
		$original_post_types = isset($editing_defn['post_types']) && is_array($editing_defn['post_types']) ? $editing_defn['post_types'] : [$editing_defn['post_type'] ?? 'post'];
		$current_pts = $original_post_types;
		$current_pt = $editing_defn && !empty($editing_defn['post_type']) ? $editing_defn['post_type'] : 'post';
		if (isset($_GET['type'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard admin URL parameter, sanitized below.
			$type_raw = sanitize_text_field(wp_unslash($_GET['type'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type_parts = array_filter(array_map('trim', explode(',', (string) $type_raw)));
			$current_pts = [];
			foreach ($type_parts as $part) {
				$clean = sanitize_key($part);
				if ($clean !== '') {
					$current_pts[] = $clean;
				}
			}
			if (empty($current_pts)) {
				$current_pts = ['post'];
			}
			$current_pt = $current_pts[0];
		}
		$current_pts = array_values(array_filter($current_pts));
		if (empty($current_pts)) {
			$current_pts = ['post'];
		}
		$current_pt = $current_pts[0];
		$original_source = BaraTables_Source_Type::normalize($editing_defn['source_type'] ?? BaraTables_Source_Type::WP_QUERY);
		$source_type_raw = isset($_GET['btbl_source']) ? sanitize_text_field(wp_unslash($_GET['btbl_source'])) : $original_source; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin preview URL parameter.
		$source_type = BaraTables_Source_Type::normalize($source_type_raw, $original_source);
		$source_changed = $source_type !== $original_source;
		$custom_query_preview_raw = isset($_GET['btbl_preview_custom_query']) ? BaraTables_Admin_Action_Handler::sanitize_json_textarea(wp_unslash($_GET['btbl_preview_custom_query'])) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin preview URL parameter; JSON textarea sanitizer preserves valid JSON syntax.
		$custom_query_raw_for_fields = $custom_query_preview_raw !== null
			? (string) $custom_query_preview_raw
			: (string) ($editing_defn['custom_query_raw'] ?? '');
		$custom_query_args_for_fields = [];
		if ($custom_query_raw_for_fields !== '') {
			$custom_query_args_for_fields = $this->service->sanitize_custom_query_json($custom_query_raw_for_fields);
		} elseif ($custom_query_preview_raw === null && !empty($editing_defn['custom_query']) && is_array($editing_defn['custom_query'])) {
			$custom_query_args_for_fields = $editing_defn['custom_query'];
		}
		$custom_query_has_input = !empty($custom_query_args_for_fields);
		$custom_query_empty = $source_type === BaraTables_Source_Type::CUSTOM_QUERY && !$custom_query_has_input;
		if ($source_type === BaraTables_Source_Type::CUSTOM_QUERY) {
			if ($custom_query_has_input) {
				$custom_post_types_raw = $custom_query_args_for_fields['post_type'] ?? [];
				if (!is_array($custom_post_types_raw)) {
					$custom_post_types_raw = [$custom_post_types_raw];
				}
				$custom_post_types = $this->service->sanitize_post_types($custom_post_types_raw, $source_type);
				if (!empty($custom_post_types)) {
					$current_pts = $custom_post_types;
					$current_pt = $current_pts[0] ?? 'post';
				} else {
					$current_pts = ['post'];
					$current_pt = 'post';
				}
			} else {
				$current_pts = [];
				$current_pt = 'post';
			}
		}
		if (BaraTables_Source_Type::is_csv($source_type) && !empty($editing_defn['columns']) && is_array($editing_defn['columns'])) {
			BaraTables_Service::normalize_csv_column_sources($editing_defn['columns']);
		}
		$original_csv_attachment_id = isset($editing_defn['csv_attachment_id']) ? (int) $editing_defn['csv_attachment_id'] : 0;
		$original_csv_has_header = !empty($editing_defn['csv_has_header']);
		$original_csv_delimiter_raw = isset($editing_defn['csv_delimiter']) ? (string) $editing_defn['csv_delimiter'] : ',';
		$original_csv_delimiter = $original_csv_delimiter_raw !== '' ? substr($original_csv_delimiter_raw, 0, 1) : ',';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin preview URL parameter.
		$csv_attachment_id = isset($_GET['btbl_preview_csv_id']) ? absint(wp_unslash($_GET['btbl_preview_csv_id'])) : (isset($editing_defn['csv_attachment_id']) ? (int) $editing_defn['csv_attachment_id'] : 0);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin preview URL parameter.
		$csv_has_header = isset($_GET['btbl_preview_csv_header']) ? (bool) absint(wp_unslash($_GET['btbl_preview_csv_header'])) : !empty($editing_defn['csv_has_header']);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin preview URL parameter.
		$csv_delimiter_raw = isset($_GET['btbl_preview_csv_delim']) ? sanitize_text_field(wp_unslash($_GET['btbl_preview_csv_delim'])) : (isset($editing_defn['csv_delimiter']) ? (string) $editing_defn['csv_delimiter'] : ',');
		$csv_delimiter = is_string($csv_delimiter_raw) && $csv_delimiter_raw !== '' ? substr($csv_delimiter_raw, 0, 1) : ',';
		$is_post_request = isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_METHOD is a server-set value.
		$csv_inputs_changed = BaraTables_Source_Type::is_csv($source_type) && (
			$csv_attachment_id !== $original_csv_attachment_id
			|| $csv_has_header !== $original_csv_has_header
			|| $csv_delimiter !== $original_csv_delimiter
		);
		$csv_query_override = !$is_post_request && BaraTables_Source_Type::is_csv($source_type) && (isset($_GET['btbl_preview_csv_id']) || isset($_GET['btbl_preview_csv_header']) || isset($_GET['btbl_preview_csv_delim'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin preview URL parameter.
		$columns_should_reset = !$is_post_request && (
			$source_changed
			|| $custom_query_empty
			|| (BaraTables_Source_Type::is_csv($source_type) && ($csv_inputs_changed || $csv_query_override))
		);
		if ($columns_should_reset) {
			$editing_defn['filter_order'] = [];
			$editing_defn['columns'] = [];
		}
		$inferred = [];
		if (BaraTables_Source_Type::is_csv($source_type)) {
			$csv_defn = $editing_defn;
			$csv_defn['source_type'] = BaraTables_Source_Type::CSV;
			$csv_defn['csv_attachment_id'] = $csv_attachment_id;
			$csv_defn['csv_has_header'] = $csv_has_header;
			$csv_defn['csv_delimiter'] = $csv_delimiter;
			$this->service->get_rows($csv_defn, 1);
			$inferred = $this->service->get_last_inferred_columns();
		} elseif (BaraTables_Source_Type::is_external_db($source_type)) {
			$external_defn = $editing_defn;
			$external_defn['source_type'] = BaraTables_Source_Type::EXTERNAL_DB;
			if (!empty($external_defn['external_db']) && is_array($external_defn['external_db'])) {
				$this->service->get_rows($external_defn, 1);
			}
			$inferred = $this->service->get_last_inferred_columns() ?: [];
		}
		if ($custom_query_empty) {
			$fields = ['core' => [], 'meta' => [], 'tax' => [], 'meta_sources' => [], 'tax_sources' => []];
			$taxonomies = [];
			$should_show_source_hint = false;
		} else {
			$fields = BaraTables_Source_Type::uses_builder_fields($source_type)
				? $this->service->get_available_fields_for_post_types($current_pts)
				: ['core' => [], 'meta' => [], 'tax' => []];
			$taxonomies = $this->service->get_taxonomies_for_post_types($current_pts);
			$should_show_source_hint = count($current_pts) > 1;
		}
		$csv_available_columns = [];
		if (BaraTables_Source_Type::is_csv($source_type)) {
			if ($csv_attachment_id === 0) {
				$csv_available_columns = [];
			} else {
				$csv_available_columns = !empty($inferred) ? $inferred : [];
				if (empty($csv_available_columns) && !empty($editing_defn['columns'])) {
					$csv_available_columns = $editing_defn['columns'];
				}
			}
		} elseif (BaraTables_Source_Type::is_external_db($source_type)) {
			$csv_available_columns = !empty($inferred) ? $inferred : (!empty($editing_defn['columns']) ? $editing_defn['columns'] : []);
		} elseif ($columns_should_reset) {
			$editing_defn['columns'] = [];
		}

		$custom_available_columns = [];
		$custom_columns = [];
		$custom_rows = [];
		$custom_rows_count = 5;
		$custom_cols_count = 3;
		if (BaraTables_Source_Type::is_custom_data($source_type)) {
			$custom_data = isset($editing_defn['custom_data']) && is_array($editing_defn['custom_data']) ? $editing_defn['custom_data'] : [];
			$custom_columns_raw = isset($custom_data['columns']) && is_array($custom_data['columns']) ? array_values($custom_data['columns']) : [];
			$custom_rows_raw = isset($custom_data['rows']) && is_array($custom_data['rows']) ? array_values($custom_data['rows']) : [];
			$requested_cols = count($custom_columns_raw) > 0 ? count($custom_columns_raw) : $custom_cols_count;
			$requested_rows = count($custom_rows_raw) > 0 ? count($custom_rows_raw) : $custom_rows_count;
			$custom_dataset = $this->service->build_custom_dataset($custom_columns_raw, $custom_rows_raw, $requested_rows, $requested_cols);
			$custom_columns = $custom_dataset['columns'];
			$custom_rows = $custom_dataset['rows'];
			$custom_cols_count = $custom_dataset['cols_count'];
			$custom_rows_count = $custom_dataset['rows_count'];
			if (!empty($editing_defn['columns'])) {
				$custom_available_columns = $editing_defn['columns'];
			} else {
				$custom_available_columns = $this->service->build_custom_display_columns($custom_columns);
			}
		}

		$display_columns = BaraTables_Source_Type::uses_column_preview($source_type)
			? $csv_available_columns
			: (BaraTables_Source_Type::is_custom_data($source_type) ? $custom_available_columns : ($editing_defn['columns'] ?? []));
		$selected_columns = $editing_defn ? array_map(static function ($col) {
			$source = isset($col['source']) ? (string) $col['source'] : 'core';
			return BaraTables_Service::build_slug($source, (string) ($col['key'] ?? ''));
		}, $editing_defn['columns']) : [];

		$available_slugs = [];
		if (BaraTables_Source_Type::uses_column_preview($source_type) && !empty($csv_available_columns)) {
			$available_slugs = array_map(function ($col) use ($source_type) {
				$source = isset($col['source']) ? (string) $col['source'] : (BaraTables_Source_Type::is_external_db($source_type) ? 'external' : 'csv');
				return BaraTables_Service::build_slug($source, (string) ($col['key'] ?? ''));
			}, $csv_available_columns);
		} elseif (BaraTables_Source_Type::uses_builder_fields($source_type)) {
			foreach ($fields['core'] as $key => $label) {
				$available_slugs[] = BaraTables_Service::build_slug('core', (string) $key);
			}
			foreach ($fields['meta'] as $meta_key) {
				$available_slugs[] = BaraTables_Service::build_slug('meta', (string) $meta_key);
			}
			if (!empty($fields['tax'])) {
				foreach ($fields['tax'] as $tax_slug => $tax_label) {
					$available_slugs[] = BaraTables_Service::build_slug('tax', (string) $tax_slug);
				}
			}
		} elseif (BaraTables_Source_Type::is_custom_data($source_type) && !empty($custom_available_columns)) {
			foreach ($custom_available_columns as $col) {
				$source = isset($col['source']) && $col['source'] !== '' ? (string) $col['source'] : 'custom';
				$available_slugs[] = BaraTables_Service::build_slug($source, (string) ($col['key'] ?? ''));
			}
		}

		$available_slug_map = !empty($available_slugs) ? array_fill_keys($available_slugs, true) : [];

		if ($columns_should_reset) {
			$selected_columns = [];
		} elseif (!empty($available_slug_map)) {
			$selected_columns = array_values(array_intersect($selected_columns, array_keys($available_slug_map)));
			$columns_for_filter = is_array($editing_defn['columns']) ? $editing_defn['columns'] : [];
			$editing_defn['columns'] = array_values(array_filter($columns_for_filter, static function ($col) use ($available_slug_map) {
				if (!is_array($col) || !isset($col['key'])) {
					return false;
				}
				$source = isset($col['source']) ? sanitize_key($col['source']) : 'core';
				$slug = ($source !== '' ? $source : 'core') . ':' . $col['key'];
				return isset($available_slug_map[$slug]);
			}));
		}
		$selected_taxonomy = [];
		$selected_tax_terms = [];
		$custom_query_pretty = '';
		$value_overrides_pretty = '';
		$custom_query_raw = '';
		$value_overrides_raw = '';
		$table_options = $this->service->get_default_table_options();
		$chart_options = $this->service->get_default_chart_options();
		$access_user_meta = '';
		$access_post_meta = '';
		$access_csv_column = '';
		$access_external_column = '';
		$access_logged_out = 'all';
		$external_host = '';
		$external_name = '';
		$external_user = '';
		$external_pass = '';
		$external_pass_saved = false;
		$external_table = '';
		$external_charset = '';
		$external_port = '';
		$filter_order = [];
		$active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'btbl-tab-general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin preview URL parameter.
		$columns_for_state = $editing_defn && !$columns_should_reset ? ($editing_defn['columns'] ?? []) : [];
		$column_state = $this->service->build_column_state_from_definition($columns_for_state);
		if (!empty($available_slug_map)) {
			$column_state = $this->service->filter_column_state_by_slug_map($column_state, $available_slug_map);
		}
		$selected_filters = $column_state['selected_filters'];
		$missing_meta = [];
		if ($editing_defn) {
			foreach ($editing_defn['columns'] as $col) {
				if ($col['source'] === 'meta' && !in_array($col['key'], $fields['meta'], true)) {
					$missing_meta[] = $col['key'];
				}
			}
			$missing_meta = array_unique($missing_meta);
		}
		$form_action = $is_edit ? 'update' : 'create';

		if ($editing_defn && !empty($editing_defn['taxonomy_filter'])) {
			foreach (BaraTables_Taxonomy_Filters::normalize($editing_defn['taxonomy_filter']) as $filter) {
				$tax_slug = sanitize_key($filter['taxonomy'] ?? '');
				if ($tax_slug === '') {
					continue;
				}
				$selected_taxonomy[] = $tax_slug;
				$selected_tax_terms[$tax_slug] = array_map('intval', (array) ($filter['terms'] ?? []));
			}
			$selected_taxonomy = array_values(array_unique($selected_taxonomy));
		}
		if ($custom_query_preview_raw !== null) {
			$custom_query_raw = (string) $custom_query_preview_raw;
		} elseif ($editing_defn && !empty($editing_defn['custom_query_raw'])) {
			$custom_query_raw = (string) $editing_defn['custom_query_raw'];
		}
		if ($custom_query_raw === '' && !empty($custom_query_args_for_fields)) {
			$custom_query_pretty = wp_json_encode($custom_query_args_for_fields, JSON_PRETTY_PRINT);
		} elseif ($editing_defn && !empty($editing_defn['custom_query']) && is_array($editing_defn['custom_query'])) {
			$custom_query_pretty = wp_json_encode($editing_defn['custom_query'], JSON_PRETTY_PRINT);
		}
		if ($editing_defn && !empty($editing_defn['value_overrides']) && is_array($editing_defn['value_overrides'])) {
			$value_overrides_pretty = wp_json_encode($editing_defn['value_overrides'], JSON_PRETTY_PRINT);
		}
		if ($editing_defn && !empty($editing_defn['value_overrides_raw'])) {
			$value_overrides_raw = (string) $editing_defn['value_overrides_raw'];
		}
		if ($editing_defn) {
			$table_options = $this->service->get_table_options($editing_defn);
			$chart_options = $this->service->get_chart_options($editing_defn);
			$filter_order = isset($editing_defn['filter_order']) && is_array($editing_defn['filter_order'])
				? array_values($editing_defn['filter_order'])
				: [];
			if (!empty($editing_defn['access_control'])) {
				$access_user_meta = $editing_defn['access_control']['user_meta_key'] ?? '';
				$access_post_meta = $editing_defn['access_control']['post_meta_key'] ?? '';
				$access_csv_column = $editing_defn['access_control']['csv_column'] ?? '';
				$access_external_column = $editing_defn['access_control']['external_column'] ?? '';
				$access_logged_out = $editing_defn['access_control']['logged_out'] ?? 'all';
			}
			if (!empty($editing_defn['external_db'])) {
				$external_host = $editing_defn['external_db']['host'] ?? '';
				$external_name = $editing_defn['external_db']['name'] ?? '';
				$external_user = $editing_defn['external_db']['user'] ?? '';
				$external_pass_saved = !empty($editing_defn['external_db']['pass']);
				$external_table = $editing_defn['external_db']['table'] ?? '';
				$external_charset = $editing_defn['external_db']['charset'] ?? '';
				$external_port = isset($editing_defn['external_db']['port']) ? (string) $editing_defn['external_db']['port'] : '';
			}
		}

		if (BaraTables_Source_Type::is_csv($source_type) && !empty($filter_order)) {
			$filter_order = array_map(static function ($slug) {
				return preg_replace('/^core:/', 'csv:', (string) $slug);
			}, $filter_order);
		}
		if (!empty($available_slug_map) && !empty($filter_order)) {
			$filter_order = array_values(array_filter($filter_order, static function ($slug) use ($available_slug_map) {
				return isset($available_slug_map[$slug]);
			}));
		}

		if (empty($filter_order)) {
			$filter_order = array_values(array_filter($selected_columns, static function ($slug) use ($selected_filters) {
				return isset($selected_filters[$slug]) && $selected_filters[$slug] !== 'none';
			}));
		}
		$column_state = $this->service->apply_column_state_defaults($column_state, $selected_columns);
		$selected_filters = $column_state['selected_filters'];
		$selected_dropdown_multi = $column_state['selected_dropdown_multi'];
		$selected_dropdown_search = $column_state['selected_dropdown_search'];
		$selected_filter_sort = $column_state['selected_filter_sort'];
		$selected_filter_values = $column_state['selected_filter_values'];
		$selected_format_date = $column_state['selected_format_date'];
		$selected_custom_labels = $column_state['selected_custom_labels'];
		$selected_filter_labels = $column_state['selected_filter_labels'];
		$selected_filter_type_priority = $column_state['selected_filter_type_priority'];
		$selected_filter_strict = $column_state['selected_filter_strict'];
		$selected_date_format = $column_state['selected_date_format'];
		$selected_hide_titles = $column_state['selected_hide_titles'];
		$selected_searchable = $column_state['selected_searchable'];
		$selected_hidden_columns = $column_state['selected_hidden_columns'];
		$selected_sort_priority = $column_state['selected_sort_priority'];
		$selected_sort_direction = $column_state['selected_sort_direction'];
		$selected_sort_enabled = $column_state['selected_sort_enabled'];
		$selected_sortable = $column_state['selected_sortable'];

		return [
			'definition' => $editing_defn,
			'post_types' => $post_types,
			'fields' => $fields,
			'display_columns' => $display_columns,
			'taxonomies' => $taxonomies,
			'current_pt' => $current_pt,
			'current_pts' => $current_pts,
			'selected_columns' => $selected_columns,
			'selected_filters' => $selected_filters,
			'selected_dropdown_multi' => $selected_dropdown_multi,
			'selected_dropdown_search' => $selected_dropdown_search,
			'selected_filter_sort' => $selected_filter_sort,
			'selected_filter_values' => $selected_filter_values,
			'selected_format_date' => $selected_format_date,
			'selected_date_format' => $selected_date_format,
			'selected_filter_labels' => $selected_filter_labels,
			'selected_filter_type_priority' => $selected_filter_type_priority,
			'selected_filter_strict' => $selected_filter_strict,
			'selected_custom_labels' => $selected_custom_labels,
			'selected_taxonomy' => $selected_taxonomy,
			'selected_tax_terms' => $selected_tax_terms,
			'custom_query_pretty' => $custom_query_pretty,
			'value_overrides_pretty' => $value_overrides_pretty,
			'custom_query_raw' => $custom_query_raw,
			'value_overrides_raw' => $value_overrides_raw,
			'missing_meta' => $missing_meta,
			'form_action' => $form_action,
			'table_options' => $table_options,
			'chart_options' => $chart_options,
			'filter_order' => $filter_order,
			'active_tab' => $active_tab,
			'selected_hide_titles' => $selected_hide_titles,
			'selected_searchable' => $selected_searchable,
			'selected_hidden_columns' => $selected_hidden_columns,
			'selected_sort_priority' => $selected_sort_priority,
			'selected_sort_direction' => $selected_sort_direction,
			'selected_sort_enabled' => $selected_sort_enabled,
			'selected_sortable' => $selected_sortable,
			'source_type' => $source_type,
			'csv_attachment_id' => $csv_attachment_id,
			'csv_has_header' => $csv_has_header,
			'csv_delimiter' => $csv_delimiter,
			'csv_columns' => $csv_available_columns ?: $editing_defn['columns'],
			'access_user_meta' => $access_user_meta,
			'access_post_meta' => $access_post_meta,
			'access_csv_column' => $access_csv_column,
			'access_external_column' => $access_external_column,
			'access_logged_out' => $access_logged_out,
			'external_host' => $external_host,
			'external_name' => $external_name,
			'external_user' => $external_user,
			'external_pass' => $external_pass,
			'external_pass_saved' => $external_pass_saved,
			'external_table' => $external_table,
			'external_charset' => $external_charset,
			'external_port' => $external_port,
			'should_show_source_hint' => $should_show_source_hint,
			'custom_columns' => $custom_columns,
			'custom_rows' => $custom_rows,
			'custom_rows_count' => $custom_rows_count,
			'custom_cols_count' => $custom_cols_count,
		];
	}
}


class BaraTables_Admin_Tab_General {
	public function render(array $context, ?array $editing_defn, string $page_slug): void {
		$source_type = $context['source_type'] ?? 'wp_query';
		$post_types = $context['post_types'] ?? [];
		$taxonomies = $context['taxonomies'] ?? [];
		$current_pts = $context['current_pts'] ?? [];
		$selected_taxonomy = $context['selected_taxonomy'] ?? [];
		$selected_tax_terms = $context['selected_tax_terms'] ?? [];
		$should_show_source_hint = !empty($context['should_show_source_hint']);
		$custom_columns = $context['custom_columns'] ?? [];
		$custom_rows = $context['custom_rows'] ?? [];
		$custom_rows_count = $context['custom_rows_count'] ?? 5;
		$custom_cols_count = $context['custom_cols_count'] ?? 3;
		$custom_query_pretty = $context['custom_query_pretty'] ?? '';
		$custom_query_raw = $context['custom_query_raw'] ?? '';
		$external_host = $context['external_host'] ?? '';
		$external_port = $context['external_port'] ?? '';
		$external_name = $context['external_name'] ?? '';
		$external_user = $context['external_user'] ?? '';
		$external_pass = $context['external_pass'] ?? '';
		$external_pass_saved = !empty($context['external_pass_saved']);
		$external_table = $context['external_table'] ?? '';
		$external_charset = $context['external_charset'] ?? '';
		$csv_attachment_id = $context['csv_attachment_id'] ?? 0;
		$csv_delimiter = $context['csv_delimiter'] ?? ',';
		$csv_has_header = !empty($context['csv_has_header']);
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$panel_class = $active_tab === 'btbl-tab-general' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		$source_hidden_class = static function(string $target) use ($source_type): string {
			return $source_type === $target ? '' : ' is-hidden';
		};
		$tax_filter_hidden = ($source_type !== 'wp_query' || empty($selected_taxonomy)) ? ' is-hidden' : '';
		?>
		<div id="btbl-tab-general" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-general-label">
			<div class="btbl-control-grid">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_source_type"><?php esc_html_e('Data source', 'baratables'); ?></label>
					<p class="description"><?php esc_html_e('Choose where your table data will come from.', 'baratables'); ?></p>
					<select name="btbl_source_type" id="btbl_source_type">
						<option value="wp_query" <?php selected($source_type, 'wp_query'); ?>><?php esc_html_e('WP Query Builder', 'baratables'); ?></option>
						<option value="custom_query" <?php selected($source_type, 'custom_query'); ?>><?php esc_html_e('Custom WP Query', 'baratables'); ?></option>
						<option value="custom_data" <?php selected($source_type, 'custom_data'); ?>><?php esc_html_e('Manual Data', 'baratables'); ?></option>
						<option value="csv" <?php selected($source_type, 'csv'); ?>><?php esc_html_e('CSV File', 'baratables'); ?></option>
						<option value="external_db" <?php selected($source_type, 'external_db'); ?>><?php esc_html_e('External Database', 'baratables'); ?></option>
					</select>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('csv')); ?>" data-btbl-source="csv">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_csv_attachment_id"><?php esc_html_e('CSV file', 'baratables'); ?></label>
					<p class="description"><?php esc_html_e('Select or upload a CSV from the Media Library.', 'baratables'); ?></p>
					<div class="btbl-media-row">
						<input type="text" name="btbl_csv_attachment_id" id="btbl_csv_attachment_id" class="small-text" value="<?php echo esc_attr((int) $csv_attachment_id); ?>" readonly />
						<button type="button" class="button btbl-media-select" data-target="#btbl_csv_attachment_id"><?php esc_html_e('Choose file', 'baratables'); ?></button>
						<button type="button" class="button btbl-media-clear" data-target="#btbl_csv_attachment_id" <?php echo empty($csv_attachment_id) ? 'style="display:none;"' : ''; ?>><?php esc_html_e('Clear', 'baratables'); ?></button>
					</div>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_csv_delimiter"><?php esc_html_e('Delimiter', 'baratables'); ?></label>
					<p class="description"><?php esc_html_e('Single character, usually a comma.', 'baratables'); ?></p>
					<input type="text" name="btbl_csv_delimiter" id="btbl_csv_delimiter" class="small-text" maxlength="1" value="<?php echo esc_attr($csv_delimiter); ?>" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_csv_has_header"><?php esc_html_e('Header row', 'baratables'); ?></label>
					<label class="btbl-flag">
						<input type="hidden" name="btbl_csv_has_header" value="0" />
						<input type="checkbox" name="btbl_csv_has_header" id="btbl_csv_has_header" value="1" <?php checked($csv_has_header); ?> />
						<span class="btbl-flag-text"><?php esc_html_e('First row contains column headers', 'baratables'); ?></span>
					</label>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('custom_data')); ?>" data-btbl-source="custom_data">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_custom_columns_count"><?php esc_html_e('Number of columns', 'baratables'); ?></label>
					<p class="description"><?php esc_html_e('Set how many columns your custom data should have.', 'baratables'); ?></p>
					<input type="number" name="btbl_custom_columns_count" id="btbl_custom_columns_count" class="small-text" min="1" max="50" value="<?php echo esc_attr((int) $custom_cols_count); ?>" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_custom_rows_count"><?php esc_html_e('Number of rows', 'baratables'); ?></label>
					<p class="description"><?php esc_html_e('Enter how many rows of data you want to manage.', 'baratables'); ?></p>
					<input type="number" name="btbl_custom_rows_count" id="btbl_custom_rows_count" class="small-text" min="1" max="500" value="<?php echo esc_attr((int) $custom_rows_count); ?>" />
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('custom_data')); ?>" data-btbl-source="custom_data">
				<div class="btbl-control btbl-custom-grid-control">
					<div class="btbl-control-header">
						<div class="btbl-header-stack">
							<label class="btbl-small-heading" for="btbl_custom_grid"><?php esc_html_e('Custom data', 'baratables'); ?></label>
							<p class="description"><?php esc_html_e('Adjust column/row counts and click Update grid to resize before saving.', 'baratables'); ?></p>
						</div>
						<button type="button" class="button" id="btbl_custom_grid_refresh"><?php esc_html_e('Update grid size', 'baratables'); ?></button>
					</div>
					<?php $allowed_inline = BaraTables_Service::allowed_inline_html(); ?>
					<div
						id="btbl_custom_grid"
						class="btbl-custom-grid"
						data-cols="<?php echo esc_attr((int) $custom_cols_count); ?>"
						data-rows="<?php echo esc_attr((int) $custom_rows_count); ?>"
						<?php // translators: %d is the row number. ?>
						data-row-label="<?php echo esc_attr(__('Row %d', 'baratables')); ?>"
						<?php // translators: %d is the column number. ?>
						data-column-label="<?php echo esc_attr(__('Column %d', 'baratables')); ?>"
						data-heading-label="<?php echo esc_attr(__('Column', 'baratables')); ?>"
					>
						<table class="widefat fixed striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e('Column', 'baratables'); ?></th>
									<?php for ($c = 0; $c < $custom_cols_count; $c++) : ?>
										<?php // translators: %d is the column number. ?>
										<?php $col_label = $custom_columns[$c] ?? sprintf(__('Column %d', 'baratables'), $c + 1); ?>
										<th scope="col"><?php echo wp_kses($col_label, $allowed_inline); ?></th>
									<?php endfor; ?>
								</tr>
							</thead>
							<tbody>
								<?php for ($r = 0; $r < $custom_rows_count; $r++) : ?>
									<?php $row_values = $custom_rows[$r] ?? array_fill(0, $custom_cols_count, ''); ?>
									<tr>
										<?php // translators: %d is the row number. ?>
										<th scope="row"><?php echo esc_html(sprintf(__('Row %d', 'baratables'), $r + 1)); ?></th>
										<?php for ($c = 0; $c < $custom_cols_count; $c++) : ?>
											<td>
												<input type="text" name="btbl_custom_data[<?php echo esc_attr($r); ?>][<?php echo esc_attr($c); ?>]" value="<?php echo esc_attr($row_values[$c] ?? ''); ?>" />
											</td>
										<?php endfor; ?>
									</tr>
								<?php endfor; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('wp_query')); ?>" data-btbl-source="wp_query">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_post_type"><?php esc_html_e('Post type', 'baratables'); ?></label>
					<p class="description"><?php esc_html_e('Choose one or more post types to pull rows from.', 'baratables'); ?></p>
					<div class="btbl-chip-picker" role="group" aria-label="<?php echo esc_attr__('Post types', 'baratables'); ?>" data-btbl-target="#btbl_post_type">
						<?php foreach ($post_types as $pt => $label) : ?>
							<?php $is_selected = in_array($pt, $current_pts, true); ?>
							<button type="button" class="btbl-chip<?php echo $is_selected ? ' is-selected' : ''; ?>" data-value="<?php echo esc_attr($pt); ?>" aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">
								<?php echo esc_html($label); ?>
							</button>
						<?php endforeach; ?>
					</div>
					<select name="btbl_post_type[]" id="btbl_post_type" class="btbl-chip-source" multiple data-edit-id="<?php echo esc_attr($editing_defn['id'] ?? ''); ?>" data-page="<?php echo esc_attr($page_slug); ?>">
						<?php foreach ($post_types as $pt => $label) : ?>
							<option value="<?php echo esc_attr($pt); ?>" <?php selected(in_array($pt, $current_pts, true)); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="btbl-control btbl-taxonomy-select">
					<label class="btbl-small-heading" for="btbl_taxonomy"><?php esc_html_e('Taxonomy filter', 'baratables'); ?></label>
					<p class="description"><?php esc_html_e('Pick the taxonomies you want to filter by.', 'baratables'); ?></p>
					<div class="btbl-chip-picker" role="group" aria-label="<?php echo esc_attr__('Taxonomies', 'baratables'); ?>" data-btbl-target="#btbl_taxonomy">
						<?php foreach ($taxonomies as $tax) : ?>
							<?php
							$source_hint = '';
							if ($should_show_source_hint && !empty($tax['sources'])) {
								$source_hint = ' (' . esc_html(implode(', ', (array) $tax['sources'])) . ')';
							}
							$tax_selected = in_array($tax['slug'], (array) $selected_taxonomy, true);
							$has_terms = !empty($tax['terms']);
							$chip_classes = 'btbl-chip' . ($tax_selected ? ' is-selected' : '') . ($has_terms ? '' : ' is-disabled');
							$chip_disabled = $has_terms ? 'false' : 'true';
							?>
							<button type="button" class="<?php echo esc_attr($chip_classes); ?>" data-value="<?php echo esc_attr($tax['slug']); ?>" aria-pressed="<?php echo $tax_selected ? 'true' : 'false'; ?>" aria-disabled="<?php echo esc_attr($chip_disabled); ?>">
								<?php echo esc_html($tax['label']) . esc_html($source_hint); ?>
							</button>
						<?php endforeach; ?>
					</div>
					<select name="btbl_taxonomy[]" id="btbl_taxonomy" class="btbl-chip-source" multiple>
						<?php foreach ($taxonomies as $tax) : ?>
							<?php
							$source_hint = '';
							if ($should_show_source_hint && !empty($tax['sources'])) {
								$source_hint = ' (' . esc_html(implode(', ', (array) $tax['sources'])) . ')';
							}
							?>
							<option value="<?php echo esc_attr($tax['slug']); ?>" <?php selected(in_array($tax['slug'], (array) $selected_taxonomy, true)); ?>>
								<?php echo esc_html($tax['label']) . esc_html($source_hint); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="btbl-control btbl-taxonomy-filter<?php echo esc_attr($tax_filter_hidden); ?>" data-btbl-source="wp_query">
				<div class="btbl-taxonomy-term-picker">
					<?php if (empty($taxonomies)) : ?>
						<p class="description"><?php esc_html_e('No taxonomies available for this post type.', 'baratables'); ?></p>
					<?php else : ?>
							<?php foreach ($taxonomies as $tax) : ?>
								<?php
								$tax_selected = in_array($tax['slug'], (array) $selected_taxonomy, true);
								$tax_group_classes = 'btbl-tax-terms-group' . ($tax_selected ? '' : ' is-hidden');
								?>
								<div class="<?php echo esc_attr($tax_group_classes); ?>" data-taxonomy="<?php echo esc_attr($tax['slug']); ?>">
								<?php
								$heading_hint = '';
								if ($should_show_source_hint && !empty($tax['sources'])) {
									$heading_hint = ' (' . esc_html(implode(', ', (array) $tax['sources'])) . ')';
								}
								?>
								<strong class="btbl-small-heading"><?php echo esc_html($tax['label']) . esc_html($heading_hint); ?></strong>
								<?php if (empty($tax['terms'])) : ?>
									<p class="description btbl-tax-terms-empty"><?php esc_html_e('No terms found for this taxonomy yet.', 'baratables'); ?></p>
								<?php else : ?>
									<?php
									$selected_terms_for_tax = $selected_tax_terms[$tax['slug']] ?? [];
									$selected_count = count($selected_terms_for_tax);
									$selected_label = $selected_count > 0
										/* translators: %d is the number of selected taxonomy terms. */
										? sprintf(_n('%d term selected', '%d terms selected', $selected_count, 'baratables'), $selected_count)
										: __('No terms selected', 'baratables');
									?>
									<div class="btbl-tax-terms-toolbar">
										<label class="screen-reader-text" for="btbl_tax_search_<?php echo esc_attr($tax['slug']); ?>"><?php esc_html_e('Search terms', 'baratables'); ?></label>
										<input
											type="search"
											id="btbl_tax_search_<?php echo esc_attr($tax['slug']); ?>"
											class="btbl-term-search"
											placeholder="<?php echo esc_attr__('Search terms', 'baratables'); ?>"
											autocomplete="off"
										/>
										<div class="btbl-term-actions">
											<button type="button" class="button-link btbl-term-action" data-action="select-all"><?php esc_html_e('Select all', 'baratables'); ?></button>
											<button type="button" class="button-link btbl-term-action" data-action="clear"><?php esc_html_e('Clear', 'baratables'); ?></button>
										</div>
									</div>
									<div class="btbl-tax-terms-meta">
										<span
											class="btbl-term-count"
											data-empty="<?php echo esc_attr__('No terms selected', 'baratables'); ?>"
											<?php // translators: %d is the number of selected taxonomy terms. ?>
											data-singular="<?php echo esc_attr__('%d term selected', 'baratables'); ?>"
											<?php // translators: %d is the number of selected taxonomy terms. ?>
											data-plural="<?php echo esc_attr__('%d terms selected', 'baratables'); ?>"
										>
											<?php echo esc_html($selected_label); ?>
										</span>
									</div>
									<div class="btbl-term-grid">
										<?php foreach ($tax['terms'] as $term) : ?>
											<?php $is_term_selected = in_array((int) $term['id'], $selected_terms_for_tax, true); ?>
											<label class="btbl-term-chip<?php echo $is_term_selected ? ' is-selected' : ''; ?>">
												<input
													type="checkbox"
													name="btbl_tax_terms[<?php echo esc_attr($tax['slug']); ?>][]"
													value="<?php echo esc_attr($term['id']); ?>"
													<?php checked($is_term_selected); ?>
												/>
												<span><?php echo esc_html($term['name']); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<p class="description btbl-term-empty is-hidden"><?php esc_html_e('No terms match your search.', 'baratables'); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('custom_query')); ?>" data-btbl-source="custom_query">
				<div class="btbl-control">
					<div class="btbl-control-header">
						<div class="btbl-header-stack">
							<label class="btbl-small-heading" for="btbl_custom_query_json"><?php esc_html_e('Custom WP_Query args (JSON)', 'baratables'); ?></label>
							<p class="description"><?php esc_html_e('Define WP_Query args in JSON. Only public post types and published posts are queried, and result size is capped.', 'baratables'); ?></p>
						</div>
						<button type="button" class="button" id="btbl_custom_query_refresh"><?php esc_html_e('Load columns', 'baratables'); ?></button>
					</div>
					<textarea name="btbl_custom_query_json" id="btbl_custom_query_json" class="large-text code" rows="6" placeholder='{"post_type":["post","product"],"posts_per_page":50,"meta_key":"price","meta_query":[{"key":"price","value":10,"compare":">="}],"orderby":{"meta_value_num":"DESC"},"tax_query":[{"taxonomy":"category","field":"slug","terms":["news","events"],"operator":"IN"}]}' spellcheck="false"><?php echo esc_textarea($custom_query_raw !== '' ? $custom_query_raw : $custom_query_pretty); ?></textarea>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('external_db')); ?>" data-btbl-source="external_db">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_host"><?php esc_html_e('DB host', 'baratables'); ?></label>
					<input type="text" name="btbl_external_host" id="btbl_external_host" class="regular-text" value="<?php echo esc_attr($external_host); ?>" placeholder="127.0.0.1" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_port"><?php esc_html_e('Port (optional)', 'baratables'); ?></label>
					<input type="number" name="btbl_external_port" id="btbl_external_port" class="small-text" value="<?php echo esc_attr($external_port); ?>" min="0" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_name"><?php esc_html_e('Database name', 'baratables'); ?></label>
					<input type="text" name="btbl_external_name" id="btbl_external_name" class="regular-text" value="<?php echo esc_attr($external_name); ?>" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_user"><?php esc_html_e('Username', 'baratables'); ?></label>
					<input type="text" name="btbl_external_user" id="btbl_external_user" class="regular-text" value="<?php echo esc_attr($external_user); ?>" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_pass"><?php esc_html_e('Password', 'baratables'); ?></label>
					<input type="password" name="btbl_external_pass" id="btbl_external_pass" class="regular-text" value="<?php echo esc_attr($external_pass); ?>" />
				<?php if ($external_pass_saved) : ?>
					<p class="description"><?php esc_html_e('A password is saved. Leave this blank to keep the existing password.', 'baratables'); ?></p>
				<?php endif; ?>
			</div>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_external_table"><?php esc_html_e('Table / View name', 'baratables'); ?></label>
				<p class="description"><?php esc_html_e('BaraTables reads this table or view with a prepared query and caps the result size automatically.', 'baratables'); ?></p>
				<input type="text" name="btbl_external_table" id="btbl_external_table" class="regular-text" value="<?php echo esc_attr($external_table); ?>" />
			</div>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_external_charset"><?php esc_html_e('Charset (optional)', 'baratables'); ?></label>
				<input type="text" name="btbl_external_charset" id="btbl_external_charset" class="regular-text" value="<?php echo esc_attr($external_charset); ?>" placeholder="utf8mb4" />
			</div>
			</div>
		</div>
		<?php
	}
}


class BaraTables_Admin_Tab_Columns {
	public function render(array $context, ?array $editing_defn): void {
		$source_type = $context['source_type'] ?? 'wp_query';
		$fields = $context['fields'] ?? [];
		$display_columns = $context['display_columns'] ?? [];
		$taxonomies = $context['taxonomies'] ?? [];
		$should_show_source_hint = !empty($context['should_show_source_hint']);
		$tax_sources = $fields['tax_sources'] ?? [];
		$meta_sources = $fields['meta_sources'] ?? [];
		$missing_meta = $context['missing_meta'] ?? [];
		$selected_columns = $context['selected_columns'] ?? [];
		$selected_filters = $context['selected_filters'] ?? [];
		$selected_dropdown_multi = $context['selected_dropdown_multi'] ?? [];
		$selected_dropdown_search = $context['selected_dropdown_search'] ?? [];
		$selected_filter_sort = $context['selected_filter_sort'] ?? [];
		$selected_filter_values = $context['selected_filter_values'] ?? [];
		$selected_format_date = $context['selected_format_date'] ?? [];
		$selected_filter_labels = $context['selected_filter_labels'] ?? [];
		$selected_filter_type_priority = $context['selected_filter_type_priority'] ?? [];
		$selected_filter_strict = $context['selected_filter_strict'] ?? [];
		$selected_custom_labels = $context['selected_custom_labels'] ?? [];
		$selected_hide_titles = $context['selected_hide_titles'] ?? [];
		$selected_searchable = $context['selected_searchable'] ?? [];
		$selected_hidden_columns = $context['selected_hidden_columns'] ?? [];
		$selected_sort_priority = $context['selected_sort_priority'] ?? [];
		$selected_sort_direction = $context['selected_sort_direction'] ?? [];
		$selected_sort_enabled = $context['selected_sort_enabled'] ?? [];
		$selected_sortable = $context['selected_sortable'] ?? [];
		$selected_date_format = $context['selected_date_format'] ?? [];
		if (!empty($selected_sort_priority)) {
			$selected_sort_priority = array_filter(
				$selected_sort_priority,
				static function ($priority): bool {
					return (int) $priority > 0;
				}
			);
		}
		$filter_order = $context['filter_order'] ?? [];
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$column_option_state = [
			'selected_filters' => $selected_filters,
			'selected_dropdown_multi' => $selected_dropdown_multi,
			'selected_dropdown_search' => $selected_dropdown_search,
			'selected_filter_sort' => $selected_filter_sort,
			'selected_filter_values' => $selected_filter_values,
			'selected_custom_labels' => $selected_custom_labels,
			'selected_filter_labels' => $selected_filter_labels,
			'selected_filter_type_priority' => $selected_filter_type_priority,
			'selected_filter_strict' => $selected_filter_strict,
			'selected_hide_titles' => $selected_hide_titles,
			'selected_searchable' => $selected_searchable,
			'selected_hidden_columns' => $selected_hidden_columns,
			'selected_sort_priority' => $selected_sort_priority,
			'selected_sort_direction' => $selected_sort_direction,
			'selected_sort_enabled' => $selected_sort_enabled,
			'selected_sortable' => $selected_sortable,
			'selected_format_date' => $selected_format_date,
			'selected_date_format' => $selected_date_format,
		];
		$panel_class = $active_tab === 'btbl-tab-columns' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		?>
		<div id="btbl-tab-columns" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-columns-label">
			<div class="btbl-options-row btbl-options-inline btbl-align-right">
				<label class="btbl-inline">
					<input type="checkbox" id="btbl_select_all_columns" />
					<?php esc_html_e('Select / Deselect All Columns', 'baratables'); ?>
				</label>
			</div>
			<fieldset class="btbl-fieldset">
				<div class="btbl-columns">
						<?php if ($source_type === 'csv') : ?>
							<?php
							$this->render_simple_column_group(
								$display_columns,
								__('CSV columns', 'baratables'),
								__('No columns detected yet. Save after choosing a CSV to load its headers.', 'baratables'),
								'core:',
								$column_option_state,
								$selected_columns
							);
							?>
						<?php elseif ($source_type === 'custom_data') : ?>
							<?php
							$this->render_simple_column_group(
								$display_columns,
								__('Custom columns', 'baratables'),
								__('Set your column and row counts in the Data Source tab to manage these columns.', 'baratables'),
								'custom:',
								$column_option_state,
								$selected_columns
							);
							?>
						<?php elseif ($source_type === 'external_db') : ?>
							<?php
							$this->render_simple_column_group(
								$display_columns,
								__('External columns', 'baratables'),
								__('No columns detected yet. Save after entering connection details to load a preview.', 'baratables'),
								'external:',
								$column_option_state,
								$selected_columns
							);
							?>
						<?php else : ?>
						<div>
								<strong class="btbl-small-heading"><?php esc_html_e('Core fields', 'baratables'); ?></strong>
								<?php foreach ($fields['core'] as $key => $label) : ?>
									<?php
									$slug = 'core:' . $key;
									$state = $column_option_state;
									$state['checked'] = in_array($slug, $selected_columns, true);
									$this->render_column_option($slug, $label, $label, $state);
									?>
								<?php endforeach; ?>
							</div>
						<?php if (!empty($fields['tax'])) : ?>
							<div>
								<strong class="btbl-small-heading"><?php esc_html_e('Taxonomies', 'baratables'); ?></strong>
								<?php foreach ($fields['tax'] as $tax_slug => $tax_label) : ?>
									<?php
									$slug = 'tax:' . $tax_slug;
									$tax_hint = '';
										if ($should_show_source_hint && !empty($tax_sources[$tax_slug])) {
											$tax_hint = ' (' . esc_html(implode(', ', (array) $tax_sources[$tax_slug])) . ')';
										}
										$tax_display = $tax_label . $tax_hint;
										$state = $column_option_state;
										$state['checked'] = in_array($slug, $selected_columns, true);
										$this->render_column_option($slug, $tax_display, $tax_label, $state);
										?>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						<div>
							<strong class="btbl-small-heading"><?php esc_html_e('Custom meta', 'baratables'); ?></strong>
							<?php if (empty($fields['meta'])) : ?>
								<p class="description"><?php esc_html_e('No meta keys detected for this post type yet.', 'baratables'); ?></p>
							<?php else : ?>
								<?php foreach ($fields['meta'] as $meta_key) : ?>
									<?php
									$slug = 'meta:' . $meta_key;
									$label_display = $this->format_meta_label($meta_key);
									$meta_hint = '';
										if ($should_show_source_hint && !empty($meta_sources[$meta_key])) {
											$meta_hint = ' (' . esc_html(implode(', ', (array) $meta_sources[$meta_key])) . ')';
										}
										$label_with_hint = $label_display . $meta_hint;
										$state = $column_option_state;
										$state['checked'] = in_array($slug, $selected_columns, true);
										$this->render_column_option($slug, $label_with_hint, $label_display, $state);
										?>
									<?php endforeach; ?>
								<?php endif; ?>
								<?php if (!empty($missing_meta)) : ?>
								<p class="description"><?php esc_html_e('Meta keys currently selected that are not detected for this post type:', 'baratables'); ?></p>
								<?php foreach ($missing_meta as $meta_key) : ?>
									<?php
									$slug = 'meta:' . $meta_key;
									$label_display = $this->format_meta_label($meta_key);
									$meta_hint = '';
										if ($should_show_source_hint && !empty($meta_sources[$meta_key])) {
											$meta_hint = ' (' . esc_html(implode(', ', (array) $meta_sources[$meta_key])) . ')';
										}
										$label_with_hint = $label_display . $meta_hint;
										$state = $column_option_state;
										$state['checked'] = true;
										$this->render_column_option($slug, $label_with_hint, $label_display, $state, false);
										?>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						<?php endif; ?>
				</div>
			</fieldset>
			<div class="btbl-selected-order">
				<strong class="btbl-small-heading"><?php esc_html_e('Selected column order', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('Drag to change the display order of selected columns.', 'baratables'); ?></p>
				<ul id="btbl-column-order-list" class="btbl-sortable-list" aria-label="<?php esc_attr_e('Selected columns order', 'baratables'); ?>"></ul>
				<input type="hidden" name="btbl_column_order" id="btbl_column_order" value="<?php echo esc_attr(implode(',', $selected_columns)); ?>" />
				<hr class="btbl-order-separator" />
				<strong class="btbl-small-heading"><?php esc_html_e('Selected filter order', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('Drag to change the display order of selected filter controls.', 'baratables'); ?></p>
				<ul id="btbl-filter-order-list" class="btbl-sortable-list" aria-label="<?php esc_attr_e('Selected filters order', 'baratables'); ?>"></ul>
				<input type="hidden" name="btbl_filter_order" id="btbl_filter_order" value="<?php echo esc_attr(implode(',', $filter_order)); ?>" />
			</div>
			</div>
			<?php
		}

	private function render_simple_column_group(array $columns, string $heading, string $empty_message, string $default_prefix, array $base_state, array $selected_columns): void {
		?>
		<div>
			<strong class="btbl-small-heading"><?php echo esc_html($heading); ?></strong>
			<?php if (empty($columns)) : ?>
				<p class="description"><?php echo esc_html($empty_message); ?></p>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<?php foreach ($columns as $col) : ?>
				<?php
				$slug_prefix = isset($col['source']) && $col['source'] !== 'core' ? sanitize_key($col['source']) . ':' : $default_prefix;
				$slug = $slug_prefix . $col['key'];
				$label_display = $col['label'] ?? $col['key'];
				$state = $base_state;
				$state['checked'] = in_array($slug, $selected_columns, true);
				$this->render_column_option($slug, $label_display, $label_display, $state);
				?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function format_meta_label(string $meta_key): string {
		$label = ucwords(str_replace(['_', '-'], ' ', $meta_key));
		return $label !== '' ? $label : $meta_key;
	}

	private function render_column_option(string $slug, string $display_label, string $default_label, array $state, bool $allow_sort = true): void {
		$selected_filters = $state['selected_filters'] ?? [];
		$selected_dropdown_multi = $state['selected_dropdown_multi'] ?? [];
		$selected_dropdown_search = $state['selected_dropdown_search'] ?? [];
		$selected_filter_sort = $state['selected_filter_sort'] ?? [];
		$selected_custom_labels = $state['selected_custom_labels'] ?? [];
		$selected_filter_labels = $state['selected_filter_labels'] ?? [];
		$selected_filter_type_priority = $state['selected_filter_type_priority'] ?? [];
		$selected_filter_strict = $state['selected_filter_strict'] ?? [];
		$selected_hide_titles = $state['selected_hide_titles'] ?? [];
		$selected_searchable = $state['selected_searchable'] ?? [];
		$selected_hidden_columns = $state['selected_hidden_columns'] ?? [];
		$selected_sort_priority = $state['selected_sort_priority'] ?? [];
		$selected_sort_direction = $state['selected_sort_direction'] ?? [];
		$selected_sort_enabled = $state['selected_sort_enabled'] ?? [];
		$selected_sortable = $state['selected_sortable'] ?? [];
		$selected_filter_values = $state['selected_filter_values'] ?? [];
		$selected_date_format = $state['selected_date_format'] ?? [];
		$is_checked = !empty($state['checked']);

		$custom_label_value = $selected_custom_labels[$slug] ?? $default_label;
		$filter_label_value = array_key_exists($slug, $selected_filter_labels) ? $selected_filter_labels[$slug] : $default_label;
		$hide_title_checked = !empty($selected_hide_titles[$slug]);
		if ($hide_title_checked) {
			$custom_label_value = '';
		}
		$hidden_checked = !empty($selected_hidden_columns[$slug]);
		$searchable_checked = array_key_exists($slug, $selected_searchable) ? !empty($selected_searchable[$slug]) : true;
		$current_filter = $selected_filters[$slug] ?? 'none';
		$sort_enabled = !empty($selected_sort_enabled[$slug]);
		$sort_priority_val = $selected_sort_priority[$slug] ?? '';
		if ($sort_priority_val !== '' && (int) $sort_priority_val < 1) {
			$sort_priority_val = '';
		}
		if ($sort_priority_val === '' && $sort_enabled) {
			$sort_priority_val = '1';
		}
		$sort_direction_val = $selected_sort_direction[$slug] ?? 'asc';
		$filter_sort_val = $selected_filter_sort[$slug] ?? '';
		$filter_type_priority_val = $selected_filter_type_priority[$slug] ?? '';
		$filter_strict_checked = !empty($selected_filter_strict[$slug]);
		if (is_array($filter_type_priority_val)) {
			$lines = [];
			foreach ($filter_type_priority_val as $priority_item) {
				if (!is_array($priority_item)) {
					continue;
				}
				$type = isset($priority_item['type']) ? (string) $priority_item['type'] : '';
				if ($type === '') {
					continue;
				}
				$direction = isset($priority_item['direction']) ? (string) $priority_item['direction'] : 'asc';
				$lines[] = $type . ' => ' . $direction;
			}
			$filter_type_priority_val = implode("\n", $lines);
		}
		if ($filter_sort_val === 'none') {
			$filter_sort_val = 'custom';
		}
		$dropdown_multi_checked = !empty($selected_dropdown_multi[$slug]);
		$dropdown_search_checked = !empty($selected_dropdown_search[$slug]);
		$sortable_checked = array_key_exists($slug, $selected_sortable) ? !empty($selected_sortable[$slug]) : true;
		$date_format_val = $selected_date_format[$slug] ?? '';
		$filter_values_text = '';
		if (isset($selected_filter_values[$slug]) && is_array($selected_filter_values[$slug])) {
			$lines = [];
			foreach ($selected_filter_values[$slug] as $item) {
				if (is_array($item)) {
					$label_val = isset($item['label']) ? (string) $item['label'] : '';
					$value_val = isset($item['value']) ? (string) $item['value'] : '';
					$search_terms = isset($item['search_terms']) && is_array($item['search_terms'])
						? array_values(array_map('strval', $item['search_terms']))
						: [];
					if (empty($search_terms)) {
						if ($value_val !== '') {
							$search_terms = [$value_val];
						} elseif ($label_val !== '') {
							$search_terms = [$label_val];
						}
					}
					if ($label_val === '') {
						$label_val = $value_val !== '' ? $value_val : (string) ($search_terms[0] ?? '');
					}
					if ($label_val === '') {
						continue;
					}
					$search_part = implode(', ', array_map('strval', $search_terms));
					$has_blank = in_array('', $search_terms, true);
					$should_show_mapping = $has_blank || count($search_terms) > 1 || ($search_part !== '' && $search_part !== $label_val);
					$lines[] = $should_show_mapping ? ($label_val . ' => ' . $search_part) : $label_val;
				} else {
					$item_str = trim((string) $item);
					if ($item_str !== '') {
						$lines[] = $item_str;
					}
				}
			}
			$filter_values_text = implode("\n", $lines);
		}
		$priority_has_value = $filter_type_priority_val !== '' && $filter_type_priority_val !== '[]' && $filter_type_priority_val !== '{}';
		if ($filter_sort_val === '') {
			$filter_sort_val = ($filter_values_text !== '' || $priority_has_value) ? 'custom' : 'asc';
		}
		if (!in_array($filter_sort_val, ['asc', 'desc', 'custom'], true)) {
			$filter_sort_val = 'asc';
		}
		$slug_lower = strtolower($slug);
		$is_date_candidate = false;
		if (strpos($slug_lower, 'core:') === 0) {
			$core_key = substr($slug_lower, 5);
			$is_date_candidate = in_array($core_key, ['post_date', 'post_modified'], true);
		}

		$should_open_options = false;
		$slug_attr = $slug;
		$allowed_inline = BaraTables_Service::allowed_inline_html();
		$display_label_html = wp_kses($display_label, $allowed_inline);
		$sort_direction_is_desc = $sort_direction_val === 'desc';
		$filter_sort_is_custom = $filter_sort_val === 'custom';
		$column_checkbox_id = 'btbl_col_' . md5($slug_attr);
		?>
			<div class="btbl-checkbox">
				<span class="btbl-checkbox-top">
					<label class="btbl-checkbox-main" for="<?php echo esc_attr($column_checkbox_id); ?>">
						<input type="checkbox" id="<?php echo esc_attr($column_checkbox_id); ?>" name="btbl_columns[]" value="<?php echo esc_attr($slug_attr); ?>" data-label="<?php echo esc_attr($display_label_html); ?>" <?php checked($is_checked); ?> />
						<span class="btbl-field-name"><?php echo wp_kses($display_label_html, $allowed_inline); ?></span>
					</label>
					<a href="#" class="btbl-options-toggle <?php echo $is_checked ? '' : 'is-hidden'; ?>" aria-expanded="<?php echo $should_open_options ? 'true' : 'false'; ?>">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e('Options', 'baratables'); ?></span>
					</a>
				</span>
			<span class="btbl-field-controls">
				<div class="btbl-field-options-body <?php echo $should_open_options ? 'is-open' : ''; ?>">
					<div class="btbl-options-row btbl-options-inline">
						<div class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Reference', 'baratables'); ?></span>
							<code class="btbl-shortcode btbl-field-ref" data-shortcode="<?php echo esc_attr($slug_attr); ?>" tabindex="0" role="button"><?php echo esc_html($slug_attr); ?></code>
						</div>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Column heading', 'baratables'); ?></span>
							<input type="text" name="btbl_custom_labels[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($custom_label_value); ?>" data-default-label="<?php echo esc_attr($default_label); ?>" />
						</label>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="checkbox" class="btbl-hide-column" name="btbl_hide_column[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($hidden_checked); ?> />
							<?php esc_html_e('Hide column', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="checkbox" class="btbl-searchable-toggle" name="btbl_searchable[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($searchable_checked); ?> />
							<?php esc_html_e('Searchable', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="hidden" name="btbl_sortable[<?php echo esc_attr($slug_attr); ?>]" value="0" />
							<input type="checkbox" class="btbl-sortable-toggle" name="btbl_sortable[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($sortable_checked); ?> />
							<?php esc_html_e('Sortable', 'baratables'); ?>
						</label>
					</div>
					<?php if ($allow_sort) : ?>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="checkbox" class="btbl-sort-enabled" name="btbl_sort_enabled[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($sort_enabled); ?> />
							<?php esc_html_e('Sort by default', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Priority', 'baratables'); ?></span>
							<input type="number" min="0" step="1" class="small-text btbl-sort-priority" name="btbl_sort_priority[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($sort_priority_val); ?>" placeholder="0" />
						</label>
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Direction', 'baratables'); ?></span>
							<select name="btbl_sort_direction[<?php echo esc_attr($slug_attr); ?>]" class="btbl-sort-direction">
								<option value="asc" <?php selected(!$sort_direction_is_desc); ?>><?php esc_html_e('Ascending', 'baratables'); ?></option>
								<option value="desc" <?php selected($sort_direction_is_desc); ?>><?php esc_html_e('Descending', 'baratables'); ?></option>
							</select>
						</label>
					</div>
					<?php endif; ?>
					<div class="btbl-options-row btbl-options-inline btbl-date-format-row <?php echo $is_date_candidate ? '' : 'is-hidden'; ?>" data-date-candidate="<?php echo $is_date_candidate ? '1' : '0'; ?>">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('PHP date format', 'baratables'); ?></span>
							<input type="text" class="btbl-date-format-input" name="btbl_date_format[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($date_format_val); ?>" placeholder="<?php echo esc_attr(get_option('date_format')); ?>" />
						</label>
					</div>
					<div class="btbl-options-row">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Filter type', 'baratables'); ?></span>
							<select name="btbl_filters[<?php echo esc_attr($slug_attr); ?>]" class="btbl-filter-select">
								<option value="none"><?php esc_html_e('No filter', 'baratables'); ?></option>
								<option value="dropdown" <?php selected($current_filter === 'dropdown'); ?>><?php esc_html_e('Dropdown', 'baratables'); ?></option>
								<option value="checkbox" <?php selected($current_filter === 'checkbox'); ?>><?php esc_html_e('Checkboxes', 'baratables'); ?></option>
								<option value="radio" <?php selected($current_filter === 'radio'); ?>><?php esc_html_e('Radio', 'baratables'); ?></option>
							</select>
						</label>
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Filter sort', 'baratables'); ?></span>
							<select name="btbl_filter_sort[<?php echo esc_attr($slug_attr); ?>]" class="btbl-filter-sort">
								<option value="asc" <?php selected($filter_sort_val === 'asc'); ?>><?php esc_html_e('Ascending', 'baratables'); ?></option>
								<option value="desc" <?php selected($filter_sort_val === 'desc'); ?>><?php esc_html_e('Descending', 'baratables'); ?></option>
								<option value="custom" <?php selected($filter_sort_val === 'custom'); ?>><?php esc_html_e('Custom', 'baratables'); ?></option>
							</select>
						</label>
					</div>
					<div class="btbl-options-row btbl-filter-sort-row <?php echo ($current_filter !== 'none' && $filter_sort_is_custom) ? '' : 'is-hidden'; ?>">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Data type priority', 'baratables'); ?></span>
							<textarea name="btbl_filter_type_priority[<?php echo esc_attr($slug_attr); ?>]" rows="3" placeholder="<?php esc_attr_e("text => asc\nnumber => desc\ndate => asc", 'baratables'); ?>"><?php echo esc_textarea($filter_type_priority_val); ?></textarea>
						</label>
					</div>
					<div class="btbl-options-row btbl-filter-label-row">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Filter heading', 'baratables'); ?></span>
							<input type="text" name="btbl_filter_labels[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($filter_label_value); ?>" />
						</label>
					</div>
					<div class="btbl-options-row btbl-filter-values-row">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Custom filter values', 'baratables'); ?></span>
							<textarea name="btbl_filter_values[<?php echo esc_attr($slug_attr); ?>]" rows="3" placeholder="<?php esc_attr_e('Label => search1, search2', 'baratables'); ?>"><?php echo esc_textarea($filter_values_text); ?></textarea>
						</label>
					</div>
					<div class="btbl-options-row btbl-filter-strict-row <?php echo $current_filter !== 'none' ? '' : 'is-hidden'; ?>">
						<label class="btbl-inline">
							<input type="hidden" name="btbl_filter_strict[<?php echo esc_attr($slug_attr); ?>]" value="0" />
							<input type="checkbox" class="btbl-filter-strict" name="btbl_filter_strict[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($filter_strict_checked); ?> />
							<?php esc_html_e('Strict matching', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row">
						<label class="btbl-inline">
							<input type="checkbox" name="btbl_dropdown_multi[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($dropdown_multi_checked); ?> />
							<?php esc_html_e('Allow multiple', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row">
						<label class="btbl-inline">
							<input type="checkbox" name="btbl_dropdown_search[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($dropdown_search_checked); ?> />
							<?php esc_html_e('Enable search', 'baratables'); ?>
						</label>
					</div>
				</div>
			</span>
		</div>
		<?php
	}
}


class BaraTables_Admin_Tab_Table {
	public function render(array $context): void {
		$table_options = $context['table_options'] ?? [];
		$option_schema = BaraTables_Service::get_table_option_schema();
		$value_overrides_pretty = $context['value_overrides_pretty'] ?? '';
		$value_overrides_raw = $context['value_overrides_raw'] ?? '';
		$custom_query_pretty = $context['custom_query_pretty'] ?? '';
		$custom_query_raw = $context['custom_query_raw'] ?? '';
		$access_user_meta = $context['access_user_meta'] ?? '';
		$access_post_meta = $context['access_post_meta'] ?? '';
		$access_csv_column = $context['access_csv_column'] ?? '';
		$access_external_column = $context['access_external_column'] ?? '';
		$access_logged_out = $context['access_logged_out'] ?? 'all';
		$source_type = $context['source_type'] ?? 'wp_query';
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$panel_class = $active_tab === 'btbl-tab-table' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		$flag_keys = [];
		$text_keys = [];
		$number_keys = [];
		$select_keys = [];
		$buttons_config = null;
		foreach ($option_schema as $key => $config) {
			$type = $config['type'] ?? '';
			if ($type === 'checkbox') {
				$flag_keys[] = $key;
			} elseif ($type === 'text_html') {
				$text_keys[] = $key;
			} elseif ($type === 'number') {
				$number_keys[] = $key;
			} elseif ($type === 'select') {
				$select_keys[] = $key;
			} elseif ($type === 'checkbox_multi' && $key === 'buttons') {
				$buttons_config = ['key' => $key, 'config' => $config];
			}
		}

		$style_keys = ['stripe', 'rowBorder', 'cellBorder', 'hover', 'orderColumn', 'compact'];
		$style_keys = array_values(array_filter($style_keys, static function ($key) use ($option_schema) {
			return array_key_exists($key, $option_schema);
		}));

		$embedded_flags = ['lengthChange', 'searchColumns', 'pagingNumbers', 'pagingFirstLast', 'pagingPreviousNext'];
		$flag_keys = array_values(array_filter($flag_keys, static function ($key) use ($embedded_flags, $style_keys) {
			return !in_array($key, $embedded_flags, true) && !in_array($key, $style_keys, true);
		}));

		$paging_inline_order = [
			'pageLength',
			'lengthChange',
			'lengthMenuPrefix',
			'lengthMenuSuffix',
			'pagingNumbers',
			'pagingFirstLast',
			'paginateFirst',
			'paginateLast',
			'pagingPreviousNext',
			'paginatePrevious',
			'paginateNext',
		];
		$inline_controls = [
			'paging' => array_values(array_filter(
				$paging_inline_order,
				static fn($key) => array_key_exists($key, $option_schema)
			)),
			'searchBox' => array_values(array_filter(
				['searchText', 'searchPlaceholder', 'searchColumns', 'searchColumnsLabel', 'searchColumnsHeading'],
				static fn($key) => in_array($key, array_merge($text_keys, $embedded_flags), true)
			)),
			'info' => array_values(array_filter(
				['infoText', 'infoEmpty', 'infoFiltered'],
				static fn($key) => in_array($key, $text_keys, true)
			)),
			'filtersTitle' => array_filter($text_keys, static fn($key) => $key === 'filtersTitleText'),
		];
		$layout_features = [
			'pagelength' => __('Page length', 'baratables'),
			'buttons' => __('Buttons', 'baratables'),
			'search' => __('Search', 'baratables'),
			'info' => __('Summary', 'baratables'),
			'paging' => __('Paging', 'baratables'),
		];
		$layout_zones = [
			'layoutTopStart' => __('Top left', 'baratables'),
			'layoutTopEnd' => __('Top right', 'baratables'),
			'layoutBottomStart' => __('Bottom left', 'baratables'),
			'layoutBottomEnd' => __('Bottom right', 'baratables'),
		];
		$layout_defaults_raw = [];
		foreach (array_keys($layout_zones) as $zone_key) {
			$layout_defaults_raw[$zone_key] = $option_schema[$zone_key]['default'] ?? [];
		}
		$layout_defaults = [];
		$layout_used = [];
		$layout_state = [];
		$layout_allowed = array_keys($layout_features);
		foreach ($layout_zones as $zone_key => $zone_label) {
			$zone_items = isset($table_options[$zone_key]) && is_array($table_options[$zone_key]) ? $table_options[$zone_key] : [];
			$filtered = [];
			foreach ($zone_items as $item) {
				if (!in_array($item, $layout_allowed, true)) {
					continue;
				}
				if (isset($layout_used[$item])) {
					continue;
				}
				$layout_used[$item] = true;
				$filtered[] = $item;
			}
			$layout_state[$zone_key] = $filtered;
			$default_items = isset($layout_defaults_raw[$zone_key]) && is_array($layout_defaults_raw[$zone_key]) ? $layout_defaults_raw[$zone_key] : [];
			$layout_defaults[$zone_key] = array_values(array_filter($default_items, static function ($item) use ($layout_allowed) {
				return in_array($item, $layout_allowed, true);
			}));
		}
		$layout_unused = array_values(array_filter($layout_allowed, static function ($item) use ($layout_used) {
			return !isset($layout_used[$item]);
		}));
		?>
		<div id="btbl-tab-table" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-table-label">
				<div class="btbl-control">
					<strong class="btbl-small-heading"><?php esc_html_e('Table controls', 'baratables'); ?></strong>
					<p class="description"><?php esc_html_e('Toggle the most common DataTables options. Defaults match the standard DataTables experience.', 'baratables'); ?></p>
						<div class="btbl-flag-grid btbl-table-flags">
						<?php foreach ($flag_keys as $flag_key) : ?>
							<?php $config = $option_schema[$flag_key]; ?>
							<?php
							$has_inline = !empty($inline_controls[$flag_key]);
							$input_id = 'btbl_table_flag_' . sanitize_key($flag_key);
							$flag_default = !empty($config['default']) ? '1' : '0';
							?>
							<input type="hidden" name="btbl_table_options[<?php echo esc_attr($flag_key); ?>]" value="0" />
							<div class="btbl-checkbox">
								<span class="btbl-checkbox-top">
									<label class="btbl-checkbox-main" for="<?php echo esc_attr($input_id); ?>">
										<input type="checkbox" id="<?php echo esc_attr($input_id); ?>" name="btbl_table_options[<?php echo esc_attr($flag_key); ?>]" value="1" data-default="<?php echo esc_attr($flag_default); ?>" <?php checked(!empty($table_options[$flag_key])); ?> />
										<span class="btbl-field-name"><?php echo esc_html($config['label']); ?></span>
									</label>
									<?php if ($has_inline) : ?>
										<a href="#" class="btbl-options-toggle btbl-flag-options-toggle" aria-expanded="false">
											<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
											<span class="screen-reader-text"><?php esc_html_e('Options', 'baratables'); ?></span>
										</a>
									<?php endif; ?>
								</span>
								<?php if ($has_inline) : ?>
									<div class="btbl-field-options-body">
										<?php foreach ($inline_controls[$flag_key] as $inline_key) : ?>
											<?php $inline_config = $option_schema[$inline_key]; ?>
											<?php
												$row_classes = ['btbl-options-row', 'btbl-options-inline'];
												if ($inline_key === 'pageLength') {
													$row_classes[] = 'btbl-page-length-row';
												}
												if ($inline_key === 'lengthChange') {
													$row_classes[] = 'btbl-length-change-flag';
												}
												if ($inline_key === 'lengthMenuPrefix' || $inline_key === 'lengthMenuSuffix') {
													$row_classes[] = 'btbl-page-length-row';
													$row_classes[] = 'btbl-length-menu-row';
												}
											if (in_array($inline_key, ['paginateFirst', 'paginatePrevious', 'paginateNext', 'paginateLast'], true)) {
												$row_classes[] = 'btbl-pagination-label-row';
											}
											if ($inline_key === 'searchText') {
												$row_classes[] = 'btbl-search-setting-row';
											}
											if ($inline_key === 'searchPlaceholder') {
												$row_classes[] = 'btbl-search-setting-row';
												$row_classes[] = 'btbl-search-placeholder-row';
											}
											if ($inline_key === 'searchColumns') {
												$row_classes[] = 'btbl-search-columns-flag';
											}
											if ($inline_key === 'searchColumnsLabel' || $inline_key === 'searchColumnsHeading') {
												$row_classes[] = 'btbl-search-setting-row';
												$row_classes[] = 'btbl-search-columns-setting';
											}
											if ($inline_key === 'filtersTitleText') {
												$row_classes[] = 'btbl-filters-title-setting';
											}
											if (in_array($inline_key, ['infoText', 'infoEmpty', 'infoFiltered'], true)) {
												$row_classes[] = 'btbl-info-setting';
											}
											?>
											<div class="<?php echo esc_attr(implode(' ', $row_classes)); ?>">
												<?php if ($inline_config['type'] === 'checkbox') : ?>
													<?php $inline_default = !empty($inline_config['default']) ? '1' : '0'; ?>
													<label class="btbl-inline">
														<input type="hidden" name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]" value="0" />
														<input
															type="checkbox"
															name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]"
															id="btbl_<?php echo esc_attr($inline_key); ?>"
															value="1"
															data-default="<?php echo esc_attr($inline_default); ?>"
															<?php checked(!empty($table_options[$inline_key])); ?>
														/>
														<?php echo esc_html($inline_config['label']); ?>
													</label>
												<?php elseif ($inline_config['type'] === 'number') : ?>
													<label class="btbl-inline" for="btbl_<?php echo esc_attr($inline_key); ?>">
														<span class="btbl-small-label"><?php echo esc_html($inline_config['label']); ?></span>
														<input
															type="number"
															min="<?php echo esc_attr((int) ($inline_config['min'] ?? 1)); ?>"
															max="<?php echo esc_attr((int) ($inline_config['max'] ?? 500)); ?>"
															step="1"
															name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]"
															id="btbl_<?php echo esc_attr($inline_key); ?>"
															value="<?php echo esc_attr((int) ($table_options[$inline_key] ?? $inline_config['default'])); ?>"
														/>
													</label>
												<?php elseif ($inline_config['type'] === 'select') : ?>
													<?php
													$choices = isset($inline_config['choices']) && is_array($inline_config['choices']) ? $inline_config['choices'] : [];
													$current_value = array_key_exists($inline_key, $table_options)
														? (string) $table_options[$inline_key]
														: (string) ($inline_config['default'] ?? '');
													?>
													<label class="btbl-inline" for="btbl_<?php echo esc_attr($inline_key); ?>">
														<span class="btbl-small-label"><?php echo esc_html($inline_config['label']); ?></span>
														<select name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]" id="btbl_<?php echo esc_attr($inline_key); ?>">
															<?php foreach ($choices as $choice_value => $choice_label) : ?>
																<option value="<?php echo esc_attr($choice_value); ?>" <?php selected($current_value, (string) $choice_value); ?>>
																	<?php echo esc_html($choice_label ?? $choice_value); ?>
																</option>
															<?php endforeach; ?>
														</select>
													</label>
												<?php else : ?>
													<?php
													$input_value = array_key_exists($inline_key, $table_options)
														? $table_options[$inline_key]
														: ($inline_config['default'] ?? '');
													$hide_placeholder = in_array($inline_key, ['searchText', 'lengthMenuPrefix', 'lengthMenuSuffix'], true);
													$placeholder_overrides = [
														'paginateFirst' => '«',
														'paginatePrevious' => '‹',
														'paginateNext' => '›',
														'paginateLast' => '»',
														'infoText' => __('Showing _START_ to _END_ of _TOTAL_ entries', 'baratables'),
														'infoEmpty' => __('Showing 0 to 0 of 0 entries', 'baratables'),
														'infoFiltered' => __('(filtered from _MAX_ total entries)', 'baratables'),
													];
													$placeholder = $hide_placeholder ? '' : ($placeholder_overrides[$inline_key] ?? ($inline_config['default'] ?? ''));
													?>
													<label class="btbl-inline" for="btbl_<?php echo esc_attr($inline_key); ?>">
														<span class="btbl-small-label"><?php echo esc_html($inline_config['label']); ?></span>
														<input
															type="text"
															name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]"
															id="btbl_<?php echo esc_attr($inline_key); ?>"
															class="regular-text"
															value="<?php echo esc_attr($input_value); ?>"
															<?php echo $placeholder !== '' ? 'placeholder="' . esc_attr($placeholder) . '"' : ''; ?>
														/>
													</label>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="btbl-control btbl-layout-builder" data-defaults="<?php echo esc_attr(wp_json_encode($layout_defaults)); ?>">
					<div class="btbl-layout-header">
						<div class="btbl-header-stack">
							<strong class="btbl-small-heading"><?php esc_html_e('Table layout', 'baratables'); ?></strong>
							<p class="description"><?php esc_html_e('Drag the elements into each zone to position DataTables controls.', 'baratables'); ?></p>
						</div>
						<button type="button" class="button btbl-layout-reset"><?php esc_html_e('Reset layout', 'baratables'); ?></button>
					</div>
					<div class="btbl-layout-grid">
						<?php foreach ($layout_zones as $zone_key => $zone_label) : ?>
							<div class="btbl-layout-zone">
								<div class="btbl-layout-zone-label"><?php echo esc_html($zone_label); ?></div>
								<div class="btbl-layout-drop" data-zone="<?php echo esc_attr($zone_key); ?>">
									<?php foreach ($layout_state[$zone_key] as $feature) : ?>
										<button type="button" class="btbl-layout-chip" draggable="true" data-feature="<?php echo esc_attr($feature); ?>">
											<?php echo esc_html($layout_features[$feature] ?? $feature); ?>
										</button>
									<?php endforeach; ?>
								</div>
								<div class="btbl-layout-inputs" data-zone-inputs="<?php echo esc_attr($zone_key); ?>">
									<input type="hidden" name="btbl_table_options[<?php echo esc_attr($zone_key); ?>][]" value="" />
									<?php foreach ($layout_state[$zone_key] as $feature) : ?>
										<input type="hidden" name="btbl_table_options[<?php echo esc_attr($zone_key); ?>][]" value="<?php echo esc_attr($feature); ?>" />
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="btbl-layout-zone btbl-layout-palette">
						<div class="btbl-layout-zone-label"><?php esc_html_e('Available elements', 'baratables'); ?></div>
						<div class="btbl-layout-drop btbl-layout-palette-drop" data-zone="palette">
							<?php foreach ($layout_unused as $feature) : ?>
								<button type="button" class="btbl-layout-chip" draggable="true" data-feature="<?php echo esc_attr($feature); ?>">
									<?php echo esc_html($layout_features[$feature] ?? $feature); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<?php if (!empty($style_keys)) : ?>
					<div class="btbl-control">
						<strong class="btbl-small-heading"><?php esc_html_e('Style features', 'baratables'); ?></strong>
						<p class="description"><?php esc_html_e('Toggle built-in DataTables style classes like borders, stripes, and hover states.', 'baratables'); ?></p>
						<div class="btbl-flag-grid btbl-table-flags">
							<?php foreach ($style_keys as $style_key) : ?>
							<?php $config = $option_schema[$style_key]; ?>
							<?php $input_id = 'btbl_table_style_' . sanitize_key($style_key); ?>
							<?php $style_default = !empty($config['default']) ? '1' : '0'; ?>
								<input type="hidden" name="btbl_table_options[<?php echo esc_attr($style_key); ?>]" value="0" />
								<div class="btbl-checkbox">
									<span class="btbl-checkbox-top">
										<label class="btbl-checkbox-main" for="<?php echo esc_attr($input_id); ?>">
										<input type="checkbox" id="<?php echo esc_attr($input_id); ?>" name="btbl_table_options[<?php echo esc_attr($style_key); ?>]" value="1" data-default="<?php echo esc_attr($style_default); ?>" <?php checked(!empty($table_options[$style_key])); ?> />
											<span class="btbl-field-name"><?php echo esc_html($config['label']); ?></span>
										</label>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php if ($buttons_config) : ?>
				<?php $config = $buttons_config['config']; ?>
				<?php
				$button_text_keys = [
					'copy' => 'buttonTextCopy',
					'csv' => 'buttonTextCsv',
					'print' => 'buttonTextPrint',
					'colvis' => 'buttonTextColvis',
					'pagelength' => 'buttonTextPagelength',
				];
				?>
				<div class="btbl-control">
					<strong class="btbl-small-heading"><?php echo esc_html($config['label']); ?></strong>
					<?php if (!empty($config['description'])) : ?>
						<p class="description"><?php echo esc_html($config['description']); ?></p>
					<?php endif; ?>
					<input type="hidden" name="btbl_table_options[<?php echo esc_attr($buttons_config['key']); ?>][]" value="" />
					<div class="btbl-flag-grid btbl-table-flags">
						<?php foreach ((array) ($config['choices'] ?? []) as $choice => $choice_label) : ?>
							<?php $choice_id = 'btbl_flag_' . sanitize_key($buttons_config['key'] . '_' . $choice); ?>
							<?php
							$choice_key = sanitize_key((string) $choice);
							$text_key = $button_text_keys[$choice_key] ?? '';
							$button_checked = in_array($choice, (array) ($table_options[$buttons_config['key']] ?? []), true);
							$button_text_value = $text_key !== '' && array_key_exists($text_key, $table_options) ? (string) $table_options[$text_key] : '';
							$button_text_id = 'btbl_button_text_' . sanitize_key($choice_key);
							?>
							<div class="btbl-flag-card">
								<div class="btbl-checkbox-top">
									<label class="btbl-checkbox-main" for="<?php echo esc_attr($choice_id); ?>">
										<input
											type="checkbox"
											name="btbl_table_options[<?php echo esc_attr($buttons_config['key']); ?>][]"
											id="<?php echo esc_attr($choice_id); ?>"
											value="<?php echo esc_attr($choice); ?>"
											<?php checked($button_checked); ?>
										/>
										<span class="btbl-field-name"><?php echo esc_html($choice_label); ?></span>
									</label>
									<?php if ($text_key !== '') : ?>
										<a href="#" class="btbl-options-toggle btbl-flag-options-toggle <?php echo $button_checked ? '' : 'is-hidden'; ?>" aria-expanded="false">
											<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
											<span class="screen-reader-text"><?php esc_html_e('Options', 'baratables'); ?></span>
										</a>
									<?php endif; ?>
								</div>
								<?php if ($text_key !== '') : ?>
									<div class="btbl-field-options-body <?php echo $button_checked ? '' : 'is-hidden'; ?>">
										<div class="btbl-options-row btbl-options-inline">
											<label class="btbl-inline" for="<?php echo esc_attr($button_text_id); ?>">
												<span class="btbl-small-label"><?php esc_html_e('Button text', 'baratables'); ?></span>
												<input
													type="text"
													name="btbl_table_options[<?php echo esc_attr($text_key); ?>]"
													id="<?php echo esc_attr($button_text_id); ?>"
													class="regular-text"
													value="<?php echo esc_attr($button_text_value); ?>"
													placeholder="<?php echo esc_attr($choice_label); ?>"
												/>
											</label>
										</div>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

class BaraTables_Admin_Tab_Advanced {
	public function render(array $context): void {
		$source_type = $context['source_type'] ?? 'wp_query';
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$panel_class = $active_tab === 'btbl-tab-advanced' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		$value_overrides_pretty = $context['value_overrides_pretty'] ?? '';
		$value_overrides_raw = $context['value_overrides_raw'] ?? '';
		$access_user_meta = $context['access_user_meta'] ?? '';
		$access_post_meta = $context['access_post_meta'] ?? '';
		$access_csv_column = $context['access_csv_column'] ?? '';
		$access_external_column = $context['access_external_column'] ?? '';
		$access_logged_out = $context['access_logged_out'] ?? 'all';
		$source_hidden_class = static function(string $target) use ($source_type): string {
			return $source_type === $target ? '' : ' is-hidden';
		};
		?>
		<div id="btbl-tab-advanced" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-advanced-label">
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_custom_meta"><?php esc_html_e('Additional meta keys (comma-separated)', 'baratables'); ?></label>
				<p class="description"><?php esc_html_e('Add keys that are not auto-detected then Update Table to apply.', 'baratables'); ?></p>
				<input type="text" name="btbl_custom_meta" id="btbl_custom_meta" class="regular-text" placeholder="price, rating, field_custom" />
			</div>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_value_overrides_json"><?php esc_html_e('Value overrides (JSON)', 'baratables'); ?></label>
				<p class="description"><?php esc_html_e('Array of rules applied after values are resolved. Each rule: column slug (or "*" for all), search string or regex (set regex=true), and replace. Merge tags in replace support any core or meta field via {{core:post_title}} or {{meta:your_key}}.', 'baratables'); ?></p>
				<textarea name="btbl_value_overrides_json" id="btbl_value_overrides_json" class="large-text code" rows="6" placeholder='[{"column":"core:post_content","search":"http","replace":"<a href="{{core:permalink}}"><span class="dashicons dashicons-admin-links"></span></a>"},{"column":"*","regex":true,"search":"#link:(.*?)#","replace":"$1"}]' spellcheck="false"><?php echo esc_textarea($value_overrides_raw !== '' ? $value_overrides_raw : $value_overrides_pretty); ?></textarea>
			</div>
			<div class="btbl-control btbl-access-control">
				<strong class="btbl-small-heading"><?php esc_html_e('Access control', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('Filter rows based on tokens from the logged-in user. Configure which row meta/column holds allowed tokens and which user meta holds the user tokens. Leave blank to disable.', 'baratables'); ?></p>
				<div class="btbl-control-grid btbl-access-grid">
					<div class="btbl-control">
						<label class="btbl-small-heading" for="btbl_access_user_meta"><?php esc_html_e('User meta key (tokens)', 'baratables'); ?></label>
						<p class="description"><?php esc_html_e('Read tokens from this user meta key. Leave blank to fall back to user roles.', 'baratables'); ?></p>
						<input type="text" name="btbl_access_user_meta" id="btbl_access_user_meta" class="regular-text" value="<?php echo esc_attr($access_user_meta); ?>" placeholder="_btbl_user_tokens" />
					</div>
					<div class="btbl-control<?php echo esc_attr($source_hidden_class('wp_query')); ?>" data-btbl-source="wp_query">
						<label class="btbl-small-heading" for="btbl_access_post_meta"><?php esc_html_e('Post meta key (row tokens)', 'baratables'); ?></label>
						<p class="description"><?php esc_html_e('Rows are shown if tokens here overlap the user tokens. Empty/missing = public.', 'baratables'); ?></p>
						<input type="text" name="btbl_access_post_meta" id="btbl_access_post_meta" class="regular-text" value="<?php echo esc_attr($access_post_meta); ?>" placeholder="_btbl_allowed_tokens" />
					</div>
					<div class="btbl-control<?php echo esc_attr($source_hidden_class('csv')); ?>" data-btbl-source="csv">
						<label class="btbl-small-heading" for="btbl_access_csv_column"><?php esc_html_e('CSV column (row tokens)', 'baratables'); ?></label>
						<p class="description"><?php esc_html_e('Use the header/slug for the CSV column containing allowed tokens.', 'baratables'); ?></p>
						<input type="text" name="btbl_access_csv_column" id="btbl_access_csv_column" class="regular-text" value="<?php echo esc_attr($access_csv_column); ?>" placeholder="allowed_tokens" />
					</div>
						<div class="btbl-control<?php echo esc_attr($source_hidden_class('external_db')); ?>" data-btbl-source="external_db">
							<label class="btbl-small-heading" for="btbl_access_external_column"><?php esc_html_e('External column (row tokens)', 'baratables'); ?></label>
							<p class="description"><?php esc_html_e('Column name from your external table or view that contains allowed tokens.', 'baratables'); ?></p>
							<input type="text" name="btbl_access_external_column" id="btbl_access_external_column" class="regular-text" value="<?php echo esc_attr($access_external_column); ?>" placeholder="allowed_tokens" />
						</div>
					<div class="btbl-control">
						<label class="btbl-small-heading" for="btbl_access_logged_out"><?php esc_html_e('Logged-out visitors see', 'baratables'); ?></label>
						<p class="description"><?php esc_html_e('Content that logged out users see.', 'baratables'); ?></p>
						<select name="btbl_access_logged_out" id="btbl_access_logged_out">
							<option value="all" <?php selected($access_logged_out, 'all'); ?>><?php esc_html_e('All rows', 'baratables'); ?></option>
							<option value="public_only" <?php selected($access_logged_out, 'public_only'); ?>><?php esc_html_e('Only public rows (empty tokens)', 'baratables'); ?></option>
							<option value="none" <?php selected($access_logged_out, 'none'); ?>><?php esc_html_e('No rows', 'baratables'); ?></option>
						</select>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}


class BaraTables_Admin_Tab_Chart {
	public function render(array $context, array $column_choices): void {
		$chart_options = $context['chart_options'] ?? [];
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$table_choices = $context['table_choices'] ?? [];
		$selected_table = $context['selected_table'] ?? '';
		$page_slug = $context['page_slug'] ?? '';
		$panel_class = $active_tab === 'btbl-tab-chart' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
			$plugin_file = dirname(__DIR__, 2) . '/baratables.php';
			$plugin_dir = plugin_dir_path($plugin_file);
			$plugin_url = plugin_dir_url($plugin_file);
			$placeholder_svg = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" viewBox="0 0 320 200"><rect width="320" height="200" fill="#f7f8fa"/><rect x="32" y="36" width="28" height="128" fill="#d7dde5"/><rect x="78" y="76" width="28" height="88" fill="#d7dde5"/><rect x="124" y="52" width="28" height="112" fill="#d7dde5"/><rect x="170" y="92" width="28" height="72" fill="#d7dde5"/><rect x="216" y="116" width="28" height="48" fill="#d7dde5"/><rect x="262" y="64" width="28" height="96" fill="#d7dde5"/><text x="160" y="186" text-anchor="middle" font-size="14" fill="#94a3b8" font-family="Arial, sans-serif">Preview coming soon</text></svg>');
			$chart_type_images = [
				'bar'   => 'bar-simple.webp',
				'line'  => 'line-simple.webp',
				'area'  => 'area-basic.webp',
				'pie'   => 'pie-simple.webp',
				'gantt' => 'custom-gantt-flight.webp',
			];
			?>
		<div id="btbl-tab-chart" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-chart-label">
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_chart_table"><?php esc_html_e('Table', 'baratables'); ?></label>
				<select name="btbl_chart_table" id="btbl_chart_table" data-page="<?php echo esc_attr($page_slug); ?>" required>
					<option value=""><?php esc_html_e('Select table', 'baratables'); ?></option>
					<?php foreach ($table_choices as $id => $label) : ?>
						<option value="<?php echo esc_attr($id); ?>" <?php selected($selected_table, $id); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e('Choose which table powers this chart. Column choices update when you change the table.', 'baratables'); ?></p>
			</div>
			<div class="btbl-flag-grid">
				<label class="btbl-flag">
					<input type="hidden" name="btbl_chart_stack" value="0" />
					<input type="checkbox" name="btbl_chart_stack" value="1" <?php checked(!empty($chart_options['stack'])); ?> />
					<span class="btbl-flag-text"><?php esc_html_e('Stack series', 'baratables'); ?></span>
				</label>
			</div>
			<div class="btbl-control-grid btbl-chart-grid">
				<div class="btbl-control">
					<div class="btbl-small-heading-row">
						<label class="btbl-small-heading" for="btbl_chart_type"><?php esc_html_e('Chart type', 'baratables'); ?></label>
						<a href="#btbl-chart-type-modal" class="btbl-chart-preview-trigger" aria-haspopup="dialog" aria-controls="btbl-chart-type-modal">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e('Open chart type gallery', 'baratables'); ?></span>
						</a>
					</div>
					<select name="btbl_chart_type" id="btbl_chart_type" class="btbl-chart-type-select">
						<option value="bar" <?php selected($chart_options['type'], 'bar'); ?>><?php esc_html_e('Bar', 'baratables'); ?></option>
						<option value="line" <?php selected($chart_options['type'], 'line'); ?>><?php esc_html_e('Line', 'baratables'); ?></option>
						<option value="area" <?php selected($chart_options['type'], 'area'); ?>><?php esc_html_e('Area', 'baratables'); ?></option>
						<option value="pie" <?php selected($chart_options['type'], 'pie'); ?>><?php esc_html_e('Pie', 'baratables'); ?></option>
						<option value="gantt" <?php selected($chart_options['type'], 'gantt'); ?>><?php esc_html_e('Gantt', 'baratables'); ?></option>
					</select>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_height"><?php esc_html_e('Chart height (px)', 'baratables'); ?></label>
					<input type="number" min="120" max="2000" name="btbl_chart_height" id="btbl_chart_height" class="small-text" value="<?php echo esc_attr((int) ($chart_options['height'] ?? 360)); ?>" />
				</div>
			</div>
			<div class="btbl-control-grid btbl-chart-grid btbl-chart-standard">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_x_axis"><?php esc_html_e('X-axis / category column', 'baratables'); ?></label>
					<select name="btbl_chart_x_axis" id="btbl_chart_x_axis" required>
						<option value=""><?php esc_html_e('Select column', 'baratables'); ?></option>
						<?php foreach ($column_choices as $slug => $label) : ?>
							<option value="<?php echo esc_attr($slug); ?>" <?php selected($chart_options['x_axis'], $slug); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e('Used for categories/labels (Pie uses this for slice labels).', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_series"><?php esc_html_e('Series columns', 'baratables'); ?></label>
					<select name="btbl_chart_series[]" id="btbl_chart_series" multiple required>
						<?php foreach ($column_choices as $slug => $label) : ?>
							<option value="<?php echo esc_attr($slug); ?>" <?php selected(in_array($slug, (array) ($chart_options['series'] ?? []), true)); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e('Select one or more numeric columns to plot. Pie uses the first series selected.', 'baratables'); ?></p>
				</div>
			</div>
			<div class="btbl-control-grid btbl-chart-grid btbl-chart-gantt">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_label"><?php esc_html_e('Task / label column', 'baratables'); ?></label>
					<select name="btbl_chart_gantt_label" id="btbl_chart_gantt_label">
						<option value=""><?php esc_html_e('Select column', 'baratables'); ?></option>
						<?php foreach ($column_choices as $slug => $label) : ?>
							<option value="<?php echo esc_attr($slug); ?>" <?php selected($chart_options['gantt_label'] ?? '', $slug); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e('Used for the task names on the Y-axis.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_start"><?php esc_html_e('Start date/time column', 'baratables'); ?></label>
					<select name="btbl_chart_gantt_start" id="btbl_chart_gantt_start">
						<option value=""><?php esc_html_e('Select column', 'baratables'); ?></option>
						<?php foreach ($column_choices as $slug => $label) : ?>
							<option value="<?php echo esc_attr($slug); ?>" <?php selected($chart_options['gantt_start'] ?? '', $slug); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e('Dates should be parseable (e.g. 2024-01-31 or 2024-01-31 12:00).', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_end"><?php esc_html_e('End date/time column', 'baratables'); ?></label>
					<select name="btbl_chart_gantt_end" id="btbl_chart_gantt_end">
						<option value=""><?php esc_html_e('Select column', 'baratables'); ?></option>
						<?php foreach ($column_choices as $slug => $label) : ?>
							<option value="<?php echo esc_attr($slug); ?>" <?php selected($chart_options['gantt_end'] ?? '', $slug); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e('Each task needs both a start and end.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_group"><?php esc_html_e('Group / lane column (optional)', 'baratables'); ?></label>
					<select name="btbl_chart_gantt_group" id="btbl_chart_gantt_group">
						<option value=""><?php esc_html_e('None', 'baratables'); ?></option>
						<?php foreach ($column_choices as $slug => $label) : ?>
							<option value="<?php echo esc_attr($slug); ?>" <?php selected($chart_options['gantt_group'] ?? '', $slug); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e('Use to color tasks by owner/lane.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_progress"><?php esc_html_e('Progress % column (optional)', 'baratables'); ?></label>
					<select name="btbl_chart_gantt_progress" id="btbl_chart_gantt_progress">
						<option value=""><?php esc_html_e('None', 'baratables'); ?></option>
						<?php foreach ($column_choices as $slug => $label) : ?>
							<option value="<?php echo esc_attr($slug); ?>" <?php selected($chart_options['gantt_progress'] ?? '', $slug); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e('Shown in tooltips; numbers should be 0-100.', 'baratables'); ?></p>
				</div>
			</div>
			<?php if (empty($column_choices)) : ?>
				<p class="description"><?php esc_html_e('Add columns first, then configure the chart.', 'baratables'); ?></p>
			<?php endif; ?>
		</div>
		<div id="btbl-chart-type-modal" class="btbl-chart-modal" role="dialog" aria-modal="true" aria-labelledby="btbl-chart-type-modal-title">
			<div class="btbl-chart-modal__backdrop"></div>
			<div class="btbl-chart-modal__content" role="document">
				<div class="btbl-chart-modal__header">
					<h3 id="btbl-chart-type-modal-title"><?php esc_html_e('Choose chart type', 'baratables'); ?></h3>
					<a href="#" class="btbl-chart-modal__close" aria-label="<?php esc_attr_e('Close chart type chooser', 'baratables'); ?>">&times;</a>
				</div>
				<div class="btbl-chart-modal__body">
					<div class="btbl-chart-type-chooser" role="list" aria-label="<?php esc_attr_e('Chart type', 'baratables'); ?>">
						<?php
						$current_type = $chart_options['type'] ?? 'bar';
							$type_labels = [
								'bar' => __('Bar', 'baratables'),
								'line' => __('Line', 'baratables'),
								'area' => __('Area', 'baratables'),
								'pie' => __('Pie', 'baratables'),
								'gantt' => __('Gantt', 'baratables'),
							];
							foreach ($type_labels as $slug => $label) :
								$image_url = '';
								$filename = $chart_type_images[$slug] ?? '';
								if ($filename !== '') {
									$full_path = $plugin_dir . 'assets/charts/' . $filename;
									if (file_exists($full_path)) {
										$image_url = $plugin_url . 'assets/charts/' . $filename;
									}
								}
								$is_active = $slug === $current_type;
								?>
							<button type="button" class="btbl-chart-type-card<?php echo $is_active ? ' is-active' : ''; ?>" data-type="<?php echo esc_attr($slug); ?>" role="listitem" aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
									<span class="btbl-chart-type-thumb<?php echo $image_url ? '' : ' is-placeholder'; ?>"<?php echo $image_url ? ' style="background-image:url(' . esc_url($image_url) . ');"' : ''; ?>>
										<?php if (!$image_url) : ?>
											<span class="btbl-chart-type-thumb-fallback" style="background-image:url('<?php echo esc_attr($placeholder_svg); ?>');"></span>
										<?php endif; ?>
									</span>
								<span class="btbl-chart-type-label"><?php echo esc_html($label); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
