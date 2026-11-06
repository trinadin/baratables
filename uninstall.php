<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Wrapped in a closure so this file declares no global-scope variables at all. uninstall.php runs
// in global scope, where a bare $posts/$post_id would clobber the WordPress globals of those names
// -- and Plugin Check flags every global here as unprefixed regardless of the name chosen, since it
// derives the expected prefix from the plugin slug rather than the plugin's own 'btbl_' prefix.
(static function () {
	$cleanup_site = static function (): void {
		// All registered statuses (not 'any', which excludes trash and auto-draft) so trashed
		// records and unfinished auto-drafts are removed along with their definition metadata.
		$post_ids = get_posts([
			'post_type'   => ['btbl_table', 'btbl_chart'],
			'numberposts' => -1,
			'post_status' => array_values(get_post_stati()),
			'fields'      => 'ids',
		]);

		foreach ($post_ids as $post_id) {
			wp_delete_post($post_id, true);
		}

		delete_option('btbl_auto_label_migrated');
		delete_option('btbl_label_i18n_migrated');
		delete_option('btbl_data_schema_version');
		delete_option('btbl_chart_link_recovery');
		// The site-wide default design (2.1.0). It is a site-level design, not a table's
		// own, so it survives the table posts this function deletes. Left behind, a
		// reinstalled plugin would silently re-apply a design from an installation that
		// no longer exists -- on every subsite of a network, too, since $cleanup_site()
		// runs once per blog.
		delete_option('btbl_default_design');
		$rate_value_like = $GLOBALS['wpdb']->esc_like('_transient_btbl_ss_rate_') . '%';
		$rate_timeout_like = $GLOBALS['wpdb']->esc_like('_transient_timeout_btbl_ss_rate_') . '%';
		$rate_legacy_like = $GLOBALS['wpdb']->esc_like('btbl_ss_rate_') . '%';
		$schema_value_like = $GLOBALS['wpdb']->esc_like('_transient_btbl_ss_schema_') . '%';
		$schema_timeout_like = $GLOBALS['wpdb']->esc_like('_transient_timeout_btbl_ss_schema_') . '%';
		$rate_cleanup_sql = $GLOBALS['wpdb']->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$GLOBALS['wpdb']->options,
			$rate_value_like,
			$rate_timeout_like,
			$rate_legacy_like,
			$schema_value_like,
			$schema_timeout_like
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared cleanup of plugin-owned transient prefixes during uninstall.
		$GLOBALS['wpdb']->query($rate_cleanup_sql);

		// The plugin caches parsed CSV rows and discovered meta keys under its own cache
		// group. A persistent object cache (Redis, Memcached) keeps them past the delete, and
		// a reinstall can then be served a stale parse. Flushing the group is scoped to the
		// plugin, so nothing else in the site cache is touched.
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group('baratables');
		}
	};

	if (is_multisite()) {
		// WordPress includes uninstall.php only once for a network deletion. Visit every site's
		// tables explicitly so no subsite definitions or encrypted connection settings survive.
		foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
			switch_to_blog((int) $site_id);
			try {
				$cleanup_site();
			} finally {
				restore_current_blog();
			}
		}
	} else {
		$cleanup_site();
	}

	// Remove plugin-owned persistent state so nothing is orphaned after deletion.
	// This file loads no plugin classes, so the literal key strings are used:
	//   - 'btbl_auto_label_migrated' option  (legacy gate left by the 1.1.0 auto-label backfill,
	//                                          removed in a later version; cleaned here for old sites)
	//   - 'btbl_label_i18n_migrated' option  (gate for the 1.2.3 English-label backfill,
	//                                          BaraTables_Service::LABEL_I18N_MIGRATION_OPTION)
	//   - 'btbl_data_schema_version' option  (current consolidated data-schema gate)
	//   - 'btbl_chart_link_recovery' option (retry journal for an interrupted linked-chart rollback)
	//   - 'btbl_default_design' option     (the site-wide default design, BaraTables_Design::OPTION_KEY)
	//   - '_transient_[timeout_]btbl_ss_rate_*' options (bounded public table-read buckets)
	//   - '_transient_[timeout_]btbl_ss_schema_*' options (cached stable-key and order-shape probes)
	//   - 'btbl_hide_help' user meta         (support.php BaraTables_Help::META_KEY, all users)
	// Per-user transients are not cleaned, because both self-expire well before anyone would
	// notice them: 'btbl_admin_notice_*' after 60s, and 'btbl_import_handoff_*' (the analyzed
	// import payload handed to the Create step) after 15 minutes.
	delete_metadata('user', 0, 'btbl_hide_help', '', true);
})();
