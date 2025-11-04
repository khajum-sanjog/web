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
        Schema::create('user_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('payment_gateway_name');
            $table->text('credentials');
            $table->enum('status', ['0', '1'])->default('1')->comment('0 = Inactive, 1 = Active');
            $table->enum('is_live_mode', ['0', '1'])->default('1')->comment('0 = Inactive, 1 = Active');
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_payment_gateways');
    }
};
