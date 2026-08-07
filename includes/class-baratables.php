<?php

if (!defined('ABSPATH')) {
	exit;
}

// Front-end + shared layer. The admin half (the seven includes/admin/*.php files) is loaded on
// demand in load_admin() only when a request actually touches wp-admin, AJAX or WP-CLI -- it is
// roughly half the plugin's PHP and a plain front-end page view needs none of it. Verified there
// are no admin-class references anywhere in this layer, so the front end never triggers the load.
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/chart-types.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/row-result.php';
// BaraTables_Service collaborators and concern traits -- must load before services.php.
require_once __DIR__ . '/service-query-sanitize.php';
require_once __DIR__ . '/service-filter-options.php';
require_once __DIR__ . '/service-value-format.php';
require_once __DIR__ . '/service-fields-discovery.php';
require_once __DIR__ . '/service-column-state.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/table-presentation.php';
require_once __DIR__ . '/chart-service.php';
require_once __DIR__ . '/frontend.php';

class BaraTables {
	private BaraTables_Repository $repo;
	private BaraTables_Chart_Repository $chart_repo;
	private BaraTables_Service $service;
	private BaraTables_Chart_Service $chart_service;
	private BaraTables_Frontend $frontend;
	private string $plugin_url;
	private string $plugin_path;

	public function __construct(string $plugin_file) {
		$this->plugin_url = plugin_dir_url($plugin_file);
		$this->plugin_path = plugin_dir_path($plugin_file);
		$this->repo = new BaraTables_Repository();
		$this->chart_repo = new BaraTables_Chart_Repository();
		$this->service = new BaraTables_Service($this->repo);
		$this->chart_service = new BaraTables_Chart_Service($this->repo, $this->chart_repo, $this->service);
		$this->frontend = new BaraTables_Frontend($this->service, $this->chart_service, $this->plugin_url, $this->plugin_path);

		// CPTs must register on the front end too so [bara_table]/[bara_chart] can resolve them.
		// Register straight from the repositories (always loaded), not via the admin objects, so a
		// front-end request has no reason to construct the admin layer at all.
		add_action('init', [$this->repo, 'register_cpt']);
		add_action('init', [$this->chart_repo, 'register_cpt']);
		add_action('wp_enqueue_scripts', [$this->frontend, 'register_frontend_assets']);
		add_shortcode('bara_table', [$this->frontend, 'render_shortcode']);
		add_shortcode('bara_chart', [$this->frontend, 'render_chart_shortcode']);

		if (is_admin() || wp_doing_ajax() || (defined('WP_CLI') && WP_CLI)) {
			$this->load_admin();
		}
	}

	/**
	 * Load and wire the admin half. Called only for wp-admin, AJAX and WP-CLI requests, never for a
	 * plain front-end page view -- the requires and object graph below are otherwise dead weight.
	 */
	private function load_admin(): void {
		require_once __DIR__ . '/admin/support.php';
		require_once __DIR__ . '/admin/form-context.php';
		require_once __DIR__ . '/admin/ui.php';
		require_once __DIR__ . '/admin/actions.php';
		require_once __DIR__ . '/admin/preview.php';
		require_once __DIR__ . '/admin/pages.php';
		require_once __DIR__ . '/admin/import.php';
		require_once __DIR__ . '/admin/options.php';
		require_once __DIR__ . '/admin/admin.php';

		$admin = new BaraTables_Admin($this->service, $this->repo, $this->plugin_url, $this->plugin_path);
		new BaraTables_Chart_Admin($this->chart_service, $this->chart_repo, $this->service, BaraTables_Admin::NONCE_ACTION, BaraTables_Admin::NONCE_FIELD);

		// One-time label backfill, admin side only: admin_init never fires on a front-end page
		// render, so a public request never pays for the write pass over every table. The gate is a
		// non-autoloaded option -- read only here, never on the front end -- so after the first run
		// each admin-side request spends a single indexed option lookup and returns.
		add_action('admin_init', [$this->service, 'migrate_legacy_english_labels']);

		add_action('admin_menu', [$this, 'cleanup_admin_menu'], 20);
		add_filter('parent_file', [$this, 'highlight_tables_parent_menu']);
		add_filter('submenu_file', [$this, 'highlight_tables_submenu'], 10, 1);
		add_action('admin_enqueue_scripts', [$admin, 'enqueue_admin_assets']);
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
