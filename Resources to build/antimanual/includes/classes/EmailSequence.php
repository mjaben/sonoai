<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\EmailCampaign;
use Antimanual\EmailCampaignsDB;
use Antimanual\EmailSubscribers;

/**
 * Email Sequence Manager
 *
 * Handles sequence (drip campaign) CRUD, AI content generation,
 * subscriber enrollment, and step-by-step email delivery.
 *
 * @package Antimanual
 */
class EmailSequence {

	/**
	 * Create a sequence campaign with its steps.
	 *
	 * @param array $data Sequence data including 'steps' array.
	 * @return array|WP_Error Created campaign with steps.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$table = EmailCampaignsDB::get_campaigns_table();
		$steps_table = EmailCampaignsDB::get_sequence_steps_table();
		EmailCampaignsDB::ensure_tables_exist();

		$name = sanitize_text_field( $data['name'] ?? '' );

		if ( empty( $name ) ) {
			return new \WP_Error( 'empty_name', __( 'Sequence name is required.', 'antimanual' ) );
		}

		$steps = $data['steps'] ?? [];

		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return new \WP_Error( 'no_steps', __( 'At least one email step is required.', 'antimanual' ) );
		}

		$now = current_time( 'mysql', true );

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

		// Create the parent campaign.
		$wpdb->insert(
			$table,
			[
				'name'          => $name,
				'subject'       => '',
				'preview_text'  => '',
				'content'       => '',
				'status'        => 'draft',
				'campaign_type' => 'sequence',
				'schedule_type' => 'immediate',
				'ai_topic'      => sanitize_textarea_field( $data['ai_topic'] ?? '' ),
				'ai_tone'       => sanitize_text_field( $data['ai_tone'] ?? 'professional' ),
				'ai_language'   => sanitize_text_field( $data['ai_language'] ?? 'English' ),
				'template_id'   => sanitize_key( $data['template_id'] ?? 'minimal' ),
				'target_lists'  => $target_lists_raw,
				'target_subscription_types' => $target_subscription_types_raw,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$campaign_id = $wpdb->insert_id;

		if ( ! $campaign_id ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to create sequence.', 'antimanual' ) );
		}

		// Insert steps.
		self::save_steps( $campaign_id, $steps );

		return self::get( $campaign_id );
	}

	/**
	 * Save steps for a sequence campaign. Replaces all existing steps.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $steps       Steps data.
	 */
	private static function save_steps( $campaign_id, array $steps ) {
		global $wpdb;
		$steps_table = EmailCampaignsDB::get_sequence_steps_table();
		$now = current_time( 'mysql', true );

		// Remove existing steps.
		$wpdb->delete( $steps_table, [ 'campaign_id' => $campaign_id ], [ '%d' ] );

		// Insert new steps.
		foreach ( $steps as $index => $step ) {
			$wpdb->insert(
				$steps_table,
				[
					'campaign_id'  => $campaign_id,
					'step_order'   => $index + 1,
					'delay_days'   => max( 0, intval( $step['delay_days'] ?? 0 ) ),
					'delay_hours'  => max( 0, min( 23, intval( $step['delay_hours'] ?? 0 ) ) ),
					'name'         => sanitize_text_field( $step['name'] ?? '' ),
					'subject'      => sanitize_text_field( $step['subject'] ?? '' ),
					'preview_text' => sanitize_text_field( $step['preview_text'] ?? '' ),
					'content'      => wp_kses_post( $step['content'] ?? '' ),
					'ai_topic'     => sanitize_textarea_field( $step['ai_topic'] ?? '' ),
					'created_at'   => $now,
					'updated_at'   => $now,
				],
				[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}
	}

	/**
	 * Determine whether a sequence step has the minimum required content.
	 *
	 * @param array $step Sequence step data.
	 * @return bool
	 */
	private static function is_step_ready( array $step ) {
		return '' !== trim( (string) ( $step['subject'] ?? '' ) )
			&& '' !== trim( (string) ( $step['content'] ?? '' ) );
	}

	/**
	 * Get 1-based step orders that are missing a subject or body.
	 *
	 * @param array $steps Sequence steps.
	 * @return int[]
	 */
	private static function get_incomplete_step_orders( array $steps ) {
		$incomplete = [];

		foreach ( $steps as $index => $step ) {
			if ( self::is_step_ready( $step ) ) {
				continue;
			}

			$incomplete[] = (int) ( $step['step_order'] ?? ( $index + 1 ) );
		}

		return $incomplete;
	}

	/**
	 * Get a sequence campaign with all its steps.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|\WP_Error
	 */
	public static function get( $campaign_id ) {
		global $wpdb;

		$table = EmailCampaignsDB::get_campaigns_table();
		$steps_table = EmailCampaignsDB::get_sequence_steps_table();

		$campaign = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE id = %d AND campaign_type = 'sequence' LIMIT 1",
			$campaign_id
		), ARRAY_A );

		if ( ! $campaign ) {
			return new \WP_Error( 'not_found', __( 'Sequence not found.', 'antimanual' ) );
		}

		$steps = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, step_order, delay_days, delay_hours, name, subject, preview_text, content, ai_topic, created_at, updated_at
			FROM $steps_table
			WHERE campaign_id = %d
			ORDER BY step_order ASC",
			$campaign_id
		), ARRAY_A );

		$campaign['steps'] = $steps ?: [];

		return $campaign;
	}

	/**
	 * Update a sequence campaign and its steps.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $data        Fields to update.
	 * @return array|\WP_Error
	 */
	public static function update( $campaign_id, array $data ) {
		global $wpdb;

		$table = EmailCampaignsDB::get_campaigns_table();
		$campaign = self::get( $campaign_id );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$update = [ 'updated_at' => current_time( 'mysql', true ) ];
		$format = [ '%s' ];

		$allowed_fields = [
			'name'        => '%s',
			'status'      => '%s',
			'ai_topic'    => '%s',
			'ai_tone'     => '%s',
			'ai_language' => '%s',
			'template_id' => '%s',
			'target_lists' => '%s',
			'target_subscription_types' => '%s',
		];

		foreach ( $allowed_fields as $field => $fmt ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			switch ( $field ) {
				case 'ai_topic':
					$update[ $field ] = sanitize_textarea_field( $data[ $field ] );
					break;
				case 'status':
					$valid = [ 'draft', 'active', 'paused', 'completed' ];
					$update[ $field ] = in_array( $data[ $field ], $valid, true ) ? $data[ $field ] : 'draft';
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

		$wpdb->update(
			$table,
			$update,
			[ 'id' => $campaign_id ],
			$format,
			[ '%d' ]
		);

		// Update steps if provided.
		if ( isset( $data['steps'] ) && is_array( $data['steps'] ) ) {
			self::save_steps( $campaign_id, $data['steps'] );
		}

		return self::get( $campaign_id );
	}

	/**
	 * Delete a sequence campaign and all related data.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( $campaign_id ) {
		global $wpdb;

		$campaign_id = absint( $campaign_id );

		if ( ! $campaign_id ) {
			return new \WP_Error( 'invalid_id', __( 'Invalid sequence ID.', 'antimanual' ) );
		}

		$steps_table = EmailCampaignsDB::get_sequence_steps_table();
		$log_table   = EmailCampaignsDB::get_sequence_log_table();
		$send_log    = EmailCampaignsDB::get_send_log_table();
		$campaigns   = EmailCampaignsDB::get_campaigns_table();

		// Clean up in order.
		$wpdb->delete( $log_table, [ 'campaign_id' => $campaign_id ], [ '%d' ] );
		$wpdb->delete( $steps_table, [ 'campaign_id' => $campaign_id ], [ '%d' ] );
		$wpdb->delete( $send_log, [ 'campaign_id' => $campaign_id ], [ '%d' ] );
		$wpdb->delete( $campaigns, [ 'id' => $campaign_id ], [ '%d' ] );

		return true;
	}

	/**
	 * List sequence campaigns.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function list_sequences( $args = [] ) {
		global $wpdb;
		$table = EmailCampaignsDB::get_campaigns_table();
		EmailCampaignsDB::ensure_tables_exist();

		$search   = sanitize_text_field( $args['search'] ?? '' );
		$page     = max( 1, intval( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 50, intval( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [ "campaign_type = 'sequence'" ];
		$params = [];

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = 'name LIKE %s';
			$params[] = $like;
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM $table $where_clause";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: (int) $wpdb->get_var( $count_sql );

		$query        = "SELECT id, name, status, campaign_type, ai_topic, ai_tone, template_id,
			target_lists, target_subscription_types, total_sent, total_opened, last_sent_at, created_at, updated_at
			FROM $table $where_clause
			ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, [ $per_page, $offset ] );
		$items        = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

		// Attach step count for each sequence.
		if ( ! empty( $items ) ) {
			$ids = array_map( 'absint', array_column( $items, 'id' ) );
			$steps_table = EmailCampaignsDB::get_sequence_steps_table();
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$step_counts = $wpdb->get_results( $wpdb->prepare(
				"SELECT campaign_id, COUNT(*) as step_count FROM $steps_table WHERE campaign_id IN ($placeholders) GROUP BY campaign_id",
				...$ids
			), ARRAY_A );

			$count_map = [];
			foreach ( $step_counts as $row ) {
				$count_map[ (int) $row['campaign_id'] ] = (int) $row['step_count'];
			}

			foreach ( $items as &$item ) {
				$item['step_count'] = $count_map[ (int) $item['id'] ] ?? 0;
			}
			unset( $item );
		}

		return [
			'items' => $items ?: [],
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Activate a sequence: enroll all eligible subscribers.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|\WP_Error Enrollment stats.
	 */
	public static function activate( $campaign_id ) {
		global $wpdb;

		$campaign = self::get( $campaign_id );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		if ( empty( $campaign['steps'] ) ) {
			return new \WP_Error( 'no_steps', __( 'Add at least one email step before activating.', 'antimanual' ) );
		}

		$incomplete_steps = self::get_incomplete_step_orders( $campaign['steps'] );

		if ( ! empty( $incomplete_steps ) ) {
			return new \WP_Error(
				'incomplete_steps',
				sprintf(
					/* translators: %s: comma-separated sequence step numbers */
					__( 'Complete the subject and content for every step before activating. Incomplete steps: %s.', 'antimanual' ),
					implode( ', ', $incomplete_steps )
				)
			);
		}

		$table     = EmailCampaignsDB::get_campaigns_table();
		$log_table = EmailCampaignsDB::get_sequence_log_table();
		$now       = current_time( 'mysql', true );

		// Get target subscribers.
		$target_lists              = array_filter( explode( ',', $campaign['target_lists'] ?? '' ), 'strlen' );
		$target_subscription_types = array_filter( explode( ',', $campaign['target_subscription_types'] ?? '' ), 'strlen' );
		$batch                     = 200;
		$offset                    = 0;
		$enrolled                  = 0;

		while ( true ) {
			$subscribers = ( ! empty( $target_lists ) || ! empty( $target_subscription_types ) )
				? EmailSubscribers::get_active_by_lists( $target_lists, $batch, $offset, $target_subscription_types )
				: EmailSubscribers::get_active( $batch, $offset );

			if ( empty( $subscribers ) ) {
				break;
			}

			foreach ( $subscribers as $subscriber ) {
				// Skip if already enrolled.
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM $log_table WHERE campaign_id = %d AND subscriber_id = %d LIMIT 1",
					$campaign_id,
					$subscriber['id']
				) );

				if ( $exists ) {
					continue;
				}

				$first_step = $campaign['steps'][0];

				// Calculate when to send step 1.
				$first_delay_hours = ( intval( $first_step['delay_days'] ?? 0 ) * 24 ) + intval( $first_step['delay_hours'] ?? 0 );
				$next_send = gmdate( 'Y-m-d H:i:s', strtotime( "+{$first_delay_hours} hours", strtotime( $now ) ) );

				$wpdb->insert(
					$log_table,
					[
						'campaign_id'   => $campaign_id,
						'subscriber_id' => $subscriber['id'],
						'current_step'  => 1,
						'status'        => 'active',
						'next_send_at'  => $next_send,
						'started_at'    => $now,
					],
					[ '%d', '%d', '%d', '%s', '%s', '%s' ]
				);

				$enrolled++;
			}

			$offset += $batch;
		}

		// Update campaign status to active.
		$wpdb->update(
			$table,
			[
				'status'     => 'active',
				'updated_at' => $now,
			],
			[ 'id' => $campaign_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return [
			'enrolled'    => $enrolled,
			'campaign_id' => $campaign_id,
		];
	}

	/**
	 * Process sequence steps that are due.
	 *
	 * Called by the lightweight campaign cron on every request.
	 */
	public static function process_due_steps() {
		global $wpdb;

		$log_table   = EmailCampaignsDB::get_sequence_log_table();
		$steps_table = EmailCampaignsDB::get_sequence_steps_table();
		$campaigns   = EmailCampaignsDB::get_campaigns_table();
		$send_log    = EmailCampaignsDB::get_send_log_table();
		$now         = current_time( 'mysql', true );

		// Get due enrollments (limit to 10 per run to avoid long-running requests).
		$due = $wpdb->get_results( $wpdb->prepare(
			"SELECT sl.id as log_id, sl.campaign_id, sl.subscriber_id, sl.current_step,
				c.template_id, c.ai_tone, c.ai_language, c.target_lists, c.target_subscription_types
			FROM $log_table sl
			INNER JOIN $campaigns c ON c.id = sl.campaign_id
			WHERE sl.status = 'active'
			AND sl.next_send_at IS NOT NULL
			AND sl.next_send_at <= %s
			AND c.status = 'active'
			AND c.campaign_type = 'sequence'
			ORDER BY sl.next_send_at ASC
			LIMIT 10",
			$now
		), ARRAY_A );

		if ( empty( $due ) ) {
			return;
		}

		// Batch-fetch the steps and subscribers we need.
		$campaign_ids   = array_unique( array_column( $due, 'campaign_id' ) );
		$subscriber_ids = array_unique( array_column( $due, 'subscriber_id' ) );

		// Fetch all relevant steps.
		$c_placeholders = implode( ', ', array_fill( 0, count( $campaign_ids ), '%d' ) );
		$all_steps      = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, campaign_id, step_order, delay_days, delay_hours, name, subject, preview_text, content
			FROM $steps_table WHERE campaign_id IN ($c_placeholders) ORDER BY step_order ASC",
			...$campaign_ids
		), ARRAY_A );

		// Index steps by campaign_id and step_order.
		$step_map = [];
		foreach ( $all_steps as $step ) {
			$step_map[ (int) $step['campaign_id'] ][ (int) $step['step_order'] ] = $step;
		}

		// Count total steps per campaign.
		$total_steps_map = [];
		foreach ( $step_map as $cid => $steps_arr ) {
			$total_steps_map[ $cid ] = count( $steps_arr );
		}

		// Fetch subscribers.
		$s_placeholders = implode( ', ', array_fill( 0, count( $subscriber_ids ), '%d' ) );
		$subscribers    = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, email, name, subscription_types, custom_fields FROM " . EmailSubscribers::get_table_name() . " WHERE id IN ($s_placeholders) AND status = 'active'",
			...$subscriber_ids
		), ARRAY_A );

		$sub_map = [];
		foreach ( $subscribers as $sub ) {
			$sub_map[ (int) $sub['id'] ] = $sub;
		}

		$em_settings = get_option( 'atml_email_settings', [] );
		$tracking_on = $em_settings['enable_tracking'] ?? true;
		$headers     = EmailCampaign::get_resend_headers();

		foreach ( $due as $entry ) {
			$campaign_id   = (int) $entry['campaign_id'];
			$subscriber_id = (int) $entry['subscriber_id'];
			$current_step  = (int) $entry['current_step'];
			$log_id_entry  = (int) $entry['log_id'];

			$step = $step_map[ $campaign_id ][ $current_step ] ?? null;
			$sub  = $sub_map[ $subscriber_id ] ?? null;

			// Skip if step or subscriber not found.
			if ( ! $step || ! $sub ) {
				$wpdb->update(
					$log_table,
					[ 'status' => 'stopped', 'completed_at' => $now ],
					[ 'id' => $log_id_entry ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
				continue;
			}

			$subject = $step['subject'] ?? '';
			$content = $step['content'] ?? '';

			if ( empty( $content ) || empty( $subject ) ) {
				// Skip steps with no content, move to next.
				self::advance_to_next_step( $log_id_entry, $campaign_id, $current_step, $total_steps_map[ $campaign_id ] ?? 0, $step_map, $now );
				continue;
			}

			// Log the send.
			$wpdb->insert(
				$send_log,
				[
					'campaign_id'      => $campaign_id,
					'subscriber_id'    => $subscriber_id,
					'subject'          => $subject,
					'status'           => 'queued',
					'sent_at'          => $now,
					'sequence_step_id' => (int) $step['id'],
				],
				[ '%d', '%d', '%s', '%s', '%s', '%d' ]
			);

			$sl_id = (int) $wpdb->insert_id;

			$tracking_url = ( $tracking_on && $sl_id > 0 )
				? EmailCampaign::get_open_tracking_url( $campaign_id, $subscriber_id, $sl_id, $sub['email'] )
				: null;
			$click_context = ( $tracking_on && $sl_id > 0 )
				? [
					'campaign_id'   => $campaign_id,
					'subscriber_id' => $subscriber_id,
					'log_id'        => $sl_id,
					'email'         => $sub['email'],
				]
				: [];

			$html = EmailCampaign::render_resend_html(
				$content,
				$subject,
				$sub['email'],
				$sub['name'] ?? '',
				[
					'template_id'  => $entry['template_id'] ?? 'minimal',
					'preview_text' => $step['preview_text'] ?? '',
				],
				$tracking_url,
				$click_context,
				$sub
			);

			$result = wp_mail( $sub['email'], $subject, $html, $headers );

			// Update send log.
			if ( $sl_id > 0 ) {
				$failure_message = __( 'wp_mail() returned false', 'antimanual' );
				$wpdb->update(
					$send_log,
					[
						'status'        => $result ? 'sent' : 'failed',
						'sent_at'       => $now,
						'error_message' => $result ? null : $failure_message,
					],
					[ 'id' => $sl_id ],
					[ '%s', '%s', '%s' ],
					[ '%d' ]
				);
			}

			// Update campaign totals.
			if ( $result ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE $campaigns SET total_sent = total_sent + 1, last_sent_at = %s, updated_at = %s WHERE id = %d",
					$now, $now, $campaign_id
				) );
			} else {
				EmailCampaign::maybe_auto_remove_failed_subscriber( $subscriber_id );
			}

			// Advance to next step or complete.
			self::advance_to_next_step( $log_id_entry, $campaign_id, $current_step, $total_steps_map[ $campaign_id ] ?? 0, $step_map, $now );
		}
	}

	/**
	 * Advance a subscriber to the next step or mark as completed.
	 *
	 * @param int   $log_id       Sequence log entry ID.
	 * @param int   $campaign_id  Campaign ID.
	 * @param int   $current_step Current step order.
	 * @param int   $total_steps  Total steps in sequence.
	 * @param array $step_map     Steps indexed by campaign_id and step_order.
	 * @param string $now         Current MySQL datetime.
	 */
	private static function advance_to_next_step( $log_id, $campaign_id, $current_step, $total_steps, $step_map, $now ) {
		global $wpdb;
		$log_table = EmailCampaignsDB::get_sequence_log_table();

		$next_step_order = $current_step + 1;

		if ( $next_step_order > $total_steps ) {
			// Sequence complete for this subscriber.
			$wpdb->update(
				$log_table,
				[
					'status'       => 'completed',
					'next_send_at' => null,
					'completed_at' => $now,
				],
				[ 'id' => $log_id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

		$next_step = $step_map[ $campaign_id ][ $next_step_order ] ?? null;

		if ( ! $next_step ) {
			$wpdb->update(
				$log_table,
				[
					'status'       => 'completed',
					'next_send_at' => null,
					'completed_at' => $now,
				],
				[ 'id' => $log_id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

		$delay_hours = ( intval( $next_step['delay_days'] ?? 0 ) * 24 ) + intval( $next_step['delay_hours'] ?? 0 );
		$next_send   = gmdate( 'Y-m-d H:i:s', strtotime( "+{$delay_hours} hours", strtotime( $now ) ) );

		$wpdb->update(
			$log_table,
			[
				'current_step' => $next_step_order,
				'next_send_at' => $next_send,
			],
			[ 'id' => $log_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Get enrollment progress for a sequence campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array Progress data.
	 */
	public static function get_progress( $campaign_id ) {
		global $wpdb;

		$log_table   = EmailCampaignsDB::get_sequence_log_table();
		$steps_table = EmailCampaignsDB::get_sequence_steps_table();

		$total_steps = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $steps_table WHERE campaign_id = %d",
			$campaign_id
		) );

		$stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) as total_enrolled,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
				SUM(CASE WHEN status = 'stopped' THEN 1 ELSE 0 END) as stopped
			FROM $log_table
			WHERE campaign_id = %d",
			$campaign_id
		), ARRAY_A );

		// Per-step breakdown.
		$step_breakdown = $wpdb->get_results( $wpdb->prepare(
			"SELECT current_step, COUNT(*) as count
			FROM $log_table
			WHERE campaign_id = %d AND status = 'active'
			GROUP BY current_step
			ORDER BY current_step ASC",
			$campaign_id
		), ARRAY_A );

		$step_counts = [];
		foreach ( $step_breakdown as $row ) {
			$step_counts[ (int) $row['current_step'] ] = (int) $row['count'];
		}

		return [
			'total_steps'    => $total_steps,
			'total_enrolled' => (int) ( $stats['total_enrolled'] ?? 0 ),
			'active'         => (int) ( $stats['active'] ?? 0 ),
			'completed'      => (int) ( $stats['completed'] ?? 0 ),
			'stopped'        => (int) ( $stats['stopped'] ?? 0 ),
			'step_breakdown' => $step_counts,
		];
	}

	/**
	 * Duplicate a sequence with all its steps as a new draft.
	 *
	 * @param int $campaign_id Source sequence campaign ID.
	 * @return array|\WP_Error The new sequence data.
	 */
	public static function duplicate( $campaign_id ) {
		$source = self::get( absint( $campaign_id ) );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$new_name = sprintf(
			/* translators: %s: original sequence name */
			__( '%s (Copy)', 'antimanual' ),
			$source['name']
		);

		$steps = array_map( function ( $step ) {
			unset( $step['id'], $step['created_at'], $step['updated_at'] );
			return $step;
		}, $source['steps'] ?? [] );

		return self::create( [
			'name'         => $new_name,
			'ai_topic'     => $source['ai_topic'] ?? '',
			'ai_tone'      => $source['ai_tone'] ?? 'professional',
			'ai_language'  => $source['ai_language'] ?? 'English',
			'template_id'  => $source['template_id'] ?? 'minimal',
			'target_lists' => $source['target_lists'] ?? '',
			'target_subscription_types' => $source['target_subscription_types'] ?? '',
			'steps'        => $steps,
		] );
	}

	/**
	 * Toggle a sequence between active and paused states.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|\WP_Error Updated sequence data.
	 */
	public static function toggle_pause( $campaign_id ) {
		global $wpdb;

		$campaign = self::get( absint( $campaign_id ) );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$current_status = $campaign['status'] ?? '';

		if ( 'active' === $current_status ) {
			$new_status = 'paused';
		} elseif ( 'paused' === $current_status ) {
			$new_status = 'active';
		} else {
			return new \WP_Error( 'invalid_status', __( 'Only active or paused sequences can be toggled.', 'antimanual' ) );
		}

		$table = EmailCampaignsDB::get_campaigns_table();

		$wpdb->update(
			$table,
			[
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $campaign_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return self::get( $campaign_id );
	}

	/**
	 * Generate content for a single email step in a sequence.
	 *
	 * Receives the full sequence context (goal, other step names) so the AI
	 * can produce content that fits the narrative arc.
	 *
	 * @param array $data Step generation data.
	 * @return array|\WP_Error Generated email data.
	 */
	public static function generate_single_step( array $data ) {
		$goal              = sanitize_textarea_field( $data['goal'] ?? '' );
		$tone              = sanitize_text_field( $data['tone'] ?? 'professional' );
		$language          = sanitize_text_field( $data['language'] ?? 'English' );
		$step_number       = absint( $data['step_number'] ?? 1 );
		$step_name         = sanitize_text_field( $data['step_name'] ?? '' );
		$step_focus        = sanitize_textarea_field( $data['step_focus'] ?? '' );
		$total_steps       = absint( $data['total_steps'] ?? 1 );
		$other_steps       = $data['other_steps'] ?? [];
		$knowledge_context = sanitize_textarea_field( $data['knowledge_context'] ?? '' );
		$site_name         = get_bloginfo( 'name' );
		$site_url          = home_url();

		if ( empty( $goal ) ) {
			return new \WP_Error( 'empty_goal', __( 'Sequence goal is required.', 'antimanual' ) );
		}

		// Build context about other emails in the sequence.
		$other_steps_desc = '';
		foreach ( $other_steps as $os ) {
			$os_name = sanitize_text_field( $os['name'] ?? '' );
			$os_num  = absint( $os['step_order'] ?? 0 );

			if ( $os_num && $os_num !== $step_number ) {
				$other_steps_desc .= "- Email {$os_num}: \"{$os_name}\"\n";
			}
		}

		$knowledge_section = '';
		if ( ! empty( $knowledge_context ) ) {
			$knowledge_section = "\n\nKNOWLEDGE CONTEXT:\n{$knowledge_context}\n";
		}

		$prompt = "You are an expert email marketer. Generate email #{$step_number} of {$total_steps} for \"{$site_name}\" ({$site_url}).

Sequence Goal: {$goal}
Tone: {$tone}
Language: {$language}
{$knowledge_section}
This email is: \"{$step_name}\"" . ( $step_focus ? "\nFocus: {$step_focus}" : '' ) . "

Other emails in the sequence:
{$other_steps_desc}
Requirements:
1. Compelling subject line (under 60 characters)
2. Place in the sequence arc: email {$step_number} of {$total_steps}
3. Use {{name}} as subscriber name placeholder
4. Plain HTML formatting (paragraphs, bold, links)
5. 150-350 words
6. Clear call-to-action
7. If not the first email, subtly reference previous emails

Respond ONLY in this exact JSON format:
{
  \"subject\": \"Subject line\",
  \"preview_text\": \"Short preview text for inbox\",
  \"content\": \"<p>HTML content...</p>\"
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

			$response = is_string( $response ) ? $response : '';
			$json_str = '';

			if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches ) ) {
				$json_str = trim( $matches[1] );
			} else {
				$first_brace = strpos( $response, '{' );
				$last_brace  = strrpos( $response, '}' );

				if ( false !== $first_brace && false !== $last_brace && $last_brace > $first_brace ) {
					$json_str = substr( $response, $first_brace, $last_brace - $first_brace + 1 );
				} else {
					$json_str = trim( $response );
				}
			}

			$parsed = json_decode( $json_str, true );

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $parsed ) ) {
				return new \WP_Error( 'parse_error', __( 'Failed to parse AI response.', 'antimanual' ) );
			}

			return [
				'step'         => $step_number,
				'subject'      => sanitize_text_field( $parsed['subject'] ?? '' ),
				'preview_text' => sanitize_text_field( $parsed['preview_text'] ?? '' ),
				'content'      => wp_kses_post( $parsed['content'] ?? '' ),
			];
		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Generate all emails for a sequence using AI.
	 *
	 * @param array $data Sequence generation data.
	 * @return array|\WP_Error Array of generated steps.
	 */
	public static function generate_sequence_content( array $data ) {
		$goal              = sanitize_textarea_field( $data['goal'] ?? '' );
		$tone              = sanitize_text_field( $data['tone'] ?? 'professional' );
		$language          = sanitize_text_field( $data['language'] ?? 'English' );
		$steps             = $data['steps'] ?? [];
		$knowledge_context = sanitize_textarea_field( $data['knowledge_context'] ?? '' );
		$site_name         = get_bloginfo( 'name' );
		$site_url          = home_url();

		if ( empty( $goal ) ) {
			return new \WP_Error( 'empty_goal', __( 'Sequence goal is required.', 'antimanual' ) );
		}

		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return new \WP_Error( 'no_steps', __( 'At least one step definition is required.', 'antimanual' ) );
		}

		// Build step definitions for the prompt.
		$steps_prompt = '';
		foreach ( $steps as $index => $step ) {
			$step_num  = $index + 1;
			$step_name = sanitize_text_field( $step['name'] ?? "Email {$step_num}" );
			$step_desc = sanitize_textarea_field( $step['ai_topic'] ?? '' );
			$steps_prompt .= "Email {$step_num}: \"{$step_name}\"\n";

			if ( $step_desc ) {
				$steps_prompt .= "  Focus: {$step_desc}\n";
			}
		}

		$total_emails = count( $steps );

		// Build optional knowledge section for the prompt.
		$knowledge_section = '';

		if ( ! empty( $knowledge_context ) ) {
			$knowledge_section = "\n\nKNOWLEDGE CONTEXT (use this information to make emails more specific and accurate):\n{$knowledge_context}\n";
		}

		$prompt = "You are an expert email marketer creating a {$total_emails}-part email sequence for \"{$site_name}\" ({$site_url}).

Sequence Goal: {$goal}
Tone: {$tone}
Language: {$language}
{$knowledge_section}
The sequence emails are:
{$steps_prompt}

Generate ALL {$total_emails} emails for this sequence. Each email must:
1. Have a unique, compelling subject line (under 60 characters)
2. Build on the previous email's message — create a narrative arc
3. Use {{name}} as a placeholder for the subscriber's name  
4. Use plain HTML for formatting (paragraphs, headings, bold, links)
5. Be between 150-350 words each
6. Have a clear call-to-action
7. Reference previous emails subtly (\"As I mentioned...\", \"Building on...\")

Respond ONLY in this exact JSON format:
{
  \"emails\": [
    {
      \"step\": 1,
      \"subject\": \"Subject line for email 1\",
      \"preview_text\": \"Short preview text for inbox\",
      \"content\": \"<p>HTML content for email 1...</p>\"
    },
    {
      \"step\": 2,
      \"subject\": \"Subject line for email 2\",
      \"preview_text\": \"Short preview text for inbox\",
      \"content\": \"<p>HTML content for email 2...</p>\"
    }
  ]
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

			// Parse JSON from response — AI may wrap in code fences or add extra text.
			$response = is_string( $response ) ? $response : '';
			$json_str = '';

			// Try to extract JSON from markdown code fence.
			if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches ) ) {
				$json_str = trim( $matches[1] );
			} else {
				// Fallback: find the first '{' to last '}' in the response.
				$first_brace = strpos( $response, '{' );
				$last_brace  = strrpos( $response, '}' );

				if ( false !== $first_brace && false !== $last_brace && $last_brace > $first_brace ) {
					$json_str = substr( $response, $first_brace, $last_brace - $first_brace + 1 );
				} else {
					$json_str = trim( $response );
				}
			}

			$parsed = json_decode( $json_str, true );

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $parsed['emails'] ) ) {
				return new \WP_Error( 'parse_error', __( 'Failed to parse AI response.', 'antimanual' ) );
			}

			$result = [];
			foreach ( $parsed['emails'] as $email ) {
				$result[] = [
					'step'         => intval( $email['step'] ?? 0 ),
					'subject'      => sanitize_text_field( $email['subject'] ?? '' ),
					'preview_text' => sanitize_text_field( $email['preview_text'] ?? '' ),
					'content'      => wp_kses_post( $email['content'] ?? '' ),
				];
			}

			return $result;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_error', $e->getMessage() );
		}
	}
}
