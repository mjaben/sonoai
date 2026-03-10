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

    if ( ! sonoai_vars || ! sonoai_vars.is_logged_in ) {
        return; // Nothing to boot for logged-out visitors.
    }

    // ── State ──────────────────────────────────────────────────────────────
    const state = {
        sessionUuid : null,
        pendingImage: null,   // { file: File, b64preview: string }
        sending     : false,
        sessions    : [],
    };

    // ── DOM refs ───────────────────────────────────────────────────────────
    const app           = document.getElementById('sonoai-app');
    const sidebar       = document.getElementById('sonoai-sidebar');
    const overlay       = document.getElementById('sonoai-overlay');
    const historyList   = document.getElementById('sonoai-history-list');
    const messages      = document.getElementById('sonoai-messages');
    const welcome       = document.getElementById('sonoai-welcome');
    const textarea      = document.getElementById('sonoai-input');
    const sendBtn       = document.getElementById('sonoai-send-btn');
    const fileInput     = document.getElementById('sonoai-file-input');
    const uploadBtn     = document.getElementById('sonoai-upload-btn');
    const imagePreview  = document.getElementById('sonoai-image-preview');
    const previewImg    = document.getElementById('sonoai-preview-img');
    const removeImg     = document.getElementById('sonoai-remove-image');
    const sidebarToggle = document.getElementById('sonoai-sidebar-toggle');
    const newChatBtn    = document.getElementById('sonoai-new-chat');
    const newChatMobile = document.getElementById('sonoai-new-chat-mobile');

    // ── Boot ───────────────────────────────────────────────────────────────
    function init() {
        bindEvents();
        loadHistory();
        autoResizeTextarea();
    }

    // ── Event bindings ─────────────────────────────────────────────────────
    function bindEvents() {
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
            sendBtn.disabled = textarea.value.trim().length === 0 && !state.pendingImage;
            autoResizeTextarea();
        });

        // Keyboard shortcut: Ctrl/Cmd + K → focus input.
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                textarea.focus();
            }
        });

        // Image upload.
        uploadBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', handleFileSelect);
        removeImg.addEventListener('click', clearImage);

        // Suggestion chips.
        document.querySelectorAll('.sonoai-suggestion').forEach(function (btn) {
            btn.addEventListener('click', function () {
                textarea.value = btn.dataset.query || '';
                textarea.dispatchEvent(new Event('input'));
                handleSend();
            });
        });

        // New chat.
        newChatBtn.addEventListener('click', startNewChat);
        newChatMobile.addEventListener('click', startNewChat);

        // Sidebar toggle (mobile).
        sidebarToggle.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', closeSidebar);
    }

    // ── Sidebar helpers ────────────────────────────────────────────────────
    function toggleSidebar() {
        const open = sidebar.classList.toggle('open');
        sidebarToggle.setAttribute('aria-expanded', open);
        overlay.hidden = !open;
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        sidebarToggle.setAttribute('aria-expanded', 'false');
        overlay.hidden = true;
    }

    // ── History ────────────────────────────────────────────────────────────
    function loadHistory() {
        apiFetch('history')
            .then(function (sessions) {
                state.sessions = Array.isArray(sessions) ? sessions : [];
                renderHistoryList();
            })
            .catch(function () {
                // Silently fail if no sessions yet.
            });
    }

    function renderHistoryList() {
        historyList.innerHTML = '';

        if (state.sessions.length === 0) {
            const li = document.createElement('li');
            li.className = 'sonoai-history-empty';
            li.textContent = sonoai_vars.i18n.no_history;
            historyList.appendChild(li);
            return;
        }

        state.sessions.forEach(function (s) {
            const li  = document.createElement('li');
            li.className = 'sonoai-history-item' + (s.session_uuid === state.sessionUuid ? ' active' : '');
            li.dataset.uuid = s.session_uuid;

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

            li.appendChild(text);
            li.appendChild(del);
            historyList.appendChild(li);
        });
    }

    function openSession(uuid) {
        state.sessionUuid = uuid;

        apiFetch('history/' + uuid)
            .then(function (session) {
                clearMessages();
                const msgs = session.messages || [];
                msgs.forEach(function (m) {
                    appendMessage(m.role, m.content, m.image_url || '', m.context_images || []);
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
        clearMessages();
        if (welcome) welcome.style.display = '';
        textarea.value = '';
        textarea.dispatchEvent(new Event('input'));
        clearImage();
        closeSidebar();
    }

    // ── Send message ───────────────────────────────────────────────────────
    function handleSend() {
        const text = textarea.value.trim();
        if ((!text && !state.pendingImage) || state.sending) {
            return;
        }

        // Hide welcome screen.
        if (welcome) welcome.style.display = 'none';

        // Show user message immediately.
        const imageUrl = state.pendingImage ? state.pendingImage.b64preview : '';
        appendMessage('user', text, imageUrl);

        // Build form data (supports image upload).
        const formData = new FormData();
        formData.append('message', text);
        if (state.sessionUuid) {
            formData.append('session_uuid', state.sessionUuid);
        }
        if (state.pendingImage && state.pendingImage.file) {
            formData.append('image', state.pendingImage.file);
        }

        // Reset input.
        textarea.value = '';
        textarea.dispatchEvent(new Event('input'));
        clearImage();

        // Show typing indicator.
        const typingEl = appendTyping();
        state.sending  = true;
        sendBtn.disabled = true;

        apiFetch('chat', { method: 'POST', body: formData, isFormData: true })
            .then(function (data) {
                typingEl.remove();
                state.sending = false;
                sendBtn.disabled = textarea.value.trim().length === 0;

                if (data.error) {
                    showError(data.error);
                    return;
                }

                state.sessionUuid = data.session_uuid;

                // Add to history sidebar if new session.
                if (data.is_new_session) {
                    const newSession = {
                        session_uuid: data.session_uuid,
                        title       : text.substring(0, 80),
                    };
                    state.sessions.unshift(newSession);
                    renderHistoryList();
                }

                appendMessage('assistant', data.reply || '', '', data.context_images || []);
                updateHistoryActiveState();
                scrollToBottom();
            })
            .catch(function (err) {
                typingEl.remove();
                state.sending    = false;
                sendBtn.disabled = false;
                showError(err.message || sonoai_vars.i18n.error);
            });
    }

    // ── Message rendering ──────────────────────────────────────────────────
    function appendMessage(role, content, imageUrl, contextImages) {
        const isUser  = role === 'user';
        const wrapper = document.createElement('div');
        wrapper.className = 'sonoai-message ' + (isUser ? 'user' : 'assistant');

        // Avatar.
        const avatarEl = document.createElement('div');
        avatarEl.className = 'sonoai-message-avatar';
        if (isUser && sonoai_vars.user && sonoai_vars.user.avatar) {
            const img = document.createElement('img');
            img.src = sonoai_vars.user.avatar;
            img.alt = sonoai_vars.user.first_name || '';
            avatarEl.appendChild(img);
        } else if (!isUser) {
            avatarEl.textContent = '🔬';
        } else {
            avatarEl.textContent = '👤';
        }

        // Body.
        const body   = document.createElement('div');
        body.className = 'sonoai-message-body';

        // Image (if present).
        if (imageUrl && isUser) {
            const imgEl = document.createElement('img');
            imgEl.src    = imageUrl;
            imgEl.alt    = 'Uploaded sonogram';
            imgEl.className = 'sonoai-message-image';
            body.appendChild(imgEl);
        }

        // Text bubble.
        if (content) {
            const bubble = document.createElement('div');
            bubble.className = 'sonoai-bubble';
            bubble.innerHTML = isUser
                ? escapeHtml(content).replace(/\n/g, '<br>')
                : markdownToHtml(content);
            body.appendChild(bubble);
        }

        // Context Images (if present).
        if (contextImages && contextImages.length > 0) {
            const gallery = document.createElement('div');
            gallery.className = 'sonoai-context-gallery';
            gallery.style.display = 'flex';
            gallery.style.gap = '8px';
            gallery.style.marginTop = '10px';
            gallery.style.overflowX = 'auto';
            gallery.style.paddingBottom = '4px';
            contextImages.forEach(function (src) {
                const a = document.createElement('a');
                a.href = src;
                a.target = '_blank';
                const imgEl = document.createElement('img');
                imgEl.src = src;
                imgEl.alt = 'Reference manual';
                imgEl.className = 'sonoai-context-image';
                imgEl.style.maxHeight = '80px';
                imgEl.style.borderRadius = '4px';
                imgEl.style.border = '1px solid rgba(0,0,0,0.1)';
                a.appendChild(imgEl);
                gallery.appendChild(a);
            });
            body.appendChild(gallery);
        }

        wrapper.appendChild(avatarEl);
        wrapper.appendChild(body);
        messages.appendChild(wrapper);
        scrollToBottom();

        return wrapper;
    }

    function appendTyping() {
        const typingWrapper = document.createElement('div');
        typingWrapper.className = 'sonoai-message assistant';

        const avatarEl = document.createElement('div');
        avatarEl.className = 'sonoai-message-avatar';
        avatarEl.textContent = '🔬';

        const body = document.createElement('div');
        body.className = 'sonoai-message-body';

        const typing = document.createElement('div');
        typing.className = 'sonoai-typing';
        typing.innerHTML = '<span></span><span></span><span></span>';
        body.appendChild(typing);

        typingWrapper.appendChild(avatarEl);
        typingWrapper.appendChild(body);
        messages.appendChild(typingWrapper);
        scrollToBottom();

        return typingWrapper;
    }

    function clearMessages() {
        messages.innerHTML = '';
        if (welcome) {
            messages.appendChild(welcome);
        }
    }

    function showError(msg) {
        const errEl  = document.createElement('div');
        errEl.className = 'sonoai-message assistant';
        const body   = document.createElement('div');
        body.className = 'sonoai-message-body';
        const bubble = document.createElement('div');
        bubble.className = 'sonoai-bubble';
        bubble.style.borderColor = 'rgba(255,80,80,0.4)';
        bubble.style.color       = '#ff8080';
        bubble.textContent       = '⚠ ' + msg;
        body.appendChild(bubble);
        errEl.appendChild(body);
        messages.appendChild(errEl);
        scrollToBottom();
    }

    // ── Image handling ─────────────────────────────────────────────────────
    function handleFileSelect() {
        const file = fileInput.files[0];
        if (!file) { return; }

        if (file.size > 10 * 1024 * 1024) {
            showError('Image must be smaller than 10 MB.');
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            state.pendingImage = { file: file, b64preview: e.target.result };
            previewImg.src     = e.target.result;
            imagePreview.hidden = false;
            sendBtn.disabled    = false;
        };
        reader.readAsDataURL(file);
    }

    function clearImage() {
        state.pendingImage  = null;
        fileInput.value     = '';
        imagePreview.hidden = true;
        previewImg.src      = '';
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
     * Very lightweight markdown → HTML (covers the main AI output patterns).
     * A full implementation would use a library; this handles the most common cases.
     */
    function markdownToHtml(text) {
        // Escape user-injected content that may appear verbatim.
        let html = text;

        // Code blocks first (before other processing).
        html = html.replace(/```[\w]*\n?([\s\S]*?)```/g, function(_, code) {
            return '<pre><code>' + escapeHtml(code.trim()) + '</code></pre>';
        });
        // Inline code.
        html = html.replace(/`([^`]+)`/g, function(_, code) {
            return '<code>' + escapeHtml(code) + '</code>';
        });
        // Bold.
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        // Italic.
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
        // Headers.
        html = html.replace(/^### (.+)/gm, '<h4>$1</h4>');
        html = html.replace(/^## (.+)/gm,  '<h3>$1</h3>');
        html = html.replace(/^# (.+)/gm,   '<h2>$1</h2>');
        // Unordered list items.
        html = html.replace(/^\s*[-*] (.+)/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
        // Numbered list items.
        html = html.replace(/^\s*\d+\. (.+)/gm, '<li>$1</li>');
        // Paragraphs (double newline).
        html = html.replace(/\n{2,}/g, '</p><p>');
        // Single newlines to <br>.
        html = html.replace(/\n/g, '<br>');
        // Wrap in paragraph.
        html = '<p>' + html + '</p>';
        // Fix <pre> wrapped in <p>.
        html = html.replace(/<p><pre>/g, '<pre>').replace(/<\/pre><\/p>/g, '</pre>');
        html = html.replace(/<p><ul>/g, '<ul>').replace(/<\/ul><\/p>/g, '</ul>');

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
