<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selective plugin uninstall cleanup.
 *
 * Allows administrators to choose which module-specific database data should
 * be removed when the plugin is uninstalled.
 */
class Uninstall {
	/**
	 * Option key that stores per-module uninstall cleanup preferences.
	 */
	const MODULE_UNINSTALL_OPTION_KEY = 'antimanual_module_uninstall_prefs';

	/**
	 * Default module cleanup preferences.
	 *
	 * @return array<string, bool>
	 */
	public static function get_module_uninstall_defaults(): array {
		return array(
			'chatbot'          => false,
			'search_block'     => false,
			'generate_post'    => false,
			'auto_posting'     => false,
			'auto_update'      => false,
			'bulk_rewrite'     => false,
			'repurpose_studio' => false,
			'faq_generator'    => false,
			'generate_docs'    => false,
			'forum_automation' => false,
			'translation'      => false,
			'seo_agent'        => false,
			'internal_linking' => false,
			'email_marketing'  => false,
		);
	}

	/**
	 * Get saved module uninstall preferences merged with defaults.
	 *
	 * @return array<string, bool>
	 */
	public static function get_module_uninstall_preferences(): array {
		$saved = \get_option( self::MODULE_UNINSTALL_OPTION_KEY, array() );

		return \wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			self::get_module_uninstall_defaults()
		);
	}

	/**
	 * UI metadata for each module cleanup toggle.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_module_uninstall_ui_data(): array {
		return array(
			'chatbot' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes chatbot conversations, feedback votes, the assistant landing page, and chatbot-specific settings. Shared Knowledge Base embeddings are kept.', 'antimanual' ),
			),
			'search_block' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes AI Search Block usage history. Shared Knowledge Base embeddings are kept so other AI features do not lose context unexpectedly.', 'antimanual' ),
			),
			'generate_post' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes Antimanual generation metadata from posts, but keeps the generated posts themselves because they are your site content.', 'antimanual' ),
			),
			'auto_posting' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes Auto Posting schedules, queue records, runtime transients, and Antimanual auto-post tracking meta.', 'antimanual' ),
			),
			'auto_update' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes Auto Update schedules, runtime transients, and Antimanual auto-update tracking meta.', 'antimanual' ),
			),
			'bulk_rewrite' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes Bulk Rewrite usage tracking stored by Antimanual.', 'antimanual' ),
			),
			'repurpose_studio' => array(
				'supports_cleanup' => false,
				'summary'          => __( 'Repurpose Studio does not store dedicated module data in the WordPress database.', 'antimanual' ),
			),
			'faq_generator' => array(
				'supports_cleanup' => false,
				'summary'          => __( 'FAQ Generator creates site content, but does not keep dedicated module-only database records that are safe to purge automatically.', 'antimanual' ),
			),
			'generate_docs' => array(
				'supports_cleanup' => false,
				'summary'          => __( 'Generate Docs creates documentation content, but does not keep dedicated module-only database records that are safe to purge automatically.', 'antimanual' ),
			),
			'forum_automation' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes Forum Automation settings and usage tracking. Existing forum topics and replies are kept because they are site content.', 'antimanual' ),
			),
			'translation' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes stored translations and translation settings created by Antimanual Translation.', 'antimanual' ),
			),
			'seo_agent' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes SEO Agent monitoring settings, cached analyses, and Antimanual SEO meta fields.', 'antimanual' ),
			),
			'internal_linking' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes Internal Linking cached reports and cache-version settings.', 'antimanual' ),
			),
			'email_marketing' => array(
				'supports_cleanup' => true,
				'summary'          => __( 'Deletes Email Campaign data including campaigns, subscribers, sequences, templates, settings, and related tracking data.', 'antimanual' ),
			),
		);
	}

	/**
	 * Execute uninstall cleanup.
	 *
	 * @return void
	 */
	public static function run(): void {
		$cleanup_prefs = self::get_module_uninstall_preferences();

		if ( ! empty( $cleanup_prefs['chatbot'] ) ) {
			self::cleanup_chatbot();
		}

		if ( ! empty( $cleanup_prefs['search_block'] ) ) {
			self::cleanup_search_block();
		}

		if ( ! empty( $cleanup_prefs['generate_post'] ) ) {
			self::cleanup_generate_post();
		}

		if ( ! empty( $cleanup_prefs['auto_posting'] ) ) {
			self::cleanup_auto_posting();
		}

		if ( ! empty( $cleanup_prefs['auto_update'] ) ) {
			self::cleanup_auto_update();
		}

		if ( ! empty( $cleanup_prefs['bulk_rewrite'] ) ) {
			self::cleanup_bulk_rewrite();
		}

		if ( ! empty( $cleanup_prefs['forum_automation'] ) ) {
			self::cleanup_forum_automation();
		}

		if ( ! empty( $cleanup_prefs['translation'] ) ) {
			self::cleanup_translation();
		}

		if ( ! empty( $cleanup_prefs['seo_agent'] ) ) {
			self::cleanup_seo_agent();
		}

		if ( ! empty( $cleanup_prefs['internal_linking'] ) ) {
			self::cleanup_internal_linking();
		}

		if ( ! empty( $cleanup_prefs['email_marketing'] ) ) {
			self::cleanup_email_marketing();
		}

		\delete_option( self::MODULE_UNINSTALL_OPTION_KEY );
	}

	/**
	 * Cleanup AI Chatbot data.
	 */
	private static function cleanup_chatbot(): void {
		self::delete_posts_by_type( array( 'atml_conversation' ) );
		self::delete_posts_by_meta( '_antimanual_assistant_page', '1' );
		self::delete_post_meta_keys( array(
			'_antimanual_assistant_page',
			'_atml_provider_conversation_id',
			'_atml_conversation_provider',
			'_atml_conversation_messages',
			'_atml_lead_email',
			'_atml_lead_name',
			'_atml_lead_ip',
			'_atml_lead_source',
			'_atml_lead_note',
			'_atml_lead_status',
			'_atml_unresolved',
		) );
		self::delete_options_by_prefixes( array( 'antimanual_chatbot_' ) );
		self::drop_tables( array( 'antimanual_query_votes' ) );
		self::delete_usage_rows( array( 'chatbot' ) );
	}

	/**
	 * Cleanup AI Search Block data.
	 */
	private static function cleanup_search_block(): void {
		self::delete_usage_rows( array( 'search_block' ) );
	}

	/**
	 * Cleanup Generate Post data.
	 */
	private static function cleanup_generate_post(): void {
		self::delete_post_meta_keys( array(
			'_atml_generated_post',
			'_atml_generated_at',
			'_atml_generation_prompt',
		) );
	}

	/**
	 * Cleanup Auto Posting data.
	 */
	private static function cleanup_auto_posting(): void {
		self::delete_posts_by_type( array( 'atml_auto_posting' ) );
		self::delete_post_meta_keys( array(
			'_atml_auto_post_status',
			'_atml_auto_posting_id',
			'_atml_auto_post_topic',
			'_atml_auto_post_error',
			'_atml_auto_post_scheduled',
		) );
		self::drop_tables( array( 'antimanual_auto_posting_queue' ) );
		self::delete_transients( array( 'atml_auto_posting_last_check', 'atml_auto_posting_running' ) );
	}

	/**
	 * Cleanup Auto Update data.
	 */
	private static function cleanup_auto_update(): void {
		self::delete_posts_by_type( array( 'atml_auto_update' ) );
		self::delete_post_meta_keys( array(
			'_atml_auto_update_status',
			'_atml_auto_update_id',
			'_atml_auto_update_error',
			'_atml_auto_updated_at',
		) );
		self::delete_transients( array( 'atml_auto_update_last_check', 'atml_auto_update_running' ) );
	}

	/**
	 * Cleanup Bulk Rewrite data.
	 */
	private static function cleanup_bulk_rewrite(): void {
		self::delete_usage_rows( array( 'bulk_rewrite' ) );
	}

	/**
	 * Cleanup Forum Automation data.
	 */
	private static function cleanup_forum_automation(): void {
		self::delete_options_by_prefixes( array( 'antimanual_bbp_' ) );
		self::delete_usage_rows( array( 'forum_answer', 'forum_conversion' ) );
	}

	/**
	 * Cleanup Translation data.
	 */
	private static function cleanup_translation(): void {
		self::drop_tables( array( 'atml_translations' ) );
		self::delete_options_by_prefixes( array( 'antimanual_translation_' ) );
		self::delete_exact_options( array( 'atml_translations_db_version' ) );
	}

	/**
	 * Cleanup SEO Agent data.
	 */
	private static function cleanup_seo_agent(): void {
		self::delete_exact_options( array( 'antimanual_seo_monitoring_prefs' ) );
		self::delete_post_meta_keys( array(
			'_atml_meta_description',
			'_atml_focus_keyword',
			'_atml_seo_force_lazy_loading',
			'_atml_seo_force_noopener',
		) );
		self::delete_transients_by_prefixes( array( 'atml_seo_agent_cache_' ) );
	}

	/**
	 * Cleanup Internal Linking data.
	 */
	private static function cleanup_internal_linking(): void {
		self::delete_exact_options( array( 'atml_il_report_cache_version' ) );
		self::delete_transients_by_prefixes( array( 'atml_il_report_' ) );
	}

	/**
	 * Cleanup Email Campaign data.
	 */
	private static function cleanup_email_marketing(): void {
		self::drop_tables( array(
			'atml_email_subscribers',
			'atml_email_campaigns',
			'atml_email_send_log',
			'atml_email_sequence_steps',
			'atml_email_sequence_log',
		) );
		self::delete_options_by_prefixes( array( 'atml_email_' ) );
		self::delete_exact_options( array( 'atml_custom_email_templates' ) );
		self::delete_transients( array( 'atml_email_campaign_last_check', 'atml_email_campaign_running' ) );
	}

	/**
	 * Delete exact options.
	 *
	 * @param array<int, string> $option_names Option names.
	 */
	private static function delete_exact_options( array $option_names ): void {
		foreach ( $option_names as $option_name ) {
			\delete_option( $option_name );
		}
	}

	/**
	 * Delete options by name prefix.
	 *
	 * @param array<int, string> $prefixes Prefixes.
	 */
	private static function delete_options_by_prefixes( array $prefixes ): void {
		global $wpdb;

		foreach ( $prefixes as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		}
	}

	/**
	 * Delete post meta keys globally.
	 *
	 * @param array<int, string> $meta_keys Meta keys.
	 */
	private static function delete_post_meta_keys( array $meta_keys ): void {
		foreach ( $meta_keys as $meta_key ) {
			\delete_post_meta_by_key( $meta_key );
		}
	}

	/**
	 * Delete all posts for the given post types.
	 *
	 * @param array<int, string> $post_types Post types.
	 */
	private static function delete_posts_by_type( array $post_types ): void {
		$post_ids = \get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $post_ids as $post_id ) {
			\wp_delete_post( (int) $post_id, true );
		}
	}

	/**
	 * Delete posts by meta key/value pair.
	 *
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 */
	private static function delete_posts_by_meta( string $meta_key, string $meta_value ): void {
		$post_ids = \get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key,
				'meta_value'     => $meta_value,
			)
		);

		foreach ( $post_ids as $post_id ) {
			\wp_delete_post( (int) $post_id, true );
		}
	}

	/**
	 * Drop custom tables by unprefixed base table name.
	 *
	 * @param array<int, string> $table_names Table names without WP prefix.
	 */
	private static function drop_tables( array $table_names ): void {
		global $wpdb;

		foreach ( $table_names as $table_name ) {
			$full_table_name = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$full_table_name}`" );
		}
	}

	/**
	 * Delete exact transients.
	 *
	 * @param array<int, string> $transient_keys Transient keys.
	 */
	private static function delete_transients( array $transient_keys ): void {
		foreach ( $transient_keys as $transient_key ) {
			\delete_transient( $transient_key );
		}
	}

	/**
	 * Delete transients by key prefix.
	 *
	 * @param array<int, string> $prefixes Transient key prefixes.
	 */
	private static function delete_transients_by_prefixes( array $prefixes ): void {
		global $wpdb;

		foreach ( $prefixes as $prefix ) {
			$transient_like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			$timeout_like   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$transient_like,
					$timeout_like
				)
			);
		}
	}

	/**
	 * Delete usage tracker rows for specific features.
	 *
	 * @param array<int, string> $features Feature slugs.
	 */
	private static function delete_usage_rows( array $features ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'antimanual_usage';
		$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( $found !== $table_name || empty( $features ) ) {
			return;
		}

		$features     = array_values( array_filter( array_map( 'sanitize_key', $features ) ) );
		$placeholders = implode( ', ', array_fill( 0, count( $features ), '%s' ) );
		$query        = $wpdb->prepare( "DELETE FROM {$table_name} WHERE feature IN ({$placeholders})", ...$features );
		$wpdb->query( $query );
	}
}