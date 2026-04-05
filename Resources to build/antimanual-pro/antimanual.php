<?php

/**
 * Plugin Name: Antimanual Pro (Premium)
 * Description: Get exclusive premium features of the Antimanual plugin to take your website to the next level.
 * Requires: antimanual
 * Plugin URI: https://antimanual.spider-themes.net/
 * Author: Spider Themes
 * Author URI: https://spider-themes.net/
 * Version: 3.0.0
 * Update URI: https://api.freemius.com
 * Tested up to: 6.9
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: antimanual
 * Domain Path: /languages
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';
if ( function_exists( 'atml_fs' ) ) {
    atml_fs()->set_basename( true, __FILE__ );
} else {
    function atml_fs() {
        global $atml_fs;
        if ( !isset( $atml_fs ) ) {
            $atml_fs = fs_dynamic_init( array(
                'id'                  => '20195',
                'slug'                => 'antimanual',
                'premium_slug'        => 'antimanual-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_0735a8aad7f2de773c19250ea08ea',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'parallel_activation' => array(
                    'enabled'                  => true,
                    'premium_version_basename' => 'antimanual-pro/antimanual.php',
                ),
                'trial'               => array(
                    'days'               => 14,
                    'is_require_payment' => true,
                ),
                'menu'                => array(
                    'slug'       => 'antimanual',
                    'first-path' => ( is_plugin_active( 'antimanual/antimanual.php' ) ? 'admin.php?page=antimanual' : 'plugins.php' ),
                    'contact'    => false,
                    'support'    => false,
                ),
                'is_live'             => true,
            ) );
        }
        return $atml_fs;
    }

    atml_fs()->add_filter( 'deactivate_on_activation', '__return_false' );
    atml_fs()->add_filter( 'hide_freemius_powered_by', '__return_true' );
    do_action( 'atml_fs_loaded' );
}
/**
 * Plugin activation tasks.
 *
 * @return void
 */
function atml_pro_activate() {
    require_once __DIR__ . '/includes/database/translations-table.php';
    atml_create_translations_table();
}

register_activation_hook( __FILE__, 'atml_pro_activate' );
class Antimanual_Pro {
    private $github;

    private $auto_update_controller;

    private $internal_linking_controller;

    public function __construct() {
        add_action( 'plugins_loaded', [$this, 'handle_plugins_loaded'] );
    }

    public function handle_plugins_loaded() {
        if ( !class_exists( 'Antimanual' ) ) {
            add_action( 'admin_notices', function () {
                $plugin_slug = 'antimanual/antimanual.php';
                $installed = file_exists( WP_PLUGIN_DIR . '/' . $plugin_slug );
                if ( $installed ) {
                    $url = wp_nonce_url( 'plugins.php?action=activate&plugin=' . $plugin_slug, 'activate-plugin_' . $plugin_slug );
                    $label = __( 'Activate Now!', 'antimanual' );
                } else {
                    $url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=antimanual' ), 'install-plugin_antimanual' );
                    $label = __( 'Install Now!', 'antimanual' );
                }
                $message = sprintf(
                    '%1$s <a href="%2$s" class="button button-primary" style="margin-left: 10px;">%3$s</a>',
                    sprintf( __( '%1$s requires the %2$s Free version plugin to be installed and activated. Please get the plugin now!', 'antimanual' ), '<strong>Antimanual Pro</strong>', '<strong>Antimanual</strong>' ),
                    esc_url( $url ),
                    esc_html( $label )
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            } );
            return;
        }
        // Load Translation feature (Pro-only)
        $this->load_translation_feature();
        // Load Auto Update feature (Pro-only)
        $this->load_auto_update_feature();
        // Load Internal Linking feature (SEO Plus only)
        $this->load_internal_linking_feature();
        // Register Pro-only REST API routes
        add_action( 'rest_api_init', [$this, 'register_pro_routes'] );
    }

    /**
     * Load Translation feature files.
     *
     * @return void
     */
    private function load_translation_feature() {
        // Load database functions (translations table)
        require_once __DIR__ . '/includes/database/translations-table.php';
        // Load TranslationService class
        require_once __DIR__ . '/includes/classes/TranslationService.php';
        // Load frontend translation handler
        require_once __DIR__ . '/includes/public/frontend-translation.php';
    }

    /**
     * Load Auto Update feature files.
     *
     * @return void
     */
    private function load_auto_update_feature() {
        // Load AutoUpdate class
        require_once __DIR__ . '/includes/classes/AutoUpdate.php';
        // Load AutoUpdateController class
        require_once __DIR__ . '/includes/classes/AutoUpdateController.php';
        // Initialize the Auto Update singleton
        \Antimanual_Pro\AutoUpdate::instance();
    }

    /**
     * Load Internal Linking feature files.
     *
     * @return void
     */
    private function load_internal_linking_feature() {
        require_once __DIR__ . '/includes/classes/Api/InternalLinkingController.php';
    }

    /**
     * Register Pro-only REST API routes.
     */
    public function register_pro_routes() {
        $this->github = new \Antimanual_Pro\GitHubController();
        $this->github->register_routes( 'antimanual/v1' );
        // Register Auto Update routes
        $this->auto_update_controller = new \Antimanual_Pro\Api\AutoUpdateController();
        $this->auto_update_controller->register_routes( 'antimanual/v1' );
        // Register Internal Linking routes (SEO Plus only)
        $this->internal_linking_controller = new \Antimanual_Pro\Api\InternalLinkingController();
        $this->internal_linking_controller->register_routes( 'antimanual/v1' );
    }

}

new Antimanual_Pro();