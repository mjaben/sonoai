<?php
namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SecurityHelper Class
 * Centralizes security checks, nonces, and sanitization for the SonoAI plugin.
 */
class SecurityHelper {

    /**
     * Check if the current user has administrative capabilities.
     * Default capability: manage_options.
     * 
     * @param string $capability The capability to check.
     * @return bool True if the user has the capability.
     */
    public static function check_admin_caps( string $capability = 'manage_options' ): bool {
        return current_user_can( $capability );
    }

    /**
     * Verify a nonce and terminate if invalid.
     * 
     * @param string $action   The nonce action.
     * @param string $query_arg The query argument name.
     */
    public static function verify_nonce( string $action = 'sonoai_action', string $query_arg = '_wpnonce' ): void {
        if ( ! isset( $_REQUEST[ $query_arg ] ) || ! wp_verify_nonce( $_REQUEST[ $query_arg ], $action ) ) {
            wp_die( esc_html__( 'Security check failed. Please refresh the page.', 'sonoai' ) );
        }
    }

    /**
     * Check AJAX referer and terminate if invalid.
     */
    public static function verify_ajax_nonce(): void {
        check_ajax_referer( 'sonoai_nonce', 'security' );
        
        if ( ! self::check_admin_caps() ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'sonoai' ) ] );
        }
    }

    /**
     * Securely get a request parameter.
     * 
     * @param string $key     The parameter key.
     * @param string $default Default value.
     * @param string $type    The type of sanitization to apply.
     * @return mixed Sanitized value.
     */
    public static function get_param( string $key, $default = '', string $type = 'text' ) {
        if ( ! isset( $_REQUEST[ $key ] ) ) {
            return $default;
        }

        $value = wp_unslash( $_REQUEST[ $key ] );

        switch ( $type ) {
            case 'int':
                return intval( $value );
            case 'bool':
                return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
            case 'url':
                return esc_url_raw( $value );
            case 'email':
                return sanitize_email( $value );
            case 'textarea':
                return sanitize_textarea_field( $value );
            case 'html':
                return wp_kses_post( $value );
            case 'raw':
                return $value;
            default:
                return sanitize_text_field( $value );
        }
    }
}
