<?php

namespace App\Http\Controllers;

use App\Models\PaymentGateway;
use App\Models\UserPaymentCredential;
use App\Models\UserPaymentSetting;
use App\Traits\ResponseTrait;
use Symfony\Component\HttpFoundation\Response;

class PaymentGatewayController extends Controller
{
    use ResponseTrait;

    /**
     * Retrieves a list of available payment gateways and their wallets and required keys.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Fetch all active parent payment gateways with type 0 (main payment gateways), selecting specific columns
        $paymentGateways = PaymentGateway::select("id", "name", "type", "description", "image")
            ->where(['status' => '1', 'type' => '0'])
            ->get()
            // Map over each payment gateway to add wallets and required keys
            ->map(function ($paymentGateway) {
                // Create a new array with the transformed data
                $formattedGateway = [
                    'id' => $paymentGateway->id,
                    'name' => $paymentGateway->name,
                    'type' => $paymentGateway->type,
                    'description' => $paymentGateway->description,
                    'image' => $paymentGateway->image,
                    'required_keys' => $paymentGateway->paymentGatewayKeys->map(function ($key) {
                        return [
                            'label' => $key->key_name,
                            'value' => $key->value
                    ];
                    })->values()->toArray(),
                    'wallets' => $paymentGateway->wallets->map(function ($wallet) use ($paymentGateway) {
                        $walletKeys = $wallet->paymentGatewayKeys->where('parent', $paymentGateway->id)->filter(function ($key) {
                            return !is_null($key->key_name) && !is_null($key->value);
                        })->values();
                        return [
                            'id' => $wallet->id,
                            'name' => $wallet->name,
                            'slug' => $wallet->slug,
                            'description' => $wallet->description,
                            'image' => $wallet->image,
                            'required_keys' => $walletKeys->map(function ($key) {
                                return [
                                    'label' => $key->key_name,
                                    'value' => $key->value
                                ];
                            })->values()->toArray()
                        ];
                    })->values()->toArray() // Filter out null wallets and reset keys
                ];

                return $formattedGateway;
            })
            ->toArray(); // Convert the final collection to array

        return $this->successResponseWithData(
            'Payment gateways fetched successfully.',
            $paymentGateways,
            Response::HTTP_OK
        );
    }

    /**
     * Retrieves the details of the user's payment gateway.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserPaymentGatewayDetails()
    {
        $user = auth()->user();

        // Fetch the user's top-level gateway
        $gateway = $user->paymentGateways()
            ->where('status', '1')
            ->select(
                'id',
                'payment_gateway_id',
                'payment_gateway_name',
                'is_live_mode'
            )
            ->first();

        if (!$gateway) {
            return $this->errorResponse('No active payment gateway found.', [], Response::HTTP_NOT_FOUND);
        }

        // Fetch credentials
        $credentials = $gateway->userPaymentCredentials()->pluck('value', 'key')->toArray();

        // Mask sensitive credentials
        foreach ($credentials as $key => &$value) {  // Use & to modify by reference
            if (in_array($key, ['secret_key', 'client_key', 'signing_key']) && is_string($value)) {
                $length = strlen($value);
                $value = $length > 10 ? str_repeat('*', $length * 0.2) . substr($value, -10) : str_repeat('*', $length);
            } elseif (in_array($key, ['transaction_key', 'gMerchant_id', 'aMerchant_id', 'reader_id']) && is_string($value)) {
                $length = strlen($value);
                $value = $length > 4 ? str_repeat('*', $length * 0.2) . substr($value, -4) : str_repeat('*', $length);
            }
        }
        unset($value); // Unset the reference to avoid unintended side effects
        $gateway->credentials = $credentials;

        // Fetch payment settings (wallet toggles)
        $paymentSettings = $gateway->userPaymentSettings()->pluck('value', 'payment_type')->toArray();

        // Add payment settings as dynamic properties
        foreach ($paymentSettings as $paymentType => $value) {
            $gateway->$paymentType = (string)$value;
        }

        unset($gateway->userPaymentCredentials);
        unset($gateway->userPaymentSettings);

        return $this->successResponseWithData(
            'Active user payment gateway fetched successfully.',
            $gateway
        );
    }

}
