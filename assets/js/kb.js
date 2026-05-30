/**
 * SonoAI — Knowledge Base admin JS
 * Handles: dark/light toggle, WP Posts AJAX table, PDF upload, URL fetch, custom text via TinyMCE.
 * Version: 1.3.1
 */
(function ($) {
    'use strict';


    var THEME_KEY = 'sonoai_kb_theme';
    var KB = window.sonoaiKB || {};
    var ajax = KB.ajaxUrl || '';
    var nonces = KB.nonces || {};

    // ── Dark / Light ──────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.querySelector('.kb-wrap');
        if (!wrap) return;

        if (localStorage.getItem(THEME_KEY) === 'dark') {
            wrap.classList.add('kb-dark');
        }

        var btn = document.getElementById('kb-theme-toggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var dark = wrap.classList.toggle('kb-dark');
                localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
            });
        }

        // Initialize all modules safely
        initWpTab();
        initPdfTab();
        initUrlTab();
        initJsonlTab();
        initTxtTab();
        initGlobalFilters();
        initDeleteButtons();
        initActionsDropdown();
        initViewModal();
        initTopicsTab();
        initRedisSync();
        initReindexAll();
        initSelectiveActions();
        initApiConfig(); // Unified API & Provider logic
        initMultiselectDropdowns();

        // Inline Source Expansion for mobile
        document.body.addEventListener('click', function(e) {
            if (e.target.classList.contains('kb-source-toggle-btn')) {
                var wrap = e.target.closest('.kb-source-wrap');
                if (wrap) {
                    var short = wrap.querySelector('.kb-source-short');
                    var full = wrap.querySelector('.kb-source-full');
                    if (short && full) {
                        short.style.display = 'none';
                        full.style.display = 'inline';
                        e.target.style.display = 'none';
                    }
                }
            }
        });
    });

    /**
     * Tab: JSONL
     */
    function initJsonlTab() {
        var form = document.getElementById('kb-jsonl-form');
        if (!form) return;

        var fileInp = document.getElementById('kb-jsonl-file');
        var fileNameHint = document.getElementById('kb-jsonl-filename');
        var submitBtn = document.getElementById('kb-jsonl-submit');
        var notice = document.getElementById('kb-jsonl-notice');

        if (fileInp) {
            fileInp.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    var name = this.files[0].name;
                    if (fileNameHint) fileNameHint.textContent = name;
                    if (submitBtn) submitBtn.disabled = false;
                } else {
                    if (fileNameHint) fileNameHint.textContent = 'No file chosen';
                    if (submitBtn) submitBtn.disabled = true;
                }
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!fileInp || !fileInp.files[0]) return;

            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
            if (notice) { notice.style.display = 'none'; }

            var fd = new FormData();
            fd.append('action', 'sonoai_kb_add_jsonl');
            fd.append('security', nonces.addJsonl || ''); // I need to add this to localization
            fd.append('jsonl_file', fileInp.files[0]);
            
            // Overrides
            fd.append('mode', document.getElementById('kb-jsonl-mode').value);


            $.ajax({
                url: ajax,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        showToast(res.data.message || 'Import successful!', 'success');
                        setTimeout(function() { window.location.reload(); }, 1500);
                    } else {
                        showToast(res.data.message || 'Error occurred.', 'error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Process & Index';
                    }
                },
                error: function () {
                    showToast('Fatal error during upload.', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Process & Index';
                }
            });
        });
    }

    function initRedisSync() {
        var btn = document.getElementById('kb-redis-sync');
        // Handle both ID styles if necessary, but we are standardizing to kb-redis-sync
        if (!btn) btn = document.getElementById('kb-redis-sync-btn'); 
        if (!btn) return;

        btn.addEventListener('click', function() {
            if (btn.disabled) return;
            if (!confirm('Are you sure you want to rebuild the Redis index? This will push all current knowledge base items from MySQL into Redis.')) return;

            btn.disabled = true;

            // Create popup overlay
            var overlay = document.createElement('div');
            overlay.className = 'kb-redis-popup-overlay';
            overlay.innerHTML = `
                <div class="kb-redis-popup">
                    <h3 style="margin-top:0;">Syncing to Redis</h3>
                    <div class="kb-redis-progress-bar-container">
                        <div class="kb-redis-progress-bar" style="width: 0%;"></div>
                    </div>
                    <div class="kb-redis-progress-text">0% Completed</div>
                    <div class="kb-redis-current-item">Preparing to sync...</div>
                </div>
            `;
            document.body.appendChild(overlay);

            var progressBar = overlay.querySelector('.kb-redis-progress-bar');
            var progressText = overlay.querySelector('.kb-redis-progress-text');
            var currentItemText = overlay.querySelector('.kb-redis-current-item');
            
            var totalIndexed = 0;
            var totalErrors = 0;

            function processBatch(offset, isFirst) {
                $.post(ajax, {
                    action: 'sonoai_kb_sync_redis',
                    security: nonces.syncRedis,
                    offset: offset,
                    is_first: isFirst ? 'true' : 'false'
                }, function(res) {
                    if (res.success) {
                        var data = res.data;
                        totalIndexed += data.indexed || 0;
                        totalErrors += data.errors || 0;
                        var total = data.total || 1;
                        var next_offset = data.next_offset || offset;
                        
                        var percentage = Math.min(100, Math.round((next_offset / total) * 100));
                        if (data.done) percentage = 100;
                        
                        progressBar.style.width = percentage + '%';
                        progressText.innerText = percentage + '% Completed';
                        if (data.current_item) {
                            currentItemText.innerText = 'Syncing: ' + data.current_item;
                        } else {
                            currentItemText.innerText = 'Syncing...';
                        }

                        if (data.done) {
                            setTimeout(function() {
                                alert('Sync to Redis has been completed.');
                                overlay.remove();
                                btn.disabled = false;
                            }, 500);
                        } else {
                            processBatch(data.next_offset, false);
                        }
                    } else {
                        alert('Sync failed: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'));
                        overlay.remove();
                        btn.disabled = false;
                    }
                }).fail(function() {
                    alert('Fatal error during synchronization.');
                    overlay.remove();
                    btn.disabled = false;
                });
            }

            processBatch(0, true);
        });

        // Redis Toggle Visibility
        var redisToggle = document.querySelector('input[name="sonoai_settings[redis_enabled]"]');
        var redisDetails = document.querySelector('.kb-redis-details');
        if (redisToggle && redisDetails) {
            redisToggle.addEventListener('change', function () {
                redisDetails.style.display = this.checked ? 'block' : 'none';
            });
        }
    }

    /**
     * Unified API & Provider switching
     */
    function initApiConfig() {
        var provSel = document.getElementById('kb-provider-select');
        if (!provSel) return;

        function applyProvider(prov) {
            document.querySelectorAll('.kb-key-group, .kb-chat-model-group, .kb-embed-model-group').forEach(function (el) {
                el.style.display = (el.dataset.provider === prov) ? '' : 'none';
            });
        }

        applyProvider(provSel.value);
        provSel.addEventListener('change', function () {
            applyProvider(this.value);
        });

        // Eye toggle — reveals the actual stored API key
        document.querySelectorAll('.kb-eye-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = this.dataset.target;
                var input    = document.getElementById(targetId);
                if (!input) return;

                var isHidden = (input.type === 'password');
                var actualKey = input.dataset.key || '';

                if (isHidden) {
                    input.type = 'text';
                    input.value = actualKey;
                    input.placeholder = '';
                    this.innerHTML = '👁️‍🗨️'; // Toggle icon representation
                } else {
                    input.type = 'password';
                    input.value = '';
                    input.placeholder = '••••••••••••';
                    this.innerHTML = '👁️'; 
                }
            });
        });
    }

    /**
     * Re-index All with Current Model Button
     */
    function initReindexAll() {
        var btn = document.getElementById('kb-reindex-all');
        if (!btn) return;

        btn.addEventListener('click', function() {
            var model = (KB.currentProvider || 'openai') + ' / ' + (KB.currentModel || 'current model');
            if (!confirm('This will re-embed ALL ' + KB.currentProvider + ' Knowledge Base items using "' + (KB.currentModel || 'current model') + '"\n\nOld vectors will be deleted and regenerated. This may take a moment.\n\nContinue?')) {
                return;
            }

            btn.disabled = true;

            // Create progress popup overlay (reusing styling classes from redis popup)
            var overlay = document.createElement('div');
            overlay.className = 'kb-redis-popup-overlay';
            overlay.innerHTML = `
                <div class="kb-redis-popup">
                    <h3 style="margin-top:0;">Re-indexing Knowledge Base</h3>
                    <div class="kb-redis-progress-bar-container">
                        <div class="kb-redis-progress-bar" style="width: 0%;"></div>
                    </div>
                    <div class="kb-redis-progress-text">0% Completed</div>
                    <div class="kb-redis-current-item">Preparing to re-index...</div>
                </div>
            `;
            document.body.appendChild(overlay);

            var progressBar = overlay.querySelector('.kb-redis-progress-bar');
            if (progressBar) {
                // Style with standard theme blue/green primary
                progressBar.style.background = 'var(--kb-primary, #2563eb)';
            }
            var progressText = overlay.querySelector('.kb-redis-progress-text');
            var currentItemText = overlay.querySelector('.kb-redis-current-item');

            var totalUpdated = 0;
            var totalErrors = 0;

            function processReindex(offset) {
                $.post(ajax, {
                    action: 'sonoai_kb_reindex_all',
                    security: nonces.reindexAll,
                    offset: offset,
                    batch_size: 5 // Process 5 items per batch
                }, function(res) {
                    if (res.success) {
                        var data = res.data;
                        totalUpdated += data.updated || 0;
                        totalErrors += data.errors || 0;
                        var total = data.total || 1;
                        var next_offset = data.next_offset || offset;

                        var percentage = Math.min(100, Math.round((next_offset / total) * 100));
                        if (data.done) percentage = 100;

                        if (progressBar) progressBar.style.width = percentage + '%';
                        if (progressText) progressText.innerText = percentage + '% Completed';
                        if (currentItemText) {
                            if (data.current_item) {
                                currentItemText.innerText = 'Re-embedding: ' + data.current_item;
                            } else {
                                currentItemText.innerText = 'Re-embedding...';
                            }
                        }

                        if (data.done) {
                            setTimeout(function() {
                                alert(data.message || 'Re-indexing completed successfully.');
                                overlay.remove();
                                btn.disabled = false;
                                btn.innerHTML = '&#9889; Re-index (' + (KB.currentModel || '') + ')';
                                
                                // Refresh the visible lists
                                if (typeof loadPosts === 'function') loadPosts(1, '');
                                if (typeof loadCustomItems === 'function') loadCustomItems();
                            }, 500);
                        } else {
                            processReindex(data.next_offset);
                        }
                    } else {
                        alert('Re-index failed: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'));
                        overlay.remove();
                        btn.disabled = false;
                        btn.innerHTML = '&#9889; Re-index (' + (KB.currentModel || '') + ')';
                    }
                }).fail(function(xhr) {
                    var errorMsg = 'Fatal error during re-indexing.';
                    if (xhr.responseText) {
                        try {
                            var json = JSON.parse(xhr.responseText);
                            if (json && json.data && json.data.message) {
                                errorMsg += '\nDetails: ' + json.data.message;
                            }
                        } catch(e) {}
                    }
                    errorMsg += '\n\nPlease check wp-content/plugins/sonoai/sonoai-errors.log for complete traceback details.';
                    alert(errorMsg);
                    overlay.remove();
                    btn.disabled = false;
                    btn.innerHTML = '&#9889; Re-index (' + (KB.currentModel || '') + ')';
                });
            }

            processReindex(0);
        });
    }
    function showToast(msg, type) {
        var container = document.getElementById('sonoai-kb-notify');
        if (!container) {
            // Fallback for missing container
            var pc = document.getElementById('sonoai-kb-page');
            if(pc) {
                container = document.createElement('div');
                container.id = 'sonoai-kb-notify';
                pc.prepend(container);
            } else {
                alert(msg); return; 
            }
        }
        
        var toast = document.createElement('div');
        toast.className = 'kb-toast kb-toast-' + (type || 'success');
        
        var iconMap = { 
            'success': '✅', 
            'error': '❌', 
            'info': '<img src="' + (KB.syncIcon || '') + '" class="kb-toast-sync-icon" onerror="this.outerHTML=\'🔄\'" style="width:18px;height:18px;filter:brightness(0.5);">' 
        };
        
        var icon = iconMap[type] || '🔔';
        
        toast.innerHTML = '<span class="kb-toast-icon">' + icon + '</span><span class="kb-toast-msg">' + msg + '</span>';
        container.appendChild(toast);
        
        setTimeout(function() {
            toast.style.animation = 'kb-toast-out 0.3s forwards';
            setTimeout(function() { toast.remove(); }, 300);
        }, 5000);
    }

    /**
     * Tab: WordPress Posts
     */
    function initWpTab() {
        
        var tbody = document.getElementById('kb-wp-tbody');
        if (!tbody) {
            console.warn('SonoAI KB: #kb-wp-tbody not found');
            return;
        }

        var currentPt = document.getElementById('kb-current-pt');
        var postType  = currentPt ? currentPt.value : 'post';
        var pagDiv    = document.getElementById('kb-wp-pagination');
        var searchInput = document.getElementById('kb-wp-search');
        var bulkSel     = document.getElementById('kb-wp-bulk-action');
        var bulkApply   = document.getElementById('kb-wp-bulk-apply');
        var checkAll    = document.getElementById('kb-wp-check-all');
        var filterBtns  = document.querySelectorAll('.kb-status-btn');

        var currentPage   = 1;
        var currentSearch = '';
        var currentFilter = 'all';

        // Global Clinical Filter Values
        function getGlobalFilters() {
            var activeBtn = document.querySelector('.kb-filter-mode-btn.active');
            return {
                mode:    activeBtn ? activeBtn.dataset.mode : '',
                topic:   (document.getElementById('kb-global-topic-filter') || {}).value || '',
                country: (document.getElementById('kb-global-country-filter') || {}).value || ''
            };
        }

        function loadPosts(page, search) {
            var filters = getGlobalFilters();
            
            currentPage   = page;
            currentSearch = search;
            tbody.innerHTML = '<tr class="kb-loading-row"><td colspan="9"><span class="kb-spinner"></span> Loading posts...</td></tr>';
            
            var payload = {
                action:    'sonoai_kb_get_posts',
                security:  nonces.getPosts,
                post_type: postType,
                page:      page,
                search:    search,
                kb_status: currentFilter,
                mode:      filters.mode,
                topic_id:  filters.topic,
                country:   filters.country
            };
            
            $.post(ajax, payload, function (res) {
                renderPosts(res.data);
            }).fail(function (xhr) {
                console.error('SonoAI KB: loadPosts() fatal error', xhr);
                tbody.innerHTML = '<tr><td colspan="9" class="kb-empty">Error loading posts. Check console.</td></tr>';
            });
        }

        function renderPosts(data) {
            // Update stats
            if (data && data.counts) {
                ['all', 'added', 'not_added', 'update'].forEach(function(k) {
                    var el = document.getElementById('kb-count-' + k);
                    if (el) el.textContent = data.counts[k];
                });
            }

            if (!data || !data.posts || data.posts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="kb-empty">No posts found.</td></tr>';
                if (pagDiv) pagDiv.innerHTML = '';
                return;
            }

            var html = '';
            data.posts.forEach(function (p) {
                var badgeHtml = '';
                var btnsHtml = '';

                if (p.kb_status === 'added' || p.kb_status === 'update') {
                    badgeHtml = p.kb_status === 'added' ? '<span class="kb-badge-added">Added</span>' : '<span class="kb-badge-update">Requires Update</span>';
                    
                    var dropdownHtml = '<div class="kb-row-actions-dropdown">'
                                     + '  <button type="button" class="kb-row-actions-btn" aria-haspopup="true" aria-expanded="false">'
                                     + '    Actions'
                                     + '    <svg width="10" height="6" viewBox="0 0 10 6" fill="none" xmlns="http://www.w3.org/2000/svg" class="kb-chevron-icon">'
                                     + '      <path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
                                     + '    </svg>'
                                     + '  </button>'
                                     + '  <div class="kb-row-actions-menu">';
                    
                    if (p.kb_status === 'update') {
                        dropdownHtml += '    <button type="button" class="kb-add-btn kb-update-btn" data-post-id="' + p.id + '">Update</button>';
                    }
                    
                    dropdownHtml += '    <button type="button" class="kb-add-btn kb-quick-edit-btn" data-post-id="' + p.id + '" data-mode="' + p.raw_mode + '" data-topic="' + p.topic_id + '">Quick Edit</button>'
                                  + '    <button type="button" class="kb-reindex-item-btn" data-knowledge-id="' + p.knowledge_id + '">Re-index</button>'
                                  + '    <button type="button" class="kb-resync-item-btn" data-knowledge-id="' + p.knowledge_id + '">Re-sync</button>'
                                  + '    <button type="button" class="kb-add-btn kb-remove-btn kb-delete-danger" data-post-id="' + p.id + '">Remove</button>'
                                  + '  </div>'
                                  + '</div>';
                    
                    btnsHtml = dropdownHtml;
                } else {
                    badgeHtml = '<span class="kb-badge-not-added">Not Added</span>';
                    btnsHtml  = '<button type="button" class="kb-add-btn kb-btn-primary-sm" data-post-id="' + p.id + '"><span class="kb-btn-text">Add to KB</span><span class="kb-spinner" style="display:none"></span></button>';
                }

                var itemNoHtml = p.sequence_no ? '<strong>#' + p.sequence_no + '</strong><br><small style="opacity:0.6;font-size:10px;">ID: ' + p.kb_item_id + '</small>' : '—';
                html += '<tr data-post-id="' + p.id + '">'
                    + '<td><input type="checkbox" class="kb-wp-row-cb" value="' + p.id + '" data-knowledge-id="' + p.knowledge_id + '"></td>'
                    + '<td>' + itemNoHtml + '</td>'
                    + '<td><a href="' + (p.edit_url || '#') + '" target="_blank">' + escHtml(p.title) + '</a></td>'
                    + '<td class="kb-col-date">' + escHtml(p.last_modified) + '</td>'
                    + '<td class="kb-col-date">' + escHtml(p.kb_added) + '</td>'
                    + '<td>' + badgeHtml + '</td>'
                    + '<td>' + escHtml(p.mode) + '</td>'
                    + '<td>' + escHtml(p.topic_name) + (p.raw_mode === 'guideline' ? '<br><small class="kb-text-muted">Country: ' + escHtml(p.country) + '</small>' : '') + '</td>'
                    + '<td>' + (p.ai_model !== '—' ? '<span class="kb-badge-model">' + escHtml(p.ai_model) + '</span>' : '—') + '</td>'
                    + '<td>' + btnsHtml + '</td>'
                    + '</tr>';
            });
            tbody.innerHTML = html;

            // Bind interactions
            tbody.querySelectorAll('.kb-add-btn:not(.kb-quick-edit-btn)').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var pid = this.dataset.postId;
                    var isRemove = this.classList.contains('kb-remove-btn');
                    var spinner = this.querySelector('.kb-spinner');
                    var text = this.querySelector('.kb-btn-text');
                    
                    this.disabled = true;
                    if (spinner) spinner.style.display = 'inline-block';
                    if (text) text.style.opacity = '0.5';

                    var payload = {
                        action:   isRemove ? 'sonoai_kb_remove_post' : 'sonoai_kb_add_post',
                        security: isRemove ? nonces.removePost : nonces.addPost,
                        post_id:  pid,
                    };

                    if (!isRemove) {
                        var mVal = (document.getElementById('kb-wp-mode') || {}).value;
                        var tVal = (document.getElementById('kb-wp-topic') || {}).value;
                        if (mVal) payload.mode = mVal;
                        if (tVal) payload.topic_id = tVal;
                    }

                    $.post(ajax, payload, function () {
                        loadPosts(currentPage, currentSearch);
                    }).fail(function (xhr) {
                        alert('Operation failed. Check permissions.');
                        loadPosts(currentPage, currentSearch);
                    });
                });
            });

            // Bind quick edit
            tbody.querySelectorAll('.kb-quick-edit-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var pid = this.dataset.postId;
                    var mode = this.dataset.mode;
                    var topics = this.dataset.topic || '';

                    var qePid = document.getElementById('qe-post-id');
                    var qeMode = document.getElementById('qe-mode');
                    var modal = document.getElementById('kb-quick-edit-modal');

                    if (qePid) qePid.value = pid;
                    if (qeMode) qeMode.value = mode || 'guideline';

                    var $dropdown = $('#qe-topics-dropdown');
                    $dropdown.find('input[type="checkbox"]').prop('checked', false);
                    if (topics) {
                        var topicIds = topics.split(',').map(function(t) { return t.trim(); });
                        topicIds.forEach(function(tid) {
                            $dropdown.find('input[type="checkbox"][value="' + tid + '"]').prop('checked', true);
                        });
                    }
                    $dropdown.find('input[type="checkbox"]').first().trigger('change');

                    if (modal) openModal(modal);
                });
            });

            renderPagination(data, pagDiv);
        }

        function renderPagination(data, container) {
            if (!container) return;
            if (data.total_pages <= 1) { container.innerHTML = ''; return; }
            var html = '';
            for (var i = 1; i <= data.total_pages; i++) {
                html += '<button type="button" class="kb-page-btn' + (i === data.page ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
            }
            container.innerHTML = html;
            container.querySelectorAll('.kb-page-btn').forEach(function (pb) {
                pb.addEventListener('click', function () {
                    loadPosts(parseInt(this.dataset.page), currentSearch);
                });
            });
        }

        // Search + Filters
        if (searchInput) {
            var searchTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                var q = this.value;
                searchTimer = setTimeout(function () { loadPosts(1, q); }, 400);
            });
        }

        if (filterBtns) {
            filterBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    filterBtns.forEach(function (b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    currentFilter = this.dataset.filter;
                    loadPosts(1, currentSearch);
                });
            });
        }

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                tbody.querySelectorAll('.kb-wp-row-cb').forEach(function (cb) { cb.checked = checkAll.checked; });
            });
        }

        // Initial Load
        loadPosts(1, '');
    }

    /**
     * Tab: PDF
     */
    function initPdfTab() {
        var form = document.getElementById('kb-pdf-form');
        if (!form) return;

        var modeSel = document.getElementById('kb-pdf-mode');
        if (modeSel) {
            modeSel.addEventListener('change', function() { toggleMetadataFields('pdf'); });
            toggleMetadataFields('pdf');
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fileInp = document.getElementById('kb-pdf-file');
            if (!fileInp || !fileInp.files[0]) return;

            var submitBtn = document.getElementById('kb-pdf-submit');
            var notice = document.getElementById('kb-pdf-notice');
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            var fd = new FormData();
            fd.append('action', 'sonoai_kb_add_pdf');
            fd.append('security', nonces.addPdf);
            fd.append('pdf_file', fileInp.files[0]);
            
            ['mode', 'country', 'source_name', 'source_url'].forEach(function(f) {
                var el = document.getElementById('kb-pdf-' + f);
                if (el) fd.append(f, el.value);
            });


            $.ajax({
                url: ajax,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        setNotice(notice, '✓ PDF added.', 'success');
                        setTimeout(function () { location.reload(); }, 1200);
                    } else {
                        setNotice(notice, res.data.message || 'Error.', 'error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[SonoAI] PDF Upload Failure:', { status: status, error: error, response: xhr.responseText });
                    setNotice(notice, 'Fatal error during PDF upload.', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit';
                }
            });
        });
    }

    /**
     * URL Tab
     */
    function initUrlTab() {
        var form = document.getElementById('kb-url-form');
        if (!form) return;

        var modeSel = document.getElementById('kb-url-mode');
        if (modeSel) {
            modeSel.addEventListener('change', function() { toggleMetadataFields('url'); });
            toggleMetadataFields('url');
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = document.getElementById('kb-url-input');
            if (!input || !input.value.trim()) return;

            var submit = document.getElementById('kb-url-submit');
            submit.disabled = true;
            
            var payload = { 
                action: 'sonoai_kb_add_url', 
                security: nonces.addUrl, 
                url: input.value.trim() 
            };
            ['mode', 'country', 'source_name', 'source_url'].forEach(function(f) {
                var el = document.getElementById('kb-url-' + f);
                if (el) payload[f] = el.value;
            });
            var urlTopics = [];
            $('#kb-url-topic-dropdown input[type="checkbox"]:checked').each(function() {
                urlTopics.push($(this).val());
            });
            payload.topic_ids = urlTopics;

            $.post(ajax, payload, function (res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.data.message || 'URL error');
                    submit.disabled = false;
                }
            });
        });
    }

    /**
     * Custom Text Tab
     */
    function initTxtTab() {
        var form = document.getElementById('kb-txt-form');
        var container = document.getElementById('kb-txt-images-container');
        var addImgBtn = document.getElementById('kb-add-image-row');
        var submitBtn = document.getElementById('kb-txt-submit');
        var notice = document.getElementById('kb-txt-notice');
        var toggleBtn = document.getElementById('kb-txt-toggle-btn');
        var formCollapse = document.getElementById('kb-txt-form-collapse');

        if (!form) return;

        // Toggle logic
        if (toggleBtn && formCollapse) {
            toggleBtn.addEventListener('click', function() {
                var isHidden = formCollapse.style.display === 'none';
                formCollapse.style.display = isHidden ? 'block' : 'none';
                toggleBtn.innerHTML = isHidden ? '<span class="kb-btn-icon">−</span> Hide form' : '<span class="kb-btn-icon">+</span> Add training data';
            });
        }

        var modeSel = document.getElementById('kb-txt-mode');
        if (modeSel) {
            modeSel.addEventListener('change', function() { toggleMetadataFields('txt'); });
            toggleMetadataFields('txt');
        }

        // Image Repeater
        if (addImgBtn && container) {
            addImgBtn.addEventListener('click', function() {
                var row = document.createElement('div');
                row.className = 'kb-image-row';
                row.style.cssText = 'display:flex; gap:12px; margin-bottom:12px; align-items: flex-end; background:var(--kb-surface-2); padding:12px; border-radius:8px; border:1px solid var(--kb-border); border-color:rgba(255,255,255,0.05);';
                row.innerHTML = `
                    <div style="flex:1.5;">
                        <label style="font-size:11px; font-weight:700; text-transform:uppercase; opacity:0.6; display:block; margin-bottom:5px;">Sonogram Reference</label>
                        <div class="kb-img-upload-wrap" style="display:flex; gap:10px; align-items:center;">
                            <div class="kb-img-preview" style="width:40px; height:40px; background:var(--kb-surface-1); border-radius:4px; border:1px solid var(--kb-border); overflow:hidden; display:flex; align-items:center; justify-content:center;">
                                <span style="font-size:16px; opacity:0.3;">🖼</span>
                            </div>
                            <input type="hidden" class="kb-img-url" value="">
                            <button type="button" class="kb-btn-sm kb-choose-img-btn" style="flex:1; justify-content:center; background:rgba(255,255,255,0.05);">Upload Sonogram</button>
                            <input type="file" class="kb-file-input" accept="image/*" style="display:none;">
                        </div>
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:11px; font-weight:700; text-transform:uppercase; opacity:0.6; display:block; margin-bottom:5px;">Clinical Label</label>
                        <input type="text" class="kb-img-label kb-input-sm" placeholder="e.g. Gallbladder with Sludge" style="width:100%;">
                    </div>
                    <button type="button" class="kb-btn-sm kb-remove-img-row" style="height:36px; padding:0 12px; color:#ef4444; background:rgba(239,68,68,0.08); border-color:rgba(239,68,68,0.15);">🗑</button>
                `;
                container.appendChild(row);
            });

            container.addEventListener('click', function(e) {
                var btn = e.target.closest('.kb-choose-img-btn');
                if (btn) {
                    var row = btn.closest('.kb-image-row');
                    var label = row.querySelector('.kb-img-label').value.trim();
                    if (!label) { alert('Enter a Clinical Label first.'); return; }
                    row.querySelector('.kb-file-input').click();
                }
                var del = e.target.closest('.kb-remove-img-row');
                if (del) del.closest('.kb-image-row').remove();
            });

            container.addEventListener('change', function(e) {
                var fileInp = e.target.closest('.kb-file-input');
                if (fileInp && fileInp.files[0]) {
                    var row = fileInp.closest('.kb-image-row');
                    var label = row.querySelector('.kb-img-label').value.trim();
                    var btn = row.querySelector('.kb-choose-img-btn');
                    var preview = row.querySelector('.kb-img-preview');
                    var hiddenUrl = row.querySelector('.kb-img-url');

                    btn.disabled = true;
                    btn.textContent = 'Uploading...';

                    var fd = new FormData();
                    fd.append('action', 'sonoai_kb_upload_img');
                    fd.append('security', nonces.uploadImg);
                    fd.append('file', fileInp.files[0]);
                    fd.append('label', label);

                    $.ajax({
                        url: ajax,
                        type: 'POST',
                        data: fd,
                        processData: false,
                        contentType: false,
                        success: function(res) {
                            if (res.success) {
                                hiddenUrl.value = res.data.url;
                                preview.innerHTML = `<img src="${res.data.url}" style="width:100%; height:100%; object-fit:cover;">`;
                                btn.textContent = 'Change';
                            } else {
                                alert(res.data.message || 'Error');
                                btn.textContent = 'Upload';
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('[SonoAI] Image Upload Failure:', { status: status, error: error, response: xhr.responseText });
                            alert('Upload failed: ' + (error || 'Server error'));
                            btn.textContent = 'Upload';
                        },
                        complete: function() { btn.disabled = false; }
                    });
                }
            });
        }

        // Submit logic
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                var content = document.getElementById('sonoai_kb_txt_editor').value;
                if (!content.trim()) { setNotice(notice, 'Content empty', 'error'); return; }

                var images = [];
                container.querySelectorAll('.kb-image-row').forEach(function(row) {
                    var url = row.querySelector('.kb-img-url').value;
                    var lbl = row.querySelector('.kb-img-label').value;
                    if (url) images.push({ url: url, label: lbl });
                });

                var action = submitBtn.dataset.action === 'edit' ? 'sonoai_kb_edit_txt' : 'sonoai_kb_add_txt';
                var nonce = action === 'sonoai_kb_edit_txt' ? nonces.editTxt : nonces.addTxt;
                
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';

                var payload = {
                    action: action,
                    security: nonce,
                    content: content,
                    images: JSON.stringify(images),
                    mode: modeSel.value,
                    topic_ids: (function() {
                        var txtTopics = [];
                        $('#kb-txt-topic-dropdown input[type="checkbox"]:checked').each(function() {
                            txtTopics.push($(this).val());
                        });
                        return txtTopics;
                    })(),
                    country: (document.getElementById('kb-txt-country') || {}).value,
                    source_name: (document.getElementById('kb-txt-source-name') || {}).value,
                    source_url:  (document.getElementById('kb-txt-source-url') || {}).value
                };
                var editId = document.getElementById('qe-post-id') || document.getElementById('kb-edit-knowledge-id');
                if (editId) payload.knowledge_id = editId.value;

                $.ajax({
                    url: ajax,
                    type: 'POST',
                    data: payload,
                    success: function(res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            console.error('[SonoAI] KB Save Error:', res);
                            alert(res.data && res.data.message ? res.data.message : 'Error: ' + JSON.stringify(res));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[SonoAI] KB AJAX Failure:', { status: status, error: error, response: xhr.responseText });
                        alert('Server error (' + status + '): ' + error);
                    },
                    complete: function() {
                        submitBtn.disabled = false;
                        submitBtn.textContent = submitBtn.dataset.action === 'edit' ? 'Update Knowledge Base' : 'Add to Knowledge Base';
                    }
                });
            });
        }
    }

    function initDeleteButtons() {
        $(document).on('click', '.kb-delete-btn', function() {
            if (!confirm('Delete this item?')) return;
            var kid = this.dataset.knowledgeId;
            var row = $(this).closest('tr');
            $.post(ajax, { action: 'sonoai_kb_delete_item', security: nonces.deleteItem, knowledge_id: kid }, function(res) {
                if (res.success) row.fadeOut();
            });
        });
    }

    function initActionsDropdown() {
        // Toggle the dropdown when the Actions button is clicked
        $(document).on('click', '.kb-row-actions-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            
            var dropdown = $(this).closest('.kb-row-actions-dropdown');
            var isOpen = dropdown.hasClass('is-open');
            
            // Close all other dropdowns first
            $('.kb-row-actions-dropdown').not(dropdown).removeClass('is-open').find('.kb-row-actions-btn').attr('aria-expanded', 'false');
            
            if (!isOpen) {
                dropdown.addClass('is-open');
                $(this).attr('aria-expanded', 'true');
            }
        });

        // Close all dropdowns when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.kb-row-actions-dropdown').length) {
                $('.kb-row-actions-dropdown').removeClass('is-open').find('.kb-row-actions-btn').attr('aria-expanded', 'false');
            }
        });

        // Close dropdown when a menu item is clicked
        $(document).on('click', '.kb-row-actions-menu a, .kb-row-actions-menu button', function () {
            $(this).closest('.kb-row-actions-dropdown').removeClass('is-open').find('.kb-row-actions-btn').attr('aria-expanded', 'false');
        });
    }

    function initGlobalFilters() {
        // Mode selector
        $('.kb-filter-mode-btn').on('click', function() {
            var mode = $(this).data('mode');
            var url = new URL(window.location.href);
            url.searchParams.set('mode', mode);
            // Don't lose existing country/topic if they are relevant?
            // Actually, usually changing mode resets context.
            window.location.href = url.toString();
        });

        // Country search trigger (Button)
        $('#kb-trigger-country-filter').on('click', function() {
            var country = $('#kb-global-country-filter').val();
            var url = new URL(window.location.href);
            if (country) {
                url.searchParams.set('country', country);
            } else {
                url.searchParams.delete('country');
            }
            window.location.href = url.toString();
        });

        // Country search trigger (Enter Key)
        $('#kb-global-country-filter').on('keypress', function(e) {
            if (e.which === 13) {
                $('#kb-trigger-country-filter').trigger('click');
            }
        });

        // Topic selector trigger
        $('#kb-global-topic-filter').on('change', function() {
            var tid = $(this).val();
            var url = new URL(window.location.href);
            if (tid) {
                url.searchParams.set('topic_id', tid);
            } else {
                url.searchParams.delete('topic_id');
            }
            window.location.href = url.toString();
        });
    }

    /**
     * Topics Management Tab Logic
     */
    function initTopicsTab() {
        var addBtn = document.getElementById('kb-btn-add-topic');
        var syncBtn = document.getElementById('kb-btn-sync-topics');
        var modal = document.getElementById('kb-topic-modal');
        var form = document.getElementById('kb-topic-form');
        var cancelBtn = document.getElementById('kb-topic-modal-cancel');
        var titleEl = document.getElementById('kb-topic-modal-title');
        var idField = document.getElementById('kb-topic-id');
        var nameField = document.getElementById('kb-topic-name');

        if (!addBtn || !form) return;

        // Open Modal (Add)
        addBtn.addEventListener('click', function() {
            idField.value = '';
            nameField.value = '';
            titleEl.textContent = 'Add Topic';
            openModal(modal);
        });

        // Close Modal
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                closeModal(modal);
            });
        }

        // Edit Topic
        $(document).on('click', '.kb-edit-topic-btn', function() {
            var tid = this.dataset.id;
            var name = this.dataset.name;
            idField.value = tid;
            nameField.value = name;
            titleEl.textContent = 'Edit Topic';
            openModal(modal);
        });

        // Delete Topic
        $(document).on('click', '.kb-delete-topic-btn', function() {
            var tid = this.dataset.id;
            if (!confirm('Are you sure you want to delete this topic? Content linked to this topic will be set to "No Topic".')) return;

            var payload = {
                action: 'sonoai_kb_delete_topic',
                security: nonces.topics,
                topic_id: tid
            };

            $.post(ajax, payload, function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    showToast(res.data.message || 'Error deleting topic.', 'error');
                }
            });
        });

        // Form Submit (Add/Edit)
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var tid = idField.value;
            var action = tid ? 'sonoai_kb_edit_topic' : 'sonoai_kb_add_topic';
            
            var payload = {
                action: action,
                security: nonces.topics,
                topic_id: tid,
                name: nameField.value.trim()
            };

            $.post(ajax, payload, function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    showToast(res.data.message || 'Error saving topic.', 'error');
                }
            });
        });

        // Sync Topics from WP
        if (syncBtn) {
            syncBtn.addEventListener('click', function() {
                if (!confirm('Sync topics from WordPress categories and tags?')) return;
                
                syncBtn.disabled = true;
                showToast('Syncing topics from WordPress...', 'info');

                var payload = {
                    action: 'sonoai_kb_sync_topics',
                    security: nonces.topics // We consolidated this to use the manage_topics nonce
                };

                $.post(ajax, payload, function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Sync successful!', 'success');
                        setTimeout(function() { location.reload(); }, 1200);
                    } else {
                        showToast(res.data.message || 'Sync failed.', 'error');
                        syncBtn.disabled = false;
                    }
                }).fail(function() {
                    showToast('Fatal error during sync.', 'error');
                    syncBtn.disabled = false;
                });
            });
        }
    }
    /**
     * Knowledge Base View Modal
     */
    function initViewModal() {
        var modal = document.getElementById('kb-view-modal');
        var body = document.getElementById('kb-modal-body');
        var closeBtn = document.querySelector('.kb-modal-close');

        if (!modal || !body) return;

        $(document).on('click', '.kb-view-txt-btn', function() {
            var content = this.dataset.content;
            body.innerHTML = content;
            openModal(modal);
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                closeModal(modal);
            });
        }

        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal(modal);
        });

        // Close on background click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal(modal);
        });
    }

    function initSelectiveActions() {
        // 1. Select All Checkbox logic for Custom Text / PDFs / URLs / JSONLs
        $(document).on('change', '.kb-select-all-cb', function() {
            var isChecked = this.checked;
            var table = $(this).closest('table');
            table.find('tbody input[type="checkbox"]').prop('checked', isChecked);
        });

        // 2. Click Handler for single Re-index button
        $(document).on('click', '.kb-reindex-item-btn', function() {
            var kid = this.dataset.knowledgeId;
            if (!kid) {
                alert('This item has not been added to the Knowledge Base yet.');
                return;
            }
            if (confirm('Are you sure you want to re-index this item? Old vectors will be replaced using the current model.')) {
                runProgressiveReindex([kid]);
            }
        });

        // 3. Click Handler for single Re-sync button
        $(document).on('click', '.kb-resync-item-btn', function() {
            var kid = this.dataset.knowledgeId;
            if (!kid) {
                alert('This item has not been added to the Knowledge Base yet.');
                return;
            }
            var btn = $(this);
            var originalText = btn.html();
            btn.prop('disabled', true).text('⚡ Syncing...');
            $.post(ajax, {
                action: 'sonoai_kb_resync_items',
                security: nonces.resyncItems,
                ids: [kid]
            }, function(res) {
                btn.prop('disabled', false).html(originalText);
                if (res.success) {
                    showToast(res.data.message || 'Sync successful!', 'success');
                } else {
                    showToast(res.data.message || 'Sync failed.', 'error');
                }
            }).fail(function() {
                btn.prop('disabled', false).html(originalText);
                showToast('Fatal error during resync.', 'error');
            });
        });

        // 4. Bulk action apply for General KB tables (Custom Text / PDF / URL / JSONL)
        $(document).on('click', '.kb-list-bar .kb-bulk button', function() {
            var bulkContainer = $(this).closest('.kb-bulk');
            var select = bulkContainer.find('select');
            var action = select.val();
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }

            var table = $(this).closest('.kb-list-bar').next('table');
            if (!table.length) {
                table = $(this).closest('.kb-tab-content, .kb-wrap').find('table');
            }
            
            var checkedCbs = table.find('tbody input[type="checkbox"]:checked');
            if (checkedCbs.length === 0) {
                alert('Please select at least one item.');
                return;
            }

            var kids = [];
            checkedCbs.each(function() {
                var val = $(this).val();
                if (val) kids.push(val);
            });

            if (kids.length === 0) {
                alert('No valid Knowledge Base items found in selection.');
                return;
            }

            if (action === 'delete') {
                if (!confirm('Are you sure you want to delete ' + kids.length + ' items?')) return;
                var processedCount = 0;
                var currentBtn = $(this);
                var originalText = currentBtn.html();
                currentBtn.prop('disabled', true).text('Deleting...');
                
                function deleteNext(idx) {
                    if (idx >= kids.length) {
                        showToast('Successfully deleted ' + processedCount + ' items!', 'success');
                        setTimeout(function() { location.reload(); }, 1200);
                        return;
                    }
                    $.post(ajax, {
                        action: 'sonoai_kb_delete_item',
                        security: nonces.deleteItem,
                        knowledge_id: kids[idx]
                    }, function(res) {
                        if (res.success) processedCount++;
                        deleteNext(idx + 1);
                    }).fail(function() {
                        deleteNext(idx + 1);
                    });
                }
                deleteNext(0);
            } else if (action === 'reindex') {
                if (confirm('Are you sure you want to re-index the ' + kids.length + ' selected items?')) {
                    runProgressiveReindex(kids);
                }
            } else if (action === 'resync') {
                if (confirm('Are you sure you want to re-sync the ' + kids.length + ' selected items directly to Redis?')) {
                    var currentBtn = $(this);
                    var originalText = currentBtn.html();
                    currentBtn.prop('disabled', true).text('Syncing...');
                    $.post(ajax, {
                        action: 'sonoai_kb_resync_items',
                        security: nonces.resyncItems,
                        ids: kids
                    }, function(res) {
                        currentBtn.prop('disabled', false).html(originalText);
                        if (res.success) {
                            showToast(res.data.message || 'Sync successful!', 'success');
                        } else {
                            showToast(res.data.message || 'Sync failed.', 'error');
                        }
                    }).fail(function() {
                        currentBtn.prop('disabled', false).html(originalText);
                        showToast('Fatal error during resync.', 'error');
                    });
                }
            }
        });

        // 5. Bulk action apply for WordPress posts table
        $(document).on('click', '#kb-wp-bulk-apply', function() {
            var action = $('#kb-wp-bulk-action').val();
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }

            var checkedCbs = $('#kb-wp-tbody input[type="checkbox"]:checked');
            if (checkedCbs.length === 0) {
                alert('Please select at least one post.');
                return;
            }

            var postIds = [];
            var kids = [];
            checkedCbs.each(function() {
                postIds.push($(this).val());
                var kid = $(this).data('knowledge-id');
                if (kid) kids.push(kid);
            });

            if (action === 'add') {
                var mVal = (document.getElementById('kb-wp-mode') || {}).value;
                var tVal = (document.getElementById('kb-wp-topic') || {}).value;
                
                var currentBtn = $(this);
                var originalText = currentBtn.html();
                currentBtn.prop('disabled', true).text('Processing...');
                
                var successCount = 0;
                function addNext(idx) {
                    if (idx >= postIds.length) {
                        showToast('Successfully added ' + successCount + ' posts!', 'success');
                        setTimeout(function() { location.reload(); }, 1200);
                        return;
                    }
                    var payload = {
                        action: 'sonoai_kb_add_post',
                        security: nonces.addPost,
                        post_id: postIds[idx]
                    };
                    if (mVal) payload.mode = mVal;
                    if (tVal) payload.topic_id = tVal;

                    $.post(ajax, payload, function(res) {
                        if (res.success) successCount++;
                        addNext(idx + 1);
                    }).fail(function() {
                        addNext(idx + 1);
                    });
                }
                addNext(0);
            } else if (action === 'remove') {
                if (!confirm('Are you sure you want to remove the ' + postIds.length + ' selected posts from the Knowledge Base?')) return;
                var currentBtn = $(this);
                var originalText = currentBtn.html();
                currentBtn.prop('disabled', true).text('Processing...');
                
                var successCount = 0;
                function removeNext(idx) {
                    if (idx >= postIds.length) {
                        showToast('Successfully removed ' + successCount + ' posts!', 'success');
                        setTimeout(function() { location.reload(); }, 1200);
                        return;
                    }
                    $.post(ajax, {
                        action: 'sonoai_kb_remove_post',
                        security: nonces.removePost,
                        post_id: postIds[idx]
                    }, function(res) {
                        if (res.success) successCount++;
                        removeNext(idx + 1);
                    }).fail(function() {
                        removeNext(idx + 1);
                    });
                }
                removeNext(0);
            } else if (action === 'reindex') {
                if (kids.length === 0) {
                    alert('None of the selected posts are currently added to the Knowledge Base. Add them first.');
                    return;
                }
                if (confirm('Are you sure you want to re-index the ' + kids.length + ' selected posts?')) {
                    runProgressiveReindex(kids);
                }
            } else if (action === 'resync') {
                if (kids.length === 0) {
                    alert('None of the selected posts are currently added to the Knowledge Base.');
                    return;
                }
                if (confirm('Are you sure you want to re-sync the ' + kids.length + ' selected posts directly to Redis?')) {
                    var currentBtn = $(this);
                    var originalText = currentBtn.html();
                    currentBtn.prop('disabled', true).text('Syncing...');
                    $.post(ajax, {
                        action: 'sonoai_kb_resync_items',
                        security: nonces.resyncItems,
                        ids: kids
                    }, function(res) {
                        currentBtn.prop('disabled', false).html(originalText);
                        if (res.success) {
                            showToast(res.data.message || 'Sync successful!', 'success');
                        } else {
                            showToast(res.data.message || 'Sync failed.', 'error');
                        }
                    }).fail(function() {
                        currentBtn.prop('disabled', false).html(originalText);
                        showToast('Fatal error during resync.', 'error');
                    });
                }
            }
        });
    }

    /**
     * Progressive Batch Re-indexing to guarantee zero timeouts
     */
    function runProgressiveReindex(ids) {
        var overlay = document.createElement('div');
        overlay.className = 'kb-redis-popup-overlay';
        overlay.innerHTML = `
            <div class="kb-redis-popup">
                <h3 style="margin-top:0;">Re-indexing Selected Items</h3>
                <div class="kb-redis-progress-bar-container">
                    <div class="kb-redis-progress-bar" style="width: 0%;"></div>
                </div>
                <div class="kb-redis-progress-text">0% Completed</div>
                <div class="kb-redis-current-item">Preparing...</div>
            </div>
        `;
        document.body.appendChild(overlay);

        var progressBar = overlay.querySelector('.kb-redis-progress-bar');
        if (progressBar) {
            progressBar.style.background = 'var(--kb-primary, #2563eb)';
        }
        var progressText = overlay.querySelector('.kb-redis-progress-text');
        var currentItemText = overlay.querySelector('.kb-redis-current-item');

        var totalUpdated = 0;
        var totalErrors = 0;

        function processBatch(offset) {
            $.post(ajax, {
                action: 'sonoai_kb_reindex_items',
                security: nonces.reindexItems,
                ids: ids,
                offset: offset,
                batch_size: 3 // Small batch to prevent timeouts
            }, function(res) {
                if (res.success) {
                    var data = res.data;
                    totalUpdated += data.updated || 0;
                    totalErrors += data.errors || 0;
                    var total = data.total || 1;
                    var next_offset = data.next_offset || offset;

                    var percentage = Math.min(100, Math.round((next_offset / total) * 100));
                    if (data.done) percentage = 100;

                    if (progressBar) progressBar.style.width = percentage + '%';
                    if (progressText) progressText.innerText = percentage + '% Completed';
                    if (currentItemText) {
                        if (data.current_item) {
                            currentItemText.innerText = 'Re-embedding: ' + data.current_item;
                        } else {
                            currentItemText.innerText = 'Re-embedding...';
                        }
                    }

                    if (data.done) {
                        setTimeout(function() {
                            alert(data.message || 'Re-indexing completed successfully.');
                            overlay.remove();
                            location.reload();
                        }, 500);
                    } else {
                        processBatch(data.next_offset);
                    }
                } else {
                    alert('Re-index failed: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'));
                    overlay.remove();
                }
            }).fail(function(xhr) {
                var errorMsg = 'Fatal error during re-indexing.';
                if (xhr.responseText) {
                    try {
                        var json = JSON.parse(xhr.responseText);
                        if (json && json.data && json.data.message) {
                            errorMsg += '\nDetails: ' + json.data.message;
                        }
                    } catch(e) {}
                }
                alert(errorMsg);
                overlay.remove();
            });
        }

        processBatch(0);
    }

    // Helpers
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function setNotice(el, msg, type) {
        if (!el) return;
        el.className = 'kb-notice ' + type;
        el.textContent = msg;
        el.style.display = 'block';
    }

    function toggleMetadataFields(prefix) {
        var mode = (document.getElementById('kb-' + prefix + '-mode') || {}).value;
        var isRes = (mode === 'research');
        $('.kb-field-topic', '#kb-' + prefix + '-form').show();
        $('.kb-field-country', '#kb-' + prefix + '-form').toggle(!isRes);
    }

    function openModal(modal) {
        if (!modal) return;
        if (modal.tagName.toLowerCase() === 'dialog') {
            modal.showModal();
        } else {
            modal.style.display = 'flex';
        }
    }

    function closeModal(modal) {
        if (!modal) return;
        if (modal.tagName.toLowerCase() === 'dialog') {
            modal.close();
        } else {
            modal.style.display = 'none';
        }
    }

    /**
     * Initialize all premium checkable multi-select dropdowns
     */
    function initMultiselectDropdowns() {
        // Toggle dropdown options visibility on trigger click
        $(document).on('click', '.kb-multiselect-trigger', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var dropdown = $(this).closest('.kb-multiselect-dropdown');
            var isOpen = dropdown.hasClass('open');
            
            // Close all other multiselect dropdowns
            $('.kb-multiselect-dropdown').not(dropdown).removeClass('open');
            
            if (isOpen) {
                dropdown.removeClass('open');
            } else {
                dropdown.addClass('open');
            }
        });

        // Close when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.kb-multiselect-dropdown').length) {
                $('.kb-multiselect-dropdown').removeClass('open');
            }
        });

        // Update trigger label dynamically when option checkbox is toggled
        $(document).on('change', '.kb-multiselect-option input[type="checkbox"]', function () {
            var dropdown = $(this).closest('.kb-multiselect-dropdown');
            updateMultiselectLabel(dropdown);
        });

        // Helper to update label text based on selected values
        function updateMultiselectLabel(dropdown) {
            var labelSpan = dropdown.find('.kb-multiselect-label');
            var checkedCheckboxes = dropdown.find('.kb-multiselect-option input[type="checkbox"]:checked');
            var isQe = dropdown.attr('id') === 'qe-topics-dropdown';
            var defaultText = isQe ? '— None —' : '— No Topic —';
            
            if (checkedCheckboxes.length === 0) {
                labelSpan.text(defaultText).removeAttr('title');
            } else {
                var selectedNames = [];
                checkedCheckboxes.each(function () {
                    selectedNames.push($(this).data('name') || $(this).next('span').text());
                });
                var fullLabel = selectedNames.join(', ');
                labelSpan.attr('title', fullLabel);
                
                // Truncate display label if too long
                if (fullLabel.length > 25) {
                    labelSpan.text(fullLabel.substring(0, 22) + '...');
                } else {
                    labelSpan.text(fullLabel);
                }
            }
        }

        // Initialize display labels for pre-selected options
        $('.kb-multiselect-dropdown').each(function () {
            updateMultiselectLabel($(this));
        });

        // ── Quick Edit Cancel Event ──────────────────────────────────────────
        $(document).on('click', '#qe-cancel-btn, #qe-close-btn', function() {
            var modal = document.getElementById('kb-quick-edit-modal');
            if (modal) closeModal(modal);
        });

        // ── Quick Edit Save Event ────────────────────────────────────────────
        $(document).on('click', '#qe-save-btn', function() {
            var saveBtn = $(this);
            var pid = $('#qe-post-id').val();
            var mode = $('#qe-mode').val();
            
            var selectedTopics = [];
            $('#qe-topics-dropdown input[type="checkbox"]:checked').each(function() {
                selectedTopics.push($(this).val());
            });

            saveBtn.prop('disabled', true).text('Saving...');

            var payload = {
                action: 'sonoai_kb_update_meta',
                security: nonces.updateMeta,
                post_id: pid,
                type: 'wp',
                mode: mode,
                topic_ids: selectedTopics
            };

            $.post(ajax, payload, function(res) {
                if (res.success) {
                    showToast('Quick edit saved successfully!', 'success');
                    var modal = document.getElementById('kb-quick-edit-modal');
                    if (modal) closeModal(modal);
                    
                    // Reload posts dynamically to display new topic lists
                    location.reload(); 
                } else {
                    alert(res.data.message || 'Error occurred while saving.');
                }
            }).fail(function() {
                alert('Fatal error occurred while saving.');
            }).always(function() {
                saveBtn.prop('disabled', false).text('Save Changes');
            });
        });
    }

})(jQuery);
