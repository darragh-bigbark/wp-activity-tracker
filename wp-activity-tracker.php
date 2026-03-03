<?php
/**
 * Plugin Name:       Site Activity Tracker
 * Plugin URI:        https://github.com/darragh-bigbark/wp-activity-tracker
 * Description:       Tracks logins (username, IP, browser) and changes to posts, pages, plugins, and themes with timestamps.
 * Version:           1.0.0
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       site-activity-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAT_VERSION',     '1.0.0' );
define( 'WAT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WAT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WAT_TABLE_NAME',  'wat_activity_log' );

require_once WAT_PLUGIN_DIR . 'includes/class-db.php';
require_once WAT_PLUGIN_DIR . 'includes/class-logger.php';
require_once WAT_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'WAT_DB', 'create_table' ) );

WAT_Logger::init();
WAT_Admin::init();
