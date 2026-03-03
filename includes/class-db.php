<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WAT_DB {

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
     * Insert an activity log entry.
     *
     * @param array $data {
     *     @type string      $event_type   Required. e.g. 'login', 'post_updated'.
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

        $result = $wpdb->insert( $table, $row );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Fetch paginated log entries with optional filters.
     *
     * @param array $args {
     *     @type string $event_type  Filter by event type.
     *     @type string $search      Search username or object name.
     *     @type int    $per_page    Rows per page (default 25).
     *     @type int    $page        Current page (default 1).
     * }
     * @return array { rows, total }
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

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['event_type'] ) ) {
            $where[]  = 'event_type = %s';
            $values[] = sanitize_text_field( $args['event_type'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]  = '( username LIKE %s OR object_name LIKE %s OR ip_address LIKE %s )';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );

        // Total count.
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $values );
        } else {
            $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Rows.
        $limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['per_page'], $offset );

        if ( ! empty( $values ) ) {
            $rows_sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC {$limit_sql}",
                $values
            );
        } else {
            $rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC {$limit_sql}";
        }

        $rows = $wpdb->get_results( $rows_sql );

        return array(
            'rows'  => $rows,
            'total' => $total,
        );
    }

    /**
     * Return distinct event types present in the log table.
     */
    public static function get_event_types() {
        global $wpdb;
        $table = $wpdb->prefix . WAT_TABLE_NAME;
        return $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type ASC" );
    }
}
