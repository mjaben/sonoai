/**
 * SonoAI — API Configuration page JS
 * Handles: provider switching, eye-toggle (reveals actual key), dark/light mode.
 */
(function () {
    'use strict';

    var THEME_KEY = 'sonoai_api_config_theme';

    document.addEventListener('DOMContentLoaded', function () {
        var wrap     = document.querySelector('.sac-wrap');
        var provSel  = document.getElementById('sac-provider-select');
        var themeBtn = document.getElementById('sac-theme-toggle');

        if (!wrap || !provSel) return;

        // ── Restore saved dark/light preference ──────────────────────────────
        if (localStorage.getItem(THEME_KEY) === 'dark') {
            wrap.classList.add('sac-dark');
        }

        // ── Theme toggle ──────────────────────────────────────────────────────
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                var isDark = wrap.classList.toggle('sac-dark');
                localStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light');
            });
        }

        // ── Provider switch ───────────────────────────────────────────────────
        function applyProvider(prov) {
            document.querySelectorAll('.sac-key-group').forEach(function (el) {
                el.style.display = (el.dataset.provider === prov) ? '' : 'none';
            });
            document.querySelectorAll('.sac-chat-model-group').forEach(function (el) {
                el.style.display = (el.dataset.provider === prov) ? '' : 'none';
            });
            document.querySelectorAll('.sac-embed-model-group').forEach(function (el) {
                el.style.display = (el.dataset.provider === prov) ? '' : 'none';
            });
        }

        applyProvider(provSel.value);

        provSel.addEventListener('change', function () {
            applyProvider(this.value);
        });

        // ── Eye toggle — reveals the actual stored API key ────────────────────
        // Bug fix: input value is always empty to avoid exposing the key in HTML source.
        // On reveal, we read the actual key from data-key on the .sac-input-wrap and
        // populate the input value so it becomes visible. On hide, we clear value back
        // to empty — the sanitize callback only updates the key if a new non-empty value
        // is submitted, so clearing keeps the existing stored key intact.
        document.querySelectorAll('.sac-eye-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = this.dataset.target;
                var input    = document.getElementById(targetId);
                if (!input) return;

                var inputWrap  = input.closest('.sac-input-wrap');
                var actualKey  = (inputWrap && inputWrap.dataset.key) ? inputWrap.dataset.key : '';
                var isHidden   = (input.type === 'password');
                var eyeOn      = this.querySelector('.sac-eye-icon');
                var eyeOff     = this.querySelector('.sac-eye-off-icon');

                if (isHidden) {
                    // Reveal
                    input.type        = 'text';
                    input.value       = actualKey;          // show the real key
                    input.placeholder = '';                 // clear placeholder
                    if (eyeOn)  eyeOn.style.display  = 'none';
                    if (eyeOff) eyeOff.style.display  = 'block';
                    this.title = 'Hide key';
                } else {
                    // Hide
                    input.type        = 'password';
                    input.value       = '';                 // clear — don't re-submit unchanged key
                    input.placeholder = actualKey
                        ? input.dataset.maskedPlaceholder || '••••••••••••'
                        : 'Enter API key\u2026';
                    if (eyeOn)  eyeOn.style.display  = 'block';
                    if (eyeOff) eyeOff.style.display = 'none';
                    this.title = 'Show key';
                }
            });
        });

        document.querySelectorAll('.sac-input').forEach(function (input) {
            input.dataset.maskedPlaceholder = input.placeholder;
        });

        // ── Redis Sync ───────────────────────────────────────────────────────
        var redisSyncBtn = document.getElementById('sac-redis-sync-btn');
        if (redisSyncBtn) {
            redisSyncBtn.addEventListener('click', function () {
                var btn      = this;
                var btnText  = btn.querySelector('.sac-btn-text');
                var spinner  = btn.querySelector('.sac-spinner');
                var oldText  = btnText.innerText;

                if (btn.disabled) return;
                if (!confirm('Are you sure you want to rebuild the Redis index? This will push all current knowledge base items from MySQL into Redis.')) return;

                btn.disabled = true;
                btnText.innerText = 'Syncing...';
                if (spinner) spinner.style.display = 'block';

                jQuery.post(ajaxurl, {
                    action: 'sonoai_kb_rebuild_redis',
                    security: (typeof sonoai_vars !== 'undefined') ? sonoai_vars.nonce : '' // Corrected key to 'security'
                }, function (response) {
                    btn.disabled = false;
                    btnText.innerText = oldText;
                    if (spinner) spinner.style.display = 'none';

                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error.'));
                    }
                }).fail(function () {
                    btn.disabled = false;
                    btnText.innerText = oldText;
                    if (spinner) spinner.style.display = 'none';
                    alert('Request failed. Check your server connection.');
                });
            });
        }

        // ── Redis Toggle ──────────────────────────────────────────────────────
        var redisToggle = document.querySelector('input[name="sonoai_settings[redis_enabled]"]');
        var redisDetails = document.querySelector('.sac-redis-details');
        if (redisToggle && redisDetails) {
            redisToggle.addEventListener('change', function () {
                redisDetails.style.display = this.checked ? 'block' : 'none';
            });
        }
    });
}());
