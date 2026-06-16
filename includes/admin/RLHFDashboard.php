<?php
/**
 * SonoAI — RLHF & KB QA Dashboard admin page.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RLHFDashboard {

    private static ?RLHFDashboard $instance = null;

    public static function instance(): RLHFDashboard {
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
            __( 'RLHF QA – SonoAI', 'sonoai' ),
            __( 'RLHF QA', 'sonoai' ),
            'sonoai_manage_rlhf',
            'sonoai-rlhf',
            [ $this, 'render' ]
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'sonoai-rlhf' ) ) {
            return;
        }

        wp_enqueue_style(
            'sonoai-rlhf',
            SONOAI_URL . 'assets/css/rlhf.css',
            [],
            time()
        );
        wp_enqueue_script(
            'sonoai-rlhf',
            SONOAI_URL . 'assets/js/rlhf.js',
            [ 'jquery' ],
            time(),
            true
        );
        wp_localize_script( 'sonoai-rlhf', 'sonoaiRLHF', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonces'    => [
                'getItems'     => wp_create_nonce( 'sonoai_rlhf_get_items' ),
                'updateStatus' => wp_create_nonce( 'sonoai_rlhf_update_status' ),
                'chatTest'     => wp_create_nonce( 'sonoai_rlhf_chat_test' ),
            ],
            'currentUserId' => get_current_user_id(),
        ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): void {
        if ( ! current_user_can( 'sonoai_manage_rlhf' ) ) {
            return;
        }

        if ( class_exists( 'SonoAI\AuditLogger' ) ) {
            AuditLogger::log( 'view_rlhf_dashboard', 'User viewed the RLHF QA Dashboard.' );
        }
        ?>
        <div class="rlhf-wrap" id="sonoai-rlhf-page">
            <div class="rlhf-header">
                <div class="rlhf-header-left">
                    <div class="rlhf-header-icon">🔬</div>
                    <div>
                        <h1 class="rlhf-title"><?php esc_html_e( 'RLHF QA Dashboard', 'sonoai' ); ?></h1>
                        <p class="rlhf-subtitle"><?php esc_html_e( 'Review and test Knowledge Base items against the live AI model.', 'sonoai' ); ?></p>
                    </div>
                </div>
                <div class="rlhf-header-right">
                    <div class="rlhf-stat-badge" id="rlhf-stat-pending">
                        <span class="rlhf-stat-icon">⏳</span>
                        <span class="count">0</span> <?php esc_html_e( 'Pending', 'sonoai' ); ?>
                    </div>
                    <div class="rlhf-stat-badge rlhf-stat-passed" id="rlhf-stat-passed">
                        <span class="rlhf-stat-icon">✅</span>
                        <span class="count">0</span> <?php esc_html_e( 'Passed', 'sonoai' ); ?>
                    </div>
                </div>
            </div>

            <div class="rlhf-split-screen">
                <!-- Left Panel: Master TO-DO List -->
                <div class="rlhf-panel rlhf-left-panel">
                    <div class="rlhf-panel-header">
                        <h2>📋 <?php esc_html_e( 'Task Queue', 'sonoai' ); ?></h2>
                        <div class="rlhf-filters">
                            <select id="rlhf-filter-type" class="rlhf-select-sm">
                                <option value="all"><?php esc_html_e( 'All Types', 'sonoai' ); ?></option>
                                <option value="wp"><?php esc_html_e( 'WordPress', 'sonoai' ); ?></option>
                                <option value="pdf"><?php esc_html_e( 'PDF', 'sonoai' ); ?></option>
                                <option value="url"><?php esc_html_e( 'URL', 'sonoai' ); ?></option>
                                <option value="txt"><?php esc_html_e( 'Custom Text', 'sonoai' ); ?></option>
                                <option value="jsonl"><?php esc_html_e( 'JSONL', 'sonoai' ); ?></option>
                            </select>
                            <select id="rlhf-filter-status" class="rlhf-select-sm">
                                <option value="pending"><?php esc_html_e( 'Pending (Not Started & Needs Retraining)', 'sonoai' ); ?></option>
                                <option value="Needs Re-training"><?php esc_html_e( 'Needs Re-training', 'sonoai' ); ?></option>
                                <option value="Not Started"><?php esc_html_e( 'Not Started', 'sonoai' ); ?></option>
                                <option value="Passed"><?php esc_html_e( 'Passed (Completed)', 'sonoai' ); ?></option>
                            </select>
                            <button id="rlhf-refresh-btn" class="rlhf-btn-icon" title="Refresh List">🔄</button>
                        </div>
                    </div>
                    <div class="rlhf-task-list" id="rlhf-task-list">
                        <div class="rlhf-loading">
                            <span class="rlhf-spinner"></span> <?php esc_html_e( 'Loading tasks...', 'sonoai' ); ?>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Interactive QA Workspace -->
                <div class="rlhf-panel rlhf-right-panel" id="rlhf-workspace" style="display:none;">
                    <div class="rlhf-workspace-scroll">
                        <input type="hidden" id="rlhf-current-item-id" value="">
                        
                        <!-- 1. Ground Truth Reference -->
                        <div class="rlhf-block rlhf-ground-truth">
                            <h3>🔍 <?php esc_html_e( 'Ground Truth Reference', 'sonoai' ); ?></h3>
                            <div class="rlhf-meta-badges" id="rlhf-meta-badges"></div>
                            <div class="rlhf-content-viewer" id="rlhf-content-viewer"></div>
                        </div>

                        <!-- 2. Live Model Playground -->
                        <div class="rlhf-block rlhf-playground">
                            <h3>🤖 <?php esc_html_e( 'Live Model Playground', 'sonoai' ); ?></h3>
                            <p class="rlhf-desc"><?php esc_html_e( 'Ask the model questions. It will use the ground truth above as context.', 'sonoai' ); ?></p>
                            
                            <div class="rlhf-chat-container">
                                <div class="rlhf-chat-history" id="rlhf-chat-history"></div>
                                <div class="rlhf-chat-input-area">
                                    <textarea id="rlhf-chat-input" placeholder="<?php esc_attr_e( 'Type your test query...', 'sonoai' ); ?>" rows="2"></textarea>
                                    <button type="button" id="rlhf-chat-send" class="rlhf-btn-primary rlhf-btn-sm">
                                        <?php esc_html_e( 'Send', 'sonoai' ); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Status Update Form -->
                        <div class="rlhf-block rlhf-grading">
                            <h3>✅ <?php esc_html_e( 'Review & Grade', 'sonoai' ); ?></h3>
                            <div class="rlhf-form-grid">
                                <div class="rlhf-form-group">
                                    <label><?php esc_html_e( 'Status', 'sonoai' ); ?></label>
                                    <select id="rlhf-grade-status" class="rlhf-select-lg">
                                        <option value="Passed">✅ <?php esc_html_e( 'Passed', 'sonoai' ); ?></option>
                                        <option value="Needs Re-training">❌ <?php esc_html_e( 'Needs Re-training', 'sonoai' ); ?></option>
                                    </select>
                                </div>
                                <div class="rlhf-form-group" id="rlhf-fail-reason-group" style="display:none;">
                                    <label><?php esc_html_e( 'Failure Reason', 'sonoai' ); ?></label>
                                    <select id="rlhf-grade-reason" class="rlhf-select-lg">
                                        <option value="Hallucination"><?php esc_html_e( 'Hallucination (Made up facts)', 'sonoai' ); ?></option>
                                        <option value="Omission"><?php esc_html_e( 'Omission (Missed key details)', 'sonoai' ); ?></option>
                                        <option value="Incorrect Logic"><?php esc_html_e( 'Incorrect Logic / Poor Reasoning', 'sonoai' ); ?></option>
                                        <option value="Formatting"><?php esc_html_e( 'Formatting Issue', 'sonoai' ); ?></option>
                                        <option value="Other"><?php esc_html_e( 'Other', 'sonoai' ); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="rlhf-form-group" style="margin-top: 15px;">
                                <label><?php esc_html_e( 'Reviewer Notes (Optional)', 'sonoai' ); ?></label>
                                <textarea id="rlhf-grade-notes" rows="3" placeholder="<?php esc_attr_e( 'Enter notes for engineers or content writers...', 'sonoai' ); ?>"></textarea>
                            </div>
                            <div class="rlhf-grading-actions">
                                <button type="button" id="rlhf-submit-next" class="rlhf-btn-success rlhf-btn-lg">
                                    <?php esc_html_e( 'Submit & Next ⏭', 'sonoai' ); ?>
                                </button>
                                <span id="rlhf-save-status" style="display:none; margin-left: 10px; color: #10b981; font-weight: 500;">✓ Saved!</span>
                            </div>
                        </div>

                    </div>
                </div>
                
                <div class="rlhf-panel rlhf-right-panel" id="rlhf-empty-state">
                    <div class="rlhf-empty-message">
                        <span class="rlhf-empty-icon">👈</span>
                        <h2><?php esc_html_e( 'Select a Task', 'sonoai' ); ?></h2>
                        <p><?php esc_html_e( 'Click on an item in the queue to begin the QA process.', 'sonoai' ); ?></p>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }
}
