/**
 * SonoAI — Chat interface controller.
 * Vanilla JS — no framework dependency.
 *
 * sonoai_vars is provided by wp_localize_script and contains:
 *   rest_url, nonce, is_logged_in, login_url, user, history_limit, i18n
 */

/* global sonoai_vars */

(function () {
    'use strict';

    if ( ! sonoai_vars ) {
        return;
    }

    // ── State ──────────────────────────────────────────────────────────────
    const state = {
        sessionUuid : sonoai_vars.session_uuid || null,
        sending     : false,
        sessions    : [],
        mode        : localStorage.getItem('sonoai_mode') || 'guideline',
        lightboxImages: [],
        lightboxIndex : 0,
        imageLibrary  : {}, // NEW: Persistent session-wide image map [ID -> {url, label}]
    };

    // ── DOM refs ───────────────────────────────────────────────────────────
    const app           = document.getElementById('sonoai-app');
    const sidebar       = document.getElementById('sonoai-sidebar');
    const overlay       = document.getElementById('sonoai-overlay');
    const historyContainer = document.getElementById('sonoai-history-container');
    const messages      = document.getElementById('sonoai-messages');
    const welcome       = document.getElementById('sonoai-welcome');
    const textarea      = document.getElementById('sonoai-input');
    const sendBtn       = document.getElementById('sonoai-send-btn');
    const sidebarToggle = document.getElementById('sonoai-sidebar-toggle');
    const newChatBtn    = document.getElementById('sonoai-new-chat');
    const newChatMobile = document.getElementById('sonoai-new-chat-mobile');
    const savedBtn      = document.getElementById('sonoai-saved-btn');
    const savedPanel    = document.getElementById('sonoai-saved-panel');
    const savedClose    = document.getElementById('sonoai-saved-close');

    // ── Boot ───────────────────────────────────────────────────────────────
    function init() {
        initTheme();
        bindEvents();

        // 1. If we have a UUID from PHP/URL and user is logged in, load it immediately to avoid "welcome flash".
        if (state.sessionUuid && sonoai_vars.is_logged_in) {
            openSession(state.sessionUuid, true);
        }

        // 2. Load history & saved responses in background if logged in.
        if (sonoai_vars.is_logged_in) {
            loadHistory();
            loadSavedResponses();
        }
        
        autoResizeTextarea();
    }

    // ── Event bindings ─────────────────────────────────────────────────────
    function bindEvents() {
        // Mode toggle.
        updateModeUI();
        document.querySelectorAll('.sonoai-mode-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                state.mode = btn.dataset.mode || 'research';
                localStorage.setItem('sonoai_mode', state.mode);
                updateModeUI();
            });
        });

        // Send on click or Enter (Shift+Enter = new line).
        sendBtn.addEventListener('click', handleSend);
        textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });

        // Enable send button when textarea has content.
        textarea.addEventListener('input', function () {
            sendBtn.disabled = textarea.value.trim().length === 0;
            autoResizeTextarea();
        });

        // Keyboard shortcut: Ctrl/Cmd + K → focus input.
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                textarea.focus();
            }
        });

        // Suggestion chips.
        document.querySelectorAll('.sonoai-suggestion').forEach(function (btn) {
            btn.addEventListener('click', function () {
                textarea.value = btn.dataset.query || '';
                textarea.dispatchEvent(new Event('input'));
                handleSend();
            });
        });

        // New chat.
        if (newChatBtn)    newChatBtn.addEventListener('click', startNewChat);
        if (newChatMobile) newChatMobile.addEventListener('click', startNewChat);

        // Sidebar toggle (mobile).
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (overlay)       overlay.addEventListener('click', closeSidebar);

        // ── Dark / Light mode toggle ──
        var themeToggleBtn = document.getElementById('sonoai-theme-toggle');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', function () {
                var isLight = app.classList.toggle('sonoai-light');
                localStorage.setItem('sonoai_theme', isLight ? 'light' : 'dark');
                var moon = themeToggleBtn.querySelector('.sonoai-icon-moon');
                var sun  = themeToggleBtn.querySelector('.sonoai-icon-sun');
                if (moon) moon.style.display = isLight ? 'none' : '';
                if (sun)  sun.style.display  = isLight ? '' : 'none';
            });
        }

        // Saved responses panel.
        if (savedBtn)   savedBtn.addEventListener('click', toggleSavedPanel);
        if (savedClose) savedClose.addEventListener('click', closeSavedPanel);

        // Handle back/forward navigation.
        window.addEventListener('popstate', function (e) {
            if (e.state && e.state.uuid) {
                openSession(e.state.uuid, true);
            } else {
                startNewChat();
            }
        });

        // Lightbox / Zoom delegation.
        messages.addEventListener('click', function(e) {
            if (e.target.classList.contains('sonoai-zoomable-img')) {
                const clickedImg = e.target;
                // Query all zoomable images currently in the messages container
                const allImgs = Array.from(messages.querySelectorAll('.sonoai-zoomable-img'));
                
                // Map to our lightbox state logic
                state.lightboxImages = allImgs.map(img => ({
                    url: img.src, // Fully resolved absolute URL
                    label: img.getAttribute('alt') || 'Clinical Visualization'
                }));
                
                state.lightboxIndex = allImgs.indexOf(clickedImg);
                if (state.lightboxIndex === -1) state.lightboxIndex = 0; // Fallback
                
                openLightbox();
            }
        });

        // Lightbox control buttons
        const lightbox = document.getElementById('sonoai-lightbox');
        if (lightbox) {
            lightbox.querySelector('.sonoai-lightbox-close').addEventListener('click', closeLightbox);
            lightbox.querySelector('.sonoai-lightbox-prev').addEventListener('click', () => navigateLightbox(-1));
            lightbox.querySelector('.sonoai-lightbox-next').addEventListener('click', () => navigateLightbox(1));
            
            // Close on backdrop click
            lightbox.addEventListener('click', function(e) {
                if (e.target === lightbox) closeLightbox();
            });

            // Keyboard navigation
            document.addEventListener('keydown', function(e) {
                if (lightbox.hidden) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') navigateLightbox(-1);
                if (e.key === 'ArrowRight') navigateLightbox(1);
            });
        }

        // ── Auth Modal Logic ──
        // Delegated natively to UsersWP via .uwp-login-link and .uwp-register-link classes

        // ── User Dropdown Logic ──
        var userMenuTrigger = document.getElementById('sonoai-user-menu-trigger');
        var userDropdown    = document.getElementById('sonoai-user-dropdown');
        if (userMenuTrigger && userDropdown) {
            userMenuTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var isHidden = userDropdown.hidden;
                userDropdown.hidden = !isHidden;
                userMenuTrigger.setAttribute('aria-expanded', isHidden);
            });

            // Close when clicking elsewhere
            document.addEventListener('click', function (e) {
                if (!userMenuTrigger.contains(e.target) && !userDropdown.contains(e.target)) {
                    userDropdown.hidden = true;
                    userMenuTrigger.setAttribute('aria-expanded', 'false');
                }
            });

            // Close on Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !userDropdown.hidden) {
                    userDropdown.hidden = true;
                    userMenuTrigger.setAttribute('aria-expanded', 'false');
                }
            });
        }
    }

    // ── Theme init (restore from localStorage) ────────────────────────────
    function initTheme() {
        var saved = localStorage.getItem('sonoai_theme');
        var themeToggleBtn = document.getElementById('sonoai-theme-toggle');
        if (saved === 'light') {
            app.classList.add('sonoai-light');
            if (themeToggleBtn) {
                var moon = themeToggleBtn.querySelector('.sonoai-icon-moon');
                var sun  = themeToggleBtn.querySelector('.sonoai-icon-sun');
                if (moon) moon.style.display = 'none';
                if (sun)  sun.style.display  = '';
            }
        }
    }

    // ── Sidebar helpers ────────────────────────────────────────────────────
    function toggleSidebar() {
        const open = sidebar.classList.toggle('open');
        sidebarToggle.setAttribute('aria-expanded', open);
        overlay.hidden = !open;
        overlay.setAttribute('aria-hidden', !open);
        if (!open) closeSavedPanel();
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        sidebarToggle.setAttribute('aria-expanded', 'false');
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        closeSavedPanel();
    }

    function toggleSavedPanel() {
        if (!savedPanel) return;
        const isOpen = savedPanel.classList.toggle('open');
        savedPanel.setAttribute('aria-hidden', !isOpen);
        if (savedBtn) savedBtn.classList.toggle('active', isOpen);
    }

    function closeSavedPanel() {
        if (!savedPanel) return;
        savedPanel.classList.remove('open');
        savedPanel.setAttribute('aria-hidden', 'true');
        if (savedBtn) savedBtn.classList.remove('active');
    }

    function updateModeUI() {
        document.querySelectorAll('.sonoai-mode-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.mode === state.mode);
        });
    }

    // ── History ────────────────────────────────────────────────────────────
    function loadHistory() {
        return apiFetch('history')
            .then(function (sessions) {
                state.sessions = Array.isArray(sessions) ? sessions : [];
                renderHistoryList();
            })
            .catch(function () {
                // Silently fail if no sessions yet.
            });
    }

    function renderHistoryList() {
        historyContainer.innerHTML = '';

        if (state.sessions.length === 0) {
            const label = document.createElement('p');
            label.className = 'sonoai-history-label';
            label.textContent = 'RECENT';
            const ul = document.createElement('ul');
            ul.className = 'sonoai-history-list';
            ul.role = 'list';
            const li = document.createElement('li');
            li.className = 'sonoai-history-empty';
            li.textContent = sonoai_vars.i18n.no_history;
            ul.appendChild(li);
            historyContainer.appendChild(label);
            historyContainer.appendChild(ul);
            return;
        }

        let savedModes = {};
        try {
            savedModes = JSON.parse(localStorage.getItem('sonoai_session_modes') || '{}');
        } catch(e) {}

        const researchSessions = [];
        const guidelineSessions = [];

        state.sessions.forEach(function (s) {
            // Prefer mode from server response; fall back to localStorage map.
            let savedModes = {};
            try { savedModes = JSON.parse(localStorage.getItem('sonoai_session_modes') || '{}'); } catch(e) {}
            const mode = s.mode || savedModes[s.session_uuid] || 'guideline';
            if (mode === 'guideline') {
                guidelineSessions.push(s);
            } else {
                researchSessions.push(s);
            }
        });

        function renderGroup(groupName, items, isSecondGroup, modeClass) {
            if (items.length === 0) return;
            
            const label = document.createElement('p');
            label.className = 'sonoai-history-label' + (isSecondGroup ? ' sonoai-history-label-2' : '') + (modeClass ? ' ' + modeClass + '-label' : '');
            label.textContent = groupName;
            historyContainer.appendChild(label);

            const ul = document.createElement('ul');
            ul.className = 'sonoai-history-list';
            ul.role = 'list';

            items.forEach(function (s) {
                const isGuideline = (modeClass === 'guideline');
                const li  = document.createElement('li');
                li.className = 'sonoai-history-item'
                    + (s.session_uuid === state.sessionUuid ? ' active' : '')
                    + (isGuideline ? ' guideline-item' : '');
                li.dataset.uuid = s.session_uuid;

                const iconWrap = document.createElement('span');
                iconWrap.className = 'sonoai-history-icon-wrap';
                iconWrap.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';

                const text = document.createElement('span');
                text.className = 'sonoai-history-text';
                text.textContent = s.title || sonoai_vars.i18n.new_chat;

                const del = document.createElement('button');
                del.className = 'sonoai-history-delete';
                del.title = sonoai_vars.i18n.delete;
                del.setAttribute('aria-label', sonoai_vars.i18n.delete + ': ' + text.textContent);
                del.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';

                del.addEventListener('click', function (e) {
                    e.stopPropagation();
                    deleteSession(s.session_uuid);
                });

                li.addEventListener('click', function () {
                    openSession(s.session_uuid);
                    closeSidebar();
                });

                li.appendChild(iconWrap);
                li.appendChild(text);
                li.appendChild(del);
                ul.appendChild(li);
            });
            
            historyContainer.appendChild(ul);
        }

        let hasResearch = researchSessions.length > 0;
        renderGroup('RECENT RESEARCH', researchSessions, false, 'research');
        renderGroup('RECENT GUIDELINE', guidelineSessions, hasResearch, 'guideline');
    }

    function openSession(uuid, skipPushState) {
        state.sessionUuid = uuid;

        if (!skipPushState) {
            updateUrl(uuid);
        }

        apiFetch('history/' + uuid)
            .then(function (session) {
                // Restore mode from server response.
                const sessionMode = session.mode || 'guideline';
                state.mode = sessionMode;
                localStorage.setItem('sonoai_mode', state.mode);

                // Also persist in local map for offline grouping.
                let savedModes = {};
                try { savedModes = JSON.parse(localStorage.getItem('sonoai_session_modes') || '{}'); } catch(e){}
                savedModes[uuid] = sessionMode;
                localStorage.setItem('sonoai_session_modes', JSON.stringify(savedModes));

                updateModeUI();
                if (welcome) welcome.style.display = 'none';

                clearMessages();
                const msgs = session.messages || [];
                msgs.forEach(function (m) {
                    // Merge historical image metadata into global library
                    const msgImgs = m.images || m.context_images || {};
                    Object.assign(state.imageLibrary, msgImgs);
                    
                    appendMessage(m.role, m.content, m.image_url || '', msgImgs, m.saved_id, true);
                });
                updateHistoryActiveState();
                scrollToBottom();
            })
            .catch(function () {
                showError(sonoai_vars.i18n.error);
            });
    }

    function deleteSession(uuid) {
        apiFetch('history/' + uuid, { method: 'DELETE' })
            .then(function () {
                if (state.sessionUuid === uuid) {
                    startNewChat();
                }
                state.sessions = state.sessions.filter(function (s) { return s.session_uuid !== uuid; });
                renderHistoryList();
                // Refresh saved responses as they might have been deleted (cascade).
                loadSavedResponses();
            })
            .catch(function () {
                showError(sonoai_vars.i18n.error);
            });
    }

    function updateHistoryActiveState() {
        document.querySelectorAll('.sonoai-history-item').forEach(function (li) {
            li.classList.toggle('active', li.dataset.uuid === state.sessionUuid);
        });
    }

    // ── New chat ───────────────────────────────────────────────────────────
    function startNewChat() {
        state.sessionUuid = null;
        updateUrl(null);
        clearMessages();
        if (welcome) welcome.style.display = '';
        textarea.value = '';
        textarea.dispatchEvent(new Event('input'));
        if (window.innerWidth < 768) {
            closeSidebar();
        }
    }

    function updateUrl(uuid) {
        let newUrl = sonoai_vars.base_url || window.location.origin + window.location.pathname;
        if (uuid) {
            // Remove trailing slash if exists to normalize
            newUrl = newUrl.replace(/\/$/, '');
            newUrl += '/' + uuid;
        }
        history.pushState({ uuid: uuid }, '', newUrl);
    }

    // ── Send message ───────────────────────────────────────────────────────
    function handleSend() {
        if (!sonoai_vars.is_logged_in) {
            if (typeof window.uwp_modal_login_form === 'function') {
                window.uwp_modal_login_form();
            } else {
                const loginLink = document.querySelector('.uwp-login-link');
                if (loginLink) loginLink.click();
            }
            return;
        }

        const text = textarea.value.trim();
        if (!text || state.sending) {
            return;
        }

        // Hide welcome screen.
        if (welcome) welcome.style.display = 'none';

        // Show user message immediately.
        const imageUrl = '';
        appendMessage('user', text, imageUrl);

        performChatStream(text, state.mode, state.sessionUuid, imageUrl);
    }

    /**
     * Reusable streaming chat request logic.
     */
    async function performChatStream(text, mode, sessionUuid, imageUrl) {
        if (state.sending) return;

        // Reset input if called from handleSend.
        textarea.value = '';
        textarea.dispatchEvent(new Event('input'));

        // Hide welcome screen.
        if (welcome) welcome.style.display = 'none';

        // Show typing indicator.
        const typingEl = appendTyping();
        state.sending  = true;
        state.isStreaming = true; // NEW: track streaming state
        sendBtn.disabled = true;

        const formData = new FormData();
        formData.append('message', text);
        formData.append('mode', mode); 
        if (sessionUuid) {
            formData.append('session_uuid', sessionUuid);
        }
        formData.append('stream', '1');

        fetch(sonoai_vars.rest_url + 'chat', {
            method: 'POST',
            body: formData,
            headers: { 'X-WP-Nonce': sonoai_vars.nonce }
        }).then(async function (response) {
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(errorText || sonoai_vars.i18n.error);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';
            
            let assistantWrapper = null;
            let bubble = null;
            let fullReply = '';
            let firstChunkReceived = false;

            try {
                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    
                    buffer += decoder.decode(value, { stream: true });
                    
                    let events = buffer.split('\n\n');
                    buffer = events.pop();
                    
                    for (let i = 0; i < events.length; i++) {
                        let ev = events[i];
                        if (!ev.trim()) continue;
                        
                        let lines = ev.split('\n');
                        let eventType = 'message';
                        let eventData = '';
                        
                        lines.forEach(function(l) {
                            if (l.startsWith('event: ')) eventType = l.substring(7).trim();
                            if (l.startsWith('data: ')) {
                                eventData = l.substring(6).trim();
                            }
                        });
                        
                        if (eventData) {
                            const parsed = JSON.parse(eventData);
                            
                            if (eventType === 'meta') {
                                state.sessionUuid = parsed.session_uuid;
                                if (parsed.context_images) {
                                    Object.assign(state.imageLibrary, parsed.context_images);
                                }
                                if (parsed.mode) {
                                    state.mode = parsed.mode;
                                    localStorage.setItem('sonoai_mode', state.mode);
                                    updateModeUI();
                                }
                                if (parsed.is_new_session) {
                                    state.sessions.unshift({
                                        session_uuid: parsed.session_uuid,
                                        title       : text.substring(0, 80),
                                        mode        : state.mode,
                                    });
                                    let savedModes = {};
                                    try { savedModes = JSON.parse(localStorage.getItem('sonoai_session_modes') || '{}'); } catch(e){}
                                    savedModes[parsed.session_uuid] = state.mode;
                                    localStorage.setItem('sonoai_session_modes', JSON.stringify(savedModes));
                                    renderHistoryList();
                                    updateUrl(parsed.session_uuid);
                                }
                                assistantWrapper = appendMessage('assistant', '', '', {}, null, false);
                                bubble = assistantWrapper.querySelector('.sonoai-bubble');
                                updateHistoryActiveState();
                            }
                            else if (eventType === 'chunk') {
                                if (!firstChunkReceived && parsed.chunk && parsed.chunk.trim().length > 0) {
                                    firstChunkReceived = true;
                                    if (typingEl) {
                                        const typingPill = typingEl.querySelector('.sonoai-typing');
                                        if (typingPill) typingPill.classList.add('sonoai-typing-fade-out');
                                        setTimeout(function() { 
                                            if (typingEl && typingEl.parentNode) typingEl.remove(); 
                                        }, 300);
                                    }
                                }
                                fullReply += parsed.chunk;
                                if (bubble) {
                                    bubble.innerHTML = markdownToHtml(fullReply, state.imageLibrary);
                                    scrollToBottom();
                                }
                            }
                            else if (eventType === 'meta_end') {
                                // Decoupled Payload: Images arrive at the end
                                if (parsed.context_images) {
                                    // Merge into global library for persistence
                                    Object.assign(state.imageLibrary, parsed.context_images);
                                }
                            }
                            else if (eventType === 'error') {
                                throw new Error(parsed.error);
                            }
                        }
                    }
                }
            } finally {
                reader.releaseLock();
                state.sending = false;
                state.isStreaming = false; // Streaming finished
                
                if (typingEl && typingEl.parentNode) {
                    const finalPill = typingEl.querySelector('.sonoai-typing');
                    if (finalPill) finalPill.classList.add('sonoai-typing-fade-out');
                    setTimeout(function() {
                        if (typingEl && typingEl.parentNode) typingEl.remove();
                    }, 300);
                }

                // Final render to reveal images (now that isStreaming is false and global library is populated)
                if (bubble) {
                    bubble.innerHTML = markdownToHtml(fullReply, state.imageLibrary);
                }

                // Check for "Show Images" opt-in text
                const offerPhrase = "Would you like to view the clinical presentation/sonogram images for this case?";
                if (fullReply.includes(offerPhrase)) {
                    showImageOptInChips(assistantWrapper);
                }

                if (assistantWrapper) {
                    const row = assistantWrapper.querySelector('.sonoai-action-row');
                    if (row) row.style.display = 'flex';
                    scrollToBottom();
                }
            }
            sendBtn.disabled = textarea.value.trim().length === 0;

        }).catch(function (err) {
            if (typingEl && typingEl.parentNode) typingEl.remove();
            state.sending    = false;
            state.isStreaming = false;
            sendBtn.disabled = textarea.value.trim().length === 0;
            showError(err.message || sonoai_vars.i18n.error);
        });
    }

    /**
     * Appends interactive "Show Images" suggestion chips inside the message body.
     */
    function showImageOptInChips(wrapper) {
        const body = wrapper.querySelector('.sonoai-message-body');
        if (!body) return;

        // Prevent duplicate chips
        if (body.querySelector('.sonoai-optin-chips')) return;

        const container = document.createElement('div');
        container.className = 'sonoai-optin-chips';
        container.style.cssText = 'display:flex;gap:8px;margin-top:10px;';

        const yesBtn = document.createElement('button');
        yesBtn.className = 'sonoai-suggestion';
        yesBtn.textContent = 'Yes, show images';
        yesBtn.dataset.query = 'Yes, show the clinical images.';
        yesBtn.addEventListener('click', function() {
            textarea.value = yesBtn.dataset.query;
            handleSend();
            container.remove();
        });

        const noBtn = document.createElement('button');
        noBtn.className = 'sonoai-suggestion';
        noBtn.textContent = 'No, thank you';
        noBtn.addEventListener('click', function() {
            container.remove();
        });

        container.appendChild(yesBtn);
        container.appendChild(noBtn);
        body.appendChild(container);
        scrollToBottom();
    }

    // ── Message rendering ──────────────────────────────────────────────────
    function appendMessage(role, content, imageUrl, images, savedId, showActions) {
        const isUser  = role === 'user';
        const wrapper = document.createElement('div');
        wrapper.className = 'sonoai-message ' + (isUser ? 'user' : 'assistant');
        if (savedId) {
            wrapper.dataset.savedId = savedId;
        }

        // Avatar (user only).
        if (isUser) {
            const avatarEl = document.createElement('div');
            avatarEl.className = 'sonoai-message-avatar';
            if (sonoai_vars.user && sonoai_vars.user.avatar) {
                const img = document.createElement('img');
                img.src = sonoai_vars.user.avatar;
                img.alt = sonoai_vars.user.first_name || '';
                avatarEl.appendChild(img);
            } else {
                avatarEl.textContent = (sonoai_vars.user && sonoai_vars.user.first_name)
                    ? sonoai_vars.user.first_name.charAt(0).toUpperCase()
                    : 'U';
            }
            wrapper.appendChild(avatarEl);
        }

        // Body.
        const body   = document.createElement('div');
        body.className = 'sonoai-message-body';

        // Image attachment (user messages).
        if (imageUrl && isUser) {
            const imgEl = document.createElement('img');
            imgEl.src    = imageUrl;
            imgEl.alt    = 'Uploaded sonogram';
            imgEl.className = 'sonoai-message-image';
            body.appendChild(imgEl);
        }

        // Text bubble.
        if (content !== null && content !== undefined) {
            const bubble = document.createElement('div');
            bubble.className = 'sonoai-bubble';
            bubble.innerHTML = isUser
                ? escapeHtml(content).replace(/\n/g, '<br>')
                : markdownToHtml(content, images);
            body.appendChild(bubble);
        }

        // Assistant actions for non-streamed historical messages

        // Action row for assistant messages.
        if (!isUser) {
            const actionRow = buildActionRow(wrapper, savedId);
            if (!showActions) {
                actionRow.style.display = 'none'; // hidden during stream
            }
            body.appendChild(actionRow);
        }

        wrapper.appendChild(body);
        messages.appendChild(wrapper);
        scrollToBottom();

        return wrapper;
    }

    // ── Build the action row beneath each assistant message ──
    function buildActionRow(wrapper, savedId) {
        const row = document.createElement('div');
        row.className = 'sonoai-action-row';

        function svgIcon(path, w, h) {
            w = w || 14; h = h || 14;
            return '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
        }

        function makeBtn(icon, label, cls, clickFn) {
            const btn = document.createElement('button');
            btn.className = 'sonoai-action-btn' + (cls ? ' ' + cls : '');
            btn.innerHTML = svgIcon(icon) + (label ? '<span>' + label + '</span>' : '');
            btn.title     = label || '';
            btn.addEventListener('click', clickFn);
            return btn;
        }

        // Thumbs up
        const upBtn = makeBtn(
            '<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>',
            '', 'sonoai-vote-up',
            function () {
                if(upBtn.classList.contains('sonoai-voted')) return;
                upBtn.classList.add('sonoai-voted');
                downBtn.classList.remove('sonoai-voted');
                submitFeedback(wrapper, 'up', '');
            }
        );

        // Thumbs down
        const downBtn = makeBtn(
            '<path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10z"/><path d="M17 2h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/>',
            '', 'sonoai-vote-down',
            function () {
                if(downBtn.classList.contains('sonoai-voted')) return;
                downBtn.classList.add('sonoai-voted');
                upBtn.classList.remove('sonoai-voted');
                submitFeedback(wrapper, 'down', '');
            }
        );

        // Save button
        const saveBtn = makeBtn(
            '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>', 'Save', '',
            function () {
                const isSaved = saveBtn.dataset.saved === '1';
                if (isSaved) {
                    // UNSAVE
                    const id = saveBtn.dataset.id;
                    if (!id) return;
                    saveBtn.disabled = true;
                    apiFetch('saved/' + id, { method: 'DELETE' })
                        .then(function () {
                            saveBtn.dataset.saved = '0';
                            saveBtn.dataset.id    = '';
                            saveBtn.classList.remove('active');
                            saveBtn.title = 'Save';
                            saveBtn.disabled = false;
                            loadSavedResponses();
                        })
                        .catch(function () {
                            saveBtn.disabled = false;
                        });
                    return;
                }

                // SAVE (existing logic)
                const bubble  = wrapper.querySelector('.sonoai-bubble');
                const content = bubble ? bubble.innerHTML : '';
                if (!content || !state.sessionUuid) return;

                const allMsgs = messages.querySelectorAll('.sonoai-message');
                let msgIndex  = 0;
                allMsgs.forEach(function (el, idx) {
                    if (el === wrapper) msgIndex = idx;
                });

                const fd = new FormData();
                fd.append('session_uuid',  state.sessionUuid);
                fd.append('message_index', msgIndex);
                fd.append('content',       content);
                fd.append('mode',          state.mode);

                saveBtn.disabled = true;
                apiFetch('saved', { method: 'POST', body: fd, isFormData: true })
                    .then(function (res) {
                        saveBtn.dataset.saved = '1';
                        saveBtn.dataset.id    = res.id;
                        saveBtn.classList.add('active');
                        saveBtn.title = 'Saved!';
                        saveBtn.disabled = false;
                        loadSavedResponses();
                    })
                    .catch(function () {
                        saveBtn.disabled = false;
                        saveBtn.title = 'Error saving';
                    });
            }
        );

        if (savedId) {
            saveBtn.dataset.saved = '1';
            saveBtn.dataset.id    = savedId;
            saveBtn.classList.add('active');
            saveBtn.title = 'Saved!';
        }

        // Regenerate — takes the closest previous user message and resubmits it
        const regenBtn = makeBtn(
            '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
            'Regenerate', '', function () {
                if (state.sending) return;
                
                // Find the user message before this assistant message
                let prevUserMsg = null;
                const allNodes = Array.from(messages.children);
                const currentIndex = allNodes.indexOf(wrapper);
                for (let i = currentIndex - 1; i >= 0; i--) {
                    if (allNodes[i].classList.contains('user')) {
                        prevUserMsg = allNodes[i].querySelector('.sonoai-bubble').innerText;
                        break;
                    }
                }
                
                if (prevUserMsg) {
                    // Send to backend without appending a NEW user message to the UI
                    performChatStream(prevUserMsg, state.mode, state.sessionUuid, '');
                }
            }
        );

        // Spacer
        const spacer = document.createElement('span');
        spacer.className = 'sonoai-action-divider';

        // Copy
        const copyBtn = makeBtn(
            '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'Copy', '',
            function () {
                const bubble = wrapper.querySelector('.sonoai-bubble');
                if (bubble) {
                    navigator.clipboard.writeText(bubble.innerText || '').then(function () {
                        const origHTML = copyBtn.innerHTML;
                        copyBtn.innerHTML = svgIcon('<polyline points="20 6 9 17 4 12"/>') + '<span>Copied!</span>';
                        copyBtn.classList.add('active');
                        setTimeout(function () {
                            copyBtn.innerHTML = origHTML;
                            copyBtn.classList.remove('active');
                        }, 2000);
                    });
                }
            }
        );

        row.appendChild(upBtn);
        row.appendChild(downBtn);
        row.appendChild(saveBtn);
        row.appendChild(regenBtn);
        row.appendChild(spacer);
        row.appendChild(copyBtn);

        return row;
    }

    function submitFeedback(wrapper, vote, comment) {
        if (!state.sessionUuid) return;

        // Calculate zero-based message index.
        const allMsgs = messages.querySelectorAll('.sonoai-message');
        let msgIndex  = 0;
        allMsgs.forEach(function (el, idx) {
            if (el === wrapper) msgIndex = idx;
        });

        const fd = new FormData();
        fd.append('session_uuid',  state.sessionUuid);
        fd.append('message_index', msgIndex);
        fd.append('vote',          vote);
        fd.append('comment',       comment || '');

        apiFetch('feedback', { method: 'POST', body: fd, isFormData: true })
            .catch(function (e) {
                console.error("Failed to submit feedback", e);
            });
    }

    function appendTyping() {
        const typingWrapper = document.createElement('div');
        typingWrapper.className = 'sonoai-message assistant';

        const body = document.createElement('div');
        body.className = 'sonoai-message-body';

        const typing = document.createElement('div');
        typing.className = 'sonoai-typing';
        typing.innerHTML = '<span class="sonoai-typing-label">Thinking</span><div class="sonoai-typing-dots"><span></span><span></span><span></span></div>';
        body.appendChild(typing);

        typingWrapper.appendChild(body);
        messages.appendChild(typingWrapper);
        scrollToBottom();

        return typingWrapper;
    }

    function clearMessages() {
        Array.from(messages.children).forEach(function (child) {
            if (welcome && child === welcome) return;
            child.remove();
        });
        if (welcome && welcome.parentNode !== messages) {
            messages.appendChild(welcome);
        }
    }

    function showError(msg) {
        const errEl  = document.createElement('div');
        errEl.className = 'sonoai-message assistant';
        const body   = document.createElement('div');
        body.className = 'sonoai-message-body';
        const bubble = document.createElement('div');
        bubble.className = 'sonoai-bubble sonoai-error-bubble';
        bubble.textContent       = '⚠ ' + msg;
        body.appendChild(bubble);
        errEl.appendChild(body);
        messages.appendChild(errEl);
        scrollToBottom();
    }

    // ── Saved Responses ────────────────────────────────────────────────────

    function loadSavedResponses() {
        if (!savedPanel) return;
        apiFetch('saved')
            .then(function (items) {
                renderSavedList(Array.isArray(items) ? items : []);
            })
            .catch(function () {
                // Silently fail.
            });
    }

    function renderSavedList(items) {
        if (!savedPanel) return;
        let listEl = savedPanel.querySelector('.sonoai-saved-list');
        if (!listEl) {
            listEl = document.createElement('div');
            listEl.className = 'sonoai-saved-list';
            savedPanel.appendChild(listEl);
        }
        listEl.innerHTML = '';

        if (items.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'sonoai-saved-empty';
            empty.textContent = 'No saved responses yet.';
            listEl.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            const card = document.createElement('div');
            card.className = 'sonoai-saved-item';
            card.dataset.id           = item.id;
            card.dataset.sessionUuid  = item.session_uuid;
            card.dataset.messageIndex = item.message_index;
            card.dataset.mode         = item.mode;

            const modePill = document.createElement('span');
            modePill.className = 'sonoai-saved-mode sonoai-mode-' + (item.mode || 'guideline');
            modePill.textContent = item.mode === 'research' ? 'Research' : 'Guideline';

            const titleEl = document.createElement('p');
            titleEl.className = 'sonoai-saved-title';
            titleEl.textContent = item.title || 'Saved response';

            const dateEl = document.createElement('span');
            dateEl.className = 'sonoai-saved-date';
            const d = new Date(item.created_at);
            dateEl.textContent = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

            const delBtn = document.createElement('button');
            delBtn.className = 'sonoai-saved-delete';
            delBtn.title     = 'Remove saved response';
            delBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>';
            delBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                apiFetch('saved/' + item.id, { method: 'DELETE' })
                    .then(function () { loadSavedResponses(); })
                    .catch(function () {});
            });

            card.appendChild(modePill);
            card.appendChild(titleEl);
            card.appendChild(dateEl);
            card.appendChild(delBtn);

            // Click: deep-link to session + message.
            card.addEventListener('click', function () {
                closeSavedPanel();
                if (window.innerWidth < 768) closeSidebar();

                // Switch mode first.
                state.mode = item.mode || 'guideline';
                localStorage.setItem('sonoai_mode', state.mode);
                updateModeUI();

                // Load session messages.
                state.sessionUuid = item.session_uuid;
                apiFetch('history/' + item.session_uuid)
                    .then(function (session) {
                        clearMessages();
                        const msgs = session.messages || [];
                        msgs.forEach(function (m) {
                            appendMessage(m.role, m.content, m.image_url || '', m.context_images || [], m.saved_id, true);
                        });
                        updateHistoryActiveState();

                        // Scroll to + highlight the target message.
                        requestAnimationFrame(function () {
                            const allMsgs = messages.querySelectorAll('.sonoai-message.assistant');
                            const targetIdx = item.message_index;
                            // Count only assistant messages for index.
                            let assistantCount = -1;
                            const allMsgEls = messages.querySelectorAll('.sonoai-message');
                            let targetEl = null;
                            allMsgEls.forEach(function (el) {
                                if (el.classList.contains('assistant')) {
                                    assistantCount++;
                                    if (assistantCount === targetIdx) {
                                        targetEl = el;
                                    }
                                }
                            });
                            if (targetEl) {
                                targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                targetEl.classList.add('sonoai-highlight');
                                setTimeout(function () {
                                    targetEl.classList.remove('sonoai-highlight');
                                }, 3000);
                            }
                        });
                    })
                    .catch(function () {
                        showError(sonoai_vars.i18n.error);
                    });
            });

            listEl.appendChild(card);
        });
    }



    // ── Lightbox Logic ──
    function openLightbox() {
        const lightbox = document.getElementById('sonoai-lightbox');
        if (!lightbox || state.lightboxImages.length === 0) return;
        
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden'; // Prevent scroll
        
        updateLightboxUI();
    }

    function closeLightbox() {
        const lightbox = document.getElementById('sonoai-lightbox');
        if (!lightbox) return;
        
        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function navigateLightbox(dir) {
        let newIndex = state.lightboxIndex + dir;
        if (newIndex < 0) newIndex = 0;
        if (newIndex >= state.lightboxImages.length) newIndex = state.lightboxImages.length - 1;
        
        if (newIndex !== state.lightboxIndex) {
            state.lightboxIndex = newIndex;
            updateLightboxUI();
        }
    }

    function updateLightboxUI() {
        const lightbox = document.getElementById('sonoai-lightbox');
        const img = document.getElementById('sonoai-lightbox-img');
        const caption = document.getElementById('sonoai-lightbox-caption');
        const counter = document.getElementById('sonoai-lightbox-counter');
        const prevBtn = lightbox.querySelector('.sonoai-lightbox-prev');
        const nextBtn = lightbox.querySelector('.sonoai-lightbox-next');
        
        const data = state.lightboxImages[state.lightboxIndex];
        if (!data) return;
        
        // Nuclear Stabilization: Clear src first to force a reload event
        img.style.opacity = '0';
        img.src = '';
        
        // Wait for next frame before setting new src to ensure event triggers
        requestAnimationFrame(() => {
            img.src = data.url;
            img.onload = () => {
                img.style.opacity = '1';
                img.style.visibility = 'visible';
            };
        });

        caption.textContent = data.label;
        counter.textContent = (state.lightboxIndex + 1) + ' / ' + state.lightboxImages.length;
        
        prevBtn.disabled = (state.lightboxIndex === 0);
        nextBtn.disabled = (state.lightboxIndex === state.lightboxImages.length - 1);
    }

    // ── Utilities ──────────────────────────────────────────────────────────
    function scrollToBottom() {
        requestAnimationFrame(function () {
            messages.scrollTop = messages.scrollHeight;
        });
    }

    function autoResizeTextarea() {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 180) + 'px';
    }

    function escapeHtml(str) {
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;');
    }

    /**
     * Section-aware markdown -> HTML renderer.
     *
     * Custom fence blocks (set via system prompt):
     *
     *   :::grid
     *   | LABEL | VALUE | SUBLABEL |
     *   :::
     *
     *   :::checklist
     *   Item text
     *   :::
     *
     * Standard ## headings become labelled .sonoai-section cards.
     */
    function markdownToHtml(text, imagesArg) {
        var blocks = [];
        function protect(html) {
            var id = '\x01B' + blocks.length + '\x01';
            blocks.push(html);
            return id;
        }

        // 1. Protect code blocks.
        text = text.replace(/```[\w]*\n?([\s\S]*?)```/g, function(_, code) {
            return protect('<pre><code>' + escapeHtml(code.trim()) + '</code></pre>');
        });

        // 2. Parse :::grid fences.
        text = text.replace(/:::grid\n([\s\S]*?):::/g, function(_, inner) {
            var rows = inner.trim().split('\n');
            var h = '<div class="sonoai-grid-2">';
            rows.forEach(function(row) {
                var parts = row.split('|').map(function(p) { return p.trim(); }).filter(Boolean);
                if (parts.length < 2) return;
                var lbl = escapeHtml(parts[0]);
                var val = escapeHtml(parts[1]);
                var sub = parts[2] ? escapeHtml(parts[2]) : '';
                h += '<div class="sonoai-metric-card">';
                h += '<span class="sonoai-metric-label">' + lbl + '</span>';
                h += '<span class="sonoai-metric-value">' + val;
                if (sub) { h += ' <span class="sonoai-metric-sub">(' + sub + ')</span>'; }
                h += '</span></div>';
            });
            h += '</div>';
            return protect(h);
        });

        // 3. Parse :::checklist fences.
        var chkSvg = '<span class="sonoai-check-icon"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>';
        text = text.replace(/:::checklist\n([\s\S]*?):::/g, function(_, inner) {
            var lines = inner.trim().split('\n').filter(function(l) { return l.trim(); });
            var h = '<ul class="sonoai-checklist">';
            lines.forEach(function(line) {
                var clean = escapeHtml(line.replace(/^[-*]\s*/, '').trim());
                h += '<li>' + chkSvg + '<span>' + clean + '</span></li>';
            });
            h += '</ul>';
            return protect(h);
        });

        // 3a. Parse :::sources fences.
        var linkSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
        text = text.replace(/:::sources\n([\s\S]*?):::/g, function(_, inner) {
            var lines = inner.trim().split('\n').filter(function(l) { return l.trim(); });
            var h = '<div class="sonoai-sources-container">';
            lines.forEach(function(line) {
                var parts   = line.split('|').map(function(p) { return p.trim(); });
                var name    = escapeHtml(parts[0]);
                var country = '';
                var url     = '';

                if (parts.length === 3) {
                    country = escapeHtml(parts[1]);
                    url     = parts[2];
                } else {
                    url     = parts[1] || '';
                }
                
                var labelHtml = '<strong>Source:</strong> <span>' + name + '</span>';
                if (country) {
                    labelHtml += '<span class="sonoai-country-badge">' + country + '</span>';
                }
                
                if (url && (url.startsWith('http') || url.startsWith('www'))) {
                    h += '<a href="' + url + '" target="_blank" class="sonoai-source-pill">' + linkSvg + labelHtml + '</a>';
                } else {
                    h += '<span class="sonoai-source-pill">' + labelHtml + '</span>';
                }
            });
            h += '</div>';
            return protect(h);
        });

        // 3a(bis). Fallback for bracketed sources [Source: Name | Country | URL]
        text = text.replace(/\[Source:\s*(.*?)\s*\|?\s*(.*?|)\s*\|?\s*(.*?|)\s*\]/gi, function(_, name, country, url) {
            var h = '<div class="sonoai-sources-container">';
            name = name.trim();
            country = (country || '').trim();
            url = (url || '').trim();

            if (!url && country.startsWith('http')) {
                url = country;
                country = '';
            }

            var labelHtml = '<strong>Source:</strong> <span>' + escapeHtml(name) + '</span>';
            if (country) {
                labelHtml += '<span class="sonoai-country-badge">' + escapeHtml(country) + '</span>';
            }
            
            if (url && (url.startsWith('http') || url.startsWith('www'))) {
                h += '<a href="' + url + '" target="_blank" class="sonoai-source-pill">' + linkSvg + labelHtml + '</a>';
            } else {
                h += '<span class="sonoai-source-pill">' + labelHtml + '</span>';
            }
            h += '</div>';
            return protect(h);
        });

        // 3b. Parse :::image | ID | Label ::: fences for clinical citations (Hardened Regex).
        text = text.replace(/:::image\s*\|\s*(.*?)\s*\|\s*(.*?)\s*:::/gi, function(_, id, label) {
            id = id.trim();
            label = label.trim();

            // Decouple Payload: Suppress rendering if still streaming
            if (state.isStreaming) {
                return protect('<div class="sonoai-image-placeholder"><span>Loading clinical visualization...</span></div>');
            }

            var url = id;
            // Guarded lookup to prevent ReferenceError and ensure persistence
            var images = (typeof imagesArg !== 'undefined' && imagesArg) ? imagesArg : state.imageLibrary;

            // Resolve ID to URL if possible.
            if (images && images[id]) {
                url = images[id].url;
            } else if (id.startsWith('http')) {
                url = id; // Fallback for direct URLs
            }

            var h = '<div class="sonoai-image-card">';
            h += '<img src="' + url + '" alt="' + escapeHtml(label) + '" class="sonoai-zoomable-img">';
            h += '<div class="sonoai-image-label">' + escapeHtml(label) + '</div>';
            h += '</div>';
            return protect(h);
        });

        // 4. Escape remaining raw HTML.
        var html = escapeHtml(text);

        // 5. Inline code.
        html = html.replace(/`([^`]+)`/g, function(_, code) {
            return protect('<code>' + code + '</code>');
        });

        // 6. Bold & Italic.
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

        // 7. Section-aware heading processing.
        var lines = html.split('\n');
        var output = [];
        var inSection = false;
        var buf = [];

        function flushSection() {
            if (!inSection) return;
            var body = buf.join('\n').trim();
            body = body.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            body = body.replace(/\*(.+?)\*/g, '<em>$1</em>');
            body = body.replace(/\n/g, '<br>');
            output.push('<div class="sonoai-section-box">' + body + '</div>');
            output.push('</div>');
            buf = [];
            inSection = false;
        }

        lines.forEach(function(line) {
            var h2 = line.match(/^## (.+)$/);
            var h3 = line.match(/^### (.+)$/);
            if (h2) {
                flushSection();
                output.push('<div class="sonoai-section">');
                output.push('<div class="sonoai-section-title">' + h2[1] + '</div>');
                inSection = true;
            } else if (h3) {
                flushSection();
                output.push('<h4 style="font-size:13px;font-weight:700;color:#c8c8d8;margin:8px 0 4px;">' + h3[1] + '</h4>');
            } else if (inSection) {
                buf.push(line);
            } else {
                output.push(line);
            }
        });
        flushSection();
        html = output.join('\n');

        // 8. Unordered lists.
        html = html.replace(/^\s*[-*] (.+)/gm, '<li data-t="ul">$1</li>');
        html = html.replace(/(<li data-t="ul">[\s\S]*?<\/li>\n?)+/g, '<ul>$&</ul>');
        html = html.replace(/ data-t="ul"/g, '');

        // 9. Ordered lists.
        html = html.replace(/^\s*\d+\. (.+)/gm, '<li data-t="ol">$1</li>');
        html = html.replace(/(<li data-t="ol">[\s\S]*?<\/li>\n?)+/g, '<ol>$&</ol>');
        html = html.replace(/ data-t="ol"/g, '');

        // 10. Paragraphs.
        html = html.replace(/\n{2,}/g, '</p><p>');
        html = html.replace(/\n/g, '<br>');
        html = '<p>' + html + '</p>';

        // 11. Clean up.
        html = html.replace(/<p>\s*<\/p>/g, '');
        ['ul','ol','pre','div'].forEach(function(tag) {
            html = html.replace(new RegExp('<p>(<' + tag + '[\\s\\S]*?>)', 'g'), '$1');
            html = html.replace(new RegExp('(<\\/' + tag + '>)<\/p>', 'g'), '$1');
        });

        // 12. Restore protected blocks.
        blocks.forEach(function(block, i) {
            html = html.split('\x01B' + i + '\x01').join(block);
        });

        return html;
    }

    // ── REST API fetch helper ──────────────────────────────────────────────
    function apiFetch(endpoint, options) {
        options = options || {};

        const headers = {
            'X-WP-Nonce': sonoai_vars.nonce,
        };

        // Don't set Content-Type for FormData — browser sets boundary automatically.
        if (!options.isFormData) {
            headers['Content-Type'] = 'application/json';
        }

        return fetch(sonoai_vars.rest_url + endpoint, {
            method : options.method  || 'GET',
            headers: headers,
            body   : options.body    || undefined,
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) {
                    throw new Error(data.message || data.error || 'API error ' + r.status);
                }
                return data;
            });
        });
    }

    // ── Start ──────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
