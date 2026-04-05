<div class="easydocs-accordion sortabled dd accordionjs nestables-child" id="least_helpful">
    <ol class="dd-list">
        <?php
        $posts     = get_posts( [ 'post_type' => 'docs', 'posts_per_page'   => -1, 'inclusive' => true] );
        $post_data = [];
        foreach ( $posts as $key => $post ) {
            $post_data[$key]['post_id'] = $post->ID;
            $post_data[$key]['post_title'] = $post->post_title;
            $post_data[$key]['post_edit_link'] = get_edit_post_link( $post->ID );
            $post_data[$key]['post_permalink'] = get_permalink( $post->ID );
            // sum of total positive votes for a post
            $post_data[$key]['positive_time'] = array_sum( get_post_meta( $post->ID, 'positive', false ) );

            $post_data[$key]['negative_time'] = array_sum( get_post_meta( $post->ID, 'negative', false ) );
        }
        // if post has positive_time number large then negative_time number then show large number first
        usort( $post_data, function( $a, $b ) {
            return $b['negative_time'] <=> $a['negative_time'];
        } );

        foreach ( $post_data as $key => $post ) {
            if ( $post['negative_time'] > 0 ) {
                if ( $key >= 0 && $key <= 9 ) {
                ?>
                <li class="dd-item dd3-item dd-item-parent easydocs-accordion-item  ez-section-acc-item type-docs" data-id="<?php echo esc_attr( $post['post_id'] ); ?>">
                    <div class="dd3-content">
                        <div class="accordion-title ez-section-title expand--child has-child">
                            <div class="left-content">
                                <?php
                                $edit_link = 'javascript:void(0)';
                                $target = '_self';
                                if (current_user_can('publish_pages')) {
                                    $edit_link = $post['post_edit_link'];
                                    $target = '_blank';
                                }
                                ?>
                                <h4>
                                    <a href="<?php echo esc_attr($edit_link); ?>" target="<?php echo esc_attr($target); ?>">
                                        <?php echo esc_html( $post['post_title'] ); ?>
                                    </a>
                                </h4>
                                <ul class="actions">
                                    <li>
                                        <a href="<?php echo esc_url( $post['post_permalink'] ); ?>" target="_blank" title="<?php esc_attr_e('View this doc item in new tab', 'eazydocs-pro') ?>">
                                            <span class="dashicons dashicons-external"></span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="right-content">
                                <?php
                                $positive = $post['positive_time'];
                                $negative = $post['negative_time'];

								// translators: %d is the number of positive votes.
                                $positive_title      = $positive ? sprintf(_n('%d Positive vote, ', '%d Positive votes and ', $positive, 'eazydocs-pro' ), number_format_i18n($positive)) : esc_html__('No Positive votes, ', 'eazydocs-pro' );
                                
								// translators: %d is the number of negative votes.
                                $negative_title      = $negative ? sprintf(_n('%d Negative vote found.', '%d Negative votes found.', $negative, 'eazydocs-pro' ), number_format_i18n($negative)) : esc_html__('No Negative votes.', 'eazydocs-pro' );

                                $positive_icon = '';
                                if ( $positive || $negative ) {
                                    $positive_icon .= '<div class="votes">';

                                    if ( $positive > 0 ) {
                                        $positive_icon .= '<div class="like"> 
                                            <span class="t-success dashicons dashicons-thumbs-up"></span>' . esc_html( $positive ) . '
                                        </div>';
                                    }

                                    $positive_icon .= '<div class="dislike"> 
                                        <span class="t-danger dashicons dashicons-thumbs-down"></span>' . esc_html( $negative ) . '
                                    </div>';

                                    $positive_icon .= '</div>';
                                }

                                $sum_votes = $positive + $negative;
                                echo wp_kses_post( $positive_icon );
                                ?>

                                <span class="progress-text">
                                    <?php
                                    if ( $positive || $negative ) {
                                        printf(
                                            '<progress id="file" value="%d" max="%d" title="%s%s"></progress>',
                                            (int) $positive,
                                            (int) $sum_votes,
                                            esc_attr( $positive_title ),
                                            esc_attr( $negative_title )
                                        );
                                    } else {
                                        esc_html_e('No rates', 'eazydocs-pro' );
                                    }
                                    ?>
                                </span>
                                
                            </div>
                        </div>
                    </div>
                </li>
            <?php } } }?>
    </ol>
</div>