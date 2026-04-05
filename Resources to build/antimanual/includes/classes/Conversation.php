<?php
/**
 * Conversation Class for the CPT 'atml_conversation'.
 *
 * @package Antimanual
 */

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversation {
	public static $instance         = null;
	public static $post_type        = 'atml_conversation';
	public static $roles            = [ 'user', 'assistant' ];
	public static $meta_messages    = '_atml_conversation_messages';
	public static $meta_provider_id = '_atml_provider_conversation_id';
	public static $meta_provider    = '_atml_conversation_provider';
	
	// Lead collection meta keys
	public static $meta_lead_email  = '_atml_lead_email';
	public static $meta_lead_name   = '_atml_lead_name';
	public static $meta_lead_ip     = '_atml_lead_ip';
	public static $meta_lead_source = '_atml_lead_source';
	public static $meta_lead_note   = '_atml_lead_note';
	public static $meta_lead_status = '_atml_lead_status';

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return Conversation The singleton instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the conversation custom post type.
	 */
	public static function register() {
		$labels = [
			'name'          => __( 'Conversations', 'antimanual' ),
			'singular_name' => __( 'Conversation', 'antimanual' ),
		];

		$args = [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => false,
			'show_in_menu' => false,
			'supports'     => [ 'title', 'custom-fields' ],
		];

		register_post_type( self::$post_type, $args );
	}

	/**
	 * Add a new message to a conversation.
	 *
	 * @param array  $message                  Message data (role, content).
	 * @param int    $conversation_id          Conversation ID (optional).
	 * @param string $provider_conversation_id Provider-specific conversation ID (optional).
	 * @param string $provider                 AI Provider name (optional).
	 * @return int The conversation ID.
	 */
	public static function new_message( array $message, int $conversation_id = 0, string $provider_conversation_id = '', string $provider = '' ) {
		$role    = $message['role'] ?? '';
		$content = $message['content'] ?? '';

		if ( ! in_array( $role, self::$roles, true ) ) {
			return $conversation_id;
		}

		$new_msg = [
			'created_at' => time(),
			'sender'     => $role,
			'message'    => wp_kses_post( $content ),
		];

		$messages = get_post_meta( $conversation_id, self::$meta_messages, true );

		if ( ! is_array( $messages ) ) {
			$messages = [];

			$conversation_id = wp_insert_post(
				[
					'post_title'  => $content,
					'post_status' => 'publish',
					'post_type'   => self::$post_type,
				]
			);

			// Store provider conversation ID
			update_post_meta( $conversation_id, self::$meta_provider_id, $provider_conversation_id );
			
			// Store which provider is used for this conversation
			if ( ! empty( $provider ) ) {
				update_post_meta( $conversation_id, self::$meta_provider, $provider );
			}
		}

		$messages[] = $new_msg;
		update_post_meta( $conversation_id, self::$meta_messages, $messages );

		return $conversation_id;
	}

	public static function list_messages( $conversation_id ) {
		$messages = get_post_meta( $conversation_id, self::$meta_messages, true );

		if ( ! is_array( $messages ) ) {
			$messages = [];
		} else {
			$messages = array_filter( $messages, function( $message ) {
				return isset( $message['created_at'], $message['sender'], $message['message'] );
			} );
		}

		$messages = array_map( function( $message ) {
			$message['created_at'] = isset( $message['created_at'] ) ? $message['created_at'] * 1000 : null;
			return $message;
		}, $messages );

		return $messages;
	}

	/**
	 * Get messages in AI-ready format for sending to providers.
	 * 
	 * This returns messages in the format expected by AI providers:
	 * [ ['role' => 'user'|'assistant', 'content' => '...'], ... ]
	 * 
	 * @param int $conversation_id The conversation post ID.
	 * @return array Array of messages in AI format.
	 */
	public static function get_messages_for_ai( $conversation_id ) {
		$raw_messages = get_post_meta( $conversation_id, self::$meta_messages, true );

		if ( ! is_array( $raw_messages ) ) {
			return [];
		}

		$messages = [];
		foreach ( $raw_messages as $msg ) {
			if ( ! isset( $msg['sender'], $msg['message'] ) ) {
				continue;
			}
			
			$messages[] = [
				'role'    => $msg['sender'], // 'user' or 'assistant'
				'content' => $msg['message'],
			];
		}

		return $messages;
	}

	public static function list_conversations() {
		$conversation_ids = get_posts(
			[
				'post_type'   => self::$post_type,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);

		$conversations = [];

		foreach ( $conversation_ids as $conversation_id ) {
			$lead = self::get_lead( $conversation_id );

			$conversations[] = [
				'id'         => $conversation_id,
				'title'      => get_the_title( $conversation_id ),
				'created_at' => get_the_date( 'U', $conversation_id ) * 1000,
				'messages'   => self::list_messages( $conversation_id ),
				'provider'   => self::get_provider( $conversation_id ),
				'lead_email' => $lead ? $lead['email'] : '',
				'lead_name'  => $lead ? $lead['name'] : '',
			];
		}

		return is_array( $conversations ) ? $conversations : [];
	}

	public static function delete_conversation( int $conversation_id ) {
		$success = wp_delete_post( $conversation_id, true );

		return $success !== null;
	}

	public static function delete_all_conversations() {
		$args = [
			'post_type'      => self::$post_type,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];

		$conversation_ids = get_posts( $args );

		if ( empty( $conversation_ids ) ) {
			return true;
		}

		foreach ( $conversation_ids as $id ) {
			wp_delete_post( $id, true );
		}

		return true;
	}

	public static function get_conversations_count() {
		$query = wp_count_posts( self::$post_type );

		return $query->publish;
	}

	public static function get_messages_count( $conversation_id ) {
		$messages = get_post_meta( $conversation_id, self::$meta_messages, true );

		return is_array( $messages ) ? count( $messages ) : 0;
	}

	/**
	 * Get the provider's conversation ID for a local conversation.
	 * 
	 * For OpenAI, this is the actual conversation ID from their API.
	 * For Gemini, this is a local marker since Gemini doesn't store conversations.
	 * 
	 * @param int $conversation_id The local conversation post ID.
	 * @return string The provider's conversation ID.
	 */
	public static function get_provider_conversation_id( $conversation_id ) {
		// Try new meta key first
		$provider_id = get_post_meta( $conversation_id, self::$meta_provider_id, true );
		
		// Fall back to old OpenAI-specific key for backwards compatibility
		if ( empty( $provider_id ) ) {
			$provider_id = get_post_meta( $conversation_id, '_atml_openai_conversation_id', true );
		}
		
		return $provider_id;
	}

	/**
	 * Backwards-compatible alias for get_provider_conversation_id.
	 * 
	 * @deprecated Use get_provider_conversation_id() instead.
	 * @param int $conversation_id The local conversation post ID.
	 * @return string The provider's conversation ID.
	 */
	public static function get_openai_conversation_id( $conversation_id ) {
		return self::get_provider_conversation_id( $conversation_id );
	}

	/**
	 * Get which AI provider was used for a conversation.
	 * 
	 * @param int $conversation_id The local conversation post ID.
	 * @return string The provider name ('openai' or 'gemini'), or empty if not set.
	 */
	public static function get_provider( $conversation_id ) {
		return get_post_meta( $conversation_id, self::$meta_provider, true );
	}

	/**
	 * Check if a conversation uses Gemini (needs history sent with each request).
	 * 
	 * @param int $conversation_id The local conversation post ID.
	 * @return bool True if this is a Gemini conversation.
	 */
	public static function is_gemini_conversation( $conversation_id ) {
		$provider = self::get_provider( $conversation_id );

		if ( 'gemini' === $provider ) {
			return true;
		}

		// Also check by conversation ID marker
		$provider_id = self::get_provider_conversation_id( $conversation_id );
		return strpos( $provider_id ?? '', 'gemini-conversation-' ) === 0;
	}

	// =========================================
	// Lead Collection Methods
	// =========================================

	/**
	 * Store lead data for a conversation.
	 *
	 * @param int    $conversation_id The conversation post ID.
	 * @param string $email           Lead's email address.
	 * @param string $name            Lead's name (optional).
	 * @param string $source          Source/page URL where lead was captured (optional).
	 * @return bool True on success.
	 */
	public static function set_lead( int $conversation_id, string $email, string $name = '', string $source = '' ) {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return false;
		}

		update_post_meta( $conversation_id, self::$meta_lead_email, sanitize_email( $email ) );
		
		if ( ! empty( $name ) ) {
			update_post_meta( $conversation_id, self::$meta_lead_name, sanitize_text_field( $name ) );
		}
		
		if ( ! empty( $source ) ) {
			update_post_meta( $conversation_id, self::$meta_lead_source, esc_url_raw( $source ) );
		}

		// Store IP address for analytics
		$ip = self::get_client_ip();
		if ( $ip ) {
			update_post_meta( $conversation_id, self::$meta_lead_ip, sanitize_text_field( $ip ) );
		}

		return true;
	}

	/**
	 * Get lead data for a conversation.
	 *
	 * @param int $conversation_id The conversation post ID.
	 * @return array|null Lead data or null if no lead.
	 */
	public static function get_lead( int $conversation_id ) {
		$email = get_post_meta( $conversation_id, self::$meta_lead_email, true );
		
		if ( empty( $email ) ) {
			return null;
		}

		return [
			'email'  => $email,
			'name'   => get_post_meta( $conversation_id, self::$meta_lead_name, true ),
			'ip'     => get_post_meta( $conversation_id, self::$meta_lead_ip, true ),
			'source' => get_post_meta( $conversation_id, self::$meta_lead_source, true ),
			'note'   => get_post_meta( $conversation_id, self::$meta_lead_note, true ),
			'status' => get_post_meta( $conversation_id, self::$meta_lead_status, true ) ?: 'new',
		];
	}

	/**
	 * List all leads with pagination.
	 *
	 * @param int    $page     Page number (1-indexed).
	 * @param int    $per_page Items per page.
	 * @param string $search   Search term for email/name.
	 * @param string $status   Filter by lead status.
	 * @param string $orderby  Sort column.
	 * @param string $order    Sort direction (ASC or DESC).
	 * @return array Array with 'leads', 'total', and 'pages' keys.
	 */
	public static function list_leads( int $page = 1, int $per_page = 20, string $search = '', string $status = '', string $orderby = 'date', string $order = 'DESC' ) {
		$meta_query = [
			[
				'key'     => self::$meta_lead_email,
				'value'   => '',
				'compare' => '!=',
			],
		];

		// Add search if provided.
		if ( ! empty( $search ) ) {
			$meta_query[] = [
				'relation' => 'OR',
				[
					'key'     => self::$meta_lead_email,
					'value'   => $search,
					'compare' => 'LIKE',
				],
				[
					'key'     => self::$meta_lead_name,
					'value'   => $search,
					'compare' => 'LIKE',
				],
			];
		}

		// Filter by status.
		if ( ! empty( $status ) && 'all' !== $status ) {
			if ( 'new' === $status ) {
				// "new" means no status meta set yet.
				$meta_query[] = [
					'relation' => 'OR',
					[
						'key'     => self::$meta_lead_status,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'   => self::$meta_lead_status,
						'value' => 'new',
					],
					[
						'key'     => self::$meta_lead_status,
						'value'   => '',
						'compare' => '=',
					],
				];
			} else {
				$meta_query[] = [
					'key'   => self::$meta_lead_status,
					'value' => $status,
				];
			}
		}

		// Sanitize sort parameters.
		$allowed_order = array( 'ASC', 'DESC' );
		$order         = in_array( strtoupper( $order ), $allowed_order, true ) ? strtoupper( $order ) : 'DESC';

		$args = [
			'post_type'      => self::$post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => $order,
			'meta_query'     => $meta_query,
		];

		$query = new \WP_Query( $args );
		$leads = [];

		if ( ! empty( $query->posts ) ) {
			update_postmeta_cache( wp_list_pluck( $query->posts, 'ID' ) );
		}

		foreach ( $query->posts as $post ) {
			$lead = self::get_lead( $post->ID );
			if ( $lead ) {
				$messages      = self::list_messages( $post->ID );
				$message_count = count( $messages );

				$leads[] = [
					'id'            => $post->ID,
					'email'         => $lead['email'],
					'name'          => $lead['name'],
					'source'        => $lead['source'],
					'ip'            => $lead['ip'],
					'title'         => get_the_title( $post->ID ),
					'created_at'    => get_the_date( 'U', $post->ID ) * 1000,
					'message_count' => $message_count,
					'provider'      => self::get_provider( $post->ID ),
					'note'          => $lead['note'],
					'status'        => $lead['status'],
				];
			}
		}

		return [
			'leads' => $leads,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		];
	}

	/**
	 * Get total leads count.
	 *
	 * @return int Total number of leads.
	 */
	public static function get_leads_count() {
		global $wpdb;
		
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				self::$meta_lead_email
			)
		);
	}

	/**
	 * Get leads statistics.
	 *
	 * @return array Statistics array.
	 */
	public static function get_leads_stats() {
		global $wpdb;
		
		$total_leads = self::get_leads_count();
		$total_conversations = self::get_conversations_count();
		
		// Leads in last 7 days
		$week_ago = gmdate( 'Y-m-d H:i:s', time() - ( 7 * 24 * 60 * 60 ) );
		$leads_this_week = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id) 
				FROM {$wpdb->postmeta} pm 
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
				WHERE pm.meta_key = %s 
				AND pm.meta_value != '' 
				AND p.post_date >= %s",
				self::$meta_lead_email,
				$week_ago
			)
		);

		// Leads in last 30 days
		$month_ago = gmdate( 'Y-m-d H:i:s', time() - ( 30 * 24 * 60 * 60 ) );
		$leads_this_month = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id) 
				FROM {$wpdb->postmeta} pm 
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
				WHERE pm.meta_key = %s 
				AND pm.meta_value != '' 
				AND p.post_date >= %s",
				self::$meta_lead_email,
				$month_ago
			)
		);

		// Conversion rate (leads / conversations)
		$conversion_rate = $total_conversations > 0 
			? round( ( $total_leads / $total_conversations ) * 100, 1 ) 
			: 0;

		return [
			'total'           => $total_leads,
			'this_week'       => $leads_this_week,
			'this_month'      => $leads_this_month,
			'conversion_rate' => $conversion_rate,
		];
	}

	/**
	 * Export leads as CSV data.
	 *
	 * @param string $date_from Start date (Y-m-d format).
	 * @param string $date_to   End date (Y-m-d format).
	 * @return array Array of lead data for CSV export.
	 */
	public static function export_leads( string $date_from = '', string $date_to = '' ) {
		$meta_query = [
			[
				'key'     => self::$meta_lead_email,
				'value'   => '',
				'compare' => '!=',
			],
		];

		$date_query = [];
		if ( ! empty( $date_from ) ) {
			$date_query['after'] = $date_from;
		}
		if ( ! empty( $date_to ) ) {
			$date_query['before'] = $date_to . ' 23:59:59';
		}

		$args = [
			'post_type'      => self::$post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query,
		];

		if ( ! empty( $date_query ) ) {
			$args['date_query'] = [ $date_query ];
		}

		$query = new \WP_Query( $args );
		$leads = [];

		if ( ! empty( $query->posts ) ) {
			update_postmeta_cache( wp_list_pluck( $query->posts, 'ID' ) );
		}

		foreach ( $query->posts as $post ) {
			$lead = self::get_lead( $post->ID );
			if ( $lead ) {
				$leads[] = [
					'Email'         => $lead['email'],
					'Name'          => $lead['name'],
					'Source'        => $lead['source'],
					'Collected At'  => get_the_date( 'Y-m-d H:i:s', $post->ID ),
					'Conversation'  => get_the_title( $post->ID ),
				];
			}
		}

		return $leads;
	}

	/**
	 * Delete lead data from a conversation.
	 *
	 * @param int $conversation_id The conversation post ID.
	 * @return bool True on success.
	 */
	public static function delete_lead( int $conversation_id ) {
		delete_post_meta( $conversation_id, self::$meta_lead_email );
		delete_post_meta( $conversation_id, self::$meta_lead_name );
		delete_post_meta( $conversation_id, self::$meta_lead_ip );
		delete_post_meta( $conversation_id, self::$meta_lead_source );
		delete_post_meta( $conversation_id, self::$meta_lead_note );
		delete_post_meta( $conversation_id, self::$meta_lead_status );

		return true;
	}

	/**
	 * Bulk delete leads.
	 *
	 * @param array $lead_ids Array of lead/conversation IDs.
	 * @return int Number of successfully deleted leads.
	 */
	public static function bulk_delete_leads( array $lead_ids ) {
		$deleted = 0;

		foreach ( $lead_ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 && self::delete_lead( $id ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Update the note for a lead.
	 *
	 * @param int    $conversation_id The conversation post ID.
	 * @param string $note            The note text.
	 * @return bool True on success.
	 */
	public static function update_lead_note( int $conversation_id, string $note ) {
		return (bool) update_post_meta(
			$conversation_id,
			self::$meta_lead_note,
			sanitize_textarea_field( $note )
		);
	}

	/**
	 * Update the status for a lead.
	 *
	 * @param int    $conversation_id The conversation post ID.
	 * @param string $status          The status value.
	 * @return bool True on success.
	 */
	public static function update_lead_status( int $conversation_id, string $status ) {
		$allowed = array( 'new', 'contacted', 'qualified', 'converted', 'lost' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		return (bool) update_post_meta(
			$conversation_id,
			self::$meta_lead_status,
			sanitize_text_field( $status )
		);
	}

	/**
	 * Get the client's IP address.
	 *
	 * @return string|null The IP address or null.
	 */
	private static function get_client_ip() {
		$ip_keys = [
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs (X-Forwarded-For)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return null;
	}
}