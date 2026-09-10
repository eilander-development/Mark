<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['birth_year', 'body_weight_kg', 'experience_level', 'equipment'])]
class Profile extends Model
{
    protected function casts(): array
    {
        return [
            'equipment' => 'array',
        ];
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class);
    }
}
