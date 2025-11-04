<?php

namespace Tests\Feature;

use Mockery;
use Tests\TestCase;
use App\Models\User;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Constant\commonConstant;
use App\Models\UserPaymentGateway;
use PHPUnit\Framework\Attributes\Test;
use App\Services\PaymentServiceFactory;
use Tests\Helpers\PaymentGatewayHelper;
use App\Services\AuthorizeNetPaymentService;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Class AuthorizeNetPaymentServiceTest
 *
 * Feature tests for payment processing via Authorize.net payment gateway.
 * These tests verify various payment operations like payment processing,
 * refunds, voids, and transaction detail retrieval.
 *
 * @package Tests\Feature
 */
class AuthorizeNetPaymentServiceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Helper function to mock the payment service and its factory.
     *
     * This method creates mock instances of AuthorizeNetPaymentService and
     * PaymentServiceFactory, configures them with expected behavior, and
     * binds them to the application container.
     *
     * @param string $method The method name to mock on the AuthorizeNetPaymentService
     * @param mixed $returnValue The value to return or exception to throw from the mocked method
     * @return void
     */
    protected function mockPaymentService($method, $returnValue)
    {
        $mockPaymentService = Mockery::mock(\App\Services\AuthorizeNetPaymentService::class);
        if ($returnValue instanceof \Exception) {
            // Throw exception
            $mockPaymentService->shouldReceive($method)->andThrow($returnValue);
        } else {
            // Return value
            $mockPaymentService->shouldReceive($method)->andReturn($returnValue);
        }

        $mockFactory = Mockery::mock(\App\Services\PaymentServiceFactory::class);
        $mockFactory->shouldReceive('getService')->andReturn($mockPaymentService);

        // Bind mocks into container
        $this->app->instance(\App\Services\PaymentServiceFactory::class, $mockFactory);
    }

    /**
     * Test successful payment processing through Authorize.net.
     *
     * This test verifies that a payment is processed successfully
     * and that the API returns the expected success response.
     *
     * @return void
     */
    public function test_processes_payment_successfully()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Mock PaymentService for processPayment
        $this->mockPaymentService('processPayment', [
            'success' => true,
            'transaction_id' => '120061795295',
            'charge_id' => 'V1D4RW',
            'status' => "0",
            'message' => 'Transaction processed successfully'
        ]);

        $payload = [
            'amount' => 100.00,
            'member_email' => 'test@example.com',
            'member_name' => 'Test User',
            'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            'payment_method_id' => 'eyJjb2RlIjoiNTBfMl8wNjAwMDUzRTlBN0FEMEJGRDgxODcyMDVEODk',
            'payment_method' => 'authorize.net'
        ];

        $paymentResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/process', $payload);

        $paymentResponse->assertStatus(200)
            ->assertJson([
                'type' => 'success',
                'message' => 'Payment processed successfully',
                'data' => [
                    'transaction_id' => '120061795295',
                    'charge_id' => 'V1D4RW',
                    'status' => "0",
                ],
            ]);
    }

    /**
     * Test successful refund processing through Authorize.net.
     *
     * This test verifies that a refund is processed successfully
     * and that the API returns the expected success response.
     *
     * @return void
     */
    public function test_processes_refund_successfully()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);
        $user = User::where('store_id', 1)->first();

        // Create original payment attempt
        $originalPayment = PaymentAttempt::factory()->create([
            'user_id' => $user->id,
            'gateway' => 'authorize.net',
            'transaction_id' => '120061795295',
            'amount' => 100.00,
            'status' => '0',
            'card_last_4_digit' => '1234',
            'card_expire_date' => '12/25'
        ]);

        // Mock PaymentService for refundTransaction
        $this->mockPaymentService('refundTransaction', [
            'success' => true,
            'transaction_id' => '120061787595',
            'status' => '2',
        ]);

        $payload = [
            'transaction_id' => '120061795295',
            'amount' => 100.00
        ];

        $refundResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/refund', $payload);

        $refundResponse->assertStatus(200)
            ->assertJson([
                'type' => 'success',
                'message' => 'Refund processed successfully',
                'data' => [
                    'transaction_id' => '120061787595',
                    'status' => '2',
                ],
            ]);
    }

    /**
     * Test failure case when no active payment gateway is found.
     *
     * This test verifies that the API correctly handles the situation
     * where a user has no active payment gateway configured.
     *
     * @return void
     */
    public function test_fails_when_no_active_payment_gateway_is_found()
    {
        $token = PaymentGatewayHelper::registerUser($this, 1);

        $payload = [
            'transaction_id' => '120061787595',
            'amount' => 100.00,
        ];

        $refundResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/refund', $payload);

        $refundResponse->assertStatus(404)
            ->assertJson([
                'type' => 'error',
                'message' => 'No active payment gateway found',
            ]);
    }

    /**
     * Test successful transaction void through Authorize.net.
     *
     * This test verifies that a transaction void is processed successfully
     * and that the API returns the expected success response.
     *
     * @return void
     */
    public function test_processes_void_transaction_successfully()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);
        $user = User::where('store_id', 1)->first();

        // Create original payment attempt
        $originalPayment = PaymentAttempt::factory()->create([
            'user_id' => $user->id,
            'gateway' => 'authorize.net',
            'transaction_id' => '120061787595',
            'amount' => 100.00,
            'status' => '0'
        ]);

        // Mock PaymentService for voidTransaction
        $this->mockPaymentService('voidTransaction', [
            'success' => true,
            'status' => '5',
        ]);

        $payload = [
            'transaction_id' => '120061787595',
        ];

        $voidResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json',
        ])->post('/api/payments/void', $payload);

        $voidResponse->assertStatus(200)
            ->assertJson([
                'type' => 'success',
                'message' => 'Transaction voided successfully.',
                'data' => [
                    'status' => '5',
                ],
            ]);
    }

    /**
     * Test successful retrieval of transaction details.
     *
     * This test verifies that transaction details are retrieved successfully
     * and that the API returns the expected success response with transaction data.
     *
     * @return void
     */
    public function test_transaction_details()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);
        $user = User::where('store_id', 1)->first();

        // Create original payment attempt
        $originalPayment = PaymentAttempt::factory()->create([
            'user_id' => $user->id,
            'gateway' => 'authorize.net',
            'transaction_id' => '120061787595',
            'amount' => 100.00,
            'status' => '0'
        ]);

        // Mock PaymentService for getTransactionDetails
        $this->mockPaymentService('getTransactionDetails', [
            'success' => true,
            'transaction_details' => [
                'transId' => '120067643295',
                'transactionStatus' => 'captured',
                'submitTimeUTC' => '2025-04-21T12:00:00Z',
                'invoiceNumber' => '12345',
                'payment' => [
                    'creditCard' => [
                        'cardNumber' => 'XXXX1234'
                    ]
                ]
            ],
        ]);

        $payload = [
            'transaction_id' => '120061787595',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json',
        ])->post('/api/payments/transactionDetails', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'type' => 'success',
                'message' => 'Transaction details retrieved successfully.',
                'data' => [
                    'transaction_details' => [
                        'transId' => '120067643295',
                        'transactionStatus' => 'captured',
                    ],
                ],
            ]);
    }

    /**
     * Test failure case when transaction is not found.
     *
     * This test verifies that the API correctly handles the situation
     * where a requested transaction cannot be found.
     *
     * @return void
     */
    public function test_fails_when_transaction_not_found()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Create the exception to throw
        $exception = new \Exception(
            'Store payment not found for transaction ID: 120067643295',
            Response::HTTP_NOT_FOUND
        );

        // Mock PaymentService to throw exception
        $this->mockPaymentService('getTransactionDetails', $exception);

        $payload = [
            'transaction_id' => '120067643295',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json',
        ])->post('/api/payments/transactionDetails', $payload);

        $response->assertStatus(404)
            ->assertJson([
                'type' => 'error',
                'message' => 'Store payment not found for transaction ID: 120067643295',
            ]);
    }

    /**
     * Test failure case when attempting to refund more than the original payment amount.
     *
     * This test verifies that the API correctly handles the situation when the requested refund amount exceeds the original payment amount.
     *
     * @return void
     */
    public function test_attempting_to_refund_more_than_the_original_payment_amount()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);
        $user = User::where('store_id', 1)->first();

        // Create original payment attempt
        $originalPayment = PaymentAttempt::factory()->create([
            'user_id' => $user->id,
            'gateway' => 'authorize.net',
            'transaction_id' => '120061795295',
            'amount' => 100.00,
            'status' => '0'
        ]);

        // Mock PaymentService for refundTransaction
        $this->mockPaymentService('refundTransaction', [
            'success' => false,
            "transaction_id" => "120061795295",
            "error_message" => "The sum of credits against the referenced transaction would exceed original debit amount.",
            "error_code" => "55",
            "status" => 4
        ]);

        $payload = [
            'transaction_id' => '120061795295',
            'amount' => 200.00
        ];

        $refundResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/refund', $payload);

        $refundResponse->assertStatus(500)
            ->assertJson([
                'type' => 'error',
                'message' => "Refund processing failed",
                "errors" => [
                    "transaction_id" => "120061795295",
                    "error_message" => "The sum of credits against the referenced transaction would exceed original debit amount.",
                    "error_code" => "55",
                    "status" => 4
                ]
            ]);
    }

    /**
     * Test refund attempt on a payment that has already been voided.
     *
     * @return void
     */
    public function test_refund_fails_when_payment_already_voided()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);
        $user = User::where('store_id', 1)->first();

        // Create original payment attempt
        $originalPayment = PaymentAttempt::factory()->create([
            'user_id' => $user->id,
            'gateway' => 'authorize.net',
            'transaction_id' => '120061795295',
            'amount' => 100.00,
            'status' => '0',
            'card_last_4_digit' => '1234',
            'card_expire_date' => '12/25'
        ]);

        // Create a void record for this payment
        PaymentAttempt::factory()->create([
            'user_id' => $user->id,
            'gateway' => 'authorize.net',
            'transaction_id' => '120061795295',
            'amount' => -100.00,
            'status' => CommonConstant::STATUS_VOID,
            'refund_void_transaction_id' => '120061795296'
        ]);

        // Create the exception to throw
        $exception = new \Exception(
            "Unable to process refund since the payment with transaction ID 120061795295 has already been voided.",
            Response::HTTP_BAD_REQUEST
        );

        // Mock PaymentService to throw exception
        $this->mockPaymentService('refundTransaction', $exception);

        $payload = [
            'transaction_id' => '120061795295',
            'amount' => 100.00
        ];

        $refundResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/refund', $payload);

        $refundResponse->assertStatus(400)
            ->assertJson([
                'type' => 'error',
                'message' => 'Unable to process refund since the payment with transaction ID 120061795295 has already been voided.',
            ]);
    }

    /**
     * Test payment processing with invalid payment token descriptor.
     *
     * @return void
     */
    public function test_payment_fails_with_invalid_descriptor()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Create the exception to throw
        $exception = new \Exception(
            'Invalid payment token descriptor',
            Response::HTTP_BAD_REQUEST
        );

        // Mock PaymentService to throw exception
        $this->mockPaymentService('processPayment', $exception);

        $payload = [
            'amount' => 100.00,
            'member_email' => 'test@example.com',
            'member_name' => 'Test User',
            'dataDescriptor' => 'INVALID.DESCRIPTOR',
            'payment_method_id' => 'eyJjb2RlIjoiNTBfMl8wNjAwMDUzRTlBN0FEMEJGRDgxODcyMDVEODk',
            'payment_method' => 'authorize.net'
        ];

        $paymentResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/process', $payload);

        $paymentResponse->assertStatus(400)
            ->assertJson([
                'type' => 'error',
                'message' => 'Invalid payment token descriptor',
            ]);
    }

    /**
     * Test payment processing with missing data value.
     *
     * @return void
     */
    public function test_payment_fails_with_missing_payment_method_id()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        $payload = [
            'amount' => 100.00,
            'member_email' => 'test@example.com',
            'member_name' => 'Test User',
            'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            // Missing payment_method_id
            'payment_method' => 'authorize.net'
        ];

        $paymentResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/process', $payload);

        // Assert validation failure
        $paymentResponse->assertStatus(422)
                 ->assertJson([
                     'message' => 'The payment method id field is required.',
                 ]);
    }

    /**
     * Test payment failure when Authorize.net connection fails or no response.
     *
     * @return void
     */
    public function test_payment_fails_when_connection_fails()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Mock PaymentService for processPayment with connection error
        $this->mockPaymentService('processPayment', [
            'success' => false,
            'transaction_id' => null,
            'charge_id' => null,
            'error_message' => 'Failed to connect to Authorize.net. Please check your credentials or try again later.',
            'error_code' => null,
            'status' => 4,
        ]);

        $payload = [
            'amount' => 100.00,
            'member_email' => 'test@example.com',
            'member_name' => 'Test User',
            'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            'payment_method_id' => 'eyJjb2RlIjoiNTBfMl8wNjAwMDUzRTlBN0FEMEJGRDgxODcyMDVEODk',
            'payment_method' => 'authorize.net'
        ];

        $paymentResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/process', $payload);

        $paymentResponse->assertStatus(500)
            ->assertJson([
                'type' => 'error',
                'message' => 'Payment processing failed',
                "errors" => [
                    "transaction_id" => null,
                    "charge_id" => null,
                    "error_message" => "Failed to connect to Authorize.net. Please check your credentials or try again later.",
                    "error_code" => null,
                    "status" => 4
                ]
            ]);
    }

    /**
     * Test payment failure when invalid credentials are provided to Authorize.net.
     *
     * @return void
     */
    public function test_payment_fails_with_invalid_credentials()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Mock PaymentService for processPayment with invalid credentials
        $this->mockPaymentService('processPayment', [
            'success' => false,
            'transaction_id' => null,
            'charge_id' => null,
            'error_message' => 'Invalid Authorize.net credentials provided.',
            'error_code' => 'E00007',
            'status' => CommonConstant::STATUS_ERROR,
        ]);

        $payload = [
            'amount' => 100.00,
            'member_email' => 'test@example.com',
            'member_name' => 'Test User',
            'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            'payment_method_id' => 'eyJjb2RlIjoiNTBfMl8wNjAwMDUzRTlBN0FEMEJGRDgxODcyMDVEODk',
            'payment_method' => 'authorize.net'
        ];

        $paymentResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/process', $payload);

        $paymentResponse->assertStatus(500)
            ->assertJson([
                'type' => 'error',
                'message' => 'Payment processing failed',
                'errors' => [
                    'transaction_id' => null,
                    'charge_id' => null,
                    'error_message' => 'Invalid Authorize.net credentials provided.',
                    'error_code' => 'E00007',
                    'status' => CommonConstant::STATUS_ERROR
                ]
            ]);
    }

}
