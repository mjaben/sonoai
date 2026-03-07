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

        // ── Admin Dashboard Endpoints ──────────────────────────────────────────

        register_rest_route( $ns, '/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_save_settings' ],
            'permission_callback' => [ $this, 'require_admin' ],
        ] );

        register_rest_route( $ns, '/kb/wp', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_embed_post' ], // reuse post logic
            'permission_callback' => [ $this, 'require_admin' ],
        ] );

        register_rest_route( $ns, '/kb/url', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_kb_url' ],
            'permission_callback' => [ $this, 'require_admin' ],
        ] );

        register_rest_route( $ns, '/kb/text', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_kb_text' ],
            'permission_callback' => [ $this, 'require_admin' ],
        ] );

        register_rest_route( $ns, '/kb/pdf', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_kb_pdf' ],
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
        $system_prompt = RAG::build_system_prompt( $message );
        $history       = Chat::get_messages_for_ai( $session_uuid );

        // Build messages array for the AI provider.
        $ai_messages   = array_merge(
            [ [ 'role' => 'system', 'content' => $system_prompt ] ],
            $history
        );

        // ── AI call ──────────────────────────────────────────────────────────
        $reply = AIProvider::get_reply( $ai_messages, $image_b64 );

        if ( is_wp_error( $reply ) ) {
            return new \WP_REST_Response( [ 'error' => $reply->get_error_message() ], 502 );
        }

        // ── Store AI reply ───────────────────────────────────────────────────
        Chat::add_message( $session_uuid, 'assistant', $reply );

        return new \WP_REST_Response( [
            'reply'          => $reply,
            'session_uuid'   => $session_uuid,
            'is_new_session' => $is_new_session,
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

    // ── POST /embed-post & /kb/wp ─────────────────────────────────────────────

    public function handle_embed_post( \WP_REST_Request $request ): \WP_REST_Response {
        $params  = $request->get_json_params() ?: $request->get_params();
        $post_id = absint( $params['post_id'] ?? 0 );

        if ( $post_id <= 0 ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid post_id.' ], 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found or not published.' ], 404 );
        }

        $content = \SonoAI\sonoai_clean_content( $post->post_content );
        $result  = Embedding::insert( $post_id, $post->post_type, $content );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
        }

        return new \WP_REST_Response( [
            'success'   => true,
            'message'   => 'Post successfully vectorized!',
            'post_id'   => $post_id,
            'post_type' => $post->post_type,
        ], 200 );
    }

    // ── POST /settings ────────────────────────────────────────────────────────

    public function handle_save_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $params   = $request->get_json_params();
        $existing = get_option( 'sonoai_settings', [] );
        $merged   = array_merge( $existing, $params );

        // Sanitize via Admin class
        $clean = \SonoAI\Admin::instance()->sanitize_settings( $merged );
        update_option( 'sonoai_settings', $clean );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    // ── POST /kb/url ──────────────────────────────────────────────────────────

    public function handle_kb_url( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $url    = esc_url_raw( $params['url'] ?? '' );

        if ( empty( $url ) ) {
            return new \WP_REST_Response( [ 'message' => 'Invalid URL provided.' ], 400 );
        }

        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) {
            return new \WP_REST_Response( [ 'message' => 'Could not fetch URL: ' . $response->get_error_message() ], 400 );
        }

        $body = wp_remote_retrieve_body( $response );
        $content = \SonoAI\sonoai_clean_content( $body );

        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'message' => 'No readable text found at URL.' ], 400 );
        }

        // Insert using dummy ID 0 or custom hash, but let's use a hashed ID or 0 for external.
        // For SonoAI, the schema (`post_id`, `post_type`, `chunk_index`, `embedding`)
        // Let's use `url` as post_type and a hash as post_id (crc32)
        $fake_id = crc32( $url );
        $result  = Embedding::insert( $fake_id, 'url', $content );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 500 );
        }

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'URL successfully crawled and embedded.' ], 200 );
    }

    // ── POST /kb/text ─────────────────────────────────────────────────────────

    public function handle_kb_text( \WP_REST_Request $request ): \WP_REST_Response {
        $params  = $request->get_json_params();
        $title   = sanitize_text_field( $params['title'] ?? 'Custom Text' );
        $content = sanitize_textarea_field( $params['content'] ?? '' );

        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'message' => 'Content cannot be empty.' ], 400 );
        }

        // We use `custom` as post_type and crc32 of title
        $fake_id = crc32( $title . time() );
        $result  = Embedding::insert( $fake_id, 'custom_text', $title . " " . $content );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 500 );
        }

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Custom text embedded.' ], 200 );
    }

    // ── POST /kb/pdf ──────────────────────────────────────────────────────────

    public function handle_kb_pdf( \WP_REST_Request $request ): \WP_REST_Response {
        $files = $request->get_file_params();
        
        if ( empty( $files['pdf']['tmp_name'] ) ) {
            return new \WP_REST_Response( [ 'message' => 'No PDF file uploaded.' ], 400 );
        }

        $file = $files['pdf'];
        if ( $file['type'] !== 'application/pdf' ) {
            return new \WP_REST_Response( [ 'message' => 'Invalid file type. Must be PDF.' ], 400 );
        }

        // TODO: We need a PDF parser library to extract the text. In this scope, since we lack composer
        // in the immediate prompt, we do a basic dummy text or regex to extract if uncompressed, 
        // but realistically we should use a utility or advise the user.
        // Let's try a very basic uncompressed text extraction as fallback.
        
        $content = file_get_contents( $file['tmp_name'] );
        // Basic preg_replace for text fragments in uncompressed PDFs
        preg_match_all( '/\((.*?)\)/', $content, $matches );
        $extracted = '';
        if ( ! empty( $matches[1] ) ) {
            $extracted = implode( " ", $matches[1] );
            $extracted = preg_replace( '/[^A-Za-z0-9 .,_-]/', '', $extracted ); // clean weird chars
        }

        if ( empty( trim( $extracted ) ) ) {
            // Provide a dummy message if we couldn't parse it without a real lib
            return new \WP_REST_Response( [ 'message' => 'Could not extract text from this PDF (requires pro PDF parser or it is compressed).' ], 400 );
        }

        $fake_id = crc32( $file['name'] . time() );
        $result  = Embedding::insert( $fake_id, 'pdf', $file['name'] . " " . $extracted );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 500 );
        }

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'PDF successfully processed and embedded.' ], 200 );
    }
}
