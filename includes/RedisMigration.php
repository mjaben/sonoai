<?php
/**
 * SonoAI — Redis VSS Migration Utility.
 *
 * Provides tools to rebuild the high-performance RediSearch index from 
 * the persistent MySQL vector store.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RedisMigration {

    /**
     * Rebuild the entire RediSearch VSS index from MySQL.
     * 
     * @return array Summary of the migration.
     */
    public static function rebuild_index(): array {
        global $wpdb;
        $table = Embedding::table();
        $offset = 0;
        $batch_size = 100;
        $total_indexed = 0;
        $errors = 0;

        // Clear existing VSS keys to avoid duplicates/stale data
        $client = RedisManager::instance()->get_client();
        if ( ! $client ) {
            return [ 'success' => false, 'message' => 'Redis not active.' ];
        }

        // 1. Wipe all existing VSS keys. Using scan is safer than keys *
        $iterator = null;
        while ( true ) {
            $result = $client->scan( $iterator, [ 'MATCH' => 'sonoai:vss:*', 'COUNT' => 100 ] );
            if ( empty( $result ) ) break;
            
            $iterator = $result[0];
            $keys     = $result[1];
            
            if ( ! empty( $keys ) ) {
                $client->del( $keys );
            }
            
            if ( $iterator == 0 ) break;
        }

        // 2. Drop and recreate index to ensure clean state
        try {
            $client->executeRaw(['FT.DROPINDEX', 'idx:sonoai_vss']);
        } catch ( \Exception $e ) {
            // Might not exist, that's fine
        }
        
        // Re-instantiating RedisManager will trigger ensure_index()
        RedisManager::instance();

        while ( true ) {
            $rows = $wpdb->get_results( 
                $wpdb->prepare( 
                    "SELECT * FROM `$table` LIMIT %d OFFSET %d", 
                    $batch_size, 
                    $offset 
                ), 
                ARRAY_A 
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                try {
                    $vector = json_decode( $row['embedding'], true );
                    $images = ! empty( $row['image_urls'] ) ? json_decode( $row['image_urls'], true ) : [];
                    
                    if ( ! is_array( $vector ) ) {
                        $errors++;
                        continue;
                    }

                    RedisManager::instance()->cache_embedding( 
                        $row['knowledge_id'] . ':' . $row['chunk_index'], 
                        $vector, 
                        [
                            'knowledge_id' => $row['knowledge_id'],
                            'post_id'      => (int) $row['post_id'],
                            'post_type'    => $row['post_type'],
                            'chunk_text'   => $row['chunk_text'],
                            'mode'         => $row['mode'],
                            'topic_slug'   => $row['topic_slug'],
                            'country'      => $row['country'],
                            'source_title' => $row['source_title'],
                            'source_url'   => $row['source_url'],
                            'image_urls'   => $images,
                        ] 
                    );
                    $total_indexed++;
                } catch ( \Exception $e ) {
                    $errors++;
                }
            }

            $offset += $batch_size;
        }

        return [
            'success' => true,
            'indexed' => $total_indexed,
            'errors'  => $errors
        ];
    }

    /**
     * Rebuild the VSS index from MySQL in batches.
     * 
     * @param int $offset The offset to start from.
     * @param int $batch_size The number of rows to process.
     * @param bool $is_first Whether this is the first batch (clears existing data).
     * @return array Summary of the migration batch.
     */
    public static function sync_batch(int $offset = 0, int $batch_size = 100, bool $is_first = false): array {
        global $wpdb;
        $table = Embedding::table();
        $total_indexed = 0;
        $errors = 0;

        $client = RedisManager::instance()->get_client();
        if ( ! $client ) {
            return [ 'success' => false, 'message' => 'Redis not active.' ];
        }

        // Only clear keys and drop index on the first batch
        if ( $is_first ) {
            $iterator = null;
            while ( true ) {
                $result = $client->scan( $iterator, [ 'MATCH' => 'sonoai:vss:*', 'COUNT' => 100 ] );
                if ( empty( $result ) ) break;
                
                $iterator = $result[0];
                $keys     = $result[1];
                
                if ( ! empty( $keys ) ) {
                    $client->del( $keys );
                }
                
                if ( $iterator == 0 ) break;
            }

            try {
                $client->executeRaw(['FT.DROPINDEX', 'idx:sonoai_vss']);
            } catch ( \Exception $e ) {
                // Might not exist, that's fine
            }
            
            // Recreate the index immediately after dropping it
            RedisManager::instance()->ensure_index();
        }

        $total_records = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );

        $rows = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT * FROM `$table` LIMIT %d OFFSET %d", 
                $batch_size, 
                $offset 
            ), 
            ARRAY_A 
        );

        if ( empty( $rows ) ) {
            return [
                'success'      => true,
                'indexed'      => 0,
                'errors'       => 0,
                'total'        => $total_records,
                'done'         => true,
                'current_item' => ''
            ];
        }

        $current_item = '';

        foreach ( $rows as $row ) {
            try {
                $vector = json_decode( $row['embedding'], true );
                $images = ! empty( $row['image_urls'] ) ? json_decode( $row['image_urls'], true ) : [];
                
                if ( ! is_array( $vector ) ) {
                    $errors++;
                    continue;
                }

                RedisManager::instance()->cache_embedding( 
                    $row['knowledge_id'] . ':' . $row['chunk_index'], 
                    $vector, 
                    [
                        'knowledge_id' => $row['knowledge_id'],
                        'post_id'      => (int) $row['post_id'],
                        'post_type'    => $row['post_type'],
                        'chunk_text'   => $row['chunk_text'],
                        'mode'         => $row['mode'],
                        'topic_slug'   => $row['topic_slug'],
                        'country'      => $row['country'],
                        'source_title' => $row['source_title'],
                        'source_url'   => $row['source_url'],
                        'image_urls'   => $images,
                    ] 
                );
                $total_indexed++;
                $current_item = !empty($row['source_title']) ? $row['source_title'] : 'Knowledge Chunk #' . $row['knowledge_id'];
            } catch ( \Exception $e ) {
                $errors++;
            }
        }

        $next_offset = $offset + $batch_size;
        $done = $next_offset >= $total_records;

        return [
            'success'      => true,
            'indexed'      => $total_indexed,
            'errors'       => $errors,
            'total'        => $total_records,
            'done'         => $done,
            'next_offset'  => $next_offset,
            'current_item' => $current_item
        ];
    }
}
