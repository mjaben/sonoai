<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\EmailCampaign;
use Antimanual\EmailSubscribers;

/**
 * Email Campaign REST API Controller
 *
 * Handles all REST endpoints for the email campaign feature.
 *
 * @package Antimanual
 */
class EmailMarketingController {
	/**
	 * Register REST routes for email campaigns.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace ) {
		// Campaign endpoints.
		register_rest_route( $namespace, '/email-marketing/campaigns', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_campaigns' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_campaign' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/bulk-delete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'bulk_delete_campaigns' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_campaign' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_campaign' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_campaign' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/send', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'send_campaign' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/history', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_campaign_history' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/progress', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_send_progress' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// AI generation endpoints.
		register_rest_route( $namespace, '/email-marketing/generate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_content' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
		] );

		register_rest_route( $namespace, '/email-marketing/generate-subject', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_subject' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/generate-image', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_image' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
		] );

		register_rest_route( $namespace, '/email-marketing/test-send', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'send_test_email' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		// Subscriber endpoints.
		register_rest_route( $namespace, '/email-marketing/subscribers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_subscribers' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'add_subscriber' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_subscribers' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/delete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'delete_subscribers' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/import', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'import_subscribers' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/generate-names', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_subscriber_names' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/smart-extract', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'smart_extract_contacts' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/clean-names', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'clean_subscriber_names' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/lists/(?P<id>[\w-]+)/generate-names', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_subscriber_names_for_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/lists/(?P<id>[\w-]+)/clean-names', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'clean_subscriber_names_for_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_subscriber' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/bulk-status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'bulk_update_status' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/check-duplicates', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'check_duplicates' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/delete-unlisted', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_unlisted_subscribers' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/audience-count', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_audience_count' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// Subscriber list management.
		register_rest_route( $namespace, '/email-marketing/lists', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_subscriber_lists' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/lists', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_subscriber_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/lists/(?P<id>[\\w-]+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_subscriber_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/lists/(?P<id>[\\w-]+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_subscriber_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/lists/(?P<id>[\\w-]+)/contacts', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_list_contacts' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/lists/(?P<id>[\\w-]+)/delete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'delete_subscriber_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/assign-list', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'assign_subscribers_to_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/subscribers/remove-list', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'remove_subscribers_from_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// Stats.
		register_rest_route( $namespace, '/email-marketing/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// Templates.
		register_rest_route( $namespace, '/email-marketing/templates', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_templates' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// Saved custom templates (user-authored reusable templates).
		register_rest_route( $namespace, '/email-marketing/custom-templates', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_custom_templates' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/custom-templates', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_custom_template' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/custom-templates/(?P<template_id>[\w-]+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_custom_template' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// Email settings (header, footer, sender).
		register_rest_route( $namespace, '/email-marketing/settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_email_settings' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/settings', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_email_settings' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// Reports.
		register_rest_route( $namespace, '/email-marketing/reports', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_reports' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/resend-failed', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'resend_failed' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
		] );

		// Resend segment counts + resend to segment.
		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/resend-segments', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_resend_segments' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/resend-segment', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'resend_segment' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
			'args'                => [
				'segment' => [
					'required'          => true,
					'type'              => 'string',
					'enum'              => [ 'failed', 'not-opened', 'opened', 'clicked' ],
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/follow-up-draft', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_follow_up_draft' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'args'                => [
				'segment' => [
					'required'          => true,
					'type'              => 'string',
					'enum'              => [ 'failed', 'not-opened', 'opened', 'clicked' ],
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		register_rest_route( $namespace, '/email-marketing/subscriber-health', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_subscriber_health' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/user-agent-stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_user_agent_stats' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		// AI enhancement endpoints.
		register_rest_route( $namespace, '/email-marketing/rewrite-content', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rewrite_content' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
		] );

		register_rest_route( $namespace, '/email-marketing/generate-preview-text', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_preview_text' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/generate-campaign-name', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_campaign_name' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/generate-custom-template', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_custom_template' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
		] );

		register_rest_route( $namespace, '/email-marketing/check-spam-score', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'check_spam_score' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/resolve-spam-issue', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'resolve_spam_issue' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 120,
		] );

		register_rest_route( $namespace, '/email-marketing/campaigns/(?P<id>\d+)/ai-insights', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_ai_insights' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		// ─── Sequence (Drip Campaign) Endpoints ─────────────────────
		register_rest_route( $namespace, '/email-marketing/sequences', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_sequences' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_sequence' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_sequence' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_sequence' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_sequence' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/(?P<id>\d+)/activate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'activate_sequence' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/(?P<id>\d+)/duplicate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'duplicate_sequence' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/(?P<id>\d+)/pause', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'pause_sequence' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/generate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_sequence_content' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 180,
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/generate-step', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_sequence_step' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'timeout'             => 60,
		] );

		register_rest_route( $namespace, '/email-marketing/sequences/(?P<id>\d+)/progress', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_sequence_progress' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );
	}

	/**
	 * List campaigns.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function list_campaigns( $request ) {
		$result = EmailCampaign::list_campaigns( [
			'status'   => sanitize_key( $request->get_param( 'status' ) ?? '' ),
			'search'   => sanitize_text_field( $request->get_param( 'search' ) ?? '' ),
			'page'     => intval( $request->get_param( 'page' ) ?? 1 ),
			'per_page' => intval( $request->get_param( 'per_page' ) ?? 20 ),
		] );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Create a campaign.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function create_campaign( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];

		$campaign = EmailCampaign::create( $payload );

		if ( is_wp_error( $campaign ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $campaign->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $campaign,
		] );
	}

	/**
	 * Get a single campaign.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_campaign( $request ) {
		$campaign = EmailCampaign::get( intval( $request['id'] ) );

		if ( is_wp_error( $campaign ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $campaign->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $campaign,
		] );
	}

	/**
	 * Update a campaign.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function update_campaign( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];

		$campaign = EmailCampaign::update( intval( $request['id'] ), $payload );

		if ( is_wp_error( $campaign ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $campaign->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $campaign,
		] );
	}

	/**
	 * Delete a campaign.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_campaign( $request ) {
		$result = EmailCampaign::delete( intval( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Campaign deleted.', 'antimanual' ),
		] );
	}

	/**
	 * Bulk-delete multiple campaigns.
	 *
	 * @param \WP_REST_Request $request REST request containing 'ids' array.
	 * @return \WP_REST_Response
	 */
	public function bulk_delete_campaigns( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$ids     = array_map( 'absint', $payload['ids'] ?? [] );
		$ids     = array_filter( $ids );

		if ( empty( $ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No campaign IDs provided.', 'antimanual' ),
			] );
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			$result = EmailCampaign::delete( $id );
			if ( ! is_wp_error( $result ) ) {
				++$deleted;
			}
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'deleted' => $deleted ],
			'message' => sprintf(
				/* translators: %d: number of deleted campaigns */
				__( '%d campaign(s) deleted.', 'antimanual' ),
				$deleted
			),
		] );
	}

	/**
	 * Send a campaign now.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function send_campaign( $request ) {
		$result = EmailCampaign::send_now( intval( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Campaign sent successfully.', 'antimanual' ),
			'data'    => $result,
		] );
	}

	/**
	 * Get campaign send history.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_campaign_history( $request ) {
		$history = EmailCampaign::get_send_history( intval( $request['id'] ) );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $history,
		] );
	}

	/**
	 * Get live send progress for a campaign.
	 *
	 * Returns how many emails have been sent/failed so far plus the
	 * total target count. The frontend polls this every few seconds
	 * while a campaign has status "sending".
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_send_progress( $request ) {
		global $wpdb;

		$campaign_id = absint( $request['id'] );
		$campaigns_table = \Antimanual\EmailCampaignsDB::get_campaigns_table();
		$log_table       = \Antimanual\EmailCampaignsDB::get_send_log_table();

		// Get the campaign status and target lists.
		$campaign = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, target_lists FROM {$campaigns_table} WHERE id = %d LIMIT 1",
			$campaign_id
		), ARRAY_A );

		if ( ! $campaign ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Campaign not found.', 'antimanual' ),
			] );
		}

		// Count already-processed entries from the send log for this campaign.
		$log_counts = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) as processed,
				SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			FROM {$log_table}
			WHERE campaign_id = %d",
			$campaign_id
		), ARRAY_A );

		$processed = (int) ( $log_counts['processed'] ?? 0 );
		$sent      = (int) ( $log_counts['sent'] ?? 0 );
		$failed    = (int) ( $log_counts['failed'] ?? 0 );

		// Get total target subscribers.
		$target_lists = array_filter( explode( ',', $campaign['target_lists'] ?? '' ), 'strlen' );
		$total = ! empty( $target_lists )
			? \Antimanual\EmailSubscribers::get_active_count_by_lists( $target_lists )
			: \Antimanual\EmailSubscribers::get_active_count();

		$percentage = $total > 0 ? min( 100, round( ( $processed / $total ) * 100 ) ) : 0;

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'status'     => $campaign['status'],
				'total'      => $total,
				'processed'  => $processed,
				'sent'       => $sent,
				'failed'     => $failed,
				'percentage' => $percentage,
			],
		] );
	}

	/**
	 * AI-generate email content.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_content( $request ) {
		$topic              = sanitize_textarea_field( $request->get_param( 'topic' ) ?? '' );
		$tone               = sanitize_text_field( $request->get_param( 'tone' ) ?? 'professional' );
		$language           = sanitize_text_field( $request->get_param( 'language' ) ?? 'English' );
		$include_posts      = filter_var( $request->get_param( 'include_recent_posts' ) ?? false, FILTER_VALIDATE_BOOLEAN );
		$posts_count        = intval( $request->get_param( 'recent_posts_count' ) ?? 3 );
		$use_existing_knowledge = filter_var( $request->get_param( 'use_existing_knowledge' ) ?? false, FILTER_VALIDATE_BOOLEAN );
		$generate_images    = filter_var( $request->get_param( 'generate_images' ) ?? false, FILTER_VALIDATE_BOOLEAN );
		$image_count        = max( 1, min( 3, intval( $request->get_param( 'image_count' ) ?? 1 ) ) );
		$file_context       = $this->extract_uploaded_file_context( $request->get_file_params()['files'] ?? $_FILES['files'] ?? [] );
		$knowledge_context  = '';

		if ( empty( $topic ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Topic is required.', 'antimanual' ),
			] );
		}

		if ( $use_existing_knowledge ) {
			$knowledge_context = \Antimanual\KnowledgeContextBuilder::build_context( [], $topic );

			if ( '' === $knowledge_context ) {
				return rest_ensure_response( [
					'success' => false,
					'message' => __( 'No knowledge base content found. Add content to Knowledge Base or disable "Use Existing Knowledge Base".', 'antimanual' ),
				] );
			}
		}

		$result = EmailCampaign::generate_email_content(
			$topic,
			$tone,
			$language,
			$include_posts,
			$posts_count,
			$knowledge_context,
			$file_context
		);

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		// Generate and insert AI images into the content if requested.
		if ( $generate_images && ! empty( $result['content'] ) ) {
			$result['content'] = $this->generate_and_insert_images( $result['content'], $image_count );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Send a test email for the current campaign draft.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function send_test_email( $request ) {
		$payload = [
			'subject'              => $request->get_param( 'subject' ) ?? '',
			'preview_text'         => $request->get_param( 'preview_text' ) ?? '',
			'content'              => $request->get_param( 'content' ) ?? '',
			'template_id'          => $request->get_param( 'template_id' ) ?? 'minimal',
			'custom_template_html' => $request->get_param( 'custom_template_html' ) ?? '',
		];

		$result = EmailCampaign::send_test_email( $payload, $request->get_param( 'test_email' ) ?? '' );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Test email sent successfully.', 'antimanual' ),
			'data'    => $result,
		] );
	}

	/**
	 * AI-generate subject line suggestions.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_subject( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$content = sanitize_textarea_field( $payload['content'] ?? '' );

		if ( empty( $content ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Content or topic is required.', 'antimanual' ),
			] );
		}

		$result = EmailCampaign::generate_subject_lines( $content );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'subjects' => $result ],
		] );
	}

	/**
	 * Generate an AI image for use in email campaigns.
	 *
	 * Accepts a `prompt` for direct generation, or `content` + `topic`
	 * to auto-build a contextual prompt. Returns the generated image URL.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_image( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$prompt  = sanitize_textarea_field( $payload['prompt'] ?? '' );
		$content = sanitize_textarea_field( $payload['content'] ?? '' );
		$topic   = sanitize_text_field( $payload['topic'] ?? '' );

		// If no explicit prompt, generate one from email content/topic.
		if ( empty( $prompt ) ) {
			if ( empty( $content ) && empty( $topic ) ) {
				return rest_ensure_response( [
					'success' => false,
					'message' => __( 'Provide an image description, or write your email content first so we can suggest a relevant image.', 'antimanual' ),
				] );
			}

			$context = ! empty( $content )
				? wp_trim_words( wp_strip_all_tags( $content ), 80 )
				: $topic;

			$prompt_result = $this->build_image_prompt_from_content( $context );

			if ( is_wp_error( $prompt_result ) ) {
				return rest_ensure_response( [
					'success' => false,
					'message' => $prompt_result->get_error_message(),
				] );
			}

			$prompt = $prompt_result;
		}

		$image_url = AIProvider::generate_image( $prompt, '1024x1024' );

		if ( is_wp_error( $image_url ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $image_url->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'url'    => esc_url( $image_url ),
				'prompt' => $prompt,
			],
		] );
	}

	/**
	 * Use AI to build an image generation prompt from email content.
	 *
	 * @param string $context Email content summary or topic.
	 * @return string|\WP_Error Image prompt or error.
	 */
	private function build_image_prompt_from_content( $context ) {
		$prompt = "Based on the following email content, generate a single short image prompt (under 100 words) that describes a relevant, professional, visually appealing image suitable for a marketing email. The image should be a clean photograph or illustration — no text overlay, no logos. Focus on mood, scene, and subject.

Email content:\n{$context}

Respond with ONLY the image prompt, nothing else.";

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return new \WP_Error( 'ai_error', $response['error'] );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$image_prompt = trim( (string) $response );

		if ( empty( $image_prompt ) ) {
			return new \WP_Error( 'ai_error', __( 'Failed to generate image prompt.', 'antimanual' ) );
		}

		return $image_prompt;
	}

	/**
	 * Generate AI images and insert them into email HTML content.
	 *
	 * Splits content into paragraphs, generates image prompts contextually,
	 * and inserts images at evenly-spaced positions.
	 *
	 * @param string $html        Email HTML content.
	 * @param int    $image_count Number of images to generate (1-3).
	 * @return string Modified HTML with images inserted.
	 */
	private function generate_and_insert_images( $html, $image_count = 1 ) {
		$content_text = wp_trim_words( wp_strip_all_tags( $html ), 120 );

		if ( empty( $content_text ) ) {
			return $html;
		}

		// Ask AI for N distinct image prompts based on the content.
		$image_prompts = $this->generate_multiple_image_prompts( $content_text, $image_count );

		if ( empty( $image_prompts ) ) {
			return $html;
		}

		// Generate each image.
		$image_urls = [];
		foreach ( $image_prompts as $prompt ) {
			$url = AIProvider::generate_image( $prompt, '1024x1024' );

			if ( ! is_wp_error( $url ) && is_string( $url ) && '' !== $url ) {
				$image_urls[] = esc_url( $url );
			}
		}

		if ( empty( $image_urls ) ) {
			return $html;
		}

		// Split HTML by paragraph/heading/div boundaries, insert images at even intervals.
		$blocks      = preg_split( '/(<\/(?:p|h[1-6]|div)>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$block_count = count( $blocks );

		if ( $block_count < 2 ) {
			// Fallback: append all images at the end.
			foreach ( $image_urls as $url ) {
				$html .= "\n" . $this->build_image_html( $url ) . "\n";
			}
			return $html;
		}

		// Calculate insertion points between blocks.
		$total_images    = count( $image_urls );
		$pair_count      = intdiv( $block_count, 2 );
		$interval        = max( 1, intdiv( $pair_count, $total_images + 1 ) );
		$result          = '';
		$pair_index      = 0;
		$images_inserted = 0;

		for ( $i = 0; $i < $block_count; $i++ ) {
			$result .= $blocks[ $i ];

			// After every closing tag (odd index = delimiter), count a "pair".
			if ( $i % 2 === 1 ) {
				$pair_index++;

				if ( $images_inserted < $total_images && $pair_index % $interval === 0 ) {
					$result .= "\n" . $this->build_image_html( $image_urls[ $images_inserted ] ) . "\n";
					$images_inserted++;
				}
			}
		}

		// Insert any remaining images at the end.
		while ( $images_inserted < $total_images ) {
			$result .= "\n" . $this->build_image_html( $image_urls[ $images_inserted ] ) . "\n";
			$images_inserted++;
		}

		return $result;
	}

	/**
	 * Generate multiple distinct image prompts from email content.
	 *
	 * @param string $content_text Plain text email content.
	 * @param int    $count        Number of prompts to generate.
	 * @return array Array of prompt strings.
	 */
	private function generate_multiple_image_prompts( $content_text, $count = 1 ) {
		$plural = $count > 1
			? "Generate exactly {$count} distinct, short image prompts (each under 80 words)"
			: 'Generate exactly 1 short image prompt (under 80 words)';

		$format = $count > 1
			? "Respond with ONLY the prompts, one per line, no numbering or bullets."
			: "Respond with ONLY the image prompt, nothing else.";

		$prompt = "{$plural} that describe relevant, professional, visually appealing images suitable for a marketing email. Each image should be a clean photograph or illustration — no text overlay, no logos. Focus on mood, scene, and subject. Each prompt should represent a different aspect of the content.

Email content:
{$content_text}

{$format}";

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( is_wp_error( $response ) || ( is_array( $response ) && isset( $response['error'] ) ) ) {
			return [];
		}

		$lines = array_filter(
			array_map( 'trim', explode( "\n", (string) $response ) ),
			fn( $line ) => ! empty( $line ) && strlen( $line ) > 10
		);

		return array_slice( array_values( $lines ), 0, $count );
	}

	/**
	 * Build a centered image HTML block for email insertion.
	 *
	 * @param string $url Image URL.
	 * @return string HTML string.
	 */
	private function build_image_html( $url ) {
		return '<div style="text-align:center;margin:20px 0;"><img src="' . esc_url( $url ) . '" alt="" style="max-width:100%;height:auto;border-radius:8px;" /></div>';
	}

	/**
	 * List subscribers.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function list_subscribers( $request ) {
		$result = EmailSubscribers::list( [
			'status'     => sanitize_key( $request->get_param( 'status' ) ?? '' ),
			'search'     => sanitize_text_field( $request->get_param( 'search' ) ?? '' ),
			'list'       => sanitize_key( $request->get_param( 'list' ) ?? '' ),
			'page'       => intval( $request->get_param( 'page' ) ?? 1 ),
			'per_page'   => intval( $request->get_param( 'per_page' ) ?? 20 ),
			'sort_by'    => sanitize_key( $request->get_param( 'sort_by' ) ?? 'created_at' ),
			'sort_order' => sanitize_key( $request->get_param( 'sort_order' ) ?? 'desc' ),
		] );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Add a subscriber.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function add_subscriber( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];

		$email              = sanitize_email( $payload['email'] ?? '' );
		$name               = sanitize_text_field( $payload['name'] ?? '' );
		$tags               = $payload['list_ids'] ?? ( $payload['tags'] ?? [] );
		$subscription_types = $payload['subscription_types'] ?? [];
		$custom_fields      = is_array( $payload['custom_fields'] ?? null ) ? $payload['custom_fields'] : [];

		if ( ! is_array( $tags ) ) {
			$tags = array_filter( explode( ',', (string) $tags ), 'strlen' );
		}

		$raw_tag_count = count( array_filter( array_map( 'sanitize_key', $tags ) ) );
		$tags          = $this->filter_existing_subscriber_list_ids( $tags );

		if ( ! is_email( $email ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Please enter a valid email address.', 'antimanual' ),
			] );
		}

		// Enforce subscriber limit for free users.
		if ( ! atml_can_add_subscribers( 1 ) ) {
			$limit = atml_get_subscriber_limit();
			return rest_ensure_response( [
				'success' => false,
				'message' => sprintf(
					/* translators: %s: subscriber limit number */
					__( 'You have reached the %s subscriber limit on the Free plan. Upgrade to Pro Campaign for unlimited contacts.', 'antimanual' ),
					number_format_i18n( $limit )
				),
			] );
		}

		if ( $raw_tag_count !== count( $tags ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'One or more selected lists no longer exist.', 'antimanual' ),
			] );
		}

		$id = EmailSubscribers::add( $email, $name, 'manual', $tags, $subscription_types, $custom_fields );

		if ( ! $id ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to add subscriber.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Subscriber added.', 'antimanual' ),
			'data'    => [ 'id' => $id ],
		] );
	}

	/**
	 * Delete subscribers.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_subscribers( $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			$payload = json_decode( $request->get_body(), true ) ?: [];
		}

		$ids     = array_map( 'absint', $payload['ids'] ?? [] );

		if ( empty( $ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No subscribers selected.', 'antimanual' ),
			] );
		}

		$count = EmailSubscribers::delete( $ids );

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of deleted subscribers */
				__( '%d subscriber(s) deleted.', 'antimanual' ),
				$count
			),
		] );
	}

	/**
	 * Import subscribers from CSV data.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function import_subscribers( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$rows                  = $payload['subscribers'] ?? [];
		$list_id               = sanitize_key( $payload['list_id'] ?? '' );
		$subscription_types    = $payload['subscription_types'] ?? [];
		$default_custom_fields = is_array( $payload['custom_fields'] ?? null ) ? $payload['custom_fields'] : [];
		$duplicate_mode        = sanitize_key( $payload['duplicate_mode'] ?? 'skip' );

		// Validate duplicate mode against allowlist.
		if ( ! in_array( $duplicate_mode, [ 'skip', 'replace' ], true ) ) {
			$duplicate_mode = 'skip';
		}

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No subscribers data provided.', 'antimanual' ),
			] );
		}

		// Enforce subscriber limit for free users — cap rows to remaining capacity.
		$limit = atml_get_subscriber_limit();
		if ( 0 !== $limit ) {
			$current   = \Antimanual\EmailSubscribers::get_total_count();
			$remaining = max( 0, $limit - $current );

			if ( 0 === $remaining ) {
				return rest_ensure_response( [
					'success' => false,
					'message' => sprintf(
						/* translators: %s: subscriber limit number */
						__( 'You have reached the %s subscriber limit on the Free plan. Upgrade to Pro Campaign for unlimited contacts.', 'antimanual' ),
						number_format_i18n( $limit )
					),
				] );
			}

			if ( count( $rows ) > $remaining ) {
				$rows = array_slice( $rows, 0, $remaining );
			}
		}

		if ( $list_id && ! $this->subscriber_list_exists( $list_id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'The selected list no longer exists.', 'antimanual' ),
			] );
		}

		$result = EmailSubscribers::import( $rows, 'import', $list_id ? [ $list_id ] : [], $subscription_types, $default_custom_fields, $duplicate_mode );

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %1$d: imported count, %2$d: skipped count */
				__( '%1$d imported, %2$d skipped.', 'antimanual' ),
				$result['imported'],
				$result['skipped']
			),
			'data' => $result,
		] );
	}

	/**
	 * Generate likely names for subscribers who currently have no name.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_subscriber_names( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$ids     = array_values( array_filter( array_map( 'absint', $payload['subscriber_ids'] ?? [] ) ) );

		if ( empty( $ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Select at least one subscriber to generate names for.', 'antimanual' ),
			] );
		}

		$result = EmailSubscribers::generate_missing_names( $ids );

		if ( 0 === (int) $result['processed'] ) {
			$message = __( 'No selected subscribers are missing names.', 'antimanual' );
		} else {
			/* translators: 1: updated count, 2: skipped count */
			$message = sprintf( __( '%1$d names generated, %2$d skipped.', 'antimanual' ), (int) $result['updated'], (int) $result['skipped'] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => $message,
			'data'    => $result,
		] );
	}

	/**
	 * Generate likely names for every contact in a selected subscriber list.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_subscriber_names_for_list( $request ) {
		$list_id = sanitize_key( $request['id'] ?? '' );

		if ( '' === $list_id || ! $this->subscriber_list_exists( $list_id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'The selected list no longer exists.', 'antimanual' ),
			] );
		}

		$subscriber_ids = EmailSubscribers::get_ids_by_list( $list_id );

		if ( empty( $subscriber_ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No contacts found in this list.', 'antimanual' ),
			] );
		}

		$result = EmailSubscribers::generate_missing_names( $subscriber_ids );

		if ( 0 === (int) $result['processed'] ) {
			$message = __( 'No contacts in this list are missing names.', 'antimanual' );
		} else {
			/* translators: 1: updated count, 2: skipped count */
			$message = sprintf( __( '%1$d names generated, %2$d skipped.', 'antimanual' ), (int) $result['updated'], (int) $result['skipped'] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => $message,
			'data'    => $result,
		] );
	}

	/**
	 * Use AI to analyze subscriber names and fix fake, gibberish, or low-quality names.
	 *
	 * @param \WP_REST_Request $request REST request with subscriber_ids.
	 * @return \WP_REST_Response
	 */
	public function clean_subscriber_names( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$ids     = array_values( array_filter( array_map( 'absint', $payload['subscriber_ids'] ?? [] ) ) );

		if ( empty( $ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Select at least one subscriber.', 'antimanual' ),
			] );
		}

		$result = $this->run_subscriber_name_cleanup( $ids );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: 1: rescanned count, 2: improved count */
				__( '%1$d name(s) analyzed, %2$d improved.', 'antimanual' ),
				(int) $result['scanned'],
				(int) $result['updated']
			),
			'data' => $result,
		] );
	}

	/**
	 * Use AI to analyze subscriber names for every contact in a selected list.
	 *
	 * @param \WP_REST_Request $request REST request with list ID.
	 * @return \WP_REST_Response
	 */
	public function clean_subscriber_names_for_list( $request ) {
		$list_id = sanitize_key( $request['id'] ?? '' );

		if ( '' === $list_id || ! $this->subscriber_list_exists( $list_id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'The selected list no longer exists.', 'antimanual' ),
			] );
		}

		$subscriber_ids = EmailSubscribers::get_ids_by_list( $list_id );

		if ( empty( $subscriber_ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No contacts found in this list.', 'antimanual' ),
			] );
		}

		$result = $this->run_subscriber_name_cleanup( $subscriber_ids );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: 1: rescanned count, 2: improved count */
				__( '%1$d name(s) analyzed, %2$d improved.', 'antimanual' ),
				(int) $result['scanned'],
				(int) $result['updated']
			),
			'data' => $result,
		] );
	}

	/**
	 * Run AI-backed name cleanup for the provided subscriber IDs.
	 *
	 * @param array<int> $ids Subscriber IDs.
	 * @return array|\WP_Error
	 */
	private function run_subscriber_name_cleanup( array $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return new \WP_Error( 'missing_subscribers', __( 'Select at least one subscriber.', 'antimanual' ) );
		}

		if ( ! AIProvider::has_api_key() ) {
			return new \WP_Error( 'missing_ai_key', __( 'AI API key is not configured. Please set up your API key in Settings.', 'antimanual' ) );
		}

		// Fetch only the needed columns — no SELECT *.
		global $wpdb;
		$table      = EmailSubscribers::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$subscribers  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, email, name FROM $table WHERE id IN ($placeholders)",
			...$ids
		), ARRAY_A );

		if ( empty( $subscribers ) ) {
			return new \WP_Error( 'missing_subscribers', __( 'No subscribers found.', 'antimanual' ) );
		}

		// Build compact payload for AI (id ⇒ email + name).
		$entries = [];
		foreach ( $subscribers as $sub ) {
			$entries[] = [
				'id'    => (int) $sub['id'],
				'email' => $sub['email'],
				'name'  => $sub['name'] ?? '',
			];
		}

		$prompt = "You are a data‑quality expert. Analyze the subscriber list below.\n"
			. "For each entry decide whether the current name looks **real and plausible**.\n\n"
			. "Rules:\n"
			. "- If the name is clearly fake, gibberish, keyboard-mashing (e.g. 'asdfjkl', 'test123', 'xxxx'), or offensive, suggest a corrected name.\n"
			. "- For the corrected name, derive it from the email address local part if possible, or mark it as empty \"\" if no useful name can be inferred.\n"
			. "- If the name is legitimate (real human names like 'John Doe', 'María García'), keep it EXACTLY as-is.\n"
			. "- If the name is empty, try to derive a natural name from the email address.\n"
			. "- Capitalize names properly (Title Case).\n"
			. "- Return ONLY a JSON array. No markdown fences, no explanation.\n"
			. "- Format: [{\"id\":1,\"name\":\"Fixed Name\",\"changed\":true},{\"id\":2,\"name\":\"Kept Name\",\"changed\":false}]\n\n"
			. "Subscribers:\n" . wp_json_encode( $entries );

		$response = AIProvider::get_reply( [
			[ 'role' => 'user', 'content' => $prompt ],
		] );

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return new \WP_Error( 'ai_error', $response['error'] );
		}

		$response = is_string( $response ) ? trim( $response ) : '';
		$response = preg_replace( '/^```(?:json)?\s*/i', '', $response );
		$response = preg_replace( '/\s*```$/i', '', (string) $response );
		$parsed   = json_decode( (string) $response, true );

		if ( ! is_array( $parsed ) ) {
			return new \WP_Error( 'invalid_ai_response', __( 'AI returned an unexpected response. Please try again.', 'antimanual' ) );
		}

		// Apply only changed names — batch updates outside loop by collecting first.
		$updated = 0;
		foreach ( $parsed as $entry ) {
			if ( empty( $entry['changed'] ) || ! isset( $entry['id'], $entry['name'] ) ) {
				continue;
			}
			$clean_name = sanitize_text_field( $entry['name'] );
			$result     = EmailSubscribers::update( (int) $entry['id'], [ 'name' => $clean_name ] );
			if ( $result ) {
				++$updated;
			}
		}

		return [
			'scanned' => count( $parsed ),
			'updated' => $updated,
		];
	}

	/**
	 * Use AI to extract structured contacts from messy pasted text.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function smart_extract_contacts( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$text    = sanitize_textarea_field( $payload['text'] ?? '' );

		if ( empty( trim( $text ) ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No text provided to extract contacts from.', 'antimanual' ),
			] );
		}

		if ( ! AIProvider::has_api_key() ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'AI API key is not configured. Please set up your API key in Settings.', 'antimanual' ),
			] );
		}

		$prompt = "Extract all email addresses and associated names from the following text.\n"
			. "Rules:\n"
			. "- Find every valid email address in the text.\n"
			. "- For each email, try to find the person's name nearby in the text.\n"
			. "- If no name is found, return an empty string for the name.\n"
			. "- Remove duplicates.\n"
			. "- Return ONLY valid JSON array, no markdown, no explanation.\n"
			. "- Format: [{\"email\":\"john@example.com\",\"name\":\"John Doe\"}]\n\n"
			. "Text:\n" . $text;

		$response = AIProvider::get_reply( [
			[ 'role' => 'user', 'content' => $prompt ],
		] );

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		$response = is_string( $response ) ? trim( $response ) : '';
		$response = preg_replace( '/^```(?:json)?\s*/i', '', $response );
		$response = preg_replace( '/\s*```$/i', '', (string) $response );
		$parsed   = json_decode( (string) $response, true );

		if ( ! is_array( $parsed ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'AI could not extract contacts from the provided text.', 'antimanual' ),
			] );
		}

		$contacts = [];
		$seen     = [];

		foreach ( $parsed as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$email = sanitize_email( $item['email'] ?? '' );
			$name  = sanitize_text_field( $item['name'] ?? '' );

			if ( ! is_email( $email ) || isset( $seen[ $email ] ) ) {
				continue;
			}

			$seen[ $email ] = true;
			$contacts[]     = [ 'email' => $email, 'name' => $name ];
		}

		return rest_ensure_response( [
			'success'  => true,
			'contacts' => $contacts,
			'count'    => count( $contacts ),
		] );
	}

	/**
	 * Update a single subscriber (name or status).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function update_subscriber( $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$payload = json_decode( $request->get_body(), true ) ?: [];

		if ( ! $id ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Invalid subscriber ID.', 'antimanual' ),
			] );
		}

		$update_data = [];

		if ( isset( $payload['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $payload['name'] );
		}

		if ( isset( $payload['status'] ) ) {
			$allowed_statuses = [ 'active', 'unsubscribed' ];
			$status           = sanitize_key( $payload['status'] );

			if ( ! in_array( $status, $allowed_statuses, true ) ) {
				return rest_ensure_response( [
					'success' => false,
					'message' => __( 'Invalid status value.', 'antimanual' ),
				] );
			}

			$update_data['status'] = $status;

			if ( 'active' === $status ) {
				$update_data['unsubscribed_at'] = null;
			} elseif ( 'unsubscribed' === $status ) {
				$update_data['unsubscribed_at'] = current_time( 'mysql', true );
			}
		}

		if ( array_key_exists( 'subscription_types', $payload ) ) {
			$update_data['subscription_types'] = $payload['subscription_types'];
		}

		if ( array_key_exists( 'custom_fields', $payload ) && is_array( $payload['custom_fields'] ) ) {
			$update_data['custom_fields'] = $payload['custom_fields'];
		}

		if ( empty( $update_data ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No fields to update.', 'antimanual' ),
			] );
		}

		$result = EmailSubscribers::update( $id, $update_data );

		if ( ! $result ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to update subscriber.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Subscriber updated.', 'antimanual' ),
		] );
	}

	/**
	 * Bulk update subscriber status (e.g., reactivate unsubscribed contacts).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function bulk_update_status( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$ids     = array_values( array_filter( array_map( 'absint', $payload['ids'] ?? [] ) ) );
		$status  = sanitize_key( $payload['status'] ?? '' );

		$allowed = [ 'active', 'unsubscribed' ];

		if ( empty( $ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No subscribers selected.', 'antimanual' ),
			] );
		}

		if ( ! in_array( $status, $allowed, true ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Invalid status value.', 'antimanual' ),
			] );
		}

		$count = EmailSubscribers::bulk_update_status( $ids, $status );

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of updated subscribers */
				__( '%d subscriber(s) updated.', 'antimanual' ),
				$count
			),
			'data' => [ 'updated' => $count ],
		] );
	}

	/**
	 * Check which emails from a list already exist as subscribers.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function check_duplicates( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$emails  = $payload['emails'] ?? [];

		if ( ! is_array( $emails ) || empty( $emails ) ) {
			return rest_ensure_response( [
				'success' => true,
				'data'    => [
					'duplicates' => [],
					'count'      => 0,
				],
			] );
		}

		$clean_emails = array_values( array_filter( array_map( 'sanitize_email', $emails ), 'is_email' ) );
		$existing     = EmailSubscribers::find_existing_emails( $clean_emails );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'duplicates' => $existing,
				'count'      => count( $existing ),
			],
		] );
	}

	/**
	 * Delete all subscribers that are not assigned to any list.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_unlisted_subscribers( $request ) {
		$count = EmailSubscribers::delete_unlisted();

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of deleted subscribers */
				__( '%d unlisted subscriber(s) deleted.', 'antimanual' ),
				$count
			),
			'data' => [ 'deleted' => $count ],
		] );
	}

	/**
	 * Get the active audience size for all subscribers or selected lists.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_audience_count( $request ) {
		$lists_param = $request->get_param( 'lists' ) ?? '';
		$types_param = $request->get_param( 'subscription_types' ) ?? '';
		$list_ids    = [];
		$type_ids    = [];

		if ( is_array( $lists_param ) ) {
			$list_ids = array_map( 'sanitize_key', $lists_param );
		} else {
			$list_ids = array_map( 'sanitize_key', array_filter( explode( ',', (string) $lists_param ), 'strlen' ) );
		}

		$list_ids = array_values( array_unique( array_filter( $list_ids ) ) );

		if ( is_array( $types_param ) ) {
			$type_ids = array_map( 'sanitize_key', $types_param );
		} else {
			$type_ids = array_map( 'sanitize_key', array_filter( explode( ',', (string) $types_param ), 'strlen' ) );
		}

		$type_ids = array_values( array_unique( array_filter( $type_ids ) ) );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'count'                    => EmailSubscribers::get_active_count_by_lists( $list_ids, $type_ids ),
				'total_active'             => EmailSubscribers::get_active_count(),
				'using_lists'              => ! empty( $list_ids ),
				'using_subscription_types' => ! empty( $type_ids ),
				'lists'                    => $list_ids,
				'subscription_types'       => $type_ids,
			],
		] );
	}

	// ─── Subscriber List Management ────────────────────────────────────

	/**
	 * Get normalized subscriber lists and optionally persist normalized IDs.
	 *
	 * @param bool $persist Whether to save normalized list IDs back to the option.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_normalized_subscriber_lists( $persist = true ) {
		$stored_lists = get_option( 'atml_email_subscriber_lists', [] );
		$lists        = [];
		$changed      = false;

		foreach ( $stored_lists as $list ) {
			if ( ! is_array( $list ) ) {
				$changed = true;
				continue;
			}

			$id         = sanitize_key( $list['id'] ?? '' );
			$name       = sanitize_text_field( $list['name'] ?? '' );
			$created_at = sanitize_text_field( $list['created_at'] ?? '' );

			if ( empty( $id ) || empty( $name ) ) {
				$changed = true;
				continue;
			}

			if ( ( $list['id'] ?? '' ) !== $id ) {
				$changed = true;
			}

			if ( isset( $lists[ $id ] ) ) {
				$changed = true;
				continue;
			}

			$lists[ $id ] = [
				'id'         => $id,
				'name'       => $name,
				'created_at' => $created_at ?: current_time( 'mysql', true ),
			];
		}

		$lists = array_values( $lists );

		if ( $persist && $changed ) {
			update_option( 'atml_email_subscriber_lists', $lists, false );
		}

		return $lists;
	}

	/**
	 * Filter raw list IDs down to subscriber lists that currently exist.
	 *
	 * @param array $list_ids Raw list IDs.
	 * @return array
	 */
	private function filter_existing_subscriber_list_ids( array $list_ids ) {
		$list_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $list_ids ) ) ) );

		if ( empty( $list_ids ) ) {
			return [];
		}

		$valid_ids = array_column( $this->get_normalized_subscriber_lists(), 'id' );

		return array_values( array_intersect( $list_ids, $valid_ids ) );
	}

	/**
	 * Check whether a subscriber list currently exists.
	 *
	 * @param string $list_id List ID.
	 * @return bool
	 */
	private function subscriber_list_exists( $list_id ) {
		$list_id = sanitize_key( $list_id );

		if ( '' === $list_id ) {
			return false;
		}

		return in_array( $list_id, array_column( $this->get_normalized_subscriber_lists( false ), 'id' ), true );
	}

	/**
	 * Get all subscriber lists.
	 * Lists are stored as a wp_option: atml_email_subscriber_lists.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function list_subscriber_lists( $request ) {
		$lists = $this->get_normalized_subscriber_lists();

		// Enrich each list with its subscriber count via a single batch query.
		global $wpdb;
		$table = EmailSubscribers::get_table_name();
		EmailSubscribers::ensure_table_exists();

		$list_ids = array_column( $lists, 'id' );

		// Default all counts to 0.
		$counts = array_fill_keys( $list_ids, 0 );

		if ( ! empty( $list_ids ) ) {
			// Build a single query with SUM(CASE … FIND_IN_SET) for each list.
			$sum_parts = [];
			$params    = [];

			foreach ( $list_ids as $lid ) {
				$sum_parts[] = 'SUM(CASE WHEN FIND_IN_SET(%s, tags) > 0 THEN 1 ELSE 0 END)';
				$params[]    = $lid;
			}

			$select = implode( ', ', $sum_parts );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT $select FROM $table WHERE status = 'active'",
				...$params
			), ARRAY_N );

			if ( is_array( $row ) ) {
				foreach ( $list_ids as $idx => $lid ) {
					$counts[ $lid ] = (int) ( $row[ $idx ] ?? 0 );
				}
			}
		}

		foreach ( $lists as &$list ) {
			$list['count'] = $counts[ $list['id'] ] ?? 0;
		}
		unset( $list );

		// Compute total active subscribers and unlisted (not in any list) count.
		$total_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'active'" );

		$unlisted_count = $total_active;
		if ( ! empty( $list_ids ) ) {
			$like_parts = [];
			$like_params = [];
			foreach ( $list_ids as $lid ) {
				$like_parts[]  = 'FIND_IN_SET(%s, tags) > 0';
				$like_params[] = $lid;
			}
			$where_any_list = implode( ' OR ', $like_parts );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$listed_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE status = 'active' AND ($where_any_list)",
				...$like_params
			) );
			$unlisted_count = $total_active - $listed_count;
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'lists'             => array_values( $lists ),
				'total_subscribers' => $total_active,
				'unlisted_count'    => max( 0, $unlisted_count ),
			],
		] );
	}

	/**
	 * Create a new subscriber list.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function create_subscriber_list( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$name    = sanitize_text_field( $payload['name'] ?? '' );

		if ( empty( $name ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'List name is required.', 'antimanual' ),
			] );
		}

		$lists = $this->get_normalized_subscriber_lists();
		$base  = sanitize_title( $name );
		$id    = '';

		do {
			$id = sanitize_key( $base . '-' . strtolower( wp_generate_password( 4, false, false ) ) );
		} while ( in_array( $id, array_column( $lists, 'id' ), true ) );

		$lists[] = [
			'id'         => $id,
			'name'       => $name,
			'created_at' => current_time( 'mysql', true ),
		];

		update_option( 'atml_email_subscriber_lists', $lists, false );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'id' => $id, 'name' => $name, 'count' => 0 ],
		] );
	}

	/**
	 * Update (rename) a subscriber list.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function update_subscriber_list( $request ) {
		$id      = sanitize_key( $request['id'] );
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$name    = sanitize_text_field( $payload['name'] ?? '' );

		if ( empty( $name ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'List name is required.', 'antimanual' ),
			] );
		}

		$lists   = $this->get_normalized_subscriber_lists();
		$updated = false;

		foreach ( $lists as &$list ) {
			if ( $list['id'] === $id ) {
				$list['name'] = $name;
				$updated = true;
				break;
			}
		}
		unset( $list );

		if ( ! $updated ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'List not found.', 'antimanual' ),
			] );
		}

		update_option( 'atml_email_subscriber_lists', $lists, false );

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'List updated.', 'antimanual' ),
		] );
	}

	/**
	 * Delete a subscriber list.
	 * Does NOT delete the subscribers — just removes the tag from all of them.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_subscriber_list( $request ) {
		$id    = sanitize_key( $request['id'] );
		$lists = $this->get_normalized_subscriber_lists( false );

		if ( '' === $id || ! $this->subscriber_list_exists( $id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'List not found.', 'antimanual' ),
			] );
		}

		$lists = array_filter( $lists, function ( $list ) use ( $id ) {
			return sanitize_key( $list['id'] ?? '' ) !== $id;
		} );

		update_option( 'atml_email_subscriber_lists', array_values( $lists ), false );
		$removed_count = EmailSubscribers::remove_list_from_all( $id );

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'List deleted.', 'antimanual' ),
			'data'    => [ 'updated' => $removed_count ],
		] );
	}

	/**
	 * Delete all subscribers that belong to a specific list.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_list_contacts( $request ) {
		$list_id = sanitize_key( $request['id'] );

		if ( '' === $list_id || ! $this->subscriber_list_exists( $list_id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'List not found.', 'antimanual' ),
			] );
		}

		$deleted = EmailSubscribers::delete_by_list( $list_id );

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of deleted subscribers */
				__( '%d contact(s) deleted.', 'antimanual' ),
				$deleted
			),
			'data' => [ 'deleted' => $deleted ],
		] );
	}

	/**
	 * Assign selected subscribers to a list (add tag).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function assign_subscribers_to_list( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$list_id = sanitize_key( $payload['list_id'] ?? '' );
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $payload['subscriber_ids'] ?? [] ) ) ) );

		if ( empty( $list_id ) || empty( $ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'List ID and subscriber IDs are required.', 'antimanual' ),
			] );
		}

		if ( ! $this->subscriber_list_exists( $list_id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'The selected list no longer exists.', 'antimanual' ),
			] );
		}

		$updated = EmailSubscribers::assign_list_to_subscribers( $list_id, $ids );

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of subscribers assigned */
				__( '%d subscriber(s) added to list.', 'antimanual' ),
				$updated
			),
			'data'    => [ 'updated' => $updated ],
		] );
	}

	/**
	 * Remove selected subscribers from a list (remove tag).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function remove_subscribers_from_list( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$list_id = sanitize_key( $payload['list_id'] ?? '' );
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $payload['subscriber_ids'] ?? [] ) ) ) );

		if ( empty( $list_id ) || empty( $ids ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'List ID and subscriber IDs are required.', 'antimanual' ),
			] );
		}

		if ( ! $this->subscriber_list_exists( $list_id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'The selected list no longer exists.', 'antimanual' ),
			] );
		}

		$removed = EmailSubscribers::remove_list_from_subscribers( $list_id, $ids );

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of subscribers removed */
				__( '%d subscriber(s) removed from list.', 'antimanual' ),
				$removed
			),
			'data'    => [ 'updated' => $removed ],
		] );
	}

	/**
	 * Get overall email campaign stats.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_stats( $request ) {
		$stats = EmailCampaign::get_stats();

		return rest_ensure_response( [
			'success' => true,
			'data'    => $stats,
		] );
	}

	/**
	 * Get available email templates.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_templates( $request ) {
		$templates = EmailCampaign::get_templates();
		$preview_content = '<h1>' . esc_html__( 'Welcome to our update', 'antimanual' ) . '</h1>'
			. '<p>' . esc_html__( 'This is a sample preview so you can compare the visual style of each email template before choosing one.', 'antimanual' ) . '</p>'
			. '<p><a href="https://example.com" style="color:inherit;">' . esc_html__( 'Explore the call to action', 'antimanual' ) . '</a></p>';

		// Don't send the full HTML to frontend, just metadata.
		$meta = [];
		foreach ( $templates as $id => $tpl ) {
			$meta[] = [
				'id'            => $id,
				'name'          => $tpl['name'],
				'description'   => $tpl['description'],
				'template_html' => $tpl['html'],
				'preview_html'  => EmailCampaign::wrap_in_template(
					$preview_content,
					__( 'A quick look at this template', 'antimanual' ),
					'#unsubscribe',
					$id,
					__( 'Preview how this design appears in the inbox before you send a real campaign.', 'antimanual' )
				),
			];
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $meta,
		] );
	}

	/**
	 * List saved custom email templates.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_custom_templates() {
		$templates = get_option( 'atml_custom_email_templates', [] );

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_values( $templates ),
		] );
	}

	/**
	 * Save or update a custom email template.
	 *
	 * Expects { name: string, html: string, id?: string }.
	 * If `id` is provided and exists, updates; otherwise creates new.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function save_custom_template( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$name    = sanitize_text_field( $payload['name'] ?? '' );
		$html    = EmailCampaign::sanitize_custom_template_html( $payload['html'] ?? '' );

		if ( empty( $name ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Template name is required.', 'antimanual' ),
			] );
		}

		if ( empty( $html ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Template HTML is required.', 'antimanual' ),
			] );
		}

		$templates = get_option( 'atml_custom_email_templates', [] );

		// Update existing or create new.
		$id = sanitize_key( $payload['id'] ?? '' );
		if ( $id && isset( $templates[ $id ] ) ) {
			$templates[ $id ]['name']       = $name;
			$templates[ $id ]['html']       = $html;
			$templates[ $id ]['updated_at'] = current_time( 'mysql', true );
		} else {
			$id = sanitize_key( wp_unique_id( sanitize_title( $name ) . '-' ) );
			$templates[ $id ] = [
				'id'         => $id,
				'name'       => $name,
				'html'       => $html,
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			];
		}

		update_option( 'atml_custom_email_templates', $templates, false );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $templates[ $id ],
		] );
	}

	/**
	 * Delete a saved custom email template.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_custom_template( $request ) {
		$id = sanitize_key( $request['template_id'] ?? '' );

		if ( empty( $id ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Template ID is required.', 'antimanual' ),
			] );
		}

		$templates = get_option( 'atml_custom_email_templates', [] );

		if ( ! isset( $templates[ $id ] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Template not found.', 'antimanual' ),
			] );
		}

		unset( $templates[ $id ] );
		update_option( 'atml_custom_email_templates', $templates, false );

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Template deleted.', 'antimanual' ),
		] );
	}

	/**
	 * Extract textual context from uploaded files for AI prompt enrichment.
	 *
	 * @param array $files Raw uploaded files array.
	 * @return string
	 */
	private function extract_uploaded_file_context( array $files ) {
		$names     = $files['name'] ?? [];
		$tmp_names = $files['tmp_name'] ?? [];
		$errors    = $files['error'] ?? [];

		if ( ! is_array( $tmp_names ) ) {
			$names     = [ $names ];
			$tmp_names = [ $tmp_names ];
			$errors    = [ $errors ];
		}

		$context_parts = [];

		foreach ( $tmp_names as $index => $tmp_name ) {
			$tmp_name = is_string( $tmp_name ) ? $tmp_name : '';
			$error    = intval( $errors[ $index ] ?? UPLOAD_ERR_NO_FILE );

			if ( '' === $tmp_name || UPLOAD_ERR_OK !== $error || ! file_exists( $tmp_name ) ) {
				continue;
			}

			$name = sanitize_file_name( (string) ( $names[ $index ] ?? 'upload.pdf' ) );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$text = '';

			if ( 'pdf' === $ext && class_exists( '\Smalot\PdfParser\Parser' ) ) {
				try {
					$parser = new \Smalot\PdfParser\Parser();
					$pdf    = $parser->parseFile( $tmp_name );
					$text   = $pdf->getText();
				} catch ( \Throwable $e ) {
					$text = '';
				}
			} else {
				$raw = file_get_contents( $tmp_name );

				if ( false === $raw ) {
					continue;
				}

				if ( in_array( $ext, [ 'html', 'htm' ], true ) && class_exists( '\Soundasleep\Html2Text' ) ) {
					$text = \Soundasleep\Html2Text::convert( $raw, [ 'ignore_errors' => true ] );
				} else {
					$text = wp_strip_all_tags( $raw, true );
				}
			}

			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

			if ( '' === $text ) {
				continue;
			}

			$context_parts[] = sprintf(
				"[Uploaded File: %s]\n%s",
				$name,
				substr( $text, 0, 4000 )
			);
		}

		return implode( "\n\n---\n\n", array_slice( $context_parts, 0, 5 ) );
	}

	/**
	 * Default email settings values.
	 *
	 * Provides sensible header/footer defaults so new installs
	 * have branded emails out of the box.
	 *
	 * @return array
	 */
	private static function email_settings_defaults() {
		$site_name = get_bloginfo( 'name' );

		return [
			'header_html'        => '<a href="{{site_url}}" style="font-size:20px;font-weight:700;color:inherit;text-decoration:none;">{{site_name}}</a>',
			'footer_html'        => '<p style="margin:0;">&copy; {{year}} {{site_name}}</p>'
				. '<p style="margin:8px 0 0;"><a href="{{unsubscribe_url}}" style="color:inherit;text-decoration:underline;">Unsubscribe</a></p>',
			'from_name'          => $site_name,
			'reply_to'           => get_bloginfo( 'admin_email' ),
			'default_template'   => 'minimal',
			'default_tone'       => 'professional',
			'default_language'   => 'en',
			'batch_size'         => 25,
			'batch_delay'        => 1,
			'enable_tracking'    => true,
			'auto_remove_failed_subscribers' => false,
			'notify_on_complete' => false,
			'notification_email' => get_bloginfo( 'admin_email' ),
		];
	}

	/**
	 * Get email settings (header, footer, sender).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_email_settings() {
		$defaults = self::email_settings_defaults();
		$stored   = get_option( 'atml_email_settings', [] );
		$settings = wp_parse_args( $stored, $defaults );

		if ( ! atml_is_pro_campaign() ) {
			$settings['auto_remove_failed_subscribers'] = false;
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'settings' => $settings,
				'defaults' => $defaults,
			],
		] );
	}

	/**
	 * Update email settings (header, footer, sender).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function update_email_settings( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$current = get_option( 'atml_email_settings', [] );

		$allowed = [
			'header_html'        => 'wp_kses_post',
			'footer_html'        => 'wp_kses_post',
			'from_name'          => 'sanitize_text_field',
			'reply_to'           => 'sanitize_email',
			'default_template'   => 'sanitize_key',
			'default_tone'       => 'sanitize_key',
			'default_language'   => 'sanitize_text_field',
			'notification_email' => 'sanitize_email',
		];

		// Integer fields — sanitize separately.
		$int_fields = [
			'batch_size'  => [ 5, 200 ],
			'batch_delay' => [ 0, 60 ],
		];

		foreach ( $int_fields as $key => $range ) {
			if ( array_key_exists( $key, $payload ) ) {
				$val = absint( $payload[ $key ] );
				$current[ $key ] = max( $range[0], min( $range[1], $val ) );
			}
		}

		// Boolean fields.
		$bool_fields = [ 'enable_tracking', 'auto_remove_failed_subscribers', 'notify_on_complete' ];

		foreach ( $bool_fields as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$current[ $key ] = filter_var( $payload[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		if ( ! atml_is_pro_campaign() && array_key_exists( 'auto_remove_failed_subscribers', $payload ) ) {
			$current['auto_remove_failed_subscribers'] = false;
		}

		foreach ( $allowed as $key => $sanitizer ) {
			if ( array_key_exists( $key, $payload ) ) {
				$current[ $key ] = call_user_func( $sanitizer, $payload[ $key ] );
			}
		}

		update_option( 'atml_email_settings', $current, false );

		return rest_ensure_response( [
			'success' => true,
			'data'    => wp_parse_args( $current, self::email_settings_defaults() ),
		] );
	}

	/**
	 * Get email campaign reports.
	 *
	 * Returns aggregate stats and per-campaign breakdown for reporting.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_reports( $request = null ) {
		global $wpdb;

		$campaigns_table = \Antimanual\EmailCampaignsDB::get_campaigns_table();
		$log_table       = \Antimanual\EmailCampaignsDB::get_send_log_table();

		// Date range filtering.
		$date_from = $request ? sanitize_text_field( $request->get_param( 'date_from' ) ?? '' ) : '';
		$date_to   = $request ? sanitize_text_field( $request->get_param( 'date_to' ) ?? '' ) : '';

		$date_where  = '';
		$date_params = [];

		if ( $date_from ) {
			$date_where   .= ' AND l.sent_at >= %s';
			$date_params[] = $date_from . ' 00:00:00';
		}

		if ( $date_to ) {
			$date_where   .= ' AND l.sent_at <= %s';
			$date_params[] = $date_to . ' 23:59:59';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Batch-fetch per-campaign stats from send_log in one query.
		$log_sql = "SELECT
				l.campaign_id,
				COUNT(*) as total,
				SUM(CASE WHEN l.status = 'sent' THEN 1 ELSE 0 END) as delivered,
				SUM(CASE WHEN l.opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
				SUM(CASE WHEN l.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked,
				SUM(CASE WHEN l.status = 'failed' THEN 1 ELSE 0 END) as failed
			FROM {$log_table} l
			WHERE 1=1{$date_where}
			GROUP BY l.campaign_id";

		$log_stats = ! empty( $date_params )
			? $wpdb->get_results( $wpdb->prepare( $log_sql, ...$date_params ), ARRAY_A )
			: $wpdb->get_results( $log_sql, ARRAY_A );

		// Index by campaign_id for O(1) lookups.
		$log_map = [];
		if ( $log_stats ) {
			foreach ( $log_stats as $row ) {
				$log_map[ (int) $row['campaign_id'] ] = $row;
			}
		}

		if ( empty( $log_map ) ) {
			return rest_ensure_response( [
				'success' => true,
				'data'    => [
					'summary' => [
						'total_attempted'   => 0,
						'total_delivered'   => 0,
						'total_opened'      => 0,
						'total_clicked'     => 0,
						'total_failed'      => 0,
						'open_rate'         => 0,
						'click_rate'        => 0,
						'delivery_rate'     => 0,
						'total_campaigns'   => 0,
						'tracking_available'=> true,
					],
					'campaigns' => [],
				],
			] );
		}

		// Fetch campaigns that have any delivery log activity, including failed-only attempts.
		$campaign_ids = array_map( 'intval', array_keys( $log_map ) );
		$placeholders = implode( ', ', array_fill( 0, count( $campaign_ids ), '%d' ) );

		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, subject, status, template_id, total_sent, total_opened, total_clicked, total_replied, total_forwarded, total_spam_reported, total_unsubscribed, last_sent_at, created_at
				FROM {$campaigns_table}
				WHERE id IN ({$placeholders})
			ORDER BY last_sent_at DESC
			LIMIT 100",
				...$campaign_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items       = [];
		$sum_attempted = 0;
		$sum_delivered = 0;
		$sum_opened  = 0;
		$sum_clicked = 0;
		$sum_failed  = 0;

		foreach ( ( $campaigns ?: [] ) as $c ) {
			$cid         = (int) $c['id'];
			$sent        = (int) $c['total_sent'];
			$log         = $log_map[ $cid ] ?? [];
			$delivered   = (int) ( $log['delivered'] ?? $sent );
			$opened      = (int) ( $log['opened'] ?? 0 );
			$clicked     = (int) ( $log['clicked'] ?? 0 );
			$failed      = (int) ( $log['failed'] ?? 0 );
			$attempted   = max( (int) ( $log['total'] ?? 0 ), $delivered + $failed, $sent + $failed );
			$delivery_rate = $attempted > 0 ? round( ( $delivered / $attempted ) * 100, 1 ) : 0;
			$open_rate   = $delivered > 0 ? round( ( $opened / $delivered ) * 100, 1 ) : 0;
			$click_rate  = $delivered > 0 ? round( ( $clicked / $delivered ) * 100, 1 ) : 0;

			$items[] = [
				'id'          => $cid,
				'name'        => $c['name'],
				'subject'     => $c['subject'],
				'status'      => $c['status'],
				'template_id' => $c['template_id'],
				'attempted'   => $attempted,
				'sent'        => $sent,
				'delivered'   => $delivered,
				'opened'      => $opened,
				'clicked'     => $clicked,
				'failed'      => $failed,
				'open_rate'         => $open_rate,
				'click_rate'        => $click_rate,
				'delivery_rate'     => $delivery_rate,
				'replied'           => (int) ( $c['total_replied'] ?? 0 ),
				'forwarded'         => (int) ( $c['total_forwarded'] ?? 0 ),
				'spam_reported'     => (int) ( $c['total_spam_reported'] ?? 0 ),
				'unsubscribed'      => (int) ( $c['total_unsubscribed'] ?? 0 ),
				'last_sent_at'      => $c['last_sent_at'],
				'created_at'        => $c['created_at'],
			];

			$sum_attempted += $attempted;
			$sum_delivered += $delivered;
			$sum_opened    += $opened;
			$sum_clicked   += $clicked;
			$sum_failed    += $failed;
		}

		$overall_delivery_rate = $sum_attempted > 0 ? round( ( $sum_delivered / $sum_attempted ) * 100, 1 ) : 0;
		$overall_open_rate     = $sum_delivered > 0 ? round( ( $sum_opened / $sum_delivered ) * 100, 1 ) : 0;
		$overall_click_rate    = $sum_delivered > 0 ? round( ( $sum_clicked / $sum_delivered ) * 100, 1 ) : 0;

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'summary' => [
					'total_attempted'    => $sum_attempted,
					'total_delivered'    => $sum_delivered,
					'total_opened'       => $sum_opened,
					'total_clicked'      => $sum_clicked,
					'total_failed'       => $sum_failed,
					'open_rate'          => $overall_open_rate,
					'click_rate'         => $overall_click_rate,
					'delivery_rate'      => $overall_delivery_rate,
					'total_campaigns'    => count( $log_map ),
					'tracking_available' => true,
				],
				'campaigns' => $items,
			],
		] );
	}

	/**
	 * AI-rewrite or improve existing email content.
	 *
	 * Supports actions: rewrite, shorten, expand, change_tone, fix_grammar.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function rewrite_content( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$content = wp_kses_post( $payload['content'] ?? '' );
		$action  = sanitize_key( $payload['action'] ?? 'rewrite' );
		$tone    = sanitize_text_field( $payload['tone'] ?? '' );

		if ( empty( $content ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Content is required for rewriting.', 'antimanual' ),
			] );
		}

		$valid_actions = [ 'rewrite', 'shorten', 'expand', 'change_tone', 'fix_grammar' ];
		if ( ! in_array( $action, $valid_actions, true ) ) {
			$action = 'rewrite';
		}

		$instructions = [
			'rewrite'     => 'Rewrite this email to be more engaging, clearer, and more persuasive while preserving the original message and key information. Keep the same length.',
			'shorten'     => 'Shorten this email to be more concise and scannable. Cut unnecessary words, merge short paragraphs, and tighten the message. Aim for roughly half the original length while keeping all key points and the CTA.',
			'expand'      => 'Expand this email with more detail, examples, and persuasive copy. Add supporting arguments, social proof suggestions, and a stronger buildup to the CTA. Roughly double the length.',
			'change_tone' => 'Rewrite this email in a ' . ( $tone ?: 'professional' ) . ' tone. Keep the same structure, length, and key points but change the voice, word choice, and style to match the requested tone.',
			'fix_grammar' => 'Fix any grammar, spelling, punctuation, and style issues in this email. Do not change the meaning, structure, or tone — only correct errors and improve clarity.',
		];

		$prompt = $instructions[ $action ] . "\n\nIMPORTANT: Respond with ONLY the rewritten HTML email content. Do not include any explanation, preamble, or markdown code fences. Keep using plain HTML (paragraphs, headings, bold, links).\n\nOriginal email content:\n" . $content;

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response->get_error_message(),
			] );
		}

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		// Strip markdown code fences if present.
		$result = trim( (string) $response );
		$result = preg_replace( '/^```html\s*/i', '', $result );
		$result = preg_replace( '/\s*```$/i', '', $result );

		if ( empty( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to rewrite content.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'content' => wp_kses_post( $result ),
				'action'  => $action,
			],
		] );
	}

	/**
	 * AI-generate preview text from email content.
	 *
	 * Preview text appears in the inbox snippet after the subject line
	 * and is critical for open rates.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_preview_text( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$content = wp_kses_post( $payload['content'] ?? '' );
		$subject = sanitize_text_field( $payload['subject'] ?? '' );

		if ( empty( $content ) && empty( $subject ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Write or generate email content first.', 'antimanual' ),
			] );
		}

		$context = ! empty( $content )
			? wp_trim_words( wp_strip_all_tags( $content ), 100 )
			: $subject;

		$prompt = "Generate 3 compelling email preview texts for the following email. Preview text appears after the subject line in inbox previews and should:
- Be 40-90 characters long
- Complement (not repeat) the subject line
- Create curiosity or highlight value
- Be different from each other in approach

Subject line: {$subject}

Email content:
{$context}

Respond with ONLY 3 preview texts, one per line, no numbering or bullets.";

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response->get_error_message(),
			] );
		}

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		$lines = array_filter(
			array_map( 'trim', explode( "\n", (string) $response ) ),
			fn( $line ) => ! empty( $line ) && strlen( $line ) > 5
		);

		$previews = array_values( array_map( function ( $line ) {
			return preg_replace( '/^[\d\.\-\*\)]+\s*/', '', $line );
		}, $lines ) );

		if ( empty( $previews ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to generate preview text.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'previews' => array_slice( $previews, 0, 3 ) ],
		] );
	}

	/**
	 * AI-generate campaign name suggestions from the topic or content.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_campaign_name( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$topic   = sanitize_text_field( $payload['topic'] ?? '' );
		$content = wp_kses_post( $payload['content'] ?? '' );
		$subject = sanitize_text_field( $payload['subject'] ?? '' );

		$context = $topic ?: ( $content ? wp_trim_words( wp_strip_all_tags( $content ), 60 ) : $subject );

		if ( empty( $context ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Provide a topic, content, or subject first.', 'antimanual' ),
			] );
		}

		$prompt = "Generate 3 short, descriptive campaign names (internal labels, not subject lines) for an email campaign with this context. Campaign names should be:
- 3-6 words long
- Descriptive and easy to identify in a list
- Professional, like internal project names
- Include a date/month reference if it seems recurring

Context: {$context}

Respond with ONLY the 3 names, one per line, no numbering or bullets.";

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response->get_error_message(),
			] );
		}

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		$lines = array_filter(
			array_map( 'trim', explode( "\n", (string) $response ) ),
			fn( $line ) => ! empty( $line ) && strlen( $line ) > 3
		);

		$names = array_values( array_map( function ( $line ) {
			return sanitize_text_field( preg_replace( '/^[\d\.\-\*\)]+\s*/', '', $line ) );
		}, $lines ) );

		if ( empty( $names ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to generate campaign names.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'names' => array_slice( $names, 0, 3 ) ],
		] );
	}

	/**
	 * AI-generate a custom HTML email template from a prompt.
	 *
	 * Produces a complete, responsive HTML email template with inline
	 * styles and the standard dynamic placeholders.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_custom_template( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$prompt  = sanitize_textarea_field( $payload['prompt'] ?? '' );

		if ( empty( $prompt ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'A prompt is required to generate a template.', 'antimanual' ),
			] );
		}

		$instructions = "You are an expert email template designer. Generate a complete, responsive HTML email template based on the user's description below.

Requirements:
- Output a COMPLETE HTML document (<!DOCTYPE html> through </html>)
- Use only inline CSS styles (no <style> blocks or external stylesheets)
- Make it mobile-responsive using table-based layout (600px content width)
- Use professional, clean design with adequate whitespace
- Include these dynamic placeholders in appropriate locations:
  {{content}} — where the email body content will be injected
  {{site_name}} — the website/brand name
  {{site_url}} — link to the website
  {{year}} — current year for copyright
  {{unsubscribe_url}} — unsubscribe link (MUST be included in the footer)
  {{preview_text}} — hidden preview text at the very top
  {{header_content}} — optional custom header area
  {{footer_content}} — optional custom footer area
- The {{content}} placeholder is the most important — it must be clearly placed in the main body area
- Include a sensible header with {{site_name}} and a footer with copyright and unsubscribe link

Respond with ONLY the raw HTML code. No markdown code fences, no explanation, no preamble.

User's template description:
" . $prompt;

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $instructions,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response->get_error_message(),
			] );
		}

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		// Strip markdown code fences if present.
		$html = trim( (string) $response );
		$html = preg_replace( '/^```html\s*/i', '', $html );
		$html = preg_replace( '/^```\s*/i', '', $html );
		$html = preg_replace( '/\s*```$/i', '', $html );

		if ( empty( $html ) || stripos( $html, '<' ) === false ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to generate template. Please try a different prompt.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'html' => $html ],
		] );
	}

	/**
	 * AI-fix a specific spam/deliverability issue in the email copy.
	 *
	 * Returns updated subject, preview text, and content so the editor can
	 * apply the fix in-place and immediately re-run the spam analysis.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function resolve_spam_issue( $request ) {
		$payload       = json_decode( $request->get_body(), true ) ?: [];
		$issue_message = sanitize_text_field( $payload['issue_message'] ?? '' );
		$subject       = sanitize_text_field( $payload['subject'] ?? '' );
		$preview_text  = sanitize_text_field( $payload['preview_text'] ?? '' );
		$content       = wp_kses_post( $payload['content'] ?? '' );

		if ( empty( $issue_message ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'A spam issue is required.', 'antimanual' ),
			] );
		}

		if ( empty( $subject ) && empty( $content ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Subject or content is required.', 'antimanual' ),
			] );
		}

		$issue_type = $this->detect_spam_issue_type( $issue_message );

		$fix_instructions = [
			'personalization' => 'Fix the missing-personalization problem. Replace generic greetings like "Hi there" with a tasteful personalization token such as {{first_name}} where it feels natural. Keep the brand voice warm and human, and do not over-personalize.',
			'urgency'         => 'Reduce spammy urgency and sales pressure. Tone down phrases like hurry, last chance, final hours, expires soon, and once it\'s gone. Keep the offer compelling but calmer, clearer, and more trustworthy.',
			'repetition'      => 'Reduce repetitive promotional wording and repeated discount/offer phrases across the subject and body. Keep the core offer, but vary the wording and make the copy sound less repetitive and less like bulk promotion.',
			'formatting'      => 'Fix deliverability-unfriendly capitalization and punctuation. Remove ALL CAPS, excessive exclamation/question marks, and any formatting that feels shouty, while preserving the original meaning and hierarchy.',
			'links'           => 'Improve link trust and link-related copy. Keep existing destinations whenever possible, prefer clear descriptive anchor text, avoid shortened-link style wording, and do not introduce suspicious-looking raw URLs.',
			'image_ratio'     => 'Improve the text-to-image balance by adding concise explanatory copy around visual elements, strengthening the written context and value so the email feels informative rather than image-heavy.',
			'general'         => 'Resolve the deliverability issue as precisely as possible while preserving the same offer, structure, intent, and important details.',
		];

		$prompt = "You are an expert email deliverability copy editor. Fix ONE specific spam or inbox-placement issue in the campaign below.\n\n" .
			"Issue to fix: {$issue_message}\n" .
			"Issue category: {$issue_type}\n\n" .
			"Primary instruction: {$fix_instructions[ $issue_type ]}\n\n" .
			"Rules:\n" .
			"- Preserve the campaign's meaning, offer, call-to-action, and important details.\n" .
			"- Preserve HTML formatting where appropriate.\n" .
			"- Keep placeholders like {{first_name}}, {{site_name}}, {{unsubscribe_url}}, and {{year}} intact if present.\n" .
			"- Do not add explanations, notes, or markdown fences.\n" .
			"- Update only what is needed to resolve the issue and improve deliverability.\n" .
			"- If preview text does not need changes, return it unchanged.\n\n" .
			"Current subject:\n{$subject}\n\n" .
			"Current preview text:\n{$preview_text}\n\n" .
			"Current email HTML/body:\n{$content}\n\n" .
			"Respond in this exact JSON format:\n" .
			"{\n" .
			"  \"summary\": \"Short description of what you improved\",\n" .
			"  \"subject\": \"Updated subject line\",\n" .
			"  \"preview_text\": \"Updated preview text\",\n" .
			"  \"content\": \"Updated HTML email body\"\n" .
			"}";

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		], '', '', 2500 );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response->get_error_message(),
			] );
		}

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		$raw = trim( (string) $response );
		$raw = preg_replace( '/^```json\s*/i', '', $raw );
		$raw = preg_replace( '/^```\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/i', '', $raw );

		$parsed = json_decode( $raw, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $parsed ) || ! isset( $parsed['content'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to generate a spam fix. Please try again.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'issue_type'   => $issue_type,
				'summary'      => sanitize_text_field( $parsed['summary'] ?? __( 'Updated the copy to improve deliverability.', 'antimanual' ) ),
				'subject'      => sanitize_text_field( $parsed['subject'] ?? $subject ),
				'preview_text' => sanitize_text_field( $parsed['preview_text'] ?? $preview_text ),
				'content'      => wp_kses_post( $parsed['content'] ?? $content ),
			],
		] );
	}

	/**
	 * Infer a normalized spam issue type from the issue message.
	 *
	 * @param string $message Spam issue message.
	 * @return string
	 */
	private function detect_spam_issue_type( string $message ): string {
		$normalized = strtolower( $message );

		if ( false !== strpos( $normalized, 'personaliz' ) || false !== strpos( $normalized, 'generic' ) || false !== strpos( $normalized, 'hi there' ) ) {
			return 'personalization';
		}

		if ( false !== strpos( $normalized, 'spam trigger' ) || false !== strpos( $normalized, 'urgency' ) || false !== strpos( $normalized, 'salesy' ) || false !== strpos( $normalized, 'pushy' ) || false !== strpos( $normalized, 'hurry' ) || false !== strpos( $normalized, 'last chance' ) || false !== strpos( $normalized, 'final hours' ) ) {
			return 'urgency';
		}

		if ( false !== strpos( $normalized, 'repetition' ) || false !== strpos( $normalized, 'repeated' ) || false !== strpos( $normalized, 'density' ) || false !== strpos( $normalized, 'clustered' ) ) {
			return 'repetition';
		}

		if ( false !== strpos( $normalized, 'capital' ) || false !== strpos( $normalized, 'punctuation' ) || false !== strpos( $normalized, 'all caps' ) || false !== strpos( $normalized, 'exclamation' ) ) {
			return 'formatting';
		}

		if ( false !== strpos( $normalized, 'link' ) || false !== strpos( $normalized, 'https' ) || false !== strpos( $normalized, 'bitly' ) || false !== strpos( $normalized, 'shortener' ) ) {
			return 'links';
		}

		if ( false !== strpos( $normalized, 'text-to-image' ) || false !== strpos( $normalized, 'image ratio' ) || false !== strpos( $normalized, 'image-heavy' ) ) {
			return 'image_ratio';
		}

		return 'general';
	}

	/**
	 * AI-powered spam score check for email subject and content.
	 *
	 * Analyzes for common deliverability issues like spam trigger
	 * words, ALL CAPS, excessive punctuation, and missing best practices.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function check_spam_score( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];
		$subject = sanitize_text_field( $payload['subject'] ?? '' );
		$content = wp_kses_post( $payload['content'] ?? '' );

		if ( empty( $subject ) && empty( $content ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Subject or content is required.', 'antimanual' ),
			] );
		}

		$plain_content = wp_trim_words( wp_strip_all_tags( $content ), 200 );

		$prompt = "You are an email deliverability expert. Analyze this email for spam triggers and deliverability issues.

Subject: {$subject}

Email content:
{$plain_content}

Check for:
1. Spam trigger words (free, buy now, limited time, act now, etc.)
2. Excessive capitalization or punctuation (!!!, ???, ALL CAPS)
3. Missing personalization
4. Suspicious link patterns
5. Poor text-to-image ratio concerns
6. Overly salesy/pushy language

NOTE: Do NOT flag missing unsubscribe link or CAN-SPAM compliance — the unsubscribe link is automatically inserted in the email footer by the system.

Respond in this exact JSON format:
{
  \"score\": 85,
  \"label\": \"Good\",
  \"issues\": [
    {\"severity\": \"warning\", \"message\": \"Description of issue\"},
    {\"severity\": \"critical\", \"message\": \"Description of critical issue\"}
  ],
  \"tips\": [\"Actionable tip 1\", \"Actionable tip 2\"]
}

Score 0-100 where 100 = perfect deliverability. Label: Excellent (90-100), Good (70-89), Fair (50-69), Poor (below 50). Be helpful but honest.";

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response->get_error_message(),
			] );
		}

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		// Parse the JSON response.
		$raw = trim( (string) $response );
		$raw = preg_replace( '/^```json\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/i', '', $raw );

		$parsed = json_decode( $raw, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $parsed['score'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to analyze spam score.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'score'  => max( 0, min( 100, intval( $parsed['score'] ) ) ),
				'label'  => sanitize_text_field( $parsed['label'] ?? '' ),
				'issues' => array_map( function ( $issue ) {
					return [
						'severity' => sanitize_key( $issue['severity'] ?? 'warning' ),
						'message'  => sanitize_text_field( $issue['message'] ?? '' ),
					];
				}, $parsed['issues'] ?? [] ),
				'tips'   => array_map( 'sanitize_text_field', $parsed['tips'] ?? [] ),
			],
		] );
	}

	/**
	 * AI-generated performance insights for a sent campaign.
	 *
	 * Analyzes open rate, click rate, delivery rate, and provides
	 * actionable recommendations for future campaigns.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	/**
	 * Resend a campaign to only the previously-failed recipients.
	 *
	 * Looks up failed entries in the send log, re-sends the campaign
	 * email to those subscribers, and updates the log entries.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function resend_failed( $request ) {
		global $wpdb;

		$campaign_id     = absint( $request['id'] );
		$campaigns_table = \Antimanual\EmailCampaignsDB::get_campaigns_table();
		$log_table       = \Antimanual\EmailCampaignsDB::get_send_log_table();
		$sub_table       = \Antimanual\EmailSubscribers::get_table_name();

		// Get campaign data.
		$campaign = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, subject, content, template_id, preview_text, target_lists
			FROM {$campaigns_table}
			WHERE id = %d LIMIT 1",
			$campaign_id
		), ARRAY_A );

		if ( ! $campaign ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Campaign not found.', 'antimanual' ),
			] );
		}

		if ( empty( $campaign['content'] ) || empty( $campaign['subject'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Campaign has no content or subject to resend.', 'antimanual' ),
			] );
		}

		// Get failed log entries with subscriber info.
		$failed_entries = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.id as log_id, l.subscriber_id, s.email, s.name as subscriber_name, s.status as sub_status
			FROM {$log_table} l
			LEFT JOIN {$sub_table} s ON l.subscriber_id = s.id
			WHERE l.campaign_id = %d AND l.status = 'failed'
			LIMIT 500",
			$campaign_id
		), ARRAY_A );

		if ( empty( $failed_entries ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No failed recipients found for this campaign.', 'antimanual' ),
			] );
		}

		// Only resend to active subscribers with valid emails.
		$eligible = array_filter( $failed_entries, function ( $entry ) {
			return ! empty( $entry['email'] )
				&& is_email( $entry['email'] )
				&& ( $entry['sub_status'] ?? '' ) === 'active';
		} );

		if ( empty( $eligible ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No active subscribers among failed recipients.', 'antimanual' ),
			] );
		}

		$headers  = EmailCampaign::get_resend_headers();
		$resent   = 0;
		$re_failed = 0;

		foreach ( $eligible as $entry ) {
			$failure_message = __( 'Resend: wp_mail() returned false', 'antimanual' );
			$tracking_url = EmailCampaign::get_open_tracking_url( $campaign_id, (int) $entry['subscriber_id'], (int) $entry['log_id'], $entry['email'] );
			$click_context = [
				'campaign_id'   => $campaign_id,
				'subscriber_id' => (int) $entry['subscriber_id'],
				'log_id'        => (int) $entry['log_id'],
				'email'         => $entry['email'],
			];

			$html = EmailCampaign::render_resend_html(
				$campaign['content'],
				$campaign['subject'],
				$entry['email'],
				$entry['subscriber_name'] ?? '',
				$campaign,
				$tracking_url,
				$click_context
			);

			$result = wp_mail( $entry['email'], $campaign['subject'], $html, $headers );

			// Update the existing log entry.
			$wpdb->update(
				$log_table,
				[
					'status'        => $result ? 'sent' : 'failed',
					'sent_at'       => current_time( 'mysql', true ),
					'error_message' => $result ? null : $failure_message,
				],
				[ 'id' => (int) $entry['log_id'] ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);

			if ( $result ) {
				$resent++;
			} else {
				$re_failed++;
				EmailCampaign::maybe_auto_remove_failed_subscriber( (int) $entry['subscriber_id'] );
			}
		}

		// Update campaign total_sent.
		if ( $resent > 0 ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$campaigns_table} SET total_sent = total_sent + %d, last_sent_at = %s WHERE id = %d",
				$resent,
				current_time( 'mysql', true ),
				$campaign_id
			) );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'resent'    => $resent,
				'failed'    => $re_failed,
				'total'     => count( $eligible ),
			],
			'message' => sprintf(
				/* translators: 1: number resent, 2: total eligible */
				__( 'Resent %1$d of %2$d failed recipients.', 'antimanual' ),
				$resent,
				count( $eligible )
			),
		] );
	}

	/**
	 * Get subscriber health report — identifies repeatedly-failing emails.
	 *
	 * Returns subscribers that have failed across multiple campaigns,
	 * helping users clean their list to save money on email sends.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_subscriber_health() {
		global $wpdb;

		$log_table = \Antimanual\EmailCampaignsDB::get_send_log_table();
		$sub_table = \Antimanual\EmailSubscribers::get_table_name();

		// Find subscribers with failures, ordered by failure count.
		$results = $wpdb->get_results(
			"SELECT
				s.id as subscriber_id,
				s.email,
				s.name as subscriber_name,
				s.status as sub_status,
				COUNT(CASE WHEN l.status = 'failed' THEN 1 END) as fail_count,
				COUNT(CASE WHEN l.status = 'sent' THEN 1 END) as success_count,
				COUNT(*) as total_sends,
				MAX(l.sent_at) as last_attempt,
				MAX(CASE WHEN l.status = 'failed' THEN l.error_message END) as last_error
			FROM {$log_table} l
			JOIN {$sub_table} s ON l.subscriber_id = s.id
			GROUP BY s.id, s.email, s.name, s.status
			HAVING fail_count >= 1
			ORDER BY fail_count DESC, total_sends DESC
			LIMIT 50",
			ARRAY_A
		);

		$items = [];
		foreach ( ( $results ?: [] ) as $row ) {
			$total      = (int) $row['total_sends'];
			$fail_count = (int) $row['fail_count'];
			$success    = (int) $row['success_count'];
			$fail_rate  = $total > 0 ? round( ( $fail_count / $total ) * 100, 1 ) : 0;

			// Determine risk level.
			$risk = 'low';
			if ( $fail_count >= 3 || $fail_rate >= 75 ) {
				$risk = 'high';
			} elseif ( $fail_count >= 2 || $fail_rate >= 50 ) {
				$risk = 'medium';
			}

			$items[] = [
				'subscriber_id'   => (int) $row['subscriber_id'],
				'email'           => $row['email'],
				'name'            => $row['subscriber_name'] ?? '',
				'status'          => $row['sub_status'] ?? 'active',
				'fail_count'      => $fail_count,
				'success_count'   => $success,
				'total_sends'     => $total,
				'fail_rate'       => $fail_rate,
				'risk'            => $risk,
				'last_attempt'    => $row['last_attempt'],
				'last_error'      => $row['last_error'] ?? '',
			];
		}

		$high_risk_count = count( array_filter( $items, fn( $i ) => $i['risk'] === 'high' ) );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'items'           => $items,
				'total'           => count( $items ),
				'high_risk_count' => $high_risk_count,
			],
		] );
	}

	/**
	 * Get user agent statistics for a campaign.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_user_agent_stats( $request ) {
		$campaign_id = absint( $request['id'] );

		if ( ! $campaign_id ) {
			return new \WP_Error( 'invalid_campaign', esc_html__( 'Invalid campaign ID.', 'antimanual' ), [ 'status' => 400 ] );
		}

		$stats = \Antimanual\EmailCampaign::get_user_agent_stats( $campaign_id );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $stats,
		] );
	}

	public function get_ai_insights( $request ) {
		$campaign_id = absint( $request['id'] );

		global $wpdb;
		$campaigns_table = \Antimanual\EmailCampaignsDB::get_campaigns_table();
		$log_table       = \Antimanual\EmailCampaignsDB::get_send_log_table();

		$campaign = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, subject, content, ai_topic, ai_tone,
				total_sent, total_opened, total_clicked, total_replied,
				total_forwarded, total_spam_reported, total_unsubscribed,
				template_id, schedule_type, last_sent_at, preview_text
			FROM {$campaigns_table}
			WHERE id = %d LIMIT 1",
			$campaign_id
		), ARRAY_A );

		if ( ! $campaign ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Campaign not found.', 'antimanual' ),
			] );
		}

		$sent       = (int) ( $campaign['total_sent'] ?? 0 );
		$opened     = (int) ( $campaign['total_opened'] ?? 0 );
		$clicked    = (int) ( $campaign['total_clicked'] ?? 0 );

		// Get delivery stats.
		$log_counts = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) as total,
				SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as delivered,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			FROM {$log_table}
			WHERE campaign_id = %d",
			$campaign_id
		), ARRAY_A );

		$delivered     = (int) ( $log_counts['delivered'] ?? $sent );
		$failed        = (int) ( $log_counts['failed'] ?? 0 );
		$attempted     = max( (int) ( $log_counts['total'] ?? 0 ), $delivered + $failed, $sent + $failed );

		if ( $attempted === 0 ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'This campaign has not been sent yet.', 'antimanual' ),
			] );
		}

		$delivery_rate   = $attempted > 0 ? round( ( $delivered / $attempted ) * 100, 1 ) : 0;
		$open_rate       = $delivered > 0 ? round( ( $opened / $delivered ) * 100, 1 ) : 0;
		$click_rate      = $delivered > 0 ? round( ( $clicked / $delivered ) * 100, 1 ) : 0;
		$subject         = $campaign['subject'] ?? '';
		$content_preview = wp_trim_words( wp_strip_all_tags( $campaign['content'] ?? '' ), 60 );

		$prompt = "You are an email campaign expert. Analyze this campaign's performance and provide actionable insights.

Campaign: {$campaign['name']}
Subject Line: {$subject}
Preview Text: {$campaign['preview_text']}
Template: {$campaign['template_id']}
Schedule Type: {$campaign['schedule_type']}
Content Preview: {$content_preview}

Performance Metrics:
- Delivery Attempts: {$attempted}
- Delivered: {$delivered} ({$delivery_rate}%)
- Opened: {$opened} ({$open_rate}%)
- Clicked: {$clicked} ({$click_rate}%)
- Failed: {$failed}

IMPORTANT CONTEXT:
- Open and click tracking are enabled, but some mail clients may still under-report opens.
- Use the actual engagement metrics above without inventing extra data.
- Focus on deliverability, open/click engagement, subject clarity, preview text quality, send timing, segmentation, and next-step actions.

Respond in this exact JSON format:
{
  \"summary\": \"A 2-3 sentence overall performance summary\",
  \"strengths\": [\"What worked well\", \"Another strength\"],
  \"improvements\": [\"Specific actionable improvement\", \"Another suggestion\"],
  \"subject_analysis\": \"Brief analysis of the subject line effectiveness\",
  \"next_campaign_tips\": [\"Specific tip for the next campaign\", \"Another tip\"]
}

Be specific, data-driven, and helpful. Reference the actual numbers.";

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response->get_error_message(),
			] );
		}

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $response['error'],
			] );
		}

		$raw = trim( (string) $response );
		$raw = preg_replace( '/^```json\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/i', '', $raw );

		$parsed = json_decode( $raw, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $parsed['summary'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Failed to generate insights.', 'antimanual' ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'summary'            => sanitize_text_field( $parsed['summary'] ?? '' ),
				'strengths'          => array_map( 'sanitize_text_field', $parsed['strengths'] ?? [] ),
				'improvements'       => array_map( 'sanitize_text_field', $parsed['improvements'] ?? [] ),
				'subject_analysis'   => sanitize_text_field( $parsed['subject_analysis'] ?? '' ),
				'next_campaign_tips' => array_map( 'sanitize_text_field', $parsed['next_campaign_tips'] ?? [] ),
			],
		] );
	}

	/**
	 * Get subscriber counts per resend segment for a campaign.
	 *
	 * Segments:
	 *  - failed:      subscriber has failures but no successful delivery yet
	 *  - not-opened:  subscriber received the campaign but never opened/clicked it
	 *  - opened:      subscriber opened the campaign
	 *  - clicked:     subscriber clicked at least one tracked link
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_resend_segments( $request ) {
		$campaign_id = absint( $request['id'] );
		$summaries   = $this->get_campaign_segment_subscriber_summaries( $campaign_id );
		$counts      = [
			'failed'     => 0,
			'not_opened' => 0,
			'opened'     => 0,
			'clicked'    => 0,
			'total'      => count( $summaries ),
		];

		foreach ( $summaries as $summary ) {
			if ( $this->subscriber_matches_segment( $summary, 'failed' ) ) {
				$counts['failed']++;
			}

			if ( $this->subscriber_matches_segment( $summary, 'not-opened' ) ) {
				$counts['not_opened']++;
			}

			if ( $this->subscriber_matches_segment( $summary, 'opened' ) ) {
				$counts['opened']++;
			}

			if ( $this->subscriber_matches_segment( $summary, 'clicked' ) ) {
				$counts['clicked']++;
			}
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'failed'     => (int) ( $counts['failed'] ?? 0 ),
				'not_opened' => (int) ( $counts['not_opened'] ?? 0 ),
				'opened'     => (int) ( $counts['opened'] ?? 0 ),
				'clicked'    => (int) ( $counts['clicked'] ?? 0 ),
				'total'      => (int) ( $counts['total'] ?? 0 ),
			],
		] );
	}

	/**
	 * Fetch unique active subscribers with aggregated engagement flags for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_campaign_segment_subscriber_summaries( $campaign_id ) {
		global $wpdb;

		$log_table = \Antimanual\EmailCampaignsDB::get_send_log_table();
		$sub_table = \Antimanual\EmailSubscribers::get_table_name();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				l.subscriber_id,
				MAX(s.email) as email,
				MAX(s.name) as subscriber_name,
				MAX(CASE WHEN l.status = 'failed' THEN 1 ELSE 0 END) as has_failed,
				MAX(CASE WHEN l.status = 'sent' THEN 1 ELSE 0 END) as has_sent,
				MAX(CASE WHEN l.opened_at IS NOT NULL THEN 1 ELSE 0 END) as has_opened,
				MAX(CASE WHEN l.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as has_clicked,
				MAX(l.sent_at) as last_sent_at
			FROM {$log_table} l
			JOIN {$sub_table} s ON l.subscriber_id = s.id AND s.status = 'active'
			WHERE l.campaign_id = %d
			GROUP BY l.subscriber_id
			ORDER BY last_sent_at DESC",
			$campaign_id
		), ARRAY_A ) ?: [];

		return array_values( array_filter( array_map( function ( $row ) {
			$email = sanitize_email( $row['email'] ?? '' );

			if ( ! is_email( $email ) ) {
				return null;
			}

			return [
				'subscriber_id'   => (int) ( $row['subscriber_id'] ?? 0 ),
				'email'           => $email,
				'subscriber_name' => sanitize_text_field( $row['subscriber_name'] ?? '' ),
				'has_failed'      => ! empty( $row['has_failed'] ),
				'has_sent'        => ! empty( $row['has_sent'] ),
				'has_opened'      => ! empty( $row['has_opened'] ),
				'has_clicked'     => ! empty( $row['has_clicked'] ),
				'last_sent_at'    => sanitize_text_field( $row['last_sent_at'] ?? '' ),
			];
		}, $rows ) ) );
	}

	/**
	 * Determine whether a subscriber summary belongs to a resend/follow-up segment.
	 *
	 * @param array  $summary Subscriber engagement summary.
	 * @param string $segment Segment key.
	 * @return bool
	 */
	private function subscriber_matches_segment( array $summary, $segment ) {
		switch ( $segment ) {
			case 'failed':
				return ! empty( $summary['has_failed'] ) && empty( $summary['has_sent'] );
			case 'not-opened':
				return ! empty( $summary['has_sent'] ) && empty( $summary['has_opened'] ) && empty( $summary['has_clicked'] );
			case 'opened':
				return ! empty( $summary['has_opened'] );
			case 'clicked':
				return ! empty( $summary['has_clicked'] );
			default:
				return false;
		}
	}

	/**
	 * Get eligible subscribers for a campaign segment.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $segment     Segment key.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_segment_subscribers( $campaign_id, $segment ) {
		$summaries = $this->get_campaign_segment_subscriber_summaries( $campaign_id );

		return array_values( array_filter( $summaries, function ( $summary ) use ( $segment ) {
			return $this->subscriber_matches_segment( $summary, $segment );
		} ) );
	}

	/**
	 * Create a hidden/generated list for a follow-up campaign audience.
	 *
	 * @param array  $campaign       Source campaign.
	 * @param string $segment        Segment key.
	 * @param int    $recipient_count Number of recipients in the generated audience.
	 * @return array<string, string>
	 */
	private function create_generated_follow_up_list( array $campaign, $segment, $recipient_count ) {
		$lists         = $this->get_normalized_subscriber_lists( false );
		$segment_label = $this->get_segment_label( $segment );
		$source_name   = sanitize_text_field( $campaign['name'] ?? $campaign['subject'] ?? __( 'Campaign', 'antimanual' ) );
		$base_slug     = sanitize_title( $source_name ?: 'campaign' );

		do {
			$list_id = sanitize_key( sprintf( 'segment-%1$d-%2$s-%3$s', (int) ( $campaign['id'] ?? 0 ), $segment, strtolower( wp_generate_password( 4, false, false ) ) ) );
		} while ( in_array( $list_id, array_column( $lists, 'id' ), true ) );

		$list_name = sprintf(
			/* translators: 1: source campaign name, 2: segment label, 3: recipient count */
			__( '%1$s — %2$s follow-up (%3$d)', 'antimanual' ),
			$source_name ?: ucfirst( str_replace( '-', ' ', $base_slug ) ),
			$segment_label,
			$recipient_count
		);

		$lists[] = [
			'id'         => $list_id,
			'name'       => $list_name,
			'created_at' => current_time( 'mysql', true ),
		];

		update_option( 'atml_email_subscriber_lists', array_values( $lists ), false );

		return [
			'id'   => $list_id,
			'name' => $list_name,
		];
	}

	/**
	 * Attach a generated list tag to a set of subscribers.
	 *
	 * @param string $list_id        List ID.
	 * @param int[]  $subscriber_ids Subscriber IDs.
	 * @return void
	 */
	private function add_generated_list_to_subscribers( $list_id, array $subscriber_ids ) {
		global $wpdb;

		$subscriber_ids = array_values( array_filter( array_map( 'absint', $subscriber_ids ) ) );

		if ( empty( $list_id ) || empty( $subscriber_ids ) ) {
			return;
		}

		$table        = EmailSubscribers::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $subscriber_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, tags FROM $table WHERE id IN ($placeholders)",
			...$subscriber_ids
		) );

		foreach ( $rows as $row ) {
			$tags = array_values( array_unique( array_filter( explode( ',', (string) $row->tags ), 'strlen' ) ) );

			if ( in_array( $list_id, $tags, true ) ) {
				continue;
			}

			$tags[] = $list_id;

			$wpdb->update(
				$table,
				[ 'tags' => implode( ',', $tags ) ],
				[ 'id' => (int) $row->id ],
				[ '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * Human-friendly segment label.
	 *
	 * @param string $segment Segment key.
	 * @return string
	 */
	private function get_segment_label( $segment ) {
		$labels = [
			'failed'     => __( 'Failed', 'antimanual' ),
			'not-opened' => __( 'Not Opened', 'antimanual' ),
			'opened'     => __( 'Opened', 'antimanual' ),
			'clicked'    => __( 'Clicked', 'antimanual' ),
		];

		return $labels[ $segment ] ?? ucfirst( str_replace( '-', ' ', $segment ) );
	}

	/**
	 * Create a new follow-up draft campaign targeted to a chosen segment.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function create_follow_up_draft( $request ) {
		$campaign_id = absint( $request['id'] );
		$segment     = sanitize_key( $request->get_param( 'segment' ) ?? '' );

		$valid_segments = [ 'failed', 'not-opened', 'opened', 'clicked' ];
		if ( ! in_array( $segment, $valid_segments, true ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Invalid segment.', 'antimanual' ),
			] );
		}

		$campaign = EmailCampaign::get( $campaign_id );

		if ( is_wp_error( $campaign ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $campaign->get_error_message(),
			] );
		}

		$entries = $this->get_segment_subscribers( $campaign_id, $segment );

		if ( empty( $entries ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No eligible subscribers found for this segment.', 'antimanual' ),
			] );
		}

		$list = $this->create_generated_follow_up_list( $campaign, $segment, count( $entries ) );
		$this->add_generated_list_to_subscribers( $list['id'], array_column( $entries, 'subscriber_id' ) );

		$segment_label = $this->get_segment_label( $segment );
		$follow_up     = EmailCampaign::create( [
			'name'                 => sprintf(
				/* translators: 1: source campaign name, 2: segment label */
				__( '%1$s — %2$s Follow-up', 'antimanual' ),
				sanitize_text_field( $campaign['name'] ?? $campaign['subject'] ?? __( 'Campaign', 'antimanual' ) ),
				$segment_label
			),
			'subject'              => sanitize_text_field( $campaign['subject'] ?? '' ),
			'preview_text'         => sanitize_text_field( $campaign['preview_text'] ?? '' ),
			'content'              => wp_kses_post( $campaign['content'] ?? '' ),
			'ai_topic'             => sanitize_textarea_field( $campaign['ai_topic'] ?? '' ),
			'ai_tone'              => sanitize_text_field( $campaign['ai_tone'] ?? 'professional' ),
			'ai_language'          => sanitize_text_field( $campaign['ai_language'] ?? 'English' ),
			'template_id'          => sanitize_key( $campaign['template_id'] ?? 'minimal' ),
			'include_recent_posts' => ! empty( $campaign['include_recent_posts'] ),
			'recent_posts_count'   => (int) ( $campaign['recent_posts_count'] ?? 3 ),
			'schedule_type'        => 'immediate',
			'target_lists'         => [ $list['id'] ],
			'target_subscription_types' => array_filter( explode( ',', (string) ( $campaign['target_subscription_types'] ?? '' ) ), 'strlen' ),
		] );

		if ( is_wp_error( $follow_up ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $follow_up->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: 1: segment label, 2: recipient count */
				__( 'Created a %1$s follow-up draft for %2$d subscribers.', 'antimanual' ),
				strtolower( $segment_label ),
				count( $entries )
			),
			'data'    => [
				'campaign'        => $follow_up,
				'segment'         => $segment,
				'recipients'      => count( $entries ),
				'generated_list'  => $list,
			],
		] );
	}

	/**
	 * Resend a campaign to a specific subscriber segment.
	 *
	 * Supported segments: failed, not-opened, opened, clicked.
	 * Creates new send log entries for each resend attempt.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function resend_segment( $request ) {
		global $wpdb;

		$campaign_id     = absint( $request['id'] );
		$segment         = sanitize_key( $request->get_param( 'segment' ) ?? '' );
		$campaigns_table = \Antimanual\EmailCampaignsDB::get_campaigns_table();
		$log_table       = \Antimanual\EmailCampaignsDB::get_send_log_table();
		$valid_segments = [ 'failed', 'not-opened', 'opened', 'clicked' ];
		if ( ! in_array( $segment, $valid_segments, true ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Invalid segment.', 'antimanual' ),
			] );
		}

		// Get campaign data.
		$campaign = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, subject, content, template_id, preview_text, target_lists, target_subscription_types
			FROM {$campaigns_table}
			WHERE id = %d LIMIT 1",
			$campaign_id
		), ARRAY_A );

		if ( ! $campaign ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Campaign not found.', 'antimanual' ),
			] );
		}

		if ( empty( $campaign['content'] ) || empty( $campaign['subject'] ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Campaign has no content or subject to resend.', 'antimanual' ),
			] );
		}

		$entries = $this->get_segment_subscribers( $campaign_id, $segment );

		if ( empty( $entries ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'No eligible subscribers found for this segment.', 'antimanual' ),
			] );
		}

		$headers   = EmailCampaign::get_resend_headers();
		$resent    = 0;
		$re_failed = 0;

		foreach ( $entries as $entry ) {
			$failure_message = __( 'Resend: wp_mail() returned false', 'antimanual' );
			// Create a new send log entry for the resend.
			$wpdb->insert(
				$log_table,
				[
					'campaign_id'   => $campaign_id,
					'subscriber_id' => (int) $entry['subscriber_id'],
					'subject'       => $campaign['subject'],
					'status'        => 'queued',
					'sent_at'       => current_time( 'mysql', true ),
					'error_message' => null,
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s' ]
			);

			$new_log_id = (int) $wpdb->insert_id;
			$tracking_url = $new_log_id > 0
				? EmailCampaign::get_open_tracking_url( $campaign_id, (int) $entry['subscriber_id'], $new_log_id, $entry['email'] )
				: '';
			$click_context = $new_log_id > 0
				? [
					'campaign_id'   => $campaign_id,
					'subscriber_id' => (int) $entry['subscriber_id'],
					'log_id'        => $new_log_id,
					'email'         => $entry['email'],
				]
				: null;

			$html = EmailCampaign::render_resend_html(
				$campaign['content'],
				$campaign['subject'],
				$entry['email'],
				$entry['subscriber_name'] ?? '',
				$campaign,
				$tracking_url,
				$click_context
			);

			$result = wp_mail( $entry['email'], $campaign['subject'], $html, $headers );

			// Update the new log entry with the result.
			if ( $new_log_id > 0 ) {
				$wpdb->update(
					$log_table,
					[
						'status'        => $result ? 'sent' : 'failed',
						'sent_at'       => current_time( 'mysql', true ),
						'error_message' => $result ? null : $failure_message,
					],
					[ 'id' => $new_log_id ],
					[ '%s', '%s', '%s' ],
					[ '%d' ]
				);
			}

			if ( $result ) {
				$resent++;
			} else {
				$re_failed++;
				EmailCampaign::maybe_auto_remove_failed_subscriber( (int) $entry['subscriber_id'] );
			}
		}

		// Update campaign total_sent.
		if ( $resent > 0 ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$campaigns_table} SET total_sent = total_sent + %d, last_sent_at = %s WHERE id = %d",
				$resent,
				current_time( 'mysql', true ),
				$campaign_id
			) );
		}

		/* translators: 1: segment label, 2: number resent, 3: total eligible */
		$segment_labels = [
			'failed'     => __( 'failed', 'antimanual' ),
			'not-opened' => __( 'not-opened', 'antimanual' ),
			'opened'     => __( 'opened', 'antimanual' ),
			'clicked'    => __( 'clicked', 'antimanual' ),
		];

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'resent'  => $resent,
				'failed'  => $re_failed,
				'total'   => count( $entries ),
				'segment' => $segment,
			],
			'message' => sprintf(
				/* translators: 1: segment label, 2: number resent, 3: total eligible */
				__( 'Resent to %1$d of %2$d %3$s subscribers.', 'antimanual' ),
				$resent,
				count( $entries ),
				$segment_labels[ $segment ] ?? $segment
			),
		] );
	}

	// ─── Sequence (Drip Campaign) Callbacks ──────────────────────────

	/**
	 * List email sequences.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function list_sequences( $request ) {
		$result = \Antimanual\EmailSequence::list_sequences( [
			'search'   => sanitize_text_field( $request->get_param( 'search' ) ?? '' ),
			'page'     => intval( $request->get_param( 'page' ) ?? 1 ),
			'per_page' => intval( $request->get_param( 'per_page' ) ?? 20 ),
		] );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Create a new email sequence.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function create_sequence( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];

		$result = \Antimanual\EmailSequence::create( $payload );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Get a single email sequence with steps.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_sequence( $request ) {
		$result = \Antimanual\EmailSequence::get( intval( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Update an email sequence.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function update_sequence( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];

		$result = \Antimanual\EmailSequence::update( intval( $request['id'] ), $payload );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Delete an email sequence.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_sequence( $request ) {
		$result = \Antimanual\EmailSequence::delete( intval( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Sequence deleted.', 'antimanual' ),
		] );
	}

	/**
	 * Activate a sequence — enroll subscribers and start sending.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function activate_sequence( $request ) {
		$result = \Antimanual\EmailSequence::activate( intval( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of enrolled subscribers */
				__( 'Sequence activated! %d subscribers enrolled.', 'antimanual' ),
				$result['enrolled']
			),
			'data' => $result,
		] );
	}

	/**
	 * AI-generate all email content for a sequence.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_sequence_content( $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$payload = is_array( $payload ) ? $payload : [];

		if ( isset( $payload['steps'] ) && is_string( $payload['steps'] ) ) {
			$decoded_steps = json_decode( wp_unslash( $payload['steps'] ), true );

			if ( is_array( $decoded_steps ) ) {
				$payload['steps'] = $decoded_steps;
			}
		}

		$use_existing_knowledge = filter_var( $payload['use_existing_knowledge'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$file_context           = $this->extract_uploaded_file_context( $request->get_file_params()['files'] ?? $_FILES['files'] ?? [] );
		$knowledge_context      = '';

		if ( $use_existing_knowledge ) {
			$knowledge_context = \Antimanual\KnowledgeContextBuilder::build_context( [], $payload['goal'] ?? '' );

			if ( '' === $knowledge_context ) {
				return rest_ensure_response( [
					'success' => false,
					'message' => __( 'No knowledge base content found. Add content to Knowledge Base or disable "Use Existing Knowledge Base".', 'antimanual' ),
				] );
			}
		}

		$payload['knowledge_context'] = trim( $knowledge_context . "\n\n" . $file_context );

		$result = \Antimanual\EmailSequence::generate_sequence_content( $payload );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'emails' => $result ],
		] );
	}

	/**
	 * Get enrollment/step progress for a sequence.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_sequence_progress( $request ) {
		$result = \Antimanual\EmailSequence::get_progress( intval( $request['id'] ) );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Duplicate a sequence with all its steps as a new draft.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function duplicate_sequence( $request ) {
		$result = \Antimanual\EmailSequence::duplicate( intval( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Pause or resume an active/paused sequence.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function pause_sequence( $request ) {
		$result = \Antimanual\EmailSequence::toggle_pause( intval( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * AI-generate content for a single sequence step.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_sequence_step( $request ) {
		$payload = json_decode( $request->get_body(), true ) ?: [];

		$use_existing_knowledge = filter_var( $payload['use_existing_knowledge'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$knowledge_context      = '';

		if ( $use_existing_knowledge ) {
			$knowledge_context = \Antimanual\KnowledgeContextBuilder::build_context( [], $payload['goal'] ?? '' );
		}

		$payload['knowledge_context'] = $knowledge_context;

		$result = \Antimanual\EmailSequence::generate_single_step( $payload );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}
}
