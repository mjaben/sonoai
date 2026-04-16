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
            SONOAI_URL . 'assets/images/sonoai-brand-icon.svg',
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
        $clean['rag_min_similarity']  = max( 0, min( 100, (int) ( ( $input['rag_min_similarity'] ?? 0.70 ) * 100 ) ) ) / 100;
        $clean['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] ) ? '1' : '0';

        return $clean;
    }

    public function enqueue_admin_assets( string $hook ): void {
        // Enqueue on all SonoAI admin pages.
        if ( false === strpos( $hook, 'sonoai' ) ) {
            return;
        }

        wp_enqueue_style( 'sonoai-kb', SONOAI_URL . 'assets/css/kb.css', [], SONOAI_VERSION );
        wp_enqueue_script( 'sonoai-kb', SONOAI_URL . 'assets/js/kb.js', [ 'jquery' ], SONOAI_VERSION, true );

        wp_localize_script( 'sonoai-kb', 'sonoaiKB', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonces'  => [
                'reindexAll' => wp_create_nonce( 'sonoai_reindex_all' ),
            ],
        ] );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $opts    = get_option( 'sonoai_settings', [] );
        $counts  = Embedding::get_counts();
        $default_prompt = "You are SonoAI, an expert Medical AI assistant specialising in ultrasound and sonography. You are a specialized medical interface with a DIRECT PIPELINE to clinical training data.\n\n" .
            "Strict Rules:\n\n" .
            "1. OUT-OF-DOMAIN: If the message is unrelated to ultrasound, sonography, or radiology, reply EXACTLY: 'I am SonoAI, an assistant specializing in ultrasound and sonography. I cannot answer queries outside of this domain.'\n\n" .
            "2. CONVERSATIONAL: Respond naturally but concisely to greetings or capability inquiries.\n\n" .
            "3. DOMAIN-SPECIFIC (KNOWLEDGE BASE): Answer ONLY using the information in the <KNOWLEDGE_BASE>. You are strictly forbidden from using internal memory for medical facts. If clinical images are mentioned in the context (e.g., [IMG_01]), you are AUTHORIZED and REQUIRED to render them using the technical tag: :::image|ID|Label::: \n\n" .
            "4. MISSING KNOWLEDGE: \n" .
            "- NEW TOPICS: If the query is about a topic completely absent from the <KNOWLEDGE_BASE>, reply EXACTLY: 'I cannot answer this question because I have not yet been trained on this specific topic.'\n" .
            "- MORE INFO: If the user asks for 'more' or 'further' details on a topic you have already answered, but no additional info exists in the <KNOWLEDGE_BASE>, summarize the key findings you have already shared and ask the user if they would like to focus on any specific finding or anatomical structure mentioned in those results.\n\n" .
            "5. MEDIA COORDINATION: \n" .
            "- IMAGE AVAILABILITY: Check the [METADATA] block below. \n" .
            "- IF IMAGES EXIST: Append the query: 'Would you like to view the associated sonogram images or clinical presentation?' \n" .
            "- IF NO IMAGES EXIST: Do NOT mention images. \n" .
            "- CONFIRMED REQUEST: When a user requests to 'show' or 'view' images, you are AUTHORIZED to output the :::image|ID|Label::: tags found in the underlying context data.\n\n" .
            "6. SOURCES: You MUST end every single response with the :::sources block. Do NOT include source names or citations in the middle of your response. Use only the :::sources format at the very end.";
        ?>
        <div class="kb-wrap" id="sonoai-settings-page">
            
            <!-- Hero Header -->
            <div class="kb-header">
                <div class="kb-header-left">
                    <div class="kb-header-icon">🔬</div>
                    <div>
                        <h1 class="kb-title"><?php esc_html_e( 'SonoAI Settings', 'sonoai' ); ?></h1>
                        <p class="kb-subtitle"><?php esc_html_e( 'AI-powered chat for the ultrasound & sonography niche.', 'sonoai' ); ?></p>
                    </div>
                </div>
                <div class="kb-header-right">
                    <button type="button" id="kb-theme-toggle" class="kb-theme-btn" title="Toggle dark / light mode">
                        <span class="kb-icon-dark">🌙</span>
                        <span class="kb-icon-light">☀️</span>
                    </button>
                </div>
            </div>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible" style="margin-top:20px;"><p><?php esc_html_e( 'Settings saved successfully.', 'sonoai' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php" style="margin-top: 30px;">
                <?php settings_fields( 'sonoai_settings_group' ); ?>
                <input type="hidden" name="sonoai_settings[_page]" value="main_settings">

                <div class="kb-card-grid">

                    <!-- System Prompt -->
                    <div class="kb-card kb-card-full">
                        <div class="kb-tab-header">
                            <h2><?php esc_html_e( 'System Prompt', 'sonoai' ); ?></h2>
                        </div>
                        <p class="kb-desc"><?php esc_html_e( 'The domain-specific context injected before every conversation.', 'sonoai' ); ?></p>
                        <textarea name="sonoai_settings[system_prompt]" rows="10" class="large-text" style="font-family: monospace; font-size: 13px !important; margin-top: 15px;"><?php echo esc_textarea( sonoai_option( 'system_prompt', $default_prompt ) ); ?></textarea>
                    </div>

                    <!-- Knowledge Base -->
                    <div class="kb-card">
                        <div class="kb-tab-header">
                            <h2><?php esc_html_e( 'Knowledge Base (RAG)', 'sonoai' ); ?></h2>
                        </div>
                        
                        <div class="kb-form-grid">
                            <label class="kb-label"><?php esc_html_e( 'RAG Context Results', 'sonoai' ); ?></label>
                            <div>
                                <input type="number" name="sonoai_settings[rag_results]" value="<?php echo esc_attr( $opts['rag_results'] ?? 5 ); ?>" min="1" max="20">
                                <p class="kb-desc"><?php esc_html_e( 'Number of knowledge chunks to inject per query (1–20).', 'sonoai' ); ?></p>
                            </div>
                        </div>

                        <div class="kb-form-grid">
                            <label class="kb-label"><?php esc_html_e( 'Min Similarity Threshold', 'sonoai' ); ?></label>
                            <div>
                                <input type="number" name="sonoai_settings[rag_min_similarity]" value="<?php echo esc_attr( $opts['rag_min_similarity'] ?? 0.47 ); ?>" min="0" max="1" step="0.01">
                                <p class="kb-desc"><?php esc_html_e( 'Minimum relevance score. Recommended: 0.47.', 'sonoai' ); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Chat History -->
                    <div class="kb-card">
                        <div class="kb-tab-header">
                            <h2><?php esc_html_e( 'Chat History', 'sonoai' ); ?></h2>
                        </div>
                        <div class="kb-form-grid">
                            <label class="kb-label"><?php esc_html_e( 'History Limit', 'sonoai' ); ?></label>
                            <div>
                                <input type="number" name="sonoai_settings[history_limit]" value="<?php echo esc_attr( $opts['history_limit'] ?? 50 ); ?>" min="1" max="500">
                                <p class="kb-desc"><?php esc_html_e( 'Maximum number of chat sessions stored per user.', 'sonoai' ); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Danger Zone -->
                    <div class="kb-card kb-card-full">
                        <div class="kb-tab-header">
                            <h2 style="color:#ef4444 !important;"><?php esc_html_e( 'Danger Zone', 'sonoai' ); ?></h2>
                        </div>
                        <div class="kb-form-grid">
                            <label class="kb-label"><?php esc_html_e( 'Delete Data on Uninstall', 'sonoai' ); ?></label>
                            <div>
                                <label class="kb-switch">
                                    <input type="checkbox" name="sonoai_settings[delete_on_uninstall]" value="1" <?php checked( $opts['delete_on_uninstall'] ?? '0', '1' ); ?>>
                                    <span class="kb-switch-slider"></span>
                                    <span class="kb-switch-label"><?php esc_html_e( 'Purge all data when plugin is deleted', 'sonoai' ); ?></span>
                                </label>
                                <p class="kb-desc" style="color:#ef4444 !important; opacity:0.8;"><?php esc_html_e( 'Warning: Permanently erases settings, history, and vectors.', 'sonoai' ); ?></p>
                            </div>
                        </div>
                    </div>

                </div><!-- .kb-card-grid -->

                <div style="display: flex; justify-content: flex-end;">
                    <?php submit_button( __( 'Save All Settings', 'sonoai' ), 'primary kb-btn-primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
        <?php
    }
}
