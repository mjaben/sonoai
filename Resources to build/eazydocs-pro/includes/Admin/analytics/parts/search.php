<?php
/**
 * Analytics Search Tab - Enhanced Version
 * Modern, redesigned search analytics with improved UI/UX and extended functionality
 *
 * @package EasyDocs\Admin\Analytics
 */

global $wpdb;

// Get search data for the last 7 days.
$search_keyword = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}eazydocs_search_log WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 7 DAY) AND NOW() ORDER BY created_at DESC" );

$labels              = [];
$total_search        = [];
$searchCount         = [];
$searchCountNotFound = [];

$m  = gmdate( 'm' );
$de = gmdate( 'd' );
$y  = gmdate( 'Y' );

for ( $i = 0; $i <= 6; $i++ ) {
	$labels[]              = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
	$total_search[]        = 0;
	$searchCount[]         = 0;
	$searchCountNotFound[] = 0;
}

// Get 7 data date wise.
foreach ( $search_keyword as $key => $item ) {
	$dates = gmdate( 'd M, Y', strtotime( $item->created_at ) );

	foreach ( $labels as $datekey => $weekdays ) {
		if ( $weekdays == gmdate( 'd M, Y', strtotime( $item->created_at ) ) ) {
			$total_search[ $datekey ]        = count( $search_keyword );
			$searchCount[ $datekey ]         = array_sum( array_column( $search_keyword, 'count' ) );
			$searchCountNotFound[ $datekey ] = array_sum( array_column( $search_keyword, 'not_found_count' ) );
		}
	}
}

// Get overall statistics using proper table joins.
// The count and not_found_count columns are in eazydocs_search_log, not in eazydocs_search_keyword.
$total_searches_all = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eazydocs_search_keyword" );

$total_found = $wpdb->get_var( 
	"SELECT SUM(l.count) FROM {$wpdb->prefix}eazydocs_search_log l" 
);

$total_not_found = $wpdb->get_var( 
	"SELECT SUM(l.not_found_count) FROM {$wpdb->prefix}eazydocs_search_log l" 
);

$total_found     = $total_found ? intval( $total_found ) : 0;
$total_not_found = $total_not_found ? intval( $total_not_found ) : 0;
$total_all       = $total_found + $total_not_found;

$success_rate = $total_all > 0 ? round( ( $total_found / $total_all ) * 100, 1 ) : 0;

// Get unique keywords count.
$unique_keywords = $wpdb->get_var( "SELECT COUNT(DISTINCT keyword) FROM {$wpdb->prefix}eazydocs_search_keyword" );

// Get popular keywords (top 20) with their search counts from the log table.
$popular_keywords = $wpdb->get_results( 
	"SELECT k.keyword, COUNT(l.id) AS search_count, COALESCE(SUM(l.count), 0) AS result_count 
	FROM {$wpdb->prefix}eazydocs_search_keyword k 
	LEFT JOIN {$wpdb->prefix}eazydocs_search_log l ON k.id = l.keyword_id 
	GROUP BY k.keyword 
	ORDER BY search_count DESC 
	LIMIT 20" 
);

// Get not found keywords.
$not_found_keywords = ezd_get_search_keywords();

// Get today's searches.
$today_searches = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eazydocs_search_log WHERE DATE(created_at) = CURDATE()" );
$nonce          = wp_create_nonce( 'ezd_analytics_nonce' );
?>

<div class="easydocs-tab" id="analytics-search">
	<!-- Page Header -->
	<div class="doc-ranks-header search-header">
		<div class="header-content">
			<h2 class="title"><?php esc_html_e( 'Search Analytics', 'eazydocs-pro' ); ?></h2>
			<p class="subtitle"><?php esc_html_e( 'Monitor search patterns and optimize your documentation for better discoverability.', 'eazydocs-pro' ); ?></p>
		</div>
		<div class="header-actions">
			<button class="ezd-btn-icon" id="export-search-data">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Export Data', 'eazydocs-pro' ); ?>
			</button>
			<button class="ezd-btn-icon" id="refresh-search-data">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Refresh', 'eazydocs-pro' ); ?>
			</button>
		</div>
	</div>

	<!-- Statistics Cards -->
	<div class="doc-ranks-stats search-stats">
		<div class="stat-card stat-card--primary">
			<div class="stat-icon">
				<span class="dashicons dashicons-search"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $total_searches_all ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Total Searches', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--success">
			<div class="stat-icon">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $success_rate ); ?>%</div>
				<div class="stat-label"><?php esc_html_e( 'Success Rate', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--info">
			<div class="stat-icon">
				<span class="dashicons dashicons-editor-spellcheck"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $unique_keywords ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Unique Keywords', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--warning">
			<div class="stat-icon">
				<span class="dashicons dashicons-calendar-alt"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $today_searches ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Today\'s Searches', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
	</div>

	<!-- Enhanced Chart Section -->
	<div class="search-chart-section">
		<div class="chart-header">
			<div class="chart-title">
				<span class="dashicons dashicons-chart-area"></span>
				<div class="title-wrapper">
					<h3><?php esc_html_e( 'Search Trends', 'eazydocs-pro' ); ?></h3>
					<span class="chart-date-range" id="search-chart-date-range">
						<?php 
						$start_date_search = gmdate( 'd M, Y', strtotime( '-6 days' ) );
						$end_date_search   = gmdate( 'd M, Y' );
						echo esc_html( $start_date_search . ' - ' . $end_date_search );
						?>
					</span>
				</div>
			</div>
			<div class="chart-controls">
				<!-- Chart Toggles -->
				<div class="chart-toggles">
					<label class="chart-toggle is-active" data-series="Total Search">
						<input type="checkbox" name="searchChart" value="Total Search" id="total_search" checked/>
						<span class="toggle-dot" style="background: #6366f1;"></span>
						<?php esc_html_e( 'Total Searches', 'eazydocs-pro' ); ?>
					</label>
					<label class="chart-toggle is-active" data-series="Result Found">
						<input type="checkbox" name="searchChart" value="Result Found" id="result_found" checked/>
						<span class="toggle-dot" style="background: #10b981;"></span>
						<?php esc_html_e( 'Results Found', 'eazydocs-pro' ); ?>
					</label>
					<label class="chart-toggle is-active" data-series="Result Not Found">
						<input type="checkbox" name="searchChart" value="Result Not Found" id="result_not_found" checked/>
						<span class="toggle-dot" style="background: #ef4444;"></span>
						<?php esc_html_e( 'No Results', 'eazydocs-pro' ); ?>
					</label>
				</div>
				<!-- Time Range Filters -->
				<div class="filter-tabs search-time-filters">
					<button class="filter-tab is-active" data-filter="week" onclick="SearchSevenDays()">
						<span class="dashicons dashicons-calendar"></span>
						<?php esc_html_e( 'This Week', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab" data-filter="month" onclick="SearchLastMonth()">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'Last 30 Days', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab date__range_from_search" data-filter="custom">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Custom', 'eazydocs-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
		<div class="chart-wrapper">
			<div id="Search_analytics"></div>
		</div>
	</div>

	<!-- Keywords Section -->
	<div class="search-keywords-grid">
		<!-- Popular Keywords Panel -->
		<div class="keywords-panel keywords-panel--popular">
			<div class="panel-header">
				<div class="panel-title">
					<span class="dashicons dashicons-star-filled"></span>
					<h3><?php esc_html_e( 'Popular Keywords', 'eazydocs-pro' ); ?></h3>
				</div>
				<div class="panel-actions">
					<div class="search-input-wrapper">
						<span class="dashicons dashicons-search"></span>
						<input type="text" id="search-popular-keywords" placeholder="<?php esc_attr_e( 'Filter keywords...', 'eazydocs-pro' ); ?>">
					</div>
				</div>
			</div>
			<div class="panel-content">
				<?php if ( count( $popular_keywords ) > 0 ) : ?>
					<ul class="keywords-list" id="popular-keywords-list">
						<?php foreach ( $popular_keywords as $index => $item ) : ?>
							<?php
							$rank_class = '';
							if ( 0 === $index ) {
								$rank_class = 'rank-gold';
							} elseif ( 1 === $index ) {
								$rank_class = 'rank-silver';
							} elseif ( 2 === $index ) {
								$rank_class = 'rank-bronze';
							}
							?>
							<li class="keyword-item <?php echo esc_attr( $rank_class ); ?>" data-keyword="<?php echo esc_attr( strtolower( $item->keyword ) ); ?>">
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
									<span class="keyword-text"><?php echo esc_html( $item->keyword ); ?></span>
									<span class="keyword-meta"><?php echo esc_html( $item->result_count ); ?> <?php esc_html_e( 'results shown', 'eazydocs-pro' ); ?></span>
								</div>
								<div class="keyword-stats">
									<span class="search-count">
										<span class="dashicons dashicons-search"></span>
										<?php echo esc_html( $item->search_count ); ?>
									</span>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<div class="empty-state">
						<span class="dashicons dashicons-search"></span>
						<p><?php esc_html_e( 'No search data available yet. Keywords will appear here once users start searching.', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Failed Searches Panel (Using Shared Component) -->
		<?php
		// Prepare data for the shared component.
		// Get failed searches data with keyword_id.
		$failed_searches = ezd_get_search_keywords();

		// Transform data to match shared component expectations.
		foreach ( $failed_searches as $search ) {
			if ( isset( $search->not_found_count ) && ! isset( $search->total_failed ) ) {
				$search->total_failed = $search->not_found_count;
			}
		}

		$total_failed = count( $failed_searches );
		$context      = 'analytics';
		$limit        = 0; // Show all in analytics.

		// Include the shared component.
		$component_path = EAZYDOCS_PATH . '/includes/Admin/template/components/failed-searches-list.php';
		if ( file_exists( $component_path ) ) {
			include $component_path;
		}
		?>
	</div>

	<!-- Search Insights -->
	<div class="search-insights-section">
		<div class="insights-header">
			<span class="dashicons dashicons-lightbulb"></span>
			<h3><?php esc_html_e( 'Quick Insights', 'eazydocs-pro' ); ?></h3>
		</div>
		<div class="insights-grid">
			<div class="insight-card">
				<div class="insight-icon insight-icon--success">
					<span class="dashicons dashicons-search"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Search Success', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						if ( $success_rate >= 80 ) {
							esc_html_e( 'Excellent! Your documentation covers most user queries.', 'eazydocs-pro' );
						} elseif ( $success_rate >= 60 ) {
							esc_html_e( 'Good coverage, but there\'s room for improvement.', 'eazydocs-pro' );
						} else {
							esc_html_e( 'Consider adding more content based on failed searches.', 'eazydocs-pro' );
						}
						?>
					</p>
				</div>
			</div>
			<div class="insight-card">
				<div class="insight-icon insight-icon--info">
					<span class="dashicons dashicons-chart-line"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Search Volume', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						printf(
							/* translators: %s: number of searches today */
							esc_html__( 'You\'ve received %s searches today. Track trends to understand user needs.', 'eazydocs-pro' ),
							'<strong>' . esc_html( number_format( $today_searches ) ) . '</strong>'
						);
						?>
					</p>
				</div>
			</div>
			<div class="insight-card">
				<div class="insight-icon insight-icon--warning">
					<span class="dashicons dashicons-warning"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Content Gaps', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						$failed_count = count( $not_found_keywords );
						if ( $failed_count > 0 ) {
							printf(
								/* translators: %s: number of keywords */
								esc_html__( '%s keywords returned no results. Create docs to fill these gaps.', 'eazydocs-pro' ),
								'<strong>' . esc_html( $failed_count ) . '</strong>'
							);
						} else {
							esc_html_e( 'No content gaps detected. Great job!', 'eazydocs-pro' );
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
		// Handle time filter buttons active state
		$('.search-time-filters .filter-tab').on('click', function() {
			$('.search-time-filters .filter-tab').removeClass('is-active');
			$(this).addClass('is-active');
		});

		// Handle chart toggle checkboxes
		$('.chart-toggle input[type="checkbox"]').on('change', function() {
			var $toggle = $(this).closest('.chart-toggle');
			if (this.checked) {
				$toggle.addClass('is-active');
				Search_Analytics.showSeries(this.value);
			} else {
				$toggle.removeClass('is-active');
				Search_Analytics.hideSeries(this.value);
			}
		});

		// Filter popular keywords
		$('#search-popular-keywords').on('keyup', function() {
			var filter = $(this).val().toLowerCase();
			$('#popular-keywords-list .keyword-item').each(function() {
				var keyword = $(this).data('keyword');
				if (keyword.indexOf(filter) > -1) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		// Filter failed keywords
		$('#search-failed-keywords').on('keyup', function() {
			var filter = $(this).val().toLowerCase();
			$('#failed-keywords-list .keyword-item').each(function() {
				var keyword = $(this).data('keyword');
				if (keyword.indexOf(filter) > -1) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		// Export data functionality
		$('#export-search-data').on('click', function() {
			var csvContent = "data:text/csv;charset=utf-8,";
			csvContent += "Keyword,Search Count,Result Count\n";

			$('#popular-keywords-list .keyword-item').each(function() {
				var keyword = $(this).find('.keyword-text').text();
				var searchCount = $(this).find('.search-count').text().trim();
				csvContent += '"' + keyword + '",' + searchCount + ',0\n';
			});

			var encodedUri = encodeURI(csvContent);
			var link = document.createElement("a");
			link.setAttribute("href", encodedUri);
			link.setAttribute("download", "search_analytics_" + new Date().toISOString().split('T')[0] + ".csv");
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
		});

		// Refresh data
		$('#refresh-search-data').on('click', function() {
			location.reload();
		});
	});

	// Chart Configuration
	var searchChartOptions = {
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
		colors: ['#6366f1', '#10b981', '#ef4444'],
		dataLabels: {
			enabled: false
		},
		series: [
			{
				name: "Total Search",
				data: <?php echo json_encode( $total_search ); ?>
			},
			{
				name: "Result Found",
				data: <?php echo json_encode( $searchCount ); ?>
			},
			{
				name: "Result Not Found",
				data: <?php echo json_encode( $searchCountNotFound ); ?>
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
			categories: <?php echo json_encode( $labels ); ?>,
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
					return val + " searches";
				}
			}
		},
		markers: {
			size: 4,
			colors: ['#fff'],
			strokeColors: ['#6366f1', '#10b981', '#ef4444'],
			strokeWidth: 2,
			hover: {
				size: 7
			}
		}
	};

	var Search_Analytics = new ApexCharts(document.querySelector("#Search_analytics"), searchChartOptions);
	Search_Analytics.render();

	function SearchSevenDays() {
		Search_Analytics.updateOptions({
			xaxis: {
				categories: <?php echo json_encode( $labels ); ?>,
				labels: { show: false }
			},
			series: [
				{
					name: "Total Search",
					data: <?php echo json_encode( $total_search ); ?>
				},
				{
					name: "Result Found",
					data: <?php echo json_encode( $searchCount ); ?>
				},
				{
					name: "Result Not Found",
					data: <?php echo json_encode( $searchCountNotFound ); ?>
				}
			],
		});
		// Update date range text
		document.getElementById('search-chart-date-range').textContent = '<?php echo esc_js( gmdate( 'd M, Y', strtotime( '-6 days' ) ) . ' - ' . gmdate( 'd M, Y' ) ); ?>';
	}

	function SearchLastMonth() {
		<?php
		global $wpdb;

		$search_keyword = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}eazydocs_search_log WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 29 DAY) AND NOW() ORDER BY created_at DESC" );

		$labels              = [];
		$total_search        = [];
		$searchCount         = [];
		$searchCountNotFound = [];

		$m  = gmdate( 'm' );
		$de = gmdate( 'd' );
		$y  = gmdate( 'Y' );

		for ( $i = 0; $i <= 29; $i++ ) {
			$labels[]              = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
			$total_search[]        = 0;
			$searchCount[]         = 0;
			$searchCountNotFound[] = 0;
		}

		foreach ( $search_keyword as $key => $item ) {
			foreach ( $labels as $datekey => $weekdays ) {
				if ( $weekdays == gmdate( 'd M, Y', strtotime( $item->created_at ) ) ) {
					$total_search[ $datekey ]        = count( $search_keyword );
					$searchCount[ $datekey ]         = array_sum( array_column( $search_keyword, 'count' ) );
					$searchCountNotFound[ $datekey ] = array_sum( array_column( $search_keyword, 'not_found_count' ) );
				}
			}
		}
		?>
		Search_Analytics.updateOptions({
			xaxis: {
				categories: <?php echo json_encode( $labels ); ?>,
				labels: { show: false }
			},
			series: [
				{
					name: "Total Search",
					data: <?php echo json_encode( $total_search ); ?>
				},
				{
					name: "Result Found",
					data: <?php echo json_encode( $searchCount ); ?>
				},
				{
					name: "Result Not Found",
					data: <?php echo json_encode( $searchCountNotFound ); ?>
				}
			],
		});
		// Update date range text
		document.getElementById('search-chart-date-range').textContent = '<?php echo esc_js( gmdate( 'd M, Y', strtotime( '-29 days' ) ) . ' - ' . gmdate( 'd M, Y' ) ); ?>';
	}

	function date__range_from_search() {
		jQuery('.date__range_from_search').daterangepicker().on('apply.daterangepicker', function (e, picker) {
			// Update active state
			jQuery('.search-time-filters .filter-tab').removeClass('is-active');
			jQuery(this).addClass('is-active');

			var startDate = picker.startDate.format('Y-M-D');
			var endDate = picker.endDate.format('Y-M-D');
			var startDateDisplay = picker.startDate.format('DD MMM, YYYY');
			var endDateDisplay = picker.endDate.format('DD MMM, YYYY');

			jQuery.ajax({
				type: "POST",
				dataType: "json",
				url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
				data: {
					action: "ezd_filter_date_from_search",
					startDate: startDate,
					endDate: endDate,
					nonce: '<?php echo esc_js( $nonce ); ?>'
				},
				success: function (response) {
					Search_Analytics.updateOptions({
						xaxis: {
							categories: response.data.labels,
							labels: { show: false }
						},
						series: [
							{
								name: "Total Search",
								data: response.data.totalSearch
							},
							{
								name: 'Result Found',
								data: response.data.searchCount
							},
							{
								name: 'Result Not Found',
								data: response.data.searchCountNotFound
							}],
					});
					// Update date range text
					document.getElementById('search-chart-date-range').textContent = startDateDisplay + ' - ' + endDateDisplay;
				}
			});
		})
	}

	date__range_from_search();
</script>