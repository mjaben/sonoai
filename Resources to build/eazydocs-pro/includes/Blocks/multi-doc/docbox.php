<?php
$parent_args = new WP_Query( [
	'post_type'      => 'docs',
	'post_status'    => is_user_logged_in() ? ['publish', 'private'] : 'publish',
	'orderby'        => $attributes['orderBy'] ?? 'menu_order',
	'posts_per_page' => $attributes['show_docs'] ?? -1,
	'order'          => $attributes['parent_docs_order'] ?? 'desc',
	'post_parent'    => 0,
	'post__not_in'   => $attributes['exclude'] ?? [],
    'post__in'       => $attributes['include'] ?? []
] );

$title_tag 		= $attributes['titleTag'];
$parent_title 	= isset($attributes['parentTitle']) ? $attributes['parentTitle'] : '';
$layoutType 	= $attributes['docs_layout'] == "grid" ? "ezd-grid ezd-column-" . $attributes['col'] : "ezd-masonry-wrap ezd-masonry-col-" . $attributes['col'];

if ( $parent_args->have_posts() ) :
	?>
	<div class="<?php echo esc_attr($layoutType); ?>">
		<?php 
		while ( $parent_args->have_posts() ) : $parent_args->the_post();
			$sections = get_children( [
				'post_parent' => get_the_ID(),
				'post_type'   => 'docs',
				'numberposts' => $attributes['sectionsNumber'],
				'post_status' => is_user_logged_in() ? ['publish', 'private'] : 'publish',
				'orderby'     => $attributes['orderBy'] ?? [],
				'order'       => $attributes['parent_docs_order'] ?? []
			] );
			?>
			<div class="docs-box-item docs-single-5-wrap doc-box-style ">
				<h5 class="docs-5-title"> <?php the_title(); ?> </h5>

				<div class="dox5-section-item">
					<?php
					foreach ( $sections as $section ) :
						$doc_items = get_children( array(
							'post_parent'    => $section->ID,
							'post_type'      => 'docs',
							'post_status'    => is_user_logged_in() ? ['publish', 'private'] : 'publish',
							'orderby'        => $attributes['orderBy'] ?? [],
							'order'       	 => $attributes['child_docs_order'] ?? [],
							'posts_per_page' => $attributes['articlesNumber'] ?? ( ! empty( $settings['ppp_doc_items'] ) ? $settings['ppp_doc_items'] : - 1),
						) );
						?>
						<div class="section5-article">
							<div class="section5-section-title">
								<h6> <?php echo wp_kses_post( $section->post_title ); ?> </h6>
							</div>
							<ul class="navbar-nav docs-single5-nav-wrap ezd-list-unstyled">
								<?php
								$doc_count = count($doc_items);
								foreach ( $doc_items as $doc_item ) :
									$doc_count++;
									$li_class = '';
									if( $doc_count % 2 == 0 ){
										$li_class = 'dark_bg';
									}
									?>
									<li>
										<a href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>">
											<?php echo wp_kses_post( $doc_item->post_title ) ?>
										</a>
									</li>
									<?php
								endforeach;
								?>
							</ul>
						</div>
					<?php
					endforeach;
					?>
				</div>
			</div>
			<?php 
		endwhile;
		?>
	</div>
	<?php
endif;