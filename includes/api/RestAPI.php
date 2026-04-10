<?php
/**
 * SonoAI — REST API endpoints.
 *
 * Namespace: sonoai/v1
 *  POST /chat            — send a message (+ optional image)
 *  GET  /history         — list user's sessions
 *  GET  /history/{uuid}  — get single session messages
 *  DELETE /history/{uuid} — delete a session
 *  POST /embed-post      — manually trigger KB embedding (admin only)
 *  POST /saved           — save an assistant message
 *  GET  /saved           — list user's saved responses
 *  DELETE /saved/{id}    — delete a saved response
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RestAPI {

    private static ?RestAPI $instance = null;

    public static function instance(): RestAPI {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        $ns = 'sonoai/v1';

        register_rest_route( $ns, '/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_chat' ],
            'permission_callback' => [ $this, 'require_logged_in' ],
            'args'                => [
                'message'      => [ 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
                'session_uuid' => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'mode'         => [ 'sanitize_callback' => 'sanitize_key', 'default' => 'guideline' ],
                'stream'       => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
            ]
        ] );

        register_rest_route( $ns, '/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_list_history' ],
            'permission_callback' => [ $this, 'require_logged_in' ],
        ] );

        register_rest_route( $ns, '/history/(?P<uuid>[0-9a-f\-]{36})', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_get_session' ],
                'permission_callback' => [ $this, 'require_logged_in' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'handle_delete_session' ],
                'permission_callback' => [ $this, 'require_logged_in' ],
            ],
        ] );

        register_rest_route( $ns, '/embed-post', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_embed_post' ],
            'permission_callback' => [ $this, 'require_admin' ],
            'args'                => [
                'post_id' => [ 'required' => true, 'validate_callback' => 'is_numeric', 'sanitize_callback' => 'absint' ],
            ]
        ] );

        // ── Saved responses ───────────────────────────────────────────────────
        register_rest_route( $ns, '/saved', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_save_response' ],
                'permission_callback' => [ $this, 'require_logged_in' ],
                'args'                => [
                    'session_uuid'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                    'content'       => [ 'required' => true, 'sanitize_callback' => 'wp_kses_post' ],
                    'message_index' => [ 'sanitize_callback' => 'absint' ],
                    'mode'          => [ 'sanitize_callback' => 'sanitize_key' ],
                    'topic_slug'    => [ 'sanitize_callback' => 'sanitize_key' ],
                ]
            ],
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_list_saved' ],
                'permission_callback' => [ $this, 'require_logged_in' ],
            ],
        ] );

        register_rest_route( $ns, '/saved/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'handle_delete_saved' ],
            'permission_callback' => [ $this, 'require_logged_in' ],
        ] );

        // ── Feedback ──────────────────────────────────────────────────────────
        register_rest_route( $ns, '/feedback', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_feedback' ],
            'permission_callback' => [ $this, 'require_logged_in' ],
            'args'                => [
                'session_uuid'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'vote'          => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                'message_index' => [ 'sanitize_callback' => 'absint' ],
                'comment'       => [ 'sanitize_callback' => 'sanitize_textarea_field' ],
            ]
        ] );
    }

    // ── Permission callbacks ──────────────────────────────────────────────────

    public function require_logged_in(): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'unauthenticated', __( 'You must be logged in.', 'sonoai' ), [ 'status' => 401 ] );
        }
        return true;
    }

    public function require_admin(): bool|\WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'unauthorized', __( 'Admin access required.', 'sonoai' ), [ 'status' => 403 ] );
        }
        return true;
    }

    // ── POST /chat ────────────────────────────────────────────────────────────

    public function handle_chat( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id      = get_current_user_id();
        $message      = sanitize_textarea_field( $request->get_param( 'message' ) ?? '' );
        $session_uuid = sanitize_text_field( $request->get_param( 'session_uuid' ) ?? '' );
        $mode         = sanitize_key( $request->get_param( 'mode' ) ?? 'guideline' );
        $mode         = in_array( $mode, [ 'guideline', 'research' ], true ) ? $mode : 'guideline';

        if ( empty( $message ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Message cannot be empty.', 'sonoai' ) ], 400 );
        }



        // ── Session management ───────────────────────────────────────────────
        $is_new_session = false;
        if ( empty( $session_uuid ) ) {
            $session_uuid   = Chat::create_session( $user_id, $message, $mode );
            $is_new_session = true;
        } else {
            // Verify ownership.
            $session = Chat::get_session( $session_uuid, $user_id );
            if ( null === $session ) {
                return new \WP_REST_Response( [ 'error' => __( 'Session not found.', 'sonoai' ) ], 404 );
            }
        }

        // ── Store user message ───────────────────────────────────────────────
        Chat::add_message( $session_uuid, 'user', $message, '' );
        RedisManager::instance()->store_memory( $session_uuid, [ 'role' => 'user', 'content' => $message ] );

        // ── Build prompt + history ───────────────────────────────────────────
        $history        = Chat::get_messages_for_ai( $session_uuid );
        $history_length = count( $history ); // Turn count before current message
        
        $context_data  = RAG::get_context_data( $message, $mode, $session_uuid, $history_length );
        $system_prompt = $context_data['prompt'];
        $context_imgs  = $context_data['images'];

        // Build messages array for the AI provider.
        $ai_messages   = array_merge(
            [ [ 'role' => 'system', 'content' => $system_prompt ] ],
            $history
        );

        // ── AI call ──────────────────────────────────────────────────────────
        $stream = (bool) $request->get_param( 'stream' );

        if ( $stream ) {
            if ( ob_get_level() ) {
                @ob_end_clean();
            }
            header( 'Content-Type: text/event-stream' );
            header( 'Cache-Control: no-cache' );
            header( 'Connection: keep-alive' );
            header( 'X-Accel-Buffering: no' );

            echo "event: meta\ndata: " . wp_json_encode( [
                'session_uuid'   => $session_uuid,
                'is_new_session' => $is_new_session,
                'mode'           => $mode,
                'context_images' => $context_imgs,
            ] ) . "\n\n";
            @ob_flush(); flush();

            $reply = AIProvider::stream_reply( $ai_messages, function( $chunk ) {
                echo "event: chunk\ndata: " . wp_json_encode( [ 'chunk' => $chunk ] ) . "\n\n";
                @ob_flush(); flush();
            } );

            if ( is_wp_error( $reply ) ) {
                echo "event: error\ndata: " . wp_json_encode( [ 'error' => $reply->get_error_message() ] ) . "\n\n";
            } else {
                Chat::add_message( $session_uuid, 'assistant', $reply, '', $context_imgs );
                RedisManager::instance()->store_memory( $session_uuid, [ 'role' => 'assistant', 'content' => $reply ] );
                
                if ( str_contains( $reply, 'I cannot answer this question because I have not yet been trained' ) || str_contains( $reply, 'I cannot answer questions or discuss topics outside of this medical domain' ) ) {
                    Chat::log_unanswered_query( $user_id, $message, $reply );
                }

                echo "event: meta_end\ndata: " . wp_json_encode( [ 'context_images' => $context_imgs ] ) . "\n\n";
            }
            exit;
        }

        $reply = AIProvider::get_reply( $ai_messages );

        if ( is_wp_error( $reply ) ) {
            return new \WP_REST_Response( [ 'error' => $reply->get_error_message() ], 502 );
        }

        // ── Store AI reply ───────────────────────────────────────────────────
        Chat::add_message( $session_uuid, 'assistant', $reply, '', $context_imgs );
        RedisManager::instance()->store_memory( $session_uuid, [ 'role' => 'assistant', 'content' => $reply ] );

        if ( str_contains( $reply, 'I cannot answer this question because I have not yet been trained' ) || str_contains( $reply, 'I cannot answer questions or discuss topics outside of this medical domain' ) ) {
            Chat::log_unanswered_query( $user_id, $message, $reply );
        }

        return new \WP_REST_Response( [
            'reply'          => $reply,
            'session_uuid'   => $session_uuid,
            'is_new_session' => $is_new_session,
            'context_images' => $context_imgs,
            'mode'           => $mode,
        ], 200 );
    }

    // ── GET /history ──────────────────────────────────────────────────────────

    public function handle_list_history( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id  = get_current_user_id();
        $sessions = Chat::list_sessions( $user_id );
        return new \WP_REST_Response( $sessions, 200 );
    }

    // ── GET /history/{uuid} ───────────────────────────────────────────────────

    public function handle_get_session( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id      = get_current_user_id();
        $session_uuid = $request->get_param( 'uuid' );
        $session      = Chat::get_session( $session_uuid, $user_id );

        if ( null === $session ) {
            return new \WP_REST_Response( [ 'error' => __( 'Session not found.', 'sonoai' ) ], 404 );
        }

        return new \WP_REST_Response( $session, 200 );
    }

    // ── DELETE /history/{uuid} ────────────────────────────────────────────────

    public function handle_delete_session( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id      = get_current_user_id();
        $session_uuid = $request->get_param( 'uuid' );
        $deleted      = Chat::delete_session( $session_uuid, $user_id );

        if ( ! $deleted ) {
            return new \WP_REST_Response( [ 'error' => __( 'Session not found or already deleted.', 'sonoai' ) ], 404 );
        }

        return new \WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── POST /embed-post ──────────────────────────────────────────────────────

    public function handle_embed_post( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = absint( $request->get_param( 'post_id' ) );

        if ( $post_id <= 0 ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid post_id.' ], 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found or not published.' ], 404 );
        }

        $content    = $post->post_content;
        $image_urls = [];
        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
            foreach ( $matches[0] as $i => $full_tag ) {
                $url = $matches[1][$i];
                $label = '';
                if ( preg_match( '/alt=["\']([^"\']+)["\']/', $full_tag, $alt_match ) ) {
                    $label = $alt_match[1];
                } elseif ( preg_match( '/title=["\']([^"\']+)["\']/', $full_tag, $title_match ) ) {
                    $label = $title_match[1];
                }
                if ( empty( $label ) ) {
                    $label = ucwords( str_replace( [ '-', '_' ], ' ', pathinfo( parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_FILENAME ) ) );
                }
                $image_urls[] = [ 'url' => $url, 'label' => $label ];
            }
        }

        $clean_content = sonoai_clean_content( $content );
        $result        = Embedding::insert( $post_id, $post->post_type, $clean_content, $image_urls );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
        }

        return new \WP_REST_Response( [
            'success'   => true,
            'post_id'   => $post_id,
            'post_type' => $post->post_type,
        ], 200 );
    }

    // ── POST /saved ───────────────────────────────────────────────────────────

    public function handle_save_response( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id       = get_current_user_id();
        $session_uuid  = sanitize_text_field( $request->get_param( 'session_uuid' ) ?? '' );
        $message_index = max( 0, (int) $request->get_param( 'message_index' ) );
        $content       = wp_kses_post( $request->get_param( 'content' ) ?? '' );
        $mode          = sanitize_key( $request->get_param( 'mode' ) ?? 'guideline' );
        $topic_slug    = sanitize_key( $request->get_param( 'topic_slug' ) ?? '' );

        if ( empty( $session_uuid ) || empty( $content ) ) {
            return new \WP_REST_Response( [ 'error' => 'session_uuid and content are required.' ], 400 );
        }

        // Verify session ownership.
        $session = Chat::get_session( $session_uuid, $user_id );
        if ( ! $session ) {
            return new \WP_REST_Response( [ 'error' => 'Session not found.' ], 404 );
        }

        $id = SavedResponses::save( $user_id, $session_uuid, $message_index, $content, $mode, $topic_slug );
        if ( ! $id ) {
            return new \WP_REST_Response( [ 'error' => 'Could not save response.' ], 500 );
        }

        return new \WP_REST_Response( [ 'id' => $id ], 201 );
    }

    // ── GET /saved ────────────────────────────────────────────────────────────

    public function handle_list_saved( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $items   = SavedResponses::list( $user_id );
        return new \WP_REST_Response( $items, 200 );
    }

    // ── DELETE /saved/{id} ────────────────────────────────────────────────────

    public function handle_delete_saved( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $id      = absint( $request->get_param( 'id' ) );
        $deleted = SavedResponses::delete( $id, $user_id );

        if ( ! $deleted ) {
            return new \WP_REST_Response( [ 'error' => 'Not found or already deleted.' ], 404 );
        }

        return new \WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── POST /feedback ────────────────────────────────────────────────────────
    
    public function handle_feedback( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id       = get_current_user_id();
        $session_uuid  = sanitize_text_field( $request->get_param( 'session_uuid' ) ?? '' );
        $message_index = max( 0, (int) $request->get_param( 'message_index' ) );
        $vote          = sanitize_key( $request->get_param( 'vote' ) ?? '' );
        $comment       = sanitize_textarea_field( $request->get_param( 'comment' ) ?? '' );

        if ( empty( $session_uuid ) || empty( $vote ) ) {
            return new \WP_REST_Response( [ 'error' => 'session_uuid and vote are required.' ], 400 );
        }

        // Verify session ownership.
        $session = Chat::get_session( $session_uuid, $user_id );
        if ( ! $session ) {
            return new \WP_REST_Response( [ 'error' => 'Session not found.' ], 404 );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sonoai_feedback';

        $wpdb->insert(
            $table_name,
            [
                'user_id'       => $user_id,
                'session_uuid'  => $session_uuid,
                'message_index' => $message_index,
                'vote'          => $vote,
                'comment'       => $comment,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s' ]
        );

        return new \WP_REST_Response( [ 'success' => true ], 201 );
    }
}
