<?php
/**
 * EazyDocs Grid Block - Light Style Template
 *
 * @package EazyDocs Pro
 */

// Extract attributes with defaults.
$ppp_column      = $attributes['col'] ?? 4;
$isFeaturedImage = $attributes['isFeaturedImage'] ?? '';
$docs_layout     = $attributes['docs_layout'] ?? 'grid';
$card_style      = $attributes['cardStyle'] ?? 'elevated';
$enable_hover    = $attributes['enableHoverAnimation'] ?? true;
$is_btn_show     = ezd_get_opt( 'docs-view-all-btn' );

// Build layout classes.
$layout_classes = array( 'light-style' );
if ( 'grid' === $docs_layout ) {
	$layout_classes[] = 'ezd-grid';
	$layout_classes[] = 'ezd-column-' . $ppp_column;
} else {
	$layout_classes[] = 'ezd-masonry-wrap';
	$layout_classes[] = 'ezd-masonry-col-' . $ppp_column;
}

// Build card classes.
$card_classes   = array( 'categories_guide_item', 'box-item', 'wow', 'fadeInUp', 'single-doc-layout-one' );
$card_classes[] = 'card-style-' . $card_style;
if ( $enable_hover ) {
	$card_classes[] = 'hover-animation-enabled';
}

// Query sections.
$sections = get_children(
	array(
		'post_type'      => 'docs',
		'post_status'    => is_user_logged_in() ? array( 'publish', 'private' ) : 'publish',
		'orderby'        => $attributes['orderBy'] ?? 'menu_order',
		'posts_per_page' => $attributes['show_docs'] ?? -1,
		'order'          => $attributes['parent_docs_order'] ?? 'desc',
		'post_parent'    => 0,
		'post__not_in'   => $attributes['exclude'] ?? array(),
		'post__in'       => $attributes['include'] ?? array(),
	)
);
?>

<div class="<?php echo esc_attr( implode( ' ', $layout_classes ) ); ?>">
	<?php
	foreach ( $sections as $section ) :
		$doc_items = get_children(
			array(
				'post_parent'    => $section->ID,
				'post_type'      => 'docs',
				'post_status'    => is_user_logged_in() ? array( 'publish', 'private' ) : 'publish',
				'orderby'        => $attributes['orderBy'] ?? 'menu_order',
				'order'          => $attributes['child_docs_order'] ?? 'desc',
				'posts_per_page' => ! empty( $attributes['articlesNumber'] ) ? $attributes['articlesNumber'] : -1,
			)
		);

		$doc_counter = get_pages(
			array(
				'child_of'    => $section->ID,
				'post_status' => is_user_logged_in() ? array( 'publish', 'private' ) : 'publish',
				'post_type'   => 'docs',
			)
		);

		$topic_count = count( $doc_counter );
		?>
		<div class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>" data-wow-delay="0.2s">

			<?php
			if ( $isFeaturedImage ) {
				echo wp_get_attachment_image( get_post_thumbnail_id( $section->ID ), 'full', false, array( 'class' => 'doc-thumbnail' ) );
			}
			?>

			<div class="doc-top ezd-d-flex ezd-align-items-start">
				<a class="doc_tag_title" href="<?php echo esc_url( get_the_permalink( $section->ID ) ); ?>">
					<h4 class="title">
						<?php echo esc_html( get_the_title( $section->ID ) ); ?>
					</h4>

					<?php if ( $attributes['show_topic'] && $topic_count > 0 ) : ?>
						<span class="ezd-badge">
							<?php
							echo esc_html( $topic_count );
							echo ' ';
							echo esc_html( $attributes['topic_label'] ?? __( 'Topics', 'eazydocs-pro' ) );
							?>
						</span>
					<?php endif; ?>
				</a>
			</div>

			<ul class="ezd-list-unstyled tag_list">
				<?php foreach ( $doc_items as $doc_item ) : ?>
					<li>
						<i class="icon_document_alt"></i>
						<a class="ct-content-text" href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>">
							<?php echo wp_kses_post( $doc_item->post_title ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php
			$has_children = $topic_count > 0;

			if ( ( ! $has_children && ! empty( $attributes['readMoreText'] ) && ! empty( $is_btn_show ) ) ||
				( $has_children && ! empty( $attributes['readMoreText'] ) ) ) :
				?>
				<a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>" class="doc_border_btn">
					<?php echo wp_kses_post( $attributes['readMoreText'] ); ?>
					<i class="<?php ezd_arrow(); ?>"></i>
				</a>
			<?php endif; ?>

		</div>
	<?php endforeach; ?>
</div>

<?php if ( $attributes['sectionButton'] && ! empty( $attributes['sectionButtonText'] ) ) : ?>
	<div class="text-center ezd-section-btn-wrap">
		<a href="<?php echo esc_url( $attributes['btnURL'] ); ?>" class="action_btn all_doc_btn wow fadeinUp">
			<?php echo esc_html( $attributes['sectionButtonText'] ); ?>
			<i class="<?php ezd_arrow(); ?>"></i>
		</a>
	</div>
<?php endif;