<?php
/**
 * SonoAI — Chat template.
 * Rendered by [sonoai_chat] shortcode.
 *
 * @package SonoAI
 */

defined( 'ABSPATH' ) || exit;
$current_user = wp_get_current_user();
$is_logged_in = is_user_logged_in();
?>
<div id="sonoai-app" class="sonoai-app <?php echo $is_logged_in ? 'logged-in' : 'guest-mode'; ?>" aria-label="<?php esc_attr_e( 'SonoAI Chat', 'sonoai' ); ?>">

    <?php if ( $is_logged_in ) : ?>

        <!-- ── Sidebar ───────────────────────────────────────────── -->
        <aside id="sonoai-sidebar" class="sonoai-sidebar" aria-label="<?php esc_attr_e( 'Chat History', 'sonoai' ); ?>">

            <!-- Sidebar header: brand + new chat -->
            <div class="sonoai-sidebar-header">
                <div class="sonoai-brand">
                    <div class="sonoai-brand-mark" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 26 Q14 10 20 26 Q26 42 32 26"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg>
                    </div>
                    <div>
                        <span class="sonoai-brand-name">Sono AI</span>
                        <span class="sonoai-brand-sub">ULTRASOUND CO-PILOT</span>
                    </div>
                </div>

                <button id="sonoai-new-chat" class="sonoai-new-chat-btn"
                    title="<?php esc_attr_e( 'New Chat', 'sonoai' ); ?>"
                    aria-label="<?php esc_attr_e( 'Start new chat', 'sonoai' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span><?php esc_html_e( 'New Chat', 'sonoai' ); ?></span>
                </button>
            </div>

            <!-- Sidebar body: nav items + dynamic history -->
            <div class="sonoai-sidebar-body">

                <!-- Saved Responses nav item -->
                <button id="sonoai-saved-btn" class="sonoai-sidebar-nav-item" aria-label="<?php esc_attr_e( 'View saved responses', 'sonoai' ); ?>">
                    <span class="sonoai-sidebar-nav-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
                        </svg>
                    </span>
                    <span class="sonoai-sidebar-nav-label"><?php esc_html_e( 'Saved Responses', 'sonoai' ); ?></span>
                    <span class="sonoai-sidebar-nav-badge" id="sonoai-saved-count" style="display:none;">0</span>
                    <span class="sonoai-sidebar-nav-arrow" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                    </span>
                </button>

                <!-- Dynamic history: Recent Research + Recent Guideline (rendered by JS) -->
                <div id="sonoai-history-container">
                    <p class="sonoai-history-label"><?php esc_html_e( 'Recent', 'sonoai' ); ?></p>
                    <ul class="sonoai-history-list" role="list">
                        <li class="sonoai-history-empty"><?php esc_html_e( 'Loading history…', 'sonoai' ); ?></li>
                    </ul>
                </div>

            </div><!-- /.sonoai-sidebar-body -->

            <!-- Sidebar footer: user info -->
            <div class="sonoai-sidebar-footer">
                <img src="<?php echo esc_url( get_avatar_url( get_current_user_id(), [ 'size' => 36 ] ) ); ?>"
                     alt="" class="sonoai-avatar" width="36" height="36" aria-hidden="true">
                <div class="sonoai-user-details">
                    <span class="sonoai-user-name">
                        <?php echo esc_html( $current_user->first_name ?: $current_user->display_name ); ?>
                    </span>
                    <span class="sonoai-user-role">Clinical Lead</span>
                </div>
                <button class="sonoai-user-menu-btn" aria-label="<?php esc_attr_e( 'User menu', 'sonoai' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                </button>
            </div>

        </aside>

        <!-- ── Saved Responses Slide Panel ─────────────────────── -->
        <div id="sonoai-saved-panel" class="sonoai-saved-panel" aria-hidden="true" role="dialog" aria-label="<?php esc_attr_e( 'Saved Responses', 'sonoai' ); ?>">
            <div class="sonoai-saved-panel-header">
                <span class="sonoai-saved-panel-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                    <?php esc_html_e( 'Saved Responses', 'sonoai' ); ?>
                </span>
                <button id="sonoai-saved-close" class="sonoai-saved-panel-close" aria-label="<?php esc_attr_e( 'Close saved responses', 'sonoai' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div id="sonoai-saved-list" class="sonoai-saved-list">
                <div class="sonoai-saved-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#444;margin-bottom:10px;"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                    <p><?php esc_html_e( 'No saved responses yet.', 'sonoai' ); ?></p>
                    <span><?php esc_html_e( 'Bookmark any AI response to see it here.', 'sonoai' ); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Main chat area ────────────────────────────────────── -->
        <main id="sonoai-main" class="sonoai-main" role="main">

            <!-- Top navigation -->
            <div class="sonoai-topnav">
                <div class="sonoai-topnav-left">
                    <?php if ( $is_logged_in ) : ?>
                    <button id="sonoai-sidebar-toggle" class="sonoai-hamburger" aria-label="<?php esc_attr_e( 'Toggle sidebar', 'sonoai' ); ?>" aria-expanded="false" aria-controls="sonoai-sidebar">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                    <?php endif; ?>
                    <span class="sonoai-topnav-title">Sonohive Intelligence <span class="sonoai-beta-badge">Beta</span></span>
                </div>
                
                <div class="sonoai-topnav-center">
                    <div class="sonoai-nav-pill-group">
                        <a href="#"><?php esc_html_e( 'Homepage', 'sonoai' ); ?></a>
                        <a href="#" class="active"><?php esc_html_e( 'Events', 'sonoai' ); ?></a>
                        <a href="#"><?php esc_html_e( 'Cases', 'sonoai' ); ?></a>
                        <a href="#"><?php esc_html_e( 'Forum', 'sonoai' ); ?></a>
                    </div>
                </div>

                <div class="sonoai-topnav-right">
                    <?php if ( ! $is_logged_in ) : ?>
                        <div class="sonoai-auth-btns">
                            <button class="uwp-login-link"><?php esc_html_e( 'Log in', 'sonoai' ); ?></button>
                            <button class="uwp-register-link"><?php esc_html_e( 'Sign up', 'sonoai' ); ?></button>
                        </div>
                    <?php endif; ?>
                    <button id="sonoai-theme-toggle" class="sonoai-theme-toggle" aria-label="<?php esc_attr_e( 'Toggle theme', 'sonoai' ); ?>">
                        <svg class="sonoai-icon-moon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                        <svg class="sonoai-icon-sun" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:none;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    </button>
                </div>
            </div>

            <!-- Message thread -->
            <div id="sonoai-messages" class="sonoai-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Conversation', 'sonoai' ); ?>">
                <!-- Welcome screen (shown when no session is active) -->
                <?php 
                $session_uuid = get_query_var( 'sonoai_uuid' ) ?: ( isset( $_GET['uuid'] ) ? sanitize_text_field( $_GET['uuid'] ) : '' ); 
                
                // Ensure session for tracking last greeting to avoid repeats
                if ( ! session_id() && ! headers_sent() ) {
                    session_start();
                }

                $greetings = [
                    __( 'Ready when you are for your research.', 'sonoai' ),
                    __( 'Where should we begin?', 'sonoai' ),
                    __( "What's on your mind today?", 'sonoai' ),
                    __( 'What are you working on?', 'sonoai' ),
                ];

                $last_idx = isset( $_SESSION['sonoai_last_greeting'] ) ? (int) $_SESSION['sonoai_last_greeting'] : -1;
                $available_indices = array_keys( $greetings );
                
                if ( $last_idx !== -1 && count( $greetings ) > 1 ) {
                    unset( $available_indices[ array_search( $last_idx, $available_indices ) ] );
                }
                
                $current_idx = $available_indices[ array_rand( $available_indices ) ];
                $_SESSION['sonoai_last_greeting'] = $current_idx;
                $random_greeting = $greetings[ $current_idx ];
                ?>
                <div id="sonoai-welcome" class="sonoai-welcome" <?php echo $session_uuid ? 'style="display:none;"' : ''; ?>>
                    <h2 class="sonoai-welcome-title">
                        <?php echo esc_html( $random_greeting ); ?>
                    </h2>
                </div>
            </div>

            <!-- Input area -->
            <div class="sonoai-input-area">
                <div class="sonoai-input-box">
                    <textarea
                        id="sonoai-input"
                        class="sonoai-textarea"
                        placeholder="<?php esc_attr_e( 'Ask a follow-up clinical query...', 'sonoai' ); ?>"
                        rows="1"
                        aria-label="<?php esc_attr_e( 'Type your message', 'sonoai' ); ?>"
                    ></textarea>

                    <button id="sonoai-send-btn" class="sonoai-send-btn" aria-label="<?php esc_attr_e( 'Send message', 'sonoai' ); ?>" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
                <div class="sonoai-mode-toggle">
                    <button class="sonoai-mode-btn" data-mode="guideline"><?php esc_html_e( 'Guideline Mode', 'sonoai' ); ?></button>
                    <button class="sonoai-mode-btn" data-mode="research"><?php esc_html_e( 'Research Mode', 'sonoai' ); ?></button>
                </div>
                <p class="sonoai-disclaimer">
                    <?php
                    printf(
                        /* translators: 1: Terms link, 2: Privacy Policy link */
                        esc_html__( 'By messaging Sono AI - an AI Chatbot, you agree to our %1$s and have read our %2$s.', 'sonoai' ),
                        '<a href="' . esc_url( home_url( '/terms/' ) ) . '" target="_blank">' . esc_html__( 'Terms', 'sonoai' ) . '</a>',
                        '<a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '" target="_blank">' . esc_html__( 'Privacy Policy', 'sonoai' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
        </main>

        <?php if ( $is_logged_in ) : ?>
        <!-- Mobile sidebar overlay -->
        <div id="sonoai-overlay" class="sonoai-overlay" hidden aria-hidden="true"></div>
        <?php endif; ?>

    <!-- ── Clinical Lightbox ── -->
    <div id="sonoai-lightbox" class="sonoai-lightbox" hidden aria-hidden="true" role="dialog">
        <button class="sonoai-lightbox-close" aria-label="<?php esc_attr_e( 'Close lightbox', 'sonoai' ); ?>">&times;</button>
        <div class="sonoai-lightbox-content">
            <div class="sonoai-lightbox-nav">
                <button class="sonoai-lightbox-prev" aria-label="<?php esc_attr_e( 'Previous image', 'sonoai' ); ?>">&#10094;</button>
                <div style="flex:1;"></div>
                <button class="sonoai-lightbox-next" aria-label="<?php esc_attr_e( 'Next image', 'sonoai' ); ?>">&#10095;</button>
            </div>
            <img id="sonoai-lightbox-img" src="" alt="<?php esc_attr_e( 'Clinical visualization', 'sonoai' ); ?>">
        </div>
        <div id="sonoai-lightbox-caption" class="sonoai-lightbox-caption"></div>
        <div id="sonoai-lightbox-counter" class="sonoai-lightbox-counter"></div>
    </div>

</div><!-- #sonoai-app -->
