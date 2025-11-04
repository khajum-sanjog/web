$(document).ready(function () {
    const publishableKey = $("meta[name='publishable_key']").attr("content");
    const stripe = Stripe(publishableKey);
    const elements = stripe.elements();
    const amount = $("meta[name='final_amt']").attr("content")
    const store = $("meta[name='store']").attr("content")

    // Load Stripe Elements
    const card = elements.create("cardNumber", {
        placeholder: "**** **** **** ****",
    });
    const cardExpiryElement = elements.create("cardExpiry");
    const cardCvcElement = elements.create("cardCvc");
    const googleContainer = $("#google_pay_container");

    // Mount Stripe Elements
    card.mount("#card-element");
    cardExpiryElement.mount("#card-expiry");
    cardCvcElement.mount("#card-cvc");

    // Handle form submission
    $("#btnSubmit").click(function (e) {
        e.preventDefault();

        const $btn = $(this);
        const $spinner = $("#btnSpinner");
        const $text = $("#btnText");

        $btn.prop("disabled", true).addClass("opacity-70 cursor-not-allowed");
        $text.text("Processing...");
        $spinner.removeClass("hidden");

        stripe.createToken(card).then((result) => {
            if (result.error) {
                // $("#card-errors").html(result.error.message).show();

                const response = JSON.stringify({
                    status: "error",
                    message: result.error.message,
                });

                document.title = response;
                console.log(result.error);

                // Re-enable button and hide spinner
                $btn.prop("disabled", false).removeClass("opacity-70 cursor-not-allowed");
                $text.text("PAY NOW");
                $spinner.addClass("hidden");
            } else {
                const response = JSON.stringify({
                    status: "success",
                    data: {
                        token: btoa(result.token.id),
                    },
                });
                document.title = response;
            }
        });
    });

    // Initialize Google Pay if container exists
    if (googleContainer.length) {
        onGooglePayLoaded();
    }

    // Load Google Pay Button and initialize
    function onGooglePayLoaded() {
        const paymentsClient = new google.payments.api.PaymentsClient({ environment: "TEST" });

        const button = paymentsClient.createButton({
            buttonColor: 'default',
            buttonType: 'pay',
            buttonRadius: 5,
            buttonSizeMode: 'fill',
            onClick: onGooglePaymentButtonClicked
        });

        // Prevent adding the button multiple times
        if (googleContainer.find('button').length === 0) {
            googleContainer.empty().append(button);
        } else {
            console.log('Google Pay button already exists');
        }
    }

    // Prepare request for Google Pay API
    function getRequest() {
        return {
            apiVersion: 2,
            apiVersionMinor: 0,
            allowedPaymentMethods: [{
                type: 'CARD',
                parameters: {
                    allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                    allowedCardNetworks: ['VISA', 'MASTERCARD', 'AMEX', 'DISCOVER', 'INTERAC', 'JCB'],
                },
                tokenizationSpecification: {
                    type: 'PAYMENT_GATEWAY',
                    parameters: {
                        'gateway': 'stripe',
                        'stripe:version': '2020-08-27',
                        'stripe:publishableKey': publishableKey
                    }
                }
            }],
            transactionInfo: {
                totalPriceStatus: 'FINAL',
                totalPrice: amount,
                currencyCode: 'USD',
                countryCode: 'US'
            },
            merchantInfo: {
                merchantName: store,
            }
        };
    }

    // Handle Google Pay Button click
    async function onGooglePaymentButtonClicked(event) {
        if (event) event.preventDefault();

        const paymentDataRequest = getRequest();
        const paymentsClient = new google.payments.api.PaymentsClient({ environment: "TEST" });
        try {
            const paymentData = await paymentsClient.loadPaymentData(paymentDataRequest);
            const token = JSON.parse(paymentData.paymentMethodData.tokenizationData.token);

            if (token) {
                const response = JSON.stringify({
                    status: "success",
                    data: {
                        token: btoa(token.id),
                    },
                });
                document.title = response;

            }
        } catch (error) {
            console.info(error);
        }
    }
});
