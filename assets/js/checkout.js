/**
 * GetPayIn — classic checkout enhancements.
 *
 * Vanilla JS; no jQuery dependency. Runs only on the classic (non-blocks) checkout.
 */
(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    var GATEWAY_ID = 'paylink';

    function getSelectedPaymentMethod() {
        var checked = document.querySelector('input[name="payment_method"]:checked');
        return checked ? checked.value : '';
    }

    function togglePaymentInfo() {
        var info = document.querySelectorAll('.getpayin-payment-info');
        var visible = getSelectedPaymentMethod() === GATEWAY_ID;
        for (var i = 0; i < info.length; i++) {
            info[i].style.display = visible ? '' : 'none';
        }
    }

    function onReady() {
        document.addEventListener('change', function (event) {
            if (event.target && event.target.name === 'payment_method') {
                togglePaymentInfo();
            }
        });
        togglePaymentInfo();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
