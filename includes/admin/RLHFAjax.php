<?php
/**
 * SonoAI — RLHF QA Ajax Handlers.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RLHFAjax {

    private static ?RLHFAjax $instance = null;

    public static function instance(): RLHFAjax {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_sonoai_rlhf_get_items',     [ $this, 'get_items' ] );
        add_action( 'wp_ajax_sonoai_rlhf_update_status', [ $this, 'update_status' ] );
        add_action( 'wp_ajax_sonoai_rlhf_chat_test',     [ $this, 'chat_test' ] );
    }

    public function get_items(): void {
        check_ajax_referer( 'sonoai_rlhf_get_items', 'nonce' );

        if ( ! current_user_can( 'sonoai_manage_rlhf' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'sonoai_kb_items';
        $type   = SecurityHelper::get_param( 'type', 'all', 'text' );
        $status = SecurityHelper::get_param( 'status', 'pending', 'text' ); // pending, Needs Re-training, Not Started, Passed

        $where = "1=1";
        $params = [];

        if ( $type !== 'all' ) {
            $where .= " AND `type` = %s";
            $params[] = $type;
        }

        if ( $status === 'pending' ) {
            $where .= " AND (`rlhf_status` = 'Not Started' OR `rlhf_status` = 'Needs Re-training')";
        } else {
            $where .= " AND `rlhf_status` = %s";
            $params[] = $status;
        }

        $query = "SELECT id, knowledge_id, type, source_title, source_url, raw_content, mode, chunk_count, rlhf_status, rlhf_fail_reason, rlhf_reviewer_notes, rlhf_last_tested_at FROM `$table` WHERE $where ORDER BY id DESC LIMIT 200";
        if ( ! empty( $params ) ) {
            $query = $wpdb->prepare( $query, ...$params );
        }

        $items = $wpdb->get_results( $query, ARRAY_A );

        // Get accurate total stats, applying only the type filter (not the status filter or limit)
        $count_where = "1=1";
        $count_params = [];
        if ( $type !== 'all' ) {
            $count_where .= " AND `type` = %s";
            $count_params[] = $type;
        }

        $count_query = "SELECT rlhf_status, COUNT(*) as count FROM `$table` WHERE $count_where GROUP BY rlhf_status";
        if ( ! empty( $count_params ) ) {
            $count_query = $wpdb->prepare( $count_query, ...$count_params );
        }
        $stats_results = $wpdb->get_results( $count_query, ARRAY_A );

        $total_pending = 0;
        $total_passed = 0;

        if ( $stats_results ) {
            foreach ( $stats_results as $row ) {
                if ( $row['rlhf_status'] === 'Passed' ) {
                    $total_passed += (int) $row['count'];
                } else {
                    $total_pending += (int) $row['count'];
                }
            }
        }

        wp_send_json_success( [
            'items' => $items ?: [],
            'stats' => [
                'pending' => $total_pending,
                'passed'  => $total_passed
            ]
        ] );
    }

    public function update_status(): void {
        check_ajax_referer( 'sonoai_rlhf_update_status', 'nonce' );

        if ( ! current_user_can( 'sonoai_manage_rlhf' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $id          = SecurityHelper::get_param( 'item_id', 0, 'int' );
        $status      = SecurityHelper::get_param( 'status', '', 'text' );
        $reason      = SecurityHelper::get_param( 'reason', '', 'text' );
        $notes       = SecurityHelper::get_param( 'notes', '', 'text' );
        $reviewer_id = get_current_user_id();

        if ( ! $id || ! in_array( $status, [ 'Passed', 'Needs Re-training' ], true ) ) {
            wp_send_json_error( 'Invalid parameters.' );
        }

        if ( $status === 'Passed' ) {
            $reason = ''; // Clear failure reason if passed
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sonoai_kb_items';

        $updated = $wpdb->update(
            $table,
            [
                'rlhf_status'         => $status,
                'rlhf_fail_reason'    => $reason,
                'rlhf_reviewer_notes' => $notes,
                'rlhf_reviewed_by_id' => $reviewer_id,
                'rlhf_last_tested_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        if ( $updated !== false ) {
            if ( class_exists( 'SonoAI\AuditLogger' ) ) {
                AuditLogger::log( 'rlhf_grade', sprintf( 'Graded KB Item #%d as %s', $id, $status ) );
            }
            wp_send_json_success( 'Updated successfully.' );
        } else {
            wp_send_json_error( 'Database update failed.' );
        }
    }

    public function chat_test(): void {
        check_ajax_referer( 'sonoai_rlhf_chat_test', 'nonce' );

        if ( ! current_user_can( 'sonoai_manage_rlhf' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $id      = SecurityHelper::get_param( 'item_id', 0, 'int' );
        $message = SecurityHelper::get_param( 'message', '', 'text' );

        if ( ! $id || ! $message ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sonoai_kb_items';
        $item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ) );

        if ( ! $item ) {
            wp_send_json_error( 'Item not found.' );
        }

        // We use the raw_content of the KB item as the sole context for the AI.
        // If it's a WP post, we might not have raw_content populated if it's chunked directly.
        // Let's fetch chunks if raw_content is empty.
        $context_text = $item->raw_content;
        if ( empty( $context_text ) ) {
            $chunks_table = $wpdb->prefix . 'sonoai_embeddings';
            $chunks = $wpdb->get_results( $wpdb->prepare( "SELECT chunk_text, image_urls FROM `$chunks_table` WHERE knowledge_id = %s ORDER BY chunk_index ASC", $item->knowledge_id ) );
            $texts = [];
            foreach ( $chunks as $chunk ) {
                $texts[] = $chunk->chunk_text;
            }
            $context_text = implode( "\n\n", $texts );
        }

        if ( empty( $context_text ) ) {
            wp_send_json_error( 'No content found for this item to use as context.' );
        }

        // Build a strict system prompt to force the model to ONLY use this specific item.
        $system_prompt = "You are a QA testing assistant. You MUST answer the user's query using ONLY the following specific context. Do NOT use outside knowledge.\n\n<KNOWLEDGE_BASE>\n$context_text\n</KNOWLEDGE_BASE>";

        $messages = [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user', 'content' => $message ]
        ];

        $response = AIProvider::get_reply( $messages );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        wp_send_json_success( [
            'reply' => $response
        ] );
    }
}
