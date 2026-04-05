<?php
namespace eazyDocsPro\Frontend;

/**
 * Class Search
 * @package eazyDocsPro\Frontend
 */
class Search {

	public function __construct() {
		// feedback
		add_action( 'wp_ajax_eazydocs_ajax_search_result', [ $this, 'fetch_posts' ] );
		add_action( 'wp_ajax_nopriv_eazydocs_ajax_search_result', [ $this, 'fetch_posts' ] );
	}

	/**
	 * Store feedback for an article.
	 * @return void
	 */
	public function fetch_posts() {
        $ed_options        = get_option( 'eazydocs_settings' ); // prefix of framework
        $instant_search    = $ed_options['assistant_tab_settings']['docs_instant_answer'] ?? '';
        $not_found         = $ed_options['assistant_tab_settings']['docs_not_found'] ?? __( 'No Results Found. Please Type a different keyword', 'eazydocs-pro' );
		?>
		<div class="chatbox-posts" tab-data="post">
			<?php
			$posts = new \WP_Query( [
					'post_type' => 'docs',
					's'         => $_POST['keyword'],
				]
			);

			if ( $posts->have_posts() ):
				while ( $posts->have_posts() ):
					$posts->the_post();
					?>
                    <div class="post-item <?php if ( $instant_search == 1 ) { echo esc_attr( 'instant-search-enabled' ); } ?>" <?php if ( $instant_search == 1 ) { echo 'data-id=' . esc_attr( get_the_ID() ); } ?>>

                        <nav aria-label="breadcrumb">
							<?php eazydocs_breadcrumbs(); ?>
                        </nav>

						<h2><a href="<?php echo esc_url( get_the_permalink(get_the_ID()) ); ?>"><?php the_title(); ?></a></h2>
                        <p>
							<?php echo wp_kses_post( mb_substr( get_the_excerpt(), 0, 80, 'UTF-8' ) ); ?>
						</p>
					</div>
				<?php
				endwhile;
				wp_reset_postdata();
			else:
				?>
				<div class="post-item keyword-danger">
					<p><?php echo esc_html( $not_found ); ?></p>
				</div>
			<?php

			endif;
			?>
		</div>
		<?php
		die();
	}
}