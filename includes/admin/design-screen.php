<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The site-wide default design screen (2.1.0).
 *
 * One place to set the design every table and chart starts from. A table or chart that
 * sets its own value overrides the site's, value by value; one that opts out ignores
 * the site's design entirely and follows the theme.
 *
 * The screen is deliberately built from the same parts as the two editors: the same
 * field names, the same presets, the same Copy CSS, and the same live preview, which
 * is the real front-end render rather than an approximation. The Design tab in the
 * table editor and the Design tab in the chart editor are siblings of this screen, not
 * separate implementations.
 */
class BaraTables_Admin_Design_Screen {
	public const PAGE_SLUG = 'baratables-design';
	private const NONCE_ACTION = 'btbl_design_save';
	private const NONCE_FIELD = '_btbl_design_nonce';

	private BaraTables_Service $service;
	private ?BaraTables_Frontend $frontend;

	public function __construct(BaraTables_Service $service, ?BaraTables_Frontend $frontend = null) {
		$this->service = $service;
		$this->frontend = $frontend;
	}

	public function register_menu(): void {
		$parent = 'edit.php?post_type=' . BaraTables_Repository::CPT;
		$hook = add_submenu_page(
			$parent,
			__('Site design', 'baratables'),
			__('Design', 'baratables'),
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'render_page']
		);
		if ($hook) {
			// The POST is handled on load-*, before a byte of output: WordPress calls
			// render_page() only after admin-header.php has emitted the whole head, so a
			// redirect issued from the renderer can never take effect.
			add_action('load-' . $hook, [$this, 'handle_save_request']);
		}
	}

	/**
	 * Handles the save POST: capability, nonce, sanitize, store, redirect.
	 */
	public function handle_save_request(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below.
		$action = isset($_POST['btbl_design_action']) ? sanitize_key(wp_unslash($_POST['btbl_design_action'])) : '';
		if ($action !== 'save_design') {
			return;
		}
		check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

		$raw = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		if (isset($_POST['btbl_table_options']) && is_array($_POST['btbl_table_options'])) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every value is sanitized in BaraTables_Design::sanitize_table_scope().
			$raw['table'] = wp_unslash($_POST['btbl_table_options']);
		}
		$raw['chart'] = [
			'stylePalette'   => $this->decode_palette_input($this->posted('btbl_chart_style_palette')),
			'styleBackground' => $this->posted('btbl_chart_style_background'),
			'styleTextColor' => $this->posted('btbl_chart_style_text_color'),
			'styleGridColor' => $this->posted('btbl_chart_style_grid_color'),
			'styleFontSize'  => $this->posted('btbl_chart_style_font_size'),
			'stylePadding'   => $this->posted('btbl_chart_style_padding'),
			'styleRadius'    => $this->posted('btbl_chart_style_radius'),
		];

		BaraTables_Design::update_site_default($raw);
		wp_safe_redirect(add_query_arg('btbl-design-saved', '1', admin_url('edit.php?post_type=' . BaraTables_Repository::CPT . '&page=' . self::PAGE_SLUG)));
		exit;
	}

	/** A posted value, unslashed and trimmed. Sanitized by the design sanitizers. */
	private function posted(string $key): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller.
		if (!isset($_POST[$key])) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the caller; sanitized in BaraTables_Design.
		return (string) wp_unslash($_POST[$key]);
	}

	/** The palette chip editor serializes its colors as JSON; malformed input is just "no palette". */
	private function decode_palette_input(string $raw): array {
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values($decoded) : [];
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		$default = BaraTables_Design::site_default();
		$table_values = $default['table'];
		$chart_values = $default['chart'];
		$saved = isset($_GET['btbl-design-saved']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.

		?>
		<div class="wrap btbl-admin">
			<h1><?php esc_html_e('Site design', 'baratables'); ?></h1>
			<?php
			// The "hide help text" preference is global (admin_body_class) and the CSS blanks every
			// .description inside .btbl-admin. Without the toggle rendered here, a user who hid help
			// in the table editor lost this page's guidance with no way to bring it back.
			BaraTables_Help::render_toggle();
			?>
			<?php if ($saved) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Site design saved.', 'baratables'); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e('This is the design every table and chart on this site starts from. A table or chart that sets its own value keeps that value; one that switches the site design off follows your theme instead.', 'baratables'); ?></p>

			<form method="post" id="btbl-site-design-form" autocomplete="off">
				<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
				<input type="hidden" name="btbl_design_action" value="save_design" />

				<div class="btbl-control" data-btbl-design-config="<?php echo esc_attr(wp_json_encode($this->script_config())); ?>">
					<strong class="btbl-small-heading"><?php esc_html_e('Style presets', 'baratables'); ?></strong>
					<p class="description"><?php esc_html_e('One click fills the controls below; keep tweaking from there.', 'baratables'); ?></p>
					<div class="btbl-flag-grid btbl-preset-grid">
						<button type="button" class="button btbl-preset-button" data-btbl-preset="default"><?php esc_html_e('Theme default', 'baratables'); ?></button>
						<button type="button" class="button btbl-preset-button" data-btbl-preset="minimal"><?php esc_html_e('Minimal', 'baratables'); ?></button>
						<button type="button" class="button btbl-preset-button" data-btbl-preset="bold"><?php esc_html_e('Bold header', 'baratables'); ?></button>
						<button type="button" class="button btbl-preset-button" data-btbl-preset="midnight"><?php esc_html_e('Midnight', 'baratables'); ?></button>
						<button type="button" class="button btbl-preset-button" data-btbl-preset="paper"><?php esc_html_e('Paper', 'baratables'); ?></button>
					</div>
				</div>

				<?php $this->render_table_groups($table_values); ?>
				<?php $this->render_chart_groups($chart_values); ?>

				<div class="btbl-control">
					<strong class="btbl-small-heading"><?php esc_html_e('Style output', 'baratables'); ?></strong>
					<div class="btbl-options-row btbl-copy-css-row">
						<button type="button" class="button" data-btbl-copy-css><?php esc_html_e('Copy CSS', 'baratables'); ?></button>
						<span class="description" aria-live="polite" data-btbl-copy-status></span>
					</div>
					<pre class="btbl-copy-css-output" hidden tabindex="0" aria-label="<?php esc_attr_e('Generated site CSS', 'baratables'); ?>"></pre>
					<p class="description btbl-field-hint"><?php esc_html_e('Copies the site design as CSS custom properties. Paste it into your theme to keep the look even if the plugin settings change.', 'baratables'); ?></p>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e('Save site design', 'baratables'); ?></button>
				</p>
			</form>

			<?php $this->render_preview(); ?>
			<?php $this->render_chart_preview($chart_values); ?>
		</div>
		<?php
	}

	/**
	 * The configuration the shared design script runs on. The site screen's own values
	 * ARE the site default, so nothing is inherited into the preview from the stored
	 * option: the preview shows exactly what the form holds.
	 */
	private function script_config(): array {
		return [
			'scope' => '#btbl-site-design-form',
			'previewSelector' => '#btbl-site-design-preview .btbl-table-wrapper',
			'varMap' => BaraTables_Service::STYLE_VAR_MAP,
			'siteDefault' => [],
			'useSiteDesign' => true,
			'siteScope' => true,
			'maxCssBytes' => BaraTables_Service::MAX_CUSTOM_CSS_BYTES,
			'copiedLabel' => __('Copied to your clipboard.', 'baratables'),
			// The em dash is written as a Unicode escape so this file stays ASCII.
			'clipboardUnavailableLabel' => __("Clipboard unavailable \u{2014} the CSS is selected below; copy it manually.", 'baratables'),
		];
	}

	/**
	 * The table design groups, rendered from the same schema the table editor's Design
	 * tab reads, so a token cannot exist in one and be missing from the other.
	 */
	private function render_table_groups(array $values): void {
		$schema = BaraTables_Service::get_table_option_schema();
		$theme_accent = BaraTables_Service::resolve_theme_accent();
		$color_groups = [
			[
				'heading' => __('Colors', 'baratables'),
				'fields' => ['accentColor', 'textColor', 'borderColor'],
			],
			[
				'heading' => __('Header row', 'baratables'),
				'fields' => ['headerBgColor', 'headerTextColor', 'headerWeight'],
			],
			[
				'heading' => __('Rows', 'baratables'),
				'fields' => ['stripeColor', 'hoverColor'],
			],
			[
				'heading' => __('Footer totals', 'baratables'),
				'fields' => ['footerBgColor', 'footerTextColor'],
			],
			[
				'heading' => __('Conditional cell colors', 'baratables'),
				'description' => __('Used by the per-column conditional formatting rules on a table\'s Columns &amp; filters tab.', 'baratables'),
				'fields' => ['successBgColor', 'successColor', 'warningBgColor', 'warningColor', 'dangerBgColor', 'dangerColor'],
			],
		];
		$size_fields = ['fontSize', 'cellPadding', 'borderRadius'];
		?>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Tables', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('The design every table starts from. A table that sets its own value keeps that value.', 'baratables'); ?></p>
		</div>
		<?php foreach ($color_groups as $group) : ?>
			<div class="btbl-control btbl-design-fields">
				<strong class="btbl-small-heading"><?php echo esc_html($group['heading']); ?></strong>
				<?php if (!empty($group['description'])) : ?>
					<p class="description"><?php echo esc_html($group['description']); ?></p>
				<?php endif; ?>
				<div class="btbl-flag-grid btbl-table-flags">
					<?php foreach ($group['fields'] as $field_key) : ?>
						<?php $this->render_field($field_key, $schema[$field_key] ?? [], $values, $theme_accent); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
		<div class="btbl-control btbl-design-fields">
			<strong class="btbl-small-heading"><?php esc_html_e('Typography & shape', 'baratables'); ?></strong>
			<div class="btbl-flag-grid btbl-table-flags">
				<?php foreach ($size_fields as $field_key) : ?>
					<?php $this->render_field($field_key, $schema[$field_key] ?? [], $values, $theme_accent); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The chart design groups. The field names are the chart editor's own, so the chart
	 * editor's palette chip editor drives this screen unchanged.
	 */
	private function render_chart_groups(array $values): void {
		$palette = [];
		foreach ((array) ($values['stylePalette'] ?? []) as $color) {
			$hex = BaraTables_Service::normalize_hex_color((string) $color);
			if ($hex !== '') {
				$palette[] = $hex;
			}
		}
		?>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Charts', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('The design every chart starts from. A chart that sets its own value keeps that value.', 'baratables'); ?></p>
		</div>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Style presets', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('One click fills the chart controls below; keep tweaking from there.', 'baratables'); ?></p>
			<div class="btbl-flag-grid btbl-preset-grid">
				<button type="button" class="button btbl-preset-button" data-btbl-chart-preset="default"><?php esc_html_e('Theme default', 'baratables'); ?></button>
				<button type="button" class="button btbl-preset-button" data-btbl-chart-preset="minimal"><?php esc_html_e('Minimal', 'baratables'); ?></button>
				<button type="button" class="button btbl-preset-button" data-btbl-chart-preset="bold"><?php esc_html_e('Bold header', 'baratables'); ?></button>
				<button type="button" class="button btbl-preset-button" data-btbl-chart-preset="midnight"><?php esc_html_e('Midnight', 'baratables'); ?></button>
				<button type="button" class="button btbl-preset-button" data-btbl-chart-preset="paper"><?php esc_html_e('Paper', 'baratables'); ?></button>
			</div>
		</div>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Series palette', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('Colors are used in order across series and slices. Drag to reorder. An empty palette keeps the table accent leading the default colors.', 'baratables'); ?></p>
			<div class="btbl-palette-editor" data-btbl-palette-editor data-label-remove="<?php esc_attr_e('Remove color', 'baratables'); ?>">
				<div class="btbl-palette-chips">
					<?php foreach ($palette as $color) : ?>
						<?php $this->render_chip($color); ?>
					<?php endforeach; ?>
				</div>
				<div class="btbl-palette-add">
					<div class="btbl-color-field">
						<input
							type="text"
							class="btbl-color-value"
							value=""
							placeholder="#2271b1"
							aria-label="<?php esc_attr_e('New palette color (hex)', 'baratables'); ?>"
						/>
						<input type="color" class="btbl-color-picker" value="#2271b1" aria-label="<?php esc_attr_e('Pick new palette color', 'baratables'); ?>" />
					</div>
					<button type="button" class="button" data-btbl-palette-add><?php esc_html_e('Add color', 'baratables'); ?></button>
				</div>
				<input type="hidden" name="btbl_chart_style_palette" value="<?php echo esc_attr((string) wp_json_encode($palette)); ?>" data-btbl-palette-input />
			</div>
		</div>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Colors & type', 'baratables'); ?></strong>
			<div class="btbl-flag-grid btbl-table-flags btbl-design-fields">
				<?php
				$this->render_color_card('btbl_chart_style_background', __('Background', 'baratables'), (string) ($values['styleBackground'] ?? ''), __('Canvas default', 'baratables'));
				$this->render_color_card('btbl_chart_style_text_color', __('Text color', 'baratables'), (string) ($values['styleTextColor'] ?? ''), __('ECharts default', 'baratables'));
				$this->render_color_card('btbl_chart_style_grid_color', __('Grid color', 'baratables'), (string) ($values['styleGridColor'] ?? ''), __('ECharts default', 'baratables'));
				$this->render_size_card('btbl_chart_style_font_size', __('Font size', 'baratables'), (string) ($values['styleFontSize'] ?? ''), '14px');
				?>
			</div>
			<p class="description btbl-field-hint"><?php esc_html_e('Text and grid colors apply to axis labels, legends, and grid lines. The palette colors the series, the slices, and a Gantt\'s task groups.', 'baratables'); ?></p>
		</div>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Chart container', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('The space and rounding around the chart itself, rather than inside it.', 'baratables'); ?></p>
			<div class="btbl-flag-grid btbl-table-flags btbl-design-fields">
				<?php
				$this->render_size_card('btbl_chart_style_padding', __('Padding', 'baratables'), (string) ($values['stylePadding'] ?? ''), __('None', 'baratables'));
				$this->render_size_card('btbl_chart_style_radius', __('Corner radius', 'baratables'), (string) ($values['styleRadius'] ?? ''), __('None', 'baratables'));
				?>
			</div>
		</div>
		<?php
	}

	/** One design field, rendered by the shared design controls. */
	private function render_field(string $key, array $config, array $values, string $theme_accent): void {
		BaraTables_Admin_Design_Fields::render_field($key, $config, $values, $theme_accent, 'btbl_table_options', 'btbl_site_design');
	}

	private function render_chip(string $color): void {
		BaraTables_Admin_Design_Fields::render_chip($color);
	}

	private function render_color_card(string $name, string $label, string $value, string $placeholder): void {
		BaraTables_Admin_Design_Fields::render_color_card($name, $label, $value, $placeholder);
	}

	private function render_size_card(string $name, string $label, string $value, string $placeholder): void {
		BaraTables_Admin_Design_Fields::render_size_card($name, $label, $value, $placeholder);
	}

	/**
	 * The live chart preview: the real front-end render of a small sample chart, in the
	 * same container the chart editor's preview uses, so the chart editor's own script
	 * restyles it as the controls change.
	 */
	private function render_chart_preview(array $values): void {
		$chart_options = array_merge(
			$this->service->get_default_chart_options(),
			[
				'enabled' => true,
				'type' => 'bar',
				'x_axis' => 'custom:col_1',
				'series' => ['custom:col_3'],
				'position' => 'above',
				'height' => 280,
			],
			$values
		);
		?>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Chart preview', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('A sample chart with the design above. Changes appear here as you type.', 'baratables'); ?></p>
			<div class="btbl-admin btbl-admin-embed btbl-chart-preview-embed">
				<?php
				if ($this->frontend === null) {
					echo '<p>' . esc_html__('The preview needs the front-end renderer, which is unavailable in this request.', 'baratables') . '</p>';
				} else {
					$definition = self::preview_definition();
					$rendered = $this->frontend->render_chart_preview($definition, self::preview_rows(), $chart_options);
					echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Front-end renderer output; escaped at every sink.
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * The live preview: the real front-end render of a small sample table, so the
	 * screen shows what a visitor sees rather than an approximation of it.
	 */
	private function render_preview(): void {
		?>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Preview', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('A sample table with the design above. Changes appear here as you type.', 'baratables'); ?></p>
			<div class="btbl-admin btbl-admin-embed" id="btbl-site-design-preview">
				<?php
				if ($this->frontend === null) {
					echo '<p>' . esc_html__('The preview needs the front-end renderer, which is unavailable in this request.', 'baratables') . '</p>';
				} else {
					$definition = self::preview_definition();
					$rendered = $this->frontend->render_table_preview($definition, self::preview_rows());
					echo $rendered['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Front-end renderer output; escaped at every sink.
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * A small manual-data table, used only for the preview.
	 */
	private static function preview_definition(): array {
		$columns = [];
		foreach ([['col_1', 'Product'], ['col_2', 'Region'], ['col_3', 'Revenue']] as $column) {
			$columns[] = [
				'key' => $column[0],
				'label' => $column[1],
				'auto_label' => false,
				'filter_label' => $column[1],
				'source' => 'custom',
				'filter' => 'none',
				'filter_sort' => 'asc',
				'slug' => 'custom:' . $column[0],
				'hide_title' => false,
				'hidden' => false,
				'searchable' => true,
				'sort_priority' => 0,
				'sort_direction' => 'asc',
				'sort_enabled' => false,
				'sortable' => true,
				'filter_values' => [],
				'filter_type_priority' => [],
				'format_date' => false,
				'date_format' => '',
			];
		}
		return [
			'id' => 'site-design-preview',
			'source_type' => BaraTables_Source_Type::CUSTOM_DATA,
			'post_type' => 'post',
			'post_types' => [],
			'columns' => $columns,
			'custom_data' => [
				'columns' => ['Product', 'Region', 'Revenue'],
				'rows' => self::preview_rows(),
			],
			'table_options' => [],
			'filter_order' => [],
			'access_control' => [],
			'value_overrides' => [],
		];
	}

	private static function preview_rows(): array {
		return [
			['Atlas Console', 'Midwest', '184000'],
			['Beacon Reports', 'West', '132500'],
			['Cedar Sync', 'South', '98000'],
			['Delta Portal', 'Northeast', '211750'],
		];
	}
}
