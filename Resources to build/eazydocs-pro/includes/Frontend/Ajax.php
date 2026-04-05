<?php
namespace eazyDocsPro\Frontend;

class Ajax {
	public function __construct() {
		// Load contributions on Profile page
		add_action( 'wp_ajax_ezdpro_contributions_by_user', [ $this, 'contributions_by_user' ] );
		add_action( 'wp_ajax_nopriv_ezdpro_contributions_by_user', [ $this, 'contributions_by_user' ] );

		// Load activities on Profile page
		add_action( 'wp_ajax_ezdpro_activities_by_user', [ $this, 'activities_by_user' ] );
		add_action( 'wp_ajax_nopriv_ezdpro_activities_by_user', [ $this, 'activities_by_user' ] );
	}

	function contributions_by_user() {
		check_ajax_referer( 'ezdpro_contributions_nonce', 'nonce' );

		$user_id = intval( $_GET['user_id'] );
		$offset  = intval( $_GET['offset'] ?: 0 );
		$limit   = intval( $_GET['limit'] ?: 5 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'eazydocs-pro' ) );
		}

		// Use the utility function
		$contributions = \ezdpro_get_contributions_by_user( $user_id, $limit, $offset );

		wp_send_json_success( $contributions );
	}

	public function activities_by_user() {
		check_ajax_referer( 'ezdpro_activities_nonce', 'nonce' );

		$user_id = intval( $_GET['user_id'] );
		$offset  = intval( $_GET['offset'] ?: 0 );
		$limit   = intval( $_GET['limit'] ?: 5 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'eazydocs-pro' ) );
		}

		// Use the utility function
		$activities = \ezdpro_get_activities_by_user( $user_id, $limit, $offset );

		wp_send_json_success( $activities['activities'] );
	}
}