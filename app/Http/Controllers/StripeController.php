<?php

namespace App\Http\Controllers;

use App\Services\ValidationService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class StripeController extends Controller
{
    use ResponseTrait;

    protected $validationService;

    public function __construct(ValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    public function stripeFormLoader(Request $request): JsonResponse|array
    {
        $validationResult = $this->validationService->validations($request);
        if (! is_array($validationResult)) {
            return $validationResult; // This would be an error response
        }

        extract($validationResult); // This will create $credentials, $paymentSettings, $amount, etc.

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
                                $cardUI = $this->_cardUI($credentials, $attributes);
                                $uiData['card'] = array_merge($cardUI, ['method' => 'card']);
                            }
                            break;
                        case 'google_pay':
                            if (isset($paymentSettings['has_google_pay']) && $paymentSettings['has_google_pay'] === '1') {
                                $googlePayUI = $this->_googleUI($credentials, $authenticatedUser['store_name'], $amount, $attributes);
                                $uiData['google_pay'] = array_merge($googlePayUI, ['method' => 'googlepay']);
                            }
                            break;
                        case 'apple_pay':
                            if (isset($paymentSettings['has_apple_pay']) && $paymentSettings['has_apple_pay'] === '1') {
                                $appleUI = $this->_appleUI($credentials, $authenticatedUser['store_name'], $amount, $attributes);
                                $uiData['apple_pay'] = array_merge($appleUI, ['method' => 'applepay']);
                            }
                            break;
                        case 'pos_pay':
                            if (isset($paymentSettings['has_pos_pay']) && $paymentSettings['has_pos_pay'] === '1') {
                                if (empty($credentials['reader_id'])) {
                                    $errors['pos_pay'] = 'Reader id is missing';
                                    break;
                                }
                                $posUI = $this->_posUI($credentials, $amount, $attributes, $jwt_token);
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

        // Default payment methods processing (unchanged)
        if (isset($paymentSettings['has_card_pay']) && $paymentSettings['has_card_pay'] === '1') {
            $cardUI = $this->_cardUI($credentials, $attributes);
            $uiData['card'] = array_merge($cardUI, ['method' => 'card']);
        }

        if (isset($paymentSettings['has_google_pay']) && $paymentSettings['has_google_pay'] === '1') {
            $googlePayUI = $this->_googleUI($credentials, $authenticatedUser['store_name'], $amount, $attributes);
            $uiData['google_pay'] = array_merge($googlePayUI, ['method' => 'googlepay']);
        }

        if (isset($paymentSettings['has_apple_pay']) && $paymentSettings['has_apple_pay'] === '1') {
            $appleUI = $this->_appleUI($credentials, $authenticatedUser['store_name'], $amount, $attributes);
            $uiData['apple_pay'] = array_merge($appleUI, ['method' => 'applepay']);
        }

        if (isset($paymentSettings['has_pos_pay']) && $paymentSettings['has_pos_pay'] === '1') {
            if (empty($credentials['reader_id'])) {
                return $this->errorResponse('Reader id is missing.');
            }
            $posUI = $this->_posUI($credentials, $amount, $attributes, $jwt_token);
            $uiData['pos_pay'] = array_merge($posUI, ['method' => 'pospay']);
        }

        return response()->json(['result' => $uiData]);
    }

    /**
     * Generates the UI and JavaScript for handling POS payments via Stripe Terminal.
     *
     * @param mixed $credentials Authentication credentials for Stripe.
     * @param string $reader_id The ID of the reader to connect to.
     * @param float $amount The amount to be paid.
     * @param array $attributes Additional attributes for customizing the UI.
     * @return array An array containing the HTML, JavaScript, and script URL.
     */
    public function _posUI($credentials, $amount, $attributes, $jwt_token): array
    {
        $scriptUrl = "https://js.stripe.com/terminal/v1/";
        $appUrl = config('configuration.appUrl');

        // Generate a temporary token
        $tempToken = Str::uuid()->toString();
        \Log::info('Generated tempToken: ' . $tempToken);

        // Encrypt the temporary token with AES-256-CBC
        $key = base64_decode(config('configuration.encryptionKey'));
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encryptedToken = openssl_encrypt($tempToken, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $encodedToken = base64_encode($iv . $encryptedToken);
        \Log::info('Encoded token: ' . $encodedToken);

        // Store the mapping in cache
        Cache::put('temp_token_' . $encodedToken, $jwt_token, now()->addMinutes(15));
        \Log::info('Cached JWT', ['key' => 'temp_token_' . $encodedToken, 'jwt' => $jwt_token]);

        $classes = $attributes['classes'] ?? [];
        $stripe_token = $attributes["payment_token"] ?? 'payment_token';
        $stripe_terminal_container = $attributes['stripe_terminal_container'] ?? 'stripe-terminal-container-default';
        $pos_button = $classes['pos_button'] ?? 'stripe-terminal-button';

        $html = <<<HTML
        <style>
            .stripe-terminal-button {
                padding: 10px 20px;
                background-color: black;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }
            .stripe-terminal-button:disabled {
                background-color: grey;
                cursor: not-allowed;
            }
        </style>
        <div class="$stripe_terminal_container">
            <input type="hidden" name="pos_element" value=""/>
            <button type="button" id="pos-pay-button" class="$pos_button" disabled>Connecting...</button>
        </div>
        HTML;

        $readerId = $credentials['reader_id'];

        $script = <<<JS
        var terminal = null;
        var reader = null;
        var amount = {$amount};
        var encrypted_token = '{$encodedToken}';
        var readerId = '{$readerId}';
        var appUrl = '{$appUrl}';

        async function fetchConnectionToken() {
            try {
                const response = await fetch(appUrl + '/api/terminal/connection-token', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Temp-Token': encrypted_token
                    }
                });
                const data = await response.json();
                if (!data.data.secret) {
                    throw new Error('Failed to fetch connection token');
                }
                return data.data.secret;
            } catch (error) {
                throw error;
            }
        }

        async function initializeTerminal() {
            if (typeof StripeTerminal === 'undefined') {
                setTimeout(initializeTerminal, 500);
                return;
            }

            terminal = StripeTerminal.create({
                onFetchConnectionToken: fetchConnectionToken,
                onUnexpectedReaderDisconnect: () => {
                    reader = null;
                    updateConnectionStatus('Reader disconnected', '#ff0000', true);
                }
            });
            await connectReader(readerId);
        }

        async function connectReader(readerId = null) {
            if (!terminal) {
                return;
            }


            let selectedReader = null;
            try {
                const discoverResult = await terminal.discoverReaders({
                      discoveryMethod: 'internet' // For smart readers like WisePOS E or S700
                                                 // or 'bluetooth' for mobile readers like BBPOS WP2 or M2
                });
                if (!discoverResult || discoverResult.discoveredReaders.length === 0) {
                    updateConnectionStatus('No readers found. Make sure your reader is powered on and connected.', '#ff0000', true);
                    return;
                }
                selectedReader = discoverResult.discoveredReaders.find(
                    r => r.id === readerId
                );
                console.log('selectedReader', selectedReader);
                // Make sure we have a valid reader object before connecting
                if (!selectedReader) {
                    updateConnectionStatus('The connection to the reader could not be established. Make sure your reader is powered on and connected.', '#ff0000', false);
                    return;
                }
                const connectResult = await terminal.connectReader(selectedReader);
                console.log('Connect Result:', connectResult);
                if (connectResult && connectResult.reader) {
                    reader = connectResult.reader;
                    updateConnectionStatus('Pay with POS', '#ffffff', false);
                } else {
                    updateConnectionStatus('Failed to connect to reader', '#ff0000', false);
                }
            } catch (error) {
                updateConnectionStatus('POS Connection Failed (Retry)', '#ff0000', false);
            }
        }

        function updateConnectionStatus(message, color, disabled) {
            const posPayButton = document.getElementById('pos-pay-button');
            if (posPayButton) {
                posPayButton.textContent = message;
                posPayButton.style.color = color;
                posPayButton.disabled = disabled;
            }
        }

        async function collectPayment() {
            const posPayButton = document.getElementById('pos-pay-button');
            try {
                posPayButton.disabled = true;
                posPayButton.textContent = 'Processing...';
                const paymentData = {
                    amount: amount,
                    currency: 'usd',
                    comment: 'Payment via Stripe Terminal',
                };

                const response = await fetch(appUrl + '/api/terminal/create-payment-intent', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Temp-Token': encrypted_token
                    },
                    body: JSON.stringify(paymentData)
                });
                const data = await response.json();
                if (!data) {
                    throw new Error(data.message);
                }

                const collectResult = await terminal.collectPaymentMethod(
                    data.data.client_secret, {
                        config_override: {
                            enable_customer_cancellation: true
                        }
                });

                console.log('Collect Payment Method Result:', collectResult);
                const processResult = await terminal.processPayment(collectResult.paymentIntent);
                console.log('Process Payment Result:', processResult);
                const paymentIntentId = processResult?.paymentIntent?.id;
                const paymentStatus = processResult?.paymentIntent?.status;

                if (paymentStatus === 'succeeded') {
                    let hiddenInput = document.getElementsByName('$stripe_token')[0];

                    if (!hiddenInput) {
                        hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = '$stripe_token';
                        hiddenInput.id = '$stripe_token';
                        document.body.appendChild(hiddenInput);
                    }

                    hiddenInput.value = btoa(paymentIntentId);

                    let hiddenDescriptor = document.getElementsByName('data_descriptor')[0];
                    if (!hiddenDescriptor) {
                        hiddenDescriptor = document.createElement('input');
                        hiddenDescriptor.type = 'hidden';
                        hiddenDescriptor.name = 'data_descriptor';
                        hiddenDescriptor.id = 'data_descriptor';
                        document.body.appendChild(hiddenDescriptor);
                    }
                    hiddenDescriptor.value = 'POS_PAY';

                    let posTriggerEle = document.getElementsByName('pos_element')[0];
                    posTriggerEle.value = 'POS_PAY';

                    setTimeout(function() {
                        const submitButton = document.querySelector('button[data-payment][type=submit]');
                        submitButton.click();
                    }, 0);
                }

                posPayButton.textContent = 'Pay In-Person';
                posPayButton.disabled = false;
            } catch (error) {
                posPayButton.textContent = 'Pay In-Person';
                posPayButton.disabled = false;
                throw error;
            }
        }

        // Initialize immediately when the script loads
        if (typeof StripeTerminal !== 'undefined') {
            initializeTerminal();
        } else {
            document.addEventListener('DOMContentLoaded', initializeTerminal);
        }

        // Button click handler
        document.getElementById('pos-pay-button').addEventListener('click', async function() {
            const posPayButton = document.getElementById('pos-pay-button');
            var conf = true;

            if (posPayButton.textContent.includes('Retry')) {
                posPayButton.disabled = true;
                posPayButton.textContent = 'Connecting...';
                await connectReader(readerId);
            }

            if (reader) {
                conf = confirm('This payment option is only available at the store and requires using our card reader.');
                if (conf) {
                    await collectPayment();
                }
            }
        });
        JS;

        return [
            'html' => $html,
            'script' => $script,
            'scriptUrl' => $scriptUrl
        ];
    }

    /**
     * @throws \JsonException
     */
    public function _cardUI($credentials, $attributes): array
    {
        $scriptUrl = "https://js.stripe.com/v3";

        $stripe_publishable_key = json_encode($credentials['publishable_key'], JSON_THROW_ON_ERROR);

        $classes = $attributes['classes'] ?? [];
        $card_number_label = $attributes["card_number_label"] ?? 'Credit Card Number';
        $card_expiry_date_label = $attributes["card_expiry_date_label"] ?? 'Credit Expiry Date';
        $card_cvc_label = $attributes["card_cvc_label"] ?? 'Credit Cvc';
        $stripe_token = $attributes["payment_token"] ?? 'payment_token';

        $card_error = $classes["card_error"] ?? '';
        $card_input = $classes["card_input"] ?? '';
        $card_element = $classes["card-element"] ?? '';
        $card_expiry = $classes["card-expiry"] ?? '';
        $card_cvc = $classes["card-cvc"] ?? '';
        $label = $classes['label'] ?? '';
        $parentDiv = $classes['parent-div'] ?? '';

        $html = <<<HTML
            <div class="parent_div $parentDiv">
                <style> #card_error {display: none; margin-bottom: 15px} </style>
                <div id="card_error" class='card_error $card_error'></div>
                <input type='hidden' name='$stripe_token' value=''>
                <label class='$label' for='card-element'>$card_number_label</label>
                <div class='card_input $card_input $card_element' id='card-element'></div>
                <label class='$label' for='card-expiry'>$card_expiry_date_label</label>
                <div class='card_input $card_input $card_expiry' id='card-expiry'></div>
                <label class='$label' for='card-cvc'>$card_cvc_label</label>
                <div class='card_input $card_input $card_cvc' id='card-cvc'></div>
            </div>

        HTML;

        $script = <<<JS
            window.initPayment = function() {
                if (typeof Stripe === 'undefined') {
                    setTimeout(initPayment, 50);
                    return;
                }

                const stripe = Stripe($stripe_publishable_key);
                const elements = stripe.elements();
                const card = elements.create('cardNumber', { placeholder: '**** **** **** ****' });
                const cardExpiryElement = elements.create('cardExpiry');
                const cardCvcElement = elements.create('cardCvc');

                card.mount('#card-element');
                cardExpiryElement.mount('#card-expiry');
                cardCvcElement.mount('#card-cvc');

                window.fnCreatePaymentToken = function(options = {}) {
                    return stripe.createToken(card).then((result) => {
                        if (result.error) {
                            if (! options.error) {
                                const errorElem = document.getElementById('card_error');
                                if (errorElem) {
                                    errorElem.innerHTML = result.error.message;
                                    errorElem.style.display = 'block';
                                }
                            }
                            return Promise.reject(result.error);
                        } else {
                            const token = btoa(result.token.id);
                            const tokenInput = document.getElementsByName('$stripe_token')[0];
                            if (tokenInput) {
                                tokenInput.value = token;
                            }
                            return { token: token };
                        }
                    });
                };
            };
        JS;

        return [
            'html' => $html,
            'script' => $script,
            'scriptUrl' => $scriptUrl
        ];
    }

    public function _googleUI($credentials, $storeName, $amount, $attributes)
    {
        $stripe_publishable_key = $credentials['publishable_key'];

        // Detect environment
        $environment = (strpos($credentials['publishable_key'], 'pk_live_') === 0
            ? 'PRODUCTION'
            : 'TEST');

        $classes = $attributes['classes'] ?? [];
        $google_pay_container = $classes['google_pay_container'] ?? '';

        $stripe_token = $attributes["payment_token"] ?? 'payment_token';
        $google_pay_style = $attributes['google_pay_style'] ?? '';

        $scriptUrl = 'https://pay.google.com/gp/p/js/pay.js';

        $html = <<<HTML
            <div id='google_pay_container' class='$google_pay_container'></div>
            <style>$google_pay_style</style>
        HTML;

        $script = <<<JS
            function getGooglePaymentsClient() {
              return new google.payments.api.PaymentsClient({ environment: '$environment' });
            }

            function onGooglePayLoaded() {
                const paymentsClient = getGooglePaymentsClient();
                const button = paymentsClient.createButton({
                    buttonColor: 'default',
                    buttonType: 'pay',
                    buttonRadius: 3,
                    buttonSizeMode: 'fill',
                    onClick: onGooglePaymentButtonClicked
                });
                const container = document.getElementById('google_pay_container');
                if (container.querySelector('button')) { return; }

                container.innerHTML = '';
                container.appendChild(button);
            }

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
                      'stripe:publishableKey': '$stripe_publishable_key'
                    }
                  }
                }],
                transactionInfo: {
                  totalPriceStatus: 'FINAL',
                  totalPrice: '$amount',
                  currencyCode: 'USD',
                  countryCode: 'US'
                },
                merchantInfo: {
                  merchantName: '$storeName',
                }
              };
            }

            async function onGooglePaymentButtonClicked() {
                if (event) event.preventDefault();

                const paymentDataRequest = getRequest();
                const paymentsClient = getGooglePaymentsClient();

                try {
                    const paymentData = await paymentsClient.loadPaymentData(paymentDataRequest);
                    const token = JSON.parse(paymentData.paymentMethodData.tokenizationData.token);

                    if (token) {
                        let hiddenInput = document.getElementsByName('$stripe_token')[0];
                        if (!hiddenInput) {
                            console.log('Creating hidden input for token');
                            hiddenInput = document.createElement("input");
                            hiddenInput.type = "hidden";
                            hiddenInput.name = '$stripe_token';
                            document.body.appendChild(hiddenInput);
                        }
                        hiddenInput.value = btoa(token.id);

                        const submitButton = document.querySelector('button[data-payment][type=submit]');
                        console.log('Submit button found:', submitButton);
                        const closestForm = submitButton.closest('form');
                        console.log('Closest form:', closestForm);
                        submitButton.click();
                    }
                } catch (error) {
                }
            }

            // Initialize Google Pay API after the script loads
            function initializeGooglePay() {
                if (window.google && google.payments) {
                    onGooglePayLoaded();
                } else {
                }
            }
            window.initGooglePayment = function() {
              initializeGooglePay();
            }
            JS;

        return [
            'html' => $html,
            'script' => $script,
            'scriptUrl' => $scriptUrl
        ];
    }

    public function _appleUI($credentials, $storeName, $amount, $attributes)
    {
        $classes = $attributes['classes'] ?? [];
        $apple_pay_container = $classes['apple_pay_container'] ?? '';
        $apple_pay_style = $attributes['apple_pay_style'] ?? '';

        $stripe_token = $attributes["payment_token"] ?? 'payment_token';

        $scriptUrl = 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js';

        $html = <<<HTML
            <style>
                apple-pay-button {
                  --apple-pay-button-width: 215px;
                  --apple-pay-button-height: 30px;
                  --apple-pay-button-border-radius: 5px;
                  --apple-pay-button-padding: 5px 0px;
                }

                $apple_pay_style
            </style>

            <div id='apple_pay_container' class='$apple_pay_container'>
                <apple-pay-button buttonstyle='black' type='buy' locale='en-US'></apple-pay-button>
            </div>
        HTML;

        $script = <<<JS
                var activeSession = null;
                async function onApplePaymentButtonClicked() {
                  if (activeSession) {
                    return;
                  }

                  const storeName = '$storeName';
                  const amount = '$amount';

                  const paymentRequest = {
                    countryCode: 'US',
                    currencyCode: 'USD',
                    total: {
                      label: storeName,
                      amount: amount
                    },
                    supportedNetworks: ['visa', 'masterCard', 'amex'],
                    merchantCapabilities: ['supports3DS']
                  };

                  activeSession = new ApplePaySession(3, paymentRequest);
                  activeSession.onvalidatemerchant = async (event) => {
                    try {
                      const response = await fetch('/validate-merchant', {
                        method: 'POST',
                        body: JSON.stringify({ validationURL: event.validationURL }),
                        headers: { 'Content-Type': 'application/json' }
                      });

                      const validationData = await response.json();
                      activeSession.completeMerchantValidation(validationData);
                    } catch (error) {
                      activeSession.abort();
                      activeSession = null;
                    }
                  };

                  activeSession.onpaymentauthorized = (event) => {
                    const payment = event.payment;

                    let hiddenInput = document.getElementsByName('$stripe_token')[0];
                    if (!hiddenInput) {
                        hiddenInput = document.createElement("input");
                        hiddenInput.type = "hidden";
                        hiddenInput.name = '$stripe_token';
                        document.body.appendChild(hiddenInput);
                    }
                    hiddenInput.value = btoa(JSON.stringify(payment.token));

                    activeSession.completePayment(ApplePaySession.STATUS_SUCCESS);
                    activeSession = null;

                    const submitButton = document.querySelector('button[data-payment][type=submit]');
                    const closestForm = submitButton.closest('form');
                    submitButton.click()
                  };

                  activeSession.oncancel = () => {
                    activeSession = null;
                  };

                  activeSession.begin();
                }

                document.querySelector('apple-pay-button').addEventListener('click', onApplePaymentButtonClicked);
        JS;

        return [
            'html' => $html,
            'script' => $script,
            'scriptUrl' => $scriptUrl,
        ];
    }
}
