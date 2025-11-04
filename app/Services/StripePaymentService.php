<?php

namespace App\Services;

use Stripe\Charge;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Constant\CommonConstant;
use Stripe\Exception\ApiErrorException;
use PHPUnit\TextUI\Configuration\Constant;
use Symfony\Component\HttpFoundation\Response;

/**
 * StripePaymentService handles payment processing with Stripe API.
 * This includes creating a payment intent, confirming the payment, and handling any errors.
 */
class StripePaymentService
{
    /**
     * @var string The secret key for Stripe API.
     */
    protected $secretKey;

    /**
     * @var string The environment mode (live/test)
     */
    protected $isLiveMode;

    /**
     * @var string Currency
     */
    protected $currency;

    /**
     * Constructor to initialize Stripe API with the secret key and mode (live/test).
     *
     * @param string $secretKey The secret API key for Stripe.
     * @param string $isLiveMode The environment mode ('1' for live, '0' for test)
     */
    public function __construct(string $secretKey, string $isLiveMode, string $currency = 'usd')
    {
        $this->secretKey = $secretKey;
        $this->isLiveMode = $isLiveMode;
        $this->currency = $currency;
        Stripe::setApiKey($this->secretKey);
    }

    /**
     * Process the payment by creating a PaymentIntent, confirming it, and updating the payment attempt.
     *
     * @param array $paymentData Data related to the payment (user_id, amount, etc.)
     *
     * @return array The result of the payment process, including success or error details.
     */
    public function processPayment(array $paymentData): array
    {
        if (isset($paymentData['descriptor']) && $paymentData['descriptor'] === 'POS_PAY') {
            $decodedToken = base64_decode($paymentData['payment_method_id'], true);
            if ($decodedToken === false) {
                return $this->handleError(null, 'Invalid payment_method_id');
            }

            // Look for PaymentAttempt by transaction_id and user_id for security
            $paymentAttempt = PaymentAttempt::where('transaction_id', $decodedToken)
                ->where('user_id', $paymentData['user_id'])
                ->where('gateway', 'Stripe POS Terminal')
                ->first();

            if ($paymentAttempt) {
                return [
                    'success' => true,
                    'payment_attempt_id' => $paymentAttempt->id,
                    'transaction_id' => $paymentAttempt->transaction_id,
                    'charge_id' => $paymentAttempt->charge_id,
                    'payment_status' => $paymentAttempt->status,
                    'gateway' => $paymentAttempt->gateway,
                    'comment' => $paymentAttempt->comment
                ];
            } else {
                return $this->handleError(null, 'POS Payment not found or not processed yet. Please try again.');
            }
        }
        // Create a new payment attempt in the database
        $paymentAttempt = PaymentAttempt::create([
            'user_id' => $paymentData['user_id'],
            'store_id' => $paymentData['store_id'],
            'temp_order_number' => $paymentData['temp_order_number'],
            'member_email' => $paymentData['member_email'] ?? null,
            'member_name' => $paymentData['member_name'] ?? null,
            'gateway' => $paymentData['gateway'],
            'amount' => $paymentData['amount'],
            'status' => CommonConstant::STATUS_ATTEMPT,
            'comment' => $paymentData['comment'] ?? null,
        ]);

        try {
            // Create the PaymentIntent to initiate the payment process
            $paymentIntent = $this->createPaymentIntent($paymentData['amount'], $this->currency, [
                'metadata' => [
                    'payment_attempt_id' => $paymentAttempt->id,
                    'user_id' => $paymentData['user_id'],
                    'store_id' => $paymentData['store_id'],
                ]
            ]);

            // Handle any errors that occur when creating the PaymentIntent
            if (isset($paymentIntent['error'])) {
                return $this->handleError($paymentAttempt, $paymentIntent['error']);
            }

            // Update the payment attempt with the payment intent's ID and status
            $paymentAttempt->update([
                'status' => CommonConstant::STATUS_ATTEMPT,
                'comment' => $paymentIntent->status,
            ]);

            // Confirm the PaymentIntent using the provided payment method ID
            $confirmation = $this->confirmPaymentIntent($paymentIntent->id, base64_decode($paymentData['payment_method_id']));

            // Handle any errors that occur during payment confirmation
            if (isset($confirmation['error'])) {
                return $this->handleError($paymentAttempt, $confirmation['error']);
            }

            // Return a successful response with payment details
            if ($paymentIntent->id && $confirmation->latest_charge) {
                // Return a successful response with payment details
                return [
                    'success' => true,
                    'transaction_id' => $paymentIntent->id,
                    'charge_id' => $confirmation->latest_charge,
                    'status' => $confirmation->status
                ];
            }

        } catch (\Exception $e) {
            return $this->handleError($paymentAttempt ?? null, $e);
        }
    }

    // /**
    //  * Refund a transaction by creating a refund request to Stripe and logging the refund attempt.
    //  *
    //  * This method attempts to refund a previous payment by finding the corresponding payment record in the database,
    //  * creating a refund attempt record, and then interacting with the Stripe API to perform the refund.
    //  *
    //  * @param array $paymentData An associative array containing the payment details to process the refund.
    //  *                           Expected keys: 'transaction_id', 'user_id', and 'amount' (the refund amount).
    //  *
    //  * @return array The result of the refund attempt, which includes a success flag, message, and refund ID if successful.
    //  *               If the refund fails, an error message with relevant details will be returned.
    //  *
    //  * @throws \Exception If any unexpected error occurs, it is caught and returned as part of the response.
    //  */
    // public function refundTransaction(array $paymentData)
    // {
    //     try {
    //         // Fetch the original successful payment
    //         $originalPayment = PaymentAttempt::where([
    //             ['transaction_id', '=', $paymentData['transaction_id']],
    //             ['user_id', '=', $paymentData['user_id']],
    //             ['status', '=', CommonConstant::STATUS_PAID]
    //         ])->first();

    //         if (!$originalPayment || !isset($originalPayment->charge_id)) {
    //             return $this->handleError(null, 'Original payment not found.', Response::HTTP_NOT_FOUND, 'payment_not_found');
    //         }

    //         // Calculate total refunded amount for this transaction
    //         $totalRefunded = PaymentAttempt::where([
    //             ['transaction_id', '=', $paymentData['transaction_id']],
    //             ['user_id', '=', $paymentData['user_id']],
    //             ['status', '=', CommonConstant::STATUS_REFUND]
    //         ])->sum('amount');

    //         $totalRefundedAbs = abs($totalRefunded); // e.g. -1000 becomes 1000

    //         $requestedRefund = $paymentData['amount'];


    //         // Ensure requested refund doesn't exceed original - total refunded
    //         $remainingRefundable = round($originalPayment->amount - $totalRefundedAbs, 2);

    //         if ($requestedRefund > $remainingRefundable) {
    //             return $this->handleError(null, 'Requested refund exceeds the remaining refundable amount.', Response::HTTP_BAD_REQUEST, 'refund_exceeds_remaining');
    //         }

    //         // Create refund attempt
    //         $refundAttempt = PaymentAttempt::create([
    //             'user_id' => $originalPayment->user_id,
    //             'store_id' => $originalPayment->store_id,
    //             'temp_order_number' => $originalPayment->temp_order_number,
    //             'member_email' => $originalPayment->member_email,
    //             'member_name' => $originalPayment->member_name,
    //             'gateway' => $originalPayment->gateway,
    //             'amount' => -abs($requestedRefund), // Refunds are negative
    //             'status' => CommonConstant::STATUS_ATTEMPT,
    //             'comment' => 'Refund initiated',
    //             'transaction_id' => $paymentData['transaction_id'],
    //             'charge_id' => $originalPayment->charge_id,
    //         ]);

    //         // Perform Stripe refund
    //         $refund = \Stripe\Refund::create([
    //             'charge' => $originalPayment->charge_id,
    //             'amount' => $this->convertToCents($requestedRefund),
    //             'metadata' => [
    //                 'user_id' => $paymentData['user_id'],
    //                 'store_id' => $originalPayment->store_id,
    //                 'original_transaction_id' => $paymentData['transaction_id'],
    //                 'refundAttemptId' => $refundAttempt->id,
    //             ],
    //         ]);


    //         return [
    //             'success' => true,
    //             'refund_id' => $refund->id,
    //             'charge_id' => $refund->charge,
    //             'transaction_status' => $refund->object === 'refund' ? '2' : '1',
    //             'note' => $refund->balance_transaction,
    //             'gateway' => "STRIPE",
    //             'status' => $refund->status,
    //             'refunded_amount' => $requestedRefund,
    //             'remaining_refundable' => round($remainingRefundable - $requestedRefund, 2),
    //         ];

    //     } catch (\Exception $e) {
    //         return $this->handleError($refundAttempt ?? null, $e);
    //     }
    // }

    /**
     * Processes a refund for a previous payment transaction through Stripe with external transaction support.
     *
     * @param array $paymentData An associative array containing the payment details to process the refund.
     *                           Expected keys: 'transaction_id', 'user_id', and 'amount' (the refund amount).
     *
     * @return array The result of the refund attempt, which includes a success flag, message, and refund ID if successful.
     *               If the refund fails, an error message with relevant details will be returned.
     *
     * @throws \Exception If any unexpected error occurs, it is caught and returned as part of the response.
     */
    public function refundTransactionV2(array $paymentData)
    {
        try {
            // Validate original payment
            $originalPayment = $this->validateOriginalPaymentV2($paymentData);

            \Log::info('Original payment attempt data for Stripe refund', [
                'charge_id' => $originalPayment->charge_id,
                'transaction_id' => $originalPayment->transaction_id,
                'is_external' => isset($originalPayment->is_external) ? $originalPayment->is_external : false,
            ]);

            // Calculate total refunded amount for this transaction
            $totalRefunded = PaymentAttempt::where([
                ['transaction_id', '=', $paymentData['transaction_id']],
                ['status', '=', CommonConstant::STATUS_REFUND]
            ])->sum('amount');

            $totalRefundedAbs = abs($totalRefunded);
            $requestedRefund = $paymentData['amount'];

            // Ensure requested refund doesn't exceed original - total refunded
            $remainingRefundable = round($originalPayment->amount - $totalRefundedAbs, 2);

            if ($requestedRefund > $remainingRefundable) {
                return $this->handleError(null, 'Requested refund exceeds the remaining refundable amount.', Response::HTTP_BAD_REQUEST, 'refund_exceeds_remaining');
            }

            // Create refund attempt
            $refundAttempt = PaymentAttempt::create([
                'user_id' => $paymentData['user_id'] ?? $originalPayment->user_id,
                'store_id' => $paymentData['store_id'] ?? $originalPayment->store_id,
                'temp_order_number' => $originalPayment->temp_order_number ?? rand(100, 10000),
                'member_email' => $paymentData['member_email'] ?? $originalPayment->member_email,
                'member_name' => $paymentData['member_name'] ?? $originalPayment->member_name,
                'gateway' => $originalPayment->gateway ?? 'Stripe',
                'amount' => -abs($requestedRefund), // Refunds are negative
                'status' => CommonConstant::STATUS_ATTEMPT,
                'comment' => isset($originalPayment->is_external) ? 'External refund transaction initiated, awaiting webhook confirmation' : 'Refund initiated, awaiting webhook confirmation',
                'transaction_id' => $paymentData['transaction_id'],
                'charge_id' => $originalPayment->charge_id,
                'payment_handle_comment' => 'Refund attempt',
            ]);

            // Perform Stripe refund
            $refund = \Stripe\Refund::create([
                'charge' => $originalPayment->charge_id,
                'amount' => $this->convertToCents($requestedRefund),
                'metadata' => [
                    'user_id' => $paymentData['user_id'],
                    'store_id' => $originalPayment->store_id ?? $paymentData['store_id'],
                    'original_transaction_id' => $paymentData['transaction_id'],
                    'refundAttemptId' => $refundAttempt->id,
                    'is_external' => isset($originalPayment->is_external) ? 'true' : 'false',
                ],
            ]);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'charge_id' => $refund->charge,
                'transaction_status' => $refund->object === 'refund' ? '2' : '1',
                'note' => $refund->balance_transaction,
                'gateway' => $originalPayment->gateway ?? 'Stripe',
                'status' => $refund->status,
                'refunded_amount' => $requestedRefund,
                'remaining_refundable' => round($remainingRefundable - $requestedRefund, 2),
            ];

        } catch (\Exception $e) {
            return $this->handleError($refundAttempt ?? null, $e);
        }
    }


    // /**
    //  * Voids a transaction by first checking its status, and then either refunding
    //  * the charge (if it hasn't been captured) or returning an error (if it's already captured).
    //  *
    //  * @param array $paymentData The payment data containing transaction and user information.
    //  *
    //  * @return array Returns an array with success status and a message indicating the outcome.
    //  */
    // public function voidTransaction(array $paymentData): array
    // {
    //     try {
    //         // Retrieve the original payment attempt
    //         $originalPayment = PaymentAttempt::where([
    //             ['transaction_id', '=', $paymentData['transaction_id']],
    //             ['user_id', '=', $paymentData['user_id']],
    //             ['status', '=', CommonConstant::STATUS_PAID]
    //         ])->first();

    //         if (!$originalPayment || !isset($originalPayment->charge_id)) {
    //             return $this->handleError(null, 'Original payment not found.', Response::HTTP_NOT_FOUND, 'payment_error');
    //         }

    //         $existingVoid = PaymentAttempt::where([
    //             ['transaction_id', '=', $paymentData['transaction_id']],
    //             ['user_id', '=', $paymentData['user_id']],
    //             ['status', '=', CommonConstant::STATUS_VOID]
    //         ])->exists();

    //         if ($existingVoid) {
    //             return $this->handleError(null, 'Transaction already voided.', Response::HTTP_CONFLICT, 'Void_failed');
    //         }

    //         // Create a new void attempt record with negative amount
    //         $voidAttempt = PaymentAttempt::create([
    //             'user_id' => $originalPayment->user_id,
    //             'store_id' => $originalPayment->store_id,
    //             'temp_order_number' => $originalPayment->temp_order_number,
    //             'member_email' => $originalPayment->member_email,
    //             'member_name' => $originalPayment->member_name,
    //             'gateway' => $originalPayment->gateway,
    //             'amount' => -abs($originalPayment->amount), // Negative amount for void
    //             'status' => CommonConstant::STATUS_ATTEMPT,
    //             'comment' => 'Void transaction initiated',
    //             'transaction_id' => $paymentData['transaction_id'],
    //             'charge_id' => $originalPayment->charge_id,
    //         ]);

    //         // Fetch charge details from Stripe
    //         $charge = \Stripe\Charge::retrieve($originalPayment->charge_id);

    //         // Check if the charge is captured
    //         if (!$charge->captured) {
    //             // If the charge is not captured, cancel the PaymentIntent instead
    //             $paymentIntent = PaymentIntent::retrieve($charge->payment_intent);
    //             $paymentIntent->cancel();

    //             // Update the payment attempt status to void
    //             $voidAttempt->update([
    //                 'status' => CommonConstant::STATUS_VOID,
    //                 'comment' => 'Transaction voided successfully',
    //                 'refund_void_transaction_id' => $voidAttempt->id
    //             ]);
    //             return [
    //                 'success' => true,
    //                 'charge_id' => $originalPayment->charge_id,
    //                 'status' => $paymentIntent->status
    //             ];
    //         }

    //         // If the charge was already captured, return an error message
    //         return $this->handleError($voidAttempt ?? null, 'Transaction is already captured, cannot void.', Response::HTTP_CONFLICT, 'Void_failed');

    //     } catch (\Exception $e) {
    //         return $this->handleError($voidAttempt ?? null, $e);
    //     }
    // }

    /**
     * Voids a transaction with external transaction support by canceling uncaptured charges or returning an error for captured charges.
     *
     * @param array $paymentData The payment data containing transaction and user information.
     *
     * @return array Returns an array with success status and a message indicating the outcome.
     */
    public function voidTransactionV2(array $paymentData): array
    {
        try {
            // Validate original payment with external transaction support
            $originalPayment = $this->validateOriginalPaymentV2($paymentData, true);

            \Log::info('Original payment data for Stripe void', [
                'charge_id' => $originalPayment->charge_id,
                'transaction_id' => $originalPayment->transaction_id,
                'is_external' => isset($originalPayment->is_external) ? $originalPayment->is_external : false,
            ]);

            // Get charge details from Stripe for external transaction
            if (isset($originalPayment->is_external) && $originalPayment->is_external) {
                // External transaction: retrieve PaymentIntent first, then get charge
                $paymentIntent = PaymentIntent::retrieve($paymentData['transaction_id']);
                if (empty($paymentIntent->latest_charge)) {
                    throw new \Exception("No charge found for PaymentIntent: {$paymentData['transaction_id']}, make sure transaction id belongs to this user", Response::HTTP_NOT_FOUND);
                }
                $charge = \Stripe\Charge::retrieve($paymentIntent->latest_charge);
            } else {
                // Local transaction: retrieve charge directly
                $charge = \Stripe\Charge::retrieve($originalPayment->charge_id);
                $paymentIntent = PaymentIntent::retrieve($originalPayment->transaction_id);
            }

            // Create a new void attempt record with negative amount
            $voidAttempt = PaymentAttempt::create([
                'user_id' => $paymentData['user_id'] ?? $originalPayment->user_id,
                'store_id' => $paymentData['store_id'] ?? $originalPayment->store_id,
                'temp_order_number' => $originalPayment->temp_order_number ?? rand(100, 10000),
                'member_email' => $paymentData['member_email'] ?? $originalPayment->member_email,
                'member_name' => $paymentData['member_name'] ?? $originalPayment->member_name,
                'gateway' => $originalPayment->gateway ?? 'Stripe',
                'amount' => -abs($originalPayment->amount), // Negative amount for void
                'status' => CommonConstant::STATUS_ATTEMPT,
                'comment' => isset($originalPayment->is_external) ? 'External void transaction initiated, awaiting webhook confirmation' : 'Void transaction initiated, awaiting webhook confirmation',
                'transaction_id' => $paymentData['transaction_id'],
                'charge_id' => $charge->id, // Use the actual charge ID from Stripe
                'payment_handle_comment' => 'Void attempt',
            ]);

            // Check if the charge is captured
            if (!$charge->captured) {
                // If the charge is not captured, cancel the PaymentIntent
                $paymentIntent->cancel();

                return [
                    'success' => true,
                    'refund_id' => $voidAttempt->id,
                    'charge_id' => $charge->id,
                    'transaction_status' => '1', // Void status
                    'note' => $paymentIntent->id,
                    'gateway' => $originalPayment->gateway ?? 'Stripe',
                    'status' => $paymentIntent->status,
                    'refunded_amount' => $originalPayment->amount,
                ];
            }

            // If the charge was already captured, return an error message
            return $this->handleError($voidAttempt ?? null, 'Transaction is already captured, cannot void.', Response::HTTP_CONFLICT, 'Void_failed');

        } catch (\Exception $e) {
            return $this->handleError($voidAttempt ?? null, $e);
        }
    }

    /**
     * Validates the original payment and checks for void/refund status with external transaction support.
     *
     * @param array $paymentData Payment data including transaction_id and user_id
     * @param bool $isVoid Whether this is a void transaction validation (default: false)
     * @return object The validated original payment (PaymentAttempt or external transaction object)
     * @throws \Exception If validation fails
     */
    private function validateOriginalPaymentV2(array $paymentData, bool $isVoid = false)
    {
        // First, try to fetch from our local DB (new transactions)
        $originalPayment = PaymentAttempt::where([
            ['transaction_id', $paymentData['transaction_id']],
            ['user_id', $paymentData['user_id']],
            ['status', CommonConstant::STATUS_PAID],
        ])->first();

        if ($originalPayment) {
            // Found in our DB - perform status checks
            $this->validatePaymentStatusV2($originalPayment, $isVoid);
            return $originalPayment;
        }

        // Not in our DB - handle as external transaction
        \Log::info('Stripe transaction not found in local DB, checking as external transaction', [
            'transaction_id' => $paymentData['transaction_id'],
            'user_id' => $paymentData['user_id'],
            'operation' => $isVoid ? 'void' : 'refund'
        ]);

        return $this->handleExternalStripeTransaction($paymentData, $isVoid);
    }

    /**
     * Validates the status of an existing payment attempt for refunds/voids.
     *
     * @param PaymentAttempt $originalPayment The original payment to validate
     * @param bool $isVoid Whether this is a void transaction validation
     * @throws \Exception If validation fails
     */
    private function validatePaymentStatusV2(PaymentAttempt $originalPayment, bool $isVoid = false): void
    {
        // Check for void attempts (applies to both void and refund operations)
        $existingVoid = PaymentAttempt::where([
            ['transaction_id', $originalPayment->transaction_id],
            ['status', CommonConstant::STATUS_VOID]
        ])->exists();

        if ($existingVoid) {
            $message = $isVoid
                ? "This transaction has already been voided."
                : "Unable to process refund since the payment with transaction ID {$originalPayment->transaction_id} has already been voided.";
            throw new \Exception($message, Response::HTTP_BAD_REQUEST);
        }

        if ($isVoid) {
            // Void-specific validations: Can only void PAID payments
            if ($originalPayment->status !== CommonConstant::STATUS_PAID) {
                throw new \Exception("Transaction cannot be voided. Only paid transactions can be voided. Current status: {$originalPayment->status}", Response::HTTP_BAD_REQUEST);
            }
        } else {
            // Refund-specific validations: Check if fully refunded
            $totalRefunded = PaymentAttempt::where([
                ['transaction_id', $originalPayment->transaction_id],
                ['status', CommonConstant::STATUS_REFUND]
            ])->sum('amount');

            $totalRefundedAbs = abs($totalRefunded);

            if ($totalRefundedAbs >= $originalPayment->amount) {
                throw new \Exception("Payment with transaction ID {$originalPayment->transaction_id} has already been fully refunded.", Response::HTTP_BAD_REQUEST);
            }

            // Additional status validation for refunds - only block if original payment status is refund
            if ($originalPayment->status === CommonConstant::STATUS_REFUND) {
                throw new \Exception("Payment with transaction ID {$originalPayment->transaction_id} has already been refunded.", Response::HTTP_BAD_REQUEST);
            }
        }
    }

    /**
     * Handles those Stripe transactions that don't exist in our database.
     *
     * @param array $paymentData Payment data
     * @param bool $isVoid Whether this is a void operation
     * @return object Transaction object with PaymentAttempt-like properties
     * @throws \Exception If transaction is invalid or cannot be processed
     */
    private function handleExternalStripeTransaction(array $paymentData, bool $isVoid = false)
    {
        try {
            $charge = null;
            if (isset($paymentData['transaction_id']) && strstr($paymentData['transaction_id'], 'ch_')) {
                // For legacy charge IDs, retrieve the charge object directly
                $charge = \Stripe\Charge::retrieve($paymentData['transaction_id']);
            } else {
                $paymentIntent = PaymentIntent::retrieve($paymentData['transaction_id']);
                // Get the charge using latest_charge ID
                $charge = \Stripe\Charge::retrieve($paymentIntent->latest_charge);
            }

            if (empty($charge)) {
                throw new \Exception("No charge found for PaymentIntent: {$paymentData['transaction_id']}, make sure transaction id belongs to this user", Response::HTTP_NOT_FOUND);
            }

            if ($isVoid) {
                // For voids: charge must be paid but NOT captured (only uncaptured charges can be voided)
                if (!$charge->paid) {
                    throw new \Exception("Stripe charge is not paid. Status: paid={$charge->paid}", Response::HTTP_BAD_REQUEST);
                }
                if ($charge->captured) {
                    throw new \Exception("Stripe charge is already captured, cannot void. Use refund instead.", Response::HTTP_BAD_REQUEST);
                }
            } else {
                // For refunds: charge must be paid and captured
                if (!$charge->paid || !$charge->captured) {
                    throw new \Exception("Stripe charge is not paid or not captured. Status: paid={$charge->paid}, captured={$charge->captured}", Response::HTTP_BAD_REQUEST);
                }

                // Check if fully refunded by comparing amounts
                if ($charge->refunded && $charge->amount_refunded >= $charge->amount) {
                    throw new \Exception("Stripe charge has been fully refunded. Amount: {$charge->amount}, Refunded: {$charge->amount_refunded}", Response::HTTP_BAD_REQUEST);
                }
            }

            // Create a PaymentAttempt-like object for external transaction
            $externalPayment = (object) [
                'user_id' => $paymentData['user_id'],
                'store_id' => $paymentData['store_id'] ?? null,
                'transaction_id' => $paymentData['transaction_id'],
                'charge_id' => $charge->id,
                'amount' => $this->convertFromCents($charge->amount),
                'member_email' => $paymentData['member_email'] ?? $charge->billing_details->email ?? null,
                'member_name' => $paymentData['member_name'] ?? $charge->billing_details->name ?? null,
                'temp_order_number' => null,
                'gateway' => 'Stripe',
                'status' => CommonConstant::STATUS_PAID,
                'is_external' => true // Flag to identify external transactions
            ];

            \Log::info("Processing " . ($isVoid ? 'void' : 'refund') . " for external Stripe transaction", [
                'transaction_id' => $paymentData['transaction_id'],
                'charge_id' => $charge->id,
                'amount' => $externalPayment->amount,
                'status' => $charge->status,
                'operation' => $isVoid ? 'void' : 'refund',
            ]);

            return $externalPayment;

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            \Log::error('External Stripe transaction not found', [
                'transaction_id' => $paymentData['transaction_id'],
                'error' => $e->getMessage()
            ]);
            throw new \Exception("External Stripe transaction not found: {$paymentData['transaction_id']}", Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            \Log::error('Failed to process external Stripe transaction', [
                'transaction_id' => $paymentData['transaction_id'],
                'error' => $e->getMessage()
            ]);
            throw new \Exception("External Stripe transaction invalid: {$paymentData['transaction_id']}. Error: {$e->getMessage()}", Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Retrieve transaction details from Stripe based on a given transaction ID and user ID.
     *
     * @param array $paymentData An associative array containing:
     *                           - 'transaction_id': string, the Stripe PaymentIntent ID
     *                           - 'user_id': int|string, the ID of the user associated with the payment
     *
     * @return array An array containing:
     *               - 'success' => true and 'transaction_details' => Stripe\PaymentIntent on success
     *               - Or an error structure returned by $this->handleError on failure
     *
     * @throws \Stripe\Exception\ApiErrorException If the Stripe API request fails
     * @throws \Exception For any other unexpected errors
     */
    public function getTransactionDetails(array $paymentData): array
    {
        $isLive = filter_var($paymentData['isLive'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        // Fetch original payment
        $originalPayment = PaymentAttempt::where(
            'user_id',
            $paymentData['user_id']
        )->where(function ($query) use ($paymentData) {
            $query->where('transaction_id', $paymentData['transaction_id'])
                ->orWhere('refund_void_transaction_id', $paymentData['transaction_id']);
        })->select(['store_id', 'temp_order_number', 'member_email', 'member_name', 'gateway', 'amount', 'card_last_4_digit', 'card_expire_date', 'transaction_id', 'charge_id', 'status', 'comment', 'payment_handle_comment'])->first();

        if (!$originalPayment) {
            return $this->handleError(null, "Store payment not found for transaction ID: {$paymentData['transaction_id']}", Response::HTTP_NOT_FOUND, 'payment_not_found');
        }

        if (!$isLive) {
            return [
                'success' => true,
                'transaction_details' => $originalPayment
            ];
        }

        try {
            // Set Stripe API key based on environment (handled by factory or here)
            $paymentIntent = PaymentIntent::retrieve($originalPayment->transaction_id);
            $transactionDetails = array_merge($originalPayment->toArray(), [
                'live_details' => $paymentIntent
            ]);
            return [
                'success' => true,
                'transaction_details' => $transactionDetails
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return $this->handleError($originalPayment, $e->getMessage(), $e->getHttpStatus(), $e->getError()->code);
        } catch (\Exception $e) {
            \Log::info('Error', [$e->getMessage()]);
            return $this->handleError($originalPayment, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR, 'unknown_error');
        }

    }


    /**
     * Creates a PaymentIntent with the specified amount.
     *
     * @param int $amount The payment amount to be charged.
     *
     * @return \Stripe\PaymentIntent|\array The created PaymentIntent or an error message.
     */
    private function createPaymentIntent(float $amount, string $currency, $options = [])
    {
        try {
            $amountInCents = $this->convertToCents($amount);

            return PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $currency,
                'description' => 'Payment for order',
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => $options['metadata'] ?? [],
            ]);
        } catch (\Exception $e) {
            return ['error' => $e];
        }
    }

    /**
     * Confirms the PaymentIntent with the provided payment method ID.
     *
     * @param string $paymentIntentId The ID of the PaymentIntent to confirm.
     * @param string $paymentMethodId The ID of the payment method used to confirm the payment.
     *
     * @return \Stripe\PaymentIntent|\array The confirmed PaymentIntent or an error message.
     */
    private function confirmPaymentIntent($paymentIntentId, $paymentMethodId)
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            return $paymentIntent->confirm([
                'payment_method_data' => [
                    'type' => 'card',
                    'card' => [
                        'token' => $paymentMethodId,
                    ],
                ],
                'error_on_requires_action' => true,
            ]);
        } catch (\Exception $e) {
            return ['error' => $e];
        }
    }

    /**
     * Handles and formats errors for payment operations with proper HTTP status codes
     *
     * @param \App\Models\PaymentAttempt|null $paymentAttempt
     * @param mixed $error
     * @return array
     */
    private function handleError($paymentAttempt, $error, $httpStatusCode = Response::HTTP_INTERNAL_SERVER_ERROR, $errorCode = null)
    {
        // Default values
        $errorMessage = 'Payment processing failed';
        $status = CommonConstant::STATUS_ERROR;

        // Handle different error types
        if ($error instanceof \Stripe\Exception\CardException) {
            $stripeError = $error->getError();
            $errorCode = $stripeError->code ?? 'card_error';
            $errorMessage = $stripeError->message ?? 'Card processing failed';
            $httpStatusCode = Response::HTTP_PAYMENT_REQUIRED; // 402
        } elseif ($error instanceof \Stripe\Exception\InvalidRequestException) {
            $errorCode = $error->getStripeCode() ?? 'invalid_request';
            $errorMessage = $error->getMessage();
            $httpStatusCode = Response::HTTP_BAD_REQUEST; // 400
        } elseif ($error instanceof \Stripe\Exception\AuthenticationException) {
            $errorCode = 'authentication_error';
            $errorMessage = 'Stripe authentication failed';
            $httpStatusCode = Response::HTTP_UNAUTHORIZED; // 401
        } elseif ($error instanceof \Exception) {
            $errorCode = $error->getCode() !== 0 ? $error->getCode() : 'operation_failed';
            $errorMessage = $error->getMessage();

        } else {
            $errorMessage = $error;
        }

        // Update payment attempt if provided
        if ($paymentAttempt) {
            $paymentAttempt->update([
                'status' => $status,
                'comment' => $errorMessage,
                'payment_handle_comment' => 'Payment processing failed',
            ]);
        }

        return [
            'success' => false,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'http_status' => $httpStatusCode,
        ];
    }

    private function convertToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function convertFromCents(int $amountInCents): float
    {
        return $amountInCents / 100;
    }
}
