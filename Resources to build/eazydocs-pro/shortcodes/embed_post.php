<?php
add_shortcode( 'embed_post', function( $atts, $content ) {
	ob_start();
 
	$atts = shortcode_atts( [
		'id' 		=> '',
		'limit' 	=> '75',
		'thumbnail' => 'yes'
	], $atts );

	$doc_id = $atts['id'] ?? '';

	if ( empty( $doc_id ) ) {
		return '';
	}
	
	$post_type = get_post_type( $doc_id );

	$args = [
		'post_type' 		=> $post_type,		
		'posts_per_page' 	=> 1,
		'post_status' 		=> 'publish',
		'p' 				=> $doc_id,
	];
	
	$docs = new WP_Query( $args );

	while($docs->have_posts()) : $docs->the_post();
	?>
	<div class="media documentation_item bs-md embed-post">
		<?php 
		if ( 'yes' === $atts['thumbnail'] ) :
			?>
			<div class="embed-post-icon">
				<?php echo has_post_thumbnail( $doc_id ) ? get_the_post_thumbnail( $doc_id, 'ezd_embed_thumb' ) : '<img src="' . esc_url( plugins_url( 'eazydocs/assets/images/icon/folder.png' ) ) . '" />'; ?>				
			</div>
			<?php 
		endif;
		?>
		<div class="media-body">
			<a href="<?php the_permalink(); ?>" class="doc-sec title">
				<?php the_title(); ?>               
			</a>
			<?php
			if ( 'no' === $atts['limit'] ) {
				echo wp_kses_post( get_the_content($doc_id) );
			} else {
				echo wp_kses_post( wp_trim_words( get_the_content($doc_id), $atts['limit'], '' ) );
			}
			?>
		</div>
	</div>
	<?php
	endwhile;
	wp_reset_postdata();
	$html = ob_get_clean();
	return $html . '<br>';
});