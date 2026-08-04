/**
 * GetPayIn — admin settings UI behaviours.
 *
 * - Active-environment toggle (Test / Live) with full keyboard support.
 * - Copy-to-clipboard for any element with data-paylink-copy.
 * - Status feedback that respects prefers-reduced-motion.
 *
 * Vanilla JS, no dependencies. Loaded on the WC settings checkout tab only.
 */
(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    var prefersReducedMotion = (function () {
        if (typeof window.matchMedia !== 'function') return false;
        try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
        catch (e) { return false; }
    })();

    var COPY_FEEDBACK_MS = prefersReducedMotion ? 800 : 1600;

    /* -------- Mode toggle -------- */
    function initModeToggle() {
        var panel = document.querySelector('.paylink-mode-panel');
        if (!panel) return;

        var toggles = panel.querySelectorAll('.paylink-toggle-option');
        var cards   = panel.querySelectorAll('.paylink-creds-card');
        var hidden  = panel.querySelector('input[type="hidden"][name$="testmode"]');

        if (!toggles.length || !cards.length || !hidden) return;

        function activate(mode, focus) {
            for (var i = 0; i < toggles.length; i++) {
                var isActive = toggles[i].getAttribute('data-mode') === mode;
                toggles[i].classList.toggle('is-active', isActive);
                toggles[i].setAttribute('aria-checked', isActive ? 'true' : 'false');
                if (focus && isActive) {
                    toggles[i].focus();
                }
            }
            for (var j = 0; j < cards.length; j++) {
                var match = cards[j].getAttribute('data-mode') === mode;
                cards[j].classList.toggle('is-active', match);
            }
            hidden.value = mode === 'test' ? 'yes' : 'no';
            panel.setAttribute('data-active-mode', mode);
        }

        for (var k = 0; k < toggles.length; k++) {
            toggles[k].addEventListener('click', function (event) {
                event.preventDefault();
                var target = event.currentTarget.getAttribute('data-mode');
                if (target) activate(target, false);
            });

            toggles[k].addEventListener('keydown', function (event) {
                var key = event.key;
                if (key !== 'ArrowLeft' && key !== 'ArrowRight' &&
                    key !== 'Home' && key !== 'End') {
                    return;
                }
                event.preventDefault();
                var idx = -1;
                for (var i = 0; i < toggles.length; i++) {
                    if (toggles[i] === event.currentTarget) { idx = i; break; }
                }
                if (idx === -1) return;
                var next = idx;
                if (key === 'ArrowLeft')  next = idx === 0 ? toggles.length - 1 : idx - 1;
                if (key === 'ArrowRight') next = idx === toggles.length - 1 ? 0 : idx + 1;
                if (key === 'Home')       next = 0;
                if (key === 'End')        next = toggles.length - 1;
                var t = toggles[next].getAttribute('data-mode');
                if (t) activate(t, true);
            });
        }
    }

    /* -------- Clipboard -------- */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.setAttribute('aria-hidden', 'true');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                ta.style.pointerEvents = 'none';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                resolve();
            } catch (e) {
                reject(e);
            }
        });
    }

    function flashCopyButton(button) {
        var label = button.querySelector('.paylink-copy-label');
        var icon  = button.querySelector('.dashicons');
        var origLabel = label ? label.textContent : null;
        var origIcon  = icon ? icon.className : null;

        button.classList.add('is-copied');
        button.setAttribute('aria-live', 'polite');
        if (label) label.textContent = 'Copied!';
        if (icon)  icon.className = 'dashicons dashicons-yes';

        clearTimeout(button.__plCopyTimer);
        button.__plCopyTimer = setTimeout(function () {
            button.classList.remove('is-copied');
            if (label && origLabel !== null) label.textContent = origLabel;
            if (icon  && origIcon  !== null) icon.className   = origIcon;
        }, COPY_FEEDBACK_MS);
    }

    function flashInline(el) {
        el.classList.add('is-copied');
        clearTimeout(el.__plCopyTimer);
        el.__plCopyTimer = setTimeout(function () {
            el.classList.remove('is-copied');
        }, COPY_FEEDBACK_MS);
    }

    function initClipboard() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest && event.target.closest('[data-paylink-copy]');
            if (!trigger) return;
            event.preventDefault();
            var text = trigger.getAttribute('data-paylink-copy');
            if (!text) return;

            copyText(text).then(function () {
                if (trigger.classList.contains('paylink-copy-btn')) {
                    flashCopyButton(trigger);
                } else {
                    flashInline(trigger);
                }
            }).catch(function () {
                // Fallback: if a sibling readonly input exists, focus + select for manual copy.
                var sibling = trigger.parentNode &&
                    trigger.parentNode.querySelector('[data-paylink-copy-source]');
                if (sibling && typeof sibling.select === 'function') {
                    sibling.focus();
                    sibling.select();
                }
            });
        });
    }

    function init() {
        initModeToggle();
        initClipboard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
