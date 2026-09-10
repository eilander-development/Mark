<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('birth_year')->default(1984);
            $table->unsignedSmallInteger('body_weight_kg')->default(82);
            $table->string('experience_level')->default('intermediate');
            $table->json('equipment')->nullable();
            $table->timestamps();
        });

        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->boolean('sound_enabled')->default(true);
            $table->boolean('routine_locked')->default(true);
            $table->boolean('show_live_video_panel')->default(false);
            $table->decimal('overload_increment', 4, 1)->default(2.0);
            $table->string('overload_frequency')->default('weekly');
            $table->unsignedTinyInteger('current_week')->default(1);
            $table->string('current_day', 8)->default('mon');
            $table->json('preferred_rest_times')->nullable();
            $table->json('custom_exercise_videos')->nullable();
            $table->timestamps();
        });

        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->default(1);
            $table->boolean('is_current')->default(true);
            $table->unsignedTinyInteger('total_weeks')->default(7);
            $table->date('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles')->cascadeOnDelete();
            $table->unsignedTinyInteger('week');
            $table->string('day', 8);
            $table->unsignedInteger('actual_duration')->nullable();
            $table->unsignedInteger('actual_avg_rest')->nullable();
            $table->timestamps();
            $table->unique(['cycle_id', 'week', 'day']);
        });

        Schema::create('workout_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_session_id')->constrained('workout_sessions')->cascadeOnDelete();
            $table->string('slot_key', 16);
            $table->string('selected_name');
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('target_reps')->nullable();
            $table->timestamps();
            $table->unique(['workout_session_id', 'slot_key']);
        });

        Schema::create('workout_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_slot_id')->constrained('workout_slots')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('weight')->default('');
            $table->string('reps')->default('');
            $table->boolean('completed')->default(false);
            $table->boolean('is_pr')->default(false);
            $table->timestamps();
            $table->unique(['workout_slot_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_sets');
        Schema::dropIfExists('workout_slots');
        Schema::dropIfExists('workout_sessions');
        Schema::dropIfExists('cycles');
        Schema::dropIfExists('preferences');
        Schema::dropIfExists('profiles');
    }
};
