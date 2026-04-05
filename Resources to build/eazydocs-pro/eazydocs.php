<?php

/**
 * Plugin Name: EazyDocs Pro (Premium)
 * Description: Power-up the EazyDocs plugin with advanced controls and features
 * Plugin URI: https://spider-themes.net/
 * Author: spider-themes
 * Author URI: http://spider-themes.net/
 * Version: 2.10.1
 * Update URI: https://api.freemius.com
 * License: GPL2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eazydocs-pro
 * Domain Path: /languages
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( function_exists( 'eaz_fs' ) ) {
    eaz_fs()->set_basename( true, __FILE__ );
} else {
    // DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE `function_exists` CALL ABOVE TO PROPERLY WORK.
    if ( !function_exists( 'eaz_fs' ) ) {
        // Create a helper function for easy SDK access.
        function eaz_fs() {
            global $eaz_fs;
            if ( !isset( $eaz_fs ) ) {
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/vendor/freemius/wordpress-sdk/start.php';
                $eaz_fs = fs_dynamic_init( array(
                    'id'                => '10290',
                    'slug'              => 'eazydocs',
                    'premium_slug'      => 'eazydocs-pro',
                    'type'              => 'plugin',
                    'public_key'        => 'pk_8474e4208f0893a7b28c04faf5045',
                    'wp_org_gatekeeper' => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                    'is_premium'        => true,
                    'has_addons'        => false,
                    'has_paid_plans'    => true,
                    'trial'             => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'menu'              => array(
                        'first-path' => 'plugins.php',
                        'slug'       => 'eazydocs',
                        'support'    => false,
                    ),
                    'is_live'           => true,
                ) );
            }
            return $eaz_fs;
        }

        // Init Freemius.
        eaz_fs()->add_filter( 'deactivate_on_activation', '__return_false' );
        eaz_fs()->add_filter( 'hide_freemius_powered_by', '__return_true' );
        // Signal that SDK was initiated.
        do_action( 'eaz_fs_loaded' );
    }
    // ... Your plugin's main file logic ...
}
require_once __DIR__ . '/vendor/autoload.php';
/**
 * Class EZD_EazyDocsPro
 */
class EZD_EazyDocsPro {
    /**
     * EazyDocs Version
     *
     * Holds the version of the plugin.
     *
     * @var string The plugin version.
     */
    const version = '2.10.1';

    /**
     * @var mixed|null
     */
    private $theme_dir_path;

    /**
     * Constructor.
     *
     * Initialize the EazyDocs plugin
     *
     * @access public
     */
    public function __construct() {
        $this->define_constants();
        $this->core_includes();
        register_activation_hook( __FILE__, [$this, 'activate'] );
        add_action( 'plugins_loaded', [$this, 'elementor_files'] );
        add_action( 'plugins_loaded', [$this, 'init_plugin'] );
        add_action( 'wp_footer', function () {
            do_action( 'eazydocs_assistant' );
        } );
        add_filter( 'template_include', [$this, 'load_iframe_assistant_template'] );
    }

    /**
     * Include Files
     *
     * Load core files required to run the plugin.
     *
     * @access public
     */
    public function core_includes() {
        require_once __DIR__ . '/includes/functions.php';
        if ( eaz_fs()->is_plan( 'promax' ) ) {
            require_once __DIR__ . '/includes/Admin/analytics/Ajax_actions.php';
            require_once __DIR__ . '/includes/Admin/analytics/Ajax_tab_loader.php';
            require_once __DIR__ . '/includes/Admin/analytics/email-reporting/mailer.php';
            require_once __DIR__ . '/includes/Admin/analytics/email-reporting/sample-report.php';
        }
        require_once __DIR__ . '/includes/notices.php';
        require_once __DIR__ . '/includes/User_Feedback.php';
        // Features
        require_once __DIR__ . '/includes/features/config.php';
        // Shortcodes
        require_once __DIR__ . '/shortcodes/one-page-shortcode.php';
        require_once __DIR__ . '/shortcodes/embed_post.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        // Block
        require_once __DIR__ . '/includes/Blocks/config.php';
        if ( is_plugin_active( 'eazydocs/eazydocs.php' ) ) {
            // Assistant
            new eazyDocsPro\Frontend\Search();
            new eazyDocsPro\Frontend\Assistant();
        }
        // Hide iframe-assistant from nav menus
        add_filter(
            'wp_get_nav_menu_items',
            function ( $items, $menu, $args ) {
                foreach ( $items as $key => $item ) {
                    if ( $item->object == 'page' && $item->object_id ) {
                        $is_iframe = get_post_meta( $item->object_id, '_iframe_assistant_page', true );
                        if ( $is_iframe === '1' ) {
                            unset($items[$key]);
                        }
                    }
                }
                return $items;
            },
            10,
            3
        );
    }

    function elementor_files() {
        if ( eaz_fs()->is_plan( 'promax' ) && did_action( 'elementor/loaded' ) ) {
            require_once __DIR__ . '/includes/template-library/template-library.php';
        }
    }

    /**
     * Define constants
     */
    public function define_constants() {
        define( 'EAZYDOCSPRO_VERSION', self::version );
        define( 'EAZYDOCSPRO_FILE', __FILE__ );
        define( 'EAZYDOCSPRO_PATH', __DIR__ );
        define( 'EAZYDOCSPRO_URL', plugins_url( '', EAZYDOCSPRO_FILE ) );
        define( 'EAZYDOCSPRO_ASSETS', EAZYDOCSPRO_URL . '/assets' );
        define( 'EAZYDOCSPRO_CSS', EAZYDOCSPRO_URL . '/assets/css' );
        define( 'EAZYDOCSPRO_FRONT_CSS', EAZYDOCSPRO_URL . '/assets/css/frontend' );
        define( 'EAZYDOCSPRO_IMG', EAZYDOCSPRO_URL . '/assets/images' );
        define( 'EAZYDOCSPRO_VEND', EAZYDOCSPRO_URL . '/assets/vendors' );
    }

    /**
     * Initializes a singleton instances
     * @return void
     */
    public static function init() {
        static $instance = false;
        if ( !$instance ) {
            $instance = new self();
        }
        return $instance;
    }

    /**
     * Initializes the plugin
     * @return void
     */
    public function init_plugin() {
        $this->theme_dir_path = apply_filters( 'eazydocspro_theme_dir_path', 'eazydocs-pro/' );
        if ( is_admin() ) {
            new eazyDocsPro\Admin\Admin_Actions();
            new eazyDocsPro\Duplicator\EazyDocs_Duplicate();
            new eazyDocsPro\Admin\Assets();
            new eazyDocsPro\Admin\Feedback_Update();
            new eazyDocsPro\Admin\Feedback_Delete();
            new eazyDocsPro\Admin\Doc_Visibility();
            new eazyDocsPro\Admin\Doc_Section_Visibility();
            new eazyDocsPro\Admin\Doc_Sidebar();
            if ( eaz_fs()->is_plan( 'promax' ) && did_action( 'elementor/loaded' ) ) {
                new eazyDocsPro\Template_library\Template_Library();
            }
        } else {
            new eazyDocsPro\Frontend\Frontend_Actions();
            new eazyDocsPro\Frontend\Assets();
            new eazyDocsPro\Frontend\Frontend();
        }
        if ( class_exists( 'EazyDocs' ) && ezd_is_premium() ) {
            new eazyDocsPro\Elementor\Config();
        }
        // Initialize profile fields functionality
        new eazyDocsPro\User\Profile_Fields();
        // Initialize the ajax handlers for frontend
        new eazyDocsPro\Frontend\Ajax();
    }

    /**
     * Do stuff upon plugin activation
     */
    public function activate() {
        //Insert the installation time into the database
        $installed = get_option( 'eazyDocsPro_installed' );
        if ( !$installed ) {
            update_option( 'eazyDocsPro_installed', time() );
        }
        update_option( 'EazyDocsPro_version', 'EAZYDOCSPRO_VERSION' );
        // Insert the eazydocs login page into the database if not exists
        if ( function_exists( 'ezd_get_page_by_title' ) && !ezd_get_page_by_title( 'Documentation Login' ) ) {
            // Create page object
            $docs_page = array(
                'post_title'   => wp_strip_all_tags( 'Documentation Login' ),
                'post_content' => '[ezd_login_form]',
                'post_status'  => 'publish',
                'post_author'  => 1,
                'post_type'    => 'page',
            );
            wp_insert_post( $docs_page );
        }
        // Create iframe-assistant page if not exists
        if ( !get_page_by_path( 'iframe-assistant' ) ) {
            $iframe_page_id = wp_insert_post( array(
                'post_title'   => 'iframe-assistant',
                'post_name'    => 'iframe-assistant',
                'post_content' => '',
                'post_status'  => 'publish',
                'post_author'  => 1,
                'post_type'    => 'page',
            ) );
            if ( $iframe_page_id ) {
                update_post_meta( $iframe_page_id, '_iframe_assistant_page', '1' );
            }
        }
        // when updating plugin send notice to admin that we did merge the docs views meta to eazydocs own table
        if ( get_option( 'eazydocspro_version' ) ) {
            update_option( 'eazydocspro_version', 'EAZYDOCSPRO_VERSION' );
            update_option( 'eazydocspro_update_notice', true );
        }
        // Check if there is a scheduled event for the hook 'ezd_fetch_remote_html_notice_content'
        $timestamp = wp_next_scheduled( 'ezd_fetch_remote_html_notice_content' );
        if ( $timestamp ) {
            // If the event is scheduled, unschedule it to prevent it from running again
            wp_unschedule_event( $timestamp, 'ezd_fetch_remote_html_notice_content' );
        }
        // Flush rewrite rules on activation
        flush_rewrite_rules();
    }

    /**
     * eazydocspro_update_notice
     */
    public function eazydocspro_update_notice() {
        if ( get_option( 'eazydocspro_update_notice' ) ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php 
            esc_html_e( 'EazyDocs Pro has been updated. We have merged the docs views meta to eazydocs own table.', 'eazydocs-pro' );
            ?></p>
            </div>
            <?php 
            delete_option( 'eazydocspro_update_notice' );
        }
    }

    public function load_iframe_assistant_template( $template ) {
        if ( is_page() ) {
            global $post;
            if ( $post && get_post_meta( $post->ID, '_iframe_assistant_page', true ) === '1' ) {
                $custom_template = __DIR__ . '/templates/embed_assistant.php';
                if ( file_exists( $custom_template ) ) {
                    return $custom_template;
                }
            }
        }
        return $template;
    }

}

/**
 * @return EazyDocs|false
 */
if ( !function_exists( 'eazydocspro' ) ) {
    /**
     * Load eazydocs
     *
     * Main instance of eazydocs
     *
     */
    function eazydocspro() {
        return EZD_EazyDocsPro::init();
    }

    /**
     * Kick of the plugin
     */
    eazydocspro();
}
// Block editing of iframe-assistant page in admin
add_action( 'admin_init', function () {
    if ( is_admin() && isset( $_GET['post'] ) ) {
        $post_id = intval( $_GET['post'] );
        if ( get_post_meta( $post_id, '_iframe_assistant_page', true ) === '1' ) {
            // Prevent access to edit screen
            if ( strpos( $_SERVER['REQUEST_URI'], 'post.php' ) !== false ) {
                wp_die( esc_html__( 'You are not allowed to edit this page.', 'eazydocs-pro' ), esc_html__( 'Not Allowed', 'eazydocs-pro' ), [
                    'response' => 403,
                ] );
            }
        }
    }
} );
// Hide iframe-assistant from admin Pages list
add_action( 'pre_get_posts', function ( $query ) {
    if ( is_admin() && $query->is_main_query() && $query->get( 'post_type' ) === 'page' ) {
        $meta_query = ( $query->get( 'meta_query' ) ?: array() );
        $meta_query[] = array(
            'key'     => '_iframe_assistant_page',
            'compare' => 'NOT EXISTS',
        );
        $query->set( 'meta_query', $meta_query );
    }
} );