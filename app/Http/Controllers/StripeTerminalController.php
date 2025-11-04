<?php

namespace App\Http\Controllers;

use Stripe\Stripe;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\PaymentAttempt;
use App\Constant\CommonConstant;
use App\Models\UserPaymentGateway;
use Illuminate\Support\Facades\Log;
use Stripe\Terminal\ConnectionToken;
use App\Services\StripeTerminalPaymentService;

class StripeTerminalController extends Controller
{
    use ResponseTrait;

    protected $paymentService;

    /**
     * StripeTerminalController constructor.
     *
     * Initializes the Stripe API key and StripeTerminalPaymentService
     * for the authenticated user based on their active Stripe gateway credentials.
     *
     * @throws \Exception If no active Stripe payment gateway is found for the user.
     */
    public function __construct()
    {
        // Get the authenticated user's ID
        $authenticatedUser = auth()->user()->id;

        // Retrieve the user's active Stripe payment gateway configuration
        $userGateway = UserPaymentGateway::where([
            'user_id' => $authenticatedUser,
            'status' => '1',
        ])->first();

        // Throw an exception if no active Stripe gateway is found
        if (!$userGateway) {
            throw new \Exception('No active Stripe payment gateway with POS support found for user');
        }

        // Get the Stripe credentials as an associative array (key => value)
        $credentials = $userGateway->userPaymentCredentials->pluck('value', 'key')->toArray() ?: [];

        // Set the Stripe API key for the SDK
        Stripe::setApiKey($credentials['secret_key']);

        // Initialize the StripeTerminalPaymentService with the credentials
        $this->paymentService = new StripeTerminalPaymentService(
            $credentials['secret_key'],
            $userGateway->is_live_mode,
            'usd'
        );

        // Log the initialization of the Stripe API key for the user
        Log::info('Stripe API key initialized for user', ['user_id' => $authenticatedUser]);
    }

    /**
     * Create a new Stripe Terminal connection token.
     *
     * This method attempts to generate a new connection token using the Stripe Terminal API.
     * It logs the attempt and the result, returning a success response with the token's secret
     * if successful, or an error response if an exception occurs.
     *
     * @return \Illuminate\Http\JsonResponse JSON response containing the connection token secret on success,
     *                                       or an error message on failure.
     */
    public function createConnectionToken()
    {
        try {
            Log::info('Attempting to create connection token');
            $token = ConnectionToken::create();
            Log::info('Connection token created successfully', ['secret' => $token->secret]);
            return $this->successResponseWithData('Connection token created successfully', ['secret' => $token->secret]);
        } catch (\Exception $e) {
            Log::error('Failed to create connection token', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to create connection token', $e->getMessage(), 400);
        }
    }

    /**
     * Create a Stripe PaymentIntent and log a payment attempt.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPaymentIntent(Request $request)
    {
        \Log::info('Creating payment intent', ['request' => $request->all()]);
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'member_email' => 'nullable|email',
            'member_name' => 'nullable|string',
            'comment' => 'nullable|string'
        ]);

        try {
            $authenticatedUser = auth()->user();
            $store_id = auth()->user()->store_id;
            $temp_order_number = rand(100, 10000);

            // Create PaymentAttempt
            $paymentAttempt = PaymentAttempt::create([
                'user_id' => $authenticatedUser->id,
                'store_id' => $store_id,
                'temp_order_number' => $temp_order_number,
                'member_email' => $request->member_email ?? null,
                'member_name' => $request->member_name ?? null,
                'gateway' => 'Stripe POS Terminal ',
                'amount' => $request->amount,
                'status' => CommonConstant::STATUS_ATTEMPT,
                'comment' => $request->comment ?? 'Payment attempt via Stripe Terminal',
            ]);

            // Create PaymentIntent with metadata
            $paymentIntent = $this->paymentService->createPaymentIntent($request->amount, $request->currency, [
                'metadata' => [
                    'user_id' => $authenticatedUser->id,
                    'payment_attempt_id' => $paymentAttempt->id,
                    'payment_source' => 'Stripe POS Terminal',
                ],
            ]);
            if (isset($paymentIntent['error'])) {
                $paymentAttempt->update([
                    'status' => CommonConstant::STATUS_ERROR,
                    'comment' => $paymentIntent['error']
                ]);
                return $this->errorResponse($paymentIntent['error'], [], 400);
            }

            // Update PaymentAttempt
            $paymentAttempt->update([
                'transaction_id' => $paymentIntent->id,
                'comment' => $paymentIntent->status
            ]);

            return $this->successResponseWithData('Payment intent created successfully', [
                'client_secret' => $paymentIntent->client_secret,
                'id' => $paymentIntent->id,
                'payment_attempt_id' => $paymentAttempt->id
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to create payment intent', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to create payment intent', $e->getMessage(), 400);
        }
    }
}