<?php

if (!defined('ABSPATH')) {
	exit;
}

/** Owns the table editor tabs; preview rendering lives in BaraTables_Admin_Preview_Renderer. */
class BaraTables_Admin_Pages {
	private string $nonce_action;
	private string $nonce_field;
	private BaraTables_Admin_Tab_General $tab_general;
	private BaraTables_Admin_Tab_Columns $tab_columns;
	private BaraTables_Admin_Tab_Table $tab_table;
	private BaraTables_Admin_Tab_Advanced $tab_advanced;

	public function __construct(string $nonce_action, string $nonce_field) {
		$this->nonce_action = $nonce_action;
		$this->nonce_field = $nonce_field;
		$this->tab_general = new BaraTables_Admin_Tab_General();
		$this->tab_columns = new BaraTables_Admin_Tab_Columns();
		$this->tab_table = new BaraTables_Admin_Tab_Table();
		$this->tab_advanced = new BaraTables_Admin_Tab_Advanced();
	}

	public function render_table_form(array $context, ?array $editing_defn): void {
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$table_id = $editing_defn['id'] ?? '';
		$shortcode = $table_id !== '' ? '[bara_table id="' . sanitize_text_field((string) $table_id) . '"]' : '';
		$nav_class = static function (string $tab) use ($active_tab): string {
			$base = 'nav-tab btbl-tab-link';
			return $active_tab === $tab ? 'nav-tab nav-tab-active btbl-tab-link' : $base;
		};
		?>
			<?php
			BaraTables_Admin_Page_Utils::render_editor_header(
				$this->nonce_action,
				$this->nonce_field,
				$active_tab,
				$shortcode,
				$editing_defn ? [
					'field' => 'btbl_table_id',
					'value' => $table_id,
					'label' => __('Table ID', 'baratables'),
					'embed_tag' => '[bara_table]',
				] : null
			);
			?>
			<?php BaraTables_Help::render_toggle(); ?>
			<?php if (!$editing_defn && BaraTables_Help::is_first_table()) : ?>
				<div class="notice notice-info inline btbl-intro-callout btbl-help-text">
					<p>
						<strong><?php esc_html_e('New table:', 'baratables'); ?></strong>
						<?php esc_html_e('1. Pick a data source on the Source tab.', 'baratables'); ?>
						<?php esc_html_e('2. Choose at least one column on Columns &amp; Filters.', 'baratables'); ?>
						<?php esc_html_e('3. Publish.', 'baratables'); ?>
					</p>
				</div>
			<?php endif; ?>
			<div class="btbl-tab-wrapper">
				<h2 class="nav-tab-wrapper btbl-nav-tab-wrapper" role="tablist">
					<a href="#btbl-tab-general" id="btbl-tab-general-label" role="tab" aria-selected="<?php echo $active_tab === 'btbl-tab-general' ? 'true' : 'false'; ?>" class="<?php echo esc_attr($nav_class('btbl-tab-general')); ?>" data-target="btbl-tab-general"><?php esc_html_e('Source', 'baratables'); ?></a>
					<a href="#btbl-tab-columns" id="btbl-tab-columns-label" role="tab" aria-selected="<?php echo $active_tab === 'btbl-tab-columns' ? 'true' : 'false'; ?>" class="<?php echo esc_attr($nav_class('btbl-tab-columns')); ?>" data-target="btbl-tab-columns"><?php esc_html_e('Columns & Filters', 'baratables'); ?></a>
					<a href="#btbl-tab-table" id="btbl-tab-table-label" role="tab" aria-selected="<?php echo $active_tab === 'btbl-tab-table' ? 'true' : 'false'; ?>" class="<?php echo esc_attr($nav_class('btbl-tab-table')); ?>" data-target="btbl-tab-table"><?php esc_html_e('Options', 'baratables'); ?></a>
					<a href="#btbl-tab-advanced" id="btbl-tab-advanced-label" role="tab" aria-selected="<?php echo $active_tab === 'btbl-tab-advanced' ? 'true' : 'false'; ?>" class="<?php echo esc_attr($nav_class('btbl-tab-advanced')); ?>" data-target="btbl-tab-advanced"><?php esc_html_e('Advanced', 'baratables'); ?></a>
				</h2>

				<?php
				$this->tab_general->render($context);
				$this->tab_columns->render($context);
				$this->tab_table->render($context);
				$this->tab_advanced->render($context);
				?>
			</div>
			<?php
			// Last field in the editor, deliberately. The manual-data grid posts one input per
			// cell, so a large grid can push the request past PHP's max_input_vars (default 1000).
			// PHP then truncates $_POST silently -- it only logs a warning -- and because both
			// nonces are emitted before the grid, the save still passes verification and proceeds
			// with everything after the cut missing. That reset table_options to defaults and
			// dropped access_control. If this marker is absent while the nonce is present, the
			// request was cut short and must not be treated as the user's intent.
			?>
			<input type="hidden" name="<?php echo esc_attr(BaraTables_Admin_Action_Guard::COMPLETE_FIELD); ?>" value="1" />
		<?php
	}

	/** Render just the Columns & Filters panel (used by the no-reload field refresh). */
	public function render_columns_panel(array $context): string {
		ob_start();
		$this->tab_columns->render($context);
		return (string) ob_get_clean();
	}

	/** Render just the Source panel (used by the no-reload field refresh). */
	public function render_source_panel(array $context): string {
		ob_start();
		$this->tab_general->render($context);
		return (string) ob_get_clean();
	}
}
