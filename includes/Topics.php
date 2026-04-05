<?php
/**
 * SonoAI — KB Topics / Category management.
 *
 * Topics are lightweight labels assigned to every KB item.
 * They are stored in sonoai_kb_topics and auto-imported from
 * WordPress taxonomy terms when content is ingested.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Topics {

	// ── Table helper ──────────────────────────────────────────────────────────

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sonoai_kb_topics';
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Get all topics, ordered alphabetically.
	 *
	 * @return array[] [{id, slug, name, wp_term_id, item_count}]
	 */
	public static function get_all(): array {
		global $wpdb;
		$topics_tbl = self::table();
		$kb_tbl     = $wpdb->prefix . 'sonoai_kb_items';

		$rows = $wpdb->get_results(
			"SELECT t.id, t.slug, t.name, t.wp_term_id,
			        COUNT(k.id) AS item_count
			 FROM `$topics_tbl` t
			 LEFT JOIN `$kb_tbl` k ON k.topic_id = t.id
			 GROUP BY t.id
			 ORDER BY t.name ASC",
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Get a single topic by ID.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	// ── Create ────────────────────────────────────────────────────────────────

	/**
	 * Get a topic by name (case-insensitive), creating it if it doesn't exist.
	 * Optionally links it to a WP term ID for auto-imported topics.
	 *
	 * @param string   $name       Human-readable topic name.
	 * @param int|null $wp_term_id WordPress term ID (null for manual topics).
	 * @return int Topic ID, or 0 on failure.
	 */
	public static function get_or_create( string $name, ?int $wp_term_id = null ): int {
		global $wpdb;
		$table = self::table();
		$name  = sanitize_text_field( trim( $name ) );
		$slug  = sanitize_title( $name );

		if ( empty( $slug ) ) {
			return 0;
		}

		// Look for existing by slug.
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `$table` WHERE slug = %s", $slug )
		);

		if ( $existing ) {
			return (int) $existing;
		}

		// Insert new.
		$result = $wpdb->insert(
			$table,
			[
				'slug'       => $slug,
				'name'       => $name,
				'wp_term_id' => $wp_term_id,
			],
			[ '%s', '%s', $wp_term_id ? '%d' : '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	// ── Update ────────────────────────────────────────────────────────────────

	/**
	 * Rename a topic. Updates slug + cascades topic_slug to embeddings.
	 *
	 * @param int    $id   Topic ID.
	 * @param string $name New name.
	 * @return bool
	 */
	public static function update( int $id, string $name ): bool {
		global $wpdb;
		$table    = self::table();
		$name     = sanitize_text_field( trim( $name ) );
		$new_slug = sanitize_title( $name );

		if ( empty( $new_slug ) ) {
			return false;
		}

		// Get old slug to cascade update.
		$old_slug = $wpdb->get_var(
			$wpdb->prepare( "SELECT slug FROM `$table` WHERE id = %d", $id )
		);

		$updated = $wpdb->update(
			$table,
			[ 'name' => $name, 'slug' => $new_slug ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		// Cascade slug update to embeddings.
		if ( $updated !== false && $old_slug && $old_slug !== $new_slug ) {
			$emb_tbl = $wpdb->prefix . 'sonoai_embeddings';
			$wpdb->update(
				$emb_tbl,
				[ 'topic_slug' => $new_slug ],
				[ 'topic_slug' => $old_slug ],
				[ '%s' ],
				[ '%s' ]
			);
		}

		return $updated !== false;
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	/**
	 * Delete a topic. Nulls topic_id on KB items and topic_slug on embeddings.
	 *
	 * @param int $id Topic ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$table   = self::table();
		$kb_tbl  = $wpdb->prefix . 'sonoai_kb_items';
		$emb_tbl = $wpdb->prefix . 'sonoai_embeddings';

		// Get slug before deleting.
		$slug = $wpdb->get_var(
			$wpdb->prepare( "SELECT slug FROM `$table` WHERE id = %d", $id )
		);

		$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		if ( $deleted ) {
			// Null out topic_id on KB items.
			$wpdb->query(
				$wpdb->prepare( "UPDATE `$kb_tbl` SET topic_id = NULL WHERE topic_id = %d", $id )
			);
			// Null out topic_slug on embeddings.
			if ( $slug ) {
				$wpdb->query(
					$wpdb->prepare( "UPDATE `$emb_tbl` SET topic_slug = NULL WHERE topic_slug = %s", $slug )
				);
			}
		}

		return (bool) $deleted;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Auto-detect a topic for a WordPress post from its primary category.
	 * Creates the topic if it doesn't exist.
	 *
	 * @param int $post_id
	 * @return int Topic ID, or 0 if no category found.
	 */
	public static function for_post( int $post_id ): int {
		$post_type = get_post_type( $post_id );

		// Try standard WP categories first.
		$terms = get_the_terms( $post_id, 'category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			// Try docs_category (EazyDocs), post_tag, or any first taxonomy.
			$taxonomies = get_object_taxonomies( $post_type );
			foreach ( $taxonomies as $tax ) {
				$terms = get_the_terms( $post_id, $tax );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					break;
				}
			}
		}

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return 0;
		}

		// Use first non-uncategorized term.
		foreach ( $terms as $term ) {
			if ( $term->slug !== 'uncategorized' ) {
				return self::get_or_create( $term->name, $term->term_id );
			}
		}

		// Fall back to first term even if uncategorized.
		$first = reset( $terms );
		return self::get_or_create( $first->name, $first->term_id );
	}

	/**
	 * Get a topic's slug by its ID. Returns empty string if not found.
	 */
	public static function get_slug( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		global $wpdb;
		$table = self::table();
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT slug FROM `$table` WHERE id = %d", $id )
		);
	}
}
