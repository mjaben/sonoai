<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\Encryption;

/**
 * List of sensitive option names that should be encrypted.
 *
 * @return array List of option names that require encryption.
 */
function atml_get_sensitive_options(): array {
	return [
		'openai_api_key',
		'gemini_api_key',
        'github_access_token',
		'telegram_bot_token',
	];
}

/**
 * Check if an option name is sensitive and should be encrypted.
 *
 * @param string $option_name The option name to check.
 * @return bool True if the option is sensitive.
 */
function atml_is_sensitive_option( string $option_name ): bool {
	return in_array( $option_name, atml_get_sensitive_options(), true );
}

/**
 * Get an Antimanual option.
 *
 * @param string $option_name The option key.
 * @param mixed  $default     Optional. Default value to use if not defined in defaults.
 * @return mixed The option value.
 */
function atml_option( $option_name, $default = null ) {
    $default_options = [
        'last_active_provider'    => fn() => 'openai',
        'openai_api_key'          => fn() => '',
        'openai_response_model'   => fn() => 'gpt-5-mini',
        'gemini_api_key'          => fn() => '',
        'gemini_response_model'   => fn() => 'gemini-3-flash-preview',
        'github_access_token'     => fn() => '',
        'github_user'             => fn() => [],
        'bbp_response_to_topic'   => fn() => false,
        'bbp_response_to_reply'   => fn() => false,
        'bbp_response_as_reply'   => fn() => false,
        'bbp_response_disclaimer' => fn() => __( "While we've trained the system with extensive internal knowledge, documentation, and best practices to ensure high accuracy, please note that AI-generated content may not always be fully accurate or reflect the most up-to-date information. We strongly encourage users to verify critical information and seek human expertise when needed.", "antimanual" ),
        'bbp_reply_author_id'     => fn() => 1,
        'bbp_reply_min_words'     => fn() => 10,
        'bbp_excluded_roles'        => fn() => [],
        'bbp_forum_kb_mapping'      => fn() => [],
        'chatbot_enabled'         => fn() => true,
        'chatbot_wlc_msg'         => fn() => __( 'What can I help you with?', 'antimanual' ),
        'chatbot_title'           => fn() => __( 'Antimanual', 'antimanual' ),
        'chatbot_help_text'       => fn() => __( 'Ask our AI support assistant your questions about our platform, features, and services.', 'antimanual' ),
        'chatbot_prebuilt_1'      => fn() => __( 'How do I get started?', 'antimanual' ),
        'chatbot_prebuilt_2'      => fn() => __( 'What features are available?', 'antimanual' ),
        'chatbot_prebuilt_3'      => fn() => __( 'How can I contact support?', 'antimanual' ),
        'chatbot_icon'            => fn() => 'message',
        'chatbot_btn_txt'         => fn() => __( 'Help', 'antimanual' ),
        'chatbot_primary_color'   => fn() => '#0066CC',
        'chatbot_bg_color'        => fn() => '',
        'chatbot_user_msg_color'  => fn() => '#0066CC',
        'chatbot_header_text_color' => fn() => '#FFFFFF',
        'chatbot_border_radius'   => fn() => 'medium',
        'chatbot_font_size'       => fn() => 'medium',
        'chatbot_position'        => fn() => 'before-tabs',
        'chatbot_label'           => fn() => __( 'Antimanual', 'antimanual' ),
        'chatbot_merge_ezd'       => fn() => false,
        'chatbot_irrelevant_ans'  => fn() => __( 'Sorry, I don\'t have enough information to answer your question.', 'antimanual' ),
        // AI Response Settings
        'chatbot_response_length'   => fn() => 'balanced',
        'chatbot_creativity_level'  => fn() => 'balanced',
        'chatbot_ai_persona'        => fn() => '',
        // Lead Collection
        'chatbot_collect_email'     => fn() => false,
        'chatbot_email_required'    => fn() => false,
        'chatbot_email_prompt'      => fn() => __( 'Please enter your email to continue chatting.', 'antimanual' ),
        'chatbot_collect_name'      => fn() => false,
        'chatbot_name_required'     => fn() => false,
        // Content Moderation
        'chatbot_blocked_words'     => fn() => '',
        'chatbot_block_message'     => fn() => __( 'This message cannot be processed.', 'antimanual' ),
        // Display & Behavior
        'chatbot_desktop_position'  => fn() => 'bottom-right',
        'chatbot_input_placeholder' => fn() => '',
        'chatbot_suggested_label'   => fn() => '',
        'chatbot_show_avatar'       => fn() => true,
        'chatbot_custom_avatar_url' => fn() => '',
        // Mobile Settings
        'chatbot_show_on_mobile'    => fn() => true,
        'chatbot_mobile_position'   => fn() => 'bottom-right',
        'chatbot_live_chat_button_enabled' => fn() => false,
        'chatbot_live_chat_button_label'   => fn() => __( 'Chat with a Human', 'antimanual' ),
        'translation_languages'         => fn() => [],
        'translation_auto_new'          => fn() => false,
        'translation_show_switcher'     => fn() => true,
        'translation_show_switcher_on_translated' => fn() => false,
        'translation_switcher_position' => fn() => 'after_title',
        'translation_provider'          => fn() => 'openai',
        'translation_post_types'        => fn() => [ 'post', 'page' ],
        // Telegram Integration
        'telegram_bot_token'            => fn() => '',
        'telegram_chat_id'              => fn() => '',
        'telegram_enabled'              => fn() => false,
        'telegram_webhook_secret'       => fn() => '',
    ];

    $default_value = $default_options[ $option_name ] ?? null;
    $default_value = is_callable( $default_value ) ? $default_value() : $default_value;

    // Use provided default if no built-in default exists
    if ( null === $default_value && null !== $default ) {
        $default_value = $default;
    }

    $prefix = 'antimanual_';
    $value  = get_option( $prefix . $option_name, $default_value );

    // Decrypt sensitive options.
    if ( atml_is_sensitive_option( $option_name ) && ! empty( $value ) && is_string( $value ) ) {
        // Check if the value is encrypted.
        if ( Encryption::is_encrypted( $value ) ) {
            $decrypted = Encryption::decrypt( $value );
            if ( false !== $decrypted ) {
                $value = $decrypted;
            }
        } elseif ( Encryption::is_available() ) {
            // Value is not encrypted (legacy/plain text).
            // Migrate: encrypt and save it for future use.
            $encrypted = Encryption::encrypt( $value );
            if ( false !== $encrypted && ! empty( $encrypted ) ) {
                update_option( $prefix . $option_name, $encrypted );
            }
            // Still return the original plain value.
        }
    }

    return $value;
}

/**
 * Update an Antimanual option (alias for atml_option_save).
 *
 * @param string $option_name The option key.
 * @param mixed $value The value to save.
 * @return bool True on success.
 */
function atml_update_option( $option_name, $value ) {
    return atml_option_save( $option_name, $value );
}

/**
 * Save an Antimanual option.
 *
 * @param string $option_name The option key.
 * @param mixed $value The value to save.
 * @return bool True on success.
 */
function atml_option_save( $option_name, $value ) {
    $prefix = 'antimanual_';

    // Encrypt sensitive options before saving.
    if ( atml_is_sensitive_option( $option_name ) && ! empty( $value ) && is_string( $value ) ) {
        // Only encrypt if the value is not already encrypted.
        if ( ! Encryption::is_encrypted( $value ) && Encryption::is_available() ) {
            $encrypted = Encryption::encrypt( $value );
            if ( false !== $encrypted && ! empty( $encrypted ) ) {
                $value = $encrypted;
            }
        }
    }

    $result = update_option( $prefix . $option_name, $value );

    return $result;
}
