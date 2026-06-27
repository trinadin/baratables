<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/admin/support.php';
require_once __DIR__ . '/admin/ui.php';
require_once __DIR__ . '/admin/actions.php';
require_once __DIR__ . '/admin/pages.php';
require_once __DIR__ . '/admin/import.php';
require_once __DIR__ . '/admin/options.php';
require_once __DIR__ . '/admin/admin.php';
require_once __DIR__ . '/frontend.php';

class BaraTables {
	private BaraTables_Repository $repo;
	private BaraTables_Chart_Repository $chart_repo;
	private BaraTables_Service $service;
	private BaraTables_Chart_Service $chart_service;
	private BaraTables_Admin $admin;
	private BaraTables_Chart_Admin $chart_admin;
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
		$this->admin = new BaraTables_Admin($this->service, $this->repo, $this->plugin_url, $this->plugin_path);
		$this->chart_admin = new BaraTables_Chart_Admin($this->chart_service, $this->chart_repo, $this->service, BaraTables_Admin::NONCE_ACTION, BaraTables_Admin::NONCE_FIELD);
		$this->frontend = new BaraTables_Frontend($this->service, $this->chart_service, $this->plugin_url, $this->plugin_path);

		add_action('init', [$this->admin, 'register_cpt']);
		add_action('init', [$this->chart_admin, 'register_cpt']);
		add_action('admin_init', [$this->service, 'migrate_auto_labels']);
		add_action('admin_menu', [$this, 'cleanup_admin_menu'], 20);
		add_filter('parent_file', [$this, 'highlight_tables_parent_menu']);
		add_filter('submenu_file', [$this, 'highlight_tables_submenu'], 10, 2);
		add_action('admin_enqueue_scripts', [$this->admin, 'enqueue_admin_assets']);
		add_action('wp_enqueue_scripts', [$this->frontend, 'register_frontend_assets']);
		add_shortcode('bara_table', [$this->frontend, 'render_shortcode']);
		add_shortcode('bara_chart', [$this->frontend, 'render_chart_shortcode']);
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

	public function highlight_tables_submenu(?string $submenu_file, ?string $parent_file): string {
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
		$submenu[$parent_slug] = $items;
	}
}
