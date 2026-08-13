<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables {
	private BaraTables_Repository $repo;
	private BaraTables_Chart_Repository $chart_repo;
	private ?BaraTables_Service $service = null;
	private ?BaraTables_Chart_Service $chart_service = null;
	private ?BaraTables_Frontend $frontend = null;
	private string $plugin_url;
	private string $plugin_path;

	public function __construct(string $plugin_file) {
		$this->plugin_url = plugin_dir_url($plugin_file);
		$this->plugin_path = plugin_dir_path($plugin_file);
		$this->repo = new BaraTables_Repository();
		$this->chart_repo = new BaraTables_Chart_Repository();

		// CPTs must register on the front end too so [bara_table]/[bara_chart] can resolve them.
		// Register straight from the repositories (always loaded), not via the admin objects, so a
		// front-end request has no reason to construct the admin layer at all.
		add_action('init', [$this->repo, 'register_cpt']);
		add_action('init', [$this->chart_repo, 'register_cpt']);
		add_action('wp_enqueue_scripts', [$this, 'maybe_register_frontend_assets']);
		add_shortcode('bara_table', [$this, 'render_table_shortcode']);
		add_shortcode('bara_chart', [$this, 'render_chart_shortcode']);

		if (is_admin() || wp_doing_ajax() || (defined('WP_CLI') && WP_CLI)) {
			$this->load_admin();
		}
	}

	private function service(): BaraTables_Service {
		if ($this->service === null) {
			$this->service = new BaraTables_Service($this->repo);
		}
		return $this->service;
	}

	private function chart_service(): BaraTables_Chart_Service {
		if ($this->chart_service === null) {
			$this->chart_service = new BaraTables_Chart_Service($this->repo, $this->chart_repo, $this->service());
		}
		return $this->chart_service;
	}

	private function frontend(): BaraTables_Frontend {
		if ($this->frontend === null) {
			$this->frontend = new BaraTables_Frontend($this->service(), $this->chart_service(), $this->plugin_url, $this->plugin_path);
		}
		return $this->frontend;
	}

	public function maybe_register_frontend_assets(): void {
		if ($this->main_query_has_shortcode()) {
			$this->frontend()->register_frontend_assets();
		}
	}

	public function render_table_shortcode($atts): string {
		return $this->frontend()->render_shortcode($atts);
	}

	public function render_chart_shortcode($atts): string {
		return $this->frontend()->render_chart_shortcode($atts);
	}

	private function main_query_has_shortcode(): bool {
		$has_shortcode = static function ($content): bool {
			$content = (string) $content;
			return $content !== '' && (has_shortcode($content, 'bara_table') || has_shortcode($content, 'bara_chart'));
		};
		if (is_singular()) {
			$post = get_queried_object();
			return $post instanceof WP_Post && $has_shortcode($post->post_content);
		}
		foreach ((array) ($GLOBALS['wp_query']->posts ?? []) as $post) {
			if ($post instanceof WP_Post && $has_shortcode($post->post_content)) {
				return true;
			}
		}
		return false;
	}

	/** Wire the admin half only for wp-admin, AJAX and WP-CLI requests. */
	private function load_admin(): void {
		$service = $this->service();
		$admin = new BaraTables_Admin($service, $this->repo, $this->plugin_url, $this->plugin_path);
		new BaraTables_Chart_Admin($this->chart_service(), $this->chart_repo, $service, BaraTables_Admin::NONCE_ACTION, BaraTables_Admin::NONCE_FIELD);

		// Run durable, idempotent data upgrades only in the admin. One autoloaded schema gate keeps
		// subsequent admin requests query-free and remains unset after a failed write so it retries.
		add_action('admin_init', [$this, 'run_data_migrations']);

		add_action('admin_menu', [$this, 'cleanup_admin_menu'], 20);
		add_filter('parent_file', [$this, 'highlight_tables_parent_menu']);
		add_filter('submenu_file', [$this, 'highlight_tables_submenu'], 10, 1);
		add_action('admin_enqueue_scripts', [$admin, 'enqueue_admin_assets']);
	}

	public function run_data_migrations(): void {
		$recovery = $this->service()->retry_chart_link_recovery();
		if ($recovery['remaining'] > 0 || $recovery['error']) {
			BaraTables_Admin_Notice::queue(
				sprintf(
					/* translators: %d is the number of linked charts still awaiting recovery. */
					_n('BaraTables is still restoring %d linked chart after a cancelled Table ID change. It will retry automatically.', 'BaraTables is still restoring %d linked charts after a cancelled Table ID change. It will retry automatically.', max(1, $recovery['remaining']), 'baratables'),
					max(1, $recovery['remaining'])
				),
				'error'
			);
		} elseif ($recovery['recovered'] > 0) {
			BaraTables_Admin_Notice::queue(
				sprintf(
					/* translators: %d is the number of linked charts restored. */
					_n('BaraTables restored %d linked chart after a cancelled Table ID change.', 'BaraTables restored %d linked charts after a cancelled Table ID change.', $recovery['recovered'], 'baratables'),
					$recovery['recovered']
				),
				'success'
			);
		}
		$error = $this->service()->migrate_data_schema();
		if ($error) {
			BaraTables_Admin_Notice::queue(
				__('BaraTables could not finish upgrading its saved data. Your previous data was kept and the upgrade will retry automatically.', 'baratables'),
				'error'
			);
		}
	}

	public function cleanup_admin_menu(): void {
		$tables_parent = 'edit.php?post_type=' . BaraTables_Repository::CPT;
		remove_submenu_page($tables_parent, 'post-new.php?post_type=' . BaraTables_Repository::CPT);
		$this->reorder_tables_submenu($tables_parent);
	}

	public function highlight_tables_parent_menu(?string $parent_file): string {
		if ($this->is_tables_add_new_screen() || $this->is_tables_import_screen()) {
			return 'edit.php?post_type=' . BaraTables_Repository::CPT;
		}
		return $parent_file ?? '';
	}

	public function highlight_tables_submenu(?string $submenu_file): string {
		if ($this->is_tables_add_new_screen()) {
			return 'edit.php?post_type=' . BaraTables_Repository::CPT;
		}
		if ($this->is_tables_import_screen()) {
			return BaraTables_Admin_Options::PAGE_SLUG;
		}
		return $submenu_file ?? '';
	}

	private function is_tables_add_new_screen(): bool {
		global $pagenow;
		if ($pagenow !== 'post-new.php') {
			return false;
		}
		$post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP admin URL parameter.
		return $post_type === BaraTables_Repository::CPT;
	}

	private function is_tables_import_screen(): bool {
		global $pagenow;
		if ($pagenow !== 'edit.php') {
			return false;
		}
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP admin URL parameter.
		return $page === BaraTables_Admin_Options::PAGE_SLUG;
	}

	private function reorder_tables_submenu(string $parent_slug): void {
		global $submenu;
		if (empty($submenu[$parent_slug]) || !is_array($submenu[$parent_slug])) {
			return;
		}
		$items = array_values($submenu[$parent_slug]);
		$import_index = null;
		$chart_index = null;
		$chart_slug = 'edit.php?post_type=' . BaraTables_Chart_Repository::CPT;
		foreach ($items as $index => $item) {
			$slug = $item[2] ?? '';
			if ($slug === BaraTables_Admin_Options::PAGE_SLUG) {
				$import_index = $index;
			} elseif ($slug === $chart_slug && $chart_index === null) {
				$chart_index = $index;
			}
		}
		if ($import_index === null || $chart_index === null || $import_index < $chart_index) {
			return;
		}
		$import_item = $items[$import_index];
		unset($items[$import_index]);
		$items = array_values($items);
		array_splice($items, $chart_index, 0, [$import_item]);
		// WordPress exposes no API for reordering submenu entries -- add_submenu_page() appends and
		// remove_submenu_page() only deletes. add_submenu_page() does accept a $position (WP 5.3+),
		// but that only places an entry as it is added; the ordering fixed up here involves
		// entries core registered for the post type, so the array still has to be rewritten.
		// The write is scoped to this plugin's own parent slug and reorders existing items only.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no core API for submenu ordering.
		$submenu[$parent_slug] = $items;
	}
}
