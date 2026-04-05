<?php
/**
 * EazyDocs Automated Email Reports
 * Sends periodic analytics summaries via email.
 *
 * @package EazyDocs Pro
 */

add_action( 'init', function() {
	if ( ! wp_next_scheduled( 'eazydocs_send_report' ) ) {
		wp_schedule_event( time(), 'hourly', 'eazydocs_send_report' ); // Check hourly for precise timing
	}
} );

add_action( 'eazydocs_send_report', 'eazydocs_send_report' );

/**
 * Main function to send the email report.
 */
function eazydocs_send_report() {
	if ( ! ezd_get_opt( 'reporting_enabled' ) ) {
		return;
	}

	global $wpdb;
	$post_meta_table = $wpdb->prefix . 'postmeta';

	// Get settings
	$reporting_format    = ezd_get_opt( 'reporting_frequency' ) ?: 'weekly';
	$reporting_day       = ezd_get_opt( 'reporting_day' ) ?: 'monday';
	$reporting_monthly   = ezd_get_opt( 'reporting_monthly_day' ) ?: 'last';
	$reporting_time      = ezd_get_opt( 'reporting_time' ) ?: '09:00';
	$last_sent           = get_option( 'ezd_send_report_email', 0 );

	$now             = current_time( 'timestamp' ); // WP local time
	$start_date      = '';
	$end_date        = gmdate( 'Y-m-d', $now );
	$send_email      = false;
	$period_key      = 0;
	$last_day_count  = '';
	$last_dates      = '';
	$report_by_day   = 0;

	// Parse the preferred time (HH:MM format)
	$preferred_hour = intval( substr( $reporting_time, 0, 2 ) );
	$current_hour   = intval( gmdate( 'H', $now ) );

	// -------------------------
	// DAILY DATA
	// -------------------------
	if ( 'daily' === $reporting_format ) {

		// Create period key based on preferred time
		$period_key = strtotime( "today {$reporting_time}", $now );

		// Only send if current hour matches preferred hour AND not already sent today
		if ( $current_hour >= $preferred_hour && $last_sent < $period_key ) {
			$send_email = true;
			update_option( 'ezd_send_report_email', $period_key );
		}

		$last_day_count = esc_html__( 'Last 24 Hours', 'eazydocs-pro' );
		$last_dates     = gmdate( 'M d, Y', strtotime( '-1 day', $now ) ) . ' - ' . gmdate( 'M d, Y', $now );
		$start_date     = gmdate( 'Y-m-d', strtotime( 'today', $now ) );
		$report_by_day  = 1;
	}
	

	// -------------------------
	// WEEKLY DATA
	// -------------------------
	if ( 'weekly' === $reporting_format && strtolower( gmdate( 'l', $now ) ) === $reporting_day ) {

		// Create period key based on preferred time
		$period_key = strtotime( "today {$reporting_time}", $now );

		// Only send if current hour matches preferred hour AND not already sent this week
		if ( $current_hour >= $preferred_hour && $last_sent < $period_key ) {
			$send_email = true;
			update_option( 'ezd_send_report_email', $period_key );
		}

		$last_day_count    = esc_html__( 'Last 7 Days', 'eazydocs-pro' );
		$last_dates        = gmdate( 'M d, Y', strtotime( '-6 days', $now ) ) . ' - ' . gmdate( 'M d, Y', $now );
		$last_selected_day = strtotime( "last $reporting_day", $now );
		$start_date        = gmdate( 'Y-m-d', $last_selected_day );
		$report_by_day     = 7;
	}
	
	// -------------------------
	// MONTHLY DATA
	// -------------------------
	if ( 'monthly' === $reporting_format ) {
		$last_day       = gmdate( 't', $now );
		$today_day      = intval( gmdate( 'j', $now ) );
		$period_key     = strtotime( "today {$reporting_time}", $now );
		$should_send    = false;

		// Determine if today is the configured monthly day
		if ( 'last' === $reporting_monthly ) {
			// Send on last day of month
			$should_send = ( $today_day == $last_day );
		} elseif ( is_numeric( $reporting_monthly ) ) {
			// Send on specific day (1st, 15th, etc.)
			$target_day = intval( $reporting_monthly );
			// Handle if target day exceeds days in month
			if ( $target_day > $last_day ) {
				$target_day = $last_day;
			}
			$should_send = ( $today_day == $target_day );
		}

		if ( $should_send && $current_hour >= $preferred_hour && $last_sent < $period_key ) {
			$send_email = true;
			update_option( 'ezd_send_report_email', $period_key );
		}

		$last_day_count = sprintf(
			/* translators: %d: Number of days */
			esc_html__( 'Last %d Days', 'eazydocs-pro' ),
			$last_day
		);
		$last_dates     = gmdate( 'M d, Y', strtotime( 'first day of this month', $now ) ) . ' - ' . gmdate( 'M d, Y', $now );
		$start_date     = gmdate( 'Y-m-01', $now );
		$report_by_day  = $last_day;
	}

	if ( ! $send_email ) {
		return;
	}
	
	// ---------------------------------------
	// Query positive votes in the period
	// ---------------------------------------
	$positive_votes = $wpdb->get_results( $wpdb->prepare( "
		SELECT gmdate(meta_value) as vote_date, COUNT(*) as total
		FROM $post_meta_table
		WHERE meta_key = 'positive_time'
		AND gmdate(meta_value) BETWEEN %s AND %s
		GROUP BY gmdate(meta_value)
		ORDER BY vote_date ASC
	", $start_date, $end_date), ARRAY_A);

	
	// ---------------------------------------
	// Query negative votes in the period
	// ---------------------------------------
	$negative_votes = $wpdb->get_results( $wpdb->prepare( "
		SELECT gmdate(meta_value) as vote_date, COUNT(*) as total
		FROM $post_meta_table
		WHERE meta_key = 'negative_time'
		AND gmdate(meta_value) BETWEEN %s AND %s
		GROUP BY gmdate(meta_value)
		ORDER BY vote_date ASC
	", $start_date, $end_date), ARRAY_A);
	

	// --------------------------------------------------
	// Convert results into arrays with gmdates as keys
	// --------------------------------------------------
	$posArr = [];
	foreach ( $positive_votes as $row) {
		$posArr[$row['vote_date']] = (int) $row['total'];
	}

	$negArr = [];
	foreach ( $negative_votes as $row) {
		$negArr[$row['vote_date']] = (int) $row['total'];
	}

	$votesByDay = [];
	for ( $i = $report_by_day; $i >= 0; $i--) {
		$date = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
		$pos  = isset( $posArr[$date] ) ? $posArr[$date] : 0;
		$neg  = isset( $negArr[$date] ) ? $negArr[$date] : 0;

		$votesByDay[$date] = [
			'positive' => $pos,
			'negative' => $neg,
			'total'    => $pos + $neg
		];
	}

	$votes_arr 			= array_column( $votesByDay, 'total' );
	$total_votes_count 	= array_sum( $votes_arr);


	// ---------------
	// Prepare arrays
	// ----------------
	$labels    		= [];
	$views_count 	= [];
	$search_count 	= [];
	$m         		= gmdate( "m");
	$de        		= gmdate( "d");
	$y         		= gmdate( "Y");

	
	// ----------------------
	// Search & views table
	// ----------------------
	$eazydocs_view_table = $wpdb->prefix . 'eazydocs_view_log';
	$eazydocs_search_log = $wpdb->prefix . 'eazydocs_search_log';

	// ----------------------
	// Get all views
	// ----------------------
	$views_db 	= $wpdb->get_results( "SELECT `count`, `created_at` FROM $eazydocs_view_table", ARRAY_A);
	$search_db 	= $wpdb->get_results( "SELECT `count`, `created_at` FROM $eazydocs_search_log", ARRAY_A);

	
	$views_arr = [];
	foreach ( $views_db as $row) {
		$date = explode( ' ', $row['created_at'] )[0]; // Only gmdate part
		if ( isset( $views_arr[$date] ) ) {
			$views_arr[$date] += $row['count'];
		} else {
			$views_arr[$date] = $row['count'];
		}
	}
	
	$search_arr = [];
	foreach ( $search_db as $row) {
		$date = explode( ' ', $row['created_at'] )[0]; // Only gmdate part
		if ( isset( $search_arr[$date] ) ) {
			$search_arr[$date] += $row['count'];
		} else {
			$search_arr[$date] = $row['count'];
		}
	}

	for ( $i = 0; $i <= $report_by_day; $i++) {
		$date 			  = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
		$views_count[$i]  = isset( $views_arr[$date] ) ? $views_arr[$date] : 0;
		$search_count[$i] = isset( $search_arr[$date] ) ? $search_arr[$date] : 0;
		$newDocs[$date]   = 0;
	}
	
	// Reverse arrays if you want oldest to newest
	$labels		  = array_reverse( $labels);
	$total_views  = array_reverse( $views_count);
	$total_search = $search_count;

	// Determine the start gmdate based on $report_by_day
	$recent_start_date = gmdate( 'Y-m-d', strtotime( "-" . ( $report_by_day - 1) . " days", $now ) );
	$recent_end_date   = gmdate( 'Y-m-d', $now ); // up to today

	// Initialize newDocs array for each gmdate in the period
	$newDocs = [];
	for ( $i = $report_by_day - 1; $i >= 0; $i--) {
		$date 			= gmdate( 'Y-m-d', strtotime( "-$i days", $now ) );
		$newDocs[$date] = 0;
	}

	// Query new docs from custom post type 'docs' in the period
	$args = [
		'post_type'      => 'docs',
		'post_status'    => 'publish',
		'date_query'     => [
			'after'     => $recent_start_date,
			'before'    => $recent_end_date,
			'inclusive' => true,
		],
		'posts_per_page' => -1
	];

	$recent_docs = get_posts( $args );

	// Count docs per day
	foreach ( $recent_docs as $doc ) {
		$post_date = get_the_date( 'Y-m-d', $doc );
		if ( isset( $newDocs[$post_date] ) ) {
			$newDocs[$post_date]++;
		}
	}

	// Reverse and reindex for charts if needed
	$new_docs 		= array_values( array_reverse( $newDocs ) );
	$total_search  	= array_reverse( $total_search);
	$total_search  	= array_values( $total_search);
    $to 			= empty ( ezd_get_opt( 'reporting_email' ) ) ? get_option( 'admin_email' ) : ezd_get_opt( 'reporting_email' );
    $subject 		= 'Your Documentation Performance Report';
    $labels 		= [];
	
	for ( $i = $report_by_day; $i >= 0; $i-- ) {
		$labels[] = gmdate( 'M d', strtotime( "-$i days" ) );
	}

	// Step 3: Build QuickChart configuration
	$chartConfig = [
		'type' => 'bar',
		'data' => [
			'labels' => $labels,
			'datasets' => []
			
		],
		'options' => [
			'plugins' => [
				'legend' => ['display' => true, 'position' => 'bottom'],
				'title'  => ['display' => true, 'text' => 'Weekly Performance']
			],
			'scales' => [
				'y' => ['beginAtZero' => true]
			]
		]
	];

	$selected_data = (array)ezd_get_opt( 'reporting_data' ) ?? [];

	// Conditionally add datasets
	if ( in_array( 'views', $selected_data ) ) {
		$chartConfig['data']['datasets'][] = [
			'label'           => 'Views',
			'data'            => $total_views,
			'borderColor'     => '#00e1ffff',
			'backgroundColor' => '#00e1ffff',
			'fill'            => true,
			'borderWidth'     => 0,
			'pointRadius'     => 0
		];
	}

	if ( in_array( 'searches', $selected_data ) ) {
		$chartConfig['data']['datasets'][] = [
			'label'           => 'Searches',
			'data'            => $total_search,
			'borderColor'     => '#09ff00ff',
			'backgroundColor' => '#09ff00ff',
			'fill'            => true,
			'borderWidth'     => 0,
			'pointRadius'     => 0
		];
	}

	if ( in_array( 'reactions', $selected_data ) ) {
		$chartConfig['data']['datasets'][] = [
			'label'           => 'Reactions',
			'data'            => $votes_arr,
			'borderColor'     => '#ff0000ff',
			'backgroundColor' => '#ff0000ff',
			'fill'            => true,
			'borderWidth'     => 0,
			'pointRadius'     => 0
		];
	}

	if ( in_array( 'docs', $selected_data ) ) {
		$chartConfig['data']['datasets'][] = [
			'label'           => 'New Docs',
			'data'            => $new_docs,
			'borderColor'     => '#6634dbff',
			'backgroundColor' => '#6634dbff',
			'fill'            => true,
			'borderWidth'     => 0,
			'pointRadius'     => 0
		];
	}

	// Encode chart JSON for QuickChart API
	$chartUrl = 'https://quickchart.io/chart?c=' . urlencode( json_encode( $chartConfig) );

	ob_start();
	?>
	<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#b8cfff54; padding:20px 0;">
		<tr border="0">
			<td align="center">
			<table width="60%" cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif; background:#ffffff; border-radius:8px; overflow:hidden;">
				
				<!-- Header -->
				<tr style="background:#0008ff;">
					<td style="padding:15px;border:none">
						<table width="100%" style="border:none;margin:0;padding:0">
							<tr border="0">
								<td align="left" style="border:none">
									<a href="<?php echo esc_url( site_url() ); ?>" target="__blank"><img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/eazydocs-logo.png" alt="Logo" width="120" style="display:block;"></a>
								</td>
								<td align="right" style="color:#ffffff; font-size:14px; border:none">
									<strong><?php echo esc_html( $last_day_count ); ?></strong><br>
									<?php echo esc_html( $last_dates ); ?>
								</td>
							</tr> 
						</table>
					</td>
				</tr>

				<!-- Title -->
				<tr border="0">
					<td align="center" style="padding:30px; background:#f7f9fb;">
						<h2 style="margin:0; font-size:23px; color:#333;">
							<?php echo esc_html( ezd_get_opt( 'reporting_heading', __( 'Your Documentation Performance', 'eazydocs-pro' ) ) ); ?>
						</h2>
						<p style="margin:5px 0 0; color:#555;font-size: 16px">
							<?php echo esc_html( ezd_get_opt( 'reporting_description', __( 'Comprehensive analytics for your website documentation', 'eazydocs-pro' ) ) ); ?>
						</p>
					</td>
				</tr>

				<!-- Metrics -->
				<tr border="0">
					<td align="center" style="background:#f7f9fb">
						<table width="100%" cellspacing="15" border="0" style="margin-bottom:-10px;">

							<tr border="0">

								<!-- Total Views -->
								<?php
								if ( in_array( 'views', $selected_data ) ) : ?>
									<td style="box-shadow:0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;border-radius:.75rem;margin-right: 20px">

										<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
											<tr border="0">
												<td align="center" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
													<img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/views.png" width="32" height="32" style="display:block;" alt="Views">
												</td>
											</tr>
										</table>
									
										<strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
											<?php esc_html_e( 'Total Views', 'eazydocs-pro' ); ?>
										</strong>

										<span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
											<?php echo esc_html( eazydocspro_number_format( json_encode( array_sum( $total_views ) ) ) ); ?>
										</span>

										<span style="display:block;text-align:center;color:#f59e0b;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
											<?php echo esc_html( ezd_analytics_diff( $report_by_day, $views_arr ) ); ?>%
										</span>
									</td>
									<?php
								endif;
								?>

								<!-- Total Searches -->
								<?php
								if ( in_array( 'searches', $selected_data ) ) : 
									?>
									<td align="center" border="0" style="box-shadow: 0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;border-radius:.75rem;margin-right: 20px">
										
										<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
											<tr border="0">
												<td align="center" border="0" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
													<img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/search.png" width="32" height="32" style="display:block;" alt="Views">
												</td>
											</tr>
										</table>

										<strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
											<?php esc_html_e( 'Total Searches', 'eazydocs-pro' ); ?>
										</strong>

										<span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
											<?php echo esc_html( eazydocspro_number_format( json_encode( array_sum( $total_search ) ) ) ); ?>
										</span>

										<span style="display:block;text-align:center;color:#f59e0b;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
											<?php echo esc_html( ezd_analytics_diff( $report_by_day, $search_arr ) ) . '%'; ?>
										</span>
									</td>
									<?php
								endif;
								?>

								<!-- Total Reactions -->
								<?php
								if ( in_array( 'reactions', $selected_data ) ) : 
									$votes = ezd_get_total_votes_diff( $report_by_day );
									?>
									<td align="center" border="0" style="box-shadow: 0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;border-radius:.75rem;margin-right: 20px">

										<table border="0" role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
											<tr border="0">
												<td border="0" align="center" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
												<img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/love.png" width="23" height="23" style="display:block;" alt="Views">
												</td>
											</tr>
										</table>

										<strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
											<?php esc_html_e( 'Total Reactions', 'eazydocs-pro' ); ?>
										</strong>

										<span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
											<?php echo isset( $votes['latest_total'] ) ? esc_html( $votes['latest_total'] ) : '0'; ?>
										</span>

										<span style="display:block;text-align:center;color:#f59e0b;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
											<?php echo isset( $votes['total_diff_percent'] ) ? esc_html( $votes['total_diff_percent'] ) . '%' : '0.00%'; ?>
										</span>
									</td>
									<?php
								endif;
								?>

								<!-- New Docs -->
								<?php
								if ( in_array( 'docs', $selected_data ) ) : 
									$docs_stats = ezd_get_posts_diff_percentage( $report_by_day );
									?>
									<td border="0" align="center" style="box-shadow: 0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;;border-radius:.75rem;margin-right: 20px">

										<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
											<tr border="0">
												<td border="0" align="center" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
													<img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/docs.png" width="32" height="32" style="display:block;" alt="Views">
												</td>
											</tr>
										</table>

										<strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
											<?php esc_html_e( 'New Documents', 'eazydocs-pro' ); ?>
										</strong>

										<span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
											<?php echo isset( $docs_stats['recent_total'] ) ? esc_html( $docs_stats['recent_total'] ) : '0'; ?>
										</span>
										<span style="display:block;text-align:center;color:#f59e0b;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
											<?php echo isset( $docs_stats['diff_percent'] ) ? esc_html( $docs_stats['diff_percent'] ) . '%' : '0.00%'; ?>
										</span>
									</td>
									<?php
								endif;
								?>
								
							</tr>

						</table>
					</td>
				</tr>

				<!-- Chart -->
				<tr border="0" style="background: #f7f9fb;padding:15px 25px 25px;display: grid;">
					<td align="center" style="padding:20px;background:#ffffff;border-radius: 10px;border:1px solid #0000000f;">
						<h3 style="color:#1f2937;font-weight:600;font-size:1.125rem;line-height:1.75rem;margin:0;margin-bottom:1rem;text-align:left;display:block">
							<?php esc_html_e( 'Weekly Performance Trend', 'eazydocs-pro' ); ?>
						</h3>
						<img src="<?php echo esc_url( $chartUrl ); ?>" alt="Performance Chart" style="display:block;max-width:100%;height:auto;">
					</td>
				</tr>

			</table>
		</td>
		</tr>
	</table>

    <?php
    $message = ob_get_clean();

	if ( $send_email ) {
		// Build email headers
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		// Get custom site name or use default
		$site_name = ezd_get_opt( 'reporting_site_name' ) ?: get_bloginfo( 'name' );

		// Set From header with site name
		$from_email = get_option( 'admin_email' );
		$headers[]  = 'From: ' . sanitize_text_field( $site_name ) . ' <' . sanitize_email( $from_email ) . '>';

		// Add CC recipients if configured
		$cc_emails = ezd_get_opt( 'reporting_cc_emails' );
		if ( ! empty( $cc_emails ) ) {
			$cc_list = array_map( 'trim', explode( ',', $cc_emails ) );
			$cc_list = array_filter( $cc_list, 'is_email' ); // Validate emails
			foreach ( $cc_list as $cc_email ) {
				$headers[] = 'Cc: ' . sanitize_email( $cc_email );
			}
		}

		// Get recipient and subject
		$to      = ezd_get_opt( 'reporting_email' ) ?: get_option( 'admin_email' );
		$subject = ezd_get_opt( 'reporting_heading' ) ?: esc_html__( 'Docs Analytics Report', 'eazydocs-pro' );

		// Add site name to subject for easier filtering
		$subject = '[' . $site_name . '] ' . $subject;

		// Send the email
		wp_mail( sanitize_email( $to ), sanitize_text_field( $subject ), $message, $headers );
	}
}