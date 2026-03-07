<?php
/**
 * Plugin Name: SonoAI
 * Description: AI-powered chat assistant for the ultrasound and sonography niche. Performs RAG over EazyDocs Cases and Forummax Topics. Supports image uploads for sonogram analysis.
 * Plugin URI:  #
 * Author:      MJA
 * Version:     1.0.8
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: sonoai
 * Domain Path: /languages
 * License:     GPL2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'SONOAI_VERSION',  '1.0.0' );
define( 'SONOAI_DIR',      plugin_dir_path( __FILE__ ) );
define( 'SONOAI_URL',      plugin_dir_url( __FILE__ ) );
define( 'SONOAI_BASENAME', plugin_basename( __FILE__ ) );

// ─── Autoload ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'SonoAI\\' ) !== 0 ) {
        return;
    }
    $relative = str_replace( [ 'SonoAI\\', '\\' ], [ '', DIRECTORY_SEPARATOR ], $class );
    $file      = SONOAI_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ─── Bootstrap ───────────────────────────────────────────────────────────────
final class SonoAI {

    private static ?SonoAI $instance = null;

    public static function instance(): SonoAI {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_includes();
        $this->boot();

        register_activation_hook( __FILE__, [ 'SonoAI\\Activator', 'run' ] );
        register_deactivation_hook( __FILE__, [ 'SonoAI\\Activator', 'deactivate' ] );
    }

    private function load_includes(): void {
        require_once SONOAI_DIR . 'includes/helpers.php';
        require_once SONOAI_DIR . 'includes/Activator.php';
        require_once SONOAI_DIR . 'includes/AIProvider.php';
        require_once SONOAI_DIR . 'includes/Embedding.php';
        require_once SONOAI_DIR . 'includes/RAG.php';
        require_once SONOAI_DIR . 'includes/ImageHandler.php';
        require_once SONOAI_DIR . 'includes/Chat.php';
        require_once SONOAI_DIR . 'includes/api/RestAPI.php';
        require_once SONOAI_DIR . 'includes/hooks/ContentHooks.php';
        require_once SONOAI_DIR . 'includes/admin/Admin.php';
        require_once SONOAI_DIR . 'includes/Shortcode.php';
    }

    private function boot(): void {
        // Maybe run DB migrations on plugin update.
        add_action( 'admin_init', [ $this, 'maybe_run_migrations' ] );

        // Boot singletons.
        add_action( 'plugins_loaded', function () {
            SonoAI\RestAPI::instance();
            SonoAI\ContentHooks::instance();
            SonoAI\Admin::instance();
            SonoAI\Shortcode::instance();
        } );
    }

    public function maybe_run_migrations(): void {
        $stored = get_option( 'sonoai_db_version', '0' );
        if ( version_compare( $stored, SONOAI_VERSION, '<' ) ) {
            SonoAI\Activator::create_tables();
            update_option( 'sonoai_db_version', SONOAI_VERSION );
        }
    }
}

SonoAI::instance();
