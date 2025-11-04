<?php

namespace Tests\Helpers;

use App\Models\PaymentGateway;
use Symfony\Component\HttpFoundation\Response;

class PaymentGatewayHelper
{
    /**
     * Retrieves the payment gateway data for testing purposes.
     *
     * This method is used within feature tests to obtain mock or sample data
     * related to the payment gateway, which can be used to simulate user payment
     * interactions and validate gateway integration.
     *
     * @return array The payment gateway data required for the test cases.
     */
    public static function getPaymentGatewayData(): array
    {
        return [
            [
                'name' => 'Stripe',
                'description' => '',
                'image' => '',
                'status' => '1',
                'type' => '0',
                'slug' => '',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => '2025-06-16 04:39:35',
                'updated_at' => '2025-06-16 04:39:35',
            ],
            [
                'name' => 'Authorize.net',
                'description' => '',
                'image' => '',
                'status' => '1',
                'type' => '0',
                'slug' => '',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => '2025-06-16 04:39:35',
                'updated_at' => '2025-06-16 04:39:35',
            ],
            [
                'name' => 'Google Pay',
                'description' => '',
                'image' => '',
                'status' => '1',
                'type' => '1',
                'slug' => 'has_google_pay',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => '2025-06-16 04:39:35',
                'updated_at' => '2025-06-16 04:39:35',
            ],
            [
                'name' => 'Apple Pay',
                'description' => '',
                'image' => '',
                'status' => '1',
                'type' => '1',
                'slug' => 'has_apple_pay',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => '2025-06-16 04:39:35',
                'updated_at' => '2025-06-16 04:39:35',
            ],

        ];
    }

    /**
     * Registers a user for payment gateway testing purposes.
     *
     * @param mixed $testInstance The test instance used to perform assertions or setup during registration.
     * @param int|null $storeId Optional store ID, will generate random if not provided
     * @return string The access token returned after successful registration.
     */
    public static function registerUser($testInstance, $storeId = null)
    {
        $response = $testInstance->withHeaders(['Accept' => 'application/json'])
                    ->post('/api/register', [
                        'name' => fake()->name(),
                        'store_id' => $storeId ?? fake()->unique()->numberBetween(1000, 999999),
                        'store_name' => fake()->company(),
                        'domain_name' => fake()->unique()->domainName(),
                        'status' => '1',
                    ]);
        $response->assertStatus(Response::HTTP_OK);

        $responseContent = json_decode($response->getContent(), true);
        $token = $responseContent['data']['access_token']; // Extract the token from the response

        return $token;
    }

    /**
     * Creates a user payment gateway for Stripe testing purposes.
     *
     * @param mixed $testInstance The test instance used to perform assertions or setup
     * @param int|null $storeId Optional store ID for user registration
     * @return \Illuminate\Testing\TestResponse The response from creating the user payment gateway
     */
    public static function createUserPaymentGatewayForStripe($testInstance, $storeId = null)
    {
        $paymentGateway = PaymentGateway::factory()->create(self::getPaymentGatewayData()[0]);

        $token = self::registerUser($testInstance, $storeId ?? 2);

        // Create a user payment gateway.
        $response = $testInstance->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])
        ->post('/api/user-payment-gateways', [
            'payment_gateway_id' => $paymentGateway->id,
            'credentials' => [
                'publishable_key' => 'test_publishable_key',
                'secret_key' => 'test_secret_key',
                'gMerchant_id' => 'test_gMerchant',
                'aMerchant_id' => 'test_aMerchant'
            ],
            'has_card_pay' => '1',
            'has_google_pay' => '1',
            'has_apple_pay' => '1',
            'has_pos_pay'   => '0',
            'is_live_mode' => '0'
        ]);

        // Check if the user payment gateway is created successfully.
        $response->assertStatus(201); // Assert status 201 (Created)
        return $token;
    }
}
