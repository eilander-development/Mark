<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sound_enabled',
    'routine_locked',
    'show_live_video_panel',
    'overload_increment',
    'overload_frequency',
    'current_week',
    'current_day',
    'preferred_rest_times',
    'custom_exercise_videos',
])]
class Preference extends Model
{
    protected function casts(): array
    {
        return [
            'sound_enabled' => 'boolean',
            'routine_locked' => 'boolean',
            'show_live_video_panel' => 'boolean',
            'overload_increment' => 'float',
            'preferred_rest_times' => 'array',
            'custom_exercise_videos' => 'array',
        ];
    }
}
