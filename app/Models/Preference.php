<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sound_enabled',
    'routine_locked',
    'show_live_video_panel',
    'overload_increment',
    'exercise_increments',
    'overload_frequency',
    'current_week',
    'current_day',
    'preferred_rest_times',
    'custom_exercise_videos',
])]
class Preference extends Model
{
    public function incrementFor(string $exerciseName): float
    {
        $increments = $this->exercise_increments ?? [];
        $increment = (float) ($increments[mb_strtolower(trim($exerciseName))] ?? 0);

        return $increment >= 0.5 && $increment <= 10 ? $increment : (float) $this->overload_increment;
    }

    /** @param array<string, mixed> $increments */
    public static function normalizeIncrements(array $increments): array
    {
        $normalized = [];
        foreach ($increments as $name => $increment) {
            if (is_string($name) && trim($name) !== '' && is_numeric($increment) && $increment >= 0.5 && $increment <= 10) {
                $normalized[mb_strtolower(trim($name))] = (float) $increment;
            }
        }

        return $normalized;
    }

    protected function casts(): array
    {
        return [
            'sound_enabled' => 'boolean',
            'routine_locked' => 'boolean',
            'show_live_video_panel' => 'boolean',
            'overload_increment' => 'float',
            'exercise_increments' => 'array',
            'preferred_rest_times' => 'array',
            'custom_exercise_videos' => 'array',
        ];
    }
}
