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

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class QueryLogsTable extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'query_log',
            'plural'   => 'query_logs',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'cb'         => '<input type="checkbox" />',
            'id'         => __( 'ID', 'sonoai' ),
            'user'       => __( 'User', 'sonoai' ),
            'query'      => __( 'Query Text', 'sonoai' ),
            'response'   => __( 'AI Response', 'sonoai' ),
            'created_at' => __( 'Date', 'sonoai' ),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'id'         => [ 'id', false ],
            'created_at' => [ 'created_at', true ],
        ];
    }

    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'id':
            case 'created_at':
                return esc_html( $item[ $column_name ] );
            case 'user':
                $user = get_userdata( $item['user_id'] );
                return $user ? esc_html( $user->display_name ) : __( 'Guest', 'sonoai' );
            case 'query':
            case 'response':
                return wp_trim_words( esc_html( $item[ $column_name ] ), 20, '...' ) . 
                       '<br><small style="color:#0071a1; cursor:pointer; text-decoration: underline;" onclick="alert(this.nextSibling.innerText)">View full</small><span style="display:none;">' . esc_html( $item[ $column_name ] ) . '</span>';
            default:
                return '';
        }
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="log_id[]" value="%d" />', $item['id'] );
    }

    public function get_bulk_actions(): array {
        return [
            'delete' => __( 'Delete', 'sonoai' ),
        ];
    }

    public function process_bulk_action() {
        if ( 'delete' === $this->current_action() ) {
            if ( ! isset( $_REQUEST['log_id'] ) || ! is_array( $_REQUEST['log_id'] ) ) {
                return;
            }
            if ( ! check_admin_referer( 'bulk-' . $this->_args['plural'] ) ) {
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'sonoai_query_logs';
            $ids   = array_map( 'intval', $_REQUEST['log_id'] );
            $ids_str = implode( ',', $ids );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DELETE FROM `$table` WHERE id IN ($ids_str)" );

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Logs deleted.', 'sonoai' ) . '</p></div>';
        }
    }

    public function prepare_items() {
        global $wpdb;
        $table    = $wpdb->prefix . 'sonoai_query_logs';
        $per_page = 20;

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $this->process_bulk_action();

        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : 'created_at';
        $order   = isset( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ? 'ASC' : 'DESC';

        // Check columns to sort by
        if ( ! in_array( $orderby, [ 'id', 'created_at' ], true ) ) {
            $orderby = 'created_at';
        }

        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->items = $wpdb->get_results( 
            "SELECT * FROM `$table` ORDER BY $orderby $order LIMIT $per_page OFFSET $offset", 
            ARRAY_A 
        );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
    }
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

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $table = new QueryLogsTable();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">🔬 <?php esc_html_e( 'SonoAI Query Logs', 'sonoai' ); ?></h1>
            <p><?php esc_html_e( 'These are unanswered queries from users, logged because the AI was explicitly unable to answer based on the provided knowledge base.', 'sonoai' ); ?></p>
            <form id="query-logs-filter" method="post">
                <input type="hidden" name="page" value="sonoai-query-logs" />
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }
}
