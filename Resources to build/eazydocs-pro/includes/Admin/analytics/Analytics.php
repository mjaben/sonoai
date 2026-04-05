<?php
/**
 * EazyDocs Analytics Page Template
 * Modern, redesigned analytics dashboard with Ajax tab loading
 *
 * @package EasyDocs\Admin\Analytics
 */

namespace EasyDocs\Admin\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Generate nonce for Ajax requests.
$ajax_nonce = wp_create_nonce( 'ezd_analytics_nonce' );

// Determine initial active tab from URL parameter.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$more_state  = isset( $_GET['more_state'] ) ? sanitize_text_field( wp_unslash( $_GET['more_state'] ) ) : '';
$initial_tab = ! empty( $more_state ) ? $more_state : 'analytics-overview';

// Valid tabs list.
$valid_tabs = array(
	'analytics-overview',
	'analytics-views',
	'analytics-feedback',
	'analytics-search',
	'analytics-helpful',
	'analytics-collaboration',
);

// Validate the initial tab.
if ( ! in_array( $initial_tab, $valid_tabs, true ) ) {
	$initial_tab = 'analytics-overview';
}
?>
<div class="wrap ezd-analytics ezd_doc_builder">
	<div class="easydocs-sidebar-menu">
		<div class="tab-container">
			<div class="dd tab-menu short">
				<ol class="easydocs-navbar dd-list">
					<li class="easydocs-navitem dd-item dd3-item<?php echo 'analytics-overview' === $initial_tab ? ' active' : ''; ?>" data-rel="analytics-overview" data-id="1">
						<div class="title">
							<span class="dashicons dashicons-chart-area"></span>
							<?php esc_html_e( 'Overview', 'eazydocs-pro' ); ?>
						</div>
					</li>
					<li class="easydocs-navitem dd-item dd3-item<?php echo 'analytics-views' === $initial_tab ? ' active' : ''; ?>" data-rel="analytics-views" data-id="2">
						<div class="title">
							<span class="dashicons dashicons-visibility"></span>
							<?php esc_html_e( 'Views', 'eazydocs-pro' ); ?>
						</div>
					</li>
					<li class="easydocs-navitem dd-item dd3-item<?php echo 'analytics-feedback' === $initial_tab ? ' active' : ''; ?>" data-rel="analytics-feedback" data-id="3">
						<div class="title">
							<span class="dashicons dashicons-thumbs-up"></span>
							<?php esc_html_e( 'Feedback', 'eazydocs-pro' ); ?>
						</div>
					</li>
					<li class="easydocs-navitem dd-item dd3-item<?php echo 'analytics-search' === $initial_tab ? ' active' : ''; ?>" data-rel="analytics-search" data-id="4">
						<div class="title">
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e( 'Search', 'eazydocs-pro' ); ?>
						</div>
					</li>
					<li class="easydocs-navitem dd-item dd3-item<?php echo 'analytics-helpful' === $initial_tab ? ' active' : ''; ?>" data-rel="analytics-helpful" data-id="5">
						<div class="title">
							<span class="dashicons dashicons-star-filled"></span>
							<?php esc_html_e( 'Doc Ranks', 'eazydocs-pro' ); ?>
						</div>
					</li>
					<li class="easydocs-navitem dd-item dd3-item<?php echo 'analytics-collaboration' === $initial_tab ? ' active' : ''; ?>" data-rel="analytics-collaboration" data-id="6">
						<div class="title">
							<span class="dashicons dashicons-groups"></span>
							<?php esc_html_e( 'Collaboration', 'eazydocs-pro' ); ?>
						</div>
					</li>
				</ol>
			</div>
			<div class="easydocs-tab-content">
				<!-- Loading State Placeholder -->
				<div class="ezd-analytics-loading" id="ezd-analytics-loading" style="display: none;">
					<div class="ezd-loading-spinner">
						<span class="dashicons dashicons-update-alt ezd-spin"></span>
						<p><?php esc_html_e( 'Loading...', 'eazydocs-pro' ); ?></p>
					</div>
				</div>

				<!-- Tab Content Containers (initially empty except overview) -->
				<div class="easydocs-tab-wrapper" id="tab-analytics-overview" data-loaded="true"<?php echo 'analytics-overview' !== $initial_tab ? ' style="display: none;"' : ''; ?>>
					<?php include dirname( __FILE__ ) . '/parts/overview.php'; ?>
				</div>
				<div class="easydocs-tab-wrapper" id="tab-analytics-views" data-loaded="false" style="display: none;"></div>
				<div class="easydocs-tab-wrapper" id="tab-analytics-feedback" data-loaded="false" style="display: none;"></div>
				<div class="easydocs-tab-wrapper" id="tab-analytics-search" data-loaded="false" style="display: none;"></div>
				<div class="easydocs-tab-wrapper" id="tab-analytics-helpful" data-loaded="false" style="display: none;"></div>
				<div class="easydocs-tab-wrapper" id="tab-analytics-collaboration" data-loaded="false" style="display: none;"></div>
			</div>
		</div>
	</div>
</div>

<!-- Immediate hash handling script (runs before jQuery ready) -->
<script>
(function() {
	var hash = window.location.hash.replace('#', '');
	var validTabs = ['analytics-overview', 'analytics-views', 'analytics-feedback', 'analytics-search', 'analytics-helpful', 'analytics-collaboration'];
	
	if (hash && validTabs.indexOf(hash) !== -1) {
		// Remove active classes from all nav items
		var navItems = document.querySelectorAll('.easydocs-navitem');
		navItems.forEach(function(item) {
			item.classList.remove('active');
		});
		
		// Add active class to the correct nav item
		var targetNav = document.querySelector('.easydocs-navitem[data-rel="' + hash + '"]');
		if (targetNav) {
			targetNav.classList.add('active');
		}
		
		// Hide overview if it's not the target
		if (hash !== 'analytics-overview') {
			var overviewTab = document.getElementById('tab-analytics-overview');
			if (overviewTab) {
				overviewTab.style.display = 'none';
			}
		}
	}
})();
</script>

<style>
/* Loading Spinner Styles */
.ezd-analytics-loading {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(255, 255, 255, 0.9);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 100;
	min-height: 400px;
}

.ezd-loading-spinner {
	text-align: center;
	padding: 40px;
}

.ezd-loading-spinner .dashicons {
	font-size: 48px;
	width: 48px;
	height: 48px;
	color: #6366f1;
}

.ezd-loading-spinner p {
	margin-top: 16px;
	font-size: 14px;
	color: #64748b;
	font-weight: 500;
}

.ezd-spin {
	animation: ezd-spin 1s linear infinite;
}

@keyframes ezd-spin {
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}

.easydocs-tab-content {
	position: relative;
	min-height: 400px;
}

.easydocs-tab-wrapper {
	min-height: 200px;
}

/* Tab loading skeleton */
.ezd-tab-skeleton {
	padding: 24px;
}

.ezd-skeleton-header {
	height: 32px;
	background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
	background-size: 200% 100%;
	animation: ezd-skeleton-loading 1.5s ease-in-out infinite;
	border-radius: 8px;
	margin-bottom: 24px;
	width: 60%;
}

.ezd-skeleton-cards {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 20px;
	margin-bottom: 24px;
}

.ezd-skeleton-card {
	height: 120px;
	background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
	background-size: 200% 100%;
	animation: ezd-skeleton-loading 1.5s ease-in-out infinite;
	border-radius: 12px;
}

.ezd-skeleton-chart {
	height: 300px;
	background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
	background-size: 200% 100%;
	animation: ezd-skeleton-loading 1.5s ease-in-out infinite;
	border-radius: 12px;
}

@keyframes ezd-skeleton-loading {
	0% {
		background-position: 200% 0;
	}
	100% {
		background-position: -200% 0;
	}
}

/* Transition for smooth tab switching */
.easydocs-tab-wrapper {
	opacity: 1;
	transition: opacity 0.2s ease-in-out;
}

.easydocs-tab-wrapper.is-loading {
	opacity: 0.5;
}

/* IMPORTANT: Make Ajax-loaded tabs visible inside wrapper */
.easydocs-tab-wrapper .easydocs-tab {
	display: block !important;
}
</style>

<script>
	jQuery(document).ready(function($) {
		var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce = '<?php echo esc_js( $ajax_nonce ); ?>';
		var loadedTabs = {
			'analytics-overview': true
		};
		var loadingTabs = {};

		/**
		 * Show loading skeleton
		 */
		function showLoadingSkeleton(tabId) {
			var $container = $('#tab-' + tabId);
			$container.html(
				'<div class="ezd-tab-skeleton">' +
					'<div class="ezd-skeleton-header"></div>' +
					'<div class="ezd-skeleton-cards">' +
						'<div class="ezd-skeleton-card"></div>' +
						'<div class="ezd-skeleton-card"></div>' +
						'<div class="ezd-skeleton-card"></div>' +
						'<div class="ezd-skeleton-card"></div>' +
					'</div>' +
					'<div class="ezd-skeleton-chart"></div>' +
				'</div>'
			);
		}

		/**
		 * Load tab content via Ajax
		 */
		function loadTabContent(tabId, callback) {
			// Check if tab is already loaded
			if (loadedTabs[tabId]) {
				if (typeof callback === 'function') {
					callback(true);
				}
				return;
			}

			// Check if tab is currently loading
			if (loadingTabs[tabId]) {
				return;
			}

			loadingTabs[tabId] = true;

			var $container = $('#tab-' + tabId);

			// Show loading skeleton
			showLoadingSkeleton(tabId);
			$container.show();

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'ezd_load_analytics_tab',
					tab_id: tabId,
					nonce: nonce
				},
				success: function(response) {
					if (response.success && response.data && response.data.content) {
						// Inject the HTML content
						$container.html(response.data.content);
						$container.data('loaded', 'true');
						loadedTabs[tabId] = true;

						// Execute any inline scripts in the content
						executeInlineScripts($container);

						// Trigger resize event to fix charts that were initialized while container had no dimensions
						// ApexCharts and similar libraries need this to recalculate chart dimensions
						setTimeout(function() {
							window.dispatchEvent(new Event('resize'));
						}, 100);

						if (typeof callback === 'function') {
							callback(true);
						}
					} else {
						$container.html(
							'<div class="ezd-tab-error">' +
								'<span class="dashicons dashicons-warning"></span>' +
								'<p><?php esc_html_e( 'Failed to load content. Please try again.', 'eazydocs-pro' ); ?></p>' +
								'<button class="button button-secondary ezd-retry-load" data-tab="' + tabId + '">' +
									'<?php esc_html_e( 'Retry', 'eazydocs-pro' ); ?>' +
								'</button>' +
							'</div>'
						);
						if (typeof callback === 'function') {
							callback(false);
						}
					}
					delete loadingTabs[tabId];
				},
				error: function(xhr, status, error) {
					console.error('Tab load error:', error);
					$container.html(
						'<div class="ezd-tab-error">' +
							'<span class="dashicons dashicons-warning"></span>' +
							'<p><?php esc_html_e( 'An error occurred while loading. Please try again.', 'eazydocs-pro' ); ?></p>' +
							'<button class="button button-secondary ezd-retry-load" data-tab="' + tabId + '">' +
								'<?php esc_html_e( 'Retry', 'eazydocs-pro' ); ?>' +
							'</button>' +
						'</div>'
					);
					delete loadingTabs[tabId];
					if (typeof callback === 'function') {
						callback(false);
					}
				}
			});
		}

		/**
		 * Execute inline scripts after Ajax content injection
		 */
		function executeInlineScripts($container) {
			$container.find('script').each(function() {
				var script = document.createElement('script');
				if (this.src) {
					script.src = this.src;
				} else {
					script.textContent = this.textContent;
				}
				document.head.appendChild(script);
			});
		}

		/**
		 * Activate a tab by its data-rel value
		 */
		function activateTab(tabId) {
			// Remove active classes from all nav items
			$('.easydocs-navitem').removeClass('active');
			
			// Find and activate the correct nav item
			var $navItem = $('.easydocs-navitem[data-rel="' + tabId + '"]');
			if ($navItem.length) {
				$navItem.addClass('active');
			}
			
			// Hide all tab wrappers
			$('.easydocs-tab-wrapper').hide();

			// Load and show the target tab
			var $targetTab = $('#tab-' + tabId);
			
			if (!loadedTabs[tabId]) {
				// Load tab content via Ajax
				loadTabContent(tabId, function(success) {
					if (success) {
						$targetTab.show();
					}
				});
			} else {
				$targetTab.show();
			}
		}

		// Handle retry button clicks
		$(document).on('click', '.ezd-retry-load', function(e) {
			e.preventDefault();
			var tabId = $(this).data('tab');
			delete loadedTabs[tabId];
			loadTabContent(tabId);
		});

		// Check for URL 'more_state' parameter first (used by Dashboard links)
		var urlParams = new URLSearchParams(window.location.search);
		var moreState = urlParams.get('more_state');
		
		if (moreState && $('.easydocs-navitem[data-rel="' + moreState + '"]').length) {
			// Activate the tab from more_state parameter
			activateTab(moreState);
		} else {
			// Check for URL hash on page load
			var hash = window.location.hash.replace('#', '');
			if (hash && $('.easydocs-navitem[data-rel="' + hash + '"]').length) {
				// Activate the tab from URL hash
				activateTab(hash);
			} else {
				// Default to overview - ensure it's shown
				$('#tab-analytics-overview').show();
			}
		}

		// Handle sidebar navigation clicks
		$('.easydocs-navitem').on('click', function() {
			var target = $(this).data('rel');
			if (target) {
				activateTab(target);
				// Update URL hash without scrolling
				history.pushState(null, null, '#' + target);
			}
		});

		// Handle browser back/forward navigation
		$(window).on('popstate', function() {
			var hash = window.location.hash.replace('#', '');
			if (hash && $('.easydocs-navitem[data-rel="' + hash + '"]').length) {
				activateTab(hash);
			} else {
				activateTab('analytics-overview');
			}
		});

		// Handle internal tab navigation links (e.g., from overview to other tabs)
		$(document).on('click', 'a[href^="#analytics-"]', function(e) {
			var href = $(this).attr('href');
			var tabId = href.replace('#', '');
			if ($('.easydocs-navitem[data-rel="' + tabId + '"]').length) {
				e.preventDefault();
				activateTab(tabId);
				history.pushState(null, null, href);
			}
		});

		// Preload adjacent tabs in the background after initial load
		setTimeout(function() {
			var tabs = ['analytics-views', 'analytics-feedback'];
			tabs.forEach(function(tabId) {
				if (!loadedTabs[tabId]) {
					// Preload silently without showing
					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'ezd_load_analytics_tab',
							tab_id: tabId,
							nonce: nonce
						},
						success: function(response) {
							if (response.success && response.data && response.data.content) {
								var $container = $('#tab-' + tabId);
								$container.html(response.data.content);
								$container.data('loaded', 'true');
								loadedTabs[tabId] = true;
								executeInlineScripts($container);
							}
						}
					});
				}
			});
		}, 2000); // Start preloading after 2 seconds
	});
</script>