<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de secretos: SOLO METADATOS.
     *
     * Esta tabla documenta qué secretos usa la app (qué env_var es, de qué app,
     * dónde se usa, dónde rotarla, estado...). NUNCA almacena el VALOR del secreto:
     * los valores siguen viviendo únicamente en `.env`. No existe columna de valor.
     */
    public function up(): void
    {
        Schema::create('secret_registry', function (Blueprint $table) {
            $table->id();
            $table->string('env_var')->unique();                 // p.ej. GEMINI_TTS_API_KEY (nombre, NO valor)
            $table->string('label');
            $table->enum('app', ['backend', 'web', 'mobile', 'shared'])->default('backend');
            $table->string('provider')->nullable();              // p.ej. "Google Gemini"
            $table->text('description');
            $table->text('used_in')->nullable();                 // dónde/cómo se usa
            $table->string('rotation_url')->nullable();          // dónde rotarla
            $table->date('last_rotated_at')->nullable();
            $table->enum('status', ['active', 'needs_rotation', 'deprecated'])->default('active');
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['sort_order', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_registry');
    }
};
