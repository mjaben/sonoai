<?php

/**
 * Shortcodes for Antimanual Chatbot (Bootstrap 5)
 *
 * @package Antimanual_Chatbot
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
    exit;
}

// Add shortcode to wp_footer
add_action('wp_footer', 'antimanual_chatbot_wp_footer');
function antimanual_chatbot_wp_footer() {
    if ( ! atml_is_merge_with_ezd() ) {
        echo do_shortcode('[antimanual_chatbot]');
    }
}

function antimanual_chatbot_shortcode() {
    ob_start();

    $iframe_assistant_page = is_page( 'antimanual-assistant' );

    if ( $iframe_assistant_page && ! atml_is_pro() ) {
        return '';
    }

    if ( ! atml_is_module_enabled( 'chatbot' ) || ! atml_option( 'chatbot_enabled' ) || atml_is_chatbot_limit_exceeded() ) {
        return '';
    }

    $title            = atml_option( 'chatbot_title' );
    $help_text        = atml_option( 'chatbot_help_text' );
    $show_avatar      = atml_option( 'chatbot_show_avatar' ) ?? true;
    $custom_avatar    = atml_option( 'chatbot_custom_avatar_url' );
    $avatar_url       = ! empty( $custom_avatar ) ? $custom_avatar : ANTIMANUAL_URL . 'assets/icons/bot.png';
    $avatar_hidden    = ! atml_boolval_mixed( $show_avatar );
    $chatbot_icon     = sanitize_file_name( (string) atml_option( 'chatbot_icon' ) );
    if ( empty( $chatbot_icon ) ) {
        $chatbot_icon = 'message';
    }
    $header_class     = ( empty( $title ) && empty( $help_text ) ) ? 'atml-empty' : '';
    $show_live_chat_button = atml_is_pro()
        && atml_boolval_mixed( atml_option( 'chatbot_escalation_enabled' ) )
        && 'live_chat' === atml_option( 'chatbot_escalation_type' )
        && atml_boolval_mixed( atml_option( 'chatbot_live_chat_button_enabled' ) );
    $live_chat_button_label = atml_option( 'chatbot_live_chat_button_label' ) ?: __( 'Chat with a Human', 'antimanual' );

    ?>
    <div id="antimanual-chatbox" class="<?php echo esc_attr( $iframe_assistant_page ? 'iframe-wrapper' : '' ); ?>">
        <div id="antimanual-chatbox-inner-wrapper">
            <div id="antimanual-chatbox-header" class="<?php echo esc_attr( $header_class ); ?>">
                <div id="antimanual-chatbox-header-text">
                    <strong><?php echo esc_html( $title ); ?></strong>
                    <p><?php echo esc_html( $help_text ); ?></p>
                </div>
                <button id="atml-chat-reset" title="<?php esc_attr_e('Reset Conversation', 'antimanual'); ?>">
                    <?php antimanual_load_svg_content('sync.svg') ?>
                </button>
            </div>
            <div class="antimanual-offline-banner">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                    <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"></path>
                    <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"></path>
                    <path d="M10.71 5.05A16 16 0 0 1 22.58 9"></path>
                    <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"></path>
                    <path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>
                    <line x1="12" y1="20" x2="12.01" y2="20"></line>
                </svg>
                <span><?php esc_html_e('You are offline', 'antimanual'); ?></span>
            </div>
            <div class="antimanualchatbox">
                <div class="antimanual-msg user placeholder">
                    <div class="msg-content"></div>
                </div>
                <div class="antimanual-msg bot placeholder<?php echo esc_attr( $avatar_hidden ? ' no-avatar' : '' ); ?>">
                    <?php if ( ! $avatar_hidden ) : ?>
                        <img class="bot-profile-icon" src="<?php echo esc_url( $avatar_url ); ?>" alt="Chatbot Avatar" />
                    <?php endif; ?>
                    <div class="antimanual-response">
                        <div class="msg-content atml-msg-html"><?php echo wp_kses_post( atml_option( 'chatbot_wlc_msg' ) ); ?></div>
                        <div class="sources-container">
                            <button class="sources">
                                <span><?php esc_html_e( 'Sources', 'antimanual' ); ?></span>
                                <?php antimanual_load_svg_content('arrow-down.svg') ?>
                            </button>
                            <div class="references">
                                <a class="placeholder" href="#" target="_blank">
                                    <?php antimanual_load_svg_content('anchor.svg') ?>
                                    <span class="text"></span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="antimanual-chatbox-footer">
                <div id="suggested-questions">
                    <p><?php esc_html_e('Not sure what to ask?', 'antimanual'); ?></p>
                    <?php
                    $suggestions = [
                        atml_option( 'chatbot_prebuilt_1' ),
                        atml_option( 'chatbot_prebuilt_2' ),
                        atml_option( 'chatbot_prebuilt_3' ),
                    ];

                    foreach ( $suggestions as $suggestion ) {
                        if ( empty( $suggestion ) ) {
                            continue;
                        }

                        ?>
                        <button class="atml-suggested-question"> <?php echo esc_html($suggestion); ?> </button>
                        <?php
                    }
                    ?>
                </div>
                <?php if ( $show_live_chat_button ) : ?>
                    <div id="antimanual-live-chat-cta">
                        <button id="antimanual-live-chat-button" type="button">
                            <?php echo esc_html( $live_chat_button_label ); ?>
                        </button>
                    </div>
                <?php endif; ?>
                <div id="antimanual-input-container">
                    <input id="antimanualinput" type="text" placeholder="<?php esc_attr_e( 'Type your query', 'antimanual' ); ?>" />
                    <button id="antimanualsend">
                        <?php antimanual_load_svg_content('paper-plane.svg') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php

    // Ensure the function exists before using it
    if ( ! function_exists('is_plugin_active') ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if ( ! atml_is_merge_with_ezd()  && ! $iframe_assistant_page  ) :
        ?>
        <button id="antimanual-help-button" onclick="AntimanualToggleChat()">
            <div>
                <span id="help-icon">
                    <?php antimanual_load_svg_content( $chatbot_icon . '.svg' ) ?>
                </span>
                <span id="close-icon">
                    <?php antimanual_load_svg_content('times.svg') ?>
                </span>
                <?php if ( ! empty( atml_option( 'chatbot_btn_txt' ) ) ) : ?>
                    <span> <?php echo esc_html( atml_option( 'chatbot_btn_txt' ) ); ?> </span>
                <?php endif; ?>
            </div>
        </button>
        <button id="antimanual-help-button-sm" onclick="AntimanualToggleChat()">
            <span><?php esc_html_e('Hide', 'antimanual') ?></span>
            <?php antimanual_load_svg_content('arrow-down.svg') ?>
        </button>
        <?php
    endif;
    
    return ob_get_clean();
}
add_shortcode('antimanual_chatbot', 'antimanual_chatbot_shortcode');

// Block editing of antimanual-assistant page in admin
add_action( 'admin_init', function() {
    if ( is_admin() && isset($_GET['post']) ) {
        $post_id = intval($_GET['post']);
        if ( get_post_meta( $post_id, '_antimanual_assistant_page', true ) === '1' ) {
            // Prevent access to edit screen
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if ( strpos( $request_uri, 'post.php' ) !== false ) {
                wp_die(
                        esc_html__( 'You are not allowed to edit this page.', 'antimanual' ),
                        esc_html__( 'Not Allowed', 'antimanual' ),
                        [ 'response' => 403 ]
                );
            }
        }
    }
} );

// Hide antimanual-assistant from admin Pages list
add_action( 'pre_get_posts', function( $query ) {
    if ( is_admin() && $query->is_main_query() && $query->get('post_type') === 'page' ) {
        $meta_query = $query->get('meta_query') ?: array();
        $meta_query[] = array(
                'key'     => '_antimanual_assistant_page',
                'compare' => 'NOT EXISTS',
        );
        $query->set( 'meta_query', $meta_query );
    }
} );
