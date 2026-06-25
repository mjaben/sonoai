/**
 * SonoAI - RLHF QA Dashboard Logic
 */

(function($) {
    'use strict';

    const RLHF = {
        items: [],
        currentIndex: -1,
        
        init: function() {
            this.cacheDOM();
            this.bindEvents();
            this.fetchItems();
        },
        
        cacheDOM: function() {
            this.$taskList = $('#rlhf-task-list');
            this.$workspace = $('#rlhf-workspace');
            this.$emptyState = $('#rlhf-empty-state');
            
            // Filters
            this.$filterType = $('#rlhf-filter-type');
            this.$filterStatus = $('#rlhf-filter-status');
            this.$refreshBtn = $('#rlhf-refresh-btn');
            
            // Stats
            this.$statPending = $('#rlhf-stat-pending .count');
            this.$statPassed = $('#rlhf-stat-passed .count');
            
            // Workspace
            this.$contentViewer = $('#rlhf-content-viewer');
            this.$copyTruthBtn = $('#rlhf-copy-truth');
            this.$metaBadges = $('#rlhf-meta-badges');
            this.$chatHistory = $('#rlhf-chat-history');
            this.$chatInput = $('#rlhf-chat-input');
            this.$chatSendBtn = $('#rlhf-chat-send');
            
            // Grading
            this.$gradeStatus = $('#rlhf-grade-status');
            this.$gradeReasonGroup = $('#rlhf-fail-reason-group');
            this.$gradeReason = $('#rlhf-grade-reason');
            this.$gradeNotes = $('#rlhf-grade-notes');
            this.$submitBtn = $('#rlhf-submit-next');
            this.$saveStatus = $('#rlhf-save-status');
        },
        
        bindEvents: function() {
            this.$filterType.on('change', () => this.fetchItems());
            this.$filterStatus.on('change', () => this.fetchItems());
            this.$refreshBtn.on('click', () => this.fetchItems());
            
            this.$taskList.on('click', '.rlhf-task-item', (e) => {
                const id = $(e.currentTarget).data('id');
                this.loadItem(id);
            });
            
            this.$chatSendBtn.on('click', () => this.sendTestQuery());
            this.$chatInput.on('keypress', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendTestQuery();
                }
            });
            
            this.$gradeStatus.on('change', () => {
                if (this.$gradeStatus.val() === 'Needs Re-training') {
                    this.$gradeReasonGroup.show();
                } else {
                    this.$gradeReasonGroup.hide();
                }
            });
            
            this.$submitBtn.on('click', () => this.submitGrade());
            
            this.$copyTruthBtn.on('click', () => {
                const text = this.$contentViewer.text();
                if (text) {
                    navigator.clipboard.writeText(text).then(() => {
                        const originalHtml = this.$copyTruthBtn.html();
                        this.$copyTruthBtn.html('✅ Copied!').css('color', '#10b981');
                        setTimeout(() => {
                            this.$copyTruthBtn.html(originalHtml).css('color', '');
                        }, 2000);
                    }).catch(err => {
                        console.error('Could not copy text: ', err);
                        alert('Failed to copy text to clipboard.');
                    });
                }
            });
        },
        
        fetchItems: function() {
            this.$taskList.html('<div class="rlhf-loading"><span class="rlhf-spinner"></span> Loading tasks...</div>');
            
            $.ajax({
                url: sonoaiRLHF.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sonoai_rlhf_get_items',
                    nonce: sonoaiRLHF.nonces.getItems,
                    type: this.$filterType.val(),
                    status: this.$filterStatus.val()
                },
                success: (response) => {
                    if (response.success) {
                        this.items = response.data.items;
                        this.stats = response.data.stats;
                        this.renderList();
                    } else {
                        this.$taskList.html(`<div class="rlhf-loading">Error: ${response.data}</div>`);
                    }
                },
                error: () => {
                    this.$taskList.html('<div class="rlhf-loading">Server error fetching tasks.</div>');
                }
            });
        },
        
        renderList: function() {
            this.$taskList.empty();
            
            if (this.items.length === 0) {
                this.$taskList.html('<div class="rlhf-loading">No tasks found for these filters. 🎉</div>');
                if (this.stats) {
                    this.updateStats(this.stats.pending, this.stats.passed);
                } else {
                    this.updateStats(0, 0);
                }
                return;
            }
            
            this.items.forEach((item, index) => {
                const statusColor = item.rlhf_status === 'Passed' ? '#10b981' : (item.rlhf_status === 'Needs Re-training' ? '#ef4444' : '#6b7280');
                
                // Get the first line of the raw content for the title
                let displayTitle = item.source_title || 'Untitled Item';
                if (item.raw_content) {
                    const firstLine = item.raw_content.split('\n').find(line => line.trim().length > 0);
                    if (firstLine) {
                        // Truncate to a reasonable length in case the first paragraph is extremely long
                        displayTitle = firstLine.length > 100 ? firstLine.substring(0, 100) + '...' : firstLine;
                    }
                }
                
                const html = `
                    <div class="rlhf-task-item" data-id="${item.id}" data-index="${index}" id="task-item-${item.id}">
                        <div class="rlhf-task-meta">
                            <span>[${item.type.toUpperCase()}] ID: ${item.id}</span>
                            <span style="color: ${statusColor}; font-weight: 500;">${item.rlhf_status}</span>
                        </div>
                        <div class="rlhf-task-title">${displayTitle}</div>
                    </div>
                `;
                this.$taskList.append(html);
            });
            
            if (this.stats) {
                this.updateStats(this.stats.pending, this.stats.passed);
            }
        },
        
        updateStats: function(pending, passed) {
            this.$statPending.text(pending);
            this.$statPassed.text(passed);
        },
        
        loadItem: function(id) {
            const index = this.items.findIndex(i => i.id == id);
            if (index === -1) return;
            
            this.currentIndex = index;
            const item = this.items[index];
            
            // Update UI State
            $('.rlhf-task-item').removeClass('active');
            $(`#task-item-${id}`).addClass('active');
            
            this.$emptyState.hide();
            this.$workspace.show();
            
            // Populate Workspace
            $('#rlhf-current-item-id').val(item.id);
            
            let badgesHTML = `<span class="rlhf-badge">${item.type.toUpperCase()}</span>`;
            if (item.mode) badgesHTML += `<span class="rlhf-badge">${item.mode} Mode</span>`;
            if (item.source_url) badgesHTML += `<a href="${item.source_url}" target="_blank" class="rlhf-badge">🔗 Source URL</a>`;
            this.$metaBadges.html(badgesHTML);
            
            let contentText = item.raw_content ? item.raw_content : 'No raw content found. Chunks will be used.';
            this.$contentViewer.text(contentText);
            
            // Reset Chat
            this.$chatHistory.html('');
            this.$chatInput.val('');
            
            // Reset Form
            this.$gradeStatus.val(item.rlhf_status !== 'Not Started' ? item.rlhf_status : 'Passed').trigger('change');
            if (item.rlhf_fail_reason) this.$gradeReason.val(item.rlhf_fail_reason);
            this.$gradeNotes.val(item.rlhf_reviewer_notes || '');
        },
        
        sendTestQuery: function() {
            const query = this.$chatInput.val().trim();
            const itemId = $('#rlhf-current-item-id').val();
            
            if (!query || !itemId) return;
            
            this.$chatInput.val('');
            this.appendChat('user', query);
            
            const $loading = this.appendChat('assistant', '<span class="rlhf-spinner"></span> Thinking...');
            this.$chatHistory.scrollTop(this.$chatHistory[0].scrollHeight);
            
            $.ajax({
                url: sonoaiRLHF.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sonoai_rlhf_chat_test',
                    nonce: sonoaiRLHF.nonces.chatTest,
                    item_id: itemId,
                    message: query
                },
                success: (response) => {
                    if (response.success) {
                        $loading.html(response.data.reply.replace(/\n/g, '<br>'));
                    } else {
                        $loading.html(`<span style="color:red">Error: ${response.data}</span>`);
                    }
                    this.$chatHistory.scrollTop(this.$chatHistory[0].scrollHeight);
                },
                error: () => {
                    $loading.html('<span style="color:red">Network error.</span>');
                }
            });
        },
        
        appendChat: function(role, htmlContent) {
            const $msg = $(`<div class="rlhf-msg ${role}">${htmlContent}</div>`);
            this.$chatHistory.append($msg);
            return $msg;
        },
        
        submitGrade: function() {
            const itemId = $('#rlhf-current-item-id').val();
            if (!itemId) return;
            
            const status = this.$gradeStatus.val();
            const reason = status === 'Needs Re-training' ? this.$gradeReason.val() : '';
            const notes = this.$gradeNotes.val();
            
            this.$submitBtn.prop('disabled', true);
            
            // Optimistic UI Update
            const $taskItem = $(`#task-item-${itemId}`);
            $taskItem.addClass('optimistic-passed');
            
            // Find next item to load
            let nextId = null;
            const $nextTask = $taskItem.next('.rlhf-task-item:not(.optimistic-passed)');
            if ($nextTask.length) {
                nextId = $nextTask.data('id');
                this.loadItem(nextId);
            } else {
                // If no next item, hide workspace
                this.$workspace.hide();
                this.$emptyState.show();
                this.$emptyState.find('h2').text('Queue Completed! 🎉');
                this.$emptyState.find('p').text('There are no more tasks in the current view.');
            }
            
            // Background API Call
            $.ajax({
                url: sonoaiRLHF.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sonoai_rlhf_update_status',
                    nonce: sonoaiRLHF.nonces.updateStatus,
                    item_id: itemId,
                    status: status,
                    reason: reason,
                    notes: notes
                },
                success: (response) => {
                    this.$submitBtn.prop('disabled', false);
                    if (response.success) {
                        this.$saveStatus.show().delay(2000).fadeOut();
                        // Adjust stats logically
                        if (status === 'Passed') {
                            const passed = parseInt(this.$statPassed.text()) + 1;
                            const pending = parseInt(this.$statPending.text()) - 1;
                            this.updateStats(Math.max(0, pending), passed);
                        }
                    }
                },
                error: () => {
                    this.$submitBtn.prop('disabled', false);
                    alert('Failed to save status. Please try again.');
                    $taskItem.removeClass('optimistic-passed'); // Revert
                }
            });
        }
    };

    $(document).ready(function() {
        if ($('#sonoai-rlhf-page').length) {
            RLHF.init();
        }
    });

})(jQuery);
