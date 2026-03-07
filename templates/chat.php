<?php
/**
 * SonoAI — Chat template.
 * Rendered by [sonoai_chat] shortcode.
 *
 * @package SonoAI
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="sonoai-app" class="sonoai-app" aria-label="<?php esc_attr_e( 'SonoAI Chat', 'sonoai' ); ?>">

    <?php if ( is_user_logged_in() ) : ?>

        <!-- ── Sidebar ───────────────────────────────────────────── -->
        <aside id="sonoai-sidebar" class="sonoai-sidebar" aria-label="<?php esc_attr_e( 'Chat History', 'sonoai' ); ?>">
            <div class="sonoai-sidebar-header">
                <div class="sonoai-brand">
                    <span class="sonoai-brand-icon">🔬</span>
                    <span class="sonoai-brand-name">SonoAI</span>
                </div>
                <div class="sonoai-header-actions" style="display: flex; gap: 8px;">
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="sonoai-btn sonoai-btn-home" title="<?php esc_attr_e( 'Back to Home', 'sonoai' ); ?>" aria-label="<?php esc_attr_e( 'Back to Home', 'sonoai' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    </a>
                    <button id="sonoai-new-chat" class="sonoai-btn sonoai-btn-new" title="<?php esc_attr_e( 'New Chat', 'sonoai' ); ?>" aria-label="<?php esc_attr_e( 'Start new chat', 'sonoai' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span><?php esc_html_e( 'New Chat', 'sonoai' ); ?></span>
                    </button>
                </div>
            </div>

            <div class="sonoai-sidebar-body">
                <p class="sonoai-history-label"><?php esc_html_e( 'Recent', 'sonoai' ); ?></p>
                <ul id="sonoai-history-list" class="sonoai-history-list" role="list">
                    <li class="sonoai-history-empty"><?php esc_html_e( 'No previous chats.', 'sonoai' ); ?></li>
                </ul>
            </div>

            <div class="sonoai-sidebar-footer">
                <div class="sonoai-user-info">
                    <img src="<?php echo esc_url( get_avatar_url( get_current_user_id(), [ 'size' => 36 ] ) ); ?>" alt="" class="sonoai-avatar" width="36" height="36" aria-hidden="true">
                    <span class="sonoai-user-name">
                        <?php
                        $u = wp_get_current_user();
                        echo esc_html( $u->first_name ?: $u->display_name );
                        ?>
                    </span>
                </div>
            </div>
        </aside>

        <!-- ── Main chat area ────────────────────────────────────── -->
        <main id="sonoai-main" class="sonoai-main" role="main">

            <!-- Mobile header -->
            <div class="sonoai-mobile-header">
                <button id="sonoai-sidebar-toggle" class="sonoai-sidebar-toggle" aria-label="<?php esc_attr_e( 'Toggle sidebar', 'sonoai' ); ?>" aria-expanded="false" aria-controls="sonoai-sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <span class="sonoai-mobile-title">🔬 SonoAI</span>
                <button id="sonoai-new-chat-mobile" class="sonoai-sidebar-toggle" aria-label="<?php esc_attr_e( 'New Chat', 'sonoai' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
            </div>

            <!-- Message thread -->
            <div id="sonoai-messages" class="sonoai-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Conversation', 'sonoai' ); ?>">
                <!-- Welcome screen (shown when no session is active) -->
                <div id="sonoai-welcome" class="sonoai-welcome">
                    <div class="sonoai-welcome-icon">🔬</div>
                    <h2 class="sonoai-welcome-title">
                        <?php
                        $fn = wp_get_current_user()->first_name;
                        if ( $fn ) {
                            /* translators: %s: user's first name */
                            printf( esc_html__( 'Hello, %s', 'sonoai' ), esc_html( $fn ) );
                        } else {
                            esc_html_e( 'Welcome to SonoAI', 'sonoai' );
                        }
                        ?>
                    </h2>
                    <p class="sonoai-welcome-subtitle"><?php esc_html_e( 'Your AI-powered sonography assistant. Ask a clinical question or upload a scan to get started.', 'sonoai' ); ?></p>
                    <div class="sonoai-suggestion-grid">
                        <button class="sonoai-suggestion" data-query="<?php esc_attr_e( 'What is the difference between B-mode and M-mode ultrasound?', 'sonoai' ); ?>">B-mode vs M-mode</button>
                        <button class="sonoai-suggestion" data-query="<?php esc_attr_e( 'How do I identify free fluid in the abdomen on ultrasound?', 'sonoai' ); ?>">Free fluid detection</button>
                        <button class="sonoai-suggestion" data-query="<?php esc_attr_e( 'Explain the FAST exam protocol.', 'sonoai' ); ?>">FAST exam protocol</button>
                        <button class="sonoai-suggestion" data-query="<?php esc_attr_e( 'What does a normal gallbladder look like on ultrasound?', 'sonoai' ); ?>">Normal gallbladder view</button>
                    </div>
                </div>
            </div>

            <!-- Image preview strip -->
            <div id="sonoai-image-preview" class="sonoai-image-preview" hidden>
                <img id="sonoai-preview-img" src="" alt="<?php esc_attr_e( 'Sonogram preview', 'sonoai' ); ?>">
                <button id="sonoai-remove-image" class="sonoai-remove-image" aria-label="<?php esc_attr_e( 'Remove image', 'sonoai' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <!-- Input area -->
            <div class="sonoai-input-area">
                <div class="sonoai-input-box">
                    <!-- Hidden file input -->
                    <input type="file" id="sonoai-file-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden aria-label="<?php esc_attr_e( 'Upload sonogram image', 'sonoai' ); ?>">

                    <button id="sonoai-upload-btn" class="sonoai-input-action" title="<?php esc_attr_e( 'Upload sonogram image', 'sonoai' ); ?>" aria-label="<?php esc_attr_e( 'Upload sonogram image', 'sonoai' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </button>

                    <textarea
                        id="sonoai-input"
                        class="sonoai-textarea"
                        placeholder="<?php esc_attr_e( 'Ask about ultrasound, sonography, or upload a scan…', 'sonoai' ); ?>"
                        rows="1"
                        aria-label="<?php esc_attr_e( 'Type your message', 'sonoai' ); ?>"
                        aria-multiline="true"
                    ></textarea>

                    <button id="sonoai-send-btn" class="sonoai-send-btn" aria-label="<?php esc_attr_e( 'Send message', 'sonoai' ); ?>" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
                <p class="sonoai-disclaimer"><?php esc_html_e( 'SonoAI is for educational purposes only and not a substitute for professional medical advice.', 'sonoai' ); ?></p>
            </div>
        </main>

        <!-- Mobile sidebar overlay -->
        <div id="sonoai-overlay" class="sonoai-overlay" hidden aria-hidden="true"></div>

    <?php else : ?>

        <!-- ── Logged-out CTA ─────────────────────────────────────── -->
        <div class="sonoai-login-wall">
            <div class="sonoai-login-card">
                <div class="sonoai-login-icon">🔬</div>
                <h2 class="sonoai-login-title"><?php esc_html_e( 'SonoAI', 'sonoai' ); ?></h2>
                <p class="sonoai-login-desc"><?php esc_html_e( 'Sign in to access your AI-powered sonography assistant. Analyse scans, explore cases, and get expert answers.', 'sonoai' ); ?></p>
                <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="sonoai-login-btn">
                    <?php esc_html_e( 'Log In to Continue', 'sonoai' ); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
        </div>

    <?php endif; ?>

</div><!-- #sonoai-app -->
