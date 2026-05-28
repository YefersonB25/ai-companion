<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('briefing_enabled')->default(false)->after('persona');
            $table->string('briefing_time')->default('08:00')->after('briefing_enabled');
            $table->string('briefing_city')->nullable()->after('briefing_time');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['briefing_enabled', 'briefing_time', 'briefing_city']);
        });
    }
};
