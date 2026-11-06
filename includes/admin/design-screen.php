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

		$rejected = BaraTables_Design::update_site_default($raw);
		$args = ['btbl-design-saved' => '1'];
		if ($rejected !== []) {
			// The keys travel in the URL so the screen can name them after the redirect.
			// They are read back through a whitelist of the known design keys, so nothing
			// reaches the page that this plugin did not put there.
			$args['btbl-design-rejected'] = implode(',', array_keys($rejected));
		}
		wp_safe_redirect(add_query_arg($args, admin_url('edit.php?post_type=' . BaraTables_Repository::CPT . '&page=' . self::PAGE_SLUG)));
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

	/**
	 * The design keys the last save rejected, read back from the redirect.
	 *
	 * The keys are matched against the plugin's own list of design values, so the query
	 * string can only ever name a field this plugin knows about.
	 *
	 * @return array<string,string> The option key => the option key.
	 */
	private function rejected_design_keys(): array {
		if (!isset($_GET['btbl-design-rejected'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
			return [];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only display flag; every key is matched against the plugin's own list of design values below, so nothing else is read.
		$raw = (string) wp_unslash($_GET['btbl-design-rejected']);
		$known = array_keys(BaraTables_Admin_Design_Fields::value_labels());
		$keys = [];
		foreach (explode(',', $raw) as $key) {
			if (in_array($key, $known, true)) {
				$keys[$key] = $key;
			}
		}
		return $keys;
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
		$rejected = $this->rejected_design_keys();

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
			<?php if ($rejected !== []) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html(BaraTables_Admin_Design_Fields::rejected_values_notice($rejected)); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e('This is the design every table and chart on this site starts from. A table or chart that sets its own value keeps that value; one that switches the site design off follows your theme instead.', 'baratables'); ?></p>

			<form method="post" id="btbl-site-design-form" autocomplete="off">
				<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
				<input type="hidden" name="btbl_design_action" value="save_design" />

				<?php // The chart editor's own context, so the chart script drives this screen's
				// chart controls, presets and preview exactly as it drives the chart editor's. ?>
				<div data-btbl-chart-config="<?php echo esc_attr(wp_json_encode($this->chart_script_config())); ?>"></div>

				<div class="btbl-control" data-btbl-design-config="<?php echo esc_attr(wp_json_encode($this->script_config())); ?>">
					<strong class="btbl-small-heading"><?php esc_html_e('Table style presets', 'baratables'); ?></strong>
					<p class="description"><?php esc_html_e('One click fills the table controls below; keep tweaking from there.', 'baratables'); ?></p>
					<div class="btbl-flag-grid btbl-preset-grid">
						<?php BaraTables_Admin_Design_Fields::render_preset_buttons('data-btbl-preset'); ?>
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
					<p class="description btbl-field-hint"><?php esc_html_e('Copies the whole site design as CSS custom properties, for the tables and for the chart containers. Paste it into your theme to keep the look even if the plugin settings change.', 'baratables'); ?></p>
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
		$chart = $this->chart_script_config();
		return [
			'scope' => '#btbl-site-design-form',
			'previewSelector' => '#btbl-site-design-preview .btbl-table-wrapper',
			'varMap' => BaraTables_Service::STYLE_VAR_MAP,
			// The chart's own maps, shipped like the table's varMap: the site screen
			// edits the chart half of the design too, and its Copy CSS has to hand the
			// chart's container tokens back as CSS.
			'chartVarMap' => $chart['chartVarMap'],
			'chartFieldMap' => $chart['chartFieldMap'],
			'siteDefault' => [],
			'useSiteDesign' => true,
			'siteScope' => true,
			'maxCssBytes' => BaraTables_Service::MAX_CUSTOM_CSS_BYTES,
			'copiedLabel' => __('Copied to your clipboard.', 'baratables'),
			// The em dash is written as a Unicode escape so this file stays ASCII.
			'clipboardUnavailableLabel' => __("Clipboard unavailable \u{2014} the CSS is selected below; copy it manually.", 'baratables'),
			// The site screen has no "Theme CSS" output mode of its own -- that is a
			// per-table option -- so its copied CSS cannot tell the owner to switch to
			// it. The chart editor's copy can, and says so.
			'copiedCssNote' => __('Paste it into Appearance > Customize > Additional CSS (or a child theme stylesheet) to keep the look even if the plugin settings change.', 'baratables'),
		];
	}

	/**
	 * The chart editor's context, for the chart script.
	 *
	 * The chart script reads the chart's vocabulary and the site default from this
	 * element. The site Design screen edits the site default itself, so it inherits
	 * nothing -- and it still needs the vocabulary, or the chart presets and the chart
	 * preview would not work here.
	 */
	private function chart_script_config(): array {
		return [
			'chartVarMap' => BaraTables_Design::CHART_VAR_MAP,
			'chartFieldMap' => BaraTables_Design::CHART_FIELD_MAP,
			'siteDefault' => [],
			'useSiteDesign' => true,
			// No chart type is gridless HERE: the site default has to cover every chart
			// type, so the grid color stays available whatever the preview chart is.
			'gridlessTypes' => [],
		];
	}

	/**
	 * The table design groups, rendered by the shared design controls so the site
	 * screen and the table editor's Design tab cannot drift apart.
	 */
	private function render_table_groups(array $values): void {
		?>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Tables', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('The design every table starts from. A table that sets its own value keeps that value.', 'baratables'); ?></p>
			<p class="description"><?php esc_html_e('These are the colors and the spacing. Whether the stripes and highlights are on at all stays a per-table setting, in each table\'s own Design tab.', 'baratables'); ?></p>
		</div>
		<?php
		BaraTables_Admin_Design_Fields::render_table_groups($values, 'btbl_table_options', 'btbl_site_design');
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
			<strong class="btbl-small-heading"><?php esc_html_e('Chart style presets', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('One click fills the chart controls below; keep tweaking from there.', 'baratables'); ?></p>
			<div class="btbl-flag-grid btbl-preset-grid">
				<?php BaraTables_Admin_Design_Fields::render_preset_buttons('data-btbl-chart-preset', true); ?>
			</div>
		</div>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Series palette', 'baratables'); ?></strong>
			<p class="description"><?php echo esc_html(BaraTables_Admin_Design_Fields::palette_hint(true)); ?></p>
			<?php BaraTables_Admin_Design_Fields::render_palette_editor($palette); ?>
		</div>
		<?php BaraTables_Admin_Design_Fields::render_chart_style_groups($values, false); ?>
		<?php
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
