<?php
/**
 * Analytics Doc Ranks Tab - Enhanced Version
 *
 * @package EasyDocs\Admin\Analytics
 */

// Get summary statistics.
$posts_query   = get_posts(
	array(
		'post_type'      => 'docs',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
$total_docs    = count( $posts_query );
$total_votes   = 0;
$positive_sum  = 0;
$negative_sum  = 0;
$total_views   = 0;
$docs_with_votes = 0;

foreach ( $posts_query as $doc_id ) {
	$positive    = array_sum( get_post_meta( $doc_id, 'positive', false ) );
	$negative    = array_sum( get_post_meta( $doc_id, 'negative', false ) );
	$views       = intval( get_post_meta( $doc_id, 'post_views_count', true ) );
	$positive_sum += $positive;
	$negative_sum += $negative;
	$total_views  += $views;

	if ( $positive > 0 || $negative > 0 ) {
		++$docs_with_votes;
	}
}

$total_votes       = $positive_sum + $negative_sum;
$helpfulness_rate  = $total_votes > 0 ? round( ( $positive_sum / $total_votes ) * 100, 1 ) : 0;
$nonce             = wp_create_nonce( 'ezd_analytics_nonce' );
?>

<div class="easydocs-tab" id="analytics-helpful">
	<div class="doc-ranks-header">
		<div class="header-content">
			<h2 class="title"><?php esc_html_e( 'Doc Ranks', 'eazydocs-pro' ); ?></h2>
			<p class="subtitle"><?php esc_html_e( 'Analyze your documentation performance based on user feedback and engagement.', 'eazydocs-pro' ); ?></p>
		</div>
		<div class="header-actions">
			<button type="button" class="ezd-btn-icon" id="ezd-export-ranks" title="<?php esc_attr_e( 'Export data', 'eazydocs-pro' ); ?>">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Export', 'eazydocs-pro' ); ?>
			</button>
		</div>
	</div>

	<!-- Summary Statistics Cards -->
	<div class="doc-ranks-stats">
		<div class="stat-card stat-card--success">
			<div class="stat-icon">
				<span class="dashicons dashicons-thumbs-up"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( number_format_i18n( $positive_sum ) ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Positive Votes', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="stat-card stat-card--danger">
			<div class="stat-icon">
				<span class="dashicons dashicons-thumbs-down"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( number_format_i18n( $negative_sum ) ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Negative Votes', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="stat-card stat-card--info">
			<div class="stat-icon">
				<span class="dashicons dashicons-visibility"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( number_format_i18n( $total_views ) ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Total Views', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="stat-card stat-card--warning">
			<div class="stat-icon">
				<span class="dashicons dashicons-chart-bar"></span>
			</div>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $helpfulness_rate ); ?>%</span>
				<span class="stat-label"><?php esc_html_e( 'Helpfulness Rate', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Enhanced Filter Container -->
	<div class="doc-ranks-filters">
		<div class="filter-tabs">
			<button type="button" class="filter-tab is-active" data-tab="most_helpful" data-filter=".most-helpful">
				<span class="dashicons dashicons-thumbs-up"></span>
				<?php esc_html_e( 'Most Helpful', 'eazydocs-pro' ); ?>
				<span class="tab-count" id="most-helpful-count">0</span>
			</button>
			<button type="button" class="filter-tab" data-tab="least_helpful" data-filter=".least-helpful">
				<span class="dashicons dashicons-thumbs-down"></span>
				<?php esc_html_e( 'Least Helpful', 'eazydocs-pro' ); ?>
				<span class="tab-count" id="least-helpful-count">0</span>
			</button>
			<button type="button" class="filter-tab" data-tab="most_viewed" data-filter=".most-viewed">
				<span class="dashicons dashicons-visibility"></span>
				<?php esc_html_e( 'Most Viewed', 'eazydocs-pro' ); ?>
				<span class="tab-count" id="most-viewed-count">0</span>
			</button>
		</div>

		<div class="filter-controls">
			<div class="search-input-wrapper">
				<span class="dashicons dashicons-search"></span>
				<input type="text" id="ezd-doc-search" placeholder="<?php esc_attr_e( 'Search docs...', 'eazydocs-pro' ); ?>" />
			</div>
			<div class="items-control">
				<label for="ezd-items-count"><?php esc_html_e( 'Show:', 'eazydocs-pro' ); ?></label>
				<select id="ezd-items-count">
					<option value="10">10</option>
					<option value="25">25</option>
					<option value="50">50</option>
					<option value="100">100</option>
				</select>
			</div>
			<div class="sort-control">
				<label for="ezd-sort-by"><?php esc_html_e( 'Sort:', 'eazydocs-pro' ); ?></label>
				<select id="ezd-sort-by">
					<option value="votes"><?php esc_html_e( 'By Votes', 'eazydocs-pro' ); ?></option>
					<option value="title"><?php esc_html_e( 'By Title', 'eazydocs-pro' ); ?></option>
					<option value="date"><?php esc_html_e( 'By Date', 'eazydocs-pro' ); ?></option>
				</select>
			</div>
		</div>
	</div>

	<!-- Doc Ranks Content Panels -->
	<div class="doc-ranks-content">
		<?php
		/**
		 * Most Helpful and Least Docs
		 */
		include __DIR__ . '/doc-ranks/helpful-most.php';
		include __DIR__ . '/doc-ranks/helpful-least.php';
		include __DIR__ . '/doc-ranks/most-viewed-docs.php';
		?>
	</div>
</div>

<script>
jQuery(document).ready(function($) {
	const $tabs = $('.filter-tab');
	const $panels = {
		'most_helpful': $('#most_helpful'),
		'least_helpful': $('#least_helpful'),
		'most_viewed': $('#most_viewed')
	};
	const $searchInput = $('#ezd-doc-search');
	const $itemsCount = $('#ezd-items-count');
	const $sortBy = $('#ezd-sort-by');
	
	let currentTab = 'most_helpful';
	let searchTimeout;

	// Initialize: Show first panel, hide others
	function initPanels() {
		$panels.most_helpful.addClass('panel-active').show();
		$panels.least_helpful.removeClass('panel-active').hide();
		$panels.most_viewed.removeClass('panel-active').hide();
		
		// Update counts
		updateTabCounts();
	}

	// Sort functionality
	$sortBy.on('change', function() {
		const sortType = $(this).val();
		sortDocs(sortType);
	});

	// Sort docs based on selected criteria
	function sortDocs(sortType) {
		const $currentPanel = $panels[currentTab];
		const $list = $currentPanel.find('.doc-ranks-list');
		const $items = $list.find('.doc-rank-item').get();

		$items.sort(function(a, b) {
			const $a = $(a);
			const $b = $(b);

			switch(sortType) {
				case 'votes':
					// Sort by net votes (positive - negative) for helpful tabs
					// Sort by views for most_viewed tab
					if (currentTab === 'most_viewed') {
						return parseInt($b.data('views') || 0) - parseInt($a.data('views') || 0);
					} else if (currentTab === 'least_helpful') {
						return parseInt($b.data('negative') || 0) - parseInt($a.data('negative') || 0);
					} else {
						return parseInt($b.data('positive') || 0) - parseInt($a.data('positive') || 0);
					}
				
				case 'title':
					// Sort alphabetically by title
					const titleA = $a.data('title') || '';
					const titleB = $b.data('title') || '';
					return titleA.localeCompare(titleB);
				
				case 'date':
					// Sort by ID (proxy for date - higher ID = more recent)
					return parseInt($b.data('id') || 0) - parseInt($a.data('id') || 0);
				
				default:
					return 0;
			}
		});

		// Re-append sorted items
		$.each($items, function(idx, item) {
			$list.append(item);
		});

		// Re-apply rank badges after sorting
		updateRankBadges($currentPanel);
	}

	// Update rank badges after sorting
	function updateRankBadges($panel) {
		$panel.find('.doc-rank-item:visible').each(function(index) {
			const $item = $(this);
			const $badge = $item.find('.rank-badge');
			
			// Remove old rank classes
			$item.removeClass('rank-gold rank-silver rank-bronze');
			
			// Update rank number/medal display
			if (index === 0) {
				$item.addClass('rank-gold');
				$badge.html('<span class="rank-medal"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#FFD700"/></svg></span>');
			} else if (index === 1) {
				$item.addClass('rank-silver');
				$badge.html('<span class="rank-medal"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#C0C0C0"/></svg></span>');
			} else if (index === 2) {
				$item.addClass('rank-bronze');
				$badge.html('<span class="rank-medal"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#CD7F32"/></svg></span>');
			} else {
				$badge.html('<span class="rank-number">' + (index + 1) + '</span>');
			}
		});
	}

	// Update tab counts
	function updateTabCounts() {
		$('#most-helpful-count').text($panels.most_helpful.find('.doc-rank-item:not(.filtered-out)').length);
		$('#least-helpful-count').text($panels.least_helpful.find('.doc-rank-item:not(.filtered-out)').length);
		$('#most-viewed-count').text($panels.most_viewed.find('.doc-rank-item:not(.filtered-out)').length);
	}

	// Tab switching with animation
	$tabs.on('click', function() {
		const $this = $(this);
		const targetTab = $this.data('tab');
		
		if (targetTab === currentTab) return;
		
		// Update active state
		$tabs.removeClass('is-active');
		$this.addClass('is-active');
		
		// Animate panel transition
		const $currentPanel = $panels[currentTab];
		const $targetPanel = $panels[targetTab];
		
		$currentPanel.removeClass('panel-active').fadeOut(150, function() {
			$targetPanel.addClass('panel-active').fadeIn(200);
		});
		
		currentTab = targetTab;
		
		// Clear search when switching tabs
		$searchInput.val('');
		filterDocs('');
	});

	// Search functionality with debounce
	$searchInput.on('input', function() {
		const query = $(this).val().toLowerCase().trim();
		
		clearTimeout(searchTimeout);
		searchTimeout = setTimeout(function() {
			filterDocs(query);
		}, 250);
	});

	// Filter docs by search query
	function filterDocs(query) {
		const $currentPanel = $panels[currentTab];
		const $items = $currentPanel.find('.doc-rank-item');
		
		if (query === '') {
			$items.removeClass('filtered-out').show();
			updateTabCounts();
			checkEmptyState($currentPanel);
			return;
		}
		
		$items.each(function() {
			const $item = $(this);
			const title = $item.find('.doc-title').text().toLowerCase();
			
			if (title.includes(query)) {
				$item.removeClass('filtered-out').show();
			} else {
				$item.addClass('filtered-out').hide();
			}
		});
		
		updateTabCounts();
		checkEmptyState($currentPanel);
	}

	// Check and show empty state
	function checkEmptyState($panel) {
		const visibleItems = $panel.find('.doc-rank-item:not(.filtered-out)').length;
		let $emptyState = $panel.find('.empty-search-state');
		
		if (visibleItems === 0 && $searchInput.val().trim() !== '') {
			if ($emptyState.length === 0) {
				$panel.find('.dd-list').append(
					'<div class="empty-search-state">' +
					'<span class="dashicons dashicons-search"></span>' +
					'<p><?php echo esc_js( __( 'No docs found matching your search.', 'eazydocs-pro' ) ); ?></p>' +
					'</div>'
				);
			}
			$emptyState.show();
		} else {
			$emptyState.hide();
		}
	}

	// Items count change - reload data
	$itemsCount.on('change', function() {
		const count = $(this).val();
		loadDocRanks(currentTab, count);
	});

	// Load doc ranks via AJAX
	function loadDocRanks(type, count) {
		const $panel = $panels[type];
		const $list = $panel.find('.dd-list');
		
		$list.addClass('loading');
		
		$.ajax({
			url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			type: 'GET',
			data: {
				action: 'ezd_search_helpful_docs_paginate',
				type: type,
				total_page: count,
				nonce: '<?php echo esc_js( $nonce ); ?>'
			},
			success: function(response) {
				$list.html(response).removeClass('loading');
				updateTabCounts();
				initRankBadges();
			},
			error: function() {
				$list.removeClass('loading');
			}
		});
	}

	// Initialize rank badges (top 3 get special styling)
	function initRankBadges() {
		$('.doc-rank-item').each(function(index) {
			const $item = $(this);
			$item.removeClass('rank-gold rank-silver rank-bronze');
			
			if (index === 0) $item.addClass('rank-gold');
			else if (index === 1) $item.addClass('rank-silver');
			else if (index === 2) $item.addClass('rank-bronze');
		});
	}

	// Export functionality
	$('#ezd-export-ranks').on('click', function() {
		const $currentPanel = $panels[currentTab];
		const $items = $currentPanel.find('.doc-rank-item:not(.filtered-out)');
		
		let csvContent = 'data:text/csv;charset=utf-8,';
		csvContent += 'Rank,Title,Positive Votes,Negative Votes,Views\n';
		
		$items.each(function(index) {
			const $item = $(this);
			const title = $item.find('.doc-title').text().replace(/,/g, ' ');
			const positive = $item.data('positive') || 0;
			const negative = $item.data('negative') || 0;
			const views = $item.data('views') || 0;
			
			csvContent += `${index + 1},"${title}",${positive},${negative},${views}\n`;
		});
		
		const encodedUri = encodeURI(csvContent);
		const link = document.createElement('a');
		link.setAttribute('href', encodedUri);
		link.setAttribute('download', `doc-ranks-${currentTab}-${new Date().toISOString().split('T')[0]}.csv`);
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	});

	// Initialize
	initPanels();
	initRankBadges();
});
</script>