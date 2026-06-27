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
		return !empty($_POST[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller. No unslash needed — truthiness check only.
	}

	public static function array_raw(string $key): array {
		return isset($_POST[$key]) ? (array) wp_unslash($_POST[$key]) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Intentionally returns raw array; caller handles sanitization.
	}

	public static function array_text(string $key): array {
		return isset($_POST[$key]) ? array_map('sanitize_text_field', (array) wp_unslash($_POST[$key])) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
	}

	public static function key(string $key, string $default = ''): string {
		return isset($_POST[$key]) ? sanitize_key(wp_unslash($_POST[$key])) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
	}
}


/**
 * Queues admin notices across the post-save redirect using a short-lived,
 * per-user transient. Used to tell the user when a save produced something
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

	/** True only on a brand-new site/account that has no saved tables yet (R30 first-run gate). */
	public static function is_first_table(): bool {
		$counts = wp_count_posts(BaraTables_Repository::CPT);
		$existing = (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0)
			+ (int) ($counts->private ?? 0) + (int) ($counts->future ?? 0) + (int) ($counts->pending ?? 0);
		return $existing === 0;
	}
}


/**
 * R7: adds a "Duplicate" row action to the Tables and Charts lists. Copies the
 * post + its definition meta into a new draft with a freshly-minted slug/id so
 * the clone's shortcode never collides with the original.
 */
class BaraTables_Admin_Duplicator {
	public function register(): void {
		add_filter('post_row_actions', [$this, 'add_action'], 10, 2);
		add_action('admin_action_btbl_duplicate', [$this, 'handle']);
	}

	private function cpt_map(): array {
		return [
			BaraTables_Repository::CPT => [
				'meta_key' => BaraTables_Repository::META_KEY,
				'meta_slug' => BaraTables_Repository::META_SLUG,
			],
			BaraTables_Chart_Repository::CPT => [
				'meta_key' => BaraTables_Chart_Repository::META_KEY,
				'meta_slug' => BaraTables_Chart_Repository::META_SLUG,
			],
		];
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
		$definition = is_array($definition) ? $definition : [];

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

		// Mint a unique slug/id so the clone's shortcode differs from the original.
		$base = sanitize_title($new_title) ?: ('btbl-copy-' . $new_id);
		$new_slug = wp_unique_post_slug($base, (int) $new_id, 'draft', $post->post_type, 0);
		wp_update_post(['ID' => (int) $new_id, 'post_name' => $new_slug]);

		$definition['id'] = $new_slug;
		$definition['name'] = $new_title;
		$definition['status'] = 'draft';
		update_post_meta((int) $new_id, $conf['meta_key'], $definition);
		update_post_meta((int) $new_id, $conf['meta_slug'], $new_slug);

		return (int) $new_id;
	}
}


class BaraTables_Admin_Page_Utils {
	public static function render_shortcode_cell(string $shortcode): string {
		// R9: accessible click-to-copy — the label includes the shortcode so list rows
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

	public static function render_title_section(string $label, string $field_name, string $title_value, string $shortcode, bool $include_title, string $after_shortcode = ''): void {
		if ($include_title) : ?>
			<div id="titlediv">
				<div id="titlewrap">
					<label class="screen-reader-text" id="title-prompt-text" for="title"><?php echo esc_html($label); ?></label>
					<input type="text" name="<?php echo esc_attr($field_name); ?>" size="30" value="<?php echo esc_attr($title_value); ?>" id="title" spellcheck="true" autocomplete="off" required />
				</div>
				<?php if ($shortcode !== '') : ?>
					<div class="inside">
						<div id="edit-slug-box" class="hide-if-no-js btbl-shortcode-row">
							<?php echo self::render_shortcode_display($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_display(). ?>
							<?php echo $after_shortcode; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_id_editor() escapes its own output. ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		<?php else :
			if ($shortcode !== '') : ?>
				<div class="btbl-shortcode-row btbl-shortcode-row-inline"><?php echo self::render_shortcode_display($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_display(). ?><?php echo $after_shortcode; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_id_editor() escapes its own output. ?></div>
			<?php endif;
		endif;
	}
}


class BaraTables_Admin_Action_Guard {
	public static function user_can_manage(int $post_id = 0): bool {
		if ($post_id > 0) {
			return current_user_can('edit_post', $post_id);
		}
		return current_user_can('manage_options');
	}

	public static function verify_nonce_or_bail(string $nonce_field, string $nonce_action): void {
		if (!isset($_POST[$nonce_field]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_field])), $nonce_action)) {
			wp_die(esc_html__('Security check failed', 'baratables'));
		}
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


abstract class BaraTables_Base_Slug_Manager {
	protected $repo;
	private bool $syncing_slug = false;

	public function __construct($repo) {
		$this->repo = $repo;
	}

	public function ensure_slug_on_save(int $post_id, WP_Post $post, bool $update): void {
		if ($this->syncing_slug) {
			return;
		}
		if ($post->post_type !== $this->get_cpt()) {
			return;
		}
		if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return;
		}

		$meta_slug = get_post_meta($post_id, $this->get_meta_slug_key(), true);
		$definition = get_post_meta($post_id, $this->get_meta_key(), true);
		$definition = is_array($definition) ? $definition : [];

		$this->maybe_resync_slug($post_id, $post, $meta_slug, $definition);
	}

	public function ensure_slug_on_meta($meta_id, $object_id, $meta_key, $meta_value): void {
		if ($this->syncing_slug) {
			return;
		}
		if (!in_array($meta_key, [$this->get_meta_key(), $this->get_meta_slug_key()], true)) {
			return;
		}
		$post = get_post($object_id);
		if (!$post || $post->post_type !== $this->get_cpt()) {
			return;
		}

		$meta_slug = get_post_meta($object_id, $this->get_meta_slug_key(), true);
		$definition = get_post_meta($object_id, $this->get_meta_key(), true);
		$definition = is_array($definition) ? $definition : [];
		if ($meta_slug === '' && !empty($definition['id'])) {
			$meta_slug = $definition['id'];
		}

		$this->maybe_resync_slug($object_id, $post, $meta_slug, $definition);
	}

	private function maybe_resync_slug(int $post_id, WP_Post $post, $meta_slug, array $definition): void {
		$current_slug = $post->post_name;
		if (!is_string($meta_slug) || strpos($meta_slug, $this->get_slug_prefix()) !== 0) {
			return;
		}

		if ($current_slug === '') {
			$base = sanitize_title((string) $post->post_title);
			if ($base === '') {
				$base = (string) $post_id;
			}
			$current_slug = wp_unique_post_slug($base, $post_id, $post->post_status, $post->post_type, $post->post_parent);
			if ($current_slug === '') {
				return;
			}
		}

		if ($meta_slug === $current_slug) {
			return;
		}

		$definition = $this->hydrate_definition($definition, $post, $meta_slug);
		$definition['id'] = $current_slug;

		$this->syncing_slug = true;
		if ($post->post_name !== $current_slug) {
			wp_update_post([
				'ID'        => $post_id,
				'post_name' => $current_slug,
			]);
		}
		update_post_meta($post_id, $this->get_meta_slug_key(), $current_slug);
		update_post_meta($post_id, $this->get_meta_key(), $definition);
		$this->syncing_slug = false;
	}

	abstract protected function get_cpt(): string;

	abstract protected function get_meta_key(): string;

	abstract protected function get_meta_slug_key(): string;

	abstract protected function get_slug_prefix(): string;

	abstract protected function hydrate_definition(array $definition, WP_Post $post, string $meta_slug): array;
}


class BaraTables_Admin_Slug_Manager extends BaraTables_Base_Slug_Manager {
	public function __construct(BaraTables_Repository $repo) {
		parent::__construct($repo);
	}

	protected function get_cpt(): string {
		return BaraTables_Repository::CPT;
	}

	protected function get_meta_key(): string {
		return BaraTables_Repository::META_KEY;
	}

	protected function get_meta_slug_key(): string {
		return BaraTables_Repository::META_SLUG;
	}

	protected function get_slug_prefix(): string {
		return 'btbl_';
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
	public function __construct(BaraTables_Chart_Repository $repo) {
		parent::__construct($repo);
	}

	protected function get_cpt(): string {
		return BaraTables_Chart_Repository::CPT;
	}

	protected function get_meta_key(): string {
		return BaraTables_Chart_Repository::META_KEY;
	}

	protected function get_meta_slug_key(): string {
		return BaraTables_Chart_Repository::META_SLUG;
	}

	protected function get_slug_prefix(): string {
		return 'btbl-chart-';
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

		if ($is_btbl_editor || $is_btbl_list) {
			if ($is_btbl_editor) {
				wp_enqueue_media();
			}
			wp_enqueue_script(
				'baratables-admin',
				$this->plugin_url . 'assets/admin.js',
				['jquery'],
				BaraTables_Asset_Utils::get_asset_version($this->plugin_path, 'assets/admin.js'),
				true
			);
		}
	}
}

class BaraTables_Admin_List_Renderer {
	/** @var callable */
	private $definition_loader;
	private array $renderers;

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
		$definition = call_user_func($this->definition_loader, $post_id);
		if (!is_array($definition)) {
			$definition = [];
		}
		call_user_func($this->renderers[$column], $definition, $post_id);
	}
}


class BaraTables_Admin_List_Columns {
	private BaraTables_Admin_List_Renderer $renderer;

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

					$term_labels = [];
					foreach ($terms as $term_id) {
						$term_obj = get_term($term_id, $tax);
						if ($term_obj && !is_wp_error($term_obj)) {
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
				if ($source === '') {
					echo '&mdash;';
					return;
				}
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
				// R43: at-a-glance row count for manual tables.
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

	public function register_list_columns(array $columns): array {
		return $columns + $this->get_column_labels();
	}

	public function render_list_columns(string $column, int $post_id): void {
		$this->renderer->render($column, $post_id);
	}

	private function get_column_labels(): array {
		return [
			'post_type' => __('Post type', 'baratables'),
			'data_source' => __('Data Source', 'baratables'),
			'taxonomy' => __('Taxonomy', 'baratables'),
			'fields' => __('Fields', 'baratables'),
			'shortcode' => __('Shortcode', 'baratables'),
		];
	}
}


class BaraTables_Chart_List_Columns {
	private BaraTables_Service $table_service;
	private BaraTables_Admin_List_Renderer $renderer;

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
				// R14: link straight to the source table's editor.
				$post_id = !empty($table['id']) ? (new BaraTables_Repository())->get_post_id_by_slug((string) $table['id']) : 0;
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
				$type_labels = [
					'bar' => __('Bar', 'baratables'),
					'line' => __('Line', 'baratables'),
					'area' => __('Area', 'baratables'),
					'pie' => __('Pie', 'baratables'),
					'gantt' => __('Gantt', 'baratables'),
				];
				// R42: a scannable Dashicon alongside the text label.
				$type_icons = [
					'bar' => 'chart-bar',
					'line' => 'chart-line',
					'area' => 'chart-area',
					'pie' => 'chart-pie',
					'gantt' => 'calendar-alt',
				];
				if (isset($type_icons[$type])) {
					printf('<span class="dashicons dashicons-%s" aria-hidden="true" style="vertical-align:text-bottom;"></span> ', esc_attr($type_icons[$type]));
				}
				echo esc_html($type_labels[$type] ?? ucwords($type));
			},
			'chart_fields' => function (array $chart): void {
				$table = $this->get_table_definition($chart);
				$chart_options = isset($chart['chart']) && is_array($chart['chart']) ? $chart['chart'] : [];
				if (!$table || empty($table['columns'])) {
					echo '&mdash;';
					return;
				}
				$slug_to_label = $this->table_service->build_column_slug_label_map($table['columns']);

				$type = isset($chart_options['type']) ? sanitize_key($chart_options['type']) : 'bar';
				$labels = [];

				if ($type === 'gantt') {
					$keys = [
						$chart_options['gantt_label'] ?? '',
						$chart_options['gantt_start'] ?? '',
						$chart_options['gantt_end'] ?? '',
					];
					foreach ($keys as $slug) {
						if ($slug === '') {
							continue;
						}
						$labels[] = $slug_to_label[$slug] ?? $slug;
					}
				} else {
					$x = $chart_options['x_axis'] ?? '';
					if ($x !== '') {
						$labels[] = $slug_to_label[$x] ?? $x;
					}
					$series = isset($chart_options['series']) && is_array($chart_options['series']) ? $chart_options['series'] : [];
					foreach ($series as $slug) {
						if ($slug === '') {
							continue;
						}
						$labels[] = $slug_to_label[$slug] ?? $slug;
					}
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

	public function register_list_columns(array $columns): array {
		return $columns + $this->get_column_labels();
	}

	public function render_list_columns(string $column, int $post_id): void {
		$this->renderer->render($column, $post_id);
	}

	private function get_column_labels(): array {
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
		return $this->table_service->find_definition($table_id);
	}
}
