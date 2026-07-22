<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Frontend {
	private BaraTables_Service $service;
	private BaraTables_Chart_Service $chart_service;
	private string $plugin_url;
	private string $plugin_path;
	private bool $assets_registered = false;

	public function __construct(BaraTables_Service $service, BaraTables_Chart_Service $chart_service, string $plugin_url, string $plugin_path) {
		$this->service = $service;
		$this->chart_service = $chart_service;
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
	}

	public function render_shortcode($atts): string {
		// WordPress < 6.5 passes shortcode_parse_atts('') as an empty STRING (not an
		// array) for an attribute-less [bara_table]; an `array` type hint would fatal
		// there. shortcode_atts() inside build_table_context() casts to array like core.
		$context = $this->build_table_context($atts);
		if (!$context) {
			return '<p>' . esc_html__('Table not found.', 'baratables') . '</p>';
		}

		$this->enqueue_frontend_assets(true, false, $this->get_table_asset_features($context['definition']));

		if (empty($context['definition']['columns'])) {
			return '<p>' . esc_html__('No columns selected for this table.', 'baratables') . '</p>';
		}

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

		$definition = $this->hydrate_definition_columns($context['table'] ?? []);
		$rows = $context['rows'] ?? [];
		$chart_definition = $context['chart'] ?? [];
		$chart_options = $context['chart_options'] ?? $this->service->get_default_chart_options();

		if (empty($definition['columns'])) {
			return '<p>' . esc_html__('No columns selected for the source table.', 'baratables') . '</p>';
		}

		$chart_type = $chart_options['type'] ?? 'bar';
		$chart_enabled = false;
		if ($chart_type === 'gantt') {
			$chart_enabled = !empty($chart_options['gantt_label']) && !empty($chart_options['gantt_start']) && !empty($chart_options['gantt_end']);
		} else {
			$chart_enabled = !empty($chart_options['x_axis']) && !empty($chart_options['series']);
		}
		if (!$chart_enabled) {
			return '<p>' . esc_html__('Chart is not configured yet.', 'baratables') . '</p>';
		}

		$this->enqueue_frontend_assets(false, true);

		$render_table = false;
		$instance_base = (string) ($chart_definition['id'] ?? $definition['id'] ?? $atts['id'] ?? 'chart');
		$instance_id = $this->get_render_instance_id('chart-' . $instance_base);
		// No edit link on the chart-only render: render_table_view only surfaces it on the table
		// path ($render_table), so computing get_chart_post_id()/get_edit_post_link() here was a
		// dead query on every chart view, for every visitor.
		return $this->render_table_view($definition, $rows, $chart_options, $chart_enabled, $instance_id, $render_table);
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
			if (!empty($spec['feature']) && !in_array($spec['feature'], $features, true)) {
				return false;
			}
			return true;
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
		$rows = $this->service->get_rows($definition);
		$definition = $this->hydrate_definition_columns($definition);
		return [
			'definition' => $definition,
			'rows'       => $rows,
		];
	}

	private function hydrate_definition_columns(array $definition): array {
		return $this->service->ensure_columns_inferred($definition);
	}

	private function ensure_assets_registered(): void {
		if (!$this->assets_registered) {
			$this->register_frontend_assets();
		}
	}

	private function get_style_specs(): array {
		return [
			[
				'handle' => 'baratables-datatables',
				'src'    => $this->plugin_url . 'assets/vendor/datatables/dataTables.dataTables.min.css',
				'deps'   => [],
				'ver'    => '2.3.8',
				'table_only' => true,
			],
			[
				'handle' => 'baratables-datatables-buttons',
				'src'    => $this->plugin_url . 'assets/vendor/datatables/buttons.dataTables.min.css',
				'deps'   => ['baratables-datatables'],
				'ver'    => '3.2.6',
				'table_only' => true,
				'feature'    => 'buttons',
			],
			[
				'handle' => 'baratables-datatables-colreorder',
				'src'    => $this->plugin_url . 'assets/vendor/datatables/colReorder.dataTables.min.css',
				'deps'   => ['baratables-datatables'],
				'ver'    => '2.1.2',
				'table_only' => true,
				'feature'    => 'colreorder',
			],
			[
				'handle' => 'baratables-select2',
				'src'    => $this->plugin_url . 'assets/vendor/select2/select2.min.css',
				'deps'   => [],
				'ver'    => '4.1.0-rc.0',
				'table_only' => true,
				'feature'    => 'select2',
			],
			[
				'handle' => 'baratables',
				'src'    => $this->plugin_url . 'assets/baratables.css',
				'deps'   => [],
				'ver'    => BaraTables_Asset_Utils::get_asset_version($this->plugin_path, 'assets/baratables.css'),
			],
		];
	}

	private function get_script_specs(): array {
		return [
			[
				'handle'    => 'baratables-datatables',
				'src'       => $this->plugin_url . 'assets/vendor/datatables/dataTables.min.js',
				'deps'      => ['jquery'],
				'ver'       => '2.3.8',
				'in_footer' => true,
				'table_only' => true,
			],
			[
				'handle'    => 'baratables-datatables-buttons',
				'src'       => $this->plugin_url . 'assets/vendor/datatables/dataTables.buttons.min.js',
				'deps'      => ['baratables-datatables'],
				'ver'       => '3.2.6',
				'in_footer' => true,
				'table_only' => true,
				'feature'    => 'buttons',
			],
			[
				// JSZip is required by DataTables' excelHtml5 button and stays a hard dependency of
				// buttons.html5 (below) so it always loads before the button's availability check runs.
				// It is tagged with the same 'buttons' feature as the rest of the family, so the whole
				// group is enqueued or skipped together and the dependency edge is never broken -- the
				// Excel button cannot silently vanish. It still loads whenever ANY button is configured,
				// even if Excel is not among them; splitting it out would mean re-guaranteeing load
				// order another way for ~95KB, which is not worth that regression risk.
				'handle'    => 'baratables-jszip',
				'src'       => $this->plugin_url . 'assets/vendor/jszip/jszip.min.js',
				'deps'      => [],
				'ver'       => '3.10.1',
				'in_footer' => true,
				'table_only' => true,
				'feature'    => 'buttons',
			],
			[
				'handle'    => 'baratables-datatables-buttons-html5',
				'src'       => $this->plugin_url . 'assets/vendor/datatables/buttons.html5.min.js',
				'deps'      => ['baratables-datatables-buttons', 'baratables-jszip'],
				'ver'       => '3.2.6',
				'in_footer' => true,
				'table_only' => true,
				'feature'    => 'buttons',
			],
			[
				'handle'    => 'baratables-datatables-buttons-print',
				'src'       => $this->plugin_url . 'assets/vendor/datatables/buttons.print.min.js',
				'deps'      => ['baratables-datatables-buttons'],
				'ver'       => '3.2.6',
				'in_footer' => true,
				'table_only' => true,
				'feature'    => 'buttons',
			],
			[
				'handle'    => 'baratables-datatables-buttons-colvis',
				'src'       => $this->plugin_url . 'assets/vendor/datatables/buttons.colVis.min.js',
				'deps'      => ['baratables-datatables-buttons'],
				'ver'       => '3.2.6',
				'in_footer' => true,
				'table_only' => true,
				'feature'    => 'buttons',
			],
			[
				'handle'    => 'baratables-datatables-colreorder',
				'src'       => $this->plugin_url . 'assets/vendor/datatables/dataTables.colReorder.min.js',
				'deps'      => ['baratables-datatables'],
				'ver'       => '2.1.2',
				'in_footer' => true,
				'table_only' => true,
				'feature'    => 'colreorder',
			],
			[
				'handle'    => 'baratables-select2',
				'src'       => $this->plugin_url . 'assets/vendor/select2/select2.min.js',
				'deps'      => ['jquery'],
				'ver'       => '4.1.0-rc.0',
				'in_footer' => true,
				'table_only' => true,
				'feature'    => 'select2',
			],
			[
				'handle'    => 'baratables-echarts',
				'src'       => $this->plugin_url . 'assets/vendor/echarts/echarts.min.js',
				'deps'      => [],
				'ver'       => '6.1.0',
				'in_footer' => true,
				'chart_only' => true,
			],
			[
				'handle'    => 'baratables-frontend',
				'src'       => $this->plugin_url . 'assets/baratables.js',
				'deps'      => ['jquery'],
				'ver'       => BaraTables_Asset_Utils::get_asset_version($this->plugin_path, 'assets/baratables.js'),
				'in_footer' => true,
			],
		];
	}

	private function render_table_view(array $definition, array $rows, array $chart_options, bool $chart_enabled, string $instance_id, bool $render_table): string {
		// Only the table markup consumes $filters. A [bara_chart] render always passes
		// $render_table = false, so building them there walks every row, normalizes, decorates and
		// sorts each option, then throws the result away -- cheap on a small table, ~600ms on a
		// high-cardinality 10,000-row one.
		$filters = $render_table ? $this->service->build_filter_options($definition, $rows) : [];
		$table_id = $instance_id;
		$preset_filters = $this->service->get_preset_filters();
		$preset_search = $this->service->get_preset_search();
		$slug_to_index = $this->service->map_column_slug_to_index($definition);
		$hidden_columns = $this->service->get_hidden_column_indices($definition);
		$non_sortable = $this->service->get_non_sortable_indices($definition);
		$non_searchable = [];
		$allowed_inline = BaraTables_Service::allowed_inline_html();
		foreach ($definition['columns'] as $idx => $col) {
			if (isset($col['searchable']) && $col['searchable'] === false) {
				$non_searchable[] = $idx;
			}
		}
		$default_sort = $this->service->get_default_sort_order($definition);
		$table_options = $this->service->localize_frontend_table_labels($this->service->get_table_options($definition));
		$wrapper_compact_class = !empty($table_options['compact']) ? ' is-compact' : '';
		$table_classes = ['btbl-table'];
		foreach (BaraTables_Service::TABLE_STYLE_CLASS_MAP as $option_key => $class_name) {
			if (!empty($table_options[$option_key])) {
				$table_classes[] = $class_name;
			}
		}
		$table_class_attr = implode(' ', $table_classes);

		$inline_payload = [
			'tableId'       => $table_id,
			'presetFilters' => $preset_filters,
			'slugToIndex'   => $slug_to_index,
			'hiddenColumns' => $hidden_columns,
			'nonSortable'   => $non_sortable,
			'tableOptions'  => $table_options,
			'nonSearchable' => $non_searchable,
			'defaultOrder'  => $default_sort,
			'presetSearch'  => $preset_search,
			'chartOnly'     => $chart_enabled && !$render_table,
		];

		if ($chart_enabled) {
			$inline_payload['chart'] = array_merge($chart_options, [
				'enabled' => $chart_enabled,
				'rows' => $rows,
				'columns' => $this->service->build_column_slug_label_list($definition['columns']),
			]);
		}

		$inline_config = wp_json_encode($inline_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		if ($inline_config) {
			wp_add_inline_script(
				'baratables-frontend',
				'window.BaraTablesFrontendQueue = window.BaraTablesFrontendQueue || []; window.BaraTablesFrontendQueue.push(' . $inline_config . ');',
				'before'
			);
		}

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
			if ($chart_enabled && ($chart_options['position'] ?? 'above') === 'above') :
				?>
				<div id="<?php echo esc_attr($chart_id); ?>" class="btbl-chart"<?php echo $chart_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: height value is cast to int and passed through esc_attr(). ?>></div>
			<?php endif; ?>
			<?php if ($render_table && !empty($filters)) : ?>
				<?php
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
						<div class="btbl-filter btbl-filter-<?php echo esc_attr($filter['type']); ?><?php echo esc_attr($filter['type'] === 'dropdown_multi' ? ' btbl-filter-dropdown-multi' : ''); ?>" data-column="<?php echo esc_attr($filter['column_index']); ?>" data-slug="<?php echo esc_attr($filter['slug']); ?>" data-type="<?php echo esc_attr($filter['type']); ?>">
							<?php $filter_label = isset($filter['label']) ? (string) $filter['label'] : ''; ?>
							<?php if ($filter_label !== '') : ?>
								<div class="btbl-filter-label"><?php echo wp_kses($filter_label, $allowed_inline); ?></div>
							<?php endif; ?>
							<div class="btbl-filter-control-wrapper">
								<?php if ($filter['type'] === 'dropdown') : ?>
									<?php $this->render_filter_select($filter['options'], [
										'include_empty' => true,
										'include_all' => true,
										'placeholder' => __('All', 'baratables'),
									]); ?>
								<?php elseif ($filter['type'] === 'dropdown_plain') : ?>
									<?php $this->render_filter_select($filter['options'], [
										'include_all' => true,
									]); ?>
								<?php elseif ($filter['type'] === 'dropdown_multi') : ?>
									<?php $this->render_filter_select($filter['options'], [
										'include_empty' => true,
										'multiple' => true,
										'placeholder' => __('Select options', 'baratables'),
									]); ?>
								<?php elseif ($filter['type'] === 'dropdown_plain_multi') : ?>
									<?php $this->render_filter_select($filter['options'], [
										'include_empty' => true,
										'multiple' => true,
									]); ?>
								<?php elseif ($filter['type'] === 'checkbox') : ?>
									<?php $this->render_filter_option_inputs($filter['options'], 'checkbox'); ?>
								<?php elseif ($filter['type'] === 'radio') : ?>
									<?php $this->render_filter_option_inputs(
										$filter['options'],
										'radio',
										'btbl-filter-' . $table_id . '-' . $filter['column_index'],
										true
									); ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ($render_table) : ?>
				<div class="btbl-results-wrapper">
					<table id="btbl-table-<?php echo esc_attr($table_id); ?>" class="<?php echo esc_attr($table_class_attr); ?>">
						<thead>
							<tr>
								<?php foreach ($definition['columns'] as $idx => $col) : ?>
									<?php $hidden_attr = !empty($col['hidden']) ? ' style="display:none;"' : ''; ?>
									<?php $heading = !empty($col['hide_title']) ? '&nbsp;' : wp_kses($this->service->display_column_label($col, (int) $idx, (string) ($definition['source_type'] ?? '')), $allowed_inline); ?>
									<th<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: hardcoded HTML attribute string. ?>><?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: value passed through wp_kses(). ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($rows as $row) : ?>
								<tr>
									<?php foreach ($definition['columns'] as $idx => $col) : ?>
										<?php
										$hidden_attr = !empty($col['hidden']) ? ' style="display:none;"' : '';
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
					<?php if ($chart_enabled && ($chart_options['position'] ?? 'above') === 'below') : ?>
						<div id="<?php echo esc_attr($chart_id); ?>" class="btbl-chart"<?php echo $chart_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: height value is cast to int and passed through esc_attr(). ?>></div>
					<?php endif; ?>
					<div class="btbl-empty-state" aria-live="polite"><?php esc_html_e('No results match these filters.', 'baratables'); ?></div>
					<?php
					// Only logged-in users can ever have an edit capability, so skip the
					// get_definition_post_id() meta_query for anonymous visitors (the common case
					// for a public table) instead of running it on every render.
					$post_id = 0;
					$can_edit = false;
					if (is_user_logged_in()) {
						$defn_id = $definition['id'] ?? $table_id;
						$post_id = $defn_id ? $this->service->get_definition_post_id($defn_id) : 0;
						$can_edit = $post_id ? current_user_can('edit_post', $post_id) : current_user_can('manage_options');
					}
					?>
					<?php if ($can_edit) : ?>
						<div class="btbl-admin-tools" role="group" aria-label="<?php echo esc_attr__('Table admin tools', 'baratables'); ?>">
							<?php
							$edit_url = $post_id
								? get_edit_post_link($post_id, '')
								: admin_url('edit.php?post_type=' . BaraTables_Repository::CPT);
							?>
							<a class="button button-small btbl-edit-link" href="<?php echo esc_url($edit_url); ?>">
								<?php esc_html_e('Edit Table', 'baratables'); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php elseif ($chart_enabled && ($chart_options['position'] ?? 'above') === 'below') : ?>
				<div id="<?php echo esc_attr($chart_id); ?>" class="btbl-chart"<?php echo $chart_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: height value is cast to int and passed through esc_attr(). ?>></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function prepare_filter_option($option): array {
		return $this->service->normalize_filter_option($option);
	}

	private function render_filter_select(array $options, array $args = []): void {
		$include_empty = !empty($args['include_empty']);
		$include_all = !empty($args['include_all']);
		$multiple = !empty($args['multiple']);
		$placeholder = $args['placeholder'] ?? '';
		$all_label = $args['all_label'] ?? __('All', 'baratables');
		?>
		<select class="btbl-filter-control"<?php echo $multiple ? ' multiple' : ''; ?><?php echo $placeholder !== '' ? ' data-placeholder="' . esc_attr($placeholder) . '"' : ''; ?>>
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
		foreach ($options as $option) {
			$opt = $this->prepare_filter_option($option);
			?>
			<option value="<?php echo esc_attr($opt['value']); ?>" data-search-terms="<?php echo esc_attr(wp_json_encode($opt['search_terms'])); ?>"><?php echo esc_html($opt['label']); ?></option>
			<?php
		}
	}

	private function render_filter_option_inputs(array $options, string $input_type, string $name = '', bool $include_all = false): void {
		$name_attr = $name !== '' ? ' name="' . esc_attr($name) . '"' : '';
		?>
		<div class="btbl-filter-options">
			<?php if ($include_all) : ?>
				<label>
					<input type="<?php echo esc_attr($input_type); ?>"<?php echo $name_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: name value passed through esc_attr(). ?> value="__all" checked />
					<?php esc_html_e('All', 'baratables'); ?>
				</label>
			<?php endif; ?>
			<?php foreach ($options as $option) : ?>
				<label>
					<?php $opt = $this->prepare_filter_option($option); ?>
					<input type="<?php echo esc_attr($input_type); ?>"<?php echo $name_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: name value passed through esc_attr(). ?> value="<?php echo esc_attr($opt['value']); ?>" data-search-terms="<?php echo esc_attr(wp_json_encode($opt['search_terms'])); ?>" />
					<?php echo esc_html($opt['label']); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
