<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Subscribers Database Table Manager
 *
	 * Manages the custom table for email campaign subscribers.
 *
 * @package Antimanual
 */
class EmailSubscribers {
	private static $table_name = 'atml_email_subscribers';

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	/**
	 * Create the subscribers table.
	 */
	public static function create_table() {
		global $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			name varchar(255) DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			source varchar(50) NOT NULL DEFAULT 'manual',
			tags text DEFAULT '',
			subscription_types text DEFAULT '',
			custom_fields longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			unsubscribed_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY email (email),
			KEY status (status),
			KEY source (source)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure the table exists before queries.
	 */
	public static function ensure_table_exists() {
		global $wpdb;
		$table_name = self::get_table_name();
		$current_version = '1.1';
		$installed       = get_option( 'atml_email_subscribers_db_version', '0' );
		$table_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( ! $table_exists || version_compare( $installed, $current_version, '<' ) ) {
			self::create_table();

			if ( $table_exists ) {
				$has_subscription_types = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table_name` LIKE %s", 'subscription_types' ) );
				$has_custom_fields      = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table_name` LIKE %s", 'custom_fields' ) );

				if ( ! $has_subscription_types ) {
					$wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN subscription_types text DEFAULT '' AFTER tags" );
				}

				if ( ! $has_custom_fields ) {
					$wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN custom_fields longtext DEFAULT NULL AFTER subscription_types" );
				}
			}

			update_option( 'atml_email_subscribers_db_version', $current_version, false );
		}
	}

	/**
	 * Get the default subscription type options.
	 *
	 * @return array<string, string>
	 */
	public static function get_subscription_type_options() {
		return [
			'newsletter' => __( 'Newsletter', 'antimanual' ),
			'product_updates' => __( 'Product Updates', 'antimanual' ),
			'promotions' => __( 'Promotions', 'antimanual' ),
			'events' => __( 'Events', 'antimanual' ),
		];
	}

	/**
	 * Add a single subscriber.
	 *
	 * @param string $email Subscriber email.
	 * @param string $name  Subscriber name.
	 * @param string       $source Source (manual, import, chatbot).
	 * @param string|array $tags   Comma-separated tags or list IDs.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function add( $email, $name = '', $source = 'manual', $tags = '', $subscription_types = [], array $custom_fields = [] ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$normalized_tags               = self::normalize_tags( $tags );
		$normalized_subscription_types = self::normalize_subscription_types( $subscription_types );
		$normalized_custom_fields      = self::normalize_custom_fields( $custom_fields );

		// Check for existing subscriber.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_name WHERE email = %s LIMIT 1",
			$email
		) );

		if ( $existing ) {
			$existing_tags = $wpdb->get_var( $wpdb->prepare(
				"SELECT tags FROM $table_name WHERE id = %d LIMIT 1",
				$existing
			) );
			$existing_subscription_types = $wpdb->get_var( $wpdb->prepare(
				"SELECT subscription_types FROM $table_name WHERE id = %d LIMIT 1",
				$existing
			) );
			$existing_custom_fields = $wpdb->get_var( $wpdb->prepare(
				"SELECT custom_fields FROM $table_name WHERE id = %d LIMIT 1",
				$existing
			) );

			$merged_tags               = self::merge_tags( $existing_tags, $normalized_tags );
			$merged_subscription_types = self::merge_subscription_types( $existing_subscription_types, $normalized_subscription_types );
			$merged_custom_fields      = self::merge_custom_fields( $existing_custom_fields, $normalized_custom_fields );

			// Re-activate if previously unsubscribed.
			$wpdb->update(
				$table_name,
				[
					'status'          => 'active',
					'name'            => sanitize_text_field( $name ),
					'tags'            => $merged_tags,
					'subscription_types' => $merged_subscription_types,
					'custom_fields'      => self::encode_custom_fields( $merged_custom_fields ),
					'unsubscribed_at' => null,
				],
				[ 'id' => $existing ],
				[ '%s', '%s', '%s', '%s', '%s', null ],
				[ '%d' ]
			);
			return (int) $existing;
		}

		$wpdb->insert(
			$table_name,
			[
				'email'              => $email,
				'name'               => sanitize_text_field( $name ),
				'status'             => 'active',
				'source'             => sanitize_key( $source ),
				'tags'               => $normalized_tags,
				'subscription_types' => $normalized_subscription_types,
				'custom_fields'      => self::encode_custom_fields( $normalized_custom_fields ),
				'created_at'         => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $wpdb->insert_id ?: false;
	}

	/**
	 * Import subscribers from a CSV-style array.
	 *
	 * @param array  $rows                      Each row: [ 'email' => '', 'name' => '' ].
	 * @param string $source                    Import source label.
	 * @param array  $tags                      Optional list IDs applied to all imported subscribers.
	 * @param array  $default_subscription_types Default subscription types for all rows.
	 * @param array  $default_custom_fields      Default custom fields for all rows.
	 * @param string $duplicate_mode             How to handle existing contacts: 'skip' or 'replace'.
	 * @return array [ 'imported' => int, 'skipped' => int, 'errors' => int ]
	 */
	public static function import( array $rows, $source = 'import', array $tags = [], $default_subscription_types = [], array $default_custom_fields = [], $duplicate_mode = 'skip' ) {
		$result = [ 'imported' => 0, 'skipped' => 0, 'errors' => 0 ];

		// In skip mode, batch-fetch all existing emails upfront to avoid per-row queries.
		$existing_emails = [];
		if ( 'skip' === $duplicate_mode ) {
			$all_emails = array_values( array_filter(
				array_map( fn( $row ) => sanitize_email( $row['email'] ?? '' ), $rows ),
				'is_email'
			) );

			if ( ! empty( $all_emails ) ) {
				$existing_emails = array_flip( array_map( 'strtolower', self::find_existing_emails( $all_emails ) ) );
			}
		}

		foreach ( $rows as $row ) {
			$email              = $row['email'] ?? '';
			$name               = $row['name'] ?? '';
			$subscription_types = $row['subscription_types'] ?? $default_subscription_types;
			$custom_fields      = self::merge_custom_fields( $default_custom_fields, $row['custom_fields'] ?? [] );

			if ( empty( $email ) || ! is_email( $email ) ) {
				$result['errors']++;
				continue;
			}

			// Skip mode: skip contacts that already exist.
			if ( 'skip' === $duplicate_mode && isset( $existing_emails[ strtolower( $email ) ] ) ) {
				$result['skipped']++;
				continue;
			}

			$id = self::add( $email, $name, $source, $tags, $subscription_types, $custom_fields );
			if ( $id ) {
				$result['imported']++;
			} else {
				$result['skipped']++;
			}
		}

		return $result;
	}

	/**
	 * Generate names for subscribers who currently have no name.
	 *
	 * Uses the configured AI provider when available, with a deterministic
	 * email-local-part parser as fallback for reliability.
	 *
	 * @param array $ids Subscriber IDs to process.
	 * @return array [ 'processed' => int, 'updated' => int, 'skipped' => int, 'used_ai' => bool, 'items' => array ]
	 */
	public static function generate_missing_names( array $ids ) {
		$candidates = self::get_missing_name_subscribers( $ids );

		if ( empty( $candidates ) ) {
			return [
				'processed' => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'used_ai'   => AIProvider::has_api_key(),
				'items'     => [],
			];
		}

		$suggested_names = self::suggest_names_for_subscribers( $candidates );
		$updated         = 0;
		$items           = [];

		global $wpdb;
		$table_name = self::get_table_name();

		foreach ( $candidates as $subscriber ) {
			$id   = (int) $subscriber['id'];
			$name = \sanitize_text_field( $suggested_names[ $id ] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$result = $wpdb->update(
				$table_name,
				[ 'name' => $name ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( false === $result ) {
				continue;
			}

			$updated++;
			$items[] = [
				'id'    => $id,
				'email' => $subscriber['email'],
				'name'  => $name,
			];
		}

		return [
			'processed' => count( $candidates ),
			'updated'   => $updated,
			'skipped'   => max( count( $candidates ) - $updated, 0 ),
			'used_ai'   => AIProvider::has_api_key(),
			'items'     => $items,
		];
	}

	/**
	 * List subscribers with pagination and filters.
	 *
	 * @param array $args Query args: status, search, list, page, per_page.
	 * @return array [ 'items' => [], 'total' => int, 'pages' => int ]
	 */
	public static function list( $args = [] ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$status     = sanitize_key( $args['status'] ?? '' );
		$search     = sanitize_text_field( $args['search'] ?? '' );
		$list       = sanitize_key( $args['list'] ?? '' );
		$page       = max( 1, intval( $args['page'] ?? 1 ) );
		$per_page   = max( 1, min( 100, intval( $args['per_page'] ?? 20 ) ) );
		$offset     = ( $page - 1 ) * $per_page;
		$sort_by    = sanitize_key( $args['sort_by'] ?? 'created_at' );
		$sort_order = strtoupper( sanitize_key( $args['sort_order'] ?? 'desc' ) );

		// Allowlist sort columns to prevent SQL injection.
		$allowed_sort = [ 'email', 'name', 'status', 'created_at' ];
		if ( ! in_array( $sort_by, $allowed_sort, true ) ) {
			$sort_by = 'created_at';
		}

		if ( ! in_array( $sort_order, [ 'ASC', 'DESC' ], true ) ) {
			$sort_order = 'DESC';
		}

		$where = [];
		$params = [];

		if ( $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(email LIKE %s OR name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( '__unlisted__' === $list ) {
			$where[] = "(tags IS NULL OR tags = '')";
		} elseif ( $list ) {
			$where[]  = 'FIND_IN_SET(%s, tags) > 0';
			$params[] = $list;
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Count total.
		$count_sql = "SELECT COUNT(*) FROM $table_name $where_clause";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: (int) $wpdb->get_var( $count_sql );

		// Fetch items.
		$query = "SELECT id, email, name, status, source, tags, subscription_types, custom_fields, created_at, unsubscribed_at
			FROM $table_name $where_clause ORDER BY $sort_by $sort_order LIMIT %d OFFSET %d";

		$query_params   = array_merge( $params, [ $per_page, $offset ] );
		$items          = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

		if ( $items ) {
			$items = array_map( [ __CLASS__, 'prepare_subscriber_record' ], $items );
		}

		return [
			'items' => $items ?: [],
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Get total subscriber count (all statuses).
	 *
	 * @return int
	 */
	public static function get_total_count() {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
	}

	/**
	 * Get active subscriber count.
	 *
	 * @return int
	 */
	public static function get_active_count() {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE status = %s",
			'active'
		) );
	}

	/**
	 * Get all active subscriber emails.
	 *
	 * @param int $limit  Batch limit.
	 * @param int $offset Batch offset.
	 * @return array
	 */
	public static function get_active( $limit = 50, $offset = 0, array $subscription_types = [] ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		if ( empty( self::normalize_subscription_types( $subscription_types ) ) ) {
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, email, name, subscription_types, custom_fields FROM $table_name WHERE status = 'active' ORDER BY id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			), ARRAY_A ) ?: [];

			return array_map( [ __CLASS__, 'prepare_subscriber_record' ], $results );
		}

		return self::get_active_by_lists( [], $limit, $offset, $subscription_types );
	}

	/**
	 * Get active subscribers filtered by specific list IDs.
	 *
	 * @param array $list_ids List IDs to filter by.
	 * @param int   $limit    Batch limit.
	 * @param int   $offset   Batch offset.
	 * @return array
	 */
	public static function get_active_by_lists( array $list_ids, $limit = 50, $offset = 0, array $subscription_types = [] ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$normalized_list_ids           = array_values( array_filter( array_map( 'sanitize_key', $list_ids ) ) );
		$normalized_subscription_types = self::normalize_subscription_types( $subscription_types );

		if ( empty( $normalized_list_ids ) && empty( $normalized_subscription_types ) ) {
			return self::get_active( $limit, $offset );
		}

		$where_groups = [];
		$params       = [];

		if ( ! empty( $normalized_list_ids ) ) {
			$list_conditions = [];

			foreach ( $normalized_list_ids as $list_id ) {
				$list_conditions[] = 'FIND_IN_SET(%s, tags) > 0';
				$params[]          = $list_id;
			}

			$where_groups[] = '(' . implode( ' OR ', $list_conditions ) . ')';
		}

		if ( ! empty( $normalized_subscription_types ) ) {
			$type_conditions = [];

			foreach ( $normalized_subscription_types as $subscription_type ) {
				$type_conditions[] = 'FIND_IN_SET(%s, subscription_types) > 0';
				$params[]          = $subscription_type;
			}

			$where_groups[] = '(' . implode( ' OR ', $type_conditions ) . ')';
		}

		$where = implode( ' AND ', $where_groups );
		$params[] = $limit;
		$params[] = $offset;

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, email, name, subscription_types, custom_fields FROM $table_name WHERE status = 'active' AND $where ORDER BY id ASC LIMIT %d OFFSET %d",
			...$params
		), ARRAY_A ) ?: [];

		return array_map( [ __CLASS__, 'prepare_subscriber_record' ], $results );
	}

	/**
	 * Get count of active subscribers filtered by specific list IDs.
	 *
	 * @param array $list_ids List IDs to filter by.
	 * @return int
	 */
	public static function get_active_count_by_lists( array $list_ids, array $subscription_types = [] ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$normalized_list_ids           = array_values( array_filter( array_map( 'sanitize_key', $list_ids ) ) );
		$normalized_subscription_types = self::normalize_subscription_types( $subscription_types );

		if ( empty( $normalized_list_ids ) && empty( $normalized_subscription_types ) ) {
			return self::get_active_count();
		}

		$where_groups = [];
		$params       = [];

		if ( ! empty( $normalized_list_ids ) ) {
			$list_conditions = [];

			foreach ( $normalized_list_ids as $list_id ) {
				$list_conditions[] = 'FIND_IN_SET(%s, tags) > 0';
				$params[]          = $list_id;
			}

			$where_groups[] = '(' . implode( ' OR ', $list_conditions ) . ')';
		}

		if ( ! empty( $normalized_subscription_types ) ) {
			$type_conditions = [];

			foreach ( $normalized_subscription_types as $subscription_type ) {
				$type_conditions[] = 'FIND_IN_SET(%s, subscription_types) > 0';
				$params[]          = $subscription_type;
			}

			$where_groups[] = '(' . implode( ' OR ', $type_conditions ) . ')';
		}

		$where = implode( ' AND ', $where_groups );

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE status = 'active' AND $where",
			...$params
		) );
	}

	/**
	 * Get all subscriber IDs that belong to a specific list.
	 *
	 * @param string $list_id List ID to match.
	 * @return array<int>
	 */
	public static function get_ids_by_list( $list_id ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$list_id = sanitize_key( $list_id );

		if ( '' === $list_id ) {
			return [];
		}

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $table_name WHERE FIND_IN_SET(%s, tags) > 0 ORDER BY id ASC",
			$list_id
		) );

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Assign a subscriber list tag to the provided subscribers.
	 *
	 * @param string $list_id List ID.
	 * @param array  $ids     Subscriber IDs.
	 * @return int Number of subscribers updated.
	 */
	public static function assign_list_to_subscribers( $list_id, array $ids ) {
		return self::update_list_membership( $list_id, $ids, true );
	}

	/**
	 * Remove a subscriber list tag from the provided subscribers.
	 *
	 * @param string $list_id List ID.
	 * @param array  $ids     Subscriber IDs.
	 * @return int Number of subscribers updated.
	 */
	public static function remove_list_from_subscribers( $list_id, array $ids ) {
		return self::update_list_membership( $list_id, $ids, false );
	}

	/**
	 * Remove a subscriber list tag from all subscribers.
	 *
	 * @param string $list_id List ID.
	 * @return int Number of subscribers updated.
	 */
	public static function remove_list_from_all( $list_id ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$list_id = sanitize_key( $list_id );

		if ( '' === $list_id ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, tags FROM $table_name WHERE FIND_IN_SET(%s, tags) > 0",
				$list_id
			),
			ARRAY_A
		) ?: [];

		$updated = 0;

		foreach ( $rows as $row ) {
			$id       = absint( $row['id'] ?? 0 );
			$new_tags = self::remove_tag( $row['tags'] ?? '', $list_id );

			if ( ! $id || self::normalize_tags( $row['tags'] ?? '' ) === $new_tags ) {
				continue;
			}

			$result = $wpdb->update(
				$table_name,
				[ 'tags' => $new_tags ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( false !== $result ) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Delete subscribers by IDs.
	 *
	 * @param array $ids Subscriber IDs.
	 * @return int Number of rows deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $table_name WHERE id IN ($placeholders)",
			...$ids
		) );
	}

	/**
	 * Delete all subscribers that belong to a specific list.
	 *
	 * @param string $list_id List ID (tag) to match.
	 * @return int Number of rows deleted.
	 */
	public static function delete_by_list( $list_id ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$list_id = sanitize_key( $list_id );

		if ( '' === $list_id ) {
			return 0;
		}

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $table_name WHERE FIND_IN_SET(%s, tags) > 0",
			$list_id
		) );
	}

	/**
	 * Count subscribers not assigned to any list.
	 *
	 * @return int
	 */
	public static function count_unlisted() {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_name WHERE tags IS NULL OR tags = ''"
		);
	}

	/**
	 * Delete all subscribers that are not assigned to any list.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function delete_unlisted() {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		return (int) $wpdb->query(
			"DELETE FROM $table_name WHERE tags IS NULL OR tags = ''"
		);
	}

	/**
	 * Unsubscribe a subscriber by email.
	 *
	 * @param string $email Subscriber email.
	 * @return bool
	 */
	public static function unsubscribe( $email ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		return (bool) $wpdb->update(
			$table_name,
			[
				'status'          => 'unsubscribed',
				'unsubscribed_at' => current_time( 'mysql', true ),
			],
			[ 'email' => sanitize_email( $email ) ],
			[ '%s', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Get subscriber stats.
	 *
	 * @return array [ 'total', 'active', 'unsubscribed' ]
	 */
	public static function get_stats() {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$stats = $wpdb->get_row(
			"SELECT
				COUNT(*) as total,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
				SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed
			FROM $table_name",
			ARRAY_A
		);

		return $stats ?: [ 'total' => 0, 'active' => 0, 'unsubscribed' => 0 ];
	}

	/**
	 * Update a subscriber by ID.
	 *
	 * @param int   $id   Subscriber ID.
	 * @param array $data Associative array of columns to update.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$id = absint( $id );

		if ( ! $id || empty( $data ) ) {
			return false;
		}

		$allowed = [ 'name', 'status', 'unsubscribed_at', 'subscription_types', 'custom_fields' ];
		$update  = [];
		$format  = [];

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			if ( 'subscription_types' === $key ) {
				$value = self::normalize_subscription_types( $value );
			}

			if ( 'custom_fields' === $key ) {
				$value = self::encode_custom_fields( self::normalize_custom_fields( $value ) );
			}

			$update[ $key ] = $value;
			$format[]       = null === $value ? null : '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update( $table_name, $update, [ 'id' => $id ], $format, [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Bulk update status for multiple subscribers.
	 *
	 * @param array  $ids    Subscriber IDs.
	 * @param string $status New status ('active' or 'unsubscribed').
	 * @return int Number of updated rows.
	 */
	public static function bulk_update_status( array $ids, $status ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$ids    = array_values( array_filter( array_map( 'absint', $ids ) ) );
		$status = sanitize_key( $status );

		if ( empty( $ids ) || ! in_array( $status, [ 'active', 'unsubscribed' ], true ) ) {
			return 0;
		}

		$placeholders    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$unsubscribed_at = 'unsubscribed' === $status ? current_time( 'mysql', true ) : null;

		if ( 'active' === $status ) {
			$query = "UPDATE $table_name SET status = %s, unsubscribed_at = NULL WHERE id IN ($placeholders)";
			return (int) $wpdb->query( $wpdb->prepare( $query, $status, ...$ids ) );
		}

		$query = "UPDATE $table_name SET status = %s, unsubscribed_at = %s WHERE id IN ($placeholders)";
		return (int) $wpdb->query( $wpdb->prepare( $query, $status, $unsubscribed_at, ...$ids ) );
	}

	/**
	 * Find which emails from a list already exist in the database.
	 *
	 * @param array $emails List of email addresses to check.
	 * @return array List of existing email addresses.
	 */
	public static function find_existing_emails( array $emails ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		if ( empty( $emails ) ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $emails ), '%s' ) );
		$results      = $wpdb->get_col( $wpdb->prepare(
			"SELECT email FROM $table_name WHERE email IN ($placeholders)",
			...$emails
		) );

		return $results ?: [];
	}

	/**
	 * Normalize a tag input into a sanitized comma-separated string.
	 *
	 * @param string|array $tags Raw tags input.
	 * @return string
	 */
	private static function normalize_tags( $tags ) {
		if ( is_array( $tags ) ) {
			$tags = implode( ',', $tags );
		}

		$parts = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', (string) $tags ) ), 'strlen' ) );
		$parts = array_values( array_unique( array_filter( $parts ) ) );

		return implode( ',', $parts );
	}

	/**
	 * Normalize subscription type input into a sanitized comma-separated string.
	 *
	 * @param string|array $types Raw types input.
	 * @return string
	 */
	private static function normalize_subscription_types( $types ) {
		if ( is_array( $types ) ) {
			$types = implode( ',', $types );
		}

		$parts = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', (string) $types ) ), 'strlen' ) );
		$parts = array_values( array_unique( array_filter( $parts ) ) );

		return implode( ',', $parts );
	}

	/**
	 * Merge subscription types and return normalized CSV.
	 *
	 * @param string|array $existing Existing types.
	 * @param string|array $incoming Incoming types.
	 * @return string
	 */
	private static function merge_subscription_types( $existing, $incoming ) {
		$combined = implode( ',', array_filter( [
			self::normalize_subscription_types( $existing ),
			self::normalize_subscription_types( $incoming ),
		], 'strlen' ) );

		return self::normalize_subscription_types( $combined );
	}

	/**
	 * Normalize arbitrary subscriber custom fields.
	 *
	 * @param mixed $custom_fields Custom field payload.
	 * @return array<string, string>
	 */
	private static function normalize_custom_fields( $custom_fields ) {
		if ( is_string( $custom_fields ) ) {
			$decoded = json_decode( $custom_fields, true );

			if ( is_array( $decoded ) ) {
				$custom_fields = $decoded;
			}
		}

		if ( ! is_array( $custom_fields ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $custom_fields as $key => $value ) {
			$normalized_key = sanitize_key( (string) $key );

			if ( '' === $normalized_key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
			}

			$value = sanitize_text_field( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$normalized[ $normalized_key ] = $value;
		}

		ksort( $normalized );

		return $normalized;
	}

	/**
	 * Merge normalized subscriber custom fields.
	 *
	 * @param mixed $existing Existing fields.
	 * @param mixed $incoming Incoming fields.
	 * @return array<string, string>
	 */
	private static function merge_custom_fields( $existing, $incoming ) {
		return array_merge(
			self::normalize_custom_fields( $existing ),
			self::normalize_custom_fields( $incoming )
		);
	}

	/**
	 * Encode normalized custom fields as JSON.
	 *
	 * @param array<string, string> $custom_fields Custom fields.
	 * @return string|null
	 */
	private static function encode_custom_fields( array $custom_fields ) {
		if ( empty( $custom_fields ) ) {
			return null;
		}

		return wp_json_encode( $custom_fields );
	}

	/**
	 * Prepare a subscriber record for API consumption.
	 *
	 * @param array $record Raw DB row.
	 * @return array
	 */
	private static function prepare_subscriber_record( array $record ) {
		$record['subscription_types'] = self::normalize_subscription_types( $record['subscription_types'] ?? '' );
		$record['custom_fields']      = self::normalize_custom_fields( $record['custom_fields'] ?? [] );

		return $record;
	}

	/**
	 * Merge two tag inputs and return a normalized CSV string.
	 *
	 * @param string|array $existing Existing tags.
	 * @param string|array $incoming Incoming tags.
	 * @return string
	 */
	private static function merge_tags( $existing, $incoming ) {
		$combined = implode( ',', array_filter( [
			self::normalize_tags( $existing ),
			self::normalize_tags( $incoming ),
		], 'strlen' ) );

		return self::normalize_tags( $combined );
	}

	/**
	 * Remove a single tag from an existing tags value.
	 *
	 * @param string|array $tags   Existing tags.
	 * @param string       $target Tag to remove.
	 * @return string
	 */
	private static function remove_tag( $tags, $target ) {
		$target = sanitize_key( $target );

		if ( '' === $target ) {
			return self::normalize_tags( $tags );
		}

		$parts = array_filter(
			explode( ',', self::normalize_tags( $tags ) ),
			static function ( $tag ) use ( $target ) {
				return $tag !== $target;
			}
		);

		return implode( ',', $parts );
	}

	/**
	 * Update subscriber list membership for a set of subscribers.
	 *
	 * @param string $list_id List ID.
	 * @param array  $ids     Subscriber IDs.
	 * @param bool   $assign  True to add the tag, false to remove it.
	 * @return int Number of subscribers updated.
	 */
	private static function update_list_membership( $list_id, array $ids, $assign ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$list_id = sanitize_key( $list_id );
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( '' === $list_id || empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, tags FROM $table_name WHERE id IN ($placeholders)",
				...$ids
			),
			ARRAY_A
		) ?: [];

		$updated = 0;

		foreach ( $rows as $row ) {
			$id           = absint( $row['id'] ?? 0 );
			$current_tags = self::normalize_tags( $row['tags'] ?? '' );
			$new_tags     = $assign ? self::merge_tags( $current_tags, [ $list_id ] ) : self::remove_tag( $current_tags, $list_id );

			if ( ! $id || $current_tags === $new_tags ) {
				continue;
			}

			$result = $wpdb->update(
				$table_name,
				[ 'tags' => $new_tags ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( false !== $result ) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Get subscribers from the provided IDs whose names are currently empty.
	 *
	 * @param array $ids Subscriber IDs.
	 * @return array
	 */
	private static function get_missing_name_subscribers( array $ids ) {
		global $wpdb;
		$table_name = self::get_table_name();
		self::ensure_table_exists();

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, name FROM $table_name WHERE id IN ($placeholders) AND TRIM(COALESCE(name, '')) = ''",
				...$ids
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Suggest names for subscribers using AI when available, with a local fallback.
	 *
	 * @param array $subscribers Subscriber rows containing id and email.
	 * @return array<int, string>
	 */
	private static function suggest_names_for_subscribers( array $subscribers ) {
		$suggestions = [];

		foreach ( $subscribers as $subscriber ) {
			$id        = (int) ( $subscriber['id'] ?? 0 );
			$email     = (string) ( $subscriber['email'] ?? '' );
			$fallback  = self::infer_name_from_email( $email );

			if ( $id > 0 && '' !== $fallback ) {
				$suggestions[ $id ] = $fallback;
			}
		}

		if ( ! AIProvider::has_api_key() ) {
			return $suggestions;
		}

		$rows_for_ai = array_map(
			static function ( $subscriber ) {
				return [
					'id'    => (int) ( $subscriber['id'] ?? 0 ),
					'email' => (string) ( $subscriber['email'] ?? '' ),
				];
			},
			$subscribers
		);

		$prompt = "Infer likely human contact names from these email addresses.\n"
			. "Rules:\n"
			. "- Return a likely real person name when the email looks person-based (e.g. john.smith@example.com -> John Smith).\n"
			. "- If the address looks generic, role-based, brand-based, or uncertain (e.g. info@, support@, team@), return an empty string.\n"
			. "- Keep names concise and properly capitalized.\n"
			. "- Do not invent job titles, company names, or extra words.\n"
			. "- Respond only as valid JSON in this exact shape: [{\"id\":123,\"name\":\"John Smith\"}].\n\n"
			. 'Subscribers: ' . \wp_json_encode( $rows_for_ai );

		$response = AIProvider::get_reply( [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] );

		if ( \is_wp_error( $response ) || ( is_array( $response ) && isset( $response['error'] ) ) ) {
			return $suggestions;
		}

		$response = is_string( $response ) ? trim( $response ) : '';
		$response = preg_replace( '/^```(?:json)?\s*/i', '', $response );
		$response = preg_replace( '/\s*```$/i', '', (string) $response );

		$parsed = json_decode( (string) $response, true );

		if ( ! is_array( $parsed ) ) {
			return $suggestions;
		}

		foreach ( $parsed as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id   = \absint( $item['id'] ?? 0 );
			$name = \sanitize_text_field( $item['name'] ?? '' );

			if ( $id > 0 && '' !== $name ) {
				$suggestions[ $id ] = $name;
			}
		}

		return $suggestions;
	}

	/**
	 * Infer a likely contact name from an email address without external services.
	 *
	 * @param string $email Subscriber email.
	 * @return string
	 */
	private static function infer_name_from_email( $email ) {
		$local_part = strstr( strtolower( \sanitize_email( $email ) ), '@', true );

		if ( false === $local_part || '' === $local_part ) {
			return '';
		}

		$local_part = preg_replace( '/\+.*/', '', $local_part );
		$tokens     = preg_split( '/[._-]+/', (string) $local_part );

		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return '';
		}

		$blocked_tokens = [
			'info',
			'support',
			'admin',
			'contact',
			'team',
			'hello',
			'hi',
			'sales',
			'newsletter',
			'news',
			'mail',
			'noreply',
			'no',
			'reply',
			'billing',
			'accounts',
			'account',
			'service',
			'services',
			'office',
			'test',
			'qa',
			'marketing',
			'shop',
			'store',
			'orders',
		];

		$clean_tokens = [];

		foreach ( $tokens as $token ) {
			$token = strtolower( preg_replace( '/\d+/', '', trim( (string) $token ) ) );

			if ( '' === $token || in_array( $token, $blocked_tokens, true ) ) {
				continue;
			}

			if ( strlen( $token ) < 2 ) {
				continue;
			}

			$clean_tokens[] = ucwords( $token );
		}

		if ( empty( $clean_tokens ) || count( $clean_tokens ) > 4 ) {
			return '';
		}

		return implode( ' ', $clean_tokens );
	}
}
