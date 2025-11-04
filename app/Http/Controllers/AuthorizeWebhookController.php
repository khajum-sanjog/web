<?php

namespace App\Http\Controllers;

use config;
use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use App\Models\PaymentAttempt;
use App\Constant\CommonConstant;
use App\Models\UserPaymentGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AuthorizeWebhookController extends Controller
{
    use ResponseTrait;
    
    private static $deletedWebhooks = [];

    /**
     * Create an Authorize.net webhook endpoint for a specific user.
     *
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function createUserWebhook($userId)
    {
        try {
            // Find user or throw 404
            $user = User::where('id', $userId)
                ->where('status', '1')
                ->firstOrFail();

            // Find active Authorize.net payment gateway
            $userPaymentGateway = UserPaymentGateway::where([
                'user_id' => $userId,
                'status' => '1',
                'payment_gateway_name' => 'Authorize.net',
            ])->first();

            if (!$userPaymentGateway) {
                Log::warning('No Authorize.net payment gateway found for user', ['user_id' => $userId]);
                return $this->errorResponse('No active payment gateway configured for user', [], Response::HTTP_NOT_FOUND);
            }

            // Get credentials as array
            $credentials = $userPaymentGateway->userPaymentCredentials->pluck('value', 'key')->toArray();
            Log::info('User Payment Gateway initialized', [
                'user_id' => $userId,
                'gateway_id' => $userPaymentGateway->id
            ]);

            // Prepare webhook details
            $appUrl = config('configuration.appUrl');
            $webhookUrl = rtrim($appUrl, '/') . '/api/webhook/authorize/user/' . $userId;
            $enabledEvents = [
                'net.authorize.payment.authcapture.created',
                'net.authorize.payment.void.created',
                'net.authorize.payment.refund.created',
            ];

            // Check for existing webhook first 
            $existingWebhookId = $userPaymentGateway->userPaymentCredentials()
                ->where('key', 'webhook_id')
                ->first();

            if ($existingWebhookId) {
                $validWebhook = $this->validateWebhook($existingWebhookId->value, $enabledEvents, $credentials, $webhookUrl, $userId);
                if ($validWebhook) {
                    return $this->successResponseWithData(
                        'Webhook already exists', 
                        ['webhook_id' => $validWebhook['webhook_id']]
                    );
                }
            }

            // Check for previous Authorize.Net webhook for this user (from when they used Authorize.Net before)
            $previousAuthorizeGateway = UserPaymentGateway::where('user_id', $userId)
                ->where('payment_gateway_name', 'Authorize.net')
                ->where('id', '!=', $userPaymentGateway->id) // Exclude current gateway
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($previousAuthorizeGateway) {
                $previousWebhookId = $previousAuthorizeGateway->userPaymentCredentials()
                    ->where('key', 'webhook_id')
                    ->first();

                if ($previousWebhookId) {
                    $validWebhook = $this->validateWebhook(
                        $previousWebhookId->value, 
                        $enabledEvents,
                        $credentials, 
                        $webhookUrl, 
                        $userId, 
                        $previousAuthorizeGateway->id
                    );
                    
                    if ($validWebhook) {
                        // Copy webhook credentials to current gateway
                        $userPaymentGateway->userPaymentCredentials()->updateOrCreate(
                            ['key' => 'webhook_id'],
                            ['value' => $previousWebhookId->value]
                        );
                        
                        return $this->successResponseWithData(
                            'Previous webhook reused', 
                            ['webhook_id' => $previousWebhookId->value]
                        );
                    }
                }
            }

            // Clear existing webhook credentials to ensure a clean slate
            $userPaymentGateway->userPaymentCredentials()->whereIn('key', ['webhook_id'])->delete();
            Log::info('Cleared existing webhook credentials', [
                'user_id' => $userId,
                'payment_gateway_id' => $userPaymentGateway->id
            ]);

            // Create new webhook
            $createResponse = $this->createWebhookViaAPI($credentials, $webhookUrl, $enabledEvents);

            if (empty($createResponse['webhookId'])) {
                Log::error('Failed to create Authorize.net webhook', [
                    'user_id' => $userId,
                    'body' => $createResponse->body()
                ]);
                return $this->errorResponse('Failed to create webhook', [], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Log the new webhook details
            Log::info('New Authorize.net webhook created', [
                'user_id' => $userId,
                'webhook_id' => $createResponse['webhookId'],
                'url' => $createResponse['url'],
                'event_types' => $createResponse['eventSubscribed']
            ]);

            // Save webhook ID
            $userPaymentGateway->userPaymentCredentials()->updateOrCreate(
                ['key' => 'webhook_id'],
                ['value' => $createResponse['webhookId']]
            );

            $userPaymentGateway->updated_at = now();
            $userPaymentGateway->save();

            return $this->successResponseWithData('Webhook created successfully', [
                'webhook_id'      => $createResponse['webhookId'],
                'url'             => $createResponse['url'],
                'status'          => $createResponse['status'],
                'eventSubscribed' => $createResponse['eventSubscribed']
            ], Response::HTTP_OK);

        } catch (ModelNotFoundException $e) {
            Log::error('User not found', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->errorResponse('User not found', [], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            Log::error('Failed to manage Authorize.net webhook', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Failed to manage webhook: ' . $e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create webhook via direct API call
     * Note: This is a placeholder implementation. Authorize.Net webhooks are typically
     * configured through the merchant interface.
     *
     * @param array $credentials
     * @param string $webhookUrl
     * @param array $enabledEvents
     * @return string|null
     */
    private function createWebhookViaAPI($credentials, $webhookUrl, $enabledEvents)
    {
        try {
            $apiUrl = config('configuration.appEnv') === 'production' ?
               'https://api.authorize.net/rest/v1/webhooks' :
               'https://apitest.authorize.net/rest/v1/webhooks';

            $payload = [
                'url' => $webhookUrl,
                'eventTypes' => $enabledEvents,
                'status' => 'active'
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($credentials['login_id'] . ':' . $credentials['transaction_key'])
            ])->post($apiUrl, $payload);

            if ($response->successful()) {
                $data = $response->json();
                Log::info($data);
                // Return webhook ID
                return [
                    'webhookId'       => $data['webhookId'] ?? null,
                    'url'             => $data['url'] ?? null,
                    'status'          => $data['status'] ?? null,
                    'eventSubscribed' => $data['eventTypes'] ?? null
                ];
            }

            Log::error('Webhook API call failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Webhook API call exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Delete an Authorize.Net webhook via API
     *
     * @param array $credentials
     * @param string $webhookId
     * @return array|null
     */
    private function deleteWebhookViaAPI($credentials, $webhookId)
    {
        try {
            $apiUrl = config('configuration.appEnv') === 'production' ?
                'https://api.authorize.net/rest/v1/webhooks' :
                'https://apitest.authorize.net/rest/v1/webhooks';

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($credentials['login_id'] . ':' . $credentials['transaction_key'])
            ])->delete($apiUrl.'/'.$webhookId);

            if ($response->successful()) {
                Log::info('Authorize.Net webhook deleted successfully', [
                    'webhook_id' => $webhookId,
                    'status' => $response->status()
                ]);
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'webhook_id' => $webhookId
                ];
            }

            Log::warning('Authorize.Net webhook deletion failed', [
                'webhook_id' => $webhookId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'webhook_id' => $webhookId
            ];

        } catch (\Exception $e) {
            Log::error('Authorize.Net webhook deletion exception', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'webhook_id' => $webhookId
            ];
        }
    }

    /**
     * Handle incoming Authorize.net webhook events for a specific user.
     *
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request, $userId)
    {
        $payload = $request->getContent();

        // Decode the JSON string into a PHP object
        $decodedPayload = json_decode($payload, false); // false for object, true for array

        // Extract the invoice number
        $invoiceNumber = $decodedPayload->payload->invoiceNumber;

        // Extract user ID from the invoice number (format: ATT-<userId>-<...>)
        $parts = explode('-', $invoiceNumber);
        $invoiceUserId = $parts[1] ?? null;

        \Log::info('Received Authorize.Net webhook', [
           'user_id' => $userId,
           'event_type' => $decodedPayload->eventType ?? 'unknown',
           'payload' => $decodedPayload
        ]);

        // Validate that the route user ID matches the invoice user ID
        if ($invoiceUserId != $userId) {
            \Log::info('Webhook event belongs to different user, skipping', [
                'webhook_user_id' => $userId,
                'event_user_id' => $invoiceUserId,
                'event_type' => $decodedPayload->eventType ?? 'unknown',
                'invoice_number' => $invoiceNumber
            ]);
            return $this->successResponse('Webhook event processed', 200);
        }

        try {
            $user = User::findOrFail($userId);
            $userPaymentGateway = $user->paymentGateways()->where([
                'status' => '1',
                'payment_gateway_name' => 'Authorize.net'
            ])->first();

            if (!$userPaymentGateway) {
                Log::warning('No Authorize.net payment gateway found for user', ['user_id' => $userId]);
                return $this->errorResponse('No active Authorize.net payment gateway configured for user', [], 404);
            }

            $credentials = $userPaymentGateway->userPaymentCredentials->pluck('value', 'key') ?? [];

            $signature = $request->header('X-Anet-Signature');

            // Verify webhook signature if signing key is available
            if (!empty($credentials['signing_key'])) {
                if (!$this->verifySignature($payload, $signature, $credentials['signing_key'])) {
                    return $this->errorResponse('Invalid signature', [], 401);
                }
            } else {
                Log::info('No signing key configured.', ['user_id' => $userId]);
                return $this->errorResponse('No signing key configured', [], 500);
            }

            $event = json_decode($payload, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Invalid JSON payload', ['user_id' => $userId, 'payload' => $payload]);
                return $this->errorResponse('Invalid JSON payload', [], 400);
            }

            $eventType = $event['eventType'] ?? '';
            $eventId = $event['payload']['id'] ?? null;

            switch ($eventType) {
                case 'net.authorize.payment.authcapture.created':
                    return $this->handleAuthCaptureCreated($event, $userId, $userPaymentGateway->id);
                case 'net.authorize.payment.void.created':
                    return $this->handleVoidCreated($event, $userId, $userPaymentGateway->id);
                case 'net.authorize.payment.refund.created':
                    return $this->handleRefunded($event, $userId, $userPaymentGateway->id);
                default:
                    \Log::warning('Unhandled Authorize.Net webhook event type', [
                        'event_type' => $eventType,
                        'user_id' => $userId,
                    ]);
                    return $this->successResponse('Event received but not processed', 200);
            }
        } catch (ModelNotFoundException $e) {
            \Log::error('User not found', ['userId' => $userId, 'error' => $e->getMessage()]);
            return $this->errorResponse('User not found', [], 404);
        } catch (\Exception $e) {
            \Log::error('Webhook processing error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Webhook processing failed', [], 500);
        }
    }

    /**
     * Verify Authorize.net webhook signature.
     *
     * @param string $payload
     * @param string $signature
     * @param string $signingKey
     * @return bool
     */
    private function verifySignature($payload, $signature, $signingKey)
    {

        // Generate signature and compare case-insensitively
        $expectedSignature = 'sha512=' . hash_hmac('sha512', $payload, $signingKey);

        Log::debug('Signature Comparison', [
            'normalized_match' => strcasecmp($signature, $expectedSignature) === 0,
        ]);

        return strcasecmp($signature, $expectedSignature) === 0;
    }


    /**
     * Handles the 'auth.capture.created' event from the payment gateway webhook.
     *
     * Processes the event data, updates the payment status, and performs any necessary
     * business logic when an authorization capture is created.
     *
     * @param array $event The event payload received from the webhook.
     * @param int $userId The ID of the user associated with the payment.
     * @param int $paymentGatewayId The ID of the payment gateway used for the transaction.
     * @return void
     */
    private function handleAuthCaptureCreated($event, $userId, $paymentGatewayId)
    {
        \Log::info('Handling auth.capture.created event', [
        'event' => json_encode($event, JSON_PRETTY_PRINT),
        ]);

        $payload = $event['payload'] ?? [];

        // Extract payment attempt ID from invoice number
        $invoiceNumber = $payload['invoiceNumber'] ?? '';

        // Split the invoice number to extract its components (format: ATT-<userId>-<paymentAttemptId>)
        $parts = explode('-', $invoiceNumber);

        $paymentAttemptId = $parts[2];

        \Log::info('Payment attempt id', [$paymentAttemptId]);

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'transaction_id' => $payload['id'] ?? null,
            'payment_attempt_id' => $paymentAttemptId,
            'amount' => $payload['authAmount'] ?? null,
            'status' => $payload['responseCode'] ?? null,
        ];

        Log::info('Processing net.authorize.payment.authcapture.created', $logData);

        if ($paymentAttemptId) {
            PaymentAttempt::where('id', $paymentAttemptId)
                ->where('user_id', $userId)
                ->update([
                    'status' => CommonConstant::STATUS_PAID, // Paid
                    'transaction_id' => $payload['id'] ?? null,
                    'comment' => 'Payment captured with Transaction ID ' . ($payload['id'] ?? 'N/A'),
                    'charge_id' => $payload['authCode'],
                    'payment_handle_comment' => 'Payment successful',
                    'updated_at' => now(),
                ]);
        }

        return $this->successResponse('authcapture event processed successfully', 200);
    }

    /**
     * Handle net.authorize.payment.void.created event.
     *
     * @param array $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleVoidCreated($event, $userId, $paymentGatewayId)
    {
        \Log::info('Processing net.authorize.payment.void.created');
        $payload = $event['payload'] ?? [];
        $transactionId = $payload['id'] ?? null;
        $amount = (float)($payload['authAmount'] ?? 0);

        // Find matching void attempt (negative amount)
        $voidAttempt = PaymentAttempt::where([
            'transaction_id' => $transactionId,
            'user_id' => $userId,
        ])
        ->whereRaw('ROUND(amount, 2) = ?', [-$amount]) // Exact decimal match
        ->first();

        if (!$voidAttempt) {
            \Log::warning('Void attempt not found', compact('transactionId', 'userId', 'amount'));
            return $this->successResponse('Void processed (no matching attempt found)', 200);
        }

        $voidAttempt->update([
            'status' => CommonConstant::STATUS_VOID, // Void
            'comment' => 'Payment voided with transaction ID ' . $transactionId . ' for $' . $amount,
            'payment_handle_comment' => 'Void Successful',
        ]);

        \Log::info('Void event processed successfully');
        return $this->successResponse('Void event processed successfully', 200);
    }

    /**
     * Handle net.authorize.payment.refund.created event.
     *
     * @param array $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleRefunded($event, $userId, $paymentGatewayId)
    {
        \Log::info('Processing net.authorize.payment.refund.created');
        $payload = $event['payload'] ?? [];

        // 1. Extract and validate refund attempt ID
        $invoiceParts = explode('-', $payload['invoiceNumber'] ?? '');
        $refundAttemptId = $invoiceParts[2] ?? null;

        if (!$refundAttemptId) {
            Log::warning('Invalid invoice number format, refund attempt id not found', ['invoice' => $payload['invoiceNumber'] ?? '']);
        }

        // 2. Find and update refund attempt
        $refundAttempt = PaymentAttempt::where([
            'id' => $refundAttemptId,
            'user_id' => $userId,
        ])->first();

        if ($refundAttempt) {
            $refundAmount = (float)($payload['authAmount'] ?? 0);

            $refundAttempt->update([
                'refund_void_transaction_id' => $payload['id'] ?? null,
                'status' => CommonConstant::STATUS_REFUND, // Refunded
                'comment' => 'Payment refunded with transaction ID ' . ($payload['id'] ?? 'N/A') . ' for $' . ($payload['authAmount'] ?? 'N/A'),
                'payment_handle_comment' => 'Refund Successful',
            ]);

            Log::info('Payment refund processed', [
                'attempt_id' => $refundAttemptId,
                'amount' => $refundAmount
            ]);
        } else {
            Log::warning('Refund attempt not found', [
                'attempt_id' => $refundAttemptId,
                'user_id' => $userId
            ]);
        }

        return $this->successResponse('Refund event processed successfully', 200);
    }

    /**
     * Validate if an Authorize.Net webhook still exists and is active
     * Handles validation, logging, and cleanup in a single function
     */
    private function validateWebhook($webhookId, $enabledEvents, $credentials, $expectedUrl, $userId, $gatewayId = null)
    {
        try {
            // Build the API URL for getting specific webhook
            $apiUrl = config('configuration.appEnv') === 'production' ?
                'https://api.authorize.net/rest/v1/webhooks' :
                'https://apitest.authorize.net/rest/v1/webhooks';

            $webhookUrl = "{$apiUrl}/{$webhookId}";

            // Make GET request to Authorize.net API to fetch specific webhook
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($credentials['login_id'] . ':' . $credentials['transaction_key'])
            ])->get($webhookUrl);

            // Check if the request was successful
            if ($response->failed()) {
                Log::warning('Failed to fetch existing Authorize.net webhook', [
                    'webhook_id' => $webhookId,
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            $webhook = $response->json();
            Log::info('Fetched existing Authorize.net webhook', [
                'webhook_id' => $webhookId,
                'user_id' => $userId,
                'webhook' => $webhook
            ]);

            // Validate webhook properties
            $reasons = [];
            
            if (!isset($webhook['url'])) {
                $reasons[] = 'URL field missing';
            } elseif ($webhook['url'] !== $expectedUrl) {
                $reasons[] = 'URL mismatch';
            }
            
            if (!isset($webhook['status'])) {
                $reasons[] = 'Status field missing';
            } elseif (strtolower($webhook['status']) !== 'active') {
                $reasons[] = 'Status not active';
            }
            
            // Sort both arrays to ensure consistent comparison
            $existingEvents = $webhook['eventTypes'] ?? [];
            $expectedEvents = $enabledEvents;
            sort($existingEvents);
            sort($expectedEvents);
            
            if ($existingEvents !== $expectedEvents) {
                $reasons[] = 'Events mismatch';
            }
            
            // Webhook is valid - return success
            if (empty($reasons)) {
                $logData = [
                    'user_id' => $userId,
                    'webhook_id' => $webhookId,
                    'url' => $webhook['url'],
                    'status' => $webhook['status']
                ];
                
                if ($gatewayId) {
                    $logData['previous_gateway_id'] = $gatewayId;
                    Log::info('Reusing previous Authorize.Net webhook', $logData);
                } else {
                    Log::info('User already has valid Authorize.Net webhook', $logData);
                }
                
                return ['webhook_id' => $webhookId];
            }

            // Webhook is invalid - log reasons and delete
            Log::info('Existing webhook validation failed - will create new webhook', [
                'reasons' => $reasons,
                'webhook_id' => $webhookId,
                'user_id' => $userId,
                'expected_url' => $expectedUrl,
                'actual_url' => $webhook['url'] ?? 'N/A',
                'expected_status' => 'active',
                'actual_status' => $webhook['status'] ?? 'N/A',
                'expected_events' => $enabledEvents,
                'actual_events' => $webhook['eventTypes'] ?? 'N/A',
            ]);

            // Delete invalid webhook if not already deleted
            if (!in_array($webhookId, self::$deletedWebhooks)) {
                try {
                    $deleteResponse = $this->deleteWebhookViaAPI($credentials, $webhookId);
                    self::$deletedWebhooks[] = $webhookId; // Track deletion
                    
                    Log::info('Deleted invalid Authorize.Net webhook', [
                        'user_id' => $userId,
                        'webhook_id' => $webhookId,
                        'reason' => implode(', ', $reasons),
                        'delete_response' => $deleteResponse
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete invalid Authorize.Net webhook', [
                        'user_id' => $userId,
                        'webhook_id' => $webhookId,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                Log::info('Authorize.Net webhook already deleted in this request, skipping', [
                    'user_id' => $userId,
                    'webhook_id' => $webhookId
                ]);
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Failed to validate existing Authorize.net webhook', [
                'webhook_id' => $webhookId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

}
