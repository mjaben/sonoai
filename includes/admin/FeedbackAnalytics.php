<?php
/**
 * SonoAI — Admin Feedback Analytics.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FeedbackTable extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'feedback',
            'plural'   => 'feedbacks',
            'ajax'     => false,
        ] );
    }

    protected function get_table_classes() {
        return [ 'kb-table' ];
    }

    public function get_columns(): array {
        return [
            'cb'            => '<input type="checkbox" />',
            'id'            => __( 'ID', 'sonoai' ),
            'session_uuid'  => __( 'Session ID', 'sonoai' ),
            'message_index' => __( 'Message Index', 'sonoai' ),
            'vote'          => __( 'Vote', 'sonoai' ),
            'comment'       => __( 'Comment', 'sonoai' ),
            'created_at'    => __( 'Date', 'sonoai' ),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'id'            => [ 'id', false ],
            'created_at'    => [ 'created_at', true ],
            'vote'          => [ 'vote', false ],
        ];
    }

    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'id':
            case 'session_uuid':
            case 'message_index':
            case 'created_at':
                return esc_html( $item[ $column_name ] );
            case 'vote':
                $vote = strtolower( $item['vote'] );
                if ( $vote === 'up' ) {
                    return '<span class="kb-badge-up">👍 Upvote</span>';
                } elseif ( $vote === 'down' ) {
                    return '<span class="kb-badge-down">👎 Downvote</span>';
                }
                return esc_html( $item['vote'] );
            case 'comment':
                if ( empty( $item['comment'] ) ) {
                    return '—';
                }
                $trimmed = wp_trim_words( esc_html( $item['comment'] ), 15, '...' );
                $full    = esc_html( $item['comment'] );
                return sprintf(
                    '%s <br><button type="button" class="kb-action-link kb-view-txt-btn" data-content="%s" style="margin-top: 5px;">👁 %s</button>',
                    $trimmed,
                    esc_attr( $full ),
                    __( 'View', 'sonoai' )
                );
        }
        return '';
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="feedback_id[]" value="%d" />', $item['id'] );
    }

    public function get_bulk_actions(): array {
        return [
            'delete' => __( 'Delete', 'sonoai' ),
        ];
    }

    public function process_bulk_action() {
        if ( 'delete' === $this->current_action() ) {
            $feedback_ids = SecurityHelper::get_param( 'feedback_id', [], 'text' );
            if ( empty( $feedback_ids ) || ! is_array( $feedback_ids ) ) {
                return;
            }
            
            if ( ! check_admin_referer( 'bulk-' . $this->_args['plural'] ) ) {
                return;
            }

            if ( ! empty( $feedback_ids ) ) {
                $ids = array_map( 'intval', $feedback_ids );
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM `{$wpdb->prefix}sonoai_feedback` WHERE id IN ($placeholders)",
                        $ids
                    )
                );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Feedback deleted.', 'sonoai' ) . '</p></div>';
            }
        }
    }

    public function prepare_items() {
        global $wpdb;
        $table    = $wpdb->prefix . 'sonoai_feedback';
        $per_page = 20;

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $this->process_bulk_action();

        $orderby = SecurityHelper::get_param( 'orderby', 'created_at' );
        $order   = strtoupper( SecurityHelper::get_param( 'order', 'DESC' ) );
        $order   = ( 'ASC' === $order ) ? 'ASC' : 'DESC';

        // Whitelist columns to prevent SQL injection through ORDER BY
        if ( ! in_array( $orderby, [ 'id', 'created_at', 'vote' ], true ) ) {
            $orderby = 'created_at';
        }

        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        // Count total items
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}sonoai_feedback`" );

        // Fetch items safely
        $this->items = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}sonoai_feedback` ORDER BY $orderby $order LIMIT %d OFFSET %d",
                (int) $per_page,
                (int) $offset
            ), 
            ARRAY_A 
        );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
    }
}
class FeedbackAnalytics {
    private static ?FeedbackAnalytics $instance = null;

    public static function instance(): FeedbackAnalytics {
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
            __( 'Feedback Analytics', 'sonoai' ),
            __( 'Feedback Analytics', 'sonoai' ),
            'manage_options',
            'sonoai-feedback',
            [ $this, 'render_page' ]
        );
    }

    public static function get_stats(): array {
        global $wpdb;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}sonoai_feedback`" );
        $up    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}sonoai_feedback` WHERE vote = %s", 'up' ) );
        $down  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}sonoai_feedback` WHERE vote = %s", 'down' ) );
        
        $ratio = $total > 0 ? round( ( $up / $total ) * 100 ) : 0;

        return [
            'total' => $total,
            'up'    => $up,
            'down'  => $down,
            'ratio' => $ratio,
        ];
    }

    public function render_page(): void {
        if ( ! SecurityHelper::check_admin_caps() ) {
            return;
        }

        $stats = self::get_stats();
        $table = new FeedbackTable();
        $table->prepare_items();
        ?>
        <div class="kb-wrap" id="sonoai-feedback-page">
            
            <!-- Hero Header -->
            <div class="kb-header">
                <div class="kb-header-left">
                    <div class="kb-header-icon">📊</div>
                    <div>
                        <h1 class="kb-title"><?php esc_html_e( 'Feedback Analytics', 'sonoai' ); ?></h1>
                        <p class="kb-subtitle"><?php esc_html_e( 'Analyze user sentiment and feedback from AI chat interactions.', 'sonoai' ); ?></p>
                    </div>
                </div>
                <div class="kb-header-right">
                    <div class="kb-stat-badge">
                        <span class="kb-stat-icon">📈</span>
                        <span><?php 
                            // translators: %d is the positive feedback percentage.
                            printf( esc_html__( '%d%% Positive', 'sonoai' ), (int) $stats['ratio'] ); 
                        ?></span>
                    </div>
                    <button type="button" id="kb-theme-toggle" class="kb-theme-btn" title="Toggle dark / light mode">
                        <span class="kb-icon-dark">🌙</span>
                        <span class="kb-icon-light">☀️</span>
                    </button>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="kb-source-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); margin-bottom: 30px;">
                <div class="kb-source-card">
                    <div class="kb-source-card-header">
                        <div class="kb-source-icon" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.3);">💬</div>
                        <div class="kb-source-meta">
                            <strong class="kb-source-name"><?php esc_html_e( 'Total Feedback', 'sonoai' ); ?></strong>
                            <span class="kb-source-count"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
                        </div>
                    </div>
                    <p class="kb-source-desc"><?php esc_html_e( 'Total number of votes and comments received from users.', 'sonoai' ); ?></p>
                </div>

                <div class="kb-source-card">
                    <div class="kb-source-card-header">
                        <div class="kb-source-icon" style="background: rgba(70, 180, 80, 0.1); border-color: rgba(70, 180, 80, 0.3);">👍</div>
                        <div class="kb-source-meta">
                            <strong class="kb-source-name"><?php esc_html_e( 'Positive Votes', 'sonoai' ); ?></strong>
                            <span class="kb-source-count"><?php echo esc_html( number_format_i18n( $stats['up'] ) ); ?></span>
                        </div>
                    </div>
                    <p class="kb-source-desc"><?php esc_html_e( 'Users found the AI response helpful and accurate.', 'sonoai' ); ?></p>
                </div>

                <div class="kb-source-card">
                    <div class="kb-source-card-header">
                        <div class="kb-source-icon" style="background: rgba(220, 50, 50, 0.1); border-color: rgba(220, 50, 50, 0.3);">👎</div>
                        <div class="kb-source-meta">
                            <strong class="kb-source-name"><?php esc_html_e( 'Negative Votes', 'sonoai' ); ?></strong>
                            <span class="kb-source-count"><?php echo esc_html( number_format_i18n( $stats['down'] ) ); ?></span>
                        </div>
                    </div>
                    <p class="kb-source-desc"><?php esc_html_e( 'Users reported issues or were dissatisfied with the response.', 'sonoai' ); ?></p>
                </div>
            </div>

            <!-- Panel Wrap -->
            <div class="kb-panel-wrap" style="border-radius: 12px; margin-top: 30px;">
                <div class="kb-list-bar" style="border-bottom: 1px solid var(--kb-border); margin-bottom: 0; padding: 15px 20px;">
                    <h2 class="kb-section-title" style="margin:0; font-size: 1.1em;">📋 <?php esc_html_e( 'Feedback Log', 'sonoai' ); ?></h2>
                </div>
                <form id="feedback-filter" method="post" style="padding: 0 20px 20px;">
                    <input type="hidden" name="page" value="sonoai-feedback" />
                    <?php $table->display(); ?>
                </form>
            </div>

        </div><!-- .kb-wrap -->
        <?php
    }
}
