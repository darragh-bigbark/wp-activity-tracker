<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WAT_DB {

    /**
     * Check whether the activity log table exists in the database.
     *
     * @return bool
     */
    public static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . WAT_TABLE_NAME;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- SHOW TABLES has no WP API equivalent.
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /**
     * Create the activity log table on plugin activation.
     */
    public static function create_table() {
        global $wpdb;

        $table   = $wpdb->prefix . WAT_TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type  VARCHAR(50)  NOT NULL,
            user_id     BIGINT(20) UNSIGNED DEFAULT NULL,
            username    VARCHAR(60)  DEFAULT NULL,
            ip_address  VARCHAR(45)  DEFAULT NULL,
            user_agent  TEXT         DEFAULT NULL,
            object_id   BIGINT(20) UNSIGNED DEFAULT NULL,
            object_name VARCHAR(255) DEFAULT NULL,
            description TEXT         DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'wat_db_version', WAT_VERSION );
    }

    /**
     * Insert an activity log entry and bust the log cache.
     *
     * @param array $data {
     *     @type string      $event_type   Required.
     *     @type int|null    $user_id
     *     @type string|null $username
     *     @type string|null $ip_address
     *     @type string|null $user_agent
     *     @type int|null    $object_id
     *     @type string|null $object_name
     *     @type string|null $description
     * }
     * @return int|false Inserted row ID or false on failure.
     */
    public static function insert( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . WAT_TABLE_NAME;

        $row = wp_parse_args( $data, array(
            'event_type'  => '',
            'user_id'     => null,
            'username'    => null,
            'ip_address'  => null,
            'user_agent'  => null,
            'object_id'   => null,
            'object_name' => null,
            'description' => null,
            'created_at'  => current_time( 'mysql' ),
        ) );

        $result = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom activity log table; no WP API equivalent.

        if ( false === $result ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && $wpdb->last_error ) {
                error_log( 'Site Activity Tracker — DB insert failed: ' . $wpdb->last_error );
            }
            return false;
        }

        // Invalidate cached log results and event-type list so the
        // next page load reflects the newly inserted row.
        wp_cache_delete( 'wat_event_types', 'wat_activity_log' );
        wp_cache_delete( 'wat_log_version', 'wat_activity_log' );
        return $wpdb->insert_id;
    }

    /**
     * Fetch paginated log entries with optional filters.
     *
     * @param array $args {
     *     @type string $event_type  Filter by event type.
     *     @type string $search      Search username, object name, or IP.
     *     @type int    $per_page    Rows per page (default 25).
     *     @type int    $page        Current page (default 1).
     * }
     * @return array { rows: array, total: int }
     */
    public static function get_logs( array $args = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . WAT_TABLE_NAME;

        $args = wp_parse_args( $args, array(
            'event_type' => '',
            'search'     => '',
            'per_page'   => 25,
            'page'       => 1,
        ) );

        // Build a version-aware cache key so that a new insert busts all
        // page-level caches without having to enumerate them individually.
        $log_version = (int) wp_cache_get( 'wat_log_version', 'wat_activity_log' );
        $cache_key   = 'wat_logs_v' . $log_version . '_' . md5( maybe_serialize( $args ) );
        $cached      = wp_cache_get( $cache_key, 'wat_activity_log' );

        if ( false !== $cached ) {
            return $cached;
        }

        $per_page   = absint( $args['per_page'] );
        $offset     = ( absint( $args['page'] ) - 1 ) * $per_page;
        $event_type = sanitize_text_field( $args['event_type'] );
        $has_type   = ! empty( $event_type );
        $has_search = ! empty( $args['search'] );
        $like       = $has_search
            ? '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%'
            : '';

        // Each branch uses a static, fully-literal SQL string so that the
        // Plugin Check static analyser can verify placeholders and escaping.
        // $table is trusted: $wpdb->prefix (set by WP) + WAT_TABLE_NAME (constant).
        if ( ! $has_type && ! $has_search ) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + constant; no user input.
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above.
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );

        } elseif ( $has_type && ! $has_search ) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + constant; $event_type is sanitized.
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE event_type = %s", $event_type ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above.
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_type = %s ORDER BY created_at DESC LIMIT %d OFFSET %d", $event_type, $per_page, $offset ) );

        } elseif ( ! $has_type && $has_search ) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + constant; $like is produced by $wpdb->esc_like().
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE ( username LIKE %s OR object_name LIKE %s OR ip_address LIKE %s )", $like, $like, $like ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above.
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE ( username LIKE %s OR object_name LIKE %s OR ip_address LIKE %s ) ORDER BY created_at DESC LIMIT %d OFFSET %d", $like, $like, $like, $per_page, $offset ) );

        } else {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + constant; all values are sanitized/escaped.
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE event_type = %s AND ( username LIKE %s OR object_name LIKE %s OR ip_address LIKE %s )", $event_type, $like, $like, $like ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same as above.
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_type = %s AND ( username LIKE %s OR object_name LIKE %s OR ip_address LIKE %s ) ORDER BY created_at DESC LIMIT %d OFFSET %d", $event_type, $like, $like, $like, $per_page, $offset ) );

        }

        $result = array(
            'rows'  => $rows ? $rows : array(),
            'total' => $total,
        );

        wp_cache_set( $cache_key, $result, 'wat_activity_log', 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Return distinct event types present in the log table.
     *
     * @return string[]
     */
    public static function get_event_types() {
        global $wpdb;

        $cached = wp_cache_get( 'wat_event_types', 'wat_activity_log' );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = $wpdb->prefix . WAT_TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table ($table = $wpdb->prefix + constant). No user-supplied values in this query.
        $types = $wpdb->get_col( "SELECT DISTINCT event_type FROM `{$table}` ORDER BY event_type ASC" );

        wp_cache_set( 'wat_event_types', $types, 'wat_activity_log', 10 * MINUTE_IN_SECONDS );

        return $types;
    }
}
