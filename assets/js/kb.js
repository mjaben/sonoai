/**
 * SonoAI — Knowledge Base admin JS
 * Handles: dark/light toggle, WP Posts AJAX table, PDF upload, URL fetch, custom text via TinyMCE.
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

        // Route to tab-specific init based on which tab is active.
        // Initialize all modules safely
        // Each function handles its own existence checks
        initWpTab();
        initPdfTab();
        initUrlTab();
        initTxtTab();
        initDeleteButtons();
        initViewModal();
        initTopicsTab();
    });

    /**
     * Safe open for <dialog> elements
     */
    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open'); // For CSS fallback
        if (typeof modal.showModal === 'function') {
            try {
                modal.showModal();
            } catch (e) {
                modal.setAttribute('open', 'open');
            }
        } else {
            modal.setAttribute('open', 'open');
            modal.style.display = 'block';
            // Simple backdrop fallback
            var backdrop = document.createElement('div');
            backdrop.id = 'kb-modal-fallback-backdrop';
            backdrop.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;';
            document.body.appendChild(backdrop);
            modal.style.zIndex = '9999';
            modal.style.position = 'fixed';
            modal.style.top = '50%';
            modal.style.left = '50%';
            modal.style.transform = 'translate(-50%, -50%)';
        }
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        if (typeof modal.close === 'function') {
            try {
                modal.close();
            } catch (e) {
                modal.removeAttribute('open');
            }
        } else {
            modal.removeAttribute('open');
            modal.style.display = 'none';
            var backdrop = document.getElementById('kb-modal-fallback-backdrop');
            if (backdrop) backdrop.remove();
        }
    }

    // ── WP Posts Tab ──────────────────────────────────────────────────────────
    function initWpTab() {
        var currentPt = document.getElementById('kb-current-pt');
        var postType = currentPt ? currentPt.value : 'post';
        var tbody  = document.getElementById('kb-wp-tbody');
        var pagDiv = document.getElementById('kb-wp-pagination');
        if (!tbody) return;

        var searchInput = document.getElementById('kb-wp-search');
        var checkAll    = document.getElementById('kb-wp-check-all');
        var bulkSel     = document.getElementById('kb-wp-bulk-action');
        var bulkApply   = document.getElementById('kb-wp-bulk-apply');
        var filterBtns  = document.querySelectorAll('.kb-status-btn');
        var filterMode  = document.getElementById('kb-wp-filter-mode');
        var filterTopic = document.getElementById('kb-wp-filter-topic');
        var currentPage = 1;
        var currentSearch = '';
        var currentFilter = 'all';

        function loadPosts(page, search) {
            currentPage   = page;
            currentSearch = search;
            tbody.innerHTML = '<tr class="kb-loading-row"><td colspan="6"><span class="kb-spinner"></span> Loading…</td></tr>';
            
            var payload = {
                action:    'sonoai_kb_get_posts',
                nonce:     nonces.getPosts,
                post_type: postType,
                page:      page,
                search:    search,
                kb_status: currentFilter,
            };
            if (filterMode && filterMode.value) payload.mode = filterMode.value;
            if (filterTopic && filterTopic.value) payload.topic_id = filterTopic.value;
            
            $.post(ajax, payload, function (res) {
                renderPosts(res.data);
            }).fail(function () {
                tbody.innerHTML = '<tr><td colspan="7" class="kb-empty">Error loading posts.</td></tr>';
            });
        }

        function renderPosts(data) {
            if (data && data.counts) {
                var elAll = document.getElementById('kb-count-all');
                var elAdded = document.getElementById('kb-count-added');
                var elNotAdded = document.getElementById('kb-count-not_added');
                var elUpdate = document.getElementById('kb-count-update');
                if (elAll) elAll.textContent = data.counts.all;
                if (elAdded) elAdded.textContent = data.counts.added;
                if (elNotAdded) elNotAdded.textContent = data.counts.not_added;
                if (elUpdate) elUpdate.textContent = data.counts.update;
            }

            if (!data || !data.posts || data.posts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="kb-empty">No posts found.</td></tr>';
                pagDiv.innerHTML = '';
                return;
            }
            var html = '';
            data.posts.forEach(function (p) {
                var badgeHtml = '';
                var btnsHtml = '';

                if (p.kb_status === 'added') {
                    badgeHtml = '<span class="kb-badge-added">Added</span>';
                    btnsHtml  = '<button type="button" class="kb-add-btn kb-quick-edit-btn" data-post-id="' + p.id + '" data-mode="' + p.raw_mode + '" data-topic="' + p.topic_id + '">Quick Edit</button>'
                              + '<button type="button" class="kb-add-btn kb-remove-btn" data-post-id="' + p.id + '">Remove</button>';
                } else if (p.kb_status === 'update') {
                    badgeHtml = '<span class="kb-badge-update">Requires Update</span>';
                    btnsHtml  = '<button type="button" class="kb-add-btn kb-update-btn" data-post-id="' + p.id + '">Update</button>'
                              + '<button type="button" class="kb-add-btn kb-quick-edit-btn" data-post-id="' + p.id + '" data-mode="' + p.raw_mode + '" data-topic="' + p.topic_id + '">Quick Edit</button>'
                              + '<button type="button" class="kb-add-btn kb-remove-btn" data-post-id="' + p.id + '">Remove</button>';
                } else {
                    badgeHtml = '<span class="kb-badge-not-added">Not Added</span>';
                    btnsHtml  = '<button type="button" class="kb-add-btn" data-post-id="' + p.id + '"><span class="kb-btn-text">Add to KB</span><span class="kb-spinner" style="display:none"></span></button>';
                }

                html += '<tr data-post-id="' + p.id + '">'
                    + '<td><input type="checkbox" class="kb-wp-row-cb" value="' + p.id + '"></td>'
                    + '<td><a href="' + (p.edit_url || '#') + '" target="_blank">' + escHtml(p.title) + '</a></td>'
                    + '<td class="kb-col-date">' + escHtml(p.last_modified) + '</td>'
                    + '<td class="kb-col-date">' + escHtml(p.kb_added) + '</td>'
                    + '<td>' + badgeHtml + '</td>'
                    + '<td>' + escHtml(p.mode) + '</td>'
                    + '<td>' + escHtml(p.topic_name) + '</td>'
                    + '<td>' + (p.ai_model !== '—' ? '<span class="kb-badge-model">' + escHtml(p.ai_model) + '</span>' : '—') + '</td>'
                    + '<td>' + btnsHtml + '</td>'
                    + '</tr>';
            });
            tbody.innerHTML = html;

            // Bind add/remove buttons.
            tbody.querySelectorAll('.kb-add-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var pid     = this.dataset.postId;
                    var isRemove = this.classList.contains('kb-remove-btn');
                    var spinner  = this.querySelector('.kb-spinner');
                    var btnText  = this.querySelector('.kb-btn-text');
                    this.disabled = true;
                    if (spinner) spinner.style.display = 'inline-block';
                    if (btnText) btnText.style.opacity  = '0.5';

                    var payload = {
                        action:  isRemove ? 'sonoai_kb_remove_post' : 'sonoai_kb_add_post',
                        nonce:   isRemove ? nonces.removePost : nonces.addPost,
                        post_id: pid,
                    };
                    if (!isRemove) {
                        var mSel = document.getElementById('kb-wp-bulk-mode'); // Should use a different ID if possible, but let's fall back to wp-mode for bulk
                        var bSelItemM = document.getElementById('kb-wp-mode');
                        if (bSelItemM) payload.mode = bSelItemM.value;
                        
                        var tSel = document.getElementById('kb-wp-topic');
                        if (tSel) payload.topic_id = tSel.value;
                    }

                    $.post(ajax, payload, function () {
                        loadPosts(currentPage, currentSearch);
                    }).fail(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                            ? xhr.responseJSON.data.message : 'Error. Try again.';
                        alert(msg);
                        loadPosts(currentPage, currentSearch);
                    });
                });
            });

            // Bind quick edit buttons.
            tbody.querySelectorAll('.kb-quick-edit-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var pid = this.dataset.postId;
                    var mode = this.dataset.mode;
                    var topic = this.dataset.topic;

                    document.getElementById('qe-post-id').value = pid;
                    var qeMode = document.getElementById('qe-mode');
                    if(qeMode) {
                        qeMode.value = mode || 'guideline';
                    }
                    var qeTopic = document.getElementById('qe-topic');
                    if(qeTopic) {
                        qeTopic.value = topic || 0;
                    }
                    var modal = document.getElementById('kb-quick-edit-modal');
                    if(modal) {
                        modal.style.display = 'flex';
                    }
                });
            });

            renderPagination(data, pagDiv);
        }

        // Quick Edit Modal events
        var qeModal = document.getElementById('kb-quick-edit-modal');
        var qeClose = document.getElementById('qe-close-btn');
        var qeSave  = document.getElementById('qe-save-btn');
        if (qeModal && qeClose) {
            qeClose.addEventListener('click', function() {
                qeModal.style.display = 'none';
            });
        }
        if (qeSave) {
            qeSave.addEventListener('click', function() {
                var pid = document.getElementById('qe-post-id').value;
                var mode = document.getElementById('qe-mode').value;
                var topic = document.getElementById('qe-topic').value;

                var btnText = this.innerText;
                this.innerText = 'Saving...';
                this.disabled = true;
                
                var payload = {
                    action: 'sonoai_kb_update_meta',
                    nonce: nonces.updateMeta, // Corrected from nonces.topics
                    post_id: pid,
                    type: 'wp',
                    mode: mode,
                    topic_id: topic
                };

                $.post(ajax, payload, function(res) {
                    qeModal.style.display = 'none';
                    qeSave.innerText = btnText;
                    qeSave.disabled = false;
                    loadPosts(currentPage, currentSearch);
                }).fail(function() {
                    alert('Error saving meta');
                    qeSave.innerText = btnText;
                    qeSave.disabled = false;
                });
            });
        }

        function renderPagination(data, container) {
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

        // Check-all.
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                tbody.querySelectorAll('.kb-wp-row-cb').forEach(function (cb) { cb.checked = checkAll.checked; });
            });
        }

        // Status filters.
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

        // Search (debounced).
        var searchTimer;
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                var q = this.value;
                searchTimer = setTimeout(function () { loadPosts(1, q); }, 400);
            });
        }

        // Mode and Topic Filters
        if (filterMode) {
            filterMode.addEventListener('change', function () {
                loadPosts(1, currentSearch);
            });
        }
        if (filterTopic) {
            filterTopic.addEventListener('change', function () {
                loadPosts(1, currentSearch);
            });
        }

        // Bulk apply.
        if (bulkApply) {
            bulkApply.addEventListener('click', function () {
                var action = bulkSel ? bulkSel.value : '';
                if (!action) return;
                var checked = Array.from(tbody.querySelectorAll('.kb-wp-row-cb:checked')).map(function (cb) { return cb.value; });
                if (!checked.length) return;
                var nonce  = action === 'add' ? nonces.addPost : nonces.removePost;
                var ajxAct = action === 'add' ? 'sonoai_kb_add_post' : 'sonoai_kb_remove_post';
                var pending = checked.length;
                checked.forEach(function (pid) {
                    var payload = { action: ajxAct, nonce: nonce, post_id: pid };
                    if (action === 'add') {
                        var mSel = document.getElementById('kb-wp-mode');
                        var tSel = document.getElementById('kb-wp-topic');
                        if (mSel) payload.mode = mSel.value;
                        if (tSel) payload.topic_id = tSel.value;
                    }
                    $.post(ajax, payload, function () {
                        pending--;
                        if (pending === 0) loadPosts(currentPage, currentSearch);
                    });
                });
            });
        }

        loadPosts(1, '');
    }

    // ── PDF Tab ───────────────────────────────────────────────────────────────
    function initPdfTab() {
        var form     = document.getElementById('kb-pdf-form');
        var fileInp  = document.getElementById('kb-pdf-file');
        var fileHint = document.getElementById('kb-pdf-filename');
        var submitBtn = document.getElementById('kb-pdf-submit');
        var notice   = document.getElementById('kb-pdf-notice');
        var modeSel  = document.getElementById('kb-pdf-mode');

        if (!form) return;

        if (modeSel) {
            modeSel.addEventListener('change', function() { toggleMetadataFields('pdf'); });
            toggleMetadataFields('pdf');
        }

        if (fileInp) {
            fileInp.addEventListener('change', function () {
                var name = fileInp.files[0] ? fileInp.files[0].name : 'No file chosen';
                fileHint.textContent = name;
                if (submitBtn) submitBtn.disabled = !fileInp.files[0];
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!fileInp.files[0]) return;
            setNotice(notice, '', '');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading…';

            var fd = new FormData();
            fd.append('action', 'sonoai_kb_add_pdf');
            fd.append('nonce',  nonces.addPdf);
            fd.append('pdf_file', fileInp.files[0]);

            var mSel = document.getElementById('kb-pdf-mode');
            var tSel = document.getElementById('kb-pdf-topic');
            var cInp = document.getElementById('kb-pdf-country');
            var snInp = document.getElementById('kb-pdf-source-name');
            var suInp = document.getElementById('kb-pdf-source-url');

            if (mSel) fd.append('mode', mSel.value);
            if (tSel) fd.append('topic_id', tSel.value);
            if (cInp) fd.append('country', cInp.value);
            if (snInp) fd.append('source_name', snInp.value);
            if (suInp) fd.append('source_url', suInp.value);

            $.ajax({
                url:         ajax,
                type:        'POST',
                data:        fd,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        setNotice(notice, '✓ PDF added to knowledge base (' + res.data.chunk_count + ' chunks).', 'success');
                        fileInp.value = '';
                        fileHint.textContent = 'No file chosen';
                        submitBtn.disabled   = true;
                        setTimeout(function () { location.reload(); }, 1200);
                    } else {
                        setNotice(notice, res.data.message || 'Error.', 'error');
                    }
                },
                error: function () { setNotice(notice, 'Upload failed. Try again.', 'error'); },
                complete: function () { submitBtn.textContent = 'Submit'; submitBtn.disabled = false; },
            });
        });
    }

    // ── Website URL Tab ───────────────────────────────────────────────────────
    function initUrlTab() {
        var form   = document.getElementById('kb-url-form');
        var input  = document.getElementById('kb-url-input');
        var submit = document.getElementById('kb-url-submit');
        var notice = document.getElementById('kb-url-notice');
        var modeSel = document.getElementById('kb-url-mode');

        if (!form) return;

        if (modeSel) {
            modeSel.addEventListener('change', function() { toggleMetadataFields('url'); });
            toggleMetadataFields('url');
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var url = input ? input.value.trim() : '';
            if (!url) return;
            setNotice(notice, '', '');
            var spinner = submit.querySelector('.kb-spinner');
            var text    = submit.querySelector('.kb-btn-text');
            if (spinner) spinner.style.display = 'inline-block';
            if (text)    text.style.opacity    = '0.5';
            submit.disabled = true;

            var payload = {
                action: 'sonoai_kb_add_url',
                nonce:  nonces.addUrl,
                url:    url,
            };
            var mSel = document.getElementById('kb-url-mode');
            var tSel = document.getElementById('kb-url-topic');
            var cInp = document.getElementById('kb-url-country');
            var snInp = document.getElementById('kb-url-source-name');
            var suInp = document.getElementById('kb-url-source-url');

            if (mSel) payload.mode = mSel.value;
            if (tSel) payload.topic_id = tSel.value;
            if (cInp) payload.country = cInp.value;
            if (snInp) payload.source_name = snInp.value;
            if (suInp) payload.source_url = suInp.value;

            $.post(ajax, payload, function (res) {
                if (res.success) {
                    setNotice(notice, '✓ URL added to knowledge base (' + res.data.chunk_count + ' chunks).', 'success');
                    if (input) input.value = '';
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    setNotice(notice, res.data.message || 'Error.', 'error');
                }
            }).fail(function () {
                setNotice(notice, 'Request failed. Check the URL and try again.', 'error');
            }).always(function () {
                if (spinner) spinner.style.display = 'none';
                if (text)    text.style.opacity    = '1';
                submit.disabled = false;
            });
        });
    }

    // ── Custom Text Tab ───────────────────────────────────────────────────────
    function initTxtTab() {
        var btn    = document.getElementById('kb-txt-submit');
        var notice = document.getElementById('kb-txt-notice');
        var modeSel = document.getElementById('kb-txt-mode');
        var container = document.getElementById('kb-txt-images-container');
        var addBtn = document.getElementById('kb-add-image-row');

        if (!btn) return;

        if (modeSel) {
            modeSel.addEventListener('change', function() { toggleMetadataFields('txt'); });
            toggleMetadataFields('txt');
        }

        // Image Repeater Logic
        if (addBtn && container) {
            addBtn.addEventListener('click', function() {
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
                            <button type="button" class="kb-btn-sm kb-choose-img-btn" style="flex:1; justify-content:center; background:rgba(255,255,255,0.05);">
                                Upload Sonogram
                            </button>
                            <input type="file" class="kb-file-input" accept="image/*" style="display:none;">
                        </div>
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:11px; font-weight:700; text-transform:uppercase; opacity:0.6; display:block; margin-bottom:5px;">Clinical Label</label>
                        <input type="text" class="kb-img-label kb-input-sm" placeholder="e.g. Gallbladder with Sludge" style="width:100%;">
                    </div>
                    <button type="button" class="kb-btn-sm kb-remove-img-row" style="height:36px; padding:0 12px; color:#ef4444; background:rgba(239,68,68,0.08); border-color:rgba(239,68,68,0.15);">
                        🗑
                    </button>
                `;
                container.appendChild(row);
            });

            container.addEventListener('click', function(e) {
                var delBtn = e.target.closest('.kb-remove-img-row');
                if (delBtn) {
                    delBtn.closest('.kb-image-row').remove();
                    return;
                }

                var chooseBtn = e.target.closest('.kb-choose-img-btn');
                if (chooseBtn) {
                    var row = chooseBtn.closest('.kb-image-row');
                    var label = row.querySelector('.kb-img-label').value.trim();
                    if (!label) {
                        alert('Please enter a Clinical Label first to name the file correctly.');
                        return;
                    }
                    row.querySelector('.kb-file-input').click();
                }
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
                    var originalText = btn.textContent;
                    btn.textContent = 'Uploading...';

                    var fd = new FormData();
                    fd.append('action', 'sonoai_kb_upload_img');
                    fd.append('nonce', nonces.uploadImg);
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
                                btn.textContent = 'Change Image';
                            } else {
                                alert(res.data.message || 'Upload failed.');
                                btn.textContent = originalText;
                            }
                        },
                        error: function() {
                            alert('Upload failed. Check your connection.');
                            btn.textContent = originalText;
                        },
                        complete: function() {
                            btn.disabled = false;
                        }
                    });
                }
            });
        }

        btn.addEventListener('click', function () {
            var ta = document.getElementById('sonoai_kb_txt_editor');
            var content = ta ? ta.value : '';

            if (!content.trim()) {
                setNotice(notice, 'Please enter some content.', 'error');
                return;
            }

            // Gather Images
            var images = [];
            if (container) {
                container.querySelectorAll('.kb-image-row').forEach(function(row) {
                    var urlEl = row.querySelector('.kb-img-url');
                    var labelEl = row.querySelector('.kb-img-label');
                    if (urlEl && urlEl.value.trim()) {
                        images.push({ 
                            url: urlEl.value.trim(), 
                            label: labelEl ? labelEl.value.trim() : '' 
                        });
                    }
                });
            }

            var action = btn.dataset.action === 'edit' ? 'sonoai_kb_edit_txt' : 'sonoai_kb_add_txt';
            var nonce  = action === 'sonoai_kb_edit_txt' ? nonces.editTxt : nonces.addTxt;
            var spinner = btn.querySelector('.kb-spinner');
            var btnText = btn.querySelector('.kb-btn-text');

            setNotice(notice, '', '');
            btn.disabled = true;
            if (spinner) spinner.style.display = 'inline-block';
            if (btnText) btnText.style.opacity  = '0.5';

            var payload = { 
                action: action, 
                nonce: nonce, 
                content: content,
                images: JSON.stringify(images)
            };
            var editId = document.getElementById('kb-edit-knowledge-id');
            if (editId) payload.knowledge_id = editId.value;

            var mSel = document.getElementById('kb-txt-mode');
            var tSel = document.getElementById('kb-txt-topic');
            var cInp = document.getElementById('kb-txt-country');
            var snInp = document.getElementById('kb-txt-source-name');
            var suInp = document.getElementById('kb-txt-source-url');

            if (mSel) payload.mode = mSel.value;
            if (tSel) payload.topic_id = tSel.value;
            if (cInp) payload.country = cInp.value;
            if (snInp) payload.source_name = snInp.value;
            if (suInp) payload.source_url = suInp.value;

            $.post(ajax, payload, function (res) {
                if (res.success) {
                    setNotice(notice, '✓ ' + (res.data.message || 'Saved.'), 'success');
                    setTimeout(function () {
                        window.location = window.location.href.split('?')[0] + '?page=sonoai-kb&kb_tab=txt';
                    }, 1000);
                } else {
                    setNotice(notice, res.data.message || 'Error.', 'error');
                    btn.disabled = false;
                    if (spinner) spinner.style.display = 'none';
                    if (btnText) btnText.style.opacity  = '1';
                }
            }).fail(function () {
                setNotice(notice, 'Request failed. Try again.', 'error');
                btn.disabled = false;
                if (spinner) spinner.style.display = 'none';
                if (btnText) btnText.style.opacity  = '1';
            });
        });
    }

    // ── Delete buttons ────────────────────────────────────────────────────────
    function initDeleteButtons() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.kb-delete-btn');
            if (!btn) return;
            if (!confirm('Delete this item from the knowledge base?')) return;
            var kid = btn.dataset.knowledgeId;
            var row = btn.closest('tr');
            $.post(ajax, {
                action:       'sonoai_kb_delete_item',
                nonce:        nonces.deleteItem,
                knowledge_id: kid,
            }, function (res) {
                if (res.success && row) {
                    row.style.opacity = '0';
                    setTimeout(function () {
                        row.remove();
                    }, 300);
                } else {
                    alert((res.data && res.data.message) || 'Delete failed.');
                }
            });
        });
    }

    // ── View modal (custom text) ──────────────────────────────────────────────
    function initViewModal() {
        var modal = document.getElementById('kb-view-modal');
        var body  = document.getElementById('kb-modal-body');
        if (!modal) return;

        document.addEventListener('click', function (e) {
            var viewBtn = e.target.closest('.kb-view-txt-btn');
            if (viewBtn) {
                body.innerHTML = viewBtn.dataset.content || '(empty)';
                modal.style.display = 'flex';
            }
        });

        modal.querySelector('.kb-modal-close').addEventListener('click', function () {
            modal.style.display = 'none';
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.style.display = 'none';
        });
    }

    // ── Topics Tab ────────────────────────────────────────────────────────────
    function initTopicsTab() {
        var modal = document.getElementById('kb-topic-modal');
        var form  = document.getElementById('kb-topic-form');
        var tbody = document.getElementById('kb-topics-tbody');

        if (!modal || !form) return;

        // Use document-level delegation for better robustness
        document.addEventListener('click', function(e) {
            // Open Modal for Add
            const addBtn = e.target.closest('#kb-btn-add-topic');
            if (addBtn) {
                e.preventDefault();
                document.getElementById('kb-topic-modal-title').textContent = 'Add Topic';
                document.getElementById('kb-topic-id').value = '';
                form.reset();
                openModal(modal);
                return;
            }

            // Edit Topic
            var editBtn = e.target.closest('.kb-edit-topic-btn');
            if (editBtn) {
                e.preventDefault();
                document.getElementById('kb-topic-modal-title').textContent = 'Edit Topic';
                document.getElementById('kb-topic-id').value = editBtn.dataset.id;
                document.getElementById('kb-topic-name').value = editBtn.dataset.name;
                openModal(modal);
                return;
            }

            // Delete Topic
            var delBtn = e.target.closest('.kb-delete-topic-btn');
            if (delBtn) {
                if (!confirm('Are you sure you want to delete this topic? Items using this topic will be unassigned.')) return;
                
                var id = delBtn.dataset.id;
                delBtn.disabled = true;

                $.post(ajax, {
                    action: 'sonoai_kb_delete_topic',
                    nonce: nonces.topics,
                    topic_id: id
                }, function(res) {
                    if (res.success) location.reload();
                    else {
                        alert(res.data.message || 'Error deleting topic.');
                        delBtn.disabled = false;
                    }
                });
            }

        });

        // Use closeModal for Cancel button inside form
        var cancelBtn = document.getElementById('kb-topic-modal-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                closeModal(modal);
            });
        }

        // Handle Form Submit (Add or Edit)
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var id   = document.getElementById('kb-topic-id').value;
            var name = document.getElementById('kb-topic-name').value;
            var submitBtn = form.querySelector('button[type="submit"]');

            var action = id ? 'sonoai_kb_edit_topic' : 'sonoai_kb_add_topic';
            
            submitBtn.disabled = true;
            var originalText = submitBtn.textContent;
            submitBtn.textContent = 'Saving...';

            console.log('SonoAI Topics: Saving', { action: action, id: id, name: name });

            $.post(ajax, {
                action: action,
                nonce:  nonces.topics,
                topic_id: id,
                name:     name
            }, function(res) {
                console.log('SonoAI Topics: Save Res', res);
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.data.message || 'Saving failed.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            }).fail(function(xhr) {
                console.error('SonoAI Topics: Save Fail', xhr);
                alert('Request failed. Check console for details.');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
        });
    }

    /**
     * Toggles field visibility based on Guideline vs Research mode
     */
    function toggleMetadataFields(prefix) {
        var modeSel = document.getElementById('kb-' + prefix + '-mode');
        if (!modeSel) return;

        var isResearch = modeSel.value === 'research';
        
        // Find Groups
        var form = document.getElementById('kb-' + prefix + '-form');
        if (!form) return;

        var topicGroup = form.querySelector('.kb-field-topic');
        var countryGroup = form.querySelector('.kb-field-country');

        if (topicGroup) {
            topicGroup.style.display = isResearch ? 'block' : 'none';
        }
        if (countryGroup) {
            countryGroup.style.display = isResearch ? 'none' : 'block';
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function setNotice(el, msg, type) {
        if (!el) return;
        el.className = 'kb-notice' + (type ? ' ' + type : '');
        el.textContent = msg;
        el.style.display = msg ? 'block' : 'none';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}(jQuery));
