<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    use DatabaseTransactions;

    /**
     * Boot the testing helper traits.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Seed the database with payment gateways only if not already seeded
        if (!static::isPaymentGatewaysSeeded()) {
            Artisan::call('db:seed', ['--class' => 'PaymentGatewaySeeder', '--force' => true]);
        }
    }

    /**
     * Check if payment gateways are already seeded to avoid duplicate seeding.
     */
    protected static function isPaymentGatewaysSeeded(): bool
    {
        return \App\Models\PaymentGateway::count() > 0;
    }
}
