<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Wrapped in a closure so this file declares no global-scope variables at all. uninstall.php runs
// in global scope, where a bare $posts/$post_id would clobber the WordPress globals of those names
// -- and Plugin Check flags every global here as unprefixed regardless of the name chosen, since it
// derives the expected prefix from the plugin slug rather than the plugin's own 'btbl_' prefix.
(static function () {
	// All registered statuses (not 'any', which excludes trash and auto-draft) so trashed tables
	// and the auto-drafts left by opening "Add Table" without saving are cleaned up too -- otherwise
	// their rows (including any external_db connection meta) survive uninstall as orphans.
	$post_ids = get_posts([
		'post_type'   => ['btbl_table', 'btbl_chart'],
		'numberposts' => -1,
		'post_status' => array_values(get_post_stati()),
		'fields'      => 'ids',
	]);

	foreach ($post_ids as $post_id) {
		wp_delete_post($post_id, true);
	}

	// Remove plugin-owned persistent state so nothing is orphaned after deletion.
	// This file loads no plugin classes, so the literal key strings are used:
	//   - 'btbl_auto_label_migrated' option  (legacy gate left by the 1.1.0 auto-label backfill,
	//                                          removed in a later version; cleaned here for old sites)
	//   - 'btbl_hide_help' user meta         (support.php BaraTables_Help::META_KEY, all users)
	// Per-user transients are not cleaned, because both self-expire well before anyone would
	// notice them: 'btbl_admin_notice_*' after 60s, and 'btbl_import_handoff_*' (the analyzed
	// import payload handed to the Create step) after 15 minutes.
	delete_option('btbl_auto_label_migrated');
	delete_metadata('user', 0, 'btbl_hide_help', '', true);
})();
