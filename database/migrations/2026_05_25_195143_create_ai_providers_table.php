<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // claude, openai, deepseek, gemini, mistral
            $table->string('model');    // e.g. claude-sonnet-4-6, gpt-4o, deepseek-chat
            $table->text('api_key');    // encrypted
            $table->string('base_url')->nullable(); // for custom endpoints
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(0); // fallback order
            $table->json('config')->nullable();       // temperature, max_tokens, etc.
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'model']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
