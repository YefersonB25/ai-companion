<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('tts_provider')->nullable()->after('calendar_alerts_enabled');
            $table->string('tts_voice')->nullable()->after('tts_provider');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['tts_provider', 'tts_voice']);
        });
    }
};
