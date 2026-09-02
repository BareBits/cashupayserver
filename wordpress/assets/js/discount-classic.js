/**
 * BareBits plugin — classic (shortcode) checkout helper.
 *
 * WooCommerce core's checkout.js only shows/hides the gateway description
 * when the payment method changes; totals are not recalculated until an
 * address field edit triggers update_checkout. The Bitcoin discount is a
 * payment-method-driven fee, so ask for the recalculation ourselves — the
 * update_order_review AJAX behind update_checkout stores the selected
 * method in the session, where the fee hook reads it.
 * License: GPLv2 or later.
 */
jQuery(function ($) {
    $(document.body).on('change', 'form.checkout input[name="payment_method"]', function () {
        $(document.body).trigger('update_checkout');
    });
});
