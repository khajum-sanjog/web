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
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('store_id');
            $table->string('temp_order_number');
            $table->string('refund_void_transaction_id')->nullable();
            $table->unsignedBigInteger('badge_id')->nullable();
            $table->string('member_name')->nullable();
            $table->string('gateway');
            $table->decimal('amount', 10, 2);
            $table->string('card_last_4_digit')->nullable();
            $table->string('card_expire_date')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('charge_id')->nullable();
            $table->tinyInteger('status')->default(3)->comment('0: Paid, 1: Handled, 2: Refund, 3: Attempt, 4: Error, 5: Void');
            $table->text('comment')->nullable();
            $table->text('payment_handle_comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
