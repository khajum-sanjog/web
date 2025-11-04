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
        Schema::table('user_payment_gateways', function (Blueprint $table) {
            $table->enum('has_card_pay', ['0', '1'])->default('1')->comment('0 = Inactive, 1 = Active')->after('is_live_mode');
            $table->enum('has_google_pay', ['0', '1'])->default('0')->comment('0 = Inactive, 1 = Active')->after('is_live_mode');
            $table->enum('has_apple_pay', ['0', '1'])->default('0')->comment('0 = Inactive, 1 = Active')->after('is_live_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_payment_gateways', function (Blueprint $table) {
            $table->dropColumn('has_card_pay');
            $table->dropColumn('has_google_pay');
            $table->dropColumn('has_apple_pay');
        });
    }
};
