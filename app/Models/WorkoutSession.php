<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cycle_id', 'week', 'day', 'actual_duration', 'actual_avg_rest'])]
class WorkoutSession extends Model
{
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(WorkoutSlot::class);
    }
}
