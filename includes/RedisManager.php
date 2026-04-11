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

    private function __construct() {
        $this->ensure_index();
    }

    /**
     * Ensure the RediSearch VSS index exists.
     */
    private function ensure_index(): void {
        $client = $this->get_client();
        if ( ! $client ) return;

        try {
            // Check if index exists
            $client->executeRaw(['FT.INFO', 'idx:sonoai_vss']);
        } catch ( \Exception $e ) {
            // Index doesn't exist, create it
            // Dimensions: OpenAI 1536, Gemini 768. We'll use 1536 as the default if not detectable.
            // Using HNSW for high-performance ANN search.
            try {
                $client->executeRaw([
                    'FT.CREATE', 'idx:sonoai_vss',
                    'ON', 'HASH',
                    'PREFIX', '1', 'sonoai:vss:',
                    'SCHEMA',
                    'v', 'VECTOR', 'HNSW', '6', 'TYPE', 'FLOAT32', 'DIM', '1536', 'DISTANCE_METRIC', 'COSINE',
                    'mode', 'TAG',
                    'post_id', 'NUMERIC'
                ]);
            } catch ( \Exception $inner ) {
                error_log( '[SonoAI] RediSearch Index Creation Failed: ' . $inner->getMessage() );
            }
        }
    }

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
            return null; // Silent return if disabled
        }

        $host = sonoai_option( 'redis_host', '127.0.0.1' );
        $port = (int) sonoai_option( 'redis_port', 6379 );
        $pass = sonoai_option( 'redis_password', null );

        // Allow overrides via constants
        $host = defined( 'SONOAI_REDIS_HOST' ) ? SONOAI_REDIS_HOST : $host;
        $port = defined( 'SONOAI_REDIS_PORT' ) ? SONOAI_REDIS_PORT : $port;
        $pass = defined( 'SONOAI_REDIS_PASSWORD' ) ? SONOAI_REDIS_PASSWORD : $pass;
        $scheme = defined( 'SONOAI_REDIS_SCHEME' ) ? SONOAI_REDIS_SCHEME : 'tcp';

        $connection_params = [
            'scheme'  => $scheme,
            'host'    => $host,
            'port'    => $port,
            'timeout' => 5.0, // Generous 5-second timeout for remote production servers
        ];

        if ( ! empty( $pass ) ) {
            $connection_params['password'] = $pass;
        }

        $client_options = [];
        if ( $scheme === 'tls' ) {
            $client_options['ssl'] = ['verify_peer' => false]; // Common for managed Redis with self-signed or pooled certs
        }

        if ( ! class_exists( 'Predis\Client' ) ) {
            error_log( '[SonoAI] Redis Error: Predis\Client class not found. Ensure the vendor folder was uploaded correctly.' );
            return null;
        }

        try {
            error_log( sprintf( '[SonoAI] Redis: Attempting connection to %s://%s:%d (Timeout: 5s)', $scheme, $host, $port ) );
            self::$client = new RedisClient( $connection_params, $client_options );
            // Test connection
            self::$client->connect();
            error_log( '[SonoAI] Redis: Connection successful.' );
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

        // VSS Format: Binary vector blob
        $binary_vector = pack( 'f*', ...$vector );
        
        $key = "sonoai:vss:{$knowledge_id}";
        $client->hset( $key, 'v', $binary_vector );
        $client->hset( $key, 'mode', $meta['mode'] );
        $client->hset( $key, 'post_id', $meta['post_id'] );
        $client->hset( $key, 'm', wp_json_encode( $meta ) );
        $client->expire( $key, 86400 * 7 ); // Cache for 7 days
    }

    /**
     * Perform a fast similarity search across cached vectors in Redis.
     * Note: This uses a simple brute-force loop for small-to-medium sets.
     * For large scale, we would use RediSearch FT.SEARCH.
     */
    public function search_vectors( array $query_vector, string $mode, float $min_sim = 0.70, int $limit = 5 ): array {
        $client = $this->get_client();
        if ( ! $client ) return [];

        $binary_query = pack( 'f*', ...$query_vector );
        
        // RediSearch KNN Query: Filter by mode TAG, then perform Vector Search
        // Query format: "@mode:{guideline} => [KNN $limit @v $binary_query AS score]"
        $query = sprintf( '@mode:{%s} => [KNN %d @v $v_blob AS score]', $mode, $limit );
        
        try {
            $response = $client->executeRaw([
                'FT.SEARCH', 'idx:sonoai_vss',
                $query,
                'PARAMS', '2', 'v_blob', $binary_query,
                'DIALECT', '2',
                'LIMIT', '0', $limit
            ]);

            if ( empty( $response ) || ! is_array( $response ) || $response[0] === 0 ) {
                return [];
            }

            $results = [];
            $count = $response[0];
            
            // FT.SEARCH returns [count, key1, [fields...], key2, [fields...]]
            for ( $i = 1; $i < count( $response ); $i += 2 ) {
                $fields = $response[ $i + 1 ];
                $item = [];
                for ( $j = 0; $j < count( $fields ); $j += 2 ) {
                    $item[ $fields[$j] ] = $fields[ $j + 1 ];
                }

                if ( ! empty( $item['m'] ) ) {
                    $meta = json_decode( $item['m'], true );
                    $score = 1 - (float) $item['score']; // Convert distance to similarity
                    
                    if ( $score >= $min_sim ) {
                        $results[] = array_merge( $meta, [ 'similarity' => $score ] );
                    }
                }
            }
            
            return $results;

        } catch ( \Exception $e ) {
            error_log( '[SonoAI] RediSearch Search Failed: ' . $e->getMessage() );
            return []; // Fallback to empty if VSS fails
        }
    }

    /**
     * Clear all cached vectors.
     */
    public function flush_vectors(): void {
        $client = $this->get_client();
        if ( ! $client ) return;
        $keys = $client->keys( 'sonoai:vector:*' );
        if ( ! empty( $keys ) ) {
            foreach ( $keys as $key ) $client->del( $key );
        }
    }
}
