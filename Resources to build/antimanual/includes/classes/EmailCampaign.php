<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\EmailSubscribers;
use Antimanual\EmailCampaignsDB;

/**
 * Email Campaign Manager
 *
 * Handles campaign CRUD, AI content generation, scheduling,
	 * and email sending for the email campaign feature.
 *
 * @package Antimanual
 */
class EmailCampaign {
	public static $instance = null;

	/**
	 * Transient keys for the lightweight cron-like mechanism.
	 */
	private static $last_check_key   = 'atml_email_campaign_last_check';
	private static $running_lock_key = 'atml_email_campaign_running';
	private static $check_interval   = 120; // Check every 2 minutes

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_run_campaigns' ], 25 );
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return EmailCampaign
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Lightweight check that runs on every WordPress request.
	 * Only executes campaign logic when sufficient time has passed.
	 */
	public function maybe_run_campaigns() {
		if ( ! atml_is_pro() ) {
			return;
		}

		$last_check = get_transient( self::$last_check_key );
		$now        = time();

		if ( false !== $last_check && ( $now - intval( $last_check ) ) < self::$check_interval ) {
			return;
		}

		if ( get_transient( self::$running_lock_key ) ) {
			return;
		}

		set_transient( self::$running_lock_key, true, 300 );
		set_transient( self::$last_check_key, $now, DAY_IN_SECONDS );

		try {
			$this->process_due_campaigns();
		} finally {
			delete_transient( self::$running_lock_key );
		}
	}

	/**
	 * Process campaigns that are due to be sent.
	 */
	public function process_due_campaigns() {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();
		EmailCampaignsDB::ensure_tables_exist();

		// Process due sequence steps.
		EmailSequence::process_due_steps();

		$now = current_time( 'mysql', true );

		// Get campaigns that are due.
		$due_campaigns = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, subject, content, ai_topic, ai_tone, ai_language,
				template_id, include_recent_posts, recent_posts_count,
				total_sent, schedule_type, recurrence, recurrence_day, recurrence_time, preview_text, target_lists, target_subscription_types
			FROM $table
			WHERE status = 'scheduled'
			AND next_send_at IS NOT NULL
			AND next_send_at <= %s
			ORDER BY next_send_at ASC
			LIMIT 3",
			$now
		), ARRAY_A );

		if ( empty( $due_campaigns ) ) {
			return;
		}

		foreach ( $due_campaigns as $campaign ) {
			$this->execute_campaign( $campaign );
		}
	}

	/**
	 * Execute a single campaign: generate content if needed, then send.
	 *
	 * @param array $campaign Campaign data row.
	 */
	private function execute_campaign( array $campaign ) {
		global $wpdb;
		$table       = EmailCampaignsDB::get_campaigns_table();
		$campaign_id = absint( $campaign['id'] );
		$current_total_sent = intval( $campaign['total_sent'] ?? 0 );

		// Mark as sending.
		$wpdb->update(
			$table,
			[ 'status' => 'sending' ],
			[ 'id' => $campaign_id ],
			[ '%s' ],
			[ '%d' ]
		);

		$content = $campaign['content'] ?? '';
		$subject = $campaign['subject'] ?? '';

		// If AI topic is set and content is empty, generate it.
		if ( ! empty( $campaign['ai_topic'] ) && empty( $content ) ) {
			$ai_result = self::generate_email_content(
				$campaign['ai_topic'],
				$campaign['ai_tone'] ?? 'professional',
				$campaign['ai_language'] ?? 'English',
				! empty( $campaign['include_recent_posts'] ),
				intval( $campaign['recent_posts_count'] ?? 3 )
			);

			if ( ! is_wp_error( $ai_result ) ) {
				$content = $ai_result['content'] ?? '';
				$subject = $ai_result['subject'] ?? $subject;

				// Save generated content back to campaign.
				$wpdb->update(
					$table,
					[
						'content' => $content,
						'subject' => $subject,
					],
					[ 'id' => $campaign_id ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
			} else {
				// AI generation failed, mark campaign as draft.
				$wpdb->update(
					$table,
					[ 'status' => 'draft' ],
					[ 'id' => $campaign_id ],
					[ '%s' ],
					[ '%d' ]
				);
				return;
			}
		}

		if ( empty( $content ) || empty( $subject ) ) {
			$wpdb->update(
				$table,
				[ 'status' => 'draft' ],
				[ 'id' => $campaign_id ],
				[ '%s' ],
				[ '%d' ]
			);
			return;
		}

		// Send to targeted or all active subscribers (batched).
		$target_lists              = array_filter( explode( ',', $campaign['target_lists'] ?? '' ), 'strlen' );
		$target_subscription_types = array_filter( explode( ',', $campaign['target_subscription_types'] ?? '' ), 'strlen' );
		$sent_count                = $this->send_to_subscribers( $campaign_id, $subject, $content, $campaign, $target_lists, $target_subscription_types );

		// Update campaign status and stats.
		$next_send = null;
		$new_status = 'sent';

		// Calculate next send for recurring campaigns.
		if ( $campaign['schedule_type'] === 'recurring' && ! empty( $campaign['recurrence'] ) ) {
			$next_send  = self::calculate_next_send( $campaign );
			$new_status = 'scheduled';
		}

		$wpdb->update(
			$table,
			[
				'status'       => $new_status,
				'total_sent'   => $current_total_sent + $sent_count,
				'last_sent_at' => current_time( 'mysql', true ),
				'next_send_at' => $next_send,
				'content'      => ( $campaign['schedule_type'] === 'recurring' && ! empty( $campaign['ai_topic'] ) ) ? '' : $content,
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => $campaign_id ],
			[ '%s', '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Send campaign to all active subscribers.
	 *
	 * @param int    $campaign_id  Campaign ID.
	 * @param string $subject      Email subject.
	 * @param string $content      Email content.
	 * @param array  $campaign     Campaign data.
	 * @param array  $target_lists List IDs to target (empty = all).
	 * @return int Number of emails sent.
	 */
	private function send_to_subscribers( $campaign_id, $subject, $content, $campaign, array $target_lists = [], array $target_subscription_types = [] ) {
		global $wpdb;
		$log_table    = EmailCampaignsDB::get_send_log_table();
		$sent         = 0;
		$offset       = 0;
		$headers      = self::get_mail_headers();
		$em_settings  = get_option( 'atml_email_settings', [] );
		$batch        = max( 5, min( 200, absint( $em_settings['batch_size'] ?? 25 ) ) );
		$batch_delay  = max( 0, min( 60, absint( $em_settings['batch_delay'] ?? 1 ) ) );
		$tracking_on  = $em_settings['enable_tracking'] ?? true;
		$auto_remove_failed     = self::should_auto_remove_failed_subscribers();
		$subscriber_ids_to_remove = [];

		while ( true ) {
			$subscribers = ( ! empty( $target_lists ) || ! empty( $target_subscription_types ) )
				? EmailSubscribers::get_active_by_lists( $target_lists, $batch, $offset, $target_subscription_types )
				: EmailSubscribers::get_active( $batch, $offset );

			if ( empty( $subscribers ) ) {
				break;
			}

			foreach ( $subscribers as $subscriber ) {
				$wpdb->insert(
					$log_table,
					[
						'campaign_id'   => $campaign_id,
						'subscriber_id' => $subscriber['id'],
						'subject'       => $subject,
						'status'        => 'queued',
						'sent_at'       => current_time( 'mysql', true ),
						'error_message' => null,
					],
					[ '%d', '%d', '%s', '%s', '%s', '%s' ]
				);

				$log_id       = (int) $wpdb->insert_id;
				$tracking_url = ( $tracking_on && $log_id > 0 )
					? self::get_open_tracking_url( $campaign_id, (int) $subscriber['id'], $log_id, $subscriber['email'] )
					: null;
				$click_context = ( $tracking_on && $log_id > 0 )
					? [
						'campaign_id'   => $campaign_id,
						'subscriber_id' => (int) $subscriber['id'],
						'log_id'        => $log_id,
						'email'         => $subscriber['email'],
					]
					: [];

				$html = self::render_email_html(
					$content,
					$subject,
					$subscriber['email'],
					$subscriber['name'] ?? '',
					$campaign,
					$tracking_url,
					$click_context,
					$subscriber
				);

				$result = wp_mail(
					$subscriber['email'],
					$subject,
					$html,
					$headers
				);

				if ( $log_id > 0 ) {
					$failure_message = __( 'wp_mail() returned false', 'antimanual' );
					$wpdb->update(
						$log_table,
						[
							'status'        => $result ? 'sent' : 'failed',
							'sent_at'       => current_time( 'mysql', true ),
							'error_message' => $result ? null : $failure_message,
						],
						[ 'id' => $log_id ],
						[ '%s', '%s', '%s' ],
						[ '%d' ]
					);
				}

				if ( $result ) {
					$sent++;
				} elseif ( $auto_remove_failed ) {
					$subscriber_ids_to_remove[] = (int) $subscriber['id'];
				}
			}

			$offset += $batch;

			// Pause between batches to avoid hitting server rate limits.
			if ( $batch_delay > 0 && ! empty( $subscribers ) ) {
				sleep( $batch_delay );
			}
		}

		if ( $auto_remove_failed && ! empty( $subscriber_ids_to_remove ) ) {
			EmailSubscribers::delete( array_values( array_unique( array_filter( array_map( 'absint', $subscriber_ids_to_remove ) ) ) ) );
		}

		return $sent;
	}

	/**
	 * Public accessor for mail headers — used by the resend-failed endpoint.
	 *
	 * @return string[]
	 */
	public static function get_resend_headers() {
		return self::get_mail_headers();
	}

	/**
	 * Public accessor for rendering email HTML — used by the resend-failed endpoint.
	 *
	 * @param string $content         Email body content.
	 * @param string $subject         Email subject.
	 * @param string $recipient_email Recipient email address.
	 * @param string $recipient_name  Recipient display name.
	 * @param array  $campaign        Campaign data array.
	 * @return string Rendered HTML.
	 */
	public static function render_resend_html( $content, $subject, $recipient_email, $recipient_name, array $campaign, $tracking_url = null, array $click_context = [], array $subscriber_data = [] ) {
		return self::render_email_html( $content, $subject, $recipient_email, $recipient_name, $campaign, $tracking_url, $click_context, $subscriber_data );
	}

	/**
	 * Build standard email headers for campaign sends.
	 *
	 * Uses custom from_name and reply_to from email settings when configured.
	 *
	 * @return string[]
	 */
	private static function get_mail_headers() {
		$settings    = get_option( 'atml_email_settings', [] );
		$from_name   = self::sanitize_mail_header_text( ! empty( $settings['from_name'] ) ? $settings['from_name'] : get_bloginfo( 'name' ) );
		$admin_email = sanitize_email( get_bloginfo( 'admin_email' ) );
		$reply_to    = sanitize_email( ! empty( $settings['reply_to'] ) ? $settings['reply_to'] : $admin_email );

		if ( ! is_email( $admin_email ) ) {
			$admin_email = sanitize_email( get_option( 'admin_email', '' ) );
		}

		if ( ! is_email( $admin_email ) ) {
			$admin_email = 'wordpress@localhost';
		}

		if ( '' === $from_name ) {
			$from_name = self::sanitize_mail_header_text( get_bloginfo( 'name' ) ) ?: 'WordPress';
		}

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$admin_email}>",
		];

		if ( is_email( $reply_to ) && $reply_to !== $admin_email ) {
			$headers[] = "Reply-To: {$from_name} <{$reply_to}>";
		}

		return $headers;
	}

	/**
	 * Check whether failed-recipient cleanup is enabled for Pro Campaign.
	 *
	 * @return bool
	 */
	public static function should_auto_remove_failed_subscribers() {
		if ( ! function_exists( 'atml_is_pro_campaign' ) || ! atml_is_pro_campaign() ) {
			return false;
		}

		$settings = get_option( 'atml_email_settings', [] );

		return ! empty( $settings['auto_remove_failed_subscribers'] );
	}

	/**
	 * Remove a failed or bounced subscriber when the premium cleanup option is enabled.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return bool True when a row was deleted.
	 */
	public static function maybe_auto_remove_failed_subscriber( $subscriber_id ) {
		$subscriber_id = absint( $subscriber_id );

		if ( ! self::should_auto_remove_failed_subscribers() || ! $subscriber_id ) {
			return false;
		}

		return EmailSubscribers::delete( [ $subscriber_id ] ) > 0;
	}

	/**
	 * Sanitize text used in email headers.
	 *
	 * @param string $value Header text.
	 * @return string
	 */
	private static function sanitize_mail_header_text( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = str_replace( [ "\r", "\n" ], '', $value );

		return trim( $value );
	}

	/**
	 * Sanitize custom email template HTML while preserving full email-document tags.
	 *
	 * Unlike wp_kses_post(), this allows structural email markup such as <html>,
	 * <head>, <body>, <style>, and <meta> so custom templates remain usable.
	 * Script execution and unsupported tags are still stripped.
	 *
	 * @param string $html Raw custom template HTML.
	 * @return string|null
	 */
	public static function sanitize_custom_template_html( $html ) {
		$html = trim( (string) $html );

		if ( '' === $html ) {
			return null;
		}

		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['html'] = [
			'lang'  => true,
			'dir'   => true,
			'xmlns' => true,
		];
		$allowed['head'] = [];
		$allowed['body'] = [
			'class'   => true,
			'id'      => true,
			'style'   => true,
			'bgcolor' => true,
			'dir'     => true,
			'lang'    => true,
		];
		$allowed['meta'] = [
			'charset'    => true,
			'name'       => true,
			'content'    => true,
			'http-equiv' => true,
		];
		$allowed['title'] = [];
		$allowed['style'] = [
			'type'  => true,
			'media' => true,
		];
		$allowed['link'] = [
			'href'  => true,
			'rel'   => true,
			'type'  => true,
			'media' => true,
		];
		$allowed['table'] = array_merge( $allowed['table'] ?? [], [
			'role'        => true,
			'width'       => true,
			'height'      => true,
			'align'       => true,
			'cellpadding' => true,
			'cellspacing' => true,
			'border'      => true,
			'bgcolor'     => true,
		] );
		$allowed['tr'] = array_merge( $allowed['tr'] ?? [], [
			'align'   => true,
			'valign'  => true,
			'bgcolor' => true,
			'height'  => true,
		] );
		$allowed['td'] = array_merge( $allowed['td'] ?? [], [
			'align'    => true,
			'valign'   => true,
			'bgcolor'  => true,
			'width'    => true,
			'height'   => true,
			'colspan'  => true,
			'rowspan'  => true,
		] );
		$allowed['th'] = array_merge( $allowed['th'] ?? [], [
			'align'    => true,
			'valign'   => true,
			'bgcolor'  => true,
			'width'    => true,
			'height'   => true,
			'colspan'  => true,
			'rowspan'  => true,
		] );
		$allowed['tbody']    = $allowed['tbody'] ?? [];
		$allowed['thead']    = $allowed['thead'] ?? [];
		$allowed['tfoot']    = $allowed['tfoot'] ?? [];
		$allowed['colgroup'] = $allowed['colgroup'] ?? [];
		$allowed['col']      = array_merge( $allowed['col'] ?? [], [ 'span' => true, 'width' => true ] );

		return trim( wp_kses( $html, $allowed ) );
	}

	/**
	 * Render the final HTML email for a specific recipient.
	 *
	 * @param string $content         Campaign body content.
	 * @param string $subject         Campaign subject.
	 * @param string $recipient_email Recipient email address.
	 * @param string $recipient_name  Recipient display name.
	 * @param array  $campaign        Campaign settings.
	 * @return string
	 */
	private static function render_email_html( $content, $subject, $recipient_email, $recipient_name, array $campaign, $tracking_url = null, array $click_context = [], array $subscriber_data = [] ) {
		$campaign_id     = absint( $campaign['id'] ?? 0 );
		$unsubscribe_url = self::get_unsubscribe_url( $recipient_email, $campaign_id );
		$custom_fields   = is_array( $subscriber_data['custom_fields'] ?? null ) ? $subscriber_data['custom_fields'] : [];
		$full_name       = $recipient_name ?: 'Subscriber';
		$name_parts      = explode( ' ', $full_name, 2 );
		$first_name      = $name_parts[0];
		$last_name       = $name_parts[1] ?? '';
		$replacements    = [
			'{{name}}'       => esc_html( $full_name ),
			'{{first_name}}' => esc_html( $first_name ),
			'{{last_name}}'  => esc_html( $last_name ),
			'{{email}}'      => esc_html( $recipient_email ),
		];

		foreach ( $custom_fields as $key => $value ) {
			$placeholder = '{{' . sanitize_key( $key ) . '}}';

			if ( '{{}}' === $placeholder ) {
				continue;
			}

			$replacements[ $placeholder ] = esc_html( (string) $value );
		}

		$personalized = strtr( $content, $replacements );

		if ( ! empty( $click_context['campaign_id'] ) && ! empty( $click_context['subscriber_id'] ) && ! empty( $click_context['log_id'] ) && ! empty( $click_context['email'] ) ) {
			$personalized = self::instrument_click_tracking(
				$personalized,
				(int) $click_context['campaign_id'],
				(int) $click_context['subscriber_id'],
				(int) $click_context['log_id'],
				(string) $click_context['email']
			);
		}

		// Build footer tracking links to pass into templates.
		$footer_links = [];

		if ( ! empty( $click_context['campaign_id'] ) && ! empty( $click_context['subscriber_id'] ) && ! empty( $click_context['log_id'] ) && ! empty( $click_context['email'] ) ) {
			$footer_links['forward_url'] = self::get_forward_tracking_url(
				(int) $click_context['campaign_id'],
				(int) $click_context['subscriber_id'],
				(int) $click_context['log_id'],
				(string) $click_context['email']
			);
			$footer_links['spam_report_url'] = self::get_spam_report_url(
				(int) $click_context['campaign_id'],
				(int) $click_context['subscriber_id'],
				(int) $click_context['log_id'],
				(string) $click_context['email']
			);
		}

		if ( 'custom' === ( $campaign['template_id'] ?? '' ) && ! empty( $campaign['custom_template_html'] ) ) {
			return self::apply_custom_template(
				$personalized,
				$subject,
				$unsubscribe_url,
				$campaign['custom_template_html'],
				$campaign['preview_text'] ?? '',
				$tracking_url,
				$footer_links
			);
		}

		return self::wrap_in_template(
			$personalized,
			$subject,
			$unsubscribe_url,
			$campaign['template_id'] ?? 'minimal',
			$campaign['preview_text'] ?? '',
			$tracking_url,
			$footer_links
		);
	}

	/**
	 * Apply placeholders to a user-authored custom template.
	 *
	 * Supports the same placeholder tokens as built-in templates so custom
	 * designs can reference {{content}}, {{site_name}}, {{unsubscribe_url}}, etc.
	 *
	 * @param string      $content       Personalised email body HTML.
	 * @param string      $subject       Campaign subject (used for <title>).
	 * @param string      $unsubscribe   Unsubscribe URL.
	 * @param string      $template_html Raw custom template HTML.
	 * @param string      $preview_text  Preview text snippet.
	 * @param string|null $tracking_url  Open-tracking pixel URL or null.
	 * @param array       $footer_links  Optional footer tracking URLs (forward_url, spam_report_url).
	 * @return string Rendered HTML.
	 */
	private static function apply_custom_template( $content, $subject, $unsubscribe, $template_html, $preview_text = '', $tracking_url = null, array $footer_links = [] ) {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$site_url  = esc_url( home_url() );
		$year      = gmdate( 'Y' );

		$preview_html = $preview_text
			? '<span style="display:none;font-size:0;line-height:0;max-height:0;mso-hide:all;overflow:hidden;">' . esc_html( $preview_text ) . '</span>'
			: '';

		// Append tracking pixel if provided.
		if ( ! empty( $tracking_url ) ) {
			$content .= '<div style="display:none;max-height:0;overflow:hidden;"><img src="' . esc_url( $tracking_url ) . '" alt="" width="1" height="1" style="display:block;border:0;outline:none;text-decoration:none;width:1px;height:1px;" /></div>';
		}

		// Load custom header/footer from email settings (same as built-in templates).
		$settings   = get_option( 'atml_email_settings', [] );
		$header_raw = wp_kses_post( $settings['header_html'] ?? '' );
		$footer_raw = wp_kses_post( $settings['footer_html'] ?? '' );

		$shortcode_map = [
			'{{site_name}}'       => $site_name,
			'{{site_url}}'        => $site_url,
			'{{year}}'            => $year,
			'{{unsubscribe_url}}' => esc_url( $unsubscribe ),
		];

		$header_content = $header_raw
			? strtr( $header_raw, $shortcode_map )
			: '<a href="' . $site_url . '" style="font-size:20px;font-weight:700;color:inherit;text-decoration:none;">' . $site_name . '</a>';

		// Build footer links row (Unsubscribe | Forward | Report Spam).
		$footer_link_parts = [];
		$footer_link_parts[] = '<a href="' . esc_url( $unsubscribe ) . '" style="color:inherit;text-decoration:underline;">Unsubscribe</a>';

		if ( ! empty( $footer_links['forward_url'] ) ) {
			$footer_link_parts[] = '<a href="' . esc_url( $footer_links['forward_url'] ) . '" style="color:inherit;text-decoration:underline;">Forward</a>';
		}

		if ( ! empty( $footer_links['spam_report_url'] ) ) {
			$footer_link_parts[] = '<a href="' . esc_url( $footer_links['spam_report_url'] ) . '" style="color:inherit;text-decoration:underline;">Report Spam</a>';
		}

		$footer_links_html = implode( ' &middot; ', $footer_link_parts );

		$footer_content = $footer_raw
			? strtr( $footer_raw, $shortcode_map )
			: '<p style="margin:0;">&copy; ' . $year . ' ' . $site_name . '</p>'
				. '<p style="margin:8px 0 0;">' . $footer_links_html . '</p>';

		// The raw custom template is treated as trusted admin HTML (wp_kses_post was
		// applied on storage) so we only do placeholder substitution here.
		return str_replace(
			[ '{{preview_text}}', '{{site_name}}', '{{site_url}}', '{{header_content}}', '{{content}}', '{{footer_content}}', '{{unsubscribe_url}}', '{{year}}' ],
			[ $preview_html, $site_name, $site_url, $header_content, $content, $footer_content, esc_url( $unsubscribe ), $year ],
			$template_html
		);
	}

	/**
	 * Generate email content using AI.
	 *
	 * @param string $topic               Topic for the email.
	 * @param string $tone                Writing tone.
	 * @param string $language            Email language.
	 * @param bool   $include_recent_posts Whether to include recent posts.
	 * @param int    $recent_posts_count   Number of recent posts to include.
	 * @return array|\WP_Error [ 'subject' => '', 'content' => '' ]
	 */
	public static function generate_email_content( $topic, $tone = 'professional', $language = 'English', $include_recent_posts = false, $recent_posts_count = 3, $knowledge_context = '', $uploaded_context = '' ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$posts_section = '';
		if ( $include_recent_posts ) {
			$recent_posts = get_posts( [
				'numberposts'            => $recent_posts_count,
				'post_status'            => 'publish',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			] );

			if ( ! empty( $recent_posts ) ) {
				$posts_section = "\n\nInclude these recent blog posts as a 'Latest Posts' section in the newsletter:\n";
				foreach ( $recent_posts as $post ) {
					$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );
					$posts_section .= "- Title: {$post->post_title}\n  URL: " . get_permalink( $post->ID ) . "\n  Summary: {$excerpt}\n";
				}
			}
		}

		$knowledge_context = trim( (string) $knowledge_context );
		$uploaded_context  = trim( (string) $uploaded_context );

		if ( strlen( $knowledge_context ) > 12000 ) {
			$knowledge_context = substr( $knowledge_context, 0, 12000 ) . "\n[...knowledge context truncated...]";
		}

		if ( strlen( $uploaded_context ) > 12000 ) {
			$uploaded_context = substr( $uploaded_context, 0, 12000 ) . "\n[...uploaded file context truncated...]";
		}

		$knowledge_section = '';
		if ( '' !== $knowledge_context ) {
			$knowledge_section = "\n\nUse the following knowledge-base context as source material. Prefer it over general assumptions and do not invent unsupported details:\n{$knowledge_context}";
		}

		$uploaded_section = '';
		if ( '' !== $uploaded_context ) {
			$uploaded_section = "\n\nUse the following uploaded file content as additional source material. Only include claims supported by this content:\n{$uploaded_context}";
		}

		$prompt = "You are an expert email marketer writing a newsletter email for \"{$site_name}\" ({$site_url}).

Topic: {$topic}
Tone: {$tone}
Language: {$language}
{$posts_section}
{$knowledge_section}
{$uploaded_section}

Generate a well-structured marketing email that:
1. Has an engaging, concise subject line (under 60 characters)
2. Starts with a compelling opening hook
3. Has clear, scannable content with short paragraphs
4. Includes a clear call-to-action
5. Is personalized — use {{name}} as placeholder for the subscriber's name
6. Uses plain HTML for formatting (paragraphs, headings, bold, links)
7. Is between 150-400 words

Respond ONLY in this exact JSON format:
{
  \"subject\": \"Your email subject line here\",
  \"content\": \"<p>Your HTML email content here...</p>\"
}";

		try {
			$response = AIProvider::get_reply( [
				[
					'role'    => 'user',
					'content' => $prompt,
				],
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( is_array( $response ) && isset( $response['error'] ) ) {
				return new \WP_Error( 'ai_error', $response['error'] );
			}

			// Parse JSON from response.
			$response = is_string( $response ) ? $response : '';
			$response = preg_replace( '/^```json\s*/i', '', $response );
			$response = preg_replace( '/\s*```$/i', '', $response );

			$parsed = json_decode( $response, true );

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $parsed['subject'] ) || empty( $parsed['content'] ) ) {
				return new \WP_Error( 'parse_error', __( 'Failed to parse AI response.', 'antimanual' ) );
			}

			return [
				'subject' => sanitize_text_field( $parsed['subject'] ),
				'content' => wp_kses_post( $parsed['content'] ),
			];
		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Generate AI subject line suggestions.
	 *
	 * @param string $content Email content or topic.
	 * @param int    $count   Number of suggestions.
	 * @return array|\WP_Error
	 */
	public static function generate_subject_lines( $content, $count = 5 ) {
		$prompt = "Generate {$count} compelling email subject lines for this email content/topic. Subject lines should be:
- Under 60 characters
- Engaging and curiosity-inducing
- Varied in style (question, statement, number-based, urgency, etc.)

Content/Topic: {$content}

Respond with just the subject lines, one per line, no numbering or bullets.";

		try {
			$response = AIProvider::get_reply( [
				[
					'role'    => 'user',
					'content' => $prompt,
				],
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( is_array( $response ) && isset( $response['error'] ) ) {
				return new \WP_Error( 'ai_error', $response['error'] );
			}

			$lines = array_filter(
				array_map( 'trim', explode( "\n", $response ) ),
				fn( $line ) => ! empty( $line ) && strlen( $line ) > 5
			);

			return array_values( array_map( function ( $line ) {
				return preg_replace( '/^[\d\.\-\*\)]+\s*/', '', $line );
			}, $lines ) );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Create a campaign.
	 *
	 * @param array $data Campaign data.
	 * @return array|\WP_Error Created campaign data.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();
		EmailCampaignsDB::ensure_tables_exist();

		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( empty( $name ) ) {
			return new \WP_Error( 'empty_name', __( 'Campaign name is required.', 'antimanual' ) );
		}

		$now    = current_time( 'mysql', true );
		$now_ts = current_time( 'timestamp', true );

		$schedule_type  = in_array( $data['schedule_type'] ?? 'immediate', [ 'immediate', 'scheduled', 'recurring' ], true )
			? $data['schedule_type'] : 'immediate';

		// Calculate next_send_at based on schedule type.
		$next_send_at = null;
		$status       = 'draft';
		$scheduled_at = null;

		if ( $schedule_type === 'immediate' && ! empty( $data['send_now'] ) ) {
			$next_send_at = $now;
			$status       = 'scheduled';
		} elseif ( $schedule_type === 'scheduled' ) {
			$scheduled_at = self::normalize_schedule_datetime( $data['scheduled_at'] ?? '' );

			if ( empty( $scheduled_at ) ) {
				return new \WP_Error( 'invalid_schedule', __( 'Please provide a valid scheduled date and time.', 'antimanual' ) );
			}

			if ( strtotime( $scheduled_at . ' UTC' ) <= $now_ts ) {
				return new \WP_Error( 'invalid_schedule', __( 'Scheduled date/time must be in the future.', 'antimanual' ) );
			}

			$next_send_at = $scheduled_at;
			$status       = 'scheduled';
		} elseif ( $schedule_type === 'recurring' ) {
			$next_send_at = self::calculate_next_send( $data );
			$status       = ! empty( $next_send_at ) ? 'scheduled' : 'draft';
		}

		// Sanitize target lists and subscription types.
		$target_lists_raw = $data['target_lists'] ?? '';
		if ( is_array( $target_lists_raw ) ) {
			$target_lists_raw = implode( ',', array_map( 'sanitize_key', $target_lists_raw ) );
		} else {
			$target_lists_raw = implode( ',', array_map( 'sanitize_key', array_filter( explode( ',', (string) $target_lists_raw ), 'strlen' ) ) );
		}

		$target_subscription_types_raw = $data['target_subscription_types'] ?? '';
		if ( is_array( $target_subscription_types_raw ) ) {
			$target_subscription_types_raw = implode( ',', array_map( 'sanitize_key', $target_subscription_types_raw ) );
		} else {
			$target_subscription_types_raw = implode( ',', array_map( 'sanitize_key', array_filter( explode( ',', (string) $target_subscription_types_raw ), 'strlen' ) ) );
		}

		$template_id           = sanitize_key( $data['template_id'] ?? 'minimal' );
		$custom_template_html  = self::sanitize_custom_template_html( $data['custom_template_html'] ?? '' );

		if ( 'custom' === $template_id && empty( $custom_template_html ) ) {
			return new \WP_Error( 'empty_custom_template', __( 'Custom template HTML is required when using the custom template.', 'antimanual' ) );
		}

		$wpdb->insert(
			$table,
			[
				'name'                 => $name,
				'subject'              => sanitize_text_field( $data['subject'] ?? '' ),
				'preview_text'         => sanitize_text_field( $data['preview_text'] ?? '' ),
				'content'              => wp_kses_post( $data['content'] ?? '' ),
				'status'               => $status,
				'schedule_type'        => $schedule_type,
				'scheduled_at'         => $scheduled_at,
				'recurrence'           => sanitize_key( $data['recurrence'] ?? '' ) ?: null,
				'recurrence_day'       => isset( $data['recurrence_day'] ) ? intval( $data['recurrence_day'] ) : null,
				'recurrence_time'      => sanitize_text_field( $data['recurrence_time'] ?? '' ) ?: null,
				'ai_topic'             => sanitize_textarea_field( $data['ai_topic'] ?? '' ),
				'ai_tone'              => sanitize_text_field( $data['ai_tone'] ?? 'professional' ),
				'ai_language'          => sanitize_text_field( $data['ai_language'] ?? 'English' ),
				'template_id'          => $template_id,
				'custom_template_html' => $custom_template_html,
				'include_recent_posts' => ! empty( $data['include_recent_posts'] ) ? 1 : 0,
				'recent_posts_count'   => intval( $data['recent_posts_count'] ?? 3 ),
				'target_lists'         => $target_lists_raw,
				'target_subscription_types' => $target_subscription_types_raw,
				'next_send_at'         => $next_send_at,
				'created_at'           => $now,
				'updated_at'           => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		$campaign_id = $wpdb->insert_id;

		if ( ! $campaign_id ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to create campaign.', 'antimanual' ) );
		}

		return self::get( $campaign_id );
	}

	/**
	 * Get a single campaign by ID.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|\WP_Error
	 */
	public static function get( $campaign_id ) {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();

		$campaign = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE id = %d LIMIT 1",
			$campaign_id
		), ARRAY_A );

		if ( ! $campaign ) {
			return new \WP_Error( 'not_found', __( 'Campaign not found.', 'antimanual' ) );
		}

		return $campaign;
	}

	/**
	 * List campaigns with pagination.
	 *
	 * @param array $args Query args: status, page, per_page, search.
	 * @return array [ 'items' => [], 'total' => int ]
	 */
	public static function list_campaigns( $args = [] ) {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();
		EmailCampaignsDB::ensure_tables_exist();

		$status   = sanitize_key( $args['status'] ?? '' );
		$search   = sanitize_text_field( $args['search'] ?? '' );
		$page     = max( 1, intval( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 50, intval( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [];
		$params = [];

		if ( $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(name LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM $table $where_clause";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: (int) $wpdb->get_var( $count_sql );

		$query = "SELECT id, name, subject, status, schedule_type, recurrence,
			total_sent, total_opened, total_clicked, last_sent_at, next_send_at,
			ai_topic, template_id, target_lists, target_subscription_types, created_at, updated_at
			FROM $table $where_clause
			ORDER BY created_at DESC LIMIT %d OFFSET %d";

		$query_params = array_merge( $params, [ $per_page, $offset ] );
		$items        = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

		return [
			'items' => $items ?: [],
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Update a campaign.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $data        Fields to update.
	 * @return array|\WP_Error Updated campaign.
	 */
	public static function update( $campaign_id, array $data ) {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();

		$existing = self::get( $campaign_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$update = [ 'updated_at' => current_time( 'mysql', true ) ];
		$format = [ '%s' ];

		if ( array_key_exists( 'scheduled_at', $data ) ) {
			$normalized_scheduled_at = self::normalize_schedule_datetime( $data['scheduled_at'] ?? '' );

			if ( ! empty( $data['scheduled_at'] ) && empty( $normalized_scheduled_at ) ) {
				return new \WP_Error( 'invalid_schedule', __( 'Please provide a valid scheduled date and time.', 'antimanual' ) );
			}

			$data['scheduled_at'] = $normalized_scheduled_at;
		}

		$allowed_fields = [
			'name'                 => '%s',
			'subject'              => '%s',
			'preview_text'         => '%s',
			'content'              => '%s',
			'status'               => '%s',
			'schedule_type'        => '%s',
			'scheduled_at'         => '%s',
			'recurrence'           => '%s',
			'recurrence_day'       => '%d',
			'recurrence_time'      => '%s',
			'ai_topic'             => '%s',
			'ai_tone'              => '%s',
			'ai_language'          => '%s',
			'template_id'          => '%s',
			'custom_template_html' => '%s',
			'include_recent_posts' => '%d',
			'recent_posts_count'   => '%d',
			'target_lists'         => '%s',
			'target_subscription_types' => '%s',
			'next_send_at'         => '%s',
		];

		foreach ( $allowed_fields as $field => $fmt ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			// Apply sanitization based on field.
			switch ( $field ) {
				case 'content':
					$update[ $field ] = ! empty( $data[ $field ] ) ? wp_kses_post( $data[ $field ] ) : null;
					break;
				case 'custom_template_html':
					$update[ $field ] = self::sanitize_custom_template_html( $data[ $field ] );
					break;
				case 'ai_topic':
					$update[ $field ] = sanitize_textarea_field( $data[ $field ] );
					break;
				case 'recurrence_day':
				case 'recent_posts_count':
					$update[ $field ] = intval( $data[ $field ] );
					break;
				case 'include_recent_posts':
					$update[ $field ] = ! empty( $data[ $field ] ) ? 1 : 0;
					break;
				case 'status':
					$valid = [ 'draft', 'scheduled', 'sending', 'sent', 'paused' ];
					$update[ $field ] = in_array( $data[ $field ], $valid, true ) ? $data[ $field ] : 'draft';
					break;
				case 'schedule_type':
					$valid = [ 'immediate', 'scheduled', 'recurring' ];
					$update[ $field ] = in_array( $data[ $field ], $valid, true ) ? $data[ $field ] : 'immediate';
					break;
				case 'target_lists':
				case 'target_subscription_types':
					$raw = $data[ $field ];
					if ( is_array( $raw ) ) {
						$update[ $field ] = implode( ',', array_map( 'sanitize_key', $raw ) );
					} else {
						$update[ $field ] = implode( ',', array_map( 'sanitize_key', array_filter( explode( ',', (string) $raw ), 'strlen' ) ) );
					}
					break;
				default:
					$update[ $field ] = sanitize_text_field( $data[ $field ] );
					break;
			}

			$format[] = $fmt;
		}

		$effective_template_id = sanitize_key( $update['template_id'] ?? $existing['template_id'] ?? 'minimal' );
		$effective_custom_html = $update['custom_template_html'] ?? $existing['custom_template_html'] ?? null;

		if ( 'custom' === $effective_template_id && empty( $effective_custom_html ) ) {
			return new \WP_Error( 'empty_custom_template', __( 'Custom template HTML is required when using the custom template.', 'antimanual' ) );
		}

		// Recalculate next_send_at if schedule params changed.
		$schedule_changed = isset( $data['schedule_type'] ) || isset( $data['scheduled_at'] )
			|| isset( $data['recurrence'] ) || isset( $data['recurrence_day'] ) || isset( $data['recurrence_time'] );

		if ( $schedule_changed ) {
			$merged = array_merge( $existing, $update );

			if ( $merged['schedule_type'] === 'scheduled' && ! empty( $merged['scheduled_at'] ) ) {
				$update['next_send_at'] = $merged['scheduled_at'];
				$format[] = '%s';
				$update['status'] = 'scheduled';
				$format[] = '%s';
			} elseif ( $merged['schedule_type'] === 'recurring' ) {
				$update['next_send_at'] = self::calculate_next_send( $merged );
				$format[] = '%s';
				$update['status'] = ! empty( $update['next_send_at'] ) ? 'scheduled' : 'draft';
				$format[] = '%s';
			} elseif ( $merged['schedule_type'] === 'immediate' ) {
				$update['next_send_at'] = null;
				$format[] = '%s';
			}
		}

		$wpdb->update(
			$table,
			$update,
			[ 'id' => $campaign_id ],
			$format,
			[ '%d' ]
		);

		return self::get( $campaign_id );
	}

	/**
	 * Delete a campaign and its send logs.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( $campaign_id ) {
		global $wpdb;

		$campaign_id = absint( $campaign_id );
		if ( ! $campaign_id ) {
			return new \WP_Error( 'invalid_id', __( 'Invalid campaign ID.', 'antimanual' ) );
		}

		// Delete send logs first.
		$log_table = EmailCampaignsDB::get_send_log_table();
		$wpdb->delete( $log_table, [ 'campaign_id' => $campaign_id ], [ '%d' ] );

		// Delete campaign.
		$campaigns_table = EmailCampaignsDB::get_campaigns_table();
		$deleted = $wpdb->delete( $campaigns_table, [ 'id' => $campaign_id ], [ '%d' ] );

		if ( ! $deleted ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete campaign.', 'antimanual' ) );
		}

		return true;
	}

	/**
	 * Send a campaign immediately (one-time trigger).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|\WP_Error
	 */
	public static function send_now( $campaign_id ) {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();

		$campaign = self::get( $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		// Update next_send_at to now and status to scheduled.
		$wpdb->update(
			$table,
			[
				'next_send_at' => current_time( 'mysql', true ),
				'status'       => 'scheduled',
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => $campaign_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		// Process it immediately.
		$instance = self::instance();
		$updated  = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, subject, content, ai_topic, ai_tone, ai_language,
				template_id, include_recent_posts, recent_posts_count,
				total_sent, schedule_type, recurrence, recurrence_day, recurrence_time, preview_text, target_lists, target_subscription_types
			FROM $table WHERE id = %d LIMIT 1",
			$campaign_id
		), ARRAY_A );

		if ( $updated ) {
			$instance->execute_campaign( $updated );
		}

		return self::get( $campaign_id );
	}

	/**
	 * Send a one-off test email without creating a campaign record.
	 *
	 * @param array  $campaign_data Campaign payload.
	 * @param string $test_email    Test recipient email.
	 * @return array|\WP_Error
	 */
	public static function send_test_email( array $campaign_data, $test_email ) {
		$test_email = sanitize_email( $test_email );

		if ( ! is_email( $test_email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Please enter a valid test email address.', 'antimanual' ) );
		}

		$subject = sanitize_text_field( $campaign_data['subject'] ?? '' );
		$content = wp_kses_post( $campaign_data['content'] ?? '' );

		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'empty_content', __( 'Email content is required before sending a test email.', 'antimanual' ) );
		}

		if ( '' === trim( $subject ) ) {
			$subject = __( 'Test Email', 'antimanual' );
		}

		$template_id          = sanitize_key( $campaign_data['template_id'] ?? 'minimal' );
		$custom_template_html = self::sanitize_custom_template_html( $campaign_data['custom_template_html'] ?? '' );

		if ( 'custom' === $template_id && empty( $custom_template_html ) ) {
			return new \WP_Error( 'empty_custom_template', __( 'Custom template HTML is required before sending a custom template test email.', 'antimanual' ) );
		}

		$html = self::render_email_html(
			$content,
			$subject,
			$test_email,
			__( 'Test Subscriber', 'antimanual' ),
			[
				'template_id'          => $template_id,
				'preview_text'         => sanitize_text_field( $campaign_data['preview_text'] ?? '' ),
				'custom_template_html' => $custom_template_html ?: '',
			]
		);

		$result = wp_mail( $test_email, $subject, $html, self::get_mail_headers() );

		if ( ! $result ) {
			return new \WP_Error( 'test_send_failed', __( 'The test email could not be sent.', 'antimanual' ) );
		}

		return [
			'email'   => $test_email,
			'subject' => $subject,
		];
	}

	/**
	 * Get campaign stats.
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();
		EmailCampaignsDB::ensure_tables_exist();

		$stats = $wpdb->get_row(
			"SELECT
				COUNT(*) as total_campaigns,
				SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
				SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as completed,
				SUM(total_sent) as total_emails_sent
			FROM $table",
			ARRAY_A
		);

		$subscriber_stats = EmailSubscribers::get_stats();

		return [
			'campaigns'          => $stats ?: [ 'total_campaigns' => 0, 'scheduled' => 0, 'completed' => 0, 'total_emails_sent' => 0 ],
			'subscribers'        => $subscriber_stats,
		];
	}

	/**
	 * Get the send history for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Limit.
	 * @return array
	 */
	public static function get_send_history( $campaign_id, $limit = 50 ) {
		global $wpdb;
		$log_table = EmailCampaignsDB::get_send_log_table();
		$sub_table = EmailSubscribers::get_table_name();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT l.id, l.subscriber_id, l.status, l.sent_at, l.opened_at, l.clicked_at,
				l.replied_at, l.forwarded_at, l.spam_reported_at, l.unsubscribed_at,
				l.error_message, s.email, s.name as subscriber_name
			FROM $log_table l
			LEFT JOIN $sub_table s ON l.subscriber_id = s.id
			WHERE l.campaign_id = %d
			ORDER BY l.sent_at DESC
			LIMIT %d",
			$campaign_id,
			$limit
		), ARRAY_A ) ?: [];
	}

	/**
	 * Calculate the next send datetime for a recurring campaign.
	 *
	 * @param array $campaign Campaign data.
	 * @return string|null MySQL datetime or null.
	 */
	public static function calculate_next_send( array $campaign ) {
		$recurrence      = $campaign['recurrence'] ?? '';
		$recurrence_day  = isset( $campaign['recurrence_day'] ) ? intval( $campaign['recurrence_day'] ) : 1;
		$recurrence_time = $campaign['recurrence_time'] ?? '09:00';

		if ( empty( $recurrence ) ) {
			return null;
		}

		$now  = current_time( 'timestamp', true );
		$time = explode( ':', $recurrence_time );
		$hour = intval( $time[0] ?? 9 );
		$min  = intval( $time[1] ?? 0 );

		switch ( $recurrence ) {
			case 'daily':
				$next = strtotime( 'tomorrow', $now );
				$next = mktime( $hour, $min, 0, (int) gmdate( 'n', $next ), (int) gmdate( 'j', $next ), (int) gmdate( 'Y', $next ) );
				break;

			case 'weekly':
				$day_names    = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
				$target_day   = $day_names[ $recurrence_day ] ?? 'Monday';
				$next         = strtotime( "next {$target_day}", $now );
				$next         = mktime( $hour, $min, 0, (int) gmdate( 'n', $next ), (int) gmdate( 'j', $next ), (int) gmdate( 'Y', $next ) );
				break;

			case 'biweekly':
				$day_names  = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
				$target_day = $day_names[ $recurrence_day ] ?? 'Monday';
				$next       = strtotime( "next {$target_day} +1 week", $now );
				$next       = mktime( $hour, $min, 0, (int) gmdate( 'n', $next ), (int) gmdate( 'j', $next ), (int) gmdate( 'Y', $next ) );
				break;

			case 'monthly':
				$next_month = strtotime( '+1 month', $now );
				$day        = min( $recurrence_day, (int) gmdate( 't', $next_month ) );
				$next       = mktime( $hour, $min, 0, (int) gmdate( 'n', $next_month ), $day, (int) gmdate( 'Y', $next_month ) );
				break;

			default:
				return null;
		}

		return gmdate( 'Y-m-d H:i:s', $next );
	}

	/**
	 * Normalize a scheduled datetime value to UTC MySQL format.
	 *
	 * Accepts HTML datetime-local values and MySQL datetimes.
	 *
	 * @param mixed $value Datetime input.
	 * @return string|null
	 */
	private static function normalize_schedule_datetime( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return null;
		}

		$value   = str_replace( 'T', ' ', $value );
		$formats = [ 'Y-m-d H:i:s', 'Y-m-d H:i' ];

		foreach ( $formats as $format ) {
			$datetime = \DateTime::createFromFormat( $format, $value, wp_timezone() );

			if ( $datetime instanceof \DateTime ) {
				$datetime->setTimezone( new \DateTimeZone( 'UTC' ) );
				return $datetime->format( 'Y-m-d H:i:s' );
			}
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Generate an unsubscribe URL.
	 *
	 * @param string $email       Subscriber email.
	 * @param int    $campaign_id Optional campaign ID for per-campaign tracking.
	 * @return string
	 */
	public static function get_unsubscribe_url( $email, $campaign_id = 0 ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return home_url( '/' );
		}

		$token = wp_hash( $email . 'atml_unsubscribe' );
		$args  = [
			'atml_unsubscribe' => '1',
			'email'            => $email,
			'token'            => $token,
		];

		$campaign_id = absint( $campaign_id );

		if ( $campaign_id > 0 ) {
			$args['campaign_id'] = $campaign_id;
		}

		return add_query_arg( $args, home_url() );
	}

	/**
	 * Generate a signed email-open tracking URL for a send log row.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return string
	 */
	public static function get_open_tracking_url( $campaign_id, $subscriber_id, $log_id, $email ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return home_url( '/' );
		}

		$token = self::get_open_tracking_token( $campaign_id, $subscriber_id, $log_id, $email );

		return add_query_arg( [
			'atml_email_open' => '1',
			'campaign_id'     => absint( $campaign_id ),
			'subscriber_id'   => absint( $subscriber_id ),
			'log_id'          => absint( $log_id ),
			'email'           => $email,
			'token'           => $token,
		], home_url( '/' ) );
	}

	/**
	 * Build the verification token for an email-open tracking request.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return string
	 */
	public static function get_open_tracking_token( $campaign_id, $subscriber_id, $log_id, $email ) {
		return wp_hash( implode( '|', [ absint( $campaign_id ), absint( $subscriber_id ), absint( $log_id ), sanitize_email( $email ), 'atml_email_open' ] ) );
	}

	/**
	 * Generate a signed email-click tracking URL for a send log row.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @param string $destination   Absolute destination URL.
	 * @return string
	 */
	public static function get_click_tracking_url( $campaign_id, $subscriber_id, $log_id, $email, $destination ) {
		$destination = self::normalize_click_destination( $destination );

		if ( '' === $destination ) {
			return home_url( '/' );
		}

		$token = self::get_click_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $destination );

		return add_query_arg( [
			'atml_email_click' => '1',
			'campaign_id'      => absint( $campaign_id ),
			'subscriber_id'    => absint( $subscriber_id ),
			'log_id'           => absint( $log_id ),
			'email'            => sanitize_email( $email ),
			'destination'      => $destination,
			'token'            => $token,
		], home_url( '/' ) );
	}

	/**
	 * Build the verification token for an email-click tracking request.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @param string $destination   Absolute destination URL.
	 * @return string
	 */
	public static function get_click_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $destination ) {
		return wp_hash( implode( '|', [ absint( $campaign_id ), absint( $subscriber_id ), absint( $log_id ), sanitize_email( $email ), self::normalize_click_destination( $destination ), 'atml_email_click' ] ) );
	}

	/**
	 * Verify an email-click tracking token.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @param string $destination   Absolute destination URL.
	 * @param string $token         Signed token.
	 * @return bool
	 */
	public static function verify_click_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $destination, $token ) {
		return hash_equals(
			self::get_click_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $destination ),
			(string) $token
		);
	}

	/**
	 * Verify an email-open tracking token.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @param string $token         Signed token.
	 * @return bool
	 */
	public static function verify_open_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $token ) {
		return hash_equals(
			self::get_open_tracking_token( $campaign_id, $subscriber_id, $log_id, $email ),
			(string) $token
		);
	}

	/**
	 * Register an email-open event for a delivery log row.
	 *
	 * Updates opened_at only once per log entry and increments the
	 * campaign's total_opened counter on the first recorded open.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return bool True when a new open was recorded.
	 */
	public static function register_open( $campaign_id, $subscriber_id, $log_id, $email ) {
		global $wpdb;

		$campaign_id   = absint( $campaign_id );
		$subscriber_id = absint( $subscriber_id );
		$log_id        = absint( $log_id );
		$email         = sanitize_email( $email );

		if ( ! $campaign_id || ! $subscriber_id || ! $log_id || ! is_email( $email ) ) {
			return false;
		}

		$log_table       = EmailCampaignsDB::get_send_log_table();
		$campaigns_table = EmailCampaignsDB::get_campaigns_table();
		$subscribers_tbl = EmailSubscribers::get_table_name();

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$log_table} l
			LEFT JOIN {$subscribers_tbl} s ON s.id = l.subscriber_id
			SET l.opened_at = %s
			WHERE l.id = %d
			AND l.campaign_id = %d
			AND l.subscriber_id = %d
			AND l.status = 'sent'
			AND l.opened_at IS NULL
			AND s.email = %s",
			current_time( 'mysql', true ),
			$log_id,
			$campaign_id,
			$subscriber_id,
			$email
		) );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		// Capture the user agent from the open-tracking pixel request.
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
			$wpdb->update(
				$log_table,
				[ 'user_agent' => mb_substr( $ua, 0, 500 ) ],
				[ 'id' => $log_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$campaigns_table}
			SET total_opened = total_opened + 1,
				updated_at = %s
			WHERE id = %d",
			current_time( 'mysql', true ),
			$campaign_id
		) );

		return true;
	}

	/**
	 * Register an email-click event for a delivery log row.
	 *
	 * Updates clicked_at only once per log entry and increments the
	 * campaign's total_clicked counter on the first recorded click.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return bool True when a new click was recorded.
	 */
	public static function register_click( $campaign_id, $subscriber_id, $log_id, $email ) {
		global $wpdb;

		$campaign_id   = absint( $campaign_id );
		$subscriber_id = absint( $subscriber_id );
		$log_id        = absint( $log_id );
		$email         = sanitize_email( $email );

		if ( ! $campaign_id || ! $subscriber_id || ! $log_id || ! is_email( $email ) ) {
			return false;
		}

		$log_table       = EmailCampaignsDB::get_send_log_table();
		$campaigns_table = EmailCampaignsDB::get_campaigns_table();
		$subscribers_tbl = EmailSubscribers::get_table_name();

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$log_table} l
			LEFT JOIN {$subscribers_tbl} s ON s.id = l.subscriber_id
			SET l.clicked_at = %s
			WHERE l.id = %d
			AND l.campaign_id = %d
			AND l.subscriber_id = %d
			AND l.status = 'sent'
			AND l.clicked_at IS NULL
			AND s.email = %s",
			current_time( 'mysql', true ),
			$log_id,
			$campaign_id,
			$subscriber_id,
			$email
		) );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		// Capture user agent if not already set by an earlier open event.
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$log_table} SET user_agent = %s WHERE id = %d AND user_agent IS NULL",
				mb_substr( $ua, 0, 500 ),
				$log_id
			) );
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$campaigns_table}
			SET total_clicked = total_clicked + 1,
				updated_at = %s
			WHERE id = %d",
			current_time( 'mysql', true ),
			$campaign_id
		) );

		return true;
	}

	/**
	 * Generate a signed forward tracking URL for a send log row.
	 *
	 * When the recipient clicks this, we record the forward and show
	 * a simple "forward to a friend" mailto compose prompt.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return string
	 */
	public static function get_forward_tracking_url( $campaign_id, $subscriber_id, $log_id, $email ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return home_url( '/' );
		}

		$token = self::get_forward_tracking_token( $campaign_id, $subscriber_id, $log_id, $email );

		return add_query_arg( [
			'atml_email_forward' => '1',
			'campaign_id'        => absint( $campaign_id ),
			'subscriber_id'      => absint( $subscriber_id ),
			'log_id'             => absint( $log_id ),
			'email'              => $email,
			'token'              => $token,
		], home_url( '/' ) );
	}

	/**
	 * Build the verification token for a forward tracking request.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return string
	 */
	public static function get_forward_tracking_token( $campaign_id, $subscriber_id, $log_id, $email ) {
		return wp_hash( implode( '|', [ absint( $campaign_id ), absint( $subscriber_id ), absint( $log_id ), sanitize_email( $email ), 'atml_email_forward' ] ) );
	}

	/**
	 * Verify a forward tracking token.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @param string $token         Signed token.
	 * @return bool
	 */
	public static function verify_forward_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $token ) {
		return hash_equals(
			self::get_forward_tracking_token( $campaign_id, $subscriber_id, $log_id, $email ),
			(string) $token
		);
	}

	/**
	 * Generate a signed spam-report tracking URL for a send log row.
	 *
	 * When the recipient clicks this, we record the spam report and
	 * unsubscribe them automatically.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return string
	 */
	public static function get_spam_report_url( $campaign_id, $subscriber_id, $log_id, $email ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return home_url( '/' );
		}

		$token = self::get_spam_report_token( $campaign_id, $subscriber_id, $log_id, $email );

		return add_query_arg( [
			'atml_spam_report' => '1',
			'campaign_id'      => absint( $campaign_id ),
			'subscriber_id'    => absint( $subscriber_id ),
			'log_id'           => absint( $log_id ),
			'email'            => $email,
			'token'            => $token,
		], home_url( '/' ) );
	}

	/**
	 * Build the verification token for a spam-report tracking request.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return string
	 */
	public static function get_spam_report_token( $campaign_id, $subscriber_id, $log_id, $email ) {
		return wp_hash( implode( '|', [ absint( $campaign_id ), absint( $subscriber_id ), absint( $log_id ), sanitize_email( $email ), 'atml_spam_report' ] ) );
	}

	/**
	 * Verify a spam-report tracking token.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @param string $token         Signed token.
	 * @return bool
	 */
	public static function verify_spam_report_token( $campaign_id, $subscriber_id, $log_id, $email, $token ) {
		return hash_equals(
			self::get_spam_report_token( $campaign_id, $subscriber_id, $log_id, $email ),
			(string) $token
		);
	}

	/**
	 * Register a forward event for a delivery log row.
	 *
	 * Updates forwarded_at only once per log entry and increments
	 * the campaign's total_forwarded counter.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return bool True when a new forward was recorded.
	 */
	public static function register_forward( $campaign_id, $subscriber_id, $log_id, $email ) {
		global $wpdb;

		$campaign_id   = absint( $campaign_id );
		$subscriber_id = absint( $subscriber_id );
		$log_id        = absint( $log_id );
		$email         = sanitize_email( $email );

		if ( ! $campaign_id || ! $subscriber_id || ! $log_id || ! is_email( $email ) ) {
			return false;
		}

		$log_table       = EmailCampaignsDB::get_send_log_table();
		$campaigns_table = EmailCampaignsDB::get_campaigns_table();
		$subscribers_tbl = EmailSubscribers::get_table_name();

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$log_table} l
			LEFT JOIN {$subscribers_tbl} s ON s.id = l.subscriber_id
			SET l.forwarded_at = %s
			WHERE l.id = %d
			AND l.campaign_id = %d
			AND l.subscriber_id = %d
			AND l.status = 'sent'
			AND l.forwarded_at IS NULL
			AND s.email = %s",
			current_time( 'mysql', true ),
			$log_id,
			$campaign_id,
			$subscriber_id,
			$email
		) );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$campaigns_table}
			SET total_forwarded = total_forwarded + 1,
				updated_at = %s
			WHERE id = %d",
			current_time( 'mysql', true ),
			$campaign_id
		) );

		return true;
	}

	/**
	 * Register a spam-report event for a delivery log row.
	 *
	 * Records spam_reported_at, increments total_spam_reported,
	 * and automatically unsubscribes the reporter.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return bool True when a new spam report was recorded.
	 */
	public static function register_spam_report( $campaign_id, $subscriber_id, $log_id, $email ) {
		global $wpdb;

		$campaign_id   = absint( $campaign_id );
		$subscriber_id = absint( $subscriber_id );
		$log_id        = absint( $log_id );
		$email         = sanitize_email( $email );

		if ( ! $campaign_id || ! $subscriber_id || ! $log_id || ! is_email( $email ) ) {
			return false;
		}

		$log_table       = EmailCampaignsDB::get_send_log_table();
		$campaigns_table = EmailCampaignsDB::get_campaigns_table();
		$subscribers_tbl = EmailSubscribers::get_table_name();

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$log_table} l
			LEFT JOIN {$subscribers_tbl} s ON s.id = l.subscriber_id
			SET l.spam_reported_at = %s
			WHERE l.id = %d
			AND l.campaign_id = %d
			AND l.subscriber_id = %d
			AND l.status = 'sent'
			AND l.spam_reported_at IS NULL
			AND s.email = %s",
			current_time( 'mysql', true ),
			$log_id,
			$campaign_id,
			$subscriber_id,
			$email
		) );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$campaigns_table}
			SET total_spam_reported = total_spam_reported + 1,
				updated_at = %s
			WHERE id = %d",
			current_time( 'mysql', true ),
			$campaign_id
		) );

		// Auto-unsubscribe the reporter.
		EmailSubscribers::unsubscribe( $email );

		return true;
	}

	/**
	 * Register a per-campaign unsubscribe event for a delivery log row.
	 *
	 * Called from the unsubscribe handler to track which campaign
	 * triggered the unsubscribe.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $email       Subscriber email.
	 * @return bool True when the unsubscribe was recorded against a campaign.
	 */
	public static function register_campaign_unsubscribe( $campaign_id, $email ) {
		global $wpdb;

		$campaign_id = absint( $campaign_id );
		$email       = sanitize_email( $email );

		if ( ! $campaign_id || ! is_email( $email ) ) {
			return false;
		}

		$log_table       = EmailCampaignsDB::get_send_log_table();
		$campaigns_table = EmailCampaignsDB::get_campaigns_table();
		$subscribers_tbl = EmailSubscribers::get_table_name();

		// Mark the most recent send log entry for this campaign/email.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$log_table} l
			LEFT JOIN {$subscribers_tbl} s ON s.id = l.subscriber_id
			SET l.unsubscribed_at = %s
			WHERE l.campaign_id = %d
			AND s.email = %s
			AND l.status = 'sent'
			AND l.unsubscribed_at IS NULL
			ORDER BY l.sent_at DESC
			LIMIT 1",
			current_time( 'mysql', true ),
			$campaign_id,
			$email
		) );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$campaigns_table}
			SET total_unsubscribed = total_unsubscribed + 1,
				updated_at = %s
			WHERE id = %d",
			current_time( 'mysql', true ),
			$campaign_id
		) );

		return true;
	}

	/**
	 * Register a reply event for a delivery log row.
	 *
	 * Intended for external email service webhooks (Mailgun, SendGrid)
	 * or manual marking via the admin UI.
	 *
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return bool True when a new reply was recorded.
	 */
	public static function register_reply( $campaign_id, $subscriber_id, $log_id, $email ) {
		global $wpdb;

		$campaign_id   = absint( $campaign_id );
		$subscriber_id = absint( $subscriber_id );
		$log_id        = absint( $log_id );
		$email         = sanitize_email( $email );

		if ( ! $campaign_id || ! $subscriber_id || ! $log_id || ! is_email( $email ) ) {
			return false;
		}

		$log_table       = EmailCampaignsDB::get_send_log_table();
		$campaigns_table = EmailCampaignsDB::get_campaigns_table();
		$subscribers_tbl = EmailSubscribers::get_table_name();

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$log_table} l
			LEFT JOIN {$subscribers_tbl} s ON s.id = l.subscriber_id
			SET l.replied_at = %s
			WHERE l.id = %d
			AND l.campaign_id = %d
			AND l.subscriber_id = %d
			AND l.status = 'sent'
			AND l.replied_at IS NULL
			AND s.email = %s",
			current_time( 'mysql', true ),
			$log_id,
			$campaign_id,
			$subscriber_id,
			$email
		) );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$campaigns_table}
			SET total_replied = total_replied + 1,
				updated_at = %s
			WHERE id = %d",
			current_time( 'mysql', true ),
			$campaign_id
		) );

		return true;
	}

	/**
	 * Rewrite HTML links to pass through signed click tracking URLs.
	 *
	 * @param string $content       HTML content.
	 * @param int    $campaign_id   Campaign ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $log_id        Send log ID.
	 * @param string $email         Subscriber email.
	 * @return string
	 */
	private static function instrument_click_tracking( $content, $campaign_id, $subscriber_id, $log_id, $email ) {
		if ( false === stripos( $content, '<a ' ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'/<a\b([^>]*?)href=("|\')(.*?)(\2)([^>]*)>/i',
			function ( $matches ) use ( $campaign_id, $subscriber_id, $log_id, $email ) {
				$destination = self::normalize_click_destination( html_entity_decode( $matches[3], ENT_QUOTES, 'UTF-8' ) );

				if ( '' === $destination ) {
					return $matches[0];
				}

				$tracked_url = self::get_click_tracking_url( $campaign_id, $subscriber_id, $log_id, $email, $destination );

				return '<a' . $matches[1] . 'href=' . $matches[2] . esc_url( $tracked_url ) . $matches[2] . $matches[5] . '>';
			},
			$content
		);
	}

	/**
	 * Normalize a click destination so it can be signed safely.
	 *
	 * @param string $destination Raw href value.
	 * @return string
	 */
	public static function normalize_click_destination( $destination ) {
		$destination = trim( (string) $destination );

		if ( '' === $destination ) {
			return '';
		}

		if ( preg_match( '/^(#|mailto:|tel:|sms:|javascript:)/i', $destination ) ) {
			return '';
		}

		if ( 0 === strpos( $destination, '//' ) ) {
			$destination = ( is_ssl() ? 'https:' : 'http:' ) . $destination;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $destination ) ) {
			$destination = home_url( '/' . ltrim( $destination, '/' ) );
		}

		return esc_url_raw( $destination, [ 'http', 'https' ] );
	}

	/**
	 * Verify an unsubscribe token.
	 *
	 * @param string $email Subscriber email.
	 * @param string $token Unsubscribe token.
	 * @return bool
	 */
	public static function verify_unsubscribe_token( $email, $token ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return false;
		}

		return hash_equals( wp_hash( $email . 'atml_unsubscribe' ), (string) $token );
	}

	/**
	 * Wrap email content in an HTML template.
	 *
	 * Custom header/footer from email settings are injected around the
	 * main content block so they appear inside every template.
	 *
	 * @param string $content       Email content.
	 * @param string $subject       Email subject.
	 * @param string $unsubscribe   Unsubscribe URL.
	 * @param string $template_id   Template ID.
	 * @param string $preview_text  Preview text.
	 * @param string|null $tracking_url Open-tracking pixel URL.
	 * @param array  $footer_links  Optional footer tracking URLs (forward_url, spam_report_url).
	 * @return string HTML email.
	 */
	public static function wrap_in_template( $content, $subject, $unsubscribe, $template_id = 'minimal', $preview_text = '', $tracking_url = null, array $footer_links = [] ) {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$site_url  = esc_url( home_url() );
		$year      = gmdate( 'Y' );

		$preview_html = $preview_text
			? '<span style="display:none;font-size:0;line-height:0;max-height:0;mso-hide:all;overflow:hidden;">' . esc_html( $preview_text ) . '</span>'
			: '';

		// Load custom header/footer from settings.
		$settings   = get_option( 'atml_email_settings', [] );
		$header_raw = wp_kses_post( $settings['header_html'] ?? '' );
		$footer_raw = wp_kses_post( $settings['footer_html'] ?? '' );

		// Shortcode map for resolving placeholders inside user HTML.
		$shortcode_map = [
			'{{site_name}}'       => $site_name,
			'{{site_url}}'        => $site_url,
			'{{year}}'            => $year,
			'{{unsubscribe_url}}' => esc_url( $unsubscribe ),
		];

		// Resolve header: use custom HTML or fall back to default site name link.
		$header_content = $header_raw
			? strtr( $header_raw, $shortcode_map )
			: '<a href="' . $site_url . '" style="font-size:20px;font-weight:700;color:inherit;text-decoration:none;">' . $site_name . '</a>';

		// Build footer links row (Unsubscribe | Forward | Report Spam).
		$footer_link_parts = [];
		$footer_link_parts[] = '<a href="' . esc_url( $unsubscribe ) . '" style="color:inherit;text-decoration:underline;">Unsubscribe</a>';

		if ( ! empty( $footer_links['forward_url'] ) ) {
			$footer_link_parts[] = '<a href="' . esc_url( $footer_links['forward_url'] ) . '" style="color:inherit;text-decoration:underline;">Forward</a>';
		}

		if ( ! empty( $footer_links['spam_report_url'] ) ) {
			$footer_link_parts[] = '<a href="' . esc_url( $footer_links['spam_report_url'] ) . '" style="color:inherit;text-decoration:underline;">Report Spam</a>';
		}

		$footer_links_html = implode( ' &middot; ', $footer_link_parts );

		// Resolve footer: use custom HTML or fall back to default copyright + links.
		$footer_content = $footer_raw
			? strtr( $footer_raw, $shortcode_map )
			: '<p style="margin:0;">&copy; ' . $year . ' ' . $site_name . '</p>'
				. '<p style="margin:8px 0 0;">' . $footer_links_html . '</p>';

		// Append tracking pixel to content if enabled.
		$wrapped_content = $content;

		if ( ! empty( $tracking_url ) ) {
			$wrapped_content .= '<div style="display:none;max-height:0;overflow:hidden;"><img src="' . esc_url( $tracking_url ) . '" alt="" width="1" height="1" style="display:block;border:0;outline:none;text-decoration:none;width:1px;height:1px;" /></div>';
		}

		$templates = self::get_templates();
		$template  = $templates[ $template_id ] ?? $templates['minimal'];

		return str_replace(
			[ '{{preview_text}}', '{{site_name}}', '{{site_url}}', '{{header_content}}', '{{content}}', '{{footer_content}}', '{{unsubscribe_url}}', '{{year}}' ],
			[ $preview_html, $site_name, $site_url, $header_content, $wrapped_content, $footer_content, esc_url( $unsubscribe ), $year ],
			$template['html']
		);
	}

	/**
	 * Get available email templates.
	 *
	 * @return array
	 */
	public static function get_templates() {
		return [
			'minimal' => [
				'id'          => 'minimal',
				'name'        => __( 'Minimal', 'antimanual' ),
				'description' => __( 'Clean and simple design with minimal styling.', 'antimanual' ),
				'html'        => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{{site_name}}</title></head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
{{preview_text}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;">
<tr><td style="padding:32px 40px 16px;text-align:center;">
{{header_content}}
</td></tr>
<tr><td style="padding:16px 40px 32px;color:#333;font-size:16px;line-height:1.6;">
{{content}}
</td></tr>
<tr><td style="padding:20px 40px;background-color:#f8f9fa;text-align:center;font-size:13px;color:#888;">
{{footer_content}}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
			],

			'modern' => [
				'id'          => 'modern',
				'name'        => __( 'Modern', 'antimanual' ),
				'description' => __( 'Contemporary design with a branded header.', 'antimanual' ),
				'html'        => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{{site_name}}</title></head>
<body style="margin:0;padding:0;background-color:#edf2f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
{{preview_text}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#edf2f7;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
<tr><td style="padding:32px 40px;background:linear-gradient(135deg,#0079FF 0%,#0DB9F2 100%);text-align:center;">
{{header_content}}
</td></tr>
<tr><td style="padding:32px 40px;color:#2d3748;font-size:16px;line-height:1.7;">
{{content}}
</td></tr>
<tr><td style="padding:24px 40px;border-top:1px solid #e2e8f0;text-align:center;font-size:13px;color:#a0aec0;">
{{footer_content}}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
			],

			'newsletter' => [
				'id'          => 'newsletter',
				'name'        => __( 'Newsletter', 'antimanual' ),
				'description' => __( 'Classic newsletter layout ideal for blog digests.', 'antimanual' ),
				'html'        => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{{site_name}}</title></head>
<body style="margin:0;padding:0;background-color:#f0f0f0;font-family:Georgia,\'Times New Roman\',serif;">
{{preview_text}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f0f0;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:4px;overflow:hidden;border:1px solid #e0e0e0;">
<tr><td style="padding:28px 40px;border-bottom:2px solid #1a1a2e;text-align:center;">
{{header_content}}
</td></tr>
<tr><td style="padding:32px 40px;color:#333;font-size:17px;line-height:1.8;">
{{content}}
</td></tr>
<tr><td style="padding:20px 40px;background-color:#1a1a2e;text-align:center;font-size:13px;color:#ccc;">
{{footer_content}}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
			],

			'bold' => [
				'id'          => 'bold',
				'name'        => __( 'Bold', 'antimanual' ),
				'description' => __( 'Dark header with a vibrant gradient accent bar.', 'antimanual' ),
				'html'        => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{{site_name}}</title></head>
<body style="margin:0;padding:0;background-color:#1a1a2e;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
{{preview_text}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#1a1a2e;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="height:4px;background:linear-gradient(90deg,#f43f5e,#ec4899,#a855f7);font-size:0;line-height:0;">&nbsp;</td></tr>
<tr><td style="padding:32px 40px 16px;text-align:center;">
{{header_content}}
</td></tr>
<tr><td style="padding:16px 40px 32px;color:#374151;font-size:16px;line-height:1.7;">
{{content}}
</td></tr>
<tr><td style="padding:20px 40px;background-color:#f3f4f6;text-align:center;font-size:13px;color:#6b7280;">
{{footer_content}}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
			],

			'elegant' => [
				'id'          => 'elegant',
				'name'        => __( 'Elegant', 'antimanual' ),
				'description' => __( 'Warm serif typography with gold accent. Great for lifestyle or luxury brands.', 'antimanual' ),
				'html'        => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{{site_name}}</title></head>
<body style="margin:0;padding:0;background-color:#faf9f6;font-family:Georgia,\'Times New Roman\',serif;">
{{preview_text}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#faf9f6;">
<tr><td align="center" style="padding:48px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:2px;overflow:hidden;border:1px solid #e8e4de;">
<tr><td style="padding:36px 44px 20px;text-align:center;border-bottom:1px solid #e8e4de;">
{{header_content}}
</td></tr>
<tr><td style="padding:32px 44px 36px;color:#3d3d3d;font-size:17px;line-height:1.8;">
{{content}}
</td></tr>
<tr><td style="padding:24px 44px;background-color:#f5f3ef;text-align:center;font-size:12px;color:#9c8c72;border-top:2px solid #c9a96e;">
{{footer_content}}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
			],

			'plain-text' => [
				'id'          => 'plain-text',
				'name'        => __( 'Plain Text', 'antimanual' ),
				'description' => __( 'No background styling, maximum deliverability. Looks like a personal email.', 'antimanual' ),
				'html'        => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{{site_name}}</title></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
{{preview_text}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:32px 20px;">
<table role="presentation" width="580" cellpadding="0" cellspacing="0">
<tr><td style="padding:0 0 20px;color:#111;font-size:15px;line-height:1.7;">
{{header_content}}
{{content}}
</td></tr>
<tr><td style="padding:16px 0 0;border-top:1px solid #e5e5e5;font-size:12px;color:#999;">
{{footer_content}}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
			],

			'compact' => [
				'id'          => 'compact',
				'name'        => __( 'Compact', 'antimanual' ),
				'description' => __( 'Condensed layout with tight spacing. Ideal for quick updates and alerts.', 'antimanual' ),
				'html'        => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{{site_name}}</title></head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
{{preview_text}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f2f5;">
<tr><td align="center" style="padding:28px 16px;">
<table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #d9dde3;">
<tr><td style="padding:18px 24px;background-color:#25293a;text-align:left;">
{{header_content}}
</td></tr>
<tr><td style="padding:20px 24px;color:#374151;font-size:14px;line-height:1.65;">
{{content}}
</td></tr>
<tr><td style="padding:14px 24px;background-color:#f8f9fa;text-align:center;font-size:12px;color:#9ca3af;">
{{footer_content}}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
			],
		];
	}

	/**
	 * Get user agent statistics for a campaign.
	 *
	 * Parses stored user agent strings into three categories:
	 * devices, email clients, and web browsers.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{ devices: array, email_clients: array, browsers: array }
	 */
	public static function get_user_agent_stats( $campaign_id ) {
		global $wpdb;

		$campaign_id = absint( $campaign_id );

		if ( ! $campaign_id ) {
			return [
				'devices'       => [],
				'email_clients' => [],
				'browsers'      => [],
			];
		}

		$log_table = EmailCampaignsDB::get_send_log_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$user_agents = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_agent FROM {$log_table} WHERE campaign_id = %d AND user_agent IS NOT NULL AND user_agent != ''",
			$campaign_id
		) );

		$devices = [
			'Mobile'   => 0,
			'Tablet'   => 0,
			'Computer' => 0,
		];

		$email_clients = [];
		$browsers      = [];

		foreach ( $user_agents as $ua ) {
			// --- Device type ---
			if ( preg_match( '/Mobile|Android.*Mobile|iPhone|iPod/i', $ua ) && ! preg_match( '/iPad|Tablet/i', $ua ) ) {
				$devices['Mobile']++;
			} elseif ( preg_match( '/iPad|Tablet|Android(?!.*Mobile)/i', $ua ) ) {
				$devices['Tablet']++;
			} else {
				$devices['Computer']++;
			}

			// --- Email client ---
			$client = self::parse_email_client( $ua );
			if ( $client ) {
				$email_clients[ $client ] = ( $email_clients[ $client ] ?? 0 ) + 1;
			}

			// --- Browser ---
			$browser = self::parse_browser( $ua );
			if ( $browser ) {
				$browsers[ $browser ] = ( $browsers[ $browser ] ?? 0 ) + 1;
			}
		}

		return [
			'devices'       => self::format_stats_array( $devices ),
			'email_clients' => self::format_stats_array( $email_clients ),
			'browsers'      => self::format_stats_array( $browsers ),
			'total'         => count( $user_agents ),
		];
	}

	/**
	 * Parse the email client from a user agent string.
	 *
	 * @param string $ua User agent string.
	 * @return string Email client name.
	 */
	private static function parse_email_client( $ua ) {
		$clients = [
			'Apple Mail'   => '/AppleWebKit.*Macintosh|Mail\\/[0-9]+/i',
			'Outlook'      => '/Microsoft Outlook|MSOffice/i',
			'Thunderbird'  => '/Thunderbird/i',
			'Gmail'        => '/GoogleImageProxy|Gmail/i',
			'Yahoo Mail'   => '/YahooMailProxy|Yahoo/i',
			'Lotus Notes'  => '/Lotus.?Notes/i',
		];

		foreach ( $clients as $name => $pattern ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $name;
			}
		}

		return 'Others';
	}

	/**
	 * Parse the browser name from a user agent string.
	 *
	 * @param string $ua User agent string.
	 * @return string Browser name.
	 */
	private static function parse_browser( $ua ) {
		// Order matters: Edge before Chrome, Chrome before Safari.
		$browsers = [
			'Edge'              => '/Edg(?:e|A|iOS)?\//i',
			'Opera'             => '/OPR\/|Opera/i',
			'Firefox'           => '/Firefox\//i',
			'Internet Explorer' => '/MSIE|Trident\//i',
			'Chrome'            => '/Chrome\//i',
			'Safari'            => '/Safari\//i',
		];

		foreach ( $browsers as $name => $pattern ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $name;
			}
		}

		return 'Others';
	}

	/**
	 * Format a name => count array into sorted label/value pairs.
	 *
	 * @param array $data Associative array of name => count.
	 * @return array[] Sorted list of [ 'label' => string, 'value' => int ].
	 */
	private static function format_stats_array( $data ) {
		arsort( $data );
		$result = [];

		foreach ( $data as $label => $value ) {
			if ( $value > 0 ) {
				$result[] = [
					'label' => $label,
					'value' => $value,
				];
			}
		}

		return $result;
	}
}
