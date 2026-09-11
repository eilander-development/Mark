<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workout_slot_id', 'position', 'weight', 'reps', 'completed', 'is_pr', 'exertion'])]
class WorkoutSet extends Model
{
    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'is_pr' => 'boolean',
        ];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(WorkoutSlot::class, 'workout_slot_id');
    }
}
