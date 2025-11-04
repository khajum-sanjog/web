<?php

namespace App\Services;

use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ValidationService
{
    use ResponseTrait;

    public function validations($request)
    {
        $request->validate([
            'amount' => 'required',
            'cart_id' => 'required',
            'attributes.submit_button_id' => 'string',
        ]);

        $jwt_token = $request->bearerToken();
        $authenticatedUser = auth()->user();
        $cart_id = $request->cart_id;
        $attributes = $request->get('attributes', []);
        $classes = $attributes['classes'] ?? [];

        $gateway = $authenticatedUser->paymentGateways()->where('status', '1')->with(['userPaymentCredentials', 'userPaymentSettings'])->first();

        if (! $gateway) {
            \Log::error('No active payment gateway found for user', ['user_id' => $authenticatedUser->id]);

            return $this->errorResponse('No active payment gateway found.', [], Response::HTTP_NOT_FOUND);
        }

        if ($gateway->status !== '1') {
            \Log::error('Payment gateway not enabled', ['gateway_id' => $gateway->id]);

            return $this->errorResponse('Payment gateway is not enabled.', [], Response::HTTP_FORBIDDEN);
        }

        $credentials = $gateway->userPaymentCredentials->pluck('value', 'key')->toArray();
        $paymentSettings = $gateway->userPaymentSettings->pluck('value', 'payment_type')->toArray();

        // Make API call to external Url to verify the payment amount
        $result = $this->getAmountFromCart($request->cart_id, $authenticatedUser->store_id, $request->amount);
        if (!$result) {
            return $this->errorResponse('Unable to verify payment amount.', [], Response::HTTP_BAD_REQUEST);
        }

        $amount = $request->amount;

        // Return all necessary data for form loaders
        return [
            'credentials' => $credentials,
            'paymentSettings' => $paymentSettings,
            'amount' => $amount,
            'attributes' => $attributes,
            'jwt_token' => $jwt_token,
            'gateway' => [
                'payment_gateway_name' => $gateway->payment_gateway_name,
                'is_live_mode' => $gateway->is_live_mode,
            ],
            'authenticatedUser' => [
                'store_name' => $authenticatedUser->store_name,
            ],
        ];
    }

    /**
     * Verifies the amount from a cart via an external API.
     *
     * @param  int  $cartId  The ID of the cart
     * @param  int  $storeId  The ID of the store
     * @param  float  $amount  The amount to verify
     * @return bool Returns true if amount is verified, false otherwise
     */
    public function getAmountFromCart($cartId, $storeId, $amount): bool
    {
        // Get API key and URL
        $apiSecret = config('configuration.apiSecret');
        $apiUrl = config('configuration.apiUrl');

        if (empty($apiSecret) || empty($apiUrl)) {
            Log::error('API configuration missing', ['api_key' => empty($apiSecret), 'api_url' => empty($apiUrl)]);

            return false;
        }

        try {
            // Make API call
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->post($apiUrl, [
                    'cart_id' => $cartId,
                    'store_id' => $storeId,
                    'amount' => $amount,
                    'password' => $apiSecret,
                ]);

            // Handle response
            if ($response->successful()) {
                $data = $response->json();
                if (empty($data['status']) || strtolower($data['status']) !== 'success') {
                    Log::warning('Amount validation failed.', ['response' => $data]);

                    return false;
                }

                return true;
            }

            $error = $response->json() ?? ['message' => 'Unknown error occurred'];
            Log::error('API request failed', ['error' => $error, 'status' => $response->status()]);

            return false;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('API request exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
