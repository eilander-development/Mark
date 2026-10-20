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
        Schema::create('program_variants', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('family', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('exercises');
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_variants');
    }
};
