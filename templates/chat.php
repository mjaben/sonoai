<?php
/**
 * SonoAI — Chat template.
 * Rendered by [sonoai_chat] shortcode.
 *
 * @package SonoAI
 */

defined( 'ABSPATH' ) || exit;
$current_user = wp_get_current_user();
?>
<div id="sonoai-app" class="sonoai-app" aria-label="<?php esc_attr_e( 'SonoAI Chat', 'sonoai' ); ?>">

    <?php if ( is_user_logged_in() ) : ?>

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

        <!-- ── Main chat area ────────────────────────────────────── -->
        <main id="sonoai-main" class="sonoai-main" role="main">

            <!-- Top navigation -->
            <div class="sonoai-topnav" style="justify-content: space-between; position: relative;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button id="sonoai-sidebar-toggle" class="sonoai-hamburger" aria-label="<?php esc_attr_e( 'Toggle sidebar', 'sonoai' ); ?>" aria-expanded="false" aria-controls="sonoai-sidebar">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                    <span class="sonoai-topnav-title" style="font-size: 16px; font-weight: 800; letter-spacing: -0.02em;">Sonohive Intelligence <span style="font-weight: 600; font-size: 15px; color: #ccc; margin-left: 4px;">Beta</span></span>
                </div>
                
                <div class="sonoai-topnav-center" style="position: absolute; left: 50%; transform: translateX(-50%); display: flex; align-items: center;">
                    <div class="sonoai-nav-pill-group" style="border: 1px solid rgba(255,255,255,0.08); background: #161618; border-radius: 9999px; padding: 4px 6px; display: flex; gap: 4px;">
                        <a href="#" style="border: none; padding: 6px 16px 7px; color: #888; font-size: 13px; font-weight: 500; border-radius: 9999px; transition: color 0.2s;"><?php esc_html_e( 'Homepage', 'sonoai' ); ?></a>
                        <a href="#" style="border: none; padding: 6px 16px 7px; color: #cecece; font-size: 13px; font-weight: 500; border-radius: 9999px; transition: color 0.2s;"><?php esc_html_e( 'Events', 'sonoai' ); ?></a>
                        <a href="#" style="border: none; padding: 6px 16px 7px; color: #888; font-size: 13px; font-weight: 500; border-radius: 9999px; transition: color 0.2s;"><?php esc_html_e( 'Cases', 'sonoai' ); ?></a>
                        <a href="#" style="border: none; padding: 6px 16px 7px; color: #888; font-size: 13px; font-weight: 500; border-radius: 9999px; transition: color 0.2s;"><?php esc_html_e( 'Forum', 'sonoai' ); ?></a>
                    </div>
                </div>

                <div class="sonoai-topnav-right" style="display: flex; align-items: center;">
                    <button id="sonoai-theme-toggle" class="sonoai-theme-toggle" aria-label="<?php esc_attr_e( 'Toggle theme', 'sonoai' ); ?>">
                        <svg class="sonoai-icon-moon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                        <svg class="sonoai-icon-sun" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:none;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    </button>
                </div>
            </div>

            <!-- Message thread -->
            <div id="sonoai-messages" class="sonoai-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Conversation', 'sonoai' ); ?>">
                <!-- Welcome screen (shown when no session is active) -->
                <?php $session_uuid = get_query_var( 'sonoai_uuid' ) ?: ( isset( $_GET['uuid'] ) ? sanitize_text_field( $_GET['uuid'] ) : '' ); ?>
                <div id="sonoai-welcome" class="sonoai-welcome" style="margin-top: -8vh; <?php echo $session_uuid ? 'display:none;' : ''; ?>">
                    <h2 class="sonoai-welcome-title" style="font-size: 30px; font-weight: 800; letter-spacing: -0.03em; line-height: 1.15; max-width: 640px; margin-bottom: 32px;">
                        <?php
                        $fn = $current_user->first_name;
                        if ( $fn ) {
                            /* translators: %s: user's first name */
                            printf( esc_html__( 'Welcome, %s.', 'sonoai' ), esc_html( $fn ) );
                        } else {
                            esc_html_e( 'Welcome.', 'sonoai' );
                        }
                        ?>
                        <br>
                        <span style="color: #999;">How can I assist with your research today?</span>
                    </h2>

                    <div class="sonoai-suggestion-grid" style="grid-template-columns: 1fr 1fr; gap: 16px; max-width: 720px; width: 100%;">
                        <button class="sonoai-suggestion" data-query="<?php esc_attr_e( 'Summarize NIH guidelines for acute myocardial infarction protocols', 'sonoai' ); ?>" style="padding: 22px 24px; border-radius: 16px; background: #16161a; border: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; align-items: flex-start; gap: 0;">
                            <div style="margin-bottom: 12px; color: #4a90e2; display: flex; align-items: center; justify-content: center;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>
                            </div>
                            <div class="sonoai-suggestion-body" style="text-align: left;">
                                <span class="sonoai-suggestion-title" style="font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 4px;">Summarize NIH guidelines</span>
                                <span class="sonoai-suggestion-desc" style="font-size: 12.5px; color: #888;">For acute myocardial infarction protocols</span>
                            </div>
                        </button>
                        <button class="sonoai-suggestion" data-query="<?php esc_attr_e( 'Review recent case studies regarding rare neurological findings in pediatric patients', 'sonoai' ); ?>" style="padding: 22px 24px; border-radius: 16px; background: #16161a; border: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; align-items: flex-start; gap: 0;">
                            <div style="margin-bottom: 12px; color: #4a90e2; display: flex; align-items: center; justify-content: center;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            </div>
                            <div class="sonoai-suggestion-body" style="text-align: left;">
                                <span class="sonoai-suggestion-title" style="font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 4px;">Review recent case studies</span>
                                <span class="sonoai-suggestion-desc" style="font-size: 12.5px; color: #888;">Rare neurological findings in pediatric patients</span>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Input area -->
            <div class="sonoai-input-area" style="background: transparent; padding: 24px 0 32px; max-width: 800px; width: 100%; margin: 0 auto; gap: 12px;">
                <div class="sonoai-input-box" style="border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; background: #161618; padding: 6px 6px 6px 16px;">
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
                <p class="sonoai-disclaimer" style="color: #666; font-size: 11.5px;"><?php esc_html_e( '© 2024 Sono AI. Clinical Use Only. Precision research tool. | Legal | Privacy', 'sonoai' ); ?></p>
            </div>
        </main>

        <!-- Mobile sidebar overlay -->
        <div id="sonoai-overlay" class="sonoai-overlay" hidden aria-hidden="true"></div>

    <?php else : ?>

        <!-- ── Logged-out CTA ─────────────────────────────────────── -->
        <div class="sonoai-login-wall">
            <div class="sonoai-login-card">
                <svg class="sonoai-login-icon" viewBox="0 0 52 52" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="26" cy="26" r="25" stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 3" opacity="0.3"/>
                    <path d="M8 26 Q14 10 20 26 Q26 42 32 26 Q38 10 44 26" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none"/>
                    <circle cx="26" cy="26" r="3" fill="currentColor" opacity="0.65"/>
                </svg>
                <h2 class="sonoai-login-title"><?php esc_html_e( 'SonoAI', 'sonoai' ); ?></h2>
                <p class="sonoai-login-desc"><?php esc_html_e( 'Sign in to access your AI-powered sonography assistant. Analyse scans, explore cases, and get expert answers.', 'sonoai' ); ?></p>
                <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="sonoai-login-btn">
                    <?php esc_html_e( 'Log In to Continue', 'sonoai' ); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
        </div>

    <?php endif; ?>

</div><!-- #sonoai-app -->
