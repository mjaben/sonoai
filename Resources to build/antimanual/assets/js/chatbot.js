jQuery(document).ready(function ($) {
    // --- Selectors ---
    const elements = {
        resetBtn: $("#atml-chat-reset"),
        suggestedQuestions: $("#suggested-questions"),
        inputArea: $("#antimanual-input-container"),
        messageInput: $('#antimanualinput'),
        sendBtn: $('#antimanualsend'),
        chatbox: $('#antimanual-chatbox'),
        chatboxArea: $(".antimanualchatbox"),
        offlineBanner: $('.antimanual-offline-banner'),
        charCounter: $('<span class="antimanual-char-counter"></span>').appendTo($("#antimanual-input-container")),
        helpButton: $('#antimanual-help-button'),
        helpButtonSm: $('#antimanual-help-button-sm'),
        liveChatCta: $('#antimanual-live-chat-cta'),
        liveChatButton: $('#antimanual-live-chat-button')
    };

    // --- Constants ---
    const STORAGE = {
        MESSAGES: "antimanual-messages",
        LEAD: "antimanual-lead-info",
        CONVERSATION: "antimanual_conversation_id",
        FEEDBACK: "antimanual-feedback",
        LIVE_CHAT_ID: "antimanual-live-chat-id",
        LIVE_CHAT_TOKEN: "antimanual-live-chat-token"
    };
    const MAX_CHAR_LIMIT = 500;
    const MOBILE_BREAKPOINT = 768;
    const SENDER = { USER: "user", BOT: "bot" };
    const LIVE_CHAT_POLL_INTERVAL_ACTIVE = 5000;
    const LIVE_CHAT_POLL_INTERVAL_IDLE = 15000;
    
    // --- Configs & State ---
    const config = window.antimanual_chatbot_vars || {};
    const { lead: leadConfig = {}, mobile: mobileConfig = {}, display: displayConfig = {} } = config;
    
    let messages = [];
    let messagesLoaded = false;
    let lastFailedMessage = null;
    let isOnline = navigator.onLine;

    // --- Live Chat State ---
    let liveChatId = parseInt(localStorage.getItem(STORAGE.LIVE_CHAT_ID)) || null;
    let liveChatToken = localStorage.getItem(STORAGE.LIVE_CHAT_TOKEN) || '';
    let liveChatPollingTimer = null;
    let liveChatPollInFlight = false;
    let liveChatLastMsgId = 0;
    let liveChatActive = false;
    let liveChatRequestPending = false;
    let liveChatAgentName = '';
    let liveChatAgentAvatar = '';

    // --- Helpers ---
    const toBool = (val, fallback = false) => typeof val === 'undefined' || val === null ? fallback : 
        typeof val === 'boolean' ? val : 
        String(val).toLowerCase() === '1' || String(val).toLowerCase() === 'true';

    const getI18n = (key, defaultText) => wp?.i18n ? wp.i18n.__(key, 'antimanual') : defaultText;
    
    const showToast = (message, type = 'info') => {
        const validTypes = ['info', 'success', 'error'];
        const toastType = validTypes.includes(type) ? type : 'info';
        const $toast = $('<div></div>').addClass(`antimanual-toast antimanual-toast-${toastType}`).text(message).appendTo('body');
        setTimeout(() => $toast.addClass('show'), 10);
        setTimeout(() => { $toast.removeClass('show'); setTimeout(() => $toast.remove(), 300); }, 3000);
    };

    const getAjaxErrorMessage = (xhr, fallback) => {
        const json = xhr?.responseJSON;
        if (json?.message) return json.message;
        if (json?.data?.message) return json.data.message;
        if (typeof xhr?.responseText === 'string' && xhr.responseText.trim()) {
            return xhr.responseText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        }
        return fallback;
    };

    const stripHtml = html => {
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    };

    const copyTextToClipboard = text => {
        if (!text) {
            return Promise.reject(new Error('No text to copy'));
        }

        if (navigator.clipboard?.writeText && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise((resolve, reject) => {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.top = '-9999px';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            try {
                const copied = document.execCommand('copy');
                document.body.removeChild(textarea);
                if (copied) {
                    resolve();
                    return;
                }
            } catch (error) {
                document.body.removeChild(textarea);
                reject(error);
                return;
            }

            reject(new Error('Copy command failed'));
        });
    };

    const normalizeCreatedAt = value => {
        if (!value) return Date.now();
        if (typeof value === 'number') return value;
        const parsed = new Date(value).getTime();
        return Number.isFinite(parsed) ? parsed : Date.now();
    };

    // --- Storage Management ---
    const LocalStore = {
        get: (key, defaultValue) => {
            try { return JSON.parse(localStorage.getItem(key)) || defaultValue; } catch { return defaultValue; }
        },
        set: (key, value) => {
            try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
        },
        remove: key => localStorage.removeItem(key)
    };

    const loadMessages = () => messages = LocalStore.get(STORAGE.MESSAGES, []);
    const saveMessages = () => LocalStore.set(STORAGE.MESSAGES, messages);
    const clearMessages = () => { messages = []; LocalStore.remove(STORAGE.MESSAGES); renderMessages(); };
    const syncLiveChatCursorFromMessages = () => {
        liveChatLastMsgId = messages.reduce((max, msg) => Math.max(max, Number(msg.liveChatServerId) || 0), 0);
    };
    
    const addMessage = (messageObj) => {
        if (!messageObj.id) {
            messageObj.id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        }
        messageObj.createdAt = normalizeCreatedAt(messageObj.createdAt);
        messages.push(messageObj);
        saveMessages();
        renderMessages();
    };

    const setLiveChatSession = ({ chatId = null, token = '' } = {}) => {
        liveChatId = chatId ? Number(chatId) : null;
        liveChatToken = token || '';
        liveChatAgentName = '';
        liveChatAgentAvatar = '';

        if (liveChatId) {
            localStorage.setItem(STORAGE.LIVE_CHAT_ID, liveChatId);
        } else {
            localStorage.removeItem(STORAGE.LIVE_CHAT_ID);
        }

        if (liveChatToken) {
            localStorage.setItem(STORAGE.LIVE_CHAT_TOKEN, liveChatToken);
        } else {
            localStorage.removeItem(STORAGE.LIVE_CHAT_TOKEN);
        }
    };

    const updateLiveChatIndicator = () => {
        const $headerText = $('#antimanual-chatbox-header-text');
        if (!$headerText.length) return;

        $('#antimanual-live-chat-indicator').remove();

        if (liveChatActive) {
            $headerText.append(`
                <div id="antimanual-live-chat-indicator">
                    <span>${getI18n('Live chat in progress', 'Live chat in progress')}</span>
                    <button type="button" class="antimanual-end-live-chat">${getI18n('End', 'End')}</button>
                </div>
            `);
        }

        elements.resetBtn.attr(
            'title',
            liveChatActive
                ? getI18n('End live chat and clear conversation', 'End live chat and clear conversation')
                : getI18n('Reset Conversation', 'Reset Conversation')
        );
    };

    const canShowPersistentLiveChatButton = () => {
        const escalation = config.escalation || {};
        return toBool(escalation.enabled) && escalation.type === 'live_chat' && toBool(escalation.show_button);
    };

    const updatePersistentLiveChatButton = () => {
        if (!elements.liveChatCta.length || !elements.liveChatButton.length) return;

        const shouldShow = canShowPersistentLiveChatButton() && isLeadCollected() && !liveChatActive;

        elements.liveChatCta.toggle(shouldShow);
        elements.liveChatButton
            .prop('disabled', liveChatRequestPending)
            .toggleClass('is-busy', liveChatRequestPending);
    };

    const getLiveChatPollInterval = () => {
        const isChatVisible = elements.chatbox.is(':visible');
        return document.hidden || !isChatVisible
            ? LIVE_CHAT_POLL_INTERVAL_IDLE
            : LIVE_CHAT_POLL_INTERVAL_ACTIVE;
    };

    const scheduleNextLiveChatPoll = (delay = getLiveChatPollInterval()) => {
        if (!liveChatActive || !liveChatId || !liveChatToken) return;
        if (liveChatPollingTimer) {
            clearTimeout(liveChatPollingTimer);
        }
        liveChatPollingTimer = setTimeout(() => {
            liveChatPollingTimer = null;
            pollLiveChat();
        }, delay);
    };

    // --- Availability & UI State ---
    const updateAvailabilityState = () => {
        isOnline = navigator.onLine;
        elements.offlineBanner.find('span').text(getI18n('You are offline', 'You are offline'));
        elements.offlineBanner.toggleClass('show', !isOnline);
        
        elements.inputArea.find('input, button').prop('disabled', !isOnline);
        elements.suggestedQuestions.find('button').prop('disabled', !isOnline);
        $('.antimanual-lead-form input, .antimanual-lead-form button').prop('disabled', !isOnline);
        elements.messageInput.attr(
            'placeholder',
            liveChatActive
                ? getI18n('Type a message...', 'Type a message...')
                : displayConfig.input_placeholder || getI18n('Type your query', 'Type your query')
        );
    };

    const applyResponsiveSettings = () => {
        const isMobile = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`).matches;
        const $body = $(document.body);
        $body.removeClass('antimanual-mobile-hidden antimanual-mobile-bottom-left antimanual-mobile-bottom-center antimanual-desktop-bottom-left');

        if (isMobile) {
            if (!toBool(mobileConfig.show_on_mobile, true)) {
                $body.addClass('antimanual-mobile-hidden').removeClass('antimanual_chatbox_expanded');
                elements.helpButton.hide();
                elements.helpButtonSm.hide();
                elements.chatbox.hide();
                return;
            }
            elements.helpButton.show();
            elements.helpButtonSm.show();
            const pos = mobileConfig.position || 'bottom-right';
            if (pos !== 'bottom-right') $body.addClass(`antimanual-mobile-${pos}`);
        } else {
            const pos = displayConfig.desktop_position || 'bottom-right';
            if (pos === 'bottom-left') $body.addClass('antimanual-desktop-bottom-left');
        }
    };

    const applyBrandingSettings = () => {
        if (displayConfig.suggested_label?.trim()) {
            elements.suggestedQuestions.find('p').first().text(displayConfig.suggested_label.trim());
        }
        
        const showAvatar = toBool(displayConfig.show_avatar, true);
        $('.bot-profile-icon').toggle(showAvatar).attr('src', showAvatar && displayConfig.custom_avatar_url?.trim() ? displayConfig.custom_avatar_url.trim() : $('.bot-profile-icon').attr('src'));
        $('.antimanual-msg.bot').toggleClass('no-avatar', !showAvatar);
    };

    // --- Lead Collection ---
    const getLeadInfo = () => LocalStore.get(STORAGE.LEAD, null);
    const isLeadCollected = () => !leadConfig.collect_email || !!getLeadInfo();

    const renderLeadForm = () => {
        elements.chatboxArea.children().hide();
        elements.suggestedQuestions.hide();
        elements.inputArea.addClass("antimanual-chatbox-blurred").find('input, button').prop('disabled', true);
        $('.antimanual-lead-form').remove();

        const prompt = leadConfig.prompt || getI18n('Please enter your details to continue.', 'Please enter your details to continue.');
        const emailPlaceholder = getI18n('Your Email', 'Your Email') + (leadConfig.email_required ? ' *' : '');
        const namePlaceholder = getI18n('Your Name', 'Your Name') + (leadConfig.name_required ? ' *' : '');
        
        const formHtml = `
            <div class="antimanual-lead-form">
                <p class="lead-prompt">${prompt}</p>
                <div class="form-group"><input type="email" id="antimanual-lead-email" placeholder="${emailPlaceholder}" ${leadConfig.email_required ? 'required' : ''} /></div>
                ${leadConfig.collect_name ? `<div class="form-group"><input type="text" id="antimanual-lead-name" placeholder="${namePlaceholder}" ${leadConfig.name_required ? 'required' : ''} /></div>` : ''}
                <button id="antimanual-lead-submit" class="lead-submit-btn">${getI18n('Start Chatting', 'Start Chatting')}</button>
            </div>
        `;
        elements.chatboxArea.append(formHtml);
    };

    const submitLeadForm = () => {
        const email = $('#antimanual-lead-email').val();
        const name = $('#antimanual-lead-name').val();

        if ((leadConfig.email_required || email) && (!email || !email.includes('@'))) {
            return showToast(getI18n('Please enter a valid email address.', 'Please enter a valid email address.'), 'error');
        }
        if (leadConfig.collect_name && leadConfig.name_required && !name) {
            return showToast(getI18n('Please enter your name.', 'Please enter your name.'), 'error');
        }

        LocalStore.set(STORAGE.LEAD, { email, name, synced: false });
        renderMessages();
    };

    // --- Core Rendering ---
    const createMessageActions = (messageId, isBot) => {
        if (!isBot) return '';
        const feedback = LocalStore.get(STORAGE.FEEDBACK, {});
        const msgFeedback = feedback[messageId];
        
        return `<div class="antimanual-msg-actions">
            <div class="antimanual-feedback-btns">
                <button class="antimanual-feedback-btn antimanual-like-btn ${msgFeedback === 'like' ? 'liked' : ''}" data-message-id="${messageId}" title="${getI18n('Helpful', 'Helpful')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg>
                </button>
                <button class="antimanual-feedback-btn antimanual-dislike-btn ${msgFeedback === 'dislike' ? 'disliked' : ''}" data-message-id="${messageId}" title="${getI18n('Not helpful', 'Not helpful')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"></path></svg>
                </button>
            </div>
            <button class="antimanual-msg-action-btn antimanual-copy-btn" data-message-id="${messageId}" title="${getI18n('Copy', 'Copy')}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button></div>`;
    };

    const appendMsgEl = (message, sender = SENDER.BOT, references = [], messageId = null, createdAt = null, messageKind = 'general', agentAvatar = '') => {
        const msgEl = $(`.antimanualchatbox .antimanual-msg.${sender}`)?.first();
        if (!msgEl || !msgEl.length) return null;
        
        const clonedMsgEl = msgEl.clone(true);
        const isBot = sender === SENDER.BOT;
        const isLoadingOrError = message.includes('antimanual-typing-indicator') || message.includes('antimanual-error-msg');
        const canShowReferences = isBot && messageKind === 'answer' && !isLoadingOrError;
        const canShowActions = canShowReferences && !!messageId;

        clonedMsgEl.find(".msg-content").html(message);
        msgEl.parent().append(clonedMsgEl);
        clonedMsgEl.removeClass("placeholder").show();

        // Swap bot avatar with the agent avatar for live chat messages.
        if (isBot && agentAvatar) {
            const $avatar = clonedMsgEl.find('.bot-profile-icon');
            if ($avatar.length) {
                $avatar.attr('src', agentAvatar).attr('alt', 'Agent Avatar');
            }
        }

        const $templateSourcesContainer = isBot ? clonedMsgEl.find(".sources-container") : null;
        $templateSourcesContainer?.remove();

        const formatTime = ts => {
            const diff = Date.now() - ts;
            const minutes = Math.floor(diff / 60000);
            if (minutes < 1) return getI18n('Just now', 'Just now');
            if (minutes < 60) return minutes === 1 ? getI18n('1 min ago', '1 min ago') : `${minutes} ${getI18n('mins ago', 'mins ago')}`;
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return hours === 1 ? getI18n('1 hour ago', '1 hour ago') : `${hours} ${getI18n('hours ago', 'hours ago')}`;
            const days = Math.floor(hours / 24);
            if (days < 7) return days === 1 ? getI18n('Yesterday', 'Yesterday') : `${days} ${getI18n('days ago', 'days ago')}`;
            return new Date(ts).toLocaleDateString();
        };

        if (!isBot && createdAt) {
            clonedMsgEl.find(".msg-content").wrap('<div class="antimanual-msg-user-wrapper"></div>');
            clonedMsgEl.find(".antimanual-msg-user-wrapper").append(`<div class="antimanual-msg-timestamp">${formatTime(createdAt)}</div>`);
        }

        if (isBot && !isLoadingOrError) {
            const validRefs = (Array.isArray(references) ? references : []).filter(r => r?.link && r.link.trim() !== "#" && !r.link.toLowerCase().startsWith('javascript:') && !r.link.toLowerCase().startsWith('about:'));
            const uniqueRefs = [...new Map(validRefs.map(v => [v.link, v])).values()];
            
            const $footer = $('<div class="antimanual-msg-footer"></div>');
            let $sourcesBtn = null;
            let $referencesEl = null;

            if (canShowReferences && uniqueRefs.length > 0) {
                $sourcesBtn = msgEl.find('.sources').clone(true).show();
                $referencesEl = msgEl.find('.references').clone(true).empty();
                
                uniqueRefs.forEach(ref => {
                    $referencesEl.append($(`<a href="${ref.link}" target="_blank"><span class="antimanual-icon"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 36 36" fill="currentColor"><path d="M17.6,24.32l-2.46,2.44a4,4,0,0,1-5.62,0,3.92,3.92,0,0,1,0-5.55l4.69-4.65a4,4,0,0,1,5.62,0,3.86,3.86,0,0,1,1,1.71A2,2,0,0,0,21.1,18l1.29-1.28a5.89,5.89,0,0,0-1.15-1.62,6,6,0,0,0-8.44,0L8.1,19.79a5.91,5.91,0,0,0,0,8.39,6,6,0,0,0,8.44,0l3.65-3.62c-.17,0-.33,0-.5,0A8,8,0,0,1,17.6,24.32Z"></path><path d="M28.61,7.82a6,6,0,0,0-8.44,0l-3.65,3.62c.17,0,.33,0,.49,0h0a8,8,0,0,1,2.1.28l2.46-2.44a4,4,0,0,1,5.62,0,3.92,3.92,0,0,1,0,5.55l-4.69,4.65a4,4,0,0,1-5.62,0,3.86,3.86,0,0,1-1-1.71,2,2,0,0,0-.28.23l-1.29,1.28a5.89,5.89,0,0,0,1.15,1.62,6,6,0,0,0,8.44,0l4.69-4.65a5.92,5.92,0,0,0,0-8.39Z"></path></svg></span><span class="text">${ref.title || getI18n("Source", "Source")}</span></a>`));
                });
                
                $sourcesBtn.on("click", () => {
                    const isVisible = $referencesEl.css("display") !== "none";
                    $referencesEl.css("display", isVisible ? "none" : "flex");
                    $sourcesBtn.toggleClass("expanded");
                });
                
                $footer.append($sourcesBtn);
            }

            if (canShowActions) $footer.append(createMessageActions(messageId, isBot));
            if (createdAt) $footer.append(`<span class="antimanual-msg-timestamp">${formatTime(createdAt)}</span>`);
            
            if ($footer.children().length > 0) {
                clonedMsgEl.find(".antimanual-response").append($footer);
            }
            if ($referencesEl) {
                clonedMsgEl.find(".antimanual-response").append($referencesEl);
            }
        }

        return clonedMsgEl;
    };

    const renderMessages = () => {
        if (!isLeadCollected()) {
            updatePersistentLiveChatButton();
            renderLeadForm();
            updateAvailabilityState();
            return;
        }

        elements.inputArea.removeClass("antimanual-chatbox-blurred").find('input, button').prop('disabled', false);
        $('.antimanual-lead-form').remove();
        elements.chatboxArea.children(":not(.placeholder)").remove();

        if (!messagesLoaded) {
            loadMessages();
            messagesLoaded = true;
        }

        if (!messages.length) {
            addMessage({
                message: config.welcome_message || getI18n("What can I help you with?", "What can I help you with?"),
                sender: SENDER.BOT
            });
            return; // addMessage calls renderMessages
        }

        const hasUserMsg = messages.some(m => m.sender === SENDER.USER);
        elements.resetBtn.prop("disabled", !hasUserMsg);
        elements.suggestedQuestions.toggle(!hasUserMsg);

        messages.forEach(m => appendMsgEl(m.message, m.sender, m.references, m.id, m.createdAt, m.kind, m.agentAvatar));
        
        setTimeout(() => elements.chatboxArea[0]?.scrollTo({ top: elements.chatboxArea[0].scrollHeight, behavior: "smooth" }), 300);
        updateAvailabilityState();
        updateLiveChatIndicator();
        updatePersistentLiveChatButton();
    };

    // --- Message Input ---
    const updateCharCounter = () => {
        const len = elements.messageInput.val().length;
        if (len === 0) return elements.charCounter.hide();
        
        const remaining = MAX_CHAR_LIMIT - len;
        elements.charCounter.text(`${len}/${MAX_CHAR_LIMIT}`).show()
            .removeClass('warning limit')
            .addClass(remaining <= 0 ? 'limit' : remaining <= 50 ? 'warning' : '');
            
        elements.sendBtn.prop('disabled', remaining < 0);
    };

    // --- Data Sending ---
    const sendMessage = (messageText) => {
        if (!messageText || messageText.length > MAX_CHAR_LIMIT) return;
        if (!navigator.onLine) return showToast(getI18n('You appear to be offline. Please check your connection.', 'You appear to be offline.'), 'error');

        addMessage({ message: messageText, sender: SENDER.USER });
        elements.messageInput.val('');
        updateCharCounter();

        const loadingMsgEl = appendMsgEl('<div class="antimanual-typing-indicator"><span class="antimanual-typing-dot"></span><span class="antimanual-typing-dot"></span><span class="antimanual-typing-dot"></span></div>', "bot");
        loadingMsgEl?.addClass("loading-message");
        setTimeout(() => elements.chatboxArea[0]?.scrollTo({ top: elements.chatboxArea[0].scrollHeight, behavior: "smooth" }), 300);

        $.ajax({
            url: `${config.rest_url}/messages`,
            type: 'POST',
            beforeSend: xhr => config.nonce && xhr.setRequestHeader('X-WP-Nonce', config.nonce),
            data: { message: messageText, conversation_id: localStorage.getItem(STORAGE.CONVERSATION) || "" },
            success: res => {
                $('.loading-message').remove();
                if (res?.success) {
                    const convId = res.data.conversation_id;
                    if (convId) localStorage.setItem(STORAGE.CONVERSATION, convId);
                    
                    const lead = getLeadInfo();
                    if (lead?.email && !lead.synced && convId) {
                        $.post({
                            url: `${config.rest_url}/leads/submit`,
                            beforeSend: xhr => config.nonce && xhr.setRequestHeader('X-WP-Nonce', config.nonce),
                            data: { conversation_id: convId, email: lead.email, name: lead.name },
                            success: () => LocalStore.set(STORAGE.LEAD, { ...lead, synced: true })
                        });
                    }
                    
                    lastFailedMessage = null;
                    addMessage({ message: res.data.answer, sender: SENDER.BOT, references: res.data.references, kind: 'answer' });

                    const escalation = config.escalation || {};
                    const handoffType = res.data.handoff_type || escalation.type;
                    const liveChatAvailable = typeof res.data.live_chat_available !== 'undefined'
                        ? toBool(res.data.live_chat_available)
                        : toBool(escalation.live_chat_available);
                    const shouldOfferHandoff = toBool(res.data.should_offer_handoff)
                        || (toBool(escalation.enabled) && toBool(res.data.is_irrelevant));

                    if (shouldOfferHandoff) {
                        if (toBool(res.data.handoff_intent) && handoffType === 'live_chat' && liveChatAvailable) {
                            requestLiveChat();
                        } else {
                            showEscalationCard({
                                message: toBool(res.data.handoff_intent) ? res.data.answer : escalation.message,
                                type: handoffType,
                                email: escalation.email,
                                url: escalation.url,
                                liveChatAvailable
                            });
                        }
                    }
                } else {
                    handleMsgError(messageText);
                }
            },
            error: () => { $('.loading-message').remove(); handleMsgError(messageText); }
        });
    };

    const handleMsgError = (original) => {
        lastFailedMessage = original;
        addMessage({
            message: `<div class="antimanual-error-msg"><span class="antimanual-error-text">${getI18n("Sorry, I couldn't process your request. Please try again.", "Error")}</span><button class="antimanual-retry-btn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>${getI18n('Retry', 'Retry')}</button></div>`,
            sender: SENDER.BOT
        });
    };

    const showEscalationCard = ({ message, type, email, url, liveChatAvailable }) => {
        const escMsg = message || getI18n('Would you like to contact a human?', 'Would you like to contact a human?');
        let escAction = '';

        if (type === 'live_chat') {
            if (liveChatAvailable) {
                escAction = `<button class="antimanual-escalation-link antimanual-live-chat-btn">${getI18n('Chat with a Human', 'Chat with a Human')}</button>`;
            } else {
                escAction = `<span class="antimanual-escalation-offline">${getI18n('No agents are currently available.', 'No agents are currently available.')}</span>`;
                if (email) {
                    escAction += ` <a href="mailto:${email}" class="antimanual-escalation-link">${getI18n('Send Email Instead', 'Send Email Instead')}</a>`;
                }
            }
        } else if (type === 'email' && email) {
            escAction = `<a href="mailto:${email}" class="antimanual-escalation-link">${getI18n('Send Email', 'Send Email')}</a>`;
        } else if (type === 'url' && url) {
            escAction = `<a href="${url}" target="_blank" rel="noopener noreferrer" class="antimanual-escalation-link">${getI18n('Contact Support', 'Contact Support')}</a>`;
        }

        addMessage({
            message: `<div class="antimanual-escalation-card"><p>${escMsg}</p>${escAction}</div>`,
            sender: SENDER.BOT,
            kind: 'system-card'
        });
    };

    // =========================================================================
    // Live Chat Functions
    // =========================================================================

    /**
     * Request a new live chat session.
     */
    const requestLiveChat = () => {
        if (liveChatActive || liveChatRequestPending) return;

        const convId = localStorage.getItem(STORAGE.CONVERSATION) || '';
        const lead = getLeadInfo();
        liveChatRequestPending = true;
        updatePersistentLiveChatButton();

        addMessage({
            message: `<div class="antimanual-live-chat-status waiting">${getI18n('Connecting you to an agent...', 'Connecting you to an agent...')}</div>`,
            sender: SENDER.BOT,
            kind: 'live-chat-status'
        });

        $.ajax({
            url: `${config.rest_url}/chatbot/live-chat/request`,
            type: 'POST',
            beforeSend: xhr => config.nonce && xhr.setRequestHeader('X-WP-Nonce', config.nonce),
            data: {
                conversation_id: convId,
                visitor_name: lead?.name || '',
                visitor_email: lead?.email || ''
            },
            success: res => {
                if (res?.success) {
                    setLiveChatSession({
                        chatId: res.data.chat_id,
                        token: res.data.visitor_token || ''
                    });
                    enterLiveChatMode();
                } else {
                    addMessage({
                        message: `<div class="antimanual-live-chat-status error">${res?.message || getI18n('Unable to start live chat.', 'Unable to start live chat.')}</div>`,
                        sender: SENDER.BOT,
                        kind: 'live-chat-status'
                    });
                }

                liveChatRequestPending = false;
                updatePersistentLiveChatButton();
            },
            error: (xhr) => {
                addMessage({
                    message: `<div class="antimanual-live-chat-status error">${getAjaxErrorMessage(xhr, getI18n('Unable to start live chat. Please try again.', 'Unable to start live chat. Please try again.'))}</div>`,
                    sender: SENDER.BOT,
                    kind: 'live-chat-status'
                });

                liveChatRequestPending = false;
                updatePersistentLiveChatButton();
            }
        });
    };

    /**
     * Enter live chat mode — switch input to send to human endpoint, start polling.
     */
    const enterLiveChatMode = () => {
        if (!liveChatId || !liveChatToken) {
            exitLiveChatMode();
            return;
        }

        liveChatActive = true;
        syncLiveChatCursorFromMessages();
        updateLiveChatIndicator();
        updateAvailabilityState();
        updatePersistentLiveChatButton();
        startLiveChatPolling();
    };

    /**
     * Exit live chat mode — stop polling, reset input.
     */
    const exitLiveChatMode = () => {
        liveChatActive = false;
        setLiveChatSession();
        stopLiveChatPolling();
        updateLiveChatIndicator();
        updateAvailabilityState();
        updatePersistentLiveChatButton();
    };

    /**
     * Send a message in live chat mode.
     */
    const sendLiveChatMessage = (messageText) => {
        if (!liveChatId || !liveChatToken || !messageText) return;

        addMessage({ message: messageText, sender: SENDER.USER, kind: 'live-chat' });
        elements.messageInput.val('');
        updateCharCounter();

        $.ajax({
            url: `${config.rest_url}/chatbot/live-chat/send`,
            type: 'POST',
            beforeSend: xhr => config.nonce && xhr.setRequestHeader('X-WP-Nonce', config.nonce),
            data: { chat_id: liveChatId, visitor_token: liveChatToken, message: messageText },
            success: res => {
                if (!res?.success) {
                    showToast(res?.message || getI18n('Failed to send message.', 'Failed to send message.'), 'error');
                }
            },
            error: (xhr) => showToast(getAjaxErrorMessage(xhr, getI18n('Failed to send message.', 'Failed to send message.')), 'error')
        });
    };

    /**
     * Poll for new messages from the agent.
     */
    const pollLiveChat = () => {
        if (!liveChatId || !liveChatToken || !liveChatActive) return;

        if (!navigator.onLine) {
            scheduleNextLiveChatPoll();
            return;
        }

        if (liveChatPollInFlight) {
            scheduleNextLiveChatPoll();
            return;
        }

        liveChatPollInFlight = true;

        $.ajax({
            url: `${config.rest_url}/chatbot/live-chat/poll`,
            type: 'GET',
            beforeSend: xhr => config.nonce && xhr.setRequestHeader('X-WP-Nonce', config.nonce),
            data: { chat_id: liveChatId, visitor_token: liveChatToken, after_id: liveChatLastMsgId },
            success: res => {
                if (!res?.success) {
                    addMessage({
                        message: `<div class="antimanual-live-chat-status error">${res?.message || getI18n('Live chat is no longer available.', 'Live chat is no longer available.')}</div>`,
                        sender: SENDER.BOT,
                        kind: 'live-chat-status'
                    });
                    exitLiveChatMode();
                    return;
                }

                const { status, messages: newMsgs, agent_name: agentName, agent_avatar: agentAvatarUrl } = res.data;

                // Store agent info when available.
                if (agentAvatarUrl) liveChatAgentAvatar = agentAvatarUrl;
                if (agentName) liveChatAgentName = agentName;

                if (newMsgs && newMsgs.length > 0) {
                    newMsgs.forEach(m => {
                        liveChatLastMsgId = Math.max(liveChatLastMsgId, m.id);

                        // Skip visitor messages (already rendered locally).
                        if (m.sender === 'visitor') return;

                        if (m.sender === 'system') {
                            addMessage({
                                message: `<div class="antimanual-live-chat-status system">${m.message}</div>`,
                                sender: SENDER.BOT,
                                id: `live-chat-system-${m.id}`,
                                createdAt: m.created_at,
                                kind: 'live-chat-status',
                                liveChatServerId: m.id,
                                agentAvatar: liveChatAgentAvatar
                            });
                        } else if (m.sender === 'agent') {
                            addMessage({
                                message: m.message,
                                sender: SENDER.BOT,
                                id: `live-chat-agent-${m.id}`,
                                createdAt: m.created_at,
                                kind: 'live-chat',
                                liveChatServerId: m.id,
                                agentAvatar: liveChatAgentAvatar
                            });
                        }
                    });
                }

                if (status === 'closed') {
                    addMessage({
                        message: `<div class="antimanual-live-chat-status ended">${getI18n('Chat session has ended.', 'Chat session has ended.')}</div>`,
                        sender: SENDER.BOT,
                        kind: 'live-chat-status'
                    });
                    exitLiveChatMode();
                }
            },
            error: () => {
                if (liveChatActive) {
                    scheduleNextLiveChatPoll(LIVE_CHAT_POLL_INTERVAL_IDLE);
                }
            },
            complete: () => {
                liveChatPollInFlight = false;
                if (liveChatActive && liveChatId && liveChatToken && !liveChatPollingTimer) {
                    scheduleNextLiveChatPoll();
                }
            }
        });
    };

    /**
     * Visitor ends the live chat.
     */
    const endLiveChat = ({ announce = true, clearConversation = false } = {}) => {
        if (!liveChatId || !liveChatToken) {
            if (clearConversation) {
                LocalStore.remove(STORAGE.CONVERSATION);
                clearMessages();
                elements.messageInput.val('');
                updateCharCounter();
            }
            return;
        }

        $.ajax({
            url: `${config.rest_url}/chatbot/live-chat/end`,
            type: 'POST',
            beforeSend: xhr => config.nonce && xhr.setRequestHeader('X-WP-Nonce', config.nonce),
            data: { chat_id: liveChatId, visitor_token: liveChatToken }
        });

        if (announce) {
            addMessage({
                message: `<div class="antimanual-live-chat-status ended">${getI18n('You ended the chat.', 'You ended the chat.')}</div>`,
                sender: SENDER.BOT,
                kind: 'live-chat-status'
            });
        }
        exitLiveChatMode();

        if (clearConversation) {
            LocalStore.remove(STORAGE.CONVERSATION);
            clearMessages();
            elements.messageInput.val('');
            updateCharCounter();
        }
    };

    const startLiveChatPolling = () => {
        stopLiveChatPolling();
        scheduleNextLiveChatPoll(0);
    };

    const stopLiveChatPolling = () => {
        if (liveChatPollingTimer) {
            clearTimeout(liveChatPollingTimer);
            liveChatPollingTimer = null;
        }
        liveChatPollInFlight = false;
    };

    // --- Events ---
    elements.resetBtn.on('click', () => {
        if (liveChatActive) {
            endLiveChat({ announce: false, clearConversation: true });
            return;
        }
        LocalStore.remove(STORAGE.CONVERSATION);
        clearMessages();
        elements.messageInput.val('');
        updateCharCounter();
    });
    elements.messageInput.on('input', updateCharCounter).on('keypress', e => { if (e.which === 13) { elements.sendBtn.click(); return false; } });
    elements.sendBtn.on('click', () => { const msg = elements.messageInput.val().trim(); if (msg) { liveChatActive ? sendLiveChatMessage(msg) : sendMessage(msg); } });
    $(document).on('click', '#suggested-questions .atml-suggested-question', function() { const q = $(this).text().trim(); elements.messageInput.val(q); sendMessage(q); });
    
    $(document).on('click', '#antimanual-lead-submit', e => { e.preventDefault(); submitLeadForm(); });
    $(document).on('keypress', '#antimanual-lead-email, #antimanual-lead-name', e => { if (e.which === 13) { submitLeadForm(); return false; } });
    $(document).on('click', '.antimanual-retry-btn', function() {
        if (!lastFailedMessage) return;
        $(this).closest('.antimanual-msg').remove();
        messages = messages.filter(m => !m.message.includes('antimanual-error-msg'));
        saveMessages();
        sendMessage(lastFailedMessage);
    });
    
    $(document).on('click', '.antimanual-copy-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const messageId = String($btn.data('message-id'));
        const message = messages.find(m => String(m.id) === messageId);
        const text = stripHtml(message?.message || $btn.closest('.antimanual-response').find('.msg-content').html() || "").trim();

        if (text) {
            copyTextToClipboard(text).then(() => {
                const orig = $btn.html();
                $btn.html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>').addClass('active');
                setTimeout(() => { $btn.removeClass('active').html(orig); }, 1500);
            }).catch(() => showToast(getI18n('Failed to copy', 'Failed to copy'), 'error'));
        }
    });

    $(document).on('click', '.antimanual-feedback-btn', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const id = $btn.data('message-id');
        const isLike = $btn.hasClass('antimanual-like-btn');
        const activeClass = isLike ? 'liked' : 'disliked';
        const inactiveClass = isLike ? 'disliked' : 'liked';
        const siblingClass = isLike ? '.antimanual-dislike-btn' : '.antimanual-like-btn';
        const feedbackVal = isLike ? 'like' : 'dislike';

        if ($btn.hasClass(activeClass)) {
            $btn.removeClass(activeClass);
            const fb = LocalStore.get(STORAGE.FEEDBACK, {});
            delete fb[id];
            LocalStore.set(STORAGE.FEEDBACK, fb);
        } else {
            $btn.addClass(activeClass);
            $btn.siblings(siblingClass).removeClass(inactiveClass);
            const fb = LocalStore.get(STORAGE.FEEDBACK, {});
            fb[id] = feedbackVal;
            LocalStore.set(STORAGE.FEEDBACK, fb);

            // Send feedback to the REST API.
            const convId = localStorage.getItem(STORAGE.CONVERSATION);
            if (convId && config.rest_url) {
                const msgIndex = messages.findIndex(m => m.id === id);
                $.ajax({
                    url: `${config.rest_url}/chatbot/feedback`,
                    type: 'POST',
                    beforeSend: xhr => config.nonce && xhr.setRequestHeader('X-WP-Nonce', config.nonce),
                    data: {
                        conversation_id: convId,
                        message_index: msgIndex >= 0 ? msgIndex : 0,
                        is_helpful: isLike ? 1 : 0
                    }
                });
            }
        }
    });

    // Live chat button click handler.
    $(document).on('click', '.antimanual-live-chat-btn', function() {
        requestLiveChat();
    });

    elements.liveChatButton.on('click', () => {
        if (liveChatActive || liveChatRequestPending) return;

        if (!isLeadCollected()) {
            renderMessages();
            return;
        }

        const escalation = config.escalation || {};
        const liveChatAvailable = toBool(escalation.live_chat_available);

        if (!liveChatAvailable) {
            showEscalationCard({
                message: escalation.message,
                type: 'live_chat',
                email: escalation.email,
                url: escalation.url,
                liveChatAvailable: false
            });
            return;
        }

        requestLiveChat();
    });

    // End live chat handler.
    $(document).on('click', '.antimanual-end-live-chat', function() {
        endLiveChat();
    });

    $(window).on('resize orientationchange', () => { applyResponsiveSettings(); });
    window.addEventListener('online', updateAvailabilityState);
    window.addEventListener('offline', updateAvailabilityState);
    document.addEventListener('visibilitychange', () => {
        if (!liveChatActive) return;
        scheduleNextLiveChatPoll(document.hidden ? LIVE_CHAT_POLL_INTERVAL_IDLE : 0);
    });
    $(document).on('keydown', e => { if (e.key === 'Escape' && elements.chatbox.is(':visible')) AntimanualToggleChat(); });
    
    if (!localStorage.getItem('antimanual-opened')) elements.helpButton.addClass('pulse');
    elements.helpButton.on('click', () => { elements.helpButton.removeClass('pulse'); localStorage.setItem('antimanual-opened', 'true'); });

    // --- Init ---
    applyResponsiveSettings();
    applyBrandingSettings();
    updateAvailabilityState();
    renderMessages();
    elements.messageInput.val('');
    updateCharCounter();

    // Resume live chat if session exists.
    if (liveChatId && liveChatToken) {
        enterLiveChatMode();
    } else if (liveChatId || liveChatToken) {
        setLiveChatSession();
    }
});

function AntimanualToggleChat() {
    const $body = jQuery(document.body);
    const $chatbox = jQuery('#antimanual-chatbox');
    
    if ($body.hasClass('antimanual-mobile-hidden')) return;

    if ($chatbox.is(':visible')) {
        $chatbox.slideUp(300, () => { jQuery('#help-icon').show().fadeIn(300); jQuery('#close-icon').hide(); });
        $chatbox.css("display", "flex");
        $body.removeClass("antimanual_chatbox_expanded");
    } else {
        $chatbox.hide().slideDown(300, () => jQuery('#antimanualinput').focus());
        jQuery('#help-icon').fadeOut(300, () => { jQuery('#help-icon').hide(); jQuery('#close-icon').hide().fadeIn(300); });
        $body.addClass("antimanual_chatbox_expanded");
        setTimeout(() => { const cb = jQuery(".antimanualchatbox")[0]; cb?.scrollTo({ top: cb.scrollHeight, behavior: "smooth" }); }, 300);
    }
}
