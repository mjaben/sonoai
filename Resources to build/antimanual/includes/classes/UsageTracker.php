<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Usage Tracking Class
 *
 * Tracks usage of features (e.g., chatbot messages, auto-posting)
 * to enforce limits for free/pro tiers.
 *
 * @package Antimanual
 */
class UsageTracker {
    private static $table_name = 'antimanual_usage';
    
    /**
     * Get the full table name with prefix.
     *
     * @return string Table name.
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }
    
    /**
     * Get usage count for a specific feature in the current month.
     *
     * @param string $feature Feature identifier.
     * @return int Usage count.
     */
    public static function get_monthly_count($feature) {
        global $wpdb;
        $table = self::get_table_name();
        $month = gmdate('Y-m');
        
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE feature = %s AND month = %s",
            $feature, $month
        )));
    }
    
    /**
     * Get total (lifetime) count of usage for a feature.
     * Used for features with lifetime limits (e.g., search_block).
     */
    public static function get_total_count($feature) {
        global $wpdb;
        $table = self::get_table_name();
        
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE feature = %s",
            $feature
        )));
    }
    
    /**
     * Get monthly counts for all features.
     *
     * @return array Associative array of feature counts.
     */
    public static function get_all_monthly_counts() {
        global $wpdb;
        $table = self::get_table_name();
        $month = gmdate('Y-m');
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT feature, COUNT(*) as count FROM $table 
             WHERE month = %s GROUP BY feature",
            $month
        ), ARRAY_A);
        
        $counts = [];
        foreach ($results as $row) {
            $counts[$row['feature']] = intval($row['count']);
        }
        
        return $counts;
    }
    
    /**
     * Increment usage for a feature.
     *
     * @param string $feature Feature identifier.
     * @return int|false Row ID on success, false on error.
     */
    public static function increment($feature) {
        global $wpdb;
        $table = self::get_table_name();
        
        return $wpdb->insert($table, [
            'feature' => $feature,
            'month' => gmdate('Y-m'),
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Create the usage tracking table.
     */
    public static function create_table() {
        global $wpdb;
        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feature varchar(50) NOT NULL,
            month varchar(7) NOT NULL,
            user_id bigint(20) DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY feature_month (feature, month),
            KEY user_month (user_id, month)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
