<?php
include_once dirname(__FILE__) . '/doc-rank-helper.php';

/**
 * Handles AJAX requests to filter analytics overview data by date.
 *
 * This function processes incoming AJAX requests to filter the analytics overview
 * based on the provided date range. It retrieves relevant data and returns the
 * filtered results in the appropriate format for the admin analytics dashboard.
 *
 * @return void Outputs the filtered analytics data as a JSON response.
 */
function ezd_filter_date_from_search() {
	// Check nonce for security.
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );

	// Check user capability.
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}

	global $wpdb;

	$start_date = sanitize_text_field( $_POST['startDate'] );
	$end_date   = sanitize_text_field( $_POST['endDate'] );

	$table          = $wpdb->prefix . 'eazydocs_search_log';
	$prepared_query = $wpdb->prepare(
		"SELECT * FROM {$table} WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC",
		$start_date,
		$end_date
	);

	$search_keyword = $wpdb->get_results( $prepared_query );

	$labels              = [];
	$total_search        = [];
	$searchCount         = [];
	$searchCountNotFound = [];

	$m  = gmdate( "m" ); //current month
	$de = gmdate( "d" ); //current day
	$y  = gmdate( "Y" ); //current year

	// Generating apexChart labels
	$datediff = strtotime( $end_date ) - strtotime( $start_date );
	$datediff = floor( $datediff / ( 60 * 60 * 24 ) );

	for ( $i = 0; $i < $datediff + 1; $i ++ ) {
		$labels[]              = gmdate( "Y-m-d", strtotime( $start_date . ' + ' . $i . 'day' ) );
		$total_search[]        = 0;
		$searchCount[]         = 0;
		$searchCountNotFound[] = 0;
	}


	// Get 7 data date wise
	foreach ( $search_keyword as $key => $item ) {
		foreach ( $labels as $datekey => $weekdays ) {
			if ( $weekdays == gmdate( 'Y-m-d', strtotime( $item->created_at ) ) ) {
				$total_search[ $datekey ]        = count( $search_keyword );
				$searchCount[ $datekey ]         = array_sum( array_column( $search_keyword, 'count' ) );
				$searchCountNotFound[ $datekey ] = array_sum( array_column( $search_keyword, 'not_found_count' ) );
			}
		}
	}

	wp_send_json_success( array(
		'labels'              => $labels,
		'searchCount'         => $searchCount,
		'totalSearch'         => $total_search,
		'searchCountNotFound' => $searchCountNotFound
	) );
	wp_die();  //die();
}
add_action( 'wp_ajax_ezd_filter_date_from_search', 'ezd_filter_date_from_search' );


/**
 * Handles AJAX pagination for helpful docs search in the admin analytics section.
 *
 * This function processes AJAX requests to paginate the list of helpful documents
 * based on search criteria. It is typically used in the admin area to improve
 * user experience when browsing analytics related to helpful documentation.
 *
 * @return void Outputs the paginated results as a JSON response.
 */
function ezd_search_helpful_docs_paginate() {
	// Check nonce for security.
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );

	// Check user capability.
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}

	$type = $_GET['type'] ?? 'most_helpful';

	if ( $type == 'most_helpful' ) {
		if ( isset( $_GET['total_page'] ) ) {
			$total_page = intval( $_GET['total_page'] );

			$posts = get_posts( [ 'post_type' => 'docs', 'posts_per_page' => - 1, 'inclusive' => true ] );

			$post_data = [];
			foreach ( $posts as $key => $post ) {
				$post_data[ $key ]['post_id']        = $post->ID;
				$post_data[ $key ]['post_title']     = $post->post_title;
				$post_data[ $key ]['post_edit_link'] = get_edit_post_link( $post->ID );
				$post_data[ $key ]['post_permalink'] = get_permalink( $post->ID );
				// sum of total positive votes for a post
				$post_data[ $key ]['positive_time'] = array_sum( get_post_meta( $post->ID, 'positive', false ) );

				$post_data[ $key ]['negative_time'] = array_sum( get_post_meta( $post->ID, 'negative', false ) );
			}
			// if post has positive_time number large then negative_time number then show large number first
			usort( $post_data, function ( $a, $b ) {
				return $b['positive_time'] <=> $a['positive_time'];
			} );

			foreach ( $post_data as $key => $post ) {
				if ( $post['positive_time'] > 0 ) {
					if ( $key >= 0 && $key <= $total_page ) {
						ezd_render_doc_rank_item( $post, $key, 'most_helpful' );
					}
				}
			}

			wp_reset_postdata();

		} else {
			// Return an error message if the total_page parameter is not set
			echo 'Error: total_page parameter is missing';
		}
	} elseif ( $type == 'most_viewed' ) {
		if ( isset( $_GET['total_page'] ) ) {
			$total_page = intval( $_GET['total_page'] );

			$posts = get_posts( [ 'post_type' => 'docs', 'posts_per_page' => $total_page, 'meta_key' => 'post_views_count', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'inclusive' => true  ] );

			$post_data = [];
			foreach ( $posts as $key => $post ) {
				$post_data[ $key ]['post_id']        = $post->ID;
				$post_data[ $key ]['post_title']     = $post->post_title;
				$post_data[ $key ]['post_edit_link'] = get_edit_post_link( $post->ID );
				$post_data[ $key ]['post_permalink'] = get_permalink( $post->ID );
				// sum of total positive votes for a post
				$post_data[ $key ]['positive_time'] = array_sum( get_post_meta( $post->ID, 'positive', false ) );

				$post_data[ $key ]['negative_time'] = array_sum( get_post_meta( $post->ID, 'negative', false ) );
			}
			
			foreach ( $post_data as $key => $post ) {
				if ( $key >= 0 && $key <= $total_page ) {
					ezd_render_doc_rank_item( $post, $key, 'most_viewed' );
				}
			}

			wp_reset_postdata();

		} else {
			// Return an error message if the total_page parameter is not set
			echo 'Error: total_page parameter is missing';
		}
	} else {
		if ( isset( $_GET['total_page'] ) ) {
			$total_page = intval( $_GET['total_page'] );

			$posts = get_posts( [ 'post_type' => 'docs', 'posts_per_page' => - 1, 'inclusive' => true ] );

			$post_data = [];
			foreach ( $posts as $key => $post ) {
				$post_data[ $key ]['post_id']        = $post->ID;
				$post_data[ $key ]['post_title']     = $post->post_title;
				$post_data[ $key ]['post_edit_link'] = get_edit_post_link( $post->ID );
				$post_data[ $key ]['post_permalink'] = get_permalink( $post->ID );
				// sum of total positive votes for a post
				$post_data[ $key ]['positive_time'] = array_sum( get_post_meta( $post->ID, 'positive', false ) );

				$post_data[ $key ]['negative_time'] = array_sum( get_post_meta( $post->ID, 'negative', false ) );
			}
			// if post has positive_time number large then negative_time number then show large number first
			usort( $post_data, function ( $a, $b ) {
				return $b['negative_time'] <=> $a['negative_time'];
			} );

			foreach ( $post_data as $key => $post ) {
				if ( $post['negative_time'] > 0 ) {
					if ( $key >= 0 && $key <= $total_page ) {
						ezd_render_doc_rank_item( $post, $key, 'least_helpful' );
					}
				}
			}
		} else {
			// Return an error message if the total_page parameter is not set
			echo 'Error: total_page parameter is missing';
		}
	}
	wp_die(); // Always remember to call wp_die() after sending an AJAX response
}
add_action( 'wp_ajax_ezd_search_helpful_docs_paginate', 'ezd_search_helpful_docs_paginate' );


/**
 * Analytics Reset
 * Overview reset callback
 */
function ezd_reset_overview_data(){
	// called views meta
	ezd_views_postmeta_reset();
	// called feedback table
	ezd_feedback_table_reset();
	// called search data
	ezd_reset_search_data();
}


/**
 * Post meta views reset callback
 */
function ezd_views_postmeta_reset(){
    global $wpdb;	
    // SQL query to delete all rows with the specified meta key
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", 'post_views_count' ) );
    // Delete all records from eazydocs_view_log
    $wpdb->query( "DELETE FROM {$wpdb->prefix}eazydocs_view_log" );
}


/**
 * Post meta feedback reset callback
 */
function ezd_feedback_table_reset(){
	global $wpdb;	
	$wpdb->query( "
		DELETE FROM {$wpdb->prefix}postmeta 
		WHERE post_id IN (
			SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'docs'
		) 
		AND meta_key IN ('positive_time', 'negative_time', 'positive', 'negative')
	" );	
    setcookie('eazydocs_response', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
}


/**
 * Search tables reset callback
 */
function ezd_reset_search_data(){	
	global $wpdb;	
	$search_keyword_table 	= $wpdb->prefix . 'eazydocs_search_keyword';
	$search_logs_table 		= $wpdb->prefix . 'eazydocs_search_log';	
	$wpdb->query( "DELETE FROM $search_keyword_table" );	
	$wpdb->query( "DELETE FROM $search_logs_table" );
}


/**
 * Overview reset ajax action
 */
function ezd_overview_reset(){
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}
	ezd_reset_overview_data();
	wp_send_json_success();
}
add_action('wp_ajax_ezd_overview_reset', 'ezd_overview_reset');


/**
 * Feedback reset ajax action
 */
function ezd_reset_feedback() {
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}
	ezd_feedback_table_reset();	
	wp_send_json_success();
}
add_action('wp_ajax_ezd_reset_feedback', 'ezd_reset_feedback' );


/**
 * Views reset ajax action
 */
function ezd_reset_views(){
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}
    ezd_views_postmeta_reset();
	wp_send_json_success();
}
add_action('wp_ajax_ezd_reset_views', 'ezd_reset_views');



/**
 * Search reset ajax action
 */
function ezd_search_table_reset(){
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}
	ezd_reset_search_data();
	wp_send_json_success();
}
add_action('wp_ajax_ezd_search_table_reset', 'ezd_search_table_reset');


/**
 * Export doc ranks data as JSON
 *
 * @return void Outputs JSON data for export
 */
function ezd_export_doc_ranks_data() {
	// Check nonce for security.
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );

	// Check user capability.
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}

	$type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'most_helpful';

	$posts     = get_posts(
		array(
			'post_type'      => 'docs',
			'posts_per_page' => -1,
		)
	);
	$post_data = array();

	foreach ( $posts as $post ) {
		$positive = array_sum( get_post_meta( $post->ID, 'positive', false ) );
		$negative = array_sum( get_post_meta( $post->ID, 'negative', false ) );
		$views    = intval( get_post_meta( $post->ID, 'post_views_count', true ) );

		$post_data[] = array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'positive' => $positive,
			'negative' => $negative,
			'views'    => $views,
			'url'      => get_permalink( $post->ID ),
		);
	}

	// Sort based on type.
	switch ( $type ) {
		case 'most_helpful':
			usort(
				$post_data,
				function ( $a, $b ) {
					return $b['positive'] <=> $a['positive'];
				}
			);
			$post_data = array_filter(
				$post_data,
				function ( $p ) {
					return $p['positive'] > 0;
				}
			);
			break;

		case 'least_helpful':
			usort(
				$post_data,
				function ( $a, $b ) {
					return $b['negative'] <=> $a['negative'];
				}
			);
			$post_data = array_filter(
				$post_data,
				function ( $p ) {
					return $p['negative'] > 0;
				}
			);
			break;

		case 'most_viewed':
			usort(
				$post_data,
				function ( $a, $b ) {
					return $b['views'] <=> $a['views'];
				}
			);
			break;
	}

	wp_send_json_success(
		array(
			'type' => $type,
			'data' => array_values( $post_data ),
		)
	);
}
add_action( 'wp_ajax_ezd_export_doc_ranks', 'ezd_export_doc_ranks_data' );

/**
 * Fetch analytics overview data for the last 30 days.
 *
 * @return void Outputs JSON data for the overview chart.
 */
function ezd_analytics_overview_last_month() {
    global $wpdb;

    // Check nonce for security.
    check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );

    // Check user capability.
    if ( ! current_user_can( 'publish_pages' ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
        return;
    }

    // Dates for the last 30 days (reversed to match chart labels)
    $labels_30 = [];
    $views_30 = [];
    $liked_30 = [];
    $disliked_30 = [];
    $search_30 = [];

    $m = gmdate('m');
    $d = gmdate('d');
    $y = gmdate('Y');

    for ($i = 0; $i <= 29; $i++) {
        $timestamp = mktime(0, 0, 0, $m, ($d - $i), $y);
        $date_key = gmdate('d M, Y', $timestamp);
        $labels_30[] = $date_key;

        // Initialize counts
        $views_30[$date_key] = 0;
        $liked_30[$date_key] = 0;
        $disliked_30[$date_key] = 0;
        $search_30[$date_key] = 0;
    }

    // 1. Fetch Views (Aggregated by Date)
    // Querying for last 30 days.
    $views_results = $wpdb->get_results(
        "SELECT DATE(created_at) as view_date, SUM(count) as total_views
         FROM {$wpdb->prefix}eazydocs_view_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(created_at)"
    );

    foreach ($views_results as $row) {
        $date_key = gmdate('d M, Y', strtotime($row->view_date));
        if (isset($views_30[$date_key])) {
            $views_30[$date_key] = (int)$row->total_views;
        }
    }

    // 2. Fetch Search Counts (Aggregated by Date)
    $search_results = $wpdb->get_results(
        "SELECT DATE(created_at) as search_date, SUM(count) as total_searches
         FROM {$wpdb->prefix}eazydocs_search_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(created_at)"
    );

    foreach ($search_results as $row) {
        $date_key = gmdate('d M, Y', strtotime($row->search_date));
        if (isset($search_30[$date_key])) {
            $search_30[$date_key] = (int)$row->total_searches;
        }
    }

    // 3. Fetch Feedback (Approximation to match legacy logic)
    // Legacy logic: Group views by Post ID, then for each Post, sum its TOTAL (Lifetime) positive/negative meta,
    // and attribute it to the date of the view.
    // Optimized Query: Get unique Post IDs viewed in last 30 days + their view date (using MAX date per post to avoid duplicates per day if possible, or just MAX).
    // Note: The legacy logic `GROUP BY post_id` picks *one* arbitrary date. MAX(created_at) is a reasonable deterministic replacement.

    $feedback_posts = $wpdb->get_results(
        "SELECT vl.post_id, DATE(MAX(vl.created_at)) as view_date
         FROM {$wpdb->prefix}eazydocs_view_log vl
         WHERE vl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY vl.post_id"
    );

    // Now fetch meta for these posts efficiently.
    if (!empty($feedback_posts)) {
        $post_ids = wp_list_pluck($feedback_posts, 'post_id');
        $post_ids_str = implode(',', array_map('intval', $post_ids));

        $meta_results = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ($post_ids_str)
             AND meta_key IN ('positive', 'negative')"
        );

        // Map meta to post_id
        $post_likes = [];
        $post_dislikes = [];
        foreach ($meta_results as $meta) {
            if (!isset($post_likes[$meta->post_id])) $post_likes[$meta->post_id] = 0;
            if (!isset($post_dislikes[$meta->post_id])) $post_dislikes[$meta->post_id] = 0;

            if ($meta->meta_key == 'positive') {
                $post_likes[$meta->post_id] += (int)$meta->meta_value;
            } elseif ($meta->meta_key == 'negative') {
                $post_dislikes[$meta->post_id] += (int)$meta->meta_value;
            }
        }

        // Aggregate to dates
        foreach ($feedback_posts as $p) {
            $date_key = gmdate('d M, Y', strtotime($p->view_date));
            if (isset($liked_30[$date_key])) {
                $liked_30[$date_key] += ($post_likes[$p->post_id] ?? 0);
                $disliked_30[$date_key] += ($post_dislikes[$p->post_id] ?? 0);
            }
        }
    }

    wp_send_json_success([
        'labels' => array_values($labels_30),
        'views' => array_values($views_30),
        'feedback' => array_values($liked_30), // Sending just Likes as per existing code
        'searches' => array_values($search_30),
        'range_text' => gmdate('d M, Y', strtotime('-29 days')) . ' - ' . gmdate('d M, Y')
    ]);
}
add_action('wp_ajax_ezd_analytics_overview_last_month', 'ezd_analytics_overview_last_month');

/**
 * AJAX handler to resolve/unresolve a failed search keyword.
 * 
 * This marks a keyword as "resolved" only if the keyword now returns search results.
 * This ensures that content has been created to address the failed search.
 * Resolved keywords are tracked in a WordPress option.
 *
 * @return void Outputs JSON response with success/failure status.
 */
function ezd_resolve_failed_search() {
	// Check nonce for security.
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );

	// Check user capability.
	if ( ! current_user_can( 'publish_pages' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}

	global $wpdb;

	$keyword_id = isset( $_POST['keyword_id'] ) ? absint( $_POST['keyword_id'] ) : 0;
	$action     = isset( $_POST['resolve_action'] ) ? sanitize_text_field( $_POST['resolve_action'] ) : 'resolve';

	if ( ! $keyword_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid keyword ID.', 'eazydocs-pro' ) ) );
		return;
	}

	// Get the keyword text from the database.
	$keyword_table = $wpdb->prefix . 'eazydocs_search_keyword';
	$keyword       = $wpdb->get_var( $wpdb->prepare( "SELECT keyword FROM {$keyword_table} WHERE id = %d", $keyword_id ) );

	if ( ! $keyword ) {
		wp_send_json_error( array( 'message' => __( 'Keyword not found.', 'eazydocs-pro' ) ) );
		return;
	}

	// Get the current resolved keywords list.
	$resolved_keywords = get_option( 'ezd_resolved_search_keywords', array() );

	if ( 'resolve' === $action ) {
		// Before marking as resolved, verify that the keyword now returns search results.
		$search_query = new WP_Query(
			array(
				'post_type'      => 'docs',
				'post_status'    => 'publish',
				's'              => $keyword,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! $search_query->have_posts() ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: the keyword that was searched */
						__( 'Cannot resolve: No documentation found for "%s". Please create content for this keyword first.', 'eazydocs-pro' ),
						esc_html( $keyword )
					),
				)
			);
			return;
		}

		wp_reset_postdata();

		// Add keyword ID to resolved list.
		if ( ! in_array( $keyword_id, $resolved_keywords, true ) ) {
			$resolved_keywords[] = $keyword_id;
		}
		$message = sprintf(
			/* translators: %s: the keyword that was resolved */
			__( 'Keyword "%s" marked as resolved.', 'eazydocs-pro' ),
			esc_html( $keyword )
		);
	} else {
		// Remove keyword ID from resolved list.
		$resolved_keywords = array_diff( $resolved_keywords, array( $keyword_id ) );
		$message           = __( 'Keyword marked as unresolved.', 'eazydocs-pro' );
	}

	// Update the option.
	update_option( 'ezd_resolved_search_keywords', array_values( $resolved_keywords ) );

	wp_send_json_success( array( 'message' => $message ) );
}
add_action( 'wp_ajax_ezd_resolve_failed_search', 'ezd_resolve_failed_search' );

/**
 * AJAX handler to get the list of resolved keyword IDs.
 *
 * @return void Outputs JSON response with resolved keyword IDs.
 */
function ezd_get_resolved_keywords() {
	// Check nonce for security.
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );

	// Check user capability.
	if ( ! current_user_can( 'publish_pages' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}

	$resolved_keywords = get_option( 'ezd_resolved_search_keywords', array() );
	wp_send_json_success( array( 'resolved_ids' => $resolved_keywords ) );
}
add_action( 'wp_ajax_ezd_get_resolved_keywords', 'ezd_get_resolved_keywords' );
