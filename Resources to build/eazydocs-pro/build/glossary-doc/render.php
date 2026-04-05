<?php
/**
 * EazyDocs Glossary Doc Block Template.
 *
 * @param   array $attributes - A clean associative array of block attributes.
 * @param   array $block - All the block settings and attributes.
 * @param   string $content - The block inner HTML (usually empty unless inner blocks are used).
 */

// Map attributes to settings expected by the template
$settings = [
    'doc' => $attributes['doc'] ?? 'all',
    'order' => $attributes['order'] ?? 'ASC',
    'tooltip' => ($attributes['tooltip'] ?? false) ? 'yes' : 'no',
    'tooltip_content_limit' => $attributes['tooltipContentLimit'] ?? 40,
];

// Generate unique ID for styling scope
$unique_id = 'ezd-glossary-' . uniqid();
$wrapper_attributes = get_block_wrapper_attributes(['class' => $unique_id]);

// Dynamic Styles
$styles = "<style>
    .{$unique_id} .spe-list-filter a {
        " . ( ! empty( $attributes['alphabetColor'] ) ? "color: {$attributes['alphabetColor']};" : "" ) . "
        " . ( ! empty( $attributes['alphabetBackground'] ) ? "background: {$attributes['alphabetBackground']};" : "" ) . "
        text-decoration: none !important;
    }
    .{$unique_id} .spe-list-search-form input[type=search] {
        " . ( ! empty( $attributes['searchBoxBackground'] ) ? "background: {$attributes['searchBoxBackground']};" : "" ) . "
        " . ( ! empty( $attributes['searchTextColor'] ) ? "color: {$attributes['searchTextColor']};" : "" ) . "
    }
    .{$unique_id} .spe-list-search-form input[type=search]:focus {
        " . ( ! empty( $attributes['searchBoxFocusBackground'] ) ? "background: {$attributes['searchBoxFocusBackground']};" : "" ) . "
    }
    .{$unique_id} .spe-list-search-form input[type=search]::placeholder {
        " . ( ! empty( $attributes['searchPlaceholderColor'] ) ? "color: {$attributes['searchPlaceholderColor']};" : "" ) . "
    }
    .{$unique_id} .spe-list-block .spe-list-block-heading {
        " . ( ! empty( $attributes['docAlphabetColor'] ) ? "color: {$attributes['docAlphabetColor']};" : "" ) . "
        " . ( ! empty( $attributes['docAlphabetBackground'] ) ? "background: {$attributes['docAlphabetBackground']};" : "" ) . "
    }
    .{$unique_id} .tag_list li a {
        " . ( ! empty( $attributes['docContentColor'] ) ? "color: {$attributes['docContentColor']};" : "" ) . "
        text-decoration: none !important;
    }
    .{$unique_id} .tag_list li a:hover,
    .{$unique_id} .tag_list li a:focus {
        " . ( ! empty( $attributes['docContentColor'] ) ? "color: {$attributes['docContentColor']};" : "" ) . "
        text-decoration: underline !important;
    }
    .{$unique_id} .spe-list-block .spe-list-items .spe-list-item .spe-list-item-title::before {
        " . ( ! empty( $attributes['docContentIconColor'] ) ? "color: {$attributes['docContentIconColor']};" : "" ) . "
    }
    .{$unique_id} .box-item {
        " . ( ! empty( $attributes['boxBackground'] ) ? "background: {$attributes['boxBackground']};" : "" ) . "
    }
</style>";

echo $styles;

// Query logic
$args = array(
    'post_type'      => 'docs',
    'post_status'    => 'publish',
    'orderby'        => 'menu_order',
    'order'          => $settings['order'],
    'posts_per_page' => -1,
);

if ( $settings['doc'] === 'all' ) {
    if ( function_exists('ezd_get_posts') ) {
        $args['post_parent__in'] = array_keys( ezd_get_posts() );
    }
} else {
    $args['post_parent'] = $settings['doc'] ?? 0;
}

$sections = new WP_Query($args);

// Render (copied and adapted from glossary-doc-1.php)
?>
<div <?php echo $wrapper_attributes; ?> class="<?php echo esc_attr($unique_id); ?> spe-list-wrapper">
    <div class="spe-list-filter">
        <a class="filter active mixitup-control-active" data-filter="all">
            <?php esc_html_e( 'All', 'eazydocs-pro' ); ?>
        </a>
        <?php
        $alphabet = range('a', 'z');
        foreach ( $alphabet as $alphabetCharacter ) :
            $has_content = false;
            
            if ( $sections->have_posts() ) {
                while ( $sections->have_posts() ) { $sections->the_post();
                    
                    $doc_items = get_children(array(
                        'post_parent'    => get_the_ID(),
                        'post_type'      => 'docs',
                        'post_status'     => is_user_logged_in() ? ['publish', 'private', 'protected'] : ['publish', 'protected'],
                        'orderby'        => 'menu_order',
                        'order'          => 'ASC',
                        'posts_per_page' => -1,
                    ));

                    if ( ! empty( $doc_items ) ) {
                        foreach ($doc_items as $doc_item) {
                            $title 		 = $doc_item->post_title;
                            $firstLetter = substr($title, 0, 1);
                            if ( strtolower($firstLetter) === $alphabetCharacter ) {
                                $has_content = true;
                                break;
                            }
                        }
                    }					
                }
                wp_reset_postdata();
            }

            $filter_class = $has_content ? 'filter' : 'filter filter_disable';
            ?>
            <a class="<?php echo esc_attr($filter_class); ?>"
                data-filter=".spe-filter-<?php echo esc_html($alphabetCharacter); ?>">
                <?php echo esc_html($alphabetCharacter); ?>
            </a>
            <?php
        endforeach;
        ?>
    </div>

    <div class="spe-list-search-form spe-list-search-form-position-below">
        <input id="input" type="search" placeholder="<?php esc_attr_e('Search by Keyword ...', 'eazydocs-pro') ?>" value="">
    </div>

    <div class="spe-list spe-list-template-three-column">
        <?php
        $alphabet = range('a', 'z');

        if ( is_array( $alphabet ) ) {
            foreach ( $alphabet as $alphabetCharacter ) {
                $has_content = false;
                
                if ( $sections->have_posts() ) {
                    while ( $sections->have_posts() ) { $sections->the_post();

                    $doc_items = get_children(array(
                        'post_parent'    => get_the_ID(),
                        'post_type'      => 'docs',
                        'post_status'     => is_user_logged_in() ? ['publish', 'private', 'protected'] : ['publish', 'protected'],
                        'orderby'        => 'menu_order',
                        'order'          => 'ASC',
                        'posts_per_page' => -1,
                    ));

                    if ( ! empty( $doc_items ) ) {
                        foreach( $doc_items as $doc_item ) {
                            $title 		 = $doc_item->post_title;
                            $firstLetter = substr( $title, 0, 1 );
                            if ( strtolower( $firstLetter ) === $alphabetCharacter ) {
                                $has_content = true;
                                break;
                            }
                        }
                    }
                }
                wp_reset_postdata();
            }

            if ($has_content) {
                ?>
                <div class="spe-list-block spe-filter-<?php echo esc_html($alphabetCharacter); ?> mix" data-filter-base="<?php echo esc_html($alphabetCharacter); ?>">
                    <h3 class="spe-list-block-heading"> <?php echo esc_html($alphabetCharacter); ?> </h3>
                    <ul class="spe-list-items list-unstyled tag_list">
                        <?php
                        if ( $sections->have_posts() ) {
                            while ( $sections->have_posts() ) { $sections->the_post();
                
                                $doc_items = get_children(array(
                                    'post_parent'    => get_the_ID(),
                                    'post_type'      => 'docs',
                                    'post_status'     => is_user_logged_in() ? ['publish', 'private', 'protected'] : ['publish', 'protected'],
                                    'orderby'        => 'menu_order',
                                    'order'          => 'ASC',
                                    'posts_per_page' => -1,
                                ));

                                if ( ! empty( $doc_items ) ) {
                                    foreach ( $doc_items as $doc_item ) {
                                        $title 		 = $doc_item->post_title;
                                        $firstLetter = substr($title, 0, 1);
                                        if (strtolower($firstLetter) === $alphabetCharacter) {
                                            ?>
                                            <li class="spe-list-item">
                                                <a class="spe-list-item-title ct-content-text" href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>" <?php echo $settings['tooltip'] == 'yes' ? 'data-tooltip-content="#' . esc_attr($doc_item->ID) . '"' : ''; ?>>
                                                    <?php echo wp_kses_post($doc_item->post_title) ?>
                                                </a>
                                                <?php 
                                                if ( $settings['tooltip'] == 'yes' ) {
                                                    ?>
                                                    <div class="tooltip_templates ezd-d-none">
                                                        <div id="<?php echo esc_attr( $doc_item->ID ); ?>" class="tip_content">
                                                            <div class="text">
                                                                <h4> <?php echo wp_kses_post($doc_item->post_title) ?> </h4>
                                                                <p>
                                                                    <?php
                                                                    if ( ! empty( get_the_excerpt( $doc_item->ID ) ) ) {
                                                                        echo wp_kses_post( wp_trim_words( get_the_excerpt($doc_item->ID), $settings['tooltip_content_limit'] ) );
                                                                    } else {
                                                                        echo wp_kses_post( wp_trim_words( get_the_content(), $settings['tooltip_content_limit'] ) );
                                                                    }
                                                                    ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php
                                                }
                                                ?>
                                            </li>
                                            <?php
                                        }
                                    }
                                }
                            }
                            wp_reset_postdata();
                        }
                        ?>
                    </ul>
                </div>
                <?php
                }
            }
        }
        ?>
    </div>
</div>
