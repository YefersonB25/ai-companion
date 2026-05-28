<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('personal')->nullable();       // ciudad, ocupación, estado civil, hijos
            $table->json('health')->nullable();         // alergias, condiciones, medicamentos, tipo sangre
            $table->json('preferences')->nullable();    // dieta, comidas, hobbies, música, deportes
            $table->json('routines')->nullable();       // hora despertar/dormir, ejercicio, trabajo
            $table->json('relationships')->nullable();  // familia, amigos (array de {name, relation, notes})
            $table->json('goals')->nullable();          // metas corto/largo plazo, finanzas
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
