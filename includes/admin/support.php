<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Post_Input {
	public static function text(string $key, string $default = ''): string {
		return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
	}

	public static function raw(string $key, string $default = ''): string {
		return isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Intentionally returns raw value; caller handles context-specific sanitization.
	}

	public static function int(string $key, int $default = 0): int {
		return isset($_POST[$key]) ? (int) wp_unslash($_POST[$key]) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Sanitized by int cast.
	}

	public static function bool(string $key): bool {
		return !empty($_POST[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller. No unslash needed -- truthiness check only.
	}

	public static function array_raw(string $key): array {
		return isset($_POST[$key]) ? (array) wp_unslash($_POST[$key]) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Intentionally returns raw array; caller handles sanitization.
	}

	public static function array_text(string $key): array {
		return isset($_POST[$key]) ? array_map('sanitize_text_field', (array) wp_unslash($_POST[$key])) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
	}

	/**
	 * Read a declared request boundary without scattering direct superglobal access across the
	 * business-level collector. Schema entries are [reader method, posted field name].
	 */
	public static function collect(array $schema): array {
		$allowed = ['text', 'raw', 'int', 'bool', 'array_raw', 'array_text'];
		$out = [];
		foreach ($schema as $name => $spec) {
			$reader = isset($spec[0]) ? (string) $spec[0] : '';
			$field = isset($spec[1]) ? (string) $spec[1] : '';
			if (!in_array($reader, $allowed, true) || $field === '') {
				continue;
			}
			$out[$name] = self::{$reader}($field);
		}
		return $out;
	}

}


/**
 * Queues admin notices across the post-save redirect using a short-lived,
 * per-user transient. It tells the user when a save produced something
 * that will not render (e.g. a table with no columns, a chart with no table).
 */
class BaraTables_Admin_Notice {
	private const TRANSIENT_PREFIX = 'btbl_admin_notice_';
	private const ALLOWED_TYPES = ['success', 'warning', 'error', 'info'];

	public static function queue(string $message, string $type = 'warning'): void {
		if ($message === '') {
			return;
		}
		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return;
		}
		$key = self::TRANSIENT_PREFIX . $user_id;
		$notices = get_transient($key);
		if (!is_array($notices)) {
			$notices = [];
		}
		$notices[] = [
			'message' => $message,
			'type' => in_array($type, self::ALLOWED_TYPES, true) ? $type : 'warning',
		];
		set_transient($key, $notices, MINUTE_IN_SECONDS);
	}

	public static function queue_rename(string $old_slug, string $new_slug, string $requested_slug, string $collision_template, string $changed_template, string $suffix = ''): void {
		$parts = [];
		if ($requested_slug !== '' && $new_slug !== $requested_slug) {
			$parts[] = sprintf($collision_template, $requested_slug, $new_slug);
		}
		$parts[] = sprintf($changed_template, $old_slug, $new_slug);
		if ($suffix !== '') {
			$parts[] = $suffix;
		}
		self::queue(implode(' ', $parts), 'info');
	}

	public static function render(): void {
		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return;
		}
		$key = self::TRANSIENT_PREFIX . $user_id;
		$notices = get_transient($key);
		if (!is_array($notices) || empty($notices)) {
			return;
		}
		delete_transient($key);
		foreach ($notices as $notice) {
			$type = in_array($notice['type'] ?? 'warning', self::ALLOWED_TYPES, true) ? $notice['type'] : 'warning';
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr($type),
				wp_kses_post((string) ($notice['message'] ?? ''))
			);
		}
	}
}


/**
 * Per-user "Show help text" preference. Lets a power user who has built many
 * tables opt out of the always-visible orientation hints (marked .btbl-help-text)
 * without affecting first-time users (default: help shown). Conditional notices,
 * collapsed-gear hints, tooltips and confirms are intentionally NOT governed.
 */
class BaraTables_Help {
	private const META_KEY = 'btbl_hide_help';
	private const NONCE = 'btbl_help_toggle';

	public static function hidden(): bool {
		$user_id = get_current_user_id();
		return $user_id > 0 && (bool) get_user_meta($user_id, self::META_KEY, true);
	}

	public static function body_class(string $classes): string {
		return self::hidden() ? trim($classes . ' btbl-help-hidden') : $classes;
	}

	public static function render_toggle(): void {
		$hidden = self::hidden();
		$label = $hidden ? __('Show help text', 'baratables') : __('Hide help text', 'baratables');
		printf(
			'<button type="button" class="btbl-help-toggle" id="btbl-help-toggle" data-nonce="%1$s" data-hide-label="%2$s" data-show-label="%3$s" title="%4$s" aria-label="%4$s"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button>',
			esc_attr(wp_create_nonce(self::NONCE)),
			esc_attr__('Hide help text', 'baratables'),
			esc_attr__('Show help text', 'baratables'),
			esc_attr($label)
		);
	}

	public static function ajax_toggle(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		check_ajax_referer(self::NONCE, '_wpnonce');
		$user_id = get_current_user_id();
		$hide = isset($_POST['hide']) && sanitize_text_field(wp_unslash($_POST['hide'])) === '1';
		if ($hide) {
			update_user_meta($user_id, self::META_KEY, 1);
		} else {
			delete_user_meta($user_id, self::META_KEY);
		}
		wp_send_json_success(['hidden' => $hide]);
	}

	/** True only on a brand-new site/account that has no saved tables yet. */
	public static function is_first_table(): bool {
		$counts = wp_count_posts(BaraTables_Repository::CPT);
		$existing = (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0)
			+ (int) ($counts->private ?? 0) + (int) ($counts->future ?? 0) + (int) ($counts->pending ?? 0);
		return $existing === 0;
	}
}


/**
 * Adds a "Duplicate" row action to the Tables and Charts lists. Copies the
 * post + its definition meta into a new draft with a freshly-minted slug/id so
 * the clone's shortcode never collides with the original.
 */
class BaraTables_Admin_Duplicator {
	public function register(): void {
		add_filter('post_row_actions', [$this, 'add_action'], 10, 2);
		add_action('admin_action_btbl_duplicate', [$this, 'handle']);
	}

	private function cpt_map(): array {
		$table = BaraTables_Entity_Descriptor::table();
		$chart = BaraTables_Entity_Descriptor::chart();
		return [$table['cpt'] => $table, $chart['cpt'] => $chart];
	}

	public function add_action(array $actions, WP_Post $post): array {
		if (!isset($this->cpt_map()[$post->post_type]) || !current_user_can('edit_post', $post->ID)) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url('admin.php?action=btbl_duplicate&post=' . (int) $post->ID),
			'btbl_duplicate_' . $post->ID
		);
		$actions['btbl_duplicate'] = '<a href="' . esc_url($url) . '">' . esc_html__('Duplicate', 'baratables') . '</a>';
		return $actions;
	}

	public function handle(): void {
		$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ($post_id <= 0) {
			wp_die(esc_html__('Invalid duplicate request.', 'baratables'));
		}
		check_admin_referer('btbl_duplicate_' . $post_id);

		$post = get_post($post_id);
		$map = $this->cpt_map();
		if (!$post instanceof WP_Post || !isset($map[$post->post_type])) {
			wp_die(esc_html__('Invalid duplicate request.', 'baratables'));
		}
		if (!current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('You are not allowed to duplicate this item.', 'baratables'));
		}

		$new_id = $this->duplicate_post($post_id);
		if (is_wp_error($new_id)) {
			wp_die(esc_html($new_id->get_error_message()));
		}

		wp_safe_redirect(admin_url('post.php?post=' . (int) $new_id . '&action=edit'));
		exit;
	}

	/**
	 * Copy a Table/Chart post + its definition into a new draft with a fresh slug/id.
	 *
	 * @return int|WP_Error New post ID on success.
	 */
	public function duplicate_post(int $post_id) {
		$post = get_post($post_id);
		$map = $this->cpt_map();
		if (!$post instanceof WP_Post || !isset($map[$post->post_type])) {
			return new WP_Error('btbl_invalid_duplicate', __('Invalid duplicate request.', 'baratables'));
		}
		$conf = $map[$post->post_type];
		$definition = get_post_meta($post_id, $conf['meta_key'], true);
		if (!is_array($definition) || empty($definition)) {
			return new WP_Error('btbl_duplicate_missing_definition', __('The original item has no saved definition to duplicate.', 'baratables'));
		}

		/* translators: %s is the original item title. */
		$new_title = sprintf(__('%s (copy)', 'baratables'), $post->post_title);
		$new_id = wp_insert_post([
			'post_type' => $post->post_type,
			'post_status' => 'draft',
			'post_title' => $new_title,
		], true);
		if (is_wp_error($new_id)) {
			return $new_id;
		}

		$new_post = get_post((int) $new_id);
		if (!$new_post instanceof WP_Post) {
			return $this->cleanup_failed_duplicate((int) $new_id, new WP_Error('btbl_duplicate_missing_post', __('The duplicated item could not be loaded.', 'baratables')));
		}
		$persistence = BaraTables_Entity_Persistence::from_descriptor($conf);
		// Drafts bypass WordPress' normal slug-collision check, so use the entity identity
		// coordinator instead of calling wp_unique_post_slug() with the draft status.
		$base = sanitize_title($new_title) ?: ('btbl-copy-' . $new_id);
		$new_slug = $persistence->unique_slug($base, (int) $new_id, $new_post);
		$definition['id'] = $new_slug;
		$definition['name'] = $new_title;
		$definition['status'] = 'draft';
		$error = $persistence->repair((int) $new_id, $new_post, $definition, $new_slug);
		if ($error instanceof WP_Error) {
			return $this->cleanup_failed_duplicate((int) $new_id, $error);
		}

		return (int) $new_id;
	}

	private function cleanup_failed_duplicate(int $post_id, WP_Error $original): WP_Error {
		$cleanup = BaraTables_Post_Cleanup::delete_or_quarantine($post_id);
		if (!$cleanup) {
			return $original;
		}
		$data = $cleanup->get_error_data();
		return new WP_Error(
			'btbl_duplicate_cleanup_failed',
			sprintf(
				/* translators: %d is the WordPress post ID of an incomplete draft. */
				__('The item could not be duplicated. An incomplete, non-public draft remains (post ID %d).', 'baratables'),
				$post_id
			),
			[
				'post_id' => $post_id,
				'original_error' => $original->get_error_code(),
				'cleanup' => is_array($data) ? $data : [],
			]
		);
	}
}


class BaraTables_Admin_Page_Utils {
	public static function render_shortcode_cell(string $shortcode): string {
		// Accessible click-to-copy: the label includes the shortcode so list rows
		// stay distinguishable to screen readers, and "Copied" is localized via a data attribute.
		return sprintf(
			'<code class="btbl-shortcode btbl-shortcode--copy" data-shortcode="%1$s" data-copied-label="%2$s" tabindex="0" role="button" title="%3$s" aria-label="%3$s">%4$s</code>',
			esc_attr($shortcode),
			esc_attr__('Copied', 'baratables'),
			esc_attr(sprintf(/* translators: %s is the shortcode. */ __('Copy shortcode: %s', 'baratables'), $shortcode)),
			esc_html($shortcode)
		);
	}

	public static function render_shortcode_display(string $shortcode): string {
		if ($shortcode === '') {
			return '';
		}
		return '<strong>' . esc_html__('Shortcode:', 'baratables') . '</strong> '
			. '<span class="btbl-shortcode-permalink">' . self::render_shortcode_cell($shortcode) . '</span>';
	}

	/**
	 * Collapsible shortcode-ID editor (WordPress slug-editor pattern): the id is hidden behind
	 * an "Edit ID" link so it doesn't clutter the header, and the inline editor (input + the
	 * help hint) appears only on demand. Shared by the table and chart builders. The caller
	 * decides when to render it (only when editing an existing record).
	 */
	public static function render_id_editor(string $field_name, string $id_value, string $label, string $embed_tag): void {
		?>
		<div class="btbl-id-editor">
			<button type="button" class="button-link btbl-id-edit-toggle">
				<span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e('Edit ID', 'baratables'); ?>
			</button>
			<div class="btbl-id-edit-panel" hidden>
				<span class="btbl-id-edit-label"><?php echo esc_html($label); ?></span>
				<input type="text" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" class="btbl-id-input" value="<?php echo esc_attr($id_value); ?>" autocomplete="off" autocapitalize="off" spellcheck="false" />
				<button type="button" class="button button-small btbl-id-edit-ok"><?php esc_html_e('OK', 'baratables'); ?></button>
				<button type="button" class="button-link btbl-id-edit-cancel"><?php esc_html_e('Cancel', 'baratables'); ?></button>
				<p class="description btbl-id-edit-hint">
					<?php
					/* translators: %s is the shortcode tag, e.g. [bara_table]. */
					printf(esc_html__('Lowercase letters, numbers, and hyphens. Any %s you have already pasted into a page will need updating by hand.', 'baratables'), esc_html($embed_tag));
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The shortcode + ID-editor row shown under the post title.
	 *
	 * $label/$field_name/$title_value are gone with the standalone builder page: both editors are
	 * metaboxes now, so WordPress renders the real title field and this only ever emitted the
	 * inline shortcode row.
	 */
	public static function render_title_section(string $shortcode, string $after_shortcode = ''): void {
		if ($shortcode === '') {
			return;
		}
		?>
		<div class="btbl-shortcode-row btbl-shortcode-row-inline"><?php echo self::render_shortcode_display($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_display(). ?><?php echo $after_shortcode; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_id_editor() escapes its own output. ?></div>
		<?php
	}

	/** Render the shared nonce, active-tab state, shortcode, and optional ID editor. */
	public static function render_editor_header(string $nonce_action, string $nonce_field, string $active_tab, string $shortcode, ?array $id_editor = null): void {
		wp_nonce_field($nonce_action, $nonce_field);
		?>
		<input type="hidden" id="btbl_active_tab" value="<?php echo esc_attr($active_tab); ?>" />
		<?php
		$id_editor_html = '';
		if ($id_editor !== null) {
			ob_start();
			self::render_id_editor(
				(string) ($id_editor['field'] ?? ''),
				(string) ($id_editor['value'] ?? ''),
				(string) ($id_editor['label'] ?? ''),
				(string) ($id_editor['embed_tag'] ?? '')
			);
			$id_editor_html = (string) ob_get_clean();
		}
		self::render_title_section($shortcode, $id_editor_html);
	}
}


class BaraTables_Admin_Action_Guard {
	/** Marker rendered as the final field of the editor form. See request_is_complete(). */
	const COMPLETE_FIELD = 'btbl_form_complete';

	/**
	 * False when PHP truncated the request at max_input_vars.
	 *
	 * The manual-data grid posts one input per cell, so a big grid can exceed the default 1000.
	 * PHP drops the overflow without raising anything the request can see, and the nonce fields
	 * are emitted before the grid, so the save would otherwise verify and then apply a request
	 * missing everything after the cut.
	 */
	public static function request_is_complete(): bool {
		// Presence check only: no request value is read here, just whether the marker key arrived.
		// can_save_post() has already verified the nonce by the time any caller reaches this.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller; presence check only.
		return isset($_POST[self::COMPLETE_FIELD]);
	}

	/**
	 * Queue the "your host cut the request short" error. Separate from can_save_post() because
	 * only the table editor renders COMPLETE_FIELD -- the chart editor has no unbounded input and
	 * folding this into the shared guard would reject every chart save.
	 */
	public static function warn_request_truncated(): void {
		BaraTables_Admin_Notice::queue(
			sprintf(
				/* translators: %d is the server's max_input_vars setting. */
				__('Nothing was saved. The editor sent more fields than this server accepts (max_input_vars is %d), so the data arrived incomplete. Use fewer grid cells, or ask your host to raise max_input_vars.', 'baratables'),
				max(0, (int) ini_get('max_input_vars'))
			),
			'error'
		);
	}

	public static function user_can_manage(int $post_id = 0): bool {
		if ($post_id > 0) {
			return current_user_can('edit_post', $post_id);
		}
		return current_user_can('manage_options');
	}

	public static function can_save_post(int $post_id, string $nonce_field, string $nonce_action): bool {
		if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return false;
		}
		if (!isset($_POST[$nonce_field]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_field])), $nonce_action)) {
			return false;
		}
		return self::user_can_manage($post_id);
	}
}

/** Shared boundary checks for authenticated admin fragment requests. */
final class BaraTables_Admin_Ajax_Guard {
	public static function verify(string $nonce_action, string $nonce_field): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		check_ajax_referer($nonce_action, $nonce_field);
	}
}

/** Resolve an entity by its indexed slug, falling back to its own postmeta during first save/import. */
final class BaraTables_Admin_Definition_Loader {
	public static function for_post(WP_Post $post, array $descriptor, callable $finder): ?array {
		$definition = $finder($post->post_name, true);
		if (!$definition) {
			$meta = get_post_meta($post->ID, $descriptor['meta_key'], true);
			$definition = is_array($meta) ? $meta : null;
		}
		return is_array($definition) ? $definition : null;
	}
}


/** Capture the post fields WordPress is about to replace before save_post runs. */
final class BaraTables_Pre_Update_State {
	private static bool $registered = false;
	private static array $states = [];

	public static function register(): void {
		if (self::$registered) {
			return;
		}
		self::$registered = true;
		add_action('pre_post_update', [self::class, 'capture'], 10, 2);
	}

	public static function capture(int $post_id, array $data): void {
		unset($data);
		if (BaraTables_Persistence_Guard::active()) {
			return;
		}
		$post = get_post($post_id);
		if (!$post instanceof WP_Post || !in_array($post->post_type, [BaraTables_Repository::CPT, BaraTables_Chart_Repository::CPT], true)) {
			return;
		}
		self::$states[$post_id] = [
			'post_title' => (string) $post->post_title,
			'post_name' => (string) $post->post_name,
			'post_status' => (string) $post->post_status,
		];
	}

	public static function take(int $post_id): ?array {
		$state = self::$states[$post_id] ?? null;
		unset(self::$states[$post_id]);
		return is_array($state) ? $state : null;
	}
}


/** Verified cleanup for an incomplete plugin post; any survivor is forced non-public. */
final class BaraTables_Post_Cleanup {
	public static function delete_or_quarantine(int $post_id): ?WP_Error {
		if ($post_id <= 0 || !get_post($post_id)) {
			return null;
		}
		wp_delete_post($post_id, true);
		$post = get_post($post_id);
		if (!$post instanceof WP_Post) {
			return null;
		}

		if (!in_array($post->post_status, ['draft', 'pending', 'auto-draft', 'trash'], true)) {
			BaraTables_Persistence_Guard::begin();
			try {
				wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
			} finally {
				BaraTables_Persistence_Guard::end();
			}
			$post = get_post($post_id);
		}

		$status = $post instanceof WP_Post ? (string) $post->post_status : '';
		$quarantined = in_array($status, ['draft', 'pending', 'auto-draft', 'trash'], true);
		return new WP_Error(
			'btbl_incomplete_post_not_deleted',
			$quarantined
				? __('WordPress did not delete an incomplete item, but BaraTables kept it non-public.', 'baratables')
				: __('WordPress did not delete or quarantine an incomplete item.', 'baratables'),
			['post_id' => $post_id, 'status' => $status, 'quarantined' => $quarantined]
		);
	}
}


/**
 * Keep a table/chart post slug, definition id and lookup-meta slug as one persistence unit.
 *
 * WordPress owns the canonical post_name. Every caller therefore reads the slug back after a
 * post update instead of assuming the requested value was accepted unchanged.
 */
class BaraTables_Entity_Persistence {
	private string $cpt;
	private string $meta_key;
	private string $meta_slug_key;

	public function __construct(string $cpt, string $meta_key, string $meta_slug_key) {
		$this->cpt = $cpt;
		$this->meta_key = $meta_key;
		$this->meta_slug_key = $meta_slug_key;
		BaraTables_Pre_Update_State::register();
	}

	public static function from_descriptor(array $descriptor): self {
		return new self($descriptor['cpt'], $descriptor['meta_key'], $descriptor['meta_slug']);
	}

	/** @return array{post_title:string,post_name:string,post_status:string,definition:array,meta_slug:array} */
	public function snapshot(int $post_id, string $previous_post_status = ''): array {
		$pre_update = BaraTables_Pre_Update_State::take($post_id);
		$stored_status = (string) get_post_field('post_status', $post_id, 'raw');
		$previous_post_status = sanitize_key($previous_post_status);
		if (is_array($pre_update) && in_array((string) ($pre_update['post_status'] ?? ''), array_values(get_post_stati()), true)) {
			$previous_post_status = (string) $pre_update['post_status'];
		} elseif (!in_array($previous_post_status, array_values(get_post_stati()), true)) {
			$previous_post_status = $stored_status;
		}
		return [
			'post_title' => is_array($pre_update) ? (string) ($pre_update['post_title'] ?? '') : (string) get_post_field('post_title', $post_id, 'raw'),
			'post_name' => is_array($pre_update) ? (string) ($pre_update['post_name'] ?? '') : (string) get_post_field('post_name', $post_id, 'raw'),
			'post_status' => $previous_post_status,
			'definition' => BaraTables_Base_Repository::snapshot_meta($post_id, $this->meta_key),
			'meta_slug' => BaraTables_Base_Repository::snapshot_meta($post_id, $this->meta_slug_key),
		];
	}

	/**
	 * Resolve and, when necessary, write the canonical slug used by an editor save.
	 *
	 * @return array{old_slug:string,requested_slug:string,slug:string,changed:bool,error:?WP_Error}
	 */
	public function save_editor_slug(int $post_id, WP_Post $post, string $requested_slug, string $fallback_slug, callable $save_callback, int $accepted_args): array {
		$old_slug = (string) $post->post_name;
		$requested_slug = sanitize_title($requested_slug);
		$slug = $old_slug;

		if ($requested_slug !== '' && $requested_slug !== $old_slug) {
			$slug = $this->unique_slug($requested_slug, $post_id, $post);
		} elseif ($old_slug === '') {
			$base = sanitize_title($fallback_slug);
			if ($base === '') {
				$base = (string) $post_id;
			}
			$slug = $this->unique_slug($base, $post_id, $post);
		}

		if ($slug === '' || $slug === $old_slug) {
			return $this->slug_result($old_slug, $requested_slug, $old_slug, null);
		}

		$save_hook = 'save_post_' . $this->cpt;
		$callback_registered = has_action($save_hook, $save_callback) !== false;
		if ($callback_registered) {
			remove_action($save_hook, $save_callback, 9);
		}
		BaraTables_Persistence_Guard::begin();
		try {
			$updated = wp_update_post([
				'ID'        => $post_id,
				'post_name' => $slug,
			], true);
		} finally {
			BaraTables_Persistence_Guard::end();
			if ($callback_registered) {
				add_action($save_hook, $save_callback, 9, $accepted_args);
			}
		}

		if (is_wp_error($updated)) {
			return $this->slug_result($old_slug, $requested_slug, $old_slug, $updated);
		}

		$stored_slug = (string) get_post_field('post_name', $post_id, 'raw');
		if ($stored_slug === '') {
			$error = new WP_Error('baratables_slug_not_saved', __('WordPress did not save the requested ID.', 'baratables'));
			return $this->slug_result($old_slug, $requested_slug, $old_slug, $error);
		}

		return $this->slug_result($old_slug, $requested_slug, $stored_slug, null);
	}

	/** Write the definition and its lookup slug together through the one canonical path. */
	public function persist(int $post_id, array $definition, string $slug): ?WP_Error {
		$definition['id'] = $slug;
		return BaraTables_Base_Repository::persist($post_id, $this->meta_key, $this->meta_slug_key, $definition, $slug);
	}

	/** Restore the exact plugin identity/data snapshot captured before an editor operation. */
	public function restore(int $post_id, array $snapshot, callable $save_callback, int $accepted_args): ?WP_Error {
		$save_hook = 'save_post_' . $this->cpt;
		$callback_registered = has_action($save_hook, $save_callback) !== false;
		if ($callback_registered) {
			remove_action($save_hook, $save_callback, 9);
		}

		$post_error = null;
		$definition_error = null;
		$slug_error = null;
		BaraTables_Persistence_Guard::begin();
		try {
			$previous_title = (string) ($snapshot['post_title'] ?? get_post_field('post_title', $post_id, 'raw'));
			$previous_slug = (string) ($snapshot['post_name'] ?? '');
			$previous_status = (string) ($snapshot['post_status'] ?? get_post_field('post_status', $post_id, 'raw'));
			if (
				(string) get_post_field('post_title', $post_id, 'raw') !== $previous_title
				|| (string) get_post_field('post_name', $post_id, 'raw') !== $previous_slug
				|| (string) get_post_field('post_status', $post_id, 'raw') !== $previous_status
			) {
				$updated = wp_update_post(['ID' => $post_id, 'post_title' => $previous_title, 'post_name' => $previous_slug, 'post_status' => $previous_status], true);
				if (is_wp_error($updated)) {
					$post_error = $updated;
				}
			}

			$definition_error = BaraTables_Base_Repository::restore_meta_snapshot(
				$post_id,
				$this->meta_key,
				$snapshot['definition'] ?? ['exists' => false, 'value' => ''],
				true
			);
			$slug_error = BaraTables_Base_Repository::restore_meta_snapshot(
				$post_id,
				$this->meta_slug_key,
				$snapshot['meta_slug'] ?? ['exists' => false, 'value' => '']
			);
		} finally {
			BaraTables_Persistence_Guard::end();
			if ($callback_registered) {
				add_action($save_hook, $save_callback, 9, $accepted_args);
			}
		}

		$previous_title = (string) ($snapshot['post_title'] ?? get_post_field('post_title', $post_id, 'raw'));
		$previous_slug = (string) ($snapshot['post_name'] ?? '');
		$previous_status = (string) ($snapshot['post_status'] ?? get_post_field('post_status', $post_id, 'raw'));
		if (
			$post_error
			|| (string) get_post_field('post_title', $post_id, 'raw') !== $previous_title
			|| (string) get_post_field('post_name', $post_id, 'raw') !== $previous_slug
			|| (string) get_post_field('post_status', $post_id, 'raw') !== $previous_status
			|| $definition_error
			|| $slug_error
		) {
			return new WP_Error('baratables_entity_rollback_failed', __('WordPress could not completely restore the previous plugin data.', 'baratables'));
		}
		return null;
	}

	/** Used by repair hooks that already guard themselves against recursive post/meta callbacks. */
	public function repair(int $post_id, WP_Post $post, array $definition, string $slug): ?WP_Error {
		if ($post->post_name !== $slug) {
			BaraTables_Persistence_Guard::begin();
			try {
				$updated = wp_update_post([
					'ID'        => $post_id,
					'post_name' => $slug,
				], true);
			} finally {
				BaraTables_Persistence_Guard::end();
			}
			if (is_wp_error($updated)) {
				return $updated;
			}
			$slug = (string) get_post_field('post_name', $post_id, 'raw');
			if ($slug === '') {
				return new WP_Error('baratables_slug_not_repaired', __('WordPress did not repair the stored ID.', 'baratables'));
			}
		}

		return $this->persist($post_id, $definition, $slug);
	}

	/** Return an identity unique across every post status and legacy lookup metadata. */
	public function unique_slug(string $base, int $post_id, WP_Post $post): string {
		$root = sanitize_title($base);
		if ($root === '') {
			$root = (string) $post_id;
		}
		$attempt = 1;
		do {
			$requested = $attempt === 1 ? $root : $root . '-' . $attempt;
			// Passing a publishing status deliberately bypasses wp_unique_post_slug()'s early
			// return for drafts/pending posts while retaining core's reserved-name rules.
			$candidate = wp_unique_post_slug($requested, $post_id, 'publish', $this->cpt, $post->post_parent);
			$attempt++;
		} while ($this->meta_slug_is_claimed($candidate, $post_id));
		return $candidate;
	}

	private function meta_slug_is_claimed(string $slug, int $post_id): bool {
		$ids = get_posts([
			'post_type' => $this->cpt,
			'post_status' => array_values(get_post_stati()),
			'numberposts' => 1,
			'fields' => 'ids',
			'post__not_in' => [$post_id], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- One exact identity lookup; excluding the current entity is the correctness condition.
			'meta_key' => $this->meta_slug_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Legacy identity metadata must be checked for collisions even if post_name drifted.
			'meta_value' => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact legacy identity lookup paired with the plugin's post type.
			'no_found_rows' => true,
		]);
		return !empty($ids);
	}

	/**
	 * @return array{old_slug:string,requested_slug:string,slug:string,changed:bool,error:?WP_Error}
	 */
	private function slug_result(string $old_slug, string $requested_slug, string $slug, ?WP_Error $error): array {
		return [
			'old_slug'       => $old_slug,
			'requested_slug' => $requested_slug,
			'slug'           => $slug,
			'changed'        => $slug !== $old_slug,
			'error'          => $error,
		];
	}
}


abstract class BaraTables_Base_Slug_Manager {
	protected BaraTables_Abstract_CPT_Repository $repo;
	private BaraTables_Entity_Persistence $persistence;
	private array $descriptor;
	private bool $syncing_slug = false;

	public function __construct(BaraTables_Abstract_CPT_Repository $repo) {
		$this->repo = $repo;
		$this->descriptor = $this->get_descriptor();
		$this->persistence = BaraTables_Entity_Persistence::from_descriptor($this->descriptor);
	}

	public function ensure_slug_on_save(int $post_id, WP_Post $post): void {
		if ($this->syncing_slug || BaraTables_Persistence_Guard::active()) {
			return;
		}
		if ($post->post_type !== $this->descriptor['cpt']) {
			return;
		}
		if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return;
		}
		// An earlier save_post callback may have coordinated a slug update. WordPress keeps passing
		// the original WP_Post object to later callbacks, so compare metadata with the durable row,
		// not that stale hook argument (otherwise a successful editor rename is "repaired" back).
		$stored_post = get_post($post_id);
		if (!$stored_post instanceof WP_Post) {
			return;
		}
		$post = $stored_post;

		$meta_slug = get_post_meta($post_id, $this->descriptor['meta_slug'], true);
		$definition = get_post_meta($post_id, $this->descriptor['meta_key'], true);
		$definition = is_array($definition) ? $definition : [];

		$this->maybe_resync_slug($post_id, $post, $meta_slug, $definition);
	}

	// $meta_id is the first argument of added_post_meta / updated_post_meta and cannot be dropped
	// without shifting $object_id and $meta_key, which are both used. The trailing $meta_value is
	// dropped instead, and the hooks are registered with a matching accepted_args of 3.
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- fixed hook signature.
	public function ensure_slug_on_meta($meta_id, $object_id, $meta_key): void {
		if ($this->syncing_slug || BaraTables_Persistence_Guard::active()) {
			return;
		}
		if (!in_array($meta_key, [$this->descriptor['meta_key'], $this->descriptor['meta_slug']], true)) {
			return;
		}
		$post = get_post($object_id);
		if (!$post || $post->post_type !== $this->descriptor['cpt']) {
			return;
		}

		$meta_slug = get_post_meta($object_id, $this->descriptor['meta_slug'], true);
		$definition = get_post_meta($object_id, $this->descriptor['meta_key'], true);
		$definition = is_array($definition) ? $definition : [];
		if ($meta_slug === '' && !empty($definition['id'])) {
			$meta_slug = $definition['id'];
		}

		$this->maybe_resync_slug($object_id, $post, $meta_slug, $definition);
	}

	private function maybe_resync_slug(int $post_id, WP_Post $post, $meta_slug, array $definition): void {
		$current_slug = $post->post_name;
		$meta_slug = is_string($meta_slug) ? $meta_slug : '';
		// A newly inserted CPT post reaches save_post before the editor has written either plugin
		// metadata record. There is no identity drift to repair yet, and synthesizing a definition
		// here would turn an intentionally incomplete draft into a false saved table/chart.
		if ($meta_slug === '' && empty($definition)) {
			return;
		}

		if ($current_slug === '') {
			$base = sanitize_title((string) $post->post_title);
			if ($base === '') {
				$base = (string) $post_id;
			}
			$current_slug = $this->persistence->unique_slug($base, $post_id, $post);
			if ($current_slug === '') {
				return;
			}
		} else {
			$current_slug = $this->persistence->unique_slug($current_slug, $post_id, $post);
		}

		if ($meta_slug === $current_slug && (string) ($definition['id'] ?? '') === $current_slug) {
			return;
		}

		$definition = $this->hydrate_definition($definition, $post, $meta_slug);
		$definition['id'] = $current_slug;

		$snapshot = $this->persistence->snapshot($post_id);
		$this->syncing_slug = true;
		try {
			$error = $this->persistence->repair($post_id, $post, $definition, $current_slug);
			if ($error) {
				$rollback = $this->persistence->restore($post_id, $snapshot, static function (): void {}, 0);
				if (class_exists('BaraTables_Admin_Notice')) {
					BaraTables_Admin_Notice::queue(
						$rollback
							? __('BaraTables could not synchronize this item\'s ID or restore its previous data. Retry the save before editing it again.', 'baratables')
							: __('BaraTables could not synchronize this item\'s ID. Its previous data was restored.', 'baratables'),
						'error'
					);
				}
			}
		} finally {
			$this->syncing_slug = false;
		}
	}

	abstract protected function get_descriptor(): array;

	abstract protected function hydrate_definition(array $definition, WP_Post $post, string $meta_slug): array;
}


class BaraTables_Admin_Slug_Manager extends BaraTables_Base_Slug_Manager {
	protected function get_descriptor(): array {
		return BaraTables_Entity_Descriptor::table();
	}

	protected function hydrate_definition(array $definition, WP_Post $post, string $meta_slug): array {
		if ((empty($definition) || empty($definition['post_type']) || empty($definition['columns'])) && $meta_slug !== '') {
			$existing = $this->repo->find_definition($meta_slug, true);
			if (is_array($existing)) {
				$definition = array_merge($existing, $definition);
			}
		}
		if (empty($definition['post_type'])) {
			$definition['post_type'] = 'post';
		}
		if (!isset($definition['columns']) || !is_array($definition['columns'])) {
			$definition['columns'] = [];
		}
		if (empty($definition['name']) && !empty($post->post_title)) {
			$definition['name'] = $post->post_title;
		}
		return $definition;
	}
}


class BaraTables_Chart_Slug_Manager extends BaraTables_Base_Slug_Manager {
	protected function get_descriptor(): array {
		return BaraTables_Entity_Descriptor::chart();
	}

	protected function hydrate_definition(array $definition, WP_Post $post, string $meta_slug): array {
		if (empty($definition) && $meta_slug !== '') {
			$existing = $this->repo->find_chart($meta_slug, true);
			if (is_array($existing)) {
				$definition = array_merge($existing, $definition);
			}
		}
		if (empty($definition['name']) && !empty($post->post_title)) {
			$definition['name'] = $post->post_title;
		}
		return $definition;
	}
}

class BaraTables_Admin_Assets {
	private string $plugin_url;
	private string $plugin_path;

	public function __construct(string $plugin_url, string $plugin_path) {
		$this->plugin_url = $plugin_url;
		$this->plugin_path = $plugin_path;
	}

	public function enqueue(string $hook): void {
		global $typenow;
		$hook_post_type = $typenow ?: (isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
		if ($hook_post_type === '' && isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
			$post_obj = get_post(absint(wp_unslash($_GET['post']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
			if ($post_obj instanceof WP_Post) {
				$hook_post_type = $post_obj->post_type;
			}
		}
		$is_btbl_list = $hook === 'edit.php'
			&& in_array($hook_post_type, [BaraTables_Repository::CPT, BaraTables_Chart_Repository::CPT], true);
		$is_btbl_editor = in_array($hook, ['post.php', 'post-new.php'], true)
			&& in_array($hook_post_type, [BaraTables_Repository::CPT, BaraTables_Chart_Repository::CPT], true);
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
		$is_btbl_page = $page === BaraTables_Admin_Options::PAGE_SLUG;
		if (!$is_btbl_list && !$is_btbl_editor && !$is_btbl_page) {
			return;
		}

		wp_enqueue_style(
			'baratables-admin',
			$this->plugin_url . 'assets/admin.css',
			[],
			BaraTables_Asset_Utils::get_asset_version($this->plugin_path, 'assets/admin.css')
		);

		// Only the table editor has a media control: the CSV "Choose file" button.
		if ($is_btbl_editor && $hook_post_type === BaraTables_Repository::CPT) {
			wp_enqueue_media();
		}

		$is_table_editor = $is_btbl_editor && $hook_post_type === BaraTables_Repository::CPT;
		$is_chart_editor = $is_btbl_editor && $hook_post_type === BaraTables_Chart_Repository::CPT;
		if ($is_chart_editor) {
			wp_enqueue_style(
				'baratables-select2',
				$this->plugin_url . 'assets/vendor/select2/select2.min.css',
				[],
				'4.1.0-rc.0'
			);
			wp_enqueue_script(
				'baratables-select2',
				$this->plugin_url . 'assets/vendor/select2/select2.min.js',
				['jquery'],
				'4.1.0-rc.0',
				true
			);
		}
		$admin_dependencies = ['jquery', 'baratables-admin-core'];
		if ($is_table_editor) {
			$admin_dependencies[] = 'baratables-admin-layout';
		}
		$scripts = [
			['baratables-admin-core', 'assets/admin-core.js', ['jquery'], true],
			['baratables-admin-common', 'assets/admin-common.js', ['jquery', 'baratables-admin-core'], true],
			['baratables-utils', 'assets/baratables-utils.js', [], $is_table_editor],
			['baratables-admin-layout', 'assets/admin-layout.js', ['jquery', 'baratables-admin-core'], $is_table_editor],
			['baratables-admin-grid', 'assets/admin-grid.js', ['jquery', 'baratables-utils'], $is_table_editor],
			['baratables-admin-chart', 'assets/admin-chart.js', ['jquery', 'baratables-admin-core', 'baratables-select2'], $is_chart_editor],
			['baratables-admin', 'assets/admin.js', $admin_dependencies, $is_btbl_editor],
		];
		foreach ($scripts as [$handle, $relative, $dependencies, $enabled]) {
			if ($enabled) {
				wp_enqueue_script(
					$handle,
					$this->plugin_url . $relative,
					$dependencies,
					BaraTables_Asset_Utils::get_asset_version($this->plugin_path, $relative),
					true
				);
			}
		}
	}
}

class BaraTables_Admin_List_Renderer {
	/** @var callable */
	private $definition_loader;
	private array $renderers;
	private array $definitions = [];

	/**
	 * @param callable(int):array $definition_loader
	 * @param array<string,callable(array,int):void> $renderers
	 */
	public function __construct(callable $definition_loader, array $renderers) {
		$this->definition_loader = $definition_loader;
		$this->renderers = $renderers;
	}

	public function render(string $column, int $post_id): void {
		if (!isset($this->renderers[$column])) {
			return;
		}
		if (!array_key_exists($post_id, $this->definitions)) {
			$definition = ($this->definition_loader)($post_id);
			$this->definitions[$post_id] = is_array($definition) ? $definition : [];
		}
		($this->renderers[$column])($this->definitions[$post_id], $post_id);
	}
}

abstract class BaraTables_Admin_List_Columns_Base {
	protected BaraTables_Admin_List_Renderer $renderer;

	final public function register_list_columns(array $columns): array {
		return $columns + $this->get_column_labels();
	}

	final public function render_list_columns(string $column, int $post_id): void {
		$this->renderer->render($column, $post_id);
	}

	abstract protected function get_column_labels(): array;
}

class BaraTables_Admin_List_Columns extends BaraTables_Admin_List_Columns_Base {

	public function __construct() {
		$definition_loader = static function (int $post_id): array {
			$definition = get_post_meta($post_id, BaraTables_Repository::META_KEY, true);
			return is_array($definition) ? $definition : [];
		};

		$renderers = [
			'taxonomy' => static function (array $definition): void {
				$parts = [];
				foreach (BaraTables_Taxonomy_Filters::normalize($definition['taxonomy_filter'] ?? []) as $filter) {
					$tax = isset($filter['taxonomy']) ? sanitize_key($filter['taxonomy']) : '';
					$terms = isset($filter['terms']) && is_array($filter['terms'])
						? array_filter(array_map('intval', $filter['terms']))
						: [];
					if ($tax === '' || empty($terms)) {
						continue;
					}
					$tax_obj = get_taxonomy($tax);
					$tax_label = $tax_obj && !is_wp_error($tax_obj) && !empty($tax_obj->labels->singular_name)
						? $tax_obj->labels->singular_name
						: ucwords(str_replace(['_', '-'], ' ', $tax));

					// One bulk fetch, not get_term() per id. These ids come from the table's own
					// postmeta rather than from the listed posts, so WordPress never primes them --
					// per-id lookups meant a query each, on every row of the Tables list screen.
					$term_labels = [];
					$term_objects = get_terms([
						'taxonomy'   => $tax,
						'include'    => $terms,
						'hide_empty' => false,
						'orderby'    => 'include',
					]);
					if (!is_wp_error($term_objects)) {
						foreach ($term_objects as $term_obj) {
							$term_labels[] = $term_obj->name;
						}
					}
					if (!empty($term_labels)) {
						$parts[] = $tax_label . ': ' . implode(', ', $term_labels);
					}
				}

				echo $parts ? esc_html(implode(' | ', $parts)) : '&mdash;';
			},
			'data_source' => static function (array $definition): void {
				$source = BaraTables_Source_Type::normalize($definition['source_type'] ?? BaraTables_Source_Type::WP_QUERY, BaraTables_Source_Type::WP_QUERY);
				$labels = BaraTables_Source_Type::labels();
				echo esc_html($labels[$source] ?? ucwords(str_replace('_', ' ', $source)));
			},
			'post_type' => static function (array $definition): void {
				$pt = $definition['post_type'] ?? '';
				if ($pt === '') {
					echo '&mdash;';
					return;
				}
				$pt_obj = get_post_type_object($pt);
				echo $pt_obj && !is_wp_error($pt_obj) ? esc_html($pt_obj->labels->singular_name ?? $pt) : esc_html($pt);
			},
			'fields' => static function (array $definition): void {
				if (empty($definition['columns']) || !is_array($definition['columns'])) {
					echo '&mdash;';
					return;
				}
				$labels = array_filter(array_map(static function ($col) {
					return (string) ($col['label'] ?? '');
				}, $definition['columns']), static function ($label) {
					return $label !== '';
				});
				if (empty($labels)) {
					echo '&mdash;';
					return;
				}
				$output = implode(', ', $labels);
				// Show an at-a-glance row count for manual tables.
				$source = $definition['source_type'] ?? '';
				if (in_array($source, ['custom_data', 'custom'], true) && !empty($definition['custom_data']['rows'])) {
					$count = count($definition['custom_data']['rows']);
					/* translators: %d is the number of data rows. */
					$output .= ' ' . sprintf(_n('(%d row)', '(%d rows)', $count, 'baratables'), $count);
				}
				echo esc_html($output);
			},
			'shortcode' => static function (array $definition, int $post_id): void {
				$id = isset($definition['id']) ? (string) $definition['id'] : (string) get_post_field('post_name', $post_id);
				$shortcode = '[bara_table id="' . sanitize_text_field($id) . '"]';
				echo BaraTables_Admin_Page_Utils::render_shortcode_cell($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_cell().
			},
		];

		$this->renderer = new BaraTables_Admin_List_Renderer($definition_loader, $renderers);
	}

	protected function get_column_labels(): array {
		return [
			'post_type' => __('Post type', 'baratables'),
			'data_source' => __('Data Source', 'baratables'),
			'taxonomy' => __('Taxonomy', 'baratables'),
			'fields' => __('Fields', 'baratables'),
			'shortcode' => __('Shortcode', 'baratables'),
		];
	}
}


class BaraTables_Chart_List_Columns extends BaraTables_Admin_List_Columns_Base {
	private BaraTables_Service $table_service;
	private array $table_definitions = [];
	private array $table_post_ids = [];
	private bool $table_definitions_primed = false;

	public function __construct(BaraTables_Service $table_service) {
		$this->table_service = $table_service;

		$definition_loader = static function (int $post_id): array {
			$chart = get_post_meta($post_id, BaraTables_Chart_Repository::META_KEY, true);
			return is_array($chart) ? $chart : [];
		};

		$renderers = [
			'chart_table' => function (array $chart): void {
				$table = $this->get_table_definition($chart);
				if (!$table) {
					echo '&mdash;';
					return;
				}
				$name = (string) ($table['name'] ?? ($table['id'] ?? ''));
				// Link straight to the source table's editor.
				$post_id = !empty($table['id']) ? $this->get_table_post_id((string) $table['id']) : 0;
				$edit_link = $post_id ? get_edit_post_link($post_id) : '';
				if ($edit_link) {
					printf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($name));
				} else {
					echo esc_html($name);
				}
			},
			'chart_type' => static function (array $chart): void {
				$type = isset($chart['chart']['type']) ? sanitize_key($chart['chart']['type']) : '';
				if ($type === '') {
					echo '&mdash;';
					return;
				}
				$chart_types = BaraTables_Chart_Types::all();
				// Show a scannable Dashicon alongside every chart-type label.
				if (isset($chart_types[$type]['icon'])) {
					printf('<span class="dashicons dashicons-%s" aria-hidden="true" style="vertical-align:text-bottom;"></span> ', esc_attr($chart_types[$type]['icon']));
				}
				echo esc_html($chart_types[$type]['label'] ?? ucwords($type));
			},
			'chart_fields' => function (array $chart): void {
				$table = $this->get_table_definition($chart);
				$chart_options = isset($chart['chart']) && is_array($chart['chart']) ? $chart['chart'] : [];
				if (!$table || empty($table['columns'])) {
					echo '&mdash;';
					return;
				}
				$slug_to_label = $this->table_service->build_column_slug_label_map($table['columns']);

				$labels = [];
				foreach (BaraTables_Chart_Types::referenced_columns($chart_options, true) as $slug) {
					$labels[] = $slug_to_label[$slug] ?? $slug;
				}

				$labels = array_filter(array_map('strval', $labels));
				echo !empty($labels) ? esc_html(implode(', ', $labels)) : '&mdash;';
			},
			'chart_shortcode' => static function (array $chart, int $post_id): void {
				$id = isset($chart['id']) ? (string) $chart['id'] : (string) get_post_field('post_name', $post_id);
				$shortcode = '[bara_chart id="' . sanitize_text_field($id) . '"]';
				echo BaraTables_Admin_Page_Utils::render_shortcode_cell($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_cell().
			},
		];

		$this->renderer = new BaraTables_Admin_List_Renderer($definition_loader, $renderers);
	}

	protected function get_column_labels(): array {
		return [
			'chart_table' => __('Table', 'baratables'),
			'chart_type' => __('Type', 'baratables'),
			'chart_fields' => __('Columns', 'baratables'),
			'chart_shortcode' => __('Shortcode', 'baratables'),
		];
	}

	private function get_table_definition(array $chart): ?array {
		$table_id = isset($chart['table_id']) ? sanitize_text_field($chart['table_id']) : '';
		if ($table_id === '') {
			return null;
		}
		$this->prime_table_definitions();
		if (!array_key_exists($table_id, $this->table_definitions)) {
			$this->table_definitions[$table_id] = $this->table_service->find_definition($table_id);
			$this->table_post_ids[$table_id] = $this->table_definitions[$table_id]
				? $this->table_service->get_definition_post_id($table_id)
				: 0;
		}
		return $this->table_definitions[$table_id];
	}

	private function get_table_post_id(string $table_id): int {
		$this->prime_table_definitions();
		return (int) ($this->table_post_ids[$table_id] ?? 0);
	}

	private function prime_table_definitions(): void {
		if ($this->table_definitions_primed) {
			return;
		}
		$this->table_definitions_primed = true;
		$table_ids = [];
		foreach (($GLOBALS['wp_query']->posts ?? []) as $post) {
			if (!$post instanceof WP_Post || $post->post_type !== BaraTables_Chart_Repository::CPT) {
				continue;
			}
			$chart = get_post_meta($post->ID, BaraTables_Chart_Repository::META_KEY, true);
			if (is_array($chart) && !empty($chart['table_id'])) {
				$table_ids[] = (string) $chart['table_id'];
			}
		}
		foreach (array_unique($table_ids) as $table_id) {
			$this->table_definitions[$table_id] = null;
			$this->table_post_ids[$table_id] = 0;
		}
		foreach ($this->table_service->find_definitions_with_post_ids($table_ids) as $table_id => $record) {
			$this->table_definitions[$table_id] = $record['definition'];
			$this->table_post_ids[$table_id] = (int) $record['post_id'];
		}
	}
}
