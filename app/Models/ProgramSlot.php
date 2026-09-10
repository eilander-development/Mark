<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slot_key', 'default_name', 'target_reps', 'rest_type', 'rest_time', 'muscles', 'equipment', 'tips', 'alternatives'])]
class ProgramSlot extends Model
{
    protected function casts(): array
    {
        return [
            'tips' => 'array',
            'alternatives' => 'array',
            'target_reps' => 'integer',
            'rest_time' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        return [
            'defaultName' => $this->default_name,
            'targetReps' => (int) $this->target_reps,
            'restType' => $this->rest_type,
            'restTime' => (int) $this->rest_time,
            'muscles' => $this->muscles,
            'equipment' => $this->equipment,
            'tips' => $this->tips ?? [],
            'alternatives' => $this->alternatives ?? [],
        ];
    }
}
