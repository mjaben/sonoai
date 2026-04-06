<?php
/**
 * SonoAI — Knowledge Base admin sub-menu page.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KnowledgeBase {

    private static ?KnowledgeBase $instance = null;

    public static function instance(): KnowledgeBase {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function add_submenu(): void {
        add_submenu_page(
            'sonoai-settings',
            __( 'Knowledge Base – SonoAI', 'sonoai' ),
            __( 'Knowledge Base', 'sonoai' ),
            'manage_options',
            'sonoai-kb',
            [ $this, 'render' ]
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'sonoai-kb' ) && false === strpos( $hook, 'sonoai-topics' ) && false === strpos( $hook, 'sonoai-query-logs' ) && false === strpos( $hook, 'sonoai-feedback' ) ) {
            return;
        }
        // Classic editor (TinyMCE) for Custom Text tab.
        wp_enqueue_editor();
        wp_enqueue_media();

        wp_enqueue_style(
            'sonoai-kb',
            SONOAI_URL . 'assets/css/kb.css',
            [],
            SONOAI_VERSION
        );
        wp_enqueue_script(
            'sonoai-kb',
            SONOAI_URL . 'assets/js/kb.js',
            [ 'jquery' ],
            SONOAI_VERSION,
            true
        );
        wp_localize_script( 'sonoai-kb', 'sonoaiKB', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonces'    => [
                'addPost'    => wp_create_nonce( 'sonoai_kb_add_post' ),
                'removePost' => wp_create_nonce( 'sonoai_kb_remove_post' ),
                'addPdf'     => wp_create_nonce( 'sonoai_kb_add_pdf' ),
                'addUrl'     => wp_create_nonce( 'sonoai_kb_add_url' ),
                'addTxt'     => wp_create_nonce( 'sonoai_kb_add_txt' ),
                'editTxt'    => wp_create_nonce( 'sonoai_kb_edit_txt' ),
                'deleteItem' => wp_create_nonce( 'sonoai_kb_delete_item' ),
                'getPosts'   => wp_create_nonce( 'sonoai_kb_get_posts' ),
                'updateMeta' => wp_create_nonce( 'sonoai_kb_update_meta' ),
                'topics'     => wp_create_nonce( 'sonoai_kb_manage_topics' ),
                'syncTopics' => wp_create_nonce( 'sonoai_kb_sync_topics' ),
                'uploadImg'  => wp_create_nonce( 'sonoai_kb_upload_img' ),
            ],
            'postTypes'  => self::get_eligible_post_types(),
            'providerLabel' => sonoai_option( 'active_provider', 'openai' ),
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function get_eligible_post_types(): array {
        $types  = get_post_types( [ 'public' => true ], 'objects' );
        $result = [];
        foreach ( $types as $pt ) {
            if ( post_type_supports( $pt->name, 'editor' ) ) {
                $result[] = [
                    'name'  => $pt->name,
                    'label' => $pt->label,
                    'count' => wp_count_posts( $pt->name )->publish ?? 0,
                ];
            }
        }
        return $result;
    }

    public static function get_kb_stats(): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'sonoai_kb_items';
        $rows = $wpdb->get_results( "SELECT `type`, COUNT(*) as cnt FROM `$tbl` GROUP BY `type`", ARRAY_A );
        $stats = [ 'total' => 0, 'wp' => 0, 'pdf' => 0, 'url' => 0, 'txt' => 0 ];
        foreach ( $rows as $r ) {
            $stats[ $r['type'] ] = (int) $r['cnt'];
            $stats['total']     += (int) $r['cnt'];
        }
        return $stats;
    }

    public static function get_topics(): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'sonoai_kb_topics';
        return $wpdb->get_results( "SELECT * FROM `$tbl` ORDER BY `name` ASC" ) ?: [];
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $stats     = self::get_kb_stats();
        $tab       = sanitize_key( $_GET['kb_tab'] ?? 'overview' );
        $post_type = sanitize_key( $_GET['pt'] ?? 'post' );
        $valid_tabs = [ 'overview', 'wp', 'pdf', 'url', 'txt', 'media' ];
        if ( ! in_array( $tab, $valid_tabs, true ) ) {
            $tab = 'overview';
        }
        ?>
        <div class="kb-wrap" id="sonoai-kb-page">

            <!-- ── Hero Header ──────────────────────────────────────────── -->
            <div class="kb-header">
                <div class="kb-header-left">
                    <div class="kb-header-icon">📚</div>
                    <div>
                        <h1 class="kb-title"><?php esc_html_e( 'Knowledge Base', 'sonoai' ); ?></h1>
                        <p class="kb-subtitle"><?php
                            printf(
                                esc_html__( 'Add content from multiple sources to train your AI assistant. Currently using %s embeddings.', 'sonoai' ),
                                '<strong>' . esc_html( ucfirst( sonoai_option( 'active_provider', 'OpenAI' ) ) ) . '</strong>'
                            );
                        ?></p>
                    </div>
                </div>
                <div class="kb-header-right">
                    <div class="kb-stat-badge">
                        <span class="kb-stat-icon">🗂</span>
                        <span><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?> <?php esc_html_e( 'Total Items', 'sonoai' ); ?></span>
                    </div>
                    <?php if ( $stats['wp'] ) : ?>
                    <div class="kb-stat-badge">
                        <span class="kb-stat-icon">📝</span>
                        <span><?php echo esc_html( number_format_i18n( $stats['wp'] ) ); ?> <?php esc_html_e( 'WP Posts', 'sonoai' ); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $stats['pdf'] ) : ?>
                    <div class="kb-stat-badge">
                        <span class="kb-stat-icon">📄</span>
                        <span><?php echo esc_html( number_format_i18n( $stats['pdf'] ) ); ?> <?php esc_html_e( 'PDFs', 'sonoai' ); ?></span>
                    </div>
                    <?php endif; ?>
                    <button type="button" id="kb-theme-toggle" class="kb-theme-btn" title="Toggle dark / light mode">
                        <span class="kb-icon-dark">🌙</span>
                        <span class="kb-icon-light">☀️</span>
                    </button>
                </div>
            </div>

            <!-- ── Tabs ─────────────────────────────────────────────────── -->
            <nav class="kb-tabs" role="tablist">
                <?php
                $tabs = [
                    'overview' => [ 'icon' => '⊞', 'label' => __( 'Overview', 'sonoai' ) ],
                    'wp'       => [ 'icon' => '📝', 'label' => sprintf( __( 'WordPress Posts (%d)', 'sonoai' ), $stats['wp'] ) ],
                    'pdf'      => [ 'icon' => '📄', 'label' => sprintf( __( 'PDF Upload (%d)', 'sonoai' ), $stats['pdf'] ) ],
                    'url'      => [ 'icon' => '🌐', 'label' => sprintf( __( 'Website URL (%d)', 'sonoai' ), $stats['url'] ) ],
                    'txt'      => [ 'icon' => '✏️', 'label' => sprintf( __( 'Custom Text (%d)', 'sonoai' ), $stats['txt'] ) ],
                    'media'    => [ 'icon' => '🖼', 'label' => __( 'Media', 'sonoai' ) ],
                ];
                foreach ( $tabs as $slug => $meta ) :
                    $url = add_query_arg( [ 'page' => 'sonoai-kb', 'kb_tab' => $slug ], admin_url( 'admin.php' ) );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="kb-tab <?php echo $tab === $slug ? 'kb-tab-active' : ''; ?>"
                   role="tab"
                   data-tab="<?php echo esc_attr( $slug ); ?>">
                    <?php echo $meta['icon']; ?> <?php echo esc_html( $meta['label'] ); ?>
                    <?php if ( $slug === 'media' ) : ?><span class="kb-coming-soon-badge"><?php esc_html_e( 'Coming Soon', 'sonoai' ); ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <!-- ── Tab Panels ────────────────────────────────────────────── -->
            <div class="kb-panel-wrap">

                <?php if ( $tab === 'overview' ) : ?>
                    <?php $this->render_overview( $stats ); ?>

                <?php elseif ( $tab === 'wp' ) : ?>
                    <?php $this->render_wp_tab( $post_type ); ?>

                <?php elseif ( $tab === 'pdf' ) : ?>
                    <?php $this->render_pdf_tab(); ?>

                <?php elseif ( $tab === 'url' ) : ?>
                    <?php $this->render_url_tab(); ?>

                <?php elseif ( $tab === 'txt' ) : ?>
                    <?php $this->render_txt_tab(); ?>

                <?php elseif ( $tab === 'media' ) : ?>
                    <?php $this->render_media_tab(); ?>

                <?php endif; ?>

            </div><!-- .kb-panel-wrap -->
        </div><!-- .kb-wrap -->
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: Overview
    // ─────────────────────────────────────────────────────────────────────────

    private function render_overview( array $stats ): void {
        $sources = [
            'wp'    => [
                'icon'    => '📝',
                'color'   => '#3b82f6',
                'title'   => __( 'WordPress Posts', 'sonoai' ),
                'count'   => $stats['wp'],
                'desc'    => __( 'Add your existing WordPress posts, pages, and custom post types to train your AI assistant.', 'sonoai' ),
                'bullets' => [
                    __( 'Blog posts & pages', 'sonoai' ),
                    __( 'Other custom post types', 'sonoai' ),
                    __( 'Auto-update on changes', 'sonoai' ),
                ],
                'use'   => __( 'Best for: blog content, documentation, product pages.', 'sonoai' ),
                'tab'   => 'wp',
                'cta'   => __( 'Manage Content', 'sonoai' ),
            ],
            'pdf'   => [
                'icon'    => '📄',
                'color'   => '#ef4444',
                'title'   => __( 'PDF Upload', 'sonoai' ),
                'count'   => $stats['pdf'],
                'desc'    => __( 'Upload PDF documents to extract and add their content to the knowledge base.', 'sonoai' ),
                'bullets' => [
                    __( 'Extract text from PDFs', 'sonoai' ),
                    __( 'Support for multi-page docs', 'sonoai' ),
                    __( 'OCR text recognition', 'sonoai' ),
                ],
                'use'   => __( 'Best for: research, reports, protocols, schemograms.', 'sonoai' ),
                'tab'   => 'pdf',
                'cta'   => __( 'Add Content', 'sonoai' ),
            ],
            'url'   => [
                'icon'    => '🌐',
                'color'   => '#0ea5e9',
                'title'   => __( 'Website URL', 'sonoai' ),
                'count'   => $stats['url'],
                'desc'    => __( 'Crawl any public website URL to extract and add text content to the knowledge base.', 'sonoai' ),
                'bullets' => [
                    __( 'Crawl public web pages', 'sonoai' ),
                    __( 'Extract main content', 'sonoai' ),
                    __( 'Filter presentation elements', 'sonoai' ),
                ],
                'use'   => __( 'Best for: external docs, competitor analysis, research.', 'sonoai' ),
                'tab'   => 'url',
                'cta'   => __( 'Add Content', 'sonoai' ),
            ],
            'txt'   => [
                'icon'    => '✏️',
                'color'   => '#f59e0b',
                'title'   => __( 'Custom Text', 'sonoai' ),
                'count'   => $stats['txt'],
                'desc'    => __( 'Write or paste any custom text, with images, for complete control over your knowledge base.', 'sonoai' ),
                'bullets' => [
                    __( 'Add any custom content', 'sonoai' ),
                    __( 'FAQ responses', 'sonoai' ),
                    __( 'Business-specific info + images', 'sonoai' ),
                ],
                'use'   => __( 'Best for: FAQs, custom responses, unique data.', 'sonoai' ),
                'tab'   => 'txt',
                'cta'   => __( 'Add Content', 'sonoai' ),
            ],
            'media' => [
                'icon'    => '🖼',
                'color'   => '#8b5cf6',
                'title'   => __( 'Media', 'sonoai' ),
                'count'   => 0,
                'desc'    => __( 'Connect your WordPress media library to enrich AI responses with images.', 'sonoai' ),
                'bullets' => [
                    __( 'WordPress media library', 'sonoai' ),
                    __( 'Image context in answers', 'sonoai' ),
                    __( 'Sonogram & scan references', 'sonoai' ),
                ],
                'use'   => __( 'Best for: visual content referencing.', 'sonoai' ),
                'tab'   => 'media',
                'cta'   => null,
                'coming_soon' => true,
            ],
        ];
        ?>
        <div class="kb-overview-header">
            <h2 class="kb-section-title">🔍 <?php esc_html_e( 'Knowledge Sources', 'sonoai' ); ?></h2>
            <p class="kb-section-desc"><?php esc_html_e( 'Choose how you want to add content to your knowledge base. Each source type is optimised for different use cases.', 'sonoai' ); ?></p>
        </div>
        <div class="kb-source-grid">
            <?php foreach ( $sources as $src ) : ?>
            <div class="kb-source-card <?php echo ! empty( $src['coming_soon'] ) ? 'kb-source-card--coming-soon' : ''; ?>">
                <div class="kb-source-card-header">
                    <div class="kb-source-icon" style="background:<?php echo esc_attr( $src['color'] . '18' ); ?>; border-color:<?php echo esc_attr( $src['color'] . '44' ); ?>">
                        <?php echo $src['icon']; ?>
                    </div>
                    <div class="kb-source-meta">
                        <strong class="kb-source-name"><?php echo esc_html( $src['title'] ); ?></strong>
                        <span class="kb-source-count"><?php echo esc_html( number_format_i18n( $src['count'] ) ); ?> <?php esc_html_e( 'items', 'sonoai' ); ?></span>
                    </div>
                    <?php if ( ! empty( $src['coming_soon'] ) ) : ?>
                        <span class="kb-coming-soon-pill"><?php esc_html_e( 'Coming Soon', 'sonoai' ); ?></span>
                    <?php endif; ?>
                </div>
                <p class="kb-source-desc"><?php echo esc_html( $src['desc'] ); ?></p>
                <ul class="kb-source-bullets">
                    <?php foreach ( $src['bullets'] as $bullet ) : ?>
                        <li>✅ <?php echo esc_html( $bullet ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="kb-source-use"><em><?php echo esc_html( $src['use'] ); ?></em></p>
                <?php if ( $src['cta'] ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'sonoai-kb', 'kb_tab' => $src['tab'] ], admin_url( 'admin.php' ) ) ); ?>"
                       class="kb-source-link">
                        <?php echo esc_html( $src['cta'] ); ?> →
                    </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: WordPress Posts
    // ─────────────────────────────────────────────────────────────────────────

    private function render_wp_tab( string $current_pt ): void {
        $post_types = self::get_eligible_post_types();
        if ( ! $current_pt || ! in_array( $current_pt, array_column( $post_types, 'name' ), true ) ) {
            $current_pt = $post_types[0]['name'] ?? 'post';
        }
        ?>
        <!-- Post type filter pills -->
        <div class="kb-pt-filters">
            <?php foreach ( $post_types as $pt ) :
                $url = add_query_arg( [ 'page' => 'sonoai-kb', 'kb_tab' => 'wp', 'pt' => $pt['name'] ], admin_url( 'admin.php' ) );
            ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="kb-pt-pill <?php echo $current_pt === $pt['name'] ? 'active' : ''; ?>">
                    <?php echo esc_html( $pt['label'] ); ?>
                    <span class="kb-pt-pill-count"><?php echo esc_html( $pt['count'] ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- KB Status Filters -->
        <div class="kb-status-filters" id="kb-wp-status-filters">
            <button type="button" class="kb-status-btn active" data-filter="all">
                <strong><?php esc_html_e( 'All', 'sonoai' ); ?></strong> <span class="kb-status-count" id="kb-count-all">0</span>
            </button>
            <button type="button" class="kb-status-btn kb-status-added" data-filter="added">
                <span class="kb-status-icon">✓</span> <?php esc_html_e( 'Added', 'sonoai' ); ?> <span class="kb-status-count" id="kb-count-added">0</span>
            </button>
            <button type="button" class="kb-status-btn kb-status-not-added" data-filter="not_added">
                <span class="kb-status-icon">✗</span> <?php esc_html_e( 'Not Added', 'sonoai' ); ?> <span class="kb-status-count" id="kb-count-not_added">0</span>
            </button>
            <button type="button" class="kb-status-btn kb-status-update" data-filter="update">
                <span class="kb-status-icon">⚠️</span> <?php esc_html_e( 'Requires Update', 'sonoai' ); ?> <span class="kb-status-count" id="kb-count-update">0</span>
            </button>
        </div>

        <!-- Bulk action bar -->
        <div class="kb-list-bar">
            <div class="kb-bulk">
                <select id="kb-wp-bulk-action" class="kb-select-sm">
                    <option value=""><?php esc_html_e( '— Bulk Action —', 'sonoai' ); ?></option>
                    <option value="add"><?php esc_html_e( 'Add to KB', 'sonoai' ); ?></option>
                    <option value="remove"><?php esc_html_e( 'Remove from KB', 'sonoai' ); ?></option>
                </select>
                <select id="kb-wp-mode" class="kb-select-sm" style="margin-left: 8px;">
                    <option value="guideline"><?php esc_html_e( 'Guideline', 'sonoai' ); ?></option>
                    <option value="research"><?php esc_html_e( 'Research', 'sonoai' ); ?></option>
                </select>
                <select id="kb-wp-topic" class="kb-select-sm" style="margin-left: 8px;">
                    <option value=""><?php esc_html_e( '— No Topic —', 'sonoai' ); ?></option>
                    <?php foreach ( self::get_topics() as $t ) : ?>
                        <option value="<?php echo esc_attr( $t->id ); ?>"><?php echo esc_html( $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="kb-wp-bulk-apply" class="kb-btn-sm" style="margin-left: 8px;"><?php esc_html_e( 'Apply', 'sonoai' ); ?></button>
            </div>
            <div class="kb-search-wrap" style="display: flex; gap: 8px;">
                <select id="kb-wp-filter-mode" class="kb-select-sm">
                    <option value=""><?php esc_html_e( '— All Modes —', 'sonoai' ); ?></option>
                    <option value="guideline"><?php esc_html_e( 'Guideline', 'sonoai' ); ?></option>
                    <option value="research"><?php esc_html_e( 'Research', 'sonoai' ); ?></option>
                </select>
                <select id="kb-wp-filter-topic" class="kb-select-sm">
                    <option value=""><?php esc_html_e( '— All Topics —', 'sonoai' ); ?></option>
                    <?php foreach ( self::get_topics() as $t ) : ?>
                        <option value="<?php echo esc_attr( $t->id ); ?>"><?php echo esc_html( $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" id="kb-wp-search" placeholder="<?php esc_attr_e( 'Search posts…', 'sonoai' ); ?>" class="kb-search-input">
            </div>
        </div>

        <!-- Table -->
        <div id="kb-wp-table-wrap">
            <table class="kb-table" id="kb-wp-table">
                <thead>
                    <tr>
                        <th class="kb-col-cb"><input type="checkbox" id="kb-wp-check-all"></th>
                        <th><?php esc_html_e( 'Title', 'sonoai' ); ?></th>
                        <th><?php esc_html_e( 'Last Modified', 'sonoai' ); ?></th>
                        <th><?php esc_html_e( 'Added to KB', 'sonoai' ); ?></th>
                        <th><?php esc_html_e( 'KB Status', 'sonoai' ); ?></th>
                        <th><?php esc_html_e( 'Mode', 'sonoai' ); ?></th>
                        <th><?php esc_html_e( 'Topic', 'sonoai' ); ?></th>
                        <th><?php esc_html_e( 'AI Model', 'sonoai' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'sonoai' ); ?></th>
                    </tr>
                </thead>
                <tbody id="kb-wp-tbody">
                    <tr class="kb-loading-row">
                        <td colspan="9"><span class="kb-spinner"></span> <?php esc_html_e( 'Loading posts…', 'sonoai' ); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="kb-pagination" id="kb-wp-pagination"></div>
            
            <!-- Quick Edit Modal -->
            <div id="kb-quick-edit-modal" class="kb-modal" style="display:none;">
                <div class="kb-modal-content">
                    <div class="kb-modal-header">
                        <h3><?php esc_html_e( 'Quick Edit KB Item', 'sonoai' ); ?></h3>
                        <span class="kb-modal-close" id="qe-close-btn">&times;</span>
                    </div>
                    <div class="kb-modal-body">
                        <input type="hidden" id="qe-post-id" value="">
                        <div class="kb-form-row">
                            <label><?php esc_html_e('Mode', 'sonoai'); ?></label>
                            <select id="qe-mode">
                                <option value="guideline"><?php esc_html_e('Guideline', 'sonoai'); ?></option>
                                <option value="research"><?php esc_html_e('Research', 'sonoai'); ?></option>
                            </select>
                        </div>
                        <div class="kb-form-row">
                            <label><?php esc_html_e('Topic', 'sonoai'); ?></label>
                            <select id="qe-topic">
                                <option value="0"><?php esc_html_e('— None —', 'sonoai'); ?></option>
                                <?php foreach ( self::get_topics() as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t->id ); ?>"><?php echo esc_html( $t->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="kb-modal-footer" style="margin-top:20px; text-align:right;">
                        <button type="button" id="qe-save-btn" class="kb-btn"><?php esc_html_e('Save Changes', 'sonoai'); ?></button>
                    </div>
                </div>
            </div>
            
        </div>
        <input type="hidden" id="kb-current-pt" value="<?php echo esc_attr( $current_pt ); ?>">
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: PDF Upload
    // ─────────────────────────────────────────────────────────────────────────

    private function render_pdf_tab(): void {
        global $wpdb;
        $tbl   = $wpdb->prefix . 'sonoai_kb_items';
        $items = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `$tbl` WHERE `type` = %s ORDER BY `created_at` DESC", 'pdf' )
        );
        ?>
        <div class="kb-card">
            <h3 class="kb-card-title">📄 <?php esc_html_e( 'Upload PDF', 'sonoai' ); ?></h3>
            <form id="kb-pdf-form" enctype="multipart/form-data">
                <div class="kb-training-fields">
                    <div class="kb-field-row">
                        <div class="kb-field-group">
                            <label for="kb-pdf-mode"><?php esc_html_e( 'Training Mode', 'sonoai' ); ?></label>
                            <select name="mode" id="kb-pdf-mode" class="kb-select-sm" required>
                                <option value="guideline"><?php esc_html_e( 'Guideline', 'sonoai' ); ?></option>
                                <option value="research"><?php esc_html_e( 'Research', 'sonoai' ); ?></option>
                            </select>
                        </div>
                        <div class="kb-field-group kb-field-topic">
                            <label for="kb-pdf-topic"><?php esc_html_e( 'Topic', 'sonoai' ); ?></label>
                            <select name="topic_id" id="kb-pdf-topic" class="kb-select-sm">
                                <option value=""><?php esc_html_e( '— No Topic —', 'sonoai' ); ?></option>
                                <?php foreach ( self::get_topics() as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t->id ); ?>"><?php echo esc_html( $t->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="kb-field-group kb-field-country">
                            <label for="kb-pdf-country"><?php esc_html_e( 'Country', 'sonoai' ); ?></label>
                            <input type="text" name="country" id="kb-pdf-country" class="kb-input-sm" placeholder="<?php esc_attr_e( 'e.g. UK, USA', 'sonoai' ); ?>">
                        </div>
                    </div>
                    <div class="kb-field-row">
                        <div class="kb-field-group">
                            <label for="kb-pdf-source-name"><?php esc_html_e( 'Source Name', 'sonoai' ); ?></label>
                            <input type="text" name="source_name" id="kb-pdf-source-name" class="kb-input-sm" placeholder="<?php esc_attr_e( 'e.g. Fetal Care Protocol', 'sonoai' ); ?>">
                        </div>
                        <div class="kb-field-group">
                            <label for="kb-pdf-source-url"><?php esc_html_e( 'Source URL', 'sonoai' ); ?></label>
                            <input type="url" name="source_url" id="kb-pdf-source-url" class="kb-input-sm" placeholder="https://...">
                        </div>
                    </div>
                </div>
                <div class="kb-upload-row">
                    <label class="kb-file-btn" for="kb-pdf-file">
                        <?php esc_html_e( 'Choose File', 'sonoai' ); ?>
                    </label>
                    <input type="file" id="kb-pdf-file" name="pdf_file" accept=".pdf" style="display:none;">
                    <span id="kb-pdf-filename" class="kb-filename-hint"><?php esc_html_e( 'No file chosen', 'sonoai' ); ?></span>
                    <button type="submit" class="kb-btn-primary" id="kb-pdf-submit" disabled>
                        <?php esc_html_e( 'Submit', 'sonoai' ); ?>
                    </button>
                </div>
            </form>
            <p id="kb-pdf-notice" class="kb-notice" style="display:none;"></p>
        </div>

        <?php $this->render_items_table( $items, 'pdf', [
            'delete' => __( 'Delete', 'sonoai' ),
            'view'   => __( 'View PDF', 'sonoai' ),
        ] ); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: Website URL
    // ─────────────────────────────────────────────────────────────────────────

    private function render_url_tab(): void {
        global $wpdb;
        $tbl   = $wpdb->prefix . 'sonoai_kb_items';
        $items = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `$tbl` WHERE `type` = %s ORDER BY `created_at` DESC", 'url' )
        );
        ?>
        <div class="kb-card">
            <h3 class="kb-card-title">🌐 <?php esc_html_e( 'Enter Website URL', 'sonoai' ); ?></h3>
            <form id="kb-url-form">
                <div class="kb-training-fields">
                    <div class="kb-field-row">
                        <div class="kb-field-group">
                            <label for="kb-url-mode"><?php esc_html_e( 'Training Mode', 'sonoai' ); ?></label>
                            <select name="mode" id="kb-url-mode" class="kb-select-sm" required>
                                <option value="guideline"><?php esc_html_e( 'Guideline', 'sonoai' ); ?></option>
                                <option value="research"><?php esc_html_e( 'Research', 'sonoai' ); ?></option>
                            </select>
                        </div>
                        <div class="kb-field-group kb-field-topic">
                            <label for="kb-url-topic"><?php esc_html_e( 'Topic', 'sonoai' ); ?></label>
                            <select name="topic_id" id="kb-url-topic" class="kb-select-sm">
                                <option value=""><?php esc_html_e( '— No Topic —', 'sonoai' ); ?></option>
                                <?php foreach ( self::get_topics() as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t->id ); ?>"><?php echo esc_html( $t->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="kb-field-group kb-field-country">
                            <label for="kb-url-country"><?php esc_html_e( 'Country', 'sonoai' ); ?></label>
                            <input type="text" name="country" id="kb-url-country" class="kb-input-sm" placeholder="<?php esc_attr_e( 'e.g. UK, USA', 'sonoai' ); ?>">
                        </div>
                    </div>
                    <div class="kb-field-row">
                        <div class="kb-field-group">
                            <label for="kb-url-source-name"><?php esc_html_e( 'Source Name', 'sonoai' ); ?></label>
                            <input type="text" name="source_name" id="kb-url-source-name" class="kb-input-sm" placeholder="<?php esc_attr_e( 'e.g. Fetal Care Protocol', 'sonoai' ); ?>">
                        </div>
                        <div class="kb-field-group">
                            <label for="kb-url-source-url"><?php esc_html_e( 'Source URL', 'sonoai' ); ?></label>
                            <input type="url" name="source_url" id="kb-url-source-url" class="kb-input-sm" placeholder="https://...">
                        </div>
                    </div>
                </div>
                <div class="kb-upload-row">
                    <input type="url" id="kb-url-input" class="kb-url-input"
                           placeholder="https://example.com/article" required>
                    <button type="submit" class="kb-btn-primary" id="kb-url-submit">
                        <span class="kb-btn-text"><?php esc_html_e( 'Fetch &amp; Add', 'sonoai' ); ?></span>
                        <span class="kb-spinner" style="display:none;"></span>
                    </button>
                </div>
            </form>
            <p id="kb-url-notice" class="kb-notice" style="display:none;"></p>
        </div>

        <?php $this->render_items_table( $items, 'url', [
            'delete' => __( 'Delete', 'sonoai' ),
            'view'   => __( 'View Source', 'sonoai' ),
        ] ); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: Custom Text
    // ─────────────────────────────────────────────────────────────────────────

    private function render_txt_tab( ?object $editing = null ): void {
        global $wpdb;
        $tbl   = $wpdb->prefix . 'sonoai_kb_items';
        $items = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `$tbl` WHERE `type` = %s ORDER BY `created_at` DESC", 'txt' )
        );

        // Check if we're editing an existing item.
        $edit_id      = sanitize_key( $_GET['edit_item'] ?? '' );
        $editing_item = null;
        if ( $edit_id ) {
            $editing_item = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `$tbl` WHERE `knowledge_id` = %s AND `type` = 'txt'",
                $edit_id
            ) );
        }

        $editor_content = $editing_item ? ( $editing_item->raw_content ?? '' ) : '';
        $form_action    = $editing_item ? 'edit' : 'add';
        ?>
        <div class="kb-card" id="kb-txt-form-wrap">
            <h3 class="kb-card-title">
                ✏️ <?php echo $editing_item
                    ? esc_html__( 'Edit Custom Text', 'sonoai' )
                    : esc_html__( 'Custom Text', 'sonoai' ); ?>
            </h3>
            <?php if ( $editing_item ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'sonoai-kb', 'kb_tab' => 'txt' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="kb-cancel-edit-link">← <?php esc_html_e( 'Cancel edit', 'sonoai' ); ?></a>
            <?php endif; ?>

            <form id="kb-txt-form">
                <div class="kb-training-fields">
                    <div class="kb-field-row">
                        <div class="kb-field-group">
                            <label for="kb-txt-mode"><?php esc_html_e( 'Training Mode', 'sonoai' ); ?></label>
                            <select name="mode" id="kb-txt-mode" class="kb-select-sm" required>
                                <option value="guideline" <?php selected( $editing_item->mode ?? 'guideline', 'guideline' ); ?>><?php esc_html_e( 'Guideline', 'sonoai' ); ?></option>
                                <option value="research" <?php selected( $editing_item->mode ?? 'guideline', 'research' ); ?>><?php esc_html_e( 'Research', 'sonoai' ); ?></option>
                            </select>
                        </div>
                        <div class="kb-field-group kb-field-topic" style="display:none;">
                            <label for="kb-txt-topic"><?php esc_html_e( 'Topic', 'sonoai' ); ?></label>
                            <select name="topic_id" id="kb-txt-topic" class="kb-select-sm">
                                <option value=""><?php esc_html_e( '— No Topic —', 'sonoai' ); ?></option>
                                <?php foreach ( self::get_topics() as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $editing_item->topic_id ?? null, $t->id ); ?>><?php echo esc_html( $t->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="kb-field-group kb-field-country">
                            <label for="kb-txt-country"><?php esc_html_e( 'Country', 'sonoai' ); ?></label>
                            <input type="text" name="country" id="kb-txt-country" class="kb-input-sm" 
                                   value="<?php echo esc_attr( $editing_item->country ?? '' ); ?>"
                                   placeholder="<?php esc_attr_e( 'e.g. UK, USA', 'sonoai' ); ?>">
                        </div>
                    </div>
                    <div class="kb-field-row">
                        <div class="kb-field-group">
                            <label for="kb-txt-source-name"><?php esc_html_e( 'Source Name', 'sonoai' ); ?></label>
                            <input type="text" name="source_name" id="kb-txt-source-name" class="kb-input-sm" 
                                   value="<?php echo esc_attr( $editing_item->source_title ?? '' ); ?>"
                                   placeholder="<?php esc_attr_e( 'e.g. Fetal Care Protocol', 'sonoai' ); ?>">
                        </div>
                        <div class="kb-field-group">
                            <label for="kb-txt-source-url"><?php esc_html_e( 'Source URL', 'sonoai' ); ?></label>
                            <input type="url" name="source_url" id="kb-txt-source-url" class="kb-input-sm" 
                                   value="<?php echo esc_attr( $editing_item->source_url ?? '' ); ?>"
                                   placeholder="https://...">
                        </div>
                    </div>
                </div>

                <div id="kb-txt-editor-wrap">
                <div id="kb-txt-editor-wrap">
                    <label for="sonoai_kb_txt_editor" style="display:block; margin-bottom:10px; font-weight:600; font-size:14px;"><?php esc_html_e( 'Training Content (Notes/Guidelines)', 'sonoai' ); ?></label>
                    <textarea name="sonoai_kb_txt_content" id="sonoai_kb_txt_editor" rows="12" class="large-text" placeholder="<?php esc_attr_e( 'Enter the medical notes or clinical guideline text here...', 'sonoai' ); ?>" style="font-family: inherit; line-height: 1.6; padding: 18px; border-radius: 10px; border: 1px solid var(--kb-border); background: var(--kb-surface-2); color: var(--kb-text); width: 100%; border-color: rgba(255,255,255,0.08);"><?php echo esc_textarea( $editor_content ); ?></textarea>
                </div>

                <div class="kb-txt-images-section" style="margin-top:30px; padding-top:25px; border-top:1px solid var(--kb-border);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
                        <div>
                            <h4 style="margin:0 0 5px 0; font-size:15px; color:var(--kb-text);"><?php esc_html_e( 'Linked Clinical Images', 'sonoai' ); ?></h4>
                            <p class="description" style="margin:0; font-size:12px; opacity:0.8;"><?php esc_html_e( 'Manually label sonograms or reference visuals for the AI to cite in responses.', 'sonoai' ); ?></p>
                        </div>
                        <button type="button" id="kb-add-image-row" class="kb-btn-sm" style="background:#4a90e21a; color:#4a90e2; border:1px solid #4a90e233;">
                            + <?php esc_html_e( 'Add Image', 'sonoai' ); ?>
                        </button>
                    </div>
                    
                    <div id="kb-txt-images-container">
                        <?php 
                        $images = ! empty( $editing_item->image_urls ) ? json_decode( $editing_item->image_urls, true ) : [];
                        if ( ! is_array( $images ) ) $images = [];
                        
                        foreach ( $images as $idx => $img ) : ?>
                            <div class="kb-image-row" style="display:flex; gap:12px; margin-bottom:12px; align-items: flex-end; background:var(--kb-surface-2); padding:12px; border-radius:8px; border:1px solid var(--kb-border); border-color:rgba(255,255,255,0.05);">
                                <div style="flex:1.5;">
                                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; opacity:0.6; display:block; margin-bottom:5px;"><?php esc_html_e( 'Sonogram Reference', 'sonoai' ); ?></label>
                                    <div class="kb-img-upload-wrap" style="display:flex; gap:10px; align-items:center;">
                                        <div class="kb-img-preview" style="width:40px; height:40px; background:var(--kb-surface-1); border-radius:4px; border:1px solid var(--kb-border); overflow:hidden; display:flex; align-items:center; justify-content:center;">
                                            <?php if ( ! empty( $img['url'] ) ) : ?>
                                                <img src="<?php echo esc_url( $img['url'] ); ?>" style="width:100%; height:100%; object-fit:cover;">
                                            <?php else : ?>
                                                <span style="font-size:16px; opacity:0.3;">🖼</span>
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" class="kb-img-url" value="<?php echo esc_url( $img['url'] ?? '' ); ?>">
                                        <button type="button" class="kb-btn-sm kb-choose-img-btn" style="flex:1; justify-content:center; background:rgba(255,255,255,0.05);">
                                            <?php echo ! empty( $img['url'] ) ? esc_html__( 'Change Image', 'sonoai' ) : esc_html__( 'Upload Sonogram', 'sonoai' ); ?>
                                        </button>
                                        <input type="file" class="kb-file-input" accept="image/*" style="display:none;">
                                    </div>
                                </div>
                                <div style="flex:1;">
                                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; opacity:0.6; display:block; margin-bottom:5px;"><?php esc_html_e( 'Clinical Label', 'sonoai' ); ?></label>
                                    <input type="text" class="kb-img-label kb-input-sm" value="<?php echo esc_attr( $img['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Gallbladder with Sludge', 'sonoai' ); ?>" style="width:100%;">
                                </div>
                                <button type="button" class="kb-btn-sm kb-remove-img-row" style="height:36px; padding:0 12px; color:#ef4444; background:rgba(239,68,68,0.08); border-color:rgba(239,68,68,0.15);">
                                    🗑
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                </div>

                <?php if ( $editing_item ) : ?>
                    <input type="hidden" id="kb-edit-knowledge-id" value="<?php echo esc_attr( $editing_item->knowledge_id ); ?>">
                <?php endif; ?>

                <div class="kb-txt-footer">
                    <button type="button" id="kb-txt-submit" class="kb-btn-primary" data-action="<?php echo esc_attr( $form_action ); ?>">
                        <span class="kb-btn-text">
                            <?php echo $editing_item
                                ? esc_html__( 'Update Knowledge Base', 'sonoai' )
                                : esc_html__( 'Add to Knowledge Base', 'sonoai' ); ?>
                        </span>
                        <span class="kb-spinner" style="display:none;"></span>
                    </button>
                </div>
                <p id="kb-txt-notice" class="kb-notice" style="display:none;"></p>
            </form>
        </div>
        </div>

        <?php
        // Render items table with 3 actions (Edit, View, Delete).
        ?>
        <div class="kb-list-bar">
            <div class="kb-bulk">
                <select class="kb-select-sm">
                    <option value=""><?php esc_html_e( '— Bulk Action —', 'sonoai' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete', 'sonoai' ); ?></option>
                </select>
                <button type="button" class="kb-btn-sm"><?php esc_html_e( 'Apply', 'sonoai' ); ?></button>
            </div>
            <span class="kb-item-count"><?php printf( esc_html( _n( '%d item', '%d items', count( $items ), 'sonoai' ) ), count( $items ) ); ?></span>
        </div>

        <table class="kb-table">
            <thead>
                <tr>
                    <th class="kb-col-cb"><input type="checkbox"></th>
                    <th><?php esc_html_e( 'Content', 'sonoai' ); ?></th>
                    <th><?php esc_html_e( 'AI Model', 'sonoai' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'sonoai' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $items ) ) : ?>
                    <tr><td colspan="4" class="kb-empty"><?php esc_html_e( 'No items found.', 'sonoai' ); ?></td></tr>
                <?php else : ?>
                    <?php
                    $topic_map = [];
                    foreach ( self::get_topics() as $t ) {
                        $topic_map[ $t->id ] = $t->name;
                    }
                    ?>
                    <?php foreach ( $items as $item ) :
                        $plain   = wp_strip_all_tags( $item->raw_content ?? '' );
                        $preview = wp_trim_words( $plain, 20, '…' );
                        $edit_url = add_query_arg( [
                            'page'      => 'sonoai-kb',
                            'kb_tab'    => 'txt',
                            'edit_item' => $item->knowledge_id,
                        ], admin_url( 'admin.php' ) );
                        $mode_label = ucfirst( $item->mode ?? 'guideline' );
                        $topic_name = $item->topic_id && isset( $topic_map[ $item->topic_id ] ) ? $topic_map[ $item->topic_id ] : '—';
                    ?>
                    <tr data-knowledge-id="<?php echo esc_attr( $item->knowledge_id ); ?>">
                        <td><input type="checkbox" value="<?php echo esc_attr( $item->knowledge_id ); ?>"></td>
                        <td class="kb-col-content">
                            <span class="kb-content-preview"><?php echo esc_html( $preview ); ?></span>
                            <div style="font-size: 0.85em; color: #666; margin-top: 4px;">
                                <strong><?php esc_html_e( 'Mode:', 'sonoai' ); ?></strong> <?php echo esc_html( $mode_label ); ?> |
                                <strong><?php esc_html_e( 'Topic:', 'sonoai' ); ?></strong> <?php echo esc_html( $topic_name ); ?>
                            </div>
                        </td>
                        <td class="kb-col-model">
                            <span class="kb-badge-model"><?php echo esc_html( $item->provider . ' / ' . ( $item->embedding_model ?? '—' ) ); ?></span>
                        </td>
                        <td class="kb-col-actions">
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="kb-action-link">✏️ <?php esc_html_e( 'Edit', 'sonoai' ); ?></a>
                            <button type="button" class="kb-action-link kb-view-txt-btn"
                                    data-content="<?php echo esc_attr( $item->raw_content ?? '' ); ?>">
                                👁 <?php esc_html_e( 'View', 'sonoai' ); ?>
                            </button>
                            <button type="button" class="kb-action-link kb-delete-btn"
                                    data-knowledge-id="<?php echo esc_attr( $item->knowledge_id ); ?>">
                                🗑 <?php esc_html_e( 'Delete', 'sonoai' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- View modal for full content -->
        <div id="kb-view-modal" class="kb-modal" style="display:none;">
            <div class="kb-modal-inner">
                <div class="kb-modal-header">
                    <strong><?php esc_html_e( 'Full Content', 'sonoai' ); ?></strong>
                    <button type="button" class="kb-modal-close">✕</button>
                </div>
                <div id="kb-modal-body" class="kb-modal-body"></div>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: Media (Coming Soon)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_media_tab(): void {
        ?>
        <div class="kb-coming-soon-panel">
            <div class="kb-coming-soon-icon">🖼</div>
            <h2><?php esc_html_e( 'Media Library Integration', 'sonoai' ); ?></h2>
            <p><?php esc_html_e( 'Connect your WordPress media library to enrich AI responses with images, sonograms, and scans. This feature is coming soon.', 'sonoai' ); ?></p>
            <span class="kb-coming-soon-pill kb-coming-soon-pill--lg"><?php esc_html_e( 'Coming Soon', 'sonoai' ); ?></span>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared: PDF / URL items table
    // ─────────────────────────────────────────────────────────────────────────

    private function render_items_table( array $items, string $type, array $actions ): void {
        $has_view   = isset( $actions['view'] );
        $has_delete = isset( $actions['delete'] );
        ?>
        <div class="kb-list-bar">
            <div class="kb-bulk">
                <select class="kb-select-sm">
                    <option value=""><?php esc_html_e( '— Bulk Action —', 'sonoai' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete', 'sonoai' ); ?></option>
                </select>
                <button type="button" class="kb-btn-sm"><?php esc_html_e( 'Apply', 'sonoai' ); ?></button>
            </div>
            <span class="kb-item-count"><?php printf( esc_html( _n( '%d item', '%d items', count( $items ), 'sonoai' ) ), count( $items ) ); ?></span>
        </div>
        <table class="kb-table">
            <thead>
                <tr>
                    <th class="kb-col-cb"><input type="checkbox"></th>
                    <th><?php echo $type === 'pdf' ? esc_html__( 'File', 'sonoai' ) : esc_html__( 'URL', 'sonoai' ); ?></th>
                    <th><?php esc_html_e( 'AI Model', 'sonoai' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'sonoai' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $items ) ) : ?>
                    <tr><td colspan="4" class="kb-empty"><?php esc_html_e( 'No items found.', 'sonoai' ); ?></td></tr>
                <?php else : ?>
                    <?php
                    $topic_map = [];
                    foreach ( self::get_topics() as $t ) {
                        $topic_map[ $t->id ] = $t->name;
                    }
                    ?>
                    <?php foreach ( $items as $item ) :
                        $mode_label = ucfirst( $item->mode ?? 'guideline' );
                        $topic_name = $item->topic_id && isset( $topic_map[ $item->topic_id ] ) ? $topic_map[ $item->topic_id ] : '—';
                    ?>
                    <tr data-knowledge-id="<?php echo esc_attr( $item->knowledge_id ); ?>">
                        <td><input type="checkbox" value="<?php echo esc_attr( $item->knowledge_id ); ?>"></td>
                        <td class="kb-col-source">
                            <?php if ( $item->source_url ) : ?>
                                <a href="<?php echo esc_url( $item->source_url ); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html( $item->source_title ?: $item->source_url ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $item->source_title ?: '—' ); ?>
                            <?php endif; ?>
                            <div style="font-size: 0.85em; color: #666; margin-top: 4px;">
                                <strong><?php esc_html_e( 'Mode:', 'sonoai' ); ?></strong> <?php echo esc_html( $mode_label ); ?> |
                                <strong><?php esc_html_e( 'Topic:', 'sonoai' ); ?></strong> <?php echo esc_html( $topic_name ); ?>
                            </div>
                        </td>
                        <td class="kb-col-model">
                            <span class="kb-badge-model"><?php echo esc_html( $item->provider . ' / ' . ( $item->embedding_model ?? '—' ) ); ?></span>
                        </td>
                        <td class="kb-col-actions">
                            <?php if ( $has_view && $item->source_url ) : ?>
                                <a href="<?php echo esc_url( $item->source_url ); ?>" target="_blank" rel="noopener"
                                   class="kb-action-link">👁 <?php echo esc_html( $actions['view'] ); ?></a>
                            <?php endif; ?>
                            <?php if ( $has_delete ) : ?>
                                <button type="button" class="kb-action-link kb-delete-btn"
                                        data-knowledge-id="<?php echo esc_attr( $item->knowledge_id ); ?>">
                                    🗑 <?php echo esc_html( $actions['delete'] ); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
