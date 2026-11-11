<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin {
	public const NONCE_ACTION = 'btbl_save';
	public const NONCE_FIELD = '_btbl_nonce';
	/**
	 * The pair 2.1.0 rendered the editor form with. 2.1.1 moved the pair into the btbl_
	 * family, so an editor page left open across the update posts the old one; refusing
	 * that save loses the administrator's work. The guards accept it once more, verified
	 * against its own action, and it goes with the 2.2.0 minor.
	 */
	public const LEGACY_NONCE_ACTION = 'baratables_save';
	public const LEGACY_NONCE_FIELD = '_baratables_nonce';

	private BaraTables_Service $service;
	private BaraTables_Repository $repo;
	private BaraTables_Admin_Assets $assets;
	private BaraTables_Admin_Action_Handler $actions;
	private BaraTables_Admin_Pages $pages;
	private BaraTables_Admin_Preview_Renderer $preview_renderer;
	private BaraTables_Entity_Persistence $persistence;
	private ?BaraTables_Frontend $frontend;

	public function __construct(BaraTables_Service $service, BaraTables_Repository $repo, string $plugin_url, string $plugin_path, ?BaraTables_Frontend $frontend = null) {
		$this->service = $service;
		$this->repo = $repo;
		$this->assets = new BaraTables_Admin_Assets($plugin_url, $plugin_path, $frontend);
		$this->actions = new BaraTables_Admin_Action_Handler($service);
		$this->pages = new BaraTables_Admin_Pages(self::NONCE_ACTION, self::NONCE_FIELD);
		$this->preview_renderer = new BaraTables_Admin_Preview_Renderer($service);
		$this->frontend = $frontend;
		$this->persistence = BaraTables_Entity_Persistence::from_descriptor(BaraTables_Entity_Descriptor::table());
		$list_columns = new BaraTables_Admin_List_Columns();
		$slug_manager = new BaraTables_Admin_Slug_Manager($repo);
		$options_page = new BaraTables_Admin_Options($service);
		// The site-wide default design screen (2.1.0): the third surface of the design
		// system, alongside the table editor's Design tab and the chart editor's.
		$design_screen = new BaraTables_Admin_Design_Screen($service, $frontend);

		add_action('save_post_' . BaraTables_Repository::CPT, [$slug_manager, 'ensure_slug_on_save'], 10, 2);
		add_action('added_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 3);
		add_action('updated_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 3);
		add_filter('manage_' . BaraTables_Repository::CPT . '_posts_columns', [$list_columns, 'register_list_columns']);
		add_action('manage_' . BaraTables_Repository::CPT . '_posts_custom_column', [$list_columns, 'render_list_columns'], 10, 2);
		add_action('admin_menu', [$options_page, 'register_menu']);
		add_action('admin_menu', [$design_screen, 'register_menu']);
		add_action('add_meta_boxes_' . BaraTables_Repository::CPT, [$this, 'register_meta_boxes']);
		add_action('save_post_' . BaraTables_Repository::CPT, [$this, 'save_table_from_editor'], 9, 3);
		add_action('admin_notices', ['BaraTables_Admin_Notice', 'render']);
		add_action('wp_ajax_btbl_refresh_preview', [$this, 'ajax_refresh_preview']);
		add_action('wp_ajax_btbl_refresh_fields', [$this, 'ajax_refresh_fields']);
		add_action('wp_ajax_btbl_fetch_remote_snapshot', [$this, 'ajax_fetch_remote_snapshot']);
		add_action('wp_ajax_btbl_block_tables', [$this, 'ajax_block_tables']);
		add_filter('admin_body_class', ['BaraTables_Help', 'body_class']);
		add_action('wp_ajax_btbl_toggle_help', ['BaraTables_Help', 'ajax_toggle']);
		(new BaraTables_Admin_Duplicator())->register();
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'btbl-table-builder',
			__('Table builder', 'baratables'),
			[$this, 'render_table_metabox'],
			BaraTables_Repository::CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'btbl-table-preview',
			__('Table preview', 'baratables'),
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
			echo '<p>' . esc_html__('Pick a data source and at least one column on the Columns &amp; filters tab, then save to preview the table here.', 'baratables') . '</p>';
			return;
		}

		$preview = $this->prepare_preview($definition);
		// Show a Refresh-preview button only once the builder has unsaved
		// edits (revealed by JS on the first change). No standing help text.
		echo '<p class="btbl-preview-toolbar" hidden>';
		echo '<button type="button" class="button btbl-icon-button" id="btbl-refresh-preview" aria-label="' . esc_attr__('Refresh preview', 'baratables') . '" title="' . esc_attr__('Refresh preview', 'baratables') . '"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>';
		echo '</p>';
		echo '<div class="btbl-admin btbl-admin-embed" id="btbl-preview-target">';
		$this->render_preview_embed($preview);
		echo '</div>';
	}

	/**
	 * The preview embed: the exact front-end render (same markup, payload, and asset pipeline
	 * as the shortcode), so styling and behavior changes can never diverge from what visitors
	 * see. The mock renderer this replaced could not show DataTables' stripes, hover, sorted
	 * tint, or control chrome, which made it useless as a style preview. Without a frontend
	 * wired in, fall back to the mock renderer.
	 */
	private function render_preview_embed(array $preview): void {
		if ($this->frontend === null) {
			$this->preview_renderer->render($preview['definition'], $preview['rows'], $preview['source_error'], 50, $preview['rows_truncated']);
			return;
		}
		if ($preview['source_error'] !== '') {
			echo '<div class="notice notice-error inline"><p>' . esc_html($preview['source_error']) . '</p></div>';
			return;
		}
		if (empty($preview['definition']['columns'])) {
			echo '<p>' . esc_html__('No columns selected yet for this table.', 'baratables') . '</p>';
			return;
		}
		if (empty($preview['rows'])) {
			echo '<p>' . esc_html__('No data available for this table yet.', 'baratables') . '</p>';
			return;
		}
		$rendered = $this->frontend->render_table_preview($preview['definition'], $preview['rows']);
		echo $rendered['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Front-end renderer output; escaped at every sink.
		if (!empty($preview['rows_truncated']) && $this->preview_has_footer($preview['definition'])) {
			// The aggregate footer is computed server-side over the capped preview rows, so the
			// note from the mock preview stays honest here too.
			echo '<p class="description">' . esc_html__('Footer totals cover the first 50 preview rows.', 'baratables') . '</p>';
		}
	}

	private function preview_has_footer(array $definition): bool {
		foreach ((array) ($definition['columns'] ?? []) as $column) {
			if (is_array($column) && ($column['footer_aggregate'] ?? 'none') !== 'none') {
				return true;
			}
		}
		return false;
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
		// build() gates its column reset on a non-POST request, because the legacy full-page
		// reload arrived as GET: a source switch, an emptied custom query, or changed CSV inputs
		// reset the column selection there. The old AJAX handler faked that by overwriting
		// $_SERVER['REQUEST_METHOD']; evaluating the same reset-worthy conditions here keeps the
		// no-reload refresh identical to the reload without touching superglobals, while an
		// unchanged refresh keeps its columns.
		$original_source = BaraTables_Source_Type::normalize($existing['source_type'] ?? BaraTables_Source_Type::WP_QUERY);
		$requested_source = BaraTables_Source_Type::normalize($preview['source'], $original_source);
		$query_emptied = $requested_source === BaraTables_Source_Type::CUSTOM_QUERY
			&& isset($preview['custom_query'])
			&& empty($this->service->sanitize_custom_query_json($preview['custom_query']));
		$has_csv_params = isset($raw['csv_id']) || isset($raw['csv_delim']) || isset($raw['csv_header']);
		$preview['request_method'] = ($requested_source !== $original_source || $query_emptied || $has_csv_params) ? 'GET' : 'POST';

		$context_builder = new BaraTables_Admin_Form_Context($this->service);
		$context = $context_builder->build($existing, $preview);

		wp_send_json_success([
			'columns' => $this->pages->render_columns_panel($context),
			'source'  => $this->pages->render_source_panel($context),
		]);
	}

	/**
	 * Table list for the block editor's table picker. Any user who can edit posts may embed a
	 * published table, but table management stays admin-only: non-admins list published tables
	 * only, so drafts and private tables are not leaked through the picker.
	 */
	public function ajax_block_tables(): void {
		check_ajax_referer('btbl_block_tables');
		if (!current_user_can('edit_posts')) {
			wp_send_json_error([], 403);
		}
		$search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
		$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
		$statuses = current_user_can('manage_options') ? [] : ['publish'];
		wp_send_json_success($this->repo->search_definition_choices($search, $page, 20, $statuses));
	}

	public function ajax_refresh_preview(): void {
		BaraTables_Admin_Ajax_Guard::verify(self::NONCE_ACTION, self::NONCE_FIELD);

		// Reuse the exact save pipeline so the preview never diverges from what would persist --
		// including the existing definition (loaded the same way save does), so deselecting every
		// column on an existing table previews as empty instead of injecting a default Title column.
		$existing = $this->get_ajax_table_definition();
		$request = $this->actions->collect_table_request_data($existing);
		$definition = $this->actions->apply_request_to_definition($request, $existing, !empty($existing));
		$definition['id'] = isset($_POST['btbl_table_id']) ? sanitize_text_field(wp_unslash($_POST['btbl_table_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX nonce and capability verified above.

		$preview = $this->prepare_preview($definition);

		ob_start();
		$this->render_preview_embed($preview);
		$html = ob_get_clean();
		// The real render also captures its JS payload (render_table_preview); the client uses
		// it to boot the freshly swapped markup. Empty for the notice fallbacks and the legacy
		// mock renderer.
		$payload = $this->frontend !== null ? ($this->frontend->lastPreviewPayload() ?? []) : [];

		wp_send_json_success(['html' => $html, 'payload' => $payload]);
	}

	/** Fetch only the already-saved remote config, then atomically replace only its snapshot. */
	public function ajax_fetch_remote_snapshot(): void {
		BaraTables_Admin_Ajax_Guard::verify(self::NONCE_ACTION, self::NONCE_FIELD);
		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX nonce and capability verified above.
		$post = $post_id > 0 ? get_post($post_id) : null;
		if (!$post instanceof WP_Post || $post->post_type !== BaraTables_Repository::CPT) {
			wp_send_json_error(['message' => __('Save the table before fetching a remote snapshot.', 'baratables')]);
		}
		$definition = get_post_meta($post_id, BaraTables_Repository::META_KEY, true);
		if (!is_array($definition) || !BaraTables_Source_Type::is_remote_snapshot($definition['source_type'] ?? '')) {
			wp_send_json_error(['message' => __('Save remote snapshot as the data source before fetching.', 'baratables')]);
		}
		$config = isset($definition['remote_source']) && is_array($definition['remote_source']) ? $definition['remote_source'] : [];
		$snapshot = BaraTables_Remote_Snapshot::fetch(
			(string) ($config['url'] ?? ''),
			(string) ($config['format'] ?? ''),
			$this->service
		);
		if (is_wp_error($snapshot)) {
			wp_send_json_error(['message' => $snapshot->get_error_message()]);
		}

		$definition = BaraTables_Remote_Snapshot::commit_fetched_snapshot($post_id, $config, $snapshot, $this->service);
		if (is_wp_error($definition)) {
			wp_send_json_error(['message' => $definition->get_error_message()]);
		}
		wp_send_json_success([
			'message' => __('Remote snapshot updated. Reloading the editor...', 'baratables'),
			'row_count' => (int) $snapshot['row_count'],
			'column_count' => (int) $snapshot['column_count'],
		]);
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

		$snapshot = $this->persistence->snapshot($post_id, BaraTables_Post_Input::raw('original_post_status'));
		// The stored definition is read FIRST: a design token whose submitted value the
		// server would reject keeps the stored value instead of being deleted.
		$existing = $this->get_existing_table_definition_for_post($post);
		// The compare-and-set needs the RAW stored value. The helper above normalises
		// what it returns, and a normalised value is not a valid expected value: the
		// meta column holds the serialised sanitised definition, so only the raw read
		// matches it.
		$expected_definition = get_post_meta($post_id, BaraTables_Repository::META_KEY, true);
		$request = $this->actions->collect_table_request_data($existing);
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
				__('The table ID could not be saved. The previous ID was kept.', 'baratables'),
				'error'
			);
		}
		$definition['id'] = $slug;
		$definition['name'] = $request['name'] !== '' ? $request['name'] : ($definition['name'] ?? $post->post_title);
		$definition['status'] = $post->post_status;

		// The write is a compare-and-set: it lands only while the stored definition is
		// still the one this request read. Without it, two administrators who open the
		// same table and both save would silently lose the first administrator's edits.
		$persist_error = BaraTables_Base_Repository::persist_if_unchanged(
			$post_id,
			BaraTables_Repository::META_KEY,
			BaraTables_Repository::META_SLUG,
			$expected_definition,
			$definition,
			$slug
		);
		if ($persist_error) {
			if ($persist_error->get_error_code() === 'baratables_definition_conflict') {
				// Another administrator saved this table while this request was running.
				// Their save is the current state, so nothing is rolled back: rolling back
				// would destroy the work they just committed. Only the post fields this
				// save changed are undone, and the administrator is told what happened.
				$this->restore_conflicting_post_fields($post_id, $post, $snapshot, $slug);
				BaraTables_Admin_Notice::queue(
					__('This table changed while you were editing it, so your changes were not saved. Reload the editor and re-apply them.', 'baratables'),
					'error'
				);
				return;
			}
			$rollback_error = $this->persistence->restore($post_id, $snapshot, [$this, 'save_table_from_editor'], 3);
			BaraTables_Admin_Notice::queue(
				$rollback_error
					? __('The table could not be saved, and WordPress could not completely restore its previous data. Retry the save before editing it again.', 'baratables')
					: __('The table could not be saved. Its previous data and table ID were restored.', 'baratables'),
				'error'
			);
			return;
		}

		// A design value the server would not accept keeps the one already stored, so the
		// change does not take effect. Say so: without this the user sees their table look
		// unchanged and has no way to know why.
		$rejected_notice = BaraTables_Admin_Design_Fields::rejected_values_notice($this->service->rejected_design_values());
		if ($rejected_notice !== '') {
			BaraTables_Admin_Notice::queue($rejected_notice, 'warning');
		}

		if ($identity['changed'] && $old_slug !== '') {
			// Commit linked charts only after the table itself is durable. The checked rewrite rolls
			// every chart back if one write fails; then restore the table so no split identity remains.
			$rewrite = $this->service->rewrite_chart_table_id_checked($old_slug, $slug);
			if ($rewrite['error']) {
				$rollback_error = $this->persistence->restore($post_id, $snapshot, [$this, 'save_table_from_editor'], 3);
				$error_data = $rewrite['error']->get_error_data();
				$failed_chart_ids = is_array($error_data) && isset($error_data['rollback_failed_chart_ids']) && is_array($error_data['rollback_failed_chart_ids'])
					? array_values(array_unique(array_map('intval', $error_data['rollback_failed_chart_ids'])))
					: [];
				if (!$rollback_error && !empty($failed_chart_ids)) {
					$queue_error = BaraTables_Chart_Link_Recovery::queue($failed_chart_ids, $slug, $old_slug);
					BaraTables_Admin_Notice::queue(
						$queue_error
							? sprintf(
								/* translators: %s is a comma-separated list of chart post IDs. */
								__('The table ID was restored, but linked chart post IDs %s could not be restored or queued for automatic recovery. Retry the save before editing those charts.', 'baratables'),
								esc_html(implode(', ', $failed_chart_ids))
							)
							: sprintf(
								/* translators: %d is the number of linked charts queued for recovery. */
								_n('The table ID was restored. %d linked chart will be restored automatically on the next admin request.', 'The table ID was restored. %d linked charts will be restored automatically on the next admin request.', count($failed_chart_ids), 'baratables'),
								count($failed_chart_ids)
							),
						'error'
					);
				} else {
					BaraTables_Admin_Notice::queue(
						$rollback_error
							? __('The table ID and linked charts could not be updated consistently. Retry the save before editing them again.', 'baratables')
							: __('The table ID change was cancelled because a linked chart could not be updated. Previous data was restored.', 'baratables'),
						'error'
					);
				}
				return;
			}
			$this->service->delete_server_side_rate_limits($old_slug);
			$this->queue_table_rename_notice($old_slug, $slug, $requested_id, $rewrite['updated']);
		}

		// Report what happened to the external DB connection (kept-because-invalid, cleared, or
		// incomplete) only once the whole save is known to have succeeded.
		self::queue_external_db_notice($request, $existing);
		self::queue_first_publish_notice($snapshot, (string) $post->post_status, $slug);

		// Warn without blocking if the saved table has no effective columns and will render nothing.
		$effective = $this->service->resolve_columns($definition);
		if (empty($effective['columns'])) {
			BaraTables_Admin_Notice::queue(
				__('This table has no columns, so it will not display anything. Select at least one column on the Columns &amp; filters tab, then update.', 'baratables'),
				'warning'
			);
		}

		// Warn when typed value-override rules do not survive JSON parsing.
		$overrides_raw = trim((string) ($request['value_overrides_raw_input'] ?? ''));
		if ($overrides_raw !== '' && empty($request['value_overrides'])) {
			BaraTables_Admin_Notice::queue(
				__('Value overrides were not valid JSON, so no rules were saved. Check the JSON on the Advanced tab.', 'baratables'),
				'warning'
			);
		} elseif ($overrides_raw !== '') {
			// Flag invalid regex rules, which otherwise pass values through unchanged.
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

	/**
	 * Undo only the post fields this save changed, after a compare-and-set conflict.
	 *
	 * The stored definition belongs to the administrator who saved it last, so it is
	 * never rolled back here. The post fields -- title, table ID and status -- are this
	 * save's own changes, so they are restored to make the refusal coherent. Each
	 * field is restored only while it still holds the value this save wrote, so a
	 * change another administrator committed in the meantime is never overwritten.
	 */
	private function restore_conflicting_post_fields(int $post_id, WP_Post $post, array $snapshot, string $written_slug): void {
		$restore = [];
		$current_name = (string) get_post_field('post_name', $post_id, 'raw');
		if ($written_slug !== '' && $current_name === $written_slug && (string) ($snapshot['post_name'] ?? '') !== $current_name) {
			$restore['post_name'] = (string) $snapshot['post_name'];
		}
		$current_title = (string) get_post_field('post_title', $post_id, 'raw');
		if ($current_title === (string) $post->post_title && (string) ($snapshot['post_title'] ?? '') !== $current_title) {
			$restore['post_title'] = (string) $snapshot['post_title'];
		}
		$current_status = (string) get_post_field('post_status', $post_id, 'raw');
		if ($current_status === (string) $post->post_status && (string) ($snapshot['post_status'] ?? '') !== $current_status) {
			$restore['post_status'] = (string) $snapshot['post_status'];
		}
		if (empty($restore)) {
			return;
		}

		// The post update must not run this handler again, and the persistence guard
		// keeps the slug-repair hooks out of a coordinated write.
		$save_hook = 'save_post_' . BaraTables_Repository::CPT;
		$callback_registered = has_action($save_hook, [$this, 'save_table_from_editor']) !== false;
		if ($callback_registered) {
			remove_action($save_hook, [$this, 'save_table_from_editor'], 9);
		}
		BaraTables_Persistence_Guard::begin();
		try {
			wp_update_post(array_merge(['ID' => $post_id], $restore), true);
		} finally {
			BaraTables_Persistence_Guard::end();
			if ($callback_registered) {
				add_action($save_hook, [$this, 'save_table_from_editor'], 9, 3);
			}
		}
	}

	/**
	 * The editor's guidance used to stop at "Publish": nothing told a first-time user the final
	 * step is pasting the shortcode into a page. Fire once, on the transition into publish.
	 */
	public static function queue_first_publish_notice(array $snapshot, string $new_status, string $slug): void {
		if ($new_status !== 'publish' || ((string) ($snapshot['post_status'] ?? '')) === 'publish') {
			return;
		}
		BaraTables_Admin_Notice::queue(
			sprintf(
				/* translators: %s: the table shortcode to paste into content. */
				__('Table published. Add it to any page or post with the shortcode %s, or choose the BaraTables table block in the block editor.', 'baratables'),
				'<code>[bara_table id="' . esc_html($slug) . '"]</code>'
			),
			'success'
		);
	}

	/**
	 * Report what the save did with the external DB connection. The definition merge keeps the
	 * previous connection when the posted config is invalid, and clears it when every field was
	 * posted empty; without these notices both outcomes happen silently, and an invalid edit
	 * keeps serving the old table with no signal at all.
	 */
	public static function queue_external_db_notice(array $request, ?array $existing_definition): void {
		if (($request['source_type'] ?? '') !== BaraTables_Source_Type::EXTERNAL_DB || !empty($request['external_db'])) {
			return;
		}
		$had_connection = !empty($existing_definition['external_db']);
		if (!empty($request['external_db_input_nonempty'])) {
			if ($had_connection) {
				BaraTables_Admin_Notice::queue(
					__('The external database settings were not valid, so the previous connection was kept. The table name may only contain letters, numbers, and underscores. Clear every connection field to remove the connection.', 'baratables'),
					'error'
				);
			} else {
				BaraTables_Admin_Notice::queue(
					__('The external database settings are not valid or incomplete. Fill in host, database, user, and table to connect.', 'baratables'),
					'warning'
				);
			}
		} elseif ($had_connection) {
			BaraTables_Admin_Notice::queue(
				__('The external database connection was removed from this table.', 'baratables'),
				'info'
			);
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
			__('The table ID "%1$s" was already in use, so it was saved as "%2$s".', 'baratables'),
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

	/** @return array{definition:array,rows:array,source_error:string,rows_truncated:bool} */
	private function prepare_preview(array $definition): array {
		// Fetch one row PAST the display cap so an exact-fit source can be told apart from a
		// truncated one -- the footer-coverage notice is only honest when something was cut.
		$result = $this->service->get_row_result($definition, 51);
		$definition = $this->service->definition_with_inferred_columns($definition, $result);
		$all_rows = $result->rows();
		$truncated = count($all_rows) > 50;
		return [
			'definition' => $definition,
			'rows' => $this->preview_renderer->sort($truncated ? array_slice($all_rows, 0, 50) : $all_rows, $definition),
			'source_error' => $result->has_error() ? $result->error_message() : '',
			'rows_truncated' => $truncated,
		];
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
	private BaraTables_Entity_Persistence $persistence;
	private ?BaraTables_Frontend $frontend;
	private BaraTables_Admin_Tab_Chart_Design $tab_chart_design;

	public function __construct(BaraTables_Chart_Service $chart_service, BaraTables_Chart_Repository $chart_repo, BaraTables_Service $table_service, string $nonce_action, string $nonce_field, ?BaraTables_Frontend $frontend = null) {
		$this->chart_service = $chart_service;
		$this->chart_repo = $chart_repo;
		$this->table_service = $table_service;
		$this->frontend = $frontend;
		$this->tab_chart = new BaraTables_Admin_Tab_Chart();
		$this->tab_chart_design = new BaraTables_Admin_Tab_Chart_Design();
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
		add_action('wp_ajax_btbl_search_chart_tables', [$this, 'ajax_search_chart_tables']);
		add_action('wp_ajax_btbl_block_charts', [$this, 'ajax_block_charts']);
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'btbl-chart-builder',
			__('Chart builder', 'baratables'),
			[$this, 'render_chart_metabox'],
			BaraTables_Chart_Repository::CPT,
			'normal',
			'high'
		);

		// Provide the chart editor with the same live-preview behavior as the table editor.
		add_meta_box(
			'btbl-chart-preview',
			__('Chart preview', 'baratables'),
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
		// Reuse the exact front-end renderer (it registers + enqueues ECharts on demand), so the
		// admin preview can never diverge from what visitors see -- except that drafts render
		// here: the publish gate belongs to the visitor-facing shortcode, not to the editor's
		// own preview, so Save Draft no longer shows "Chart not found." for the chart being
		// edited. Without a renderer wired in, fall back to the published-only shortcode.
		$output = $this->frontend
			? $this->frontend->render_chart_by_id($chart_id, false)
			: do_shortcode('[bara_chart id="' . esc_attr($chart_id) . '"]');
		echo '<div class="btbl-admin btbl-admin-embed btbl-chart-preview-embed">' . $output . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is escaped at every sink.
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

	public function ajax_search_chart_tables(): void {
		BaraTables_Admin_Ajax_Guard::verify($this->nonce_action, $this->nonce_field);
		$search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard verified above.
		$page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard verified above.
		wp_send_json_success($this->chart_service->search_table_choices($search, $page));
	}

	/**
	 * Chart list for the block editor's chart picker. Same policy as the table picker
	 * (ajax_block_tables): any user who can edit posts may embed a published chart, but chart
	 * management stays admin-only, so non-admins list published charts only and drafts and
	 * private charts are not leaked through the picker.
	 */
	public function ajax_block_charts(): void {
		check_ajax_referer('btbl_block_charts');
		if (!current_user_can('edit_posts')) {
			wp_send_json_error([], 403);
		}
		$search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
		$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
		$statuses = current_user_can('manage_options') ? [] : ['publish'];
		wp_send_json_success($this->chart_repo->search_definition_choices($search, $page, 20, $statuses));
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
				<h2 class="nav-tab-wrapper btbl-nav-tab-wrapper" role="tablist" aria-label="<?php esc_attr_e('Chart settings', 'baratables'); ?>">
					<a href="#btbl-tab-chart" id="btbl-tab-chart-label" role="tab" aria-controls="btbl-tab-chart" aria-selected="true" class="nav-tab nav-tab-active btbl-tab-link" data-target="btbl-tab-chart"><?php esc_html_e('Chart', 'baratables'); ?></a>
					<a href="#btbl-tab-chart-design" id="btbl-tab-chart-design-label" role="tab" aria-controls="btbl-tab-chart-design" aria-selected="false" class="nav-tab btbl-tab-link" data-target="btbl-tab-chart-design"><?php esc_html_e('Design', 'baratables'); ?></a>
				</h2>
				<?php
				$this->tab_chart->render($panel, $panel['column_choices']);
				$this->tab_chart_design->render($panel);
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
			// WordPress still publishes the post, so the shortcode would render
			// "Chart not found." with no explanation. Tell the user why and what to do.
			$message = $table_id === ''
				? __('This chart has no source table, so it will show "Chart not found." Edit the chart and choose one.', 'baratables')
				: __('This chart\'s table no longer exists, so it will show "Chart not found." Edit the chart and choose another.', 'baratables');
			BaraTables_Admin_Notice::queue($message, 'error');
			// Do NOT bail before persisting: the post is published regardless, so returning here
			// would silently discard the user's name/option edits and any simultaneous Chart ID
			// rename. Save what we have so their work survives; they can fix the table and update.
		}

		$snapshot = $this->persistence->snapshot($post_id, BaraTables_Post_Input::raw('original_post_status'));
		// The compare-and-set needs the RAW stored value. The lookup above normalises
		// what it returns, and a normalised value is not a valid expected value: the
		// meta column holds the serialised sanitised definition, so only the raw read
		// matches it.
		$expected_definition = get_post_meta($post_id, BaraTables_Chart_Repository::META_KEY, true);
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
				__('The chart ID could not be saved. The previous ID was kept.', 'baratables'),
				'error'
			);
		}
		$chart['id'] = $slug;
		$chart['name'] = $name !== '' ? $name : ($chart['name'] ?? $post->post_title);
		$chart['status'] = $post->post_status;

		// The write is a compare-and-set: it lands only while the stored definition is
		// still the one this request read, so two administrators saving the same chart
		// cannot silently lose the first administrator's edits.
		$persist_error = BaraTables_Base_Repository::persist_if_unchanged(
			$post_id,
			BaraTables_Chart_Repository::META_KEY,
			BaraTables_Chart_Repository::META_SLUG,
			$expected_definition,
			$chart,
			$slug
		);
		if ($persist_error) {
			if ($persist_error->get_error_code() === 'baratables_definition_conflict') {
				// Another administrator saved this chart while this request was running.
				// Their save is the current state, so nothing is rolled back: rolling
				// back would destroy the work they just committed.
				$this->restore_conflicting_post_fields($post_id, $post, $snapshot, $slug);
				BaraTables_Admin_Notice::queue(
					__('This chart changed while you were editing it, so your changes were not saved. Reload the editor and re-apply them.', 'baratables'),
					'error'
				);
				return;
			}
			$rollback_error = $this->persistence->restore($post_id, $snapshot, [$this, 'save_chart_from_editor'], 2);
			BaraTables_Admin_Notice::queue(
				$rollback_error
					? __('The chart could not be saved, and WordPress could not completely restore its previous data. Retry the save before editing it again.', 'baratables')
					: __('The chart could not be saved. Its previous data and chart ID were restored.', 'baratables'),
				'error'
			);
			return;
		}
		// A design value the server would not accept keeps the one already stored, so the
		// change does not take effect. Say so: without this the user sees their chart look
		// unchanged and has no way to know why.
		$rejected_notice = BaraTables_Admin_Design_Fields::rejected_values_notice($this->chart_service->rejected_design_values());
		if ($rejected_notice !== '') {
			BaraTables_Admin_Notice::queue($rejected_notice, 'warning');
		}
		if ($identity['changed'] && $old_slug !== '') {
			// Nothing references a chart by id (charts reference tables, not vice versa), so
			// no forward-rewrite is needed -- only flag the [bara_chart] embeds we cannot reach.
			$this->queue_chart_rename_notice($old_slug, $slug, $requested_id);
		}

		// A chart with no data series renders empty, so warn without blocking. Skip when the table
		// is missing: the "Chart not found" error above already covers it and is the actionable one.
		if (!$table_missing && self::should_warn_no_series($chart)) {
			BaraTables_Admin_Notice::queue(
				__('This chart has no series selected, so it will render empty. Choose at least one series column.', 'baratables'),
				'warning'
			);
		}
	}

	/**
	 * Undo only the post fields this save changed, after a compare-and-set conflict.
	 *
	 * The stored definition belongs to the administrator who saved it last, so it is
	 * never rolled back here. The post fields -- title, chart ID and status -- are this
	 * save's own changes, so they are restored to make the refusal coherent. Each
	 * field is restored only while it still holds the value this save wrote, so a
	 * change another administrator committed in the meantime is never overwritten.
	 */
	private function restore_conflicting_post_fields(int $post_id, WP_Post $post, array $snapshot, string $written_slug): void {
		$restore = [];
		$current_name = (string) get_post_field('post_name', $post_id, 'raw');
		if ($written_slug !== '' && $current_name === $written_slug && (string) ($snapshot['post_name'] ?? '') !== $current_name) {
			$restore['post_name'] = (string) $snapshot['post_name'];
		}
		$current_title = (string) get_post_field('post_title', $post_id, 'raw');
		if ($current_title === (string) $post->post_title && (string) ($snapshot['post_title'] ?? '') !== $current_title) {
			$restore['post_title'] = (string) $snapshot['post_title'];
		}
		$current_status = (string) get_post_field('post_status', $post_id, 'raw');
		if ($current_status === (string) $post->post_status && (string) ($snapshot['post_status'] ?? '') !== $current_status) {
			$restore['post_status'] = (string) $snapshot['post_status'];
		}
		if (empty($restore)) {
			return;
		}

		// The post update must not run this handler again, and the persistence guard
		// keeps the slug-repair hooks out of a coordinated write.
		$save_hook = 'save_post_' . BaraTables_Chart_Repository::CPT;
		$callback_registered = has_action($save_hook, [$this, 'save_chart_from_editor']) !== false;
		if ($callback_registered) {
			remove_action($save_hook, [$this, 'save_chart_from_editor'], 9);
		}
		BaraTables_Persistence_Guard::begin();
		try {
			wp_update_post(array_merge(['ID' => $post_id], $restore), true);
		} finally {
			BaraTables_Persistence_Guard::end();
			if ($callback_registered) {
				add_action($save_hook, [$this, 'save_chart_from_editor'], 9, 2);
			}
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
			__('The chart ID "%1$s" was already in use, so it was saved as "%2$s".', 'baratables'),
			/* translators: 1: old Chart ID, 2: new Chart ID. */
			__('Chart ID changed from "%1$s" to "%2$s". Update any [bara_chart id="%1$s"] you have already pasted into your content.', 'baratables')
		);
	}

	public function collect_chart_options_from_request(): array {
		$p = BaraTables_Post_Input::class;
		$options = [
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
			// Design tab (2.0): the palette arrives as a JSON array of hex strings from the
			// chip editor; merge_chart_options() re-sanitizes every value, so decode is the
			// only step here.
			'stylePalette'   => BaraTables_Admin_Design_Fields::decode_palette_input($p::raw('btbl_chart_style_palette')),
			'styleBackground' => $p::raw('btbl_chart_style_background'),
			'styleTextColor' => $p::raw('btbl_chart_style_text_color'),
			'styleGridColor' => $p::raw('btbl_chart_style_grid_color'),
			'styleFontSize'  => $p::raw('btbl_chart_style_font_size'),
			// The chart container's shape (2.1.0): CSS-visible, so they ride the same
			// custom-property layer the table's tokens use.
			'stylePadding'   => $p::raw('btbl_chart_style_padding'),
			'styleRadius'    => $p::raw('btbl_chart_style_radius'),
		];
		// The site-design opt-out (2.1.0). A hidden "0" twin sits in front of the
		// checkbox, so an unchecked box submits the opt-out rather than nothing.
		//
		// A MISSING field is not an opt-out. It means the request never carried the
		// toggle, and reading that as "switched off" would drop the site design on a
		// save that did not touch it, so an absent field leaves the key out entirely.
		$use_site_design = $p::raw('btbl_chart_use_site_design');
		if ((string) $use_site_design !== '') {
			$options['useSiteDesign'] = $use_site_design;
		}
		return $options;
	}

}
