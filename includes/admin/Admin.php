<?php
/**
 * SonoAI — Admin settings page.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {

    private static ?Admin $instance = null;

    public static function instance(): Admin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function add_menu(): void {
        add_menu_page(
            __( 'SonoAI', 'sonoai' ),
            __( 'SonoAI', 'sonoai' ),
            'manage_options',
            'sonoai-settings',
            [ $this, 'render_settings_page' ],
            'dashicons-robot',
            58
        );

        // Sub-menus — add any future sub-menus below this line.
        // API Configuration is registered by the ApiConfig class itself.
    }

    public function register_settings(): void {
        register_setting( 'sonoai_settings_group', 'sonoai_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( array $input ): array {
        // Guard: only process when this page's form was submitted.
        // Both Admin and ApiConfig register sanitize callbacks for the same option;
        // WordPress chains them. If the _page sentinel isn't ours, pass through unchanged.
        if ( ( $input['_page'] ?? '' ) !== 'main_settings' ) {
            return $input;
        }

        $existing = (array) get_option( 'sonoai_settings', [] );
        $clean    = $existing;

        $clean['system_prompt']       = sanitize_textarea_field( $input['system_prompt'] ?? '' );
        $clean['history_limit']       = max( 1, min( 500, absint( $input['history_limit'] ?? 50 ) ) );
        $clean['rag_results']         = max( 1, min( 20, absint( $input['rag_results'] ?? 5 ) ) );
        $clean['rag_use_docs']        = ! empty( $input['rag_use_docs'] ) ? '1' : '0';
        $clean['rag_use_topics']      = ! empty( $input['rag_use_topics'] ) ? '1' : '0';
        $clean['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] ) ? '1' : '0';

        return $clean;
    }

    public function enqueue_admin_assets( string $hook ): void {
        // Only enqueue on main SonoAI settings page (not sub-menu pages).
        if ( 'toplevel_page_sonoai-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'sonoai-admin', SONOAI_URL . 'assets/css/admin.css', [], SONOAI_VERSION );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $opts    = get_option( 'sonoai_settings', [] );
        $counts  = Embedding::get_counts();
        $default_prompt = "You are SonoAI, an expert AI assistant specialising in ultrasound and sonography. You help sonographers, radiologists, and medical students understand ultrasound images and clinical cases. When analysing sonogram images, describe what you observe, relevant anatomy, and educational notes. Always remind users that your responses are for educational purposes only and not a substitute for professional clinical judgment. Use clear, professional medical terminology while remaining accessible.";
        ?>
        <div class="wrap sonoai-admin-wrap">
            <div class="sonoai-admin-header">
                <h1>🔬 SonoAI <span>Settings</span></h1>
                <p class="sonoai-admin-subtitle">AI-powered chat for the ultrasound & sonography niche.</p>
            </div>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved successfully.', 'sonoai' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'sonoai_settings_group' ); ?>
                <input type="hidden" name="sonoai_settings[_page]" value="main_settings">

                <div class="sonoai-card-grid">

                    <!-- API config moved to SonoAI → API Configuration sub-menu -->

                    <!-- System Prompt -->
                    <div class="sonoai-card">
                        <h2><?php esc_html_e( 'System Prompt', 'sonoai' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'The domain-specific context injected before every conversation. Tailor this to your sonography use case.', 'sonoai' ); ?></p>
                        <textarea name="sonoai_settings[system_prompt]" rows="8" class="large-text"><?php echo esc_textarea( $opts['system_prompt'] ?? $default_prompt ); ?></textarea>
                    </div>

                    <!-- Knowledge Base -->
                    <div class="sonoai-card">
                        <h2><?php esc_html_e( 'Knowledge Base (RAG)', 'sonoai' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Use EazyDocs Cases', 'sonoai' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="sonoai_settings[rag_use_docs]" value="1" <?php checked( $opts['rag_use_docs'] ?? '1', '1' ); ?>>
                                        <?php esc_html_e( 'Include published docs (docs CPT) in context', 'sonoai' ); ?>
                                    </label>
                                    <p class="description"><?php echo esc_html( sprintf( __( 'Indexed: %d case(s)', 'sonoai' ), $counts['docs'] ?? 0 ) ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Use Forummax Topics', 'sonoai' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="sonoai_settings[rag_use_topics]" value="1" <?php checked( $opts['rag_use_topics'] ?? '1', '1' ); ?>>
                                        <?php esc_html_e( 'Include forum topics (topic CPT) in context', 'sonoai' ); ?>
                                    </label>
                                    <p class="description"><?php echo esc_html( sprintf( __( 'Indexed: %d topic(s)', 'sonoai' ), $counts['topic'] ?? 0 ) ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'RAG Context Results', 'sonoai' ); ?></th>
                                <td>
                                    <input type="number" name="sonoai_settings[rag_results]" value="<?php echo esc_attr( $opts['rag_results'] ?? 5 ); ?>" min="1" max="20" class="small-text">
                                    <p class="description"><?php esc_html_e( 'Number of knowledge chunks to inject per query (1–20).', 'sonoai' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Chat History -->
                    <div class="sonoai-card">
                        <h2><?php esc_html_e( 'Chat History', 'sonoai' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'History Limit', 'sonoai' ); ?></th>
                                <td>
                                    <input type="number" name="sonoai_settings[history_limit]" value="<?php echo esc_attr( $opts['history_limit'] ?? 50 ); ?>" min="1" max="500" class="small-text">
                                    <p class="description"><?php esc_html_e( 'Maximum number of chat sessions stored per user. Oldest sessions are auto-deleted when the limit is exceeded.', 'sonoai' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Danger Zone -->
                    <div class="sonoai-card">
                        <h2><?php esc_html_e( 'Danger Zone', 'sonoai' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Delete Data on Uninstall', 'sonoai' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="sonoai_settings[delete_on_uninstall]" value="1" <?php checked( $opts['delete_on_uninstall'] ?? '0', '1' ); ?>>
                                        <?php esc_html_e( 'Delete all plugin data (settings, chat history, and embedded vectors) when deleting the plugin.', 'sonoai' ); ?>
                                    </label>
                                    <p class="description" style="color:#d63638;"><?php esc_html_e( 'Warning: If this is checked, deleting the plugin from the WordPress admin will permanently erase all SonoAI database tables and settings.', 'sonoai' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                </div><!-- .sonoai-card-grid -->

                <?php submit_button( __( 'Save Settings', 'sonoai' ) ); ?>
            </form>
        </div>
        <?php
    }
}
