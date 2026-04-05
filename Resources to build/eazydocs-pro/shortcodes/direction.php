<?php
add_shortcode( 'direction', function( $atts, $content ) {
    ob_start();
    $directions = explode( '>', $content );
    if ( is_array($directions) ) :
        ?>
        <span class="direction_steps">
            <?php
            foreach ( $directions as $direction ) {
                echo '<span class="direction_step">';
                echo esc_html( $direction );
                echo '</span>';
            }
            ?>
        </span>
        <?php
    endif;
    $html = ob_get_clean();
    return $html;
});