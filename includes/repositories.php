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
	/**
	 * Definition fields whose value is not HTML, so the meta-level markup filter
	 * has to leave them byte-exact.
	 *
	 * wp_kses() does two separate things: it strips markup it does not allow, and
	 * it rewrites the text it keeps, turning "&", "<" and ">" into entities. For a
	 * value that is not HTML, that second half is damage rather than protection: a
	 * media query loses its "<", and the stylesheet the owner wrote is published
	 * as something the browser cannot read.
	 *
	 * Each of these fields has its own sanitiser at the field level, and that is
	 * the one that decides what the value may contain: sanitize_custom_css() for
	 * the stylesheet.
	 */
	private const RAW_META_STRING_KEYS = [
		'customCss' => true,
		'custom_query_raw' => true,
		'pass' => true,
		'replace' => true,
		'search' => true,
		'value_overrides_raw' => true,
	];

	/**
	 * Definition fields whose value is text rather than markup.
	 *
	 * The markup filter still runs -- it is what keeps a script tag out of the
	 * definition -- but the entities it introduces are decoded again, so the stored
	 * value is the characters the owner wrote. Without that, a table called
	 * "Fish & Chips" is stored as "Fish &amp; Chips" and then printed through
	 * esc_html() a second time, which shows the owner "Fish &amp; Chips".
	 */
	private const DECODED_META_STRING_KEYS = [
		'name' => true,
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
		} catch (Throwable $throwable) {
			// A throwable during the restore is reported the way a failed restore is
			// reported: the caller decides what the administrator is told. The guard is
			// still ended by the finally block below.
			return new WP_Error(
				'baratables_meta_rollback_failed',
				__('WordPress could not restore the previously saved plugin data.', 'baratables'),
				self::throwable_data($throwable)
			);
		} finally {
			BaraTables_Persistence_Guard::end();
		}
		return null;
	}

	/** Write only a definition, verify it, and restore the previous value on failure. */
	public static function persist_definition(int $post_id, string $meta_key, array $definition): ?WP_Error {
		$before = self::snapshot_meta($post_id, $meta_key);
		// The stored value is the sanitized one, so that is what the verification
		// compares against. Comparing against the caller's array instead would read
		// a sanitizer's rewrite as a failed write.
		$expected = self::sanitize_definition($definition);
		BaraTables_Persistence_Guard::begin();
		try {
			self::write_definition($post_id, $meta_key, $definition);
			$stored = get_post_meta($post_id, $meta_key, true);
			if (is_array($stored) && $stored === $expected) {
				return null;
			}
			$error = new WP_Error('baratables_definition_not_saved', __('WordPress did not save the plugin definition.', 'baratables'));
			self::restore_meta_snapshot_if_owned($post_id, $meta_key, $before, true, self::owned_value($post_id, $meta_key, $expected));
			return self::with_rollback_result($error, $post_id, $meta_key, $before);
		} catch (Throwable $throwable) {
			// Something raised while the definition was being written -- a third-party
			// save_post hook raising an Error, for example. Without this catch the
			// exception escapes, the finally block ends the guard, and the table is
			// left half-written with the administrator's content lost. The same
			// compensation the error path performs runs here, and the caller gets a
			// WP_Error so its "could not be saved" notice path runs.
			$data = self::throwable_data($throwable);
			$rollback_throwable = self::compensate_safely($post_id, $meta_key, $before, true, self::owned_value($post_id, $meta_key, $expected));
			if ($rollback_throwable) {
				$data['rollback_exception'] = get_class($rollback_throwable) . ': ' . $rollback_throwable->getMessage();
			}
			$error = new WP_Error('baratables_definition_write_failed', __('WordPress did not save the plugin definition.', 'baratables'), $data);
			return self::with_rollback_result($error, $post_id, $meta_key, $before);
		} finally {
			BaraTables_Persistence_Guard::end();
		}
	}

	/** Compare-and-swap a definition; never roll back across a concurrent writer. */
	public static function persist_definition_if_unchanged(int $post_id, string $meta_key, array $expected, array $definition): ?WP_Error {
		// The stored value is the sanitized one; see persist_definition().
		$expected_stored = self::sanitize_definition($definition);
		if (empty($expected)) {
			// An empty expected value cannot be expressed as a WHERE clause: WordPress
			// drops the meta_value condition for an empty prev_value, so the write would
			// land unconditionally. Refuse instead of overwriting a value this caller
			// never read.
			return new WP_Error('baratables_definition_conflict', __('The plugin definition changed during this operation.', 'baratables'));
		}
		BaraTables_Persistence_Guard::begin();
		try {
			update_post_meta($post_id, $meta_key, wp_slash($definition), $expected);
			$stored = get_post_meta($post_id, $meta_key, true);
			if (is_array($stored) && $stored === $expected_stored) {
				return null;
			}
			if (!is_array($stored) || $stored !== $expected) {
				return new WP_Error('baratables_definition_conflict', __('The plugin definition changed during this operation.', 'baratables'));
			}
			return new WP_Error('baratables_definition_not_saved', __('WordPress did not save the plugin definition.', 'baratables'));
		} catch (Throwable $throwable) {
			// No rollback here on purpose: this method never rolls back across a
			// concurrent writer, and a value another writer stored after this write is
			// newer than anything this call could restore. The caller is told the
			// write did not complete.
			return new WP_Error(
				'baratables_definition_write_failed',
				__('WordPress did not save the plugin definition.', 'baratables'),
				self::throwable_data($throwable)
			);
		} finally {
			BaraTables_Persistence_Guard::end();
		}
	}

	/**
	 * Compare-and-swap a definition and its lookup ID as one compensated unit.
	 *
	 * The expected value is the raw stored definition the caller read, exactly as
	 * get_post_meta() returned it. The write lands only while the row still holds
	 * that value, so a save built from a stale read can never overwrite the save
	 * another administrator committed in the meantime. The lookup ID belongs to the
	 * same unit, so it is written only after the compare-and-swap succeeded.
	 *
	 * A conflict is reported with the 'baratables_definition_conflict' code and
	 * nothing is rolled back: the stored definition belongs to the writer that
	 * committed it last.
	 *
	 * @param mixed $expected The raw stored definition, exactly as get_post_meta() returned it.
	 */
	public static function persist_if_unchanged(int $post_id, string $meta_key, string $meta_slug, $expected, array $definition, string $slug): ?WP_Error {
		$before_definition = self::snapshot_meta($post_id, $meta_key);
		$before_slug = self::snapshot_meta($post_id, $meta_slug);
		// The stored value is the sanitized one; see persist_definition().
		$expected_definition = self::sanitize_definition($definition);

		BaraTables_Persistence_Guard::begin();
		try {
			self::compare_and_set_definition($post_id, $meta_key, $expected, $definition);
			$stored_definition = get_post_meta($post_id, $meta_key, true);
			if ($stored_definition === $expected_definition) {
				// The write landed. The lookup ID is part of the same unit, so it is
				// written now -- after the compare-and-swap, never before it.
				update_post_meta($post_id, $meta_slug, $slug);
				$stored_slug = get_post_meta($post_id, $meta_slug, true);
				if (is_string($stored_slug) && $stored_slug === $slug) {
					return null;
				}
				$error = new WP_Error('baratables_meta_slug_not_saved', __('WordPress did not save the plugin ID lookup.', 'baratables'));
				self::restore_meta_snapshot_if_owned($post_id, $meta_key, $before_definition, true, self::owned_value($post_id, $meta_key, $expected_definition));
				self::restore_meta_snapshot_if_owned($post_id, $meta_slug, $before_slug, false, self::owned_value($post_id, $meta_slug, $slug));
				$error = self::with_rollback_result($error, $post_id, $meta_key, $before_definition);
				return self::with_rollback_result($error, $post_id, $meta_slug, $before_slug);
			}
			if ($stored_definition !== $expected) {
				// The row holds a value that is neither the one this caller read nor the
				// one it wanted to write: another writer got there first. Nothing is
				// rolled back, because the stored value is theirs.
				return new WP_Error('baratables_definition_conflict', __('The plugin definition changed during this operation.', 'baratables'));
			}
			// The row still holds the value this caller read, so WordPress refused the
			// write. That is a genuine write failure, not a conflict.
			$error = new WP_Error('baratables_definition_not_saved', __('WordPress did not save the plugin definition.', 'baratables'));
			self::restore_meta_snapshot_if_owned($post_id, $meta_key, $before_definition, true, self::owned_value($post_id, $meta_key, $expected_definition));
			return self::with_rollback_result($error, $post_id, $meta_key, $before_definition);
		} catch (Throwable $throwable) {
			// The write raised instead of failing: compensate the same way the error
			// path does and report it, so a half-written entity is never left behind.
			$data = self::throwable_data($throwable);
			$rollback_throwable = self::compensate_safely($post_id, $meta_key, $before_definition, true, self::owned_value($post_id, $meta_key, $expected_definition));
			$slug_throwable = self::compensate_safely($post_id, $meta_slug, $before_slug, false, self::owned_value($post_id, $meta_slug, $slug));
			if ($rollback_throwable) {
				$data['rollback_exception'] = get_class($rollback_throwable) . ': ' . $rollback_throwable->getMessage();
			}
			if ($slug_throwable) {
				$data['slug_rollback_exception'] = get_class($slug_throwable) . ': ' . $slug_throwable->getMessage();
			}
			$error = new WP_Error('baratables_definition_write_failed', __('WordPress did not save the plugin definition.', 'baratables'), $data);
			$error = self::with_rollback_result($error, $post_id, $meta_key, $before_definition);
			return self::with_rollback_result($error, $post_id, $meta_slug, $before_slug);
		} finally {
			BaraTables_Persistence_Guard::end();
		}
	}

	/** Write a definition and lookup ID as one compensated persistence unit. */
	public static function persist(int $post_id, string $meta_key, string $meta_slug, array $definition, string $slug): ?WP_Error {
		$before_definition = self::snapshot_meta($post_id, $meta_key);
		$before_slug = self::snapshot_meta($post_id, $meta_slug);
		$error = null;
		// The stored value is the sanitized one; see persist_definition().
		$expected_definition = self::sanitize_definition($definition);

		BaraTables_Persistence_Guard::begin();
		try {
			self::write_definition($post_id, $meta_key, $definition);
			$stored_definition = get_post_meta($post_id, $meta_key, true);
			if (!is_array($stored_definition) || $stored_definition !== $expected_definition) {
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

			self::restore_meta_snapshot_if_owned($post_id, $meta_key, $before_definition, true, self::owned_value($post_id, $meta_key, $expected_definition));
			self::restore_meta_snapshot_if_owned($post_id, $meta_slug, $before_slug, false, self::owned_value($post_id, $meta_slug, $slug));
			$error = self::with_rollback_result($error, $post_id, $meta_key, $before_definition);
			return self::with_rollback_result($error, $post_id, $meta_slug, $before_slug);
		} catch (Throwable $throwable) {
			// Something raised while the entity was being written -- a third-party
			// save_post hook raising an Error, for example. The exception used to escape
			// from here: the finally block ended the guard, the rollback never ran, and
			// the table was left half-written with the administrator's content lost.
			// The same compensation the error path performs runs now, and the caller
			// gets a WP_Error so its "could not be saved" notice path runs.
			//
			// A true PHP fatal (out of memory, max_execution_time) cannot be caught and
			// is not covered by this.
			$data = self::throwable_data($throwable);
			$rollback_throwable = self::compensate_safely($post_id, $meta_key, $before_definition, true, self::owned_value($post_id, $meta_key, $expected_definition));
			$slug_throwable = self::compensate_safely($post_id, $meta_slug, $before_slug, false, self::owned_value($post_id, $meta_slug, $slug));
			if ($rollback_throwable) {
				$data['rollback_exception'] = get_class($rollback_throwable) . ': ' . $rollback_throwable->getMessage();
			}
			if ($slug_throwable) {
				$data['slug_rollback_exception'] = get_class($slug_throwable) . ': ' . $slug_throwable->getMessage();
			}
			$error = new WP_Error('baratables_definition_write_failed', __('WordPress did not save the plugin definition.', 'baratables'), $data);
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

	/**
	 * Write a definition only while the row still holds the value the caller read.
	 *
	 * update_post_meta()'s fourth argument compiles into a WHERE meta_value = <serialised
	 * expected value> clause, so the write lands only while the row is unchanged. The
	 * expected value has to be the raw stored value -- exactly what get_post_meta()
	 * returned -- because that is what WordPress serialized into the column.
	 *
	 * An empty expected value cannot be expressed that way: WordPress drops the
	 * meta_value condition for an empty prev_value, and the write would land
	 * unconditionally. That case is guarded separately.
	 */
	private static function compare_and_set_definition(int $post_id, string $meta_key, $expected, array $definition): void {
		if (is_array($expected) && !empty($expected)) {
			update_post_meta($post_id, $meta_key, wp_slash($definition), $expected);
			return;
		}
		if (metadata_exists('post', $post_id, $meta_key)) {
			// A definition exists now. It may only be overwritten while it still holds
			// the exact value that was read.
			$current = get_post_meta($post_id, $meta_key, true);
			if ($current !== $expected) {
				return;
			}
			// An empty definition cannot be expressed as a WHERE clause, so this write
			// is not atomic. It is still refused when the row changed in the meantime.
			update_post_meta($post_id, $meta_key, wp_slash($definition));
			return;
		}
		// No definition is stored: create one, but only while no other writer created
		// one in the meantime. The unique flag makes the insert fail in that case.
		add_post_meta($post_id, $meta_key, wp_slash($definition), true);
	}

	/**
	 * The value this write still owns on a metadata row, or null when it owns none.
	 *
	 * Ownership is decided by what the row holds now, not by whether the write call
	 * returned: a write that landed before a throwable still owns its value, and a
	 * value another writer stored afterwards is not ours to undo.
	 *
	 * @param mixed $expected The value this write stored.
	 * @return mixed The owned value, or null.
	 */
	private static function owned_value(int $post_id, string $meta_key, $expected) {
		try {
			$current = get_post_meta($post_id, $meta_key, true);
		} catch (Throwable $throwable) {
			return null;
		}
		return $current === $expected ? $expected : null;
	}

	/**
	 * Restore a snapshot only while this write still owns the row.
	 *
	 * A rollback must never overwrite a value another writer stored after this
	 * write: that value is newer than the snapshot, and restoring the snapshot would
	 * silently discard the other writer's committed work. The restore is therefore a
	 * compare-and-set against the value THIS write produced, and it is skipped when
	 * the row no longer holds that value. A key this write never stored is left
	 * exactly as it is.
	 *
	 * @param mixed $owned_value The value this write stored, or null when it stored none.
	 */
	private static function restore_meta_snapshot_if_owned(int $post_id, string $meta_key, array $snapshot, bool $definition, $owned_value): bool {
		if ($owned_value === null || $owned_value === '' || $owned_value === []) {
			// This write stored nothing on this key, so there is nothing of ours to
			// undo, and the row may already hold another writer's value.
			return false;
		}
		$current = get_post_meta($post_id, $meta_key, true);
		if ($current !== $owned_value) {
			// Another writer stored something else after this write. Their value wins.
			return false;
		}
		if (!empty($snapshot['exists'])) {
			$value = $snapshot['value'] ?? '';
			// The fourth argument compiles into a WHERE meta_value = <serialised owned
			// value> clause, so the restore lands only while the row still holds our
			// value.
			$written = (bool) update_post_meta($post_id, $meta_key, $definition && is_array($value) ? wp_slash($value) : $value, $owned_value);
		} else {
			// delete_post_meta()'s third argument is the value that has to match, so
			// the row is removed only while it still holds our value.
			$written = (bool) delete_post_meta($post_id, $meta_key, $owned_value);
		}
		return $written && self::meta_matches_snapshot($post_id, $meta_key, $snapshot);
	}

	/**
	 * Compensate a failed write without letting the compensation itself throw.
	 *
	 * @return Throwable|null The throwable the compensation raised, if any.
	 */
	private static function compensate_safely(int $post_id, string $meta_key, array $snapshot, bool $definition, $owned_value): ?Throwable {
		try {
			self::restore_meta_snapshot_if_owned($post_id, $meta_key, $snapshot, $definition, $owned_value);
		} catch (Throwable $throwable) {
			return $throwable;
		}
		return null;
	}

	/** @return array<string,string> Diagnostic data for a caught throwable. */
	private static function throwable_data(Throwable $throwable): array {
		return [
			'exception' => get_class($throwable) . ': ' . $throwable->getMessage(),
		];
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
			// Keep the diagnostic data the error already carries; only the rollback
			// failures are added to it.
			$data = is_array($data) ? $data : [];
			$failures = isset($data['rollback_failures']) && is_array($data['rollback_failures'])
				? $data['rollback_failures']
				: [];
			$failures[] = ['post_id' => $post_id, 'plugin_field' => $meta_key];
			$data['rollback_failures'] = $failures;
			$error->add_data($data);
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

	/**
	 * @deprecated 2.1.1 Call find_post_id_common() with the publish-only predicate.
	 */
	protected function get_post_id_by_slug_common(string $cpt, string $meta_slug, string $slug): int {
		return $this->find_post_id_common($cpt, $meta_slug, $slug, true);
	}

	public function meta_auth_callback(): bool {
		return current_user_can('manage_options');
	}

	protected static function sanitize_array_meta_value($value): array {
		if (is_array($value)) {
			return self::sanitize_definition($value);
		}
		if (is_string($value)) {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				return self::sanitize_definition($decoded);
			}
		}
		return [];
	}

	/**
	 * The definition as WordPress will actually store it.
	 *
	 * update_post_meta() runs the registered sanitize callback over the value it
	 * is handed, so the definition that comes back out of the database is not the
	 * array the caller passed in: the callback rewrites every string it does not
	 * accept. A write verification that compares the stored value with the
	 * caller's array therefore reads a sanitizer's rewrite as a failed write and
	 * rolls the whole save back. That is how a table whose name contains "&",
	 * or whose stylesheet contains a "<", could not be saved at all.
	 *
	 * @param array $definition The definition as the caller built it.
	 * @return array The definition as WordPress will store it.
	 */
	public static function sanitize_definition(array $definition): array {
		$clean = [];
		foreach ($definition as $key => $item) {
			$clean_key = is_int($key) ? $key : sanitize_text_field((string) $key);
			if ($clean_key === '') {
				continue;
			}
			$clean[$clean_key] = self::sanitize_meta_value($item, (string) $clean_key);
		}
		return $clean;
	}

	private static function sanitize_meta_value($value, string $key) {
		if (is_array($value)) {
			return self::sanitize_definition($value);
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

		$clean = wp_kses($clean, self::get_meta_allowed_html());
		if (isset(self::DECODED_META_STRING_KEYS[$key])) {
			// The markup filter has already removed what it does not allow, so
			// restoring the text it rewrote cannot put a dangerous tag back: the
			// filter encodes every "&" before it looks at anything else, so decoding
			// gives back exactly the characters that were submitted.
			$clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
		}
		return $clean;
	}

	private static function get_meta_allowed_html(): array {
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
		return self::sanitize_array_meta_value($value);
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
			'add_new_item'       => __('Add table', 'baratables'),
			'new_item'           => __('New table', 'baratables'),
			'edit_item'          => __('Edit table', 'baratables'),
			'view_item'          => __('View table', 'baratables'),
			'all_items'          => __('Tables', 'baratables'),
			'search_items'       => __('Search tables', 'baratables'),
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
			'add_new_item'       => __('Add chart', 'baratables'),
			'new_item'           => __('New chart', 'baratables'),
			'edit_item'          => __('Edit chart', 'baratables'),
			'view_item'          => __('View chart', 'baratables'),
			'all_items'          => __('Charts', 'baratables'),
			'search_items'       => __('Search charts', 'baratables'),
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
