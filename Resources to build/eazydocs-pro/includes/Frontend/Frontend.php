<?php
namespace eazyDocsPro\Frontend;

class Frontend {
	public function __construct() {
		add_action( 'init', [ $this, 'register_routes' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_filter( 'template_include', [ $this, 'template_loads' ] );
        add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	/**
	 * @return array
	 *
	 * @since 1.0.0
	 */
    public function body_class( $classes ) {
        
        $current_theme 	= 'ezd-theme-'.str_replace(' ', '-', strtolower(wp_get_theme()));
        $classes[]		= $current_theme;
        
        return $classes;
    }

	public function register_routes() {
		$doc_slug = function_exists('ezd_docs_slug') ? ezd_docs_slug() : '';
		$doc_slug = $doc_slug ? '/' . $doc_slug : '';

		add_rewrite_rule( "^$doc_slug/profile/([^/]+)/?$", 'index.php?ezd_user_slug=$matches[1]', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'ezd_user_slug';
		return $vars;
	}

	public function template_loads( $template ) {
		$profile_template = EAZYDOCSPRO_PATH . '/templates/profile.php';

		if ( ezdpro_is_profile_page() ) {
			global $wp_query;
			$wp_query->is_404 = false;

			return apply_filters( 'eazydocs_template_' . $template, $profile_template );
		}

		return $template;
	}
}