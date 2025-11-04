<?php

namespace App\Http\Controllers;

use App\Models\UserPaymentGateway;
use App\Services\ValidationService;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeController extends Controller
{
    use ResponseTrait;

    protected $validationService;

    public function __construct(ValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Loads and configures the payment gateway form for the authenticated user.
     *
     * This method retrieves the active payment gateway for the authenticated user,
     * validates its status, and generates the necessary UI data for supported
     * payment methods (card, Google Pay, Apple Pay) based on the gateway type
     * (Authorize.net or Stripe).
     *
     * @return \Illuminate\Http\JsonResponse JSON response containing UI data for payment methods
     *                                       or an error response if validation fails
     */
    public function authorizeFormLoader(Request $request)
    {
        $validationResult = $this->validationService->validations($request);
        if (! is_array($validationResult)) {
            return $validationResult; // This would be an error response
        }

        extract($validationResult); // This will create $credentials, $paymentSettings, $amount, etc.

        try {
            $uiData = [];
            $errors = [];

            if ($request->has('requested_payment_forms')) {
                $requestedForms = $request->requested_payment_forms;
                $validMethods = ['card_pay', 'google_pay', 'apple_pay', 'pos_pay'];
                $invalidMethods = array_diff($requestedForms, $validMethods);

                // Add invalid methods to errors instead of returning immediately
                foreach ($invalidMethods as $invalidMethod) {
                    $errors[$invalidMethod] = "Payment method not supported for $invalidMethod";
                }

                foreach (array_intersect($requestedForms, $validMethods) as $method) {
                    try {
                        switch ($method) {
                            case 'card_pay':
                                if (isset($paymentSettings['has_card_pay']) && $paymentSettings['has_card_pay'] === '1') {
                                    $cardUI = $this->_authNetCardUI(
                                        $credentials['login_id'] ?? '',
                                        $credentials['client_key'] ?? '',
                                        $amount,
                                        $attributes,
                                        $gateway['is_live_mode']
                                    );
                                    $uiData['card'] = array_merge($cardUI, ['method' => 'card']);
                                }
                                break;
                            case 'google_pay':
                                if (isset($paymentSettings['has_google_pay']) && $paymentSettings['has_google_pay'] === '1') {
                                    $googlePayUI = $this->_googlePayUI(
                                        $credentials['gMerchant_id'],
                                        $credentials['paymentGateway_id'],
                                        'authorizenet',
                                        $amount,
                                        $authenticatedUser['store_name'],
                                        $attributes
                                    );
                                    $uiData['google_pay'] = array_merge($googlePayUI, ['method' => 'googlepay']);
                                }
                                break;
                            case 'apple_pay':
                                if (isset($paymentSettings['has_apple_pay']) && $paymentSettings['has_apple_pay'] === '1') {
                                    $appleUI = $this->_applePayUI(
                                        $credentials['aMerchant_id'] ?? '',
                                        $credentials['certificate_path'] ?? '',
                                        $amount,
                                        $authenticatedUser['store_name'],
                                        $attributes
                                    );
                                    $uiData['apple_pay'] = array_merge($appleUI, ['method' => 'applepay']);
                                }
                                break;

                            case 'pos_pay':
                                if (isset($paymentSettings['has_pos_pay']) && $paymentSettings['has_pos_pay'] === '1') {
                                    $posUI = $this->_posUI(
                                        $credentials,
                                        $amount,
                                        $attributes,
                                        $jwt_token
                                    );
                                    $uiData['pos_pay'] = array_merge($posUI, ['method' => 'pospay']);
                                }
                                break;
                        }
                    } catch (\Exception $e) {
                        $errors[$method] = $e->getMessage();
                    }
                }
                // Build response with both data and errors
                $response = ['result' => $uiData];
                if (!empty($errors)) {
                    $response['errors'] = $errors;
                }
                return response()->json($response);
            }

            if (strtolower($gateway['payment_gateway_name']) === 'authorize.net') {
                if (isset($paymentSettings['has_card_pay']) && $paymentSettings['has_card_pay'] === '1') {
                    if (empty($credentials['login_id']) || empty($credentials['client_key'])) {
                        \Log::error('Missing Authorize.net card credentials');
                        return $this->errorResponse('Missing card payment credentials.');
                    }
                    $uiData['card'] = array_merge(
                        $this->_authNetCardUI(
                            $credentials['login_id'] ?? '',
                            $credentials['client_key'] ?? '',
                            $amount,
                            $attributes,
                            $gateway['is_live_mode']
                        ),
                        ['method' => 'card']
                    );
                }
                if (isset($paymentSettings['has_google_pay']) && $paymentSettings['has_google_pay'] === '1') {
                    if (empty($credentials['gMerchant_id']) || empty($credentials['paymentGateway_id'])) {
                        \Log::error('Missing Google Pay credentials', [
                            'gMerchant_id' => $credentials['gMerchant_id'] ?? null,
                            'paymentGateway_id' => $credentials['paymentGateway_id'] ?? null,
                        ]);
                        return $this->errorResponse('Google Pay credentials missing.');
                    }
                    $uiData['google_pay'] = array_merge(
                        $this->_googlePayUI(
                            $credentials['gMerchant_id'],
                            $credentials['paymentGateway_id'],
                            'authorizenet',
                            $amount,
                            $authenticatedUser['store_name'],
                            $attributes
                        ),
                        ['method' => 'googlepay']
                    );
                }
                if (isset($paymentSettings['has_apple_pay']) && $paymentSettings['has_apple_pay'] === '1') {
                    // if (empty($credentials['aMerchant_id']) || empty($credentials['certificate_path'])) {
                    //     \Log::error('Missing Apple Pay credentials');
                    //     return $this->errorResponse('Missing Apple Pay credentials.');
                    // }
                    $uiData['apple_pay'] = array_merge(
                        $this->_applePayUI(
                            $credentials['aMerchant_id'] ?? '',
                            $credentials['certificate_path'] ?? '',
                            $amount,
                            $authenticatedUser['store_name'],
                            $attributes
                        ),
                        ['method' => 'applepay']
                    );
                }
                if (isset($paymentSettings['has_pos_pay']) && $paymentSettings['has_pos_pay'] === '1') {
                    $uiData['pos_pay'] = array_merge(
                        $this->_posUI(
                            $credentials,
                            $amount,
                            $attributes,
                            $jwt_token
                        ),
                        ['method' => 'pospay']
                    );
                }

                if (empty($uiData)) {
                    \Log::error('No supported payment methods enabled for Authorize.net');
                    return $this->errorResponse(
                        'No supported payment method enabled for Authorize.net.',
                        [],
                        Response::HTTP_NOT_FOUND
                    );
                }
            } else {
                \Log::error('Unsupported payment gateway', ['gateway' => $gateway['payment_gateway_name']]);

                return $this->errorResponse(
                    'Unsupported payment gateway.',
                    [],
                    Response::HTTP_NOT_FOUND
                );
            }

            return response()->json(['result' => $uiData]);
        } catch (\Exception $e) {
            \Log::error('Failed to load payment gateway UI', [
                'gateway' => $gateway['payment_gateway_name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse('Failed to load payment UI.', [$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generates UI components for Authorize.net card payment using AcceptUI.js.
     *
     * @param string $apiLoginId Authorize.net API login ID
     * @param string $clientKey Authorize.net client key
     * @param float $amount Payment amount
     * @param array $attributes Client-provided attributes, including CSS classes
     * @return array Array containing HTML and JavaScript for the card payment UI
     */
    protected function _authNetCardUI($apiLoginId, $clientKey, $amount, $attributes = [], $isLiveMode = '1')
    {
        $scriptUrl = $isLiveMode == '0'
            ? 'https://jstest.authorize.net/v1/Accept.js'
            : 'https://js.authorize.net/v1/Accept.js';

        $instanceId = uniqid('card_'); // Unique ID for this instance
        $classes = $attributes['classes'] ?? [];

        // Extract client-provided classes with default classes
        $containerClass = $classes['card_div'] ?? 'authnet-container';
        $inputClass = $classes['card_input'] ?? 'authnet-card';
        $labelClass = $classes['label'] ?? 'authnet-label';
        $inputWrapper = $classes['input_wrapper'] ?? '';
        $errorClass = $classes['card_error'] ?? '';

        $html = <<<HTML
        <style>
            .authnet-card.{$instanceId} { margin-bottom: 12px; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; width: 100%; max-width: 200px; outline: none; }
            .authnet-card.{$instanceId}:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5); }
            .authnet-label.{$instanceId} { display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px; }
            #paymentErrors-{$instanceId} { color: #b91c1c; margin-bottom: 12px; padding: 10px; background: #fee2e2; border-radius: 6px; display: none; max-width: 400px; }
        </style>
        <div class="{$containerClass} {$instanceId}">
            <div id="paymentErrors-{$instanceId}" class="{$errorClass}"></div>
            <div class="input-wrapper {$inputWrapper}">
                <label for="cardNumber-{$instanceId}" class="{$labelClass} {$instanceId}">Card Number</label>
                <input type="text" class="{$inputClass} {$instanceId}" id="cardNumber-{$instanceId}" placeholder="**** **** **** ****" maxlength="19" autocomplete="cc-number">
            </div>
            <div class="input-wrapper {$inputWrapper}">
                <label for="expDate-{$instanceId}" class="{$labelClass} {$instanceId}">Expiration (MM/YY)</label>
                <input type="text" class="{$inputClass} {$instanceId}" id="expDate-{$instanceId}" placeholder="MM/YY" maxlength="5" autocomplete="cc-exp">
            </div>
            <div class="input-wrapper {$inputWrapper}">
                <label for="cvc-{$instanceId}" class="{$labelClass} {$instanceId}">CVC</label>
                <input type="text" class="{$inputClass} {$instanceId}" id="cvc-{$instanceId}" placeholder="CVC" maxlength="4" autocomplete="cc-csc">
            </div>
            <div class="input-wrapper {$inputWrapper}">
                <input type="hidden" name="payment_token" id="dataValue-{$instanceId}">
                <input type="hidden" name="data_descriptor" id="dataDescriptor-{$instanceId}">
            </div>
        </div>
HTML;

        $script = <<<JS
    (function() {
        window.initPayment = function(attempt = 1, maxAttempts = 5) {
            var errorDiv = document.getElementById('paymentErrors-{$instanceId}');
            var cardNumberInput = document.getElementById('cardNumber-{$instanceId}');
            var expDateInput = document.getElementById('expDate-{$instanceId}');
            var cvcInput = document.getElementById('cvc-{$instanceId}');

            if (!errorDiv || !cardNumberInput || !expDateInput || !cvcInput) {
                if (attempt < maxAttempts) {
                    setTimeout(function() { window.initPayment(attempt + 1, maxAttempts); }, 100);
                    return;
                } else {
                    if (errorDiv) {
                        errorDiv.innerText = 'Payment form initialization failed. Please refresh the page.';
                        errorDiv.style.display = 'block';
                    }
                    return;
                }
            }

            // Add input event listener for card number formatting
            if (!cardNumberInput.dataset.formattingAttached) {
                cardNumberInput.addEventListener('input', (e) => {
                    let value = e.target.value.replace(/\D/g, '');
                    value = value.match(/.{1,4}/g)?.join(' ') || value;
                    e.target.value = value.slice(0, 23);
                });
                cardNumberInput.dataset.formattingAttached = 'true';
            }

            // Add input event listener for expiration date formatting
            if (!expDateInput.dataset.formattingAttached) {
                expDateInput.addEventListener('input', (e) => {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length > 2) {
                        value = value.slice(0, 2) + '/' + value.slice(2, 4);
                    }
                    e.target.value = value.slice(0, 5);
                });
                expDateInput.dataset.formattingAttached = 'true';
            }

            window.fnCreatePaymentToken = function(options = {}) {
                return new Promise((resolve) => {
                    console.log('Triggering tokenization for {$instanceId} with options:', options);
                    if (typeof Accept === 'undefined') {
                        console.error('Accept.js not loaded for {$instanceId}');
                        if (!options.error && errorDiv) {
                            errorDiv.innerText = 'Payment library not loaded. Please try again.';
                            errorDiv.style.display = 'block';
                        }
                        resolve({ error: 'Payment library not loaded' });
                        return;
                    }

                    const cardNumber = cardNumberInput.value.replace(/\s/g, '');
                    const expDate = expDateInput.value.trim();
                    const cvc = cvcInput.value.trim();

                    if (!cardNumber.match(/^\d{13,19}$/)) {
                        if (!options.error && errorDiv) {
                            errorDiv.innerText = 'Invalid card number. Please enter a valid card number (13-19 digits).';
                            errorDiv.style.display = 'block';
                        }
                        resolve({ error: 'Invalid card number' });
                        return;
                    }

                    if (!expDate.match(/^\d{2}\/\d{2}$/)) {
                        if (!options.error && errorDiv) {
                            errorDiv.innerText = 'Invalid expiration date. Please use MM/YY format.';
                            errorDiv.style.display = 'block';
                        }
                        resolve({ error: 'Invalid expiration date' });
                        return;
                    }
                    const [month, year] = expDate.split('/');
                    const currentDate = new Date();
                    const currentYear = currentDate.getFullYear() % 100;
                    const currentMonth = currentDate.getMonth() + 1;
                    const inputMonth = parseInt(month, 10);
                    const inputYear = parseInt(year, 10);
                    if (inputMonth < 1 || inputMonth > 12) {
                        if (!options.error && errorDiv) {
                            errorDiv.innerText = 'Invalid month. Please enter a month between 01 and 12.';
                            errorDiv.style.display = 'block';
                        }
                        resolve({ error: 'Invalid month' });
                        return;
                    }
                    if (inputYear < currentYear || (inputYear === currentYear && inputMonth < currentMonth)) {
                        if (!options.error && errorDiv) {
                            errorDiv.innerText = 'Card has expired. Please use a valid expiration date.';
                            errorDiv.style.display = 'block';
                        }
                        resolve({ error: 'Card has expired' });
                        return;
                    }

                    if (!cvc.match(/^\d{3,4}$/)) {
                        if (!options.error && errorDiv) {
                            errorDiv.innerText = 'Invalid CVC. Please enter a 3- or 4-digit code.';
                            errorDiv.style.display = 'block';
                        }
                        resolve({ error: 'Invalid CVC' });
                        return;
                    }

                    var secureData = {
                        authData: {
                            apiLoginID: '{$apiLoginId}',
                            clientKey: '{$clientKey}'
                        },
                        cardData: {
                            cardNumber: cardNumber,
                            month: month,
                            year: '20' + year,
                            cardCode: cvc
                        }
                    };

                    Accept.dispatchData(secureData, function(response) {
                        if (response.messages.resultCode === 'Error') {
                            const errorMessage = response.messages.message?.[0]?.text || 'Payment system configuration error. Please contact support.';
                            if (!options.error && errorDiv) {
                                errorDiv.innerText = 'Payment failed: ' + errorMessage;
                                errorDiv.style.display = 'block';
                            }
                            resolve({ error: errorMessage });
                        } else {
                            const encodedNonce = window.btoa(response.opaqueData.dataValue);
                            const dataValueInput = document.getElementById('dataValue-{$instanceId}');
                            const dataDescriptorInput = document.getElementById('dataDescriptor-{$instanceId}');

                            cardNumberInput.value = '';
                            expDateInput.value = '';
                            cvcInput.value = '';

                            resolve({
                                token: encodedNonce,
                                descriptor: response.opaqueData.dataDescriptor
                            });
                        }
                    });
                });
            };
        };
    })();
    JS;

        return [
            'html' => $html,
            'script' => $script,
            'scriptUrl' => $scriptUrl,
        ];
    }
    /**
     * Generates UI components for Google Pay payment.
     *
     * @param string $merchantId Google Pay merchant ID
     * @param string $gatewayMerchantId Gateway merchant ID
     * @param string $gateway Payment gateway name (e.g., 'authorizenet')
     * @param float $amount Payment amount
     * @param string $storeName Merchant store name
     * @param array $attributes Client-provided attributes, including CSS classes
     * @return array Array containing HTML, JavaScript, and script URL for the Google Pay UI
     */
    protected function _googlePayUI($merchantId, $gatewayMerchantId, $gateway, $amount, $storeName = 'My Store', $attributes = [])
    {
        $scriptUrl = 'https://pay.google.com/gp/p/js/pay.js';
        $instanceId = uniqid('googlepay_'); // Unique ID for this instance
        $safeAmount = (float) $amount; // Ensure numeric value for JavaScript
        $classes = $attributes['classes'] ?? [];

        // Extract client-provided classes with defaults
        $containerClass = $classes['google_pay_container'] ?? 'google-pay-container';
        $inputClass = $classes['card_input'] ?? 'google-pay-input';
        $labelClass = $classes['label'] ?? 'google-pay-label';
        $errorClass = $classes['error'] ?? '';
        $buttonClass = $classes['submit_button'] ?? '';

        $html = <<<HTML

    <div class="{$containerClass} {$instanceId}">
        <div id="google-pay-error-{$instanceId}" class="{$errorClass}"></div>
        <div id="google-pay-button-{$instanceId}" class="{$buttonClass}"></div>
    </div>
    HTML;

        $script = <<<JS
    (function() {

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
                    gateway: '{$gateway}',
                    gatewayMerchantId: '{$gatewayMerchantId}'
                }
            }
        };

        function getRequest() {
            return {
                ...baseRequest,
                allowedPaymentMethods: [baseCardPaymentMethod],
                transactionInfo: {
                    totalPriceStatus: 'FINAL',
                    totalPrice: '{$safeAmount}',
                    currencyCode: 'USD',
                    countryCode: 'US'
                },
                merchantInfo: {
                    merchantId: '{$merchantId}',
                    merchantName: '{$storeName}'
                }
            };
        }

        function getGooglePaymentsClient() {
            return new window.google.payments.api.PaymentsClient({
                environment: 'TEST'
            });
        }

        function initializeGooglePay() {
            if (!window.google || !window.google.payments) {
                console.error('Google Pay API not available for {$instanceId}');
                document.getElementById('google-pay-error-{$instanceId}').innerText = 'Google Pay API unavailable';
                document.getElementById('google-pay-error-{$instanceId}').style.display = 'block';
                return;
            }

            const paymentsClient = getGooglePaymentsClient();
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
            const paymentsClient = getGooglePaymentsClient();
            const buttonContainer = document.getElementById('google-pay-button-{$instanceId}');
            buttonContainer.innerHTML = '';
            const button = paymentsClient.createButton({
                onClick: () => onGooglePayClicked(paymentsClient),
                buttonType: 'buy',
                buttonColor: 'black',
                buttonSizeMode: 'fill',
                buttonRadius: 4
            });
            buttonContainer.appendChild(button);
        }

        let isSubmitting = false;

        async function onGooglePayClicked() {
            const paymentsClient = getGooglePaymentsClient();
            if (isSubmitting || !paymentsClient) {
                console.log('Submission blocked for {$instanceId}: isSubmitting=', isSubmitting, 'paymentsClient=', !!paymentsClient);
                return;
            }
            isSubmitting = true;
            const errorDiv = document.getElementById('google-pay-error-{$instanceId}');
            const buttonContainer = document.getElementById('google-pay-button-{$instanceId}');
            errorDiv.innerText = '';
            errorDiv.style.display = 'none';

            try {
                const amount = {$safeAmount};

                if (typeof amount !== 'number' || isNaN(amount) || amount <= 0) {
                    errorDiv.innerText = 'Invalid amount: ' + amount;
                    errorDiv.style.display = 'block';
                    isSubmitting = false;
                    return;
                }

                const paymentDataRequest = getRequest();
                buttonContainer.classList.add('loading');
                const paymentData = await paymentsClient.loadPaymentData(paymentDataRequest);
                const paymentToken = paymentData.paymentMethodData.tokenizationData.token;

             if (paymentToken) {
                    const firstEncode = window.btoa(paymentToken);
                    const encodedToken = window.btoa(firstEncode);

                    // Check for existing payment_token input
                    let hiddenInput = document.getElementsByName('payment_token')[0];
                    if (!hiddenInput) {
                        hiddenInput = document.createElement("input");
                        hiddenInput.type = "hidden";
                        hiddenInput.name = 'payment_token';
                        document.body.appendChild(hiddenInput);
                    }
                    hiddenInput.value = encodedToken;

                    // Check for existing data_descriptor input
                    let descriptorInput = document.getElementsByName('data_descriptor')[0];
                    if (!descriptorInput) {
                        descriptorInput = document.createElement("input");
                        descriptorInput.type = "hidden";
                        descriptorInput.name = 'data_descriptor';
                        document.body.appendChild(descriptorInput);
                    }
                    descriptorInput.value = 'COMMON.GOOGLE.INAPP.PAYMENT';

                    // Submit the form
                    const submitButton = document.querySelector('button[data-payment][type=submit]');
                    if (submitButton) {
                        submitButton.click();
                    } else {
                        console.error('Submit button or form not found');
                    }
                }
            } catch (err) {

                let errorMessage = err.message || err.statusCode || 'Unknown error';
                if (err.statusCode === 'DEVELOPER_ERROR') {
                    errorMessage = 'Invalid payment configuration. Please try again or contact support. [OR_BIBED_06]';
                } else if (err.statusCode === 'CANCELED') {
                    errorMessage = 'Payment canceled by user.';
                } else if (err.message && err.message.includes('OR_BIBED_06')) {
                    errorMessage = 'Merchant configuration error. Try a different payment method or contact support. [OR_BIBED_06]';
                }
                errorDiv.innerText = 'Payment error: ' + errorMessage;
                errorDiv.style.display = 'block';
            } finally {
                isSubmitting = false;
                buttonContainer.classList.remove('loading');
            }
        }

        function initializeGooglePayForm(attempt = 1, maxAttempts = 5) {
            const buttonContainer = document.getElementById('google-pay-button-{$instanceId}');
            const errorDiv = document.getElementById('google-pay-error-{$instanceId}');

            if (!buttonContainer || !errorDiv) {
                if (attempt < maxAttempts) {
                    setTimeout(() => initializeGooglePayForm(attempt + 1, maxAttempts), 100);
                    return;
                } else {
                    errorDiv.innerText = 'Payment form initialization failed. Please refresh the page.';
                    errorDiv.style.display = 'block';
                    return;
                }
            }
            initializeGooglePay();
        }

        if (document.readyState === 'complete' || document.readyState === 'interactive') {
             window.initGooglePayment = function() {
              initializeGooglePay();
            }
        } else {
            document.addEventListener('DOMContentLoaded', initializeGooglePayForm);
        }
    })();
    JS;

        return [
            'html' => $html,
            'script' => $script,
            'scriptUrl' => $scriptUrl,
        ];
    }


    /**
     * Generates UI components for Apple Pay payment.
     *
     * @param string $merchantId Apple Pay merchant ID
     * @param string $gatewayMerchantId Gateway merchant ID
     * @param float $amount Payment amount
     * @param string $storeName Merchant store name
     * @param array $attributes Client-provided attributes, including CSS classes
     * @return array Array containing HTML, JavaScript, and script URL for the Apple Pay UI
     */

    protected function _applePayUI($merchantId, $gatewayMerchantId, $amount, $storeName = 'My Store', $attributes = [])
    {
        $scriptUrl = 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js';
        $instanceId = uniqid('applepay_'); // Unique ID for this instance
        $safeAmount = (float) $amount; // Ensure numeric value for JavaScript
        $classes = $attributes['classes'] ?? [];

        // Extract client-provided classes with defaults
        $containerClass = $classes['apple_pay_container'] ?? '';
        $errorClass = $classes['error'] ?? '';
        $apple_pay_container = $classes['apple_pay_container'] ?? '';

        $html = <<<HTML
    <style>
          apple-pay-button {
                  --apple-pay-button-width: 215px;
                  --apple-pay-button-height: 30px;
                  --apple-pay-button-border-radius: 5px;
                  --apple-pay-button-padding: 5px 0px;
                }
        #apple-pay-error-{$instanceId} { color: #b91c1c; margin-bottom: 12px; padding: 10px; background: #fee2e2; border-radius: 6px; display: none; }
    </style>
    <div class="{$containerClass} {$instanceId}">
        <div id="apple-pay-error-{$instanceId}" class="{$errorClass}"></div>
        <div id="apple-pay-container-{$instanceId}" class="{$apple_pay_container}">
            <apple-pay-button buttonstyle="black" type="buy" locale="en-US"></apple-pay-button>
        </div>
    </div>
    HTML;

        $script = <<<JS
    (function() {

        const merchantIdentifier = '{$merchantId}';
        const gatewayMerchantId = '{$gatewayMerchantId}';

        function initializeApplePay() {
            if (!window.ApplePaySession || !ApplePaySession.canMakePayments()) {
                console.error('Apple Pay API not available for {$instanceId}');
                document.getElementById('apple-pay-error-{$instanceId}').innerText = 'Apple Pay is not available on this device or browser';
                document.getElementById('apple-pay-error-{$instanceId}').style.display = 'block';
                return;
            }

            // Reference the existing <apple-pay-button> element
            const buttonContainer = document.getElementById('apple-pay-container-{$instanceId}');
            const applePayButton = buttonContainer.querySelector('apple-pay-button');

            if (!applePayButton) {
                document.getElementById('apple-pay-error-{$instanceId}').innerText = 'Apple Pay button failed to load';
                document.getElementById('apple-pay-error-{$instanceId}').style.display = 'block';
                return;
            }

            applePayButton.addEventListener('click', onApplePayClicked);
        }

        let isSubmitting = false;

        async function onApplePayClicked() {
            if (isSubmitting) {
                return;
            }
            isSubmitting = true;
            const errorDiv = document.getElementById('apple-pay-error-{$instanceId}');
            const buttonContainer = document.getElementById('apple-pay-container-{$instanceId}');
            errorDiv.innerText = '';
            errorDiv.style.display = 'none';

            try {
                const amount = {$safeAmount};

                if (typeof amount !== 'number' || isNaN(amount) || amount <= 0) {
                    errorDiv.innerText = 'Invalid amount: ' + amount;
                    errorDiv.style.display = 'block';
                    isSubmitting = false;
                    return;
                }

                const paymentRequest = {
                    countryCode: 'US',
                    currencyCode: 'USD',
                    supportedNetworks: ['visa', 'masterCard', 'amex', 'discover'],
                    merchantCapabilities: ['supports3DS'],
                    merchantIdentifier: merchantIdentifier,
                    total: {
                        label: '{$storeName}',
                        type: 'final',
                        amount: amount
                    }
                };

                const session = new ApplePaySession(4, paymentRequest);
                buttonContainer.classList.add('loading');

                session.onvalidatemerchant = async (event) => {
                    try {
                        const response = await fetch('/validate-merchant', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ validationURL: event.validationURL })
                        });
                        if (!response.ok) throw new Error('Merchant validation failed');
                        const validationData = await response.json();
                        session.completeMerchantValidation(validationData);
                    } catch (err) {
                        errorDiv.innerText = 'Merchant validation failed: ' + err.message;
                        errorDiv.style.display = 'block';
                        session.abort();
                        isSubmitting = false;
                    }
                };

                session.onpaymentauthorized = async (event) => {
                    try {
                        const paymentToken = event.payment.token;
                        if (paymentToken) {
                            const firstEncode = window.btoa(paymentToken);
                            const encodedToken = window.btoa(JSON.stringify(firstEncode));

                            // Check for existing payment_token input
                            let paymentInput = document.getElementById('payment_token')[0];
                            if (!paymentInput) {
                                paymentInput = document.createElement("input");
                                paymentInput.type = "hidden";
                                paymentInput.id = 'payment_token-{$instanceId}';
                                paymentInput.name = 'payment_token';
                                document.body.appendChild(paymentInput);
                            }
                            paymentInput.value = encodedToken;

                            // Check for existing dataDescriptor input
                            let descriptorInput = document.getElementById('data_descriptor')[0];
                            if (!descriptorInput) {
                                descriptorInput = document.createElement("input");
                                descriptorInput.type = "hidden";
                                descriptorInput.id = 'data_descriptor-{$instanceId}';
                                descriptorInput.name = 'data_descriptor';
                                document.body.appendChild(descriptorInput);
                            }
                            descriptorInput.value = 'COMMON.APPLE.INAPP.PAYMENT';

                            const submitButton = document.querySelector('button[data-payment][type=submit]');
                            if (submitButton) {
                                const closestForm = submitButton.closest('form');
                                if (closestForm) {
                                    submitButton.click();
                                } else {
                                    errorDiv.innerText = 'Form submission failed: No form found';
                                    errorDiv.style.display = 'block';
                                }
                            } else {
                                errorDiv.innerText = 'Form submission failed: Submit button not found';
                                errorDiv.style.display = 'block';
                            }
                            session.completePayment(ApplePaySession.STATUS_SUCCESS);
                        }
                    } catch (err) {
                        let errorMessage = err.message || err.statusCode || 'Unknown error';
                        errorDiv.innerText = 'Payment error: ' + errorMessage;
                        errorDiv.style.display = 'block';
                        session.completePayment(ApplePaySession.STATUS_FAILURE);
                    }
                };

                session.oncancel = () => {
                    isSubmitting = false;
                    buttonContainer.classList.remove('loading');
                };

                session.begin();
            } catch (err) {
                let errorMessage = err.message || err.statusCode || 'Unknown error';
                errorDiv.innerText = 'Payment error: ' + errorMessage;
                errorDiv.style.display = 'block';
                isSubmitting = false;
                buttonContainer.classList.remove('loading');
            }
        }

        function initializeApplePayForm(attempt = 1, maxAttempts = 5) {
            const buttonContainer = document.getElementById('apple-pay-container-{$instanceId}');
            const errorDiv = document.getElementById('apple-pay-error-{$instanceId}');

            if (!buttonContainer || !errorDiv) {
                if (attempt < maxAttempts) {
                    setTimeout(() => initializeApplePayForm(attempt + 1, maxAttempts), 100);
                    return;
                } else {
                    errorDiv.innerText = 'Payment form initialization failed. Please refresh the page.';
                    errorDiv.style.display = 'block';
                    return;
                }
            }
            initializeApplePay();
        }

        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            window.initApplePayment = function() {
                initializeApplePayForm();
            };
        } else {
            document.addEventListener('DOMContentLoaded', () => {
                window.initApplePayment = function() {
                    initializeApplePayForm();
                };
            });
        }
    })();
    JS;

        return [
            'html' => $html,
            'script' => $script,
            'scriptUrl' => $scriptUrl,
        ];
    }

    /**
     * Validates the Apple Pay merchant.
     *
     * @param Request $request The request containing the validation URL
     * @return \Illuminate\Http\JsonResponse The response from the Apple Pay server
     */
    public function validateMerchant(Request $request)
    {
        $authenticatedUser = auth()->user();
        $gateway = UserPaymentGateway::where([
            'user_id' => $authenticatedUser->id,
            'status' => '1'
        ])->first();

        $validationURL = $request->input('validationURL');
        $merchantId = $gateway->credentials['aMerchant_id'];
        $certificatePath = 'path/to/merchant_cert.p12';
        $payload = [
            'merchantIdentifier' => $merchantId,
            'domainName' => $authenticatedUser->domain_name,
            'displayName' => $authenticatedUser['store_name'],
            'initiative' => 'web',
            'initiativeContext' => $authenticatedUser->domain_name,
        ];
        $response = Http::withOptions(['cert' => $certificatePath])
            ->post($validationURL, $payload);
        return $response->json();
    }

    public function _posUI($credentials, $amount, $attributes, $jwt_token): array
    {
        $terminal = new StripeController($this->validationService);

        return $terminal->_posUI($credentials, $amount, $attributes, $jwt_token);
    }
}
