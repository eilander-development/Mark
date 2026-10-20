<?php

namespace Tests\Feature;

use App\Models\WorkoutSet;
use App\Services\Catalog;
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
            ->assertJsonPath('message', 'Een nieuwe periode start na week 7. Je kunt het voorstel alvast bekijken.');
    }

    public function test_next_cycle_advice_is_unavailable_before_deload(): void
    {
        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.nextCycle.available', false)
            ->assertJsonPath('appState.nextCycle.currentWeek', 1);
    }

    public function test_next_cycle_advice_does_not_copy_barbell_weight_to_a_dumbbell_exercise(): void
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
            ->assertJsonPath('schema.mon.slot_a1.selectedName', 'Dumbbell Bench Press')
            ->assertJsonPath('schema.mon.slot_a1.fromName', 'Barbell Bench Press')
            ->assertJsonPath('schema.mon.slot_a1.rotated', true)
            ->assertJsonPath('schema.mon.slot_a1.targetReps', 8)
            ->assertJsonPath('schema.mon.slot_a1.weight', null);
    }

    public function test_next_period_uses_the_first_selectable_push_pull_template(): void
    {
        $this->getJson('/api/state')->assertOk();
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();

        $advice = $this->getJson('/api/cycles/next-advice')->assertOk()
            ->assertJsonPath('programTemplate.key', 'balanced')
            ->assertJsonPath('programTemplate.cycleNumber', 2)
            ->assertJsonPath('programTemplate.name', '1 · Gemengde basis')
            ->assertJsonPath('days.0.title', 'Maandag: Push A · aangepast')
            ->assertJsonPath('schema.mon.slot_a1.selectedName', 'Dumbbell Bench Press');

        $this->assertSame(['slot_a1', 'slot_a3', 'slot_b2', 'slot_a5', 'slot_b6'], array_column($advice->json('days.0.slots'), 'slotKey'));
        $this->assertSame(['slot_a2', 'slot_b4', 'slot_b5', 'slot_a6', 'slot_a4'], array_column($advice->json('days.1.slots'), 'slotKey'));
    }

    public function test_legacy_templates_remain_available_for_existing_periods(): void
    {
        $catalog = app(Catalog::class);
        $allSlots = $catalog->allSlots();
        $this->assertNull($catalog->programTemplateForCycle(1));
        $this->assertSame(['1', '2', '3', '4', '1'], array_map(
            fn (int $cycleNumber): string => (string) $catalog->programTemplateForCycle($cycleNumber)['key'],
            range(2, 6),
        ));

        foreach (range(2, 5) as $cycleNumber) {
            $template = $catalog->programTemplateForCycle($cycleNumber);
            $this->assertNotNull($template);

            foreach ($template['exercises'] as $slotKey => $exerciseName) {
                $this->assertContains($exerciseName, $allSlots[$slotKey]['alternatives'], "{$exerciseName} moet toegestaan zijn in {$slotKey}.");
            }
        }
    }

    public function test_starting_a_cycle_after_week_7_applies_advised_schema(): void
    {
        $this->getJson('/api/state')->assertOk();
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();

        $this->postJson('/api/cycles', [
            'close_current_period' => true,
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
            ->assertJsonPath('appState.weeks.1.tue.slot_b4.selectedName', 'Dumbbell One-Arm Row')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.targetReps', 8)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '26');
    }

    public function test_preparing_week_seven_does_not_allow_start_without_explicit_closure(): void
    {
        $this->getJson('/api/state')->assertOk();
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();
        $this->getJson('/api/cycles/next-advice')->assertOk()->assertJsonPath('available', true);
        $this->postJson('/api/cycles')->assertUnprocessable()
            ->assertJsonPath('errors.close_current_period.0', 'Sluit eerst de huidige periode bewust af. Open trainingen blijven onvoltooid in het archief.');
        $this->assertDatabaseCount('cycles', 1);
        $this->assertDatabaseHas('cycles', ['number' => 1, 'is_current' => true]);
    }

    public function test_explicit_closure_keeps_unfinished_sets_unfinished_in_the_archive(): void
    {
        $this->getJson('/api/state')->assertOk();
        $set = WorkoutSet::query()->firstOrFail();
        $set->update(['weight' => '20', 'reps' => '8', 'completed' => false]);
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();
        $this->postJson('/api/cycles', ['close_current_period' => true])->assertOk()
            ->assertJsonPath('currentWeek', 1)->assertJsonPath('totalWeeks', 6);
        $this->assertDatabaseHas('workout_sets', ['id' => $set->id, 'weight' => '20', 'completed' => false]);
        $this->assertDatabaseHas('cycles', ['number' => 1, 'is_current' => false]);
    }

    public function test_week_seven_report_does_not_prescribe_week_eight(): void
    {
        $report = $this->getJson('/api/weeks/7/report')->assertOk()->json();
        $this->assertTrue($report['isDeload']);
        $this->assertNull($report['exercises'][0]['next']);
        $this->assertTrue($report['exercises'][0]['nextPeriodRequired']);
    }
}
