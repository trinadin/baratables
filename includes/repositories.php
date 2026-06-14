<?php

if (!defined('ABSPATH')) {
	exit;
}

abstract class BaraTables_Base_Repository {
	private const STATUSES_LIVE = ['publish', 'draft', 'pending', 'future', 'private'];
	private const STATUSES_WITH_TRASH = ['publish', 'draft', 'pending', 'future', 'private', 'trash'];
	private const RAW_META_STRING_KEYS = [
		'custom_query_raw' => true,
		'pass' => true,
		'replace' => true,
		'search' => true,
		'value_overrides_raw' => true,
	];

	protected function get_statuses(bool $include_trash): array {
		return $include_trash ? self::STATUSES_WITH_TRASH : self::STATUSES_LIVE;
	}

	public static function persist(int $post_id, string $meta_key, string $meta_slug, array $definition, string $slug): void {
		update_post_meta($post_id, $meta_key, $definition);
		update_post_meta($post_id, $meta_slug, $slug);
	}

	protected function register_meta_keys_common(string $cpt, string $meta_key, string $meta_slug, callable $sanitize_callback, ?callable $auth_callback = null): void {
		$auth_callback = $auth_callback ?: [$this, 'meta_auth_callback'];
		$definition_schema = [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'string'],
				'name' => ['type' => 'string'],
				'status' => ['type' => 'string'],
				'post_type' => ['type' => 'string'],
				'post_types' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
				],
				'source_type' => ['type' => 'string'],
				'columns' => [
					'type' => 'array',
					'items' => ['type' => 'object'],
				],
				'chart' => ['type' => 'object'],
				'table_options' => ['type' => 'object'],
				'filter_order' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
				],
			],
			'additionalProperties' => true,
		];

		register_post_meta($cpt, $meta_key, [
			'type'              => 'object',
			'single'            => true,
			'show_in_rest'      => [
				'schema' => $definition_schema,
			],
			'object_subtype'    => $cpt,
			'sanitize_callback' => $sanitize_callback,
			'auth_callback'     => $auth_callback,
		]);

		register_post_meta($cpt, $meta_slug, [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'object_subtype'    => $cpt,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth_callback,
		]);
	}

	protected function register_cpt_common(string $cpt, array $labels, string $menu_icon, int $menu_position, $show_in_menu = true): void {
		register_post_type($cpt, [
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => $show_in_menu,
			'show_in_admin_bar'  => true,
			'show_in_nav_menus'  => false,
			'exclude_from_search' => true,
			'show_in_rest'       => true,
			'menu_icon'          => $menu_icon,
			'menu_position'      => $menu_position,
			'supports'           => ['title', 'revisions'],
			'capability_type'    => [$cpt, $cpt . 's'],
			'capabilities'        => [
				'edit_post'              => 'edit_' . $cpt,
				'read_post'              => 'read_' . $cpt,
				'delete_post'            => 'delete_' . $cpt,
				'read'                   => 'manage_options',
				'edit_posts'             => 'manage_options',
				'edit_others_posts'      => 'manage_options',
				'delete_posts'           => 'manage_options',
				'publish_posts'          => 'manage_options',
				'read_private_posts'     => 'manage_options',
				'create_posts'           => 'manage_options',
				'delete_private_posts'   => 'manage_options',
				'delete_published_posts' => 'manage_options',
				'delete_others_posts'    => 'manage_options',
				'edit_private_posts'     => 'manage_options',
				'edit_published_posts'   => 'manage_options',
			],
			'map_meta_cap'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
			'hierarchical'       => false,
		]);
	}

	protected function query_items_common(string $cpt, bool $include_trash, callable $mapper): array {
		$statuses = $this->get_statuses($include_trash);
		$query = new WP_Query([
			'post_type'      => $cpt,
			'post_status'    => $statuses,
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		]);

		$items = [];
		foreach ($query->posts as $post_id) {
			$item = $mapper((int) $post_id, $include_trash);
			if ($item) {
				$items[] = $item;
			}
		}
		return $items;
	}

	protected function find_item_common(string $cpt, string $meta_slug, string $slug, bool $include_trash, callable $mapper): ?array {
		$statuses = $this->get_statuses($include_trash);
		$query = new WP_Query([
			'post_type'      => $cpt,
			'post_status'    => $statuses,
			'posts_per_page' => 1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for slug-based lookup; indexed meta key.
			'meta_query'     => [
				[
					'key'   => $meta_slug,
					'value' => $slug,
				],
			],
			'fields'         => 'ids',
		]);

		if (empty($query->posts)) {
			return null;
		}

		return $mapper((int) $query->posts[0], $include_trash);
	}

	protected function get_post_id_by_slug_common(string $cpt, string $meta_slug, string $slug): int {
		$query = new WP_Query([
			'post_type'      => $cpt,
			'post_status'    => self::STATUSES_WITH_TRASH,
			'posts_per_page' => 1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for slug-based lookup; indexed meta key.
			'meta_query'     => [
				[
					'key'   => $meta_slug,
					'value' => $slug,
				],
			],
			'fields'         => 'ids',
		]);
		return !empty($query->posts) ? (int) $query->posts[0] : 0;
	}

	public function meta_auth_callback(...$args): bool {
		return current_user_can('manage_options');
	}

	protected function sanitize_array_meta_value($value): array {
		if (is_array($value)) {
			return $this->sanitize_meta_array($value);
		}
		if (is_string($value)) {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				return $this->sanitize_meta_array($decoded);
			}
		}
		return [];
	}

	private function sanitize_meta_array(array $value): array {
		$clean = [];
		foreach ($value as $key => $item) {
			$clean_key = is_int($key) ? $key : sanitize_text_field((string) $key);
			if ($clean_key === '') {
				continue;
			}
			$clean[$clean_key] = $this->sanitize_meta_value($item, (string) $clean_key);
		}
		return $clean;
	}

	private function sanitize_meta_value($value, string $key) {
		if (is_array($value)) {
			return $this->sanitize_meta_array($value);
		}
		if (is_bool($value) || is_int($value) || is_float($value)) {
			return $value;
		}
		if (!is_scalar($value)) {
			return '';
		}

		$clean = str_replace("\0", '', (string) $value);
		$clean = (string) wp_check_invalid_utf8($clean, true);
		if (isset(self::RAW_META_STRING_KEYS[$key])) {
			return $clean;
		}

		return wp_kses($clean, $this->get_meta_allowed_html());
	}

	private function get_meta_allowed_html(): array {
		$allowed = wp_kses_allowed_html('post');
		if (class_exists('BaraTables_Service')) {
			foreach (BaraTables_Service::allowed_inline_html() as $tag => $attrs) {
				$allowed[$tag] = array_merge($allowed[$tag] ?? [], $attrs);
			}
		}
		return $allowed;
	}
}


abstract class BaraTables_Abstract_CPT_Repository extends BaraTables_Base_Repository {
	abstract protected function get_cpt(): string;
	abstract protected function get_meta_key(): string;
	abstract protected function get_meta_slug(): string;
	abstract protected function get_labels(): array;
	abstract protected function get_menu_icon(): string;
	abstract protected function get_menu_position(): int;

	/**
	 * @return bool|string
	 */
	protected function get_show_in_menu() {
		return true;
	}

	public function register_cpt(): void {
		$this->register_cpt_common(
			$this->get_cpt(),
			$this->get_labels(),
			$this->get_menu_icon(),
			$this->get_menu_position(),
			$this->get_show_in_menu()
		);

		$this->register_meta_keys();
	}

	protected function register_meta_keys(): void {
		$this->register_meta_keys_common(
			$this->get_cpt(),
			$this->get_meta_key(),
			$this->get_meta_slug(),
			[$this, 'sanitize_meta']
		);
	}

	public function get_items(bool $include_trash = false): array {
		return $this->query_items_common($this->get_cpt(), $include_trash, function (int $post_id, bool $with_trash) {
			return $this->map_post_to_item($post_id, $with_trash);
		});
	}

	public function find_item(string $slug, bool $include_trash = false): ?array {
		return $this->find_item_common($this->get_cpt(), $this->get_meta_slug(), $slug, $include_trash, function (int $post_id, bool $with_trash) {
			return $this->map_post_to_item($post_id, $with_trash);
		});
	}

	public function get_post_id_by_slug(string $slug): int {
		return $this->get_post_id_by_slug_common($this->get_cpt(), $this->get_meta_slug(), $slug);
	}

	public function sanitize_meta($value, $meta_key = '', $object_type = ''): array {
		return $this->sanitize_array_meta_value($value);
	}

	protected function map_post_to_item(int $post_id, bool $include_trash = false): ?array {
		$post = get_post($post_id);
		if (!$post || $post->post_type !== $this->get_cpt()) {
			return null;
		}
		if (!$include_trash && $post->post_status === 'trash') {
			return null;
		}

		$item = get_post_meta($post_id, $this->get_meta_key(), true);
		if (!is_array($item)) {
			return null;
		}
		if (empty($item['id'])) {
			$item['id'] = $post->post_name ?: (string) $post_id;
		}
		if (empty($item['name'])) {
			$item['name'] = $post->post_title;
		}
		$item['status'] = $post->post_status;
		return $item;
	}

}


class BaraTables_Repository extends BaraTables_Abstract_CPT_Repository {
	public const CPT = 'btbl_table';
	public const META_KEY = '_btbl_definition';
	public const META_SLUG = '_btbl_slug';

	protected function get_cpt(): string {
		return self::CPT;
	}

	protected function get_meta_key(): string {
		return self::META_KEY;
	}

	protected function get_meta_slug(): string {
		return self::META_SLUG;
	}

	protected function get_labels(): array {
		return [
			'name'               => _x('Tables', 'post type general name', 'baratables'),
			'singular_name'      => _x('Table', 'post type singular name', 'baratables'),
			'menu_name'          => _x('BaraTables', 'admin menu', 'baratables'),
			'name_admin_bar'     => _x('Table', 'add new on admin bar', 'baratables'),
			'add_new'            => _x('Add New', 'table', 'baratables'),
			'add_new_item'       => __('Add Table', 'baratables'),
			'new_item'           => __('New Table', 'baratables'),
			'edit_item'          => __('Edit Table', 'baratables'),
			'view_item'          => __('View Table', 'baratables'),
			'all_items'          => __('Tables', 'baratables'),
			'search_items'       => __('Search Tables', 'baratables'),
			'not_found'          => __('No tables found.', 'baratables'),
			'not_found_in_trash' => __('No tables found in Trash.', 'baratables'),
		];
	}

	protected function get_menu_icon(): string {
		return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA4MTAgNzgwIiBjbGFzcz0id3BzLW1lbnUtaWNvbiI+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gODggNzgxIEMgMzguODQ2NjU3IDc4MSAtMSA3NDEuMTUzMzIgLTEgNjkyIEwgLTEgOTAgQyAtMSA0MC44NDY2OCAzOC44NDY2NTcgMSA4OCAxIEwgNzIwIDEgQyA3NjkuMTUzMzIgMSA4MDkgNDAuODQ2NjggODA5IDkwIEwgODA5IDY5MiBDIDgwOSA3NDEuMTUzMzIgNzY5LjE1MzMyIDc4MSA3MjAgNzgxIEwgODggNzgxIFogTSAxMzcgNjYxIEMgMTE2LjAxMzE4NCA2NjEgOTkgNjQzLjk4NjgxNiA5OSA2MjMgTCA5OSAxNTkgQyA5OSAxMzguMDEzMTg0IDExNi4wMTMxODQgMTIxIDEzNyAxMjEgTCA2NzEgMTIxIEMgNjkxLjk4NjgxNiAxMjEgNzA5IDEzOC4wMTMxODQgNzA5IDE1OSBMIDcwOSA2MjMgQyA3MDkgNjQzLjk4NjgxNiA2OTEuOTg2ODE2IDY2MSA2NzEgNjYxIEwgMTM3IDY2MSBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gMTU2IDI3NSBMIDI4NCAyNzUgTCAyODQgMTc3IEwgMTU2IDE3NyBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gMTU2IDQyNCBMIDI4NCA0MjQgTCAyODQgMzI2IEwgMTU2IDMyNiBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gNTI5IDI3NSBMIDY1NyAyNzUgTCA2NTcgMTc3IEwgNTI5IDE3NyBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gNTI5IDQyNCBMIDY1NyA0MjQgTCA2NTcgMzI2IEwgNTI5IDMyNiBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gMzM4IDI3NSBMIDQ3NiAyNzUgTCA0NzYgMTc3IEwgMzM4IDE3NyBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gMzM4IDQyNCBMIDQ3NiA0MjQgTCA0NzYgMzI2IEwgMzM4IDMyNiBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gMTU2IDYwMyBMIDI4NCA2MDMgTCAyODQgNDc1IEwgMTU2IDQ3NSBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gNTI5IDYwMyBMIDY1NyA2MDMgTCA2NTcgNDc1IEwgNTI5IDQ3NSBaIi8+CiAgICA8cGF0aCBmaWxsPSIjOWNhMWE3IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0gMzM4IDYwMyBMIDQ3NiA2MDMgTCA0NzYgNDc1IEwgMzM4IDQ3NSBaIi8+Cjwvc3ZnPgo=';
	}

	protected function get_menu_position(): int {
		return 54;
	}

	public function get_definitions(bool $include_trash = false): array {
		return $this->get_items($include_trash);
	}

	public function find_definition(string $slug, bool $include_trash = false): ?array {
		return $this->find_item($slug, $include_trash);
	}

}


class BaraTables_Chart_Repository extends BaraTables_Abstract_CPT_Repository {
	public const CPT = 'btbl_chart';
	public const META_KEY = '_btbl_chart_definition';
	public const META_SLUG = '_btbl_chart_slug';

	protected function get_cpt(): string {
		return self::CPT;
	}

	protected function get_meta_key(): string {
		return self::META_KEY;
	}

	protected function get_meta_slug(): string {
		return self::META_SLUG;
	}

	protected function get_labels(): array {
		return [
			'name'               => _x('Charts', 'post type general name', 'baratables'),
			'singular_name'      => _x('Chart', 'post type singular name', 'baratables'),
			'menu_name'          => _x('Charts', 'admin menu', 'baratables'),
			'name_admin_bar'     => _x('Chart', 'add new on admin bar', 'baratables'),
			'add_new'            => _x('Add New', 'chart', 'baratables'),
			'add_new_item'       => __('Add Chart', 'baratables'),
			'new_item'           => __('New Chart', 'baratables'),
			'edit_item'          => __('Edit Chart', 'baratables'),
			'view_item'          => __('View Chart', 'baratables'),
			'all_items'          => __('Charts', 'baratables'),
			'search_items'       => __('Search Charts', 'baratables'),
			'not_found'          => __('No charts found.', 'baratables'),
			'not_found_in_trash' => __('No charts found in Trash.', 'baratables'),
		];
	}

	protected function get_menu_icon(): string {
		return 'dashicons-chart-bar';
	}

	protected function get_menu_position(): int {
		return 55;
	}

	protected function get_show_in_menu() {
		return 'edit.php?post_type=' . BaraTables_Repository::CPT;
	}

	public function find_chart(string $slug, bool $include_trash = false): ?array {
		return $this->find_item($slug, $include_trash);
	}
}
