<?php
/**
 * Plugin Name: BaraTables
 * Description: Build searchable, sortable tables and charts from CSV files, manual data, WordPress content, or external databases.
 * Version: 1.0.1
 * Author: Nathan Noom
 * Author URI: https://profiles.wordpress.org/nathannoom/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: baratables
 * Domain Path: /languages
 * Tested up to: 7.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-baratables.php';

function baratables_bootstrap(): BaraTables {
	static $instance = null;
	if ($instance === null) {
		$instance = new BaraTables(__FILE__);
	}
	return $instance;
}

baratables_bootstrap();
