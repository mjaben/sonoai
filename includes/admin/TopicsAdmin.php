<?php
/**
 * SonoAI — Topics management sub-menu page.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TopicsAdmin {

    private static ?TopicsAdmin $instance = null;

    public static function instance(): TopicsAdmin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_submenu' ] );
    }

    /**
     * Register the Topics sub-menu.
     */
    public function add_submenu(): void {
        add_submenu_page(
            'sonoai-settings',
            __( 'Topics – SonoAI', 'sonoai' ),
            __( 'Topics', 'sonoai' ),
            'manage_options',
            'sonoai-topics',
            [ $this, 'render' ]
        );
    }

    public function render( bool $is_tab = false ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $topics = Topics::get_all();
        $wrap_class = $is_tab ? '' : 'wrap kb-wrap';
        ?>
        <div class="<?php echo esc_attr( $wrap_class ); ?>">
            <?php if ( ! $is_tab ) : ?>
                <!-- Header -->
                <div class="kb-header">
                    <div class="kb-header-left">
                        <div class="kb-header-icon">🏷️</div>
                        <div>
                            <h1 class="kb-title"><?php esc_html_e( 'Topics Management', 'sonoai' ); ?></h1>
                            <p class="kb-subtitle"><?php esc_html_e( 'Organize and categorize your SonoAI knowledge base content.', 'sonoai' ); ?></p>
                        </div>
                    </div>
                    <div class="kb-header-right">
                        <button type="button" id="kb-theme-toggle" class="kb-theme-btn" title="Toggle dark / light mode">
                            <span class="kb-icon-dark">🌙</span>
                            <span class="kb-icon-light">☀️</span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Content Area -->
            <div class="kb-panel-wrap" style="<?php echo $is_tab ? 'border:none; background:transparent; padding:0;' : ''; ?>">
                <div class="kb-tab-header">
                    <div>
                        <h2><?php esc_html_e( 'All Topics', 'sonoai' ); ?></h2>
                    </div>
                    <div>
                        <button type="button" class="kb-btn kb-btn-secondary" id="kb-btn-sync-topics">
                            <img src="<?php echo esc_url( SONOAI_URL . 'assets/images/sync-icon.png' ); ?>" class="kb-sync-icon" alt="" style="width:14px; height:14px; vertical-align:middle; margin-right:5px;">
                            <?php esc_html_e( 'Sync from WordPress', 'sonoai' ); ?>
                        </button>
                        <button type="button" class="kb-btn kb-btn-primary" id="kb-btn-add-topic" style="margin-left: 10px;">
                            + <?php esc_html_e( 'Add Topic', 'sonoai' ); ?>
                        </button>
                    </div>
                </div>
                <div class="kb-card">
                    <table class="kb-table" id="kb-topics-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" class="kb-select-all-topics"></th>
                                <th><?php esc_html_e( 'Name', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'Slug', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'KB Items', 'sonoai' ); ?></th>
                                <th style="width: 150px; text-align: right;"><?php esc_html_e( 'Actions', 'sonoai' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="kb-topics-tbody">
                            <?php if ( empty( $topics ) ) : ?>
                                <tr>
                                    <td colspan="5" class="kb-empty">
                                        <?php esc_html_e( 'No topics found. Create one to organize your content.', 'sonoai' ); ?>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $topics as $topic ) : ?>
                                <tr data-topic-id="<?php echo esc_attr( $topic['id'] ); ?>">
                                    <td><input type="checkbox" class="kb-topic-checkbox" value="<?php echo esc_attr( $topic['id'] ); ?>"></td>
                                    <td><strong><?php echo esc_html( $topic['name'] ); ?></strong></td>
                                    <td><code><?php echo esc_html( $topic['slug'] ); ?></code></td>
                                    <td><span class="kb-badge-model"><?php echo (int) $topic['item_count']; ?></span></td>
                                    <td class="kb-col-actions" style="text-align: right;">
                                        <button type="button" class="kb-action-link kb-edit-topic-btn" 
                                            data-id="<?php echo esc_attr( $topic['id'] ); ?>"
                                            data-name="<?php echo esc_attr( $topic['name'] ); ?>">
                                            ✏️ <?php esc_html_e( 'Edit', 'sonoai' ); ?>
                                        </button>
                                        <button type="button" class="kb-action-link kb-delete-topic-btn" 
                                            data-id="<?php echo esc_attr( $topic['id'] ); ?>">
                                            🗑 <?php esc_html_e( 'Delete', 'sonoai' ); ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div><!-- .kb-panel-wrap -->

            <!-- Add/Edit Topic Modal (Moved Inside kb-wrap) -->
            <dialog id="kb-topic-modal" class="kb-modal pt-4">
                <div class="kb-modal-content">
                    <h3 id="kb-topic-modal-title"><?php esc_html_e( 'Add Topic', 'sonoai' ); ?></h3>
                    <form id="kb-topic-form">
                        <input type="hidden" id="kb-topic-id" name="topic_id" value="">
                        <div class="kb-form-group">
                            <label for="kb-topic-name"><?php esc_html_e( 'Topic Name', 'sonoai' ); ?></label>
                            <input type="text" id="kb-topic-name" name="name" class="kb-input" required placeholder="e.g. Abdominal Ultrasound">
                        </div>
                        <div class="kb-form-actions">
                            <button type="button" class="kb-btn kb-btn-secondary" id="kb-topic-modal-cancel">
                                <?php esc_html_e( 'Cancel', 'sonoai' ); ?>
                            </button>
                            <button type="submit" class="kb-btn kb-btn-primary">
                                <?php esc_html_e( 'Save Topic', 'sonoai' ); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>

        </div><!-- .kb-wrap -->
        <?php
    }
}
