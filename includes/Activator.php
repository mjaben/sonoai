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
        // Add mode + topic_slug columns if missing (v1.3.0)
        if ( ! empty( $cols ) && ! in_array( 'mode', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT 'guideline' AFTER `image_urls`" );
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD KEY `idx_mode` (`mode`)" );
        }
        if ( ! empty( $cols ) && ! in_array( 'topic_slug', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD COLUMN `topic_slug` VARCHAR(100) DEFAULT NULL AFTER `mode`" );
            $wpdb->query( "ALTER TABLE `$embeddings_table` ADD KEY `idx_topic_slug` (`topic_slug`(100))" );
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

        // Add mode + topic_id columns to KB items if missing (v1.3.0)
        $kb_cols = $wpdb->get_col( "DESCRIBE `$kb_items_table`", 0 );
        if ( ! empty( $kb_cols ) && ! in_array( 'mode', $kb_cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$kb_items_table` ADD COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT 'guideline' AFTER `type`" );
            $wpdb->query( "ALTER TABLE `$kb_items_table` ADD KEY `idx_mode` (`mode`)" );
        }
        if ( ! empty( $kb_cols ) && ! in_array( 'topic_id', $kb_cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$kb_items_table` ADD COLUMN `topic_id` INT UNSIGNED DEFAULT NULL AFTER `mode`" );
        }

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

        // Add mode column to sessions if missing (v1.3.0)
        $sess_cols = $wpdb->get_col( "DESCRIBE `$sessions_table`", 0 );
        if ( ! empty( $sess_cols ) && ! in_array( 'mode', $sess_cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$sessions_table` ADD COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT 'guideline' AFTER `title`" );
        }

        // ── Query Logs table ──────────────────────────────────────────────────
        $logs_table = $wpdb->prefix . 'sonoai_query_logs';
        $sql_logs   = "CREATE TABLE IF NOT EXISTS `$logs_table` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `query_text`   LONGTEXT        NOT NULL,
            `response`     LONGTEXT        NOT NULL,
            `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) $charset_collate;";
        dbDelta( $sql_logs );

        // ── KB Topics table ───────────────────────────────────────────────────
        $topics_table = $wpdb->prefix . 'sonoai_kb_topics';
        $sql_topics   = "CREATE TABLE IF NOT EXISTS `$topics_table` (
            `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `slug`       VARCHAR(100)    NOT NULL,
            `name`       VARCHAR(255)    NOT NULL,
            `wp_term_id` BIGINT UNSIGNED          DEFAULT NULL,
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_slug` (`slug`(100))
        ) $charset_collate;";
        dbDelta( $sql_topics );

        // ── Saved Responses table ─────────────────────────────────────────────
        $saved_table = $wpdb->prefix . 'sonoai_saved_responses';
        $sql_saved   = "CREATE TABLE IF NOT EXISTS `$saved_table` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`       BIGINT UNSIGNED NOT NULL,
            `session_uuid`  VARCHAR(36)     NOT NULL,
            `message_index` INT             NOT NULL DEFAULT 0,
            `content`       LONGTEXT        NOT NULL,
            `mode`          VARCHAR(20)     NOT NULL DEFAULT 'guideline',
            `topic_slug`    VARCHAR(100)             DEFAULT NULL,
            `title`         VARCHAR(255)             DEFAULT NULL,
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id`      (`user_id`),
            KEY `idx_session_uuid` (`session_uuid`)
        ) $charset_collate;";
        dbDelta( $sql_saved );
        // ── Feedback table ────────────────────────────────────────────────────
        $feedback_table = $wpdb->prefix . 'sonoai_feedback';
        $sql_feedback   = "CREATE TABLE IF NOT EXISTS `$feedback_table` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_uuid` VARCHAR(36)     NOT NULL,
            `message_index`INT             NOT NULL DEFAULT 0,
            `vote`         VARCHAR(10)     NOT NULL, -- 'up' or 'down'
            `comment`      TEXT                     DEFAULT NULL,
            `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_session_uuid` (`session_uuid`),
            KEY `idx_vote` (`vote`)
        ) $charset_collate;";
        dbDelta( $sql_feedback );
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
