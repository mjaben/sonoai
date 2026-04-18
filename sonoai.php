<?php
/**
 * Plugin Name: Sono AI
 * Description: Educational AI-powered chat assistant for the ultrasound and sonography niche. Performs RAG over WordPress Knowledge Base.
 * Author:      Sonohive Ltd
 * Version:     1.1.0 Beta
 * Text Domain: sonoai
 * License:     GPL2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Diagnostics & Logging ──────────────────────────────────────────────────
/**
 * Custom logger for SonoAI.
 */
function sonoai_log_error( $message ) {
    error_log( "SonoAI Error: {$message}" );
}

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'SONOAI_VERSION',  '1.1.1' );
define( 'SONOAI_DIR',      plugin_dir_path( __FILE__ ) );
define( 'SONOAI_URL',      plugin_dir_url( __FILE__ ) );
define( 'SONOAI_BASENAME', plugin_basename( __FILE__ ) );

// ─── Compatibility Polyfills ────────────────────────────────────────────────
if ( file_exists( SONOAI_DIR . 'includes/compat.php' ) ) {
    require_once SONOAI_DIR . 'includes/compat.php';
}

// ─── Requirement Checks ─────────────────────────────────────────────────────
function sonoai_meets_requirements() {
    $missing = [];
    $extensions = [ 'curl', 'mbstring', 'json', 'dom', 'xml', 'iconv' ];
    
    foreach ( $extensions as $ext ) {
        if ( ! extension_loaded( $ext ) ) {
            $missing[] = $ext;
        }
    }
    
    // Check PHP version
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        sonoai_log_error( 'PHP version too old: ' . PHP_VERSION );
        return false;
    }

    if ( ! empty( $missing ) ) {
        sonoai_log_error( 'Missing PHP extensions: ' . implode( ', ', $missing ) );
        return false;
    }

    return true;
}

if ( ! sonoai_meets_requirements() ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__( 'SonoAI is disabled because your server is missing required PHP extensions (Check sonoai-errors.log).', 'sonoai' ) . '</p></div>';
    } );
    return;
}

// ─── Vendor Autoload ─────────────────────────────────────────────────────────
define( 'SONOAI_VENDOR_MISSING', ! file_exists( SONOAI_DIR . 'vendor/autoload.php' ) );

if ( ! SONOAI_VENDOR_MISSING ) {
    try {
        require_once SONOAI_DIR . 'vendor/autoload.php';
    } catch ( \Throwable $t ) {
        sonoai_log_error( 'Vendor Autoload Failure: ' . $t->getMessage() );
    }
} else {
    // Note: Don't hard-crash here, just log. The autoload check below will handle missing classes.
    sonoai_log_error( 'CRITICAL: vendor/autoload.php not found. Please ensure you have run "composer install" or uploaded the "vendor" folder to your production server.' );
}

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

    public static function instance(): ?SonoAI {
        if ( null === self::$instance ) {
            try {
                self::$instance = new self();
            } catch ( \Throwable $t ) {
                sonoai_log_error( 'Fatal Instance Error: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine() );
                return null;
            }
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! sonoai_meets_requirements() ) return;

        try {
            $this->load_includes();
            $this->boot();

            register_activation_hook( __FILE__, [ 'SonoAI\\Activator', 'run' ] );
            register_deactivation_hook( __FILE__, [ 'SonoAI\\Activator', 'deactivate' ] );
        } catch ( \Throwable $t ) {
            sonoai_log_error( 'Bootstrap Error: ' . $t->getMessage() );
        }
    }

    private function load_includes(): void {
        $files = [
            'includes/helpers.php',
            'includes/Activator.php',
            'includes/AIProvider.php',
            'includes/Embedding.php',
            'includes/RAG.php',
            'includes/Chat.php',
            'includes/Topics.php',
            'includes/RedisManager.php',
            'includes/SavedResponses.php',
            'includes/api/RestAPI.php',
            'includes/hooks/ContentHooks.php',
            'includes/admin/Admin.php',
            'includes/admin/ApiConfig.php',
            'includes/admin/KnowledgeBase.php',
            'includes/admin/TopicsAdmin.php',
            'includes/admin/KnowledgeBaseAjax.php',
            'includes/admin/FeedbackAnalytics.php',
            'includes/admin/QueryLogs.php',
            'includes/Shortcode.php',
        ];

        foreach ( $files as $file ) {
            $path = SONOAI_DIR . $file;
            if ( file_exists( $path ) ) {
                require_once $path;
            } else {
                sonoai_log_error( "Required file missing: {$file}" );
            }
        }
    }

    private function boot(): void {
        add_action( 'admin_init', [ $this, 'maybe_run_migrations' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_userswp_notice' ] );

        add_action( 'plugins_loaded', function () {
            try {
                // Initialize controllers
                if ( class_exists( 'SonoAI\RestAPI' ) ) SonoAI\RestAPI::instance();
                if ( class_exists( 'SonoAI\ContentHooks' ) ) SonoAI\ContentHooks::instance();
                if ( class_exists( 'SonoAI\Admin' ) ) SonoAI\Admin::instance();
                if ( class_exists( 'SonoAI\ApiConfig' ) ) SonoAI\ApiConfig::instance();
                if ( class_exists( 'SonoAI\KnowledgeBase' ) ) SonoAI\KnowledgeBase::instance();
                if ( class_exists( 'SonoAI\TopicsAdmin' ) ) SonoAI\TopicsAdmin::instance();
                if ( class_exists( 'SonoAI\KnowledgeBaseAjax' ) ) SonoAI\KnowledgeBaseAjax::instance();
                if ( class_exists( 'SonoAI\FeedbackAnalytics' ) ) SonoAI\FeedbackAnalytics::instance();
                if ( class_exists( 'SonoAI\QueryLogs' ) ) SonoAI\QueryLogs::instance();
                if ( class_exists( 'SonoAI\Shortcode' ) ) SonoAI\Shortcode::instance();
            } catch ( \Throwable $t ) {
                sonoai_log_error( 'Runtime Class Load Error: ' . $t->getMessage() );
            }
        } );
    }

    public function maybe_run_migrations(): void {
        $stored = get_option( 'sonoai_db_version', '0' );
        if ( version_compare( $stored, SONOAI_VERSION, '<' ) ) {
            try {
                if ( class_exists( 'SonoAI\Activator' ) ) {
                    SonoAI\Activator::create_tables();
                    update_option( 'sonoai_db_version', SONOAI_VERSION );
                }
            } catch ( \Throwable $t ) {
                sonoai_log_error( 'Migration Error: ' . $t->getMessage() );
            }
        }
    }

    public function maybe_show_userswp_notice(): void {
        if ( ! class_exists( 'UsersWP' ) && current_user_can( 'activate_plugins' ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'SonoAI Alert:', 'sonoai' ) . '</strong> ' . esc_html__( 'The UsersWP plugin is not active. SonoAI requires UsersWP to handle user login and registration workflows on the chat interface. Your users will not be able to authenticate without it.', 'sonoai' ) . '</p></div>';
        }
    }
}

// Start the plugin.
SonoAI::instance();
