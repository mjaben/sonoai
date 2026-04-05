<?php
namespace eazyDocsPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feedback_Delete
 * @package eazyDocs\Admin
 */
class Feedback_Delete {

	/**
	 * Create_Post constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'feedback_delete' ] );
	}

	/**
	 * Delete Parent Doc
	 */
	public function feedback_delete() {
		if ( isset( $_GET['feedback_delete'], $_GET['_wpnonce'] ) ) {
			// Sanitize the input values
			$feedback_delete_id = intval( $_GET['feedback_delete'] ); // Ensure it's an integer
			$data_type   = isset( $_GET['data_type'] ) ? sanitize_text_field( $_GET['data_type'] ) : '';
			$tab_type    = isset( $_GET['tab_type'] ) ? sanitize_text_field( $_GET['tab_type'] ) : '';
			$nonce 		 = sanitize_text_field( $_GET['_wpnonce'] ); // Sanitize nonce input
		 
			
			// Verify the nonce
			if ( wp_verify_nonce( $nonce, $feedback_delete_id ) ) {

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have permission to perform this action.', 'eazydocs-pro' ) );
				}

				// Check if the feedback ID exists and is a valid post
				if ( get_post_type( $feedback_delete_id ) === 'ezd_feedback' || get_post_type( $feedback_delete_id ) === 'ezd-text-feedback' ) {
					// Delete the post
					wp_delete_post( $feedback_delete_id, true );
				} 
			 
				wp_safe_redirect( admin_url( 'admin.php?page=ezd-user-feedback&tab=' . $tab_type . '&status=' . $data_type ) );
				exit;
			}
		}
	}
}