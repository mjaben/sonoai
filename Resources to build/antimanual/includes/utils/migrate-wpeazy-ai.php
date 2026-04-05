<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! get_option( 'antimanual_is_wpeazy_ai_migrated' ) ) {
    atml_migrate_wpeazy_ai();
    update_option( 'antimanual_is_wpeazy_ai_migrated', true );
}

function atml_migrate_wpeazy_ai() {
    atml_migrate_wpeazyai_options();
    atml_migrate_wpeazyai_databases();
    atml_migrate_wpeazyai_post_types();
}

function atml_migrate_wpeazyai_options() {
    $api_key             = get_option( 'wpeazyai_api_key'             );
    $model               = get_option( 'wpeazyai_model'               );

    $response_to_topic   = get_option( 'wpeazyai_response_to_topic'   );
    $response_as_reply   = get_option( 'wpeazyai_response_as_reply'   );
    $response_disclaimer = get_option( 'wpeazyai_response_disclaimer' );
    $author_id           = get_option( 'wpeazyai_author_id'           );
    $enabled             = get_option( 'wpeazyai_enabled'             );
    $welcome_message     = get_option( 'wpeazyai_welcome_message'     );
    $title               = get_option( 'wpeazyai_title'               );
    $help_text           = get_option( 'wpeazyai_help_text'           );
    $prebuilt_1          = get_option( 'wpeazyai_prebuilt_1'          );
    $prebuilt_2          = get_option( 'wpeazyai_prebuilt_2'          );
    $prebuilt_3          = get_option( 'wpeazyai_prebuilt_3'          );
    $chat_icon           = get_option( 'wpeazyai_chat_icon'           );
    $button_text         = get_option( 'wpeazyai_button_text'         );
    $primary_color       = get_option( 'wpeazyai_primary_color'       );
    $chat_bg_color       = get_option( 'wpeazyai_chat_bg_color'       );
    $chatbox_position    = get_option( 'wpeazyai_chatbox_position'    );
    $merge_eazydocs      = get_option( 'wpeazyai_merge_eazydocs'      );
    $chatbot_label       = get_option( 'wpeazyai_chatbot_label'       );

    update_option( 'antimanual_openai_api_key',          get_option( 'antimanual_openai_api_key',          $api_key             ) );
    update_option( 'antimanual_openai_response_model',   get_option( 'antimanual_openai_response_model',   $model               ) );

    update_option( 'antimanual_bbp_response_to_topic',   get_option( 'antimanual_bbp_response_to_topic',   $response_to_topic   ) );
    update_option( 'antimanual_bbp_response_as_reply',   get_option( 'antimanual_bbp_response_as_reply',   $response_as_reply   ) );
    update_option( 'antimanual_bbp_response_disclaimer', get_option( 'antimanual_bbp_response_disclaimer', $response_disclaimer ) );
    update_option( 'antimanual_bbp_reply_author_id',     get_option( 'antimanual_bbp_reply_author_id',     $author_id           ) );
    update_option( 'antimanual_chatbot_enabled',         get_option( 'antimanual_chatbot_enabled',         $enabled             ) );
    update_option( 'antimanual_chatbot_wlc_msg',         get_option( 'antimanual_chatbot_wlc_msg',         $welcome_message     ) );
    update_option( 'antimanual_chatbot_title',           get_option( 'antimanual_chatbot_title',           $title               ) );
    update_option( 'antimanual_chatbot_help_text',       get_option( 'antimanual_chatbot_help_text',       $help_text           ) );
    update_option( 'antimanual_chatbot_prebuilt_1',      get_option( 'antimanual_chatbot_prebuilt_1',      $prebuilt_1          ) );
    update_option( 'antimanual_chatbot_prebuilt_2',      get_option( 'antimanual_chatbot_prebuilt_2',      $prebuilt_2          ) );
    update_option( 'antimanual_chatbot_prebuilt_3',      get_option( 'antimanual_chatbot_prebuilt_3',      $prebuilt_3          ) );
    update_option( 'antimanual_chatbot_icon',            get_option( 'antimanual_chatbot_icon',            $chat_icon           ) );
    update_option( 'antimanual_chatbot_btn_txt',         get_option( 'antimanual_chatbot_btn_txt',         $button_text         ) );
    update_option( 'antimanual_chatbot_primary_color',   get_option( 'antimanual_chatbot_primary_color',   $primary_color       ) );
    update_option( 'antimanual_chatbot_bg_color',        get_option( 'antimanual_chatbot_bg_color',        $chat_bg_color       ) );
    update_option( 'antimanual_chatbot_position',        get_option( 'antimanual_chatbot_position',        $chatbox_position    ) );
    update_option( 'antimanual_chatbot_label',           get_option( 'antimanual_chatbot_label',           $chatbot_label       ) );
    update_option( 'antimanual_chatbot_merge_ezd',       get_option( 'antimanual_chatbot_merge_ezd',       $merge_eazydocs      ) );
}

function atml_migrate_wpeazyai_databases() {
    global $wpdb;

    $old_embeddings_table   = $wpdb->prefix . 'wpeazyai_embeddings';
    $embeddings_table       = $wpdb->prefix . 'antimanual_embeddings';

    $old_query_votes_table  = $wpdb->prefix . 'wpeazyai_query_votes';
    $query_votes_table      = $wpdb->prefix . 'antimanual_query_votes';

    $old_embeddings_exists  = $wpdb->get_var( "SHOW TABLES LIKE '{$old_embeddings_table}'" ) === $old_embeddings_table;
    $new_embeddings_exists  = $wpdb->get_var( "SHOW TABLES LIKE '{$embeddings_table}'" ) === $embeddings_table;
    $old_query_votes_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$old_query_votes_table}'" ) === $old_query_votes_table;
    $new_query_votes_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$query_votes_table}'" ) === $query_votes_table;

    if ( $old_embeddings_exists && ! $new_embeddings_exists ) {
        $wpdb->query( "RENAME TABLE {$old_embeddings_table} TO {$embeddings_table}" );
    }

    if ( $old_query_votes_exists && ! $new_query_votes_exists ) {
        $wpdb->query( "RENAME TABLE {$old_query_votes_table} TO {$query_votes_table}" );
    }
}

function atml_migrate_wpeazyai_post_types() {
    global $wpdb;

    $wpdb->query( 
        $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
            'atml_auto_posting',
            'wea_auto_posting',
        )
    );

    $wpdb->query( 
        $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
            'atml_conversation',
            'wea_conversation',
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            SET pm.meta_key = %s
            WHERE p.post_type = %s AND pm.meta_key = %s",
            '_atml_data',
            'atml_auto_posting',
            '_wea_data',
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            SET pm.meta_key = %s
            WHERE p.post_type = %s AND pm.meta_key = %s",
            '_atml_openai_conversation_id',
            'atml_conversation',
            '_wea_openai_conversation_id',
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            SET pm.meta_key = %s
            WHERE p.post_type = %s AND pm.meta_key = %s",
            '_atml_conversation_messages',
            'atml_conversation',
            '_wea_conversation_messages'
        )
    );
}