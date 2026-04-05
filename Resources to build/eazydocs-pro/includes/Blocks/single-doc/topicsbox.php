<?php
$ppp_column      = $attributes['col'] ?? 4;
$isFeaturedImage = $attributes['isFeaturedImage'] ?? '';
$sections = get_children( array(
	'post_parent'    => $attributes['docId'] ?? 0,
	'post_type'      => 'docs',
	'post_status'    => ['publish', 'private'],
	'orderby'        => $attributes['orderBy'],
	'posts_per_page' => $attributes['sectionsNumber'],
	'order'          => $attributes['parent_docs_order'],
) );
?>
<div class="ezd-grid ezd-column-<?php echo esc_attr( $ppp_column ); ?> h_content_items topic-box-style">
	<?php
	foreach ( $sections as $section ) :
		?>
        <a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>">
            <div class="h_item">
                <?php 
                if ( $isFeaturedImage && has_post_thumbnail( $section->ID ) ) {
                    echo get_the_post_thumbnail( $section->ID, 'full' );
                }
                ?>
                <h4 class="ct-heading-text"><?php echo wp_kses_post( $section->post_title ); ?></h4>
                <div class="ct-content-text">
                    <?php
                    if ( strlen( trim( $section->post_excerpt ) ) != 0 ) {
                        echo wp_kses_post( wpautop( $section->post_excerpt ) );
                    } else {
                        echo wp_kses_post( wpautop( wp_trim_words( $section->post_content, $attributes['excerptCharNumber'], '' ) ) );
                    }
                    ?>
                </div>
            </div>
        </a>
		<?php
	endforeach;
	?>
</div>

<?php
$sections2 = get_children( array(
	'post_parent'    => $attributes['docId'] ?? 0,
	'post_type'      => 'docs',
	'post_status'    => ['publish', 'private'],
	'orderby'        => $attributes['orderBy'],
	'order'          => $attributes['parent_docs_order'],
	'posts_per_page' => $attributes['sectionsTwoNumber'],
	'offset'         => $attributes['sectionsNumber'],
) );
?>
<!--    collapse-wrap-->
<div class="h_content_items box-item  collapse-wrap">
    <div class="ezd-grid ezd-column-<?php echo esc_attr( $ppp_column ); ?>">
		<?php
		foreach ( $sections2 as $section ) :
			?>
            <a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>">
                <div class="h_item">
                    <?php 
                    if ( $isFeaturedImage && has_post_thumbnail( $section->ID ) ) {
                        echo get_the_post_thumbnail( $section->ID, 'full' );
                    }
                    ?>
                    <h4 class="ct-heading-text"> <?php echo wp_kses_post( $section->post_title ); ?> </h4>
                    <div class="ct-content-text">
						<?php
                        if ( strlen( trim( $section->post_excerpt ) ) != 0 ) {
                            echo wp_kses_post( wpautop( $section->post_excerpt ) );
                        } else {
                            echo wp_kses_post( wpautop( wp_trim_words( $section->post_content, $attributes['excerptCharNumber'], '' ) ) );
                        }
                        ?>
                    </div>
                </div>
            </a>
			<?php
		endforeach;
		?>
    </div>
</div>

<?php
if ( ! empty( $attributes['showMoreBtn'] ) ) :
	?>
    <div class="more text-center">
        <a class="icon_btn2 blue collapse-btn" href="#">
            <span>
                <ion-icon name="caret-down-circle-outline"></ion-icon>
                <?php esc_html_e( 'View All', 'eazydocs-pro' ); ?>
            </span>
            <span>
                <ion-icon name="caret-up-circle-outline"></ion-icon>
                <?php esc_html_e( 'Show Less', 'eazydocs-pro' ); ?>
            </span>
        </a>
    </div>
    <script type="text/javascript">
        ;(function($) {
            "use strict";
            $(document).ready(function() {
                function general() {
                    if ($('.collapse-btn').length > 0) {
                        $('.collapse-btn').on('click', function(e) {

                            e.preventDefault();
                            $(this).toggleClass('active');
                            $('.collapse-wrap').slideToggle(500);
                        });
                    }
                }
                general();
            });

        })(jQuery);
    </script>
	<?php
endif;