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
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn(['parent', 'required_keys']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->string('parent')->nullable()->after('id');
            $table->json('required_keys')->nullable()->after('description');
        });
    }
};
