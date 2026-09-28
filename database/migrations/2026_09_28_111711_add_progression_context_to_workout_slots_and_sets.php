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
        Schema::table('workout_slots', function (Blueprint $table) {
            $table->json('progression_plan')->nullable();
        });
        Schema::table('workout_sets', function (Blueprint $table) {
            $table->json('input_fields')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workout_sets', function (Blueprint $table) {
            $table->dropColumn('input_fields');
        });
        Schema::table('workout_slots', function (Blueprint $table) {
            $table->dropColumn('progression_plan');
        });
    }
};
