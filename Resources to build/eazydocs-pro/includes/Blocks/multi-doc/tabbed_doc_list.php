<?php 
$exclude_id  = $attributes['exclude'] ?? [];
$include_id  = $attributes['include'] ?? [];

$parent_args = new WP_Query( [
	'post_type'      => 'docs',
	'post_status'    => array( 'publish', 'private' ),
	'orderby'        => $attributes['orderBy'],
	'posts_per_page' => $attributes['show_docs'],
	'order'          => $attributes['parent_docs_order'],
	'post_parent'    => 0,
	'post__not_in'   => $exclude_id,
    'post__in'       => $include_id
] );
$layoutType = $attributes['docs_layout'] == "grid" ? "ezd-grid ezd-column-" . $attributes['col'] : "ezd-masonry-wrap ezd-masonry-col-" . $attributes['col'];

?>
<section class="doc_tag_area" id="Arrow_slides-<?php echo esc_attr( get_the_ID() ); ?>">
    <div class="tabs_sliders">
        <span class="scroller-btn left inactive"><i class="arrow_carrot-left"></i></span>
        
        <?php                
        if ( $parent_args->have_posts() ) :
            ?>
            <ul class="nav nav-tabs doc_tag ezd-tab-menu slide_nav_tabs ezd-list-unstyled">
                <?php
                $i = 0;
                while($parent_args->have_posts($i++)) : $parent_args->the_post();
                
                $active = ( $i == 1 ) ? 'active' : '';
                    ?>
                    <li class="nav-item ">                                
                        <a data-rel="<?php echo esc_attr( get_post_field('post_name', get_the_ID() ) ); ?>" class="nav-link ezd_tab_title <?php echo esc_attr( $active ); ?>">
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

        <span class="scroller-btn right inactive"><i class="arrow_carrot-right"></i></span>
    </div>

    <div class="ezd-tab-content">

        <?php                
        if ( $parent_args->have_posts() ) :
        
            $i = 0;
            while($parent_args->have_posts($i++)) : $parent_args->the_post();
                $active = ( $i == 1 ) ? 'active' : '';
                ?>
                <div class="doc_tab_pane ezd-tab-box <?php echo esc_attr( $active ); ?>" id="<?php echo esc_attr( get_post_field('post_name', get_the_ID() ) ); ?>">
                    <div class="<?php echo esc_attr( $layoutType); ?>">
                        
                    <?php
                    $sections = get_children( [
                        'post_parent' => get_the_ID(),
                        'post_type'   => 'docs',
                        'numberposts' => $attributes['sectionsNumber'],
                        'post_status' => array( 'publish', 'private' ),
                        'orderby'     => $attributes['orderBy'],
                        'order'       => $attributes['child_docs_order'],
                    ] );

                    foreach (  $sections as $section ) :
                        ?>
                        <div class="doc_tag_item">
                            <?php 
                            if ( ! empty( $section->post_title ) ) : 
                                ?>
                                <div class="doc_tag_title">
                                    <h4 class="ezd_item_title"><?php echo wp_kses_post( $section->post_title ); ?></h4>
                                    <div class="line"></div>
                                </div>
                                <?php 
                            endif;

                            $doc_items = get_children( array(
                                'post_parent'    => $section->ID,
                                'post_type'      => 'docs',
                                'post_status'    => 'publish',
                                'orderby'     => $attributes['orderBy'],
                                'order'       => $attributes['child_docs_order'],
                                'posts_per_page' => ! empty( $attributes['articlesNumber'] ) ? $attributes['articlesNumber'] : - 1,
                            ) );

                            if ( ! empty( $doc_items ) ) : ?>
                                <ul class="ezd-list-unstyled tag_list">
                                    <?php
                                    foreach ( $doc_items as $doc_item ) :
                                        ?>
                                        <li>
                                            <a href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>" class="ezd_item_list_title">
                                                <?php echo wp_kses_post( $doc_item->post_title ) ?>
                                            </a>
                                        </li>
                                    <?php
                                    endforeach;
                                    ?>
                                </ul>
                                <?php
                            endif;

                            if ( ! empty( $settings['read_more'] ) ) : ?>
                                <a href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>" class="learn_btn ezd_btn">
                                    <?php echo esc_html( $settings['read_more'] ) ?>
                                    <i class="<?php ezd_arrow() ?>"></i>
                                </a>
                                <?php 
                            endif; 
                            ?>
                        </div>
                        <?php
                    endforeach;
                    ?>
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