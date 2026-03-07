<?php
/**
 * SonoAI — Chat session management.
 *
 * Handles session CRUD and history-limit auto-pruning.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Chat {

    private static ?Chat $instance = null;

    public static function instance(): Chat {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sonoai_sessions';
    }

    // ── Session CRUD ──────────────────────────────────────────────────────────

    /**
     * Create a new session and return its UUID.
     *
     * @param int    $user_id       WP user ID.
     * @param string $first_message The first user message (used as title).
     * @return string Session UUID.
     */
    public static function create_session( int $user_id, string $first_message ): string {
        global $wpdb;
        $table = self::table();
        $uuid  = wp_generate_uuid4();
        $title = mb_substr( sanitize_text_field( $first_message ), 0, 120 );

        $wpdb->insert(
            $table,
            [
                'session_uuid' => $uuid,
                'user_id'      => $user_id,
                'title'        => $title,
                'messages'     => wp_json_encode( [] ),
            ],
            [ '%s', '%d', '%s', '%s' ]
        );

        // Enforce history limit (prune oldest sessions beyond the limit).
        self::prune_sessions( $user_id );

        return $uuid;
    }

    /**
     * Append a message to a session's messages array.
     *
     * @param string $session_uuid Session identifier.
     * @param string $role         'user' or 'assistant'.
     * @param string $content      Message text.
     * @param string $image_url    Optional stored image URL.
     * @return bool
     */
    public static function add_message( string $session_uuid, string $role, string $content, string $image_url = '' ): bool {
        global $wpdb;
        $table   = self::table();
        $session = self::get_raw( $session_uuid );
        if ( ! $session ) {
            return false;
        }

        $messages   = json_decode( $session->messages, true );
        $messages   = is_array( $messages ) ? $messages : [];
        $messages[] = array_filter( [
            'role'      => $role,
            'content'   => $content,
            'image_url' => $image_url,
            'created_at' => time(),
        ] );

        return (bool) $wpdb->update(
            $table,
            [ 'messages' => wp_json_encode( $messages ) ],
            [ 'session_uuid' => $session_uuid ],
            [ '%s' ],
            [ '%s' ]
        );
    }

    /**
     * Get a session's messages (with ownership check).
     *
     * @param string $session_uuid
     * @param int    $user_id
     * @return array|null
     */
    public static function get_session( string $session_uuid, int $user_id ): ?array {
        global $wpdb;
        $table   = self::table();
        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `$table` WHERE session_uuid = %s AND user_id = %d",
                $session_uuid,
                $user_id
            )
        );

        if ( ! $session ) {
            return null;
        }

        $messages = json_decode( $session->messages, true );
        return [
            'uuid'       => $session->session_uuid,
            'title'      => $session->title,
            'messages'   => is_array( $messages ) ? $messages : [],
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at,
        ];
    }

    /**
     * Get a lightweight list of a user's sessions (no messages).
     *
     * @param int $user_id
     * @return array[]
     */
    public static function list_sessions( int $user_id ): array {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, (int) sonoai_option( 'history_limit', 50 ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT session_uuid, title, created_at, updated_at FROM `$table` WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",
                $user_id,
                $limit
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Delete a single session (with ownership check).
     */
    public static function delete_session( string $session_uuid, int $user_id ): bool {
        global $wpdb;
        $table = self::table();

        $deleted = $wpdb->delete(
            $table,
            [ 'session_uuid' => $session_uuid, 'user_id' => $user_id ],
            [ '%s', '%d' ]
        );

        return $deleted > 0;
    }

    /**
     * Delete ALL sessions for a user.
     */
    public static function delete_all_sessions( int $user_id ): bool {
        global $wpdb;
        $table = self::table();
        return $wpdb->delete( $table, [ 'user_id' => $user_id ], [ '%d' ] ) !== false;
    }

    /**
     * Get messages from a session in format ready for the AI provider.
     * Returns the N most recent messages to avoid exceeding context limits.
     *
     * @param string $session_uuid
     * @param int    $max_messages Maximum context window.
     * @return array[]
     */
    public static function get_messages_for_ai( string $session_uuid, int $max_messages = 20 ): array {
        $session = self::get_raw( $session_uuid );
        if ( ! $session ) {
            return [];
        }

        $messages = json_decode( $session->messages, true );
        if ( ! is_array( $messages ) ) {
            return [];
        }

        // Keep only {role, content} — strip metadata fields.
        $clean = array_map( fn( $m ) => [
            'role'    => $m['role'] ?? 'user',
            'content' => $m['content'] ?? '',
        ], $messages );

        // Take last N messages.
        return array_slice( $clean, -$max_messages );
    }

    // ── History limit ─────────────────────────────────────────────────────────

    /**
     * Delete the oldest sessions beyond the configured history limit.
     */
    public static function prune_sessions( int $user_id ): void {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, (int) sonoai_option( 'history_limit', 50 ) );

        // Find IDs to keep (most recent N).
        $keep_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM `$table` WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",
                $user_id,
                $limit
            )
        );

        if ( empty( $keep_ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `$table` WHERE user_id = %d AND id NOT IN ($placeholders)",
                array_merge( [ $user_id ], array_map( 'intval', $keep_ids ) )
            )
        );
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private static function get_raw( string $session_uuid ): ?\stdClass {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `$table` WHERE session_uuid = %s", $session_uuid )
        );
    }
}
