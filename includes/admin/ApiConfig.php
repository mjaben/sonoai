<?php
/**
 * SonoAI — API Configuration sub-menu page.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ApiConfig {

    private static ?ApiConfig $instance = null;

    public static function instance(): ApiConfig {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_submenu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function add_submenu(): void {
        add_submenu_page(
            'sonoai-settings',                        // parent slug
            __( 'API Configuration – SonoAI', 'sonoai' ),
            __( 'API Configuration', 'sonoai' ),
            'manage_options',
            'sonoai-api-config',
            [ $this, 'render' ]
        );
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function register_settings(): void {
        // Reuses the same sonoai_settings option — just a different group name
        // so we can use settings_fields() on this page alone.
        register_setting( 'sonoai_api_config_group', 'sonoai_settings', [
            'sanitize_callback' => [ $this, 'sanitize' ],
        ] );
    }

    public function sanitize( array $input ): array {
        // Guard: only process when the API Config form was submitted.
        // Both Admin and ApiConfig register sanitize callbacks for sonoai_settings;
        // WordPress chains them. If the _page sentinel isn't ours, pass through unchanged.
        if ( ( $input['_page'] ?? '' ) !== 'api_config' ) {
            return $input;
        }

        $allowed_providers = [ 'openai', 'gemini', 'anthropic', 'mistral' ];
        $existing          = (array) get_option( 'sonoai_settings', [] );

        // Merge so non-API settings (RAG, history, etc.) are preserved.
        $clean = $existing;

        $clean['active_provider'] = in_array( $input['active_provider'] ?? '', $allowed_providers, true )
                                    ? $input['active_provider'] : ( $existing['active_provider'] ?? 'openai' );

        // API keys — only overwrite if a new (non-empty) value was submitted.
        foreach ( [ 'openai', 'gemini', 'anthropic', 'mistral' ] as $p ) {
            $key = $p . '_api_key';
            if ( ! empty( trim( $input[ $key ] ?? '' ) ) ) {
                $clean[ $key ] = sanitize_text_field( $input[ $key ] );
            }
        }

        // Chat models.
        $clean['openai_chat_model']    = sanitize_text_field( $input['openai_chat_model']    ?? ( $existing['openai_chat_model']    ?? 'gpt-4o' ) );
        $clean['gemini_chat_model']    = sanitize_text_field( $input['gemini_chat_model']    ?? ( $existing['gemini_chat_model']    ?? 'gemini-2.0-flash' ) );
        $clean['anthropic_chat_model'] = sanitize_text_field( $input['anthropic_chat_model'] ?? ( $existing['anthropic_chat_model'] ?? 'claude-3-5-sonnet-20241022' ) );
        $clean['mistral_chat_model']   = sanitize_text_field( $input['mistral_chat_model']   ?? ( $existing['mistral_chat_model']   ?? 'mistral-large-latest' ) );

        // Embedding models.
        $clean['openai_embedding_model']  = sanitize_text_field( $input['openai_embedding_model']  ?? ( $existing['openai_embedding_model']  ?? 'text-embedding-3-small' ) );
        $clean['gemini_embedding_model']  = sanitize_text_field( $input['gemini_embedding_model']  ?? ( $existing['gemini_embedding_model']  ?? 'text-embedding-004' ) );
        $clean['mistral_embedding_model'] = sanitize_text_field( $input['mistral_embedding_model'] ?? ( $existing['mistral_embedding_model'] ?? 'mistral-embed' ) );

        // Redis settings.
        $clean['redis_enabled']  = ! empty( $input['redis_enabled'] );
        $clean['redis_host']     = sanitize_text_field( $input['redis_host']     ?? '127.0.0.1' );
        $clean['redis_port']     = intval( $input['redis_port']     ?? 6379 );
        if ( ! empty( $input['redis_password'] ) ) {
            $clean['redis_password'] = sanitize_text_field( $input['redis_password'] );
        }

        // Refresh AI provider singleton.
        AIProvider::refresh();

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'sonoai-api-config' ) ) {
            return;
        }

        wp_enqueue_style( 'sonoai-kb', SONOAI_URL . 'assets/css/kb.css', [], SONOAI_VERSION );
        wp_enqueue_script( 'sonoai-kb', SONOAI_URL . 'assets/js/kb.js', [ 'jquery' ], SONOAI_VERSION, true );

        wp_localize_script( 'sonoai-kb', 'sonoaiKB', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonces'  => [
                'syncRedis' => wp_create_nonce( 'sonoai_kb_sync_redis' ),
            ],
            // Add provider context for Re-index tooltips
            'currentProvider' => sonoai_option( 'active_provider', 'openai' ),
            'currentModel'    => sonoai_option( sonoai_option( 'active_provider', 'openai' ) . '_embedding_model', '' ),
        ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $opts     = (array) get_option( 'sonoai_settings', [] );
        $provider = $opts['active_provider'] ?? 'openai';

        // Key presence helpers.
        $keys = [
            'openai'    => $opts['openai_api_key']    ?? '',
            'gemini'    => $opts['gemini_api_key']    ?? '',
            'anthropic' => $opts['anthropic_api_key'] ?? '',
            'mistral'   => $opts['mistral_api_key']   ?? '',
        ];
        $active_key_set = ! empty( $keys[ $provider ] );

        // Mask helper — shows sk-...xxxx style preview.
        $mask = function( string $key ): string {
            if ( empty( $key ) ) return '';
            $len = strlen( $key );
            if ( $len <= 8 ) return str_repeat( '•', $len );
            return substr( $key, 0, 4 ) . str_repeat( '•', max( 4, $len - 8 ) ) . substr( $key, -4 );
        };

        // Provider meta.
        $providers = [
            'openai'    => [
                'label'   => 'OpenAI',
                'logo'    => '⬡',
                'color'   => '#10a37f',
                'doc_url' => 'https://platform.openai.com/api-keys',
            ],
            'gemini'    => [
                'label'   => 'Google Gemini',
                'logo'    => '✦',
                'color'   => '#4285f4',
                'doc_url' => 'https://aistudio.google.com/app/apikey',
            ],
            'anthropic' => [
                'label'   => 'Anthropic Claude',
                'logo'    => '◆',
                'color'   => '#d97706',
                'doc_url' => 'https://console.anthropic.com/settings/keys',
            ],
            'mistral'   => [
                'label'   => 'Mistral AI',
                'logo'    => '◈',
                'color'   => '#7c3aed',
                'doc_url' => 'https://console.mistral.ai/api-keys/',
            ],
        ];

        // Models list (used by JS too via wp_add_inline_script or data attribute).
        $chat_models = [
            'openai'    => [ 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo' ],
            'gemini'    => [ 'gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash' ],
            'anthropic' => [ 'claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022', 'claude-3-opus-20240229' ],
            'mistral'   => [ 'mistral-large-latest', 'mistral-small-latest', 'open-mistral-7b' ],
        ];
        $embed_models = [
            'openai'    => [ 'text-embedding-3-large', 'text-embedding-3-small', 'text-embedding-ada-002' ],
            'gemini'    => [ 'text-embedding-004' ],
            'anthropic' => [], // No native embedding — note shown in UI.
            'mistral'   => [ 'mistral-embed' ],
        ];

        $saved_chat  = [
            'openai'    => $opts['openai_chat_model']    ?? 'gpt-4o',
            'gemini'    => $opts['gemini_chat_model']    ?? 'gemini-2.0-flash',
            'anthropic' => $opts['anthropic_chat_model'] ?? 'claude-3-5-sonnet-20241022',
            'mistral'   => $opts['mistral_chat_model']   ?? 'mistral-large-latest',
        ];
        $saved_embed = [
            'openai'    => $opts['openai_embedding_model']  ?? 'text-embedding-3-small',
            'gemini'    => $opts['gemini_embedding_model']  ?? 'text-embedding-004',
            'anthropic' => $opts['openai_embedding_model']  ?? 'text-embedding-3-small', // fallback
            'mistral'   => $opts['mistral_embedding_model'] ?? 'mistral-embed',
        ];
        ?>
        <div class="kb-wrap" id="kb-api-config-page">

            <!-- ── Hero Header ─────────────────────────────────────────── -->
            <div class="kb-header">
                <div class="kb-header-left">
                    <div class="kb-header-icon">⚙️</div>
                    <div>
                        <h1 class="kb-title"><?php esc_html_e( 'API Configuration', 'sonoai' ); ?></h1>
                        <p class="kb-subtitle"><?php esc_html_e( 'Configure your AI provider to enable SonoAI features.', 'sonoai' ); ?></p>
                    </div>
                </div>
                <div class="kb-header-right">
                    <div class="kb-status-badge <?php echo $active_key_set ? 'kb-status-connected' : 'kb-status-missing'; ?>">
                        <span class="kb-status-dot"></span>
                        <?php echo $active_key_set
                            ? esc_html__( 'Connected', 'sonoai' )
                            : esc_html__( 'Not Configured', 'sonoai' ); ?>
                    </div>
                    <button type="button" id="kb-theme-toggle" class="kb-theme-btn" title="Toggle dark / light mode">
                        <span class="kb-icon-dark">🌙</span>
                        <span class="kb-icon-light">☀️</span>
                    </button>
                </div>
            </div>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible" style="margin-top:20px;">
                    <p><?php esc_html_e( 'API settings saved successfully.', 'sonoai' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" id="kb-api-form" style="margin-top: 30px;">
                <?php settings_fields( 'sonoai_api_config_group' ); ?>
                <input type="hidden" name="sonoai_settings[_page]" value="api_config">

                <div class="kb-card">
                    <div class="kb-tab-header">
                        <h2><?php esc_html_e( 'Provider Settings', 'sonoai' ); ?></h2>
                    </div>

                    <!-- ── AI Provider ──────────────────────────────────── -->
                    <div class="kb-form-grid">
                        <label class="kb-label" for="kb-provider-select">
                            <?php esc_html_e( 'AI Provider', 'sonoai' ); ?>
                        </label>
                        <div>
                            <select id="kb-provider-select" name="sonoai_settings[active_provider]">
                                <?php foreach ( $providers as $slug => $meta ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"
                                        <?php selected( $provider, $slug ); ?>
                                        data-color="<?php echo esc_attr( $meta['color'] ); ?>"
                                        data-doc="<?php echo esc_url( $meta['doc_url'] ); ?>">
                                        <?php echo esc_html( $meta['logo'] . '  ' . $meta['label'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="kb-desc"><?php esc_html_e( 'Choose which AI provider to use for chat and embeddings.', 'sonoai' ); ?></p>
                        </div>
                    </div>

                    <!-- ── API Keys (one per provider, shown/hidden via JS) ─ -->
                    <?php foreach ( $providers as $slug => $meta ) :
                        $pkey   = $keys[ $slug ];
                        $is_set = ! empty( $pkey );
                        ?>
                        <div class="kb-form-grid kb-key-group"
                             data-provider="<?php echo esc_attr( $slug ); ?>"
                             style="<?php echo $provider !== $slug ? 'display:none;' : ''; ?>">
                            <label class="kb-label" for="kb-key-<?php echo esc_attr( $slug ); ?>">
                                <?php echo esc_html( $meta['label'] ); ?> <?php esc_html_e( 'API Key', 'sonoai' ); ?>
                                <?php if ( $is_set ) : ?>
                                    <span class="kb-status-badge kb-status-connected" style="zoom: 0.8; margin-left:10px;">✓ <?php esc_html_e( 'Configured', 'sonoai' ); ?></span>
                                <?php else : ?>
                                    <span class="kb-status-badge kb-status-missing" style="zoom: 0.8; margin-left:10px;"><?php esc_html_e( 'Not Set', 'sonoai' ); ?></span>
                                <?php endif; ?>
                            </label>
                            <div>
                                <div class="kb-input-wrap">
                                    <input
                                        type="password"
                                        id="kb-key-<?php echo esc_attr( $slug ); ?>"
                                        name="sonoai_settings[<?php echo esc_attr( $slug ); ?>_api_key]"
                                        value=""
                                        data-key="<?php echo esc_attr( $pkey ); ?>"
                                        placeholder="<?php echo $is_set
                                            ? esc_attr( $mask( $pkey ) )
                                            : esc_attr__( 'Enter API key…', 'sonoai' ); ?>"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="kb-eye-btn" data-target="kb-key-<?php echo esc_attr( $slug ); ?>" title="<?php esc_attr_e( 'Show / hide key', 'sonoai' ); ?>">
                                        👁️
                                    </button>
                                </div>
                                <p class="kb-desc">
                                    <?php esc_html_e( 'Leave blank to keep the existing key. Enter a new key to replace it.', 'sonoai' ); ?>
                                </p>
                                <a href="<?php echo esc_url( $meta['doc_url'] ); ?>" target="_blank" rel="noopener" class="kb-source-link" style="font-size: 12px; margin-top:8px; display:inline-block;">
                                    <?php /* translators: %s = provider name */ ?>
                                    <?php printf( esc_html__( 'Get your %s API key →', 'sonoai' ), esc_html( $meta['label'] ) ); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- ── Chat Model ──────────────────────────────────── -->
                    <div class="kb-form-grid">
                        <label class="kb-label" for="kb-chat-model">
                            <?php esc_html_e( 'AI Chat Model', 'sonoai' ); ?>
                        </label>
                        <div>
                            <?php foreach ( $chat_models as $p_slug => $models ) : ?>
                                <div class="kb-model-group kb-chat-model-group"
                                     data-provider="<?php echo esc_attr( $p_slug ); ?>"
                                     style="<?php echo $provider !== $p_slug ? 'display:none;' : ''; ?>">
                                    <select id="kb-chat-model" name="sonoai_settings[<?php echo esc_attr( $p_slug ); ?>_chat_model]">
                                        <?php foreach ( $models as $m ) : ?>
                                            <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $saved_chat[ $p_slug ], $m ); ?>>
                                                <?php echo esc_html( $m ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                            <p class="kb-desc"><?php esc_html_e( 'Select the chat model to use for AI responses.', 'sonoai' ); ?></p>
                        </div>
                    </div>

                    <!-- ── Embedding Model ─────────────────────────────── -->
                    <div class="kb-form-grid">
                        <label class="kb-label" for="kb-embed-model">
                            <?php esc_html_e( 'AI Embedding Model', 'sonoai' ); ?>
                        </label>
                        <div>
                            <?php foreach ( $embed_models as $p_slug => $models ) :
                                $has_embed = ! empty( $models );
                                ?>
                                <div class="kb-model-group kb-embed-model-group"
                                     data-provider="<?php echo esc_attr( $p_slug ); ?>"
                                     style="<?php echo $provider !== $p_slug ? 'display:none;' : ''; ?>">
                                    <?php if ( $has_embed ) : ?>
                                        <select name="sonoai_settings[<?php echo esc_attr( $p_slug ); ?>_embedding_model]">
                                            <?php foreach ( $models as $m ) : ?>
                                                <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $saved_embed[ $p_slug ], $m ); ?>>
                                                    <?php echo esc_html( $m ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else : ?>
                                        <div class="notice notice-info inline" style="margin:0;">
                                            <p style="font-size:12px;"><?php esc_html_e( 'Anthropic does not offer a native embedding API. SonoAI will use your OpenAI key for embeddings. Please ensure an OpenAI API key is also configured.', 'sonoai' ); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <p class="kb-desc"><?php esc_html_e( 'Used to generate vector embeddings for the RAG knowledge base.', 'sonoai' ); ?></p>
                        </div>
                    </div>

                    <!-- ── Redis Configuration ─────────────────────────── -->
                    <div class="kb-form-grid">
                        <label class="kb-label">
                            <?php esc_html_e( 'Redis Vector Cache', 'sonoai' ); ?>
                            <?php if ( RedisManager::instance()->is_active() ) : ?>
                                <span class="kb-status-badge kb-status-connected" style="zoom: 0.8; margin-left:10px;">✓ <?php esc_html_e( 'Active', 'sonoai' ); ?></span>
                            <?php else : ?>
                                <span class="kb-status-badge kb-status-missing" style="zoom: 0.8; margin-left:10px;"><?php esc_html_e( 'Inactive', 'sonoai' ); ?></span>
                            <?php endif; ?>
                        </label>
                        <div>
                            <label class="kb-switch">
                                <input type="checkbox" name="sonoai_settings[redis_enabled]" value="1" <?php checked( $opts['redis_enabled'] ?? false ); ?>>
                                <span class="kb-switch-slider"></span>
                                <span class="kb-switch-label"><?php esc_html_e( 'Enable Redis for High-Performance RAG & Memory', 'sonoai' ); ?></span>
                            </label>
                            
                            <div class="kb-redis-details" style="display: <?php echo ( $opts['redis_enabled'] ?? false ) ? 'block' : 'none'; ?>;">
                                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                    <div style="flex: 2;">
                                        <label class="kb-label" style="font-size: 11px;"><?php esc_html_e( 'Host', 'sonoai' ); ?></label>
                                        <input type="text" name="sonoai_settings[redis_host]" value="<?php echo esc_attr( $opts['redis_host'] ?? '127.0.0.1' ); ?>" placeholder="127.0.0.1">
                                    </div>
                                    <div style="flex: 1;">
                                        <label class="kb-label" style="font-size: 11px;"><?php esc_html_e( 'Port', 'sonoai' ); ?></label>
                                        <input type="number" name="sonoai_settings[redis_port]" value="<?php echo esc_attr( $opts['redis_port'] ?? 6379 ); ?>" placeholder="6379">
                                    </div>
                                </div>
                                <div style="margin-bottom: 20px;">
                                    <label class="kb-label" style="font-size: 11px;"><?php esc_html_e( 'Password (Optional)', 'sonoai' ); ?></label>
                                    <input type="password" name="sonoai_settings[redis_password]" value="" placeholder="<?php echo !empty($opts['redis_password']) ? '••••••••' : 'No password'; ?>">
                                </div>

                                <div class="kb-redis-actions">
                                    <button type="button" id="kb-redis-sync-btn" class="kb-btn-secondary" style="background: rgba(37, 99, 235, 0.08); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.15); font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px;">
                                        <span class="kb-btn-text"><?php esc_html_e( 'Sync MySQL Vectors to Redis', 'sonoai' ); ?></span>
                                        <span class="kb-spinner" style="display:none; border: 2px solid rgba(37,99,235,0.1); border-left-color: #2563eb; border-radius: 50%; width: 14px; height: 14px; animation: kb-spin 0.8s linear infinite;"></span>
                                    </button>
                                    <p class="kb-desc" style="margin-top: 10px;">
                                        <?php esc_html_e( 'Sync your existing Knowledge Base vectors into Redis for high-performance retrieval.', 'sonoai' ); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- .kb-card -->

                <!-- ── Quick Tips ──────────────────────────────────────── -->
                <div class="kb-card kb-card-full" style="background:#f0f9ff; border-left:4px solid #3b82f6; margin-top:20px;">
                    <div style="display:flex; gap:12px; padding:20px;">
                        <span style="font-size:20px;">💡</span>
                        <div>
                            <strong style="display:block; margin-bottom:5px; color:#1e40af;"><?php esc_html_e( 'Quick Tips', 'sonoai' ); ?></strong>
                            <ul style="margin:0; padding-left:18px; font-size:12.5px; color:#374151; line-height:1.6;">
                                <li><?php esc_html_e( 'API keys are stored securely and only accessible to administrators.', 'sonoai' ); ?></li>
                                <li><?php esc_html_e( 'Each provider has unique chat and embedding model capabilities.', 'sonoai' ); ?></li>
                                <li><?php esc_html_e( 'Changes take effect immediately across all SonoAI interfaces.', 'sonoai' ); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>

            </form>
        </div><!-- .kb-wrap -->
        <?php
    }
}
