<?php
namespace eazyDocsPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feedback update
 * @package eazyDocsPro\feedback
 */
class Feedback_Update {

	public function __construct() {
		add_action( 'admin_init', [ $this, 'ezd_feedback_update' ] );
	}

	function ezd_feedback_update() {
		if ( isset( $_GET['feedback_id'], $_GET['_wpnonce'] ) ) {

			// Sanitize and validate inputs
			$feedback_id = intval( $_GET['feedback_id'] ); // Ensure it's an integer
			$nonce 		 = sanitize_text_field( $_GET['_wpnonce'] ); // Sanitize the nonce
			$data_type   = isset( $_GET['data_type'] ) ? sanitize_text_field( $_GET['data_type'] ) : '';
			$tab_type    = isset( $_GET['tab_type'] ) ? sanitize_text_field( $_GET['tab_type'] ) : '';

			// Verify the nonce
			if ( wp_verify_nonce( $nonce, $feedback_id ) ) {				

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have permission to perform this action.', 'eazydocs-pro' ) );
				}

				// Determine the feedback type based on the data_type
				$doc_feedback	= $data_type === 'archive' ? 'open' : 'false'; 
				$text_feedback	= $data_type === 'archive' ? 'false' : 'true'; 
				
				// Check if feedback_id is valid and corresponds to the correct post type
				if ( $feedback_id && get_post_type( $feedback_id ) === 'ezd_feedback' ) {					
					update_post_meta( $feedback_id, 'ezd_feedback_status', $doc_feedback );
				}

				if ( $feedback_id && get_post_type( $feedback_id ) === 'ezd-text-feedback' ) {
					update_post_meta( $feedback_id, 'ezd_feedback_archived', $text_feedback );
				}
				
				// Redirect securely
				wp_safe_redirect( admin_url( 'admin.php?page=ezd-user-feedback&tab=' . $tab_type . '&status=' . $data_type ) );
				exit;
			}
		}
	}
}