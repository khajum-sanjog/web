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
        Schema::create('user_payment_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_payment_gateway_id');
            $table->foreign('user_payment_gateway_id')
                ->references('id')
                ->on('user_payment_gateways')
                ->onDelete('cascade');
            $table->string('key');
            $table->string('value');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_payment_credentials');
    }
};
