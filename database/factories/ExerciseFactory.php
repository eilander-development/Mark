<?php

namespace Database\Factories;

use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'youtube_id' => 'vthMCtgVtFw',
            'title' => 'ATHLEAN-X form guide',
            'channel' => 'ATHLEAN-X™',
            'cues' => ['Plant je voeten.', 'Houd je core strak.', 'Beweeg gecontroleerd.'],
        ];
    }
}
