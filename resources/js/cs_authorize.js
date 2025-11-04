
$(document).ready(function () {
    const cardNumberInput = $('#cardNumber');
    const expDateInput = $('#expDate');
    const cvcInput = $('#cvc');

    const googleContainer = $("#google_pay_container");

    if (!cardNumberInput.data('formattingAttached')) {
        cardNumberInput.on('input', function() {
            let value = $(this).val().replace(/\D/g, ''); // remove non-digits
            value = (value.match(/.{1,4}/g) || []).join(' ');
            $(this).val(value.slice(0, 23)); // limit to max 23 chars
        });

        cardNumberInput.data('formattingAttached', true);
    }

    if (!expDateInput.data('formattingAttached')) {
        expDateInput.on('input', function() {
            let value = $(this).val().replace(/\D/g, ''); // remove non-digits

            if (value.length > 2) {
                value = value.slice(0, 2) + '/' + value.slice(2, 4);
            }

            $(this).val(value.slice(0, 5)); // MM/YY format (max length 5)
        });

        expDateInput.data('formattingAttached', true);
    }

    $("#btnSubmit").click(function (e) {
        e.preventDefault();

        const $btn = $(this);
        const $spinner = $("#btnSpinner");
        const $text = $("#btnText");

        $btn.prop("disabled", true).addClass("opacity-70 cursor-not-allowed");
        $text.text("Processing...");
        $spinner.removeClass("hidden");

        return new Promise((resolve) => {
            const cardNumber = cardNumberInput.val().replace(/\s/g, '');
            const expDate = expDateInput.val().trim();
            const cvc = cvcInput.val().trim();
            const $errorDiv = $('#paymentErrors');


            if (!/^\d{13,19}$/.test(cardNumber)) {
                $errorDiv
                    .text('Invalid card number. Please enter a valid card number (13-19 digits).')
                    .show();

                // Re-enable button and hide spinner
                $btn.prop("disabled", false).removeClass("opacity-70 cursor-not-allowed");
                $text.text("PAY NOW");
                $spinner.addClass("hidden");
                return;
            }

            if (!/^\d{2}\/\d{2}$/.test(expDate)) {
                $errorDiv
                    .text('Invalid expiration date. Please use MM/YY format.')
                    .show();

                // Re-enable button and hide spinner
                $btn.prop("disabled", false).removeClass("opacity-70 cursor-not-allowed");
                $text.text("PAY NOW");
                $spinner.addClass("hidden");
                return;
            }

            const [month, year] = expDate.split('/');
            const currentDate = new Date();
            const currentYear = currentDate.getFullYear() % 100; // get last two digits
            const currentMonth = currentDate.getMonth() + 1;
            const inputMonth = parseInt(month, 10);
            const inputYear = parseInt(year, 10);

            // ---- Validate Month ----
            if (inputMonth < 1 || inputMonth > 12) {
                if ($errorDiv.length) {
                    $errorDiv
                        .text('Invalid month. Please enter a month between 01 and 12.')
                        .show();
                }
                resolve({ error: 'Invalid month' });
                // Re-enable button and hide spinner
                $btn.prop("disabled", false).removeClass("opacity-70 cursor-not-allowed");
                $text.text("PAY NOW");
                $spinner.addClass("hidden");
                return;
            }

            // ---- Validate Expiration Date ----
            if (inputYear < currentYear || (inputYear === currentYear && inputMonth < currentMonth)) {
                if ($errorDiv.length) {
                    $errorDiv
                        .text('Card has expired. Please use a valid expiration date.')
                        .show();
                }
                resolve({ error: 'Card has expired' });
                // Re-enable button and hide spinner
                $btn.prop("disabled", false).removeClass("opacity-70 cursor-not-allowed");
                $text.text("PAY NOW");
                $spinner.addClass("hidden");
                return;
            }

            // ---- Validate CVC ----
            if (!/^\d{3,4}$/.test(cvc)) {
                if ($errorDiv.length) {
                    $errorDiv
                        .text('Invalid CVC. Please enter a 3- or 4-digit code.')
                        .show();
                }
                resolve({ error: 'Invalid CVC' });
                // Re-enable button and hide spinner
                $btn.prop("disabled", false).removeClass("opacity-70 cursor-not-allowed");
                $text.text("PAY NOW");
                $spinner.addClass("hidden");
                return;
            }

            var secureData = {
                authData: {
                    apiLoginID: $("meta[name='auth_login_id']").attr("content"),
                    clientKey: $("meta[name='client_key']").attr("content")
                },
                cardData: {
                    cardNumber: cardNumber,
                    month: month,
                    year: '20' + year,
                    cardCode: cvc
                }
            };

            Accept.dispatchData(secureData, function(response) {
                console.log('main_response', response)

                if (response.messages.resultCode === 'Error') {
                    const response = JSON.stringify({
                        status: "error",
                        message: response.messages.message?.[0]?.text || 'Payment system configuration error. Please contact support.',
                    });

                    document.title = response;

                    // Re-enable button and hide spinner
                    $btn.prop("disabled", false).removeClass("opacity-70 cursor-not-allowed");
                    $text.text("PAY NOW");
                    $spinner.addClass("hidden");
                    console.log('errorMessage', errorMessage)
                } else {
                    const encodedNonce = window.btoa(response.opaqueData.dataValue);

                    cardNumberInput.value = '';
                    expDateInput.value = '';
                    cvcInput.value = '';

                    const _response = JSON.stringify({
                        status: "success",
                        data: {
                            token: encodedNonce,
                            descriptor: response.opaqueData.dataDescriptor
                        },
                    });
                    console.log('response', _response)
                    document.title = _response;
                }
            });
        });
    });

    if (googleContainer.length)
    {
        const baseRequest = {
            apiVersion: 2,
            apiVersionMinor: 0
        };
        const baseCardPaymentMethod = {
            type: 'CARD',
            parameters: {
                allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                allowedCardNetworks: ['AMEX', 'DISCOVER', 'MASTERCARD', 'VISA']
            },
            tokenizationSpecification: {
                type: 'PAYMENT_GATEWAY',
                parameters: {
                    gateway: $("meta[name='user_gateway']").attr("content"),
                    gatewayMerchantId: $("meta[name='user_gateway_merchant_id']").attr("content")
                }
            }
        };
        const paymentsClient = new window.google.payments.api.PaymentsClient({
            environment: 'TEST'
        });
        const isReadyToPayRequest = {
            ...baseRequest,
            allowedPaymentMethods: [baseCardPaymentMethod]
        };

        paymentsClient.isReadyToPay(isReadyToPayRequest)
            .then((response) => {
                if (response.result) {
                    onGooglePayLoaded();
                } else {
                    document.getElementById('google-pay-button-{$instanceId}').innerHTML = 'Google Pay not available';
                }
            })
            .catch((err) => {
                document.getElementById('google-pay-error-{$instanceId}').innerText = 'Google Pay unavailable: ' + err.message;
                document.getElementById('google-pay-error-{$instanceId}').style.display = 'block';
            });
    }

    function onGooglePayLoaded() {
        const paymentsClient = new window.google.payments.api.PaymentsClient({
            environment: 'TEST'
        });;
        const buttonContainer = googleContainer;
        const button = paymentsClient.createButton({
            onClick: () => onGooglePayClicked(paymentsClient),
            buttonType: 'buy',
            buttonColor: 'black',
            buttonSizeMode: 'fill',
            buttonRadius: 4
        });

        buttonContainer.empty().append(button);
    }
});
