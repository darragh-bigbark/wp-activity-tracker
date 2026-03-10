<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WAT_Logger {

    public static function init() {
        // --- Login events ---
        add_action( 'wp_login',        array( __CLASS__, 'on_login' ), 10, 2 );
        add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ), 10, 1 );
        add_action( 'wp_logout',       array( __CLASS__, 'on_logout' ) );

        // --- Post / page events ---
        // wp_after_insert_post fires for both create and update (WP 5.6+),
        // has an explicit $update flag, and works with the block editor REST API.
        add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_after_insert' ), 10, 4 );
        add_action( 'delete_post',          array( __CLASS__, 'on_post_deleted' ), 10, 1 );

        // --- Plugin events ---
        add_action( 'activated_plugin',   array( __CLASS__, 'on_plugin_activated' ),   10, 1 );
        add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ), 10, 1 );
        add_action( 'deleted_plugin',     array( __CLASS__, 'on_plugin_deleted' ),     10, 1 );

        // --- User events ---
        add_action( 'user_register',  array( __CLASS__, 'on_user_created' ),  10, 1 );
        add_action( 'delete_user',    array( __CLASS__, 'on_user_deleted' ),   10, 3 );
        add_action( 'profile_update', array( __CLASS__, 'on_user_updated' ),  10, 2 );

        // --- Theme events ---
        add_action( 'switch_theme',      array( __CLASS__, 'on_theme_switched' ), 10, 2 );

        // --- Plugin & theme upgrades ---
        add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function get_ip() {
        $keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                // Unslash and sanitize before use; validate as IP below.
                $raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // X-Forwarded-For can be a comma-separated list; take the first.
                $ip  = trim( explode( ',', $raw )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return 'unknown';
    }

    private static function get_user_agent() {
        return isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : 'unknown';
    }

    private static function current_user_info() {
        $user = wp_get_current_user();
        return array(
            'user_id'  => $user->ID ?: null,
            'username' => $user->user_login ?: null,
        );
    }

    private static function log( array $data ) {
        // Verify the table exists before every first write of a request.
        // This catches missed activation hooks (e.g. after a directory rename).
        static $table_verified = false;
        if ( ! $table_verified ) {
            if ( ! WAT_DB::table_exists() ) {
                WAT_DB::create_table();
            }
            $table_verified = true;
        }

        $data = array_merge( array(
            'ip_address' => self::get_ip(),
            'user_agent' => self::get_user_agent(),
        ), $data );
        WAT_DB::insert( $data );
    }

    // -------------------------------------------------------------------------
    // Login callbacks
    // -------------------------------------------------------------------------

    public static function on_login( $user_login, $user ) {
        self::log( array(
            'event_type'  => 'login_success',
            'user_id'     => $user->ID,
            'username'    => $user_login,
            'description' => 'User logged in successfully.',
        ) );
    }

    public static function on_login_failed( $username ) {
        self::log( array(
            'event_type'  => 'login_failed',
            'username'    => sanitize_user( $username ),
            'description' => 'Failed login attempt.',
        ) );
    }

    public static function on_logout() {
        $info = self::current_user_info();
        self::log( array(
            'event_type'  => 'logout',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'description' => 'User logged out.',
        ) );
    }

    // -------------------------------------------------------------------------
    // Post / page callbacks
    // -------------------------------------------------------------------------

    public static function on_post_after_insert( $post_id, $post, $update, $post_before ) {
        // Skip revisions and autosave shadow copies.
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        // Skip the silent auto-draft created when the editor is first opened —
        // the user hasn't intentionally saved anything yet.
        if ( 'auto-draft' === $post->post_status ) {
            return;
        }

        $info = self::current_user_info();
        $type = ucfirst( $post->post_type );

        // Treat as a creation when:
        //   a) it's a brand-new DB record ($update = false), or
        //   b) the previous status was 'auto-draft' (first real save of a new post).
        $prev_status    = $post_before ? $post_before->post_status : 'new';
        $is_new_content = ! $update || 'auto-draft' === $prev_status;

        if ( $is_new_content ) {
            self::log( array(
                'event_type'  => 'post_created',
                'user_id'     => $info['user_id'],
                'username'    => $info['username'],
                'object_id'   => $post_id,
                'object_name' => $post->post_title,
                'description' => "{$type} created: \"{$post->post_title}\" (ID {$post_id}) — status: {$post->post_status}.",
            ) );
        } else {
            self::log( array(
                'event_type'  => 'post_updated',
                'user_id'     => $info['user_id'],
                'username'    => $info['username'],
                'object_id'   => $post_id,
                'object_name' => $post->post_title,
                'description' => "{$type} updated: \"{$post->post_title}\" (ID {$post_id}) — status: {$prev_status} → {$post->post_status}.",
            ) );
        }
    }

    public static function on_post_deleted( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $info = self::current_user_info();
        $type = ucfirst( $post->post_type );

        self::log( array(
            'event_type'  => 'post_deleted',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_id'   => $post_id,
            'object_name' => $post->post_title,
            'description' => "{$type} deleted: \"{$post->post_title}\" (ID {$post_id}).",
        ) );
    }

    // -------------------------------------------------------------------------
    // Plugin callbacks
    // -------------------------------------------------------------------------

    public static function on_plugin_activated( $plugin ) {
        $info = self::current_user_info();
        self::log( array(
            'event_type'  => 'plugin_activated',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_name' => $plugin,
            'description' => "Plugin activated: {$plugin}.",
        ) );
    }

    public static function on_plugin_deactivated( $plugin ) {
        $info = self::current_user_info();
        self::log( array(
            'event_type'  => 'plugin_deactivated',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_name' => $plugin,
            'description' => "Plugin deactivated: {$plugin}.",
        ) );
    }

    public static function on_plugin_deleted( $plugin ) {
        $info = self::current_user_info();
        self::log( array(
            'event_type'  => 'plugin_deleted',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_name' => $plugin,
            'description' => "Plugin deleted: {$plugin}.",
        ) );
    }

    // -------------------------------------------------------------------------
    // User callbacks
    // -------------------------------------------------------------------------

    public static function on_user_created( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $info = self::current_user_info();
        self::log( array(
            'event_type'  => 'user_created',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_id'   => $user_id,
            'object_name' => $user->user_login,
            'description' => "User created: \"{$user->user_login}\" (ID {$user_id}, email: {$user->user_email}).",
        ) );
    }

    public static function on_user_deleted( $user_id, $reassign, $user ) {
        $info = self::current_user_info();
        self::log( array(
            'event_type'  => 'user_deleted',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_id'   => $user_id,
            'object_name' => $user->user_login,
            'description' => "User deleted: \"{$user->user_login}\" (ID {$user_id}, email: {$user->user_email}).",
        ) );
    }

    public static function on_user_updated( $user_id, $old_user_data ) {
        $new_user = get_userdata( $user_id );
        if ( ! $new_user ) {
            return;
        }
        $info = self::current_user_info();

        $changes = array();
        if ( $old_user_data->user_email !== $new_user->user_email ) {
            $changes[] = "email: {$old_user_data->user_email} → {$new_user->user_email}";
        }
        if ( $old_user_data->display_name !== $new_user->display_name ) {
            $changes[] = "display name: \"{$old_user_data->display_name}\" → \"{$new_user->display_name}\"";
        }
        if ( $old_user_data->user_login !== $new_user->user_login ) {
            $changes[] = "login: {$old_user_data->user_login} → {$new_user->user_login}";
        }

        $detail = $changes ? implode( ', ', $changes ) : 'profile data updated';

        self::log( array(
            'event_type'  => 'user_updated',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_id'   => $user_id,
            'object_name' => $new_user->user_login,
            'description' => "User updated: \"{$new_user->user_login}\" (ID {$user_id}) — {$detail}.",
        ) );
    }

    // -------------------------------------------------------------------------
    // Theme callbacks
    // -------------------------------------------------------------------------

    public static function on_theme_switched( $new_name, $new_theme ) {
        $info = self::current_user_info();
        self::log( array(
            'event_type'  => 'theme_switched',
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'object_name' => $new_name,
            'description' => "Active theme switched to \"{$new_name}\".",
        ) );
    }

    // -------------------------------------------------------------------------
    // Upgrade callback (plugins & themes)
    // -------------------------------------------------------------------------

    public static function on_upgrade( $upgrader, $hook_extra ) {
        $info = self::current_user_info();

        $type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';   // 'plugin' or 'theme'
        $action = isset( $hook_extra['action'] ) ? $hook_extra['action'] : 'update'; // 'install' or 'update'

        if ( 'plugin' === $type ) {
            $plugins = isset( $hook_extra['plugins'] ) ? $hook_extra['plugins']
                     : ( isset( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() );

            foreach ( $plugins as $plugin ) {
                self::log( array(
                    'event_type'  => "plugin_{$action}d",
                    'user_id'     => $info['user_id'],
                    'username'    => $info['username'],
                    'object_name' => $plugin,
                    'description' => "Plugin {$action}d: {$plugin}.",
                ) );
            }
        } elseif ( 'theme' === $type ) {
            $themes = isset( $hook_extra['themes'] ) ? $hook_extra['themes']
                    : ( isset( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() );

            foreach ( $themes as $theme ) {
                self::log( array(
                    'event_type'  => "theme_{$action}d",
                    'user_id'     => $info['user_id'],
                    'username'    => $info['username'],
                    'object_name' => $theme,
                    'description' => "Theme {$action}d: {$theme}.",
                ) );
            }
        }
    }
}
