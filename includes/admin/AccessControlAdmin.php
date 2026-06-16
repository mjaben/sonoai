<?php
/**
 * SonoAI — Admin Access Control.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AccessControlAdmin {
    private static ?AccessControlAdmin $instance = null;

    public static function instance(): AccessControlAdmin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_sonoai_save_user_permissions', [ $this, 'handle_save_permissions' ] );
        add_action( 'wp_ajax_sonoai_revoke_user_permissions', [ $this, 'handle_revoke_permissions' ] );
        add_action( 'wp_ajax_sonoai_search_users', [ $this, 'handle_search_users' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'sonoai-access-control' ) === false ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0' );
        wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', [ 'jquery' ], '4.1.0', true );

        // Add Select2 inline script
        wp_add_inline_script( 'select2', '
            jQuery(document).ready(function($) {
                $(".kb-user-select").select2({
                    ajax: {
                        url: ajaxurl,
                        dataType: "json",
                        delay: 250,
                        data: function (params) {
                            return {
                                action: "sonoai_search_users",
                                q: params.term,
                                security: "' . wp_create_nonce( 'sonoai_access_control' ) . '"
                            };
                        },
                        processResults: function (data) {
                            return {
                                results: data.data
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 3,
                    placeholder: "Search for a user by name or email...",
                    allowClear: true
                });

                $("#kb-access-form").on("submit", function(e) {
                    e.preventDefault();
                    var $btn = $(this).find("button[type=submit]");
                    $btn.prop("disabled", true).text("Saving...");
                    
                    $.post(ajaxurl, $(this).serialize(), function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || "Failed to save permissions.");
                            $btn.prop("disabled", false).text("Assign Permissions");
                        }
                    });
                });

                $(".kb-revoke-btn").on("click", function(e) {
                    e.preventDefault();
                    if (!confirm("Are you sure you want to revoke all SonoAI access for this user?")) return;
                    
                    var userId = $(this).data("id");
                    $.post(ajaxurl, {
                        action: "sonoai_revoke_user_permissions",
                        user_id: userId,
                        security: "' . wp_create_nonce( 'sonoai_access_control' ) . '"
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || "Failed to revoke permissions.");
                        }
                    });
                });
            });
        ' );
    }

    public function handle_search_users() {
        check_ajax_referer( 'sonoai_access_control', 'security' );
        if ( ! current_user_can( 'sonoai_manage_access' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $search = sanitize_text_field( $_REQUEST['q'] ?? '' );
        $users = get_users( [
            'search'         => "*{$search}*",
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 20,
        ] );

        $results = [];
        foreach ( $users as $user ) {
            $results[] = [
                'id'   => $user->ID,
                'text' => $user->display_name . ' (' . $user->user_email . ')',
            ];
        }

        wp_send_json_success( $results );
    }

    public function handle_save_permissions() {
        check_ajax_referer( 'sonoai_access_control', 'security' );
        if ( ! current_user_can( 'sonoai_manage_access' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $user_id = intval( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'No user selected.' ] );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json_error( [ 'message' => 'Invalid user.' ] );
        }

        $caps = [
            'sonoai_manage_api',
            'sonoai_manage_kb',
            'sonoai_manage_topics',
            'sonoai_view_feedback',
            'sonoai_view_logs',
        ];

        $assigned = $_POST['caps'] ?? [];
        if ( ! is_array( $assigned ) ) {
            $assigned = [];
        }

        $has_any = false;

        foreach ( $caps as $cap ) {
            if ( in_array( $cap, $assigned, true ) ) {
                $user->add_cap( $cap );
                $has_any = true;
            } else {
                $user->remove_cap( $cap );
            }
        }

        if ( $has_any ) {
            $user->add_cap( 'sonoai_access' );
            // Optionally add read cap so they can access the backend dashboard at minimum
            $user->add_cap( 'read' );
        } else {
            $user->remove_cap( 'sonoai_access' );
        }

        if ( class_exists( 'SonoAI\AuditLogger' ) ) {
            AuditLogger::log( 'update_permissions', 'Updated permissions for user ID ' . $user_id );
        }

        wp_send_json_success( [ 'message' => 'Permissions saved.' ] );
    }

    public function handle_revoke_permissions() {
        check_ajax_referer( 'sonoai_access_control', 'security' );
        if ( ! current_user_can( 'sonoai_manage_access' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $user_id = intval( $_POST['user_id'] ?? 0 );
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json_error( [ 'message' => 'Invalid user.' ] );
        }

        $caps = [
            'sonoai_manage_api',
            'sonoai_manage_kb',
            'sonoai_manage_topics',
            'sonoai_view_feedback',
            'sonoai_view_logs',
            'sonoai_access'
        ];

        foreach ( $caps as $cap ) {
            $user->remove_cap( $cap );
        }

        if ( class_exists( 'SonoAI\AuditLogger' ) ) {
            AuditLogger::log( 'revoke_permissions', 'Revoked all permissions for user ID ' . $user_id );
        }

        wp_send_json_success( [ 'message' => 'Permissions revoked.' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'sonoai-settings',
            __( 'Access Control', 'sonoai' ),
            __( 'Access Control', 'sonoai' ),
            'sonoai_manage_access',
            'sonoai-access-control',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'sonoai_manage_access' ) ) {
            return;
        }

        $available_actions = [
            'sonoai_manage_api'    => __( 'Manage API Config', 'sonoai' ),
            'sonoai_manage_kb'     => __( 'Manage Knowledge Base', 'sonoai' ),
            'sonoai_manage_topics' => __( 'Manage Topics', 'sonoai' ),
            'sonoai_view_feedback' => __( 'View Feedback Analytics', 'sonoai' ),
            'sonoai_view_logs'     => __( 'View & Clear Query Logs', 'sonoai' ),
        ];

        // Find users who have at least sonoai_access
        $users_with_access = get_users( [ 'capability' => 'sonoai_access' ] );

        ?>
        <div class="kb-wrap" id="sonoai-access-control-page">
            
            <div class="kb-header">
                <div class="kb-header-left">
                    <div class="kb-header-icon">🔑</div>
                    <div>
                        <h1 class="kb-title"><?php esc_html_e( 'Access Control', 'sonoai' ); ?></h1>
                        <p class="kb-subtitle"><?php esc_html_e( 'Delegate administrative tasks securely to specific team members.', 'sonoai' ); ?></p>
                    </div>
                </div>
                <div class="kb-header-right">
                    <button type="button" id="kb-theme-toggle" class="kb-theme-btn" title="Toggle dark / light mode">
                        <span class="kb-icon-dark">🌙</span>
                        <span class="kb-icon-light">☀️</span>
                    </button>
                </div>
            </div>

            <!-- Add Permission Form -->
            <div class="kb-card" style="margin-top: 30px;">
                <div class="kb-tab-header">
                    <h2><?php esc_html_e( 'Assign Permissions', 'sonoai' ); ?></h2>
                </div>
                <form id="kb-access-form" style="padding: 20px;">
                    <input type="hidden" name="action" value="sonoai_save_user_permissions">
                    <input type="hidden" name="security" value="<?php echo esc_attr( wp_create_nonce( 'sonoai_access_control' ) ); ?>">
                    
                    <div class="kb-form-grid" style="margin-bottom: 20px;">
                        <label class="kb-label"><?php esc_html_e( 'Select User', 'sonoai' ); ?></label>
                        <div>
                            <select name="user_id" class="kb-user-select" style="width: 100%; max-width: 400px;"></select>
                            <p class="kb-desc" style="margin-top: 5px;"><?php esc_html_e( 'Search by name or email. Minimum 3 characters.', 'sonoai' ); ?></p>
                        </div>
                    </div>

                    <div class="kb-form-grid">
                        <label class="kb-label"><?php esc_html_e( 'Capabilities', 'sonoai' ); ?></label>
                        <div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
                                <?php foreach ( $available_actions as $cap => $label ) : ?>
                                    <label class="kb-checkbox" style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="caps[]" value="<?php echo esc_attr( $cap ); ?>">
                                        <span style="font-size: 13px;"><?php echo esc_html( $label ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="kb-desc" style="margin-top: 10px;">
                                <?php esc_html_e( 'Check the boxes to grant the user access to these specific areas. Any selection will grant basic backend access.', 'sonoai' ); ?>
                            </p>
                        </div>
                    </div>

                    <div class="kb-form-footer" style="margin-top: 20px;">
                        <button type="submit" class="kb-btn kb-btn-primary"><?php esc_html_e( 'Assign Permissions', 'sonoai' ); ?></button>
                    </div>
                </form>
            </div>

            <!-- Current Access List -->
            <div class="kb-panel-wrap" style="border-radius: 12px; margin-top: 30px;">
                <div class="kb-list-bar">
                    <h2 class="kb-section-title" style="margin:0; font-size: 1.1em;">👥 <?php esc_html_e( 'Users with Access', 'sonoai' ); ?></h2>
                </div>
                
                <table class="kb-table">
                    <thead>
                            <tr>
                                <th><?php esc_html_e( 'User', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'Role', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'Permissions Granted', 'sonoai' ); ?></th>
                                <th style="text-align: right;"><?php esc_html_e( 'Actions', 'sonoai' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $users_with_access ) ) : ?>
                                <tr>
                                    <td colspan="4" class="kb-empty"><?php esc_html_e( 'No users have custom access yet.', 'sonoai' ); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $users_with_access as $user ) : 
                                    // Skip the main admin role if desired, or show it with an "All Access" badge.
                                    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
                                        continue;
                                    }
                                    
                                    $user_caps = array_filter( $available_actions, function($cap) use ($user) {
                                        return $user->has_cap( $cap );
                                    }, ARRAY_FILTER_USE_KEY );
                                    
                                    global $wp_roles;
                                    $role_names = array_map( function($r) use ($wp_roles) {
                                        return $wp_roles->role_names[$r] ?? $r;
                                    }, $user->roles );
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $user->display_name ); ?></strong><br>
                                        <span style="color: #666; font-size: 12px;"><?php echo esc_html( $user->user_email ); ?></span>
                                    </td>
                                    <td><?php echo esc_html( implode( ', ', $role_names ) ); ?></td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <?php foreach ( $user_caps as $cap => $label ) : ?>
                                                <span class="kb-badge-model" style="opacity: 0.9;"><?php echo esc_html( $label ); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" class="kb-action-link kb-revoke-btn" style="color: #dc2626;" data-id="<?php echo esc_attr( $user->ID ); ?>">
                                            ✕ <?php esc_html_e( 'Revoke All', 'sonoai' ); ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
            </div>

        </div>
        <?php
    }
}
