<?php
/**
 * SonoAI — Admin Audit Logs.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AuditLogAdmin {
    private static ?AuditLogAdmin $instance = null;

    public static function instance(): AuditLogAdmin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'sonoai-settings',
            __( 'Audit Log', 'sonoai' ),
            __( 'Audit Log', 'sonoai' ),
            'sonoai_view_logs',
            'sonoai-audit-log',
            [ $this, 'render_page' ]
        );
    }

    private function handle_actions() {
        if ( ! current_user_can( 'sonoai_view_logs' ) ) {
            return;
        }

        if ( isset( $_POST['action'] ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'sonoai_audit_logs';

            if ( 'delete' === $_POST['action'] && ! empty( $_POST['log_id'] ) && is_array( $_POST['log_id'] ) ) {
                $ids = array_map( 'intval', $_POST['log_id'] );
                $ids_str = implode( ',', $ids );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( "DELETE FROM `$table` WHERE id IN ($ids_str)" );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Selected logs deleted.', 'sonoai' ) . '</p></div>';
            } elseif ( 'clear_all_logs' === $_POST['action'] ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( "TRUNCATE TABLE `$table`" );
                if ( class_exists( 'SonoAI\AuditLogger' ) ) {
                    AuditLogger::log( 'clear_all_audit_logs', 'Permanently cleared all audit logs.' );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All audit logs cleared.', 'sonoai' ) . '</p></div>';
            }
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'sonoai_view_logs' ) ) {
            return;
        }

        $this->handle_actions();

        global $wpdb;
        $table = $wpdb->prefix . 'sonoai_audit_logs';

        // Check if table exists yet
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Audit Log', 'sonoai' ); ?></h1>
                <div class="notice notice-warning"><p><?php esc_html_e( 'The Audit Log table does not exist yet. Please deactivate and reactivate the SonoAI plugin to create it.', 'sonoai' ); ?></p></div>
            </div>
            <?php
            return;
        }

        // Pagination setup
        $per_page = 20;
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset = ( $current_page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
        $total_pages = ceil( $total_items / $per_page );

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `$table` ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A );

        ?>
        <div class="kb-wrap" id="sonoai-audit-log-page">
            
            <!-- Hero Header -->
            <div class="kb-header">
                <div class="kb-header-left">
                    <div class="kb-header-icon">🛡️</div>
                    <div>
                        <h1 class="kb-title"><?php esc_html_e( 'System Audit Log', 'sonoai' ); ?></h1>
                        <p class="kb-subtitle"><?php esc_html_e( 'Track administrative actions and security events.', 'sonoai' ); ?></p>
                    </div>
                </div>
                <div class="kb-header-right">
                    <button type="button" id="kb-theme-toggle" class="kb-theme-btn" title="Toggle dark / light mode">
                        <span class="kb-icon-dark">🌙</span>
                        <span class="kb-icon-light">☀️</span>
                    </button>
                </div>
            </div>

            <!-- Panel Wrap -->
            <div class="kb-panel-wrap" style="border-radius: 12px; margin-top: 30px;">
                <form method="post" id="audit-logs-form">
                    
                    <div class="kb-list-bar">
                        <div class="kb-bulk">
                            <select name="action" class="kb-select-sm">
                                <option value=""><?php esc_html_e( '— Bulk Action —', 'sonoai' ); ?></option>
                                <option value="delete"><?php esc_html_e( 'Delete', 'sonoai' ); ?></option>
                            </select>
                            <button type="submit" class="kb-btn-sm"><?php esc_html_e( 'Apply', 'sonoai' ); ?></button>
                            <button type="submit" name="action" value="clear_all_logs" class="kb-btn-sm" style="color:#ef4444; border-color:rgba(239,68,68,0.3); background:rgba(239,68,68,0.1); white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; padding: 0 12px;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete ALL audit logs?', 'sonoai' ); ?>');">
                                🗑 <?php esc_html_e( 'Clear All', 'sonoai' ); ?>
                            </button>
                        </div>

                        <span class="kb-item-count">
                            <?php printf( esc_html( _n( '%d item', '%d items', $total_items, 'sonoai' ) ), $total_items ); ?>
                        </span>
                    </div>

                    <table class="kb-table">
                        <thead>
                            <tr>
                                <th class="kb-col-cb"><input type="checkbox" id="cb-select-all-1"></th>
                                <th><?php esc_html_e( 'Date', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'User', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'Action', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'Details', 'sonoai' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $items ) ) : ?>
                                <tr><td colspan="5" class="kb-empty"><?php esc_html_e( 'No actions logged yet.', 'sonoai' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $items as $item ) : 
                                    $user = get_userdata( $item['user_id'] );
                                    $user_name = $user ? $user->display_name : __( 'Guest / System', 'sonoai' );
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="log_id[]" value="<?php echo esc_attr( $item['id'] ); ?>"></td>
                                    <td style="white-space: nowrap;"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['created_at'] ) ) ); ?></td>
                                    <td><strong><?php echo esc_html( $user_name ); ?></strong></td>
                                    <td><code style="background: rgba(0,0,0,0.05); padding: 3px 6px; border-radius: 4px;"><?php echo esc_html( $item['action'] ); ?></code></td>
                                    <td class="kb-col-content">
                                        <span class="kb-content-preview"><?php echo esc_html( $item['details'] ); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="kb-pagination" style="margin-top: 15px; text-align: right;">
                            <?php
                            echo paginate_links( [
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => __( '&laquo;' ),
                                'next_text' => __( '&raquo;' ),
                                'total'     => $total_pages,
                                'current'   => $current_page,
                            ] );
                            ?>
                        </div>
                    <?php endif; ?>

                </form>
            </div>

        </div><!-- .kb-wrap -->

        <script>
            // Native select-all toggle behavior
            document.addEventListener('DOMContentLoaded', function() {
                const selectAll = document.getElementById('cb-select-all-1');
                if (selectAll) {
                    selectAll.addEventListener('change', function(e) {
                        const checkboxes = document.querySelectorAll('input[name="log_id[]"]');
                        checkboxes.forEach(cb => cb.checked = e.target.checked);
                    });
                }
            });
        </script>
        <?php
    }
}
