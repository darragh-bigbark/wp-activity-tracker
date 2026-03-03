<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WAT_Admin {

    public static function init() {
        add_action( 'admin_menu',    array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
    }

    public static function register_menu() {
        add_menu_page(
            __( 'Activity Log', 'wp-activity-tracker' ),
            __( 'Activity Log', 'wp-activity-tracker' ),
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

        // Read filter inputs.
        $event_filter = isset( $_GET['wat_event'] )  ? sanitize_text_field( wp_unslash( $_GET['wat_event'] ) )  : '';
        $search       = isset( $_GET['wat_search'] ) ? sanitize_text_field( wp_unslash( $_GET['wat_search'] ) ) : '';
        $per_page     = 25;
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

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
            <h1><?php esc_html_e( 'Activity Log', 'wp-activity-tracker' ); ?></h1>

            <form method="get" class="wat-filter-form">
                <input type="hidden" name="page" value="wat-activity-log">

                <select name="wat_event">
                    <option value=""><?php esc_html_e( '— All Events —', 'wp-activity-tracker' ); ?></option>
                    <?php foreach ( $event_types as $et ) : ?>
                        <option value="<?php echo esc_attr( $et ); ?>" <?php selected( $event_filter, $et ); ?>>
                            <?php echo esc_html( str_replace( '_', ' ', $et ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="wat_search" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search username, IP, object…', 'wp-activity-tracker' ); ?>">

                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'wp-activity-tracker' ); ?></button>
                <?php if ( $event_filter || $search ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">
                        <?php esc_html_e( 'Reset', 'wp-activity-tracker' ); ?>
                    </a>
                <?php endif; ?>
            </form>

            <p class="wat-total">
                <?php printf(
                    esc_html( _n( '%s event found.', '%s events found.', $total, 'wp-activity-tracker' ) ),
                    '<strong>' . number_format_i18n( $total ) . '</strong>'
                ); ?>
            </p>

            <table class="widefat fixed striped wat-table">
                <thead>
                    <tr>
                        <th style="width:50px"><?php esc_html_e( 'ID', 'wp-activity-tracker' ); ?></th>
                        <th style="width:150px"><?php esc_html_e( 'Event', 'wp-activity-tracker' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'User', 'wp-activity-tracker' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'IP Address', 'wp-activity-tracker' ); ?></th>
                        <th><?php esc_html_e( 'Browser / User Agent', 'wp-activity-tracker' ); ?></th>
                        <th style="width:160px"><?php esc_html_e( 'Object', 'wp-activity-tracker' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'wp-activity-tracker' ); ?></th>
                        <th style="width:150px"><?php esc_html_e( 'Date / Time', 'wp-activity-tracker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e( 'No activity recorded yet.', 'wp-activity-tracker' ); ?></td>
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
                    $pagination_args = array(
                        'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'add_args'  => array_filter( array(
                            'wat_event'  => $event_filter,
                            'wat_search' => $search,
                        ) ),
                    );
                    echo paginate_links( $pagination_args ); // phpcs:ignore WordPress.Security.EscapeOutput
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function format_date( $mysql_date ) {
        $ts = strtotime( $mysql_date );
        return $ts ? date_i18n( 'Y-m-d H:i:s', $ts ) : $mysql_date;
    }

    /**
     * Return a condensed browser / UA string (first 80 chars).
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
            'login_success'    => 'success',
            'login_failed'     => 'danger',
            'logout'           => 'neutral',
            'post_updated'     => 'info',
            'post_status_changed' => 'info',
            'post_deleted'     => 'danger',
            'plugin_activated' => 'success',
            'plugin_deactivated' => 'warning',
            'plugin_deleted'   => 'danger',
            'plugin_updated'   => 'info',
            'plugin_installed' => 'success',
            'theme_switched'   => 'info',
            'theme_updated'    => 'info',
            'theme_installed'  => 'success',
        );
        return isset( $map[ $event_type ] ) ? $map[ $event_type ] : 'neutral';
    }
}
