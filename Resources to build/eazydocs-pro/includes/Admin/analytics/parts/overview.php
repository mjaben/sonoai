<?php
/**
 * Analytics Overview Tab - Enhanced Version
 * Modern, redesigned overview analytics with improved UI/UX and extended functionality
 *
 * @package EasyDocs\Admin\Analytics
 */

global $wpdb;
$date_range = strtotime( '-7 day' );

// Get views from wp eazy docs views table and post type docs and sum count.
$posts = $wpdb->get_results( "SELECT post_id, SUM(count) AS totalcount, created_at FROM {$wpdb->prefix}eazydocs_view_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY post_id" );

// Get data from wp_eazydocs_search_log based on $date_range with prefix.
$search_keyword = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}eazydocs_search_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );

// Get total search count from wp_eazydocs_search_log table.
$total_search = $wpdb->get_var( "SELECT count(id) FROM {$wpdb->prefix}eazydocs_search_log" );
if ( empty( $total_search ) ) {
	$total_search = 0;
}

// Get total failed searches from wp_eazydocs_search_log.
$total_failed_search = $wpdb->get_var( "SELECT count(id) FROM {$wpdb->prefix}eazydocs_search_log WHERE not_found_count > 0" );
if ( empty( $total_failed_search ) ) {
	$total_failed_search = 0;
}

// Get total positive feedback count from wp meta table.
$total_positive_feedback = $wpdb->get_var( "SELECT SUM(meta_value) FROM {$wpdb->prefix}postmeta WHERE meta_key = 'positive'" );
if ( empty( $total_positive_feedback ) ) {
	$total_positive_feedback = 0;
}

// Get total negative feedback count from wp meta table.
$total_negative_feedback = $wpdb->get_var( "SELECT SUM(meta_value) FROM {$wpdb->prefix}postmeta WHERE meta_key = 'negative'" );
if ( empty( $total_negative_feedback ) ) {
	$total_negative_feedback = 0;
}

// Get total views from the view log or meta.
$total_views = ezdpro_get_total_views();

// Get total docs count.
$total_docs = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'docs' AND post_status = 'publish'" );

// Weekly views sum.
$weekly_views = $wpdb->get_var( "SELECT SUM(count) FROM {$wpdb->prefix}eazydocs_view_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
if ( empty( $weekly_views ) ) {
	$weekly_views = 0;
}

// Today's views.
$today_views = $wpdb->get_var( "SELECT SUM(count) FROM {$wpdb->prefix}eazydocs_view_log WHERE DATE(created_at) = CURDATE()" );
if ( empty( $today_views ) ) {
	$today_views = 0;
}

// Calculate percentages.
$total_feedback        = intval( $total_positive_feedback ) + intval( $total_negative_feedback );
$feedback_satisfaction = $total_feedback > 0 ? round( ( $total_positive_feedback / $total_feedback ) * 100, 1 ) : 0;

$successful_searches   = intval( $total_search ) - intval( $total_failed_search );
$search_success_rate   = $total_search > 0 ? round( ( $successful_searches / $total_search ) * 100, 1 ) : 0;

// Get top 5 most viewed docs this week.
$top_docs_this_week = $wpdb->get_results(
	"SELECT p.ID, p.post_title, SUM(vl.count) as view_count
	FROM {$wpdb->prefix}eazydocs_view_log vl
	INNER JOIN {$wpdb->prefix}posts p ON vl.post_id = p.ID
	WHERE vl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
	AND p.post_type = 'docs' AND p.post_status = 'publish'
	GROUP BY vl.post_id
	ORDER BY view_count DESC
	LIMIT 5"
);

// Get recent feedback (docs with most recent feedback activity).
$recent_feedback_docs = $wpdb->get_results(
	"SELECT p.ID, p.post_title,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'positive' LIMIT 1), 0) as positive,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'negative' LIMIT 1), 0) as negative
	FROM {$wpdb->prefix}posts p
	WHERE p.post_type = 'docs' AND p.post_status = 'publish'
	AND (
		EXISTS (SELECT 1 FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'positive' AND meta_value > 0)
		OR EXISTS (SELECT 1 FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'negative' AND meta_value > 0)
	)
	ORDER BY (
		CAST(COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'positive' LIMIT 1), 0) AS SIGNED) +
		CAST(COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'negative' LIMIT 1), 0) AS SIGNED)
	) DESC
	LIMIT 5"
);

// Get top searched keywords (join keyword table with log table).
$top_keywords = $wpdb->get_results(
	"SELECT k.keyword, COUNT(l.id) AS search_count
	FROM {$wpdb->prefix}eazydocs_search_keyword k
	LEFT JOIN {$wpdb->prefix}eazydocs_search_log l ON k.id = l.keyword_id
	WHERE l.not_found_count = 0
	GROUP BY k.keyword
	ORDER BY search_count DESC
	LIMIT 5"
);

$labels              = [];
$dataCount           = [];
$Liked               = [];
$Disliked            = [];
$searchCount         = [];
$searchCountNotFound = [];

$m  = gmdate( 'm' );
$de = gmdate( 'd' );
$y  = gmdate( 'Y' );

for ( $i = 0; $i <= 6; $i++ ) {
	$labels[]              = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
	$dataCount[]           = 0;
	$Liked[]               = 0;
	$Disliked[]            = 0;
	$searchCount[]         = 0;
	$searchCountNotFound[] = 0;
}

// Get 7 data date wise.
foreach ( $posts as $key => $item ) {
	$dates = gmdate( 'd M, Y', strtotime( $item->created_at ) );

	foreach ( $labels as $datekey => $weekdays ) {
		if ( $weekdays == $dates ) {
			$Liked[ $datekey ]    = $Liked[ $datekey ] + array_sum( get_post_meta( $item->post_id, 'positive', false ) );
			$Disliked[ $datekey ] = $Disliked[ $datekey ] + array_sum( get_post_meta( $item->post_id, 'negative', false ) );
			
			$searchCount[ $datekey ]         = array_sum( array_column( $search_keyword, 'count' ) );
			$searchCountNotFound[ $datekey ] = array_sum( array_column( $search_keyword, 'not_found_count' ) );
		}
	}
}

// Re-tweaked the views.
$eazydocs_view_table = $wpdb->prefix . 'eazydocs_view_log';
$results             = $wpdb->get_results( "SELECT `count`, `created_at` FROM $eazydocs_view_table", ARRAY_A );

$rowValues = array();
foreach ( $results as $row ) {
	$rowValues[ $row['created_at'] ] = $row['count'];
}

$totalValues = array();
foreach ( $rowValues as $dateTime => $value ) {
	$date = explode( ' ', $dateTime )[0];
	if ( isset( $totalValues[ $date ] ) ) {
		$totalValues[ $date ] += $value;
	} else {
		$totalValues[ $date ] = $value;
	}
}

$dataCounts = array();
foreach ( $totalValues as $date => $total ) {
	$dataCounts[ $date ] = $total;
}

$currentDate = gmdate( 'Y-m-d' );

for ( $i = 0; $i <= 6; $i++ ) {
	$date = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
	if ( isset( $dates[ $date ] ) ) {
		if ( ! isset( $dataCounts[ $i ] ) ) {
			$dataCounts[ $i ] = 0;
		}
		$dataCounts[ $i ] += $dates[ $date ];
	}
}

$monthlyViews   = [];
$monthly_labels = [];
for ( $i = 0; $i <= 29; $i++ ) {
	$monthly_labels[] = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
	$monthlyViews[]   = 0;
}

foreach ( $dataCounts as $day => $sum ) {
	$dataCounts[ $day ] = $sum;
}

$currentDate = gmdate( 'Y-m-d' );
foreach ( $dataCounts as $date => $value ) {
	$daysDifference = floor( ( strtotime( $currentDate ) - strtotime( $date ) ) / ( 60 * 60 * 24 ) );
	if ( $daysDifference >= 0 && $daysDifference <= 6 ) {
		$dataCount[ $daysDifference ] = $value;
	}

	if ( $daysDifference >= 0 && $daysDifference <= 29 ) {
		$monthlyViews[ $daysDifference ] = $value;
	}
}
?>

<div class="easydocs-tab tab-active" id="analytics-overview">
	<!-- Page Header -->
	<div class="doc-ranks-header overview-header">
		<div class="header-content">
			<h2 class="title"><?php esc_html_e( 'Analytics Overview', 'eazydocs-pro' ); ?></h2>
			<p class="subtitle"><?php esc_html_e( 'Get a bird\'s eye view of your documentation performance and engagement.', 'eazydocs-pro' ); ?></p>
		</div>
		<div class="header-actions">
			<button class="ezd-btn-icon" id="refresh-overview">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Refresh', 'eazydocs-pro' ); ?>
			</button>
		</div>
	</div>

	<!-- Key Metrics Cards -->
	<div class="doc-ranks-stats overview-stats">
		<a href="#analytics-views" class="stat-card stat-card--primary">
			<div class="stat-icon">
				<span class="dashicons dashicons-visibility"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $total_views ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Total Views', 'eazydocs-pro' ); ?></div>
				<div class="stat-meta">
					<span class="dashicons dashicons-calendar"></span>
					<?php 
					/* translators: %s: weekly views count */
					printf( esc_html__( '%s this week', 'eazydocs-pro' ), esc_html( number_format( $weekly_views ) ) ); 
					?>
				</div>
			</div>
		</a>
		<a href="#analytics-helpful" class="stat-card stat-card--success">
			<div class="stat-icon">
				<span class="dashicons dashicons-thumbs-up"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $feedback_satisfaction ); ?>%</div>
				<div class="stat-label"><?php esc_html_e( 'Satisfaction Rate', 'eazydocs-pro' ); ?></div>
				<div class="stat-meta">
					<span class="dashicons dashicons-chart-pie"></span>
					<?php 
					/* translators: %s: total feedback count */
					printf( esc_html__( '%s total votes', 'eazydocs-pro' ), esc_html( number_format( $total_feedback ) ) ); 
					?>
				</div>
			</div>
		</a>
		<a href="#analytics-search" class="stat-card stat-card--info">
			<div class="stat-icon">
				<span class="dashicons dashicons-search"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $search_success_rate ); ?>%</div>
				<div class="stat-label"><?php esc_html_e( 'Search Success', 'eazydocs-pro' ); ?></div>
				<div class="stat-meta">
					<span class="dashicons dashicons-editor-help"></span>
					<?php 
					/* translators: %s: failed searches count */
					printf( esc_html__( '%s failed searches', 'eazydocs-pro' ), esc_html( number_format( $total_failed_search ) ) ); 
					?>
				</div>
			</div>
		</a>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=docs' ) ); ?>" class="stat-card stat-card--warning">
			<div class="stat-icon">
				<span class="dashicons dashicons-media-document"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $total_docs ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Published Docs', 'eazydocs-pro' ); ?></div>
				<div class="stat-meta">
					<span class="dashicons dashicons-clock"></span>
					<?php 
					/* translators: %s: today's views count */
					printf( esc_html__( '%s views today', 'eazydocs-pro' ), esc_html( number_format( $today_views ) ) ); 
					?>
				</div>
			</div>
		</a>
	</div>

	<!-- Trends Chart Section -->
	<div class="overview-chart-section">
		<div class="chart-header">
			<div class="chart-title">
				<span class="dashicons dashicons-chart-area"></span>
				<div class="title-wrapper">
					<h3><?php esc_html_e( 'Performance Trends', 'eazydocs-pro' ); ?></h3>
					<span class="chart-date-range" id="overview-chart-date-range">
						<?php 
						$start_date = gmdate( 'd M, Y', strtotime( '-6 days' ) );
						$end_date   = gmdate( 'd M, Y' );
						echo esc_html( $start_date . ' - ' . $end_date );
						?>
					</span>
				</div>
			</div>
			<div class="chart-controls">
				<!-- Metric Toggles -->
				<div class="chart-toggles">
					<label class="toggle-item toggle-item--views">
						<input type="checkbox" name="filterChart" value="Views" id="views" checked/>
						<span class="toggle-indicator"></span>
						<span class="toggle-label"><?php esc_html_e( 'Views', 'eazydocs-pro' ); ?></span>
					</label>
					<label class="toggle-item toggle-item--feedback">
						<input type="checkbox" name="filterChart" value="Feedback" id="feedback" checked/>
						<span class="toggle-indicator"></span>
						<span class="toggle-label"><?php esc_html_e( 'Feedback', 'eazydocs-pro' ); ?></span>
					</label>
					<label class="toggle-item toggle-item--searches">
						<input type="checkbox" name="filterChart" value="Searches" id="searches" checked/>
						<span class="toggle-indicator"></span>
						<span class="toggle-label"><?php esc_html_e( 'Searches', 'eazydocs-pro' ); ?></span>
					</label>
				</div>
				<!-- Time Range Filters -->
				<div class="filter-tabs overview-time-filters">
					<button class="filter-tab is-active" data-filter="week" onclick="OverviewAllTimes()">
						<span class="dashicons dashicons-calendar"></span>
						<?php esc_html_e( 'This Week', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab" data-filter="month" onclick="OverviewLastmonth()">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'Last 30 Days', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab date__range_overview" data-filter="custom">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Custom', 'eazydocs-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
		<div class="chart-wrapper">
			<div id="OvervIewChart"></div>
		</div>
	</div>

	<!-- Analytics Breakdown Grid -->
	<div class="overview-breakdown-grid">
		<!-- Article Feedback Summary -->
		<div class="overview-summary-card">
			<div class="summary-header">
				<span class="dashicons dashicons-heart"></span>
				<h3><?php esc_html_e( 'Article Feedback', 'eazydocs-pro' ); ?></h3>
			</div>
			<div class="summary-content">
				<div class="summary-metrics">
					<div class="metric-item metric-item--positive">
						<div class="metric-icon">
							<span class="dashicons dashicons-thumbs-up"></span>
						</div>
						<div class="metric-details">
							<span class="metric-value"><?php echo esc_html( eazydocspro_number_format( $total_positive_feedback ) ); ?></span>
							<span class="metric-label"><?php esc_html_e( 'Helpful Votes', 'eazydocs-pro' ); ?></span>
						</div>
					</div>
					<div class="metric-item metric-item--negative">
						<div class="metric-icon">
							<span class="dashicons dashicons-thumbs-down"></span>
						</div>
						<div class="metric-details">
							<span class="metric-value"><?php echo esc_html( eazydocspro_number_format( $total_negative_feedback ) ); ?></span>
							<span class="metric-label"><?php esc_html_e( 'Not Helpful', 'eazydocs-pro' ); ?></span>
						</div>
					</div>
				</div>
				<div class="summary-chart">
					<div id="feedback-donut-chart"></div>
				</div>
				<div class="summary-insight article-feedback-insight">
					<?php if ( $total_feedback > 0 ) : ?>
						<span class="insight-badge insight-badge--<?php echo $feedback_satisfaction >= 50 ? 'success' : 'warning'; ?>">
							<?php echo esc_html( $feedback_satisfaction ); ?>% <?php esc_html_e( 'Satisfaction', 'eazydocs-pro' ); ?>
						</span>
					<?php else : ?>
						<span class="insight-badge insight-badge--neutral">
							<?php esc_html_e( 'No feedback data yet', 'eazydocs-pro' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Search Effectiveness Summary -->
		<div class="overview-summary-card">
			<div class="summary-header">
				<span class="dashicons dashicons-search"></span>
				<h3><?php esc_html_e( 'Search Effectiveness', 'eazydocs-pro' ); ?></h3>
			</div>
			<div class="summary-content">
				<div class="summary-metrics">
					<div class="metric-item metric-item--success">
						<div class="metric-icon">
							<span class="dashicons dashicons-yes-alt"></span>
						</div>
						<div class="metric-details">
							<span class="metric-value"><?php echo esc_html( eazydocspro_number_format( $total_search ) ); ?></span>
							<span class="metric-label"><?php esc_html_e( 'Total Searches', 'eazydocs-pro' ); ?></span>
						</div>
					</div>
					<div class="metric-item metric-item--danger">
						<div class="metric-icon">
							<span class="dashicons dashicons-dismiss"></span>
						</div>
						<div class="metric-details">
							<span class="metric-value"><?php echo esc_html( eazydocspro_number_format( $total_failed_search ) ); ?></span>
							<span class="metric-label"><?php esc_html_e( 'Failed Searches', 'eazydocs-pro' ); ?></span>
						</div>
					</div>
				</div>
				<div class="summary-chart">
					<div id="search-donut-chart"></div>
				</div>
				<div class="summary-insight search-effectiveness-insight">
					<?php if ( $total_search > 0 ) : ?>
						<span class="insight-badge insight-badge--<?php echo $search_success_rate >= 70 ? 'success' : ( $search_success_rate >= 40 ? 'warning' : 'danger' ); ?>">
							<?php echo esc_html( $search_success_rate ); ?>% <?php esc_html_e( 'Success Rate', 'eazydocs-pro' ); ?>
						</span>
					<?php else : ?>
						<span class="insight-badge insight-badge--neutral">
							<?php esc_html_e( 'No search data yet', 'eazydocs-pro' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Quick Lists Grid -->
	<div class="overview-lists-grid">
		<!-- Top Docs This Week -->
		<div class="overview-list-card">
			<div class="list-header">
				<span class="dashicons dashicons-star-filled"></span>
				<h3><?php esc_html_e( 'Trending Documentations', 'eazydocs-pro' ); ?></h3>
				<a href="#analytics-views" class="list-view-all"><?php esc_html_e( 'View All', 'eazydocs-pro' ); ?></a>
			</div>
			<ul class="quick-list">
				<?php if ( ! empty( $top_docs_this_week ) ) : ?>
					<?php foreach ( $top_docs_this_week as $index => $doc ) : ?>
						<li class="quick-list-item">
							<span class="item-rank"><?php echo esc_html( $index + 1 ); ?></span>
							<a href="<?php echo esc_url( get_permalink( $doc->ID ) ); ?>" class="item-title" target="_blank">
								<?php echo esc_html( wp_trim_words( $doc->post_title, 6 ) ); ?>
							</a>
							<span class="item-badge item-badge--views">
								<span class="dashicons dashicons-visibility"></span>
								<?php echo esc_html( number_format( $doc->view_count ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				<?php else : ?>
					<li class="quick-list-empty">
						<span class="dashicons dashicons-info-outline"></span>
						<?php esc_html_e( 'No view data this week', 'eazydocs-pro' ); ?>
					</li>
				<?php endif; ?>
			</ul>
		</div>

		<!-- Top Feedback Docs -->
		<div class="overview-list-card">
			<div class="list-header">
				<span class="dashicons dashicons-heart"></span>
				<h3><?php esc_html_e( 'Most Engaged Docs', 'eazydocs-pro' ); ?></h3>
				<a href="#analytics-feedback" class="list-view-all"><?php esc_html_e( 'View All', 'eazydocs-pro' ); ?></a>
			</div>
			<ul class="quick-list">
				<?php if ( ! empty( $recent_feedback_docs ) ) : ?>
					<?php foreach ( $recent_feedback_docs as $index => $doc ) : ?>
						<?php
						$doc_total = intval( $doc->positive ) + intval( $doc->negative );
						$doc_rate  = $doc_total > 0 ? round( ( $doc->positive / $doc_total ) * 100 ) : 0;
						?>
						<li class="quick-list-item">
							<span class="item-rank"><?php echo esc_html( $index + 1 ); ?></span>
							<a href="<?php echo esc_url( get_permalink( $doc->ID ) ); ?>" class="item-title" target="_blank">
								<?php echo esc_html( wp_trim_words( $doc->post_title, 6 ) ); ?>
							</a>
							<span class="item-badge item-badge--<?php echo $doc_rate >= 50 ? 'success' : 'warning'; ?>">
								<?php echo esc_html( $doc_rate ); ?>%
							</span>
						</li>
					<?php endforeach; ?>
				<?php else : ?>
					<li class="quick-list-empty">
						<span class="dashicons dashicons-info-outline"></span>
						<?php esc_html_e( 'No feedback data yet', 'eazydocs-pro' ); ?>
					</li>
				<?php endif; ?>
			</ul>
		</div>

		<!-- Top Search Keywords -->
		<div class="overview-list-card">
			<div class="list-header">
				<span class="dashicons dashicons-search"></span>
				<h3><?php esc_html_e( 'Popular Searches', 'eazydocs-pro' ); ?></h3>
				<a href="#analytics-search" class="list-view-all"><?php esc_html_e( 'View All', 'eazydocs-pro' ); ?></a>
			</div>
			<ul class="quick-list">
				<?php if ( ! empty( $top_keywords ) ) : ?>
					<?php foreach ( $top_keywords as $index => $kw ) : ?>
						<li class="quick-list-item">
							<span class="item-rank"><?php echo esc_html( $index + 1 ); ?></span>
							<span class="item-title item-title--keyword"><?php echo esc_html( $kw->keyword ); ?></span>
							<span class="item-badge item-badge--info">
								<?php echo esc_html( number_format( $kw->search_count ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				<?php else : ?>
					<li class="quick-list-empty">
						<span class="dashicons dashicons-info-outline"></span>
						<?php esc_html_e( 'No search data yet', 'eazydocs-pro' ); ?>
					</li>
				<?php endif; ?>
			</ul>
		</div>
	</div>
</div>
 
<script>
	jQuery(document).ready(function($) {
		// Handle filter buttons active state.
		$('.overview-time-filters .filter-tab').on('click', function() {
			$('.overview-time-filters .filter-tab').removeClass('is-active');
			$(this).addClass('is-active');
		});

		// Refresh button.
		$('#refresh-overview').on('click', function() {
			location.reload();
		});
	});

	// ApexCharts configuration with modern styling.
	var options = {
		chart: {
			height: 380,
			type: 'area',
			fontFamily: 'inherit',
			toolbar: {
				show: true,
				tools: {
					download: true,
					selection: false,
					zoom: true,
					zoomin: true,
					zoomout: true,
					pan: false,
					reset: true
				}
			},
			zoom: {
				enabled: true
			},
			animations: {
				enabled: true,
				easing: 'easeinout',
				speed: 800
			}
		},
		colors: ['#6366f1', '#10b981', '#f59e0b'],
		dataLabels: {
			enabled: false
		},
		series: [
			{
				name: "Views",
				data: <?php echo wp_json_encode( $dataCount ); ?>
			},
			{
				name: "Feedback",
				data: <?php echo wp_json_encode( array_map( function( $l, $d ) { return $l + $d; }, $Liked, $Disliked ) ); ?>
			},
			{
				name: "Searches",
				data: <?php echo wp_json_encode( $searchCount ); ?>
			}
		],
		fill: {
			type: "gradient",
			gradient: {
				shadeIntensity: 1,
				opacityFrom: 0.45,
				opacityTo: 0.05,
				stops: [0, 100]
			}
		},
		stroke: {
			curve: 'smooth',
			width: 3
		},
		xaxis: {
			categories: <?php echo wp_json_encode( $labels ); ?>,
			axisBorder: {
				show: false
			},
			axisTicks: {
				show: false
			},
			labels: {
				show: false
			}
		},
		yaxis: {
			labels: {
				style: {
					colors: '#64748b',
					fontSize: '12px'
				},
				formatter: function(val) {
					return Math.round(val);
				}
			}
		},
		grid: {
			borderColor: '#e2e8f0',
			strokeDashArray: 4
		},
		legend: {
			show: false,
			position: 'top',
			horizontalAlign: 'right',
			fontSize: '13px',
			fontWeight: 500,
			markers: {
				radius: 12
			}
		},
		tooltip: {
			theme: 'light',
			x: {
				show: true
			}
		},
		markers: {
			size: 4,
			colors: ['#fff'],
			strokeColors: ['#6366f1', '#10b981', '#f59e0b'],
			strokeWidth: 2,
			hover: {
				size: 7
			}
		}
	};

	var Overviewchart = new ApexCharts(document.querySelector("#OvervIewChart"), options);
	Overviewchart.render();

	// Feedback Donut Chart.
	var feedbackDonutOptions = {
		chart: {
			type: 'donut',
			height: 180,
			fontFamily: 'inherit'
		},
		series: [<?php echo esc_js( $total_positive_feedback ); ?>, <?php echo esc_js( $total_negative_feedback ); ?>],
		labels: ['<?php esc_attr_e( 'Helpful', 'eazydocs-pro' ); ?>', '<?php esc_attr_e( 'Not Helpful', 'eazydocs-pro' ); ?>'],
		colors: ['#10b981', '#ef4444'],
		plotOptions: {
			pie: {
				donut: {
					size: '70%',
					labels: {
						show: true,
						name: {
							show: true,
							fontSize: '12px',
							color: '#64748b'
						},
						value: {
							show: true,
							fontSize: '20px',
							fontWeight: 700,
							color: '#0f172a'
						},
						total: {
							show: true,
							label: '<?php esc_attr_e( 'Total', 'eazydocs-pro' ); ?>',
							fontSize: '12px',
							color: '#64748b'
						}
					}
				}
			}
		},
		legend: {
			show: false
		},
		dataLabels: {
			enabled: false
		}
	};

	var feedbackDonut = new ApexCharts(document.querySelector("#feedback-donut-chart"), feedbackDonutOptions);
	feedbackDonut.render();

	// Search Donut Chart.
	var searchDonutOptions = {
		chart: {
			type: 'donut',
			height: 180,
			fontFamily: 'inherit'
		},
		series: [<?php echo esc_js( $successful_searches ); ?>, <?php echo esc_js( $total_failed_search ); ?>],
		labels: ['<?php esc_attr_e( 'Found', 'eazydocs-pro' ); ?>', '<?php esc_attr_e( 'Not Found', 'eazydocs-pro' ); ?>'],
		colors: ['#3b82f6', '#f59e0b'],
		plotOptions: {
			pie: {
				donut: {
					size: '70%',
					labels: {
						show: true,
						name: {
							show: true,
							fontSize: '12px',
							color: '#64748b'
						},
						value: {
							show: true,
							fontSize: '20px',
							fontWeight: 700,
							color: '#0f172a'
						},
						total: {
							show: true,
							label: '<?php esc_attr_e( 'Total', 'eazydocs-pro' ); ?>',
							fontSize: '12px',
							color: '#64748b'
						}
					}
				}
			}
		},
		legend: {
			show: false
		},
		dataLabels: {
			enabled: false
		}
	};

	var searchDonut = new ApexCharts(document.querySelector("#search-donut-chart"), searchDonutOptions);
	searchDonut.render();

	// Checkbox filter for chart series.
	var ezd_docs_checkbox = document.querySelectorAll(".chart-toggles input[type=checkbox]");
	ezd_docs_checkbox.forEach(function (ezd_docs_checkbox) {
		ezd_docs_checkbox.addEventListener("change", function () {
			if (this.checked) {
				Overviewchart.showSeries(this.value);
			} else {
				Overviewchart.hideSeries(this.value);
			}
		});
	});

	function OverviewAllTimes() {
		Overviewchart.updateOptions({
			xaxis: {
				categories: <?php echo wp_json_encode( $labels ); ?>,
				labels: { show: false }
			},
			series: [
				{
					name: 'Views',
					data: <?php echo wp_json_encode( $dataCount ); ?>
				},
				{
					name: 'Feedback',
					data: <?php echo wp_json_encode( $Liked ); ?>
				},
				{
					name: 'Searches',
					data: <?php echo wp_json_encode( array_reverse( $searchCount ) ); ?>
				}
			]
		});
		// Update date range text
		document.getElementById('overview-chart-date-range').textContent = '<?php echo esc_js( gmdate( 'd M, Y', strtotime( '-6 days' ) ) . ' - ' . gmdate( 'd M, Y' ) ); ?>';
	}

	function OverviewLastmonth() {
		var nonce = '<?php echo wp_create_nonce( "ezd_analytics_nonce" ); ?>';

		jQuery.ajax({
			type: "POST",
			dataType: "json",
			url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
			data: {
				action: "ezd_analytics_overview_last_month",
				nonce: nonce
			},
			success: function (response) {
				if (response.success) {
					Overviewchart.updateOptions({
						xaxis: {
							categories: response.data.labels,
							labels: { show: false }
						},
						series: [{
							name: 'Views',
							data: response.data.views
						},
						{
							name: 'Feedback',
							data: response.data.feedback
						},
						{
							name: 'Searches',
							data: response.data.searches
						}],
					});
					// Update date range text
					if (response.data.range_text) {
						document.getElementById('overview-chart-date-range').textContent = response.data.range_text;
					}
				} else {
					console.error("Failed to fetch data", response);
				}
			}
		});
	}

	// Date range picker.
	function OverViewDateRange() {
		jQuery('.date__range_overview').daterangepicker().on('apply.daterangepicker', function (e, picker) {
			// Update active state.
			jQuery('.overview-time-filters .filter-tab').removeClass('is-active');
			jQuery(this).addClass('is-active');

			var startDate = picker.startDate.format('Y-M-D');
			var endDate = picker.endDate.format('Y-M-D');
			var startDateDisplay = picker.startDate.format('DD MMM, YYYY');
			var endDateDisplay = picker.endDate.format('DD MMM, YYYY');

			jQuery.ajax({
				type: "POST",
				dataType: "json",
				url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
				data: {action: "edz_filter_overview_date", startDate: startDate, endDate: endDate},
				success: function (response) {
					Overviewchart.updateOptions({
						xaxis: {
							categories: response.data.labels,
							labels: { show: false }
						},
						series: [{
							name: 'Views',
							data: response.data.views
						},
						{
							name: 'Feedback',
							data: response.data.liked
						},
						{
							name: 'Searches',
							data: response.data.searchCount
						}],
					});
					// Update date range text
					document.getElementById('overview-chart-date-range').textContent = startDateDisplay + ' - ' + endDateDisplay;
				}
			});
		})
	}

	OverViewDateRange();
</script>