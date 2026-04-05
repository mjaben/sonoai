<?php
$title_tag 		= ! empty( $settings['title_tag'] ) ? $settings['title_tag'] : 'h2';
$ppp_column  	= $attributes['col'] ?? 4;
$docs_layout 	= $attributes['docs_layout'] ?? 'grid';
$layoutType 	= $docs_layout === "grid" ? 'ezd-grid ezd-column-'.$ppp_column : 'ezd-masonry-wrap ezd-masonry-col-'.$ppp_column;
$ppp_column 	= $attributes['col'] ?? 4;
$articlesNumber = $attributes['articlesNumber'] ?? -1;
$show_topic 	= $attributes['show_topic'] ?? 0;

$sections = get_children( array(
	'post_parent'    => $attributes['docId'] ?? 0,
	'post_type'      => 'docs',
	'post_status'    => ['publish', 'private'],
	'orderby'        => $attributes['orderBy'],
	'posts_per_page' => $attributes['sectionsNumber'],
	'order'          => $attributes['parent_docs_order'],
) );
?>

<div class="<?php echo esc_attr( $layoutType ); ?> topic_list_inner box-style">
	<?php
	$delay = 0.2;
	foreach ( $sections as $section ) :
		$doc_items = get_children( array(
			'post_parent'    => $section->ID,
			'post_type'      => 'docs',
			'post_status'    => is_user_logged_in() ? ['publish', 'private'] : 'publish',
			'orderby'        => 'menu_order',
			'order'          => $attributes['child_docs_order'],
			'posts_per_page' => $articlesNumber ?? ( ! empty( $settings['ppp_doc_items'] ) ? $settings['ppp_doc_items'] : -1 ),
		) );

		$all_doc_items = get_children( array(
			'post_parent'    => $section->ID,
			'post_type'      => 'docs',
			'post_status'    => is_user_logged_in() ? ['publish', 'private'] : 'publish',
			'posts_per_page' => -1,
		) );
		?>
        <div class="topic_list_item box-item wow fadeIn" data-wow-delay="0.2s">
			<?php
			if ( ! empty( $section->post_title ) ) :
				?>
                <a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>" class="topic-title">
                    <h4 class="ct-heading-text">
						<?php echo get_the_post_thumbnail( $section->ID, 'full' ); ?>
						<?php echo wp_kses_post( $section->post_title ); ?>
                    </h4>
					<?php 
					if ( $show_topic == '1' ) :
						?>
						<span class="ezd-badge ezd-circle"> <?php echo count( $all_doc_items ) ?> </span>
						<?php
					endif;
					?>
                </a>
			<?php
			endif;

			if ( ! empty( $doc_items ) ) : ?>
                <ul class="navbar-nav ezd-list-unstyled">
					<?php
					foreach ( $doc_items as $doc_item ) :
						?>
                        <li>
                            <a class="ct-content-text" href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>">
                                <i class="icon_document_alt"></i>
								<?php echo wp_kses_post( $doc_item->post_title ) ?>
                            </a>
                        </li>
					<?php
					endforeach;
					?>
                </ul>
			<?php
			endif;

			if ( ! empty( $attributes['readMoreText'] ) ) : ?>
                <a href="#" class="text_btn dark_btn">
					<?php echo wp_kses_post( $attributes['readMoreText'] ) ?>
                    <i class="<?php ezd_arrow() ?>"></i>
                </a>
			<?php endif; ?>
        </div>
	<?php
	endforeach;
	?>
</div>


