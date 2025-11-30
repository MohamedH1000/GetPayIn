<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('quantity');
            $table->timestamp('expires_at');
            $table->enum('status', ['active', 'expired', 'converted'])->default('active');
            $table->string('hold_token')->unique();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['expires_at', 'status']);
            $table->index(['hold_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};