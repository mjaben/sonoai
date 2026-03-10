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
        // Stores individual 800-char chunks with their vector embedding.
        // knowledge_id groups all chunks that belong to one KB item.
        $embeddings_table = $wpdb->prefix . 'sonoai_embeddings';
        $sql_embeddings   = "CREATE TABLE IF NOT EXISTS `$embeddings_table` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `knowledge_id`      VARCHAR(36)     NOT NULL,
            `post_id`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `post_type`         VARCHAR(50)     NOT NULL DEFAULT 'docs',
            `type`              VARCHAR(20)     NOT NULL DEFAULT 'wp',
            `source_url`        TEXT                     DEFAULT NULL,
            `source_title`      VARCHAR(255)             DEFAULT NULL,
            `image_urls`        LONGTEXT                 DEFAULT NULL,
            `chunk_index`       INT             NOT NULL DEFAULT 0,
            `chunk_text`        LONGTEXT        NOT NULL,
            `embedding`         LONGTEXT        NOT NULL,
            `provider`          VARCHAR(20)     NOT NULL DEFAULT 'openai',
            `embedding_model`   VARCHAR(100)             DEFAULT NULL,
            `post_modified_gmt` DATETIME                 DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_post_id`      (`post_id`),
            KEY `idx_post_type`    (`post_type`),
            KEY `idx_type`         (`type`),
            KEY `idx_knowledge_id` (`knowledge_id`(36))
        ) $charset_collate;";
        dbDelta( $sql_embeddings );

        // Explicit fallback for WP dbDelta failing to alter existing tables
        $cols = $wpdb->get_col( "DESCRIBE `$embeddings_table`", 0 );
        if ( ! empty( $cols ) && ! in_array( 'type', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'wp' AFTER `post_type`" );
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD COLUMN `source_url` TEXT DEFAULT NULL AFTER `type`" );
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD COLUMN `source_title` VARCHAR(255) DEFAULT NULL AFTER `source_url`" );
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD COLUMN `image_urls` LONGTEXT DEFAULT NULL AFTER `source_title`" );
        }

        // ── KB Items table ─────────────────────────────────────────────────────
        // One row per KB item (not per chunk) — drives the list-table views.
        $kb_items_table = $wpdb->prefix . 'sonoai_kb_items';
        $sql_kb_items   = "CREATE TABLE IF NOT EXISTS `$kb_items_table` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `knowledge_id`    VARCHAR(36)     NOT NULL,
            `type`            VARCHAR(20)     NOT NULL DEFAULT 'wp',
            `post_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `source_title`    VARCHAR(255)             DEFAULT NULL,
            `source_url`      TEXT                     DEFAULT NULL,
            `raw_content`     LONGTEXT                 DEFAULT NULL,
            `image_urls`      LONGTEXT                 DEFAULT NULL,
            `provider`        VARCHAR(20)     NOT NULL DEFAULT 'openai',
            `embedding_model` VARCHAR(100)             DEFAULT NULL,
            `chunk_count`     INT             NOT NULL DEFAULT 0,
            `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_knowledge_id` (`knowledge_id`),
            KEY `idx_type`    (`type`),
            KEY `idx_post_id` (`post_id`)
        ) $charset_collate;";
        dbDelta( $sql_kb_items );

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
