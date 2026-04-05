<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\FAQGenerator;

/**
 * FAQ API endpoints.
 */
class FAQController {
    /**
     * Register REST routes for FAQ.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/generate-faq', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_generate_faq' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/faq-sources', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_get_faq_sources' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 60,
        ] );

        register_rest_route( $namespace, '/faqs-to-blocks', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_faqs_to_blocks' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 60,
        ] );

        register_rest_route( $namespace, '/faqs-to-default-blocks', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_faqs_to_default_blocks' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 60,
        ] );

        register_rest_route( $namespace, '/aab-plugin-status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_check_aab_plugin_status' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 30,
        ] );

        register_rest_route( $namespace, '/create-faq-post', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_create_faq_post' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'timeout'             => 60,
        ] );

        register_rest_route( $namespace, '/aab-plugin/install', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_install_aab_plugin' ],
            'permission_callback' => fn() => current_user_can( 'install_plugins' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/aab-plugin/activate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_activate_aab_plugin' ],
            'permission_callback' => fn() => current_user_can( 'activate_plugins' ),
            'timeout'             => 60,
        ] );
    }

    /**
     * Handle FAQ generation request.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_generate_faq( $request ) {
        $payload = json_decode( $request->get_body(), true );

        $options = [
            'count'      => isset( $payload['count'] ) ? intval( $payload['count'] ) : 5,
            'topic'      => isset( $payload['topic'] ) ? sanitize_text_field( $payload['topic'] ) : '',
            'tone'       => isset( $payload['tone'] ) ? sanitize_text_field( $payload['tone'] ) : 'professional',
            'use_kb'     => isset( $payload['use_kb'] ) ? (bool) $payload['use_kb'] : true,
            'source_ids' => isset( $payload['source_ids'] ) && is_array( $payload['source_ids'] ) ? array_map( 'sanitize_text_field', $payload['source_ids'] ) : [],
        ];

        $faqs = FAQGenerator::generate_faqs( $options );

        if ( is_wp_error( $faqs ) ) {
            return rest_ensure_response( [
                'success' => false,
                'message' => $faqs->get_error_message(),
            ] );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'faqs' => $faqs,
            ],
        ] );
    }

    /**
     * Get knowledge sources for FAQ generation.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_get_faq_sources( $request ) {
        $sources = FAQGenerator::get_knowledge_sources();

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'sources' => $sources,
            ],
        ] );
    }

    /**
     * Convert FAQ items to Gutenberg blocks.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_faqs_to_blocks( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! isset( $payload['faqs'] ) || ! is_array( $payload['faqs'] ) ) {
            return rest_ensure_response( [
                'success' => false,
                'message' => __( 'FAQs array is required.', 'antimanual' ),
            ] );
        }

        $include_schema = isset( $payload['include_schema'] ) ? (bool) $payload['include_schema'] : true;
        $blocks         = FAQGenerator::to_gutenberg_blocks( $payload['faqs'] );
        $schema_block   = $include_schema ? FAQGenerator::generate_schema_block( $payload['faqs'] ) : null;

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'blocks'       => $blocks,
                'schema_block' => $schema_block,
                'schema'       => $include_schema ? FAQGenerator::generate_faq_schema( $payload['faqs'] ) : null,
            ],
        ] );
    }

    /**
     * Handle converting FAQs to default WordPress accordion blocks (core/details).
     *
     * @since 2.3.0
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_faqs_to_default_blocks( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! isset( $payload['faqs'] ) || ! is_array( $payload['faqs'] ) ) {
            return rest_ensure_response( [
                'success' => false,
                'message' => __( 'FAQs array is required.', 'antimanual' ),
            ] );
        }

        $include_schema = isset( $payload['include_schema'] ) ? (bool) $payload['include_schema'] : true;
        $blocks         = FAQGenerator::to_default_accordion_blocks( $payload['faqs'] );
        $schema_block   = $include_schema ? FAQGenerator::generate_schema_block( $payload['faqs'] ) : null;

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'blocks'       => $blocks,
                'schema_block' => $schema_block,
                'schema'       => $include_schema ? FAQGenerator::generate_faq_schema( $payload['faqs'] ) : null,
            ],
        ] );
    }

    /**
     * Handle checking Advanced Accordion Block plugin status.
     *
     * @since 2.3.0
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_check_aab_plugin_status( $request ) {
        $status = FAQGenerator::check_aab_plugin_status();

        return rest_ensure_response( [
            'success' => true,
            'data'    => $status,
        ] );
    }

    /**
     * Handle creating a new post/page with FAQ blocks.
     *
     * @since 2.3.0
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_create_faq_post( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! isset( $payload['blocks'] ) || empty( $payload['blocks'] ) ) {
            return rest_ensure_response( [
                'success' => false,
                'message' => __( 'Blocks content is required.', 'antimanual' ),
            ] );
        }

        $blocks      = $payload['blocks'];
        $schema      = isset( $payload['schema_block'] ) ? $payload['schema_block'] : '';
        $post_type   = isset( $payload['post_type'] ) ? sanitize_key( $payload['post_type'] ) : 'post';
        $post_status = isset( $payload['post_status'] ) ? sanitize_key( $payload['post_status'] ) : 'draft';

        if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
            $post_type = 'post';
        }

        if ( ! in_array( $post_status, [ 'draft', 'publish' ], true ) ) {
            $post_status = 'draft';
        }

        $post_id = FAQGenerator::create_faq_post( $blocks, $schema, $post_type, $post_status );

        if ( is_wp_error( $post_id ) ) {
            return rest_ensure_response( [
                'success' => false,
                'message' => $post_id->get_error_message(),
            ] );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'post_id'  => $post_id,
                'edit_url' => get_edit_post_link( $post_id, 'raw' ),
                'view_url' => get_permalink( $post_id ),
            ],
        ] );
    }

    /**
     * Handle installing the Advanced Accordion Block plugin.
     *
     * @since 2.3.0
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_install_aab_plugin( $request ) {
        $result = FAQGenerator::install_aab_plugin();

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

    /**
     * Handle activating the Advanced Accordion Block plugin.
     *
     * @since 2.3.0
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_activate_aab_plugin( $request ) {
        $result = FAQGenerator::activate_aab_plugin();

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
