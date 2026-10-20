<?php

namespace App\Models;

use Database\Factories\ProgramVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'family', 'name', 'description', 'exercises', 'archived'])]
class ProgramVariant extends Model
{
    /** @use HasFactory<ProgramVariantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['exercises' => 'array', 'archived' => 'boolean'];
    }
}
