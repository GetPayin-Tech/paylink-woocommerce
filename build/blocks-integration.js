/**
 * GetPayIn Blocks Integration JavaScript
 */

(function() {
    'use strict';

    // Check if WooCommerce blocks are available
    if (typeof wc === 'undefined' || !wc.wcBlocksRegistry) {
        console.warn('GetPayIn: WooCommerce blocks not available');
        return;
    }

    // Get payment method data
    const settings = wc.wcSettings.getSetting('paylink_data', {});
    
    // Create payment method object
    const GetPayInPaymentMethod = {
        name: 'paylink',
        label: wp.element.createElement('span', null, settings.title || 'Apple Pay / Google Pay / Visa / Mastercard'),
        content: wp.element.createElement('div', {
            className: 'getpayin-payment-content'
        }, wp.element.createElement('p', null, settings.description || 'You will be redirected to GetPayIn to complete your payment.')),
        edit: wp.element.createElement('div', {
            className: 'getpayin-payment-edit'
        }, 'GetPayIn'),
        canMakePayment: function() {
            return true;
        },
        ariaLabel: settings.title || 'GetPayIn payment method',
        supports: {
            features: settings.supports || ['products']
        }
    };

    // Register the payment method
    wc.wcBlocksRegistry.registerPaymentMethod(GetPayInPaymentMethod);

    console.log('GetPayIn: Payment method registered with blocks');

})();
