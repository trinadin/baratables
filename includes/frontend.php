<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Frontend {
	private BaraTables_Service $service;
	private BaraTables_Chart_Service $chart_service;
	private BaraTables_Table_Presentation $table_presentation;
	private string $plugin_url;
	private string $plugin_path;
	private bool $assets_registered = false;
	private ?array $style_specs = null;
	private ?array $script_specs = null;

	public function __construct(BaraTables_Service $service, BaraTables_Chart_Service $chart_service, string $plugin_url, string $plugin_path) {
		$this->service = $service;
		$this->chart_service = $chart_service;
		$this->table_presentation = new BaraTables_Table_Presentation($service);
		$this->plugin_url = $plugin_url;
		$this->plugin_path = $plugin_path;
	}

	public function register_frontend_assets(): void {
		foreach ($this->get_style_specs() as $style) {
			wp_register_style($style['handle'], $style['src'], $style['deps'], $style['ver']);
		}

		foreach ($this->get_script_specs() as $script) {
			wp_register_script($script['handle'], $script['src'], $script['deps'], $script['ver'], $script['in_footer']);
		}

		$this->assets_registered = true;
		$this->enqueue_base_style_early();
	}

	/**
	 * Put the small base stylesheet in <head> when the page is going to render a table.
	 *
	 * Everything else is enqueued from render_shortcode(), i.e. during the_content -- long after
	 * wp_head -- so core prints it with the footer styles. That left the server-rendered table
	 * (up to rowLimit rows) painting completely unstyled first, and the .is-loading mask that is
	 * supposed to hide it until DataTables initializes is itself defined in that late CSS.
	 *
	 * Only the ~3KB base file moves; the DataTables/Select2 vendor CSS stays on-demand so pages
	 * without those features keep their current payload. This is best-effort by design: shortcodes
	 * coming from widgets, blocks or templates are not detected here and simply fall back to the
	 * existing late enqueue, exactly as before.
	 */
	private function enqueue_base_style_early(): void {
		if (is_admin() || !$this->content_in_main_query_has_table()) {
			return;
		}
		wp_enqueue_style('baratables');
	}

	private function content_in_main_query_has_table(): bool {
		$has = static function ($content): bool {
			$content = (string) $content;
			return $content !== ''
				&& (has_shortcode($content, 'bara_table') || has_shortcode($content, 'bara_chart'));
		};

		if (is_singular()) {
			$queried = get_queried_object();
			return $queried instanceof WP_Post && $has($queried->post_content);
		}

		$posts = $GLOBALS['wp_query']->posts ?? null;
		if (is_array($posts)) {
			foreach ($posts as $post) {
				if ($post instanceof WP_Post && $has($post->post_content)) {
					return true;
				}
			}
		}
		return false;
	}

	public function render_shortcode($atts): string {
		// WordPress < 6.5 passes shortcode_parse_atts('') as an empty STRING (not an
		// array) for an attribute-less [bara_table]; an `array` type hint would fatal
		// there. shortcode_atts() inside build_table_context() casts to array like core.
		$context = $this->build_table_context($atts);
		if (!$context) {
			return '<p>' . esc_html__('Table not found.', 'baratables') . '</p>';
		}

		if (empty($context['definition']['columns'])) {
			return '<p>' . esc_html__('No columns selected for this table.', 'baratables') . '</p>';
		}

		$this->enqueue_frontend_assets(true, false, $this->get_table_asset_features($context['definition']));

		$chart_options = $this->service->get_default_chart_options();
		$instance_id = $this->get_render_instance_id((string) ($context['definition']['id'] ?? 'table'));
		return $this->render_table_view($context['definition'], $context['rows'], $chart_options, false, $instance_id, true);
	}

	public function render_chart_shortcode($atts): string {
		// See render_shortcode(): pre-6.5 core may pass a string for an attribute-less
		// [bara_chart]; shortcode_atts() casts to array, so accept an untyped $atts.
		$atts = shortcode_atts(['id' => ''], $atts, 'bara_chart');
		$context = $this->chart_service->get_render_context($atts['id']);
		if (!$context) {
			return '<p>' . esc_html__('Chart not found.', 'baratables') . '</p>';
		}

		$definition = $context['table'] ?? [];
		$rows = $context['rows'] ?? [];
		$chart_definition = $context['chart'] ?? [];
		$chart_options = $context['chart_options'] ?? $this->service->get_default_chart_options();

		if (empty($definition['columns'])) {
			return '<p>' . esc_html__('No columns selected for the source table.', 'baratables') . '</p>';
		}

		$chart_enabled = BaraTables_Chart_Types::is_configured($chart_options);
		if (!$chart_enabled) {
			return '<p>' . esc_html__('Chart is not configured yet.', 'baratables') . '</p>';
		}

		$this->enqueue_frontend_assets(false, true);

		$instance_base = (string) ($chart_definition['id'] ?? $definition['id'] ?? $atts['id'] ?? 'chart');
		$instance_id = $this->get_render_instance_id('chart-' . $instance_base);
		// No edit link on the chart-only render: render_table_view only surfaces it on the table
		// path ($render_table), so resolving the chart's post id and edit link here was a dead
		// query on every chart view, for every visitor.
		return $this->render_table_view($definition, $rows, $chart_options, $chart_enabled, $instance_id, false);
	}

	private function get_render_instance_id(string $base): string {
		$base = sanitize_html_class(sanitize_title($base));
		if ($base === '') {
			$base = 'baratables';
		}
		// Deterministic per-render counter, NOT wp_unique_id(). wp_unique_id() is a request-global
		// counter shared with core blocks and every other plugin, so its value -- and therefore the
		// table's DOM id -- shifts whenever page composition changes. DataTables keys its saved state
		// by that DOM id, so a shifting id silently discards "Remember table state" between loads.
		// A plugin-local counter is stable across reloads of the same page (shortcodes render in a
		// fixed document order) while staying unique per shortcode instance on the page.
		static $counter = 0;
		$counter++;
		return $base . '-' . $counter;
	}

	/**
	 * Which optional front-end libraries a table actually needs.
	 *
	 * All three ship off by default -- `buttons` defaults to [], `colReorder` to false, and a
	 * column's `filter` to 'none' (Select2 is only ever instantiated for dropdown filters). They
	 * were previously enqueued on every table page regardless, which put roughly 265KB of
	 * unusable JS/CSS on a stock table: the Buttons family + JSZip, Select2, and ColReorder.
	 */
	private function get_table_asset_features(array $definition): array {
		$features = [];
		$options = $this->service->get_table_options($definition);

		if (!empty($options['buttons'])) {
			$features[] = 'buttons';
		}
		if (!empty($options['colReorder'])) {
			$features[] = 'colreorder';
		}
		foreach (($definition['columns'] ?? []) as $col) {
			$filter = is_array($col) && isset($col['filter']) ? (string) $col['filter'] : '';
			if ($filter === 'dropdown' || $filter === 'dropdown_multi') {
				$features[] = 'select2';
				break;
			}
		}

		return $features;
	}

	/**
	 * @param string[] $features Optional-library features to include (see get_table_asset_features).
	 *                           A spec carrying a 'feature' key is skipped unless it is listed here.
	 */
	private function enqueue_frontend_assets(bool $include_table, bool $include_chart, array $features = []): void {
		$this->ensure_assets_registered();

		$wanted = static function (array $spec) use ($include_table, $include_chart, $features): bool {
			if (!$include_table && !empty($spec['table_only'])) {
				return false;
			}
			if (!$include_chart && !empty($spec['chart_only'])) {
				return false;
			}
			// Specs tagged with a feature only load when that feature is actually configured.
			// Every member of a feature group is gated together, so the buttons.html5 -> jszip
			// dependency edge is preserved intact whenever the Buttons family does load.
			return empty($spec['feature']) || in_array($spec['feature'], $features, true);
		};

		foreach ($this->get_style_specs() as $style) {
			if ($wanted($style)) {
				wp_enqueue_style($style['handle']);
			}
		}

		foreach ($this->get_script_specs() as $script) {
			if ($wanted($script)) {
				wp_enqueue_script($script['handle']);
			}
		}
	}

	private function build_table_context($atts): ?array {
		$atts = shortcode_atts(['id' => ''], $atts, 'bara_table');
		$definition = $this->service->find_definition($atts['id'], true);
		if (!$definition) {
			return null;
		}
		$result = $this->service->get_row_result($definition);
		$definition = $this->service->definition_with_inferred_columns($definition, $result);
		return [
			'definition' => $definition,
			'rows'       => $result->rows(),
		];
	}

	private function ensure_assets_registered(): void {
		if (!$this->assets_registered) {
			$this->register_frontend_assets();
		}
	}

	private function get_style_specs(): array {
		if ($this->style_specs === null) {
			$this->style_specs = [
				$this->style_spec('baratables-datatables', 'assets/vendor/datatables/dataTables.dataTables.min.css', [], '2.3.8', ['table_only' => true]),
				$this->style_spec('baratables-datatables-buttons', 'assets/vendor/datatables/buttons.dataTables.min.css', ['baratables-datatables'], '3.2.6', ['table_only' => true, 'feature' => 'buttons']),
				$this->style_spec('baratables-datatables-colreorder', 'assets/vendor/datatables/colReorder.dataTables.min.css', ['baratables-datatables'], '2.1.2', ['table_only' => true, 'feature' => 'colreorder']),
				$this->style_spec('baratables-select2', 'assets/vendor/select2/select2.min.css', [], '4.1.0-rc.0', ['table_only' => true, 'feature' => 'select2']),
				$this->style_spec('baratables', 'assets/baratables.css'),
			];
		}
		return $this->style_specs;
	}

	private function get_script_specs(): array {
		if ($this->script_specs === null) {
			$this->script_specs = [
				$this->script_spec('baratables-utils', 'assets/baratables-utils.js'),
				$this->script_spec('baratables-datatables', 'assets/vendor/datatables/dataTables.min.js', ['jquery'], '2.3.8', ['table_only' => true]),
				$this->script_spec('baratables-datatables-buttons', 'assets/vendor/datatables/dataTables.buttons.min.js', ['baratables-datatables'], '3.2.6', ['table_only' => true, 'feature' => 'buttons']),
				// JSZip stays a hard dependency of buttons.html5 so the Excel button cannot
				// silently vanish. The whole Buttons family is feature-gated together.
				$this->script_spec('baratables-jszip', 'assets/vendor/jszip/jszip.min.js', [], '3.10.1', ['table_only' => true, 'feature' => 'buttons']),
				$this->script_spec('baratables-datatables-buttons-html5', 'assets/vendor/datatables/buttons.html5.min.js', ['baratables-datatables-buttons', 'baratables-jszip'], '3.2.6', ['table_only' => true, 'feature' => 'buttons']),
				$this->script_spec('baratables-datatables-buttons-print', 'assets/vendor/datatables/buttons.print.min.js', ['baratables-datatables-buttons'], '3.2.6', ['table_only' => true, 'feature' => 'buttons']),
				$this->script_spec('baratables-datatables-buttons-colvis', 'assets/vendor/datatables/buttons.colVis.min.js', ['baratables-datatables-buttons'], '3.2.6', ['table_only' => true, 'feature' => 'buttons']),
				$this->script_spec('baratables-datatables-colreorder', 'assets/vendor/datatables/dataTables.colReorder.min.js', ['baratables-datatables'], '2.1.2', ['table_only' => true, 'feature' => 'colreorder']),
				$this->script_spec('baratables-select2', 'assets/vendor/select2/select2.min.js', ['jquery'], '4.1.0-rc.0', ['table_only' => true, 'feature' => 'select2']),
				$this->script_spec('baratables-echarts', 'assets/vendor/echarts/echarts.min.js', [], '6.1.0', ['chart_only' => true]),
				$this->script_spec('baratables-charts', 'assets/baratables-charts.js', ['baratables-echarts'], null, ['chart_only' => true]),
				$this->script_spec('baratables-search', 'assets/baratables-search.js', ['jquery', 'baratables-utils'], null, ['table_only' => true]),
				$this->script_spec('baratables-filters', 'assets/baratables-filters.js', ['jquery', 'baratables-utils'], null, ['table_only' => true]),
				$this->script_spec('baratables-frontend', 'assets/baratables.js', ['jquery', 'baratables-utils']),
			];
		}
		return $this->script_specs;
	}

	private function style_spec(string $handle, string $relative, array $deps = [], ?string $version = null, array $flags = []): array {
		return array_merge([
			'handle' => $handle,
			'src' => $this->plugin_url . $relative,
			'deps' => $deps,
			'ver' => $version ?? BaraTables_Asset_Utils::get_asset_version($this->plugin_path, $relative),
		], $flags);
	}

	private function script_spec(string $handle, string $relative, array $deps = [], ?string $version = null, array $flags = []): array {
		return array_merge([
			'handle' => $handle,
			'src' => $this->plugin_url . $relative,
			'deps' => $deps,
			'ver' => $version ?? BaraTables_Asset_Utils::get_asset_version($this->plugin_path, $relative),
			'in_footer' => true,
		], $flags);
	}

	private function render_table_view(array $definition, array $rows, array $chart_options, bool $chart_enabled, string $table_id, bool $render_table): string {
		$this->prevent_access_control_caching($definition);

		// Only the table markup consumes $filters. A [bara_chart] render always passes
		// $render_table = false, so building them there walks every row, normalizes, decorates and
		// sorts each option, then throws the result away -- cheap on a small table, ~600ms on a
		// high-cardinality 10,000-row one.
		$filters = $render_table ? $this->service->build_filter_options($definition, $rows) : [];
		$presentation = $render_table ? $this->table_presentation->build($definition, $rows) : null;
		$allowed_inline = $render_table ? BaraTables_Service::allowed_inline_html() : [];
		$table_options = $render_table ? $presentation['options'] : $this->service->get_table_options($definition);
		$wrapper_compact_class = !empty($table_options['compact']) ? ' is-compact' : '';
		$table_class_attr = $render_table
			? implode(' ', array_merge(['btbl-table'], $presentation['style_classes']))
			: '';

		$this->enqueue_render_payload($this->build_render_payload(
			$definition,
			$rows,
			$chart_options,
			$chart_enabled,
			$render_table,
			$table_id,
			$presentation
		));

		ob_start();
		?>
		<div class="btbl-table-wrapper is-loading<?php echo !$render_table ? ' is-chart-only' : ''; ?><?php echo esc_attr($wrapper_compact_class); ?>" data-table-id="<?php echo esc_attr($table_id); ?>">
			<div class="btbl-loading-mask" role="status" aria-live="polite">
				<span class="btbl-spinner" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e('Loading table...', 'baratables'); ?></span>
			</div>
			<?php
			$chart_id = 'btbl-chart-' . $table_id;
			$chart_height = isset($chart_options['height']) ? (int) $chart_options['height'] : 360;
			$chart_style = $chart_height > 0 ? ' style="height:' . esc_attr((string) $chart_height) . 'px;"' : '';
			// Built once: the chart container is emitted from three mutually exclusive branches
			// (above the table, below it, and the chart-only render). Both interpolations are
			// escaped here, so the echo sites need no further escaping.
			$chart_div_html = '<div id="' . esc_attr($chart_id) . '" class="btbl-chart"' . $chart_style . '></div>';
			if ($chart_enabled && ($chart_options['position'] ?? 'above') === 'above') :
				?>
				<?php echo $chart_div_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: id via esc_attr(), height cast to int + esc_attr()d. ?>
			<?php endif; ?>
			<?php if ($render_table && !empty($filters)) { $this->render_filter_panel($filters, $table_options, $table_id, $allowed_inline); } ?>
			<?php if ($render_table) : ?>
				<div class="btbl-results-wrapper">
					<table id="btbl-table-<?php echo esc_attr($table_id); ?>" class="<?php echo esc_attr($table_class_attr); ?>">
						<thead>
							<tr>
								<?php foreach ($presentation['columns'] as $idx => $column_model) : ?>
									<?php $hidden_attr = $column_model['hidden'] ? ' style="display:none;"' : ''; ?>
									<?php $heading = wp_kses($column_model['heading'], $allowed_inline); ?>
									<th<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: hardcoded HTML attribute string. ?>><?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: value passed through wp_kses(). ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($rows as $row) : ?>
								<tr>
									<?php foreach ($presentation['columns'] as $idx => $column_model) : ?>
										<?php
										$hidden_attr = $column_model['hidden'] ? ' style="display:none;"' : '';
										$cell = (string) ($row[$idx] ?? '');
										// wp_kses_post() only rewrites markup ('<') and entities ('&'); a cell with
										// neither passes through byte-identical, so skipping the parse is safe. At the
										// 10,000-row ceiling most cells are plain scalars, so this avoids the bulk of
										// a measurable per-cell cost with no change to output.
										$cell_html = (strpbrk($cell, '<&') === false) ? $cell : wp_kses_post($cell);
										?>
										<td<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: hardcoded HTML attribute string. ?>><?php echo $cell_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- No '<'/'&' means raw == wp_kses_post(); otherwise wp_kses_post() ran. ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<div class="btbl-empty-state" aria-live="polite"><?php esc_html_e('No results match these filters.', 'baratables'); ?></div>
					<?php $edit_url = $this->resolve_admin_edit_url($definition, $table_id); ?>
					<?php if ($edit_url !== null) : ?>
						<div class="btbl-admin-tools" role="group" aria-label="<?php echo esc_attr__('Table admin tools', 'baratables'); ?>">
							<a class="button button-small btbl-edit-link" href="<?php echo esc_url($edit_url); ?>">
								<?php esc_html_e('Edit Table', 'baratables'); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php elseif ($chart_enabled && ($chart_options['position'] ?? 'above') === 'below') : ?>
				<?php echo $chart_div_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: id via esc_attr(), height cast to int + esc_attr()d. ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_filter_panel(array $filters, array $table_options, string $table_id, array $allowed_inline): void {
		$filters_title_enabled = !empty($table_options['filtersTitle']);
		$filters_title_text = isset($table_options['filtersTitleText']) && $table_options['filtersTitleText'] !== ''
			? $table_options['filtersTitleText']
			: __('Filters', 'baratables');
		?>
		<div class="btbl-filter-wrapper">
			<div class="btbl-filter-header">
				<?php if ($filters_title_enabled) : ?>
					<div class="btbl-filter-title"><?php echo wp_kses((string) $filters_title_text, $allowed_inline); ?></div>
				<?php endif; ?>
				<div class="btbl-filter-reset">
					<button type="button" class="btbl-reset-button button button-secondary"><?php esc_html_e('Clear filters', 'baratables'); ?></button>
				</div>
			</div>
			<?php foreach ($filters as $filter) : ?>
				<?php $this->render_filter_control($filter, $table_id, $allowed_inline); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_filter_control(array $filter, string $table_id, array $allowed_inline): void {
		$filter_label = isset($filter['label']) ? (string) $filter['label'] : '';
		$filter_label_id = $filter_label !== ''
			? 'btbl-filter-label-' . $table_id . '-' . $filter['column_index']
			: '';
		?>
		<div class="btbl-filter btbl-filter-<?php echo esc_attr($filter['type']); ?><?php echo esc_attr($filter['type'] === 'dropdown_multi' ? ' btbl-filter-dropdown-multi' : ''); ?>" data-column="<?php echo esc_attr($filter['column_index']); ?>" data-slug="<?php echo esc_attr($filter['slug']); ?>" data-type="<?php echo esc_attr($filter['type']); ?>">
			<?php if ($filter_label !== '') : ?>
				<div class="btbl-filter-label" id="<?php echo esc_attr($filter_label_id); ?>"><?php echo wp_kses($filter_label, $allowed_inline); ?></div>
			<?php endif; ?>
			<div class="btbl-filter-control-wrapper">
				<?php $this->render_filter_input($filter, $table_id, $filter_label_id); ?>
			</div>
		</div>
		<?php
	}

	private function render_filter_input(array $filter, string $table_id, string $filter_label_id): void {
		$configs = [
			'dropdown' => ['include_empty' => true, 'include_all' => true, 'placeholder' => __('All', 'baratables')],
			'dropdown_plain' => ['include_all' => true],
			'dropdown_multi' => ['include_empty' => true, 'multiple' => true, 'placeholder' => __('Select options', 'baratables')],
			'dropdown_plain_multi' => ['include_empty' => true, 'multiple' => true],
		];
		$type = (string) ($filter['type'] ?? '');
		if (isset($configs[$type])) {
			$config = $configs[$type];
			$config['labelledby'] = $filter_label_id;
			$this->render_filter_select($filter['options'], $config);
			return;
		}
		if ($type === 'checkbox') {
			$this->render_filter_option_inputs($filter['options'], 'checkbox', '', false, $filter_label_id);
		} elseif ($type === 'radio') {
			$this->render_filter_option_inputs(
				$filter['options'],
				'radio',
				'btbl-filter-' . $table_id . '-' . $filter['column_index'],
				true,
				$filter_label_id
			);
		}
	}

	private function resolve_admin_edit_url(array $definition, string $table_id): ?string {
		// Only administrators can edit this CPT. Gate before the definition lookup so anonymous
		// visitors and ordinary logged-in members never pay for its meta query.
		if (!is_user_logged_in() || !current_user_can('manage_options')) {
			return null;
		}
		$definition_id = $definition['id'] ?? $table_id;
		$post_id = $definition_id ? $this->service->get_definition_post_id($definition_id) : 0;
		if ($post_id && !current_user_can('edit_post', $post_id)) {
			return null;
		}
		return $post_id
			? get_edit_post_link($post_id, '')
			: admin_url('edit.php?post_type=' . BaraTables_Repository::CPT);
	}

	private function prevent_access_control_caching(array $definition): void {
		if (empty($definition['access_control'])) {
			return;
		}
		// DONOTCACHEPAGE is the de-facto cross-plugin contract page caches read. Row access control
		// makes this response visitor-specific, even on hosts configured to cache logged-in traffic.
		if (!defined('DONOTCACHEPAGE')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Established shared constant; the exact name is the contract.
			define('DONOTCACHEPAGE', true);
		}
		nocache_headers();
	}

	private function build_render_payload(array $definition, array $rows, array $chart_options, bool $chart_enabled, bool $render_table, string $table_id, ?array $presentation): array {
		$payload = $render_table ? [
			'tableId' => $table_id,
			'presetFilters' => $this->service->get_preset_filters(),
			'slugToIndex' => $presentation['slug_to_index'],
			'hiddenColumns' => $presentation['hidden_columns'],
			'nonSortable' => $presentation['non_sortable'],
			'tableOptions' => $presentation['options'],
			'tableClasses' => $presentation['style_classes'],
			'compact' => !empty($presentation['raw_options']['compact']),
			'nonSearchable' => $presentation['non_searchable'],
			'defaultOrder' => $presentation['default_sort'],
			'presetSearch' => $this->service->get_preset_search(),
			'chartOnly' => false,
		] : [
			'tableId' => $table_id,
			'slugToIndex' => $this->service->map_column_slug_to_index($definition),
			'chartOnly' => true,
		];
		if (!$chart_enabled) {
			return $payload;
		}

		// Ship only the columns the chart plots; rows are re-indexed with a matching slug map.
		$projection = $this->project_rows_for_chart($rows, $definition['columns'], $chart_options);
		$type_capabilities = BaraTables_Chart_Types::get((string) ($chart_options['type'] ?? 'bar'));
		$payload['chart'] = array_merge($chart_options, [
			'enabled' => true,
			'mode' => $type_capabilities['mode'],
			'rows' => $projection['rows'],
			'row_slug_index' => $projection['slug_index'],
			'columns' => $this->service->build_column_slug_label_list($definition['columns']),
		]);
		return $payload;
	}

	private function enqueue_render_payload(array $payload): void {
		$config = wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if (!$config) {
			return;
		}
		wp_add_inline_script(
			'baratables-frontend',
			'window.BaraTablesFrontendQueue = window.BaraTablesFrontendQueue || []; window.BaraTablesFrontendQueue.push(' . $config . ');',
			'before'
		);
	}

	/**
	 * Narrow the inline chart payload to the columns the chart actually plots.
	 *
	 * @return array{rows: array<int, array<int, mixed>>, slug_index: ?array<string, int>}
	 *         Rows re-indexed to the kept columns, plus the slug => new-index map for them.
	 *         Falls back to the untouched rows and a null override if nothing can be narrowed,
	 *         so a chart referencing an unknown slug still behaves exactly as before.
	 */
	private function project_rows_for_chart(array $rows, array $columns, array $chart_options): array {
		$wanted = array_fill_keys(BaraTables_Chart_Types::referenced_columns($chart_options), true);

		$keep = [];
		$slug_index = [];
		foreach ($this->service->column_slugs_in_order($columns) as $idx => $slug) {
			if ($slug !== '' && isset($wanted[$slug]) && !isset($slug_index[$slug])) {
				$slug_index[$slug] = count($keep);
				$keep[] = $idx;
			}
		}

		// Nothing matched (unknown slugs, or a definition whose columns are not resolvable):
		// keep the original payload rather than shipping a chart that cannot find its data.
		if (empty($keep) || count($keep) >= count($columns)) {
			return ['rows' => $rows, 'slug_index' => null];
		}

		$projected = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$new_row = [];
			foreach ($keep as $idx) {
				$new_row[] = $row[$idx] ?? '';
			}
			$projected[] = $new_row;
		}

		return ['rows' => $projected, 'slug_index' => $slug_index];
	}

	private function render_filter_select(array $options, array $args = []): void {
		$include_empty = !empty($args['include_empty']);
		$include_all = !empty($args['include_all']);
		$multiple = !empty($args['multiple']);
		$placeholder = $args['placeholder'] ?? '';
		$all_label = $args['all_label'] ?? __('All', 'baratables');
		// Without this the control has no accessible name -- a screen reader announces every filter
		// on the table identically as just "combo box". Points at the visible filter heading.
		$labelledby = isset($args['labelledby']) ? (string) $args['labelledby'] : '';
		$labelledby_attr = $labelledby !== '' ? ' aria-labelledby="' . esc_attr($labelledby) . '"' : '';
		?>
		<select class="btbl-filter-control"<?php echo $multiple ? ' multiple' : ''; ?><?php echo $labelledby_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: value passed through esc_attr(). ?><?php echo $placeholder !== '' ? ' data-placeholder="' . esc_attr($placeholder) . '"' : ''; ?>>
			<?php if ($include_empty) : ?>
				<option value=""></option>
			<?php endif; ?>
			<?php if ($include_all) : ?>
				<option value="__all"><?php echo esc_html($all_label); ?></option>
			<?php endif; ?>
			<?php $this->render_filter_option_tags($options); ?>
		</select>
		<?php
	}

	private function render_filter_option_tags(array $options): void {
		foreach ($options as $opt) {
			?>
			<option value="<?php echo esc_attr($opt['value']); ?>" data-search-terms="<?php echo esc_attr(wp_json_encode($opt['search_terms'])); ?>"><?php echo esc_html($opt['label']); ?></option>
			<?php
		}
	}

	private function render_filter_option_inputs(array $options, string $input_type, string $name = '', bool $include_all = false, string $labelledby = ''): void {
		$name_attr = $name !== '' ? ' name="' . esc_attr($name) . '"' : '';
		// role=group + a name so the set of checkboxes/radios is announced as "<filter>, group"
		// rather than as loose controls with no indication of which filter they belong to.
		$group_attr = $labelledby !== ''
			? ' role="group" aria-labelledby="' . esc_attr($labelledby) . '"'
			: '';
		?>
		<div class="btbl-filter-options"<?php echo $group_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: value passed through esc_attr(). ?>>
			<?php if ($include_all) : ?>
				<label>
					<input type="<?php echo esc_attr($input_type); ?>"<?php echo $name_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: name value passed through esc_attr(). ?> value="__all" checked />
					<?php esc_html_e('All', 'baratables'); ?>
				</label>
			<?php endif; ?>
			<?php foreach ($options as $opt) : ?>
					<label>
					<input type="<?php echo esc_attr($input_type); ?>"<?php echo $name_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: name value passed through esc_attr(). ?> value="<?php echo esc_attr($opt['value']); ?>" data-search-terms="<?php echo esc_attr(wp_json_encode($opt['search_terms'])); ?>" />
					<?php echo esc_html($opt['label']); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
