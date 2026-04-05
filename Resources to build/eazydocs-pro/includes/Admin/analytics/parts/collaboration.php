<?php
/**
 * Analytics Collaboration Tab - Enhanced Version
 *
 * @package EasyDocs\Admin\Analytics
 */

// Collect User Stats.
$users_data = [];
$docs       = get_posts(
	[
		'post_type'      => 'docs',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	]
);

// Summary statistics.
$total_docs        = count( $docs );
$total_contributors = 0;
$total_contributions = 0;
$recent_activity   = [];

foreach ( $docs as $doc ) {
	$post_id   = $doc->ID;
	$author_id = $doc->post_author;

	// Contributors stored as comma-separated string.
	$contributors_raw = get_post_meta( $post_id, 'ezd_doc_contributors', true );
	$contributors     = [];

	if ( is_string( $contributors_raw ) && ! empty( $contributors_raw ) ) {
		$contributors = array_map( 'trim', explode( ',', $contributors_raw ) );
	}

	// Combine author + contributors.
	$all_contributors = array_unique( array_merge( [ $author_id ], $contributors ) );

	foreach ( $all_contributors as $user_id ) {
		if ( empty( $user_id ) ) {
			continue;
		}
		if ( ! isset( $users_data[ $user_id ] ) ) {
			$user                   = get_userdata( $user_id );
			$users_data[ $user_id ] = [
				'name'      => $user ? $user->display_name : "User {$user_id}",
				'email'     => $user ? $user->user_email : '',
				'role'      => $user ? implode( ', ', $user->roles ) : '',
				'articles'  => 0,
				'likes'     => 0,
				'dislikes'  => 0,
				'views'     => 0,
				'last_edit' => '',
			];
		}
		$users_data[ $user_id ]['articles']++;
		++$total_contributions;

		// Track last edit.
		$post_modified = get_post_modified_time( 'U', false, $post_id );
		if ( empty( $users_data[ $user_id ]['last_edit'] ) || $post_modified > strtotime( $users_data[ $user_id ]['last_edit'] ) ) {
			$users_data[ $user_id ]['last_edit'] = get_post_modified_time( 'Y-m-d H:i:s', false, $post_id );
		}

		// Track recent activity.
		$recent_activity[] = [
			'user_id'   => $user_id,
			'post_id'   => $post_id,
			'title'     => $doc->post_title,
			'date'      => get_post_modified_time( 'U', false, $post_id ),
			'date_formatted' => get_post_modified_time( 'M j, Y', false, $post_id ),
		];
	}

	// Views.
	$doc_views = intval( get_post_meta( $post_id, 'post_views_count', true ) );
	if ( isset( $users_data[ $author_id ] ) ) {
		$users_data[ $author_id ]['views'] += $doc_views;
	}

	// Likes.
	$positive_voters = get_post_meta( $post_id, 'positive_voter', true );
	$positive_voters = is_array( $positive_voters ) ? $positive_voters : ( ! empty( $positive_voters ) ? [ $positive_voters ] : [] );

	foreach ( $positive_voters as $user_id ) {
		if ( empty( $user_id ) ) {
			continue;
		}
		if ( ! isset( $users_data[ $user_id ] ) ) {
			$user                   = get_userdata( $user_id );
			$users_data[ $user_id ] = [
				'name'      => $user ? $user->display_name : "User {$user_id}",
				'email'     => $user ? $user->user_email : '',
				'role'      => $user ? implode( ', ', $user->roles ) : '',
				'articles'  => 0,
				'likes'     => 0,
				'dislikes'  => 0,
				'views'     => 0,
				'last_edit' => '',
			];
		}
		$users_data[ $user_id ]['likes']++;
	}

	// Dislikes.
	$negative_voters = get_post_meta( $post_id, 'negative_voter', true );
	$negative_voters = is_array( $negative_voters ) ? $negative_voters : ( ! empty( $negative_voters ) ? [ $negative_voters ] : [] );

	foreach ( $negative_voters as $user_id ) {
		if ( empty( $user_id ) ) {
			continue;
		}
		if ( ! isset( $users_data[ $user_id ] ) ) {
			$user                   = get_userdata( $user_id );
			$users_data[ $user_id ] = [
				'name'      => $user ? $user->display_name : "User {$user_id}",
				'email'     => $user ? $user->user_email : '',
				'role'      => $user ? implode( ', ', $user->roles ) : '',
				'articles'  => 0,
				'likes'     => 0,
				'dislikes'  => 0,
				'views'     => 0,
				'last_edit' => '',
			];
		}
		$users_data[ $user_id ]['dislikes']++;
	}
}

// Only keep users with at least 1 article.
$users_data = array_filter( $users_data, fn( $u ) => $u['articles'] > 0 );
$total_contributors = max( 0, count( $users_data ) - 1 );

// Sort by articles descending.
uasort( $users_data, fn( $a, $b ) => $b['articles'] - $a['articles'] );

// Calculate average articles per contributor.
$avg_articles = $total_contributors > 0 ? round( $total_contributions / $total_contributors, 1 ) : 0;

// Sort recent activity by date and limit to last 10.
usort( $recent_activity, fn( $a, $b ) => $b['date'] - $a['date'] );
$recent_activity = array_slice( $recent_activity, 0, 10 );

// Get top contributor.
$top_contributor = ! empty( $users_data ) ? reset( $users_data ) : null;

// Prepare for JS.
$user_names = [];
$articles   = [];
$likes      = [];
$dislikes   = [];
$views      = [];

foreach ( $users_data as $user_id => $data ) {
	$user_names[] = $data['name'];
	$articles[]   = $data['articles'];
	$likes[]      = $data['likes'];
	$dislikes[]   = $data['dislikes'];
	$views[]      = $data['views'];
}
?>

<div class="easydocs-tab" id="analytics-collaboration">
	<!-- Enhanced Header -->
	<div class="doc-ranks-header">
		<div class="header-content">
			<h2 class="title"><?php esc_html_e( 'Collaboration', 'eazydocs-pro' ); ?></h2>
			<p class="subtitle"><?php esc_html_e( 'Track contributor performance, team productivity, and documentation authorship metrics.', 'eazydocs-pro' ); ?></p>
		</div>
		<div class="header-actions">
			<button type="button" class="ezd-btn-icon" id="ezd-export-collaboration" title="<?php esc_attr_e( 'Export data', 'eazydocs-pro' ); ?>">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Export', 'eazydocs-pro' ); ?>
			</button>
		</div>
	</div>

	<!-- Summary Statistics Cards -->
	<div class="doc-ranks-stats">
		<div class="stat-card stat-card--primary">
			<div class="stat-icon">
				<span class="dashicons dashicons-groups"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( number_format_i18n( $total_contributors ) ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Contributors', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="stat-card stat-card--success">
			<div class="stat-icon">
				<span class="dashicons dashicons-media-document"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( number_format_i18n( $total_docs ) ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Total Documentations', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="stat-card stat-card--info">
			<div class="stat-icon">
				<span class="dashicons dashicons-chart-line"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $avg_articles ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Avg. per Contributor', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="stat-card stat-card--warning">
			<div class="stat-icon">
				<span class="dashicons dashicons-awards"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value stat-value--small"><?php echo $top_contributor ? esc_html( $top_contributor['name'] ) : '-'; ?></span>
				<span class="stat-label"><?php esc_html_e( 'Top Contributor', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Chart Section -->
	<div class="collaboration-chart-section">
		<div class="chart-header">
			<div class="chart-title">
				<span class="dashicons dashicons-chart-area"></span>
				<h3><?php esc_html_e( 'Contribution Overview', 'eazydocs-pro' ); ?></h3>
			</div>
			<div class="chart-controls">
				<div class="chart-toggles">
					<button type="button" id="toggle-articles" class="chart-toggle is-active" data-series="Articles">
						<span class="toggle-dot" style="background: #6366f1;"></span>
						<?php esc_html_e( 'Articles', 'eazydocs-pro' ); ?>
					</button>
					<button type="button" id="toggle-likes" class="chart-toggle is-active" data-series="Likes">
						<span class="toggle-dot" style="background: #10b981;"></span>
						<?php esc_html_e( 'Likes', 'eazydocs-pro' ); ?>
					</button>
					<button type="button" id="toggle-dislikes" class="chart-toggle is-active" data-series="Dislikes">
						<span class="toggle-dot" style="background: #ef4444;"></span>
						<?php esc_html_e( 'Dislikes', 'eazydocs-pro' ); ?>
					</button>
					<button type="button" id="toggle-views" class="chart-toggle is-active" data-series="Views">
						<span class="toggle-dot" style="background: #3b82f6;"></span>
						<?php esc_html_e( 'Views', 'eazydocs-pro' ); ?>
					</button>
				</div>
				<div class="chart-type-switcher">
					<button type="button" class="chart-type-btn is-active" data-type="area" title="<?php esc_attr_e( 'Area Chart', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-chart-area"></span>
					</button>
					<button type="button" class="chart-type-btn" data-type="bar" title="<?php esc_attr_e( 'Bar Chart', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-chart-bar"></span>
					</button>
				</div>
			</div>
		</div>
		<div class="chart-wrapper">
			<div id="collaboration_chart"></div>
		</div>
	</div>

	<!-- Content Grid: Contributors Table + Activity Feed -->
	<div class="collaboration-grid">
		<!-- Contributors Table -->
		<div class="contributors-section">
			<div class="section-header">
				<div class="section-title">
					<span class="dashicons dashicons-star-filled"></span>
					<h3><?php esc_html_e( 'Leading Contributors', 'eazydocs-pro' ); ?></h3>
				</div>
				<div class="section-controls">
					<div class="search-wrapper">
						<span class="dashicons dashicons-search"></span>
						<input type="text" id="contributor-search" placeholder="<?php esc_attr_e( 'Search contributors...', 'eazydocs-pro' ); ?>" />
					</div>
					<select id="contributor-sort" class="sort-select">
						<option value="articles"><?php esc_html_e( 'Sort by Articles', 'eazydocs-pro' ); ?></option>
						<option value="likes"><?php esc_html_e( 'Sort by Likes', 'eazydocs-pro' ); ?></option>
						<option value="views"><?php esc_html_e( 'Sort by Views', 'eazydocs-pro' ); ?></option>
						<option value="name"><?php esc_html_e( 'Sort by Name', 'eazydocs-pro' ); ?></option>
					</select>
				</div>
			</div>
			<div class="contributors-table-wrapper">
				<table class="contributors-table">
					<thead>
						<tr>
							<th class="col-rank"><?php esc_html_e( 'Rank', 'eazydocs-pro' ); ?></th>
							<th class="col-author"><?php esc_html_e( 'Contributor', 'eazydocs-pro' ); ?></th>
							<th class="col-articles"><?php esc_html_e( 'Articles', 'eazydocs-pro' ); ?></th>
							<th class="col-likes"><?php esc_html_e( 'Likes', 'eazydocs-pro' ); ?></th>
							<th class="col-dislikes"><?php esc_html_e( 'Dislikes', 'eazydocs-pro' ); ?></th>
							<th class="col-views"><?php esc_html_e( 'Views', 'eazydocs-pro' ); ?></th>
							<th class="col-engagement"><?php esc_html_e( 'Engagement', 'eazydocs-pro' ); ?></th>
						</tr>
					</thead>
					<tbody id="contributors-body">
						<?php
						$rank = 1;
						foreach ( $users_data as $user_id => $data ) :
							$user = get_userdata( $user_id );
							if ( ! $user ) {
								continue;
							}
							$total_votes  = $data['likes'] + $data['dislikes'];
							$engagement   = $total_votes > 0 ? round( ( $data['likes'] / $total_votes ) * 100 ) : 0;
							$rank_class   = '';
							$rank_display = $rank;
							if ( 1 === $rank ) {
								$rank_class   = 'rank-gold';
								$rank_display = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#FFD700"/></svg>';
							} elseif ( 2 === $rank ) {
								$rank_class   = 'rank-silver';
								$rank_display = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#C0C0C0"/></svg>';
							} elseif ( 3 === $rank ) {
								$rank_class   = 'rank-bronze';
								$rank_display = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#CD7F32"/></svg>';
							}
							?>
							<tr class="contributor-row <?php echo esc_attr( $rank_class ); ?>" 
								data-name="<?php echo esc_attr( strtolower( $data['name'] ) ); ?>"
								data-articles="<?php echo esc_attr( $data['articles'] ); ?>"
								data-likes="<?php echo esc_attr( $data['likes'] ); ?>"
								data-views="<?php echo esc_attr( $data['views'] ); ?>">
								<td class="col-rank">
									<span class="rank-badge"><?php echo $rank <= 3 ? $rank_display : esc_html( $rank ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								</td>
								<td class="col-author">
									<div class="author-info">
										<a href="<?php echo esc_url( ezdpro_author_url( $user->user_login ) ); ?>" class="author-avatar">
											<?php echo get_avatar( $user->ID, 40 ); ?>
										</a>
										<div class="author-details">
											<a href="<?php echo esc_url( ezdpro_author_url( $user->user_login ) ); ?>" class="author-name">
												<?php echo esc_html( $user->display_name ); ?>
											</a>
											<span class="author-role"><?php echo esc_html( ucwords( str_replace( '_', ' ', $data['role'] ) ) ); ?></span>
										</div>
									</div>
								</td>
								<td class="col-articles">
									<span class="stat-badge stat-badge--primary"><?php echo esc_html( number_format_i18n( $data['articles'] ) ); ?></span>
								</td>
								<td class="col-likes">
									<span class="stat-badge stat-badge--success">
										<span class="dashicons dashicons-thumbs-up"></span>
										<?php echo esc_html( number_format_i18n( $data['likes'] ) ); ?>
									</span>
								</td>
								<td class="col-dislikes">
									<span class="stat-badge stat-badge--danger">
										<span class="dashicons dashicons-thumbs-down"></span>
										<?php echo esc_html( number_format_i18n( $data['dislikes'] ) ); ?>
									</span>
								</td>
								<td class="col-views">
									<span class="stat-badge stat-badge--info">
										<span class="dashicons dashicons-visibility"></span>
										<?php echo esc_html( number_format_i18n( $data['views'] ) ); ?>
									</span>
								</td>
								<td class="col-engagement">
									<div class="engagement-bar">
										<div class="bar-track">
											<div class="bar-fill" style="width: <?php echo esc_attr( $engagement ); ?>%;"></div>
										</div>
										<span class="bar-value"><?php echo esc_html( $engagement ); ?>%</span>
									</div>
								</td>
							</tr>
							<?php
							++$rank;
						endforeach;
						?>
					</tbody>
				</table>
				<?php if ( empty( $users_data ) ) : ?>
					<div class="empty-state">
						<span class="dashicons dashicons-groups"></span>
						<p><?php esc_html_e( 'No contributors found. Start creating documentation to see contribution metrics.', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Activity Feed -->
		<div class="activity-section">
			<div class="section-header">
				<div class="section-title">
					<span class="dashicons dashicons-backup"></span>
					<h3><?php esc_html_e( 'Recent Activity', 'eazydocs-pro' ); ?></h3>
				</div>
			</div>
			<div class="activity-feed">
				<?php if ( ! empty( $recent_activity ) ) : ?>
					<?php foreach ( $recent_activity as $activity ) : ?>
						<?php $activity_user = get_userdata( $activity['user_id'] ); ?>
						<?php if ( $activity_user ) : ?>
							<div class="activity-item">
								<div class="activity-avatar">
									<?php echo get_avatar( $activity_user->ID, 32 ); ?>
								</div>
								<div class="activity-content">
									<p class="activity-text">
										<strong><?php echo esc_html( $activity_user->display_name ); ?></strong>
										<?php esc_html_e( 'updated', 'eazydocs-pro' ); ?>
										<a href="<?php echo esc_url( get_permalink( $activity['post_id'] ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $activity['title'], 6 ) ); ?></a>
									</p>
									<span class="activity-time">
										<span class="dashicons dashicons-clock"></span>
										<?php echo esc_html( $activity['date_formatted'] ); ?>
									</span>
								</div>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="empty-state empty-state--small">
						<span class="dashicons dashicons-backup"></span>
						<p><?php esc_html_e( 'No recent activity', 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<script>
jQuery(document).ready(function($) {
	const categories  = <?php echo wp_json_encode( $user_names ); ?>;
	const articleData = <?php echo wp_json_encode( $articles ); ?>;
	const likeData    = <?php echo wp_json_encode( $likes ); ?>;
	const dislikeData = <?php echo wp_json_encode( $dislikes ); ?>;
	const viewData    = <?php echo wp_json_encode( $views ); ?>;

	let currentChartType = 'area';
	let collaborationChart;

	// Chart options generator.
	function getChartOptions(type) {
		return {
			series: [
				{ name: 'Articles', data: articleData },
				{ name: 'Likes', data: likeData },
				{ name: 'Dislikes', data: dislikeData },
				{ name: 'Views', data: viewData }
			],
			chart: {
				height: 380,
				type: type,
				fontFamily: 'inherit',
				toolbar: {
					show: true,
					tools: {
						download: true,
						selection: false,
						zoom: false,
						zoomin: false,
						zoomout: false,
						pan: false,
						reset: false
					}
				},
				animations: {
					enabled: true,
					easing: 'easeinout',
					speed: 500
				}
			},
			colors: ['#6366f1', '#10b981', '#ef4444', '#3b82f6'],
			dataLabels: {
				enabled: false
			},
			stroke: {
				curve: 'smooth',
				width: type === 'bar' ? 0 : 3
			},
			plotOptions: {
				bar: {
					borderRadius: 6,
					columnWidth: '60%',
					dataLabels: {
						position: 'top'
					}
				}
			},
			xaxis: {
				categories: categories,
				axisBorder: {
					show: false
				},
				axisTicks: {
					show: false
				},
				labels: {
					style: {
						colors: '#64748b',
						fontSize: '12px'
					}
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
			fill: type === 'area' ? {
				type: "gradient",
				gradient: {
					shadeIntensity: 1,
					opacityFrom: 0.45,
					opacityTo: 0.05,
					stops: [0, 100]
				}
			} : {
				opacity: 1
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
				shared: true,
				intersect: false,
				y: {
					formatter: function (val) {
						return val + " entries";
					}
				}
			}
		};
	}

	// Initialize chart.
	function initChart() {
		if (collaborationChart) {
			collaborationChart.destroy();
		}
		collaborationChart = new ApexCharts(document.querySelector("#collaboration_chart"), getChartOptions(currentChartType));
		collaborationChart.render();
	}

	initChart();

	// Toggle series visibility.
	$('.chart-toggle').on('click', function() {
		const $btn = $(this);
		const series = $btn.data('series');
		$btn.toggleClass('is-active');
		collaborationChart.toggleSeries(series);
	});

	// Chart type switcher.
	$('.chart-type-btn').on('click', function() {
		const $btn = $(this);
		const type = $btn.data('type');
		
		if (type === currentChartType) return;
		
		$('.chart-type-btn').removeClass('is-active');
		$btn.addClass('is-active');
		
		currentChartType = type;
		initChart();
		
		// Re-apply toggle states.
		$('.chart-toggle').each(function() {
			if (!$(this).hasClass('is-active')) {
				collaborationChart.toggleSeries($(this).data('series'));
			}
		});
	});

	// Contributor search.
	$('#contributor-search').on('input', function() {
		const query = $(this).val().toLowerCase().trim();
		
		$('.contributor-row').each(function() {
			const $row = $(this);
			const name = $row.data('name');
			
			if (query === '' || name.includes(query)) {
				$row.show();
			} else {
				$row.hide();
			}
		});
	});

	// Contributor sorting.
	$('#contributor-sort').on('change', function() {
		const sortBy = $(this).val();
		const $tbody = $('#contributors-body');
		const $rows = $tbody.find('.contributor-row').get();

		$rows.sort(function(a, b) {
			const $a = $(a);
			const $b = $(b);

			if (sortBy === 'name') {
				return $a.data('name').localeCompare($b.data('name'));
			} else if (sortBy === 'articles') {
				return parseInt($b.data('articles')) - parseInt($a.data('articles'));
			} else if (sortBy === 'likes') {
				return parseInt($b.data('likes')) - parseInt($a.data('likes'));
			} else if (sortBy === 'views') {
				return parseInt($b.data('views')) - parseInt($a.data('views'));
			}
			return 0;
		});

		$.each($rows, function(idx, row) {
			$tbody.append(row);
			// Update rank badges.
			const $row = $(row);
			const $badge = $row.find('.rank-badge');
			$row.removeClass('rank-gold rank-silver rank-bronze');
			
			if (idx === 0) {
				$row.addClass('rank-gold');
				$badge.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#FFD700"/></svg>');
			} else if (idx === 1) {
				$row.addClass('rank-silver');
				$badge.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#C0C0C0"/></svg>');
			} else if (idx === 2) {
				$row.addClass('rank-bronze');
				$badge.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#CD7F32"/></svg>');
			} else {
				$badge.html(idx + 1);
			}
		});
	});

	// Export functionality.
	$('#ezd-export-collaboration').on('click', function() {
		const $rows = $('.contributor-row:visible');
		
		let csvContent = 'data:text/csv;charset=utf-8,';
		csvContent += 'Rank,Contributor,Articles,Likes,Dislikes,Views,Engagement\n';
		
		$rows.each(function(index) {
			const $row = $(this);
			const name = $row.find('.author-name').text().trim().replace(/,/g, ' ');
			const articles = $row.data('articles');
			const likes = $row.data('likes');
			const dislikes = $row.find('.col-dislikes .stat-badge').text().trim();
			const views = $row.data('views');
			const engagement = $row.find('.bar-value').text().trim();
			
			csvContent += `${index + 1},"${name}",${articles},${likes},${dislikes},${views},${engagement}\n`;
		});
		
		const encodedUri = encodeURI(csvContent);
		const link = document.createElement('a');
		link.setAttribute('href', encodedUri);
		link.setAttribute('download', `collaboration-report-${new Date().toISOString().split('T')[0]}.csv`);
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	});
});
</script>