<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('platform');              // android | ios
            $table->string('version');               // semver: 1.2.0
            $table->unsignedInteger('version_code'); // monotonically increasing int
            $table->json('changelog');               // ["Fixed login bug", "Added memory view"]
            $table->string('download_url')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->unique(['platform', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
