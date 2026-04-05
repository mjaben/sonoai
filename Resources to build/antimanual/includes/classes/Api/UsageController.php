<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Usage and analytics API endpoints.
 */
class UsageController {
	/**
	 * Register REST routes for usage and analytics.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace ) {
		register_rest_route(
			$namespace,
			'/usage-stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_usage_stats' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/search-analytics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_search_analytics' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/search-queries',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_search_queries' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/search-queries/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_search_query' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/search-queries/bulk-delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_delete_search_queries' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/search-analytics/top-queries',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_top_queries' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/search-analytics/daily-volumes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_daily_volumes' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/search-queries/export-csv',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_search_queries_csv' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);
	}

	/**
	 * Get usage statistics.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function get_usage_stats( $request ) {
		$limits = array(
			'chatbot'          => 30,
			'bulk_rewrite'     => 30,
			'forum_conversion' => 100,
			'forum_answer'     => 100,
			'search_block'     => 100,
		);

		$counts = array();
		if ( ! atml_is_pro() ) {
			$counts                 = \Antimanual\UsageTracker::get_all_monthly_counts();
			$counts['search_block'] = \Antimanual\UsageTracker::get_total_count( 'search_block' );
		}

		$data = array();
		foreach ( $limits as $feature => $limit ) {
			$data[ $feature ] = array(
				'count' => $counts[ $feature ] ?? 0,
				'limit' => $limit,
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Get search analytics.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function get_search_analytics( $request ) {
		global $wpdb;

		$post_types    = get_post_types( array( 'exclude_from_search' => false ), 'names' );
		$search_string = '<!-- wp:antimanual/antimanual-search ';
		$post_types    = array_values( array_map( 'sanitize_key', $post_types ) );

		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		}

		$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$count_query_args       = array_merge( $post_types, array( '%' . $wpdb->esc_like( $search_string ) . '%' ) );

		$block_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM $wpdb->posts
             WHERE post_status IN ('publish', 'draft')
	             AND post_type IN ($post_type_placeholders)
             AND post_content LIKE %s",
				...$count_query_args
			)
		);

		// Fetch the list of pages/posts using the block.
		$list_query_args = array_merge( $post_types, array( '%' . $wpdb->esc_like( $search_string ) . '%' ) );
		$block_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status FROM $wpdb->posts
             WHERE post_status IN ('publish', 'draft')
	             AND post_type IN ($post_type_placeholders)
             AND post_content LIKE %s
             ORDER BY post_modified DESC
             LIMIT 50",
				...$list_query_args
			)
		);

		$block_pages = array();
		foreach ( $block_posts as $post ) {
			$block_pages[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title ?: __( '(No title)', 'antimanual' ),
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
				'permalink' => $post->post_status === 'publish' ? get_permalink( $post->ID ) : '',
			);
		}

		// Also check widget areas for blocks.
		$widget_blocks = get_option( 'widget_block', array() );
		$sidebars      = get_option( 'sidebars_widgets', array() );
		$widgets_url   = admin_url( 'widgets.php' );
		$widget_count  = 0;

		if ( is_array( $widget_blocks ) ) {
			foreach ( $widget_blocks as $key => $widget ) {
				if ( ! is_array( $widget ) || empty( $widget['content'] ) ) {
					continue;
				}

				if ( strpos( $widget['content'], '<!-- wp:antimanual/antimanual-search ' ) === false ) {
					continue;
				}

				++$widget_count;
				$widget_id = 'block-' . $key;

				// Find which sidebar this widget belongs to.
				$sidebar_name = __( 'Widget Area', 'antimanual' );
				if ( is_array( $sidebars ) ) {
					foreach ( $sidebars as $sidebar_id => $widgets_list ) {
						if ( $sidebar_id === 'wp_inactive_widgets' || ! is_array( $widgets_list ) ) {
							continue;
						}
						if ( in_array( $widget_id, $widgets_list, true ) ) {
							$sidebar_info = wp_get_sidebar( $sidebar_id );
							$sidebar_name = ! empty( $sidebar_info['name'] ) ? $sidebar_info['name'] : $sidebar_id;
							break;
						}
					}
				}

				$block_pages[] = array(
					'id'        => 0,
					'title'     => $sidebar_name,
					'post_type' => 'widget',
					'edit_url'  => $widgets_url,
					'permalink' => '',
				);
			}
		}

		$block_count += $widget_count;

		$votes_table  = $wpdb->prefix . 'antimanual_query_votes';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$votes_table'" ) === $votes_table;

		$total_queries   = 0;
		$helpful_votes   = 0;
		$unhelpful_votes = 0;
		$monthly_queries = 0;

		if ( $table_exists ) {
			$total_queries   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $votes_table" );
			$helpful_votes   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $votes_table WHERE is_helpful = 1" );
			$unhelpful_votes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $votes_table WHERE is_helpful = 0" );

			$first_day       = gmdate( 'Y-m-01 00:00:00' );
			$monthly_queries = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM $votes_table WHERE created_at >= %s", $first_day )
			);
		}

		$counts             = \Antimanual\UsageTracker::get_all_monthly_counts();
		$search_block_usage = $counts['search_block'] ?? 0;

		$usage_table          = $wpdb->prefix . 'antimanual_usage';
		$usage_table_exists   = $wpdb->get_var( "SHOW TABLES LIKE '$usage_table'" ) === $usage_table;
		$total_search_queries = 0;
		if ( $usage_table_exists ) {
			$total_search_queries = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $usage_table WHERE feature = 'search_block'"
			);
		}

		$recent_queries = array();
		if ( $table_exists ) {
			$results = $wpdb->get_results( "SELECT query, is_helpful, created_at FROM $votes_table ORDER BY created_at DESC LIMIT 5" );
			foreach ( $results as $row ) {
				$query_text = ! empty( $row->query ) ? @gzuncompress( $row->query ) : '';
				if ( $query_text === false ) {
					$query_text = $row->query ?: '';
				}

				$recent_queries[] = array(
					'query'      => $query_text,
					'is_helpful' => $row->is_helpful === null ? null : (bool) $row->is_helpful,
					'created_at' => $row->created_at,
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'block_count'     => $block_count,
					'block_pages'     => $block_pages,
					'total_queries'   => max( $total_queries, $total_search_queries ),
					'monthly_queries' => max( $monthly_queries, $search_block_usage ),
					'helpful_votes'   => $helpful_votes,
					'unhelpful_votes' => $unhelpful_votes,
					'recent_queries'  => $recent_queries,
				),
			)
		);
	}

	/**
	 * List search queries.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function list_search_queries( $request ) {
		global $wpdb;

		$page     = intval( $request->get_param( 'page' ) ) ?: 1;
		$per_page = intval( $request->get_param( 'per_page' ) ) ?: 20;
		$filter   = $request->get_param( 'filter' ) ?? 'all';
		$offset   = ( $page - 1 ) * $per_page;

		$votes_table  = $wpdb->prefix . 'antimanual_query_votes';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$votes_table'" ) === $votes_table;

		if ( ! $table_exists ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'queries' => array(),
						'total'   => 0,
					),
				)
			);
		}

		$where = '';
		if ( 'unhelpful' === $filter ) {
			$where = 'WHERE is_helpful = 0';
		} elseif ( 'helpful' === $filter ) {
			$where = 'WHERE is_helpful = 1';
		} elseif ( 'no-vote' === $filter ) {
			$where = 'WHERE is_helpful IS NULL';
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $votes_table $where" );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, query, answer, is_helpful, created_at FROM $votes_table $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$queries = array();
		foreach ( $results as $row ) {
			$query_text = ! empty( $row->query ) ? @gzuncompress( $row->query ) : '';
			if ( $query_text === false ) {
				$query_text = $row->query ?: '';
			}

			$answer_text = ! empty( $row->answer ) ? @gzuncompress( $row->answer ) : '';
			if ( $answer_text === false ) {
				$answer_text = $row->answer ?: '';
			}
			$answer_text = wp_kses_post( $answer_text );

			$queries[] = array(
				'id'         => (int) $row->id,
				'query'      => $query_text,
				'answer'     => $answer_text,
				'is_helpful' => $row->is_helpful === null ? null : (bool) $row->is_helpful,
				'created_at' => $row->created_at,
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'queries'  => $queries,
					'total'    => $total,
					'page'     => $page,
					'per_page' => $per_page,
				),
			)
		);
	}

	/**
	 * Delete search query.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function delete_search_query( $request ) {
		global $wpdb;

		$id          = intval( $request['id'] );
		$votes_table = $wpdb->prefix . 'antimanual_query_votes';

		$deleted = $wpdb->delete( $votes_table, array( 'id' => $id ), array( '%d' ) );

		if ( $deleted === false ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Failed to delete search query.', 'antimanual' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Search query deleted successfully.', 'antimanual' ),
			)
		);
	}

	/**
	 * Bulk delete search queries.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function bulk_delete_search_queries( $request ) {
		global $wpdb;

		$ids = $request->get_json_params()['ids'] ?? array();

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'No query IDs provided.', 'antimanual' ),
				)
			);
		}

		$votes_table  = $wpdb->prefix . 'antimanual_query_votes';
		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $votes_table WHERE id IN ($placeholders)",
				...$ids
			)
		);

		if ( $deleted === false ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Failed to delete search queries.', 'antimanual' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of deleted queries */
					__( '%d search queries deleted successfully.', 'antimanual' ),
					$deleted
				),
				'data'    => array(
					'deleted_count' => $deleted,
				),
			)
		);
	}

	/**
	 * Get top (most frequent) search queries.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function get_top_queries( $request ) {
		global $wpdb;

		$limit        = intval( $request->get_param( 'limit' ) ) ?: 10;
		$votes_table  = $wpdb->prefix . 'antimanual_query_votes';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$votes_table'" ) === $votes_table;

		if ( ! $table_exists ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(),
				)
			);
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query,
                        COUNT(*) as frequency,
                        SUM(CASE WHEN is_helpful = 1 THEN 1 ELSE 0 END) as helpful_count,
                        SUM(CASE WHEN is_helpful = 0 THEN 1 ELSE 0 END) as unhelpful_count,
                        MAX(created_at) as last_asked
                 FROM $votes_table
                 GROUP BY query
                 ORDER BY frequency DESC
                 LIMIT %d",
				$limit
			)
		);

		$top_queries = array();
		foreach ( $results as $row ) {
			$query_text = ! empty( $row->query ) ? @gzuncompress( $row->query ) : '';
			if ( $query_text === false ) {
				$query_text = $row->query ?: '';
			}

			$total_votes  = (int) $row->helpful_count + (int) $row->unhelpful_count;
			$satisfaction = $total_votes > 0 ? round( ( (int) $row->helpful_count / $total_votes ) * 100 ) : null;

			$top_queries[] = array(
				'query'           => $query_text,
				'frequency'       => (int) $row->frequency,
				'helpful_count'   => (int) $row->helpful_count,
				'unhelpful_count' => (int) $row->unhelpful_count,
				'satisfaction'    => $satisfaction,
				'last_asked'      => $row->last_asked,
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $top_queries,
			)
		);
	}

	/**
	 * Get daily query volumes for the last N days.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function get_daily_volumes( $request ) {
		global $wpdb;

		$days         = intval( $request->get_param( 'days' ) ) ?: 7;
		$votes_table  = $wpdb->prefix . 'antimanual_query_votes';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$votes_table'" ) === $votes_table;

		if ( ! $table_exists ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(),
				)
			);
		}

		$start_date = gmdate( 'Y-m-d', time() - ( $days * 24 * 60 * 60 ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as count
                 FROM $votes_table
                 WHERE created_at >= %s
                 GROUP BY DATE(created_at)
                 ORDER BY date ASC",
				$start_date . ' 00:00:00'
			)
		);

		// Build a complete date range including days with zero queries.
		$volumes = array();
		for ( $i = $days; $i >= 0; $i-- ) {
			$date             = gmdate( 'Y-m-d', time() - ( $i * 24 * 60 * 60 ) );
			$volumes[ $date ] = 0;
		}

		foreach ( $results as $row ) {
			$volumes[ $row->date ] = (int) $row->count;
		}

		$data = array();
		foreach ( $volumes as $date => $count ) {
			$data[] = array(
				'date'  => $date,
				'label' => gmdate( 'D', strtotime( $date . ' 00:00:00 UTC' ) ),
				'count' => $count,
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Export search queries as CSV data.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function export_search_queries_csv( $request ) {
		global $wpdb;

		$votes_table  = $wpdb->prefix . 'antimanual_query_votes';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$votes_table'" ) === $votes_table;

		if ( ! $table_exists ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'csv'      => '',
						'filename' => 'search-queries.csv',
					),
				)
			);
		}

		$results = $wpdb->get_results(
			"SELECT id, query, answer, is_helpful, created_at
             FROM $votes_table
             ORDER BY created_at DESC"
		);

		$csv_lines   = array();
		$csv_lines[] = 'ID,Query,Answer,Feedback,Date';

		foreach ( $results as $row ) {
			$query_text = ! empty( $row->query ) ? @gzuncompress( $row->query ) : '';
			if ( $query_text === false ) {
				$query_text = $row->query ?: '';
			}

			$answer_text = ! empty( $row->answer ) ? @gzuncompress( $row->answer ) : '';
			if ( $answer_text === false ) {
				$answer_text = $row->answer ?: '';
			}
			// Strip HTML from answer for CSV readability.
			$answer_text = wp_strip_all_tags( $answer_text );

			$feedback = $row->is_helpful === null ? 'No Vote' : ( (int) $row->is_helpful === 1 ? 'Helpful' : 'Not Helpful' );

			// Escape CSV fields.
			$csv_lines[] = sprintf(
				'%d,"%s","%s","%s","%s"',
				(int) $row->id,
				str_replace( '"', '""', $query_text ),
				str_replace( '"', '""', $answer_text ),
				$feedback,
				$row->created_at
			);
		}

		$csv_content = implode( "\n", $csv_lines );
		$filename    = 'search-queries-' . gmdate( 'Y-m-d' ) . '.csv';

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'csv'      => $csv_content,
					'filename' => $filename,
				),
			)
		);
	}
}
