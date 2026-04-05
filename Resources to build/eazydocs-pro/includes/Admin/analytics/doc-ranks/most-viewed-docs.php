<?php
/**
 * Most Viewed Docs Panel
 *
 * @package EasyDocs\Admin\Analytics
 */

defined( 'ABSPATH' ) || exit;

include_once __DIR__ . '/../doc-rank-helper.php';

$posts = get_posts(
	array(
		'post_type'      => 'docs',
		'posts_per_page' => 10,
		'meta_key'       => 'post_views_count',
		'orderby'        => 'meta_value_num',
		'order'          => 'DESC',
	)
);

$post_data   = array();
$total_views = 0;

foreach ( $posts as $key => $post ) {
	$views = intval( get_post_meta( $post->ID, 'post_views_count', true ) );

	$post_data[ $key ]['post_id']        = $post->ID;
	$post_data[ $key ]['post_title']     = $post->post_title;
	$post_data[ $key ]['post_edit_link'] = get_edit_post_link( $post->ID );
	$post_data[ $key ]['post_permalink'] = get_permalink( $post->ID );
	$post_data[ $key ]['positive_time']  = array_sum( get_post_meta( $post->ID, 'positive', false ) );
	$post_data[ $key ]['negative_time']  = array_sum( get_post_meta( $post->ID, 'negative', false ) );
	$post_data[ $key ]['views']          = $views;

	$total_views += $views;
}
?>

<div class="doc-ranks-panel" id="most_viewed">
	<div class="panel-header">
		<h3 class="panel-title">
			<span class="dashicons dashicons-visibility"></span>
			<?php esc_html_e( 'Most Viewed Docs', 'eazydocs-pro' ); ?>
		</h3>
		<span class="panel-subtitle">
			<?php
			printf(
				/* translators: %s: total views count */
				esc_html__( 'Top docs with %s total views', 'eazydocs-pro' ),
				number_format_i18n( $total_views )
			);
			?>
		</span>
	</div>
	
	<ol class="dd-list doc-ranks-list">
		<?php
		if ( ! empty( $post_data ) ) {
			foreach ( $post_data as $key => $post ) {
				if ( $key >= 0 && $key <= 9 ) {
					ezd_render_doc_rank_item( $post, $key, 'most_viewed' );
				}
			}
		} else {
			ezd_render_empty_state( 'most_viewed' );
		}
		?>
	</ol>
</div>