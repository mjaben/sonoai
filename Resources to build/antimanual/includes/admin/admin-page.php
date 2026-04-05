<?php
/**
 * Register Admin Page for Antimanual
 *
 * @package Antimanual
 * @since   1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'antimanual_admin_menu' );
function antimanual_admin_menu() {
	$icon_svg = file_get_contents( ANTIMANUAL_DIR . 'assets/icons/antimanual.svg' );
	$icon     = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );

	$hook = add_menu_page(
		esc_html__( 'Antimanual', 'antimanual' ),
		esc_html__( 'Antimanual', 'antimanual' ),
		'manage_options',
		'antimanual',
		'antimanual_admin_page',
		$icon,
		56
	);

	foreach ( $GLOBALS['ATML_STORE']['menus'] as $sub_menu ) {
		$hook = add_submenu_page(
			'antimanual',
			$sub_menu['title'],
			$sub_menu['title'],
			'manage_options',
			$sub_menu['slug'],
			$sub_menu['callback'] ?? 'antimanual_admin_page',
		);
	}
}

function antimanual_admin_scripts( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}

	$post_type = get_post_type();
	
	// Data to localize
	$antimanual_ajax = array(
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'post_id' => get_the_ID(),
		'nonce'   => wp_create_nonce( 'antimanual_process' )
	);

	// 1. AI Excerpt Generator (chatbot-admin.js)
	if ( post_type_supports( $post_type, 'excerpt' ) ) {
		wp_enqueue_script( 
			'antimanual-chatbot-admin-js', 
			ANTIMANUAL_URL . 'assets/js/chatbot-admin.js', 
			array( 'wp-element', 'wp-i18n', 'wp-data', 'wp-dom-ready' ), 
			ANTIMANUAL_VERSION, 
			true 
		);
		wp_localize_script( 'antimanual-chatbot-admin-js', 'antimanual_ajax', $antimanual_ajax );
		wp_set_script_translations( 'antimanual-chatbot-admin-js', 'antimanual', ANTIMANUAL_DIR . 'languages' );
	}

	// 2. AI Categories & Tags Generator (tags.js)
	if ( post_type_supports( $post_type, 'post_tag' ) || post_type_supports( $post_type, 'category' ) || $post_type == 'post' ) {
		wp_enqueue_script( 
			'antimanual-chatbot-tags-js', 
			ANTIMANUAL_URL . 'assets/js/tags.js', 
			array( 'wp-element', 'wp-components', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-data' ), 
			ANTIMANUAL_VERSION, 
			true 
		);
		wp_localize_script( 'antimanual-chatbot-tags-js', 'antimanual_ajax', $antimanual_ajax );
		wp_set_script_translations( 'antimanual-chatbot-tags-js', 'antimanual', ANTIMANUAL_DIR . 'languages' );
	}
}

add_action( 'admin_enqueue_scripts', 'antimanual_admin_scripts' );