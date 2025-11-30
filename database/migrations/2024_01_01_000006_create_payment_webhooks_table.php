<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('payment_id');
            $table->enum('status', ['success', 'failure']);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['idempotency_key']);
            $table->index(['payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};