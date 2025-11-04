<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->index('store_id');
            $table->index('transaction_id');
            $table->index('charge_id');
            $table->index('status');
        });

        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('request_logs', function (Blueprint $table) {
            $table->index('store_id');
            $table->index('response_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('store_id');
            $table->index('domain_name');
            $table->index('status');
        });

        Schema::table('user_payment_gateways', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('user_payment_credentials', function (Blueprint $table) {
            $table->index('key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex('store_id');
            $table->dropIndex('transaction_id');
            $table->dropIndex('charge_id');
            $table->dropIndex('status');
        });

        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropIndex('status');
        });

        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropIndex('store_id');
            $table->dropIndex('response_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('store_id');
            $table->dropIndex('domain_name');
            $table->dropIndex('status');
        });

        Schema::table('user_payment_gateways', function (Blueprint $table) {
            $table->dropIndex('status');
        });

        Schema::table('user_payment_credentials', function (Blueprint $table) {
            $table->dropIndex('key');
        });
    }
};
