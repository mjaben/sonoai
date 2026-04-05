<?php
/**
 * Ajax Tab Loader for Analytics Page
 * Handles lazy loading of tab content to optimize performance.
 *
 * @package EasyDocs\Admin\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle AJAX request to load analytics tab content.
 *
 * @return void
 */
function ezd_load_analytics_tab() {
	// Verify nonce for security.
	check_ajax_referer( 'ezd_analytics_nonce', 'nonce' );

	// Check user capability.
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ) );
		return;
	}

	$tab_id = isset( $_POST['tab_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tab_id'] ) ) : '';

	if ( empty( $tab_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid tab ID.', 'eazydocs-pro' ) ) );
		return;
	}

	// Map tab IDs to their respective template files.
	$tab_templates = array(
		'analytics-overview'      => 'parts/overview.php',
		'analytics-views'         => 'parts/views.php',
		'analytics-feedback'      => 'parts/feedback.php',
		'analytics-search'        => 'parts/search.php',
		'analytics-helpful'       => 'doc-ranks.php',
		'analytics-collaboration' => 'parts/collaboration.php',
	);

	if ( ! isset( $tab_templates[ $tab_id ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown tab.', 'eazydocs-pro' ) ) );
		return;
	}

	$template_file = dirname( __FILE__ ) . '/' . $tab_templates[ $tab_id ];

	if ( ! file_exists( $template_file ) ) {
		wp_send_json_error( array( 'message' => __( 'Template file not found.', 'eazydocs-pro' ) ) );
		return;
	}

	// Buffer output to capture the HTML.
	ob_start();
	include $template_file;
	$content = ob_get_clean();

	wp_send_json_success(
		array(
			'tab_id'  => $tab_id,
			'content' => $content,
		)
	);
}
add_action( 'wp_ajax_ezd_load_analytics_tab', 'ezd_load_analytics_tab' );
