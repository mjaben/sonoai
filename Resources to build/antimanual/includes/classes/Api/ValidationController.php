<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\OpenAI;
use Antimanual\Gemini;

/**
 * API key validation endpoints.
 */
class ValidationController {
    /**
     * Register REST routes for model validation.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/validate-models/openai', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'validate_openai_models' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 60,
            'args'                => [
                'api_key' => [
                    'required'          => false,
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_string( $param );
                    },
                ],
            ],
        ] );

        register_rest_route( $namespace, '/validate-models/gemini', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'validate_gemini_models' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 60,
            'args'                => [
                'api_key' => [
                    'required'          => false,
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_string( $param );
                    },
                ],
            ],
        ] );
    }

    /**
     * Validate OpenAI API key and fetch available models.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function validate_openai_models( $request ) {
        $api_key = $request->get_param( 'api_key' );

        if ( empty( $api_key ) ) {
            $api_key = atml_option( 'openai_api_key' );
        }

        if ( empty( $api_key ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'No API key provided. Please enter your OpenAI API key.', 'antimanual' ),
                'data'    => [
                    'valid'       => false,
                    'chat_models' => [],
                ],
            ]);
        }

        $result = OpenAI::list_models( $api_key );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $result->get_error_message(),
                'data'    => [
                    'valid'       => false,
                    'chat_models' => [],
                ],
            ]);
        }

        $supported_chat_models = $GLOBALS['ATML_STORE']['openai_chat_models'] ?? [];
        $supported_chat_ids    = array_column( $supported_chat_models, 'value' );

        $filtered_chat_models = array_values( array_filter(
            $result['chat_models'] ?? [],
            fn( $model ) => in_array( $model['value'], $supported_chat_ids, true )
        ) );

        $filtered_chat_models = array_map( function( $model ) use ( $supported_chat_models ) {
            foreach ( $supported_chat_models as $supported ) {
                if ( $supported['value'] === $model['value'] ) {
                    return $supported;
                }
            }
            return $model;
        }, $filtered_chat_models );

        return rest_ensure_response([
            'success' => true,
            'message' => __( 'API key validated successfully!', 'antimanual' ),
            'data'    => [
                'valid'       => true,
                'chat_models' => $filtered_chat_models,
            ],
        ]);
    }

    /**
     * Validate Gemini API key and fetch available models.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function validate_gemini_models( $request ) {
        $api_key = $request->get_param( 'api_key' );

        if ( empty( $api_key ) ) {
            $api_key = atml_option( 'gemini_api_key' );
        }

        if ( empty( $api_key ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'No API key provided. Please enter your Gemini API key.', 'antimanual' ),
                'data'    => [
                    'valid'       => false,
                    'chat_models' => [],
                ],
            ]);
        }

        $result = Gemini::fetch_available_models( $api_key );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $result->get_error_message(),
                'data'    => [
                    'valid'       => false,
                    'chat_models' => [],
                ],
            ]);
        }

        $supported_chat_models = $GLOBALS['ATML_STORE']['gemini_chat_models'] ?? [];
        $supported_chat_ids    = array_column( $supported_chat_models, 'value' );

        $filtered_chat_models = array_values( array_filter(
            $result['chat_models'] ?? [],
            fn( $model ) => in_array( $model['value'], $supported_chat_ids, true )
        ) );

        $filtered_chat_models = array_map( function( $model ) use ( $supported_chat_models ) {
            foreach ( $supported_chat_models as $supported ) {
                if ( $supported['value'] === $model['value'] ) {
                    return $supported;
                }
            }
            return $model;
        }, $filtered_chat_models );

        return rest_ensure_response([
            'success' => true,
            'message' => __( 'API key validated successfully!', 'antimanual' ),
            'data'    => [
                'valid'       => true,
                'chat_models' => $filtered_chat_models,
            ],
        ]);
    }
}
