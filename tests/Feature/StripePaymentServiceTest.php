<?php

namespace Tests\Feature;

use Mockery;
use Tests\TestCase;
use App\Models\User;
use App\Models\PaymentGateway;
use App\Models\UserPaymentGateway;
use App\Services\StripePaymentService;
use App\Services\PaymentServiceFactory;
use Tests\Helpers\PaymentGatewayHelper;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class StripePaymentServiceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Helper function to mock PaymentServiceFactory and StripePaymentService.
     */
    protected function mockPaymentService($method, $returnValue)
    {
        $mockPaymentService = Mockery::mock(StripePaymentService::class);
        $mockPaymentService->shouldReceive($method)->andReturn($returnValue);

        $mockFactory = Mockery::mock(PaymentServiceFactory::class);
        $mockFactory->shouldReceive('getService')->andReturn($mockPaymentService);

        // Bind mocks into container
        $this->app->instance(PaymentServiceFactory::class, $mockFactory);
    }

    /**
     * Test payment process when everything is successful.
     *
     * @test
     */
    public function test_processes_payment_successfully()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Mock PaymentService for processPayment
        $this->mockPaymentService('processPayment', [
            'success' => true,
            'transaction_id' => 'pi_123456789',
            'charge_id' => 'ch_123456789',
            'status' => 'succeeded'
        ]);

        $payload = [
            'amount' => 100,
            'member_email' => 'test@example.com',
            'member_name' => 'Test User',
            'payment_method_id' => 'pm_12345'
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
                    'transaction_id' => 'pi_123456789',
                    'charge_id' => 'ch_123456789',
                    'status' => 'succeeded',
                ],
            ]);
    }

    /**
     * Test refund process when everything is successful.
     *
     * @test
     */
    public function test_processes_refund_successfully()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Mock PaymentService for refundTransaction
        $this->mockPaymentService('refundTransaction', [
            'success' => true,
            'refund_id' => 'refund_123456789',
            'status' => 'succeeded',
            'refunded_amount' => 100.00,
            'remaining_refundable' => 50.00,
        ]);

        $payload = [
            'transaction_id' => 'pi_123456789',
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
                    'refund_id' => 'refund_123456789',
                    'status' => 'succeeded',
                    'refunded_amount' => 100.00,
                    'remaining_refundable' => 50.00,
                ],
            ]);


    }

    /**
     * Test failure when no active payment gateway is found.
     *
     * @test
     */
    public function test_fails_when_no_active_payment_gateway_is_found()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        $payload = [
            'transaction_id' => 'pi_123456789',
            'amount' => 100.00,
        ];

        $refundResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])->post('/api/payments/refund', $payload);

        $refundResponse->assertStatus(404)
            ->assertJson([
                'type' => 'error',
                'message' => 'Refund processing failed',
            ]);
    }

    /**
     * Test process void transaction success.
     *
     * @test
     */

    public function test_processes_void_transaction_successfully()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Mock PaymentService for voidTransaction
        $this->mockPaymentService('voidTransaction', [
            'success' => true,
            'message' => 'Transaction voided successfully.',
            'transaction_id' => 'pi_123456789',
            'status' => 'voided',
        ]);

        $payload = [
            'transaction_id' => 'pi_123456789',
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
                    'transaction_id' => 'pi_123456789',
                    'status' => 'voided',
                ],
            ]);
    }

    /**
     * Test get  transaction details.
     *
     * @test
     */
    public function test_transaction_details()
    {
        $token = PaymentGatewayHelper::createUserPaymentGatewayForStripe($this, 1);

        // Mock PaymentService for voidTransaction
        $this->mockPaymentService('getTransactionDetails', [
            'success' => true,
            'transaction_details' => [
                'transaction_id' => 'pi_123456789',
                'status' => 'succeeded',
                'amount' => 5000
            ],
        ]);

        $payload = [
            'transaction_id' => 'pi_123456789',
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
                    'transaction_id' => 'pi_123456789',
                    'status' => 'succeeded',
                    'amount' => 5000
                ]
            ],
        ]);

    }
}
