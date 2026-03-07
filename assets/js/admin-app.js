document.addEventListener('DOMContentLoaded', () => {
    
    // --- State & Elements ---
    const root = document.getElementById('sonoai-admin-app-root');
    const themeToggle = document.getElementById('theme-toggle');
    const navItems = document.querySelectorAll('.nav-item');
    const views = document.querySelectorAll('.sonoai-view');
    const pageTitle = document.getElementById('sonoai-page-title');

    // --- Theme Toggling ---
    // Load saved theme or default to light
    const savedTheme = localStorage.getItem('sonoai_theme') || 'light';
    root.setAttribute('data-theme', savedTheme);

    themeToggle.addEventListener('click', () => {
        const currentTheme = root.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', newTheme);
        localStorage.setItem('sonoai_theme', newTheme);
    });

    // --- Navigation ---
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = item.getAttribute('data-target');
            
            // Update active nav
            navItems.forEach(n => n.classList.remove('active'));
            item.classList.add('active');
            
            // Update page title
            pageTitle.textContent = item.textContent.trim();

            // Switch view
            views.forEach(v => v.classList.remove('active-view'));
            document.getElementById(`view-${targetId}`).classList.add('active-view');
        });
    });

    // --- API Configuration Form ---
    const apiConfigForm = document.getElementById('api-config-form');
    const activeProviderSelect = document.getElementById('active_provider');
    const providerSettingsBlocks = document.querySelectorAll('.provider-settings');

    // Toggle provider settings visibility
    activeProviderSelect.addEventListener('change', () => {
        const selected = activeProviderSelect.value;
        providerSettingsBlocks.forEach(block => {
            if (block.id === `settings-${selected}`) {
                block.style.display = 'block';
            } else {
                block.style.display = 'none';
            }
        });
    });

    // Populate existing options
    if (typeof sonoai_admin_vars !== 'undefined' && sonoai_admin_vars.options) {
        const opts = sonoai_admin_vars.options;
        
        // Active Provider
        if (opts.active_provider) {
            activeProviderSelect.value = opts.active_provider;
            activeProviderSelect.dispatchEvent(new Event('change'));
        }

        // Fill inputs
        const inputs = document.querySelectorAll('.sonoai-form input, .sonoai-form select, .sonoai-form textarea');
        inputs.forEach(input => {
            if (input.name && opts[input.name] !== undefined) {
                if (input.type === 'checkbox') {
                    input.checked = opts[input.name] == '1' || opts[input.name] === true;
                } else {
                    input.value = opts[input.name];
                }
            }
        });
    }

    // Handle forms submission
    const handleFormSubmit = async (e, endpoint) => {
        e.preventDefault();
        const form = e.target;
        const msgSpan = form.querySelector('.form-msg');
        const btn = form.querySelector('button[type="submit"]');
        
        msgSpan.className = 'form-msg'; // reset
        msgSpan.textContent = sonoai_admin_vars.i18n.saving;
        btn.disabled = true;

        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        
        // Handle checkboxes (fix missing un-checked values)
        form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            data[cb.name] = cb.checked ? '1' : '0';
        });

        try {
            const res = await fetch(`${sonoai_admin_vars.rest_url}${endpoint}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': sonoai_admin_vars.nonce
                },
                body: JSON.stringify(data)
            });

            const result = await res.json();
            if (res.ok) {
                msgSpan.classList.add('success');
                msgSpan.textContent = sonoai_admin_vars.i18n.saved;
            } else {
                throw new Error(result.message || 'Error saving');
            }
        } catch (err) {
            msgSpan.classList.add('error');
            msgSpan.textContent = err.message;
        } finally {
            btn.disabled = false;
            setTimeout(() => { if(msgSpan.classList.contains('success')) msgSpan.textContent = ''; }, 3000);
        }
    };

    if (apiConfigForm) {
        apiConfigForm.addEventListener('submit', (e) => handleFormSubmit(e, 'settings'));
    }

    const generalSettingsForm = document.getElementById('general-settings-form');
    if (generalSettingsForm) {
        generalSettingsForm.addEventListener('submit', (e) => handleFormSubmit(e, 'settings'));
    }

    const dangerSettingsForm = document.getElementById('danger-settings-form');
    if (dangerSettingsForm) {
        dangerSettingsForm.addEventListener('submit', (e) => handleFormSubmit(e, 'settings'));
    }


    // --- Knowledge Base Actions ---
    const kbBtns = document.querySelectorAll('.kb-add-btn');
    kbBtns.forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const source = btn.getAttribute('data-source');
            const originalText = btn.textContent;
            
            btn.textContent = 'Processing...';
            btn.disabled = true;

            let payload = {};
            let endpoint = `kb/${source}`;

            if (source === 'wp') {
                const postId = document.getElementById('kb-wp-select').value;
                if (!postId) {
                    alert('Please select a post first.');
                    btn.textContent = originalText;
                    btn.disabled = false;
                    return;
                }
                payload.post_id = postId;
            } 
            else if (source === 'url') {
                const url = document.getElementById('kb-url-input').value;
                if (!url) {
                    alert('Please enter a URL.');
                    btn.textContent = originalText;
                    btn.disabled = false;
                    return;
                }
                payload.url = url;
            }
            else if (source === 'text') {
                const title = document.getElementById('kb-text-title').value;
                const content = document.getElementById('kb-text-content').value;
                if (!content) {
                    alert('Please enter some text content.');
                    btn.textContent = originalText;
                    btn.disabled = false;
                    return;
                }
                payload.title = title || 'Custom Text';
                payload.content = content;
            }

            try {
                const res = await fetch(`${sonoai_admin_vars.rest_url}${endpoint}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': sonoai_admin_vars.nonce
                    },
                    body: JSON.stringify(payload)
                });

                const result = await res.json();
                if (res.ok) {
                    alert(result.message || 'Successfully added to Knowledge Base!');
                    // Clear inputs
                    if (source === 'url') document.getElementById('kb-url-input').value = '';
                    if (source === 'text') {
                        document.getElementById('kb-text-title').value = '';
                        document.getElementById('kb-text-content').value = '';
                    }
                } else {
                    throw new Error(result.message || 'Failed to add to Knowledge Base.');
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                btn.textContent = originalText;
                btn.disabled = false;
            }
        });
    });


    // --- PDF Upload handling ---
    const pdfDropArea = document.getElementById('kb-pdf-drop');
    const pdfInput = document.getElementById('kb-pdf-file');

    if (pdfInput && pdfDropArea) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            pdfDropArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults (e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            pdfDropArea.addEventListener(eventName, () => pdfDropArea.style.background = 'var(--sono-bg)', false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            pdfDropArea.addEventListener(eventName, () => pdfDropArea.style.background = '', false);
        });

        pdfDropArea.addEventListener('drop', handleDrop, false);
        pdfInput.addEventListener('change', (e) => handleFiles(e.target.files), false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }

        async function handleFiles(files) {
            if (!files.length) return;
            const file = files[0];
            
            if (file.type !== 'application/pdf') {
                alert('Please upload a valid PDF file.');
                return;
            }

            const formData = new FormData();
            formData.append('pdf', file);

            const fileMsg = pdfDropArea.querySelector('.file-msg');
            const originalMsg = fileMsg.textContent;
            fileMsg.textContent = 'Uploading & Extracting...';

            try {
                const res = await fetch(`${sonoai_admin_vars.rest_url}kb/pdf`, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': sonoai_admin_vars.nonce
                    },
                    body: formData // Content-Type is auto set by FormData
                });

                const result = await res.json();
                if (res.ok) {
                    alert(result.message || 'PDF successfully added to Knowledge Base!');
                } else {
                    throw new Error(result.message || 'Failed to process PDF.');
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                fileMsg.textContent = originalMsg;
                pdfInput.value = ''; // reset
            }
        }
    }

});
