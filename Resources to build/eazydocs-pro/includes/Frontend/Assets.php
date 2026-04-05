<?php
namespace eazyDocsPro\Frontend;
/**
 * Class Assets
 * @package EazyDocs\Admin
 */
class Assets {
	
	/**
	 * Assets constructor.
	 */
	public function __construct() {
		add_action('wp_enqueue_scripts', [$this, 'eazydocs_pro_wp_scripts'], 99 );
	}

	/**
	 * Register scripts and styles
	 */
	public function eazydocs_pro_wp_scripts() {
		if ( eazydocspro_assistant_assets() == true ) {
			wp_enqueue_style('eazydocs-assistant', EAZYDOCSPRO_ASSETS . '/css/assistant.css');
			wp_enqueue_script('eazydocs-assistant', EAZYDOCSPRO_ASSETS . '/js/assistant.js');
						
			$localized_settings = [
				'ajax_url'	=> admin_url( 'admin-ajax.php' ),
				'nonce'  	=> wp_create_nonce( 'eazydocs_assistant_nonce' ),			
			];
			wp_localize_script( 'eazydocs-assistant', 'eazydocs_assistant', $localized_settings );
		}

		wp_register_style( 'eazydocs-tooltip', EAZYDOCSPRO_ASSETS . '/vendors/tooltipster/tooltipster.bundle.css');
		wp_register_style( 'eazydocs-pro-frontend', EAZYDOCSPRO_CSS . '/ezd-pro.css' );
		wp_register_script( 'eazydocs-pro-frontend', EAZYDOCSPRO_ASSETS . '/js/frontend.js', array( 'jquery' ), true, true );
		wp_register_script( 'eazydocs-tooltip', EAZYDOCSPRO_ASSETS . '/vendors/tooltipster/tooltipster.bundle.min.js', array( 'jquery' ), true, true );
		
		if ( ezydocspro_frontend_assets() == true ) {
			wp_enqueue_style( 'eazydocs-pro-frontend' );

			// Mark JS for left sidebar search field
			if ( function_exists( 'ezd_get_opt' ) ){
				$word_mark = ezd_get_opt( 'search_mark_word', 'eazydocs_settings' );
				if ( $word_mark == 1 ) {
					wp_enqueue_script( 'ezd-mark', EAZYDOCSPRO_ASSETS . '/js/mark.js' );
					wp_enqueue_script( 'jquery-mark', EAZYDOCSPRO_ASSETS . '/js/jquery.mark.min.js' );
				}
			}

			wp_enqueue_script( 'eazydocs-local-ajax', EAZYDOCSPRO_ASSETS . '/js/ajax.js' );
			$localized_settings = [
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				'eazydocs_local_nonce'  => wp_create_nonce( 'eazydocs_local_nonce' ),
				'current_page' 			=> get_query_var( 'paged' ) ? get_query_var('paged') : 1,
				'selected_comment' 			=> eazydocspro_get_option('enable-selected-comment'),
				'assistant_not_found_words' => __( 'Please type a keyword to search for contents.', 'eazydocs-pro' ),
				'docs_id'  					=> get_the_ID(),
				'ezd_selected_comment_data' => function_exists('ezd_selected_comment_settings') ? ezd_selected_comment_settings() : []
			];

			wp_localize_script( 'eazydocs-local-ajax', 'eazydocs_ajax_search', $localized_settings );
			wp_enqueue_script( 'eazydocs-pro-frontend' );
		}

		if ( ezdpro_is_profile_page() ) {
			wp_enqueue_style( 'eazydocs-pro-profile', EAZYDOCSPRO_CSS . '/profile.css' );
		}
	}
}