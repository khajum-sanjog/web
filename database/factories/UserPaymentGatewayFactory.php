<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserPaymentGateway>
 */
class UserPaymentGatewayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => 1,
            'payment_gateway_id' => 1,
            'payment_gateway_name' => 'Stripe',
            'created_by' => 1,
            'status' => '1',
            'is_live_mode' => '1',
            'status' => '1',
        ];
    }
}
