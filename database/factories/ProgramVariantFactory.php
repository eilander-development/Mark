<?php

namespace Database\Factories;

use App\Models\ProgramVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgramVariant>
 */
class ProgramVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'push_'.fake()->unique()->slug(2),
            'family' => 'push',
            'name' => 'Push '.fake()->word(),
            'description' => 'Testvariant',
            'archived' => false,
            'exercises' => collect(config('push_pull.families.push.slots'))->mapWithKeys(fn (string $key): array => [$key => [
                'selectedName' => config('push_pull.roles.'.$key.'.exercises')[0],
                'sets' => config('push_pull.roles.'.$key.'.sets'),
                'minReps' => config('push_pull.roles.'.$key.'.minReps'),
                'maxReps' => config('push_pull.roles.'.$key.'.maxReps'),
                'rest' => config('push_pull.roles.'.$key.'.rest'),
            ]])->all(),
        ];
    }
}
