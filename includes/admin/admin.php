<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin {
	public const NONCE_ACTION = 'baratables_save';
	public const NONCE_FIELD = '_baratables_nonce';

	private BaraTables_Service $service;
	private BaraTables_Repository $repo;
	private BaraTables_Admin_Assets $assets;
	private BaraTables_Admin_Action_Handler $actions;
	private BaraTables_Admin_Pages $pages;

	public function __construct(BaraTables_Service $service, BaraTables_Repository $repo, string $plugin_url, string $plugin_path) {
		$this->service = $service;
		$this->repo = $repo;
		$this->assets = new BaraTables_Admin_Assets($plugin_url, $plugin_path);
		$this->actions = new BaraTables_Admin_Action_Handler($service);
		$this->pages = new BaraTables_Admin_Pages($service, self::NONCE_ACTION, self::NONCE_FIELD);
		$list_columns = new BaraTables_Admin_List_Columns();
		$slug_manager = new BaraTables_Admin_Slug_Manager($repo);
		$options_page = new BaraTables_Admin_Options($service);

		add_action('save_post_' . BaraTables_Repository::CPT, [$slug_manager, 'ensure_slug_on_save'], 10, 3);
		add_action('added_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_action('updated_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_filter('manage_' . BaraTables_Repository::CPT . '_posts_columns', [$list_columns, 'register_list_columns']);
		add_action('manage_' . BaraTables_Repository::CPT . '_posts_custom_column', [$list_columns, 'render_list_columns'], 10, 2);
		add_action('admin_menu', [$options_page, 'register_menu']);
		add_action('add_meta_boxes_' . BaraTables_Repository::CPT, [$this, 'register_meta_boxes']);
		add_action('save_post_' . BaraTables_Repository::CPT, [$this, 'save_table_from_editor'], 9, 3);
		add_action('admin_notices', ['BaraTables_Admin_Notice', 'render']);
		add_action('wp_ajax_btbl_refresh_preview', [$this, 'ajax_refresh_preview']);
		add_action('wp_ajax_btbl_refresh_fields', [$this, 'ajax_refresh_fields']);
		add_filter('admin_body_class', ['BaraTables_Help', 'body_class']);
		add_action('wp_ajax_btbl_toggle_help', ['BaraTables_Help', 'ajax_toggle']);
		(new BaraTables_Admin_Duplicator())->register();
	}

	public function register_cpt(): void {
		$this->repo->register_cpt();
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'btbl-table-builder',
			__('Table Builder', 'baratables'),
			[$this, 'render_table_metabox'],
			BaraTables_Repository::CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'btbl-table-preview',
			__('Table Preview', 'baratables'),
			[$this, 'render_table_preview_metabox'],
			BaraTables_Repository::CPT,
			'normal',
			'default'
		);
	}

	public function render_table_metabox(WP_Post $post): void {
		$existing = $this->get_existing_table_definition_for_post($post);
		if ($existing && empty($existing['post_type'])) {
			$existing['post_type'] = 'post';
		}

		$context_builder = new BaraTables_Admin_Form_Context($this->service);
		$context = $context_builder->build($existing);
		$page_slug = $existing ? 'baratables-edit' : 'baratables-add';
		echo '<div class="btbl-admin btbl-admin-embed">';
		$this->pages->render_table_form($context, $existing, $page_slug, false, false, get_the_title($post));
		echo '</div>';
	}

	public function render_table_preview_metabox(WP_Post $post): void {
		$definition = $this->get_existing_table_definition_for_post($post) ?: [];
		if (empty($definition)) {
			echo '<p>' . esc_html__('Pick a data source and select at least one column on the Columns &amp; Filters tab, then Save Draft or Publish to preview your table here.', 'baratables') . '</p>';
			return;
		}

		$preview_defn = $this->service->ensure_columns_inferred($definition);
		$preview_rows = $this->service->get_rows($preview_defn, 50);
		$preview_rows = $this->pages->apply_preview_sort($preview_rows, $preview_defn);
		// R15: a Refresh-preview button that appears only once the builder has unsaved
		// edits (revealed by JS on the first change). No standing help text.
		echo '<p class="btbl-preview-toolbar" hidden>';
		echo '<button type="button" class="button btbl-icon-button" id="btbl-refresh-preview" aria-label="' . esc_attr__('Refresh preview', 'baratables') . '" title="' . esc_attr__('Refresh preview', 'baratables') . '"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>';
		echo '</p>';
		echo '<div class="btbl-admin btbl-admin-embed" id="btbl-preview-target">';
		$this->pages->render_preview_panel($preview_defn, $preview_rows);
		echo '</div>';
	}

	public function ajax_refresh_fields(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		check_ajax_referer(self::NONCE_ACTION, self::NONCE_FIELD);

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		$post = $post_id ? get_post($post_id) : null;
		$existing = ($post instanceof WP_Post && $post->post_type === BaraTables_Repository::CPT)
			? $this->get_existing_table_definition_for_post($post)
			: null;
		$existing = is_array($existing) ? $existing : [];

		// BaraTables_Admin_Form_Context::build honors these admin params for the
		// requested post types + source, mirroring the legacy reload's query args.
		$_GET['type'] = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'post';
		$_GET['btbl_source'] = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : 'wp_query';

		// CSV: forward the preview params so build() infers the columns from the uploaded file,
		// mirroring the legacy reload's GET args. admin-ajax is always POST, so build()'s CSV
		// column-reset (gated on !is_post_request) would not fire — present as GET for the build
		// so the reset semantics match the old window.location reload exactly.
		$has_csv_params = false;
		if (isset($_POST['csv_id'])) {
			$_GET['btbl_preview_csv_id'] = absint(wp_unslash($_POST['csv_id']));
			$has_csv_params = true;
		}
		if (isset($_POST['csv_delim'])) {
			$_GET['btbl_preview_csv_delim'] = sanitize_text_field(wp_unslash($_POST['csv_delim']));
			$has_csv_params = true;
		}
		if (isset($_POST['csv_header'])) {
			$_GET['btbl_preview_csv_header'] = absint(wp_unslash($_POST['csv_header']));
			$has_csv_params = true;
		}
		$saved_request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : null;
		if ($has_csv_params) {
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}
		// Custom WP_Query "Load columns": forward the query JSON so build() infers columns from its
		// post types. Pass the STILL-SLASHED $_POST value — Form_Context::build() does the single
		// wp_unslash + sanitize (a second unslash here would corrupt backslash escapes in the JSON).
		if (isset($_POST['custom_query'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_json_textarea() + wp_unslash() run in Form_Context::build().
			$_GET['btbl_preview_custom_query'] = $_POST['custom_query'];
		}

		$context_builder = new BaraTables_Admin_Form_Context($this->service);
		$context = $context_builder->build($existing);
		if ($has_csv_params) {
			if ($saved_request_method === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $saved_request_method;
			}
		}
		$editing_defn = !empty($existing) ? $existing : null;
		$page_slug = $editing_defn ? 'baratables-edit' : 'baratables-add';

		wp_send_json_success([
			'columns' => $this->pages->render_columns_panel($context, $editing_defn),
			'source'  => $this->pages->render_source_panel($context, $editing_defn, $page_slug),
		]);
	}

	public function ajax_refresh_preview(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		check_ajax_referer(self::NONCE_ACTION, self::NONCE_FIELD);

		// Reuse the exact save pipeline so the preview never diverges from what would persist —
		// including the existing definition (loaded the same way save does), so deselecting every
		// column on an existing table previews as empty instead of injecting a default Title column.
		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		$post = $post_id ? get_post($post_id) : null;
		$existing = ($post instanceof WP_Post && $post->post_type === BaraTables_Repository::CPT)
			? $this->get_existing_table_definition_for_post($post)
			: null;
		$request = $this->actions->collect_table_request_data();
		$definition = $this->actions->apply_request_to_definition($request, $existing, !empty($existing));
		$definition['id'] = isset($_POST['btbl_table_id']) ? sanitize_text_field(wp_unslash($_POST['btbl_table_id'])) : '';

		$preview_defn = $this->service->ensure_columns_inferred($definition);
		$preview_rows = $this->service->get_rows($preview_defn, 50);
		$preview_rows = $this->pages->apply_preview_sort($preview_rows, $preview_defn);

		ob_start();
		$this->pages->render_preview_panel($preview_defn, $preview_rows);
		$html = ob_get_clean();

		wp_send_json_success(['html' => $html]);
	}

	public function save_table_from_editor(int $post_id, WP_Post $post, bool $update): void {
		if ($post->post_type !== BaraTables_Repository::CPT) {
			return;
		}
		if (!BaraTables_Admin_Action_Guard::can_save_post($post_id, self::NONCE_FIELD, self::NONCE_ACTION)) {
			return;
		}

		$request = $this->actions->collect_table_request_data();
		if ($request['name'] === '') {
			$request['name'] = BaraTables_Post_Input::text('post_title');
		}

		$existing = $this->get_existing_table_definition_for_post($post);
		$definition = $this->actions->apply_request_to_definition($request, $existing, $update);
		$old_slug = $post->post_name;
		$requested_id = sanitize_title(BaraTables_Post_Input::text('btbl_table_id'));
		$slug = $old_slug;
		if ($requested_id !== '' && $requested_id !== $old_slug) {
			// The user explicitly set or changed the Table ID.
			$slug = wp_unique_post_slug($requested_id, $post_id, $post->post_status, $post->post_type, $post->post_parent);
		} elseif ($old_slug === '') {
			// First save with no explicit ID — derive it from the title (original behavior).
			$base = sanitize_title((string) $post->post_title);
			if ($base === '') {
				$base = (string) $post_id;
			}
			$slug = wp_unique_post_slug($base, $post_id, $post->post_status, $post->post_type, $post->post_parent);
		}
		if ($slug !== '' && $slug !== $old_slug) {
			// Our own post_name write re-fires save_post; detach this handler around it so it
			// does not re-enter and double the persist + admin notices.
			$save_hook = 'save_post_' . BaraTables_Repository::CPT;
			remove_action($save_hook, [$this, 'save_table_from_editor'], 9);
			wp_update_post([
				'ID' => $post_id,
				'post_name' => $slug,
			]);
			add_action($save_hook, [$this, 'save_table_from_editor'], 9, 3);
			if ($old_slug !== '') {
				// Rename of an existing table: forward-fix linked charts (our own records)
				// and flag the embeds we can't reach. No alias is stored.
				$charts_updated = $this->service->rewrite_chart_table_id($old_slug, $slug);
				$this->queue_table_rename_notice($old_slug, $slug, $requested_id, $charts_updated);
			}
		}
		$definition['id'] = $slug;
		$definition['name'] = $request['name'] !== '' ? $request['name'] : ($definition['name'] ?? $post->post_title);
		$definition['status'] = $post->post_status;

		BaraTables_Base_Repository::persist($post_id, BaraTables_Repository::META_KEY, BaraTables_Repository::META_SLUG, $definition, $slug);

		// R1: warn (non-blocking) if the saved table has no effective columns and will render nothing.
		$effective = $this->service->ensure_columns_inferred($definition);
		if (empty($effective['columns'])) {
			BaraTables_Admin_Notice::queue(
				__('This table was saved without any columns, so it will not display anything yet. Open the Columns &amp; Filters tab, select at least one column, then update the table.', 'baratables'),
				'warning'
			);
		}

		// R6: the user typed value-override rules, but none survived parsing (invalid JSON discarded silently).
		$overrides_raw = trim((string) ($request['value_overrides_raw_input'] ?? ''));
		if ($overrides_raw !== '' && empty($request['value_overrides'])) {
			BaraTables_Admin_Notice::queue(
				__('Value overrides could not be read as valid JSON, so no override rules were saved. Check the JSON on the Advanced tab.', 'baratables'),
				'warning'
			);
		} elseif ($overrides_raw !== '') {
			// R21: flag regex rules whose pattern is invalid (they silently pass values through unchanged).
			$decoded = json_decode($overrides_raw, true);
			$bad_patterns = [];
			if (is_array($decoded)) {
				foreach ($decoded as $rule) {
					if (is_array($rule) && !empty($rule['regex']) && isset($rule['search'])) {
						if (@preg_match((string) $rule['search'], '') === false) {
							$bad_patterns[] = (string) $rule['search'];
						}
					}
				}
			}
			if (!empty($bad_patterns)) {
				BaraTables_Admin_Notice::queue(
					sprintf(
						/* translators: %s is a comma-separated list of invalid regex patterns. */
						__('These value-override regex patterns are invalid and were skipped (remember the delimiters, e.g. #pattern#): %s', 'baratables'),
						esc_html(implode(', ', $bad_patterns))
					),
					'warning'
				);
			}
		}
	}

	/** Build the non-blocking notice shown after a Table ID rename. */
	private function queue_table_rename_notice(string $old_slug, string $new_slug, string $requested_id, int $charts_updated): void {
		$parts = [];
		if ($requested_id !== '' && $new_slug !== $requested_id) {
			$parts[] = sprintf(
				/* translators: 1: the ID the user asked for, 2: the unique ID actually saved. */
				__('The Table ID “%1$s” was already in use, so it was saved as “%2$s”.', 'baratables'),
				$requested_id,
				$new_slug
			);
		}
		$parts[] = sprintf(
			/* translators: 1: old Table ID, 2: new Table ID. */
			__('Table ID changed from “%1$s” to “%2$s”. Update any [bara_table id="%1$s"] already pasted into your content.', 'baratables'),
			$old_slug,
			$new_slug
		);
		if ($charts_updated > 0) {
			$parts[] = sprintf(
				/* translators: %d: number of linked charts updated. */
				_n('%d linked chart was updated automatically.', '%d linked charts were updated automatically.', $charts_updated, 'baratables'),
				$charts_updated
			);
		}
		BaraTables_Admin_Notice::queue(implode(' ', $parts), 'info');
	}

	private function get_existing_table_definition_for_post(WP_Post $post): ?array {
		$existing = $this->service->find_definition($post->post_name, true);
		if (!$existing) {
			$meta = get_post_meta($post->ID, BaraTables_Repository::META_KEY, true);
			$existing = is_array($meta) ? $meta : null;
		}
		return $existing;
	}

	public function enqueue_admin_assets(string $hook): void {
		$this->assets->enqueue($hook);
	}

}


class BaraTables_Chart_Admin {
	private BaraTables_Chart_Service $chart_service;
	private BaraTables_Chart_Repository $chart_repo;
	private BaraTables_Service $table_service;
	private BaraTables_Admin_Tab_Chart $tab_chart;
	private string $nonce_action;
	private string $nonce_field;

	public function __construct(BaraTables_Chart_Service $chart_service, BaraTables_Chart_Repository $chart_repo, BaraTables_Service $table_service, string $nonce_action, string $nonce_field) {
		$this->chart_service = $chart_service;
		$this->chart_repo = $chart_repo;
		$this->table_service = $table_service;
		$this->tab_chart = new BaraTables_Admin_Tab_Chart();
		$this->nonce_action = $nonce_action;
		$this->nonce_field = $nonce_field;
		$list_columns = new BaraTables_Chart_List_Columns($table_service);
		$slug_manager = new BaraTables_Chart_Slug_Manager($chart_repo);

		add_filter('manage_' . BaraTables_Chart_Repository::CPT . '_posts_columns', [$list_columns, 'register_list_columns']);
		add_action('manage_' . BaraTables_Chart_Repository::CPT . '_posts_custom_column', [$list_columns, 'render_list_columns'], 10, 2);
		add_action('save_post_' . BaraTables_Chart_Repository::CPT, [$slug_manager, 'ensure_slug_on_save'], 10, 3);
		add_action('added_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_action('updated_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_action('add_meta_boxes_' . BaraTables_Chart_Repository::CPT, [$this, 'register_meta_boxes']);
		add_action('save_post_' . BaraTables_Chart_Repository::CPT, [$this, 'save_chart_from_editor'], 9, 3);
		add_action('wp_ajax_btbl_refresh_chart_fields', [$this, 'ajax_refresh_chart_fields']);
	}

	public function register_cpt(): void {
		$this->chart_repo->register_cpt();
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'btbl-chart-builder',
			__('Chart Builder', 'baratables'),
			[$this, 'render_chart_metabox'],
			BaraTables_Chart_Repository::CPT,
			'normal',
			'high'
		);

		// R16: a live chart preview, mirroring the Table Preview the table editor has.
		add_meta_box(
			'btbl-chart-preview',
			__('Chart Preview', 'baratables'),
			[$this, 'render_chart_preview_metabox'],
			BaraTables_Chart_Repository::CPT,
			'normal',
			'default'
		);
	}

	public function render_chart_preview_metabox(WP_Post $post): void {
		$chart = $this->chart_service->find_chart($post->post_name, true);
		$chart_id = $chart['id'] ?? ($post->post_name ?: '');
		if (empty($chart) || $chart_id === '' || empty($chart['table_id'])) {
			echo '<p>' . esc_html__('Save the chart to see a preview.', 'baratables') . '</p>';
			return;
		}
		// Reuse the exact front-end renderer (it registers + enqueues ECharts on demand),
		// so the admin preview can never diverge from what visitors see.
		$output = do_shortcode('[bara_chart id="' . esc_attr($chart_id) . '"]');
		echo '<div class="btbl-admin btbl-admin-embed btbl-chart-preview-embed">' . $output . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped by the renderer.
	}

	public function render_chart_metabox(WP_Post $post): void {
		$chart = $this->chart_service->find_chart($post->post_name, true);
		if (!$chart) {
			$chart = get_post_meta($post->ID, BaraTables_Chart_Repository::META_KEY, true);
			$chart = is_array($chart) ? $chart : null;
		}
		$selected_table = isset($_GET['table']) ? sanitize_text_field(wp_unslash($_GET['table'])) : ($chart['table_id'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin URL parameter.
		$context = $this->chart_service->build_form_context($chart, $selected_table);
		$page_slug = $chart ? 'btbl-charts-edit' : 'btbl-charts-add';

		echo '<div class="btbl-admin btbl-admin-embed">';
		$this->render_chart_form($context, $chart, $chart ? 'update' : 'create', false, false, get_the_title($post));
		echo '</div>';
	}

	/**
	 * Render just the Chart tab panel to a string (for the in-place AJAX field refresh when the
	 * source table changes — the no-reload equivalent of re-rendering the metabox with ?table=).
	 */
	private function render_chart_panel(array $context): string {
		ob_start();
		$this->tab_chart->render([
			'chart_options'   => $context['chart_options'] ?? $this->table_service->get_default_chart_options(),
			'active_tab'      => 'btbl-tab-chart',
			'table_choices'   => $context['table_choices'] ?? [],
			'selected_table'  => $context['selected_table'] ?? '',
			'page_slug'       => $context['page_slug'] ?? '',
			'dropped_columns' => $context['dropped_columns'] ?? [],
		], $context['column_choices'] ?? []);
		return (string) ob_get_clean();
	}

	/**
	 * Rebuild the chart's column-dependent controls (X-axis / series / gantt selects) for a newly
	 * chosen source table, without a full page reload. Mirrors render_chart_metabox's chart + table
	 * resolution, then returns the rendered Chart panel for the JS to swap in place.
	 */
	public function ajax_refresh_chart_fields(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		check_ajax_referer($this->nonce_action, $this->nonce_field);

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		$post = $post_id ? get_post($post_id) : null;
		$chart = null;
		if ($post instanceof WP_Post && $post->post_type === BaraTables_Chart_Repository::CPT) {
			$chart = $this->chart_service->find_chart($post->post_name, true);
			if (!$chart) {
				$meta = get_post_meta($post->ID, BaraTables_Chart_Repository::META_KEY, true);
				$chart = is_array($meta) ? $meta : null;
			}
		}
		$selected_table = isset($_POST['table_id']) ? sanitize_text_field(wp_unslash($_POST['table_id'])) : '';
		$context = $this->chart_service->build_form_context($chart, $selected_table);
		$context['page_slug'] = $chart ? 'btbl-charts-edit' : 'btbl-charts-add';

		wp_send_json_success(['panel' => $this->render_chart_panel($context)]);
	}

	private function render_chart_form(
		array $context,
		?array $chart,
		string $action,
		bool $wrap_form = true,
		bool $include_title = true,
		string $title_fallback = ''
	): void {
		$chart_options = $context['chart_options'] ?? $this->table_service->get_default_chart_options();
		$column_choices = $context['column_choices'] ?? [];
		$table_choices = $context['table_choices'] ?? [];
		$selected_table = $context['selected_table'] ?? '';
		$page_slug = $chart ? 'btbl-charts-edit' : 'btbl-charts-add';
		$chart_id = $chart['id'] ?? '';
		$shortcode = $chart_id !== '' ? '[bara_chart id="' . sanitize_text_field((string) $chart_id) . '"]' : '';
		$title_value = $chart['name'] ?? $title_fallback;
		?>
		<?php if ($wrap_form) : ?>
			<form method="post" autocomplete="off">
		<?php endif; ?>
			<?php wp_nonce_field($this->nonce_action, $this->nonce_field); ?>
			<?php if ($wrap_form) : ?>
				<input type="hidden" name="btbl_chart_action" value="<?php echo esc_attr($action); ?>" />
			<?php endif; ?>
			<input type="hidden" name="btbl_active_tab" id="btbl_active_tab" value="btbl-tab-chart" />
			<?php
			$id_editor_html = '';
			if ($chart) {
				ob_start();
				BaraTables_Admin_Page_Utils::render_id_editor('btbl_chart_id', (string) $chart_id, __('Chart ID', 'baratables'), '[bara_chart]');
				$id_editor_html = (string) ob_get_clean();
			}
			BaraTables_Admin_Page_Utils::render_title_section(
				__('Chart name', 'baratables'),
				'btbl_chart_name',
				$title_value,
				$shortcode,
				$include_title,
				$id_editor_html
			);
			?>
			<?php BaraTables_Help::render_toggle(); ?>
			<div class="btbl-tab-wrapper">
				<h2 class="nav-tab-wrapper btbl-nav-tab-wrapper">
					<a href="#btbl-tab-chart" id="btbl-tab-chart-label" role="tab" aria-selected="true" class="nav-tab nav-tab-active btbl-tab-link" data-target="btbl-tab-chart"><?php esc_html_e('Chart', 'baratables'); ?></a>
				</h2>
				<?php
				$this->tab_chart->render([
					'chart_options' => $chart_options,
					'active_tab' => 'btbl-tab-chart',
					'table_choices' => $table_choices,
						'selected_table' => $selected_table,
						'page_slug' => $page_slug,
						'dropped_columns' => $context['dropped_columns'] ?? [],
					], $column_choices);
				?>
			</div>
			<?php if ($wrap_form) : ?>
				<p class="btbl-submit-row">
					<button type="submit" class="button button-primary">
						<?php echo $chart ? esc_html__('Update Chart', 'baratables') : esc_html__('Publish Chart', 'baratables'); ?>
					</button>
				</p>
			<?php endif; ?>
		<?php if ($wrap_form) : ?>
			</form>
		<?php endif;
	}

	public function save_chart_from_editor(int $post_id, WP_Post $post, bool $update): void {
		if ($post->post_type !== BaraTables_Chart_Repository::CPT) {
			return;
		}
		if (!BaraTables_Admin_Action_Guard::can_save_post($post_id, $this->nonce_field, $this->nonce_action)) {
			return;
		}

		$name = BaraTables_Post_Input::text('btbl_chart_name');
		if ($name === '') {
			$name = BaraTables_Post_Input::text('post_title');
		}
		$table_id = BaraTables_Post_Input::text('btbl_chart_table');
		$options = $this->collect_chart_options_from_request();

		$existing = $this->chart_service->find_chart($post->post_name, true);
		$prepared = $this->chart_service->prepare_chart_definition([
			'name' => $name,
			'table_id' => $table_id,
			'chart' => $options,
		], $existing);

		$chart = $prepared['definition'];
		$table_missing = empty($prepared['table_definition']);
		if ($table_missing) {
			// R2: WordPress still publishes the post, so the shortcode would render
			// "Chart not found." with no explanation. Tell the user why and what to do.
			$message = $table_id === ''
				? __('This chart was published without a source table, so it will show &#8220;Chart not found.&#8221; Edit the chart, choose a table, then update it.', 'baratables')
				: __('The table selected for this chart could not be found, so it will show &#8220;Chart not found.&#8221; Edit the chart and choose an existing table.', 'baratables');
			BaraTables_Admin_Notice::queue($message, 'error');
			// Do NOT bail before persisting: the post is published regardless, so returning here
			// would silently discard the user's name/option edits and any simultaneous Chart ID
			// rename. Save what we have so their work survives; they can fix the table and update.
		}

		$old_slug = $post->post_name;
		$requested_id = sanitize_title(BaraTables_Post_Input::text('btbl_chart_id'));
		$slug = $old_slug;
		if ($requested_id !== '' && $requested_id !== $old_slug) {
			// The user explicitly set or changed the Chart ID.
			$slug = wp_unique_post_slug($requested_id, $post_id, $post->post_status, $post->post_type, $post->post_parent);
		} elseif ($old_slug === '') {
			$slug = $chart['id'] ?? BaraTables_Id_Generator::generate_chart_id();
		}
		if ($slug !== '' && $slug !== $old_slug) {
			// Our own post_name write re-fires save_post; detach this handler around it so it
			// does not re-enter and double the persist + admin notices.
			$save_hook = 'save_post_' . BaraTables_Chart_Repository::CPT;
			remove_action($save_hook, [$this, 'save_chart_from_editor'], 9);
			wp_update_post([
				'ID' => $post_id,
				'post_name' => $slug,
			]);
			add_action($save_hook, [$this, 'save_chart_from_editor'], 9, 3);
			if ($old_slug !== '') {
				// Nothing references a chart by id (charts reference tables, not vice versa), so
				// no forward-rewrite is needed — only flag the [bara_chart] embeds we can't reach.
				$this->queue_chart_rename_notice($old_slug, $slug, $requested_id);
			}
		}
		$chart['id'] = $slug;
		$chart['name'] = $name !== '' ? $name : ($chart['name'] ?? $post->post_title);
		$chart['status'] = $post->post_status;

		BaraTables_Base_Repository::persist($post_id, BaraTables_Chart_Repository::META_KEY, BaraTables_Chart_Repository::META_SLUG, $chart, $slug);

		// R2: a chart with no data series renders empty — warn (non-blocking). Skip when the table
		// is missing: the "Chart not found" error above already covers it and is the actionable one.
		if (!$table_missing && self::should_warn_no_series($chart)) {
			BaraTables_Admin_Notice::queue(
				__('This chart has no data series selected, so it will render empty. Edit the chart and choose at least one series column.', 'baratables'),
				'warning'
			);
		}
	}

	/**
	 * Whether to warn that a chart has no data series. Gantt charts are driven by
	 * gantt_label/start/end (not series), so an empty series is expected for them and must
	 * not trigger the warning — mirroring the front-end enabled-check in frontend.php.
	 */
	private static function should_warn_no_series(array $chart): bool {
		$type = $chart['chart']['type'] ?? 'bar';
		return $type !== 'gantt' && empty($chart['chart']['series']);
	}

	/** Build the non-blocking notice shown after a Chart ID rename. */
	private function queue_chart_rename_notice(string $old_slug, string $new_slug, string $requested_id): void {
		$parts = [];
		if ($requested_id !== '' && $new_slug !== $requested_id) {
			$parts[] = sprintf(
				/* translators: 1: the ID the user asked for, 2: the unique ID actually saved. */
				__('The Chart ID “%1$s” was already in use, so it was saved as “%2$s”.', 'baratables'),
				$requested_id,
				$new_slug
			);
		}
		$parts[] = sprintf(
			/* translators: 1: old Chart ID, 2: new Chart ID. */
			__('Chart ID changed from “%1$s” to “%2$s”. Update any [bara_chart id="%1$s"] already pasted into your content.', 'baratables'),
			$old_slug,
			$new_slug
		);
		BaraTables_Admin_Notice::queue(implode(' ', $parts), 'info');
	}

	public function collect_chart_options_from_request(): array {
		$p = BaraTables_Post_Input::class;
		return [
			'type'           => $p::raw('btbl_chart_type'),
			'x_axis'         => $p::raw('btbl_chart_x_axis'),
			'series'         => $p::array_raw('btbl_chart_series'),
			'stack'          => $p::raw('btbl_chart_stack'),
			'height'         => $p::raw('btbl_chart_height'),
			'gantt_label'    => $p::raw('btbl_chart_gantt_label'),
			'gantt_start'    => $p::raw('btbl_chart_gantt_start'),
			'gantt_end'      => $p::raw('btbl_chart_gantt_end'),
			'gantt_group'    => $p::raw('btbl_chart_gantt_group'),
			'gantt_progress' => $p::raw('btbl_chart_gantt_progress'),
		];
	}

}
