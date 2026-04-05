<?php
/**
 * Handles all database Antimanual Chatbot
 *
 * @package Antimanual_Chatbot
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_antimanual_convert_topic', 'antimanual_convert_topic_ajax_handler');
function antimanual_convert_topic_ajax_handler() {
    check_ajax_referer( 'antimanual_convert_topic', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    if ( atml_is_forum_conversion_limit_exceeded() ) {
        wp_send_json_error( [ 
            'message' => __( 'You have reached your monthly limit of 100 forum conversions. Upgrade to Pro for unlimited conversions.', 'antimanual' ),
            'code' => 'monthly_limit_exceeded'
        ] );
    }

    $topic_id       = isset( $_POST['topic_id'] ) ? intval( $_POST['topic_id'] ) : 0;
    $post_type      = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
    $tone           = isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : 'professional';
    $post_status    = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'draft';
    $excerpt_length = isset( $_POST['excerpt_length'] ) ? intval( $_POST['excerpt_length'] ) : 150;
    $taxonomy_limit = isset( $_POST['taxonomy_limit'] ) ? intval( $_POST['taxonomy_limit'] ) : 5;

    // Validate post_status
    $allowed_statuses = array( 'draft', 'publish', 'private' );
    if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
        $post_status = 'draft';
    }

    if ( $topic_id <= 0 || empty( $post_type ) ) {
        wp_send_json_error( [ 'message' => 'Invalid request.' ] );
    }

    $topic = get_post( $topic_id );
    if ( ! $topic ) {
        wp_send_json_error( [ 'message' => 'Topic not found.' ] );
    }

    $replies = get_children( [
        'post_parent' => $topic_id,
        'post_type'   => 'reply',
        'post_status' => 'publish',
        'orderby'     => 'date',
        'order'       => 'ASC'
    ] );

    $full_conversation = "
        ### Topic Title ###
        {$topic->post_title}

        ### Topic Content ###
        {$topic->post_content}
    ";

    if ( ! empty( $replies ) ) {
        foreach ( $replies as $id => $reply ) {
            $full_conversation .= "
                ### Reply - {$id} ###
                {$reply->post_content}
            ";
        }
    }

    // Map tone to clear writing instruction
    $tone_map = array(
        'casual'           => 'Use a relaxed and friendly tone. Keep the language simple and conversational.',
        'professional'     => 'Use clear, concise, and formal language. Maintain a professional tone.',
        'technical'        => 'Use precise and detailed language. Include accurate terminology for developers.',
        'beginner-friendly' => 'Use very simple and clear language. Avoid jargon and explain concepts simply.',
        'instructor'       => 'Adopt a teaching tone. Explain concepts step by step.',
        'authoritative'    => 'Write confidently and authoritatively. Avoid casual language.',
        'enthusiastic'     => 'Use energetic and upbeat language. Make the content engaging.',
        'minimalist'       => 'Use short, to-the-point sentences. Focus only on what is necessary.',
        'support agent'    => 'Write like a helpful support agent. Be polite and solution-focused.',
        'blog-style'       => 'Use an informal blog-post tone. Keep it human and story-like.',
    );
    $tone_instruction = isset( $tone_map[ $tone ] ) ? $tone_map[ $tone ] : $tone_map['professional'];

    $messages = [
        [
            'role'    => 'system',
            'content' => "
                You are an expert forum topic-to-WordPress post converter. Your task is to transform a given forum topic (including its title, content, and all replies) into a high-quality, self-contained post of type '$post_type'. Analyze the entire conversation: identify the main question or issue, key discussions, solutions, insights, and any resolutions. Then, compile this into a coherent, helpful post that summarizes and explains the topic clearly, so future users can find answers without starting new threads.

                Tone instruction: {$tone_instruction}

                Key guidelines:
                - **Title**: Create a concise, descriptive title that captures the essence of the topic (e.g., 'How to Fix Common WordPress Login Issues').
                - **Content**: Generate the post body in valid WordPress Gutenberg block format. Use appropriate blocks like paragraphs (<!-- wp:paragraph -->), headings (<!-- wp:heading {\"level\":2} -->), lists (<!-- wp:list -->), code blocks (<!-- wp:code -->), or quotes (<!-- wp:quote -->) to structure the content logically. Start with an introduction, include sections for problem description, steps/solutions, and a conclusion if applicable. Ensure the content is engaging, informative, and optimized for readability.
                - **Output Format**: Respond ONLY with a valid JSON object in this exact structure. Do not include any additional text, explanations, or markdown outside the JSON:
                {
                \"title\": \"Generated Post Title\",
                \"content\": \"Generated Post Content in Gutenberg format\"
                }
            ",
        ],
        [
            'role'    => 'user',
            'content' => $full_conversation
        ]
    ];

    // $generated_post = antimanual_generate_post( $topic->post_title, $full_conversation, $tone, $excerpt_length, $taxonomy_limit );
    $response = antimanual_openai_chat_completions( $messages, true );

    if ( isset( $response['error'] ) ) {
        wp_send_json_error( [ 'message' => $response['error'] ] );
    }

    $generated_post = json_decode( $response, true );

    $post_id = wp_insert_post( [
        'post_title'   => $generated_post['title'],
        'post_content' => $generated_post['content'],
        'post_status'  => $post_status,
        'post_type'    => $post_type,
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
    }

    update_post_meta( $post_id, 'atml_source_topic_id', $topic_id );

    if ( ! atml_is_pro() ) {
        \Antimanual\UsageTracker::increment( 'forum_conversion' );
    }

    wp_send_json_success( [
        'post_id'        => $post_id,
        'title'          => $generated_post['title'],
        'content'        => $generated_post['content'],
        'post_edit_link' => get_edit_post_link($post_id),
    ] );
}

add_action('wp_ajax_antimanual_set_terms', 'antimanual_set_terms_ajax_handler');
function antimanual_set_terms_ajax_handler() {
    check_ajax_referer('antimanual_process', 'nonce');

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $excerpt = isset($_POST['excerpt']) ? sanitize_text_field(wp_unslash($_POST['excerpt'])) : '';
    $tags = isset($_POST['tags']) ? array_map('intval', $_POST['tags']) : [];
    $categories = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : [];
    
    if ($post_id <= 0) {
        wp_send_json_error(['message' => 'Invalid request.']);
    }
    
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Post not found.']);
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Unauthorized access.']);
    }
    
    // Update post excerpt
    wp_update_post([
        'ID'           => $post_id,
        'post_excerpt' => $excerpt,
    ]);
    
    // Update post tags and categories
    wp_set_post_terms($post_id, $tags, 'post_tag', true);
    wp_set_post_terms($post_id, $categories, 'category', true);
    
    wp_send_json_success();
}

add_action( 'wp_ajax_antimanual_set_taxonomy_terms', 'antimanual_set_taxonomy_terms_ajax_handler' );
function antimanual_set_taxonomy_terms_ajax_handler() {
	check_ajax_referer( 'antimanual_set_taxonomy_terms', 'nonce' );

	$post_id  = intval( $_POST['post_id'] ?? 0 );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

	$taxonomy = sanitize_key( $_POST['taxonomy'] ?? "" );
	$terms    = array_map( 'intval', $_POST['terms'] ?? [] );

	wp_set_post_terms($post_id, $terms, $taxonomy, true);

    wp_send_json_success();
}

add_action('wp_ajax_antimanual_get_posts', 'antimanual_get_posts_ajax_handler');
function antimanual_get_posts_ajax_handler() {
    check_ajax_referer('antimanual_process', 'nonce');

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }
    
    $post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : '';
    $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field(wp_unslash($_POST['taxonomy'])) : '';
    $threshold = isset($_POST['threshold']) ? intval($_POST['threshold']) : 0;
    
    if (empty($post_type)) {
        wp_send_json_error(['message' => 'Invalid request.']);
    }
    
    global $wpdb;
    
    // Base query for published posts of specified type
    $query = $wpdb->prepare("
        SELECT p.ID 
        FROM {$wpdb->posts} p
        WHERE p.post_type = %s 
        AND p.post_status = 'publish'", 
        $post_type
    );
    
    // Add taxonomy threshold condition if taxonomy is specified
    if (!empty($taxonomy)) {
        $query = $wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN (
                SELECT object_id, COUNT(*) as term_count
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = %s
                GROUP BY object_id
            ) terms ON p.ID = terms.object_id
            WHERE p.post_type = %s 
            AND p.post_status = 'publish'
            AND (terms.term_count IS NULL OR terms.term_count <= %d)",
            $taxonomy,
            $post_type,
            $threshold
        );
    }
    
    $posts = $wpdb->get_col($query);
    
    wp_send_json_success(['posts' => $posts]);
}

add_action('wp_ajax_antimanual_process_posts_batch', 'antimanual_process_posts_batch');
function antimanual_process_posts_batch() {
    check_ajax_referer('antimanual_process', 'nonce');

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'antimanual_embeddings';
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = 1; // Process 5 posts per batch
    
    $api_key = atml_option( 'openai_api_key' );
    $post_types = atml_option( 'selected_post_types' );
    
    $posts = get_posts([
        'post_type' => $post_types,
        'posts_per_page' => $batch_size,
        'offset' => $offset,
        'post_status' => 'publish',
    ]);
    
    $processed = 0;
    foreach ($posts as $post) {
        // Check if post already exists in embeddings table
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE post_id = %d',
                $table_name,
                $post->ID
            )
        );
        
        // Skip if post already has embeddings
        if ($exists > 0) {
            $processed++;
            continue;
        }
        $content = wp_strip_all_tags($post->post_content);
        $chunks = str_split($content, 800);
        
        foreach ($chunks as $chunk_index => $chunk) {
            try {
                $embedding = antimanual_generate_embedding($chunk, $api_key);
                if ($embedding) {
                    $wpdb->insert($table_name, [
                        'post_id' => $post->ID,
                        'chunk_index' => $chunk_index, 
                        'chunk_text' => $chunk,
                        'embedding' => wp_json_encode($embedding),
                    ]);
                }
            } catch (Exception $e) {
                wp_send_json_error([
                    'message' => sprintf('Error processing post %d: %s', $post->ID, $e->getMessage()),
                    'processed' => $processed
                ]);
                return;
            }
        }
        $processed++;
    }
    
    wp_send_json_success([
        'processed' => $processed,
        'post_link' => get_permalink($post->ID),
        'post_title' => $post->post_title,
        'post_id' => $post->ID,
        'message' => sprintf('Processed %d', $processed)
    ]);
}

/**
 * Handle AJAX request to add a post to the knowledge base.
 */
add_action( 'wp_ajax_antimanual_add_post_to_kb', 'antimanual_add_post_to_kb' );

function antimanual_add_post_to_kb() {
    check_ajax_referer( 'antimanual_add_post_to_kb', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $post_id = absint( $_POST['post_id'] ?? 0 );
    $result  = antimanual_add_post_to_kb_common( $post_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error(
            [ 'message' => $result->get_error_message() ],
            $result->get_error_data( 'status' ) ?: 500
        );
    }

    wp_send_json_success( [ 'message' => __( 'Post added to knowledge base', 'antimanual' ) ] );
}

/**
 * Handle AJAX request to remove a post from the knowledge base.
 */
add_action( 'wp_ajax_antimanual_remove_post_from_kb', 'antimanual_remove_post_from_kb' );

function antimanual_remove_post_from_kb() {
    check_ajax_referer( 'antimanual_remove_post_from_kb', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $post_id = absint( $_POST['post_id'] ?? 0 );
    $result  = antimanual_remove_post_from_kb_common( $post_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error(
            [ 'message' => $result->get_error_message() ],
            $result->get_error_data( 'status' ) ?: 500
        );
    }

    wp_send_json_success( [ 'message' => __( 'Post removed from knowledge base', 'antimanual' ) ] );
}

/**
 * Handle AJAX request to update a post in the knowledge base.
 */
add_action( 'wp_ajax_antimanual_update_post_in_kb', 'antimanual_update_post_in_kb' );

function antimanual_update_post_in_kb() {
    check_ajax_referer( 'antimanual_update_post_in_kb', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $post_id       = absint( $_POST['post_id'] ?? 0 );
    $remove_result = antimanual_remove_post_from_kb_common( $post_id );

    if ( is_wp_error( $remove_result ) ) {
        wp_send_json_error(
            [ 'message' => $remove_result->get_error_message() ],
            $remove_result->get_error_data( 'status' ) ?: 500
        );
    }

    $add_result = antimanual_add_post_to_kb_common( $post_id );

    if ( is_wp_error( $add_result ) ) {
        wp_send_json_error(
            [ 'message' => $add_result->get_error_message() ],
            $add_result->get_error_data( 'status' ) ?: 500
        );
    }

    wp_send_json_success( [ 'message' => __( 'Post updated in knowledge base', 'antimanual' ) ] );
}

/**
 * Split content into chunks by words, sentences, or paragraphs, without breaking words.
 *
 * @param string $content     The content to split.
 * @param int    $max_length  Maximum chunk length in characters. Default 800.
 *
 * @return array Array of chunked strings.
 */
function antimanual_split_content_by_words( $content, $max_length = 800 ) {
    $chunks        = array();
    $current_chunk = '';
    $words         = preg_split( '/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

    foreach ( $words as $word ) {
        // If adding this word would exceed the max length, start a new chunk.
        if ( mb_strlen( $current_chunk . $word, 'UTF-8' ) > $max_length && $current_chunk !== '' ) {
            $chunks[]      = $current_chunk;
            $current_chunk = '';
        }
        $current_chunk .= $word;
    }

    if ( $current_chunk !== '' ) {
        $chunks[] = $current_chunk;
    }

    return $chunks;
}

function antimanual_add_post_to_kb_common( int $post_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'antimanual_embeddings';
    $post       = get_post( $post_id );

    if ( ! $post ) {
        return new WP_Error( 'post_not_found', __( 'Post not found.', 'antimanual' ), array( 'status' => 404 ) );
    }

    $existing_chunks = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE post_id = %d',
            $table_name,
            $post->ID
        )
    );

    if ( $existing_chunks > 0 ) {
        return new WP_Error( 'post_exists', __( 'Post already exists in knowledge base.', 'antimanual' ), array( 'status' => 409 ) );
    }

    $content = wp_strip_all_tags( $post->post_content );
    $chunks  = antimanual_split_content_by_words( $content );

    if ( empty( $chunks ) ) {
        return new WP_Error( 'empty_content', __( 'The content of the post is empty.', 'antimanual' ), array( 'status' => 400 ) );
    }

    foreach ( $chunks as $chunk_index => $chunk ) {
        try {
            $embedding = antimanual_generate_embedding( $chunk, atml_option( 'openai_api_key' ) );
        } catch ( Exception $e ) {
            return new WP_Error( 'embedding_failed', __( "Could not generate embedding", 'antimanual' ), array( 'status' => 500 ) );
        }

        $inserted  = $wpdb->insert(
            $table_name,
            array(
                'post_id'           => $post->ID,
                'chunk_index'       => $chunk_index,
                'chunk_text'        => $chunk,
                'embedding'         => wp_json_encode( $embedding ),
                'post_modified_gmt' => $post->post_modified_gmt,
            )
        );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Could not save to the database.', 'antimanual' ), array( 'status' => 500 ) );
        }
    }

    return true;
}

function antimanual_remove_post_from_kb_common( int $post_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'antimanual_embeddings';

    if ( ! $post_id ) {
        return new WP_Error( 'invalid_post_id', __( 'Invalid post ID.', 'antimanual' ), array( 'status' => 400 ) );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'post_not_found', __( 'Post not found.', 'antimanual' ), array( 'status' => 404 ) );
    }

    $result = $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM %i WHERE post_id = %d',
            $table_name,
            $post->ID
        )
    );

    if ( false === $result ) {
        return new WP_Error( 'db_error', __( 'Could not remove from the database.', 'antimanual' ), array( 'status' => 500 ) );
    }

    return true;
}
