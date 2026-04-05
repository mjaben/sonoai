<?php
namespace eazyDocsPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets
 * @package EazyDocs\Admin
 */
class Assets {
	/**
	 * Assets constructor.
	 */
	public function __construct() {
		// add script to admin head
		add_action( 'admin_head', [ $this, 'admin_analytics_scripts' ], 999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ], 999 );
	}

	/**
	 * Register scripts and styles [ ADMIN ]
	 */
	public function admin_scripts() {
		if ( ezydocspro_admin_assets() ) {
			wp_enqueue_style( 'ezd-pro-admin', EAZYDOCSPRO_CSS . '/ezd-pro-admin.css' );
			wp_enqueue_style( 'eazydocs-datepricker-css', '//cdn.jsdelivr.net/bootstrap.daterangepicker/2/daterangepicker.css' );

			// Load analytics page specific styles and scripts.
			if ( isset( $_GET['page'] ) && 'ezd-analytics' === $_GET['page'] ) {
				wp_enqueue_style( 'ezd-analytics', EAZYDOCSPRO_CSS . '/analytics.css', [ 'ezd-pro-admin' ], EAZYDOCSPRO_VERSION );

				// Localize analytics data for Ajax tab loading.
				wp_localize_script(
					'jquery',
					'ezdAnalytics',
					[
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'ezd_analytics_nonce' ),
					]
				);
			}

			// Load feedback page specific styles.
			if ( isset( $_GET['page'] ) && 'ezd-user-feedback' === $_GET['page'] ) {
				wp_enqueue_style( 'ezd-feedback-admin', EAZYDOCSPRO_CSS . '/ezd-feedback-admin.css', [ 'ezd-pro-admin' ], EAZYDOCSPRO_VERSION );
			}

			wp_enqueue_script( 'Sortable', EAZYDOCSPRO_ASSETS . '/js/admin.js', [ 'jquery' ], EAZYDOCSPRO_VERSION . '.' . time(), true );
			wp_enqueue_script( 'ezydocs-feedback', EAZYDOCSPRO_ASSETS . '/js/feedback.js', [ 'jquery' ], true, true );
			wp_enqueue_script( 'eazydocs-duplicate', EAZYDOCSPRO_ASSETS . '/js/duplicate.js', [ 'jquery' ], true, true );

			// Localize the script with new data
			$ajax_url              = admin_url( 'admin-ajax.php' );
			$wpml_current_language = apply_filters( 'wpml_current_language', null );
			if ( ! empty( $wpml_current_language ) ) {
				$ajax_url = add_query_arg( 'wpml_lang', $wpml_current_language, $ajax_url );
			}

			wp_localize_script( 'jquery', 'eazydocspro_local_object',
				[
					'nonce'                           => wp_create_nonce( 'ezd_analytics_nonce' ),
					'ajaxurl'                   	  => $ajax_url,
					'feedback_prompt_archive_title'   => esc_html__( 'Archive This Message', 'eazydocs-pro' ),
					'feedback_prompt_archive_desc'    => esc_html__( 'Click on the Mark as Archive button to archive this message.', 'eazydocs-pro' ),
					'feedback_prompt_open_title'      => esc_html__( 'Open This Message', 'eazydocs-pro' ),
					'feedback_prompt_open_desc'       => esc_html__( 'Click on the Mark as Open button to Open this message.', 'eazydocs-pro' ),
					'feedback_prompt_delete_title'    => esc_html__( 'Delete This Message', 'eazydocs-pro' ),
					'feedback_prompt_delete_desc'     => esc_html__( 'Click on the Delete button to delete this message.', 'eazydocs-pro' ),
					'wp_roles'                        => $this->get_wp_roles_for_visibility(),
					'is_premium'                      => ezd_is_premium() ? '1' : '0',
					'role_visibility_enable'          => ezd_get_opt( 'role_visibility_enable', true ) ? '1' : '0',
				]
			);
		}
	}

	/**
	 * Add script to admin head
	 */
	public function admin_analytics_scripts() {
		if ( ezydocspro_admin_assets() ){
			echo '<script type="text/javascript" src="//cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>';
			echo '<script type="text/javascript" src="//cdn.jsdelivr.net/bootstrap.daterangepicker/2/daterangepicker.js"></script>';
		}
	}

	/**
	 * Get all WordPress user roles for visibility options.
	 *
	 * @return array Associative array of role slug => role name.
	 */
	private function get_wp_roles_for_visibility() {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles();
		}

		$roles = [];
		foreach ( $wp_roles->roles as $key => $role ) {
			$roles[ $key ] = translate_user_role( $role['name'] );
		}

		return $roles;
	}

}