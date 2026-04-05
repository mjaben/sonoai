<?php
/**
 * Most Helpful Docs Panel
 *
 * @package EasyDocs\Admin\Analytics
 */

defined( 'ABSPATH' ) || exit;

include_once __DIR__ . '/../doc-rank-helper.php';

$posts     = get_posts(
	array(
		'post_type'      => 'docs',
		'posts_per_page' => -1,
		'inclusive'      => true,
	)
);
$post_data = array();

foreach ( $posts as $key => $post ) {
	$post_data[ $key ]['post_id']        = $post->ID;
	$post_data[ $key ]['post_title']     = $post->post_title;
	$post_data[ $key ]['post_edit_link'] = get_edit_post_link( $post->ID );
	$post_data[ $key ]['post_permalink'] = get_permalink( $post->ID );
	$post_data[ $key ]['positive_time']  = array_sum( get_post_meta( $post->ID, 'positive', false ) );
	$post_data[ $key ]['negative_time']  = array_sum( get_post_meta( $post->ID, 'negative', false ) );
}

// Sort by positive votes (highest first).
usort(
	$post_data,
	function ( $a, $b ) {
		return $b['positive_time'] <=> $a['positive_time'];
	}
);

// Filter only posts with positive votes.
$filtered_data = array_filter(
	$post_data,
	function ( $post ) {
		return $post['positive_time'] > 0;
	}
);
$filtered_data = array_values( $filtered_data );
?>

<div class="doc-ranks-panel" id="most_helpful">
	<div class="panel-header">
		<h3 class="panel-title">
			<span class="dashicons dashicons-thumbs-up"></span>
			<?php esc_html_e( 'Most Helpful Docs', 'eazydocs-pro' ); ?>
		</h3>
		<span class="panel-subtitle">
			<?php
			printf(
				/* translators: %d: number of docs */
				esc_html( _n( '%d doc with positive feedback', '%d docs with positive feedback', count( $filtered_data ), 'eazydocs-pro' ) ),
				count( $filtered_data )
			);
			?>
		</span>
	</div>
	
	<ol class="dd-list doc-ranks-list">
		<?php
		if ( ! empty( $filtered_data ) ) {
			foreach ( $filtered_data as $key => $post ) {
				// Show first 10 by default.
				if ( $key >= 0 && $key <= 9 ) {
					ezd_render_doc_rank_item( $post, $key, 'most_helpful' );
				}
			}
		} else {
			ezd_render_empty_state( 'most_helpful' );
		}
		?>
	</ol>
</div>