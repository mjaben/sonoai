<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use stdClass;
use WP_Query;
use Antimanual\AIProvider;

/**
 * Embedding Manager Class
 *
 * Handles creation, storage, and retrieval of vector embeddings for
 * knowledge base content (posts, PDFs, URLs, etc.).
 *
 * @package Antimanual
 */
class Embedding {
    private static $instance   = null;
	private static $table_name = 'antimanual_embeddings';

    private function __construct() {}

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

    /**
     * Create the embeddings table if it doesn't exist.
     */
    public static function create_table() {
		global             $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`knowledge_id` VARCHAR(36) NOT NULL,
            `post_id` BIGINT UNSIGNED NOT NULL,
            `chunk_index` INT NOT NULL,
            `chunk_text` LONGTEXT NOT NULL,
            `embedding` LONGTEXT NOT NULL,
			`post_modified_gmt` DATETIME DEFAULT CURRENT_TIMESTAMP,
			`type` VARCHAR(50) DEFAULT 'wp',
			`url` TEXT,
            PRIMARY KEY (`id`)
        ) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		$columns_to_add = [
			'post_modified_gmt',
			'type',
			'url',
			'knowledge_id',
			'provider',
			'embedding_model',
		];

		$existing_columns = $wpdb->get_col(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ),
			0
		);

		foreach ( $columns_to_add as $column ) {
			if ( ! in_array( $column, $existing_columns ) ) {
				switch ( $column ) {
					case 'post_modified_gmt':
						$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD `post_modified_gmt` DATETIME DEFAULT CURRENT_TIMESTAMP', $table_name ) );
						break;
					case 'type':
						$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD `type` VARCHAR(50) DEFAULT 'wp'", $table_name ) );
						break;
					case 'url':
						$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD `url` TEXT', $table_name ) );
						break;
					case 'knowledge_id':
						$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD `knowledge_id` VARCHAR(36) NOT NULL AFTER `id`', $table_name ) );
						break;
					case 'provider':
						$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD `provider` VARCHAR(20) DEFAULT 'openai'", $table_name ) );
						break;
					case 'embedding_model':
						$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD `embedding_model` VARCHAR(100) DEFAULT NULL', $table_name ) );
						break;
				}
			}
		}

		// Backfill existing rows that have NULL provider (pre-Gemini entries).
		// ALTER TABLE DEFAULT only applies to new rows, not existing ones.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET `provider` = 'openai' WHERE `provider` IS NULL", $table_name ) );

		// Backfill existing rows that have NULL embedding_model.
		$openai_model = get_option( 'antimanual_openai_embedding_model', 'text-embedding-ada-002' );
		$gemini_model = get_option( 'antimanual_gemini_embedding_model', 'text-embedding-004' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `embedding_model` = %s WHERE `provider` = %s AND `embedding_model` IS NULL', $table_name, $openai_model, 'openai' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `embedding_model` = %s WHERE `provider` = %s AND `embedding_model` IS NULL', $table_name, $gemini_model, 'gemini' ) );

		self::sync_knowledge_ids();
    }

	private static function sync_knowledge_ids() {
		global $wpdb;
		$table_name = self::get_table_name();
		$chunks     = $wpdb->get_results( "SELECT * FROM `$table_name`", ARRAY_A );

		$knowledge_map = [];

		foreach ( $chunks as $c ) {
			if ( ! empty( $c['knowledge_id'] ) ) {
				if ( ! empty( $c['post_id'] ) ) {
					$key = $c['type'] . '|p|' . $c['post_id'];
					if ( ! isset( $knowledge_map[ $key ] ) ) {
						$knowledge_map[ $key ] = $c['knowledge_id'];
					}
				}
				if ( ! empty( $c['url'] ) ) {
					$key = $c['type'] . '|u|' . $c['url'];
					if ( ! isset( $knowledge_map[ $key ] ) ) {
						$knowledge_map[ $key ] = $c['knowledge_id'];
					}
				}
			}
		}

		foreach ( $chunks as $i => $chunk ) {
			if ( empty( $chunk['knowledge_id'] ) ) {
				$knowledge_id = null;

				if ( ! empty( $chunk['post_id'] ) ) {
					$key = $chunk['type'] . '|p|' . $chunk['post_id'];
					if ( isset( $knowledge_map[ $key ] ) ) {
						$knowledge_id = $knowledge_map[ $key ];
					}
				}

				if ( empty( $knowledge_id ) && ! empty( $chunk['url'] ) ) {
					$key = $chunk['type'] . '|u|' . $chunk['url'];
					if ( isset( $knowledge_map[ $key ] ) ) {
						$knowledge_id = $knowledge_map[ $key ];
					}
				}

				if ( empty( $knowledge_id ) ) {
					$knowledge_id = wp_generate_uuid4();

					if ( ! empty( $chunk['post_id'] ) ) {
						$key                   = $chunk['type'] . '|p|' . $chunk['post_id'];
						$knowledge_map[ $key ] = $knowledge_id;
					}
					if ( ! empty( $chunk['url'] ) ) {
						$key                   = $chunk['type'] . '|u|' . $chunk['url'];
						$knowledge_map[ $key ] = $knowledge_id;
					}
				}

				$wpdb->update(
					$table_name,
					[ 'knowledge_id' => $knowledge_id ],
					[ 'id' => $chunk['id'] ],
					[ '%s' ],
				);

				$chunks[ $i ]['knowledge_id'] = $knowledge_id;
			}
		}
	}

	public static function split_into_chunks( $text ) {
		$chunk_size    = 700;
		$overlap       = 100;

		$text_length   = strlen( $text );
		$total         = ceil( $text_length / $chunk_size );
		$chunk_size    = ceil( $text_length / $total );

		$chunks        = [];

		for ( $i = 0; $i < $total; $i++ ) {
			$chunks[] = substr( $text, $i * $chunk_size, $chunk_size + $overlap );
		}

		return $chunks;
	}

	/**
	 * Insert a new embedding.
	 *
	 * @param array $data Embedding data (content, type, post_id, etc.).
	 * @return array|\WP_Error Array of chunks on success, WP_Error on failure.
	 */
	public static function insert( $data ) {
		$content = $data['content'] ?? ''; // (required)
		$type    = $data['type'] ?? 'txt';  // (optional) 'wp' | 'pdf' | 'url' | 'txt'
		$url     = $data['url'] ?? '';     // (optional if type is 'wp' or 'txt')
		$post_id = $data['post_id'] ?? 0;  // (required if type is 'wp')

		if ( ! in_array( $type, [ 'wp', 'pdf', 'url', 'txt', 'github' ], true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid type parameter. Valid types are: wp, pdf, url, txt, github.', 'antimanual' ) );
		}

		$content = trim( $content );

		if ( empty( $content ) ) {
			return new \WP_Error( 'missing_text', __( 'Missing content parameter.', 'antimanual' ) );
		}

		if ( 'wp' === $type && $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'Post ID is required for WP type.', 'antimanual' ) );
		}

		if ( ( 'pdf' === $type || 'url' === $type || 'github' === $type ) && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid URL parameter.', 'antimanual' ) );
		}

		global $wpdb;
		$table_name      = self::get_table_name();
		$active_provider = AIProvider::get_name();
		$active_model    = AIProvider::get_embedding_model();

		$knowledge_id = wp_generate_uuid4();
		$chunks       = self::split_into_chunks( $content );
		$chunks       = array_map( fn( $chunk ) => [ 'content' => $chunk ], $chunks );

		foreach ( $chunks as $i => $chunk ) {
			$chunk['inserted'] = false;

			$embedding = AIProvider::generate_embedding( $chunk['content'] );

			if ( is_wp_error( $embedding ) ) {
				error_log( '[Antimanual] KB embedding failed for type=' . $type . ': ' . $embedding->get_error_message() );
				return $embedding;
			}

			$result = $wpdb->insert(
				$table_name,
				[
					'knowledge_id'      => $knowledge_id,
					'chunk_index'       => $i,
					'chunk_text'        => $chunk['content'],
					'embedding'         => wp_json_encode( $embedding ),
					'type'              => $type,
					'post_id'           => $post_id,
					'url'               => $url,
					'post_modified_gmt' => current_time( 'mysql', 1 ),
					'provider'          => $active_provider,
					'embedding_model'   => $active_model,
				],
				[ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
			);

			if ( false !== $result ) {
				$chunk['inserted'] = true;
			} else {
				error_log( '[Antimanual] KB chunk insert failed. DB error: ' . ( $wpdb->last_error ?: 'none' ) );
			}

			$chunks[ $i ] = $chunk;
		}

		$inserted = count( array_filter( $chunks, fn( $chunk ) => $chunk['inserted'] ) );

		if ( $inserted === 0 ) {
			$error_message = __( 'Failed to insert content into the knowledge base.', 'antimanual' );

			// Check if the query failed.
			if ( ! empty( $wpdb->last_error ) ) {
				$error_message .= ' ' . sprintf(
					/* translators: %s: database error message */
					__( 'Database error: %s', 'antimanual' ),
					$wpdb->last_error
				);
			} elseif ( empty( $chunks ) ) {
				$error_message = __( 'No content chunks were generated from the input.', 'antimanual' );
			}

			return new \WP_Error( 'insertion_failed', $error_message );
		}

		if ( 'wp' === $type ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `$table_name` WHERE post_id = %d AND knowledge_id != %s AND provider = %s AND embedding_model = %s",
					$post_id,
					$knowledge_id,
					$active_provider,
					$active_model
				)
			);
		}

		if ( 'url' === $type || 'github' === $type ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `$table_name` WHERE url = %s AND knowledge_id != %s AND provider = %s AND embedding_model = %s",
					$url,
					$knowledge_id,
					$active_provider,
					$active_model
				)
			);
		}

		return $chunks;
	}

	public static function delete_knowledge( $knowledge_id ) {
		$knowledge_id = is_string( $knowledge_id ) ? trim( $knowledge_id ) : '';

		if ( empty( $knowledge_id ) ) {
			return new \WP_Error( 'invalid_knowledge_id', __( 'knowledge_id is required.', 'antimanual' ) );
		}

		global $wpdb;
		$table_name = self::get_table_name();

		$knowledge = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT type, post_id, url FROM `$table_name` WHERE knowledge_id = %s",
				$knowledge_id
			)
		);

		if ( empty( $knowledge ) ) {
			return new \WP_Error( 'knowledge_not_found', __( 'Knowledge entry not found.', 'antimanual' ) );
		}

		if ( 'wp' === $knowledge->type ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `$table_name` WHERE post_id = %d",
					$knowledge->post_id
				)
			);
		} elseif ( 'url' === $knowledge->type || 'github' === $knowledge->type ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `$table_name` WHERE url = %s",
					$knowledge->url
				)
			);
		} else {
			$wpdb->delete(
				$table_name,
				[ 'knowledge_id' => $knowledge_id ],
				[ '%s' ]
			);
		}

		return true;
	}

	public static function list_by_type( $type, $post_type = 'post', $offset = 0, $limit = 10 ) {
		$type = sanitize_key( (string) $type );

		if ( ! in_array( $type, array( 'wp', 'pdf', 'url', 'txt', 'github' ), true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid type parameter.', 'antimanual' ) );
		}

		$post_type = sanitize_key( (string) $post_type );

		global $wpdb;
		$table_name = self::get_table_name();

		$chunks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, knowledge_id, `type`, `url`, post_id, chunk_index, chunk_text, post_modified_gmt, `provider` FROM `$table_name` WHERE `type` = %s",
				$type,
			),
			ARRAY_A
		);

		// Step 1: Group chunks by knowledge_id.
		$knowledge_groups = array();

		foreach ( $chunks as $chunk ) {
			$kid = $chunk['knowledge_id'];

			if ( isset( $knowledge_groups[ $kid ] ) ) {
				$knowledge_groups[ $kid ]['chunks'][] = $chunk;
				continue;
			}

			$reference = self::get_chunk_reference( $chunk );
			$knowledge_groups[ $kid ] = array(
				'id'        => $kid,
				'title'     => $reference['title'],
				'link'      => $reference['link'],
				'edit_link' => $reference['edit_link'],
				'modified'  => $chunk['post_modified_gmt'],
				'type'      => $chunk['type'],
				'post_id'   => (int) $chunk['post_id'],
				'url'       => $chunk['url'] ?? '',
				'provider'  => $chunk['provider'] ?? 'openai',
				'chunks'    => array( $chunk ),
			);
		}

		// Step 2: Merge entries that share the same natural key.
		// WP items share post_id, URL-based items share url.
		$merged = array();

		foreach ( $knowledge_groups as $kid => $row ) {
			if ( 'wp' === $type && $row['post_id'] > 0 ) {
				$natural_key = 'post_' . $row['post_id'];
			} elseif ( ! empty( $row['url'] ) ) {
				$natural_key = $row['url'];
			} else {
				$natural_key = $kid;
			}

			if ( isset( $merged[ $natural_key ] ) ) {
				// Add provider if not already present.
				if ( ! in_array( $row['provider'], $merged[ $natural_key ]['providers'], true ) ) {
					$merged[ $natural_key ]['providers'][] = $row['provider'];
				}
				// Use most recent modified date.
				if ( $row['modified'] > $merged[ $natural_key ]['modified'] ) {
					$merged[ $natural_key ]['modified'] = $row['modified'];
				}
			} else {
				$row['providers'] = array( $row['provider'] );
				$merged[ $natural_key ] = $row;
			}
		}

		$rows = array_values( $merged );

		// Respect post-type filtering for WP knowledge entries.
		if ( 'wp' === $type && ! empty( $post_type ) && 'all' !== $post_type ) {
			$rows = array_values(
				array_filter(
					$rows,
					function( $row ) use ( $post_type ) {
						$row_post_id = intval( $row['post_id'] ?? 0 );
						if ( $row_post_id <= 0 ) {
							return false;
						}

						return get_post_type( $row_post_id ) === $post_type;
					}
				)
			);
		}

		return $rows;
	}

	public static function get_chunk_reference( $chunk ) {
		$chunk = (array) $chunk;

		$type      = $chunk['type'] ?? '';
		$post_id   = isset( $chunk['post_id'] ) ? (int) $chunk['post_id'] : -1;
		$url	   = $chunk['url'] ?? '';

		$title     = ($post_id > 0) ? get_the_title( $post_id ) : '';
		$link      = ($post_id > 0) ? get_permalink( $post_id ) : '';
		$edit_link = ($post_id > 0) ? get_edit_post_link( $post_id ) : '';

		if ( ( 'pdf' === $type || 'url' === $type ) && ! empty( $url ) ) {
			$title = basename( $url );
			$link  = $url;
		}

		if ( 'github' === $type && ! empty( $url ) ) {
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			if ( ! empty( $path ) ) {
				$title = $path;
				$link  = $url;
			}
		}

		if ( 'txt' === $type ) {
			$title = wp_trim_words( $chunk['chunk_text'] ?? '', 20, '...' );
		}

		return [
			'title'     => $title ?: __( 'Untitled', 'antimanual' ),
			'link'      => $link ?: '#',
			'edit_link' => $edit_link ?: '',
		];
	}

	/**
	 * Get knowledge base stats counting unique items by natural key.
	 *
	 * Counts unique posts (by post_id), unique URLs (by url), and unique
	 * text entries (by knowledge_id) across all providers to match the
	 * merged list view.
	 *
	 * @return array Stats per type.
	 */
	public static function get_stats() {
		global $wpdb;
		$table_name = self::get_table_name();

		$stat = array();

		// WP: count unique posts by post_id.
		$wp_count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM `$table_name` WHERE `type` = 'wp' AND post_id > 0"
		);
		if ( $wp_count > 0 ) {
			$stat[] = array( 'type' => 'wp', 'total' => $wp_count );
		}

		// URL-based types: count unique URLs.
		$url_stats = $wpdb->get_results(
			"SELECT type, COUNT(DISTINCT url) as total FROM `$table_name` WHERE `type` IN ('url', 'pdf', 'github') AND url != '' GROUP BY type",
			ARRAY_A
		);
		foreach ( $url_stats as $row ) {
			$stat[] = array( 'type' => $row['type'], 'total' => (int) $row['total'] );
		}

		// TXT: count unique entries by knowledge_id (no natural key).
		$txt_count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT knowledge_id) FROM `$table_name` WHERE `type` = 'txt'"
		);
		if ( $txt_count > 0 ) {
			$stat[] = array( 'type' => 'txt', 'total' => $txt_count );
		}

		return $stat;
	}

	/**
	 * Get WordPress KB item counts grouped by post type.
	 *
	 * Counts distinct knowledge entries per post type (post, page, docs, etc.)
	 * for the currently active AI provider.
	 *
	 * @since 2.3.0
	 * @return array Associative array of post_type => count.
	 */
	public static function get_wp_post_type_counts() {
		global $wpdb;
		$table_name = self::get_table_name();

		$results = $wpdb->get_results(
			"SELECT p.post_type, COUNT(DISTINCT e.post_id) as total
			FROM `$table_name` e
			INNER JOIN `{$wpdb->posts}` p ON e.post_id = p.ID
			WHERE e.type = 'wp' AND e.post_id > 0
			GROUP BY p.post_type",
			ARRAY_A
		);

		$counts = array();
		foreach ( $results as $row ) {
			$counts[ $row['post_type'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Get knowledge base count for a specific provider.
	 *
	 * @param string $provider The provider name ('openai' or 'gemini').
	 * @return int Total distinct knowledge entries for the provider.
	 */
	public static function get_kb_count_for_provider( $provider ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT knowledge_id) FROM `$table_name` WHERE `provider` = %s",
				$provider
			)
		);

		return $count;
	}

	/**
	 * Get count of unmigrated knowledge base items.
	 *
	 * Counts items that exist in the non-active provider or whose embedding model
	 * doesn't match the active provider's current model, but have no matching
	 * entry in the active provider/model context.
	 * Comparison uses natural keys: post_id for WP type, url for URL/PDF/GitHub types,
	 * and count-based heuristic for TXT type.
	 *
	 * @return int Number of unmigrated KB entries.
	 */
	public static function get_other_provider_kb_count() {
		global $wpdb;
		$table_name      = self::get_table_name();
		$active_provider = AIProvider::get_name();
		$active_model    = AIProvider::get_embedding_model();

		$unmigrated = 0;

		// WP type: count distinct post_ids that either belong to another provider, OR use wrong model.
		$unmigrated += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT o.post_id)
				FROM `$table_name` o
				WHERE o.type = 'wp' AND o.post_id > 0
				  AND (o.provider != %s OR o.embedding_model != %s)
				  AND o.post_id NOT IN (
				    SELECT DISTINCT a.post_id FROM `$table_name` a
				    WHERE a.provider = %s AND a.embedding_model = %s AND a.type = 'wp' AND a.post_id > 0
				  )",
				$active_provider, $active_model,
				$active_provider, $active_model
			)
		);

		// URL-based types: count distinct type+url combos not in active context.
		$unmigrated += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT CONCAT(o.type, '|', o.url))
				FROM `$table_name` o
				WHERE o.type IN ('url', 'github', 'pdf') AND o.url != ''
				  AND (o.provider != %s OR o.embedding_model != %s)
				  AND NOT EXISTS (
				    SELECT 1 FROM `$table_name` a
				    WHERE a.provider = %s AND a.embedding_model = %s AND a.type = o.type AND a.url = o.url
				  )",
				$active_provider, $active_model,
				$active_provider, $active_model
			)
		);

		// TXT type: no natural key to match, so compare counts as heuristic.
		$other_txt_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT knowledge_id) FROM `$table_name` WHERE (provider != %s OR embedding_model != %s) AND type = 'txt'",
				$active_provider, $active_model
			)
		);
		$active_txt_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT knowledge_id) FROM `$table_name` WHERE provider = %s AND embedding_model = %s AND type = 'txt'",
				$active_provider, $active_model
			)
		);

		if ( $other_txt_count > $active_txt_count ) {
			$unmigrated += ( $other_txt_count - $active_txt_count );
		}

		return $unmigrated;
	}

	/**
	 * Get the list of unmigrated knowledge base items.
	 *
	 * Returns items that exist in a non-active provider or whose embedding model
	 * doesn't match the active provider's current model, and have no matching
	 * entry in the active provider/model context.
	 *
	 * @return array List of unmigrated items with knowledge_id, title, and type.
	 */
	public static function get_unmigrated_items() {
		global $wpdb;
		$table_name      = self::get_table_name();
		$active_provider = AIProvider::get_name();
		$active_model    = AIProvider::get_embedding_model();

		// Get all chunks that are outdated, grouped by knowledge_id.
		$chunks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT knowledge_id, `type`, post_id, `url`, chunk_text, chunk_index FROM `$table_name` WHERE (`provider` != %s OR `embedding_model` != %s) ORDER BY knowledge_id, chunk_index",
				$active_provider, $active_model
			),
			ARRAY_A
		);

		if ( empty( $chunks ) ) {
			return array();
		}

		// Group chunks by knowledge_id.
		$grouped = array();
		foreach ( $chunks as $chunk ) {
			$kid = $chunk['knowledge_id'];
			if ( ! isset( $grouped[ $kid ] ) ) {
				$grouped[ $kid ] = array(
					'type'    => $chunk['type'],
					'post_id' => (int) $chunk['post_id'],
					'url'     => $chunk['url'],
					'chunk'   => $chunk,
				);
			}
		}

		// Filter out items that already have an active-provider equivalent.
		$items = array();
		foreach ( $grouped as $knowledge_id => $item ) {
			$has_active = false;

			if ( 'wp' === $item['type'] && $item['post_id'] > 0 ) {
				$has_active = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `$table_name` WHERE provider = %s AND embedding_model = %s AND type = 'wp' AND post_id = %d",
						$active_provider, $active_model, $item['post_id']
					)
				) > 0;
			} elseif ( in_array( $item['type'], array( 'url', 'github', 'pdf' ), true ) && ! empty( $item['url'] ) ) {
				$has_active = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `$table_name` WHERE provider = %s AND embedding_model = %s AND type = %s AND url = %s",
						$active_provider, $active_model, $item['type'], $item['url']
					)
				) > 0;
			}

			if ( ! $has_active ) {
				$reference = self::get_chunk_reference( $item['chunk'] );
				$items[]   = array(
					'knowledge_id' => $knowledge_id,
					'title'        => $reference['title'],
					'type'         => $item['type'],
				);
			}
		}

		return $items;
	}

	/**
	 * Migrate a single knowledge base item to the active provider and model.
	 *
	 * Re-embeds all chunks of a single knowledge entry using the active provider.
	 *
	 * @param string $knowledge_id The knowledge_id to migrate.
	 * @return array|\WP_Error Migration result on success, WP_Error on failure.
	 */
	public static function migrate_single_item( $knowledge_id ) {
		global $wpdb;
		$table_name      = self::get_table_name();
		$active_provider = AIProvider::get_name();
		$active_model    = AIProvider::get_embedding_model();

		$knowledge_id = sanitize_text_field( (string) $knowledge_id );

		if ( empty( $knowledge_id ) ) {
			return new \WP_Error( 'invalid_knowledge_id', __( 'knowledge_id is required.', 'antimanual' ) );
		}

		// Get all chunks for this knowledge_id.
		$chunks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT knowledge_id, `type`, post_id, `url`, chunk_text, chunk_index FROM `$table_name` WHERE knowledge_id = %s ORDER BY chunk_index",
				$knowledge_id
			),
			ARRAY_A
		);

		if ( empty( $chunks ) ) {
			return new \WP_Error( 'not_found', __( 'Knowledge entry not found.', 'antimanual' ) );
		}

		$first   = $chunks[0];
		$content = implode( ' ', array_column( $chunks, 'chunk_text' ) );

		if ( empty( trim( $content ) ) ) {
			return new \WP_Error( 'empty_content', __( 'Knowledge entry has no content.', 'antimanual' ) );
		}

		$result = self::insert( array(
			'content' => $content,
			'type'    => $first['type'],
			'post_id' => (int) $first['post_id'],
			'url'     => $first['url'],
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'knowledge_id' => $knowledge_id,
			'migrated'     => true,
		);
	}

	/**
	 * Get total knowledge base count (all providers, including legacy entries).
	 *
	 * @return int Total distinct knowledge entries across all providers.
	 */
	public static function get_total_kb_count() {
		global $wpdb;
		$table_name = self::get_table_name();

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT knowledge_id) FROM `$table_name`"
		);
	}
	/**
	 * Migrate knowledge base items from a different provider or obsolete model
	 * to the currently active provider and model.
	 *
	 * Re-embeds all content using the active provider. Optionally keeps
	 * the old provider's entries intact.
	 *
	 * @param bool $keep_old Whether to keep old provider entries after migration.
	 * @return array|\WP_Error Migration results on success, WP_Error on failure.
	 */
	public static function migrate_from_other_provider( $keep_old = true ) {
		global $wpdb;
		$table_name      = self::get_table_name();
		$active_provider = AIProvider::get_name();
		$active_model    = AIProvider::get_embedding_model();

		// Get all knowledge entries that are outdated, grouped by knowledge_id.
		$chunks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT knowledge_id, `type`, post_id, `url`, chunk_text, chunk_index FROM `$table_name` WHERE `provider` != %s OR `embedding_model` != %s ORDER BY knowledge_id, chunk_index",
				$active_provider, $active_model
			),
			ARRAY_A
		);

		if ( empty( $chunks ) ) {
			return new \WP_Error( 'no_items', __( 'No knowledge base items found to migrate.', 'antimanual' ) );
		}

		// Group chunks by knowledge_id to reconstruct the original content.
		$grouped = [];
		foreach ( $chunks as $chunk ) {
			$kid = $chunk['knowledge_id'];
			if ( ! isset( $grouped[ $kid ] ) ) {
				$grouped[ $kid ] = [
					'type'    => $chunk['type'],
					'post_id' => (int) $chunk['post_id'],
					'url'     => $chunk['url'],
					'chunks'  => [],
				];
			}
			$grouped[ $kid ]['chunks'][] = $chunk['chunk_text'];
		}

		$migrated = 0;
		$failed   = 0;
		$errors   = [];

		foreach ( $grouped as $knowledge_id => $item ) {
			// Reconstruct the original content from chunks.
			$content = implode( ' ', $item['chunks'] );

			if ( empty( trim( $content ) ) ) {
				$failed++;
				continue;
			}

			$result = self::insert([
				'content' => $content,
				'type'    => $item['type'],
				'post_id' => $item['post_id'],
				'url'     => $item['url'],
			]);

			if ( is_wp_error( $result ) ) {
				$failed++;
				$errors[] = $result->get_error_message();
				continue;
			}

			// Only delete old entries when not keeping both providers.
			if ( ! $keep_old ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `$table_name` WHERE knowledge_id = %s AND (provider != %s OR embedding_model != %s)",
						$knowledge_id,
						$active_provider,
						$active_model
					)
				);
			}

			$migrated++;
		}

		return [
			'migrated' => $migrated,
			'failed'   => $failed,
			'total'    => count( $grouped ),
			'errors'   => $errors,
		];
	}
}
