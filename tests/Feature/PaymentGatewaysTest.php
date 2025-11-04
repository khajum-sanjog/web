<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\PaymentGateway;
use Tests\Helpers\PaymentGatewayHelper;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Class PaymentGatewaysTest
 *
 * @package Tests\Feature
 */
class PaymentGatewaysTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test fetching the payment gateways.
     *
     * This test ensures that a user can fetch the list of active payment gateways
     * after authenticating using a valid token.
     *
     * @return void
     */
    public function test_fetch_payment_gateway(): void
    {
        $token = PaymentGatewayHelper::registerUser($this, 1);

        // Fetch the payment gateways using the obtained token.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])
        ->get('/api/payment-gateways');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Payment gateways fetched successfully.',
        ]);
    }
}
