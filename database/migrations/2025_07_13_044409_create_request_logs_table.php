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
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->nullable();
            $table->string('url');
            $table->string('method');
            $table->string('ip');
            $table->json('request')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('response_status');
            $table->json('headers')->nullable();
            $table->json('queries')->nullable();
            $table->json('response')->nullable();
            $table->json('query_parameters')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
