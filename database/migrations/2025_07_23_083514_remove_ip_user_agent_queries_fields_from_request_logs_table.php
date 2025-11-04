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
        Schema::table('request_logs', function (Blueprint $table) {
            // Drop columns
            $table->dropColumn([
                'ip',
                'user_agent',
                'queries',
            ]);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('queries')->nullable();
        });
    }
};
