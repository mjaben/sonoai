<?php
/**
 * SonoAI — Audit Logger.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AuditLogger {

    public static function init(): void {
        add_action( 'sonoai_purge_audit_logs', [ self::class, 'purge_old_logs' ] );
    }

    /**
     * Log an action to the audit log.
     *
     * @param string $action  The action taken (e.g., 'view_kb', 'edit_settings').
     * @param string $details Additional details (optional).
     */
    public static function log( string $action, string $details = '' ): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sonoai_audit_logs';

        // Check if table exists (in case it's not created yet or skipped in early load)
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
            return;
        }

        $user_id = get_current_user_id();

        $wpdb->insert(
            $table_name,
            [
                'user_id'    => $user_id,
                'action'     => sanitize_text_field( $action ),
                'details'    => wp_kses_post( $details ),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Purge audit logs older than 90 days.
     */
    public static function purge_old_logs(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sonoai_audit_logs';

        // Only proceed if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
            return;
        }

        $wpdb->query(
            "DELETE FROM `{$table_name}` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
    }
}
