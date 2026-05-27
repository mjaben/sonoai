/**
 * SonoAI — Landing Page Interactive Scripts
 *
 * Handles Sandbox simulations, theme toggling, and Clinical Lightbox modals.
 *
 * @package SonoAI
 */

jQuery(document).ready(function($) {
    'use strict';

    // ─── Theme Toggle System ────────────────────────────────────────────────
    const $app = $('#sonoai-landing-app');
    const $themeToggle = $('#sonoai-landing-theme-toggle');
    const $iconMoon = $themeToggle.find('.sonoai-landing-icon-moon');
    const $iconSun = $themeToggle.find('.sonoai-landing-icon-sun');

    // Retrieve stored preference or check system setting
    let savedTheme = localStorage.getItem('sonoai-landing-theme');
    if (!savedTheme) {
        savedTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    // Apply initial theme
    if (savedTheme === 'dark') {
        $app.addClass('sono-landing-dark');
        $iconMoon.hide();
        $iconSun.show();
    } else {
        $app.removeClass('sono-landing-dark');
        $iconMoon.show();
        $iconSun.hide();
    }

    $themeToggle.on('click', function() {
        if ($app.hasClass('sono-landing-dark')) {
            $app.removeClass('sono-landing-dark');
            localStorage.setItem('sonoai-landing-theme', 'light');
            $iconMoon.fadeIn(150);
            $iconSun.hide();
        } else {
            $app.addClass('sono-landing-dark');
            localStorage.setItem('sonoai-landing-theme', 'dark');
            $iconMoon.hide();
            $iconSun.fadeIn(150);
        }
    });

    // ─── Clinical Sandbox Mock Data ─────────────────────────────────────────
    const sandboxData = [
        {
            query: "What are the criteria for gallbladder wall thickening?",
            engine: "SonoAI: Guideline Mode",
            isGuideline: true,
            html: `<strong>Gallbladder Wall Thickening Reference benchmarks:</strong>
                   <p>A normal gallbladder wall thickness is less than 3 mm. Thickening of the wall (≥ 3 mm) is a key sonographic finding which can be classified into:</p>
                   <ul>
                       <li><strong>Intrinsic Causes:</strong> Acute cholecystitis (often associated with gallstones and positive sonographic Murphy sign), adenomyomatosis, and gallbladder carcinoma.</li>
                       <li><strong>Extrinsic Causes:</strong> Hepatitis, liver cirrhosis, congestive heart failure, pancreatitis, and renal failure.</li>
                   </ul>
                   <p class="sandbox-citation">Source citation: AIUM Practice Parameter for Ultrasound of the Abdomen, Page 9.</p>`,
            mediaClass: "gb-thickening",
            svgMarkup: `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="350" viewBox="0 0 600 350" style="background:#0E0E10; border-radius:8px;">
                            <rect width="100%" height="100%" fill="#0E0E10"/>
                            <text x="24" y="32" fill="#8E8EA2" font-size="12" font-family="monospace">SONOHIVE ULTRASOUND SCANNERS — GB VIEW</text>
                            <circle cx="300" cy="180" r="110" fill="none" stroke="#222" stroke-width="4"/>
                            <!-- Thickened GB Wall Layer -->
                            <path d="M 200,180 A 100,100 0 0,1 400,180" fill="none" stroke="#15B787" stroke-width="12" opacity="0.8"/>
                            <path d="M 200,180 A 100,100 0 0,0 400,180" fill="none" stroke="#15B787" stroke-width="12" opacity="0.8"/>
                            <!-- Fluid lumen -->
                            <ellipse cx="300" cy="180" rx="88" ry="88" fill="#0A0710" stroke="#333" stroke-width="1"/>
                            <!-- Caliper Lines -->
                            <line x1="194" y1="180" x2="206" y2="180" stroke="#A337DB" stroke-width="2"/>
                            <line x1="200" y1="174" x2="200" y2="186" stroke="#A337DB" stroke-width="2"/>
                            <line x1="200" y1="180" x2="212" y2="180" stroke="#A337DB" stroke-dasharray="2" stroke-width="1.5"/>
                            <line x1="206" y1="180" x2="218" y2="180" stroke="#A337DB" stroke-width="2"/>
                            <line x1="212" y1="174" x2="212" y2="186" stroke="#A337DB" stroke-width="2"/>
                            <!-- Caliper Label -->
                            <text x="180" y="156" fill="#A337DB" font-size="11" font-family="sans-serif" font-weight="bold">Dist d1: 4.8 mm</text>
                            <!-- Stratified Edema indicator -->
                            <path d="M 190,180 A 110,110 0 0,1 410,180" fill="none" stroke="#15B787" stroke-width="2" stroke-dasharray="3" opacity="0.6"/>
                            <text x="24" y="324" fill="#15B787" font-size="11" font-family="sans-serif">Gallbladder Wall Thickened: Acute Cholecystitis</text>
                        </svg>`,
            caption: "Fig 1: High-contrast transverse ultrasound scan mockup demonstrating diffuse gallbladder wall thickening (calipers: 4.8 mm) with stratified edema in acute cholecystitis."
        },
        {
            query: "List the Doppler indices for fetal growth restriction (FGR).",
            engine: "SonoAI: Research Mode",
            isGuideline: false,
            html: `<strong>Fetal Growth Restriction (FGR) Doppler Evaluation:</strong>
                   <p>Doppler velocimetry is the primary tool for managing FGR. Key research indicators synthesize standard measurements with literature findings:</p>
                   <ul>
                       <li><strong>Umbilical Artery (UA) PI:</strong> Indicated by high resistance patterns. Progresses to Absent End-Diastolic Velocity (AEDV) or Reversed End-Diastolic Velocity (REDV) under severe placental degradation.</li>
                       <li><strong>Middle Cerebral Artery (MCA) PI:</strong> Shows lowered resistance reflecting the 'brain-sparing' protective mechanism. The Cerebroplacental Ratio (CPR = MCA PI / UA PI) is highly sensitive; a ratio &lt; 1.08 indicates circulatory redistribution.</li>
                       <li><strong>Ductus Venosus (DV) A-wave:</strong> The ultimate marker of cardiac decompensation. Reverse or absent A-wave velocity indicates severe fetal acidemia and warrants urgent delivery.</li>
                   </ul>
                   <p class="sandbox-citation">Source citation: ISUOG Practice Guidelines: Use of Doppler ultrasonography in obstetrics, p. 204.</p>`,
            mediaClass: "fgr-doppler",
            svgMarkup: `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="350" viewBox="0 0 600 350" style="background:#0E0E10; border-radius:8px;">
                            <rect width="100%" height="100%" fill="#0E0E10"/>
                            <text x="24" y="32" fill="#8E8EA2" font-size="12" font-family="monospace">DOppler spectral flow velocimetry — umbilical artery</text>
                            <!-- Grid lines -->
                            <line x1="50" y1="250" x2="550" y2="250" stroke="#222" stroke-width="1.5"/>
                            <line x1="50" y1="150" x2="550" y2="150" stroke="#111" stroke-width="1" stroke-dasharray="4"/>
                            <line x1="50" y1="50" x2="550" y2="50" stroke="#111" stroke-width="1" stroke-dasharray="4"/>
                            <!-- Doppler wave path showing reversed end-diastolic flow (REDV) -->
                            <path d="M 50,250 
                                     C 70,50 80,50 100,100
                                     C 120,160 130,220 150,270
                                     C 170,290 180,250 200,250
                                     C 220,50 230,50 250,100
                                     C 270,160 280,220 300,270
                                     C 320,290 330,250 350,250
                                     C 370,50 380,50 400,100
                                     C 420,160 430,220 450,270
                                     C 470,290 480,250 500,250" 
                                  fill="none" stroke="#A337DB" stroke-width="3.5"/>
                            <!-- Labels -->
                            <text x="75" y="40" fill="#A337DB" font-size="16" font-family="sans-serif" font-weight="bold">Systole (S)</text>
                            <text x="135" y="295" fill="#ff5f56" font-size="13" font-family="sans-serif" font-weight="bold">Reversed Diastole (REDV)</text>
                            <line x1="150" y1="250" x2="150" y2="270" stroke="#ff5f56" stroke-width="1.5" stroke-dasharray="2"/>
                            <text x="24" y="324" fill="#A337DB" font-size="11" font-family="sans-serif">Diagnosis: Placental Insufficiency & FGR Risk Class III</text>
                        </svg>`,
            caption: "Fig 2: Umbilical artery Doppler waveform mockup demonstrating high resistance and Reversed End-Diastolic Flow (REDV), indicating high perinatal mortality risk."
        },
        {
            query: "Show thyroid nodule FNA parameters.",
            engine: "SonoAI: Guideline Mode",
            isGuideline: true,
            html: `<strong>Thyroid Nodule FNA Decision Parameters (ACR TI-RADS):</strong>
                   <p>Fine-needle aspiration (FNA) recommendations are based on a combination of the nodule's TI-RADS score and its maximum diameter:</p>
                   <ul>
                       <li><strong>TR3 (Mildly Suspicious - 3 pts):</strong> FNA recommended if maximum diameter ≥ 2.5 cm. Active follow-up surveillance if size ≥ 1.5 cm.</li>
                       <li><strong>TR4 (Moderately Suspicious - 4-6 pts):</strong> FNA recommended if maximum diameter ≥ 1.5 cm. Active follow-up surveillance if size ≥ 1.0 cm.</li>
                       <li><strong>TR5 (Highly Suspicious - 7+ pts):</strong> FNA recommended if maximum diameter ≥ 1.0 cm. Active follow-up surveillance if size ≥ 0.5 cm.</li>
                   </ul>
                   <p class="sandbox-citation">Source citation: ACR TI-RADS Committee White Paper, Page 5.</p>`,
            mediaClass: "thyroid-fna",
            svgMarkup: `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="350" viewBox="0 0 600 350" style="background:#0E0E10; border-radius:8px;">
                            <rect width="100%" height="100%" fill="#0E0E10"/>
                            <text x="24" y="32" fill="#8E8EA2" font-size="12" font-family="monospace">SONOHIVE ULTRASOUND SCANNERS — THYROID LOBE</text>
                            <!-- Thyroid parenchyma -->
                            <ellipse cx="300" cy="180" rx="160" ry="100" fill="none" stroke="#222" stroke-width="2" stroke-dasharray="4"/>
                            <!-- Nodule boundary -->
                            <path d="M 240,150 Q 250,110 320,130 Q 360,160 350,210 Q 300,240 250,220 Q 230,180 240,150" fill="#1C1826" stroke="#15B787" stroke-width="2.5" stroke-dasharray="3"/>
                            <!-- Microcalcifications (dots) -->
                            <circle cx="270" cy="160" r="1.5" fill="#FFF"/>
                            <circle cx="280" cy="150" r="1.5" fill="#FFF"/>
                            <circle cx="310" cy="180" r="1.5" fill="#FFF"/>
                            <circle cx="320" cy="165" r="1.5" fill="#FFF"/>
                            <circle cx="295" cy="195" r="1.5" fill="#FFF"/>
                            <!-- FNA Needle path representation -->
                            <line x1="120" y1="60" x2="270" y2="160" stroke="#8E8EA2" stroke-width="2"/>
                            <polygon points="270,160 262,152 268,148" fill="#8E8EA2"/>
                            <!-- Labels -->
                            <text x="360" y="150" fill="#15B787" font-size="13" font-family="sans-serif" font-weight="bold">TR5 Nodule (Hypoechoic)</text>
                            <text x="70" y="50" fill="#A337DB" font-size="12" font-family="sans-serif" font-weight="bold">FNA Biopsy Needle</text>
                            <text x="24" y="324" fill="#15B787" font-size="11" font-family="sans-serif">Guideline Recommendation: Nodule ≥ 1.0 cm (FNA Indicated)</text>
                        </svg>`,
            caption: "Fig 3: Thyroid ultrasound scan mockup showing a TR5 nodule with microcalcifications and a conceptual trajectory of fine-needle aspiration (FNA)."
        }
    ];

    // ─── Typing Animation and Chat Simulation ──────────────────────────────
    const $prompts = $('.sandbox-prompt-btn');
    const $userBalloon = $('#sandbox-user-balloon');
    const $assistantBalloon = $('#sandbox-assistant-balloon');
    const $loading = $('#sandbox-loading');
    const $engineLabel = $('#sandbox-engine-label');
    const $scanTrigger = $('#sandbox-scan-trigger');

    let currentPromptIndex = 0;
    let typingTimer = null;

    function simulatePrompt(index) {
        // Clear active states
        $prompts.removeClass('active');
        $prompts.eq(index).addClass('active');

        // Cancel previous timer
        if (typingTimer) {
            clearInterval(typingTimer);
        }

        const data = sandboxData[index];

        // 1. Fade out current balloons
        $userBalloon.css({ opacity: 0, display: 'none' });
        $assistantBalloon.css({ opacity: 0, display: 'none' });
        $loading.hide();

        // Update mode label
        $engineLabel.text(data.engine);
        if (data.isGuideline) {
            $engineLabel.css('color', '#15B787');
        } else {
            $engineLabel.css('color', '#A337DB');
        }

        // 2. Type the user query
        const queryText = data.query;
        let charIndex = 0;
        $userBalloon.html('<p></p>').show().animate({ opacity: 1 }, 150);

        typingTimer = setInterval(function() {
            if (charIndex < queryText.length) {
                $userBalloon.find('p').append(queryText.charAt(charIndex));
                charIndex++;
            } else {
                clearInterval(typingTimer);
                
                // 3. Show loading skeleton
                setTimeout(function() {
                    $loading.fadeIn(200);
                    
                    // 4. Show response balloon after short delay
                    setTimeout(function() {
                        $loading.fadeOut(150, function() {
                            // Populate response balloon
                            $assistantBalloon.html(data.html).show().animate({ opacity: 1 }, 250);
                            
                            // Re-append the media trigger
                            const $mediaBtn = $('<div class="sandbox-media-trigger" id="sandbox-scan-trigger"></div>');
                            $mediaBtn.attr('data-caption', data.caption);
                            $mediaBtn.attr('data-index', index);
                            $mediaBtn.html(`
                                <div class="trigger-overlay">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <span>Click to Zoom Literature Scan</span>
                                </div>
                                <div class="trigger-placeholder-image ${data.mediaClass}"></div>
                            `);
                            $assistantBalloon.append($mediaBtn);
                        });
                    }, 800); // Simulated RAG latency
                }, 400);
            }
        }, 18); // Typing speed
    }

    // Bind click events on sandbox buttons
    $prompts.on('click', function() {
        const index = parseInt($(this).data('index'), 10);
        if (index === currentPromptIndex) return;
        currentPromptIndex = index;
        simulatePrompt(index);
    });

    // ─── Clinical Lightbox system ───────────────────────────────────────────
    const $lightbox = $('#sonoai-landing-lightbox');
    const $lightboxImg = $('#sonoai-landing-lightbox-img');
    const $lightboxCaption = $('#sonoai-landing-lightbox-caption');
    const $lightboxContent = $lightbox.find('.sonoai-landing-lightbox-content');

    $(document).on('click', '#sandbox-scan-trigger', function() {
        const index = parseInt($(this).attr('data-index'), 10);
        const data = sandboxData[index];

        // Clean out previous content (whether image or inline SVG)
        $lightboxContent.empty();

        if (data.svgMarkup) {
            // Render high-fidelity SVG directly into the container!
            $lightboxContent.append(data.svgMarkup);
        } else {
            // Fallback to image tag
            const $img = $('<img id="sonoai-landing-lightbox-img" src="" alt="">');
            $img.attr('src', $(this).attr('data-image-url') || '');
            $lightboxContent.append($img);
        }

        // Set caption and open modal
        $lightboxCaption.text($(this).attr('data-caption') || '');
        $lightbox.removeAttr('hidden').attr('aria-hidden', 'false').css({ display: 'flex', opacity: 0 }).animate({ opacity: 1 }, 200);
    });

    // Close lightbox functions
    function closeLightbox() {
        $lightbox.animate({ opacity: 0 }, 150, function() {
            $lightbox.attr('hidden', true).attr('aria-hidden', 'true').css('display', 'none');
        });
    }

    $('.sonoai-landing-lightbox-close').on('click', closeLightbox);
    $lightbox.on('click', function(e) {
        if ($(e.target).closest('.sonoai-landing-lightbox-content').length === 0 && $(e.target).closest('.sonoai-landing-lightbox-caption').length === 0) {
            closeLightbox();
        }
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$lightbox.attr('hidden')) {
            closeLightbox();
        }
    });
});
