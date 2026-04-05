<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Campaign Feature Bootstrap
 *
 * Keeps Email Campaign specific dependency loading, lifecycle setup,
 * and frontend request handlers out of the main plugin bootstrap file.
 *
 * @package Antimanual
 */
class EmailMarketingFeature {
	/**
	 * Singleton instance.
	 *
	 * @var EmailMarketingFeature|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return EmailMarketingFeature
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Ensure database helper classes are loaded.
	 *
	 * These files live outside the PSR-4 autoload path, so this feature owns
	 * their loading instead of the main plugin bootstrap.
	 *
	 * @return void
	 */
	public static function load_dependencies() {
		if ( ! class_exists( EmailSubscribers::class, false ) ) {
			require_once ANTIMANUAL_DIR . '/includes/database/email-subscribers.php';
		}

		if ( ! class_exists( EmailCampaignsDB::class, false ) ) {
			require_once ANTIMANUAL_DIR . '/includes/database/email-campaigns.php';
		}
	}

	/**
	 * Create or update Email Campaign tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		self::load_dependencies();

		EmailSubscribers::create_table();
		EmailCampaignsDB::create_tables();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		self::load_dependencies();

		add_action( 'plugins_loaded', [ $this, 'initialize_campaign_manager' ], 20 );
		add_action( 'template_redirect', [ $this, 'handle_unsubscribe' ] );
		add_action( 'template_redirect', [ $this, 'handle_email_open_tracking' ] );
		add_action( 'template_redirect', [ $this, 'handle_email_click_tracking' ] );
		add_action( 'template_redirect', [ $this, 'handle_email_forward_tracking' ] );
		add_action( 'template_redirect', [ $this, 'handle_spam_report' ] );
	}

	/**
	 * Initialize Email Campaign manager for Pro sites.
	 *
	 * @return void
	 */
	public function initialize_campaign_manager() {
		if ( atml_is_pro() ) {
			EmailCampaign::instance();
		}
	}

	/**
	 * Handle email unsubscribe requests.
	 * Listens for ?atml_unsubscribe=1&email=...&token=... on the frontend.
	 *
	 * @return void
	 */
	public function handle_unsubscribe() {
		if ( empty( $_GET['atml_unsubscribe'] ) ) {
			return;
		}

		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( empty( $email ) || empty( $token ) ) {
			wp_die(
				esc_html__( 'Invalid unsubscribe link.', 'antimanual' ),
				esc_html__( 'Unsubscribe', 'antimanual' ),
				[ 'response' => 400 ]
			);
		}

		if ( ! EmailCampaign::verify_unsubscribe_token( $email, $token ) ) {
			wp_die(
				esc_html__( 'Invalid or expired unsubscribe link.', 'antimanual' ),
				esc_html__( 'Unsubscribe', 'antimanual' ),
				[ 'response' => 403 ]
			);
		}

		// Track which campaign triggered the unsubscribe.
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;

		if ( $campaign_id > 0 ) {
			EmailCampaign::register_campaign_unsubscribe( $campaign_id, $email );
		}

		EmailSubscribers::unsubscribe( $email );

		wp_die(
			esc_html__( 'You have been successfully unsubscribed. You will no longer receive emails from us.', 'antimanual' ),
			esc_html__( 'Unsubscribed Successfully', 'antimanual' ),
			[ 'response' => 200 ]
		);
	}

	/**
	 * Handle email-forward tracking requests.
	 *
	 * Listens for ?atml_email_forward=1&campaign_id=...&subscriber_id=...&log_id=...&email=...&token=...
	 * Records the forward event and shows a simple page with a mailto link.
	 *
	 * @return void
	 */
	public function handle_email_forward_tracking() {
		if ( empty( $_GET['atml_email_forward'] ) ) {
			return;
		}

		$campaign_id   = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		$subscriber_id = isset( $_GET['subscriber_id'] ) ? absint( wp_unslash( $_GET['subscriber_id'] ) ) : 0;
		$log_id        = isset( $_GET['log_id'] ) ? absint( wp_unslash( $_GET['log_id'] ) ) : 0;
		$email         = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$token         = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if (
			$campaign_id
			&& $subscriber_id
			&& $log_id
			&& $email
			&& $token
			&& EmailCampaign::verify_forward_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $token )
		) {
			EmailCampaign::register_forward( $campaign_id, $subscriber_id, $log_id, $email );
		}

		$site_name = esc_html( get_bloginfo( 'name' ) );

		wp_die(
			sprintf(
				'<div style="text-align:center;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">'
				. '<h2 style="margin:0 0 12px;">%s</h2>'
				. '<p style="color:#666;margin:0 0 24px;">%s</p>'
				. '<a href="mailto:?subject=%s&body=%s" style="display:inline-block;padding:12px 28px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">%s</a>'
				. '</div>',
				esc_html__( 'Forward to a Friend', 'antimanual' ),
				/* translators: %s: site name */
				sprintf( esc_html__( 'Share this email from %s with someone you think would enjoy it.', 'antimanual' ), $site_name ),
				rawurlencode( sprintf( __( 'Check this out from %s', 'antimanual' ), $site_name ) ),
				rawurlencode( sprintf( __( 'I thought you might enjoy this email from %s.', 'antimanual' ), $site_name ) ),
				esc_html__( 'Open Email Composer', 'antimanual' )
			),
			esc_html__( 'Forward to a Friend', 'antimanual' ),
			[ 'response' => 200 ]
		);
	}

	/**
	 * Handle spam report tracking requests.
	 *
	 * Listens for ?atml_spam_report=1&campaign_id=...&subscriber_id=...&log_id=...&email=...&token=...
	 * Records the spam complaint and auto-unsubscribes the reporter.
	 *
	 * @return void
	 */
	public function handle_spam_report() {
		if ( empty( $_GET['atml_spam_report'] ) ) {
			return;
		}

		$campaign_id   = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		$subscriber_id = isset( $_GET['subscriber_id'] ) ? absint( wp_unslash( $_GET['subscriber_id'] ) ) : 0;
		$log_id        = isset( $_GET['log_id'] ) ? absint( wp_unslash( $_GET['log_id'] ) ) : 0;
		$email         = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$token         = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if (
			$campaign_id
			&& $subscriber_id
			&& $log_id
			&& $email
			&& $token
			&& EmailCampaign::verify_spam_report_token( $campaign_id, $subscriber_id, $log_id, $email, $token )
		) {
			EmailCampaign::register_spam_report( $campaign_id, $subscriber_id, $log_id, $email );
		}

		wp_die(
			esc_html__( 'Your report has been recorded and you have been unsubscribed. You will no longer receive emails from us.', 'antimanual' ),
			esc_html__( 'Spam Report Received', 'antimanual' ),
			[ 'response' => 200 ]
		);
	}

	/**
	 * Handle email-open tracking pixel requests.
	 *
	 * Listens for ?atml_email_open=1&campaign_id=...&subscriber_id=...&log_id=...&email=...&token=...
	 * and records the first open for a sent email before returning a 1x1 GIF.
	 *
	 * @return void
	 */
	public function handle_email_open_tracking() {
		if ( empty( $_GET['atml_email_open'] ) ) {
			return;
		}

		$campaign_id   = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		$subscriber_id = isset( $_GET['subscriber_id'] ) ? absint( wp_unslash( $_GET['subscriber_id'] ) ) : 0;
		$log_id        = isset( $_GET['log_id'] ) ? absint( wp_unslash( $_GET['log_id'] ) ) : 0;
		$email         = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$token         = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if (
			$campaign_id
			&& $subscriber_id
			&& $log_id
			&& $email
			&& $token
			&& EmailCampaign::verify_open_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $token )
		) {
			EmailCampaign::register_open( $campaign_id, $subscriber_id, $log_id, $email );
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
		exit;
	}

	/**
	 * Handle tracked email click redirects.
	 *
	 * Listens for ?atml_email_click=1&campaign_id=...&subscriber_id=...&log_id=...&email=...&token=...&destination=...
	 * and records the first click before redirecting the visitor to the original destination.
	 *
	 * @return void
	 */
	public function handle_email_click_tracking() {
		if ( empty( $_GET['atml_email_click'] ) ) {
			return;
		}

		$campaign_id   = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		$subscriber_id = isset( $_GET['subscriber_id'] ) ? absint( wp_unslash( $_GET['subscriber_id'] ) ) : 0;
		$log_id        = isset( $_GET['log_id'] ) ? absint( wp_unslash( $_GET['log_id'] ) ) : 0;
		$email         = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$token         = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$destination   = isset( $_GET['destination'] ) ? rawurldecode( wp_unslash( $_GET['destination'] ) ) : '';

		$normalized_destination = EmailCampaign::normalize_click_destination( $destination );

		if ( ! $normalized_destination ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if (
			$campaign_id
			&& $subscriber_id
			&& $log_id
			&& $email
			&& $token
			&& EmailCampaign::verify_click_tracking_token( $campaign_id, $subscriber_id, $log_id, $email, $normalized_destination, $token )
		) {
			EmailCampaign::register_click( $campaign_id, $subscriber_id, $log_id, $email );
		}

		wp_safe_redirect( $normalized_destination );
		exit;
	}
}