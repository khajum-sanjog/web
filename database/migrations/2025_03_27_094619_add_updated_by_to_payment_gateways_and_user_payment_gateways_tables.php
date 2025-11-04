<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUpdatedByToPaymentGatewaysAndUserPaymentGatewaysTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add `updated_by` column to the `payment_gateways` table
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->unsignedBigInteger('updated_by')->default(0)->after('created_by');
        });

        // Add `updated_by` column to the `user_payment_gateways` table
        Schema::table('user_payment_gateways', function (Blueprint $table) {
            $table->unsignedBigInteger('updated_by')->default(0)->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remove the `updated_by` column from the `payment_gateways` table
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn('updated_by');
        });

        // Remove the `updated_by` column from the `user_payment_gateways` table
        Schema::table('user_payment_gateways', function (Blueprint $table) {
            $table->dropColumn('updated_by');
        });
    }
}
