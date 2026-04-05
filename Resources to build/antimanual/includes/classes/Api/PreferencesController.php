<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preferences API endpoints.
 */
class PreferencesController {
    /** Option key for SEO monitoring preferences. */
    const MONITORING_OPTION_KEY = 'antimanual_seo_monitoring_prefs';

    /** Default monitoring preferences structure. */
    const MONITORING_DEFAULTS = [
        'frequency'              => 'weekly',
        'monitorScope'           => 'all',
        'scoreThreshold'         => 50,
        'emailAlerts'            => true,
        'autoFixSuggestions'     => true,
        'weeklyDigest'           => true,
        'notificationChannels'   => [ 'email' ],
        'alertEmails'            => [],
        'slackWebhookUrl'        => '',
        'competitorUrls'         => [],
        'contentFreshness'       => true,
        'contentFreshnessPeriod' => '90',
        'crawlHealth'            => true,
        'httpAlertTypes'         => [ '404', '500' ],
        'performanceBudget'      => true,
        'maxPageLoadTime'        => 3,
        'maxPageSize'            => 3,
        'uptimeMonitoring'       => false,
    ];

    /** Option key for module preferences. */
    const MODULE_OPTION_KEY = 'antimanual_module_prefs';

    /** Option key for module uninstall cleanup preferences. */
    const MODULE_UNINSTALL_OPTION_KEY = 'antimanual_module_uninstall_prefs';

    /** Default module preferences structure — matches frontend MODULES keys. */
    const MODULE_DEFAULTS = [
        'chatbot'          => true,
        'search_block'     => true,
        'generate_post'    => true,
        'auto_posting'     => true,
        'auto_update'      => true,
        'bulk_rewrite'     => true,
        'repurpose_studio' => true,
        'faq_generator'    => true,
        'generate_docs'    => true,
        'forum_automation' => true,
        'translation'      => true,
        'seo_agent'        => true,
        'internal_linking'  => true,
        'email_marketing'   => true,
    ];

    /**
     * Register REST routes for preferences.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/preferences/chatbot', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_chatbot_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/preferences/openai', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_openai_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/preferences/gemini', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_gemini_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/preferences/openai/key', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_openai_key' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( $namespace, '/preferences/gemini/key', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_gemini_key' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( $namespace, '/preferences/knowledge-base', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_knowledge_base_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( $namespace, '/preferences/knowledge-base', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_knowledge_base_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/preferences/seo-monitoring', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_monitoring_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( $namespace, '/preferences/seo-monitoring', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_monitoring_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/preferences/modules', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_module_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( $namespace, '/preferences/modules', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_module_preferences' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );
    }

    /**
     * Save chatbot preferences.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function save_chatbot_preferences( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! is_array( $payload ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid chatbot preferences payload.', 'antimanual' ),
            ]);
        }

        $saved = atml_save_chatbot_configs( $payload, true );

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'message' => __( 'Chatbot preferences saved successfully.', 'antimanual' ),
                'config'  => $saved,
            ],
        ]);
    }

    /**
     * Save OpenAI preferences.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function save_openai_preferences( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! is_array( $payload ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid OpenAI preferences payload.', 'antimanual' ),
            ]);
        }

        atml_save_openai_configs( $payload );

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'OpenAI preferences saved successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Save Gemini preferences.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function save_gemini_preferences( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! is_array( $payload ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid Gemini preferences payload.', 'antimanual' ),
            ]);
        }

        atml_save_gemini_configs( $payload );

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'Gemini preferences saved successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Delete the stored OpenAI API key.
     *
     * @return \WP_REST_Response The REST response.
     */
    public function delete_openai_key() {
        atml_option_save( 'openai_api_key', '' );

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'OpenAI API key removed successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Delete the stored Gemini API key.
     *
     * @return \WP_REST_Response The REST response.
     */
    public function delete_gemini_key() {
        atml_option_save( 'gemini_api_key', '' );

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'Gemini API key removed successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Get knowledge base preferences.
     *
     * @return \WP_REST_Response The REST response.
     */
    public function get_knowledge_base_preferences() {
        $defaults = [
            'auto_add_enabled'    => false,
            'auto_add_post_types' => [ 'post' ],
            'github_import_options' => [
                'include_readme'        => true,
                'include_docs'          => true,
                'include_root_markdown' => false,
                'include_code_files'    => false,
                'code_file_extensions'  => [ 'php', 'js', 'ts', 'py', 'rb', 'go', 'java', 'css', 'scss' ],
            ],
        ];

        $saved = get_option( 'antimanual_kb_preferences', [] );
        $prefs = wp_parse_args( $saved, $defaults );

        return rest_ensure_response([
            'success' => true,
            'data'    => $prefs,
        ]);
    }

    /**
     * Save knowledge base preferences.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function save_knowledge_base_preferences( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! is_array( $payload ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid knowledge base preferences payload.', 'antimanual' ),
            ]);
        }

        $defaults = [
            'auto_add_enabled'    => false,
            'auto_add_post_types' => [ 'post' ],
            'github_import_options' => [
                'include_readme'        => true,
                'include_docs'          => true,
                'include_root_markdown' => false,
                'include_code_files'    => false,
                'code_file_extensions'  => [ 'php', 'js', 'ts', 'py', 'rb', 'go', 'java', 'css', 'scss' ],
            ],
        ];

        $current   = get_option( 'antimanual_kb_preferences', [] );
        $sanitized = wp_parse_args( is_array( $current ) ? $current : [], $defaults );

        if ( array_key_exists( 'auto_add_enabled', $payload ) ) {
            $sanitized['auto_add_enabled'] = ! empty( $payload['auto_add_enabled'] );
        }

        if ( array_key_exists( 'auto_add_post_types', $payload ) ) {
            $sanitized['auto_add_post_types'] = isset( $payload['auto_add_post_types'] ) && is_array( $payload['auto_add_post_types'] )
                ? array_values( array_filter( array_map( 'sanitize_key', $payload['auto_add_post_types'] ) ) )
                : [ 'post' ];
        }

        if ( array_key_exists( 'github_import_options', $payload ) && is_array( $payload['github_import_options'] ) ) {
            $github_defaults = $defaults['github_import_options'];
            $github_current  = isset( $sanitized['github_import_options'] ) && is_array( $sanitized['github_import_options'] )
                ? $sanitized['github_import_options']
                : [];
            $github          = wp_parse_args( $github_current, $github_defaults );
            $github_payload  = $payload['github_import_options'];

            if ( array_key_exists( 'include_readme', $github_payload ) ) {
                $github['include_readme'] = ! empty( $github_payload['include_readme'] );
            }

            if ( array_key_exists( 'include_docs', $github_payload ) ) {
                $github['include_docs'] = ! empty( $github_payload['include_docs'] );
            }

            if ( array_key_exists( 'include_root_markdown', $github_payload ) ) {
                $github['include_root_markdown'] = ! empty( $github_payload['include_root_markdown'] );
            }

            if ( array_key_exists( 'include_code_files', $github_payload ) ) {
                $github['include_code_files'] = ! empty( $github_payload['include_code_files'] );
            }

            if ( array_key_exists( 'code_file_extensions', $github_payload ) ) {
                $extensions = [];
                if ( is_array( $github_payload['code_file_extensions'] ) ) {
                    $extensions = $github_payload['code_file_extensions'];
                } elseif ( is_string( $github_payload['code_file_extensions'] ) ) {
                    $extensions = explode( ',', $github_payload['code_file_extensions'] );
                }

                $github['code_file_extensions'] = array_values(
                    array_filter(
                        array_map(
                            static function ( $extension ) {
                                $extension = strtolower( sanitize_key( (string) $extension ) );
                                return ltrim( $extension, '.' );
                            },
                            $extensions
                        )
                    )
                );
            }

            $sanitized['github_import_options'] = $github;
        }

        update_option( 'antimanual_kb_preferences', $sanitized );

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'Knowledge base preferences saved successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Get SEO monitoring preferences.
     *
     * @return \WP_REST_Response The REST response.
     */
    public function get_monitoring_preferences() {
        $saved = get_option( self::MONITORING_OPTION_KEY, [] );
        $prefs = wp_parse_args( is_array( $saved ) ? $saved : [], self::MONITORING_DEFAULTS );

        return rest_ensure_response([
            'success' => true,
            'data'    => $prefs,
        ]);
    }

    /**
     * Save SEO monitoring preferences.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function save_monitoring_preferences( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! is_array( $payload ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid monitoring preferences payload.', 'antimanual' ),
            ]);
        }

        $current   = get_option( self::MONITORING_OPTION_KEY, [] );
        $sanitized = wp_parse_args( is_array( $current ) ? $current : [], self::MONITORING_DEFAULTS );

        // --- Frequency ---
        $allowed_frequencies = [ 'daily', 'weekly', 'monthly' ];
        if ( array_key_exists( 'frequency', $payload ) && in_array( $payload['frequency'], $allowed_frequencies, true ) ) {
            $sanitized['frequency'] = $payload['frequency'];
        }

        // --- Monitor Scope ---
        $allowed_scopes = [ 'all', 'specific' ];
        if ( array_key_exists( 'monitorScope', $payload ) && in_array( $payload['monitorScope'], $allowed_scopes, true ) ) {
            $sanitized['monitorScope'] = $payload['monitorScope'];
        }

        // --- Score Threshold ---
        if ( array_key_exists( 'scoreThreshold', $payload ) ) {
            $sanitized['scoreThreshold'] = max( 0, min( 100, absint( $payload['scoreThreshold'] ) ) );
        }

        // --- Boolean toggles ---
        $bool_keys = [ 'emailAlerts', 'autoFixSuggestions', 'weeklyDigest', 'contentFreshness', 'crawlHealth', 'performanceBudget', 'uptimeMonitoring' ];
        foreach ( $bool_keys as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $sanitized[ $key ] = ! empty( $payload[ $key ] );
            }
        }

        // --- Notification Channels ---
        $allowed_channels = [ 'email', 'browser', 'slack' ];
        if ( array_key_exists( 'notificationChannels', $payload ) && is_array( $payload['notificationChannels'] ) ) {
            $sanitized['notificationChannels'] = array_values(
                array_intersect( $payload['notificationChannels'], $allowed_channels )
            );
        }

        // --- Alert Emails ---
        if ( array_key_exists( 'alertEmails', $payload ) && is_array( $payload['alertEmails'] ) ) {
            $sanitized['alertEmails'] = array_values(
                array_filter(
                    array_map( 'sanitize_email', $payload['alertEmails'] ),
                    'is_email'
                )
            );
        }

        // --- Slack Webhook URL ---
        if ( array_key_exists( 'slackWebhookUrl', $payload ) ) {
            $sanitized['slackWebhookUrl'] = esc_url_raw( (string) $payload['slackWebhookUrl'] );
        }

        // --- Competitor URLs ---
        if ( array_key_exists( 'competitorUrls', $payload ) && is_array( $payload['competitorUrls'] ) ) {
            $sanitized['competitorUrls'] = array_values(
                array_slice(
                    array_filter( array_map( 'esc_url_raw', $payload['competitorUrls'] ) ),
                    0,
                    5
                )
            );
        }

        // --- Content Freshness Period ---
        $allowed_periods = [ '30', '60', '90', '180', '365' ];
        if ( array_key_exists( 'contentFreshnessPeriod', $payload ) && in_array( (string) $payload['contentFreshnessPeriod'], $allowed_periods, true ) ) {
            $sanitized['contentFreshnessPeriod'] = (string) $payload['contentFreshnessPeriod'];
        }

        // --- HTTP Alert Types ---
        $allowed_http = [ '404', '301', '500', 'mixed-content' ];
        if ( array_key_exists( 'httpAlertTypes', $payload ) && is_array( $payload['httpAlertTypes'] ) ) {
            $sanitized['httpAlertTypes'] = array_values(
                array_intersect( $payload['httpAlertTypes'], $allowed_http )
            );
        }

        // --- Max Page Load Time ---
        if ( array_key_exists( 'maxPageLoadTime', $payload ) ) {
            $sanitized['maxPageLoadTime'] = max( 1, min( 10, (float) $payload['maxPageLoadTime'] ) );
        }

        // --- Max Page Size ---
        if ( array_key_exists( 'maxPageSize', $payload ) ) {
            $sanitized['maxPageSize'] = max( 0.5, min( 10, (float) $payload['maxPageSize'] ) );
        }

        update_option( self::MONITORING_OPTION_KEY, $sanitized );

        return rest_ensure_response([
            'success' => true,
            'data'    => __( 'Monitoring preferences saved successfully.', 'antimanual' ),
        ]);
    }

    /**
     * Get module-enabled preferences.
     *
     * Returns a map of module slug => bool indicating whether each
     * feature module is globally enabled.
     *
     * @return \WP_REST_Response The REST response.
     */
    public function get_module_preferences() {
        $saved         = get_option( self::MODULE_OPTION_KEY, [] );
        $prefs         = wp_parse_args( is_array( $saved ) ? $saved : [], self::MODULE_DEFAULTS );
        $cleanup_prefs = \Antimanual\Uninstall::get_module_uninstall_preferences();

        return rest_ensure_response([
            'success'                => true,
            'data'                   => $prefs,
            'module_uninstall_prefs' => $cleanup_prefs,
        ]);
    }

    /**
     * Save module-enabled preferences.
     *
     * Only boolean flags from the MODULE_DEFAULTS allowlist are accepted.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function save_module_preferences( $request ) {
        $payload = json_decode( $request->get_body(), true );

        if ( ! is_array( $payload ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid module preferences payload.', 'antimanual' ),
            ]);
        }

        $current          = get_option( self::MODULE_OPTION_KEY, [] );
        $sanitized        = wp_parse_args( is_array( $current ) ? $current : [], self::MODULE_DEFAULTS );
        $cleanup_current  = get_option( self::MODULE_UNINSTALL_OPTION_KEY, [] );
        $cleanup_defaults = \Antimanual\Uninstall::get_module_uninstall_defaults();
        $cleanup_prefs    = wp_parse_args( is_array( $cleanup_current ) ? $cleanup_current : [], $cleanup_defaults );

        // Only accept boolean values for known module keys.
        foreach ( array_keys( self::MODULE_DEFAULTS ) as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $sanitized[ $key ] = ! empty( $payload[ $key ] );
            }
        }

        if ( ! empty( $payload['cleanup'] ) && is_array( $payload['cleanup'] ) ) {
            foreach ( array_keys( $cleanup_defaults ) as $key ) {
                if ( array_key_exists( $key, $payload['cleanup'] ) ) {
                    $cleanup_prefs[ $key ] = ! empty( $payload['cleanup'][ $key ] );
                }
            }
        }

        if ( ! empty( $payload['module_uninstall_prefs'] ) && is_array( $payload['module_uninstall_prefs'] ) ) {
            foreach ( array_keys( $cleanup_defaults ) as $key ) {
                if ( array_key_exists( $key, $payload['module_uninstall_prefs'] ) ) {
                    $cleanup_prefs[ $key ] = ! empty( $payload['module_uninstall_prefs'][ $key ] );
                }
            }
        }

        update_option( self::MODULE_OPTION_KEY, $sanitized );
        update_option( self::MODULE_UNINSTALL_OPTION_KEY, $cleanup_prefs );

        return rest_ensure_response([
            'success'                => true,
            'data'                   => __( 'Module preferences saved successfully.', 'antimanual' ),
            'module_uninstall_prefs' => $cleanup_prefs,
        ]);
    }
}
