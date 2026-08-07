<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin {
	public const NONCE_ACTION = 'baratables_save';
	public const NONCE_FIELD = '_baratables_nonce';

	private BaraTables_Service $service;
	private BaraTables_Admin_Assets $assets;
	private BaraTables_Admin_Action_Handler $actions;
	private BaraTables_Admin_Pages $pages;
	private BaraTables_Admin_Preview_Renderer $preview_renderer;
	private BaraTables_Entity_Persistence $persistence;

	public function __construct(BaraTables_Service $service, BaraTables_Repository $repo, string $plugin_url, string $plugin_path) {
		$this->service = $service;
		$this->assets = new BaraTables_Admin_Assets($plugin_url, $plugin_path);
		$this->actions = new BaraTables_Admin_Action_Handler($service);
		$this->pages = new BaraTables_Admin_Pages(self::NONCE_ACTION, self::NONCE_FIELD);
		$this->preview_renderer = new BaraTables_Admin_Preview_Renderer($service);
		$this->persistence = BaraTables_Entity_Persistence::from_descriptor(BaraTables_Entity_Descriptor::table());
		$list_columns = new BaraTables_Admin_List_Columns();
		$slug_manager = new BaraTables_Admin_Slug_Manager($repo);
		$options_page = new BaraTables_Admin_Options($service);

		add_action('save_post_' . BaraTables_Repository::CPT, [$slug_manager, 'ensure_slug_on_save'], 10, 2);
		add_action('added_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 3);
		add_action('updated_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 3);
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
		echo '<div class="btbl-admin btbl-admin-embed">';
		$this->pages->render_table_form($context, $existing);
		echo '</div>';
	}

	public function render_table_preview_metabox(WP_Post $post): void {
		$definition = $this->get_existing_table_definition_for_post($post) ?: [];
		if (empty($definition)) {
			echo '<p>' . esc_html__('Pick a data source and at least one column on the Columns &amp; Filters tab, then save to preview the table here.', 'baratables') . '</p>';
			return;
		}

		$preview = $this->prepare_preview($definition);
		// R15: a Refresh-preview button that appears only once the builder has unsaved
		// edits (revealed by JS on the first change). No standing help text.
		echo '<p class="btbl-preview-toolbar" hidden>';
		echo '<button type="button" class="button btbl-icon-button" id="btbl-refresh-preview" aria-label="' . esc_attr__('Refresh preview', 'baratables') . '" title="' . esc_attr__('Refresh preview', 'baratables') . '"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>';
		echo '</p>';
		echo '<div class="btbl-admin btbl-admin-embed" id="btbl-preview-target">';
		$this->preview_renderer->render($preview['definition'], $preview['rows']);
		echo '</div>';
	}

	public function ajax_refresh_fields(): void {
		BaraTables_Admin_Ajax_Guard::verify(self::NONCE_ACTION, self::NONCE_FIELD);

		$existing = $this->get_ajax_table_definition() ?? [];

		// Form_Context::build() takes its live-preview inputs as an argument, so hand it an array
		// assembled straight from this AJAX POST. (It used to fake a full-page GET by writing $_GET
		// and $_SERVER['REQUEST_METHOD'] and restoring them in a finally -- fragile, because build()
		// opens files, runs WP_Query and dials external MySQL, any of which can throw mid-request.)
		// Same sanitizer as the full-page GET collector, so the in-place refresh and the legacy
		// reload can never disagree about an input. Nonce already verified above.
		$raw = [];
		foreach (BaraTables_Admin_Form_Context::preview_post_fields() as $key) {
			if (isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX nonce and capability verified at the handler boundary.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boundary verified above; sanitized in sanitize_preview_values().
				$raw[$key] = wp_unslash($_POST[$key]);
			}
		}
		$preview = BaraTables_Admin_Form_Context::sanitize_preview_values($raw);
		$preview += ['type' => 'post', 'source' => 'wp_query'];
		// CSV preview params let build() infer columns from the uploaded file. build()'s CSV column
		// reset is gated on a non-POST request (the legacy reload arrived as GET), so mark the
		// preview as GET only when those params are present -- matching the old reload semantics.
		$has_csv_params = isset($raw['csv_id']) || isset($raw['csv_delim']) || isset($raw['csv_header']);
		$preview['request_method'] = $has_csv_params ? 'GET' : 'POST';

		$context_builder = new BaraTables_Admin_Form_Context($this->service);
		$context = $context_builder->build($existing, $preview);

		$editing_defn = !empty($existing) ? $existing : null;

		wp_send_json_success([
			'columns' => $this->pages->render_columns_panel($context, $editing_defn),
			'source'  => $this->pages->render_source_panel($context, $editing_defn),
		]);
	}

	public function ajax_refresh_preview(): void {
		BaraTables_Admin_Ajax_Guard::verify(self::NONCE_ACTION, self::NONCE_FIELD);

		// Reuse the exact save pipeline so the preview never diverges from what would persist --
		// including the existing definition (loaded the same way save does), so deselecting every
		// column on an existing table previews as empty instead of injecting a default Title column.
		$existing = $this->get_ajax_table_definition();
		$request = $this->actions->collect_table_request_data();
		$definition = $this->actions->apply_request_to_definition($request, $existing, !empty($existing));
		$definition['id'] = isset($_POST['btbl_table_id']) ? sanitize_text_field(wp_unslash($_POST['btbl_table_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX nonce and capability verified above.

		$preview = $this->prepare_preview($definition);

		ob_start();
		$this->preview_renderer->render($preview['definition'], $preview['rows']);
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
		// Bail before reading anything: a truncated request looks like a deliberate save with
		// empty tabs, and apply_request_to_definition() would overwrite table_options with
		// defaults and unset access_control.
		if (!BaraTables_Admin_Action_Guard::request_is_complete()) {
			BaraTables_Admin_Action_Guard::warn_request_truncated();
			return;
		}

		$request = $this->actions->collect_table_request_data();
		$existing = $this->get_existing_table_definition_for_post($post);
		$definition = $this->actions->apply_request_to_definition($request, $existing, $update);
		$identity = $this->persistence->save_editor_slug(
			$post_id,
			$post,
			BaraTables_Post_Input::text('btbl_table_id'),
			(string) $post->post_title,
			[$this, 'save_table_from_editor'],
			3
		);
		$old_slug = $identity['old_slug'];
		$requested_id = $identity['requested_slug'];
		$slug = $identity['slug'];
		if ($identity['error'] instanceof WP_Error) {
			BaraTables_Admin_Notice::queue(
				__('The Table ID could not be saved. The previous ID was kept.', 'baratables'),
				'error'
			);
		}
		if ($identity['changed'] && $old_slug !== '') {
			// Rename of an existing table: forward-fix linked charts (our own records)
			// and flag the embeds we can't reach. No alias is stored.
			$charts_updated = $this->service->rewrite_chart_table_id($old_slug, $slug);
			$this->queue_table_rename_notice($old_slug, $slug, $requested_id, $charts_updated);
		}
		$definition['id'] = $slug;
		$definition['name'] = $request['name'] !== '' ? $request['name'] : ($definition['name'] ?? $post->post_title);
		$definition['status'] = $post->post_status;

		$this->persistence->persist($post_id, $definition, $slug);

		// R1: warn (non-blocking) if the saved table has no effective columns and will render nothing.
		$effective = $this->service->resolve_columns($definition);
		if (empty($effective['columns'])) {
			BaraTables_Admin_Notice::queue(
				__('This table has no columns, so it will not display anything. Select at least one column on the Columns &amp; Filters tab, then update.', 'baratables'),
				'warning'
			);
		}

		// R6: the user typed value-override rules, but none survived parsing (invalid JSON discarded silently).
		$overrides_raw = trim((string) ($request['value_overrides_raw_input'] ?? ''));
		if ($overrides_raw !== '' && empty($request['value_overrides'])) {
			BaraTables_Admin_Notice::queue(
				__('Value overrides were not valid JSON, so no rules were saved. Check the JSON on the Advanced tab.', 'baratables'),
				'warning'
			);
		} elseif ($overrides_raw !== '') {
			// R21: flag regex rules whose pattern is invalid (they silently pass values through unchanged).
			$decoded = json_decode($overrides_raw, true);
			$bad_patterns = [];
			if (is_array($decoded)) {
				foreach ($decoded as $rule) {
					if (is_array($rule) && !empty($rule['regex']) && isset($rule['search'])) {
						// Deliberate probe: the point is to discover whether an admin-supplied pattern
					// is valid, and an invalid one makes preg_match() emit a warning no matter what
					// we do. Silencing is the check, not a shortcut around one.
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validity probe on user input.
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
		$suffix = '';
		if ($charts_updated > 0) {
			$suffix = sprintf(
				/* translators: %d: number of linked charts updated. */
				_n('%d linked chart was updated automatically.', '%d linked charts were updated automatically.', $charts_updated, 'baratables'),
				$charts_updated
			);
		}
		BaraTables_Admin_Notice::queue_rename(
			$old_slug,
			$new_slug,
			$requested_id,
			/* translators: 1: the ID the user asked for, 2: the unique ID actually saved. */
			__('The Table ID "%1$s" was already in use, so it was saved as "%2$s".', 'baratables'),
			/* translators: 1: old Table ID, 2: new Table ID. */
			__('Table ID changed from "%1$s" to "%2$s". Update any [bara_table id="%1$s"] you have already pasted into your content.', 'baratables'),
			$suffix
		);
	}

	private function get_existing_table_definition_for_post(WP_Post $post): ?array {
		return BaraTables_Admin_Definition_Loader::for_post(
			$post,
			BaraTables_Entity_Descriptor::table(),
			[$this->service, 'find_definition']
		);
	}

	private function get_ajax_table_definition(): ?array {
		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after the AJAX boundary guard verifies nonce and capability.
		$post = $post_id ? get_post($post_id) : null;
		return $post instanceof WP_Post && $post->post_type === BaraTables_Repository::CPT
			? $this->get_existing_table_definition_for_post($post)
			: null;
	}

	/** @return array{definition:array,rows:array} */
	private function prepare_preview(array $definition): array {
		$result = $this->service->get_row_result($definition, 50);
		$definition = $this->service->definition_with_inferred_columns($definition, $result);
		return [
			'definition' => $definition,
			'rows' => $this->preview_renderer->sort($result->rows(), $definition),
		];
	}

	public function enqueue_admin_assets(string $hook): void {
		$this->assets->enqueue($hook);
	}

}


class BaraTables_Chart_Admin {
	private BaraTables_Chart_Service $chart_service;
	private BaraTables_Service $table_service;
	private BaraTables_Admin_Tab_Chart $tab_chart;
	private string $nonce_action;
	private string $nonce_field;
	private BaraTables_Entity_Persistence $persistence;

	public function __construct(BaraTables_Chart_Service $chart_service, BaraTables_Chart_Repository $chart_repo, BaraTables_Service $table_service, string $nonce_action, string $nonce_field) {
		$this->chart_service = $chart_service;
		$this->table_service = $table_service;
		$this->tab_chart = new BaraTables_Admin_Tab_Chart();
		$this->nonce_action = $nonce_action;
		$this->nonce_field = $nonce_field;
		$this->persistence = BaraTables_Entity_Persistence::from_descriptor(BaraTables_Entity_Descriptor::chart());
		$list_columns = new BaraTables_Chart_List_Columns($table_service);
		$slug_manager = new BaraTables_Chart_Slug_Manager($chart_repo);

		add_filter('manage_' . BaraTables_Chart_Repository::CPT . '_posts_columns', [$list_columns, 'register_list_columns']);
		add_action('manage_' . BaraTables_Chart_Repository::CPT . '_posts_custom_column', [$list_columns, 'render_list_columns'], 10, 2);
		add_action('save_post_' . BaraTables_Chart_Repository::CPT, [$slug_manager, 'ensure_slug_on_save'], 10, 2);
		add_action('added_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 3);
		add_action('updated_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 3);
		add_action('add_meta_boxes_' . BaraTables_Chart_Repository::CPT, [$this, 'register_meta_boxes']);
		add_action('save_post_' . BaraTables_Chart_Repository::CPT, [$this, 'save_chart_from_editor'], 9, 2);
		add_action('wp_ajax_btbl_refresh_chart_fields', [$this, 'ajax_refresh_chart_fields']);
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
		$chart = $this->get_existing_chart_definition_for_post($post);
		$selected_table = isset($_GET['table']) ? sanitize_text_field(wp_unslash($_GET['table'])) : ($chart['table_id'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin URL parameter.
		$context = $this->chart_service->build_form_context($chart, $selected_table);

		echo '<div class="btbl-admin btbl-admin-embed">';
		$this->render_chart_form($context, $chart);
		echo '</div>';
	}

	/**
	 * Render just the Chart tab panel to a string (for the in-place AJAX field refresh when the
	 * source table changes -- the no-reload equivalent of re-rendering the metabox with ?table=).
	 */
	private function render_chart_panel(array $context): string {
		$panel = $this->normalize_chart_panel_context($context);
		ob_start();
		$this->tab_chart->render($panel, $panel['column_choices']);
		return (string) ob_get_clean();
	}

	private function normalize_chart_panel_context(array $context): array {
		return [
			'chart_options' => $context['chart_options'] ?? $this->table_service->get_default_chart_options(),
			'active_tab' => 'btbl-tab-chart',
			'table_choices' => $context['table_choices'] ?? [],
			'selected_table' => $context['selected_table'] ?? '',
			'dropped_columns' => $context['dropped_columns'] ?? [],
			'column_choices' => $context['column_choices'] ?? [],
		];
	}

	/**
	 * Rebuild the chart's column-dependent controls (X-axis / series / gantt selects) for a newly
	 * chosen source table, without a full page reload. Mirrors render_chart_metabox's chart + table
	 * resolution, then returns the rendered Chart panel for the JS to swap in place.
	 */
	public function ajax_refresh_chart_fields(): void {
		BaraTables_Admin_Ajax_Guard::verify($this->nonce_action, $this->nonce_field);

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX nonce and capability verified above.
		$post = $post_id ? get_post($post_id) : null;
		$chart = null;
		if ($post instanceof WP_Post && $post->post_type === BaraTables_Chart_Repository::CPT) {
			$chart = $this->get_existing_chart_definition_for_post($post);
		}
		$selected_table = isset($_POST['table_id']) ? sanitize_text_field(wp_unslash($_POST['table_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX nonce and capability verified above.
		$context = $this->chart_service->build_form_context($chart, $selected_table);

		wp_send_json_success(['panel' => $this->render_chart_panel($context)]);
	}

	/**
	 * The saved chart for a chart post: the indexed lookup first, falling back to the raw meta for
	 * a post whose slug index has not been written yet (first save / an import).
	 */
	private function get_existing_chart_definition_for_post(WP_Post $post): ?array {
		return BaraTables_Admin_Definition_Loader::for_post(
			$post,
			BaraTables_Entity_Descriptor::chart(),
			[$this->chart_service, 'find_chart']
		);
	}

	private function render_chart_form(array $context, ?array $chart): void {
		$panel = $this->normalize_chart_panel_context($context);
		$chart_id = $chart['id'] ?? '';
		$shortcode = $chart_id !== '' ? '[bara_chart id="' . sanitize_text_field((string) $chart_id) . '"]' : '';
		?>
			<?php
			BaraTables_Admin_Page_Utils::render_editor_header(
				$this->nonce_action,
				$this->nonce_field,
				'btbl-tab-chart',
				$shortcode,
				$chart ? [
					'field' => 'btbl_chart_id',
					'value' => $chart_id,
					'label' => __('Chart ID', 'baratables'),
					'embed_tag' => '[bara_chart]',
				] : null
			);
			?>
			<?php BaraTables_Help::render_toggle(); ?>
			<div class="btbl-tab-wrapper">
				<h2 class="nav-tab-wrapper btbl-nav-tab-wrapper" role="tablist">
					<a href="#btbl-tab-chart" id="btbl-tab-chart-label" role="tab" aria-selected="true" class="nav-tab nav-tab-active btbl-tab-link" data-target="btbl-tab-chart"><?php esc_html_e('Chart', 'baratables'); ?></a>
				</h2>
				<?php
				$this->tab_chart->render($panel, $panel['column_choices']);
				?>
			</div>
		<?php
	}

	public function save_chart_from_editor(int $post_id, WP_Post $post): void {
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
				? __('This chart has no source table, so it will show "Chart not found." Edit the chart and choose one.', 'baratables')
				: __('This chart\'s table no longer exists, so it will show "Chart not found." Edit the chart and choose another.', 'baratables');
			BaraTables_Admin_Notice::queue($message, 'error');
			// Do NOT bail before persisting: the post is published regardless, so returning here
			// would silently discard the user's name/option edits and any simultaneous Chart ID
			// rename. Save what we have so their work survives; they can fix the table and update.
		}

		$identity = $this->persistence->save_editor_slug(
			$post_id,
			$post,
			BaraTables_Post_Input::text('btbl_chart_id'),
			(string) ($chart['id'] ?? BaraTables_Id_Generator::generate_chart_id()),
			[$this, 'save_chart_from_editor'],
			2
		);
		$old_slug = $identity['old_slug'];
		$requested_id = $identity['requested_slug'];
		$slug = $identity['slug'];
		if ($identity['error'] instanceof WP_Error) {
			BaraTables_Admin_Notice::queue(
				__('The Chart ID could not be saved. The previous ID was kept.', 'baratables'),
				'error'
			);
		}
		if ($identity['changed'] && $old_slug !== '') {
			// Nothing references a chart by id (charts reference tables, not vice versa), so
			// no forward-rewrite is needed -- only flag the [bara_chart] embeds we can't reach.
			$this->queue_chart_rename_notice($old_slug, $slug, $requested_id);
		}
		$chart['id'] = $slug;
		$chart['name'] = $name !== '' ? $name : ($chart['name'] ?? $post->post_title);
		$chart['status'] = $post->post_status;

		$this->persistence->persist($post_id, $chart, $slug);

		// R2: a chart with no data series renders empty -- warn (non-blocking). Skip when the table
		// is missing: the "Chart not found" error above already covers it and is the actionable one.
		if (!$table_missing && self::should_warn_no_series($chart)) {
			BaraTables_Admin_Notice::queue(
				__('This chart has no series selected, so it will render empty. Choose at least one series column.', 'baratables'),
				'warning'
			);
		}
	}

	/**
	 * Whether to warn that a chart is missing a role named "series". The chart registry owns
	 * which modes require that role, matching the front-end configured check.
	 */
	private static function should_warn_no_series(array $chart): bool {
		$type = $chart['chart']['type'] ?? 'bar';
		$capabilities = BaraTables_Chart_Types::get((string) $type);
		return in_array('series', $capabilities['required_roles'], true) && empty($chart['chart']['series']);
	}

	/** Build the non-blocking notice shown after a Chart ID rename. */
	private function queue_chart_rename_notice(string $old_slug, string $new_slug, string $requested_id): void {
		BaraTables_Admin_Notice::queue_rename(
			$old_slug,
			$new_slug,
			$requested_id,
			/* translators: 1: the ID the user asked for, 2: the unique ID actually saved. */
			__('The Chart ID "%1$s" was already in use, so it was saved as "%2$s".', 'baratables'),
			/* translators: 1: old Chart ID, 2: new Chart ID. */
			__('Chart ID changed from "%1$s" to "%2$s". Update any [bara_chart id="%1$s"] you have already pasted into your content.', 'baratables')
		);
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
			'heatmap_x'      => $p::raw('btbl_chart_heatmap_x'),
			'heatmap_y'      => $p::raw('btbl_chart_heatmap_y'),
			'heatmap_value'  => $p::raw('btbl_chart_heatmap_value'),
		];
	}

}
