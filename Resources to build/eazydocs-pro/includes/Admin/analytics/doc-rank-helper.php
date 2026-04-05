<?php
/**
 * Helper function to render a single doc rank item
 *
 * @package EasyDocs\Admin\Analytics
 *
 * @param array  $post Post data array.
 * @param int    $key Index for ranking.
 * @param string $type Type: 'most_helpful', 'least_helpful', 'most_viewed'.
 * @return void
 */
function ezd_render_doc_rank_item( $post, $key, $type = 'most_helpful' ) {
	$positive = isset( $post['positive_time'] ) ? intval( $post['positive_time'] ) : 0;
	$negative = isset( $post['negative_time'] ) ? intval( $post['negative_time'] ) : 0;
	$views    = intval( get_post_meta( $post['post_id'], 'post_views_count', true ) );
	$rank     = $key + 1;

	// Calculate helpfulness percentage.
	$total_votes = $positive + $negative;
	$helpfulness = $total_votes > 0 ? round( ( $positive / $total_votes ) * 100 ) : 0;

	// Get rank class.
	$rank_class = '';
	if ( 1 === $rank ) {
		$rank_class = 'rank-gold';
	} elseif ( 2 === $rank ) {
		$rank_class = 'rank-silver';
	} elseif ( 3 === $rank ) {
		$rank_class = 'rank-bronze';
	}

	// Translators: %d is the number of positive votes.
	$positive_title = $positive ? sprintf( _n( '%d Positive vote', '%d Positive votes', $positive, 'eazydocs-pro' ), number_format_i18n( $positive ) ) : esc_html__( 'No positive votes', 'eazydocs-pro' );

	// Translators: %d is the number of negative votes.
	$negative_title = $negative ? sprintf( _n( '%d Negative vote', '%d Negative votes', $negative, 'eazydocs-pro' ), number_format_i18n( $negative ) ) : esc_html__( 'No negative votes', 'eazydocs-pro' );

	$edit_link = 'javascript:void(0)';
	$target    = '_self';
	if ( current_user_can( 'publish_pages' ) ) {
		$edit_link = $post['post_edit_link'];
		$target    = '_blank';
	}

	// Get parent doc for breadcrumb.
	$parent_title = '';
	$post_obj     = get_post( $post['post_id'] );
	if ( $post_obj && $post_obj->post_parent ) {
		$parent       = get_post( $post_obj->post_parent );
		$parent_title = $parent ? $parent->post_title : '';
	}
	?>
	<li class="doc-rank-item easydocs-accordion-item <?php echo esc_attr( $rank_class ); ?>" 
		data-id="<?php echo esc_attr( $post['post_id'] ); ?>"
		data-positive="<?php echo esc_attr( $positive ); ?>"
		data-negative="<?php echo esc_attr( $negative ); ?>"
		data-views="<?php echo esc_attr( $views ); ?>"
		data-title="<?php echo esc_attr( strtolower( $post['post_title'] ) ); ?>">
		
		<div class="rank-item-wrapper">
			<!-- Rank Badge -->
			<div class="rank-badge">
				<?php if ( $rank <= 3 ) : ?>
					<span class="rank-medal">
						<?php if ( 1 === $rank ) : ?>
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#FFD700"/>
							</svg>
						<?php elseif ( 2 === $rank ) : ?>
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#C0C0C0"/>
							</svg>
						<?php else : ?>
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" fill="#CD7F32"/>
							</svg>
						<?php endif; ?>
					</span>
				<?php else : ?>
					<span class="rank-number"><?php echo esc_html( $rank ); ?></span>
				<?php endif; ?>
			</div>

			<!-- Doc Info -->
			<div class="doc-info">
				<?php if ( $parent_title ) : ?>
					<span class="doc-breadcrumb"><?php echo esc_html( $parent_title ); ?></span>
				<?php endif; ?>
				<h4 class="doc-title">
					<a href="<?php echo esc_url( $edit_link ); ?>" target="<?php echo esc_attr( $target ); ?>">
						<?php echo esc_html( $post['post_title'] ); ?>
					</a>
					<a href="<?php echo esc_url( $post['post_permalink'] ); ?>" 
						target="_blank" 
						class="doc-frontend-link" 
						title="<?php esc_attr_e( 'View on frontend', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-external"></span>
					</a>
				</h4>
			</div>

			<!-- Stats Section -->
			<div class="doc-stats">
				<?php if ( 'most_viewed' === $type ) : ?>
					<div class="stat-item stat-views">
						<span class="dashicons dashicons-visibility"></span>
						<span class="stat-value"><?php echo esc_html( number_format_i18n( $views ) ); ?></span>
						<span class="stat-label"><?php esc_html_e( 'views', 'eazydocs-pro' ); ?></span>
					</div>
				<?php else : ?>
					<div class="stat-item stat-positive" title="<?php echo esc_attr( $positive_title ); ?>">
						<span class="dashicons dashicons-thumbs-up"></span>
						<span class="stat-value"><?php echo esc_html( number_format_i18n( $positive ) ); ?></span>
					</div>
					<div class="stat-item stat-negative" title="<?php echo esc_attr( $negative_title ); ?>">
						<span class="dashicons dashicons-thumbs-down"></span>
						<span class="stat-value"><?php echo esc_html( number_format_i18n( $negative ) ); ?></span>
					</div>
					<?php if ( $total_votes > 0 ) : ?>
						<div class="helpfulness-bar">
							<div class="bar-track">
								<div class="bar-fill <?php echo $helpfulness >= 50 ? 'positive' : 'negative'; ?>" 
									style="width: <?php echo esc_attr( $helpfulness ); ?>%"></div>
							</div>
							<span class="bar-label"><?php echo esc_html( $helpfulness ); ?>%</span>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<!-- Actions -->
			<div class="doc-actions">
				<a href="<?php echo esc_url( $post['post_permalink'] ); ?>" 
					target="_blank" 
					class="action-btn" 
					title="<?php esc_attr_e( 'View doc', 'eazydocs-pro' ); ?>">
					<span class="dashicons dashicons-external"></span>
				</a>
				<?php if ( current_user_can( 'publish_pages' ) ) : ?>
					<a href="<?php echo esc_url( $edit_link ); ?>" 
						target="_blank" 
						class="action-btn" 
						title="<?php esc_attr_e( 'Edit doc', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-edit"></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</li>
	<?php
}

/**
 * Render empty state for doc ranks
 *
 * @param string $type The type of ranking.
 * @return void
 */
function ezd_render_empty_state( $type = 'most_helpful' ) {
	$messages = array(
		'most_helpful'  => array(
			'icon'    => 'dashicons-thumbs-up',
			'title'   => __( 'No Helpful Docs Yet', 'eazydocs-pro' ),
			'message' => __( 'When users rate your docs as helpful, they\'ll appear here ranked by positive votes.', 'eazydocs-pro' ),
		),
		'least_helpful' => array(
			'icon'    => 'dashicons-thumbs-down',
			'title'   => __( 'No Feedback Yet', 'eazydocs-pro' ),
			'message' => __( 'Docs with negative feedback will appear here. This helps you identify content that needs improvement.', 'eazydocs-pro' ),
		),
		'most_viewed'   => array(
			'icon'    => 'dashicons-visibility',
			'title'   => __( 'No Views Recorded', 'eazydocs-pro' ),
			'message' => __( 'View counts will appear here once your docs start getting traffic.', 'eazydocs-pro' ),
		),
	);

	$data = isset( $messages[ $type ] ) ? $messages[ $type ] : $messages['most_helpful'];
	?>
	<div class="doc-ranks-empty-state">
		<div class="empty-icon">
			<span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>"></span>
		</div>
		<h3 class="empty-title"><?php echo esc_html( $data['title'] ); ?></h3>
		<p class="empty-message"><?php echo esc_html( $data['message'] ); ?></p>
	</div>
	<?php
}