<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('youtube_id', 16)->nullable();
            $table->string('title')->nullable();
            $table->string('channel')->default('ATHLEAN-X™');
            $table->json('cues')->nullable();
            $table->timestamps();
        });

        Schema::create('program_slots', function (Blueprint $table) {
            $table->id();
            $table->string('slot_key', 16)->unique();
            $table->string('default_name');
            $table->unsignedTinyInteger('target_reps')->default(8);
            $table->string('rest_type')->nullable();
            $table->unsignedSmallInteger('rest_time')->default(90);
            $table->string('muscles')->nullable();
            $table->string('equipment')->nullable();
            $table->json('tips')->nullable();
            $table->json('alternatives')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_slots');
        Schema::dropIfExists('exercises');
    }
};
