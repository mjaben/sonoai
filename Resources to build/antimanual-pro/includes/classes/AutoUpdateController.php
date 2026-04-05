<?php

namespace Antimanual_Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Antimanual_Pro\AutoUpdate;

/**
 * Auto-update API endpoints (Pro Feature).
 */
class AutoUpdateController {
    /**
     * Register REST routes for auto-update.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/auto-update', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_auto_updates' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/auto-update', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'new_auto_update' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/auto-update', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_auto_update' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/auto-update', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_auto_update' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/auto-update/trigger', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'trigger_auto_update' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/auto-update/preview', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'preview_auto_update' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );
    }

    /**
     * List auto-updates.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function list_auto_updates( $request ) {
        $populate   = explode( ',', $request->get_param( 'populate' ) ?? '' );
        $autoUpdates = AutoUpdate::list( $populate );

        if ( is_wp_error( $autoUpdates ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $autoUpdates->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $autoUpdates,
        ]);
    }

    /**
     * Create new auto-update schedule.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function new_auto_update( $request ) {
        $payload = json_decode( $request->get_body(), true );
        $payload = is_array( $payload ) ? $payload : [];

        $autoUpdate = AutoUpdate::create( $payload );

        if ( is_wp_error( $autoUpdate ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $autoUpdate->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $autoUpdate,
        ]);
    }

    /**
     * Update auto-update schedule.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function update_auto_update( $request ) {
        $auto_update_id = intval( $request->get_param( 'auto_update_id' ) );
        $payload         = json_decode( $request->get_body(), true );
        $payload         = is_array( $payload ) ? $payload : [];

        $autoUpdate = AutoUpdate::update( $auto_update_id, $payload );

        if ( is_wp_error( $autoUpdate ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $autoUpdate->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $autoUpdate,
        ]);
    }

    /**
     * Delete auto-update schedule.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function delete_auto_update( $request ) {
        $auto_update_id = intval( $request->get_param( 'auto_update_id' ) );

        $result = AutoUpdate::delete( $auto_update_id );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $result->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => true,
        ]);
    }

    /**
     * Manually trigger an auto-update schedule.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function trigger_auto_update( $request ) {
        $auto_update_id = intval( $request->get_param( 'auto_update_id' ) );

        if ( empty( $auto_update_id ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Auto Update ID is required.', 'antimanual' ),
            ]);
        }

        $result = AutoUpdate::run_update( $auto_update_id );
        $auto_update = AutoUpdate::get( $auto_update_id );
        $now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

        if ( ! is_wp_error( $auto_update ) ) {
            $auto_update['last_run_at'] = $now->format( 'Y-m-d H:i:s' );

            if ( is_wp_error( $result ) ) {
                $auto_update['last_run_stats'] = [
                    'run_date' => $now->format( 'Y-m-d H:i:00' ),
                    'error'    => $result->get_error_message(),
                ];
            } else {
                $auto_update['last_run_stats'] = array_merge(
                    [ 'run_date' => $now->format( 'Y-m-d H:i:00' ) ],
                    $result
                );
            }

            update_post_meta( $auto_update_id, AutoUpdate::$meta_data, $auto_update );
        }

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $result->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * Preview eligible posts for auto-update based on payload.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function preview_auto_update( $request ) {
        $payload = json_decode( $request->get_body(), true );
        $payload = is_array( $payload ) ? $payload : [];

        $limit = intval( $payload['limit'] ?? 10 );
        $limit = max( 1, min( 50, $limit ) );

        $preview = AutoUpdate::preview_posts( $payload, $limit );

        if ( is_wp_error( $preview ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $preview->get_error_message(),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $preview,
        ]);
    }
}
