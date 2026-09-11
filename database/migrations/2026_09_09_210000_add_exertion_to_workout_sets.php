<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_sets', function (Blueprint $table) {
            if (! Schema::hasColumn('workout_sets', 'exertion')) {
                $table->string('exertion', 16)->nullable()->after('is_pr');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workout_sets', function (Blueprint $table) {
            if (Schema::hasColumn('workout_sets', 'exertion')) {
                $table->dropColumn('exertion');
            }
        });
    }
};
