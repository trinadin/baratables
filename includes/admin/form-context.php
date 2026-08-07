<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin_Form_Context {
	private const PREVIEW_GET_FIELDS = [
		'type' => 'type',
		'source' => 'btbl_source',
		'custom_query' => 'btbl_preview_custom_query',
		'csv_id' => 'btbl_preview_csv_id',
		'csv_header' => 'btbl_preview_csv_header',
		'csv_delim' => 'btbl_preview_csv_delim',
		'tab' => 'tab',
	];
	private BaraTables_Service $service;

	public function __construct(BaraTables_Service $service) {
		$this->service = $service;
	}

	private function resolve_source_state(array $editing_defn, array $request): array {
		$post_types = $this->service->get_supported_post_types();
		$current_pts = isset($editing_defn['post_types']) && is_array($editing_defn['post_types'])
			? $editing_defn['post_types']
			: [$editing_defn['post_type'] ?? 'post'];
		if (isset($request['type'])) {
			$current_pts = [];
			foreach (array_filter(array_map('trim', explode(',', (string) $request['type']))) as $part) {
				$clean = sanitize_key($part);
				if ($clean !== '') {
					$current_pts[] = $clean;
				}
			}
		}
		$current_pts = array_values(array_filter($current_pts));
		if (empty($current_pts)) {
			$current_pts = ['post'];
		}

		$original_source = BaraTables_Source_Type::normalize($editing_defn['source_type'] ?? BaraTables_Source_Type::WP_QUERY);
		$source_type = BaraTables_Source_Type::normalize($request['source'] ?? $original_source, $original_source);
		$custom_query_preview_raw = $request['custom_query'] ?? null;
		$custom_query_raw_for_fields = $custom_query_preview_raw !== null
			? (string) $custom_query_preview_raw
			: (string) ($editing_defn['custom_query_raw'] ?? '');
		$custom_query_args_for_fields = [];
		if ($custom_query_raw_for_fields !== '') {
			$custom_query_args_for_fields = $this->service->sanitize_custom_query_json($custom_query_raw_for_fields);
		} elseif ($custom_query_preview_raw === null && !empty($editing_defn['custom_query']) && is_array($editing_defn['custom_query'])) {
			$custom_query_args_for_fields = $editing_defn['custom_query'];
		}
		$custom_query_empty = $source_type === BaraTables_Source_Type::CUSTOM_QUERY && empty($custom_query_args_for_fields);
		if ($source_type === BaraTables_Source_Type::CUSTOM_QUERY) {
			if ($custom_query_empty) {
				$current_pts = [];
			} else {
				$custom_post_types = $custom_query_args_for_fields['post_type'] ?? [];
				$current_pts = $this->service->sanitize_post_types(
					is_array($custom_post_types) ? $custom_post_types : [$custom_post_types],
					$source_type
				);
				if (empty($current_pts)) {
					$current_pts = ['post'];
				}
			}
		}

		if (BaraTables_Source_Type::is_csv($source_type) && !empty($editing_defn['columns'])) {
			BaraTables_Service::normalize_csv_column_sources($editing_defn['columns']);
		}
		$original_attachment_id = (int) ($editing_defn['csv_attachment_id'] ?? 0);
		$original_has_header = !empty($editing_defn['csv_has_header']);
		$original_delimiter_raw = (string) ($editing_defn['csv_delimiter'] ?? ',');
		$original_delimiter = $original_delimiter_raw !== '' ? substr($original_delimiter_raw, 0, 1) : ',';
		$csv_attachment_id = isset($request['csv_id']) ? (int) $request['csv_id'] : $original_attachment_id;
		$csv_has_header = isset($request['csv_header']) ? (bool) $request['csv_header'] : $original_has_header;
		$csv_delimiter_raw = isset($request['csv_delim']) ? (string) $request['csv_delim'] : $original_delimiter_raw;
		$csv_delimiter = $csv_delimiter_raw !== '' ? substr($csv_delimiter_raw, 0, 1) : ',';
		$is_post_request = ($request['request_method'] ?? '') === 'POST';
		$csv_inputs_changed = BaraTables_Source_Type::is_csv($source_type) && (
			$csv_attachment_id !== $original_attachment_id
			|| $csv_has_header !== $original_has_header
			|| $csv_delimiter !== $original_delimiter
		);
		$csv_query_override = !$is_post_request && BaraTables_Source_Type::is_csv($source_type)
			&& (isset($request['csv_id']) || isset($request['csv_header']) || isset($request['csv_delim']));
		$columns_should_reset = !$is_post_request && (
			$source_type !== $original_source
			|| $custom_query_empty
			|| (BaraTables_Source_Type::is_csv($source_type) && ($csv_inputs_changed || $csv_query_override))
		);
		if ($columns_should_reset) {
			$editing_defn['filter_order'] = [];
			$editing_defn['columns'] = [];
		}

		return compact(
			'editing_defn',
			'post_types',
			'current_pts',
			'source_type',
			'custom_query_preview_raw',
			'custom_query_args_for_fields',
			'custom_query_empty',
			'csv_attachment_id',
			'csv_has_header',
			'csv_delimiter',
			'columns_should_reset'
		);
	}

	private function discover_source_fields(array $source): array {
		$editing_defn = $source['editing_defn'];
		$source_type = $source['source_type'];
		$current_pts = $source['current_pts'];
		$custom_query_empty = $source['custom_query_empty'];
		$csv_attachment_id = $source['csv_attachment_id'];
		$csv_has_header = $source['csv_has_header'];
		$csv_delimiter = $source['csv_delimiter'];
		$columns_should_reset = $source['columns_should_reset'];
		$inferred = [];
		if (BaraTables_Source_Type::is_csv($source_type)) {
			$source_definition = array_merge($editing_defn, [
				'source_type' => BaraTables_Source_Type::CSV,
				'csv_attachment_id' => $csv_attachment_id,
				'csv_has_header' => $csv_has_header,
				'csv_delimiter' => $csv_delimiter,
			]);
			$inferred = $this->service->get_row_result($source_definition, 1)->inferred_columns();
		} elseif (BaraTables_Source_Type::is_external_db($source_type) && !empty($editing_defn['external_db'])) {
			$source_definition = $editing_defn;
			$source_definition['source_type'] = BaraTables_Source_Type::EXTERNAL_DB;
			$inferred = $this->service->get_row_result($source_definition, 1)->inferred_columns();
		}

		if ($custom_query_empty) {
			$fields = ['core' => [], 'meta' => [], 'tax' => [], 'meta_sources' => [], 'tax_sources' => []];
			$taxonomies = [];
			$should_show_source_hint = false;
		} else {
			$fields = BaraTables_Source_Type::uses_builder_fields($source_type)
				? $this->service->get_available_fields_for_post_types($current_pts)
				: ['core' => [], 'meta' => [], 'tax' => []];
			$selected_term_ids = [];
			foreach ((array) ($editing_defn['taxonomy_filter'] ?? []) as $tax_filter) {
				foreach ((array) ($tax_filter['terms'] ?? []) as $term_id) {
					$selected_term_ids[] = (int) $term_id;
				}
			}
			$taxonomies = $this->service->get_taxonomies_for_post_types($current_pts, $selected_term_ids);
			$should_show_source_hint = count($current_pts) > 1;
		}

		$source_columns = [];
		if (BaraTables_Source_Type::is_csv($source_type)) {
			if ($csv_attachment_id > 0) {
				$source_columns = !empty($inferred) ? $inferred : ($editing_defn['columns'] ?? []);
			}
		} elseif (BaraTables_Source_Type::is_external_db($source_type)) {
			$source_columns = !empty($inferred) ? $inferred : ($editing_defn['columns'] ?? []);
		} elseif ($columns_should_reset) {
			$editing_defn['columns'] = [];
		}

		return compact('editing_defn', 'fields', 'taxonomies', 'should_show_source_hint', 'source_columns');
	}

	private function build_custom_data_context(array $editing_defn, string $source_type): array {
		$context = [
			'available_columns' => [],
			'columns' => [],
			'rows' => [],
			'rows_count' => 5,
			'cols_count' => 3,
		];
		if (!BaraTables_Source_Type::is_custom_data($source_type)) {
			return $context;
		}
		$custom_data = isset($editing_defn['custom_data']) && is_array($editing_defn['custom_data']) ? $editing_defn['custom_data'] : [];
		$columns_raw = isset($custom_data['columns']) && is_array($custom_data['columns']) ? array_values($custom_data['columns']) : [];
		$rows_raw = isset($custom_data['rows']) && is_array($custom_data['rows']) ? array_values($custom_data['rows']) : [];
		$dataset = $this->service->build_custom_dataset(
			$columns_raw,
			$rows_raw,
			!empty($rows_raw) ? count($rows_raw) : $context['rows_count'],
			!empty($columns_raw) ? count($columns_raw) : $context['cols_count']
		);
		$context['columns'] = $dataset['columns'];
		$context['rows'] = $dataset['rows'];
		$context['cols_count'] = $dataset['cols_count'];
		$context['rows_count'] = $dataset['rows_count'];
		$context['available_columns'] = !empty($editing_defn['columns'])
			? $editing_defn['columns']
			: $this->service->build_custom_display_columns($context['columns']);
		return $context;
	}

	private function reconcile_available_columns(array $editing_defn, string $source_type, array $source_columns, array $custom_columns, array $fields, bool $columns_should_reset): array {
		$display_columns = BaraTables_Source_Type::uses_column_preview($source_type)
			? $source_columns
			: (BaraTables_Source_Type::is_custom_data($source_type) ? $custom_columns : ($editing_defn['columns'] ?? []));
		$selected_columns = array_map(static function ($column) {
			return BaraTables_Service::build_slug((string) ($column['source'] ?? 'core'), (string) ($column['key'] ?? ''));
		}, $editing_defn['columns']);

		$available_slugs = [];
		if (BaraTables_Source_Type::uses_column_preview($source_type)) {
			foreach ($source_columns as $column) {
				$column_source = $column['source'] ?? (BaraTables_Source_Type::is_external_db($source_type) ? 'external' : 'csv');
				$available_slugs[] = BaraTables_Service::build_slug((string) $column_source, (string) ($column['key'] ?? ''));
			}
		} elseif (BaraTables_Source_Type::uses_builder_fields($source_type)) {
			foreach (array_keys($fields['core']) as $key) {
				$available_slugs[] = BaraTables_Service::build_slug('core', (string) $key);
			}
			foreach ($fields['meta'] as $key) {
				$available_slugs[] = BaraTables_Service::build_slug('meta', (string) $key);
			}
			foreach (array_keys($fields['tax'] ?? []) as $key) {
				$available_slugs[] = BaraTables_Service::build_slug('tax', (string) $key);
			}
		} else {
			foreach ($custom_columns as $column) {
				$column_source = !empty($column['source']) ? $column['source'] : 'custom';
				$available_slugs[] = BaraTables_Service::build_slug((string) $column_source, (string) ($column['key'] ?? ''));
			}
		}
		$available_slug_map = !empty($available_slugs) ? array_fill_keys($available_slugs, true) : [];

		if ($columns_should_reset) {
			$selected_columns = [];
		} elseif (!empty($available_slug_map)) {
			$selected_columns = array_values(array_intersect($selected_columns, array_keys($available_slug_map)));
			$editing_defn['columns'] = array_values(array_filter($editing_defn['columns'], static function ($column) use ($available_slug_map) {
				if (!is_array($column) || !isset($column['key'])) {
					return false;
				}
				$source = sanitize_key((string) ($column['source'] ?? 'core')) ?: 'core';
				return isset($available_slug_map[$source . ':' . $column['key']]);
			}));
		}

		return compact('editing_defn', 'display_columns', 'selected_columns', 'available_slug_map');
	}

	/**
	 * Sanitize live-preview inputs keyed by their CANONICAL preview name.
	 *
	 * The single sanitizer for both collectors -- the full-page GET below and the AJAX POST in
	 * BaraTables_Admin::ajax_refresh_fields(). They previously repeated these rules, and the two
	 * had to stay byte-identical or the in-place refresh and the legacy reload would disagree
	 * about the same input. A key is emitted only when it was present in the request.
	 *
	 * Values must ALREADY be wp_unslash()-ed by the caller, at the point it touches the
	 * superglobal -- that is where the unslash belongs, and deferring it here reads as an
	 * unsanitized superglobal access. custom_query is unslashed exactly once for the same reason
	 * a second pass would corrupt its JSON escapes.
	 */
	public static function sanitize_preview_values(array $raw): array {
		$sanitizers = [
			'type' => 'sanitize_text_field',
			'source' => 'sanitize_key',
			'custom_query' => [BaraTables_Admin_Action_Handler::class, 'sanitize_json_textarea'],
			'csv_id' => 'absint',
			'csv_header' => 'absint',
			'csv_delim' => 'sanitize_text_field',
			'tab' => 'sanitize_key',
		];
		$out = [];
		foreach ($sanitizers as $key => $sanitizer) {
			if (isset($raw[$key])) {
				$out[$key] = $sanitizer($raw[$key]);
			}
		}
		return $out;
	}

	/** Canonical POST names accepted by the in-place source-fields preview. */
	public static function preview_post_fields(): array {
		return array_values(array_diff(array_keys(self::PREVIEW_GET_FIELDS), ['tab']));
	}

	public static function preview_request_from_globals(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only live-preview params; unslashed + sanitized in sanitize_preview_values().
		$get = $_GET;
		$raw = [];
		foreach (self::PREVIEW_GET_FIELDS as $canonical => $get_key) {
			if (isset($get[$get_key])) {
				$raw[$canonical] = wp_unslash($get[$get_key]);
			}
		}
		$request = self::sanitize_preview_values($raw);
		$request['request_method'] = isset($_SERVER['REQUEST_METHOD'])
			? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
			: '';
		return $request;
	}

	private function build_editor_values(array $editing_defn, array $request, array $state): array {
		$is_edit = $state['is_edit'];
		$columns_should_reset = $state['columns_should_reset'];
		$available_slug_map = $state['available_slug_map'];
		$selected_columns = $state['selected_columns'];
		$fields = $state['fields'];
		$source_type = $state['source_type'];
		$custom_query_preview_raw = $state['custom_query_preview_raw'];
		$custom_query_args_for_fields = $state['custom_query_args_for_fields'];
		$values = [
			'selected_taxonomy' => [],
			'selected_tax_terms' => [],
			'custom_query_pretty' => '',
			'value_overrides_pretty' => '',
			'custom_query_raw' => '',
			'value_overrides_raw' => '',
			'table_options' => $this->service->get_default_table_options(),
			'access_user_meta' => '',
			'access_post_meta' => '',
			'access_csv_column' => '',
			'access_external_column' => '',
			'access_logged_out' => 'public_only',
			'external_host' => '',
			'external_name' => '',
			'external_user' => '',
			'external_pass' => '',
			'external_pass_saved' => false,
			'external_table' => '',
			'external_charset' => '',
			'external_port' => '',
			'filter_order' => [],
			'active_tab' => $request['tab'] ?? 'btbl-tab-general',
		];
		$columns_for_state = $is_edit && !$columns_should_reset ? $editing_defn['columns'] : [];
		$column_records = $this->service->build_editor_column_records_from_definition($columns_for_state);
		if (!empty($available_slug_map)) {
			$column_records = $this->service->filter_editor_column_records_by_slug_map($column_records, $available_slug_map);
		}

		$missing_meta = [];
		foreach ($editing_defn['columns'] as $column) {
			if (($column['source'] ?? '') === 'meta' && !in_array($column['key'], $fields['meta'], true)) {
				$missing_meta[] = $column['key'];
			}
		}
		$values['missing_meta'] = array_values(array_unique($missing_meta));

		foreach (BaraTables_Taxonomy_Filters::normalize($editing_defn['taxonomy_filter'] ?? []) as $filter) {
			$taxonomy = sanitize_key($filter['taxonomy'] ?? '');
			if ($taxonomy !== '') {
				$values['selected_taxonomy'][] = $taxonomy;
				$values['selected_tax_terms'][$taxonomy] = array_map('intval', (array) ($filter['terms'] ?? []));
			}
		}
		$values['selected_taxonomy'] = array_values(array_unique($values['selected_taxonomy']));

		if ($custom_query_preview_raw !== null) {
			$values['custom_query_raw'] = (string) $custom_query_preview_raw;
		} elseif (!empty($editing_defn['custom_query_raw'])) {
			$values['custom_query_raw'] = (string) $editing_defn['custom_query_raw'];
		}
		if ($values['custom_query_raw'] === '' && !empty($custom_query_args_for_fields)) {
			$values['custom_query_pretty'] = wp_json_encode($custom_query_args_for_fields, JSON_PRETTY_PRINT);
		} elseif (!empty($editing_defn['custom_query']) && is_array($editing_defn['custom_query'])) {
			$values['custom_query_pretty'] = wp_json_encode($editing_defn['custom_query'], JSON_PRETTY_PRINT);
		}
		if (!empty($editing_defn['value_overrides']) && is_array($editing_defn['value_overrides'])) {
			$values['value_overrides_pretty'] = wp_json_encode($editing_defn['value_overrides'], JSON_PRETTY_PRINT);
		}
		if (!empty($editing_defn['value_overrides_raw'])) {
			$values['value_overrides_raw'] = (string) $editing_defn['value_overrides_raw'];
		}

		if (!empty($editing_defn)) {
			$values['table_options'] = $this->service->get_table_options($editing_defn);
			$values['filter_order'] = isset($editing_defn['filter_order']) && is_array($editing_defn['filter_order'])
				? array_values($editing_defn['filter_order'])
				: [];
			$access = isset($editing_defn['access_control']) && is_array($editing_defn['access_control']) ? $editing_defn['access_control'] : [];
			$values['access_user_meta'] = $access['user_meta_key'] ?? '';
			$values['access_post_meta'] = $access['post_meta_key'] ?? '';
			$values['access_csv_column'] = $access['csv_column'] ?? '';
			$values['access_external_column'] = $access['external_column'] ?? '';
			$values['access_logged_out'] = $access['logged_out'] ?? 'public_only';
			$external = isset($editing_defn['external_db']) && is_array($editing_defn['external_db']) ? $editing_defn['external_db'] : [];
			$values['external_host'] = $external['host'] ?? '';
			$values['external_name'] = $external['name'] ?? '';
			$values['external_user'] = $external['user'] ?? '';
			$values['external_pass_saved'] = !empty($external['pass']);
			$values['external_table'] = $external['table'] ?? '';
			$values['external_charset'] = $external['charset'] ?? '';
			$values['external_port'] = isset($external['port']) ? (string) $external['port'] : '';
		}

		if (BaraTables_Source_Type::is_csv($source_type)) {
			$values['filter_order'] = array_map(static function ($slug) {
				return preg_replace('/^core:/', 'csv:', (string) $slug);
			}, $values['filter_order']);
		}
		if (!empty($available_slug_map)) {
			$values['filter_order'] = array_values(array_filter($values['filter_order'], static function ($slug) use ($available_slug_map) {
				return isset($available_slug_map[$slug]);
			}));
		}
		if (empty($values['filter_order'])) {
			$values['filter_order'] = array_values(array_filter($selected_columns, static function ($slug) use ($column_records) {
				return isset($column_records[$slug]) && $column_records[$slug]['filter'] !== 'none';
			}));
		}
		$values['column_records'] = $this->service->apply_editor_column_record_defaults($column_records, $selected_columns);
		return $values;
	}

	public function build(?array $editing_defn, ?array $request = null): array {
		$request = $request ?? self::preview_request_from_globals();
		// Captured BEFORE the normalisation below: that gives $editing_defn a 'columns' key, so
		// from then on it is always a non-empty array. $is_edit is the only honest "editing an
		// existing table?" test after this point.
		$is_edit = !empty($editing_defn);
		$editing_defn = $editing_defn ?? [];
		if (!isset($editing_defn['columns']) || !is_array($editing_defn['columns'])) {
			$editing_defn['columns'] = [];
		}
		$state = $this->resolve_source_state($editing_defn, $request);
		$state = array_merge($state, $this->discover_source_fields($state));
		$custom = $this->build_custom_data_context($state['editing_defn'], $state['source_type']);
		$state = array_merge($state, [
			'custom_columns' => $custom['columns'],
			'custom_rows' => $custom['rows'],
			'custom_rows_count' => $custom['rows_count'],
			'custom_cols_count' => $custom['cols_count'],
		]);
		$state = array_merge($state, $this->reconcile_available_columns(
			$state['editing_defn'],
			$state['source_type'],
			$state['source_columns'],
			$custom['available_columns'],
			$state['fields'],
			$state['columns_should_reset']
		));
		$editor_values = $this->build_editor_values($state['editing_defn'], $request, [
			'is_edit' => $is_edit,
			'columns_should_reset' => $state['columns_should_reset'],
			'available_slug_map' => $state['available_slug_map'],
			'selected_columns' => $state['selected_columns'],
			'fields' => $state['fields'],
			'source_type' => $state['source_type'],
			'custom_query_preview_raw' => $state['custom_query_preview_raw'],
			'custom_query_args_for_fields' => $state['custom_query_args_for_fields'],
		]);

		return array_merge([
			'post_types' => $state['post_types'],
			'fields' => $state['fields'],
			'display_columns' => $state['display_columns'],
			'taxonomies' => $state['taxonomies'],
			'current_pts' => $state['current_pts'],
			'selected_columns' => $state['selected_columns'],
			'source_type' => $state['source_type'],
			'csv_attachment_id' => $state['csv_attachment_id'],
			'csv_has_header' => $state['csv_has_header'],
			'csv_delimiter' => $state['csv_delimiter'],
			'should_show_source_hint' => $state['should_show_source_hint'],
			'custom_columns' => $state['custom_columns'],
			'custom_rows' => $state['custom_rows'],
			'custom_rows_count' => $state['custom_rows_count'],
			'custom_cols_count' => $state['custom_cols_count'],
		], $editor_values);
	}

	/**
	 * Visibility class for a control block that belongs to one or more data sources.
	 *
	 * $target may name several sources separated by spaces, matching the data-btbl-source
	 * attribute format (admin.js matches it with the ~= "contains word" selector). Shared by the
	 * General and Advanced tabs so the matching convention only has to change in one place.
	 */
	public static function source_hidden_class(string $target, string $source_type): string {
		return in_array($source_type, explode(' ', $target), true) ? '' : ' is-hidden';
	}

}
