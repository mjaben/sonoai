<?php
/**
 * SonoAI — Landing Page template.
 * Rendered by [sonoai_landing] shortcode.
 *
 * @package SonoAI
 */

defined( 'ABSPATH' ) || exit;
$current_user = wp_get_current_user();
$is_logged_in = is_user_logged_in();
?>
<div id="sonoai-landing-app" class="sonoai-landing-app <?php echo $is_logged_in ? 'logged-in' : 'guest-mode'; ?>">

    <!-- ─── Header & Navigation ──────────────────────────────────────────────── -->
    <header class="sonoai-landing-header">
        <div class="sonoai-landing-container">
            <div class="sonoai-landing-brand">
                <div class="sonoai-landing-brand-mark">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 26 Q14 10 20 26 Q26 42 32 26"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg>
                </div>
                <div>
                    <span class="sonoai-landing-brand-name">Sono AI</span>
                    <span class="sonoai-landing-brand-sub">ULTRASOUND CO-PILOT</span>
                </div>
            </div>
            
            <nav class="sonoai-landing-nav" aria-label="<?php esc_attr_e( 'Main Navigation', 'sonoai' ); ?>">
                <a href="#features" class="sonoai-landing-nav-link"><?php esc_html_e( 'Features', 'sonoai' ); ?></a>
                <a href="#sandbox" class="sonoai-landing-nav-link"><?php esc_html_e( 'Demo Sandbox', 'sonoai' ); ?></a>
                <a href="#modes" class="sonoai-landing-nav-link"><?php esc_html_e( 'Clinical Engines', 'sonoai' ); ?></a>
                <a href="#pricing" class="sonoai-landing-nav-link"><?php esc_html_e( 'Pricing', 'sonoai' ); ?></a>
            </nav>

            <div class="sonoai-landing-header-actions">
                <button id="sonoai-landing-theme-toggle" class="sonoai-landing-theme-toggle" aria-label="<?php esc_attr_e( 'Toggle theme', 'sonoai' ); ?>">
                    <svg class="sonoai-landing-icon-moon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                    <svg class="sonoai-landing-icon-sun" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:none;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                </button>
                <?php if ( $is_logged_in ) : ?>
                    <a href="<?php echo esc_url( home_url( '/sonoai-chat/' ) ); ?>" class="sonoai-landing-btn-primary"><?php esc_html_e( 'Launch Co-Pilot', 'sonoai' ); ?></a>
                <?php else : ?>
                    <button class="sonoai-landing-btn-secondary uwp-login-link"><?php esc_html_e( 'Log in', 'sonoai' ); ?></button>
                    <button class="sonoai-landing-btn-primary uwp-register-link"><?php esc_html_e( 'Sign up', 'sonoai' ); ?></button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ─── Hero Section ─────────────────────────────────────────────────────── -->
    <section class="sonoai-landing-hero">
        <div class="sonoai-landing-container grid-2">
            <div class="sonoai-landing-hero-left">
                <div class="sonoai-landing-hero-badge">
                    <span class="pulse-dot"></span>
                    <span><?php esc_html_e( 'B2C Clinical Research & On-the-Go Co-Pilot', 'sonoai' ); ?></span>
                </div>
                <h1 class="sonoai-landing-hero-title">
                    <?php _e( 'The Generative AI Co-Pilot for <span class="text-gradient">Ultrasound & Sonography</span>.', 'sonoai' ); ?>
                </h1>
                <p class="sonoai-landing-hero-desc">
                    <?php esc_html_e( 'Exclusively tailored for ultrasound and sonography. An instant, evidence-based on-the-go reference co-pilot for sonographers, doctors, and students, trained on thousands of official guidelines from reputable sources like BMUS, AIUM, and SDMS.', 'sonoai' ); ?>
                </p>
                <div class="sonoai-landing-hero-ctas">
                    <?php if ( $is_logged_in ) : ?>
                        <a href="<?php echo esc_url( home_url( '/sonoai-chat/' ) ); ?>" class="sonoai-landing-btn-primary large"><?php esc_html_e( 'Launch Co-Pilot App', 'sonoai' ); ?></a>
                    <?php else : ?>
                        <button class="sonoai-landing-btn-primary large uwp-register-link"><?php esc_html_e( 'Start Free Beta Access', 'sonoai' ); ?></button>
                    <?php endif; ?>
                    <a href="#sandbox" class="sonoai-landing-btn-outline large"><?php esc_html_e( 'Try Interactive Demo', 'sonoai' ); ?></a>
                </div>
                <div class="sonoai-landing-hero-meta">
                    <div class="meta-item">
                        <strong>94ms</strong>
                        <span><?php esc_html_e( 'Search Latency', 'sonoai' ); ?></span>
                    </div>
                    <div class="meta-item">
                        <strong>100%</strong>
                        <span><?php esc_html_e( 'Auditable Papers', 'sonoai' ); ?></span>
                    </div>
                    <div class="meta-item">
                        <strong>Free</strong>
                        <span><?php esc_html_e( 'Beta Release Access', 'sonoai' ); ?></span>
                    </div>
                </div>
            </div>

            <!-- Hero Right: Live UI Mockup -->
            <div class="sonoai-landing-hero-right">
                <div class="sonoai-mock-ui-shell">
                    <div class="sonoai-mock-ui-header">
                        <span class="dot red"></span>
                        <span class="dot yellow"></span>
                        <span class="dot green"></span>
                        <span class="title"><?php esc_html_e( 'Sonohive Intelligence co-pilot_v1.1.2', 'sonoai' ); ?></span>
                    </div>
                    <div class="sonoai-mock-ui-body">
                        <div class="sonoai-mock-sidebar">
                            <span class="mock-line short"></span>
                            <span class="mock-line active"></span>
                            <span class="mock-line"></span>
                            <span class="mock-line"></span>
                        </div>
                        <div class="sonoai-mock-chat">
                            <div class="mock-bubble user">
                                <p><?php esc_html_e( 'Identify SRU nodule parameters', 'sonoai' ); ?></p>
                            </div>
                            <div class="mock-bubble assistant">
                                <div class="mock-badge teal"><?php esc_html_e( 'Guideline Mode', 'sonoai' ); ?></div>
                                <p><strong><?php esc_html_e( 'ACR TI-RADS Nodule Assessment:', 'sonoai' ); ?></strong></p>
                                <ul>
                                    <li><?php esc_html_e( 'Composition: Solid (2 pts)', 'sonoai' ); ?></li>
                                    <li><?php esc_html_e( 'Echogenicity: Very hypoechoic (3 pts)', 'sonoai' ); ?></li>
                                    <li><?php esc_html_e( 'Margin: Lobulated (2 pts)', 'sonoai' ); ?></li>
                                </ul>
                                <p><?php esc_html_e( 'Total: 7 pts (TR5). FNA is recommended if size ≥ 1.0 cm.', 'sonoai' ); ?></p>
                                <span class="mock-citation">[ACR 2017 TI-RADS, p.15]</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="hero-bg-glow"></div>
            </div>
        </div>
    </section>

    <!-- ─── Interactive Sandbox (Demo Widget) ────────────────────────────────── -->
    <section id="sandbox" class="sonoai-landing-sandbox">
        <div class="sonoai-landing-container">
            <div class="section-header text-center">
                <span class="section-tag"><?php esc_html_e( 'Interactive Sandbox', 'sonoai' ); ?></span>
                <h2><?php esc_html_e( 'Test the Intelligence Engine', 'sonoai' ); ?></h2>
                <p><?php esc_html_e( 'Click on any clinical question below to watch how SonoAI performs real-time guideline checks and visual literature retrieval.', 'sonoai' ); ?></p>
            </div>

            <div class="sandbox-widget">
                <!-- Sandbox Prompts Left -->
                <div class="sandbox-prompts">
                    <button class="sandbox-prompt-btn active" data-index="0">
                        <span class="prompt-icon">🔬</span>
                        <span class="prompt-text"><?php esc_html_e( 'Gallbladder wall thickening criteria?', 'sonoai' ); ?></span>
                    </button>
                    <button class="sandbox-prompt-btn" data-index="1">
                        <span class="prompt-icon">📚</span>
                        <span class="prompt-text"><?php esc_html_e( 'FGR Doppler evaluation indices?', 'sonoai' ); ?></span>
                    </button>
                    <button class="sandbox-prompt-btn" data-index="2">
                        <span class="prompt-icon">💡</span>
                        <span class="prompt-text"><?php esc_html_e( 'Thyroid nodule FNA parameters?', 'sonoai' ); ?></span>
                    </button>
                </div>

                <!-- Sandbox Chat Display Right -->
                <div class="sandbox-display">
                    <div class="sandbox-chat-header">
                        <span class="sandbox-chat-indicator pulse-dot"></span>
                        <span id="sandbox-engine-label"><?php esc_html_e( 'SonoAI: Guideline Mode', 'sonoai' ); ?></span>
                    </div>
                    <div class="sandbox-chat-body" id="sandbox-chat-body">
                        <!-- Query Balloon -->
                        <div class="sandbox-balloon user" id="sandbox-user-balloon">
                            <p><?php esc_html_e( 'What are the criteria for gallbladder wall thickening?', 'sonoai' ); ?></p>
                        </div>
                        
                        <!-- RAG Loading Skeleton -->
                        <div class="sandbox-skeleton" id="sandbox-loading" style="display:none;">
                            <div class="skeleton-bar short pulse"></div>
                            <div class="skeleton-bar pulse"></div>
                            <div class="skeleton-bar medium pulse"></div>
                        </div>

                        <!-- Response Balloon -->
                        <div class="sandbox-balloon assistant" id="sandbox-assistant-balloon">
                            <p><strong><?php esc_html_e( 'Gallbladder Wall Thickening Reference benchmarks:', 'sonoai' ); ?></strong></p>
                            <p><?php esc_html_e( 'A normal gallbladder wall thickness is less than 3 mm. Thickening of the wall (≥ 3 mm) is a key sonographic finding which can be classified into:', 'sonoai' ); ?></p>
                            <ul>
                                <li><strong><?php esc_html_e( 'Intrinsic Causes:', 'sonoai' ); ?></strong> <?php esc_html_e( 'Acute cholecystitis (often associated with gallstones and positive sonographic Murphy sign), adenomyomatosis, and gallbladder carcinoma.', 'sonoai' ); ?></li>
                                <li><strong><?php esc_html_e( 'Extrinsic Causes:', 'sonoai' ); ?></strong> <?php esc_html_e( 'Hepatitis, liver cirrhosis, congestive heart failure, pancreatitis, and renal failure.', 'sonoai' ); ?></li>
                            </ul>
                            <p class="sandbox-citation"><?php esc_html_e( 'Source citation: AIUM Practice Parameter for Ultrasound of the Abdomen, Page 9.', 'sonoai' ); ?></p>
                            
                            <!-- Visual scan result inside Clinical Lightbox trigger -->
                            <div class="sandbox-media-trigger" id="sandbox-scan-trigger" data-image-url="" data-caption="Fig 1: Transverse scan demonstrating diffuse gallbladder wall thickening (calipers: 4.8 mm) with stratified edema in acute cholecystitis.">
                                <div class="trigger-overlay">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <span><?php esc_html_e( 'Click to Zoom Literature Scan', 'sonoai' ); ?></span>
                                </div>
                                <div class="trigger-placeholder-image gb-thickening"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── Dual clinical engines (Guideline vs Research) ────────────────────── -->
    <section id="modes" class="sonoai-landing-modes">
        <div class="sonoai-landing-container">
            <div class="section-header text-center">
                <span class="section-tag"><?php esc_html_e( 'Dual-Engine Intelligence', 'sonoai' ); ?></span>
                <h2><?php esc_html_e( 'Two Engines. Focused Ultrasound Rigor.', 'sonoai' ); ?></h2>
                <p><?php esc_html_e( 'Search dynamically to match official sonography guidelines or probe recent academic literature.', 'sonoai' ); ?></p>
            </div>

            <div class="grid-2 gap-4">
                <!-- Guideline Card (Teal) -->
                <div class="engine-card guideline-card">
                    <div class="card-badge teal"><?php esc_html_e( 'Standard Core', 'sonoai' ); ?></div>
                    <h3><?php esc_html_e( 'Guideline Mode', 'sonoai' ); ?></h3>
                    <p class="card-desc"><?php esc_html_e( 'Queries verified standard operating procedures, medical society guidelines, and national university ultrasound curricula.', 'sonoai' ); ?></p>
                    <ul class="card-features">
                        <li><span>✔</span> <?php esc_html_e( 'Standardizes reference guidelines (BMUS, SDMS, AIUM, ACR)', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Conservative answers anchored strictly in ultrasound textbook curriculum', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Perfect for student exams and general clinical QA protocols', 'sonoai' ); ?></li>
                    </ul>
                </div>

                <!-- Research Card (Purple) -->
                <div class="engine-card research-card">
                    <div class="card-badge purple"><?php esc_html_e( 'Experimental & Academic', 'sonoai' ); ?></div>
                    <h3><?php esc_html_e( 'Research Mode', 'sonoai' ); ?></h3>
                    <p class="card-desc"><?php esc_html_e( 'Queries raw indexed clinical papers, PubMed research releases, and peer-reviewed ultrasound journals.', 'sonoai' ); ?></p>
                    <ul class="card-features">
                        <li><span>✔</span> <?php esc_html_e( 'Provides emerging scientific discoveries and atypical case reports', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Synthesizes literature findings with reference paper links', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Designed for researchers, doctors, and literature reviewers', 'sonoai' ); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </section>





    <!-- ─── Pricing Tiers Section ─────────────────────────────────────────── -->
    <section id="pricing" class="sonoai-landing-pricing">
        <div class="sonoai-landing-container">
            <div class="section-header text-center">
                <span class="section-tag"><?php esc_html_e( 'Pricing Plans', 'sonoai' ); ?></span>
                <h2><?php esc_html_e( 'Free for Everyone. Unlocked for Beta.', 'sonoai' ); ?></h2>
                <p><?php esc_html_e( 'Explore our individual plan tiers. Access all premium medical AI co-pilot tools at zero cost during our public release.', 'sonoai' ); ?></p>
            </div>

            <div class="grid-2 gap-4">
                <!-- Free Plan -->
                <div class="pricing-card">
                    <div class="pricing-header">
                        <h4><?php esc_html_e( 'Free Plan', 'sonoai' ); ?></h4>
                        <div class="price">
                            <span class="currency">$</span><span class="amount">0</span><span class="period">/<?php esc_html_e( 'mo', 'sonoai' ); ?></span>
                        </div>
                        <p class="pricing-desc"><?php esc_html_e( 'Essential medical references and on-the-go guidelines lookup.', 'sonoai' ); ?></p>
                    </div>
                    <ul class="pricing-features">
                        <li><span>✔</span> <?php esc_html_e( '10 standard Guideline Mode lookups daily', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Basic standard ultrasound guidelines (ACR, AIUM)', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Sub-100ms vector search speed', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Mobile-friendly responsive UI', 'sonoai' ); ?></li>
                    </ul>
                    <button class="sonoai-landing-btn-secondary full-width uwp-register-link"><?php esc_html_e( 'Get Started Free', 'sonoai' ); ?></button>
                </div>

                <!-- Premium Plan (Unlocked!) -->
                <div class="pricing-card premium-card featured">
                    <div class="pricing-ribbon"><?php esc_html_e( 'Everything Free For Now!', 'sonoai' ); ?></div>
                    <div class="pricing-header">
                        <h4><?php esc_html_e( 'Premium Plan', 'sonoai' ); ?></h4>
                        <div class="price">
                            <span class="currency">$</span><span class="amount">0</span><span class="period">/<?php esc_html_e( 'mo', 'sonoai' ); ?></span>
                            <span class="price-strike">$19/mo</span>
                        </div>
                        <p class="pricing-desc"><?php esc_html_e( 'Unlimited research inquiries, clinical scans, and bookmark libraries.', 'sonoai' ); ?></p>
                    </div>
                    <ul class="pricing-features">
                        <li><span>✔</span> <?php esc_html_e( 'Unlimited Research Mode literature searches', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Unlimited Guideline Mode searches', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Full access to clinical literature scans & lightboxes', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Personalized saved responses & bookmark dashboard', 'sonoai' ); ?></li>
                        <li><span>✔</span> <?php esc_html_e( 'Priority clinical RAG search nodes', 'sonoai' ); ?></li>
                    </ul>
                    <button class="sonoai-landing-btn-primary full-width uwp-register-link"><?php esc_html_e( 'Unlock Premium Free', 'sonoai' ); ?></button>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── Call to Action Footer ────────────────────────────────────────────── -->
    <section class="sonoai-landing-cta">
        <div class="sonoai-landing-container text-center">
            <h2 class="cta-title"><?php esc_html_e( 'Elevate Your Clinical Workflow Today.', 'sonoai' ); ?></h2>
            <p class="cta-desc"><?php esc_html_e( 'Sign up to start chatting, bookmarking responses, and researching guidelines with sub-100ms vector latency.', 'sonoai' ); ?></p>
            <div class="cta-btns">
                <?php if ( $is_logged_in ) : ?>
                    <a href="<?php echo esc_url( home_url( '/sonoai-chat/' ) ); ?>" class="sonoai-landing-btn-primary large"><?php esc_html_e( 'Launch App Workspace', 'sonoai' ); ?></a>
                <?php else : ?>
                    <button class="sonoai-landing-btn-primary large uwp-register-link"><?php esc_html_e( 'Sign Up via UsersWP', 'sonoai' ); ?></button>
                    <button class="sonoai-landing-btn-outline large uwp-login-link"><?php esc_html_e( 'Log In to Account', 'sonoai' ); ?></button>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ─── Clinical Lightbox modal support ──────────────────────────────────── -->
    <div id="sonoai-landing-lightbox" class="sonoai-landing-lightbox" hidden aria-hidden="true" role="dialog">
        <button class="sonoai-landing-lightbox-close" aria-label="<?php esc_attr_e( 'Close lightbox', 'sonoai' ); ?>">&times;</button>
        <div class="sonoai-landing-lightbox-content">
            <img id="sonoai-landing-lightbox-img" src="" alt="<?php esc_attr_e( 'Clinical scan reference illustration', 'sonoai' ); ?>">
        </div>
        <div id="sonoai-landing-lightbox-caption" class="sonoai-landing-lightbox-caption"></div>
    </div>

</div><!-- #sonoai-landing-app -->
