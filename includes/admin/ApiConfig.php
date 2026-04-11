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
        // Use strpos to handle any WordPress hook name variation for sub-menu pages.
        if ( false === strpos( $hook, 'sonoai-api-config' ) ) {
            return;
        }
        wp_enqueue_style(
            'sonoai-api-config',
            SONOAI_URL . 'assets/css/api-config.css',
            [],
            SONOAI_VERSION
        );
        wp_enqueue_script(
            'sonoai-api-config',
            SONOAI_URL . 'assets/js/api-config.js',
            [ 'jquery' ],
            SONOAI_VERSION,
            true
        );
        wp_localize_script( 'sonoai-api-config', 'sonoai_vars', [
            'nonce' => wp_create_nonce( 'sonoai_admin_nonce' ),
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
        <div class="sac-wrap" id="sonoai-api-config-page">

            <!-- ── Hero Header ─────────────────────────────────────────── -->
            <div class="sac-header">
                <div class="sac-header-left">
                    <div class="sac-header-icon">⚙</div>
                    <div>
                        <h1 class="sac-title"><?php esc_html_e( 'API Configuration', 'sonoai' ); ?></h1>
                        <p class="sac-subtitle"><?php esc_html_e( 'Configure your AI provider to enable SonoAI features.', 'sonoai' ); ?></p>
                    </div>
                </div>
                <div class="sac-header-right">
                    <div class="sac-status-badge <?php echo $active_key_set ? 'sac-status-connected' : 'sac-status-missing'; ?>">
                        <span class="sac-status-dot"></span>
                        <?php echo $active_key_set
                            ? esc_html__( 'Connected', 'sonoai' )
                            : esc_html__( 'Not Configured', 'sonoai' ); ?>
                    </div>
                    <button type="button" id="sac-theme-toggle" class="sac-theme-btn" title="Toggle dark / light mode">
                        <span class="sac-icon-dark">🌙</span>
                        <span class="sac-icon-light">☀️</span>
                    </button>
                </div>
            </div>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="sac-notice sac-notice-success">
                    <span>✓</span> <?php esc_html_e( 'API settings saved successfully.', 'sonoai' ); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" id="sac-form">
                <?php settings_fields( 'sonoai_api_config_group' ); ?>
                <input type="hidden" name="sonoai_settings[_page]" value="api_config">

                <div class="sac-card">

                    <!-- ── AI Provider ──────────────────────────────────── -->
                    <div class="sac-field-group">
                        <label class="sac-label" for="sac-provider-select">
                            <?php esc_html_e( 'AI Provider', 'sonoai' ); ?>
                        </label>
                        <div class="sac-control">
                            <div class="sac-select-wrap">
                                <select id="sac-provider-select" name="sonoai_settings[active_provider]" class="sac-select">
                                    <?php foreach ( $providers as $slug => $meta ) : ?>
                                        <option value="<?php echo esc_attr( $slug ); ?>"
                                            <?php selected( $provider, $slug ); ?>
                                            data-color="<?php echo esc_attr( $meta['color'] ); ?>"
                                            data-doc="<?php echo esc_url( $meta['doc_url'] ); ?>">
                                            <?php echo esc_html( $meta['logo'] . '  ' . $meta['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <p class="sac-desc"><?php esc_html_e( 'Choose which AI provider to use for chat and embeddings.', 'sonoai' ); ?></p>
                        </div>
                    </div>

                    <div class="sac-divider"></div>

                    <!-- ── API Keys (one per provider, shown/hidden via JS) ─ -->
                    <?php foreach ( $providers as $slug => $meta ) :
                        $pkey   = $keys[ $slug ];
                        $is_set = ! empty( $pkey );
                        ?>
                        <div class="sac-field-group sac-key-group"
                             data-provider="<?php echo esc_attr( $slug ); ?>"
                             style="<?php echo $provider !== $slug ? 'display:none;' : ''; ?>">
                            <label class="sac-label" for="sac-key-<?php echo esc_attr( $slug ); ?>">
                                <?php echo esc_html( $meta['label'] ); ?> <?php esc_html_e( 'API Key', 'sonoai' ); ?>
                                <?php if ( $is_set ) : ?>
                                    <span class="sac-badge sac-badge-ok">✓ <?php esc_html_e( 'Key Configured', 'sonoai' ); ?></span>
                                <?php else : ?>
                                    <span class="sac-badge sac-badge-missing"><?php esc_html_e( 'Not Set', 'sonoai' ); ?></span>
                                <?php endif; ?>
                            </label>
                            <div class="sac-control">
                                <div class="sac-input-wrap">
                                    <input
                                        type="password"
                                        id="sac-key-<?php echo esc_attr( $slug ); ?>"
                                        name="sonoai_settings[<?php echo esc_attr( $slug ); ?>_api_key]"
                                        value=""
                                        placeholder="<?php echo $is_set
                                            ? esc_attr( $mask( $pkey ) )
                                            : esc_attr__( 'Enter API key…', 'sonoai' ); ?>"
                                        autocomplete="new-password"
                                        class="sac-input"
                                    >
                                    <button type="button" class="sac-eye-btn" data-target="sac-key-<?php echo esc_attr( $slug ); ?>" title="<?php esc_attr_e( 'Show / hide key', 'sonoai' ); ?>">
                                        <svg class="sac-eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        <svg class="sac-eye-off-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                                            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/>
                                            <line x1="1" y1="1" x2="23" y2="23"/>
                                        </svg>
                                    </button>
                                </div>
                                <p class="sac-desc">
                                    <?php esc_html_e( 'Leave blank to keep the existing key. Enter a new key to replace it.', 'sonoai' ); ?>
                                </p>
                                <a href="<?php echo esc_url( $meta['doc_url'] ); ?>" target="_blank" rel="noopener" class="sac-key-link">
                                    <?php /* translators: %s = provider name */ ?>
                                    <?php printf( esc_html__( 'Get your %s API key →', 'sonoai' ), esc_html( $meta['label'] ) ); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="sac-divider"></div>

                    <!-- ── Chat Model ──────────────────────────────────── -->
                    <div class="sac-field-group">
                        <label class="sac-label" for="sac-chat-model">
                            <?php esc_html_e( 'AI Chat Model', 'sonoai' ); ?>
                        </label>
                        <div class="sac-control">
                            <?php foreach ( $chat_models as $p_slug => $models ) : ?>
                                <div class="sac-model-group sac-chat-model-group"
                                     data-provider="<?php echo esc_attr( $p_slug ); ?>"
                                     style="<?php echo $provider !== $p_slug ? 'display:none;' : ''; ?>">
                                    <div class="sac-select-wrap">
                                        <select id="sac-chat-model" name="sonoai_settings[<?php echo esc_attr( $p_slug ); ?>_chat_model]" class="sac-select">
                                            <?php foreach ( $models as $m ) : ?>
                                                <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $saved_chat[ $p_slug ], $m ); ?>>
                                                    <?php echo esc_html( $m ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <p class="sac-desc"><?php esc_html_e( 'Select the chat model to use for AI responses.', 'sonoai' ); ?></p>
                        </div>
                    </div>

                    <div class="sac-divider"></div>

                    <!-- ── Embedding Model ─────────────────────────────── -->
                    <div class="sac-field-group">
                        <label class="sac-label" for="sac-embed-model">
                            <?php esc_html_e( 'AI Embedding Model', 'sonoai' ); ?>
                        </label>
                        <div class="sac-control">
                            <?php foreach ( $embed_models as $p_slug => $models ) :
                                $has_embed = ! empty( $models );
                                ?>
                                <div class="sac-model-group sac-embed-model-group"
                                     data-provider="<?php echo esc_attr( $p_slug ); ?>"
                                     style="<?php echo $provider !== $p_slug ? 'display:none;' : ''; ?>">
                                    <?php if ( $has_embed ) : ?>
                                        <div class="sac-select-wrap">
                                            <select name="sonoai_settings[<?php echo esc_attr( $p_slug ); ?>_embedding_model]" class="sac-select">
                                                <?php foreach ( $models as $m ) : ?>
                                                    <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $saved_embed[ $p_slug ], $m ); ?>>
                                                        <?php echo esc_html( $m ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php else : ?>
                                        <div class="sac-notice-inline">
                                            <span>ℹ</span>
                                            <?php esc_html_e( 'Anthropic does not offer a native embedding API. SonoAI will use your OpenAI key for embeddings. Please ensure an OpenAI API key is also configured.', 'sonoai' ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <p class="sac-desc"><?php esc_html_e( 'Used to generate vector embeddings for the RAG knowledge base.', 'sonoai' ); ?></p>
                        </div>
                    </div>

                    <div class="sac-divider"></div>

                    <!-- ── Redis Configuration ─────────────────────────── -->
                    <div class="sac-field-group">
                        <label class="sac-label">
                            <?php esc_html_e( 'Redis Vector Cache', 'sonoai' ); ?>
                            <?php if ( RedisManager::instance()->is_active() ) : ?>
                                <span class="sac-badge sac-badge-ok">✓ <?php esc_html_e( 'Active', 'sonoai' ); ?></span>
                            <?php else : ?>
                                <span class="sac-badge sac-badge-missing"><?php esc_html_e( 'Inactive', 'sonoai' ); ?></span>
                            <?php endif; ?>
                        </label>
                        <div class="sac-control">
                            <label class="sac-switch-wrap">
                                <input type="checkbox" name="sonoai_settings[redis_enabled]" value="1" <?php checked( $opts['redis_enabled'] ?? false ); ?>>
                                <span class="sac-switch-slider"></span>
                                <span class="sac-switch-label"><?php esc_html_e( 'Enable Redis for High-Performance RAG & Memory', 'sonoai' ); ?></span>
                            </label>
                            
                            <div class="sac-redis-details" style="margin-top: 15px; display: <?php echo ( $opts['redis_enabled'] ?? false ) ? 'block' : 'none'; ?>;">
                                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                    <div style="flex: 2;">
                                        <label style="font-size: 11px; text-transform: uppercase; color: #888;"><?php esc_html_e( 'Host', 'sonoai' ); ?></label>
                                        <input type="text" name="sonoai_settings[redis_host]" value="<?php echo esc_attr( $opts['redis_host'] ?? '127.0.0.1' ); ?>" class="sac-input-sm" placeholder="127.0.0.1">
                                    </div>
                                    <div style="flex: 1;">
                                        <label style="font-size: 11px; text-transform: uppercase; color: #888;"><?php esc_html_e( 'Port', 'sonoai' ); ?></label>
                                        <input type="number" name="sonoai_settings[redis_port]" value="<?php echo esc_attr( $opts['redis_port'] ?? 6379 ); ?>" class="sac-input-sm" placeholder="6379">
                                    </div>
                                </div>
                                <div style="margin-bottom: 10px;">
                                    <label style="font-size: 11px; text-transform: uppercase; color: #888;"><?php esc_html_e( 'Password (Optional)', 'sonoai' ); ?></label>
                                    <input type="password" name="sonoai_settings[redis_password]" value="" class="sac-input-sm" placeholder="<?php echo !empty($opts['redis_password']) ? '••••••••' : 'No password'; ?>">
                                </div>
                                <div class="sac-notice-inline" style="background: rgba(0,0,0,0.05); margin-bottom: 15px;">
                                    <span>ℹ</span>
                                    <?php esc_html_e( 'Use Redis for sub-millisecond retrieval. If disabled, SonoAI will fall back to MySQL vector storage.', 'sonoai' ); ?>
                                </div>

                                <div class="sac-redis-actions">
                                    <button type="button" id="sac-redis-sync-btn" class="sac-btn-secondary" style="background: var(--sac-accent-dim); color: var(--sac-accent); border: 1px solid var(--sac-accent-dim); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px;">
                                        <span class="sac-sync-icon">🔄</span>
                                        <span class="sac-btn-text"><?php esc_html_e( 'Sync MySQL Vectors to Redis', 'sonoai' ); ?></span>
                                        <span class="sac-spinner" style="display:none; border: 2px solid rgba(0,0,0,0.1); border-left-color: var(--sac-accent); border-radius: 50%; width: 14px; height: 14px; animation: sac-spin 0.8s linear infinite;"></span>
                                    </button>
                                    <p class="description" style="margin-top: 8px; font-size: 11px;">
                                        <?php esc_html_e( 'Click this after the first connection to push your existing Knowledge Base vectors into Redis for high-performance retrieval.', 'sonoai' ); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- .sac-card -->

                <!-- ── Quick Tips ──────────────────────────────────────── -->
                <div class="sac-tips-card">
                    <div class="sac-tips-icon">💡</div>
                    <div>
                        <strong><?php esc_html_e( 'Quick Tips', 'sonoai' ); ?></strong>
                        <ul class="sac-tips-list">
                            <li><?php esc_html_e( 'Your API key is stored securely in the WordPress database and is only accessible to users with administrator privileges.', 'sonoai' ); ?></li>
                            <li><?php esc_html_e( 'Pick a chat model that fits your quality, speed, and cost needs.', 'sonoai' ); ?></li>
                            <li><?php esc_html_e( 'Changes take effect immediately after saving.', 'sonoai' ); ?></li>
                        </ul>
                    </div>
                </div>

                <!-- ── Save Button ─────────────────────────────────────── -->
                <div class="sac-footer">
                    <?php submit_button( __( 'Save Settings', 'sonoai' ), 'primary', 'submit', false, [ 'id' => 'sac-save-btn', 'class' => 'sac-save-btn' ] ); ?>
                </div>

            </form>
        </div><!-- .sac-wrap -->
        <?php
    }
}
