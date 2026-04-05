<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\Conversation;
use Antimanual\Embedding;

/**
 * Chatbot and Search API endpoints.
 */
class ChatbotController {


    /**
     * Register REST routes for chatbot and search.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/messages', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'new_message' ],
            'permission_callback' => [ $this, 'check_public_nonce_permission' ],
            'timeout'             => 120,
            'args'                => [
                'message'         => [
                    'required'          => true,
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_string( $param );
                    },
                ],
                'conversation_id' => [
                    'required'          => false,
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_string( $param );
                    },
                ],
            ],
        ] );

        register_rest_route( $namespace, '/search', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'search_message' ],
            'permission_callback' => [ $this, 'check_public_nonce_permission' ],
            'timeout'             => 120,
            'args'                => [
                'message' => [
                    'required'          => true,
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_string( $param );
                    },
                ],
                'answer_detail' => [
                    'required'          => false,
                    'default'           => 'balanced',
                    'validate_callback' => function( $param, $request, $key ) {
                        if ( ! is_string( $param ) ) {
                            return false;
                        }

                        return in_array( sanitize_key( $param ), [ 'brief', 'balanced', 'detailed' ], true );
                    },
                ],
            ],
        ]);

        register_rest_route( $namespace, '/conversations', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_conversations' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/conversations/import', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'import_conversations' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/conversations/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_conversation' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/conversations', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_all_conversations' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        // Lead collection routes
        register_rest_route( $namespace, '/leads', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_leads' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
            'args'                => [
                'page'     => [
                    'required' => false,
                    'default'  => 1,
                    'type'     => 'integer',
                ],
                'per_page' => [
                    'required' => false,
                    'default'  => 20,
                    'type'     => 'integer',
                ],
                'search'   => [
                    'required' => false,
                    'default'  => '',
                    'type'     => 'string',
                ],
                'status'   => [
                    'required' => false,
                    'default'  => '',
                    'type'     => 'string',
                ],
                'order'    => [
                    'required' => false,
                    'default'  => 'DESC',
                    'type'     => 'string',
                ],
            ],
        ] );

        register_rest_route( $namespace, '/leads/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_leads_stats' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/leads/export', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_leads' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
            'args'                => [
                'date_from' => [
                    'required' => false,
                    'default'  => '',
                    'type'     => 'string',
                ],
                'date_to'   => [
                    'required' => false,
                    'default'  => '',
                    'type'     => 'string',
                ],
            ],
        ] );

        register_rest_route( $namespace, '/leads/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_lead' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/leads/bulk-delete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'bulk_delete_leads' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
            'args'                => [
                'ids' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ] );

        register_rest_route( $namespace, '/leads/(?P<id>\d+)/note', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_lead_note' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
            'args'                => [
                'note' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ] );

        register_rest_route( $namespace, '/leads/(?P<id>\d+)/status', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_lead_status' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
            'args'                => [
                'status' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ] );

        // Public endpoint for submitting lead from frontend chatbot
        register_rest_route( $namespace, '/leads/submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_lead' ],
            'permission_callback' => [ $this, 'check_public_nonce_permission' ],
            'timeout'             => 120,
            'args'                => [
                'conversation_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'email'           => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'name'            => [
                    'required' => false,
                    'default'  => '',
                    'type'     => 'string',
                ],
            ],
        ] );

        // Chatbot Feedback (public endpoint).
        register_rest_route( $namespace, '/chatbot/feedback', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_feedback' ],
            'permission_callback' => [ $this, 'check_public_nonce_permission' ],
            'timeout'             => 120,
            'args'                => [
                'conversation_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'message_index' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'is_helpful' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
            ],
        ] );

        // Unresolved Questions (admin only).
        register_rest_route( $namespace, '/chatbot/unresolved', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_unresolved_questions' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'page'     => [
                    'required' => false,
                    'default'  => 1,
                    'type'     => 'integer',
                ],
                'per_page' => [
                    'required' => false,
                    'default'  => 20,
                    'type'     => 'integer',
                ],
            ],
        ] );
    }

    /**
     * Get the appropriate AI provider instance.

     *
     * @return OpenAI|Gemini The AI provider instance.
     */
    private function get_ai( $provider = null ) {
        return AIProvider::get();
    }

    /**
     * Get the current active provider name.
     *
     * @return string 'openai' or 'gemini'
     */
    private function get_provider() {
        return AIProvider::get_name();
    }

    /**
     * Handle new chat message request.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response|\WP_Error Response object or error.
     */
    public function new_message( $request ) {
        if ( atml_is_chatbot_limit_exceeded() ) {
            return new \WP_Error(
                'monthly_limit_exceeded',
                __( 'You have reached your monthly limit of 60 chatbot conversations. Upgrade to Pro for unlimited conversations.', 'antimanual' )
            );
        }

        $message         = trim( (string) $request->get_param( 'message' ) );
        $conversation_id = intval( $request->get_param( 'conversation_id' ) );

        if ( '' === $message ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Please enter a message.', 'antimanual' ),
            ]);
        }

        if ( atml_chatbot_is_message_blocked( $message ) ) {
            $block_message = atml_option( 'chatbot_block_message' ) ?: __( 'This message cannot be processed.', 'antimanual' );

            return $this->build_quick_reply_response( $block_message, $conversation_id );
        }

        $chatbot_config = atml_get_chatbot_configs();
        $handoff_data   = $this->get_handoff_response_data( $message, $chatbot_config );

        if ( $handoff_data ) {
            $conversation_id = Conversation::new_message(
                [
                    'role'    => Conversation::$roles[0],
                    'content' => $message,
                ],
                $conversation_id ?? 0,
            );

            $conversation_id = Conversation::new_message(
                [
                    'role'    => Conversation::$roles[1],
                    'content' => $handoff_data['answer'],
                ],
                $conversation_id ?? 0,
            );

            return rest_ensure_response([
                'success' => true,
                'data'    => array_merge(
                    [
                        'conversation_id' => $conversation_id,
                        'references'      => [],
                        'is_irrelevant'   => false,
                    ],
                    $handoff_data
                ),
            ]);
        }

        // Check Custom Answers before calling the AI (Pro only).
        $custom_match = class_exists( '\Antimanual_Pro\CustomAnswer' ) ? \Antimanual_Pro\CustomAnswer::find_match( $message ) : null;
        if ( $custom_match ) {
            $conversation_id = Conversation::new_message(
                [
                    'role'    => Conversation::$roles[0],
                    'content' => $message,
                ],
                $conversation_id ?? 0,
            );

            $conversation_id = Conversation::new_message(
                [
                    'role'    => Conversation::$roles[1],
                    'content' => $custom_match['answer'],
                ],
                $conversation_id ?? 0,
            );

            return rest_ensure_response([
                'success' => true,
                'data'    => [
                    'conversation_id' => $conversation_id,
                    'answer'          => $custom_match['answer'],
                    'references'      => [],
                    'is_custom'       => true,
                ],
            ]);
        }

        $response_length      = sanitize_key( (string) ( $chatbot_config['response_length'] ?? 'balanced' ) );
        $max_output_tokens    = $this->get_chatbot_max_output_tokens( $response_length );

        // Detect short keyword queries (≤ 3 words) and adjust lookup strategy.
        // Use Unicode-aware word counting for proper non-Latin language support.
        $word_count = $this->count_unicode_words( $message );
        $is_short   = $word_count <= 3;

        // For short queries, expand the lookup phrase so embeddings match more
        // relevant chunks, and fetch a wider set with a lower similarity floor.
        $lookup_query         = $is_short ? $this->expand_short_query_for_search( $message ) : $message;
        $related_chunk_limit  = $is_short
            ? $this->get_chat_context_chunk_limit( $response_length ) + 2
            : $this->get_chat_context_chunk_limit( $response_length );
        $related_chunk_limit  = min( $related_chunk_limit, 10 );
        $similarity_threshold = $is_short ? 0.1 : $this->get_chat_context_similarity_threshold();
        $related_chunks       = antimanual_get_related_chunks( $lookup_query, $related_chunk_limit );

        if ( is_array( $related_chunks ) && isset( $related_chunks['error'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => sanitize_text_field( $related_chunks['error'] ),
            ]);
        }

        if ( ! is_array( $related_chunks ) ) {
            $related_chunks = [];
        }

        $reference_candidates = [];
        $context              = '';
        $chunk_no             = 0;

        foreach ( $related_chunks as $chunk ) {
            if ( ! is_array( $chunk ) || empty( $chunk['row'] ) || ! is_object( $chunk['row'] ) ) {
                continue;
            }

            $similarity = $chunk['similarity'] ?? 0;
            if ( $similarity < $similarity_threshold ) {
                continue;
            }

            $row        = $chunk['row'];
            $chunk_text = is_string( $row->chunk_text ?? '' ) ? $row->chunk_text : '';

            if ( '' === $chunk_text ) {
                continue;
            }

            $chunk_no++;
            $context .= "\n===== CHUNK $chunk_no START {similarity: $similarity} =====\n" . $chunk_text . "\n===== CHUNK $chunk_no END =====\n";

            // Lower reference threshold for short queries so sources are still surfaced.
            $ref_threshold = $is_short ? 0.35 : 0.55;
            if ( $similarity > $ref_threshold ) {
                $ref = Embedding::get_chunk_reference( $row );
                if ( $this->is_valid_reference( $ref ) ) {
                    $reference_candidates[] = [
                        'reference'  => $ref,
                        'chunk_text' => $chunk_text,
                        'similarity' => (float) $similarity,
                    ];
                }
            }
        }

        $messages = [
            [
                'role'    => 'user',
                'content' => "\n===== CONTEXT START =====\n" . $context . "\n===== CONTEXT END =====\n",
            ],
            [
                'role'    => 'user',
                'content' => "\n===== QUERY START =====\n" . $message . "\n===== QUERY END =====\n",
            ],
        ];

        $instructions = atml_get_chatbot_instructions();

        // For short keyword queries, append an explicit handling instruction so the
        // AI treats the keyword as a topic lookup rather than an ambiguous question.
        if ( $is_short ) {
            $short_query_instruction = "\n\n## Short Query Handling\n- The user has sent a short keyword or phrase. Treat it as a topic lookup and summarise all relevant information from the context about that topic. Do not refuse to answer solely because the query is short.\n- Always respond in the same language as the user's query.";
            $instructions           .= $short_query_instruction;
        }

        $provider_conversation_id = Conversation::get_provider_conversation_id( $conversation_id );

        // Determine which provider to use for this conversation
        $conversation_provider = Conversation::get_provider( $conversation_id );
        $current_provider      = $this->get_provider();

        // For new conversations, use current provider
        // For existing conversations, use the provider that started it
        $use_provider = ! empty( $conversation_provider ) ? $conversation_provider : $current_provider;
        $ai = $this->get_ai( $use_provider );

        // Removed defunct create_conversation call that was causing latency

        // For Gemini, we need to prepend conversation history since Gemini doesn't store it server-side
        $input_messages = $messages;
        if ( 'gemini' === $use_provider && $conversation_id > 0 ) {
            $previous_messages = Conversation::get_messages_for_ai( $conversation_id );
            if ( ! empty( $previous_messages ) ) {
                $input_messages = array_merge( $previous_messages, $messages );
            }
        }

        $reply_data = $ai->get_reply( $input_messages, $provider_conversation_id, $instructions, $max_output_tokens );

        if ( isset( $reply_data['error'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $reply_data['error'],
            ]);
        }

        $reply = '';
        if ( is_array( $reply_data ) && isset( $reply_data['reply'] ) ) {
            $reply = $reply_data['reply'];
            if ( ! empty( $reply_data['conversation_id'] ) ) {
                $provider_conversation_id = $reply_data['conversation_id'];
            }
        } elseif ( is_string( $reply_data ) ) {
            $reply = $reply_data;
        }

        $references = $this->select_references_for_answer( $reference_candidates, $reply, 4 );

        $conversation_id = Conversation::new_message(
            [
                'role'    => Conversation::$roles[0],
                'content' => $message,
            ],
            $conversation_id ?? 0,
            $provider_conversation_id ?? '',
            $use_provider,
        );

        $conversation_id = Conversation::new_message(
            [
                'role'    => Conversation::$roles[1],
                'content' => $reply,
            ],
            $conversation_id ?? 0,
        );

        if ( ! atml_is_pro() ) {
            \Antimanual\UsageTracker::increment( 'chatbot' );
        }

        $irrelevant_ans = atml_option( 'chatbot_irrelevant_ans' );
        if ( empty( $irrelevant_ans ) ) {
            $irrelevant_ans = __( 'Sorry, I don\'t have enough information to answer your question.', 'antimanual' );
        }

        $is_irrelevant = ( 0 === $chunk_no ) || ( false !== strpos( wp_strip_all_tags( $reply ), $irrelevant_ans ) );

        // Flag conversations as unresolved
        if ( $is_irrelevant && $conversation_id > 0 ) {
            update_post_meta( $conversation_id, '_atml_unresolved', '1' );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'conversation_id' => $conversation_id,
                'answer'          => $reply,
                'references'      => $references,
                'is_irrelevant'   => $is_irrelevant,
            ]
        ]);
    }

    /**
     * List all conversations.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response Response object.
     */
    public function list_conversations( $request ) {
        $messages = Conversation::list_conversations();

        return rest_ensure_response([
            'success' => true,
            'data'    => $messages,
        ]);
    }

    /**
     * Delete a conversation.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function delete_conversation( $request ) {
        $conversation_id = intval( $request->get_param( 'id' ) );
        $success         = Conversation::delete_conversation( $conversation_id );

        if ( ! $success ) {
            return rest_ensure_response([
                'success' => false,
                'data'    => __( 'Failed to delete conversation.', 'antimanual' ),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'Conversation deleted successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Delete all conversations.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function delete_all_conversations( $request ) {
        $success = Conversation::delete_all_conversations();

        if ( ! $success ) {
            return rest_ensure_response([
                'success' => false,
                'data'    => __( 'Failed to clear all conversations.', 'antimanual' ),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'All conversations cleared successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Import conversations from a JSON export.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function import_conversations( $request ) {
        $payload = $request->get_json_params();
        $items   = is_array( $payload ) ? ( $payload['conversations'] ?? [] ) : [];

        if ( empty( $items ) || ! is_array( $items ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid file format. Expected a conversations array.', 'antimanual' ),
            ]);
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                $skipped++;
                /* translators: %d: conversation number in the imported file */
                $errors[] = sprintf( __( 'Conversation %d is invalid.', 'antimanual' ), $index + 1 );
                continue;
            }

            $messages = $item['messages'] ?? [];
            if ( ! is_array( $messages ) ) {
                $messages = [];
            }

            $title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
            if ( empty( $title ) && ! empty( $messages ) ) {
                $first_message = $messages[0]['message'] ?? $messages[0]['content'] ?? '';
                $title = sanitize_text_field( wp_strip_all_tags( $first_message ) );
            }

            if ( empty( $title ) ) {
                $title = __( 'Conversation', 'antimanual' );
            }

            $created_at = $this->normalize_timestamp( $item['created_at'] ?? 0 );
            $post_args  = [
                'post_title'  => $title,
                'post_status' => 'publish',
                'post_type'   => Conversation::$post_type,
            ];

            if ( $created_at > 0 ) {
                $post_args['post_date'] = gmdate( 'Y-m-d H:i:s', $created_at );
            }

            $conversation_id = wp_insert_post( $post_args, true );

            if ( is_wp_error( $conversation_id ) ) {
                $skipped++;
                $errors[] = $conversation_id->get_error_message();
                continue;
            }

            $stored_messages = [];
            foreach ( $messages as $message ) {
                if ( ! is_array( $message ) ) {
                    continue;
                }

                $sender = $message['sender'] ?? $message['role'] ?? '';
                $sender = strtolower( sanitize_text_field( $sender ) );
                if ( in_array( $sender, [ 'bot', 'ai' ], true ) ) {
                    $sender = 'assistant';
                }

                if ( ! in_array( $sender, Conversation::$roles, true ) ) {
                    continue;
                }

                $content = $message['message'] ?? $message['content'] ?? '';
                if ( $content === '' ) {
                    continue;
                }

                $message_created_at = $this->normalize_timestamp( $message['created_at'] ?? 0 );
                if ( $message_created_at <= 0 ) {
                    $message_created_at = current_time( 'timestamp' );
                }

                $stored_messages[] = [
                    'created_at' => $message_created_at,
                    'sender'     => $sender,
                    'message'    => wp_kses_post( $content ),
                ];
            }

            update_post_meta( $conversation_id, Conversation::$meta_messages, $stored_messages );

            if ( ! empty( $item['provider'] ) ) {
                update_post_meta( $conversation_id, Conversation::$meta_provider, sanitize_text_field( $item['provider'] ) );
            }

            if ( ! empty( $item['provider_conversation_id'] ) ) {
                update_post_meta( $conversation_id, Conversation::$meta_provider_id, sanitize_text_field( $item['provider_conversation_id'] ) );
            }

            $imported++;
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ],
        ]);
    }

    /**
     * Normalize timestamps from seconds or milliseconds.
     *
     * @param mixed $value Timestamp value.
     * @return int
     */
    private function normalize_timestamp( $value ): int {
        if ( ! is_numeric( $value ) ) {
            return 0;
        }

        $timestamp = (int) $value;
        if ( $timestamp > 9999999999 ) {
            $timestamp = (int) floor( $timestamp / 1000 );
        }

        return $timestamp;
    }

    /**
     * Handle search message request.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response|\WP_Error Response object or error.
     */
    public function search_message( $request ) {
        if ( atml_is_search_block_limit_exceeded() ) {
            return new \WP_Error(
                'limit_exceeded',
                __( 'You have reached your limit of 100 search queries. Upgrade to Pro for unlimited searches.', 'antimanual' )
            );
        }

        $message = trim( (string) $request->get_param( 'message' ) );
        if ( '' === $message ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Please enter a message.', 'antimanual' ),
            ]);
        }

        $answer_detail = sanitize_key( (string) $request->get_param( 'answer_detail' ) );
        if ( ! in_array( $answer_detail, [ 'brief', 'balanced', 'detailed' ], true ) ) {
            $answer_detail = 'balanced';
        }

        // Detect short keyword queries (≤ 3 words) and adjust lookup strategy.
        // Use Unicode-aware word counting for proper non-Latin language support.
        $word_count  = $this->count_unicode_words( $message );
        $is_short    = $word_count <= 3;

        // For short queries, expand the lookup phrase so embeddings match more
        // relevant chunks, and fetch a wider set with a lower similarity floor.
        $lookup_query         = $is_short ? $this->expand_short_query_for_search( $message ) : $message;
        $related_chunk_limit  = $is_short
            ? $this->get_search_context_chunk_limit( $answer_detail ) + 2
            : $this->get_search_context_chunk_limit( $answer_detail );
        $related_chunk_limit  = min( $related_chunk_limit, 10 );
        $similarity_threshold = $is_short ? 0.1 : $this->get_search_context_similarity_threshold();
        $max_output_tokens    = $this->get_search_max_output_tokens( $answer_detail );
        $related_chunks       = antimanual_get_related_chunks( $lookup_query, $related_chunk_limit );

        if ( is_array( $related_chunks ) && isset( $related_chunks['error'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => sanitize_text_field( $related_chunks['error'] ),
            ]);
        }

        if ( ! is_array( $related_chunks ) ) {
            $related_chunks = [];
        }

        $reference_candidates = [];
        $context              = '';

        foreach ( $related_chunks as $chunk ) {
            if ( ! is_array( $chunk ) || empty( $chunk['row'] ) || ! is_object( $chunk['row'] ) ) {
                continue;
            }

            $similarity = $chunk['similarity'] ?? 0;

            if ( $similarity < $similarity_threshold ) {
                continue;
            }

            $row        = $chunk['row'];
            $chunk_text = is_string( $row->chunk_text ?? '' ) ? $row->chunk_text : '';

            if ( '' === $chunk_text ) {
                continue;
            }

            $context .= "\n===== CHUNK START =====\n" . $chunk_text . "\n===== CHUNK END =====\n";

            // Lower reference threshold for short queries so sources are still surfaced.
            $ref_threshold = $is_short ? 0.35 : 0.55;
            if ( $similarity > $ref_threshold ) {
                $ref = Embedding::get_chunk_reference( $row );

                if ( $this->is_valid_reference( $ref ) ) {
                    $reference_candidates[] = [
                        'reference'  => $ref,
                        'chunk_text' => $chunk_text,
                        'similarity' => (float) $similarity,
                    ];
                }
            }
        }

        $detail_instruction = $this->get_search_detail_instruction( $answer_detail );

        // Build a search-specific system instruction that explicitly handles keyword queries.
        $short_query_instruction = $is_short
            ? 'The user has searched using a short keyword or phrase. Treat it as a topic lookup: summarise all relevant information from the context about that topic without requiring a full question. Always respond in the same language as the user\'s query.'
            : '';

        $elaborate_instruction = "
            ## Query Handling
            - The QUERY may be a full question, a short phrase, or one or two keywords.
            - Always treat the QUERY as a valid search intent. Never refuse to answer solely because the query is short.
            - {$short_query_instruction}

            ## Response Depth
            - {$detail_instruction}

            ## HTML Guidelines
            Allowed tags only: `<h4>`, `<h5>`, `<h6>`, `<p>`, `<pre>`, `<strong>`, `<em>`, `<a>`, `<img>`, `<ul>`, `<ol>`, `<li>`, `<div class=\"conclusion\">`.
            - Use `<h4>` for main headings, `<h5>` for subheadings, and `<h6>` for minor headings.
            - Wrap paragraphs in `<p>`.
            - Use `<div class=\"conclusion\">` only for an optional final takeaway section.
            - Do not include any tags outside the allowed list.
        ";

        $instructions = atml_get_chatbot_instructions();

        if ( $instructions ) {
            $instructions .= "\n" . $elaborate_instruction;
        } else {
            $instructions = $elaborate_instruction;
        }

        $messages = [
            [
                'role'    => 'user',
                'content' => "\n===== CONTEXT START =====\n" . $context . "\n===== CONTEXT END =====\n",
            ],
            [
                'role'    => 'user',
                'content' => "\n===== QUERY START =====\n" . $message . "\n===== QUERY END =====\n",
            ],
        ];

        $reply_data = AIProvider::get_reply( $messages, '', $instructions, $max_output_tokens );

        if ( isset( $reply_data['error'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $reply_data['error'],
            ]);
        }

        $reply = is_array( $reply_data ) && isset( $reply_data['reply'] ) ? $reply_data['reply'] : $reply_data;

        $reply = is_string( $reply ) ? $reply : '';
        $reply = $this->sanitize_search_answer_html( $reply );
        $references = $this->select_references_for_answer( $reference_candidates, $reply, 4 );

        // Track search block usage for analytics
        \Antimanual\UsageTracker::increment( 'search_block' );

        // Log search query and answer for analytics
        global $wpdb;
        $votes_table = $wpdb->prefix . 'antimanual_query_votes';
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$votes_table'" ) === $votes_table;

        $query_log_id = null;
        if ( $table_exists ) {
            $created_at = current_time( 'mysql' );

            $wpdb->insert( $votes_table, [
                'query'      => sanitize_text_field( $message ),
                'answer'     => $reply,
                'is_helpful' => null,
                'created_at' => $created_at,
            ] );

            $query_log_id = $wpdb->insert_id;
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'answer'       => $reply,
                'references'   => $references,
                'query_log_id' => $query_log_id,
            ]
        ]);
    }

    /**
     * Expand a short keyword query into a descriptive phrase for embedding lookup.
     *
     * Short single- or two-word queries produce embedding vectors that may not
     * match well against longer knowledge-base chunks. Wrapping the keyword in a
     * natural-language phrase ("information about X", "how to X", etc.) centres
     * the embedding closer to explanatory content without altering the user-facing
     * query that is ultimately sent to the AI.
     *
     * For non-Latin scripts (Arabic, CJK, Cyrillic, etc.) the query is returned
     * as-is because prepending English text corrupts the embedding vector and
     * prevents correct semantic matching against non-English knowledge-base content.
     *
     * @param string $query The original short query.
     * @return string Expanded search phrase.
     */
    private function expand_short_query_for_search( string $query ): string {
        $query = trim( $query );

        if ( '' === $query ) {
            return $query;
        }

        // If the query already looks like a question, return it as-is.
        if ( '?' === substr( $query, -1 ) ) {
            return $query;
        }

        // Check for Arabic question mark as well.
        if ( function_exists( 'mb_substr' ) && '؟' === mb_substr( $query, -1, 1, 'UTF-8' ) ) {
            return $query;
        }

        // If the query contains predominantly non-Latin characters, return as-is.
        // Prepending English text to Arabic / CJK / Cyrillic queries corrupts the
        // embedding vector and produces poor matches.
        if ( $this->is_non_latin_text( $query ) ) {
            return $query;
        }

        // Prepend a phrase that steers the embedding toward explanatory content.
        return 'Information and details about ' . $query;
    }

    /**
     * Detect whether the given text is predominantly non-Latin.
     *
     * Returns true when more than half of the Unicode letter characters in the
     * string fall outside the Basic Latin and Latin Extended blocks.
     *
     * @param string $text Input text.
     * @return bool True if the text is predominantly non-Latin.
     */
    private function is_non_latin_text( string $text ): bool {
        // Match all Unicode letters.
        if ( ! preg_match_all( '/\pL/u', $text, $all_matches ) ) {
            return false;
        }

        $total_letters = count( $all_matches[0] );
        if ( 0 === $total_letters ) {
            return false;
        }

        // Match only Latin letters (Basic Latin + Latin Extended-A/B/Additional).
        preg_match_all( '/[\x{0041}-\x{024F}\x{1E00}-\x{1EFF}]/u', $text, $latin_matches );
        $latin_count = count( $latin_matches[0] );

        // If fewer than half the letters are Latin, consider it non-Latin text.
        return $latin_count < ( $total_letters / 2 );
    }

    /**
     * Count words in a Unicode-safe manner.
     *
     * PHP's built-in str_word_count() only recognises Latin-alphabet characters.
     * For non-Latin scripts (Arabic, Hebrew, CJK, Cyrillic, etc.) it returns 0,
     * which breaks the short-query detection logic.
     *
     * This method splits on whitespace for most scripts and adds special handling
     * for CJK text (Chinese, Japanese, Korean) where words are not separated by
     * spaces — each CJK character is counted as one word-unit.
     *
     * @param string $text Input text.
     * @return int Number of words.
     */
    private function count_unicode_words( string $text ): int {
        $text = trim( $text );

        if ( '' === $text ) {
            return 0;
        }

        // Split on whitespace — works for Latin, Arabic, Cyrillic, Devanagari, etc.
        $parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $count = is_array( $parts ) ? count( $parts ) : 0;

        // For CJK text that has no spaces, each character represents a word-unit.
        // If we got 0 or 1 segments, check for CJK characters and count them.
        if ( $count <= 1 && preg_match_all( '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text, $cjk_matches ) ) {
            $cjk_count = count( $cjk_matches[0] );
            if ( $cjk_count > $count ) {
                return $cjk_count;
            }
        }

        return $count;
    }

    /**
     * Get response-style guidance for search answer detail.
     *
     * @param string $answer_detail Detail mode.
     * @return string
     */
    private function get_search_detail_instruction( string $answer_detail ): string {
        if ( 'brief' === $answer_detail ) {
            return 'Keep the answer concise and focused on the key point only.';
        }

        if ( 'detailed' === $answer_detail ) {
            return 'Provide a comprehensive, well-structured answer with meaningful depth when context supports it.';
        }

        return 'Provide a balanced answer with practical detail while staying concise.';
    }

    /**
     * Get related chunk limit for chatbot replies.
     *
     * @param string $response_length Chatbot response length setting.
     * @return int
     */
    private function get_chat_context_chunk_limit( string $response_length ): int {
        $limit_map = [
            'concise'  => 3,
            'balanced' => 4,
            'detailed' => 5,
        ];

        $default_limit = $limit_map[ $response_length ] ?? 4;
        $limit         = (int) apply_filters( 'antimanual_chatbot_related_chunk_limit', $default_limit, $response_length );

        return max( 1, min( $limit, 10 ) );
    }

    /**
     * Get related chunk limit for search answers.
     *
     * @param string $answer_detail Search answer detail setting.
     * @return int
     */
    private function get_search_context_chunk_limit( string $answer_detail ): int {
        $limit_map = [
            'brief'    => 3,
            'balanced' => 4,
            'detailed' => 5,
        ];

        $default_limit = $limit_map[ $answer_detail ] ?? 4;
        $limit         = (int) apply_filters( 'antimanual_search_related_chunk_limit', $default_limit, $answer_detail );

        return max( 1, min( $limit, 10 ) );
    }

    /**
     * Get minimum similarity threshold used to include chatbot context chunks.
     *
     * @return float
     */
    private function get_chat_context_similarity_threshold(): float {
        $threshold = (float) apply_filters( 'antimanual_chat_context_similarity_threshold', 0.2 );
        return max( 0.0, min( $threshold, 1.0 ) );
    }

    /**
     * Get minimum similarity threshold used to include search context chunks.
     *
     * @return float
     */
    private function get_search_context_similarity_threshold(): float {
        $threshold = (float) apply_filters( 'antimanual_search_context_similarity_threshold', 0.2 );
        return max( 0.0, min( $threshold, 1.0 ) );
    }

    /**
     * Select references that best match the generated answer.
     *
     * Uses bidirectional relevance scoring: checks both what fraction of the
     * chunk overlaps with the answer AND what fraction of the answer overlaps
     * with the chunk. This prevents short chunks with coincidental term matches
     * from being surfaced as irrelevant source links.
     *
     * @param array  $reference_candidates Candidate references with chunk text and similarity.
     * @param string $answer Generated answer.
     * @param int    $limit Maximum references to return.
     * @return array<int, array{title:string, link:string}>
     */
    private function select_references_for_answer( array $reference_candidates, string $answer, int $limit = 4 ): array {
        if ( empty( $reference_candidates ) || $limit < 1 ) {
            return [];
        }

        // Cap at 3 sources by default to reduce noisy references.
        $limit        = max( 1, min( $limit, 3 ) );
        $answer_terms = $this->tokenize_for_reference_scoring( $answer );
        $answer_set   = ! empty( $answer_terms ) ? array_fill_keys( $answer_terms, true ) : [];
        $answer_count = count( $answer_terms );
        $scored       = [];

        // Minimum combined score a candidate must reach to be included.
        $min_combined_score = (float) apply_filters( 'antimanual_reference_min_combined_score', 0.25 );

        foreach ( $reference_candidates as $candidate ) {
            if ( ! is_array( $candidate ) ) {
                continue;
            }

            $reference = $candidate['reference'] ?? [];
            if ( ! is_array( $reference ) || ! $this->is_valid_reference( $reference ) ) {
                continue;
            }

            $similarity = (float) ( $candidate['similarity'] ?? 0 );
            $similarity = max( 0.0, min( $similarity, 1.0 ) );

            $chunk_text  = is_string( $candidate['chunk_text'] ?? '' ) ? $candidate['chunk_text'] : '';
            $chunk_terms = $this->tokenize_for_reference_scoring( $chunk_text );

            $overlap_count       = 0;
            $chunk_overlap_ratio = 0.0; // fraction of chunk terms found in answer
            $answer_overlap_ratio = 0.0; // fraction of answer terms found in chunk

            if ( ! empty( $chunk_terms ) && ! empty( $answer_set ) ) {
                $chunk_set            = array_fill_keys( $chunk_terms, true );
                $overlap_count        = count( array_intersect_key( $chunk_set, $answer_set ) );
                $chunk_overlap_ratio  = $overlap_count / max( 1, count( $chunk_set ) );
                $answer_overlap_ratio = $overlap_count / max( 1, $answer_count );
            }

            // Require at least 3 overlapping meaningful terms AND 15% chunk-side overlap.
            // This is stricter than before (was 2 terms / 10%) to filter out noisy matches.
            if ( $overlap_count < 3 && $chunk_overlap_ratio < 0.15 ) {
                continue;
            }

            // Bidirectional relevance: geometric mean of both overlap directions.
            // This penalises chunks that share a few terms with a long answer (low answer_overlap)
            // or very short chunks where 2-3 common words produce a high chunk_overlap ratio.
            $bidirectional_overlap = sqrt( $chunk_overlap_ratio * $answer_overlap_ratio );

            $combined_score = ( 0.55 * $bidirectional_overlap ) + ( 0.45 * $similarity );

            // Drop candidates below the minimum quality threshold.
            if ( $combined_score < $min_combined_score ) {
                continue;
            }

            $scored[] = [
                'score'      => $combined_score,
                'similarity' => $similarity,
                'reference'  => $reference,
            ];
        }

        usort(
            $scored,
            function ( $a, $b ) {
                $score_compare = $b['score'] <=> $a['score'];
                if ( 0 !== $score_compare ) {
                    return $score_compare;
                }

                return $b['similarity'] <=> $a['similarity'];
            }
        );

        $selected   = [];
        $seen_links = [];

        foreach ( $scored as $item ) {
            $reference = $item['reference'];
            $link      = trim( (string) ( $reference['link'] ?? '' ) );
            $key       = strtolower( $link );

            if ( '' === $link || isset( $seen_links[ $key ] ) ) {
                continue;
            }

            $seen_links[ $key ] = true;
            $selected[]         = [
                'title' => (string) ( $reference['title'] ?? __( 'Source', 'antimanual' ) ),
                'link'  => $link,
            ];

            if ( count( $selected ) >= $limit ) {
                return $selected;
            }
        }

        if ( ! empty( $selected ) ) {
            return $selected;
        }

        // Fallback: only include references with very high embedding similarity
        // AND at least some lexical overlap with the answer. The previous fallback
        // (similarity >= 0.75, no overlap check) was surfacing unrelated articles.
        usort(
            $reference_candidates,
            function ( $a, $b ) {
                return ( (float) ( $b['similarity'] ?? 0 ) ) <=> ( (float) ( $a['similarity'] ?? 0 ) );
            }
        );

        foreach ( $reference_candidates as $candidate ) {
            if ( ! is_array( $candidate ) ) {
                continue;
            }

            $reference  = $candidate['reference'] ?? [];
            $similarity = (float) ( $candidate['similarity'] ?? 0 );

            // Raised from 0.75 → 0.82 so only truly relevant embeddings pass.
            if ( ! is_array( $reference ) || ! $this->is_valid_reference( $reference ) || $similarity < 0.82 ) {
                continue;
            }

            // Additionally require at least 1 meaningful term overlap with the answer.
            $chunk_text  = is_string( $candidate['chunk_text'] ?? '' ) ? $candidate['chunk_text'] : '';
            $chunk_terms = $this->tokenize_for_reference_scoring( $chunk_text );
            $fallback_overlap = 0;
            if ( ! empty( $chunk_terms ) && ! empty( $answer_set ) ) {
                $chunk_set        = array_fill_keys( $chunk_terms, true );
                $fallback_overlap = count( array_intersect_key( $chunk_set, $answer_set ) );
            }

            if ( $fallback_overlap < 1 ) {
                continue;
            }

            $link = trim( (string) ( $reference['link'] ?? '' ) );
            $key  = strtolower( $link );

            if ( '' === $link || isset( $seen_links[ $key ] ) ) {
                continue;
            }

            $seen_links[ $key ] = true;
            $selected[]         = [
                'title' => (string) ( $reference['title'] ?? __( 'Source', 'antimanual' ) ),
                'link'  => $link,
            ];

            if ( count( $selected ) >= $limit ) {
                break;
            }
        }

        return $selected;
    }

    /**
     * Validate a source reference entry.
     *
     * @param array $reference Reference array.
     * @return bool
     */
    private function is_valid_reference( array $reference ): bool {
        $link  = trim( (string) ( $reference['link'] ?? '' ) );
        $title = trim( (string) ( $reference['title'] ?? '' ) );

        if ( '' === $link || '' === $title || '#' === $link ) {
            return false;
        }

        $normalized = strtolower( $link );
        if ( 'about:blank' === $normalized || 0 === strpos( $normalized, 'javascript:' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Tokenize text for lightweight lexical reference scoring.
     *
     * @param string $text Input text.
     * @return array<int, string>
     */
    private function tokenize_for_reference_scoring( string $text ): array {
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = function_exists( 'mb_strtolower' )
            ? mb_strtolower( $text, 'UTF-8' )
            : strtolower( $text );

        $parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) {
            return [];
        }

        $stop_words = [
            'the', 'and', 'for', 'that', 'with', 'this', 'from', 'you', 'your', 'are', 'was',
            'were', 'have', 'has', 'had', 'into', 'about', 'can', 'will', 'not', 'but', 'our',
            'their', 'they', 'them', 'its', 'his', 'her', 'she', 'him', 'who', 'what', 'when',
            'where', 'why', 'how', 'all', 'any', 'too', 'very', 'also', 'just', 'than', 'then',
            'there', 'here', 'over', 'under', 'more', 'most', 'some', 'such', 'only', 'each',
            'per', 'via', 'use', 'using',
        ];

        $tokens = [];
        foreach ( $parts as $part ) {
            if ( is_numeric( $part ) ) {
                continue;
            }

            $length = function_exists( 'mb_strlen' )
                ? mb_strlen( $part, 'UTF-8' )
                : strlen( $part );

            if ( $length < 3 || in_array( $part, $stop_words, true ) ) {
                continue;
            }

            $tokens[] = $part;
        }

        return array_values( array_unique( $tokens ) );
    }

    /**
     * Get max output tokens for chatbot conversation replies.
     *
     * @param string $response_length Chatbot response length setting.
     * @return int
     */
    private function get_chatbot_max_output_tokens( string $response_length ): int {
        $token_map = [
            'concise'  => 800,
            'balanced' => 1500,
            'detailed' => 2800,
        ];

        $default_tokens = $token_map[ $response_length ] ?? 1500;
        $tokens         = (int) apply_filters( 'antimanual_chatbot_max_output_tokens', $default_tokens, $response_length );

        if ( $tokens <= 0 ) {
            return 0;
        }

        return max( 400, min( $tokens, 4000 ) );
    }

    /**
     * Get max output tokens for search answers.
     *
     * @param string $answer_detail Search answer detail setting.
     * @return int
     */
    private function get_search_max_output_tokens( string $answer_detail ): int {
        $token_map = [
            'brief'    => 600,
            'balanced' => 1200,
            'detailed' => 2400,
        ];

        $default_tokens = $token_map[ $answer_detail ] ?? 1200;
        $tokens         = (int) apply_filters( 'antimanual_search_max_output_tokens', $default_tokens, $answer_detail );

        if ( $tokens <= 0 ) {
            return 0;
        }

        return max( 400, min( $tokens, 4000 ) );
    }

    /**
     * Sanitize AI search HTML output before storing and returning it.
     *
     * @param string $html Raw AI HTML.
     * @return string
     */
    private function sanitize_search_answer_html( string $html ): string {
        $allowed_tags = [
            'h4'     => [],
            'h5'     => [],
            'h6'     => [],
            'p'      => [],
            'pre'    => [],
            'strong' => [],
            'em'     => [],
            'a'      => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
            'img'    => [
                'src'    => true,
                'alt'    => true,
                'title'  => true,
                'width'  => true,
                'height' => true,
            ],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'div'    => [
                'class' => true,
            ],
        ];

        return wp_kses( $html, $allowed_tags );
    }

    /**
     * Build a successful quick-reply response without invoking AI.
     *
     * @param string $message Response message.
     * @param int    $conversation_id Conversation ID.
     * @return \WP_REST_Response
     */
    private function build_quick_reply_response( string $message, int $conversation_id = 0 ) {
        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'conversation_id' => $conversation_id,
                'answer'          => wpautop( esc_html( $message ) ),
                'references'      => [],
            ],
        ]);
    }

    /**
     * Detect explicit handoff requests and prepare the live-chat response.
     *
     * @param string $message        Visitor message.
     * @param array  $chatbot_config Chatbot settings.
     * @return array|null
     */
    private function get_handoff_response_data( string $message, array $chatbot_config ): ?array {
        if ( ! atml_is_pro() || empty( $chatbot_config['escalation_enabled'] ) ) {
            return null;
        }

        if ( ! $this->is_handoff_intent( $message ) ) {
            return null;
        }

        $handoff_type = sanitize_key( (string) ( $chatbot_config['escalation_type'] ?? 'email' ) );
        $answer        = trim( (string) ( $chatbot_config['escalation_message'] ?? '' ) );

        if ( '' === $answer ) {
            $answer = __( 'I can connect you with a human for further help.', 'antimanual' );
        }

        $response = [
            'answer'               => $answer,
            'should_offer_handoff' => true,
            'handoff_intent'       => true,
            'handoff_type'         => $handoff_type,
            'live_chat_available'  => false,
        ];

        if ( 'live_chat' === $handoff_type ) {
            $response['live_chat_available'] = class_exists('\Antimanual_Pro\LiveChat') ? \Antimanual_Pro\LiveChat::any_agent_available() : false;
            $response['answer'] = $response['live_chat_available']
                ? __( 'I can connect you to a live agent now.', 'antimanual' )
                : $answer;
        }

        return $response;
    }

    /**
     * Check whether the visitor is explicitly asking for a human handoff.
     *
     * @param string $message Visitor message.
     * @return bool
     */
    private function is_handoff_intent( string $message ): bool {
        $normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );

        if ( '' === $normalized ) {
            return false;
        }

        $phrases = [
            'human agent',
            'human support',
            'support agent',
            'live chat',
            'livechat',
            'real person',
            'talk to a human',
            'talk to human',
            'speak to a human',
            'speak to human',
            'chat with a human',
            'chat with human',
            'connect me to a human',
            'connect me to human',
            'connect me with a human',
            'connect me with human',
            'contact a human',
            'contact support',
        ];

        foreach ( $phrases as $phrase ) {
            if ( false !== strpos( $normalized, $phrase ) ) {
                return true;
            }
        }

        $has_human_target = preg_match( '/\b(human|agent|support|representative|person)\b/', $normalized );
        $has_contact_verb = preg_match( '/\b(connect|contact|talk|speak|chat|transfer|escalate|reach)\b/', $normalized );

        return (bool) ( $has_human_target && $has_contact_verb );
    }

    /**
     * Permission callback for public routes that require nonce.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_public_nonce_permission( $request ) {
        if ( is_user_logged_in() ) {
            return true;
        }

        $nonce = $request->get_header( 'x_wp_nonce' );
        if ( ! $nonce ) {
            $nonce = $request->get_param( '_wpnonce' );
        }

        if ( ! $nonce ) {
            return new \WP_Error( 'rest_forbidden', __( 'Missing nonce.', 'antimanual' ), [ 'status' => 403 ] );
        }

        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'antimanual' ), [ 'status' => 403 ] );
        }

        return true;
    }

    // =========================================
    // Lead Collection Handlers
    // =========================================

    /**
     * List leads with pagination, search, and filtering.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response Response object.
     */
    public function list_leads( $request ) {
        $page     = absint( $request->get_param( 'page' ) );
        $per_page = absint( $request->get_param( 'per_page' ) );
        $search   = sanitize_text_field( $request->get_param( 'search' ) );
        $status   = sanitize_text_field( $request->get_param( 'status' ) );
        $order    = sanitize_text_field( $request->get_param( 'order' ) );

        $result = Conversation::list_leads( $page, $per_page, $search, $status, 'date', $order );

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * Get leads statistics.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response Response object.
     */
    public function get_leads_stats( $request ) {
        $stats = Conversation::get_leads_stats();

        return rest_ensure_response([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    /**
     * Export leads as JSON for CSV conversion.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response Response object.
     */
    public function export_leads( $request ) {
        $date_from = sanitize_text_field( $request->get_param( 'date_from' ) );
        $date_to   = sanitize_text_field( $request->get_param( 'date_to' ) );

        $leads = Conversation::export_leads( $date_from, $date_to );

        return rest_ensure_response([
            'success' => true,
            'data'    => $leads,
        ]);
    }

    /**
     * Delete a lead.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function delete_lead( $request ) {
        $lead_id = intval( $request->get_param( 'id' ) );
        $success = Conversation::delete_lead( $lead_id );

        if ( ! $success ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Failed to delete lead.', 'antimanual' ),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => __( 'Lead deleted successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Bulk delete leads.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function bulk_delete_leads( $request ) {
        $ids     = $request->get_param( 'ids' );
        $deleted = Conversation::bulk_delete_leads( is_array( $ids ) ? $ids : [] );

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'deleted' => $deleted,
            ],
            'message' => sprintf(
                /* translators: %d: number of deleted leads */
                __( '%d lead(s) deleted successfully.', 'antimanual' ),
                $deleted
            ),
        ]);
    }

    /**
     * Update a lead's note.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function update_lead_note( $request ) {
        $lead_id = intval( $request->get_param( 'id' ) );
        $note    = sanitize_textarea_field( $request->get_param( 'note' ) );

        $success = Conversation::update_lead_note( $lead_id, $note );

        return rest_ensure_response([
            'success' => $success,
            'message' => $success
                ? __( 'Note updated.', 'antimanual' )
                : __( 'Failed to update note.', 'antimanual' ),
        ]);
    }

    /**
     * Update a lead's status.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function update_lead_status( $request ) {
        $lead_id = intval( $request->get_param( 'id' ) );
        $status  = sanitize_text_field( $request->get_param( 'status' ) );

        $success = Conversation::update_lead_status( $lead_id, $status );

        return rest_ensure_response([
            'success' => $success,
            'message' => $success
                ? __( 'Status updated.', 'antimanual' )
                : __( 'Invalid status value.', 'antimanual' ),
        ]);
    }

    /**
     * Submit a lead from the frontend chatbot.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function submit_lead( $request ) {
        $conversation_id = intval( $request->get_param( 'conversation_id' ) );
        $email           = sanitize_email( $request->get_param( 'email' ) );
        $name            = sanitize_text_field( $request->get_param( 'name' ) );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Please enter a valid email address.', 'antimanual' ),
            ]);
        }

        // Get the current page URL as source
        $source = wp_get_referer() ?: '';

        $success = Conversation::set_lead( $conversation_id, $email, $name, $source );

        if ( ! $success ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Failed to save lead information.', 'antimanual' ),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => __( 'Thank you! Your email has been saved.', 'antimanual' ),
        ]);
    }
}
