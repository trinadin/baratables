<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$posts = get_posts([
	'post_type'   => ['btbl_table', 'btbl_chart'],
	'numberposts' => -1,
	'post_status' => 'any',
	'fields'      => 'ids',
]);

foreach ($posts as $post_id) {
	wp_delete_post($post_id, true);
}

// Remove plugin-owned persistent state so nothing is orphaned after deletion.
// This file loads no plugin classes, so the literal key strings are used:
//   - 'btbl_auto_label_migrated' option  (legacy gate left by the 1.1.0 auto-label backfill,
//                                          removed in a later version; cleaned here for old sites)
//   - 'btbl_hide_help' user meta         (support.php BaraTables_Help::META_KEY, all users)
// Per-user 'btbl_admin_notice_*' transients are not cleaned: they self-expire in 60s.
delete_option('btbl_auto_label_migrated');
delete_metadata('user', 0, 'btbl_hide_help', '', true);
