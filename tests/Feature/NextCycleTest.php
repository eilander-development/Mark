<?php

namespace Tests\Feature;

use App\Models\WorkoutSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NextCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_cycle_returns_422_before_week_7(): void
    {
        $this->getJson('/api/state')->assertOk();

        $this->postJson('/api/cycles')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Een nieuwe periode start ná week 7 (deload). Je zit nu in week 1.');
    }

    public function test_next_cycle_advice_is_unavailable_before_deload(): void
    {
        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.nextCycle.available', false)
            ->assertJsonPath('appState.nextCycle.currentWeek', 1);
    }

    public function test_next_cycle_advice_prescribes_rotated_schema_and_reps_after_week_7(): void
    {
        $this->getJson('/api/state')->assertOk();
        $set = WorkoutSet::query()->firstOrFail();
        $this->patchJson('/api/sets/'.$set->id, [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'context' => 'live',
        ])->assertOk();
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();

        $this->getJson('/api/cycles/next-advice')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('schema.mon.slot_a1.selectedName', 'Incline Barbell Press')
            ->assertJsonPath('schema.mon.slot_a1.fromName', 'Barbell Bench Press')
            ->assertJsonPath('schema.mon.slot_a1.rotated', true)
            ->assertJsonPath('schema.mon.slot_a1.targetReps', 8)
            ->assertJsonPath('schema.mon.slot_a1.weight', null);
    }

    public function test_starting_a_cycle_after_week_7_applies_advised_schema(): void
    {
        $this->getJson('/api/state')->assertOk();
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();

        $this->postJson('/api/cycles', [
            'schema' => [
                'mon' => [
                    'slot_a1' => [
                        'selectedName' => 'Dumbbell Bench Press',
                        'weight' => 26,
                        'targetReps' => 8,
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('currentCycle', 2);

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.currentWeek', 1)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.selectedName', 'Dumbbell Bench Press')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.targetReps', 8)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '26');
    }
}
