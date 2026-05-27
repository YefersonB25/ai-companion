<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('default_provider')->default('claude');
            $table->string('default_model')->default('claude-sonnet-4-6');
            $table->string('language')->default('es');
            $table->string('timezone')->default('America/Bogota');
            $table->boolean('memory_enabled')->default(true);
            $table->boolean('auto_title')->default(true);       // auto-generate conversation titles
            $table->boolean('stream_responses')->default(true); // streaming vs full response
            $table->json('routing_rules')->nullable();          // task-based routing rules
            $table->json('persona')->nullable();                // custom assistant persona/system prompt
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
