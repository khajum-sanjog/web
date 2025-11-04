<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Services\AuthorizeNetPaymentService;
use App\Services\Authorizenet;
use App\Services\StripePaymentService;
use Illuminate\Support\Facades\Log;

class PaymentServiceFactory
{
    /**
     * Get the payment service instance based on the gateway name.
     *
     * @param string $gatewayName The name of the payment gateway (e.g., 'Stripe', 'Authorize.net')
     * @param array $credentials Associative array of credentials (e.g., ['publishable_key' => 'value'])
     * @param string $isLiveMode Whether the gateway is in live mode ('1' or '0')
     * @return mixed Payment service instance or error response array
     */
    public function getService(string $gatewayName, array $credentials, string $isLiveMode)
    {
        $gatewayName = strtolower($gatewayName);

        // Fetch required_keys from payment_gateways table
        $gateway = PaymentGateway::where('name', $gatewayName)->first();
        if (!$gateway) {
            Log::error('Payment gateway not found in database', ['gateway_name' => $gatewayName]);
            return ['success' => false, 'message' => 'Unsupported payment gateway: ' . $gatewayName];
        }

        // Fetch the required_keys
        $requiredCredentials = $gateway->paymentGatewayKeys()->pluck('value')->toArray();

        // Validate required credentials
        $missingKeys = array_diff($requiredCredentials, array_keys(array_filter($credentials, fn ($value) => !empty($value))));
        if (!empty($missingKeys)) {
            Log::error('Missing required credentials for payment gateway', [
                'gateway_name' => $gatewayName,
                'missing_keys' => $missingKeys,
            ]);
            return ['success' => false, 'message' => 'Missing required credentials: ' . implode(', ', $missingKeys)];
        }

        try {
            switch ($gatewayName) {
                case 'authorize.net':
                    return new AuthorizeNetPaymentService(new Authorizenet(
                        $credentials['login_id'],
                        $credentials['transaction_key'],
                        $isLiveMode,
                    ));

                case 'stripe':
                    return new StripePaymentService(
                        $credentials['secret_key'],
                        $isLiveMode,
                    );

                default:
                    // Fallback for unsupported gateways
                    Log::error('Unsupported payment gateway in switch', ['gateway_name' => $gatewayName]);
                    return ['success' => false, 'message' => 'Unsupported payment gateway: ' . $gatewayName];
            }
        } catch (\Exception $e) {
            Log::error('Failed to initialize payment service', [
                'gateway_name' => $gatewayName,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Failed to initialize payment service: ' . $e->getMessage()];
        }
    }
}
