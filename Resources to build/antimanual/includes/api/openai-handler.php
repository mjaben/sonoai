<?php
/**
 * Handles all OpenAI for Antimanual Chatbot
 *
 * @package Antimanual_Chatbot
 * @since   1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility function to generate embeddings using the configured AI provider.
 * 
 * @param string $text    The text to generate embeddings for.
 * @param string $api_key Deprecated parameter, kept for backwards compatibility.
 * @return array|null The embedding array or null on failure.
 */
function antimanual_generate_embedding( $text, $api_key = '' ) {
	// Trim and clean up text
	$text = trim( $text );
	$text = mb_convert_encoding( $text, 'UTF-8', 'auto' );
	
	$embedding = \Antimanual\AIProvider::generate_embedding( $text );
	
	if ( is_wp_error( $embedding ) ) {
		throw new Exception( sanitize_text_field( $embedding->get_error_message() ) );
	}
	
	if ( ! is_array( $embedding ) ) {
		throw new Exception( 'Failed to generate embedding. Unexpected response format.' );
	}
	
	return $embedding;
}

/**
 * Resolve the knowledge base IDs (UUIDs) scoped to a specific forum.
 *
 * Reads the `bbp_forum_kb_mapping` option — a map of forum_id => knowledge_id[]
 * — and returns the configured knowledge IDs for that forum.
 *
 * These UUIDs map directly to the `knowledge_id` column in antimanual_embeddings,
 * so they work for all KB entry types: wp, github, url, pdf, txt.
 *
 * Returns an empty array when no mapping is configured for the forum,
 * meaning the AI will fall back to searching the entire knowledge base.
 *
 * @param int $forum_id The bbPress forum ID.
 * @return string[] Array of knowledge_id UUIDs, or empty array if no mapping is set.
 */
function atml_get_forum_kb_knowledge_ids( int $forum_id ): array {
	if ( $forum_id <= 0 ) {
		return [];
	}

	$mapping = atml_option( 'bbp_forum_kb_mapping' );
	$mapping = is_array( $mapping ) ? $mapping : [];

	$key = (string) $forum_id;
	if ( empty( $mapping[ $key ] ) ) {
		return [];
	}

	// Sanitize each UUID string.
	$knowledge_ids = array_filter(
		array_map( 'sanitize_text_field', (array) $mapping[ $key ] ),
		fn( $id ) => strlen( $id ) === 36
	);

	return array_values( $knowledge_ids );
}

/**
 * Build an HTML source-links block for forum AI replies.
 *
 * Mirrors the chatbot's reference-selection approach: uses Embedding::get_chunk_reference()
 * to extract title + URL for each chunk, de-duplicates by link, and renders them as
 * a simple HTML list styled consistently with the chatbot sources widget.
 *
 * Only chunks with similarity >= 0.6 are included as sources.
 * Returns an empty string when no valid sources are found.
 *
 * @param array $related_chunks Output of antimanual_get_related_chunks().
 * @param float $min_similarity Minimum similarity to include as a source. Default 0.6.
 * @return string HTML source-links block, or empty string.
 */
function atml_build_forum_source_links( array $related_chunks, float $min_similarity = 0.6 ): string {
	if ( empty( $related_chunks ) ) {
		return '';
	}

	$seen_links = [];
	$sources    = [];

	foreach ( $related_chunks as $chunk ) {
		if ( ! is_array( $chunk ) || empty( $chunk['row'] ) || ! is_object( $chunk['row'] ) ) {
			continue;
		}

		$similarity = (float) ( $chunk['similarity'] ?? 0 );
		if ( $similarity < $min_similarity ) {
			continue;
		}

		$ref  = \Antimanual\Embedding::get_chunk_reference( $chunk['row'] );
		$link = trim( (string) ( $ref['link'] ?? '' ) );

		if ( '' === $link || '#' === $link ) {
			continue;
		}

		$key = strtolower( $link );
		if ( isset( $seen_links[ $key ] ) ) {
			continue;
		}

		$seen_links[ $key ] = true;
		$sources[] = [
			'title' => sanitize_text_field( $ref['title'] ?? __( 'Source', 'antimanual' ) ),
			'link'  => esc_url( $link ),
		];
	}

	if ( empty( $sources ) ) {
		return '';
	}

	$items = '';
	foreach ( $sources as $source ) {
		$items .= sprintf(
			'<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
			$source['link'],
			esc_html( $source['title'] )
		);
	}

	return sprintf(
		'<div class="atml-forum-sources"><p><strong>%s</strong></p><ul>%s</ul></div>',
		esc_html__( 'Sources', 'antimanual' ),
		$items
	);
}

/**
 * Get related chunks
 *
 * @param string   $input         The input string that related chunks should be provided for.
 * @param int      $limit         Number of chunks to return.
 * @param string[] $knowledge_ids Optional. Restrict search to embeddings with these knowledge_id UUIDs.
 *                                Works for all entry types (wp, github, url, pdf, txt).
 *                                Pass an empty array to search across all KB entries (default).
 * @return array{row: mixed, similarity: float}[]|array{error: string} Related chunks or error.
 */
function antimanual_get_related_chunks( string $input, int $limit = 3, array $knowledge_ids = [] ) {
	if ( ! \Antimanual\AIProvider::has_api_key() ) {
		return [ 'error' => __( 'AI API Key is not configured!', 'antimanual' ) ];
	}

	if ( empty( $input ) ) {
		return [ 'error' => __( 'Input text is empty!', 'antimanual' ) ];
	}

	$limit         = max( 1, min( absint( $limit ), 20 ) );
	$knowledge_ids = array_values( array_filter( array_map( 'sanitize_text_field', $knowledge_ids ) ) );

	global $wpdb;
	$table_name = $wpdb->prefix . 'antimanual_embeddings';

	$provider_name = \Antimanual\AIProvider::get_name();
	$embed_model   = \Antimanual\AIProvider::get_embedding_model();

	$cache_ttl    = max(
		0,
		(int) apply_filters( 'antimanual_related_chunks_cache_ttl', 5 * MINUTE_IN_SECONDS, $input, $limit )
	);
	$max_modified = (string) $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM $table_name" );
	$results_key  = 'antimanual_related_chunks_' . md5( wp_json_encode( [ $provider_name, $embed_model, $limit, $max_modified, $input, $knowledge_ids ] ) );

	if ( $cache_ttl > 0 ) {
		$cached_results = get_transient( $results_key );
		if ( is_array( $cached_results ) ) {
			return $cached_results;
		}
	}

	$embedding_cache_ttl = max(
		0,
		(int) apply_filters( 'antimanual_query_embedding_cache_ttl', DAY_IN_SECONDS, $input )
	);
	$query_embedding_key = 'antimanual_query_embedding_' . md5( wp_json_encode( [ $provider_name, $embed_model, $input ] ) );
	$query_embedding     = null;

	if ( $embedding_cache_ttl > 0 ) {
		$query_embedding = get_transient( $query_embedding_key );
	}

	if ( ! is_array( $query_embedding ) ) {
		try {
			$query_embedding = antimanual_generate_embedding( $input );
		} catch ( \Throwable $e ) {
			return [ 'error' => sanitize_text_field( $e->getMessage() ) ];
		}

		if ( $embedding_cache_ttl > 0 && is_array( $query_embedding ) ) {
			set_transient( $query_embedding_key, $query_embedding, $embedding_cache_ttl );
		}
	}

	if ( ! is_array( $query_embedding ) ) {
		return [ 'error' => __( 'Failed to generate embeddings for the given input!', 'antimanual' ) ];
	}

	// Optionally scope the search to embeddings belonging to the specified knowledge IDs.
	// knowledge_id works for ALL entry types: wp, github, url, pdf, txt.
	if ( ! empty( $knowledge_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $knowledge_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT id, embedding FROM $table_name WHERE knowledge_id IN ($placeholders)", $knowledge_ids ) );
	} else {
		// Fetch only vectors first to keep payload small.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "SELECT id, embedding FROM $table_name" );
	}

	if ( empty( $results ) ) {
		if ( $cache_ttl > 0 ) {
			set_transient( $results_key, [], $cache_ttl );
		}
		return [];
	}

	// Keep only top N similarities while iterating (faster than sorting the full set).
	$top_matches = [];
	foreach ( $results as $row ) {
		$chunk_embedding = json_decode( $row->embedding, true ) ?? [];
		if ( ! is_array( $chunk_embedding ) ) {
			continue;
		}

		$similarity = antimanual_cosine_similarity( $query_embedding, $chunk_embedding );
		$candidate  = [
			'id'         => (int) $row->id,
			'similarity' => (float) $similarity,
		];

		if ( count( $top_matches ) < $limit ) {
			$top_matches[] = $candidate;
			usort(
				$top_matches,
				function ( $a, $b ) {
					return $b['similarity'] <=> $a['similarity'];
				}
			);
			continue;
		}

		$lowest_score = $top_matches[ count( $top_matches ) - 1 ]['similarity'];
		if ( $candidate['similarity'] <= $lowest_score ) {
			continue;
		}

		$top_matches[ count( $top_matches ) - 1 ] = $candidate;
		usort(
			$top_matches,
			function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);
	}

	$final_results = [];
	if ( ! empty( $top_matches ) ) {
		$ids             = array_column( $top_matches, 'id' );
		$ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, `url`, `type`, chunk_index, chunk_text FROM $table_name WHERE id IN ($ids_placeholder)",
				$ids
			)
		);

		$rows_by_id = [];
		foreach ( $rows as $row ) {
			$rows_by_id[ (int) $row->id ] = $row;
		}

		foreach ( $top_matches as $match ) {
			$match_id = (int) $match['id'];
			if ( isset( $rows_by_id[ $match_id ] ) ) {
				$final_results[] = [
					'row'        => $rows_by_id[ $match_id ],
					'similarity' => $match['similarity'],
				];
			}
		}
	}

	if ( $cache_ttl > 0 ) {
		set_transient( $results_key, $final_results, $cache_ttl );
	}

	return $final_results;
}

/** another openai ajax request which generated excerpt from post content */
add_action( 'wp_ajax_antimanual_get_excerpt', 'antimanual_get_excerpt_ajax_handler' );
function antimanual_get_excerpt_ajax_handler() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'antimanual_process' ) ) {
		wp_send_json_error( [ 'message' => 'Invalid security token.' ] );
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}
	if ( atml_is_bulk_rewrite_limit_exceeded() ) {
		wp_send_json_error( [ 
			'message' => __( 'You have reached your monthly limit of 100 bulk rewrite actions. Upgrade to Pro for unlimited actions.', 'antimanual' ),
			'code' => 'monthly_limit_exceeded'
		] );
	}

	$post_id        = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$excerpt_length = isset( $_POST['excerpt_length'] ) ? intval( $_POST['excerpt_length'] ) : 50;
	$overwrite      = boolval( $_POST['overwrite'] ?? false );

	if ( $post_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Invalid request.' ] );
	}

	$post         = get_post( $post_id );
	$post_content = $post->post_content;
	if ( ! $post ) {
		wp_send_json_error( [ 'message' => 'Post not found.' ] );
	}

	if ( ! $overwrite && ! empty( $post->post_excerpt ) ) {
		wp_send_json_error( [ 'message' => 'Post already has an excerpt.' ] );
	}

	$system_message = "
		You are an expert content editor. Your task is to generate a compelling excerpt from a given WordPress post content.

		Rules:
		- The excerpt must be exactly {$excerpt_length} words. Not more, not less.
		- Detect the language of the post content. Write the excerpt in that same language.
		- Do NOT translate the content or switch languages.
		- Make the excerpt engaging, coherent, and end with a complete sentence.
		- Capture the essence and key points of the content, without rewriting it entirely.
		- Do not explain your process. Do not include word counts or any additional commentary.
		- Output only the excerpt text. No greetings, no footnotes, no formatting.

		The user will now provide the post content. Extract and return the excerpt according to these rules.
	";

	$messages = [
		[
			'role'    => 'system',
			'content' => $system_message,
		],
		[
			'role'    => 'user',
			'content' => $post_content,
		]
	];

	$response = antimanual_openai_chat_completions( $messages );

	if ( is_array( $response ) && isset( $response['error'] ) ) {
		wp_send_json_error( [ 'message' => $response['error'] ] );
	}

	$excerpt = trim( $response );

	if ( empty( $excerpt ) ) {
		$excerpt = wp_trim_words( $post->post_content, 50 );
	}

    if ( ! atml_is_pro() ) {
        \Antimanual\UsageTracker::increment('bulk_rewrite');
    }

	wp_send_json_success( [ 'excerpt' => $excerpt ] );
}

add_action( 'wp_ajax_antimanual_get_taxonomy_terms', 'antimanual_get_taxonomy_terms_ajax_handler' );
function antimanual_get_taxonomy_terms_ajax_handler() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'antimanual_process' ) ) {
		wp_send_json_error( [ 'message' => 'Invalid security token.' ] );
	}
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}
	if ( atml_is_bulk_rewrite_limit_exceeded() ) {
		wp_send_json_error( [ 
			'message' => __( 'You have reached your monthly limit of 100 bulk rewrite actions. Upgrade to Pro for unlimited actions.', 'antimanual' ),
			'code' => 'monthly_limit_exceeded'
		] );
	}

	$post_id         = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$taxonomy        = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '';
	$number_of_terms = isset( $_POST['number_of_terms'] ) ? intval( $_POST['number_of_terms'] ) : 5;
	$terms_threshold = isset( $_POST['terms_threshold'] ) ? intval( $_POST['terms_threshold'] ) : 5;
	$number_of_terms = max( 1, min( 10, $number_of_terms ) );
	$terms_threshold = max( 0, min( 100, $terms_threshold ) );
	
	if ( $post_id <= 0 || empty( $taxonomy ) ) {
		wp_send_json_error( [ 'message' => 'Invalid request parameters.' ] );
	}

	if ( ! taxonomy_exists( $taxonomy ) ) {
		wp_send_json_error( [ 'message' => 'Invalid taxonomy.' ] );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( [ 'message' => 'Post not found.' ] );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}

	if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
		wp_send_json_error( [ 'message' => 'Taxonomy does not belong to this post type.' ] );
	}

	$existing_terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
	$term_ids       = is_wp_error( $existing_terms ) ? [] : array_map( 'intval', $existing_terms );

	// Skip generation when the post already has more terms than the configured threshold.
	if ( count( $term_ids ) > $terms_threshold ) {
		wp_send_json_success( [
			'taxonomy_terms' => $term_ids,
			'skipped'        => true,
			'content'        => '',
		] );
	}

	$messages = [
		[
			'role'    => 'system',
			'content' => "Generate exactly $number_of_terms unique, comma-separated {$taxonomy} terms that are highly relevant to the given CONTENT.\n\nRules:\n- Only output the terms, separated by commas.\n- Do not include any explanations, numbers, or extra text.\n- Each term must be simple, short, and suitable for use as a {$taxonomy} in WordPress.\n- Avoid duplicates, synonyms, and overly broad or generic terms.\n- Do not use phrases longer than 3 words.\n- Focus on the main topics, entities, or keywords present in the CONTENT.\n- If the CONTENT is not relevant, return a set of general {$taxonomy} terms for the topic.\n\n"
		],
		[
			'role'    => 'user',
			'content' => '===== CONTENT START =====' . wp_strip_all_tags( $post->post_content ) . '====== CONTENT END =====',
		]
	];

	$response = antimanual_openai_chat_completions( $messages );

	if ( is_array( $response ) && isset( $response['error'] ) ) {
		wp_send_json_error( [ 'message' => $response['error'] ] );
	}

	$terms = explode( ',', $response );
	$terms = array_map( 'trim', $terms );
	$terms = array_filter( $terms );

	foreach ( $terms as $term ) {
		$term_check = term_exists( $term, $taxonomy );
		if ( ! $term_check ) {
			$term_check = wp_insert_term( $term, $taxonomy );
		}
		if ( ! is_wp_error( $term_check ) ) {
			$term_ids[] = is_array( $term_check ) ? $term_check['term_id'] : $term_check;
		}
	}
	$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

    if ( ! atml_is_pro() ) {
        \Antimanual\UsageTracker::increment('bulk_rewrite');
    }

	wp_send_json_success( [
		'taxonomy_terms' => $term_ids,
		'skipped'        => false,
		'content'        => $response
	] );
}


/** write another openai ajax request which generated tags and categories from post content */
add_action( 'wp_ajax_antimanual_get_tags', 'antimanual_get_tags_ajax_handler' );
function antimanual_get_tags_ajax_handler() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'antimanual_process' ) ) {
		wp_send_json_error( [ 'message' => 'Invalid security token.' ] );
	}
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}
	$post_id        = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$number_of_tags = isset( $_POST['number_of_tags'] ) ? intval( $_POST['number_of_tags'] ) : 5;
	if ( $post_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Invalid request.' ] );
	}

	$post         = get_post( $post_id );
	$post_content = $post->post_content;
	if ( ! $post ) {
		wp_send_json_error( [ 'message' => 'Post not found.' ] );
	}

	$messages = [
		[
			'role'    => 'system',
			'content' => "Return only comma seperated $number_of_tags categories based on the following content."
		],
		[
			'role'    => 'user',
			'content' => 'Return only comma seperated ' . $number_of_tags . ' categories based on the following content: ' . wp_strip_all_tags( $post_content )
		]
	];

	$response = antimanual_openai_chat_completions( $messages );

	if ( is_array( $response ) && isset( $response['error'] ) ) {
		wp_send_json_error( [ 'message' => $response['error'] ] );
	}

	$tags = explode( ',', $response );
	$tags = array_map( 'trim', $tags );
	$tags = array_filter( $tags );
	// insert them into post tags and categories and return ids
	$tag_ids = [];
	$cat_ids = [];
	// Get existing tags and categories
	$existing_tags = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
	$existing_cats = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );

	// Append existing tags and categories to arrays
	if ( ! is_wp_error( $existing_tags ) ) {
		$tag_ids = array_merge( $tag_ids, $existing_tags );
	}
	if ( ! is_wp_error( $existing_cats ) ) {
		$cat_ids = array_merge( $cat_ids, $existing_cats );
	}
	foreach ( $tags as $tag ) {
		// Check if tag length is too long (e.g., more than 50 characters)
		if ( strlen( $tag ) > 50 ) {
			wp_send_json_error( [ 'message' => 'Generated category/tag is too long: ' . esc_html( $tag ) ] );

			return;
		}
		$term = term_exists( $tag, 'post_tag' );
		$cat  = term_exists( $tag, 'category' );
		if ( ! $term ) {
			$term = wp_insert_term( $tag, 'post_tag' );
		}
		if ( ! $cat ) {
			$cat = wp_insert_term( $tag, 'category' );
		}
		$tag_ids[] = $term['term_id'];
		$cat_ids[] = $cat['term_id'];
	}
	// try to associate the post with the generated tags and categories
	//wp_set_post_terms($post_id, $tag_ids, 'post_tag', true);
	//wp_set_post_terms($post_id, $cat_ids, 'category', true);
	wp_send_json_success( [
		'message'    => 'Tags and categories generated successfully',
		'tags'       => $tag_ids,
		'categories' => $cat_ids,
		'content'    => $response
	] );
}

/**
 * Call a custom AI function to generate a comprehensive post.
 * This function should analyze the full conversation and return an associative array:
 * [
 *    'title'      => (string) Generated title,
 *    'content'    => (string) Generated content,
 *    'excerpt'    => (string) Generated excerpt,
 *    'taxonomies' => (array)  Taxonomies e.g. ['category' => [...], 'post_tag' => [...]]
 * ]
 * Users can control the tone, excerpt length, and taxonomy limit via POST vars.
 */
function antimanual_generate_post( $title, $conversation, $tone, $excerpt_length, $taxonomy_limit ) {
	$system_message
		= "You are an expert content generator. Your task is to create a comprehensive post based on a provided conversation. Use a {$tone} tone. The output must include a title, content body, a concise excerpt of roughly {$excerpt_length} words, and relevant taxonomies. For taxonomies, generate two lists: one for categories and one for post tags, each limited to {$taxonomy_limit} simple and relevant items. Respond in valid JSON format with these keys: 'title', 'content', 'excerpt', and 'taxonomies'. The 'taxonomies' value should be an object with keys 'category' and 'post_tag' containing arrays of terms.";
	$user_message
		= "Generate a blog post or documentation based on the following forum topic {$title}\n\n. The topic contains a discussion about an issue—rewrite it as a structured post that clearly presents the problem and its solution for other users: Topic Title: {$title}\n\nConversation:\n{$conversation}\n\nPlease generate the complete post accordingly.";

	$messages = [
		[
			'role'    => 'system',
			'content' => $system_message
		],
		[
			'role'    => 'user',
			'content' => $user_message
		]
	];

	$response = antimanual_openai_chat_completions( $messages, true );

	if ( is_array( $response ) && isset( $response['error'] ) ) {
		return null;
	}

	$generated      = trim( $response );
	$generated_post = json_decode( $generated, true );

	if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $generated_post['title'], $generated_post['content'], $generated_post['excerpt'] ) ) {
		// Fallback if the response is not in valid JSON format.
		$generated_post = [
			'title'      => $title,
			'content'    => $conversation,
			'excerpt'    => wp_trim_words( $conversation, $excerpt_length, '...' ),
			'taxonomies' => [
				'category' => [],
				'post_tag' => []
			]
		];
	}

	return $generated_post;
}

add_action( 'wp_ajax_antimanual_generate_doc', 'antimanual_generate_doc_ajax_handler' );

function antimanual_generate_doc_ajax_handler() {
	check_ajax_referer( 'antimanual_generate_doc', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$doc_outline      = isset( $_POST['doc_outline'] ) ? wp_unslash( $_POST['doc_outline'] ) : [];
	$doc_outline_json = json_encode( $doc_outline, true );
	$object_id        = isset( $_POST['object_id'] ) ? sanitize_text_field( wp_unslash( $_POST['object_id'] ) ) : '';
	$parent_post_id   = isset( $_POST['parent_post_id'] ) ? intval( $_POST['parent_post_id'] ) : 0;
	$tone             = isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : 'Professional';
	$status           = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'draft';

	if ( ! isset( $doc_outline['lessons'][0]['sub_lessons'][0]['id'] ) ) {
		wp_send_json_error( [ 'message' => __( 'Invalid documentation outline format.', 'antimanual' ) ] );
	}

	if ( empty( $object_id ) ) {
		wp_send_json_error( [ 'message' => __( 'object_id is required', 'antimanual' ) ] );
	}

	$targeted_obj = $doc_outline['id'] === $object_id ? $doc_outline : null;
	$order		  = 0;

	foreach ( $doc_outline['lessons'] as $_ => $lesson ) {
		$order++;

		if ( $lesson['id'] === $object_id ) {
			$targeted_obj = $lesson;
			break;
		}

		foreach ( $lesson['sub_lessons'] as $__ => $sub_lesson ) {
			$order++;

			if ( $sub_lesson['id'] === $object_id ) {
				$targeted_obj = $sub_lesson;
				break 2;
			}

			if ( isset( $sub_lesson['topics'] ) && is_array( $sub_lesson['topics'] ) ) {
				foreach ( $sub_lesson['topics'] as $___ => $topic ) {
					$order++;

					if ( $topic['id'] === $object_id ) {
						$targeted_obj = $topic;
						break 3;
					}
				}
			}
		}
	}

	if ( ! $targeted_obj ) {
		wp_send_json_error( [ 'message' => __( "object_id doesn't exist in the given outline.", 'antimanual' ) ] );
	}

	if ( empty( $targeted_obj['title'] ?? '' ) ) {
		wp_send_json_error( [ 'message' => __( 'Title cannot be empty', 'antimanual' ) ] );
	}

	$system_prompt = "
		You are a professional WordPress content writer.

		Your job is to write a comprehensive article of **at least 600 words**, formatted strictly in **WordPress Gutenberg block format**.

		**Important Rules**:
		- Do NOT use Markdown, HTML tags, or inline styling.
		- Use only Gutenberg block syntax (with `<!-- wp:... -->` and corresponding JSON attributes).
		- Output only the block content, no explanations or comments outside the blocks.

		**Structure Tips**:
		- Begin with a paragraph block.
		- Use multiple heading blocks to break the article into sections.
		- Include list blocks, paragraph blocks, and any other relevant Gutenberg blocks to make the content rich and readable.

		Your output must be 100% compatible with the block editor.
	";

	$user_prompt = "
		# DOC OUTLINE:
		{$doc_outline_json}

		# TASK:
		Write a full-length documentation article (600+ words) for the title: \"{$targeted_obj['title']}\" (ID: {$targeted_obj['id']}).

		# RULES:
		- Begin directly with a paragraph block (do NOT include a heading — the title already exists in the page).
		- Use ONLY **WordPress Gutenberg block syntax**. Every single content section must be properly wrapped in Gutenberg blocks like:

		<!-- wp:paragraph -->
		<p>...</p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {\"level\":2} -->
		<h2>...</h2>
		<!-- /wp:heading -->

		<!-- wp:list -->
		<ul><li>...</li></ul>
		<!-- /wp:list -->

		- If including links, code snippets, or images:
		- Wrap links in proper paragraph or list blocks.
		- Wrap code in:
			<!-- wp:code -->
			<pre class=\"wp-block-code\"><code>...</code></pre>
			<!-- /wp:code -->
		- Wrap images using:
			<!-- wp:image {\"alt\":\"...\",\"caption\":\"...\"} -->
			<figure class=\"wp-block-image\">...</figure>
			<!-- /wp:image -->

		- DO NOT return Markdown (`##`, `-`, etc.) or raw HTML (`<h2>`, `<img>`, etc.).
		- DO NOT return unwrapped content. Every line of content must be within a Gutenberg block comment.

		# CONTEXT:
		Use the full DOC OUTLINE above to understand the position and role of this section. This section may be a lesson or sub-lesson — use the style and depth appropriate to that level.

		# STYLE:
		- Keep tone consistent with the outline's theme.
		- Use clear, technical language suited for documentation.
		- Format for readability: break up long text with headings and lists.

		# TONE INSTRUCTION:
			- Adopt the following writing tone: {$tone}
	";

	$messages = [
		[
			'role'    => 'system',
			'content' => $system_prompt,
		],
		[
			'role'    => 'user',
			'content' => $user_prompt,
		],
	];

	$content = antimanual_openai_chat_completions( $messages );

	if ( isset( $content['error'] ) ) {
		wp_send_json_error( [ 'message' => $content['error'] ] );
	}

	$targeted_obj['content'] = $content ?? '';
	$targeted_obj['order']   = $order;

	$post_id = wp_insert_post( [
		'post_title'   => $targeted_obj['title'],
		'post_content' => $targeted_obj['content'],
		'post_parent'  => $parent_post_id,
		'post_type'    => 'docs',
		'post_status'  => $status,
		'post_name'    => $targeted_obj['slug'],
		'menu_order'   => $targeted_obj['order'],
	] );

	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Database insertion failed for the generated doc', 'antimanual' ) ] );
	}

	$targeted_obj['post_id'] = $post_id;
	$targeted_obj['url']     = get_permalink( $post_id );

	wp_send_json_success( $targeted_obj );
}

add_action( 'wp_ajax_antimanual_doc_outline', 'antimanual_doc_outline_ajax_handler' );

function antimanual_doc_outline_ajax_handler() {
	check_ajax_referer( 'antimanual_doc_outline', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ] );
	}

	$subject            = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
	$language           = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'English';
	$lessons_count      = isset( $_POST['lessons_count'] ) ? intval( $_POST['lessons_count'] ) : 5;
	$sub_lessons_count  = isset( $_POST['sub_lessons_count'] ) ? intval( $_POST['sub_lessons_count'] ) : 4;
	$tone               = isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : 'Professional but friendly and humane';
	$slug_lang          = isset( $_POST['slug_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['slug_lang'] ) ) : 'English';
	$slug_len           = isset( $_POST['slug_len'] ) ? intval( $_POST['slug_len'] ) : 5;

	if ( empty( $subject ) ) {
		wp_send_json_error( [ 'message' => __( 'Subject cannot be empty.', 'antimanual' ) ] );
	}

	if ( ! ( $lessons_count > 0 ) ) {
		$lessons_count     = 'as much as appropriate';
		$sub_lessons_count = 'as much as appropriate';
	}

	if ( ! ( $slug_len > 0 ) ) {
		$slug_len = 'as much as appropriate';
	}

	$system_prompt  = "
		You are a documentation outline generator.

		Follow these rules **strictly**:

		- Output ONLY valid JSON (not Markdown, not code blocks, not explanations):
		{
			\"title\": string,
			\"slug\": string,
			\"lessons\": [
				{
					\"title\": string,
					\"slug\": string,
					\"sub_lessons\": [
						{ \"title\": string, \"slug\": string }
					]
				}
			]
		}

		- All titles should be concise and descriptive.
		- Do NOT exceed or fall short on counts. Your output must match the structure and counts exactly or it will be rejected.
	";

	$user_prompt = "
		# STRICT REQUIREMENTS:
		- Language of the titles : '{$language}'
		- Language of the slugs : '{$slug_lang}'
		- Length of the slugs in words : {$slug_len}
		- Number of lessons to generate : {$lessons_count}
		- Number of sub-lessons for each lesson : {$sub_lessons_count}

		# INSTRUCTIONS:
		- Slugs should be URL-friendly, lowercase, and use hyphens to separate words.
		- DO NOT exceed or fall short in count.
		- DO NOT include extra keys or data. Just stick to the schema.
		- Now go ahead to generate the documentation outline based on the below SUBJECT.

		# TONE INSTRUCTION:
		- Adopt the following writing tone: 
			- {$tone}

		# SUBJECT:
		{$subject}
	";

	$messages = [
		[
			'role'    => 'system',
			'content' => $system_prompt,
		],
		[
			'role'    => 'user',
			'content' => $user_prompt,
		],
	];

    $response = antimanual_openai_chat_completions(
		$messages,
		true,
	);

	if ( isset( $response['error'] ) ) {
		wp_send_json_error( [ 'message' => $response['error'] ] );
	}

    $outline = json_decode( $response, true );

	wp_send_json_success( $outline );
}

add_action( 'wp_ajax_antimanual_response_to_topic', 'antimanual_response_to_topic_ajax_handler' );
add_action( 'wp_ajax_nopriv_antimanual_response_to_topic', 'antimanual_response_to_topic_ajax_handler' );

function antimanual_response_to_topic_ajax_handler() {
	check_ajax_referer( 'antimanual_response_to_topic', 'nonce' );

    $forum_id      = isset( $_POST['forum_id'] ) ? intval( $_POST['forum_id'] ) : 0;
	$topic_title   = isset( $_POST['topic_title'] ) ? sanitize_text_field( $_POST['topic_title'] ) : '';
	$topic_content = isset( $_POST['topic_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topic_content'] ) ) : '';

	if ( empty( $topic_title ) ) {
		wp_send_json_error( [ 'message' => __( 'Topic title cannot be empty.', 'antimanual' ) ] );
	}

	if ( empty( $topic_content ) ) {
		wp_send_json_error( [ 'message' => __( 'Topic content cannot be empty.', 'antimanual' ) ] );
	}

    if ( atml_is_forum_answer_limit_exceeded() ) {
        wp_send_json_error( [
            'message' => __( 'Monthly limit exceeded.', 'antimanual' ),
            'code'    => 'monthly_limit_exceeded',
        ] );
    }

	// Resolve scoped KB knowledge IDs for this forum (empty = search all KB).
	// knowledge_id covers all types: wp, github, url, pdf, txt.
	$forum_knowledge_ids = atml_get_forum_kb_knowledge_ids( $forum_id );

	$related_chunks = antimanual_get_related_chunks(
		"
		# TOPIC TITLE:
		{$topic_title}

		# TOPIC CONTENT:
		{$topic_content}
		",
		3,
		$forum_knowledge_ids
	);

	$context            = '';
	$reference_chunks   = [];

	foreach ( $related_chunks as $i => $chunk ) {
		if ( ! isset( $chunk['row'] ) || ! is_object( $chunk['row'] ) ) {
			continue;
		}

		$row        = $chunk['row'];
		$similarity = round( $chunk['similarity'], 3 );
		$title      = get_the_title( $row->post_id );
		$no         = $i + 1;

		$context .= "
			Snippet #{$no} (similarity=$similarity) [Title: {$title}]:
			{$row->chunk_text} \n\n
		";

		// Collect chunks above similarity threshold for source links.
		if ( (float) $chunk['similarity'] >= 0.5 ) {
			$reference_chunks[] = $chunk;
		}
	}

	// Load saved response preferences
	$response_tone    = atml_option( 'bbp_response_tone' ) ?: 'professional';
	$response_length  = atml_option( 'bbp_response_length' ) ?: 'balanced';
	$custom_instructions = atml_option( 'bbp_custom_instructions' ) ?: '';

	// Map tone to prompt instruction
	$tone_map = array(
		'professional'  => 'Clear, concise, and professional',
		'friendly'      => 'Warm, friendly, and approachable',
		'casual'        => 'Relaxed, conversational, and casual',
		'support-agent' => 'Helpful, empathetic, and solution-focused like a support agent',
		'technical'     => 'Precise, detailed, and technically accurate',
	);
	$tone_instruction = isset( $tone_map[ $response_tone ] ) ? $tone_map[ $response_tone ] : $tone_map['professional'];

	// Map length to word count guidance
	$length_map = array(
		'concise'  => 'Keep the response brief: 1–2 paragraphs or ~100 words max.',
		'balanced' => 'Aim for a balanced response: 2–4 paragraphs or ~200 words.',
		'detailed' => 'Provide a thorough, detailed response: 4–6 paragraphs or ~350 words.',
	);
	$length_instruction = isset( $length_map[ $response_length ] ) ? $length_map[ $response_length ] : $length_map['balanced'];

	// Build custom instructions block
	$custom_block = '';
	if ( ! empty( $custom_instructions ) ) {
		$custom_block = "\n\t\tAdditional instructions from the site admin:\n\t\t{$custom_instructions}\n";
	}

	$system_message = 'You are an AI assistant specialized in generating comprehensive responses to forum topics. Your task is to analyze the provided topic title and content, and generate a helpful informative response that answers the topic effectively.';

	$user_message = "
		You are an AI assistant replying to forum topics. You will be given a topic title, description, and some background info. Write a helpful reply as if you're participating in the forum.
		Use ONLY the information provided below. If the answer is not present, do not make assumptions or use outside knowledge.
		Respond using well-formed, valid HTML only (no Markdown, no explanations).
		Tone: {$tone_instruction}.

		Formatting guidelines:
		- Start with a short paragraph. DO NOT start with any heading.
		- Use semantic tags like <p>, <ul>, <li>, <h3>, <a>, <b>, <hr> <code>, <pre> etc.
		- {$length_instruction}
		- Use headings, lists, or links when helpful.
		- If there isn't enough information to answer, reply naturally as a human would — without mentioning missing data or lack of context.
		- You must never refer to the source, the background info, or say where you got the answer from.
		{$custom_block}
		Here is the background information:
		{$context}

		Here is the topic:
		Title: {$topic_title}
		Description: {$topic_content}
	";

	$messages = [
		[
			'role'    => 'system',
			'content' => $system_message,
		],
		[
			'role'    => 'user',
			'content' => $user_message,
		],
	];

    $response = antimanual_openai_chat_completions( $messages );

    if ( isset( $response['error'] ) ) {
        wp_send_json_error( $response['error'] );
    }

    if ( ! atml_is_pro() ) {
        \Antimanual\UsageTracker::increment( 'forum_answer' );
    }

	// Append source links to the reply, mirroring the chatbot's references panel.
	$source_links_html = atml_build_forum_source_links( $reference_chunks );
	if ( ! empty( $source_links_html ) && is_string( $response ) ) {
		$response .= $source_links_html;
	}

    wp_send_json_success( $response );
}

/**
 * AJAX handler for AI response to forum replies
 * Includes full thread context for better AI understanding
 */
add_action( 'wp_ajax_antimanual_response_to_reply', 'antimanual_response_to_reply_ajax_handler' );
add_action( 'wp_ajax_nopriv_antimanual_response_to_reply', 'antimanual_response_to_reply_ajax_handler' );

function antimanual_response_to_reply_ajax_handler() {
    check_ajax_referer( 'antimanual_response_to_reply', 'nonce' );

    $topic_title    = isset( $_POST['topic_title'] ) ? sanitize_text_field( wp_unslash( $_POST['topic_title'] ) ) : '';
    $reply_content  = isset( $_POST['reply_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reply_content'] ) ) : '';
    $thread_context = isset( $_POST['thread_context'] ) ? sanitize_textarea_field( wp_unslash( $_POST['thread_context'] ) ) : '';

    // Minimum word count check
    $min_words = intval( atml_option( 'bbp_reply_min_words' ) );
	$word_count = str_word_count( wp_strip_all_tags( $reply_content ) );
    
    if ( $word_count < $min_words ) {
        wp_send_json_error( [ 
            'message' => sprintf( 
				/* translators: 1: current reply word count, 2: minimum required word count */
				__( 'Reply is too short (%1$d words). AI responses are only shown for replies with at least %2$d words.', 'antimanual' ), 
                $word_count, 
                $min_words 
            ),
            'code' => 'reply_too_short'
        ] );
    }

    if ( empty( $reply_content ) ) {
        wp_send_json_error( [ 'message' => __( 'Reply content cannot be empty.', 'antimanual' ) ] );
    }

    if ( atml_is_forum_answer_limit_exceeded() ) {
        wp_send_json_error( [
            'message' => __( 'Monthly limit exceeded.', 'antimanual' ),
            'code'    => 'monthly_limit_exceeded',
        ] );
    }

    // Resolve scoped KB knowledge IDs for this forum (empty = search all KB).
    // knowledge_id covers all types: wp, github, url, pdf, txt.
    $forum_id            = isset( $_POST['forum_id'] ) ? intval( $_POST['forum_id'] ) : 0;
    $forum_knowledge_ids = atml_get_forum_kb_knowledge_ids( $forum_id );

    // Get related knowledge base chunks scoped to the forum's product context.
    $related_chunks = antimanual_get_related_chunks(
        "
        # TOPIC TITLE:
        {$topic_title}

        # USER REPLY:
        {$reply_content}
        ",
        3,
        $forum_knowledge_ids
    );

    $knowledge_context = '';
    $reference_chunks  = [];

    foreach ( $related_chunks as $i => $chunk ) {
        if ( ! isset( $chunk['row'] ) || ! is_object( $chunk['row'] ) ) {
            continue;
        }

        $row        = $chunk['row'];
        $similarity = round( $chunk['similarity'], 3 );
        $title      = get_the_title( $row->post_id );
        $no         = $i + 1;

        $knowledge_context .= "
            Snippet #{$no} (similarity=$similarity) [Title: {$title}]:
            {$row->chunk_text} \n\n
        ";

        // Collect chunks above similarity threshold for source links.
        if ( (float) $chunk['similarity'] >= 0.5 ) {
            $reference_chunks[] = $chunk;
        }
    }

    // Load saved response preferences
    $response_tone       = atml_option( 'bbp_response_tone' ) ?: 'professional';
    $response_length     = atml_option( 'bbp_response_length' ) ?: 'balanced';
    $custom_instructions = atml_option( 'bbp_custom_instructions' ) ?: '';

    // Map tone to prompt instruction
    $tone_map = array(
        'professional'  => 'Clear, concise, and professional',
        'friendly'      => 'Warm, friendly, and approachable',
        'casual'        => 'Relaxed, conversational, and casual',
        'support-agent' => 'Helpful, empathetic, and solution-focused like a support agent',
        'technical'     => 'Precise, detailed, and technically accurate',
    );
    $tone_instruction = isset( $tone_map[ $response_tone ] ) ? $tone_map[ $response_tone ] : $tone_map['professional'];

    // Map length to word count guidance for replies (slightly shorter than topic responses)
    $length_map = array(
        'concise'  => 'Keep it very brief: 1 paragraph or ~75 words max.',
        'balanced' => 'Keep it concise: 1-3 paragraphs or ~150 words max.',
        'detailed' => 'Provide a thorough reply: 3-5 paragraphs or ~250 words.',
    );
    $length_instruction = isset( $length_map[ $response_length ] ) ? $length_map[ $response_length ] : $length_map['balanced'];

    // Build custom instructions block
    $custom_block = '';
    if ( ! empty( $custom_instructions ) ) {
        $custom_block = "\n        Additional instructions from the site admin:\n        {$custom_instructions}\n";
    }

    $system_message = 'You are an AI assistant specialized in generating helpful follow-up responses in forum discussions. You understand thread context and provide relevant, concise responses that add value to the conversation.';

    $user_message = "
        You are an AI assistant helping in a forum discussion. You will be given:
        1. The topic title
        2. The thread context (previous messages)
        3. The new reply from a user that you need to respond to
        4. Background knowledge from documentation
        
        Write a helpful reply that:
        - Directly addresses the user's message
        - Takes into account the conversation history
        - Uses ONLY the information provided or from the knowledge base
        - Does NOT repeat information already given in the thread
        - Uses well-formed, valid HTML only (no Markdown)
        
        Tone: {$tone_instruction}.
        
        Formatting guidelines:
        - Start with a short paragraph addressing the user's point. DO NOT start with any heading.
        - Use semantic tags like <p>, <ul>, <li>, <h3>, <a>, <b>, <hr> <code>, <pre> etc.
        - {$length_instruction}
        - If the user is just saying thanks or has a very short message, respond briefly and naturally.
        - Never mention the knowledge base, documentation sources, or that you're an AI unless directly asked.
        {$custom_block}
        TOPIC TITLE: {$topic_title}

        THREAD CONTEXT:
        {$thread_context}

        NEW USER REPLY (respond to this):
        {$reply_content}

        KNOWLEDGE BASE:
        {$knowledge_context}
    ";

    $messages = [
        [
            'role'    => 'system',
            'content' => $system_message,
        ],
        [
            'role'    => 'user',
            'content' => $user_message,
        ],
    ];

    $response = antimanual_openai_chat_completions( $messages );

    if ( isset( $response['error'] ) ) {
        wp_send_json_error( $response['error'] );
    }

    if ( ! atml_is_pro() ) {
        \Antimanual\UsageTracker::increment( 'forum_answer' );
    }

    // Append source links to the reply, mirroring the chatbot's references panel.
    $source_links_html = atml_build_forum_source_links( $reference_chunks );
    if ( ! empty( $source_links_html ) && is_string( $response ) ) {
        $response .= $source_links_html;
    }

    wp_send_json_success( $response );
}

/**
 * Makes a call to the configured AI provider and returns the main response.
 * 
 * Uses the user's selected provider (OpenAI or Gemini) via the provider classes.
 *
 * @param array $messages The messages array for the chat (system/user/assistant roles)
 * @param bool  $is_json_output Whether to request JSON output format
 * @return string|array The main response content, or error array on failure
 */
function antimanual_openai_chat_completions( $messages, $is_json_output = false ) {

    // Extract system instructions if present
    $instructions = '';
    $filtered_messages = [];
    foreach ( $messages as $message ) {
        if ( $message['role'] === 'system' ) {
            $instructions .= $message['content'] . "\n";
        } else {
            $filtered_messages[] = $message;
        }
    }

    // If JSON output is requested, add instruction
    if ( $is_json_output ) {
        $instructions .= "\nIMPORTANT: Return your response as valid JSON only. No additional text or formatting.";
    }

    $response = \Antimanual\AIProvider::get_reply( $filtered_messages, '', $instructions );

    if ( is_array( $response ) && isset( $response['error'] ) ) {
        return $response;
    }

    if ( ! is_string( $response ) ) {
        return array( 'error' => __( 'No content returned from AI provider.', 'antimanual' ) );
    }

    return $response;
}

/**
 * Makes a call to OpenAI's responses API and returns the parsed JSON object.
 *
 * @param array $input The messages array for the chat (system/user/assistant roles)
 * @param array $json_schema The JSON schema for the expected response structure (Default schema is for generating a documentation outline.)
 * @param string $schema_name The name for the schema (e.g., 'documentation_outline_response')
 * @return array|string The parsed response (json object), or error array on failure
 */
function antimanual_openai_structured_response( $input, $json_schema, $schema_name = 'documentation_outline_response' ) {
    $configs  = atml_get_openai_configs();
    $api_key  = $configs['api_key'];
    $model    = $configs['response_model'];
    $endpoint = 'https://api.openai.com/v1/responses';

    if ( empty( $api_key ) ) {
        return array( 'error' => __( 'OpenAI API key not configured.', 'antimanual' ) );
    }

	if( ! isset( $json_schema ) ) {
		$json_schema = [
			'type'   => 'object',
			'properties' => [
				'title'    => [ 'type' => 'string' ],
				'prompt'   => [ 'type' => 'string' ],
				'children' => [
					'type'       => 'array',
					'items'      => [
						'type'                 => 'object',
						'required'             => [ 'title', 'prompt', 'children' ],
						'additionalProperties' => false,
						'properties' => [
							'title'    => [ 'type' => 'string' ],
							'prompt'   => [ 'type' => 'string' ],
							'children' => [
								'type'  => 'array',
								'items' => [
									'type'                 => 'object',
									'required'             => [ 'title', 'prompt' ],
									'additionalProperties' => false,
									'properties' => [
										'title'  => [ 'type' => 'string' ],
										'prompt' => [ 'type' => 'string' ],
									]
								]
							]
						]
					]
				]
			],
			'required'             => [ 'title', 'prompt', 'children' ],
			'additionalProperties' => false,
		];
	}

    $body = [
        'model'  => $model,
        'input'  => $input,
        'text'   => [
            'format' => [
                'type'   => 'json_schema',
                'name'   => $schema_name,
                'schema' => $json_schema,
                'strict' => true,
            ]
        ]
    ];

    $response = wp_remote_post( $endpoint, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body'    => json_encode( $body ),
        'timeout' => 60,
    ] );

    if ( is_wp_error( $response ) ) {
        return array( 'error' => $response->get_error_message() );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data['error'] ) ) {
        return array(
            'error' => sanitize_text_field( $data['error']['message'] ?? __( 'Couldn\'t retrieve structured response from OpenAI API.', 'antimanual' ) ),
        );
    }

    $output_text = \Antimanual\OpenAI::extract_response_text( $data );
    if ( '' !== $output_text ) {
        return $output_text;
    }

    return array( 'error' => __( 'No structured output returned from OpenAI.', 'antimanual' ) );
}
