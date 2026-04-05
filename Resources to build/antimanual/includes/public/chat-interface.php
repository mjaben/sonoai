<?php

use Antimanual\API;
/**
 * Enqueue Scripts and css for frontend for Antimanual Chatbot
 *
 * @package Antimanual_Chatbot
 * @since   1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function antimanual_enqueue_chatbot_scripts() {
	if ( is_admin() || is_login() || ! atml_is_module_enabled( 'chatbot' ) || ! atml_option( 'chatbot_enabled' ) ) {
		return;
	}

	wp_enqueue_script( 'antimanual-custom', ANTIMANUAL_JS . 'chatbot.js', [ 'jquery', 'wp-i18n' ], ANTIMANUAL_VERSION, true );
	wp_enqueue_style( 'antimanual-custom', ANTIMANUAL_URL . 'assets/css/frontend-custom.css', [], ANTIMANUAL_VERSION );

	// Attach the user-configured chatbot variables inline so public pages do not
	// trigger an extra uncached admin-ajax stylesheet request on every load.
	$dynamic_css = function_exists( 'antimanual_get_dynamic_css' ) ? antimanual_get_dynamic_css() : '';
	if ( '' !== $dynamic_css ) {
		wp_add_inline_style( 'antimanual-custom', $dynamic_css );
	}

	$welcome_message = atml_option( 'chatbot_wlc_msg' );
	$is_pro          = atml_is_pro();
	wp_localize_script(
		'antimanual-custom',
		'antimanual_chatbot_vars',
		[
			'ajaxurl'         => admin_url( 'admin-ajax.php' ),
			'rest_url'        => API::url(),
			'bot_avatar'      => ANTIMANUAL_URL . 'assets/icons/bot.png',
			'welcome_message' => $welcome_message,
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'lead'            => [
				'collect_email'  => $is_pro ? atml_boolval_mixed( atml_option( 'chatbot_collect_email' ) ) : false,
				'email_required' => $is_pro ? atml_boolval_mixed( atml_option( 'chatbot_email_required' ) ) : false,
				'collect_name'   => $is_pro ? atml_boolval_mixed( atml_option( 'chatbot_collect_name' ) ) : false,
				'name_required'  => $is_pro ? atml_boolval_mixed( atml_option( 'chatbot_name_required' ) ) : false,
				'prompt'         => $is_pro ? atml_option( 'chatbot_email_prompt' ) : '',
			],
			'mobile'          => [
				'show_on_mobile' => $is_pro ? atml_boolval_mixed( atml_option( 'chatbot_show_on_mobile' ) ) : true,
				'position'       => $is_pro ? atml_option( 'chatbot_mobile_position' ) : 'bottom-right',
			],
			'display'         => [
				'desktop_position'  => atml_option( 'chatbot_desktop_position' ) ?: 'bottom-right',
				'input_placeholder' => atml_option( 'chatbot_input_placeholder' ) ?: '',
				'suggested_label'   => atml_option( 'chatbot_suggested_label' ) ?: '',
				'show_avatar'       => atml_boolval_mixed( atml_option( 'chatbot_show_avatar' ) ),
				'custom_avatar_url' => atml_option( 'chatbot_custom_avatar_url' ) ?: '',
			],
			'feedback_enabled' => atml_boolval_mixed( atml_option( 'chatbot_feedback_enabled' ) ),
			'irrelevant_ans'   => atml_option( 'chatbot_irrelevant_ans' ) ?: '',
			'escalation'       => [
				'enabled' => $is_pro ? atml_boolval_mixed( atml_option( 'chatbot_escalation_enabled' ) ) : false,
				'type'    => $is_pro ? ( atml_option( 'chatbot_escalation_type' ) ?: 'email' ) : 'email',
				'message' => $is_pro ? ( atml_option( 'chatbot_escalation_message' ) ?: '' ) : '',
				'email'   => $is_pro ? ( atml_option( 'chatbot_escalation_email' ) ?: '' ) : '',
				'url'     => $is_pro ? ( atml_option( 'chatbot_escalation_url' ) ?: '' ) : '',
				'show_button' => $is_pro ? atml_boolval_mixed( atml_option( 'chatbot_live_chat_button_enabled' ) ) : false,
				'button_label' => $is_pro ? ( atml_option( 'chatbot_live_chat_button_label' ) ?: __( 'Chat with a Human', 'antimanual' ) ) : __( 'Chat with a Human', 'antimanual' ),
				'live_chat_available' => $is_pro && class_exists('\Antimanual_Pro\LiveChat') ? \Antimanual_Pro\LiveChat::any_agent_available() : false,
			],
		]
	);
}

add_action( 'wp_enqueue_scripts', 'antimanual_enqueue_chatbot_scripts' );
