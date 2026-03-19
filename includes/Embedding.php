<?php
/**
 * SonoAI — Embedding management.
 *
 * Handles creation, storage, and cosine-similarity retrieval of vector
 * embeddings for eligible WordPress post types.
 * Adapted from the Antimanual Embedding class pattern.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Embedding {

    private static ?Embedding $instance = null;

    public static function instance(): Embedding {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ── Table name ────────────────────────────────────────────────────────────

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sonoai_embeddings';
    }

    // ── Chunking ──────────────────────────────────────────────────────────────

    /**
     * Split text into overlapping chunks for embedding.
     *
     * @param string $text    Cleaned plain-text content.
     * @param int    $size    Chunk character size.
     * @param int    $overlap Overlap between chunks.
     * @return string[]
     */
    public static function split_into_chunks( string $text, int $size = 700, int $overlap = 100 ): array {
        $text_len = mb_strlen( $text );
        if ( $text_len === 0 ) {
            return [];
        }

        $total     = max( 1, (int) ceil( $text_len / $size ) );
        $real_size = (int) ceil( $text_len / $total );
        $chunks    = [];

        for ( $i = 0; $i < $total; $i++ ) {
            $chunks[] = mb_substr( $text, $i * $real_size, $real_size + $overlap );
        }

        return array_filter( $chunks );
    }

    // ── Insert ────────────────────────────────────────────────────────────────

    /**
     * Embed a post's content and store all chunks.
     *
     * @param int      $post_id    WordPress post ID.
     * @param string   $post_type  CPT slug (e.g. 'docs', 'topic').
     * @param string   $content    Plain-text content.
     * @param string[] $image_urls Optional array of image source URLs.
     * @return string|\WP_Error
     */
    public static function insert( int $post_id, string $post_type, string $content, array $image_urls = [] ) {
        $content = trim( $content );
        if ( empty( $content ) ) {
            return new \WP_Error( 'empty_content', __( 'Content is empty.', 'sonoai' ) );
        }

        if ( ! AIProvider::has_api_key() ) {
            return new \WP_Error( 'no_api_key', __( 'AI API key is not configured.', 'sonoai' ) );
        }

        global $wpdb;
        $table        = self::table();
        $knowledge_id = wp_generate_uuid4();
        $provider     = AIProvider::get_name();
        $model        = AIProvider::get_embedding_model();
        $chunks       = self::split_into_chunks( $content );
        $modified_gmt = current_time( 'mysql', true );
        $image_json   = ! empty( $image_urls ) ? wp_json_encode( array_values( array_unique( $image_urls ) ) ) : null;
        $errors       = 0;

        foreach ( $chunks as $idx => $chunk_text ) {
            $embedding = AIProvider::generate_embedding( $chunk_text );

            if ( is_wp_error( $embedding ) ) {
                error_log( '[SonoAI] Embedding failed for post ' . $post_id . ': ' . $embedding->get_error_message() );
                $errors++;
                continue;
            }

            $result = $wpdb->insert(
                $table,
                [
                    'knowledge_id'      => $knowledge_id,
                    'post_id'           => $post_id,
                    'post_type'         => $post_type,
                    'image_urls'        => $image_json,
                    'chunk_index'       => $idx,
                    'chunk_text'        => $chunk_text,
                    'embedding'         => wp_json_encode( $embedding ),
                    'provider'          => $provider,
                    'embedding_model'   => $model,
                    'post_modified_gmt' => $modified_gmt,
                ],
                [ '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( false === $result ) {
                error_log( '[SonoAI] DB insert failed: ' . $wpdb->last_error );
                $errors++;
            }
        }

        if ( $post_id > 0 ) {
            // Remove old chunks for this post (different knowledge_id).
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `$table` WHERE post_id = %d AND knowledge_id != %s AND provider = %s",
                    $post_id,
                    $knowledge_id,
                    $provider
                )
            );
        }

        if ( $errors === count( $chunks ) ) {
            return new \WP_Error( 'all_chunks_failed', __( 'All embedding chunks failed.', 'sonoai' ) );
        }

        return $knowledge_id;
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Remove all embeddings for a post.
     */
    public static function delete_by_post( int $post_id ): void {
        global $wpdb;
        $table = self::table();
        $wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
    }

    // ── Search ────────────────────────────────────────────────────────────────

    /**
     * Find the top-N most relevant chunks for a query string.
     *
     * @param string   $query          User's question.
     * @param int      $limit          Number of results.
     * @param string[] $post_types     Filter by CPT; empty array = all types.
     * @param float    $min_similarity Minimum similarity score to include (0.0 to 1.0).
     * @return array{chunk_text: string, post_id: int, post_type: string, similarity: float}[]
     */
    public static function search( string $query, int $limit = 5, array $post_types = [], float $min_similarity = 0.0 ) {
        if ( ! AIProvider::has_api_key() ) {
            return [];
        }

        // Cache query embedding for 24 h.
        $provider      = AIProvider::get_name();
        $model         = AIProvider::get_embedding_model();
        $cache_key     = 'sonoai_qemb_' . md5( $provider . $model . $query );
        $query_vector  = get_transient( $cache_key );

        if ( ! is_array( $query_vector ) ) {
            $query_vector = AIProvider::generate_embedding( $query );
            if ( is_wp_error( $query_vector ) ) {
                error_log( '[SonoAI] Search embedding error: ' . $query_vector->get_error_message() );
                return [];
            }
            set_transient( $cache_key, $query_vector, DAY_IN_SECONDS );
        }

        global $wpdb;
        $table = self::table();

        // Fetch embeddings (only provider match to keep vectors comparable).
        $where_sql    = $wpdb->prepare( 'provider = %s', $provider );
        $type_placeholders = [];
        if ( ! empty( $post_types ) ) {
            foreach ( $post_types as $pt ) {
                $type_placeholders[] = $wpdb->prepare( '%s', $pt );
            }
            $where_sql .= ' AND post_type IN (' . implode( ',', $type_placeholders ) . ')';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT id, post_id, post_type, chunk_text, embedding, image_urls FROM `$table` WHERE $where_sql", ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        // Cosine similarity, keep top-N.
        $top = [];
        foreach ( $rows as $row ) {
            $vec        = json_decode( $row['embedding'], true );
            if ( ! is_array( $vec ) ) {
                continue;
            }
            $sim        = sonoai_cosine_similarity( $query_vector, $vec );
            
            // Filter by minimum similarity.
            if ( $sim < $min_similarity ) {
                continue;
            }

            $candidate  = [
                'chunk_text' => $row['chunk_text'],
                'post_id'    => (int) $row['post_id'],
                'post_type'  => $row['post_type'],
                'image_urls' => $row['image_urls'] ? json_decode( $row['image_urls'], true ) : [],
                'similarity' => $sim,
            ];

            if ( count( $top ) < $limit ) {
                $top[] = $candidate;
                usort( $top, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
            } elseif ( $sim > ( $top[ $limit - 1 ]['similarity'] ?? 0 ) ) {
                $top[ $limit - 1 ] = $candidate;
                usort( $top, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
            }
        }

        return $top;
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Count unique posts in the knowledge base, grouped by post_type.
     *
     * @return array<string, int>
     */
    public static function get_counts(): array {
        global $wpdb;
        $table   = self::table();
        $results = $wpdb->get_results(
            "SELECT post_type, COUNT(DISTINCT post_id) as total FROM `$table` GROUP BY post_type",
            ARRAY_A
        );
        $counts  = [];
        foreach ( $results as $row ) {
            $counts[ $row['post_type'] ] = (int) $row['total'];
        }
        return $counts;
    }
}
