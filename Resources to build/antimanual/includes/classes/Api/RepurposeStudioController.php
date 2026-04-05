<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\RepurposeStudio;

/**
 * Repurpose Studio API endpoints.
 */
class RepurposeStudioController {
    /**
     * Register REST routes for Repurpose Studio.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/repurpose-studio', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_assets' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'timeout'             => 120,
        ] );
    }

    /**
     * Generate repurposed assets from a post.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function generate_assets( $request ) {
        $payload = json_decode( $request->get_body(), true );
        $post_id = intval( $payload['post_id'] ?? 0 );
        $tone    = sanitize_text_field( $payload['tone'] ?? 'professional' );

        // Channel and platform parameters.
        $channels            = isset( $payload['channels'] ) && is_array( $payload['channels'] ) ? $payload['channels'] : [ 'email', 'social', 'video', 'docs' ];
        $platforms           = isset( $payload['platforms'] ) && is_array( $payload['platforms'] ) ? $payload['platforms'] : [ 'X', 'LinkedIn', 'Facebook' ];
        $custom_instructions = sanitize_textarea_field( $payload['custom_instructions'] ?? '' );
        $target_audience     = sanitize_text_field( $payload['target_audience'] ?? '' );

        // New parameters.
        $content_length   = sanitize_text_field( $payload['content_length'] ?? 'medium' );
        $output_language  = sanitize_text_field( $payload['output_language'] ?? '' );
        $include_hashtags = ! empty( $payload['include_hashtags'] );

        if ( ! $post_id ) {
            return rest_ensure_response( [
                'success' => false,
                'message' => __( 'Post ID is required.', 'antimanual' ),
            ] );
        }

        $result = RepurposeStudio::generate(
            $post_id,
            $tone,
            $channels,
            $platforms,
            $custom_instructions,
            $target_audience,
            $content_length,
            $output_language,
            $include_hashtags
        );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ] );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $result,
        ] );
    }
}
