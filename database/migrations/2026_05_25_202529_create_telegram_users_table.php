<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('telegram_id')->unique();      // Telegram chat_id
            $table->string('telegram_username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->foreignId('active_conversation_id')  // last active conversation
                ->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('state')->default('idle');    // idle, awaiting_email, awaiting_password, awaiting_link_code
            $table->json('state_data')->nullable();      // temporary state data
            $table->timestamps();

            $table->index('telegram_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
