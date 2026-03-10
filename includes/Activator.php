<?php
/**
 * SonoAI — Activator / DB table creation.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    /**
     * Fired on plugin activation.
     */
    public static function run(): void {
        self::create_tables();
        self::create_upload_dir();
        // Flush rewrite rules after CPT/shortcode registration.
        flush_rewrite_rules();
    }

    /**
     * Fired on plugin deactivation.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Create or upgrade custom DB tables.
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Embeddings table ──────────────────────────────────────────────────
        $embeddings_table = $wpdb->prefix . 'sonoai_embeddings';
        $sql_embeddings   = "CREATE TABLE IF NOT EXISTS `$embeddings_table` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `knowledge_id`      VARCHAR(36)     NOT NULL,
            `post_id`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `post_type`         VARCHAR(50)     NOT NULL DEFAULT 'docs',
            `chunk_index`       INT             NOT NULL DEFAULT 0,
            `chunk_text`        LONGTEXT        NOT NULL,
            `embedding`         LONGTEXT        NOT NULL,
            `provider`          VARCHAR(20)     NOT NULL DEFAULT 'openai',
            `embedding_model`   VARCHAR(100)             DEFAULT NULL,
            `post_modified_gmt` DATETIME                 DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_post_id`   (`post_id`),
            KEY `idx_post_type` (`post_type`)
        ) $charset_collate;";
        dbDelta( $sql_embeddings );

        // ── Sessions table ────────────────────────────────────────────────────
        $sessions_table = $wpdb->prefix . 'sonoai_sessions';
        $sql_sessions   = "CREATE TABLE IF NOT EXISTS `$sessions_table` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_uuid` VARCHAR(36)     NOT NULL,
            `user_id`      BIGINT UNSIGNED NOT NULL,
            `title`        VARCHAR(255)    NOT NULL DEFAULT '',
            `messages`     LONGTEXT                 DEFAULT NULL,
            `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_session_uuid` (`session_uuid`),
            KEY `idx_user_id` (`user_id`)
        ) $charset_collate;";
        dbDelta( $sql_sessions );
    }

    /**
     * Create the sonoai upload directory and protect it with an .htaccess.
     */
    public static function create_upload_dir(): void {
        $dir = sonoai_upload_dir();
        if ( ! file_exists( $dir['path'] ) ) {
            wp_mkdir_p( $dir['path'] );
        }
        $htaccess = $dir['path'] . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" );
        }
    }
}
