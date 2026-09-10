/* Stripe Payment Element used only by the payment module. Author: Khor Zhi Hong */

(function () {
    'use strict';

    function setUpStripePayment() {
        var form = document.getElementById('stripePaymentForm');

        if (!form || typeof window.Stripe !== 'function') {
            return;
        }

        var stripe = window.Stripe(form.getAttribute('data-publishable-key'));
        var elements = stripe.elements({
            clientSecret: form.getAttribute('data-client-secret')
        });
        var paymentElement = elements.create('payment');
        var button = document.getElementById('stripePaymentButton');
        var message = document.getElementById('stripePaymentMessage');

        paymentElement.mount('#stripePaymentElement');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            button.disabled = true;
            message.textContent = 'Submitting payment...';

            stripe.confirmPayment({
                elements: elements,
                confirmParams: {
                    return_url: form.getAttribute('data-return-url')
                }
            }).then(function (result) {
                if (result.error) {
                    message.textContent = result.error.message || 'Payment could not be completed.';
                    button.disabled = false;
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setUpStripePayment);
    } else {
        setUpStripePayment();
    }
}());
