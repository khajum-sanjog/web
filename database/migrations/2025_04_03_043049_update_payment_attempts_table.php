<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePaymentAttemptsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            // Drop the `badge_id` column
            $table->dropColumn('badge_id');

            // Add the `member_email` column
            $table->string('member_email')->nullable()->after('temp_order_number');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            // Re-add the `badge_id` column
            $table->unsignedBigInteger('badge_id')->nullable()->after('temp_order_number');

            // Drop the `member_email` column
            $table->dropColumn('member_email');
        });
    }
}
