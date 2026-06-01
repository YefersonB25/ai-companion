<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('licenses_required')->default(false);
            $table->string('whatsapp_number')->default('');
            $table->unsignedInteger('price_monthly_cop')->default(50000);
            $table->unsignedInteger('price_yearly_cop')->default(480000);
            $table->json('license_features')->nullable();
            $table->timestamps();
        });

        // Seed singleton row
        DB::table('license_settings')->insert([
            'licenses_required' => false,
            'whatsapp_number'   => '',
            'price_monthly_cop' => 50000,
            'price_yearly_cop'  => 480000,
            'license_features'  => json_encode([
                'Acceso completo a todos los modelos de IA',
                'Memoria semántica ilimitada',
                'App móvil Android e iOS',
                'Integración con Telegram',
                'Briefing diario personalizado',
                'Actualizaciones incluidas',
                'Soporte prioritario',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('license_settings');
    }
};
