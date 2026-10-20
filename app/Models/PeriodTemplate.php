<?php

namespace App\Models;

use Database\Factories\PeriodTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'name', 'description', 'days', 'archived'])]
class PeriodTemplate extends Model
{
    /** @use HasFactory<PeriodTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['days' => 'array', 'archived' => 'boolean'];
    }
}
