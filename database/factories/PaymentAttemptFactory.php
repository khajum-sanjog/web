<?php

namespace Database\Factories;

use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Constant\commonConstant;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => 1,
            'store_id' => $this->faker->numberBetween(1, 100),
            'temp_order_number' =>$this->faker->numberBetween(100, 10000),
            'member_email' => $this->faker->safeEmail,
            'member_name' => $this->faker->name,
            'gateway' => 'authorize.net',
            'amount' => $this->faker->randomFloat(2, 10, 500),
            'card_last_4_digit' => substr($this->faker->creditCardNumber, -4),
            'card_expire_date' => $this->faker->creditCardExpirationDateString,
            'transaction_id' => $this->faker->unique()->numerify('TXN#####'),
            'refund_void_transaction_id' => null,
            'charge_id' => $this->faker->unique()->regexify('[A-Z0-9]{6}'),
            'status' => $this->faker->randomElement([
                CommonConstant::STATUS_PAID,
                CommonConstant::STATUS_HANDLED,
                CommonConstant::STATUS_REFUND,
                CommonConstant::STATUS_ATTEMPT,
                CommonConstant::STATUS_ERROR,
                CommonConstant::STATUS_VOID,
            ]),
            'comment' => $this->faker->sentence,
            'payment_handle_comment' => $this->faker->sentence,
            'created_at' => $this->faker->dateTimeThisYear,
            'updated_at' => $this->faker->dateTimeThisYear,
        ];
    }
}
