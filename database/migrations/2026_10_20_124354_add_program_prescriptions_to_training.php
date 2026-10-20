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
        Schema::table('cycles', function (Blueprint $table) {
            $table->json('program_definition')->nullable();
        });
        Schema::table('workout_slots', function (Blueprint $table) {
            $table->json('prescription')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workout_slots', function (Blueprint $table) {
            $table->dropColumn('prescription');
        });
        Schema::table('cycles', function (Blueprint $table) {
            $table->dropColumn('program_definition');
        });
    }
};
