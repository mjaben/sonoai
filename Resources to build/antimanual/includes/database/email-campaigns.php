<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Campaigns Database Table Manager
 *
 * Manages the custom tables for email campaigns and send log.
 *
 * @package Antimanual
 */
class EmailCampaignsDB {
	private static $campaigns_table      = 'atml_email_campaigns';
	private static $send_log_table       = 'atml_email_send_log';
	private static $sequence_steps_table = 'atml_email_sequence_steps';
	private static $sequence_log_table   = 'atml_email_sequence_log';

	/**
	 * Get the campaigns table name with prefix.
	 *
	 * @return string
	 */
	public static function get_campaigns_table() {
		global $wpdb;
		return $wpdb->prefix . self::$campaigns_table;
	}

	/**
	 * Get the send log table name with prefix.
	 *
	 * @return string
	 */
	public static function get_send_log_table() {
		global $wpdb;
		return $wpdb->prefix . self::$send_log_table;
	}

	/**
	 * Get the sequence steps table name with prefix.
	 *
	 * @return string
	 */
	public static function get_sequence_steps_table() {
		global $wpdb;
		return $wpdb->prefix . self::$sequence_steps_table;
	}

	/**
	 * Get the sequence log table name with prefix.
	 *
	 * @return string
	 */
	public static function get_sequence_log_table() {
		global $wpdb;
		return $wpdb->prefix . self::$sequence_log_table;
	}

	/**
	 * Create both tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$campaigns_table      = self::get_campaigns_table();
		$send_log_table       = self::get_send_log_table();
		$sequence_steps_table = self::get_sequence_steps_table();
		$sequence_log_table   = self::get_sequence_log_table();

		$sql_campaigns = "CREATE TABLE IF NOT EXISTS $campaigns_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			subject varchar(255) DEFAULT '',
			preview_text varchar(255) DEFAULT '',
			content longtext DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			campaign_type varchar(20) NOT NULL DEFAULT 'single',
			schedule_type varchar(20) NOT NULL DEFAULT 'immediate',
			scheduled_at datetime DEFAULT NULL,
			recurrence varchar(20) DEFAULT NULL,
			recurrence_day int DEFAULT NULL,
			recurrence_time varchar(5) DEFAULT NULL,
			ai_topic text DEFAULT NULL,
			ai_tone varchar(50) DEFAULT 'professional',
			ai_language varchar(50) DEFAULT 'English',
			template_id varchar(50) DEFAULT 'minimal',
			custom_template_html longtext DEFAULT NULL,
			include_recent_posts tinyint(1) DEFAULT 0,
			recent_posts_count int DEFAULT 3,
			target_lists text DEFAULT '',
			target_subscription_types text DEFAULT '',
			total_sent int DEFAULT 0,
			total_opened int DEFAULT 0,
			total_clicked int DEFAULT 0,
			total_replied int DEFAULT 0,
			total_forwarded int DEFAULT 0,
			total_spam_reported int DEFAULT 0,
			total_unsubscribed int DEFAULT 0,
			last_sent_at datetime DEFAULT NULL,
			next_send_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY campaign_type (campaign_type),
			KEY schedule_type (schedule_type),
			KEY next_send_at (next_send_at)
		) $charset_collate;";

		$sql_send_log = "CREATE TABLE IF NOT EXISTS $send_log_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) NOT NULL,
			subscriber_id bigint(20) NOT NULL,
			subject varchar(255) DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'queued',
			sent_at datetime DEFAULT NULL,
			opened_at datetime DEFAULT NULL,
			clicked_at datetime DEFAULT NULL,
			replied_at datetime DEFAULT NULL,
			forwarded_at datetime DEFAULT NULL,
			spam_reported_at datetime DEFAULT NULL,
			unsubscribed_at datetime DEFAULT NULL,
			error_message text DEFAULT NULL,
			sequence_step_id bigint(20) DEFAULT NULL,
			user_agent varchar(500) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY campaign_id (campaign_id),
			KEY subscriber_id (subscriber_id),
			KEY status (status),
			KEY clicked_at (clicked_at),
			KEY sequence_step_id (sequence_step_id)
		) $charset_collate;";

		$sql_sequence_steps = "CREATE TABLE IF NOT EXISTS $sequence_steps_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) NOT NULL,
			step_order int NOT NULL DEFAULT 1,
			delay_days int NOT NULL DEFAULT 0,
			delay_hours int NOT NULL DEFAULT 0,
			name varchar(255) NOT NULL DEFAULT '',
			subject varchar(255) DEFAULT '',
			preview_text varchar(255) DEFAULT '',
			content longtext DEFAULT '',
			ai_topic text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY campaign_id (campaign_id),
			KEY step_order (step_order)
		) $charset_collate;";

		$sql_sequence_log = "CREATE TABLE IF NOT EXISTS $sequence_log_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) NOT NULL,
			subscriber_id bigint(20) NOT NULL,
			current_step int NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			next_send_at datetime DEFAULT NULL,
			started_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY campaign_subscriber (campaign_id, subscriber_id),
			KEY status (status),
			KEY next_send_at (next_send_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_campaigns );
		dbDelta( $sql_send_log );
		dbDelta( $sql_sequence_steps );
		dbDelta( $sql_sequence_log );
	}

	/**
	 * Ensure the campaigns table exists and schema is up to date.
	 *
	 * Uses a DB version option so dbDelta re-runs when schema changes
	 * (e.g. new columns like target_lists).
	 */
	public static function ensure_tables_exist() {
		global $wpdb;

		$current_version = '1.8';
		$installed       = get_option( 'atml_email_campaigns_db_version', '0' );
		$campaigns_table = self::get_campaigns_table();
		$send_log_table  = self::get_send_log_table();
		$seq_steps_table = self::get_sequence_steps_table();
		$seq_log_table   = self::get_sequence_log_table();
		$campaigns_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $campaigns_table ) );
		$send_log_exist  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $send_log_table ) );
		$seq_steps_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $seq_steps_table ) );
		$seq_log_exist   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $seq_log_table ) );

		if (
			version_compare( $installed, $current_version, '<' )
			|| $campaigns_exist !== $campaigns_table
			|| $send_log_exist !== $send_log_table
			|| $seq_steps_exist !== $seq_steps_table
			|| $seq_log_exist !== $seq_log_table
		) {
			self::create_tables();

			// Migration: add target_lists column if missing.
			if ( $campaigns_exist === $campaigns_table ) {
				$has_target_lists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$campaigns_table` LIKE %s", 'target_lists' ) );

				if ( ! $has_target_lists ) {
					$wpdb->query( "ALTER TABLE `$campaigns_table` ADD COLUMN target_lists text DEFAULT '' AFTER recent_posts_count" );
				}

				$has_target_subscription_types = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$campaigns_table` LIKE %s", 'target_subscription_types' ) );

				if ( ! $has_target_subscription_types ) {
					$wpdb->query( "ALTER TABLE `$campaigns_table` ADD COLUMN target_subscription_types text DEFAULT '' AFTER target_lists" );
				}
			}

			// Migration: add campaign_type column if missing.
			if ( $campaigns_exist === $campaigns_table ) {
				$has_campaign_type = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$campaigns_table` LIKE %s", 'campaign_type' ) );

				if ( ! $has_campaign_type ) {
					$wpdb->query( "ALTER TABLE `$campaigns_table` ADD COLUMN campaign_type varchar(20) NOT NULL DEFAULT 'single' AFTER status" );
				}
			}

			// Migration: add sequence_step_id column to send log if missing.
			if ( $send_log_exist === $send_log_table ) {
				$has_step_id = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$send_log_table` LIKE %s", 'sequence_step_id' ) );

				if ( ! $has_step_id ) {
					$wpdb->query( "ALTER TABLE `$send_log_table` ADD COLUMN sequence_step_id bigint(20) DEFAULT NULL AFTER error_message" );
					$wpdb->query( "ALTER TABLE `$send_log_table` ADD KEY sequence_step_id (sequence_step_id)" );
				}

				$has_clicked_at = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$send_log_table` LIKE %s", 'clicked_at' ) );

				if ( ! $has_clicked_at ) {
					$wpdb->query( "ALTER TABLE `$send_log_table` ADD COLUMN clicked_at datetime DEFAULT NULL AFTER opened_at" );
					$wpdb->query( "ALTER TABLE `$send_log_table` ADD KEY clicked_at (clicked_at)" );
				}
			}

			// Migration: add custom_template_html column if missing.
			if ( $campaigns_exist === $campaigns_table ) {
				$has_custom_tpl = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$campaigns_table` LIKE %s", 'custom_template_html' ) );

				if ( ! $has_custom_tpl ) {
					$wpdb->query( "ALTER TABLE `$campaigns_table` ADD COLUMN custom_template_html longtext DEFAULT NULL AFTER template_id" );
				}
			}

			// Migration: add tracking columns to send_log if missing.
			if ( $send_log_exist === $send_log_table ) {
				$tracking_cols = [ 'replied_at', 'forwarded_at', 'spam_reported_at', 'unsubscribed_at' ];

				foreach ( $tracking_cols as $col ) {
					$has_col = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$send_log_table` LIKE %s", $col ) );

					if ( ! $has_col ) {
						$wpdb->query( "ALTER TABLE `$send_log_table` ADD COLUMN `$col` datetime DEFAULT NULL AFTER clicked_at" );
					}
				}
			}

			// Migration: add tracking counter columns to campaigns if missing.
			if ( $campaigns_exist === $campaigns_table ) {
				$counter_cols = [ 'total_replied', 'total_forwarded', 'total_spam_reported', 'total_unsubscribed' ];

				foreach ( $counter_cols as $col ) {
					$has_col = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$campaigns_table` LIKE %s", $col ) );

					if ( ! $has_col ) {
						$wpdb->query( "ALTER TABLE `$campaigns_table` ADD COLUMN `$col` int DEFAULT 0 AFTER total_clicked" );
					}
				}
			}

			// Migration: add user_agent column to send_log if missing.
			if ( $send_log_exist === $send_log_table ) {
				$has_ua = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$send_log_table` LIKE %s", 'user_agent' ) );

				if ( ! $has_ua ) {
					$wpdb->query( "ALTER TABLE `$send_log_table` ADD COLUMN user_agent varchar(500) DEFAULT NULL AFTER sequence_step_id" );
				}
			}

			update_option( 'atml_email_campaigns_db_version', $current_version, false );
		}
	}
}
