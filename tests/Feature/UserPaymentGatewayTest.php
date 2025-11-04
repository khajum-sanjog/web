<?php

namespace Tests\Feature;

use DB;
use Tests\TestCase;
use App\Models\User;
use App\Models\PaymentGateway;
use App\Models\UserPaymentGateway;
use Tests\Helpers\PaymentGatewayHelper;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Class UserPaymentGatewayTest
 *
 * @package Tests\Feature
 */
class UserPaymentGatewayTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test creating a user payment gateway.
     *
     * This test ensures that a user can create a new payment gateway association
     * with their credentials and is_live_mode status.
     *
     * @return void
     */
    public function test_create_user_payment_gateway(): void
    {
        // Create Stripe and Google payment gateway instances for testing.
        $stripePaymentGateway = PaymentGateway::factory()->create(PaymentGatewayHelper::getPaymentGatewayData()[0]);

        $token = PaymentGatewayHelper::registerUser($this, 2);

        //create a user payment gateway.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json'
        ])
        ->post('/api/user-payment-gateways', [
            'payment_gateway_id' => $stripePaymentGateway->id,
            'credentials' => [
                'publishable_key' => 'test_publishable_key',
                'secret_key' => 'test_secret_key',
                'gMerchant_id' => 'test_gmerchant_id'
            ],
            'has_card_pay' => '1',
            'has_google_pay' => '1',
            'has_apple_pay' => '0',
            'has_pos_pay'   => '0',
            'is_live_mode' => '1'
        ]);

        //  Check if the user payment gateway is created successfully.
        $response->assertStatus(201); // Assert status 201 (Created)
        $response->assertJson([
            'message' => 'User payment gateway created successfully',
        ]);
    }

    /**
     * Test updating an existing user payment gateway's live mode.
     *
     * This test ensures that a user can update the `is_live_mode` of an existing
     * payment gateway.
     *
     * @return void
     */
    public function test_update_existing_user_payment_gateway_live_mode(): void
    {
        // Arrange: Create a user, payment gateway, and an existing user payment gateway
        $paymentGateway = PaymentGateway::factory()->create(PaymentGatewayHelper::getPaymentGatewayData()[0]);

        $token = PaymentGatewayHelper::registerUser($this, 1);
        $this->assertNotNull($token, 'Access token not found in login response');
        $user = User::where('store_id', 1)->first();

        $userPaymentGateway = UserPaymentGateway::factory()->create([
            'user_id' => $user->id,
            'payment_gateway_id' => $paymentGateway->id,
            'payment_gateway_name' => $paymentGateway->name,
            'created_by' => $user->id,
            'status' => '1',
            'is_live_mode' => '0',
        ]);

        // Act: Update the user payment gateway
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->post('/api/user-payment-gateways', [
            'payment_gateway_id' => $paymentGateway->id,
            'credentials' => [
                'publishable_key' => 'new_publishable_key',
                'secret_key' => 'new_secret_key',
            ],
            'has_card_pay' => '1',
            'has_google_pay' => '0', // Google Pay is a separate gateway
            'has_apple_pay' => '0',  // Apple Pay is a separate gateway
            'has_pos_pay' => '0',
            'is_live_mode' => '1',
        ]);

        // Assert: Check if the user payment gateway was updated successfully
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJson([
            'message' => 'User payment gateway updated successfully',
        ]);

        // Assert: Verify the updated data in the database
        $this->assertDatabaseHas('user_payment_gateways', [
            'user_id' => $user->id,
            'payment_gateway_id' => $paymentGateway->id,
            'is_live_mode' => '1',
        ]);
    }

    /**
     * Test the update functionality of the payment gateway method for a user.
     *
     * This test ensures that the payment gateway method can be updated successfully
     * and validates the expected behavior after the update operation.
     *
     * @return void
     */
    public function test_update_payment_gateway_method(): void
    {
        // Arrange: Create a user and a payment gateway
        $stripePaymentGateway = PaymentGateway::factory()->create(PaymentGatewayHelper::getPaymentGatewayData()[0]);
        $authorizePaymentGateway = PaymentGateway::factory()->create(PaymentGatewayHelper::getPaymentGatewayData()[1]);

        $token = PaymentGatewayHelper::registerUser($this, 2);
        $this->assertNotNull($token, 'Access token not found in login response');

        $user = User::where('store_id', 2)->first();

        // Create an active Stripe UserPaymentGateway for the user as stripe
        $userPaymentGateway = UserPaymentGateway::factory()->create([
            'user_id' => $user->id,
            'payment_gateway_id' => $stripePaymentGateway->id,
            'payment_gateway_name' => $stripePaymentGateway->name,
            'created_by' => $user->id,
            'status' => '1',
        ]);

        // Act: Switch the user's payment gateway from Stripe to Authorize.net
        // This simulates updating the payment method and credentials for the user.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->post('/api/user-payment-gateways', [
            'payment_gateway_id' => $authorizePaymentGateway->id,
            'credentials' => [
            'login_id' => '12345',
            'transaction_key' => '678905633',
            'client_key' => 'exampleClientKey2345',
            'paymentGateway_id' => '5698556',
            ],
            'has_card_pay' => '1',
            'has_google_pay' => '1',
            'has_apple_pay' => '0',
            'has_pos_pay' => '0',
        ]);

        // Assert: Check if the user payment gateway was updated successfully
        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJson([
            'message' => 'User payment gateway created successfully',
        ]);

        // Assert: Check that the 'user_payment_gateways' table contains the old Stripe record with status '0' (deactivated).
        $this->assertDatabaseHas('user_payment_gateways', [
           'user_id' => $user->id,
           'payment_gateway_id' => $stripePaymentGateway->id,
           'status' => '0'
        ]);

        // Assert: Verify the updated data in the database
        $this->assertDatabaseHas('user_payment_gateways', [
            'user_id' => $user->id,
            'payment_gateway_id' => $authorizePaymentGateway->id,
            'status' => '1'
        ]);

        // Retrieve the newly created active Authorize.net user payment gateway record
        $userPaymentGatewayRecord = UserPaymentGateway::where([
            'payment_gateway_id' => $authorizePaymentGateway->id,
            'user_id' => $user->id,
            'status' => '1'
        ])->select('id')->first();

        // Assert: Check that the 'user_payment_credentials' table contains the expected credential records for the new user payment gateway.
        $this->assertDatabaseHas('user_payment_credentials', [
            'user_payment_gateway_id' => $userPaymentGatewayRecord->id,
            'key' => 'login_id',
            'value' => '12345',
        ]);
        $this->assertDatabaseHas('user_payment_credentials', [
            'user_payment_gateway_id' => $userPaymentGatewayRecord->id,
            'key' => 'transaction_key',
            'value' => '678905633',
        ]);
        $this->assertDatabaseHas('user_payment_credentials', [
            'user_payment_gateway_id' => $userPaymentGatewayRecord->id,
            'key' => 'client_key',
            'value' => 'exampleClientKey2345',
        ]);
        $this->assertDatabaseHas('user_payment_credentials', [
            'user_payment_gateway_id' => $userPaymentGatewayRecord->id,
            'key' => 'paymentGateway_id',
            'value' => '5698556',
        ]);

        // Assert: Check that the user_payment_settings table contains correct settings for the new gateway
        $this->assertDatabaseHas('user_payment_settings', [
            'user_payment_gateway_id' => $userPaymentGatewayRecord->id,
            'payment_type' => 'has_card_pay',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('user_payment_settings', [
            'user_payment_gateway_id' => $userPaymentGatewayRecord->id,
            'payment_type' => 'has_google_pay',
            'value' => '1',
        ]);
    }
}
