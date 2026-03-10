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
        var currentPt = document.getElementById('kb-current-pt');
        if (currentPt) initWpTab(currentPt.value);

        initPdfTab();
        initUrlTab();
        initTxtTab();
        initDeleteButtons();
        initViewModal();
    });

    // ── WP Posts Tab ──────────────────────────────────────────────────────────
    function initWpTab(postType) {
        var tbody  = document.getElementById('kb-wp-tbody');
        var pagDiv = document.getElementById('kb-wp-pagination');
        if (!tbody) return;

        var searchInput = document.getElementById('kb-wp-search');
        var checkAll    = document.getElementById('kb-wp-check-all');
        var bulkSel     = document.getElementById('kb-wp-bulk-action');
        var bulkApply   = document.getElementById('kb-wp-bulk-apply');
        var filterBtns  = document.querySelectorAll('.kb-status-btn');
        var currentPage = 1;
        var currentSearch = '';
        var currentFilter = 'all';

        function loadPosts(page, search) {
            currentPage   = page;
            currentSearch = search;
            tbody.innerHTML = '<tr class="kb-loading-row"><td colspan="6"><span class="kb-spinner"></span> Loading…</td></tr>';
            $.post(ajax, {
                action:    'sonoai_kb_get_posts',
                nonce:     nonces.getPosts,
                post_type: postType,
                page:      page,
                search:    search,
                kb_status: currentFilter,
            }, function (res) {
                renderPosts(res.data);
            }).fail(function () {
                tbody.innerHTML = '<tr><td colspan="6" class="kb-empty">Error loading posts.</td></tr>';
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
                tbody.innerHTML = '<tr><td colspan="7" class="kb-empty">No posts found.</td></tr>';
                pagDiv.innerHTML = '';
                return;
            }
            var html = '';
            data.posts.forEach(function (p) {
                var badgeHtml = '';
                var btnsHtml = '';

                if (p.kb_status === 'added') {
                    badgeHtml = '<span class="kb-badge-added">Added</span>';
                    btnsHtml  = '<button type="button" class="kb-add-btn kb-remove-btn" data-post-id="' + p.id + '">Remove</button>';
                } else if (p.kb_status === 'update') {
                    badgeHtml = '<span class="kb-badge-update">Requires Update</span>';
                    btnsHtml  = '<button type="button" class="kb-add-btn kb-update-btn" data-post-id="' + p.id + '">Update</button>'
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

                    $.post(ajax, {
                        action:  isRemove ? 'sonoai_kb_remove_post' : 'sonoai_kb_add_post',
                        nonce:   isRemove ? nonces.removePost : nonces.addPost,
                        post_id: pid,
                    }, function () {
                        loadPosts(currentPage, currentSearch);
                    }).fail(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                            ? xhr.responseJSON.data.message : 'Error. Try again.';
                        alert(msg);
                        loadPosts(currentPage, currentSearch);
                    });
                });
            });

            renderPagination(data, pagDiv);
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
                    $.post(ajax, { action: ajxAct, nonce: nonce, post_id: pid }, function () {
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
        if (!form) return;

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

    // ── URL Tab ───────────────────────────────────────────────────────────────
    function initUrlTab() {
        var form   = document.getElementById('kb-url-form');
        var input  = document.getElementById('kb-url-input');
        var submit = document.getElementById('kb-url-submit');
        var notice = document.getElementById('kb-url-notice');
        if (!form) return;

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

            $.post(ajax, {
                action: 'sonoai_kb_add_url',
                nonce:  nonces.addUrl,
                url:    url,
            }, function (res) {
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
        if (!btn) return;

        btn.addEventListener('click', function () {
            // Fetch content from TinyMCE or plain textarea.
            var content = '';
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('sonoai_kb_txt_editor')) {
                content = tinyMCE.get('sonoai_kb_txt_editor').getContent();
            } else {
                var ta = document.getElementById('sonoai_kb_txt_editor');
                content = ta ? ta.value : '';
            }

            if (!content.trim()) {
                setNotice(notice, 'Please enter some content.', 'error');
                return;
            }

            var action = btn.dataset.action === 'edit' ? 'sonoai_kb_edit_txt' : 'sonoai_kb_add_txt';
            var nonce  = action === 'sonoai_kb_edit_txt' ? nonces.editTxt : nonces.addTxt;
            var spinner = btn.querySelector('.kb-spinner');
            var btnText = btn.querySelector('.kb-btn-text');

            setNotice(notice, '', '');
            btn.disabled = true;
            if (spinner) spinner.style.display = 'inline-block';
            if (btnText) btnText.style.opacity  = '0.5';

            var data = { action: action, nonce: nonce, content: content };
            var editId = document.getElementById('kb-edit-knowledge-id');
            if (editId) data.knowledge_id = editId.value;

            $.post(ajax, data, function (res) {
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
