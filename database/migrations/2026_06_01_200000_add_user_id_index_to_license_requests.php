<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_requests', function (Blueprint $table) {
            $table->index('user_id'); // Fix #25
        });
    }

    public function down(): void
    {
        Schema::table('license_requests', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
