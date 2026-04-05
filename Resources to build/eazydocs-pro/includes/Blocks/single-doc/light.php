<?php
$ppp_column  	 = $attributes['col'] ?? 4;
$isFeaturedImage = $attributes['isFeaturedImage'] ?? '';
$docs_layout 	 = $attributes['docs_layout'] ?? 'grid';
$layoutType  	 = $docs_layout === "grid" ? 'ezd-grid ezd-column-'.$ppp_column : 'ezd-masonry-wrap ezd-masonry-col-'.$ppp_column;

$sections = get_children( array(
	'post_parent'    => $attributes['docId'] ?? 0,
	'post_type'      => 'docs',
	'post_status'    => is_user_logged_in() ? ['publish', 'private'] : 'publish',
	'orderby'        => $attributes['orderBy'] ?? 'menu_order',
	'posts_per_page' => $attributes['sectionsNumber'],
	'order'          => $attributes['parent_docs_order'],
) );
?>
<div class="light-style <?php echo esc_attr( $layoutType ); ?>">
	<?php
	foreach ( $sections as $section ) :
		$doc_items = get_children( array(
			'post_parent'    => $section->ID,
			'post_type'      => 'docs',
			'post_status'    => is_user_logged_in() ? ['publish', 'private'] : 'publish',
			'orderby'        => $attributes['orderBy'] ?? 'menu_order',
			'order'          => $attributes['child_docs_order'] ?? 'ASC',
			'posts_per_page' => $attributes['articlesNumber'] ?? (! empty( $settings['ppp_doc_items'] ) ? $settings['ppp_doc_items'] : - 1),
		));
		$doc_counter    = get_pages( [
			'child_of'  => $section->ID,
			'post_type' => 'docs',
		]);
		?>
		<div class="categories_guide_item box-item wow fadeInUp single-doc-layout-one">
			
			<?php
			if ( $isFeaturedImage ) {
			echo wp_get_attachment_image( get_post_thumbnail_id( $section->ID ), 'full', false, array( 'class' => 'doc-thumbnail' ) ); 
			}
			?>

			<div class="doc-top ezd-d-flex ezd-align-items-start">
				<a class="doc_tag_title" href="<?php echo esc_url( get_the_permalink( $section->ID ) ); ?>">
					<h4 class="title"> <?php echo esc_html( get_the_title( $section->ID ) ); ?> </h4>					
					<?php
					if ( $attributes['show_topic'] == 1 ) :
						?>
						<span class="ezd-badge">
							<?php 
							echo count( $doc_counter ) > 0 ? count( $doc_counter ) . ' ' : '';
							echo isset( $attributes['topic_label'] ) && ! empty($attributes['topic_label']) ? esc_html( $attributes['topic_label']) : esc_html__( ' Topics', 'eazydocs-pro' );
							?>
						</span>
						<?php
					endif;
					?>					
				</a>
			</div>
			<ul class="ezd-list-unstyled tag_list">
				<?php
				foreach ( $doc_items as $doc_item ) : ?>
					<li>
						<a class="ct-content-text" href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>">
							<?php echo wp_kses_post($doc_item->post_title) ?>
						</a>
					</li>
				<?php
				endforeach;
				?>
			</ul>
			<?php
			if ( !empty($attributes['readMoreText']) ) : ?>
				<a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>" class="doc_border_btn">
					<?php echo wp_kses_post($attributes['readMoreText']) ?>
					<i class="<?php ezd_arrow() ?>"></i>
				</a>
			<?php endif; ?>
		</div>
	<?php
	endforeach;
	?>
</div>

<?php
if ( $attributes['sectionButton'] == 'yes' && !empty( $attributes['sectionButtonText'] ) ) : ?>

    <div class="text-center">
        <a href="<?php echo esc_url( $attributes['btnURL'] ); ?>" class="action_btn all_doc_btn wow fadeinUp">
			<?php echo esc_html( $attributes['sectionButtonText'] ) ?>
            <i class="<?php ezd_arrow() ?>"></i>
        </a>
    </div>

	<?php 
endif;