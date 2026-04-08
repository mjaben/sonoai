/**
 * SonoAI — Knowledge Base admin JS
 * Handles: dark/light toggle, WP Posts AJAX table, PDF upload, URL fetch, custom text via TinyMCE.
 * Version: 1.3.1
 */
(function ($) {
    'use strict';

    console.log('SonoAI KB: v1.3.1 Active');

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
        initTxtTab();
        initGlobalFilters();
        initDeleteButtons();
        initViewModal();
        initTopicsTab();
    });

    /**
     * Tab: WordPress Posts
     */
    function initWpTab() {
        console.log('SonoAI KB: initWpTab starting...');
        
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
            console.log('SonoAI KB: loadPosts()', { page, search, filters });
            
            currentPage   = page;
            currentSearch = search;
            tbody.innerHTML = '<tr class="kb-loading-row"><td colspan="9"><span class="kb-spinner"></span> Loading posts...</td></tr>';
            
            var payload = {
                action:    'sonoai_kb_get_posts',
                nonce:     nonces.getPosts,
                post_type: postType,
                page:      page,
                search:    search,
                kb_status: currentFilter,
                mode:      filters.mode,
                topic_id:  filters.topic,
                country:   filters.country
            };
            
            $.post(ajax, payload, function (res) {
                console.log('SonoAI KB: loadPosts() success', res);
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
                        action:  isRemove ? 'sonoai_kb_remove_post' : 'sonoai_kb_add_post',
                        nonce:   isRemove ? nonces.removePost : nonces.addPost,
                        post_id: pid,
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
                    var topic = this.dataset.topic;

                    var qePid = document.getElementById('qe-post-id');
                    var qeMode = document.getElementById('qe-mode');
                    var qeTopic = document.getElementById('qe-topic');
                    var modal = document.getElementById('kb-quick-edit-modal');

                    if (qePid) qePid.value = pid;
                    if (qeMode) qeMode.value = mode || 'guideline';
                    if (qeTopic) qeTopic.value = topic || 0;
                    if (modal) modal.style.display = 'flex';
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
            fd.append('nonce',  nonces.addPdf);
            fd.append('pdf_file', fileInp.files[0]);
            
            ['mode', 'topic_id', 'country', 'source_name', 'source_url'].forEach(function(f) {
                var el = document.getElementById('kb-pdf-' + f.replace('_id', ''));
                if (el) fd.append(f === 'topic' ? 'topic_id' : f, el.value);
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
                nonce: nonces.addUrl, 
                url: input.value.trim() 
            };
            ['mode', 'topic_id', 'country', 'source_name', 'source_url'].forEach(function(f) {
                var el = document.getElementById('kb-url-' + f.replace('_id', ''));
                if (el) payload[f === 'topic' ? 'topic_id' : f] = el.value;
            });

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
                                btn.textContent = 'Change';
                            } else {
                                alert(res.data.message || 'Error');
                                btn.textContent = 'Upload';
                            }
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
                    nonce: nonce,
                    content: content,
                    images: JSON.stringify(images),
                    mode: modeSel.value,
                    topic_id: (document.getElementById('kb-txt-topic') || {}).value,
                    country: (document.getElementById('kb-txt-country') || {}).value
                };
                var editId = document.getElementById('kb-edit-knowledge-id');
                if (editId) payload.knowledge_id = editId.value;

                $.post(ajax, payload, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data.message || 'Error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Save';
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
            $.post(ajax, { action: 'sonoai_kb_delete_item', nonce: nonces.deleteItem, knowledge_id: kid }, function(res) {
                if (res.success) row.fadeOut();
            });
        });
    }

    function initGlobalFilters() {
        $('.kb-filter-mode-btn').on('click', function() {
            var mode = $(this).data('mode');
            $('.kb-filter-mode-btn').removeClass('active');
            $(this).addClass('active');
            
            // Sync visibility
            $('.kb-filter-country-wrap').toggle(mode === 'guideline');
            $('.kb-filter-topic-wrap').toggle(mode === 'research');
            
            // Hard reload for now to let PHP handle URL params or re-init
            var url = new URL(window.location.href);
            url.searchParams.set('mode', mode);
            window.location.href = url.toString();
        });
    }

    function initTopicsTab() { /* Implementation... */ }
    function initViewModal() { /* Implementation... */ }

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
        $('.kb-field-topic', '#kb-' + prefix + '-form').toggle(isRes);
        $('.kb-field-country', '#kb-' + prefix + '-form').toggle(!isRes);
    }

    function openModal(modal) {
        if (!modal) return;
        modal.style.display = 'flex';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.style.display = 'none';
    }

})(jQuery);
