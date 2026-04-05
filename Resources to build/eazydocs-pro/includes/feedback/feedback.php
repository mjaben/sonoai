<?php
/**
 * User Feedback Page Template
 *
 * @package eazyDocsPro\feedback
 */

// Include necessary files.
include_once EAZYDOCSPRO_PATH . '/includes/feedback/action.php';

// Get current tab.
$current_tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'doc', 'text' ), true ) ? sanitize_text_field( $_GET['tab'] ) : 'doc';

// Handle search input.
$feedback_search = isset( $_GET['feedback_search'] ) ? sanitize_text_field( $_GET['feedback_search'] ) : '';

// Current status filter.
$current_status = isset( $_GET['status'] ) && $_GET['status'] === 'archive' ? 'archive' : 'open';

// Sorting.
$sort_by    = isset( $_GET['sort'] ) ? sanitize_text_field( $_GET['sort'] ) : 'date_desc';
$orderby    = 'date';
$order      = 'DESC';

if ( $sort_by === 'date_asc' ) {
	$order = 'ASC';
} elseif ( $sort_by === 'title_asc' ) {
	$orderby = 'title';
	$order   = 'ASC';
} elseif ( $sort_by === 'title_desc' ) {
	$orderby = 'title';
	$order   = 'DESC';
}

// Pagination.
$items_per_page = 10;
$paged          = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$offset         = ( $paged - 1 ) * $items_per_page;

// Initialize counters.
$open_count       = 0;
$archive_count    = 0;
$total_count      = 0;
$text_open_count  = 0;
$text_archive_count = 0;

/**
 * ====================================
 * Get counts for both tabs
 * ====================================
 */

// Doc feedback counts.
global $wpdb;
$doc_total   = 0;
$doc_open    = 0;
$doc_archive = 0;

$doc_counts = $wpdb->get_results( "
	SELECT
		pm_status.meta_value as status,
		COUNT(p.ID) as count
	FROM {$wpdb->posts} p
	LEFT JOIN {$wpdb->postmeta} pm_type ON (p.ID = pm_type.post_id AND pm_type.meta_key = 'ezd_feedback_type')
	LEFT JOIN {$wpdb->postmeta} pm_status ON (p.ID = pm_status.post_id AND pm_status.meta_key = 'ezd_feedback_status')
	WHERE p.post_type = 'ezd_feedback'
	AND p.post_status = 'publish'
	AND (
		pm_type.meta_value = 'doc'
		OR pm_type.post_id IS NULL
	)
	GROUP BY pm_status.meta_value
" );

if ( $doc_counts ) {
	foreach ( $doc_counts as $row ) {
		$doc_total += $row->count;
		if ( $row->status === 'false' ) {
			$doc_archive += $row->count;
		} else {
			$doc_open += $row->count;
		}
	}
}

// Text feedback counts.
$text_total = 0;

$text_counts = $wpdb->get_results( "
	SELECT
		pm_archived.meta_value as is_archived,
		COUNT(p.ID) as count
	FROM {$wpdb->posts} p
	LEFT JOIN {$wpdb->postmeta} pm_archived ON (p.ID = pm_archived.post_id AND pm_archived.meta_key = 'ezd_feedback_archived')
	WHERE p.post_type = 'ezd-text-feedback'
	AND p.post_status = 'publish'
	GROUP BY pm_archived.meta_value
" );

if ( $text_counts ) {
	foreach ( $text_counts as $row ) {
		$text_total += $row->count;
		if ( $row->is_archived === 'true' ) {
			$text_archive_count += $row->count;
		} else {
			$text_open_count += $row->count;
		}
	}
}

// Set current counts based on tab.
if ( $current_tab === 'text' ) {
	$open_count    = $text_open_count;
	$archive_count = $text_archive_count;
	$total_count   = $text_total;
} else {
	$open_count    = $doc_open;
	$archive_count = $doc_archive;
	$total_count   = $doc_total;
}

/**
 * ====================================
 * Query for current tab with pagination
 * ====================================
 */
if ( $current_tab === 'text' ) {
	$args = array(
		'post_type'      => 'ezd-text-feedback',
		'posts_per_page' => -1,
		'orderby'        => $orderby,
		'order'          => $order,
		'meta_query'     => array(),
	);

	if ( $feedback_search ) {
		$args['s'] = $feedback_search;
	}

	$query = new WP_Query( $args );
} else {
	$args = array(
		'post_type'      => 'ezd_feedback',
		'posts_per_page' => -1,
		'orderby'        => $orderby,
		'order'          => $order,
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => 'ezd_feedback_type',
				'value'   => 'doc',
				'compare' => '=',
			),
			array(
				'key'     => 'ezd_feedback_type',
				'compare' => 'NOT EXISTS',
			),
		),
	);

	if ( $feedback_search ) {
		$args['s'] = $feedback_search;
	}

	$query = new WP_Query( $args );
}

// Filter and paginate results.
$filtered_posts  = array();
$filtered_ids    = array();
$display_count   = 0;

if ( $query->have_posts() ) {
	while ( $query->have_posts() ) {
		$query->the_post();
		$post_id = get_the_ID();

		if ( $current_tab === 'text' ) {
			$is_archived   = get_post_meta( $post_id, 'ezd_feedback_archived', true );
			$matches_status = ( $current_status === 'archive' && $is_archived === 'true' ) ||
							  ( $current_status === 'open' && $is_archived !== 'true' );
		} else {
			$feedback_status = get_post_meta( $post_id, 'ezd_feedback_status', true );
			$matches_status  = ( $current_status === 'archive' && $feedback_status === 'false' ) ||
							   ( $current_status === 'open' && $feedback_status !== 'false' );
		}

		if ( $matches_status ) {
			$filtered_posts[] = $post_id;
			$filtered_ids[]   = $post_id;
			$display_count++;
		}
	}
	wp_reset_postdata();
}

// Calculate pagination.
$total_filtered = count( $filtered_posts );
$total_pages    = ceil( $total_filtered / $items_per_page );
$paged_posts    = array_slice( $filtered_posts, $offset, $items_per_page );

// Calculate stats.
$total_all_feedback = $doc_total + $text_total;
$total_open         = $doc_open + $text_open_count;
$total_archived     = $doc_archive + $text_archive_count;
$response_rate      = $total_all_feedback > 0 ? round( ( $total_archived / $total_all_feedback ) * 100 ) : 0;
?>

<div class="wrap ezd-feedback-page">
	<!-- Page Header -->
	<div class="ezd-feedback-header">
		<div class="ezd-feedback-header-content">
			<div class="ezd-feedback-header-left">
				<h1 class="ezd-feedback-title">
					<span class="dashicons dashicons-feedback"></span>
					<?php esc_html_e( 'User Feedback', 'eazydocs-pro' ); ?>
				</h1>
				<p class="ezd-feedback-subtitle"><?php esc_html_e( 'Manage and respond to user feedback from your documentation.', 'eazydocs-pro' ); ?></p>
			</div>
			<div class="ezd-feedback-header-right">
				<button type="button" class="ezd-btn ezd-btn-outline ezd-export-btn" id="ezd-export-feedback">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export CSV', 'eazydocs-pro' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Stats Cards -->
	<div class="ezd-feedback-stats">
		<div class="ezd-stat-card ezd-stat-total">
			<div class="ezd-stat-icon">
				<span class="dashicons dashicons-format-chat"></span>
			</div>
			<div class="ezd-stat-content">
				<span class="ezd-stat-value"><?php echo esc_html( $total_all_feedback ); ?></span>
				<span class="ezd-stat-label"><?php esc_html_e( 'Total Feedback', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="ezd-stat-card ezd-stat-open">
			<div class="ezd-stat-icon">
				<span class="dashicons dashicons-visibility"></span>
			</div>
			<div class="ezd-stat-content">
				<span class="ezd-stat-value"><?php echo esc_html( $total_open ); ?></span>
				<span class="ezd-stat-label"><?php esc_html_e( 'Open', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="ezd-stat-card ezd-stat-archived">
			<div class="ezd-stat-icon">
				<span class="dashicons dashicons-archive"></span>
			</div>
			<div class="ezd-stat-content">
				<span class="ezd-stat-value"><?php echo esc_html( $total_archived ); ?></span>
				<span class="ezd-stat-label"><?php esc_html_e( 'Archived', 'eazydocs-pro' ); ?></span>
			</div>
		</div>
		<div class="ezd-stat-card ezd-stat-rate">
			<div class="ezd-stat-icon">
				<span class="dashicons dashicons-chart-pie"></span>
			</div>
			<div class="ezd-stat-content">
				<span class="ezd-stat-value"><?php echo esc_html( $response_rate ); ?>%</span>
				<span class="ezd-stat-label"><?php esc_html_e( 'Response Rate', 'eazydocs-pro' ); ?></span>
				<div class="ezd-stat-progress">
					<div class="ezd-stat-progress-bar" style="width: <?php echo esc_attr( $response_rate ); ?>%"></div>
				</div>
			</div>
		</div>
	</div>

	<!-- Main Content Card -->
	<div class="ezd-feedback-card">
		<!-- Tabs -->
		<div class="ezd-feedback-tabs">
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'doc', remove_query_arg( array( 'status', 'feedback_search', 'paged', 'sort' ) ) ) ); ?>" 
			   class="ezd-feedback-tab <?php echo $current_tab === 'doc' ? 'is-active' : ''; ?>">
				<span class="dashicons dashicons-media-document"></span>
				<?php esc_html_e( 'Doc Feedback', 'eazydocs-pro' ); ?>
				<span class="ezd-tab-badge"><?php echo esc_html( $doc_total ); ?></span>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'text', remove_query_arg( array( 'status', 'feedback_search', 'paged', 'sort' ) ) ) ); ?>" 
			   class="ezd-feedback-tab <?php echo $current_tab === 'text' ? 'is-active' : ''; ?>">
				<span class="dashicons dashicons-editor-quote"></span>
				<?php esc_html_e( 'Text Feedback', 'eazydocs-pro' ); ?>
				<span class="ezd-tab-badge"><?php echo esc_html( $text_total ); ?></span>
			</a>
		</div>

		<!-- Toolbar -->
		<div class="ezd-feedback-toolbar">
			<div class="ezd-toolbar-left">
				<!-- Status Filters -->
				<div class="ezd-status-filters">
					<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $current_tab, 'status' => 'open' ), remove_query_arg( array( 'paged', 'feedback_search' ) ) ) ); ?>" 
					   class="ezd-status-pill <?php echo $current_status === 'open' ? 'is-active' : ''; ?>">
						<span class="ezd-status-dot ezd-dot-open"></span>
						<?php esc_html_e( 'Open', 'eazydocs-pro' ); ?>
						<span class="ezd-pill-count"><?php echo esc_html( $open_count ); ?></span>
					</a>
					<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $current_tab, 'status' => 'archive' ), remove_query_arg( array( 'paged', 'feedback_search' ) ) ) ); ?>" 
					   class="ezd-status-pill <?php echo $current_status === 'archive' ? 'is-active' : ''; ?>">
						<span class="ezd-status-dot ezd-dot-archive"></span>
						<?php esc_html_e( 'Archived', 'eazydocs-pro' ); ?>
						<span class="ezd-pill-count"><?php echo esc_html( $archive_count ); ?></span>
					</a>
				</div>

				<!-- Bulk Actions -->
				<div class="ezd-bulk-actions" style="display: none;">
					<span class="ezd-selected-count">
						<span class="count">0</span> <?php esc_html_e( 'selected', 'eazydocs-pro' ); ?>
					</span>
					<?php if ( $current_status !== 'archive' ) : ?>
					<button type="button" class="ezd-btn ezd-btn-sm ezd-bulk-archive" title="<?php esc_attr_e( 'Archive Selected', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-archive"></span>
					</button>
					<?php else : ?>
					<button type="button" class="ezd-btn ezd-btn-sm ezd-bulk-unarchive" title="<?php esc_attr_e( 'Unarchive Selected', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-visibility"></span>
					</button>
					<?php endif; ?>
					<button type="button" class="ezd-btn ezd-btn-sm ezd-btn-danger ezd-bulk-delete" title="<?php esc_attr_e( 'Delete Selected', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			</div>

			<div class="ezd-toolbar-right">
				<!-- Sort -->
				<div class="ezd-sort-dropdown">
					<select id="ezd-feedback-sort" class="ezd-select">
						<option value="date_desc" <?php selected( $sort_by, 'date_desc' ); ?>><?php esc_html_e( 'Newest First', 'eazydocs-pro' ); ?></option>
						<option value="date_asc" <?php selected( $sort_by, 'date_asc' ); ?>><?php esc_html_e( 'Oldest First', 'eazydocs-pro' ); ?></option>
						<option value="title_asc" <?php selected( $sort_by, 'title_asc' ); ?>><?php esc_html_e( 'Title A-Z', 'eazydocs-pro' ); ?></option>
						<option value="title_desc" <?php selected( $sort_by, 'title_desc' ); ?>><?php esc_html_e( 'Title Z-A', 'eazydocs-pro' ); ?></option>
					</select>
				</div>

				<!-- Search -->
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ezd-search-form">
					<input type="hidden" name="page" value="ezd-user-feedback">
					<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
					<input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>">
					<div class="ezd-search-input-wrap">
						<span class="dashicons dashicons-search"></span>
						<input type="search" 
							   name="feedback_search" 
							   value="<?php echo esc_attr( $feedback_search ); ?>" 
							   placeholder="<?php esc_attr_e( 'Search feedback...', 'eazydocs-pro' ); ?>"
							   class="ezd-search-input">
						<?php if ( $feedback_search ) : ?>
							<a href="<?php echo esc_url( remove_query_arg( 'feedback_search' ) ); ?>" class="ezd-search-clear">
								<span class="dashicons dashicons-no-alt"></span>
							</a>
						<?php endif; ?>
					</div>
				</form>
			</div>
		</div>

		<!-- Feedback List -->
		<div class="ezd-feedback-list">
			<?php if ( ! empty( $paged_posts ) ) : ?>
				<!-- Select All Header -->
				<div class="ezd-feedback-list-header">
					<label class="ezd-checkbox-wrap">
						<input type="checkbox" id="ezd-select-all" class="ezd-checkbox">
						<span class="ezd-checkbox-label"><?php esc_html_e( 'Select All', 'eazydocs-pro' ); ?></span>
					</label>
					<span class="ezd-list-info">
						<?php
						printf(
							/* translators: 1: start number, 2: end number, 3: total count */
							esc_html__( 'Showing %1$d-%2$d of %3$d', 'eazydocs-pro' ),
							( $offset + 1 ),
							min( $offset + $items_per_page, $total_filtered ),
							$total_filtered
						);
						?>
					</span>
				</div>

				<?php
				foreach ( $paged_posts as $post_id ) :
					if ( $current_tab === 'text' ) {
						do_action( 'ezd_text_feedback_loop_enhanced', $post_id, $current_status );
					} else {
						do_action( 'ezd_feedback_loop_enhanced', $post_id, $current_status );
					}
				endforeach;
				?>

				<!-- Pagination -->
				<?php if ( $total_pages > 1 ) : ?>
					<div class="ezd-feedback-pagination">
						<div class="ezd-pagination-info">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages */
								esc_html__( 'Page %1$d of %2$d', 'eazydocs-pro' ),
								$paged,
								$total_pages
							);
							?>
						</div>
						<div class="ezd-pagination-links">
							<?php if ( $paged > 1 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>" class="ezd-page-link ezd-page-first" title="<?php esc_attr_e( 'First Page', 'eazydocs-pro' ); ?>">
									<span class="dashicons dashicons-controls-skipback"></span>
								</a>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" class="ezd-page-link ezd-page-prev" title="<?php esc_attr_e( 'Previous Page', 'eazydocs-pro' ); ?>">
									<span class="dashicons dashicons-arrow-left-alt2"></span>
								</a>
							<?php endif; ?>

							<?php
							$start_page = max( 1, $paged - 2 );
							$end_page   = min( $total_pages, $paged + 2 );

							for ( $i = $start_page; $i <= $end_page; $i++ ) :
							?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>" 
								   class="ezd-page-link ezd-page-num <?php echo $i === $paged ? 'is-current' : ''; ?>">
									<?php echo esc_html( $i ); ?>
								</a>
							<?php endfor; ?>

							<?php if ( $paged < $total_pages ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" class="ezd-page-link ezd-page-next" title="<?php esc_attr_e( 'Next Page', 'eazydocs-pro' ); ?>">
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</a>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>" class="ezd-page-link ezd-page-last" title="<?php esc_attr_e( 'Last Page', 'eazydocs-pro' ); ?>">
									<span class="dashicons dashicons-controls-skipforward"></span>
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<!-- Empty State -->
				<div class="ezd-feedback-empty">
					<div class="ezd-empty-icon">
						<?php if ( $feedback_search ) : ?>
							<span class="dashicons dashicons-search"></span>
						<?php else : ?>
							<span class="dashicons dashicons-format-chat"></span>
						<?php endif; ?>
					</div>
					<h3 class="ezd-empty-title">
						<?php
						if ( $feedback_search ) {
							esc_html_e( 'No results found', 'eazydocs-pro' );
						} elseif ( $current_status === 'archive' ) {
							esc_html_e( 'No archived feedback', 'eazydocs-pro' );
						} else {
							esc_html_e( 'No open feedback', 'eazydocs-pro' );
						}
						?>
					</h3>
					<p class="ezd-empty-text">
						<?php
						if ( $feedback_search ) {
							printf(
								/* translators: %s: search term */
								esc_html__( 'No feedback matching "%s" was found. Try a different search term.', 'eazydocs-pro' ),
								esc_html( $feedback_search )
							);
						} elseif ( $current_status === 'archive' ) {
							esc_html_e( 'Archived feedback will appear here once you archive open feedback items.', 'eazydocs-pro' );
						} else {
							esc_html_e( 'When users submit feedback on your documentation, it will appear here.', 'eazydocs-pro' );
						}
						?>
					</p>
					<?php if ( $feedback_search ) : ?>
						<a href="<?php echo esc_url( remove_query_arg( 'feedback_search' ) ); ?>" class="ezd-btn ezd-btn-primary">
							<?php esc_html_e( 'Clear Search', 'eazydocs-pro' ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- Reply Modal -->
<div id="ezd-reply-modal" class="ezd-modal" style="display: none;">
	<div class="ezd-modal-overlay"></div>
	<div class="ezd-modal-content">
		<div class="ezd-modal-header">
			<h3><?php esc_html_e( 'Reply to Feedback', 'eazydocs-pro' ); ?></h3>
			<button type="button" class="ezd-modal-close">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="ezd-modal-body">
			<div class="ezd-reply-to-info">
				<strong><?php esc_html_e( 'Replying to:', 'eazydocs-pro' ); ?></strong>
				<span class="ezd-reply-email"></span>
			</div>
			<textarea id="ezd-reply-message" class="ezd-textarea" rows="6" placeholder="<?php esc_attr_e( 'Type your reply message...', 'eazydocs-pro' ); ?>"></textarea>
		</div>
		<div class="ezd-modal-footer">
			<button type="button" class="ezd-btn ezd-btn-outline ezd-modal-cancel"><?php esc_html_e( 'Cancel', 'eazydocs-pro' ); ?></button>
			<button type="button" class="ezd-btn ezd-btn-primary ezd-send-reply">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Send Reply', 'eazydocs-pro' ); ?>
			</button>
		</div>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Sort change handler
	$('#ezd-feedback-sort').on('change', function() {
		var url = new URL(window.location.href);
		url.searchParams.set('sort', $(this).val());
		url.searchParams.delete('paged');
		window.location.href = url.toString();
	});

	// Select all checkbox
	$('#ezd-select-all').on('change', function() {
		var isChecked = $(this).is(':checked');
		$('.ezd-feedback-item-checkbox').prop('checked', isChecked);
		updateBulkActions();
	});

	// Individual checkbox
	$(document).on('change', '.ezd-feedback-item-checkbox', function() {
		updateBulkActions();
	});

	function updateBulkActions() {
		var checkedCount = $('.ezd-feedback-item-checkbox:checked').length;
		if (checkedCount > 0) {
			$('.ezd-bulk-actions').show();
			$('.ezd-bulk-actions .count').text(checkedCount);
		} else {
			$('.ezd-bulk-actions').hide();
		}
	}

	// Bulk Archive
	$('.ezd-bulk-archive').on('click', function(e) {
		e.preventDefault();
		var ids = [];
		$('.ezd-feedback-item-checkbox:checked').each(function() {
			ids.push($(this).val());
		});

		if (ids.length === 0) return;

		if (confirm('<?php esc_html_e( 'Are you sure you want to archive the selected feedback?', 'eazydocs-pro' ); ?>')) {
			var $btn = $(this);
			$btn.addClass('updating-message');
			
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ezd_bulk_feedback_action',

					bulk_action: 'archive',
					ids: ids,
					nonce: '<?php echo wp_create_nonce( 'ezd_bulk_feedback_nonce' ); ?>'
				},
				success: function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert(response.data.message || '<?php esc_html_e( 'An error occurred.', 'eazydocs-pro' ); ?>');
						$btn.removeClass('updating-message');
					}
				},
				error: function() {
					alert('<?php esc_html_e( 'An error occurred.', 'eazydocs-pro' ); ?>');
					$btn.removeClass('updating-message');
				}
			});
		}
	});

	// Bulk Unarchive
	$('.ezd-bulk-unarchive').on('click', function(e) {
		e.preventDefault();
		var ids = [];
		$('.ezd-feedback-item-checkbox:checked').each(function() {
			ids.push($(this).val());
		});

		if (ids.length === 0) return;

		if (confirm('<?php esc_html_e( 'Are you sure you want to unarchive the selected feedback?', 'eazydocs-pro' ); ?>')) {
			var $btn = $(this);
			$btn.addClass('updating-message');
			
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ezd_bulk_feedback_action',
					bulk_action: 'unarchive',
					ids: ids,
					nonce: '<?php echo wp_create_nonce( 'ezd_bulk_feedback_nonce' ); ?>'
				},
				success: function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert(response.data.message || '<?php esc_html_e( 'An error occurred.', 'eazydocs-pro' ); ?>');
						$btn.removeClass('updating-message');
					}
				},
				error: function() {
					alert('<?php esc_html_e( 'An error occurred.', 'eazydocs-pro' ); ?>');
					$btn.removeClass('updating-message');
				}
			});
		}
	});

	// Bulk Delete
	$('.ezd-bulk-delete').on('click', function(e) {
		e.preventDefault();
		var ids = [];
		$('.ezd-feedback-item-checkbox:checked').each(function() {
			ids.push($(this).val());
		});

		if (ids.length === 0) return;

		if (confirm('<?php esc_html_e( 'Are you sure you want to delete the selected feedback? This action cannot be undone.', 'eazydocs-pro' ); ?>')) {
			var $btn = $(this);
			$btn.addClass('updating-message');
			
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ezd_bulk_feedback_action',
					bulk_action: 'delete',
					ids: ids,
					nonce: '<?php echo wp_create_nonce( 'ezd_bulk_feedback_nonce' ); ?>'
				},
				success: function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert(response.data.message || '<?php esc_html_e( 'An error occurred.', 'eazydocs-pro' ); ?>');
						$btn.removeClass('updating-message');
					}
				},
				error: function() {
					alert('<?php esc_html_e( 'An error occurred.', 'eazydocs-pro' ); ?>');
					$btn.removeClass('updating-message');
				}
			});
		}
	});

	// Copy email
	$(document).on('click', '.ezd-copy-email', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var email = $btn.data('email');
		
		// Fallback for older browsers or when clipboard API is not available (e.g. non-HTTPS)
		if (!navigator.clipboard) {
			var textArea = document.createElement("textarea");
			textArea.value = email;
			
			// Avoid scrolling to bottom
			textArea.style.top = "0";
			textArea.style.left = "0";
			textArea.style.position = "fixed";

			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();

			try {
				var successful = document.execCommand('copy');
				if (successful) {
					$btn.addClass('copied');
					setTimeout(function() {
						$btn.removeClass('copied');
					}, 2000);
				}
			} catch (err) {
				console.error('Fallback: Oops, unable to copy', err);
			}

			document.body.removeChild(textArea);
			return;
		}
		
		navigator.clipboard.writeText(email).then(function() {
			$btn.addClass('copied');
			setTimeout(function() {
				$btn.removeClass('copied');
			}, 2000);
		}).catch(function(err) {
			console.error('Async: Could not copy text: ', err);
		});
	});

	// Reply modal
	$(document).on('click', '.ezd-reply-btn', function(e) {
		e.preventDefault();
		var email = $(this).data('email');
		$('.ezd-reply-email').text(email);
		$('#ezd-reply-modal').data('email', email).fadeIn(200);
	});

	$('.ezd-modal-close, .ezd-modal-cancel, .ezd-modal-overlay').on('click', function() {
		$('#ezd-reply-modal').fadeOut(200);
	});

	$('.ezd-send-reply').on('click', function() {
		var email = $('#ezd-reply-modal').data('email');
		var message = $('#ezd-reply-message').val();
		if (message.trim()) {
			window.location.href = 'mailto:' + email + '?body=' + encodeURIComponent(message);
			$('#ezd-reply-modal').fadeOut(200);
			$('#ezd-reply-message').val('');
		}
	});

	// Export CSV
	$('#ezd-export-feedback').on('click', function() {
		var currentTab = '<?php echo esc_js( $current_tab ); ?>';
		var currentStatus = '<?php echo esc_js( $current_status ); ?>';
		
		// Collect visible feedback data
		var csvData = [];
		csvData.push(['Subject/Author', 'Email', 'Content', 'Date', 'Doc Title', 'Status']);
		
		$('.ezd-feedback-item').each(function() {
			var $item = $(this);
			var subject = $item.find('.ezd-fi-title a').text().trim() || $item.find('.ezd-fi-author').text().trim();
			var email = $item.find('.ezd-fi-email-link').text().trim();
			var content = $item.find('.ezd-fi-content-text').text().trim().substring(0, 200);
			var date = $item.find('.ezd-fi-date').text().trim();
			var docTitle = $item.find('.ezd-fi-doc-link').text().trim();
			var status = currentStatus;
			
			csvData.push([subject, email, content, date, docTitle, status]);
		});
		
		// Generate CSV
		var csv = csvData.map(function(row) {
			return row.map(function(cell) {
				return '"' + String(cell).replace(/"/g, '""') + '"';
			}).join(',');
		}).join('\n');
		
		// Download
		var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'feedback-export-' + new Date().toISOString().split('T')[0] + '.csv';
		link.click();
	});

	// Star/important toggle
	$(document).on('click', '.ezd-star-btn', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var $item = $btn.closest('.ezd-feedback-item');
		var postId = $item.data('id');
		var isStarred = $btn.hasClass('is-starred');
		
		$btn.toggleClass('is-starred');
		var $icon = $btn.find('.dashicons');
		if ($btn.hasClass('is-starred')) {
			$icon.removeClass('dashicons-star-empty').addClass('dashicons-star-filled');
		} else {
			$icon.removeClass('dashicons-star-filled').addClass('dashicons-star-empty');
		}

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'ezd_toggle_feedback_star',
				post_id: postId,
				is_starred: !isStarred,
				nonce: '<?php echo wp_create_nonce( 'ezd_toggle_star_nonce' ); ?>'
			}
		});
	});

	// Quick expand content
	$(document).on('click', '.ezd-read-more-btn', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var $content = $btn.siblings('.ezd-fi-content-wrap').find('.ezd-fi-content-text');
		$content.toggleClass('is-expanded');
		
		if ($content.hasClass('is-expanded')) {
			$btn.text('<?php esc_html_e( 'Show Less', 'eazydocs-pro' ); ?>');
		} else {
			$btn.text('<?php esc_html_e( 'Read More', 'eazydocs-pro' ); ?>');
		}
	});
});
</script>