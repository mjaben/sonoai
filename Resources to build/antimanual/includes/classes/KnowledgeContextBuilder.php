<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared builder for AI knowledge-base context.
 *
 * Consolidates the repeated logic used across generators that enrich prompts
 * with semantic or source-scoped knowledge-base chunks.
 *
 * @package Antimanual
 */
class KnowledgeContextBuilder {
	/**
	 * Build knowledge context from embeddings.
	 *
	 * When source IDs are provided, only those sources are included.
	 * Otherwise, the builder attempts semantic retrieval first and falls back
	 * to the most recently modified knowledge chunks.
	 *
	 * @param string[] $source_ids Optional selected knowledge IDs.
	 * @param string   $query      Optional semantic query.
	 * @return string
	 */
	public static function build_context( array $source_ids = [], string $query = '' ): string {
		global $wpdb;

		$table_name = Embedding::get_table_name();
		if ( ! $table_name ) {
			return '';
		}

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $table_exists !== $table_name ) {
			return '';
		}

		$context_parts = [];
		$seen          = [];

		$append_chunk = static function ( $chunk ) use ( &$context_parts, &$seen ) {
			$reference = Embedding::get_chunk_reference( $chunk );
			$text      = trim( (string) ( $chunk->chunk_text ?? '' ) );

			if ( '' === $text ) {
				return;
			}

			$knowledge_id = \sanitize_text_field( (string) ( $chunk->knowledge_id ?? '' ) );
			$chunk_index  = intval( $chunk->chunk_index ?? 0 );
			$unique_key   = $knowledge_id . ':' . $chunk_index;

			if ( isset( $seen[ $unique_key ] ) ) {
				return;
			}

			$seen[ $unique_key ] = true;
			$context_parts[]     = sprintf(
				"[Source: %s]\n%s",
				$reference['title'],
				$text
			);
		};

		$source_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $source_ids ) ) ) );

		if ( ! empty( $source_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%s' ) );
			$chunks       = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT knowledge_id, chunk_index, chunk_text, post_id, type, url
					FROM `$table_name`
					WHERE knowledge_id IN ($placeholders)
					ORDER BY knowledge_id, chunk_index
					LIMIT 200",
					$source_ids
				)
			);

			if ( is_array( $chunks ) ) {
				foreach ( $chunks as $chunk ) {
					$append_chunk( $chunk );
				}
			}
		} else {
			$query = \sanitize_text_field( $query );

			if ( '' !== $query && function_exists( 'antimanual_get_related_chunks' ) ) {
				$related_chunks = antimanual_get_related_chunks( $query, 20 );

				if ( is_array( $related_chunks ) && ! isset( $related_chunks['error'] ) ) {
					foreach ( $related_chunks as $item ) {
						$similarity = floatval( $item['similarity'] ?? 0 );
						$row        = $item['row'] ?? null;

						if ( $row && $similarity >= 0.2 ) {
							$append_chunk( $row );
						}
					}
				}
			}

			if ( count( $context_parts ) < 8 ) {
				$limit  = max( 8, 20 - count( $context_parts ) );
				$chunks = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT knowledge_id, chunk_index, chunk_text, post_id, type, url
						FROM `$table_name`
						ORDER BY post_modified_gmt DESC
						LIMIT %d",
						$limit
					)
				);

				if ( is_array( $chunks ) ) {
					foreach ( $chunks as $chunk ) {
						$append_chunk( $chunk );
					}
				}
			}
		}

		if ( empty( $context_parts ) ) {
			return '';
		}

		return implode( "\n\n---\n\n", array_slice( $context_parts, 0, 30 ) );
	}
}