<?php

namespace Antimanual_Pro;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub integration API endpoints (Pro).
 */
class GitHubController {
    /**
     * Register REST routes for GitHub integration.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/github/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/github/connect', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'connect' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/github/disconnect', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'disconnect' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/github/repos', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_repos' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
            'args'                => [
                'page' => [
                    'required'          => false,
                    'validate_callback' => fn( $param ) => is_numeric( $param ),
                ],
                'per_page' => [
                    'required'          => false,
                    'validate_callback' => fn( $param ) => is_numeric( $param ),
                ],
                'search' => [
                    'required'          => false,
                    'validate_callback' => fn( $param ) => is_string( $param ),
                ],
            ],
        ] );
    }

    /**
     * Get GitHub connection status.
     *
     * @return \WP_REST_Response
     */
    public function get_status() {
        return rest_ensure_response([
            'success' => true,
            'data'    => GitHubIntegration::get_status(),
        ]);
    }

    /**
     * Connect GitHub using a personal access token.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function connect( $request ) {
        $payload = json_decode( $request->get_body(), true );
        $token   = trim( $payload['token'] ?? '' );

        $user = GitHubIntegration::connect( $token );

        if ( is_wp_error( $user ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $user->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'connected' => true,
                'user'      => $user,
            ],
        ]);
    }

    /**
     * Disconnect GitHub.
     *
     * @return \WP_REST_Response
     */
    public function disconnect() {
        GitHubIntegration::disconnect();

        return rest_ensure_response([
            'success' => true,
            'data'    => [ 'connected' => false ],
        ]);
    }

    /**
     * List GitHub repositories.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function list_repos( $request ) {
        $page     = intval( $request->get_param( 'page' ) ?? 1 );
        $per_page = intval( $request->get_param( 'per_page' ) ?? 50 );
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );

        $repos = GitHubIntegration::list_repos( $page, $per_page, $search );

        if ( is_wp_error( $repos ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $repos->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $repos,
        ]);
    }

    /**
     * Add GitHub repository to knowledge base.
     *
     * @param \WP_REST_Request $request
     * @return array
     */
    public static function add_to_kb_github( $request ) {
        $payload   = json_decode( $request->get_body(), true );
        $full_name = sanitize_text_field( $payload['full_name'] ?? '' );
        $options   = is_array( $payload['options'] ?? null ) ? $payload['options'] : [];

        $chunks = GitHubIntegration::add_repo_to_kb( $full_name, $options );

        if ( is_wp_error( $chunks ) ) {
            return [
                'success' => false,
                'message' => $chunks->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'data'    => $chunks,
        ];
    }
}
