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
        $user_id     = get_current_user_id();
        $message     = sanitize_textarea_field( $request->get_param( 'message' ) ?? '' );
        $session_uuid = sanitize_text_field( $request->get_param( 'session_uuid' ) ?? '' );

        if ( empty( $message ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Message cannot be empty.', 'sonoai' ) ], 400 );
        }

        // ── Handle image upload ──────────────────────────────────────────────
        $image_b64  = '';
        $image_url  = '';
        $files      = $request->get_file_params();

        if ( ! empty( $files['image']['tmp_name'] ) ) {
            $saved = ImageHandler::save( $files['image'] );
            if ( is_wp_error( $saved ) ) {
                return new \WP_REST_Response( [ 'error' => $saved->get_error_message() ], 422 );
            }
            $image_url = $saved['url'];
            $image_b64 = ImageHandler::encode_base64( $saved['path'] ) ?: '';
        }

        // ── Session management ───────────────────────────────────────────────
        $is_new_session = false;
        if ( empty( $session_uuid ) ) {
            $session_uuid   = Chat::create_session( $user_id, $message );
            $is_new_session = true;
        } else {
            // Verify ownership.
            $session = Chat::get_session( $session_uuid, $user_id );
            if ( null === $session ) {
                return new \WP_REST_Response( [ 'error' => __( 'Session not found.', 'sonoai' ) ], 404 );
            }
        }

        // ── Store user message ───────────────────────────────────────────────
        Chat::add_message( $session_uuid, 'user', $message, $image_url );

        // ── Build prompt + history ───────────────────────────────────────────
        $context_data  = RAG::get_context_data( $message );
        $system_prompt = $context_data['prompt'];
        $context_imgs  = $context_data['images'];
        $history       = Chat::get_messages_for_ai( $session_uuid );

        // Build messages array for the AI provider.
        $ai_messages   = array_merge(
            [ [ 'role' => 'system', 'content' => $system_prompt ] ],
            $history
        );

        // ── AI call ──────────────────────────────────────────────────────────
        $stream = isset( $_POST['stream'] ) && $_POST['stream'] === '1';

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
                'context_images' => $context_imgs,
            ] ) . "\n\n";
            @ob_flush(); flush();

            $reply = AIProvider::stream_reply( $ai_messages, $image_b64, function( $chunk ) {
                echo "event: chunk\ndata: " . wp_json_encode( [ 'chunk' => $chunk ] ) . "\n\n";
                @ob_flush(); flush();
            } );

            if ( is_wp_error( $reply ) ) {
                echo "event: error\ndata: " . wp_json_encode( [ 'error' => $reply->get_error_message() ] ) . "\n\n";
            } else {
                Chat::add_message( $session_uuid, 'assistant', $reply );
                
                if ( str_contains( $reply, 'I cannot answer this question because I have not yet been trained' ) || str_contains( $reply, 'I cannot answer questions or discuss topics outside of this medical domain' ) ) {
                    Chat::log_unanswered_query( $user_id, $message, $reply );
                }

                echo "event: done\ndata: {}\n\n";
            }
            exit;
        }

        $reply = AIProvider::get_reply( $ai_messages, $image_b64 );

        if ( is_wp_error( $reply ) ) {
            return new \WP_REST_Response( [ 'error' => $reply->get_error_message() ], 502 );
        }

        // ── Store AI reply ───────────────────────────────────────────────────
        Chat::add_message( $session_uuid, 'assistant', $reply );

        if ( str_contains( $reply, 'I cannot answer this question because I have not yet been trained' ) || str_contains( $reply, 'I cannot answer questions or discuss topics outside of this medical domain' ) ) {
            Chat::log_unanswered_query( $user_id, $message, $reply );
        }

        return new \WP_REST_Response( [
            'reply'          => $reply,
            'session_uuid'   => $session_uuid,
            'is_new_session' => $is_new_session,
            'context_images' => $context_imgs,
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

        $content = sonoai_clean_content( $post->post_content );
        $result  = Embedding::insert( $post_id, $post->post_type, $content );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
        }

        return new \WP_REST_Response( [
            'success'   => true,
            'post_id'   => $post_id,
            'post_type' => $post->post_type,
        ], 200 );
    }
}
