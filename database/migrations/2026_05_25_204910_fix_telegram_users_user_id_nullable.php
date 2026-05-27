<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite doesn't support ALTER COLUMN — recreate the table
        Schema::drop('telegram_users');

        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('telegram_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->foreignId('active_conversation_id')
                ->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('state')->default('idle');
            $table->json('state_data')->nullable();
            $table->timestamps();

            $table->index('telegram_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
