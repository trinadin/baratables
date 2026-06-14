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
