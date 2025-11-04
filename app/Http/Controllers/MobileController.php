<?php

namespace App\Http\Controllers;

use App\Services\ValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MobileController extends Controller
{
    protected $validationService;

    public function __construct(ValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    public function paymentRenderAction(Request $request)
    {
        $validationResult = $this->validationService->validations($request);
        if (! is_array($validationResult)) {
            return $validationResult; // This would be an error response
        }
        extract($validationResult);

        $data = [];

        if ($validationResult["gateway"]["payment_gateway_name"] === "Stripe" )
        {
            $data = [
                'stripeForm' => true,
                'authorizeForm' => false,
                'publishableKey' => $credentials['publishable_key'],
            ];
        } else {
            $data = [
                'stripeForm' => false,
                'authorizeForm' => true,
                'loginId' => $credentials['login_id'],
                'clientKey' => $credentials['client_key'],
            ];
        }

        if ($paymentSettings['has_google_pay'] === '1' && $request->get('google_pay',  null))
        {
            $data['hasGooglePay'] = true;

            if(!empty($credentials['gMerchant_id']) && !empty($credentials['paymentGateway_id']))
            {
                $data['gMerchant_id'] = $credentials['gMerchant_id'];
                $data['user_gateway_merchant_id'] = $credentials['user_gateway_merchant_id'];
            }
        } else {
            $data['hasGooglePay'] = false;
        }

        $data['amount'] = $amount;
        $data['store'] = $authenticatedUser['store_name'];

        return view('mobile.form', $data);
    }
}
