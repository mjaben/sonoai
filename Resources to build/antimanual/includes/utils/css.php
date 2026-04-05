<?php

/**
 * Enqueue Scripts and css for both frontend and admin for Antimanual Chatbot
 *
 * @package Antimanual
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_antimanual_dynamic_css', 'antimanual_generate_dynamic_css' );
add_action( 'wp_ajax_nopriv_antimanual_dynamic_css', 'antimanual_generate_dynamic_css' );

/**
 * Build the chatbot dynamic CSS payload.
 *
 * Returning the CSS as a string lets normal page renders attach it inline,
 * which avoids an extra admin-ajax request on every frontend page load.
 * The ajax endpoint remains available for backward compatibility.
 *
 * @return string
 */
function antimanual_get_dynamic_css() {
    $css_rules = [];

    $primary_color = esc_attr( atml_option( 'chatbot_primary_color' ) );
    $chat_bg_color = esc_attr( atml_option( 'chatbot_bg_color' ) );

    if ( ! empty( $chat_bg_color ) ) {
        $css_rules[] = ":root { --antimanual_chat_bg_color: {$chat_bg_color}; }";
    }

    if ( ! empty( $primary_color ) ) {
        $css_rules[] = ":root { --antimanual_primary_color: {$primary_color}; }";
    }

    $msg_color     = esc_attr( atml_option( 'chatbot_user_msg_color' ) );
    $header_color  = esc_attr( atml_option( 'chatbot_header_text_color' ) );
    $border_radius = esc_attr( atml_option( 'chatbot_border_radius' ) );
    $font_size     = esc_attr( atml_option( 'chatbot_font_size' ) );

    if ( ! empty( $msg_color ) ) {
        $css_rules[] = ":root { --antimanual_user_msg_color: {$msg_color}; }";
    }

    if ( ! empty( $header_color ) ) {
        $css_rules[] = ":root { --antimanual_header_text_color: {$header_color}; }";
    }

    $radius_val = '12px';
    if ( $border_radius === 'small' ) {
        $radius_val = '8px';
    } elseif ( $border_radius === 'large' ) {
        $radius_val = '16px';
    }

    $css_rules[] = ":root { --antimanual_border_radius: {$radius_val}; }";

    $font_val = '14px';
    if ( $font_size === 'small' ) {
        $font_val = '12px';
    } elseif ( $font_size === 'large' ) {
        $font_val = '16px';
    }

    $css_rules[] = ":root { --antimanual_font_size: {$font_val}; }";

    return implode( "\n", $css_rules );
}

function antimanual_generate_dynamic_css() {
    header("Content-type: text/css; charset: UTF-8");

    if ( ! function_exists( 'esc_attr' ) ) {
        require_once ABSPATH . 'wp-load.php';
    }

    echo esc_html( antimanual_get_dynamic_css() );
    exit;
}