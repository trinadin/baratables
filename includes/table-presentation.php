<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Shared, render-agnostic presentation state for the public table and the static admin preview.
 */
final class BaraTables_Table_Presentation {
	private BaraTables_Service $service;

	public function __construct(BaraTables_Service $service) {
		$this->service = $service;
	}

	public function build(array $definition, array $rows, bool $include_preview = false): array {
		$columns = isset($definition['columns']) && is_array($definition['columns']) ? array_values($definition['columns']) : [];
		$options_raw = $this->service->get_table_options($definition);
		$options = $this->service->localize_frontend_table_labels($options_raw);
		$slug_to_index = $this->service->map_column_slug_to_index($definition);
		$default_sort = $this->service->get_default_sort_order($definition);
		$sorted_columns = $include_preview ? $this->build_sorted_columns($default_sort, $slug_to_index, $options_raw) : [];
		// Header arrows mirror DataTables, which marks the ordered column whenever sorting is on
		// and a default sort exists -- independent of the "Highlight sorted column" toggle that
		// only backgrounds the cells. Without the split, turning the highlight off also removed
		// the preview's arrows while the live table kept them.
		$arrow_columns = $include_preview
			? $this->build_sorted_columns($default_sort, $slug_to_index, ['orderColumn' => true, 'ordering' => !empty($options_raw['ordering'])])
			: [];
		$column_state = $this->build_column_models($columns, $definition, $arrow_columns);

		$presentation = [
			'options' => $options,
			'raw_options' => $options_raw,
			'style_classes' => BaraTables_Service::table_style_classes($options_raw),
			'columns' => $column_state['models'],
			'sorted_columns' => $sorted_columns,
			'slug_to_index' => $slug_to_index,
			'hidden_columns' => $column_state['hidden'],
			'non_sortable' => $column_state['non_sortable'],
			'non_searchable' => $column_state['non_searchable'],
			'default_sort' => $default_sort,
		];
		if (!$include_preview) {
			return $presentation;
		}

		$page_length = isset($options_raw['pageLength']) ? (int) $options_raw['pageLength'] : 10;
		$preview_rows = !empty($options_raw['paging']) && $page_length > 0
			? array_slice($rows, 0, $page_length)
			: $rows;
		$info_text = $this->build_info_text($options_raw, $options, count($preview_rows), count($rows));
		return array_merge($presentation, [
			'preview_rows' => $preview_rows,
			'layout_zones' => [
				'topStart' => $options_raw['layoutTopStart'] ?? [],
				'topEnd' => $options_raw['layoutTopEnd'] ?? [],
				'bottomStart' => $options_raw['layoutBottomStart'] ?? [],
				'bottomEnd' => $options_raw['layoutBottomEnd'] ?? [],
			],
			'layout_controls' => [
				'pagelength' => !empty($options_raw['lengthChange']),
				'search' => !empty($options_raw['searchBox']),
				'buttons' => !empty($options_raw['buttons']),
				'info' => !empty($options_raw['info']) && $info_text !== '',
				'paging' => !empty($options_raw['paging']),
			],
			'info_text' => $info_text,
			'search_label' => (string) ($options['searchText'] ?? ''),
			'search_placeholder' => (string) ($options_raw['searchPlaceholder'] ?? ''),
			'length_prefix' => (string) ($options['lengthMenuPrefix'] ?? ''),
			'length_suffix' => (string) ($options['lengthMenuSuffix'] ?? ''),
			'page_length' => $page_length,
			'length_choices' => $this->length_choices($page_length),
			'button_labels' => $this->build_button_labels($options),
			'paginate_labels' => $this->build_paginate_labels($options_raw),
		]);
	}

	private function build_sorted_columns(array $default_sort, array $slug_to_index, array $options): array {
		if (empty($options['orderColumn']) || empty($options['ordering'])) {
			return [];
		}
		$sorted = [];
		foreach ($this->resolve_sort_rules($default_sort, $slug_to_index) as $rule) {
			if (!array_key_exists($rule['index'], $sorted)) {
				$sorted[$rule['index']] = $rule['direction'];
			}
		}
		return $sorted;
	}

	private function build_info_text(array $raw_options, array $options, int $display_rows, int $total_rows): string {
		if (empty($raw_options['info'])) {
			return '';
		}
		$template = $display_rows > 0 ? (string) $options['infoText'] : (string) $options['infoEmpty'];
		return str_replace(
			['_START_', '_END_', '_TOTAL_', '_MAX_'],
			[$display_rows > 0 ? 1 : 0, $display_rows, $total_rows, $total_rows],
			$template
		);
	}

	private function build_button_labels(array $options): array {
		$schema = BaraTables_Service::get_table_option_schema();
		$text_options = isset($schema['buttons']['choice_text_options']) && is_array($schema['buttons']['choice_text_options'])
			? $schema['buttons']['choice_text_options']
			: [];
		$labels = [];
		foreach ($text_options as $button => $option_key) {
			$labels[$button] = (string) ($options[$option_key] ?? '');
		}
		return $labels;
	}

	private function build_paginate_labels(array $raw_options): array {
		$labels = [];
		foreach (BaraTables_Service::paginate_glyph_defaults() as $option_key => $fallback) {
			$key = lcfirst(substr($option_key, strlen('paginate')));
			$custom = (string) ($raw_options[$option_key] ?? '');
			$labels[$key] = $custom !== '' ? $custom : $fallback;
		}
		return $labels;
	}

	private function build_column_models(array $columns, array $definition, array $sorted_columns): array {
		$models = [];
		$non_searchable = [];
		$hidden = [];
		$non_sortable = [];
		foreach ($columns as $index => $column) {
			if (!is_array($column)) {
				continue;
			}
			if (array_key_exists('searchable', $column) && $column['searchable'] === false) {
				$non_searchable[] = $index;
			}
			if (!empty($column['hidden'])) {
				$hidden[] = $index;
			}
			if (isset($column['sortable']) && $column['sortable'] === false) {
				$non_sortable[] = $index;
			}
			$models[$index] = [
				'hidden' => !empty($column['hidden']),
				'heading' => !empty($column['hide_title'])
					? '&nbsp;'
					: $this->service->display_column_label($column, $index, (string) ($definition['source_type'] ?? '')),
				'sort_direction' => $sorted_columns[$index] ?? null,
			];
		}
		return [
			'models' => $models,
			'hidden' => $hidden,
			'non_sortable' => $non_sortable,
			'non_searchable' => $non_searchable,
		];
	}

	public function sort_rules(array $definition): array {
		return $this->resolve_sort_rules(
			$this->service->get_default_sort_order($definition),
			$this->service->map_column_slug_to_index($definition)
		);
	}

	private function resolve_sort_rules(array $default_sort, array $slug_to_index): array {
		$resolved = [];
		foreach ($default_sort as $rule) {
			$slug = (string) ($rule['slug'] ?? '');
			if (!array_key_exists($slug, $slug_to_index)) {
				continue;
			}
			$resolved[] = [
				'index' => $slug_to_index[$slug],
				'direction' => ($rule['direction'] ?? '') === 'desc' ? 'desc' : 'asc',
			];
		}
		return $resolved;
	}

	private function length_choices(int $page_length): array {
		$choices = array_unique(array_filter([$page_length, 10, 25, 50, 100]));
		sort($choices);
		return $choices;
	}
}
