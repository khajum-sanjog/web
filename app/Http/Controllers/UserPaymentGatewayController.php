<?php

namespace App\Http\Controllers;

use Stripe\StripeClient;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\PaymentGateway;
use App\Models\UserPaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\AuthorizeWebhookController;

class UserPaymentGatewayController extends Controller
{
    use ResponseTrait;

    protected $stripeWebhookController;
    protected $authorizeWebhookController;

    public function __construct(StripeWebhookController $stripeWebhookController, AuthorizeWebhookController $authorizeWebhookController)
    {
        $this->stripeWebhookController = $stripeWebhookController;
        $this->authorizeWebhookController = $authorizeWebhookController;
    }

    /**
     * Create a new user payment gateway with credentials and settings.
     *
     * @param int $userId
     * @param PaymentGateway $paymentGateway
     * @param Request $request
     * @param array $paymentSlugs
     * @return UserPaymentGateway
     */
    private function createNewPaymentGateway($userId, $paymentGateway, $request, $paymentSlugs)
    {
        // Define shared keys
        $sharedKeys = ['gMerchant_id', 'aMerchant_id', 'reader_id'];

        // Create new user payment gateway
        $userPaymentGateway = UserPaymentGateway::create([
            'user_id' => $userId,
            'payment_gateway_id' => $request->payment_gateway_id,
            'payment_gateway_name' => $paymentGateway->name,
            'is_live_mode' => $request->is_live_mode ?? '1',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        // Find the previous payment gateway for the user (if any)
        $previousGateway = UserPaymentGateway::where('user_id', $userId)
            ->where('id', '!=', $userPaymentGateway->id)
            ->where('status', '0') // Recently deactivated
            ->with('userPaymentCredentials')
            ->first();

        // Store credentials
        foreach ($request->credentials as $key => $value) {
            $value = trim($value);
            if (empty($value)) {
                Log::info('Skipping empty credential', ['key' => $key, 'payment_gateway' => $paymentGateway->name]);
                continue;
            }

            // Default to the provided value for storage
            $storeValue = $value;

            // Check if the key is a shared key and if a previous gateway exists
            if (in_array($key, $sharedKeys) && $previousGateway) {
                // Detect if the value is masked (contains two or more asterisks)
                $isMasked = preg_match('/[\*]{2,}/', $value);
                if ($isMasked) {
                    // Retrieve the previous credential for this key
                    $previousCredential = $previousGateway->userPaymentCredentials->firstWhere('key', $key);
                    if ($previousCredential) {
                        // If the last 4 digits match, use the previous unmasked value
                        if (substr($value, -4) === substr($previousCredential->value, -4)) {
                            $storeValue = $previousCredential->value;
                            Log::info('Using previous credential for shared key', ['key' => $key]);
                        } else {
                            // Log error if masked value does not match previous credential
                            Log::error('Masked shared key does not match previous credential', ['key' => $key]);
                        }
                    } else {
                        // Log error if no previous credential is found for the masked key
                        Log::error('No previous credential found for masked shared key', ['key' => $key]);
                    }
                }
            }

            // Store the credential
            $userPaymentGateway->userPaymentCredentials()->create([
                'key' => $key,
                'value' => $storeValue,
            ]);
            Log::info('Created new credential', ['key' => $key, 'payment_gateway' => $paymentGateway->name]);
        }

        // Store payment settings
        foreach ($paymentSlugs as $slug) {
            if (!$request->has($slug)) {
                Log::warning('Missing payment setting in request', ['slug' => $slug]);
                continue;
            }

            $userPaymentGateway->userPaymentSettings()->create([
                'payment_type' => $slug,
                'value' => (string) $request->get($slug),
            ]);
            Log::info('Created payment setting', ['slug' => $slug]);
        }

        return $userPaymentGateway;
    }

    /**
     * Store or update the user's payment gateway, credentials, and settings.
     *
     * Handles validation, credential management, and payment settings for the authenticated user.
     * If a new gateway is selected, deactivates the previous one and creates a new entry.
     * If the same gateway is selected, updates credentials and settings as needed.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Fetch the authenticated user
        $authenticatedUser = auth()->user();

        // If the user's status is '0', returns an error response indicating the user is inactive.
        if ($authenticatedUser->status == '0') {
            return $this->errorResponse('Inactive User.', [], Response::HTTP_BAD_REQUEST);
        }

        // Fetch dynamic payment methods (slugs) from payment_gateways (wallets)
        $paymentSlugs = PaymentGateway::where('type', '1')
            ->where('status', '1')
            ->pluck('slug')
            ->toArray();

        // Ensure slugs are unique
        $paymentSlugs = array_unique($paymentSlugs);

        // Define base validation rules for the request
        $validationRules = [
            'payment_gateway_id' => 'required|integer|exists:payment_gateways,id',
            'credentials' => 'required|array',
            'is_live_mode' => 'nullable|in:0,1',
        ];

        // Dynamically add validation rules for each payment slug
        foreach ($paymentSlugs as $slug) {
            $validationRules[$slug] = 'required|in:0,1';
        }

        // Validate the incoming request against the rules
        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return $this->errorResponse('Validation error', $validator->errors()->toArray(), Response::HTTP_BAD_REQUEST);
        }

        // Check if at least one payment method is enabled
        $hasEnabledMethod = collect($paymentSlugs)->contains(fn($slug) => $request->get($slug) === '1');
        if (!$hasEnabledMethod) {
            return $this->errorResponse('At least one payment method must be enabled.', [], Response::HTTP_BAD_REQUEST);
        }

        // Fetch the payment gateway based on the provided ID
        $paymentGateway = PaymentGateway::with(['paymentGatewayKeys', 'wallets'])->where(['id' => $request->payment_gateway_id, 'status' => '1'])->first();
        if (!$paymentGateway) {
            return $this->errorResponse('Payment Gateway not found or inactive', [], Response::HTTP_NOT_FOUND);
        }

        // Fetch required keys for the primary gateway
        $requiredKeys = $paymentGateway->paymentGatewayKeys()->pluck('value')->toArray();

        // Filter enabled child gateways based on request slugs
        $enabledChildGateways = $paymentGateway->wallets->filter(function ($wallet) use ($request, $paymentSlugs) {
            return in_array($wallet->slug, $paymentSlugs) && $request->get($wallet->slug) === '1';
        });

        // Merge required keys from enabled child gateways
        foreach ($enabledChildGateways as $childGateway) {
            $childRequiredKeys = $childGateway->paymentGatewayKeys()
                ->where('parent', $paymentGateway->id)
                ->pluck('value')
                ->toArray();
            $requiredKeys = array_merge($requiredKeys, $childRequiredKeys);
        }

        // Clean and deduplicate required keys
        $requiredKeys = array_unique(array_filter(array_map('trim', $requiredKeys)));

        // Identify missing required keys in the credentials
        $missingKeys = array_diff($requiredKeys, array_keys($request->credentials ?: []));
        if (!empty($missingKeys)) {
            return $this->errorResponse("Missing required keys: " . implode(', ', $missingKeys), [], Response::HTTP_BAD_REQUEST);
        }

        // Validate Stripe requirements before transaction
        if (strtolower($paymentGateway->name) === 'stripe') {
            $secretKey = $request->credentials['secret_key'] ?? null;
            if (!$secretKey) {
                return $this->errorResponse('Stripe secret key is required', [], Response::HTTP_BAD_REQUEST);
            }
        }

        // Execute database transaction for atomic operations
        return DB::transaction(function () use ($request, $paymentGateway, $paymentSlugs, $requiredKeys, $authenticatedUser) {
            $userId = $authenticatedUser->id;
            $existingPaymentGateway = UserPaymentGateway::where('user_id', $userId)->where('status', '1')->first();
            $isNewGatewayCreated = false;

            if ($existingPaymentGateway) {
                $isNewGateway = ($existingPaymentGateway->payment_gateway_id != $request->payment_gateway_id);

                if ($isNewGateway) {
                    // Deactivate old gateway
                    $existingPaymentGateway->update([
                        'status' => '0',
                        'updated_by' => $userId,
                    ]);

                    // Create new gateway
                    $this->createNewPaymentGateway($userId, $paymentGateway, $request, $paymentSlugs);
                    $isNewGatewayCreated = true;

                } else {
                    // Update existing gateway details
                    $existingPaymentGateway->update([
                        'payment_gateway_name' => $paymentGateway->name,
                        'is_live_mode' => $request->input('is_live_mode', '1'),
                        'updated_by' => $userId,
                    ]);

                    // Define sensitive keys for partial update check
                    $sensitiveKeys = ['secret_key', 'transaction_key', 'client_key', 'gMerchant_id', 'aMerchant_id', 'signing_key'];
                    foreach ($request->credentials as $key => $value) {
                        $value = trim($value);
                        if (empty($value)) {
                            continue;
                        }

                        $existingCredential = $existingPaymentGateway->userPaymentCredentials()->where('key', $key)->first();
                        if (in_array($key, $sensitiveKeys) && $existingCredential) {
                            $expectedDigits = in_array($key, ['secret_key', 'client_key', 'signing_key']) ? 10 : 4;
                            if (substr($value, -$expectedDigits) === substr($existingCredential->value, -$expectedDigits)) {
                                continue;
                            }
                        }

                        $existingPaymentGateway->userPaymentCredentials()->updateOrCreate(
                            ['key' => $key],
                            ['value' => $value]
                        );
                    }

                    // Update payment settings for each slug
                    foreach ($paymentSlugs as $slug) {
                        $existingPaymentGateway->userPaymentSettings()->updateOrCreate(
                            ['payment_type' => $slug],
                            ['value' => (string) $request->get($slug)]
                        );
                    }

                }
            } else {
                // Create new user payment gateway if none exists
                $this->createNewPaymentGateway($userId, $paymentGateway, $request, $paymentSlugs);
                $isNewGatewayCreated = true;
            }

            // --- Webhook Creation Logic ---
            $primaryGatewayName = strtolower($paymentGateway->name);
            $supportedWebhookGateways = ['stripe', 'authorize.net'];

            // 1. Create webhook for the primary gateway if it's supported
            if (in_array($primaryGatewayName, $supportedWebhookGateways) && $authenticatedUser->status == '1') {
                $this->createWebhook($primaryGatewayName);
            }

            // 2. Additionally, create a Stripe webhook if the user is on Authorize.Net but enables Stripe POS
            if ($primaryGatewayName === 'authorize.net' && $request->input('has_pos_pay') === '1' && $authenticatedUser->status == '1') {
                Log::info('Primary gateway is Authorize.Net with POS enabled. Creating additional Stripe webhook.', ['user_id' => $authenticatedUser->id]);
                $this->createWebhook('stripe');
            }

            if (strtolower($paymentGateway->name) === 'stripe') {
                $secretKey = $request->credentials['secret_key'] ?? null;
                $stripe = new StripeClient($secretKey); // Use StripeClient

                $domain = $authenticatedUser->domain_name;

                try {
                    $paymentMethodDomain = $stripe->paymentMethodDomains->create([
                        'domain_name' => $domain,
                        'enabled' => true, // Explicitly enable Apple Pay
                    ]);
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    \Log::error("Apple Pay domain registration failed: " . $e->getMessage());
                }
            }

            // Return response based on whether it was created or updated
            return $this->successResponse(
                $isNewGatewayCreated ? 'User payment gateway created successfully' : 'User payment gateway updated successfully',
                $isNewGatewayCreated ? Response::HTTP_CREATED : Response::HTTP_OK
            );

        });
    }

    /**
     * Creates a webhook for the specified payment gateway.
     *
     * @param mixed $paymentGateway The payment gateway instance or identifier for which the webhook will be created.
     * @return mixed The result of the webhook creation process.
     */
    protected function createWebhook($paymentGateway)
    {
        $gatewayName = strtolower($paymentGateway);
        $webhookControllers = [
            'stripe' => $this->stripeWebhookController,
            'authorize.net' => $this->authorizeWebhookController,
        ];

        if (!array_key_exists($gatewayName, $webhookControllers)) {
            \Log::warning('Unsupported payment gateway for webhook creation', [
                'user_id' => auth()->id(),
                'gateway' => $gatewayName
            ]);
            return $this->errorResponse('Unsupported payment gateway', [], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $webhookControllers[$gatewayName]->createUserWebhook(auth()->id());
        } catch (\Exception $e) {
            \Log::error('Failed to create webhook', [
                'user_id' => auth()->id(),
                'gateway' => $gatewayName,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Failed to create webhook', ['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
