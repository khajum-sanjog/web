<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_gateway_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent')->nullable()->constrained('payment_gateways')->cascadeOnDelete();
            $table->string('key_name')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_keys');
    }
};
