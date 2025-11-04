<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />

    @if($stripeForm)
        <meta name="publishable_key" content="{{ $publishableKey }}"/>
    @elseif($authorizeForm)
        <meta name="auth_login_id" content="{{ $loginId }}"/>
        <meta name="client_key" content="{{ $clientKey }}"/>
        @if($hasGooglePay)
            <meta name="user_gateway" content="{{ $gMerchant_id }}"/>
            <meta name="user_gateway_merchant_id" content="{{ $paymentGateway_id }}"/>
        @endif
    @endif
    <meta name="final_amt" content="{{ $amount }}" />
    <meta name="store" content="{{ $store }}" />
    <title>CultureShock Payment</title>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>

</head>
<body
    class="bg-white font-sans leading-normal tracking-normal flex justify-center items-center"
>
<div class="bg-white pl-4 pr-4 rounded-lg w-full max-w-lg">
    @if($stripeForm)
        {{--        <div id="card-errors" role="alert" class="text-red-600 mb-4"></div>--}}
        <div class="mb-5">
            <div class="mb-5">
                <label
                    for="card-element"
                    class="block text-gray-700 mb-2 font-medium"
                >
                    {{ $cardNumberLabel ?? 'Credit Card Number' }}
                </label>
                <div
                    id="card-element"
                    class="w-full border border-gray-300 rounded-md p-3 text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                ></div>
            </div>
            <div class="mb-5">
                <label
                    for="card-expiry"
                    class="block text-gray-700 mb-2 font-medium"
                >
                    {{ $cardExpiryDateLabel ?? 'Card Expiry Date' }}
                </label>
                <div
                    id="card-expiry"
                    class="w-full border border-gray-300 rounded-md p-3 text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                ></div>
            </div>
            <div class="mb-5">
                <label
                    for="card-cvc"
                    class="block text-gray-700 mb-2 font-medium"
                >
                    {{ $cardCVCLabel ?? 'Card CVC' }}
                </label>
                <div
                    id="card-cvc"
                    class="w-full border border-gray-300 rounded-md p-3 text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                ></div>
            </div>
        </div>
        <script src="https://js.stripe.com/v3/"></script>
        <script src="{{ asset("build/assets/cs_stripe.js") }}"></script>
    @elseif($authorizeForm)
        <!-- Payment Error Message -->
        <div id="paymentErrors" class="text-red-600 mb-4 text-sm font-medium hidden"></div>
        <!-- Card Number -->
        <div class="mb-5">
            <label for="cardNumber" class="block text-gray-700 font-medium mb-2">{{ $cardNumberLabel ?? 'Credit Card Number' }}</label>
            <input
                type="text"
                id="cardNumber"
                placeholder="**** **** **** ****"
                maxlength="19"
                autocomplete="cc-number"
                class="w-full border border-gray-300 rounded-md p-3 text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
            />
        </div>
        <!-- Expiration Date -->
        <div class="mb-5">
            <label for="expDate" class="block text-gray-700 font-medium mb-2">{{ $cardExpiryDateLabel ?? 'Card Expiry Date' }}</label>
            <input
                type="text"
                id="expDate"
                placeholder="MM / YY"
                maxlength="5"
                autocomplete="cc-exp"
                class="w-full border border-gray-300 rounded-md p-3 text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
            />
        </div>
        <!-- CVC -->
        <div class="mb-5">
            <label for="cvc" class="block text-gray-700 font-medium mb-2">{{ $cardCVCLabel ?? 'Card CVC' }}</label>
            <input
                type="text"
                id="cvc"
                placeholder="CVC"
                maxlength="4"
                autocomplete="cc-csc"
                class="w-full border border-gray-300 rounded-md p-3 text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
            />
        </div>
        <script src="{{ asset("https://jstest.authorize.net/v1/Accept.js") }}"></script>
        <script src="{{ asset("build/assets/cs_authorize.js") }}"></script>
    @endif
    <div id="userHasClicked"></div>
    <button
        type="submit"
        id="btnSubmit"
        style="background-color: #1787FC !important;"
        class="w-full text-white py-2 rounded-md font-semibold transition duration-200 flex items-center justify-center"
    >
        <span id="btnText">PAY NOW</span>
        <svg
            id="btnSpinner"
            class="animate-spin h-5 w-5 ml-2 text-white hidden"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            ></circle>
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
            ></path>
        </svg>
    </button>
    @if($hasGooglePay)
        <div id='google_pay_container' class="mt-5"></div>
        <script src="https://pay.google.com/gp/p/js/pay.js"></script>
    @endif
</div>
</body>
</html>
