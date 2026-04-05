<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Antimanual\API;

add_action( 
    'wp_enqueue_scripts',
    function () {
        if ( ! function_exists( 'is_bbpress' ) || ! is_bbpress() ) {
            return;
        }

        $build = require ANTIMANUAL_DIR . 'build/frontend.asset.php';

        wp_enqueue_script(
            'antimanual-react-frontend',
            ANTIMANUAL_URL . 'build/frontend.js',
            $build['dependencies'],
            $build['version'],
            true
        );

        wp_enqueue_style( 'antimanual-react-frontend', ANTIMANUAL_URL . 'build/frontend.css', [ 'wp-components' ], ANTIMANUAL_VERSION );

        // Inline styles for the AI forum reply source-links block (.atml-forum-sources).
        wp_add_inline_style( 'antimanual-react-frontend', '
            .atml-forum-sources {
                margin-top: 16px;
                padding: 10px 14px;
                background: #f8f9fa;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                font-size: 13px;
            }
            .atml-forum-sources p {
                margin: 0 0 6px;
                color: #6c757d;
                font-size: 12px;
            }
            .atml-forum-sources ul {
                margin: 0;
                padding: 0;
                list-style: none;
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .atml-forum-sources ul li {
                margin: 0;
            }
            .atml-forum-sources ul li a {
                color: #374151;
                text-decoration: none;
                font-size: 13px;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                transition: color 0.2s ease;
            }
            .atml-forum-sources ul li a:hover {
                color: #2563eb;
                text-decoration: underline;
                text-underline-offset: 2px;
            }
        ' );


        // Get current topic context for reply forms
        $current_topic_id = 0;
        $current_topic_title = '';
        $thread_context = '';
        
        if ( bbp_is_single_topic() ) {
            $current_topic_id = bbp_get_topic_id();
            $current_topic_title = bbp_get_topic_title( $current_topic_id );
            
            // Get thread context (original topic + existing replies) for AI context
            $topic_content = bbp_get_topic_content( $current_topic_id );
            $replies = bbp_get_all_child_ids( $current_topic_id, bbp_get_reply_post_type() );
            
            $thread_context = "Original Topic:\n" . wp_strip_all_tags( $topic_content ) . "\n\n";
            
            if ( ! empty( $replies ) ) {
                $thread_context .= "Previous Replies:\n";
                $reply_count = 0;
                foreach ( array_slice( $replies, -5 ) as $reply_id ) { // Last 5 replies for context
                    $reply_author = bbp_get_reply_author_display_name( $reply_id );
                    $reply_content = wp_strip_all_tags( bbp_get_reply_content( $reply_id ) );
                    $thread_context .= "- {$reply_author}: {$reply_content}\n";
                    $reply_count++;
                }
            }
        }

        // Get current user info for AI response filtering
        $current_user = wp_get_current_user();
        $ai_author_id = intval( atml_option( 'bbp_reply_author_id' ) );
        $excluded_roles = atml_option( 'bbp_excluded_roles' );
        $excluded_roles = is_array( $excluded_roles ) ? $excluded_roles : [];

        wp_localize_script(
            'antimanual-react-frontend',
            'antimanual',
            [
                'is_pro' => atml_is_pro(),
                'ajax_url'  => admin_url( 'admin-ajax.php' ),
                'rest_url' => API::url(),
                'nonce'    => [
                    'ai_response_to_topic' => wp_create_nonce( 'antimanual_response_to_topic' ),
                    'ai_response_to_reply' => wp_create_nonce( 'antimanual_response_to_reply' ),
                ],
                'options' => [
                    'ai_response_to_topic' => atml_option( 'bbp_response_to_topic' ),
                    'ai_response_to_reply' => atml_option( 'bbp_response_to_reply' ),
                    'ai_response_notice'   => atml_option( 'bbp_response_disclaimer' ),
                    'ai_reply_min_words'   => intval( atml_option( 'bbp_reply_min_words' ) ),
                ],
                'current_user' => [
                    'id'    => $current_user->ID,
                    'roles' => $current_user->roles,
                ],
                'ai_author_id'    => $ai_author_id,
                'excluded_roles'  => $excluded_roles,
                'topic_context' => [
                    'topic_id'    => $current_topic_id,
                    'topic_title' => $current_topic_title,
                    'thread'      => $thread_context,
                ],
            ]
        );
    }
);

