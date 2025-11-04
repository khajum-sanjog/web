<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Constant\CommonConstant;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * StripeTerminalPaymentService handles payment processing for Stripe Terminal.
 * This includes creating PaymentIntents for card-present payments and updating payment attempts.
 */
class StripeTerminalPaymentService
{
    protected $secretKey;
    protected $isLiveMode;
    protected $currency;

    /**
     * Constructor to initialize Stripe API with the secret key and mode (live/test).
     *
     * @param string $secretKey The secret API key for Stripe.
     * @param string $isLiveMode The environment mode ('1' for live, '0' for test).
     * @param string $currency The currency (default: 'usd').
     */
    public function __construct(string $isLiveMode, string $currency = 'usd')
    {
        $this->isLiveMode = $isLiveMode;
        $this->currency = $currency;
    }

    /**
     * Create a PaymentIntent for a Terminal payment.
     *
     * @param float $amount The payment amount to be charged.
     * @param string $currency The currency (e.g., 'usd').
     * @return \Stripe\PaymentIntent|array The created PaymentIntent or an error message.
     */
    public function createPaymentIntent(float $amount, string $currency, $options = [])
    {
        try {
            $amountInCents = $this->convertToCents($amount);
            return PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $currency,
                'payment_method_types' => ['card_present'],
                'capture_method' => 'automatic',
                'description' => 'Payment via Stripe Terminal',
                'metadata' => $options['metadata'] ?? [],
            ]);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Convert an amount to cents.
     *
     * @param float $amount The amount in dollars.
     * @return int The amount in cents.
     */
    private function convertToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
