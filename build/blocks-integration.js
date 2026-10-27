/**
 * GetPayIn Blocks Integration JavaScript
 *
 * Renders the GetPayIn option in the block checkout: the merchant's title plus the
 * same wallet / card logo strip the classic checkout shows, and — once selected —
 * the description and what happens after the order is placed.
 */

(function () {
    'use strict';

    // Check if WooCommerce blocks are available
    if (typeof wc === 'undefined' || !wc.wcBlocksRegistry || !wc.wcSettings) {
        return;
    }

    var el = wp.element.createElement;
    var decodeEntities = (wp.htmlEntities && wp.htmlEntities.decodeEntities) || function (value) {
        return value;
    };
    var translate = (wp.i18n && wp.i18n.__) || function (value) {
        return value;
    };

    // Get payment method data
    var settings = wc.wcSettings.getSetting('paylink_data', {});

    var title = decodeEntities(settings.title || translate('Credit card or wallet', 'paylink-woocommerce'));
    var description = decodeEntities(settings.description || '');
    var notice = decodeEntities(settings.notice || '');
    var icons = Array.isArray(settings.icons) ? settings.icons : [];

    function logoStrip() {
        if (!icons.length) {
            return null;
        }

        return el(
            'span',
            {
                className: 'getpayin-payment-logos getpayin-payment-logos--inline',
                role: 'list',
                'aria-label': translate('Supported payment methods', 'paylink-woocommerce')
            },
            icons.map(function (icon) {
                return el('img', {
                    key: icon.id,
                    src: icon.src,
                    alt: decodeEntities(icon.alt || ''),
                    className: icon['class'] || '',
                    role: 'listitem'
                });
            })
        );
    }

    function Label() {
        return el(
            'span',
            { className: 'getpayin-blocks-label' },
            el('span', { className: 'getpayin-blocks-title' }, title),
            logoStrip()
        );
    }

    function Content() {
        return el(
            'div',
            { className: 'getpayin-payment-content' },
            description ? el('p', { className: 'getpayin-blocks-description' }, description) : null,
            notice ? el('p', { className: 'getpayin-blocks-notice' }, notice) : null
        );
    }

    // Register the payment method
    wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'paylink',
        label: el(Label, null),
        content: el(Content, null),
        edit: el(Content, null),
        canMakePayment: function () {
            return true;
        },
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
