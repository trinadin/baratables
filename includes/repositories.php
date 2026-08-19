<?php

if (!defined('ABSPATH')) {
	exit;
}

/** Canonical identity metadata shared by repositories and admin persistence. */
final class BaraTables_Entity_Descriptor {
	public static function table(): array {
		return [
			'cpt' => BaraTables_Repository::CPT,
			'meta_key' => BaraTables_Repository::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Descriptor field, not query arguments.
			'meta_slug' => BaraTables_Repository::META_SLUG,
		];
	}

	public static function chart(): array {
		return [
			'cpt' => BaraTables_Chart_Repository::CPT,
			'meta_key' => BaraTables_Chart_Repository::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Descriptor field, not query arguments.
			'meta_slug' => BaraTables_Chart_Repository::META_SLUG,
		];
	}

}

/** Suppress slug-repair hooks while a coordinated write or rollback is in progress. */
final class BaraTables_Persistence_Guard {
	private static int $depth = 0;

	public static function begin(): void {
		self::$depth++;
	}

	public static function end(): void {
		self::$depth = max(0, self::$depth - 1);
	}

	public static function active(): bool {
		return self::$depth > 0;
	}
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

	/** @return array{exists:bool,value:mixed} */
	public static function snapshot_meta(int $post_id, string $meta_key): array {
		return [
			'exists' => metadata_exists('post', $post_id, $meta_key),
			'value' => get_post_meta($post_id, $meta_key, true),
		];
	}

	/** Restore one exact metadata snapshot. Used by the editor-level rollback coordinator. */
	public static function restore_meta_snapshot(int $post_id, string $meta_key, array $snapshot, bool $definition = false): ?WP_Error {
		BaraTables_Persistence_Guard::begin();
		try {
			self::restore_meta_snapshot_unchecked($post_id, $meta_key, $snapshot, $definition);
			if (!self::meta_matches_snapshot($post_id, $meta_key, $snapshot)) {
				return new WP_Error('baratables_meta_rollback_failed', __('WordPress could not restore the previously saved plugin data.', 'baratables'));
			}
		} finally {
			BaraTables_Persistence_Guard::end();
		}
		return null;
	}

	/** Write only a definition, verify it, and restore the previous value on failure. */
	public static function persist_definition(int $post_id, string $meta_key, array $definition): ?WP_Error {
		$before = self::snapshot_meta($post_id, $meta_key);
		BaraTables_Persistence_Guard::begin();
		try {
			self::write_definition($post_id, $meta_key, $definition);
			$stored = get_post_meta($post_id, $meta_key, true);
			if (is_array($stored) && $stored === $definition) {
				return null;
			}
			$error = new WP_Error('baratables_definition_not_saved', __('WordPress did not save the plugin definition.', 'baratables'));
			self::restore_meta_snapshot_unchecked($post_id, $meta_key, $before, true);
			return self::with_rollback_result($error, $post_id, $meta_key, $before);
		} finally {
			BaraTables_Persistence_Guard::end();
		}
	}

	/** Write a definition and lookup ID as one compensated persistence unit. */
	public static function persist(int $post_id, string $meta_key, string $meta_slug, array $definition, string $slug): ?WP_Error {
		$before_definition = self::snapshot_meta($post_id, $meta_key);
		$before_slug = self::snapshot_meta($post_id, $meta_slug);
		$error = null;

		BaraTables_Persistence_Guard::begin();
		try {
			self::write_definition($post_id, $meta_key, $definition);
			$stored_definition = get_post_meta($post_id, $meta_key, true);
			if (!is_array($stored_definition) || $stored_definition !== $definition) {
				$error = new WP_Error('baratables_definition_not_saved', __('WordPress did not save the plugin definition.', 'baratables'));
			} else {
				update_post_meta($post_id, $meta_slug, $slug);
				$stored_slug = get_post_meta($post_id, $meta_slug, true);
				if (!is_string($stored_slug) || $stored_slug !== $slug) {
					$error = new WP_Error('baratables_meta_slug_not_saved', __('WordPress did not save the plugin ID lookup.', 'baratables'));
				}
			}

			if (!$error) {
				return null;
			}

			self::restore_meta_snapshot_unchecked($post_id, $meta_key, $before_definition, true);
			self::restore_meta_snapshot_unchecked($post_id, $meta_slug, $before_slug, false);
			$error = self::with_rollback_result($error, $post_id, $meta_key, $before_definition);
			return self::with_rollback_result($error, $post_id, $meta_slug, $before_slug);
		} finally {
			BaraTables_Persistence_Guard::end();
		}
	}

	private static function write_definition(int $post_id, string $meta_key, array $definition): void {
		// update_post_meta() unslashes values before serialization. Slash the complete definition
		// first so literal backslashes in imported cells, queries, and passwords survive exactly.
		update_post_meta($post_id, $meta_key, wp_slash($definition));
	}

	private static function restore_meta_snapshot_unchecked(int $post_id, string $meta_key, array $snapshot, bool $definition): void {
		if (!empty($snapshot['exists'])) {
			$value = $snapshot['value'] ?? '';
			update_post_meta($post_id, $meta_key, $definition && is_array($value) ? wp_slash($value) : $value);
			return;
		}
		delete_post_meta($post_id, $meta_key);
	}

	private static function meta_matches_snapshot(int $post_id, string $meta_key, array $snapshot): bool {
		$exists = metadata_exists('post', $post_id, $meta_key);
		if ($exists !== !empty($snapshot['exists'])) {
			return false;
		}
		return !$exists || get_post_meta($post_id, $meta_key, true) === ($snapshot['value'] ?? '');
	}

	private static function with_rollback_result(WP_Error $error, int $post_id, string $meta_key, array $snapshot): WP_Error {
		if (!self::meta_matches_snapshot($post_id, $meta_key, $snapshot)) {
			$data = $error->get_error_data();
			$failures = is_array($data) && isset($data['rollback_failures']) && is_array($data['rollback_failures'])
				? $data['rollback_failures']
				: [];
			$failures[] = ['post_id' => $post_id, 'plugin_field' => $meta_key];
			$error->add_data([
				'rollback_failures' => $failures,
			]);
		}
		return $error;
	}

	protected function register_meta_keys_common(string $cpt, string $meta_key, string $meta_slug, callable $sanitize_callback, ?callable $auth_callback = null): void {
		$auth_callback = $auth_callback ?: [$this, 'meta_auth_callback'];

		// show_in_rest is false: the plugin never uses the REST API (classic metaboxes only), and the
		// definition meta holds external-DB connection details, custom queries, and access-control
		// config that must not be reachable through the public REST endpoints.
		register_post_meta($cpt, $meta_key, [
			'type'              => 'object',
			'single'            => true,
			'show_in_rest'      => false,
			'object_subtype'    => $cpt,
			'sanitize_callback' => $sanitize_callback,
			'auth_callback'     => $auth_callback,
		]);

		register_post_meta($cpt, $meta_slug, [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
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
			// No REST endpoints: nothing in the plugin consumes them, and leaving them on let
			// anonymous requests enumerate every table/chart post (ids, titles, slugs).
			'show_in_rest'       => false,
			'menu_icon'          => $menu_icon,
			'menu_position'      => $menu_position,
			// No 'revisions': the table/chart definition lives in post meta that is not revisioned,
			// so every save stored a title-only revision that could never restore anything useful
			// while the rows accumulated without bound.
			'supports'           => ['title'],
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
			'no_found_rows'  => true,
			'fields'         => 'ids',
		]);

		// With fields => 'ids', WP_Query skips update_post_caches(), so the per-id
		// get_post()/get_post_meta() in the mapper would each be an individual query
		// (1 + 2N). Prime the post + meta caches in one bulk pass so the mapper reads
		// from cache. Term cache is not needed by the mapper.
		if (!empty($query->posts)) {
			_prime_post_caches($query->posts, false, true);
		}

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
		$post_id = $this->find_post_id_common($cpt, $meta_slug, $slug, $include_trash);
		return $post_id ? $mapper($post_id, $include_trash) : null;
	}

	protected function find_post_id_common(string $cpt, string $meta_slug, string $slug, bool $include_trash): int {
		if ($slug === '') {
			return 0;
		}
		$base_args = [
			'post_type'      => $cpt,
			'post_status'    => $this->get_statuses($include_trash),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'update_post_term_cache' => false,
		];
		// post_name is WordPress' indexed canonical slug and is synchronized on every plugin save.
		// Hydrate the one matching post + its meta so the mapper immediately following this lookup
		// reads both from cache.
		$query = new WP_Query(array_merge($base_args, [
			'name'            => $slug,
		]));
		if (!empty($query->posts)) {
			return (int) $query->posts[0]->ID;
		}

		// Definitions created before post_name synchronization can still be found by their legacy
		// lookup meta. This fallback is cold compatibility; current records stay on the indexed path.
		$query = new WP_Query(array_merge($base_args, [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for slug-based lookup; indexed meta key.
			'meta_query'     => [
				[
					'key'   => $meta_slug,
					'value' => $slug,
				],
			],
		]));

		return !empty($query->posts) ? (int) $query->posts[0]->ID : 0;
	}

	protected function get_post_id_by_slug_common(string $cpt, string $meta_slug, string $slug): int {
		return $this->find_post_id_common($cpt, $meta_slug, $slug, true);
	}

	public function meta_auth_callback(): bool {
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
		// Called once per string value while sanitizing a definition. wp_kses_allowed_html('post')
		// runs filters and rebuilds the same table every call, so memoize it per request. The result
		// is deterministic within a request (kses filters are registered once), so caching is safe.
		static $allowed = null;
		if ($allowed !== null) {
			return $allowed;
		}
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
	private ?array $resolved_descriptor = null;

	abstract protected function get_descriptor(): array;
	abstract protected function get_labels(): array;
	abstract protected function get_menu_icon(): string;
	abstract protected function get_menu_position(): int;

	/**
	 * @return bool|string
	 */
	protected function get_show_in_menu() {
		return true;
	}

	private function descriptor(): array {
		if ($this->resolved_descriptor === null) {
			$this->resolved_descriptor = $this->get_descriptor();
		}
		return $this->resolved_descriptor;
	}

	protected function get_cpt(): string {
		return $this->descriptor()['cpt'];
	}

	protected function get_meta_key(): string {
		return $this->descriptor()['meta_key'];
	}

	protected function get_meta_slug(): string {
		return $this->descriptor()['meta_slug'];
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

	public function sanitize_meta($value): array {
		return $this->sanitize_array_meta_value($value);
	}

	/**
	 * @param string[] $statuses Restrict results to these statuses; empty (default) searches all
	 *                           live statuses. The block pickers pass ['publish'] for non-admins.
	 * @return array{results:array<int,array{id:string,text:string}>,more:bool}
	 */
	public function search_definition_choices(string $search, int $page = 1, int $per_page = 20, array $statuses = []): array {
		$page = max(1, $page);
		$per_page = min(50, max(1, $per_page));
		$args = [
			'post_type' => $this->get_cpt(),
			'post_status' => $statuses === [] ? $this->get_statuses(false) : $statuses,
			'posts_per_page' => $per_page,
			'paged' => $page,
			'orderby' => 'title',
			'order' => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// Keep incomplete posts out without loading any serialized definitions. This bounded,
			// paginated query replaces the previous unbounded all-table hydration path.
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Existence filter avoids per-result definition reads.
				['key' => $this->get_meta_key(), 'compare' => 'EXISTS'],
			],
		];
		if ($search !== '') {
			$args['s'] = $search;
		}
		$query = new WP_Query($args);
		$results = [];
		foreach ($query->posts as $post) {
			if (!$post instanceof WP_Post || $post->post_name === '') {
				continue;
			}
			$results[] = [
				'id' => (string) $post->post_name,
				'text' => $post->post_title !== '' ? (string) $post->post_title : (string) $post->post_name,
			];
		}
		return ['results' => $results, 'more' => $page < (int) $query->max_num_pages];
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

	protected function get_descriptor(): array {
		return BaraTables_Entity_Descriptor::table();
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

	/**
	 * Legacy id => name API. The chart editor uses search_definition_choices() so its request is
	 * bounded, but integrations may still rely on this full list.
	 */
	public function get_definition_choices(bool $include_trash = false): array {
		$query = new WP_Query([
			'post_type' => static::CPT,
			'post_status' => $this->get_statuses($include_trash),
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
			'no_found_rows' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Compatibility API must exclude incomplete posts without reading each definition.
				['key' => static::META_KEY, 'compare' => 'EXISTS'],
			],
		]);
		$choices = [];
		foreach ($query->posts as $post) {
			if (!$post instanceof WP_Post || $post->post_name === '') {
				continue;
			}
			$slug = (string) $post->post_name;
			$choices[$slug] = $post->post_title !== '' ? (string) $post->post_title : $slug;
		}
		return $choices;
	}

	public function find_first_definition(): ?array {
		$query = new WP_Query([
			'post_type' => static::CPT,
			'post_status' => $this->get_statuses(false),
			'posts_per_page' => 1,
			'orderby' => 'title',
			'order' => 'ASC',
			'no_found_rows' => true,
			'update_post_term_cache' => false,
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-row first-table fallback must exclude incomplete posts.
				['key' => static::META_KEY, 'compare' => 'EXISTS'],
			],
		]);
		if (empty($query->posts)) {
			return null;
		}
		return $this->map_post_to_item((int) $query->posts[0]->ID);
	}

	/** @return array<string,array{definition:array,post_id:int}> */
	public function find_definitions_with_post_ids(array $slugs): array {
		$slugs = array_values(array_unique(array_filter(array_map('sanitize_title', $slugs))));
		if (empty($slugs)) {
			return [];
		}
		$query = new WP_Query([
			'post_type' => static::CPT,
			'post_status' => $this->get_statuses(false),
			'posts_per_page' => count($slugs),
			'post_name__in' => $slugs,
			'no_found_rows' => true,
			'update_post_term_cache' => false,
		]);
		$found = [];
		foreach ($query->posts as $post) {
			$definition = $this->map_post_to_item((int) $post->ID);
			if ($definition) {
				$found[(string) $post->post_name] = ['definition' => $definition, 'post_id' => (int) $post->ID];
			}
		}
		return $found;
	}

	public function find_definition(string $slug, bool $include_trash = false): ?array {
		return $this->find_item($slug, $include_trash);
	}

}


class BaraTables_Chart_Repository extends BaraTables_Abstract_CPT_Repository {
	public const CPT = 'btbl_chart';
	public const META_KEY = '_btbl_chart_definition';
	public const META_SLUG = '_btbl_chart_slug';

	protected function get_descriptor(): array {
		return BaraTables_Entity_Descriptor::chart();
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
