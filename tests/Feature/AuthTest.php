<?php

namespace Tests\Feature;

use Symfony\Component\HttpFoundation\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\PaymentGateway;
use App\Models\UserPaymentGateway;
use App\Models\User;

class AuthTest extends TestCase
{
    // Enable the DatabaseTransactions trait for rolling back transactions after each test
    use DatabaseTransactions;

    /**
     * Test user registration
     */
    public function test_register_user()
    {
        $this->post('/api/register', $this->getUserDataFaker())
            ->assertStatus(Response::HTTP_OK)
            ->assertJson([
                'type' => 'success',
                'message' => 'Token generated successfully'
            ]);
    }

    /**
     * Test returns validation error when required fields are missing
     */
    public function test_returns_validation_error_when_required_fields_are_missing()
    {
        $response = $this->postJson('/api/register', []);

        $response->assertStatus(400)
            ->assertJsonStructure(['message', 'errors']);
    }

    /**
     * Test successful login with valid credentials
     */
    // public function test_login_user()
    // {
    //     $user = User::factory()->create();

    //     $this->withHeader('accept', 'application/json')
    //         ->post('/api/login', [
    //             'email' => $user->email,
    //             'password' => 'password'
    //         ])
    //         ->assertStatus(Response::HTTP_OK);
    // }

    /**
     * Test login with invalid credentials
     */
    public function test_login_with_invalid_credentials()
    {
        $user = User::factory()->create();

        $this->withHeader('accept', 'application/json')
            ->post('/api/login', [
                'email' => $user->email,
                'password' => '123456password'
            ])
            ->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJson([
                'type' => 'error',
                'message' => 'Invalid credentials',
                'errors' => []
            ]);
    }

    /**
     * Test refresh token functionality
     */
    // public function test_refresh_token()
    // {
    //     $user = User::factory()->create();

    //     $response = $this->withHeader('accept', 'application/json')
    //         ->post('/api/login', [
    //             'email' => $user->email,
    //             'password' => 'password'
    //         ])
    //         ->assertStatus(Response::HTTP_OK);

    //     $responseContent = json_decode($response->getContent(), true);
    //     $token = $responseContent['data']['access_token'];

    //     $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'accept' => 'application/json'])
    //         ->post('/api/refresh')
    //         ->assertStatus(Response::HTTP_OK);
    // }

    /**
     * Generate fake user registration data
     *
     * @return array
     */
    private function getUserDataFaker(): array
    {
        return [
            'name'        => fake()->name(),
            'store_id'    => '123',
            'store_name'  => fake()->name(),
            'domain_name' => 'voxmg.com',
            'status'      => '1'
        ];
    }
}
