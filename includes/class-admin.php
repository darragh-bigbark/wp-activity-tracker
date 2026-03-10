<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WAT_Admin {

    public static function init() {
        add_action( 'admin_menu',            array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
        add_action( 'admin_init',            array( __CLASS__, 'handle_export' ) );
    }

    public static function register_menu() {
        add_menu_page(
            __( 'Activity Log', 'site-activity-tracker' ),
            __( 'Activity Log', 'site-activity-tracker' ),
            'manage_options',
            'wat-activity-log',
            array( __CLASS__, 'render_page' ),
            'dashicons-list-view',
            80
        );
    }

    public static function enqueue_styles( $hook ) {
        if ( 'toplevel_page_wat-activity-log' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'wat-admin',
            WAT_PLUGIN_URL . 'assets/admin.css',
            array(),
            WAT_VERSION
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // If the table is missing, create it now and show an admin notice.
        // This is a visible failsafe for cases where the activation hook was skipped.
        if ( ! WAT_DB::table_exists() ) {
            WAT_DB::create_table();
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'Site Activity Tracker:', 'site-activity-tracker' ) . '</strong> ';
            esc_html_e( 'The activity log table was not found and has been re-created. New events will now be recorded. If this message keeps appearing, deactivate and reactivate the plugin.', 'site-activity-tracker' );
            echo '</p></div>';
        }

        // Only trust filter values when the nonce is present and valid.
        $nonce_valid = isset( $_GET['_wat_nonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wat_nonce'] ) ), 'wat_log_filter' );

        $event_filter = ( $nonce_valid && isset( $_GET['wat_event'] ) )
            ? sanitize_text_field( wp_unslash( $_GET['wat_event'] ) )
            : '';

        $search = ( $nonce_valid && isset( $_GET['wat_search'] ) )
            ? sanitize_text_field( wp_unslash( $_GET['wat_search'] ) )
            : '';

        $current_page = ( $nonce_valid && isset( $_GET['paged'] ) )
            ? max( 1, absint( $_GET['paged'] ) )
            : 1;

        $per_page = 25;

        $result      = WAT_DB::get_logs( array(
            'event_type' => $event_filter,
            'search'     => $search,
            'per_page'   => $per_page,
            'page'       => $current_page,
        ) );
        $rows        = $result['rows'];
        $total       = $result['total'];
        $total_pages = (int) ceil( $total / $per_page );
        $event_types = WAT_DB::get_event_types();

        $base_url = admin_url( 'admin.php?page=wat-activity-log' );
        ?>
        <div class="wrap wat-wrap">
            <h1><?php esc_html_e( 'Activity Log', 'site-activity-tracker' ); ?></h1>

            <form method="get" class="wat-filter-form">
                <input type="hidden" name="page" value="wat-activity-log">
                <?php wp_nonce_field( 'wat_log_filter', '_wat_nonce' ); ?>

                <select name="wat_event">
                    <option value=""><?php esc_html_e( '— All Events —', 'site-activity-tracker' ); ?></option>
                    <?php foreach ( $event_types as $et ) : ?>
                        <option value="<?php echo esc_attr( $et ); ?>" <?php selected( $event_filter, $et ); ?>>
                            <?php echo esc_html( str_replace( '_', ' ', $et ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="wat_search" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search username, IP, object…', 'site-activity-tracker' ); ?>">

                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'site-activity-tracker' ); ?></button>
                <?php if ( $event_filter || $search ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">
                        <?php esc_html_e( 'Reset', 'site-activity-tracker' ); ?>
                    </a>
                <?php endif; ?>
            </form>

            <?php
            $export_url = add_query_arg( array_filter( array(
                'page'               => 'wat-activity-log',
                'wat_action'         => 'export_csv',
                '_wat_export_nonce'  => wp_create_nonce( 'wat_export_csv' ),
                'wat_event'          => $event_filter,
                'wat_search'         => $search,
            ) ), admin_url( 'admin.php' ) );
            ?>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button wat-export-btn">
                <?php esc_html_e( 'Download CSV', 'site-activity-tracker' ); ?>
            </a>

            <p class="wat-total">
                <?php
                $count_html = '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>';
                $message = sprintf(
                    /* translators: %s: Number of activity log events found, wrapped in <strong> tags. */
                    _n( '%s event found.', '%s events found.', $total, 'site-activity-tracker' ),
                    $count_html
                );
                echo wp_kses( $message, array( 'strong' => array() ) );
                ?>
            </p>

            <table class="widefat fixed striped wat-table">
                <thead>
                    <tr>
                        <th style="width:50px"><?php esc_html_e( 'ID', 'site-activity-tracker' ); ?></th>
                        <th style="width:150px"><?php esc_html_e( 'Event', 'site-activity-tracker' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'User', 'site-activity-tracker' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'IP Address', 'site-activity-tracker' ); ?></th>
                        <th><?php esc_html_e( 'Browser / User Agent', 'site-activity-tracker' ); ?></th>
                        <th style="width:160px"><?php esc_html_e( 'Object', 'site-activity-tracker' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'site-activity-tracker' ); ?></th>
                        <th style="width:150px"><?php esc_html_e( 'Date / Time', 'site-activity-tracker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e( 'No activity recorded yet.', 'site-activity-tracker' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row->id ); ?></td>
                                <td>
                                    <span class="wat-badge wat-badge--<?php echo esc_attr( self::badge_class( $row->event_type ) ); ?>">
                                        <?php echo esc_html( str_replace( '_', ' ', $row->event_type ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $row->username ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row->ip_address ?: '—' ); ?></td>
                                <td class="wat-ua" title="<?php echo esc_attr( $row->user_agent ); ?>">
                                    <?php echo esc_html( self::short_ua( $row->user_agent ) ); ?>
                                </td>
                                <td><?php echo esc_html( $row->object_name ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row->description ?: '—' ); ?></td>
                                <td><?php echo esc_html( self::format_date( $row->created_at ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="wat-pagination">
                    <?php
                    echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns safe HTML.
                        'base'     => add_query_arg( 'paged', '%#%', $base_url ),
                        'format'   => '',
                        'current'  => $current_page,
                        'total'    => $total_pages,
                        'add_args' => array_filter( array(
                            'wat_event'  => $event_filter,
                            'wat_search' => $search,
                        ) ),
                    ) );
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // CSV Export
    // -------------------------------------------------------------------------

    public static function handle_export() {
        if ( ! isset( $_GET['page'] ) || 'wat-activity-log' !== $_GET['page'] ) {
            return;
        }
        if ( ! isset( $_GET['wat_action'] ) || 'export_csv' !== $_GET['wat_action'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export the activity log.', 'site-activity-tracker' ) );
        }
        if ( ! isset( $_GET['_wat_export_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wat_export_nonce'] ) ), 'wat_export_csv' ) ) {
            wp_die( esc_html__( 'Invalid request. Please try again.', 'site-activity-tracker' ) );
        }

        $event_filter = isset( $_GET['wat_event'] )  ? sanitize_text_field( wp_unslash( $_GET['wat_event'] ) )  : '';
        $search       = isset( $_GET['wat_search'] ) ? sanitize_text_field( wp_unslash( $_GET['wat_search'] ) ) : '';

        $rows     = WAT_DB::get_all_logs( array(
            'event_type' => $event_filter,
            'search'     => $search,
        ) );
        $filename = 'activity-log-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is not a real file; no WP filesystem API equivalent.
        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM so Excel opens the file correctly.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to php://output stream, not a filesystem path.
        fwrite( $output, "\xEF\xBB\xBF" );

        fputcsv( $output, array( 'ID', 'Event', 'User', 'IP Address', 'User Agent', 'Object', 'Description', 'Date / Time' ) );

        foreach ( $rows as $row ) {
            fputcsv( $output, array(
                $row->id,
                $row->event_type,
                $row->username    ?: '',
                $row->ip_address  ?: '',
                $row->user_agent  ?: '',
                $row->object_name ?: '',
                $row->description ?: '',
                self::format_date( $row->created_at ),
            ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing php://output stream.
        fclose( $output );
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function format_date( $mysql_date ) {
        $ts = strtotime( $mysql_date );
        return $ts ? date_i18n( 'Y-m-d H:i:s', $ts ) : $mysql_date;
    }

    /**
     * Return a condensed UA string (first 80 chars).
     */
    private static function short_ua( $ua ) {
        if ( ! $ua ) {
            return '—';
        }
        return strlen( $ua ) > 80 ? substr( $ua, 0, 80 ) . '…' : $ua;
    }

    /**
     * Map event type to a CSS modifier class for the badge.
     */
    private static function badge_class( $event_type ) {
        $map = array(
            'login_success'  => 'success',
            'login_failed'   => 'danger',
            'logout'         => 'neutral',
            'post_created'   => 'success',
            'post_updated'   => 'info',
            'post_deleted'   => 'danger',
            'plugin_activated'    => 'success',
            'plugin_deactivated'  => 'warning',
            'plugin_deleted'      => 'danger',
            'plugin_updated'      => 'info',
            'plugin_installed'    => 'success',
            'theme_switched'      => 'info',
            'theme_updated'       => 'info',
            'theme_installed'     => 'success',
            'user_created'        => 'success',
            'user_updated'        => 'info',
            'user_deleted'        => 'danger',
        );
        return isset( $map[ $event_type ] ) ? $map[ $event_type ] : 'neutral';
    }
}
