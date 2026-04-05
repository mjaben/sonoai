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

    /** Whether the shortcode is present on the current request. */
    private bool $is_active = false;

    private function __construct() {
        add_shortcode( 'sonoai_chat', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );

        // Detect shortcode early so we can override the template.
        add_action( 'template_redirect', [ $this, 'maybe_override_template' ], 1 );

        // Add body class for CSS targeting.
        add_filter( 'body_class', [ $this, 'add_body_class' ] );

        // Clean URLs: support /UUID
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
    }

    /** Add rewrite rule to handle /UUID after the page slug. */
    public function add_rewrite_rules(): void {
        // Matches page-slug/UUID-v4
        add_rewrite_rule(
            '(.?.+?)/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/?$',
            'index.php?pagename=$matches[1]&sonoai_uuid=$matches[2]',
            'top'
        );
    }

    /** Register our custom query variable. */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'sonoai_uuid';
        return $vars;
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

        if ( ! has_shortcode( $post->post_content, 'sonoai_chat' ) ) {
            return;
        }

        $this->is_active = true;

        // Detect session UUID from either GET param or our new query var.
        $session_uuid = get_query_var( 'sonoai_uuid' ) ?: ( isset( $_GET['uuid'] ) ? sanitize_text_field( $_GET['uuid'] ) : '' );

        // Enqueue assets now so wp_head() includes them.
        add_action( 'wp_enqueue_scripts', function () use ( $session_uuid ) {
            wp_enqueue_style( 'sonoai-chat' );
            wp_enqueue_script( 'sonoai-chat' );
            wp_localize_script( 'sonoai-chat', 'sonoai_vars', [
                'rest_url'      => esc_url( rest_url( 'sonoai/v1/' ) ),
                'nonce'         => wp_create_nonce( 'wp_rest' ),
                'is_logged_in'  => is_user_logged_in(),
                'login_url'     => wp_login_url( get_permalink() ),
                'user'          => $this->get_user_data(),
                'history_limit' => (int) sonoai_option( 'history_limit', 50 ),
                'session_uuid'  => $session_uuid,
                'base_url'      => get_permalink(),
                'i18n'          => [
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
        }, 5 );

        // Override the template: output a bare HTML page and exit.
        add_filter( 'template_include', function () {
            // Capture styles/scripts.
            ob_start();
            ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( get_the_title() ); ?> — <?php bloginfo( 'name' ); ?></title>
<?php wp_head(); ?>
<style>
    html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #0d0d0f; }
    body.sonoai-fullscreen { display: flex; flex-direction: column; }
    #sonoai-app { height: 100vh !important; height: 100dvh !important; border-radius: 0 !important; border: none !important; }
</style>
</head>
<body class="sonoai-fullscreen">
<?php
            // Run the shortcode manually.
            echo do_shortcode( '[sonoai_chat]' );
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
        if ( $this->is_active ) {
            $classes[] = 'sonoai-fullscreen';
        }
        return $classes;
    }

    public function register_assets(): void {
        wp_register_style(
            'sonoai-chat',
            SONOAI_URL . 'assets/css/chat.css',
            [],
            SONOAI_VERSION
        );

        wp_register_script(
            'sonoai-chat',
            SONOAI_URL . 'assets/js/chat.js',
            [],
            SONOAI_VERSION,
            true   // Load in footer.
        );
    }

    public function render( array $atts = [] ): string {
        wp_enqueue_style( 'sonoai-chat' );
        wp_enqueue_script( 'sonoai-chat' );

        // Always provide data even for logged-out state.
        $session_uuid = get_query_var( 'sonoai_uuid' ) ?: ( isset( $_GET['uuid'] ) ? sanitize_text_field( $_GET['uuid'] ) : '' );
        
        wp_localize_script( 'sonoai-chat', 'sonoai_vars', [
            'rest_url'       => esc_url( rest_url( 'sonoai/v1/' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'is_logged_in'   => is_user_logged_in(),
            'login_url'      => wp_login_url( get_permalink() ),
            'user'           => $this->get_user_data(),
            'history_limit'  => (int) sonoai_option( 'history_limit', 50 ),
            'session_uuid'   => $session_uuid,
            'base_url'       => get_permalink(),
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

        ob_start();
        include SONOAI_DIR . 'templates/chat.php';
        return ob_get_clean();
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
