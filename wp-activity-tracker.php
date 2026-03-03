<?php
/**
 * Plugin Name:       Site Activity Tracker
 * Plugin URI:        https://github.com/darragh-bigbark/wp-activity-tracker
 * Description:       Tracks logins (username, IP, browser) and changes to posts, pages, plugins, and themes with timestamps.
 * Version:           1.0.1
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       site-activity-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAT_VERSION',     '1.0.1' );
define( 'WAT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WAT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WAT_TABLE_NAME',  'wat_activity_log' );

require_once WAT_PLUGIN_DIR . 'includes/class-db.php';
require_once WAT_PLUGIN_DIR . 'includes/class-logger.php';
require_once WAT_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'WAT_DB', 'create_table' ) );

// Ensure the DB table exists on every load. Handles the case where the
// activation hook was missed (e.g. after a directory rename or manual upload).
add_action( 'plugins_loaded', function () {
    if ( get_option( 'wat_db_version' ) !== WAT_VERSION ) {
        WAT_DB::create_table();
    }
} );

add_action( 'plugins_loaded', array( 'WAT_Logger', 'init' ) );
add_action( 'plugins_loaded', array( 'WAT_Admin', 'init' ) );
