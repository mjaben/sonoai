<?php
/**
 * SonoAI — Saved Responses management.
 *
 * Handles saving, listing, and deleting user-saved assistant messages.
 * Saved items carry the session_uuid + message_index so the frontend
 * can deep-link directly to the exact bubble in chat history.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SavedResponses {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sonoai_saved_responses';
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Save an assistant message.
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param string $session_uuid  Session UUID.
	 * @param int    $message_index Zero-based index of the message in the session's messages array.
	 * @param string $content       Full assistant message text (HTML or markdown).
	 * @param string $mode          'guideline' or 'research'.
	 * @param string $topic_slug    Topic slug (optional).
	 * @return int|false  Inserted row ID, or false on failure.
	 */
	public static function save(
		int $user_id,
		string $session_uuid,
		int $message_index,
		string $content,
		string $mode = 'guideline',
		string $topic_slug = ''
	) {
		global $wpdb;
		$table = self::table();

		// Auto-generate title from first 80 chars of plain text.
		$plain = wp_strip_all_tags( $content );
		$title = mb_substr( $plain, 0, 80 );
		if ( mb_strlen( $plain ) > 80 ) {
			$title .= '…';
		}

		$result = $wpdb->insert(
			$table,
			[
				'user_id'       => $user_id,
				'session_uuid'  => $session_uuid,
				'message_index' => $message_index,
				'content'       => $content,
				'mode'          => in_array( $mode, [ 'guideline', 'research' ], true ) ? $mode : 'guideline',
				'topic_slug'    => $topic_slug ?: null,
				'title'         => $title,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * List all saved responses for a user, newest first.
	 *
	 * @param int $user_id
	 * @return array[]
	 */
	public static function list( int $user_id ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, session_uuid, message_index, title, mode, topic_slug, created_at
				 FROM `$table`
				 WHERE user_id = %d
				 ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		);
		return $rows ?: [];
	}

	/**
	 * Get saved response IDs for a session, keyed by message_index.
	 */
	public static function get_saved_ids_for_session( string $session_uuid, int $user_id ): array {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT message_index, id FROM `$table` WHERE session_uuid = %s AND user_id = %d",
				$session_uuid,
				$user_id
			),
			OBJECT_K
		);
	}

	/**
	 * Delete a saved response (ownership check included).
	 *
	 * @param int $id      Row ID.
	 * @param int $user_id Must match the saved item's user_id.
	 * @return bool
	 */
	public static function delete( int $id, int $user_id ): bool {
		global $wpdb;
		$table   = self::table();
		$deleted = $wpdb->delete(
			$table,
			[ 'id' => $id, 'user_id' => $user_id ],
			[ '%d', '%d' ]
		);
		return (bool) $deleted;
	}
}
