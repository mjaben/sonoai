<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\AIResponseCleaner;

/**
 * Content rewrite API endpoints.
 */
class ContentController {

    /**
     * Register REST routes for content rewriting.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/rewrite-content', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rewrite_content' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );
    }

    /**
     * Rewrite content.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response|\WP_Error The REST response.
     */
    public function rewrite_content( $request ) {
        if ( atml_is_bulk_rewrite_limit_exceeded() ) {
            return new \WP_Error(
                'monthly_limit_exceeded',
                __( 'You have reached your monthly limit of 100 bulk rewrite actions. Upgrade to Pro for unlimited actions.', 'antimanual' )
            );
        }

        $payload = json_decode( $request->get_body(), true );
        $payload = is_array( $payload ) ? $payload : [];

        $post_id = intval( $payload['postId'] ?? 0 );
        $prompt  = trim( strval( $payload['prompt'] ?? '' ) );

        if ( ! $post_id || empty( $prompt ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Post ID and prompt are required.', 'antimanual' ),
            ]);
        }

        $post = get_post( $post_id );

        if ( ! $post ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Post not found.', 'antimanual' ),
            ]);
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Unauthorized', 'antimanual' ),
            ]);
        }

        $content = $post->post_content;

        $instructions = "You are an expert WordPress content editor and AI writing assistant.\n"
            . "Your task is to rewrite or edit a given WordPress post’s content according to the user's prompt.\n\n"
            . "Follow these rules carefully:\n"
            . "1. Keep all valid WordPress block syntax intact (e.g., <!-- wp:paragraph --> ... <!-- /wp:paragraph -->, shortcodes, embeds, HTML tags).\n"
            . "2. Apply the user’s instructions exactly — rewrite, enhance, shorten, expand, or change the tone as requested.\n"
            . "3. Do not remove, reorder, or merge block structures unless the user explicitly says so.\n"
            . "4. Preserve factual meaning unless the user asks for creative rewriting.\n"
            . "5. Maintain a natural, human writing style that is clear, engaging, and grammatically correct.\n"
            . "6. Do not include explanations, comments, or any extra text — return only the rewritten WordPress post content.\n"
            . "7. The output must be valid WordPress post content that can be saved directly.\n\n"
            . "Input: USER PROMPT + ORIGINAL POST CONTENT\n"
            . "Output: REWRITTEN POST CONTENT";

        $user_msg = "USER PROMPT:\n"
            . "```md\n"
            . $prompt
            . "\n```\n\n"
            . "ORIGINAL POST CONTENT:\n"
            . "```html\n"
            . $content
            . "\n```";

        $messages = [
            [
                'role'    => 'system',
                'content' => $instructions,
            ],
            [
                'role'    => 'user',
                'content' => $user_msg,
            ],
        ];

        $reply = AIProvider::get_reply( $messages );

        if ( ! is_string( $reply ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $reply['error'] ?? __( 'Failed to rewrite content.', 'antimanual' ),
            ]);
        }

        $reply = AIResponseCleaner::clean_gutenberg_content( $reply );

        wp_update_post([
            'ID'           => $post_id,
            'post_content' => $reply,
        ]);

        if ( ! atml_is_pro() ) {
            \Antimanual\UsageTracker::increment( 'bulk_rewrite' );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'Content rewritten successfully.', 'antimanual' ),
        ]);
    }
}
