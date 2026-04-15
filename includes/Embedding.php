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

    public static function split_into_chunks( string $text, int $size = 800, int $overlap = 100 ): array {
        $text = trim( $text );
        if ( empty( $text ) ) return [];

        // ── Structural Chunking ──
        // Instead of characters, we split by logical medical document blocks.
        // Look for: Procedure:, Required For:, Skills Required:, SITM:, or generic Level/Level Headers
        $split_pattern = "/(?=(?:Procedure:|SITM:|Required\sFor:|Skills\sRequired:|Level\s\d+|Introduction|Assessment|Mandatory\sUltrasound))/i";
        $raw_parts      = preg_split( $split_pattern, $text );

        if ( ! $raw_parts ) return [ $text ];

        $chunks       = [];
        $current_chunk = '';

        foreach ( $raw_parts as $part ) {
            $part = trim( $part );
            if ( empty( $part ) ) continue;

            // If adding this part exceeds size, push current and start new
            if ( mb_strlen( $current_chunk . $part ) > $size && ! empty( $current_chunk ) ) {
                $chunks[]      = $current_chunk;
                $current_chunk = mb_substr( $current_chunk, -$overlap ) . $part;
            } else {
                $current_chunk .= ( empty( $current_chunk ) ? '' : "\n\n" ) . $part;
            }
        }

        if ( ! empty( $current_chunk ) ) {
            $chunks[] = $current_chunk;
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
    public static function insert( int $post_id, string $post_type, string $content, array $image_urls = [], string $mode = 'guideline', string $topic_slug = '', string $country = '', string $source_name = '', string $source_url = '' ) {
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
        global $wpdb;
        $table        = self::table();
        $knowledge_id = wp_generate_uuid4();
        $provider     = AIProvider::get_name();
        $model        = AIProvider::get_embedding_model();
        $modified_gmt = current_time( 'mysql', true );
        $redis        = RedisManager::instance();

        // ── Prevention of Duplicates ──
        if ( $post_id > 0 ) {
            $redis->delete_vectors_by_post( $post_id );
        }

        // ── Resolve Chunks ──
        $chunks_data = [];
        if ( $post_type === 'jsonl' ) {
            $lines = explode( "\n", $content );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( empty( $line ) ) {
                    continue;
                }
                $data = json_decode( $line, true );
                if ( is_array( $data ) && ! empty( $data['content'] ) ) {
                    $chunks_data[] = [
                        'text'   => $data['content'],
                        'source' => $data['source_name'] ?? $source_name,
                        'url'    => $data['source_url']  ?? $source_url,
                        'mode'   => $data['mode']        ?? $mode,
                        'images' => ! empty( $data['images'] ) ? $data['images'] : $image_urls,
                    ];
                }
            }
        } else {
            $chunks = self::split_into_chunks( $content );
            foreach ( $chunks as $c ) {
                $chunks_data[] = [
                    'text'   => $c,
                    'source' => $source_name,
                    'url'    => $source_url,
                    'mode'   => $mode,
                    'images' => $image_urls,
                ];
            }
        }

        $errors = 0;
        foreach ( $chunks_data as $idx => $data ) {
            $chunk_text   = $data['text'];
            $chunk_source = $data['source'];
            $chunk_url    = $data['url'];
            $chunk_mode   = $data['mode'];
            $chunk_images = $data['images'];

            $embedding = AIProvider::generate_embedding( $chunk_text );

            // Retry once on transient timeout errors
            if ( is_wp_error( $embedding ) && ( str_contains( $embedding->get_error_message(), 'timeout' ) || str_contains( $embedding->get_error_message(), 'timed out' ) ) ) {
                error_log( '[SonoAI] Embedding timeout for chunk ' . $idx . '. Retrying in 2s...' );
                sleep( 2 ); 
                $embedding = AIProvider::generate_embedding( $chunk_text );
            }

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
                    'image_urls'        => ! empty( $chunk_images ) ? wp_json_encode( array_values( $chunk_images ) ) : null,
                    'chunk_index'       => $idx,
                    'chunk_text'        => $chunk_text,
                    'embedding'         => wp_json_encode( $embedding ),
                    'provider'          => $provider,
                    'embedding_model'   => $model,
                    'post_modified_gmt' => $modified_gmt,
                    'mode'              => in_array( $chunk_mode, [ 'guideline', 'research' ], true ) ? $chunk_mode : 'guideline',
                    'topic_slug'        => $topic_slug ?: null,
                    'country'           => $country ?: null,
                    'source_title'      => $chunk_source ?: null,
                    'source_url'        => $chunk_url ?: null,
                ],
                [ '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            // Cache in Redis for high-performance retrieval
            if ( false !== $result ) {
                RedisManager::instance()->cache_embedding( $knowledge_id . ':' . $idx, $embedding, [
                    'knowledge_id' => $knowledge_id,
                    'post_id'      => $post_id,
                    'post_type'    => $post_type,
                    'chunk_text'   => $chunk_text,
                    'mode'         => $chunk_mode,
                    'topic_slug'   => $topic_slug,
                    'country'      => $country,
                    'source_title' => $chunk_source,
                    'source_url'   => $chunk_url,
                    'image_urls'   => $chunk_images,
                ]);
            }

            if ( false === $result ) {
                error_log( '[SonoAI] DB insert failed: ' . $wpdb->last_error );
                $errors++;
            }
        }

        if ( $post_id > 0 ) {
            // Remove old chunks for this post (different knowledge_id).
            // Scoped by BOTH provider AND model to ensure model changes fully invalidate old vectors.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `$table` WHERE post_id = %d AND knowledge_id != %s AND provider = %s AND embedding_model = %s",
                    $post_id,
                    $knowledge_id,
                    $provider,
                    $model
                )
            );

            // Also remove any old chunks from a DIFFERENT model entirely
            // (e.g., switching from text-embedding-ada-002 to text-embedding-3-small)
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `$table` WHERE post_id = %d AND embedding_model != %s",
                    $post_id,
                    $model
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
    public static function search( string $query, int $limit = 5, array $post_types = [], float $min_similarity = 0.0, string $mode = '' ) {
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

        // ── Phase 1: Redis Fast Search ──────────────────────────────────────────
        $redis_results = RedisManager::instance()->search_vectors( $query_vector, $mode, $min_similarity, $limit );
        if ( ! empty( $redis_results ) ) {
            return $redis_results;
        }

        // ── Phase 2: MySQL Fallback (Traditional) ────────────────────────────────
        global $wpdb;
        $table = self::table();

        // Fetch embeddings — filter by provider AND model to ensure dimensional compatibility.
        $where_sql = $wpdb->prepare( 'provider = %s AND embedding_model = %s', $provider, $model );
        
        if ( ! empty( $post_types ) ) {
            // Sanitize literals for IN clause
            $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
            $where_sql .= $wpdb->prepare( " AND post_type IN ($placeholders)", $post_types );
        }

        // Filter by mode if provided.
        if ( ! empty( $mode ) && in_array( $mode, [ 'guideline', 'research' ], true ) ) {
            $where_sql .= $wpdb->prepare( ' AND mode = %s', $mode );
        }

        // Note: Table name is derived from trusted prefix.
        $rows = $wpdb->get_results( "SELECT knowledge_id, post_id, post_type, chunk_text, embedding, image_urls, country, topic_slug, source_title, source_url FROM `{$wpdb->prefix}sonoai_embeddings` WHERE $where_sql", ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        // Cosine similarity, keep top-N.
        $top = [];
        foreach ( $rows as $row ) {
            $vec = json_decode( $row['embedding'], true );
            if ( ! is_array( $vec ) ) {
                continue;
            }
            $sim = sonoai_cosine_similarity( $query_vector, $vec );
            
            // Filter by minimum similarity.
            if ( $sim < $min_similarity ) {
                continue;
            }

            $candidate = [
                'knowledge_id' => $row['knowledge_id'],
                'chunk_text'   => $row['chunk_text'],
                'post_id'      => (int) $row['post_id'],
                'post_type'    => $row['post_type'],
                'image_urls'   => $row['image_urls'] ? json_decode( $row['image_urls'], true ) : [],
                'country'      => $row['country'],
                'topic_slug'   => $row['topic_slug'],
                'source_name'  => $row['source_title'],
                'source_url'   => $row['source_url'],
                'similarity'   => $sim,
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
