<?php

namespace Database\Factories;

use App\Models\PeriodTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodTemplate>
 */
class PeriodTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => 'Testperiode',
            'description' => 'Push/Pull',
            'days' => config('push_pull.templates.balanced.days'),
            'archived' => false,
        ];
    }
}
