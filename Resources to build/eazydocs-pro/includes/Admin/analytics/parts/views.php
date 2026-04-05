<?php
/**
 * Analytics Views Tab - Enhanced Version
 * Modern, redesigned views analytics with improved UI/UX and extended functionality
 *
 * @package EasyDocs\Admin\Analytics
 */

global $wpdb;

$labels       = [];
$dataCount    = [];
$monthlyViews = [];
$m            = gmdate( 'm' );
$de           = gmdate( 'd' );
$y            = gmdate( 'Y' );

// Initialize weekly labels.
for ( $i = 0; $i <= 6; $i++ ) {
	$labels[]    = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
	$dataCount[] = 0;
}

// Initialize monthly labels.
$monthly_labels = [];
for ( $i = 0; $i <= 29; $i++ ) {
	$monthly_labels[] = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
	$monthlyViews[]   = 0;
}

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

// Calculate statistics.
$this_week_views  = array_sum( $dataCount );
$last_month_views = array_sum( $monthlyViews );
$total_views      = ezdpro_get_total_views();

// Today's views.
$today = gmdate( 'Y-m-d' );
$today_views = isset( $dataCounts[ $today ] ) ? intval( $dataCounts[ $today ] ) : 0;

// Calculate average daily views.
$avg_daily_views = $last_month_views > 0 ? round( $last_month_views / 30, 1 ) : 0;

// Get top viewed Documentations.
$top_viewed_docs = $wpdb->get_results(
	"SELECT p.ID, p.post_title, 
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'post_views_count' LIMIT 1), 0) as views,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'positive' LIMIT 1), 0) as positive,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'negative' LIMIT 1), 0) as negative
	FROM {$wpdb->prefix}posts p 
	WHERE p.post_type = 'docs' AND p.post_status = 'publish'
	ORDER BY CAST(COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'post_views_count' LIMIT 1), 0) AS SIGNED) DESC
	LIMIT 15"
);

// Get recently viewed Documentations (last 7 days based on view log).
$recent_view_logs = $wpdb->get_results(
	"SELECT vl.post_id, vl.count, vl.created_at, p.post_title
	FROM {$wpdb->prefix}eazydocs_view_log vl
	INNER JOIN {$wpdb->prefix}posts p ON vl.post_id = p.ID
	WHERE p.post_type = 'docs' AND p.post_status = 'publish'
	ORDER BY vl.created_at DESC
	LIMIT 20"
);

// Get total docs count.
$total_docs = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'docs' AND post_status = 'publish'" );

// Get docs with zero views.
$docs_with_views = $wpdb->get_var(
	"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->prefix}posts p 
	INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id 
	WHERE p.post_type = 'docs' AND p.post_status = 'publish' 
	AND pm.meta_key = 'post_views_count' AND CAST(pm.meta_value AS UNSIGNED) > 0"
);
$docs_without_views = intval( $total_docs ) - intval( $docs_with_views );

// Calculate view coverage percentage.
$view_coverage = $total_docs > 0 ? round( ( $docs_with_views / $total_docs ) * 100, 1 ) : 0;
?>

<div class="easydocs-tab" id="analytics-views">
	<!-- Page Header -->
	<div class="doc-ranks-header views-header">
		<div class="header-content">
			<h2 class="title"><?php esc_html_e( 'Views Analytics', 'eazydocs-pro' ); ?></h2>
			<p class="subtitle"><?php esc_html_e( 'Monitor documentations traffic and identify your most popular content.', 'eazydocs-pro' ); ?></p>
		</div>
		<div class="header-actions">
			<button class="ezd-btn-icon" id="export-views-data">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Export Data', 'eazydocs-pro' ); ?>
			</button>
			<button class="ezd-btn-icon" id="refresh-views-data">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Refresh', 'eazydocs-pro' ); ?>
			</button>
		</div>
	</div>

	<!-- Statistics Cards -->
	<div class="doc-ranks-stats views-stats">
		<div class="stat-card stat-card--primary">
			<div class="stat-icon">
				<span class="dashicons dashicons-visibility"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $total_views ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Total Views', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--success">
			<div class="stat-icon">
				<span class="dashicons dashicons-calendar"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $this_week_views ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'This Week', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--info">
			<div class="stat-icon">
				<span class="dashicons dashicons-clock"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $today_views ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Today', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--warning">
			<div class="stat-icon">
				<span class="dashicons dashicons-chart-line"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $avg_daily_views ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Avg. Daily', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
	</div>

	<!-- Enhanced Chart Section -->
	<div class="views-chart-section">
		<div class="chart-header">
			<div class="chart-title">
				<span class="dashicons dashicons-chart-area"></span>
				<div class="title-wrapper">
					<h3><?php esc_html_e( 'Traffic Trends', 'eazydocs-pro' ); ?></h3>
					<span class="chart-date-range" id="views-chart-date-range">
						<?php 
						$start_date_views = gmdate( 'd M, Y', strtotime( '-6 days' ) );
						$end_date_views   = gmdate( 'd M, Y' );
						echo esc_html( $start_date_views . ' - ' . $end_date_views );
						?>
					</span>
				</div>
			</div>
			<div class="chart-controls">
				<!-- Time Range Filters -->
				<div class="filter-tabs views-time-filters">
					<button class="filter-tab is-active" data-filter="week" onclick="ViewsLastSeveday()">
						<span class="dashicons dashicons-calendar"></span>
						<?php esc_html_e( 'This Week', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab" data-filter="month" onclick="ViewLastMonth()">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'Last 30 Days', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab date__range_view" data-filter="custom">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Custom', 'eazydocs-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
		<div class="chart-wrapper">
			<div id="OverviewViewsChart"></div>
		</div>
	</div>

	<!-- Views Breakdown Grid -->
	<div class="views-breakdown-grid">
		<!-- Coverage Breakdown -->
		<div class="views-breakdown-card">
			<div class="breakdown-header">
				<span class="dashicons dashicons-chart-pie"></span>
				<h3><?php esc_html_e( 'Content Coverage', 'eazydocs-pro' ); ?></h3>
			</div>
			<div class="breakdown-content">
				<div class="coverage-chart">
					<div id="coverage-radial-chart"></div>
				</div>
				<div class="coverage-details">
					<div class="coverage-item">
						<div class="coverage-indicator coverage-indicator--viewed"></div>
						<div class="coverage-info">
							<span class="coverage-label"><?php esc_html_e( 'Docs with Views', 'eazydocs-pro' ); ?></span>
							<span class="coverage-value"><?php echo esc_html( number_format( $docs_with_views ) ); ?></span>
						</div>
					</div>
					<div class="coverage-item">
						<div class="coverage-indicator coverage-indicator--unviewed"></div>
						<div class="coverage-info">
							<span class="coverage-label"><?php esc_html_e( 'No Views Yet', 'eazydocs-pro' ); ?></span>
							<span class="coverage-value"><?php echo esc_html( number_format( $docs_without_views ) ); ?></span>
						</div>
					</div>
					<div class="coverage-total">
						<span class="total-label"><?php esc_html_e( 'Total Documentations', 'eazydocs-pro' ); ?></span>
						<span class="total-value"><?php echo esc_html( number_format( $total_docs ) ); ?></span>
					</div>
				</div>
			</div>
		</div>

		<!-- Recent Activity -->
		<div class="views-activity-card">
			<div class="activity-header">
				<span class="dashicons dashicons-clock"></span>
				<h3><?php esc_html_e( 'Recent Activity', 'eazydocs-pro' ); ?></h3>
			</div>
			<div class="activity-list">
				<?php if ( ! empty( $recent_view_logs ) ) : ?>
					<?php foreach ( $recent_view_logs as $log ) : ?>
						<?php
						$doc_title = ! empty( $log->post_title ) ? $log->post_title : __( 'Untitled', 'eazydocs-pro' );
						$time_ago  = human_time_diff( strtotime( $log->created_at ), current_time( 'timestamp' ) );
						?>
						<div class="activity-item activity-item--view">
							<div class="activity-icon">
								<span class="dashicons dashicons-visibility"></span>
							</div>
							<div class="activity-content">
								<p class="activity-text">
									<a href="<?php echo esc_url( get_permalink( $log->post_id ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $doc_title, 6 ) ); ?></a>
									<span class="view-count"><?php echo esc_html( $log->count ); ?> <?php esc_html_e( 'views', 'eazydocs-pro' ); ?></span>
								</p>
								<span class="activity-time">
									<span class="dashicons dashicons-clock"></span>
									<?php 
									/* translators: %s: human time diff */
									printf( esc_html__( '%s ago', 'eazydocs-pro' ), esc_html( $time_ago ) ); 
									?>
								</span>
							</div>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="empty-state empty-state--small">
						<span class="dashicons dashicons-clock"></span>
						<p><?php esc_html_e( 'No recent view activity.', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Top Viewed Documentations -->
	<div class="views-docs-section">
		<div class="keywords-panel keywords-panel--popular">
			<div class="panel-header">
				<div class="panel-title">
					<span class="dashicons dashicons-star-filled"></span>
					<h3><?php esc_html_e( 'Most Viewed Documentations', 'eazydocs-pro' ); ?></h3>
				</div>
				<div class="panel-actions">
					<div class="search-input-wrapper">
						<span class="dashicons dashicons-search"></span>
						<input type="text" id="search-viewed-docs" placeholder="<?php esc_attr_e( 'Filter docs...', 'eazydocs-pro' ); ?>">
					</div>
				</div>
			</div>
			<div class="panel-content">
				<?php if ( ! empty( $top_viewed_docs ) ) : ?>
					<ul class="keywords-list doc-views-list" id="viewed-docs-list">
						<?php foreach ( $top_viewed_docs as $index => $doc ) : ?>
							<?php
							$views     = intval( $doc->views );
							$positive  = intval( $doc->positive );
							$negative  = intval( $doc->negative );
							$total_fb  = $positive + $negative;
							$rate      = $total_fb > 0 ? round( ( $positive / $total_fb ) * 100 ) : 0;
							$rank_class = '';
							if ( 0 === $index ) {
								$rank_class = 'rank-gold';
							} elseif ( 1 === $index ) {
								$rank_class = 'rank-silver';
							} elseif ( 2 === $index ) {
								$rank_class = 'rank-bronze';
							}
							?>
							<li class="keyword-item <?php echo esc_attr( $rank_class ); ?>" data-title="<?php echo esc_attr( strtolower( $doc->post_title ) ); ?>">
								<div class="keyword-rank">
									<?php if ( $index < 3 ) : ?>
										<span class="rank-medal rank-medal--<?php echo esc_attr( $rank_class ); ?>">
											<?php echo esc_html( $index + 1 ); ?>
										</span>
									<?php else : ?>
										<span class="rank-number"><?php echo esc_html( $index + 1 ); ?></span>
									<?php endif; ?>
								</div>
								<div class="keyword-info">
									<span class="keyword-text">
										<a href="<?php echo esc_url( get_permalink( $doc->ID ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $doc->post_title, 10 ) ); ?></a>
									</span>
									<?php if ( $total_fb > 0 ) : ?>
										<span class="keyword-meta"><?php echo esc_html( $rate ); ?>% <?php esc_html_e( 'satisfaction', 'eazydocs-pro' ); ?></span>
									<?php else : ?>
										<span class="keyword-meta"><?php esc_html_e( 'No feedback yet', 'eazydocs-pro' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="keyword-stats">
									<span class="stat-badge stat-badge--info">
										<span class="dashicons dashicons-visibility"></span>
										<?php echo esc_html( number_format( $views ) ); ?>
									</span>
									<?php if ( $positive > 0 ) : ?>
										<span class="stat-badge stat-badge--success">
											<span class="dashicons dashicons-thumbs-up"></span>
											<?php echo esc_html( $positive ); ?>
										</span>
									<?php endif; ?>
								</div>
								<div class="keyword-actions">
									<a href="<?php echo esc_url( get_permalink( $doc->ID ) ); ?>" class="action-btn action-btn--view" title="<?php esc_attr_e( 'View Doc', 'eazydocs-pro' ); ?>" target="_blank">
										<span class="dashicons dashicons-external"></span>
									</a>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<div class="empty-state">
						<span class="dashicons dashicons-visibility"></span>
						<p><?php esc_html_e( 'No view data available yet.', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Views Insights -->
	<div class="search-insights-section views-insights-section">
		<div class="insights-header">
			<span class="dashicons dashicons-lightbulb"></span>
			<h3><?php esc_html_e( 'Quick Insights', 'eazydocs-pro' ); ?></h3>
		</div>
		<div class="insights-grid">
			<div class="insight-card">
				<div class="insight-icon insight-icon--success">
					<span class="dashicons dashicons-chart-bar"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Traffic Overview', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						if ( $this_week_views > $avg_daily_views * 7 ) {
							esc_html_e( 'Traffic is above average this week! Your content is attracting more visitors.', 'eazydocs-pro' );
						} elseif ( $this_week_views > 0 ) {
							esc_html_e( 'Steady traffic this week. Consider promoting your most helpful content.', 'eazydocs-pro' );
						} else {
							esc_html_e( 'No traffic recorded this week. Check if view tracking is enabled.', 'eazydocs-pro' );
						}
						?>
					</p>
				</div>
			</div>
			<div class="insight-card">
				<div class="insight-icon insight-icon--info">
					<span class="dashicons dashicons-admin-page"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Content Coverage', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						printf(
							/* translators: 1: coverage percentage, 2: docs with views, 3: total docs */
							esc_html__( '%1$s%% of your docs (%2$s/%3$s) have received views. Ensure all content is discoverable.', 'eazydocs-pro' ),
							'<strong>' . esc_html( $view_coverage ) . '</strong>',
							esc_html( number_format( $docs_with_views ) ),
							esc_html( number_format( $total_docs ) )
						);
						?>
					</p>
				</div>
			</div>
			<div class="insight-card">
				<div class="insight-icon insight-icon--warning">
					<span class="dashicons dashicons-megaphone"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Recommendations', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						if ( $docs_without_views > 0 ) {
							printf(
								/* translators: %s: number of docs without views */
								esc_html__( '%s Documentations have no views. Consider improving their SEO or linking them from popular pages.', 'eazydocs-pro' ),
								'<strong>' . esc_html( $docs_without_views ) . '</strong>'
							);
						} else {
							esc_html_e( 'All your Documentations have received views. Great job!', 'eazydocs-pro' );
						}
						?>
					</p>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	jQuery(document).ready(function($) {
		// Handle time filter buttons active state.
		$('.views-time-filters .filter-tab').on('click', function() {
			$('.views-time-filters .filter-tab').removeClass('is-active');
			$(this).addClass('is-active');
		});

		// Filter viewed docs.
		$('#search-viewed-docs').on('keyup', function() {
			var filter = $(this).val().toLowerCase();
			$('#viewed-docs-list .keyword-item').each(function() {
				var title = $(this).data('title');
				if (title.indexOf(filter) > -1) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		// Export data functionality.
		$('#export-views-data').on('click', function() {
			var csvContent = "data:text/csv;charset=utf-8,";
			csvContent += "Rank,Document,Views,Positive Feedback,Satisfaction Rate\n";

			$('#viewed-docs-list .keyword-item').each(function(index) {
				var title = $(this).find('.keyword-text a').text().trim();
				var views = $(this).find('.stat-badge--info').text().trim();
				var positive = $(this).find('.stat-badge--success').text().trim() || '0';
				var rate = $(this).find('.keyword-meta').text().trim();
				csvContent += (index + 1) + ',"' + title + '",' + views + ',' + positive + ',"' + rate + '"\n';
			});

			var encodedUri = encodeURI(csvContent);
			var link = document.createElement("a");
			link.setAttribute("href", encodedUri);
			link.setAttribute("download", "views_analytics_" + new Date().toISOString().split('T')[0] + ".csv");
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
		});

		// Refresh data.
		$('#refresh-views-data').on('click', function() {
			location.reload();
		});
	});

	// Area Chart Configuration.
	var viewsChartOptions = {
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
				},
				autoSelected: 'zoom'
			},
			zoom: {
				enabled: true
			},
			animations: {
				enabled: true,
				easing: 'easeinout',
				speed: 800,
				animateGradually: {
					enabled: true,
					delay: 150
				},
				dynamicAnimation: {
					enabled: true,
					speed: 350
				}
			}
		},
		colors: ['#6366f1'],
		dataLabels: {
			enabled: false
		},
		series: [
			{
				name: "Views",
				data: <?php echo wp_json_encode( $dataCount ); ?>
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
			show: false
		},
		tooltip: {
			theme: 'light',
			x: {
				format: 'dd MMM yyyy'
			},
			y: {
				formatter: function(val) {
					return val + " views";
				}
			}
		},
		markers: {
			size: 4,
			colors: ['#fff'],
			strokeColors: ['#6366f1'],
			strokeWidth: 2,
			hover: {
				size: 7
			}
		}
	};

	var OverviewViewchart = new ApexCharts(document.querySelector("#OverviewViewsChart"), viewsChartOptions);
	OverviewViewchart.render();

	// Coverage Radial Chart.
	var coverageOptions = {
		chart: {
			height: 200,
			type: 'radialBar',
			fontFamily: 'inherit'
		},
		plotOptions: {
			radialBar: {
				hollow: {
					size: '60%'
				},
				track: {
					background: '#f1f5f9',
					strokeWidth: '100%'
				},
				dataLabels: {
					name: {
						show: true,
						fontSize: '12px',
						fontWeight: 600,
						color: '#64748b',
						offsetY: 16
					},
					value: {
						show: true,
						fontSize: '28px',
						fontWeight: 800,
						color: '#0f172a',
						offsetY: -16,
						formatter: function(val) {
							return val + '%';
						}
					}
				}
			}
		},
		colors: ['#6366f1'],
		series: [<?php echo esc_js( $view_coverage ); ?>],
		labels: ['<?php esc_attr_e( 'Coverage', 'eazydocs-pro' ); ?>']
	};

	var coverageChart = new ApexCharts(document.querySelector("#coverage-radial-chart"), coverageOptions);
	coverageChart.render();

	function ViewsLastSeveday() {
		OverviewViewchart.updateOptions({
			xaxis: {
				categories: <?php echo wp_json_encode( $labels ); ?>,
				labels: { show: false }
			},
			series: [{
				name: 'Views',
				data: <?php echo wp_json_encode( $dataCount ); ?>
			}],
		});
		// Update date range text
		document.getElementById('views-chart-date-range').textContent = '<?php echo esc_js( gmdate( 'd M, Y', strtotime( '-6 days' ) ) . ' - ' . gmdate( 'd M, Y' ) ); ?>';
	}

	function ViewLastMonth() {
		OverviewViewchart.updateOptions({
			xaxis: {
				categories: <?php echo wp_json_encode( $monthly_labels ); ?>,
				labels: { show: false }
			},
			series: [{
				name: 'Views',
				data: <?php echo wp_json_encode( $monthlyViews ); ?>
			}],
		});
		// Update date range text
		document.getElementById('views-chart-date-range').textContent = '<?php echo esc_js( gmdate( 'd M, Y', strtotime( '-29 days' ) ) . ' - ' . gmdate( 'd M, Y' ) ); ?>';
	}

	function ViewDateRange() {
		jQuery('.date__range_view').daterangepicker().on('apply.daterangepicker', function (e, picker) {
			// Update active state.
			jQuery('.views-time-filters .filter-tab').removeClass('is-active');
			jQuery(this).addClass('is-active');

			var start_date = picker.startDate.format('Y-M-D');
			var end_date = picker.endDate.format('Y-M-D');
			var startDateDisplay = picker.startDate.format('DD MMM, YYYY');
			var endDateDisplay = picker.endDate.format('DD MMM, YYYY');

			var data = {
				'action': 'ezd_view_date_range_data',
				'start_date': start_date,
				'end_date': end_date
			};
			jQuery.post(ajaxurl, data, function (response) {
				OverviewViewchart.updateOptions({
					xaxis: {
						categories: response.data.labels,
						labels: { show: false }
					},
					series: [{
						name: 'Views',
						data: response.data.views
					}],
				});
				// Update date range text
				document.getElementById('views-chart-date-range').textContent = startDateDisplay + ' - ' + endDateDisplay;
			});
		});
	}

	ViewDateRange();
</script>