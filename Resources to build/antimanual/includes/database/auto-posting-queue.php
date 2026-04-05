<?php

namespace Antimanual;

class AutoPostingQueue {
    private static $table_name = 'antimanual_auto_posting_queue';

    /**
     * Create the queue table
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            posting_id bigint(20) NOT NULL,
            scheduled_time datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            retry_count int(11) NOT NULL DEFAULT 0,
            last_error text,
            created_at datetime NOT NULL,
            executed_at datetime,
            PRIMARY KEY (id),
            KEY posting_id (posting_id),
            KEY status (status),
            KEY scheduled_time (scheduled_time)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Add a posting to the queue
     */
    public static function add( $posting_id, $scheduled_time ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // Ensure table exists
        self::ensure_table_exists();

        // Check if this exact schedule already exists in queue
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table_name WHERE posting_id = %d AND scheduled_time = %s AND status IN ('pending', 'processing')",
            $posting_id,
            $scheduled_time
        ) );

        if ( $existing ) {
            return $existing; // Already queued
        }

        $wpdb->insert(
            $table_name,
            [
                'posting_id'     => $posting_id,
                'scheduled_time' => $scheduled_time,
                'status'         => 'pending',
                'created_at'     => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );

        return $wpdb->insert_id;
    }

    /**
     * Ensure the table exists
     */
    public static function ensure_table_exists() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
            self::create_table();
        }
    }

    /**
     * Get pending queue items ready to process
     */
    public static function get_pending( $limit = 10 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $current_time = current_time( 'mysql', true );

        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ) );

        if ( ! $table_exists ) {
            return [];
        }

        // Get items that are:
        // 1. Pending status
        // 2. Scheduled time has passed
        // 3. Retry count < 3
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE status = 'pending' 
            AND scheduled_time <= %s 
            AND retry_count < 3 
            ORDER BY scheduled_time ASC 
            LIMIT %d",
            $current_time,
            $limit
        ) );

        return $results ?: [];
    }

    /**
     * Mark item as processing
     */
    public static function mark_processing( $id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->update(
            $table_name,
            [ 'status' => 'processing' ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Mark item as completed
     */
    public static function mark_completed( $id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->update(
            $table_name,
            [
                'status'      => 'completed',
                'executed_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Mark item as failed and increment retry count
     */
    public static function mark_failed( $id, $error_message = '' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $wpdb->query( $wpdb->prepare(
            "UPDATE $table_name 
            SET status = 'pending', 
                retry_count = retry_count + 1, 
                last_error = %s 
            WHERE id = %d",
            $error_message,
            $id
        ) );

        // Check if max retries reached
        $retry_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT retry_count FROM $table_name WHERE id = %d",
            $id
        ) );

        if ( $retry_count >= 3 ) {
            $wpdb->update(
                $table_name,
                [ 'status' => 'failed' ],
                [ 'id' => $id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    /**
     * Clean up old completed items (older than 7 days)
     */
    public static function cleanup() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DELETE FROM $table_name WHERE status = 'completed' AND executed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" );
    }

    /**
     * Get queue statistics
     */
    public static function get_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ) );

        if ( ! $table_exists ) {
            // Create the table if it doesn't exist
            self::create_table();
            
            // Return empty stats
            return [
                'total'      => 0,
                'pending'    => 0,
                'processing' => 0,
                'completed'  => 0,
                'failed'     => 0,
            ];
        }

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM $table_name",
            ARRAY_A
        );

        return $stats ?: [
            'total'      => 0,
            'pending'    => 0,
            'processing' => 0,
            'completed'  => 0,
            'failed'     => 0,
        ];
    }
}
