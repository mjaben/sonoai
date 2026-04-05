<?php 
$sectionsNumber     = $attributes['sectionsNumber'] ?? '';
$show_docs          = $attributes['show_docs'] ?? '';
$articlesNumber     = $attributes['articlesNumber'] ?? '';
$order_by           = $attributes['orderBy'] ?? '';
$child_docs_order   = $attributes['child_docs_order'] ?? '';
$exclude_id         = $attributes['exclude'] ?? [];
$include_id         = $attributes['include'] ?? [];
$isFeaturedImage    = $attributes['isFeaturedImage'] ?? '';
// Fetch parent posts
$parent_args = new WP_Query([
    'post_type'      => 'docs',
    'post_status'    => is_user_logged_in() ? ['publish', 'private' ] : 'publish',
    'orderby'        => $order_by,
    'posts_per_page' => $show_docs ?? -1, // Default to 10 if not set
    'order'          => $attributes['parent_docs_order'] ?? 'ASC', // Default to ASC if not set
    'post_parent'    => 0,
	'post__not_in'   => $exclude_id,
    'post__in'       => $include_id
]);
?>

<section class="doc6_community_area docs6 bew-topics">
    <div class="doc_community_info">
        <?php
        $delay      = 0.1;
        $duration   = 0.5;
        while ( $parent_args->have_posts() ) : $parent_args->the_post();
            $doc_id = get_the_ID();
            ?>
            <div class="doc_community_item topic-item wow fadeInUp" data-wow-delay="<?php echo esc_attr($delay) ?>s">

                <div class="doc_community_icon ezd-docs5-icon-wrap">
                    <?php
                    if ( $isFeaturedImage && has_post_thumbnail() ){ 
                        echo get_the_post_thumbnail( $doc_id, 'full'); 
                    }
                    ?>
                </div>

                <div class="doc_entry_content">
                    <a href="<?php echo esc_url( get_the_permalink( $doc_id ) ); ?>">
                        <h4 class="ezd_item_title"><?php echo wp_kses_post( get_the_title(get_the_ID()) ); ?></h4>
                    </a>
                    <p class="ezd_item_content"><?php ezd_widget_excerpt( $doc_id, 15 ); ?></p>
                    <div class="doc_entry_info">
                        <ul class="ezd-list-unstyled author_avatar">
                            <?php
                            $docs = new WP_Query(array(
                                'post_type'     => 'docs',
                                'post_per_page' => -1,
                                'post_parent'   => $doc_id,
                            ));
                            $doc_counter = get_pages( [
                                'child_of'      => $doc_id,
                                'post_type'     => 'docs'
                            ]);

                            $author_names       = [];
                            $i                  = 1;
                            $child_ids          = [];
                            $author_id          = [];

                            while ( $docs->have_posts() ) : $docs->the_post();
                                $child_ids[]        = get_the_ID();  
                                $author_id[]        = get_post_field('post_author', get_the_ID());                        
                                ++$i;
                            endwhile;

                            $child_authors = [];
                            if ( !empty($child_ids) ) {
                                foreach( $child_ids as $child_id ) {
                                    $child_authors[] = get_post_meta($child_id, 'ezd_doc_contributors', true);
                                }
                            }
                            
                            $child_authors     = implode(',', $child_authors);
                            $child_authors     = preg_replace('/(,)+/', ',', trim($child_authors, ','));
                            // Convert the string to an array
                            $child_authors     = explode(',', $child_authors);
                            // Remove duplicate values
                            $child_authors     = array_unique($child_authors);
                            
                            $author_id         = array_filter($author_id);
                            $author_id         = array_unique($author_id);                             

                            $parent_authors    = get_post_meta($doc_id, 'ezd_doc_contributors', true);
                            $parent_authors    = explode(',', $parent_authors);   
                            $sibling_authors   = array_merge($author_id, $parent_authors);
                            $parent_authors    = array_filter($sibling_authors);
                            $parent_authors    = array_unique($parent_authors);
                            
                            $contributed_authors    = array_merge($child_authors, $parent_authors);
                            $contributed_authors    = array_unique($contributed_authors);
                            $contributed_authors    = array_filter($contributed_authors);
                            
                            $author_count           = count($contributed_authors);
                            $author_count           = $author_count - 1;
                            $show_avatar_count      = 2;
                            $ii                     = 0;
                            $doc_author             = get_the_author_meta('display_name', get_post_field('post_author', $doc_id));

                            echo '<li> ' . get_avatar(get_post_field('post_author', $doc_id), 36) . '</li>';

                            // if exist post_author in $contributed_authors
                            if ( in_array(get_post_field('post_author', $doc_id), $contributed_authors) ) {
                                $contributed_authors = array_diff($contributed_authors, [get_post_field('post_author', $doc_id)]);
                            }

                            foreach ( $contributed_authors as $author_id ) {
                                if ( $ii === $show_avatar_count ) {
                                    break;
                                }

                                $author_names[] = get_the_author_meta('display_name', $author_id);
                                                                
                                echo '<li> ' . get_avatar($author_id, 36) . ' </li>';
                                ++$ii;
                            }
                            wp_reset_postdata();

                            $remaining_authors_count = $author_count - $show_avatar_count;
                            $others = '';
                            if ( $author_count > $show_avatar_count ) : 
                                ?>
                                <li class="avatar_plus">+<?php echo esc_html($remaining_authors_count); ?></li>
                                <?php
                                // translators: %d is the number of additional authors not shown.
                                $others = sprintf( __( ' and %d others', 'eazydocs-pro' ), $remaining_authors_count );
                            endif;
                            ?>
                        </ul>

                        <div class="text">
                            <?php echo count( $doc_counter ); ?> 
                            <?php esc_html_e( 'Article in this Docs.', 'eazydocs-pro' ); ?>
                            <br>
                            <?php 
                                esc_html_e('Written by ', 'eazydocs-pro');
                                echo esc_html( $doc_author );
                                foreach ( $author_names as $author_name ) {
                                    echo ', ' . esc_html( $author_name );
                                }
                                echo esc_html( $others );
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php 
        endwhile; 
        wp_reset_postdata(); 
        ?>
    </div>
</section>