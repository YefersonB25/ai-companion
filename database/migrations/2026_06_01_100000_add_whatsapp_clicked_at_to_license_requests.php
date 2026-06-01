<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_requests', function (Blueprint $table) {
            $table->timestamp('whatsapp_clicked_at')->nullable()->after('catalog_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('license_requests', function (Blueprint $table) {
            $table->dropColumn('whatsapp_clicked_at');
        });
    }
};
