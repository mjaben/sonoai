<?php
$ppp_column  = $attributes['col'] ?? 4;
$docs_layout = $attributes['docs_layout'] ?? 'grid';
$layoutType  = $docs_layout === "grid" ? 'ezd-grid ezd-column-'.$ppp_column : 'ezd-masonry-wrap ezd-masonry-col-'.$ppp_column;
$title_tag 	 = $attributes['titleTag'] ?? 'h2';
$isFeaturedImage = $attributes['isFeaturedImage'] ?? '';

$sections = get_children( array(
	'post_parent'    => $attributes['docId'] ?? 0,
	'post_type'      => 'docs',
	'post_status'    => ['publish', 'private'],
	'orderby'        => $attributes['orderBy'] ?? 'menu_order',
	'posts_per_page' => $attributes['sectionsNumber'],
	'order'          => $attributes['parent_docs_order'],
) );
?>
<section class="recommended_topic_area">
	<div class="recommended_topic_inner">

		<img src="<?php echo esc_attr( $attributes['imageUrl'] ?? EAZYDOCS_IMG . '/widgets/docbg-shap.png' ); ?>" class="doc_shap_one" alt="curve shape">

		<div class="doc_round one" data-parallax='{"x": -80, "y": -100, "rotateY":0}'></div>
		<div class="doc_round two" data-parallax='{"x": -10, "y": 70, "rotateY":0}'></div>
		
		<?php 
		if ( ! empty( $attributes['titleText'] ) ) : 
			$title_tag = $attributes['titleTag'] ?? 'h2';

			// Sanitize the tag
			$allowed_tags = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];
			$title_tag = in_array( strtolower( $title_tag ), $allowed_tags, true ) ? strtolower( $title_tag ) : 'h2';
			?>
			<div class="doc_title text-center">
				<?php
				printf(
					'<%1$s class="title" data-animation="wow fadeInUp" data-wow-delay="0.2s">%2$s</%1$s>',
					esc_attr( $title_tag ),
					wp_kses_post( nl2br( esc_html( $attributes['titleText'] ) ) )
				);
				?>
			</div>
			<?php 
		endif; 
		?>

		<div class="ezd-container">
			<div class="<?php echo esc_attr( $layoutType ); ?>">
				<?php
				$delay = 0.2;
				foreach ( $sections as $section ) :
					$doc_items = get_children( array(
						'post_parent'    => $section->ID,
						'post_type'      => 'docs',
						'post_status'    => is_user_logged_in() ? ['publish', 'private'] : 'publish',
						'orderby'        => $attributes['orderBy'] ?? 'menu_order',
						'order'          => $attributes['child_docs_order'] ?? 'ASC',
						'posts_per_page' => $attributes['articlesNumber'] ?? (! empty( $settings['ppp_doc_items'] ) ? $settings['ppp_doc_items'] : - 1),
					) );
					?>
					<div class="recommended_item box-item wow fadeInUp" data-wow-delay="<?php echo esc_attr( $delay ) ?>s">
						<?php
						if ( $isFeaturedImage && has_post_thumbnail( $section->ID ) ) {
							echo get_the_post_thumbnail( $section->ID, 'full' );
						}

						if ( ! empty( $section->post_title ) ) { ?>
							<a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>">
								<h3 class="ct-heading-text"> <?php echo wp_kses_post( $section->post_title ); ?> </h3>
							</a>
							<?php
						}

						if ( ! empty( $doc_items ) ) : ?>
							<ul class="ezd-list-unstyled">
								<?php
								foreach ( $doc_items as $doc_item ) :
									?>
									<li>
										<a class="ct-content-text" href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>">
											<?php echo wp_kses_post( $doc_item->post_title ) ?>
										</a>
									</li>
								<?php
								endforeach;
								?>
							</ul>
							<?php
						endif;
						?>

					</div>
					<?php
					$delay = $delay + 0.1;
				endforeach;
				?>
			</div>
		</div>
		 
		<?php
		if ( $attributes['sectionButton']  && ! empty(  $attributes['readMoreText'] ) ) : ?>
			<div class="text-center wow fadeInUp" data-wow-delay="0.2s">
				<a href="<?php echo esc_url( $attributes['btnURL'] ); ?>" class="question_text">
					<?php echo wp_kses_post( $attributes['sectionButtonText'] ) ?>
					<i class="arrow_right"></i>
				</a>
			</div>
			<?php
		endif;
		?>
	</div>
</section>