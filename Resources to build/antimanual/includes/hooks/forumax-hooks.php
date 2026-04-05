<?php
/**
 * Forumax integration hooks
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook into Forumax topic creation to handle AI-assisted closures and replies
 */
add_action( 'bbp_new_topic', 'atml_handle_new_forum_topic', 10, 4 );

function atml_handle_new_forum_topic( $topic_id, $forum_id, $anonymous_data, $topic_author ) {
    $helped      = boolval( $_POST['antimanual_response_helped'] ?? false );
    $ai_response = wp_kses_post( wp_unslash( $_POST['antimanual_response'] ?? '' ) );

    if ( ! $helped || empty( $ai_response ) ) {
        return;
    }

    if ( atml_option( 'bbp_response_as_reply' ) ) {
        $ai_author_id = atml_option( 'bbp_reply_author_id' );

        bbp_insert_reply( array(
            'post_parent'   => $topic_id,
            'post_content'  => $ai_response,
            'post_status'   => bbp_get_public_status_id(),
            'post_author'   => $ai_author_id,
        ) );
    }

    wp_update_post( array(
        'ID'          => $topic_id,
        'post_status' => bbp_get_closed_status_id(),
    ) );
}

/**
 * Hook into Forumax reply creation to handle AI-assisted replies to comments
 * This inserts an AI response as a follow-up reply when the user indicates 
 * the AI suggestion was helpful.
 */
add_action( 'bbp_new_reply', 'atml_handle_new_forum_reply', 10, 5 );

function atml_handle_new_forum_reply( $reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author ) {
    $helped      = boolval( $_POST['antimanual_response_helped'] ?? false );
    $ai_response = wp_kses_post( wp_unslash( $_POST['antimanual_response'] ?? '' ) );

    if ( ! $helped || empty( $ai_response ) ) {
        return;
    }

    // Get the configured AI author
    $ai_author_id = atml_option( 'bbp_reply_author_id' );

    // Insert the AI response as a new reply
    $ai_reply_id = bbp_insert_reply( array(
        'post_parent'  => $topic_id,
        'post_content' => $ai_response,
        'post_status'  => bbp_get_public_status_id(),
        'post_author'  => $ai_author_id,
    ), array(
        'forum_id' => $forum_id,
        'topic_id' => $topic_id,
    ) );

    // If threaded replies are enabled, set the reply-to relationship
    if ( $ai_reply_id && ! is_wp_error( $ai_reply_id ) && bbp_allow_threaded_replies() ) {
        update_post_meta( $ai_reply_id, '_bbp_reply_to', $reply_id );
    }
}

