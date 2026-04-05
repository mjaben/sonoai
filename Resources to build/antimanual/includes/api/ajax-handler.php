<?php
/**
 * Handles all Ajax Requests
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_antimanual_topic_response_preferences', 'antimanual_topic_response_preferences_ajax_handler' );

/**
 * Handles the AJAX request to update topic response preferences
 *
 * @return void
 */
function antimanual_topic_response_preferences_ajax_handler() {
	check_ajax_referer( 'antimanual_topic_response_preferences', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}

	if ( isset( $_POST["antimanual_response_to_topic"] ) ) {
		atml_option_save( "bbp_response_to_topic", boolval( $_POST["antimanual_response_to_topic"] ) );
	}
	if ( isset( $_POST["antimanual_response_to_reply"] ) ) {
		atml_option_save( "bbp_response_to_reply", boolval( $_POST["antimanual_response_to_reply"] ) );
	}
	if ( isset( $_POST["antimanual_response_as_reply"] ) ) {
		atml_option_save( "bbp_response_as_reply", boolval( $_POST["antimanual_response_as_reply"] ) );
	}
	if ( isset( $_POST["antimanual_response_disclaimer"] ) ) {
		atml_option_save( "bbp_response_disclaimer", sanitize_text_field( wp_unslash( $_POST["antimanual_response_disclaimer"] ) ) );
	}
	if ( isset( $_POST["antimanual_author_id"] ) ) {
		atml_option_save( "bbp_author_id", intval( $_POST["antimanual_author_id"] ) );
	}
	if ( isset( $_POST["antimanual_reply_min_words"] ) ) {
		atml_option_save( "bbp_reply_min_words", intval( $_POST["antimanual_reply_min_words"] ) );
	}
	if ( isset( $_POST["antimanual_excluded_roles"] ) ) {
		$excluded_roles = wp_unslash( $_POST["antimanual_excluded_roles"] );
		// Handle both array and JSON string formats
		if ( is_string( $excluded_roles ) ) {
			$excluded_roles = json_decode( $excluded_roles, true );
		}
		$excluded_roles = is_array( $excluded_roles ) ? array_map( 'sanitize_text_field', $excluded_roles ) : [];
		atml_option_save( "bbp_excluded_roles", $excluded_roles );
	}
	if ( isset( $_POST["antimanual_response_tone"] ) ) {
		$allowed_tones = array( 'professional', 'friendly', 'casual', 'support-agent', 'technical' );
		$tone          = sanitize_text_field( wp_unslash( $_POST["antimanual_response_tone"] ) );
		if ( in_array( $tone, $allowed_tones, true ) ) {
			atml_option_save( "bbp_response_tone", $tone );
		}
	}
	if ( isset( $_POST["antimanual_response_length"] ) ) {
		$allowed_lengths = array( 'concise', 'balanced', 'detailed' );
		$length          = sanitize_text_field( wp_unslash( $_POST["antimanual_response_length"] ) );
		if ( in_array( $length, $allowed_lengths, true ) ) {
			atml_option_save( "bbp_response_length", $length );
		}
	}
	if ( isset( $_POST['antimanual_custom_instructions'] ) ) {
		atml_option_save( 'bbp_custom_instructions', sanitize_textarea_field( wp_unslash( $_POST['antimanual_custom_instructions'] ) ) );
	}
	if ( isset( $_POST['antimanual_forum_product_mapping'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_mapping = wp_unslash( $_POST['antimanual_forum_product_mapping'] );

		// Accept both JSON string and decoded array.
		if ( is_string( $raw_mapping ) ) {
			$raw_mapping = json_decode( $raw_mapping, true );
		}

		// Sanitize: ensure it's an array of forum_id => knowledge_id[] (UUID strings).
		$clean_mapping = [];
		if ( is_array( $raw_mapping ) ) {
			foreach ( $raw_mapping as $forum_id => $knowledge_ids ) {
				$forum_id = absint( $forum_id );
				if ( $forum_id <= 0 ) {
					continue;
				}
				$knowledge_ids = is_array( $knowledge_ids )
					? array_values( array_filter( array_map( 'sanitize_text_field', $knowledge_ids ), fn( $id ) => strlen( $id ) === 36 ) )
					: [];

				$clean_mapping[ (string) $forum_id ] = $knowledge_ids;
			}
		}

		atml_option_save( 'bbp_forum_kb_mapping', $clean_mapping );
	}

	wp_send_json_success();
}

add_action( 'wp_ajax_antimanual_get_posts_by_type', 'antimanual_get_posts_by_type_ajax_handler' );

/**
 * Handles the AJAX request to get posts
 *
 * @return void
 */
function antimanual_get_posts_by_type_ajax_handler() {
	check_ajax_referer( 'antimanual_get_posts_by_type', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}

	$post_type      = sanitize_text_field( $_POST['post_type'] ?? 'post' );
	
	$limit          = intval( $_POST['limit'] ?? -1 );
	$offset         = intval( $_POST['offset'] ?? 0 );

	$id             = intval( $_POST['id'] ?? -1 );
	$post_status_raw = $_POST['post_status'] ?? '';
	$post_status     = '';

	if ( is_array( $post_status_raw ) ) {
		$post_status = array_filter(
			array_map(
				'sanitize_key',
				array_map( 'wp_unslash', $post_status_raw )
			)
		);
	} else {
		$post_status = sanitize_text_field( wp_unslash( (string) $post_status_raw ) );
		if ( false !== strpos( $post_status, ',' ) ) {
			$post_status = array_filter(
				array_map(
					'sanitize_key',
					array_map( 'trim', explode( ',', $post_status ) )
				)
			);
		} elseif ( ! empty( $post_status ) ) {
			$post_status = sanitize_key( $post_status );
		}
	}
	$search         = sanitize_text_field( $_POST['search'] ?? '' );
	$orderby        = sanitize_text_field( $_POST['orderby'] ?? '' );
	$order          = sanitize_text_field( $_POST['order'] ?? '' );
	$post_parent    = isset( $_POST['post_parent'] ) ? intval( $_POST['post_parent'] ) : null;

	$args = array(
		'post_type'      => $post_type,
		'posts_per_page' => $limit,
		'offset'         => $offset,
	);

	if ( ! empty( $post_status ) ) {
		$args['post_status'] = $post_status;
	}

	if ( ! empty( $id ) ) {
		$args['p'] = $id;
	}

	if ( ! empty( $search ) ) {
		$args['s'] = $search;
	}

	if ( ! empty( $orderby ) ) {
		$args['orderby'] = $orderby;
	}

	if ( ! empty( $order ) ) {
		$args['order'] = $order;
	}

	// Filter by parent doc - only applies to hierarchical post types like 'docs'
	if ( $post_parent !== null && $post_parent >= 0 ) {
		$args['post_parent'] = $post_parent;
	}

	$query = new WP_Query( $args );

	$posts       = $query->posts;
	$total_posts = $query->found_posts;

	$taxonomies      = get_object_taxonomies( $post_type );
	$post_type_obj   = get_post_type_object( $post_type );
	$is_hierarchical = $post_type_obj && $post_type_obj->hierarchical;

	foreach ( $posts as $post ) {
		$post->permalink      = get_permalink( $post );
		$post->edit_link      = get_edit_post_link( $post->ID, null );
		
		// Calculate word count from post content using utility function.
		$post->word_count = \Antimanual\Utils\count_post_words( $post->post_content );

		if ( $post_type === 'topic' ) {
			$post->reply_count = bbp_get_topic_reply_count( $post->ID, true );
		}

		// Add parent doc info for hierarchical post types
		if ( $is_hierarchical && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				$post->parent_doc = [
					'ID'    => $parent->ID,
					'title' => $parent->post_title,
				];
			}
		}

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );

			$post->{$taxonomy} = is_array( $terms ) ? array_map( function( $term ) {
				return [
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			}, $terms ) : [];
		}

		$post->metadata = get_post_meta( $post->ID );
	}

	wp_send_json_success( array(
		'posts' => $posts,
		'total' => $total_posts,
	) );
}

add_action( 'wp_ajax_antimanual_get_parent_posts', 'antimanual_get_parent_posts_ajax_handler' );

/**
 * Handles the AJAX request to get parent posts (top-level posts with post_parent = 0)
 * Works for any hierarchical post type (pages, docs, etc.)
 *
 * @return void
 */
function antimanual_get_parent_posts_ajax_handler() {
	check_ajax_referer( 'antimanual_get_parent_posts', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}

	$post_type = sanitize_text_field( $_POST['post_type'] ?? 'page' );

	// Only allow hierarchical post types
	$post_type_obj = get_post_type_object( $post_type );
	if ( ! $post_type_obj || ! $post_type_obj->hierarchical ) {
		wp_send_json_success( array(
			'parent_posts' => [],
		) );
		return;
	}

	$args = array(
		'post_type'      => $post_type,
		'posts_per_page' => -1,
		'post_parent'    => 0,
		'post_status'    => 'any',
		'orderby'        => 'title',
		'order'          => 'ASC',
	);

	$query = new WP_Query( $args );

	$parent_posts = array_map( function( $post ) {
		return [
			'id'    => $post->ID,
			'title' => $post->post_title ?: __( '(No Title)', 'antimanual' ),
		];
	}, $query->posts );

	wp_send_json_success( array(
		'parent_posts' => $parent_posts,
	) );
}

/**
 * Handles the AJAX request for voting on AI responses
 * Supports both logged-in and guest users
 */
add_action( 'wp_ajax_antimanual_vote', 'antimanual_handle_vote' );
add_action( 'wp_ajax_nopriv_antimanual_vote', 'antimanual_handle_vote' );

function antimanual_handle_vote() {
	if ( ! check_ajax_referer( 'wp_rest', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Invalid security token' ] );
	}

	// Rate limiting to prevent abuse
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	if ( ! empty( $ip ) ) {
		$transient_key = 'atml_vote_limit_' . md5( $ip );
		$attempts      = (int) get_transient( $transient_key );

		if ( $attempts >= 10 ) {
			wp_send_json_error( [ 'message' => 'Rate limit exceeded. Please try again later.' ], 429 );
		}

		set_transient( $transient_key, $attempts + 1, MINUTE_IN_SECONDS );
	}

	// Get and sanitize input data
	$post_id = intval( $_POST['post_id'] ?? 0 );
	$vote_type = sanitize_text_field( $_POST['vote_type'] ?? '' );
	$query_log_id = intval( $_POST['query_log_id'] ?? 0 );
	// Kept for backwards compatibility
	$query = sanitize_text_field( $_POST['query'] ?? '' );
	// Sanitize HTML content to prevent XSS
	$answer = wp_kses_post( wp_unslash( $_POST['answer'] ?? '' ) );

	// Validate input
	if ( ! $post_id || ! in_array( $vote_type, ['yes', 'no'], true ) ) {
		wp_send_json_error( ['message' => 'Invalid data provided'] );
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'antimanual_query_votes';
	$is_helpful = $vote_type === 'yes' ? 1 : 0;

	// Primary method: Update by query_log_id (reliable, passed from search response)
	if ( $query_log_id > 0 ) {
		$result = $wpdb->update(
			$table,
			[ 'is_helpful' => $is_helpful ],
			[ 'id' => $query_log_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( $result === false ) {
			wp_send_json_error( ['message' => 'Failed to save vote'] );
			return;
		}
	}
	// Fallback: For backwards compatibility with old frontend sessions that don't have query_log_id
	elseif ( $query && $answer ) {
		// Try to find an existing record with the same query that hasn't been voted on yet
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE query = %s AND is_helpful IS NULL ORDER BY created_at DESC LIMIT 1",
			$query
		) );

		if ( $existing_id ) {
			$result = $wpdb->update(
				$table,
				[ 'is_helpful' => $is_helpful ],
				[ 'id' => $existing_id ],
				[ '%d' ],
				[ '%d' ]
			);
		} else {
			// No existing record found - this shouldn't happen in normal flow
			// but we create one for safety
			$created_at = current_time( 'mysql' );
			$result = $wpdb->insert( $table, [
				'query'      => $query,
				'answer'     => $answer,
				'is_helpful' => $is_helpful,
				'created_at' => $created_at,
			] );
		}

		if ( $result === false ) {
			wp_send_json_error( ['message' => 'Failed to save vote'] );
			return;
		}
	}

	// Get vote counts from custom table instead of postmeta
	global $wpdb;
	$table = $wpdb->prefix . 'antimanual_query_votes';
	$yes_votes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE is_helpful = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$no_votes  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE is_helpful = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Return success with updated vote counts
	wp_send_json_success( [
		'message' => 'Vote recorded successfully',
		'yes_votes' => $yes_votes,
		'no_votes' => $no_votes,
		'vote_type' => $vote_type
	] );
}
