<?php

/**
 * The chart editor's tab renderers, extracted from the table editor's ui.php:
 * the two editors share the tab shape but own disjoint control sets.
 */

if (!defined('ABSPATH')) {
	exit;
}


class BaraTables_Admin_Tab_Chart {
	public function render(array $context, array $column_choices): void {
		$chart_options = $context['chart_options'] ?? [];
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$table_choices = $context['table_choices'] ?? [];
		$selected_table = $context['selected_table'] ?? '';
		$panel_class = $active_tab === 'btbl-tab-chart' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
			static $assets = null;
			if ($assets === null) {
				$plugin_file = dirname(__DIR__, 2) . '/baratables.php';
				$assets = [
					'dir' => plugin_dir_path($plugin_file),
					'url' => plugin_dir_url($plugin_file),
					'placeholder' => 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" viewBox="0 0 320 200"><rect width="320" height="200" fill="#f7f8fa"/><rect x="32" y="36" width="28" height="128" fill="#d7dde5"/><rect x="78" y="76" width="28" height="88" fill="#d7dde5"/><rect x="124" y="52" width="28" height="112" fill="#d7dde5"/><rect x="170" y="92" width="28" height="72" fill="#d7dde5"/><rect x="216" y="116" width="28" height="48" fill="#d7dde5"/><rect x="262" y="64" width="28" height="96" fill="#d7dde5"/><text x="160" y="186" text-anchor="middle" font-size="14" fill="#94a3b8" font-family="Arial, sans-serif">Preview coming soon</text></svg>'),
				];
			}
			$chart_types = BaraTables_Chart_Types::all();
			// The visible label AND the control's own accessible name come from one string,
			// so the two can never drift apart.
			//
			// The name has to live on the control itself, not only on the <label for>. The
			// chart editor's table picker is enhanced with the bundled Tom Select, which
			// hides the original <select> -- it stays in the accessibility tree, clipped to
			// nothing -- and re-points the label's `for` at its own widget. A name that
			// lived only on the label was therefore taken away from the original control,
			// leaving a combobox with no accessible name behind. Stating the name on the
			// control survives that rewrite, and Tom Select copies it onto the widget it
			// shows in its place.
			$table_label = __('Table', 'baratables');
			$chart_type_label = __('Chart type', 'baratables');
			?>
		<div id="btbl-tab-chart" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-chart-label">
			<?php $dropped_columns = $context['dropped_columns'] ?? []; ?>
			<?php if (!empty($dropped_columns)) : ?>
				<div class="notice notice-warning inline btbl-dropped-columns">
					<p><?php echo esc_html(sprintf(
						/* translators: %s is a comma-separated list of column references. */
						__('These chart columns no longer exist and were cleared: %s. Pick replacements below, then update.', 'baratables'),
						implode(', ', $dropped_columns)
					)); ?></p>
				</div>
			<?php endif; ?>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_chart_table"><?php echo esc_html($table_label); ?></label>
				<select name="btbl_chart_table" id="btbl_chart_table" aria-label="<?php echo esc_attr($table_label); ?>" data-switch-confirm="<?php echo esc_attr__('Switching tables will reset the column choices for this chart. Continue?', 'baratables'); ?>" data-no-results-label="<?php echo esc_attr__('No tables found.', 'baratables'); ?>" data-searching-label="<?php echo esc_attr__('Searching...', 'baratables'); ?>" required>
					<option value=""><?php esc_html_e('Select table', 'baratables'); ?></option>
					<?php foreach ($table_choices as $id => $label) : ?>
						<option value="<?php echo esc_attr($id); ?>" <?php selected($selected_table, $id); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
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
						<label class="btbl-small-heading" for="btbl_chart_type"><?php echo esc_html($chart_type_label); ?></label>
						<a href="#btbl-chart-type-modal" class="btbl-chart-preview-trigger" aria-haspopup="dialog" aria-controls="btbl-chart-type-modal" title="<?php echo esc_attr__('Preview chart types', 'baratables'); ?>">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e('Open chart type gallery', 'baratables'); ?></span>
						</a>
					</div>
					<select name="btbl_chart_type" id="btbl_chart_type" class="btbl-chart-type-select" aria-label="<?php echo esc_attr($chart_type_label); ?>">
						<?php foreach ($chart_types as $type_slug => $type_capabilities) : ?>
							<option value="<?php echo esc_attr($type_slug); ?>" data-mode="<?php echo esc_attr($type_capabilities['mode']); ?>" data-stackable="<?php echo $type_capabilities['stackable'] ? '1' : '0'; ?>" <?php selected($chart_options['type'], $type_slug); ?>><?php echo esc_html($type_capabilities['label']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_height"><?php esc_html_e('Chart height (px)', 'baratables'); ?></label>
					<input type="number" min="120" max="2000" name="btbl_chart_height" id="btbl_chart_height" class="small-text" value="<?php echo esc_attr((int) ($chart_options['height'] ?? 360)); ?>" />
					<p class="description btbl-help-text"><?php esc_html_e('Recommended 300-500px.', 'baratables'); ?></p>
				</div>
			</div>
			<div class="btbl-control-grid btbl-chart-grid btbl-chart-standard">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_x_axis"><?php esc_html_e('X-axis / category column', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_x_axis', $column_choices, (string) ($chart_options['x_axis'] ?? ''), __('Select column', 'baratables'), true); ?>
					<?php $this->render_chart_note([
						['types' => ['bar', 'horizontal_bar', 'line', 'area', 'radar'], 'text' => __('Row labels. Each row becomes one category on the chart.', 'baratables')],
						['types' => ['pie', 'donut', 'treemap', 'funnel'], 'text' => __('Item names. Each row becomes one slice, stage, or tile.', 'baratables')],
						['types' => ['scatter', 'bubble'], 'text' => __('Plotted as a number along the horizontal axis. Rows without a numeric value are skipped.', 'baratables')],
						['types' => [], 'text' => __('The column that supplies the chart\'s categories or values.', 'baratables')],
					], (string) ($chart_options['type'] ?? 'bar')); ?>
				</div>
				<div class="btbl-control">
					<span class="btbl-small-heading"><?php esc_html_e('Series columns', 'baratables'); ?></span>
					<div id="btbl_chart_series" class="btbl-chart-series-list" role="group" aria-label="<?php echo esc_attr__('Series columns', 'baratables'); ?>">
						<?php foreach ($column_choices as $slug => $label) : ?>
							<label class="btbl-inline btbl-chart-series-option" data-slug="<?php echo esc_attr($slug); ?>">
								<input type="checkbox" name="btbl_chart_series[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) ($chart_options['series'] ?? []), true)); ?> />
								<?php echo esc_html($label); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<?php $this->render_chart_note([
						['types' => ['bar', 'horizontal_bar', 'line', 'area', 'radar'], 'text' => __('Each selected column is plotted as its own series.', 'baratables')],
						['types' => ['pie', 'donut', 'treemap', 'funnel'], 'text' => __('Only the first selected column is plotted; the rest are ignored.', 'baratables')],
						['types' => ['scatter'], 'text' => __('Each selected column becomes its own set of points.', 'baratables')],
						['types' => ['bubble'], 'text' => __('The first column sets each bubble\'s height; the second sets its size. Extra columns are ignored.', 'baratables')],
						['types' => [], 'text' => __('The columns to plot.', 'baratables')],
					], (string) ($chart_options['type'] ?? 'bar'), 'btbl-help-text'); ?>
				</div>
			</div>
			<div class="btbl-control-grid btbl-chart-grid btbl-chart-heatmap">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_heatmap_x"><?php esc_html_e('X category column', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_heatmap_x', $column_choices, (string) ($chart_options['heatmap_x'] ?? ''), __('Select column', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Categories shown across the chart.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_heatmap_y"><?php esc_html_e('Y category column', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_heatmap_y', $column_choices, (string) ($chart_options['heatmap_y'] ?? ''), __('Select column', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Categories shown down the chart.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_heatmap_value"><?php esc_html_e('Value column', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_heatmap_value', $column_choices, (string) ($chart_options['heatmap_value'] ?? ''), __('Select column', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Numeric values control each cell color; non-numeric rows are skipped.', 'baratables'); ?></p>
				</div>
			</div>
			<div class="btbl-control-grid btbl-chart-grid btbl-chart-gantt">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_label"><?php esc_html_e('Task / label column', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_gantt_label', $column_choices, (string) ($chart_options['gantt_label'] ?? ''), __('Select column', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Used for the task names on the Y-axis.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_start"><?php esc_html_e('Start date/time column', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_gantt_start', $column_choices, (string) ($chart_options['gantt_start'] ?? ''), __('Select column', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Dates should be parseable (e.g. 2024-01-31 or 2024-01-31 12:00).', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_end"><?php esc_html_e('End date/time column', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_gantt_end', $column_choices, (string) ($chart_options['gantt_end'] ?? ''), __('Select column', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Each task needs both a start and end.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_group"><?php esc_html_e('Group / lane column (optional)', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_gantt_group', $column_choices, (string) ($chart_options['gantt_group'] ?? ''), __('None', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Use to color tasks by owner/lane.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_chart_gantt_progress"><?php esc_html_e('Progress % column (optional)', 'baratables'); ?></label>
					<?php $this->render_column_select('btbl_chart_gantt_progress', $column_choices, (string) ($chart_options['gantt_progress'] ?? ''), __('None', 'baratables')); ?>
					<p class="description"><?php esc_html_e('Shown in tooltips; numbers should be 0-100.', 'baratables'); ?></p>
				</div>
			</div>
			<?php if (empty($column_choices)) : ?>
				<p class="description"><?php esc_html_e('Add columns first, then configure the chart.', 'baratables'); ?></p>
			<?php endif; ?>
		</div>
		<dialog id="btbl-chart-type-modal" class="btbl-chart-modal" aria-labelledby="btbl-chart-type-modal-title">
			<div class="btbl-chart-modal__content">
				<div class="btbl-chart-modal__header">
					<h3 id="btbl-chart-type-modal-title"><?php esc_html_e('Choose chart type', 'baratables'); ?></h3>
					<button type="button" class="btbl-chart-modal__close" aria-label="<?php esc_attr_e('Close chart type chooser', 'baratables'); ?>">&times;</button>
				</div>
				<div class="btbl-chart-modal__body">
					<div class="btbl-chart-type-chooser" role="group" aria-label="<?php esc_attr_e('Chart type', 'baratables'); ?>">
						<?php
						$current_type = $chart_options['type'] ?? 'bar';
							foreach ($chart_types as $slug => $type) :
								$image_url = '';
								$filename = $type['image'];
								if ($filename !== '') {
									$full_path = $assets['dir'] . 'assets/charts/' . $filename;
									if (file_exists($full_path)) {
										$image_url = $assets['url'] . 'assets/charts/' . $filename;
									}
								}
								$is_active = $slug === $current_type;
								?>
							<button type="button" class="btbl-chart-type-card<?php echo $is_active ? ' is-active' : ''; ?>" data-type="<?php echo esc_attr($slug); ?>" aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
									<span class="btbl-chart-type-thumb<?php echo $image_url ? '' : ' is-placeholder'; ?>"<?php echo $image_url ? ' style="background-image:url(' . esc_url($image_url) . ');"' : ''; ?>>
										<?php if (!$image_url) : ?>
										<span class="btbl-chart-type-thumb-fallback" style="background-image:url('<?php echo esc_attr($assets['placeholder']); ?>');"></span>
										<?php endif; ?>
									</span>
								<span class="btbl-chart-type-label"><?php echo esc_html($type['label']); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Chart field notes, one variant per chart-type group, so the description always
	 * matches the selected type instead of enumerating every type's behavior.
	 *
	 * A variant with an empty type list is the catch-all fallback. data-chart-types
	 * carries the same membership list to admin-chart.js, which re-runs this match
	 * whenever the chart type select changes.
	 *
	 * @param array<int, array{types:string[], text:string}> $variants Ordered variants; a type matches at most one.
	 * @param string                                         $active_type Currently selected chart type slug.
	 * @param string                                         $extra_class Optional additional class for each note.
	 */
	private function render_chart_note(array $variants, string $active_type, string $extra_class = ''): void {
		$matched = false;
		foreach ($variants as $variant) {
			if ($variant['types'] !== [] && in_array($active_type, $variant['types'], true)) {
				$matched = true;
				break;
			}
		}
		foreach ($variants as $variant) {
			$is_specific = $variant['types'] !== [];
			$is_match = $is_specific ? in_array($active_type, $variant['types'], true) : !$matched;
			printf(
				'<p class="description btbl-chart-note%1$s%2$s" data-chart-types="%3$s">%4$s</p>',
				$extra_class !== '' ? ' ' . esc_attr($extra_class) : '',
				$is_match ? '' : ' is-hidden',
				esc_attr(implode(' ', $variant['types'])),
				esc_html($variant['text'])
			);
		}
	}

	private function render_column_select(string $field, array $choices, string $value, string $empty_label, bool $required = false): void {
		?>
		<select name="<?php echo esc_attr($field); ?>" id="<?php echo esc_attr($field); ?>"<?php echo $required ? ' required' : ''; ?>>
			<option value=""><?php echo esc_html($empty_label); ?></option>
			<?php foreach ($choices as $slug => $label) : ?>
				<option value="<?php echo esc_attr($slug); ?>" <?php selected($value, $slug); ?>><?php echo esc_html($label); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}

/**
 * The chart editor's Design tab (2.0). Charts draw into a canvas, so unlike the table's
 * token controls these values mostly ride the JS payload; the background is the one
 * CSS-visible piece and prints as --btbl-chart-bg on the container. Empty means "keep the
 * current look": the accent-led stock palette and ECharts' own text and grid colors.
 */
class BaraTables_Admin_Tab_Chart_Design {
	public function render(array $context): void {
		$chart_options = $context['chart_options'] ?? [];
		$palette = [];
		foreach ((array) ($chart_options['stylePalette'] ?? []) as $color) {
			$hex = BaraTables_Service::normalize_hex_color((string) $color);
			if ($hex !== '') {
				$palette[] = $hex;
			}
		}
		$chart_config = [
			// The site default this chart inherits, and whether it inherits at all, so
			// the editor's live preview shows the EFFECTIVE design -- the chart's own
			// value, else the site default's -- exactly as the published chart will.
			'siteDefault' => BaraTables_Design::site_default()['chart'],
			'useSiteDesign' => !BaraTables_Design::opts_out((array) $chart_options),
			// The chart's own maps, shipped like the table's varMap: the preview, Copy
			// CSS and the preset buttons read them instead of restating the chart's
			// vocabulary, which is how they drifted apart in the first place.
			'chartVarMap' => BaraTables_Design::CHART_VAR_MAP,
			'chartFieldMap' => BaraTables_Design::CHART_FIELD_MAP,
			// The chart types with no grid, from the chart-type registry, so the editor
			// cannot offer a grid color for a chart that has no axes.
			'gridlessTypes' => BaraTables_Chart_Types::gridless_types(),
			// The note the copied CSS carries. It is shipped rather than written into the
			// script, because the chart's block says something the table's does not.
			'copiedCssNote' => __('The chart\'s background, padding and radius live on the container; its text, grid and series colors are drawn on the canvas and stay in the chart options. Paste this into Appearance > Customize > Additional CSS (or a child theme stylesheet).', 'baratables'),
		];
		?>
		<div id="btbl-tab-chart-design" class="btbl-tab-panel" role="tabpanel" aria-labelledby="btbl-tab-chart-design-label">
			<div data-btbl-chart-config="<?php echo esc_attr(wp_json_encode($chart_config)); ?>"></div>
			<p class="description btbl-help-text"><?php esc_html_e('Styles apply to the Chart preview as you edit, and publish with the chart. Leave anything blank to inherit the site default, and then the chart\'s own defaults.', 'baratables'); ?></p>
			<div class="btbl-control">
				<strong class="btbl-small-heading"><?php esc_html_e('Site design', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('The site default applies to every chart that leaves a value blank here.', 'baratables'); ?></p>
				<div class="btbl-checkbox btbl-design-field">
					<input type="hidden" name="btbl_chart_use_site_design" value="0" />
					<span class="btbl-checkbox-top">
						<label class="btbl-checkbox-main" for="btbl_chart_use_site_design">
							<input
								type="checkbox"
								id="btbl_chart_use_site_design"
								name="btbl_chart_use_site_design"
								value="1"
								data-default="1"
								<?php checked(!BaraTables_Design::opts_out((array) $chart_options)); ?>
							/>
							<span class="btbl-field-name"><?php esc_html_e('Use the site default design', 'baratables'); ?></span>
						</label>
					</span>
				</div>
			</div>
			<div class="btbl-control">
				<strong class="btbl-small-heading"><?php esc_html_e('Style presets', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('One click fills the controls below; keep tweaking from there. Your chart and its data are not touched.', 'baratables'); ?></p>
				<div class="btbl-flag-grid btbl-preset-grid">
					<?php BaraTables_Admin_Design_Fields::render_preset_buttons('data-btbl-chart-preset', true); ?>
				</div>
			</div>
			<div class="btbl-control">
				<strong class="btbl-small-heading"><?php esc_html_e('Series palette', 'baratables'); ?></strong>
				<p class="description"><?php echo esc_html(BaraTables_Admin_Design_Fields::palette_hint()); ?></p>
				<?php BaraTables_Admin_Design_Fields::render_palette_editor($palette); ?>
			</div>
			<?php BaraTables_Admin_Design_Fields::render_chart_style_groups($chart_options, true); ?>
			<div class="btbl-control">
				<strong class="btbl-small-heading"><?php esc_html_e('Chart CSS', 'baratables'); ?></strong>
				<div class="btbl-options-row btbl-copy-css-row">
					<button type="button" class="button" data-btbl-chart-copy-css><?php esc_html_e('Copy CSS', 'baratables'); ?></button>
					<span class="description" aria-live="polite" data-btbl-chart-copy-status></span>
				</div>
				<pre class="btbl-copy-css-output" hidden tabindex="0" aria-label="<?php esc_attr_e('Generated chart CSS', 'baratables'); ?>" data-btbl-chart-copy-css-output data-copied-label="<?php esc_attr_e('Copied to your clipboard.', 'baratables'); ?>" data-unavailable-label="<?php echo esc_attr__("Clipboard unavailable \u{2014} the CSS is selected below; copy it manually.", 'baratables'); ?>"></pre>
				<p class="description btbl-field-hint"><?php esc_html_e('Copies the chart container\'s background, padding and radius as CSS. The chart\'s text, grid and series colors are drawn on the canvas, so they stay in the chart options.', 'baratables'); ?></p>
			</div>
		</div>
		<?php
	}

}
