<?php
/**
 * SonoAI — [sonoai_chat] shortcode.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shortcode {

    private static ?Shortcode $instance = null;

    public static function instance(): Shortcode {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Whether the chat shortcode is present. */
    private bool $is_chat_active = false;

    /** Whether the admin shortcode is present. */
    private bool $is_admin_active = false;

    private function __construct() {
        add_shortcode( 'sonoai_chat', [ $this, 'render_chat' ] );
        add_shortcode( 'sonoai_admin', [ $this, 'render_admin' ] );
        
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );

        // Detect shortcodes early so we can override the template.
        add_action( 'template_redirect', [ $this, 'maybe_override_template' ], 1 );

        // Add body class for CSS targeting.
        add_filter( 'body_class', [ $this, 'add_body_class' ] );
    }

    /**
     * If the current singular post contains our shortcode, output a minimal
     * HTML shell (no theme header / footer) and exit.
     */
    public function maybe_override_template(): void {
        global $post;

        if ( ! is_singular() || ! $post instanceof \WP_Post ) {
            return;
        }

        $has_chat = has_shortcode( $post->post_content, 'sonoai_chat' );
        $has_admin = has_shortcode( $post->post_content, 'sonoai_admin' );

        if ( ! $has_chat && ! $has_admin ) {
            return;
        }

        if ( $has_chat ) {
            $this->is_chat_active = true;
        }
        if ( $has_admin ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return; // Admin shortcode restricted
            }
            $this->is_admin_active = true;
        }

        // Enqueue assets now so wp_head() includes them.
        add_action( 'wp_enqueue_scripts', function () {
            if ( $this->is_chat_active ) {
                wp_enqueue_style( 'sonoai-chat' );
                wp_enqueue_script( 'sonoai-chat' );
                $this->localize_chat_script();
            }
            if ( $this->is_admin_active ) {
                wp_enqueue_style( 'sonoai-admin-app' );
                wp_enqueue_script( 'sonoai-admin-app' );
                $this->localize_admin_script();
            }
        }, 5 );

        // Override the template: output a bare HTML page and exit.
        add_filter( 'template_include', function () {
            // Capture styles/scripts.
            ob_start();
            $title = $this->is_admin_active ? 'SonoAI Dashboard' : get_the_title();
            ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?> — <?php bloginfo( 'name' ); ?></title>
<?php wp_head(); ?>
<style>
    html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #0d0d0f; }
    body.sonoai-fullscreen { display: flex; flex-direction: column; }
    #sonoai-app, #sonoai-admin-app-root { height: 100vh !important; height: 100dvh !important; border-radius: 0 !important; border: none !important; }
</style>
</head>
<body class="sonoai-fullscreen">
<?php
            // Run the shortcode manually.
            if ( $this->is_admin_active ) {
                echo do_shortcode( '[sonoai_admin]' );
            } else {
                echo do_shortcode( '[sonoai_chat]' );
            }
            wp_footer();
?>
</body>
</html>
<?php
            ob_end_flush();
            exit;
        } );
    }

    /** Append body class on shortcode pages (fallback when template not overridden). */
    public function add_body_class( array $classes ): array {
        if ( $this->is_chat_active || $this->is_admin_active ) {
            $classes[] = 'sonoai-fullscreen';
        }
        return $classes;
    }

    public function register_assets(): void {
        // Chat Assets
        wp_register_style( 'sonoai-chat', SONOAI_URL . 'assets/css/chat.css', [], SONOAI_VERSION );
        wp_register_script( 'sonoai-chat', SONOAI_URL . 'assets/js/chat.js', [], SONOAI_VERSION, true );

        // Admin App Assets
        wp_register_style( 'sonoai-admin-app', SONOAI_URL . 'assets/css/admin-app.css', [], SONOAI_VERSION );
        wp_register_script( 'sonoai-admin-app', SONOAI_URL . 'assets/js/admin-app.js', [], SONOAI_VERSION, true );
    }

    public function render_chat( array $atts = [] ): string {
        wp_enqueue_style( 'sonoai-chat' );
        wp_enqueue_script( 'sonoai-chat' );
        $this->localize_chat_script();

        ob_start();
        include SONOAI_DIR . 'templates/chat.php';
        return ob_get_clean();
    }

    public function render_admin( array $atts = [] ): string {
        if ( ! current_user_can( 'manage_options' ) ) {
            return __( 'You do not have permission to view this page.', 'sonoai' );
        }

        wp_enqueue_style( 'sonoai-admin-app' );
        wp_enqueue_script( 'sonoai-admin-app' );
        $this->localize_admin_script();

        ob_start();
        include SONOAI_DIR . 'templates/admin-app.php';
        return ob_get_clean();
    }

    private function localize_chat_script(): void {
        wp_localize_script( 'sonoai-chat', 'sonoai_vars', [
            'rest_url'       => esc_url( rest_url( 'sonoai/v1/' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'is_logged_in'   => is_user_logged_in(),
            'login_url'      => wp_login_url( get_permalink() ),
            'user'           => $this->get_user_data(),
            'history_limit'  => (int) sonoai_option( 'history_limit', 50 ),
            'i18n'           => [
                'new_chat'     => __( 'New Chat', 'sonoai' ),
                'send'         => __( 'Send', 'sonoai' ),
                'placeholder'  => __( 'Ask about ultrasound, sonography, or upload a scan…', 'sonoai' ),
                'thinking'     => __( 'SonoAI is thinking…', 'sonoai' ),
                'error'        => __( 'Something went wrong. Please try again.', 'sonoai' ),
                'upload_hint'  => __( 'Upload a sonogram image', 'sonoai' ),
                'login_cta'    => __( 'Login to access SonoAI', 'sonoai' ),
                'login_button' => __( 'Log In', 'sonoai' ),
                'login_desc'   => __( 'Sign in to start your AI-powered sonography consultation.', 'sonoai' ),
                'delete'       => __( 'Delete', 'sonoai' ),
                'history'      => __( 'Chat History', 'sonoai' ),
                'today'        => __( 'Today', 'sonoai' ),
                'no_history'   => __( 'No previous chats.', 'sonoai' ),
            ],
        ] );
    }

    private function localize_admin_script(): void {
        wp_localize_script( 'sonoai-admin-app', 'sonoai_admin_vars', [
            'rest_url' => esc_url( rest_url( 'sonoai/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'i18n'     => [
                'saving'  => __( 'Saving...', 'sonoai' ),
                'saved'   => __( 'Saved!', 'sonoai' ),
                'error'   => __( 'Error!', 'sonoai' ),
            ],
            // Preload existing options
            'options'  => get_option( 'sonoai_settings', [] ),
        ] );
    }

    private function get_user_data(): ?array {
        if ( ! is_user_logged_in() ) {
            return null;
        }
        $user = wp_get_current_user();
        return [
            'id'         => $user->ID,
            'first_name' => $user->first_name ?: $user->display_name,
            'avatar'     => get_avatar_url( $user->ID, [ 'size' => 40 ] ),
        ];
    }
}
