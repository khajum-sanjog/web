<?php

namespace App\Http\Controllers;

use Stripe\Stripe;
use Stripe\Webhook;
use App\Models\User;
use Stripe\WebhookEndpoint;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use App\Models\PaymentAttempt;
use App\Models\UserPaymentGateway;
use App\Constant\CommonConstant;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StripeWebhookController extends Controller
{
    use ResponseTrait;

    private static $deletedWebhooks = [];

    /**
     * Create a Stripe webhook endpoint for a specific user.
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

            // Find active Stripe payment gateway
            $userPaymentGateway = UserPaymentGateway::where([
                'user_id' => $userId,
                'status' => '1',
            ])->first();

            if (!$userPaymentGateway) {
                Log::warning('No Stripe POS payment gateway found for user', ['user_id' => $userId]);
                return $this->errorResponse('No active payment gateway configured for user', [], Response::HTTP_NOT_FOUND);
            }

            $credentials = $userPaymentGateway->userPaymentCredentials->pluck('value', 'key')->toArray();

            // Create Stripe client instance with user-specific API key
            $stripe = new \Stripe\StripeClient($credentials['secret_key'] ?? '');
            Log::info('Stripe client initialized for user', ['user_id' => $userId]);

            // Prepare webhook details
            $appUrl = config('configuration.appUrl');
            $webhookUrl = rtrim($appUrl, '/') . '/api/webhook/stripe/user/' . $userId;
            $enabledEvents = [
                'payment_intent.created',
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'charge.succeeded',
                'refund.created',
                'refund.failed',
                'refund.updated',
                'terminal.reader.action_succeeded', // For reader_id support
            ];

            // First check current user's webhook
            $existingWebhookId = $userPaymentGateway->userPaymentCredentials()
                ->where('key', 'webhook_id')
                ->first();

            if ($existingWebhookId) {
                $validWebhook = $this->validateStripeWebhook($existingWebhookId->value, $webhookUrl, $enabledEvents, $userId, null, $stripe);
                if ($validWebhook) {
                    return $this->successResponseWithData(
                        'Webhook already exists',
                        ['webhook_id' => $validWebhook['webhook_id']]
                    );
                }
            }

            // Check for previous Stripe webhook for this user (from when they used Stripe before)
            $previousStripeGateway = UserPaymentGateway::where('user_id', $userId)
                ->where('payment_gateway_name', 'Stripe')
                ->where('id', '!=', $userPaymentGateway->id) // Exclude current gateway
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($previousStripeGateway) {
                $previousWebhookId = $previousStripeGateway->userPaymentCredentials()
                    ->where('key', 'webhook_id')
                    ->first();
                $previousWebhookSecret = $previousStripeGateway->userPaymentCredentials()
                    ->where('key', 'webhook_secret')
                    ->first();

                if ($previousWebhookId && $previousWebhookSecret) {
                    $validWebhook = $this->validateStripeWebhook($previousWebhookId->value, $webhookUrl, $enabledEvents, $userId, $previousStripeGateway->id, $stripe);
                    if ($validWebhook) {
                        // Copy webhook credentials to current gateway
                        $userPaymentGateway->userPaymentCredentials()->updateOrCreate(
                            ['key' => 'webhook_id'],
                            ['value' => $previousWebhookId->value]
                        );
                        $userPaymentGateway->userPaymentCredentials()->updateOrCreate(
                            ['key' => 'webhook_secret'],
                            ['value' => $previousWebhookSecret->value]
                        );

                        return $this->successResponseWithData(
                            'Previous webhook reused',
                            ['webhook_id' => $previousWebhookId->value]
                        );
                    }
                }
            }

            // Clear existing webhook credentials to ensure a clean slate
            $userPaymentGateway->userPaymentCredentials()->whereIn('key', ['webhook_id', 'webhook_secret'])->delete();
            Log::info('Cleared existing webhook credentials', [
                'user_id' => $userId,
                'payment_gateway_id' => $userPaymentGateway->id
            ]);

            // Create new webhook
            $webhook = $stripe->webhookEndpoints->create([
                'url' => $webhookUrl,
                'enabled_events' => $enabledEvents,
                'description' => 'Webhook for user ' . $userId,
                'metadata' => ['user_id' => (string) $userId],
            ]);

            // Save webhook ID and secret
            $userPaymentGateway->userPaymentCredentials()->updateOrCreate(
                ['key' => 'webhook_id'],
                ['value' => $webhook->id]
            );

            $userPaymentGateway->userPaymentCredentials()->updateOrCreate(
                ['key' => 'webhook_secret'],
                ['value' => $webhook->secret]
            );

            $userPaymentGateway->updated_at = now();
            $userPaymentGateway->save();

            Log::info('New webhook created and stored for user', [
                'user_id' => $userId,
                'webhook_id' => $webhook->id,
                'payment_gateway_id' => $userPaymentGateway->id
            ]);

            return $this->successResponseWithData('Webhook created successfully', ['webhook_id' => $webhook->id], Response::HTTP_OK);

        } catch (ModelNotFoundException $e) {
            Log::error('User not found', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->errorResponse('User not found', [], Response::HTTP_NOT_FOUND);
        } catch (ApiErrorException $e) {
            Log::error('Stripe API error managing webhook', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Failed to manage webhook: ' . $e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            Log::error('Failed to manage webhook for user', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Failed to manage webhook: ' . $e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handle incoming Stripe webhook events for a specific user.
     *
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request, $userId)
    {
        $payload = $request->getContent();
        // Decode the JSON string into a PHP array
        $decodedPayload = json_decode($payload, true);

        $eventType = $decodedPayload['type'];
        $object = $decodedPayload['data']['object'];

        // Validate event belongs to the correct user
        $metadata = $object['metadata'] ?? [];
        $eventUserId = $metadata['user_id'] ?? null;

        if ($eventUserId && $eventUserId != $userId) {
            \Log::info('Webhook event belongs to different user, skipping', [
                'webhook_user_id' => $userId,
                'event_user_id' => $eventUserId,
                'event_type' => $eventType
            ]);
            return $this->successResponse('Event belongs to different user, skipping webhook processing', Response::HTTP_OK);
        }

        \Log::info('Received Stripe webhook', [
            'user_id' => $userId,
            'event_type' => $eventType,
            'payload' => $decodedPayload
        ]);

        try {
            $user = User::where('id', $userId)
                ->where('status', '1')
                ->firstOrFail();

            $userPaymentGateway = UserPaymentGateway::where('user_id', $userId)
                ->where('status', '1')
                ->first();

            if (!$userPaymentGateway) {
                Log::warning('No Stripe payment gateway found for user', ['user_id' => $userId]);
                return $this->errorResponse('No active Stripe payment gateway configured for user', [], 404);
            }

            $credentials = $userPaymentGateway->userPaymentCredentials->pluck('value', 'key') ?? [];

            $endpoint_secret = $credentials['webhook_secret'] ?? null;

            if (!$endpoint_secret) {
                Log::warning('No webhook secret found for user payment gateway', [
                    'user_id' => $userId,
                    'payment_gateway_id' => $userPaymentGateway->id
                ]);
                return $this->errorResponse('Webhook secret not configured', [], 400);
            }

            $sig_header = $request->header('Stripe-Signature');
            $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);

            // Log event summary
            $eventSummary = [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'user_id' => $userId,
                'payment_gateway_id' => $userPaymentGateway->id,
                'created' => date('Y-m-d H:i:s', $event->created),
                'object' => [
                    'id' => $event->data->object->id,
                    'object_type' => $event->data->object->object,
                    'amount' => isset($event->data->object->amount) ? $event->data->object->amount / 100 : null,
                    'currency' => $event->data->object->currency ?? null,
                    'status' => $event->data->object->status ?? null,
                    'payment_attempt_id' => $event->data->object->metadata->payment_attempt_id ?? null,
                    'payment_intent_id' => $event->data->object->payment_intent ?? $event->data->object->id,
                ],
            ];

            switch ($event->type) {
                case 'payment_intent.created':
                    return $this->handlePaymentIntentCreated($event, $userId, $userPaymentGateway->id);
                case 'payment_intent.succeeded':
                    return $this->handlePaymentIntentSucceeded($event, $userId, $userPaymentGateway->id);
                case 'payment_intent.payment_failed':
                    return $this->handlePaymentIntentFailed($event, $userId, $userPaymentGateway->id);
                case 'charge.succeeded':
                    return $this->handleChargeSucceeded($event, $userId, $userPaymentGateway->id);
                case 'refund.created':
                    return $this->handleRefundCreated($event, $userId, $userPaymentGateway->id);
                case 'refund.failed':
                    return $this->handleRefundFailed($event, $userId, $userPaymentGateway->id);
                case 'refund.updated':
                    return $this->handleRefundUpdated($event, $userId, $userPaymentGateway->id);
                case 'terminal.reader.action_succeeded':
                    return $this->handleReaderActionSucceeded($event, $userId, $userPaymentGateway->id);
                default:
                    \Log::warning('Unhandled Stripe webhook event type', [
                        'event_type' => $event->type,
                        'user_id' => $userId,
                    ]);
                    return $this->successResponse('Event type not handled', 200);
            }
        } catch (SignatureVerificationException $e) {
            Log::error('Webhook signature verification failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Invalid signature', [], 400);
        } catch (\ModelNotFoundException $e) {
            Log::error('User not found', ['userId' => $userId, 'error' => $e->getMessage()]);
            return $this->errorResponse('User not found', [], 404);
        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Webhook processing failed', [], 500);
        }
    }

    /**
     * Handle payment_intent.created event.
     *
     * @param \Stripe\Event $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handlePaymentIntentCreated($event, $userId, $paymentGatewayId)
    {
        $paymentIntent = $event->data->object;
        $paymentAttemptId = $paymentIntent->metadata->payment_attempt_id ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'payment_intent_id' => $paymentIntent->id,
            'payment_attempt_id' => $paymentAttemptId,
            'amount' => $paymentIntent->amount / 100,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
        ];
        Log::info('Processing payment_intent.created');

        if (!$paymentAttemptId) {
            Log::warning('PaymentIntent created but no payment_attempt_id found', $logData);
            return $this->successResponse('No payment attempt ID', 200);
        }

        $paymentAttempt = PaymentAttempt::where('id', $paymentAttemptId)
            ->where('user_id', $userId)
            ->first();

        if ($paymentAttempt) {
            $paymentAttempt->update([
                'status' => 3, // Attempt
                'transaction_id' => $paymentIntent->id,
                'comment' => 'Payment intent created: ' . $paymentIntent->id,
                'updated_at' => now(),
            ]);

            Log::info('Payment attempt updated to Attempt', $logData);
        } else {
            Log::warning('Payment attempt not found for payment_intent.created', $logData);
        }

        return $this->successResponse('Payment intent created processed', 200);
    }

    /**
     * Handle payment_intent.succeeded event.
     *
     * @param \Stripe\Event $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handlePaymentIntentSucceeded($event, $userId, $paymentGatewayId)
    {
        $paymentIntent = $event->data->object;
        $paymentAttemptId = $paymentIntent->metadata->payment_attempt_id ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'payment_intent_id' => $paymentIntent->id,
            'payment_attempt_id' => $paymentAttemptId,
            'amount' => $paymentIntent->amount / 100,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'charge_id' => $paymentIntent->latest_charge,
        ];
        Log::info('Processing payment_intent.succeeded', [
            'details' => json_encode($logData, JSON_PRETTY_PRINT)
        ]);

        if (!$paymentAttemptId) {
            Log::warning('PaymentIntent succeeded but no payment_attempt_id found', $logData);
            return $this->successResponse('No payment attempt ID', 200);
        }

        $paymentAttempt = PaymentAttempt::where('id', $paymentAttemptId)
            ->where('user_id', $userId)
            ->first();

        if ($paymentAttempt) {
            $paymentAttempt->update([
                'status' => 0, // Paid
                'transaction_id' => $paymentIntent->id,
                'charge_id' => $paymentIntent->latest_charge,
                'comment' => 'PaymentIntent succeeded for ' . $paymentIntent->id,
                'updated_at' => now(),
            ]);

            Log::info('Payment attempt updated to Paid', $logData);
        } else {
            Log::warning('Payment attempt not found', $logData);
        }

        return $this->successResponse('Payment intent processed', 200);
    }

    /**
     * Handle payment_intent.payment_failed event.
     *
     * @param \Stripe\Event $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handlePaymentIntentFailed($event, $userId, $paymentGatewayId)
    {
        $paymentIntent = $event->data->object;
        $paymentAttemptId = $paymentIntent->metadata->payment_attempt_id ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'payment_intent_id' => $paymentIntent->id,
            'payment_attempt_id' => $paymentAttemptId,
            'amount' => $paymentIntent->amount / 100,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'error_message' => $paymentIntent->last_payment_error->message ?? null,
        ];
        Log::info('Processing payment_intent.payment_failed', [
            'details' => json_encode($logData, JSON_PRETTY_PRINT)
        ]);

        if ($paymentAttemptId) {
            $paymentAttempt = PaymentAttempt::where('id', $paymentAttemptId)
                ->where('user_id', $userId)
                ->first();

            if ($paymentAttempt) {
                $paymentAttempt->update([
                    'status' => 4, // Error
                    'comment' => $paymentIntent->last_payment_error->message ?? 'PaymentIntent failed for ' . $paymentIntent->id,
                    'payment_handle_comment' => 'Payment attempt failed',
                    'updated_at' => now(),
                ]);

                Log::info('Payment attempt updated to Error', $logData);
            }
        }

        return $this->successResponse('Payment failure processed', 200);
    }

    /**
     * Handle charge.succeeded event.
     *
     * @param \Stripe\Event $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleChargeSucceeded($event, $userId, $paymentGatewayId)
    {
        \Log::info('Charge Succeeded event');
        $charge = $event->data->object;
        $paymentIntentId = $charge->payment_intent;
        $walletType = $charge->payment_method_details?->card?->wallet?->type ?? null;
        $paymentSource = $charge->metadata->payment_source ?? $walletType;
        $paymentAttemptId = $charge->metadata->payment_attempt_id ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'charge_id' => $charge->id,
            'payment_intent_id' => $paymentIntentId,
            'amount' => $charge->amount / 100,
            'currency' => $charge->currency,
            'status' => $charge->status,
            'payment_attempt_id' => $charge->metadata->payment_attempt_id ?? null,
            'card_expire_date' => isset($charge->payment_method_details->card->exp_month, $charge->payment_method_details->card->exp_year)
                ? ($charge->payment_method_details->card->exp_month . '/' . $charge->payment_method_details->card->exp_year)
                : null,

            'card_last_4_digit' => $charge->payment_method_details->card->last4 ?? null,
            'gateway' => $paymentSource ?? 'Stripe',
        ];
        Log::info('Processing charge.succeeded', [
            'details' => json_encode($logData, JSON_PRETTY_PRINT)
        ]);

        $paymentAttempt = PaymentAttempt::where('id', $paymentAttemptId)
            ->where('user_id', $userId)
            ->first();

        if ($paymentAttempt) {
            $paymentAttempt->update([
                'status' => 0, // Paid
                'charge_id' => $charge->id,
                'card_last_4_digit' => $charge->payment_method_details->card->last4 ?? null,
                'card_expire_date' => isset($charge->payment_method_details->card->exp_month, $charge->payment_method_details->card->exp_year)
                    ? ($charge->payment_method_details->card->exp_month . '/' . $charge->payment_method_details->card->exp_year)
                    : null,
                'comment' => 'Payment succeeded for payment intent ' . $paymentIntentId,
                'payment_handle_comment' => 'Payment successful',
                'gateway' => $paymentSource ?? 'Stripe ' . ($walletType ?? ''),
                'updated_at' => now(),
            ]);

            Log::info('Payment attempt updated to Paid', $logData);
        } else {
            Log::warning('Payment attempt not found for charge.succeeded', $logData);
        }

        return $this->successResponse('Charge succeeded processed', 200);
    }

    /**
     * Handle refund.created event.
     *
     * @param \Stripe\Event $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleRefundCreated($event, $userId, $paymentGatewayId)
    {
        \Log::info('Handling Refund/ Void Created event');
        $refund = $event->data->object;
        $paymentIntentId = $refund->payment_intent;
        $chargeId = $refund->charge;
        $destinationType = $refund->destination_details->card->type ?? null;
        $paymentSource = $refund->destination_details->type ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'refund_id' => $refund->id,
            'payment_intent_id' => $paymentIntentId,
            'charge_id' => $chargeId,
            'amount' => $refund->amount / 100,
            'currency' => $refund->currency,
            'status' => $refund->status,
            'destination_type' => $destinationType,
            'payment_source' => $paymentSource,
            'metadata' => $refund->metadata ?? null,
        ];
        \Log::info('Processing refund.create event', ['details' => json_encode($logData, JSON_PRETTY_PRINT)]);

        if ($destinationType === 'reversal' || $destinationType === 'pending') {
            // Handle reversal/pending: update void payment attempt
            $refundAmount = $refund->amount / 100; // Convert cents to dollars (e.g., 44400 -> 444.00)
            $negativeAmount = -$refundAmount; // Negate to match PaymentAttempt (e.g., -444.00)

            $originalPaymentAttempt = PaymentAttempt::where([
                'charge_id' => $chargeId,
                'user_id' => $userId,
                'amount' => $negativeAmount,
                'status' => CommonConstant::STATUS_ATTEMPT // Only find attempts awaiting confirmation
            ])->orderBy('created_at', 'desc') // Get the most recent void attempt
                ->first();

            if ($originalPaymentAttempt) {
                $originalPaymentAttempt->update([
                    'status' => CommonConstant::STATUS_ATTEMPT, // Define this constant, e.g., STATUS_ATTEMPT
                    'comment' => 'Void attempt created via reversal',
                    'payment_handle_comment' => 'Void In Progress',
                    'updated_at' => now(),
                ]);
                \Log::info('Original payment attempt updated to void attempt', [
                    'payment_attempt_id' => $originalPaymentAttempt->id,
                    'charge_id' => $chargeId,
                ]);
            } else {
                \Log::warning('Original payment attempt not found for reversal', $logData);
                return $this->successResponse('Refund reversal processed but no original payment attempt found', 200);
            }
        } elseif ($destinationType === 'refund') {
            // Handle refund: update refund-specific payment attempt
            $refundAttemptId = $refund->metadata->refundAttemptId ?? null;
            if ($refundAttemptId) {
                $refundPaymentAttempt = PaymentAttempt::where('id', $refundAttemptId)
                    ->where('user_id', $userId)
                    ->first();

                if ($refundPaymentAttempt) {
                    $refundPaymentAttempt->update([
                        'status' => 3, // refund attempt
                        'comment' => 'Refund created for payment intent ' . $paymentIntentId,
                        'updated_at' => now(),
                    ]);
                    \Log::info('Refund payment attempt updated', [
                        'refund_attempt_id' => $refundAttemptId,
                        'charge_id' => $chargeId,
                    ]);
                } else {
                    \Log::warning('Refund payment attempt not found', $logData);
                    return $this->successResponse('Refund created but payment attempt not found', 200);
                }
            } else {
                \Log::warning('Metadata refundAttemptId missing for refund', $logData);
                return $this->successResponse('Refund created but metadata missing', 200);
            }
        } else {
            \Log::warning('Unknown destination_details.card.type', $logData);
            return $this->successResponse('Refund created but unknown destination type', 200);
        }

        return $this->successResponse('refund.created event processed successfully', 200);
    }

    private function handleRefundUpdated($event, $userId, $paymentGatewayId)
    {
        \Log::info('Handling Refund/Void Updated event');
        $refund = $event->data->object;
        $paymentIntentId = $refund->payment_intent;
        $chargeId = $refund->charge;
        $destinationType = $refund->destination_details->card->type ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'refund_id' => $refund->id,
            'payment_intent_id' => $paymentIntentId,
            'charge_id' => $chargeId,
            'amount' => $refund->amount / 100,
            'currency' => $refund->currency,
            'status' => $refund->status,
            'destination_type' => $destinationType,
            'metadata' => $refund->metadata ?? null
        ];
        \Log::info('Processing refund.updated', ['details' => json_encode($logData, JSON_PRETTY_PRINT)]);

        if ($destinationType === 'reversal' || $destinationType === 'pending') {
            // Handle reversal/pending: update original payment attempt
            $refundAmount = $refund->amount / 100; // Convert cents to dollars
            $negativeAmount = -$refundAmount; // Negate to match PaymentAttempt

            $originalPaymentAttempt = PaymentAttempt::where([
                'charge_id' => $chargeId,
                'user_id' => $userId,
                'amount' => $negativeAmount,
                'gateway' => 'Stripe',
                'status' => CommonConstant::STATUS_ATTEMPT // For updated events, find void attempts
            ])->orderBy('created_at', 'desc')
                ->first();

            if ($originalPaymentAttempt) {
                $originalPaymentAttempt->update([
                    'status' => CommonConstant::STATUS_VOID,
                    'refund_void_transaction_id' => $refund->id,
                    'comment' => 'Transaction void updated via reversal for $' . number_format($refundAmount, 2),
                    'payment_handle_comment' => 'Transaction voided successfully',
                    'updated_at' => now(),
                ]);
                \Log::info('Original payment attempt updated to voided', [
                    'payment_attempt_id' => $originalPaymentAttempt->id,
                    'charge_id' => $chargeId,
                    'amount' => $negativeAmount,
                ]);
            } else {
                \Log::warning('Original payment attempt not found for reversal', array_merge($logData, [
                    'attempted_amount' => $negativeAmount,
                ]));
                return $this->successResponse('Refund updated but original payment attempt not found', 200);
            }
        } elseif ($destinationType === 'refund') {
            // Handle refund: update refund-specific payment attempt
            $refundAttemptId = $refund->metadata->refundAttemptId ?? null;
            if ($refundAttemptId) {
                $refundPaymentAttempt = PaymentAttempt::where('id', $refundAttemptId)
                    ->where('user_id', $userId)
                    ->first();

                if ($refundPaymentAttempt) {
                    $refundPaymentAttempt->update([
                        'status' => 2, // refund attempt
                        'refund_void_transaction_id' => $refund->id,
                        'comment' => 'Refund successful for payment intent ' . $paymentIntentId . ' for $' . number_format($refund->amount / 100, 2),
                        'payment_handle_comment' => 'Refund successful',
                        'updated_at' => now(),
                    ]);
                    \Log::info('Refund payment attempt updated', [
                        'refund_attempt_id' => $refundAttemptId,
                        'charge_id' => $chargeId,
                    ]);
                } else {
                    \Log::warning('Refund payment attempt not found', $logData);
                    return $this->successResponse('Refund updated but payment attempt not found', 200);
                }
            } else {
                \Log::warning('Metadata refundAttemptId missing for refund', $logData);
                return $this->successResponse('Refund updated but metadata missing', 200);
            }
        } else {
            \Log::warning('Unknown destination_details.card.type', $logData);
            return $this->successResponse('Refund updated but unknown destination type', 200);
        }

        return $this->successResponse('refund.updated event processed successfully', 200);
    }

    /**
     * Handle refund.failed event.
     *
     * @param \Stripe\Event $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleRefundFailed($event, $userId, $paymentGatewayId)
    {
        \Log::info('Handling Refund Failed event');
        $refund = $event->data->object;
        $paymentIntentId = $refund->payment_intent;
        $chargeId = $refund->charge;
        $destinationType = $refund->destination_details->card->type ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'refund_id' => $refund->id,
            'payment_intent_id' => $paymentIntentId,
            'charge_id' => $chargeId,
            'amount' => $refund->amount / 100,
            'currency' => $refund->currency,
            'status' => $refund->status,
            'destination_type' => $destinationType,
            'metadata' => $refund->metadata ?? null
        ];
        \Log::info('Processing refund.failed', ['details' => json_encode($logData, JSON_PRETTY_PRINT)]);

        if ($destinationType === 'reversal' || $destinationType === 'pending') {
            // Handle reversal/pending: update original payment attempt
            $refundAmount = $refund->amount / 100; // Convert cents to dollars
            $negativeAmount = -$refundAmount; // Negate to match PaymentAttempt

            $originalPaymentAttempt = PaymentAttempt::where([
                'charge_id' => $chargeId,
                'user_id' => $userId,
                'amount' => $negativeAmount,
                'gateway' => 'Stripe',
                'status' => CommonConstant::STATUS_ATTEMPT // For failed events, find pending attempts
            ])->orderBy('created_at', 'desc')
                ->first();

            if ($originalPaymentAttempt) {
                $originalPaymentAttempt->update([
                    'status' => 4, // Error
                    'comment' => 'Transaction void failed for payment intent ' . $paymentIntentId . ' for $' . number_format($refundAmount, 2),
                    'payment_handle_comment' => 'Void failed',
                    'updated_at' => now(),
                ]);
                \Log::info('Original payment attempt updated to error for reversal', [
                    'payment_attempt_id' => $originalPaymentAttempt->id,
                    'charge_id' => $chargeId,
                    'amount' => $negativeAmount,
                ]);
            } else {
                \Log::warning('Original payment attempt not found for reversal', array_merge($logData, [
                    'attempted_amount' => $negativeAmount,
                ]));
                return $this->successResponse('Refund failed but original payment attempt not found', 200);
            }
        } elseif ($destinationType === 'refund') {
            // Handle refund: update refund-specific payment attempt
            $refundAttemptId = $refund->metadata->refundAttemptId ?? null;
            if ($refundAttemptId) {
                $refundPaymentAttempt = PaymentAttempt::where('id', $refundAttemptId)
                    ->where('user_id', $userId)
                    ->first();

                if ($refundPaymentAttempt) {
                    $refundPaymentAttempt->update([
                        'status' => 4, // Error
                        'comment' => 'Refund failed for payment intent ' . $paymentIntentId . ' for $' . number_format($refund->amount / 100, 2),
                        'payment_handle_comment' => 'Refund failed',
                        'updated_at' => now(),
                    ]);
                    \Log::info('Refund payment attempt updated to error', [
                        'refund_attempt_id' => $refundAttemptId,
                        'charge_id' => $chargeId,
                    ]);
                } else {
                    \Log::warning('Refund payment attempt not found', $logData);
                    return $this->successResponse('Refund failed but payment attempt not found', 200);
                }
            } else {
                \Log::warning('Metadata refundAttemptId missing for refund', $logData);
                return $this->successResponse('Refund failed but metadata missing', 200);
            }
        } else {
            \Log::warning('Unknown destination_details.card.type', $logData);
            return $this->successResponse('Refund failed but unknown destination type', 200);
        }

        return $this->successResponse('refund.failed event processed successfully', 200);
    }

    /**
     * Handle terminal.reader.action_succeeded event.
     *
     * @param \Stripe\Event $event
     * @param int $userId
     * @param int $paymentGatewayId
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleReaderActionSucceeded($event, $userId, $paymentGatewayId)
    {
        \Log::info('Handling Terminal Reader Action Succeeded event');
        $reader = $event->data->object;
        $paymentIntentId = $reader->action->payment_intent ?? null;
        $actionType = $reader->action->type ?? null;
        $readerId = $reader->id ?? null;
        $amount = $reader->amount ? $reader->amount / 100 : null; // Convert cents to dollars
        $currency = $reader->currency ?? null;

        $logData = [
            'user_id' => $userId,
            'payment_gateway_id' => $paymentGatewayId,
            'reader_id' => $readerId,
            'payment_intent_id' => $paymentIntentId,
            'action_type' => $actionType,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $reader->action->status ?? null,
            'device_type' => $reader->device_type ?? null,
            'serial_number' => $reader->serial_number ?? null
        ];
        \Log::info('Processing terminal.reader.action_succeeded', [
            'details' => json_encode($logData, JSON_PRETTY_PRINT)
        ]);

        if ($actionType === 'process_payment_intent' && $paymentIntentId) {
            // Handle payment intent processing
            $paymentAttempt = PaymentAttempt::where('payment_intent_id', $paymentIntentId)
                ->where('gateway', 'Stripe')
                ->where('status', CommonConstant::STATUS_ATTEMPT)
                ->where('user_id', $userId)
                ->first();

            if ($paymentAttempt) {
                $paymentAttempt->update([
                    'status' => CommonConstant::STATUS_PAID, // or your paid status constant
                    'comment' => sprintf(
                        'Payment succeeded via terminal reader %s for payment intent %s for $%s',
                        $readerId,
                        $paymentIntentId,
                        number_format($amount, 2)
                    ),
                    'payment_handle_comment' => 'Terminal payment succeeded',
                    'updated_at' => now(),
                ]);
                \Log::info('Payment attempt updated to paid', [
                    'payment_attempt_id' => $paymentAttempt->id,
                    'payment_intent_id' => $paymentIntentId,
                    'reader_id' => $readerId,
                    'amount' => $amount,
                ]);
            } else {
                \Log::warning('Payment attempt not found for terminal.reader.action_succeeded', $logData);
                return $this->successResponse('Terminal action succeeded but payment attempt not found', 200);
            }
        } else {
            \Log::warning('Unsupported action type or missing payment intent', array_merge($logData, [
                'action_type' => $actionType,
                'payment_intent_id' => $paymentIntentId,
            ]));
            return $this->successResponse('Terminal action succeeded but unsupported action type or missing payment intent', 200);
        }

        return $this->successResponse('terminal.reader.action_succeeded event processed successfully', 200);
    }


    /**
     * Validate if a Stripe webhook still exists and is active
     */
    private function validateStripeWebhook($webhookId, $expectedUrl, $enabledEvents, $userId, $gatewayId = null, $stripe = null)
    {
        try {
            $existingWebhook = $stripe ? $stripe->webhookEndpoints->retrieve($webhookId) : WebhookEndpoint::retrieve($webhookId);
            // Sort both arrays to ensure consistent comparison
            $existingEvents = $existingWebhook->enabled_events ? $existingWebhook->enabled_events : [];
            sort($existingEvents);
            sort($enabledEvents);

            if ($existingWebhook && $existingWebhook->url === $expectedUrl && $existingWebhook->status === "enabled" && $existingEvents === $enabledEvents) {
                $logData = [
                    'user_id' => $userId,
                    'webhook_id' => $webhookId
                ];
                if ($gatewayId) {
                    $logData['previous_gateway_id'] = $gatewayId;
                    Log::info('Reusing previous Stripe webhook', $logData);
                } else {
                    Log::info('User already has valid webhook', $logData);
                }

                return ['webhook_id' => $webhookId];
            } else {
                // Webhook exists but is invalid (wrong URL, disabled, or mismatched events) - delete it
                if (!in_array($webhookId, self::$deletedWebhooks)) {
                    try {
                        $deleted = $stripe ? $stripe->webhookEndpoints->retrieve($webhookId)->delete() : \Stripe\WebhookEndpoint::retrieve($webhookId)->delete();
                        self::$deletedWebhooks[] = $webhookId; // Track deletion
                        Log::info('Deleted invalid webhook', [
                            'deleted_webhook' => $deleted,
                            'user_id' => $userId,
                            'deleted_webhook_id' => $webhookId,
                            'reason' => 'URL/status/events mismatch/secret mismatch',
                            'expected_url' => $expectedUrl,
                            'actual_url' => $existingWebhook->url ?? 'unknown',
                            'status' => $existingWebhook->status ?? 'unknown',
                            'old_events' => $existingEvents,
                            'new_events' => $enabledEvents,
                            'old_secret' => $existingWebhook->secret ?? 'unknown',
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete invalid webhook', [
                            'user_id' => $userId,
                            'webhook_id' => $webhookId,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::info('Webhook already deleted in this request, skipping', [
                        'user_id' => $userId,
                        'webhook_id' => $webhookId
                    ]);
                }
            }
        } catch (ApiErrorException $e) {
            $logData = [
                'user_id' => $userId,
                'webhook_id' => $webhookId,
                'error' => $e->getMessage()
            ];
            if ($gatewayId) {
                $logData['previous_gateway_id'] = $gatewayId;
            }
            Log::info('Webhook no longer exists, will create new one', $logData);
        }

        return null;
    }

}
