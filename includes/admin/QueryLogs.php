<?php
/**
 * SonoAI — Admin Query Logs.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QueryLogs {
    private static ?QueryLogs $instance = null;

    public static function instance(): QueryLogs {
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
            __( 'Query Logs', 'sonoai' ),
            __( 'Query Logs', 'sonoai' ),
            'manage_options',
            'sonoai-query-logs',
            [ $this, 'render_page' ]
        );
    }

    private function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['action'] ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'sonoai_query_logs';

            if ( 'delete' === $_POST['action'] && ! empty( $_POST['log_id'] ) && is_array( $_POST['log_id'] ) ) {
                $ids = array_map( 'intval', $_POST['log_id'] );
                $ids_str = implode( ',', $ids );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( "DELETE FROM `$table` WHERE id IN ($ids_str)" );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Selected logs deleted.', 'sonoai' ) . '</p></div>';
            } elseif ( 'clear_all_logs' === $_POST['action'] ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( "TRUNCATE TABLE `$table`" );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All query logs cleared.', 'sonoai' ) . '</p></div>';
            }
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->handle_actions();

        global $wpdb;
        $table = $wpdb->prefix . 'sonoai_query_logs';

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
        <div class="kb-wrap" id="sonoai-query-logs-page">
            
            <!-- Hero Header -->
            <div class="kb-header">
                <div class="kb-header-left">
                    <div class="kb-header-icon">🔬</div>
                    <div>
                        <h1 class="kb-title"><?php esc_html_e( 'Query Logs', 'sonoai' ); ?></h1>
                        <p class="kb-subtitle"><?php esc_html_e( 'Review queries the AI lacked the knowledge to answer.', 'sonoai' ); ?></p>
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
                <form method="post" id="query-logs-form">
                    
                    <div class="kb-list-bar">
                        <div class="kb-bulk">
                            <select name="action" class="kb-select-sm">
                                <option value=""><?php esc_html_e( '— Bulk Action —', 'sonoai' ); ?></option>
                                <option value="delete"><?php esc_html_e( 'Delete', 'sonoai' ); ?></option>
                            </select>
                            <button type="submit" class="kb-btn-sm"><?php esc_html_e( 'Apply', 'sonoai' ); ?></button>
                            <button type="submit" name="action" value="clear_all_logs" class="kb-btn-sm" style="color:#ef4444; border-color:rgba(239,68,68,0.3); background:rgba(239,68,68,0.1); white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; padding: 0 12px;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete ALL query logs?', 'sonoai' ); ?>');">
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
                                <th><?php esc_html_e( 'Mode', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'Query Details', 'sonoai' ); ?></th>
                                <th><?php esc_html_e( 'Action', 'sonoai' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $items ) ) : ?>
                                <tr><td colspan="6" class="kb-empty"><?php esc_html_e( 'No queries logged yet.', 'sonoai' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $items as $item ) : 
                                    $user = get_userdata( $item['user_id'] );
                                    $user_name = $user ? $user->display_name : __( 'Guest', 'sonoai' );
                                    $mode = ucfirst( $item['mode'] ?? 'Guideline' );
                                    
                                    // Prepare safe preview text
                                    $query_plain = wp_strip_all_tags( $item['query_text'] );
                                    $query_preview = wp_trim_words( $query_plain, 8, '…' );
                                    
                                    // Generate the Train AI link
                                    $train_url = add_query_arg( [
                                        'page'          => 'sonoai-kb',
                                        'kb_tab'        => 'txt',
                                        'add_query_log' => $item['id'],
                                    ], admin_url( 'admin.php' ) );
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="log_id[]" value="<?php echo esc_attr( $item['id'] ); ?>"></td>
                                    <td style="white-space: nowrap;"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['created_at'] ) ) ); ?></td>
                                    <td><?php echo esc_html( $user_name ); ?></td>
                                    <td><span class="kb-badge-model" style="opacity:0.8;"><?php echo esc_html( $mode ); ?></span></td>
                                    <td class="kb-col-content">
                                        <span class="kb-content-preview"><?php echo esc_html( $query_preview ); ?></span>
                                        <div style="font-size: 0.85em; color: #666; margin-top: 4px;">
                                            <button type="button" class="kb-action-link kb-view-txt-btn" style="padding:0; margin:0; border:none; background:none; cursor:pointer;" data-content="<?php echo esc_attr( $item['query_text'] ); ?>">
                                                👁 <?php esc_html_e( 'View Query Text', 'sonoai' ); ?>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="kb-col-actions">
                                        <a href="<?php echo esc_url( $train_url ); ?>" class="kb-action-link" style="color: #10b981;">🧠 <?php esc_html_e( 'Train AI', 'sonoai' ); ?></a>
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

            <!-- View modal for full content -->
            <dialog id="kb-view-modal" class="kb-modal" style="display:none;">
                <div class="kb-modal-content">
                    <div class="kb-modal-header">
                        <strong><?php esc_html_e( 'Full Query Details', 'sonoai' ); ?></strong>
                        <button type="button" class="kb-modal-close" style="background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
                    </div>
                    <div id="kb-modal-body" class="kb-modal-body" style="white-space: pre-wrap; margin-top:15px; line-height:1.5;"></div>
                </div>
            </dialog>

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
