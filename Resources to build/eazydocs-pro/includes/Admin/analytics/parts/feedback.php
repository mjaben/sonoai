<?php
/**
 * Analytics Feedback Tab - Enhanced Version
 * Modern, redesigned feedback analytics with improved UI/UX and extended functionality
 *
 * @package EasyDocs\Admin\Analytics
 */

global $wpdb;

$date_range = strtotime( '-7 day' );
$today_date = gmdate( 'Y-m-d' );

$posts = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}postmeta WHERE post_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'docs') AND meta_key IN ('positive_time', 'negative_time') AND meta_value BETWEEN $today_date AND UNIX_TIMESTAMP() ORDER BY meta_value DESC" );

// Get total positive and negative feedback.
$totalPost = $wpdb->get_results( "SELECT COUNT(*) as total, meta_key FROM {$wpdb->prefix}postmeta WHERE post_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'docs') AND meta_key IN ('positive_time', 'negative_time') GROUP BY meta_key" );
$totalPost = array_column( $totalPost, 'total', 'meta_key' );

$labels         = [];
$Liked          = [];
$Disliked       = [];
$total_liked    = $totalPost['positive_time'] ?? 0;
$total_disliked = $totalPost['negative_time'] ?? 0;
$total_votes    = $total_liked + $total_disliked;

// Calculate percentages.
$positive_percentage = $total_votes > 0 ? round( ( $total_liked / $total_votes ) * 100, 1 ) : 0;
$negative_percentage = $total_votes > 0 ? round( ( $total_disliked / $total_votes ) * 100, 1 ) : 0;

$m  = gmdate( 'm' );
$de = gmdate( 'd' );
$y  = gmdate( 'Y' );

for ( $i = 0; $i <= 6; $i++ ) {
	$labels[]   = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
	$Liked[]    = 0;
	$Disliked[] = 0;
}

// Get 7 data date wise.
foreach ( $posts as $key => $item ) {
	$dates = gmdate( 'd M, Y', strtotime( $item->meta_value ) );

	foreach ( $labels as $datekey => $weekdays ) {
		if ( $weekdays == gmdate( 'd M, Y', strtotime( $item->meta_value ) ) ) {
			if ( $item->meta_key == 'positive_time' ) {
				$Liked[ $datekey ] = $Liked[ $datekey ] + 1;
			} else {
				$Disliked[ $datekey ] = $Disliked[ $datekey ] + 1;
			}
		}
	}
}

// Get today's feedback.
$today_positive = $wpdb->get_var( 
	"SELECT COUNT(*) FROM {$wpdb->prefix}postmeta 
	WHERE post_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'docs') 
	AND meta_key = 'positive_time' 
	AND DATE(meta_value) = CURDATE()" 
);
$today_negative = $wpdb->get_var( 
	"SELECT COUNT(*) FROM {$wpdb->prefix}postmeta 
	WHERE post_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'docs') 
	AND meta_key = 'negative_time' 
	AND DATE(meta_value) = CURDATE()" 
);
$today_total = intval( $today_positive ) + intval( $today_negative );

// Get most helpful docs (top 10).
$most_helpful_docs = $wpdb->get_results(
	"SELECT p.ID, p.post_title, 
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'positive' LIMIT 1), 0) as positive,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'negative' LIMIT 1), 0) as negative,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'post_views_count' LIMIT 1), 0) as views
	FROM {$wpdb->prefix}posts p 
	WHERE p.post_type = 'docs' AND p.post_status = 'publish'
	ORDER BY (CAST(COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'positive' LIMIT 1), 0) AS SIGNED)) DESC
	LIMIT 10"
);

// Get least helpful docs (most negative feedback).
$least_helpful_docs = $wpdb->get_results(
	"SELECT p.ID, p.post_title, 
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'positive' LIMIT 1), 0) as positive,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'negative' LIMIT 1), 0) as negative,
		COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'post_views_count' LIMIT 1), 0) as views
	FROM {$wpdb->prefix}posts p 
	WHERE p.post_type = 'docs' AND p.post_status = 'publish'
	ORDER BY (CAST(COALESCE((SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p.ID AND meta_key = 'negative' LIMIT 1), 0) AS SIGNED)) DESC
	LIMIT 10"
);

// Get recent feedback activity.
$recent_feedback = $wpdb->get_results(
	"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title
	FROM {$wpdb->prefix}postmeta pm
	INNER JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID
	WHERE p.post_type = 'docs' 
	AND pm.meta_key IN ('positive_time', 'negative_time')
	ORDER BY pm.meta_value DESC
	LIMIT 15"
);
?>

<div class="easydocs-tab" id="analytics-feedback">
	<!-- Page Header -->
	<div class="doc-ranks-header feedback-header">
		<div class="header-content">
			<h2 class="title"><?php esc_html_e( 'Feedback Analytics', 'eazydocs-pro' ); ?></h2>
			<p class="subtitle"><?php esc_html_e( 'Track user satisfaction and identify documentation that needs improvement.', 'eazydocs-pro' ); ?></p>
		</div>
		<div class="header-actions">
			<button class="ezd-btn-icon" id="export-feedback-data">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Export Data', 'eazydocs-pro' ); ?>
			</button>
			<button class="ezd-btn-icon" id="refresh-feedback-data">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Refresh', 'eazydocs-pro' ); ?>
			</button>
		</div>
	</div>

	<!-- Statistics Cards -->
	<div class="doc-ranks-stats feedback-stats">
		<div class="stat-card stat-card--success">
			<div class="stat-icon">
				<span class="dashicons dashicons-thumbs-up"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $total_liked ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Helpful Votes', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--danger">
			<div class="stat-icon">
				<span class="dashicons dashicons-thumbs-down"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $total_disliked ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Unhelpful Votes', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--primary">
			<div class="stat-icon">
				<span class="dashicons dashicons-chart-pie"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $positive_percentage ); ?>%</div>
				<div class="stat-label"><?php esc_html_e( 'Satisfaction Rate', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
		<div class="stat-card stat-card--info">
			<div class="stat-icon">
				<span class="dashicons dashicons-calendar-alt"></span>
			</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( number_format( $today_total ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Today\'s Feedback', 'eazydocs-pro' ); ?></div>
			</div>
		</div>
	</div>

	<!-- Enhanced Chart Section -->
	<div class="feedback-chart-section">
		<div class="chart-header">
			<div class="chart-title">
				<span class="dashicons dashicons-chart-area"></span>
				<div class="title-wrapper">
					<h3><?php esc_html_e( 'Feedback Trends', 'eazydocs-pro' ); ?></h3>
					<span class="chart-date-range" id="feedback-chart-date-range">
						<?php 
						$start_date_fb = gmdate( 'd M, Y', strtotime( '-6 days' ) );
						$end_date_fb   = gmdate( 'd M, Y' );
						echo esc_html( $start_date_fb . ' - ' . $end_date_fb );
						?>
					</span>
				</div>
			</div>
			<div class="chart-controls">
				<!-- Chart Toggles -->
				<div class="chart-toggles">
					<label class="chart-toggle is-active" data-series="Positive">
						<input type="checkbox" name="filterChart" value="Positive" id="positive_feedback" checked/>
						<span class="toggle-dot" style="background: #10b981;"></span>
						<?php esc_html_e( 'Positive', 'eazydocs-pro' ); ?>
					</label>
					<label class="chart-toggle is-active" data-series="Negative">
						<input type="checkbox" name="filterChart" value="Negative" id="negative_feedback" checked/>
						<span class="toggle-dot" style="background: #ef4444;"></span>
						<?php esc_html_e( 'Negative', 'eazydocs-pro' ); ?>
					</label>
				</div>
				<!-- Time Range Filters -->
				<div class="filter-tabs feedback-time-filters">
					<button class="filter-tab is-active" data-filter="week" onclick="AllSewvenDaysFeedback()">
						<span class="dashicons dashicons-calendar"></span>
						<?php esc_html_e( 'This Week', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab" data-filter="month" onclick="FeedbackLastMonth()">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'Last 30 Days', 'eazydocs-pro' ); ?>
					</button>
					<button class="filter-tab date__range" data-filter="custom">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Custom', 'eazydocs-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
		<div class="chart-wrapper">
			<div id="Positive_negative_feedback"></div>
		</div>
	</div>

	<!-- Feedback Breakdown Grid -->
	<div class="feedback-breakdown-grid">
		<!-- Satisfaction Breakdown -->
		<div class="feedback-breakdown-card">
			<div class="breakdown-header">
				<span class="dashicons dashicons-chart-pie"></span>
				<h3><?php esc_html_e( 'Satisfaction Breakdown', 'eazydocs-pro' ); ?></h3>
			</div>
			<div class="breakdown-content">
				<div class="satisfaction-chart">
					<div id="satisfaction-radial-chart"></div>
				</div>
				<div class="satisfaction-details">
					<div class="satisfaction-item">
						<div class="satisfaction-indicator satisfaction-indicator--positive"></div>
						<div class="satisfaction-info">
							<span class="satisfaction-label"><?php esc_html_e( 'Helpful', 'eazydocs-pro' ); ?></span>
							<span class="satisfaction-value"><?php echo esc_html( number_format( $total_liked ) ); ?> (<?php echo esc_html( $positive_percentage ); ?>%)</span>
						</div>
					</div>
					<div class="satisfaction-item">
						<div class="satisfaction-indicator satisfaction-indicator--negative"></div>
						<div class="satisfaction-info">
							<span class="satisfaction-label"><?php esc_html_e( 'Unhelpful', 'eazydocs-pro' ); ?></span>
							<span class="satisfaction-value"><?php echo esc_html( number_format( $total_disliked ) ); ?> (<?php echo esc_html( $negative_percentage ); ?>%)</span>
						</div>
					</div>
					<div class="satisfaction-total">
						<span class="total-label"><?php esc_html_e( 'Total Votes', 'eazydocs-pro' ); ?></span>
						<span class="total-value"><?php echo esc_html( number_format( $total_votes ) ); ?></span>
					</div>
				</div>
			</div>
		</div>

		<!-- Recent Feedback Activity -->
		<div class="feedback-activity-card">
			<div class="activity-header">
				<span class="dashicons dashicons-clock"></span>
				<h3><?php esc_html_e( 'Recent Feedback', 'eazydocs-pro' ); ?></h3>
			</div>
			<div class="activity-list">
				<?php if ( ! empty( $recent_feedback ) ) : ?>
					<?php foreach ( $recent_feedback as $feedback ) : ?>
						<?php
						$is_positive  = $feedback->meta_key === 'positive_time';
						$icon_class   = $is_positive ? 'dashicons-thumbs-up' : 'dashicons-thumbs-down';
						$type_class   = $is_positive ? 'positive' : 'negative';
						$time_ago     = human_time_diff( strtotime( $feedback->meta_value ), current_time( 'timestamp' ) );
						$doc_title    = ! empty( $feedback->post_title ) ? $feedback->post_title : __( 'Untitled', 'eazydocs-pro' );
						?>
						<div class="activity-item activity-item--<?php echo esc_attr( $type_class ); ?>">
							<div class="activity-icon">
								<span class="dashicons <?php echo esc_attr( $icon_class ); ?>"></span>
							</div>
							<div class="activity-content">
								<p class="activity-text">
									<a href="<?php echo esc_url( get_permalink( $feedback->post_id ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $doc_title, 8 ) ); ?></a>
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
						<p><?php esc_html_e( 'No recent feedback activity.', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Top Docs Grid -->
	<div class="feedback-docs-grid">
		<!-- Most Helpful Documents -->
		<div class="keywords-panel keywords-panel--popular">
			<div class="panel-header">
				<div class="panel-title">
					<span class="dashicons dashicons-thumbs-up"></span>
					<h3><?php esc_html_e( 'Most Helpful Docs', 'eazydocs-pro' ); ?></h3>
				</div>
				<div class="panel-actions">
					<div class="search-input-wrapper">
						<span class="dashicons dashicons-search"></span>
						<input type="text" id="search-helpful-docs" placeholder="<?php esc_attr_e( 'Filter docs...', 'eazydocs-pro' ); ?>">
					</div>
				</div>
			</div>
			<div class="panel-content">
				<?php if ( ! empty( $most_helpful_docs ) ) : ?>
					<ul class="keywords-list doc-feedback-list" id="helpful-docs-list">
						<?php foreach ( $most_helpful_docs as $index => $doc ) : ?>
							<?php
							$positive  = intval( $doc->positive );
							$negative  = intval( $doc->negative );
							$total     = $positive + $negative;
							$rate      = $total > 0 ? round( ( $positive / $total ) * 100 ) : 0;
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
										<a href="<?php echo esc_url( get_permalink( $doc->ID ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $doc->post_title, 8 ) ); ?></a>
									</span>
									<span class="keyword-meta"><?php echo esc_html( $rate ); ?>% <?php esc_html_e( 'satisfaction', 'eazydocs-pro' ); ?></span>
								</div>
								<div class="keyword-stats">
									<span class="stat-badge stat-badge--success">
										<span class="dashicons dashicons-thumbs-up"></span>
										<?php echo esc_html( $positive ); ?>
									</span>
									<span class="stat-badge stat-badge--danger">
										<span class="dashicons dashicons-thumbs-down"></span>
										<?php echo esc_html( $negative ); ?>
									</span>
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
						<span class="dashicons dashicons-thumbs-up"></span>
						<p><?php esc_html_e( 'No feedback data available yet.', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Needs Improvement Documents -->
		<div class="keywords-panel keywords-panel--failed">
			<div class="panel-header panel-header--warning">
				<div class="panel-title">
					<span class="dashicons dashicons-warning"></span>
					<h3><?php esc_html_e( 'Needs Improvement', 'eazydocs-pro' ); ?></h3>
				</div>
				<div class="panel-actions">
					<div class="search-input-wrapper">
						<span class="dashicons dashicons-search"></span>
						<input type="text" id="search-improvement-docs" placeholder="<?php esc_attr_e( 'Filter docs...', 'eazydocs-pro' ); ?>">
					</div>
				</div>
			</div>
			<div class="panel-content">
				<?php 
				// Filter to only show docs with negative feedback.
				$needs_improvement = array_filter( 
					$least_helpful_docs, 
					function( $doc ) {
						return intval( $doc->negative ) > 0;
					}
				);
				?>
				<?php if ( ! empty( $needs_improvement ) ) : ?>
					<div class="failed-searches-info">
						<span class="dashicons dashicons-lightbulb"></span>
						<p><?php esc_html_e( 'These documents have received negative feedback. Consider reviewing and improving their content.', 'eazydocs-pro' ); ?></p>
					</div>
					<ul class="keywords-list keywords-list--failed doc-feedback-list" id="improvement-docs-list">
						<?php foreach ( $needs_improvement as $index => $doc ) : ?>
							<?php
							$positive = intval( $doc->positive );
							$negative = intval( $doc->negative );
							$total    = $positive + $negative;
							$rate     = $total > 0 ? round( ( $negative / $total ) * 100 ) : 0;
							?>
							<li class="keyword-item keyword-item--failed" data-title="<?php echo esc_attr( strtolower( $doc->post_title ) ); ?>">
								<div class="keyword-rank">
									<span class="failed-icon">
										<span class="dashicons dashicons-warning"></span>
									</span>
								</div>
								<div class="keyword-info">
									<span class="keyword-text">
										<a href="<?php echo esc_url( get_permalink( $doc->ID ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $doc->post_title, 8 ) ); ?></a>
									</span>
									<span class="keyword-meta keyword-meta--danger"><?php echo esc_html( $rate ); ?>% <?php esc_html_e( 'negative', 'eazydocs-pro' ); ?></span>
								</div>
								<div class="keyword-stats">
									<span class="stat-badge stat-badge--danger">
										<span class="dashicons dashicons-thumbs-down"></span>
										<?php echo esc_html( $negative ); ?>
									</span>
								</div>
								<div class="keyword-actions">
									<a href="<?php echo esc_url( get_edit_post_link( $doc->ID ) ); ?>" class="action-btn" title="<?php esc_attr_e( 'Edit Doc', 'eazydocs-pro' ); ?>">
										<span class="dashicons dashicons-edit"></span>
									</a>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<div class="empty-state empty-state--success">
						<span class="dashicons dashicons-yes-alt"></span>
						<p><?php esc_html_e( 'Great news! No documents have significant negative feedback.', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Feedback Insights -->
	<div class="search-insights-section feedback-insights-section">
		<div class="insights-header">
			<span class="dashicons dashicons-lightbulb"></span>
			<h3><?php esc_html_e( 'Quick Insights', 'eazydocs-pro' ); ?></h3>
		</div>
		<div class="insights-grid">
			<div class="insight-card">
				<div class="insight-icon insight-icon--success">
					<span class="dashicons dashicons-smiley"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'User Satisfaction', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						if ( $positive_percentage >= 80 ) {
							esc_html_e( 'Excellent! Users find your documentation very helpful.', 'eazydocs-pro' );
						} elseif ( $positive_percentage >= 60 ) {
							esc_html_e( 'Good satisfaction rate. A few docs may need attention.', 'eazydocs-pro' );
						} elseif ( $positive_percentage >= 40 ) {
							esc_html_e( 'Moderate satisfaction. Review docs with negative feedback.', 'eazydocs-pro' );
						} else {
							esc_html_e( 'Consider reviewing your documentation based on user feedback.', 'eazydocs-pro' );
						}
						?>
					</p>
				</div>
			</div>
			<div class="insight-card">
				<div class="insight-icon insight-icon--info">
					<span class="dashicons dashicons-chart-bar"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Engagement Rate', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						printf(
							/* translators: %s: number of votes today */
							esc_html__( 'You\'ve received %s feedback votes today. Track trends to understand user engagement.', 'eazydocs-pro' ),
							'<strong>' . esc_html( number_format( $today_total ) ) . '</strong>'
						);
						?>
					</p>
				</div>
			</div>
			<div class="insight-card">
				<div class="insight-icon insight-icon--warning">
					<span class="dashicons dashicons-flag"></span>
				</div>
				<div class="insight-content">
					<h4><?php esc_html_e( 'Action Items', 'eazydocs-pro' ); ?></h4>
					<p>
						<?php
						$needs_attention = count( array_filter( $least_helpful_docs, function( $d ) { return intval( $d->negative ) > 2; } ) );
						if ( $needs_attention > 0 ) {
							printf(
								/* translators: %s: number of docs */
								esc_html__( '%s documents have multiple negative votes. Review them for improvement.', 'eazydocs-pro' ),
								'<strong>' . esc_html( $needs_attention ) . '</strong>'
							);
						} else {
							esc_html_e( 'No urgent action items. Keep up the good work!', 'eazydocs-pro' );
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
		$('.feedback-time-filters .filter-tab').on('click', function() {
			$('.feedback-time-filters .filter-tab').removeClass('is-active');
			$(this).addClass('is-active');
		});

		// Handle chart toggle checkboxes.
		$('.feedback-chart-section .chart-toggle input[type="checkbox"]').on('change', function() {
			var $toggle = $(this).closest('.chart-toggle');
			if (this.checked) {
				$toggle.addClass('is-active');
				P_N_ChartFeedback.showSeries(this.value);
			} else {
				$toggle.removeClass('is-active');
				P_N_ChartFeedback.hideSeries(this.value);
			}
		});

		// Filter helpful docs.
		$('#search-helpful-docs').on('keyup', function() {
			var filter = $(this).val().toLowerCase();
			$('#helpful-docs-list .keyword-item').each(function() {
				var title = $(this).data('title');
				if (title.indexOf(filter) > -1) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		// Filter improvement docs.
		$('#search-improvement-docs').on('keyup', function() {
			var filter = $(this).val().toLowerCase();
			$('#improvement-docs-list .keyword-item').each(function() {
				var title = $(this).data('title');
				if (title.indexOf(filter) > -1) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		// Export data functionality.
		$('#export-feedback-data').on('click', function() {
			var csvContent = "data:text/csv;charset=utf-8,";
			csvContent += "Document,Positive Votes,Negative Votes,Satisfaction Rate\n";

			$('#helpful-docs-list .keyword-item').each(function() {
				var title = $(this).find('.keyword-text a').text();
				var positive = $(this).find('.stat-badge--success').text().trim();
				var negative = $(this).find('.stat-badge--danger').text().trim();
				var rate = $(this).find('.keyword-meta').text().trim();
				csvContent += '"' + title + '",' + positive + ',' + negative + ',"' + rate + '"\n';
			});

			var encodedUri = encodeURI(csvContent);
			var link = document.createElement("a");
			link.setAttribute("href", encodedUri);
			link.setAttribute("download", "feedback_analytics_" + new Date().toISOString().split('T')[0] + ".csv");
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
		});

		// Refresh data.
		$('#refresh-feedback-data').on('click', function() {
			location.reload();
		});
	});

	// Area Chart Configuration.
	var feedbackChartOptions = {
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
		colors: ['#10b981', '#ef4444'],
		dataLabels: {
			enabled: false
		},
		series: [
			{
				name: "Positive",
				data: <?php echo wp_json_encode( $Liked ); ?>
			},
			{
				name: "Negative",
				data: <?php echo wp_json_encode( $Disliked ); ?>
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
					return val + " votes";
				}
			}
		},
		markers: {
			size: 4,
			colors: ['#fff'],
			strokeColors: ['#10b981', '#ef4444'],
			strokeWidth: 2,
			hover: {
				size: 7
			}
		}
	};

	var P_N_ChartFeedback = new ApexCharts(document.querySelector("#Positive_negative_feedback"), feedbackChartOptions);
	P_N_ChartFeedback.render();

	// Satisfaction Radial Chart.
	var satisfactionOptions = {
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
		colors: ['#10b981'],
		series: [<?php echo esc_js( $positive_percentage ); ?>],
		labels: ['<?php esc_attr_e( 'Satisfaction', 'eazydocs-pro' ); ?>']
	};

	var satisfactionChart = new ApexCharts(document.querySelector("#satisfaction-radial-chart"), satisfactionOptions);
	satisfactionChart.render();

	function AllSewvenDaysFeedback() {
		P_N_ChartFeedback.updateOptions({
			xaxis: {
				categories: <?php echo wp_json_encode( $labels ); ?>,
				labels: { show: false }
			},
			series: [
				{
					name: "Positive",
					data: <?php echo wp_json_encode( $Liked ); ?>
				},
				{
					name: "Negative",
					data: <?php echo wp_json_encode( $Disliked ); ?>
				}
			],
		});
		// Update date range text
		document.getElementById('feedback-chart-date-range').textContent = '<?php echo esc_js( gmdate( 'd M, Y', strtotime( '-6 days' ) ) . ' - ' . gmdate( 'd M, Y' ) ); ?>';
	}

	function FeedbackLastMonth() {
		<?php
		global $wpdb;

		$date_range = strtotime( '-29 day' );
		$today_date = gmdate( 'Y-m-d' );
		$posts      = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}postmeta WHERE post_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'docs') AND meta_key IN ('positive_time', 'negative_time') AND meta_value BETWEEN $today_date AND UNIX_TIMESTAMP() ORDER BY meta_value DESC" );

		$labels   = [];
		$Liked    = [];
		$Disliked = [];

		$m  = gmdate( 'm' );
		$de = gmdate( 'd' );
		$y  = gmdate( 'Y' );

		for ( $i = 0; $i <= 29; $i++ ) {
			$labels[]   = gmdate( 'd M, Y', mktime( 0, 0, 0, $m, ( $de - $i ), $y ) );
			$Liked[]    = 0;
			$Disliked[] = 0;
		}

		foreach ( $posts as $key => $item ) {
			$dates = gmdate( 'd M, Y', strtotime( $item->meta_value ) );

			foreach ( $labels as $datekey => $weekdays ) {
				if ( $weekdays == gmdate( 'd M, Y', strtotime( $item->meta_value ) ) ) {
					if ( $item->meta_key == 'positive_time' ) {
						$Liked[ $datekey ] = $Liked[ $datekey ] + 1;
					} else {
						$Disliked[ $datekey ] = $Disliked[ $datekey ] + 1;
					}
				}
			}
		}
		?>
		P_N_ChartFeedback.updateOptions({
			xaxis: {
				categories: <?php echo wp_json_encode( $labels ); ?>,
				labels: { show: false }
			},
			series: [
				{
					name: "Positive",
					data: <?php echo wp_json_encode( $Liked ); ?>
				},
				{
					name: "Negative",
					data: <?php echo wp_json_encode( $Disliked ); ?>
				}
			],
		});
		// Update date range text
		document.getElementById('feedback-chart-date-range').textContent = '<?php echo esc_js( gmdate( 'd M, Y', strtotime( '-29 days' ) ) . ' - ' . gmdate( 'd M, Y' ) ); ?>';
	}

	function FeedbackDateRange() {
		jQuery('.date__range').daterangepicker().on('apply.daterangepicker', function (e, picker) {
			// Update active state.
			jQuery('.feedback-time-filters .filter-tab').removeClass('is-active');
			jQuery(this).addClass('is-active');

			var startDate = picker.startDate.format('Y-M-D');
			var endDate = picker.endDate.format('Y-M-D');
			var startDateDisplay = picker.startDate.format('DD MMM, YYYY');
			var endDateDisplay = picker.endDate.format('DD MMM, YYYY');

			jQuery.ajax({
				type: "POST",
				dataType: "json",
				url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
				data: {action: "ezd_filter_date_from_feedback", startDate: startDate, endDate: endDate},
				success: function (response) {
					P_N_ChartFeedback.updateOptions({
						xaxis: {
							categories: response.data.labels,
							labels: { show: false }
						},
						series: [
							{
								name: 'Positive',
								data: response.data.liked
							},
							{
								name: 'Negative',
								data: response.data.disliked
							}],
					});
					// Update date range text
					document.getElementById('feedback-chart-date-range').textContent = startDateDisplay + ' - ' + endDateDisplay;
				}
			});
		})
	}

	FeedbackDateRange();
</script>