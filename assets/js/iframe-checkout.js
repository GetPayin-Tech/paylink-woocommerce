/**
 * GetPayIn — embedded (iframe) checkout listener.
 *
 * Runs on the order-pay (receipt) page when Embedded checkout is enabled. Reads the
 * container's data attributes, then listens for the signed `paylink_payment` message
 * the hosted checkout posts on completion and moves the top window to the success or
 * failure URL. Messages are accepted only from the configured GetPayIn origin, so a
 * foreign frame cannot spoof an outcome.
 *
 * Vanilla JS; no jQuery dependency. No inline JS is emitted, so the page stays CSP-safe.
 */
(function () {
    'use strict';

    var el = document.getElementById('getpayin-embedded-checkout');
    if (!el) {
        return;
    }

    var origin = el.getAttribute('data-origin');
    var successUrl = el.getAttribute('data-success-url');
    var failUrl = el.getAttribute('data-fail-url');

    window.addEventListener('message', function (e) {
        if (origin && e.origin !== origin) {
            return;
        }

        var d = e.data;
        if (!d || typeof d !== 'object' || d.type !== 'paylink_payment') {
            return;
        }

        var url = d.success ? successUrl : failUrl;
        try {
            if (url) {
                window.top.location.href = url;
            }
        } catch (ex) {
            if (url) {
                window.location.href = url;
            }
        }
    });
})();
