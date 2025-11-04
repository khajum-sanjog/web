<?php

namespace App\Http\Controllers;

use Log;
use config;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\UserPaymentGateway;
use App\Services\ValidationService;
use Illuminate\Support\Facades\Http;
use App\Services\PaymentServiceFactory;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\AuthorizeController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles payment-related operations and processing.
 */
class PaymentController extends Controller
{
    use ResponseTrait;

    /**
     * @var paymentServiceFactory The payment service instance
     */
    private $paymentServiceFactory;
    private $validationService;

    /**
     * PaymentController constructor.
     *
     * @param PaymentServiceFactory $paymentServiceFactory The payment service dependency
     */
    public function __construct(PaymentServiceFactory $paymentServiceFactory, ValidationService $validationService)
    {
        $this->paymentServiceFactory = $paymentServiceFactory;
        $this->validationService = $validationService;
    }

    /**
     * Loads the payment UI based on the user's active payment gateway.
     *
     * @param Request $request The HTTP request containing payment details
     * @return \Illuminate\Http\JsonResponse JSON response with UI data or error
     */
    public function loadPaymentUI(Request $request)
    {
        Log::info('Loading payment UI', ['request' => $request->all()]);
        $authenticatedUser = auth()->user();

        // Fetch the user's active payment gateway
        $gateway = $authenticatedUser->paymentGateways()->where('status', '1')->first();

        if (!$gateway) {
            return $this->errorResponse('No active payment gateway found', [], Response::HTTP_NOT_FOUND);
        }

        // Route to the appropriate controller based on gateway
        try {
            if (strtolower($gateway->payment_gateway_name) === 'stripe') {
                return app()->make(StripeController::class)->stripeFormLoader($request);
            } elseif (strtolower($gateway->payment_gateway_name) === 'authorize.net') {
                return app()->make(AuthorizeController::class)->authorizeFormLoader($request);
            } else {
                return $this->errorResponse('Unsupported payment gateway', [], Response::HTTP_NOT_FOUND);
            }
        } catch (\Exception $e) {
            Log::error('Failed to load payment UI', [
                'gateway' => $gateway->payment_gateway_name,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Failed to load payment UI', [$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Process a payment transaction.
     *
     * This method handles the payment processing flow including validation,
     * authentication, and interaction with the payment service.
     *
     * @param Request $request The HTTP request containing payment details
     * @return \Illuminate\Http\JsonResponse JSON response with payment result
     */
    public function processPayment(Request $request)
    {
        \Log::info('Processing payment', ['request' => $request->all()]);
        $authenticatedUser = auth()->user()->id;
        $store_id = auth()->user()->store_id;
        $temp_order_number = rand(100, 10000);

        // Fetch the gateway with credentials
        $gateway = UserPaymentGateway::with(['userPaymentCredentials'])
            ->where([
                'user_id' => $authenticatedUser,
                'status' => '1',
            ])->first();

        if (!$gateway) {
            return $this->errorResponse('No active payment gateway found', [], Response::HTTP_NOT_FOUND);
        }

        // Build credentials array from user_payment_credentials
        $credentials = $gateway->userPaymentCredentials->pluck('value', 'key')->toArray();

        $validationRules = [
            'amount' => 'required|numeric|min:0.01',
            // 'cart_id' => 'required',
            'member_email' => 'required|email',
            'member_name' => 'required|string',
            'comment' => 'nullable|string',
            'payment_method_id' => 'required|string',
            'descriptor' => 'nullable|string',
        ];

        $validated = $request->validate($validationRules);

        $validated['user_id'] = $authenticatedUser;
        $validated['store_id'] = $store_id;
        $validated['temp_order_number'] = $temp_order_number;
        $validated['gateway'] = $gateway->payment_gateway_name;

        // Make API call to external Url to verify the payment amount
        $result = $this->validationService->getAmountFromCart($request->cart_id, auth()->user()->store_id, $request->amount);
        if ($result !== true) {
            return $this->errorResponse('Amount validation failed', [], Response::HTTP_BAD_REQUEST);
        }

        // only set the amount if external API confirmed it
        $validated['amount'] = $request->amount;

        $gatewayName = $gateway->payment_gateway_name;
        // Check if this is a POS payment - always use Stripe for POS regardless of primary gateway
        if (isset($validated['descriptor']) && $validated['descriptor'] === 'POS_PAY') {
            Log::info('POS payment detected, routing to Stripe regardless of primary gateway', [
                'user_id' => $authenticatedUser,
                'primary_gateway' => $gateway->payment_gateway_name,
                'temp_order_number' => $temp_order_number
            ]);
            $gatewayName = 'Stripe';
            $validated['gateway'] = 'Stripe POS Terminal';
        }

        // Initialize the payment service with credentials
        $paymentService = $this->paymentServiceFactory->getService(
            $gatewayName,
            $credentials,
            $gateway->is_live_mode,
        );

        if (!$paymentService || (is_array($paymentService) && !$paymentService['success'])) {
            $errorMessage = is_array($paymentService) ? $paymentService['message'] : 'Invalid payment gateway.';
            return $this->errorResponse($errorMessage, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $response = $paymentService->processPayment($validated);
            if ($response['success']) {
                return $this->successResponseWithData('Payment processed successfully', $response);
            } else {
                \Log::error('Payment processing failed', ['response' => $response]);
                return $this->errorResponse('Payment processing failed', $response, $response['http_status'] ?? Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            \Log::error('Payment processing error', ['error' => $e->getMessage()]);
            return $this->errorResponse($e->getMessage(), [], $e->getCode());
        }
    }

    /**
     * Processes a refund for a previous payment transaction.
     *
     * This method handles the refund flow by validating the request, retrieving
     * the authenticated user's payment gateway, and interacting with the payment
     * service to process the refund.
     *
     * @param Request $request The HTTP request containing refund details
     * @return JsonResponse JSON response with refund result
     * @throws \Illuminate\Validation\ValidationException If validation fails
     */
    public function processRefund(Request $request)
    {
        $authenticatedUser = auth()->user()->id;

        $validationRules = [
            'transaction_id' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
        ];

        $validated = $request->validate($validationRules);

        $validated['user_id'] = $authenticatedUser;
        $validated['store_id'] = auth()->user()->store_id;
        $validated['member_email'] = auth()->user()->email;
        $validated['member_name'] = auth()->user()->name;

        // Fetch the gateway (specific ID or preferred)
        $gateway = UserPaymentGateway::where([
            'user_id' => $authenticatedUser,
            'status' => '1'
        ])->first();

        if (!$gateway) {
            return $this->errorResponse('No active payment gateway found', [], Response::HTTP_NOT_FOUND);
        }

        $validated['gateway'] = $gateway->payment_gateway_name;

        // Build credentials array from user_payment_credentials
        $credentials = $gateway->userPaymentCredentials->pluck('value', 'key')->toArray();

        // Initialize the payment service with credentials
        $paymentService = $this->paymentServiceFactory->getService(
            $gateway->payment_gateway_name,
            $credentials,
            $gateway->is_live_mode
        );

        if (!$paymentService || (is_array($paymentService) && !$paymentService['success'])) {
            $errorMessage = is_array($paymentService) ? $paymentService['message'] : 'Invalid payment gateway.';
            return $this->errorResponse($errorMessage, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $response = $paymentService->refundTransactionV2($validated);

            if ($response['success']) {
                return $this->successResponseWithData('Refund processed successfully', $response);
            }

            return $this->errorResponse('Refund processing failed', $response, $response['http_status'] ?? Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            $statusCode = $e->getCode() > 0 && $e->getCode() <= 599 ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->errorResponse($e->getMessage(), [], $statusCode);
        }
    }


    /**
     * Processes a void for an unsettled payment transaction.
     *
     * This method handles the void flow by validating the request, retrieving
     * the authenticated user's payment gateway, and interacting with the payment
     * service to void the transaction. Voiding is typically used for transactions
     * that are captured but not yet settled.
     *
     * @param Request $request The HTTP request containing void details
     * @return JsonResponse JSON response with void result
     * @throws \Illuminate\Validation\ValidationException If validation fails
     */
    public function processVoid(Request $request)
    {
        $authenticatedUser = auth()->user()->id;

        $validationRules = [
            'transaction_id' => 'required|string',
        ];

        $validated = $request->validate($validationRules);

        $validated['user_id'] = $authenticatedUser;
        $validated['store_id'] = auth()->user()->store_id;
        $validated['member_email'] = auth()->user()->email;
        $validated['member_name'] = auth()->user()->name;

        // Fetch the gateway
        $gateway = UserPaymentGateway::where([
            'user_id' => $authenticatedUser,
            'status' => '1'
        ])->first();

        if (!$gateway) {
            return $this->errorResponse('No active payment gateway found', [], Response::HTTP_NOT_FOUND);
        }

        $validated['gateway'] = $gateway->payment_gateway_name;

        // Build credentials array from user_payment_credentials
        $credentials = $gateway->userPaymentCredentials->pluck('value', 'key')->toArray();

        // Initialize the payment service with credentials
        $paymentService = $this->paymentServiceFactory->getService(
            $gateway->payment_gateway_name,
            $credentials,
            $gateway->is_live_mode
        );

        if (!$paymentService || (is_array($paymentService) && !$paymentService['success'])) {
            $errorMessage = is_array($paymentService) ? $paymentService['message'] : 'Invalid payment gateway.';
            return $this->errorResponse($errorMessage, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $response = $paymentService->voidTransactionV2($validated);

            if ($response['success']) {
                return $this->successResponseWithData('Transaction voided successfully.', $response, Response::HTTP_OK);
            }

            return $this->errorResponse('Void processing failed', $response, $response['http_status'] ?? Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            $statusCode = $e->getCode() > 0 && $e->getCode() <= 599 ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->errorResponse($e->getMessage(), [], $statusCode);
        }
    }

    /**
     * Retrieves transaction details for a specific transaction ID.
     *
     * This method handles the retrieval of transaction details by validating
     * the request, retrieving the authenticated user's payment gateway, and
     * interacting with the payment service to fetch the transaction details.
     *
     * @param Request $request The HTTP request containing transaction ID
     * @return JsonResponse JSON response with transaction details result
     * @throws \Illuminate\Validation\ValidationException If validation fails
     */
    public function getTransactionDetails(Request $request)
    {
        $authenticatedUser = auth()->user()->id;

        $isLive = $request->query('isLive'); // Returns "true" as a string

        $validationRules = [
            'transaction_id' => 'required|string',
            'isLive' => 'nullable|in:true,false', // Strict validation
        ];

        $validated = $request->validate($validationRules);
        $validated['isLive'] = $request->query('isLive', 'false'); // Default to false

        // Fetch the gateway
        $gateway = UserPaymentGateway::where([
            'user_id' => $authenticatedUser,
            'status' => '1'
        ])->first();

        if (!$gateway) {
            return $this->errorResponse('No active payment gateway found', [], Response::HTTP_NOT_FOUND);
        }

        $validated['user_id'] = $authenticatedUser;

        $credentials = $gateway->userPaymentCredentials->pluck('value', 'key')->toArray();

        // Initialize the payment service with credentials
        $paymentService = $this->paymentServiceFactory->getService(
            $gateway->payment_gateway_name,
            $credentials,
            $gateway->is_live_mode
        );

        if (!$paymentService || (is_array($paymentService) && !$paymentService['success'])) {
            $errorMessage = is_array($paymentService) ? $paymentService['message'] : 'Invalid payment gateway.';
            return $this->errorResponse($errorMessage, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $response = $paymentService->getTransactionDetails($validated);

            if ($response['success']) {
                return $this->successResponseWithData('Transaction details retrieved successfully.', $response, Response::HTTP_OK);
            }

            return $this->errorResponse('Failed to retrieve transaction details', $response, $response['http_status'] ?? Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), [], $e->getCode());
        }
    }
}
