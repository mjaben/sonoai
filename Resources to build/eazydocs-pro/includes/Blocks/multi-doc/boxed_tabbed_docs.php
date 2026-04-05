<?php 
$sectionsNumber     = $attributes['sectionsNumber'] ?? -1;
$show_docs          = $attributes['show_docs'] ?? -1;
$articlesNumber     = $attributes['articlesNumber'] ?? -1;
$order_by           = $attributes['orderBy'] ?? 'menu_order';
$child_docs_order   = $attributes['child_docs_order'] ?? 'desc';
$current_page_id    = get_the_ID();

// Fetch parent posts
$parent_args = new WP_Query([
    'post_type'      => 'docs',
    'post_status'    => is_user_logged_in() ? ['publish', 'private' ] : 'publish',
    'orderby'        => $order_by,
    'posts_per_page' => $show_docs ?? -1, // Default to 10 if not set
    'order'          => $attributes['parent_docs_order'] ?? 'ASC', // Default to ASC if not set
    'post_parent'    => 0,
	'post__not_in'   => $attributes['exclude'] ?? [],
    'post__in'       => $attributes['include'] ?? []
]);
?>
<div class="question_menu docs3" id="Arrow_slides-<?php echo esc_attr( $current_page_id ); ?>">
    <div class="tabs_sliders">
        <span class="scroller-btn left"><i class="arrow_carrot-left"></i></span>

        <?php 
        if ( $parent_args->have_posts() ) : 
            ?>
            <ul class="nav nav-tabs mb-5 ezd-tab-menu slide_nav_tabs">
                <?php
                $i = 0;
                while ($parent_args->have_posts()) : $parent_args->the_post();
                    $i++;
                    $active = ($i === 1) ? 'active' : '';
                    ?>
                    <li class="nav-item">
                        <a data-rel="<?php echo esc_attr( get_post_field('post_name', get_the_ID()) ); ?>" class="nav-link ezd_tab_title <?php echo esc_attr($active); ?>">
                            <?php the_title(); ?>
                        </a>
                    </li>
                    <?php 
                endwhile;
                ?>
            </ul>
            <?php 
        endif;
        ?>
        
        <span class="scroller-btn right inactive">
            <i class="arrow_carrot-right"></i>
        </span>
    </div>

    <?php 
    // Determine layout type
    $layoutType = ($attributes['docs_layout'] ?? 'grid') === 'grid' ? 'ezd-grid ezd-column-' . $attributes['col'] : 'ezd-masonry-wrap ezd-masonry-col-' . $attributes['col'];
    ?>
    
    <div class="topic_list_inner">
        <div class="ezd-tab-content">

            <?php 
            if ( $parent_args->have_posts() ) :
                $i = 0;
                while ($parent_args->have_posts()) : $parent_args->the_post();
                    $i++;
                    $active = ($i === 1) ? 'active' : '';
                    ?>
                    <div class="doc_tab_pane ezd-tab-box  <?php echo esc_attr($active); ?>" id="<?php echo esc_attr( get_post_field('post_name', get_the_ID() ) ); ?>">
                        <div class="<?php echo esc_attr($layoutType); ?>">
                            
                            <?php
                            $doc_items = get_children( array(
                                'post_parent'    => get_the_ID(),
                                'post_type'      => 'docs',
                                'post_status'    => is_user_logged_in() ? ['publish', 'private' ] : 'publish',
                                'orderby'        => $order_by,
                                'order'          => $child_docs_order,
                                'posts_per_page' => $sectionsNumber ?? ( ! empty( $settings['ppp_doc_items'] ) ? $settings['ppp_doc_items'] : - 1),
                            ) );

                            foreach ( $doc_items as $doc_item ) :
                                ?>
                                <div class="topic_list_item">
                                    <h4 class="ezd_item_title">
                                        <?php echo wp_kses_post( $doc_item->post_title ) ?>
                                    </h4>
                                    <ul class="navbar-nav">
                                        <?php
                                        $child_items = get_children( array(
                                            'post_parent'    => $doc_item->ID,
                                            'post_type'      => 'docs',
                                            'post_status'    => is_user_logged_in() ? ['publish', 'private' ] : 'publish',
                                            'orderby'        => $order_by,
                                            'order'          => $child_docs_order,
                                            'posts_per_page' => $articlesNumber,
                                        ) );

                                        foreach ( $child_items as $child_item ) :
                                            ?>
                                            <li>
                                                <a href="<?php echo esc_url( get_permalink( $child_item->ID ) ); ?>" class="ezd_item_list_title">
                                                    <?php echo esc_html( get_the_title( $child_item->ID ) ); ?>
                                                </a>
                                            </li>
                                            <?php 
                                        endforeach;
                                        ?>
                                    </ul>
                                    <a href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>" class="text_btn dark_btn ezd_btn">View All </a>
                                </div>
                                <?php
                            endforeach;
                            ?>
                        
                        </div>  
                    </div>
                    <?php
                endwhile;
            endif;
            ?>
        </div>
    </div>
</div>

<script>
;(function($) {
        "use strict";

        $(document).ready(function() {

            // === Tabs Slider
            var tabId = "#Arrow_slides-<?php echo esc_js($current_page_id) ?>";
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