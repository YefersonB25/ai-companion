<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');          // person, project, habit, preference, event, skill
            $table->string('label');         // display name in mind map
            $table->text('content');         // detailed description
            $table->json('attributes')->nullable(); // key-value extra data
            $table->string('qdrant_id')->nullable(); // vector DB reference
            $table->float('importance')->default(0.5); // 0-1, affects retrieval priority
            $table->timestamp('last_accessed_at')->nullable();
            $table->integer('access_count')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('memory_nodes')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'importance']);
            $table->index('qdrant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_nodes');
    }
};
