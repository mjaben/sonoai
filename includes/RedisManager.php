<?php
/**
 * SonoAI — Redis Memory & Vector Cache Manager.
 *
 * Provides sub-millisecond conversation memory and bridges the MySQL 
 * vector store to Redis for high-performance semantic search.
 *
 * @package SonoAI
 */

namespace SonoAI;

use Predis\Client as RedisClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RedisManager {

    private static ?RedisClient $client = null;
    private static ?RedisManager $instance = null;

    public static function instance(): RedisManager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Get the Predis client instance.
     */
    public function get_client(): ?RedisClient {
        if ( null !== self::$client ) {
            return self::$client;
        }

        // Configuration from sonoai_settings
        $enabled = sonoai_option( 'redis_enabled', false );
        if ( ! $enabled && ! defined( 'SONOAI_REDIS_FORCE' ) ) {
            return null;
        }

        $host = sonoai_option( 'redis_host', '127.0.0.1' );
        $port = (int) sonoai_option( 'redis_port', 6379 );
        $pass = sonoai_option( 'redis_password', null );

        // Allow overrides via constants
        $host = defined( 'SONOAI_REDIS_HOST' ) ? SONOAI_REDIS_HOST : $host;
        $port = defined( 'SONOAI_REDIS_PORT' ) ? SONOAI_REDIS_PORT : $port;
        $pass = defined( 'SONOAI_REDIS_PASSWORD' ) ? SONOAI_REDIS_PASSWORD : $pass;

        try {
            self::$client = new RedisClient([
                'scheme'   => 'tcp',
                'host'     => $host,
                'port'     => $port,
                'password' => $pass,
                'timeout'  => 1.0, // Slightly more generous for remote Redis
            ]);
            // Test connection
            self::$client->connect();
        } catch ( \Exception $e ) {
            error_log( '[SonoAI] Redis connection failed: ' . $e->getMessage() );
            self::$client = null;
        }

        return self::$client;
    }

    public function is_active(): bool {
        return null !== $this->get_client();
    }

    // ── Memory (Conversation History) ─────────────────────────────────────────

    /**
     * Store a message in the Redis session cache.
     */
    public function store_memory( string $session_uuid, array $message, int $ttl = 3600 ): void {
        $client = $this->get_client();
        if ( ! $client ) return;

        $key = "sonoai:memory:{$session_uuid}";
        $client->rpush( $key, wp_json_encode( $message ) );
        $client->ltrim( $key, -10, -1 ); // Keep last 10 messages for context
        $client->expire( $key, $ttl );
    }

    /**
     * Retrieve the last N messages from Redis memory.
     */
    public function get_memory( string $session_uuid, int $limit = 5 ): array {
        $client = $this->get_client();
        if ( ! $client ) return [];

        $key  = "sonoai:memory:{$session_uuid}";
        $data = $client->lrange( $key, -$limit, -1 );

        if ( empty( $data ) ) return [];

        return array_map( fn( $m ) => json_decode( $m, true ), $data );
    }

    // ── Vector Cache (Fast Semantic Search) ───────────────────────────────────

    /**
     * Cache an embedding for fast similarity search.
     */
    public function cache_embedding( string $knowledge_id, array $vector, array $meta ): void {
        $client = $this->get_client();
        if ( ! $client ) return;

        $mode = $meta['mode'] ?? 'guideline';
        $key  = "sonoai:vector:{$mode}:{$knowledge_id}";

        $client->hset( $key, 'v', wp_json_encode( $vector ) );
        $client->hset( $key, 'm', wp_json_encode( $meta ) );
        $client->expire( $key, 86400 * 7 ); // Cache for 7 days

        // Register in mode-specific set index to avoid expensive KEYS scans.
        $index_key = "sonoai:idx:{$mode}";
        $client->sadd( $index_key, [ $key ] );
        $client->expire( $index_key, 86400 * 7 );
    }

    /**
     * Perform a fast similarity search across cached vectors in Redis.
     *
     * Uses a Set index (sonoai:idx:{mode}) instead of a KEYS scan, which
     * avoids O(N) keyspace scans and scales to any KB size with standard Redis.
     */
    public function search_vectors( array $query_vector, string $mode, float $min_sim = 0.70, int $limit = 5 ): array {
        $client = $this->get_client();
        if ( ! $client ) return [];

        // Use the mode-specific set index instead of a full KEYS scan.
        $index_key = "sonoai:idx:{$mode}";
        $keys      = $client->smembers( $index_key );
        if ( empty( $keys ) ) return [];

        $results = [];
        foreach ( $keys as $key ) {
            // Verify the vector key still exists (may have expired).
            if ( ! $client->exists( $key ) ) {
                $client->srem( $index_key, $key ); // Clean up stale index entry.
                continue;
            }

            $data = $client->hgetall( $key );
            if ( empty( $data['v'] ) ) continue;

            $vector = json_decode( $data['v'], true );
            $sim    = sonoai_cosine_similarity( $query_vector, $vector );

            if ( $sim >= $min_sim ) {
                $meta = json_decode( $data['m'], true );
                $results[] = array_merge( $meta, [ 'similarity' => $sim ] );
            }
        }

        usort( $results, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
        return array_slice( $results, 0, $limit );
    }

    /**
     * Remove a specific vector from the Redis cache and its set index.
     * Call this before re-embedding or deleting a KB item.
     *
     * @param string $knowledge_id The knowledge_id:chunk_index key fragment.
     * @param string $mode         The mode the vector was indexed under.
     */
    public function evict_vector( string $knowledge_id, string $mode ): void {
        $client = $this->get_client();
        if ( ! $client ) return;

        // knowledge_id may be a prefix — evict all matching chunks from the index.
        $index_key = "sonoai:idx:{$mode}";
        $all_keys  = $client->smembers( $index_key );

        foreach ( $all_keys as $key ) {
            if ( str_contains( $key, $knowledge_id ) ) {
                $client->del( $key );
                $client->srem( $index_key, $key );
            }
        }
    }

    /**
     * Clear all cached vectors and their set indexes.
     */
    public function flush_vectors(): void {
        $client = $this->get_client();
        if ( ! $client ) return;

        foreach ( [ 'guideline', 'research' ] as $mode ) {
            $index_key = "sonoai:idx:{$mode}";
            $keys      = $client->smembers( $index_key );
            if ( ! empty( $keys ) ) {
                foreach ( $keys as $key ) {
                    $client->del( $key );
                }
            }
            $client->del( $index_key );
        }
    }
}
