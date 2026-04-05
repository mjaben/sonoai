<?php 
$isFeaturedImage = $attributes['isFeaturedImage'] ?? '';
$is_btn_show     = ezd_get_opt('docs-view-all-btn');
$parent_args = new WP_Query( [
	'post_type'      => 'docs',
    'post_status'    => is_user_logged_in() ? ['publish', 'private' ] : 'publish',
	'orderby'        => $attributes['orderBy'] ?? 'menu_order',
	'posts_per_page' => $attributes['show_docs'] ?? -1,
	'order'          => $attributes['parent_docs_order'] ?? 'desc',
	'post_parent'    => 0,
	'post__not_in'   => $attributes['exclude'] ?? [],
    'post__in'       => $attributes['include'] ?? []
] );
?>
<section class="h_doc_documentation_area" id="Arrow_slides-<?php echo esc_attr( get_the_ID() ); ?>">
            
    <div class="tabs_sliders">
        <span class="scroller-btn left inactive"><i class="arrow_carrot-left"></i></span>
        
        <?php                
        if ( $parent_args->have_posts() ) :
            ?>
            <ul class="nav nav-tabs documentation_tab ezd-tab-menu slide_nav_tabs ezd-list-unstyled">
                <?php
                $i = 0;
                while($parent_args->have_posts($i++)) : $parent_args->the_post();
                    $active = ( $i == 1 ) ? 'active' : '';
                    ?>
                    <li class="nav-item ">                                
                        <a data-rel="<?php echo esc_attr( get_post_field('post_name', get_the_ID()) ); ?>" class="nav-link ezd_tab_title <?php echo esc_attr( $active ); ?>">
                            <?php the_title(); ?>
                        </a>
                    </li>
                    <?php
                endwhile;
                wp_reset_postdata();
                ?>
            </ul>
            <?php 
        endif;
        ?>
        
        <span class="scroller-btn right inactive">
            <i class="arrow_carrot-right"></i>
        </span>
    </div>

    <div class="ezd-tab-content">
        <?php                
        if ( $parent_args->have_posts() ) :
            $i = 0;
            while($parent_args->have_posts($i++)) : $parent_args->the_post();
                $active = ( $i == 1 ) ? 'active' : '';
                
                $all_doc_items = get_children( array(
                    'post_parent'    => get_the_ID(),
                    'post_type'      => 'docs',
                    'post_status'    => ['publish', 'private'],
                    'posts_per_page' => -1
                ) );
                ?>
                <div class="documentation_tab_pane ezd-tab-box  <?php echo esc_attr( $active ); ?>" id="<?php echo esc_attr( get_post_field('post_name', get_the_ID()) ); ?>">
                    <div class="ezd-grid ezd-grid-cols-12"> 
                        <div class="ezd-lg-col-4 ezd-grid-column-full">
                            <div class="documentation_text">
                                <?php 
                                
                                if ( $isFeaturedImage ) {
                                    the_post_thumbnail( 'full', ['class' => 'doc-logo'] ); 
                                }
                                ?>
                                <h4 class="ezd_item_parent_title"><?php the_title(); ?></h4>
                                <p class="ezd_item_content">
                                    <?php echo esc_html( wp_trim_words( get_the_content( get_the_ID() ), 10 ) ); ?>
                                </p>
                                <?php
                                $has_children = count( $all_doc_items ) > 0;

                                if ( ( ! $has_children && ! empty( $attributes['readMoreText'] ) && ! empty( $is_btn_show ) ) || 
                                     ( $has_children && ! empty( $attributes['readMoreText'] ) ) ) :
                                    ?>                                 
                                    <a href="<?php the_permalink(); ?>" class="learn_btn ezd_btn">
                                        <?php echo esc_html( $attributes['readMoreText'] ?? __( 'View All', 'eazydocs-pro' ) ); ?> <i class="arrow_right"></i>
                                    </a>
                                    <?php 
                                endif;
                                ?>
                            </div>
                        </div>
                        
                        <div class="ezd-lg-col-8 ezd-grid-column-full">
                            <div class="d-items">
                                <?php                        
                                $sections = get_children( [
                                    'post_parent' => get_the_ID(),
                                    'post_type'   => 'docs',
                                    'numberposts' => $attributes['articlesNumber'],
                                    'post_status' => array( 'publish', 'private' ),
                                    'orderby'     => $attributes['orderBy'],
                                    'order'       => $attributes['child_docs_order'],
                                ] );

                                foreach ( $sections as $section ) :
                                    ?>
                                    <div class="media documentation_item">
                                        
                                        <div class="icon bs-sm">
                                            <?php
                                            if ( $isFeaturedImage && has_post_thumbnail( $section->ID ) ) {
                                                echo get_the_post_thumbnail( $section->ID, 'full' );
                                            } else {
                                                $default_icon = esc_url( EAZYDOCSPRO_IMG . '/icon/folder.png' );
                                                echo '<img src="' . esc_url( $default_icon ) . '" alt="' . esc_attr( $section->post_title ) . '">';
                                            }
                                            ?>
                                        </div>

                                        <div class="media-body">
                                            
                                            <a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>">
                                                <h5 class="title ezd_item_title">
                                                    <?php echo wp_kses_post( $section->post_title ); ?>
                                                </h5>
                                            </a>

                                            <p class="ezd_item_content">
                                                <?php
                                                if ( strlen( trim( $section->post_excerpt ) ) != 0 ) {
                                                    echo wp_kses_post( wp_trim_words( $section->post_excerpt, $attributes['excerptCharNumber'], '' ) );
                                                } else {
                                                    echo wp_kses_post( wp_trim_words( $section->post_content, $attributes['excerptCharNumber'], '' ) );
                                                }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php
                                endforeach;
                                ?>

                            </div>
                        </div>
                    </div>
                </div>
                <?php 
            endwhile;
            wp_reset_postdata();        
        endif;
        ?>
    </div>
</section>

<script>
;(function($) {
    "use strict";

    $(document).ready(function() {

        // === Tabs Slider
        var tabId = "#Arrow_slides-<?php echo esc_js(get_the_ID()) ?>";
        var tabSliderContainers = $(tabId + " .tabs_sliders");

        tabSliderContainers.each(function() {
            let tabWrapWidth = $(this).outerWidth();
            let totalWidth = 0;

            let slideArrowBtn = $(tabId + " .scroller-btn");
            let slideBtnLeft = $(tabId + " .scroller-btn.left");
            let slideBtnRight = $(tabId + " .scroller-btn.right");
            let navWrap = $(tabId + " .slide_nav_tabs");
            let navWrapItem = $(tabId + " .slide_nav_tabs li");

            navWrapItem.each(function() {
                totalWidth += $(this).outerWidth();
            });

            if (totalWidth > tabWrapWidth) {
                slideArrowBtn.removeClass("inactive");
            } else {
                slideArrowBtn.addClass("inactive");
            }

            if (navWrap.scrollLeft() === 0) {
                slideBtnLeft.addClass("inactive");
            } else {
                slideBtnLeft.removeClass("inactive");
            }

            slideBtnRight.on("click", function() {
                navWrap.animate({
                    scrollLeft: "+=200px"
                }, 300);
                console.log(navWrap.scrollLeft() + " px");
            });

            slideBtnLeft.on("click", function() {
                navWrap.animate({
                    scrollLeft: "-=200px"
                }, 300);
            });

            scrollerHide(navWrap, slideBtnLeft, slideBtnRight);
        });

        function scrollerHide(navWrap, slideBtnLeft, slideBtnRight) {
            let scrollLeftPrev = 0;
            navWrap.scroll(function() {
                let $elem = $(this);
                let newScrollLeft = $elem.scrollLeft(),
                    width = $elem.outerWidth(),
                    scrollWidth = $elem.get(0).scrollWidth;
                if (scrollWidth - newScrollLeft === width) {
                    slideBtnRight.addClass("inactive");
                } else {
                    slideBtnRight.removeClass("inactive");
                }
                if (newScrollLeft === 0) {
                    slideBtnLeft.addClass("inactive");
                } else {
                    slideBtnLeft.removeClass("inactive");
                }
                scrollLeftPrev = newScrollLeft;
            });
        }

        // custom tab js
        $('.ezd-tab-menu li a').on('click', function(e) {
            e.preventDefault();

            // Remove active class from all tabs within the same menu
            $(this).closest('.ezd-tab-menu').find('li a').removeClass('active');

            // Add active class to the clicked tab
            $(this).addClass('active');

            var target = $(this).attr('data-rel');

            $('#' + target)
                .addClass('active')
                .siblings('.ezd-tab-box')
                .removeClass('active');

            return false;
        });

    });
})(jQuery);
</script>