<?php

/**
 * Admin Page Setting HTML JS and CSS for Antimanual Chatbot
 *
 * @package Antimanual_Chatbot
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\API;

/**
 * Render the Antimanual admin page.
 *
 * @return void
 */
function antimanual_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'antimanual-chatbot-admin', ANTIMANUAL_URL . 'assets/css/admin.css', array(), ANTIMANUAL_VERSION );

	// Reuse the same generated CSS inline in admin instead of incurring a second
	// admin-ajax stylesheet request just to set CSS custom properties.
	$dynamic_css = function_exists( 'antimanual_get_dynamic_css' ) ? antimanual_get_dynamic_css() : '';
	if ( '' !== $dynamic_css ) {
		wp_add_inline_style( 'antimanual-chatbot-admin', $dynamic_css );
	}

	if ( isset( $_POST['antimanual_save_settings'] ) ) {
		if ( ! isset( $_POST['antimanual_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['antimanual_settings_nonce'] ?? '' ) ), 'antimanual_settings' ) ) {
			wp_die( 'Invalid nonce' );
		}

		$post_data = wp_unslash( $_POST );
		atml_save_chatbot_configs( $post_data );
		atml_save_openai_configs( $post_data );
	}

	$asset_path         = ANTIMANUAL_DIR . 'build/admin.asset.php';
	$script_path        = ANTIMANUAL_DIR . 'build/admin.js';
	$style_path         = ANTIMANUAL_DIR . 'build/admin.css';
	$runtime_path       = ANTIMANUAL_DIR . 'build/runtime.js';
	$runtime_asset_path = ANTIMANUAL_DIR . 'build/runtime.asset.php';

	if ( file_exists( $asset_path ) && file_exists( $script_path ) ) {
		$build        = require $asset_path;
		$dependencies = $build['dependencies'];

		if ( file_exists( $runtime_path ) && file_exists( $runtime_asset_path ) ) {
			$runtime = require $runtime_asset_path;
			wp_enqueue_script(
				'antimanual-runtime',
				ANTIMANUAL_URL . 'build/runtime.js',
				$runtime['dependencies'],
				$runtime['version'],
				true
			);
			$dependencies = array_unique( array_merge( array( 'antimanual-runtime' ), $dependencies ) );
		}

		wp_enqueue_script( 'antimanual-react-admin', ANTIMANUAL_URL . 'build/admin.js', $dependencies, $build['version'], true );
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( 'antimanual-react-admin', ANTIMANUAL_URL . 'build/admin.css', array( 'wp-components' ), ANTIMANUAL_VERSION );
		}
	} else {
		if ( file_exists( ANTIMANUAL_DIR . 'assets/js/chatbot-admin.js' ) ) {
			wp_enqueue_script( 'antimanual-admin-legacy', ANTIMANUAL_URL . 'assets/js/chatbot-admin.js', array( 'jquery' ), ANTIMANUAL_VERSION, true );
		}
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Antimanual admin assets are missing. Please run the build step to generate build/admin.js and build/admin.asset.php.', 'antimanual' ) . '</p></div>';
			}
		);
	}

	$fetched_post_types = antimanual_get_post_types( 'array' );
	$post_types         = array();
	$exclude_taxonomies = array( 'post_format' );

	foreach ( $fetched_post_types as $post_type ) {
		$has_editor = post_type_supports( $post_type->name, 'editor' );
		$supports   = array_keys( get_all_post_type_supports( $post_type->name ) );

		$new_post_type = array(
			'name'         => $post_type->name,
			'label'        => $post_type->label,
			'has_editor'   => $has_editor,
			'hierarchical' => $post_type->hierarchical,
			'supports'     => $supports,
			'taxonomies'   => array(),
		);

		$taxonomies = get_object_taxonomies( $post_type->name, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! in_array( $taxonomy->name, $exclude_taxonomies ) ) {
				$new_taxonomy = array(
					'name'   => $taxonomy->name,
					'label'  => $taxonomy->label,
					'labels' => array(
						'singular_name' => $taxonomy->labels->singular_name ?? '',
						'plural_name'   => $taxonomy->labels->plural_name ?? '',
					),
				);

				$new_post_type['taxonomies'][] = $new_taxonomy;
			}
		}

		$post_types[] = $new_post_type;
	}

	$chatbot_configs = atml_get_chatbot_configs();
	$openai_configs  = atml_get_openai_configs();
	$gemini_configs  = atml_get_gemini_configs();
	$current_user    = wp_get_current_user();

	$forumax_info  = atml_get_plugin_info( 'bbp-core', 'bbp-core', 'forumax' );
	$eazydocs_info = atml_get_plugin_info( 'eazydocs' );
	$aab_info      = \Antimanual\FAQGenerator::check_aab_plugin_status();

	// Load module preferences.
	$module_defaults         = \Antimanual\Api\PreferencesController::MODULE_DEFAULTS;
	$module_saved            = get_option( 'antimanual_module_prefs', [] );
	$module_prefs            = wp_parse_args( is_array( $module_saved ) ? $module_saved : [], $module_defaults );
	$module_uninstall_prefs  = \Antimanual\Uninstall::get_module_uninstall_preferences();
	$module_uninstall_info   = \Antimanual\Uninstall::get_module_uninstall_ui_data();

	wp_localize_script(
		'antimanual-react-admin',
		'antimanual',
		array(
			'is_pro'               => atml_is_pro(),
			'module_prefs'         => $module_prefs,
			'module_uninstall_prefs' => $module_uninstall_prefs,
			'module_uninstall_info'  => $module_uninstall_info,
			'is_seo_plus'          => atml_is_seo_plus(),
			'is_pro_campaign'      => atml_is_pro_campaign(),
			'subscriber_limit'     => atml_get_subscriber_limit(),
			'subscriber_count'     => \Antimanual\EmailSubscribers::get_total_count(),
			'pricing_details_url'  => atml_pricing_details_url(),
			'buy_pro_url'          => atml_buy_pro_url(),
			'admin_url'            => admin_url( 'admin.php' ),
			'ajax_url'             => admin_url( 'admin-ajax.php' ),
			'rest_url'             => API::url(),
			'build_url'            => ANTIMANUAL_URL . 'build/',
			'plugin_url'           => ANTIMANUAL_URL,
			'is_public_site'       => atml_is_public_site(),
			'has_eazydocs_pro'     => atml_has_eazydocs_pro(),
			'last_active_provider' => atml_option( 'last_active_provider' ) ?: 'openai',
			'nonce'                => array(
				'doc_outline'                => wp_create_nonce( 'antimanual_doc_outline' ),
				'generate_doc'               => wp_create_nonce( 'antimanual_generate_doc' ),
				'topic_response_preferences' => wp_create_nonce( 'antimanual_topic_response_preferences' ),
				'get_posts_by_type'          => wp_create_nonce( 'antimanual_get_posts_by_type' ),
				'get_parent_posts'           => wp_create_nonce( 'antimanual_get_parent_posts' ),
				'get_excerpt'                => wp_create_nonce( 'antimanual_process' ),
				'set_terms'                  => wp_create_nonce( 'antimanual_process' ),
				'get_taxonomy_terms'         => wp_create_nonce( 'antimanual_process' ),
				'set_taxonomy_terms'         => wp_create_nonce( 'antimanual_set_taxonomy_terms' ),
				'convert_topic'              => wp_create_nonce( 'antimanual_convert_topic' ),
				'translation'                => wp_create_nonce( 'atml_translation' ),
			),
			'openai'               => array(
				'has_key'          => ! empty( $openai_configs['api_key'] ),
				'masked_key'       => \Antimanual\Encryption::mask_api_key( $openai_configs['api_key'] ),
				'model'            => $openai_configs['response_model'],
				'chat_models'      => $GLOBALS['ATML_STORE']['openai_chat_models'],
			),
			'gemini'               => array(
				'has_key'          => ! empty( $gemini_configs['api_key'] ),
				'masked_key'       => \Antimanual\Encryption::mask_api_key( $gemini_configs['api_key'] ),
				'model'            => $gemini_configs['response_model'],
				'chat_models'      => $GLOBALS['ATML_STORE']['gemini_chat_models'],
			),
			'options'              => array(
				'ai_response_to_topic'   => atml_option( 'bbp_response_to_topic' ),
				'ai_response_to_reply'   => atml_option( 'bbp_response_to_reply' ),
				'ai_response_as_reply'   => atml_option( 'bbp_response_as_reply' ),
				'ai_response_notice'     => atml_option( 'bbp_response_disclaimer' ),
				'ai_author_id'           => atml_option( 'bbp_reply_author_id' ),
				'ai_reply_min_words'     => atml_option( 'bbp_reply_min_words' ),
				'excluded_roles'         => atml_option( 'bbp_excluded_roles' ),
				'ai_response_tone'       => atml_option( 'bbp_response_tone' ),
				'ai_response_length'     => atml_option( 'bbp_response_length' ),
				'ai_custom_instructions' => atml_option( 'bbp_custom_instructions' ),
				'forum_kb_mapping'       => atml_option( 'bbp_forum_kb_mapping' ),
			),
			'available_roles'      => array_map(
				function ( $role, $details ) {
					return array(
						'value' => $role,
						'label' => translate_user_role( $details['name'] ),
					);
				},
				array_keys( wp_roles()->roles ),
				wp_roles()->roles
			),
			'chatbot'              => array(
				'icons'  => array(
					array(
						'value' => 'message',
						'label' => __( 'Message', 'antimanual' ),
						'url'   => ANTIMANUAL_ICONS . 'message.svg',
					),
					array(
						'value' => 'comments',
						'label' => __( 'Comments', 'antimanual' ),
						'url'   => ANTIMANUAL_ICONS . 'comments.svg',
					),
					array(
						'value' => 'circle-question',
						'label' => __( 'Question', 'antimanual' ),
						'url'   => ANTIMANUAL_ICONS . 'circle-question.svg',
					),
					array(
						'value' => 'robot',
						'label' => __( 'Robot', 'antimanual' ),
						'url'   => ANTIMANUAL_ICONS . 'robot.svg',
					),
					array(
						'value' => 'headset',
						'label' => __( 'Headset', 'antimanual' ),
						'url'   => ANTIMANUAL_ICONS . 'headset.svg',
					),
				),
				'config' => $chatbot_configs,
				'is_live_chat_available' => class_exists('\Antimanual_Pro\LiveChat') ? \Antimanual_Pro\LiveChat::is_available( get_current_user_id() ) : false,
			),
			'users'                => get_users(
				array(
					'fields'   => array( 'ID', 'display_name', 'user_login' ),
					'role__in' => array( 'author', 'editor', 'administrator' ),
				)
			),
			'current_user'         => array(
				'id'    => $current_user->ID,
				'roles' => $current_user->roles,
			),
			'post_types'           => $post_types,
			'plugins'              => array(
				'forumax'                  => $forumax_info,
				'eazydocs'                 => $eazydocs_info,
				'advanced_accordion_block' => $aab_info,
			),
		)
	);

	echo '<div class="wrap atml-container"></div>';
}
