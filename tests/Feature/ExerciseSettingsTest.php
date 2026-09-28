<?php

namespace Tests\Feature;

use App\Models\Preference;
use App\Services\CycleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_exercise_steps_are_saved_normalized_and_preserved_in_state_round_trip(): void
    {
        $this->patchJson('/api/preferences', ['exercise_increments' => [' Dumbbell Bench Press ' => 0.5]])
            ->assertOk()->assertJsonPath('preferences.exercise_increments.dumbbell bench press', 0.5);
        $this->assertSame(['dumbbell bench press' => 0.5], Preference::firstOrFail()->exercise_increments);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('exerciseIncrements.dumbbell bench press', 0.5);
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $this->assertSame(0.5, $state['exerciseIncrements']['dumbbell bench press']);
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();
        $this->assertSame(0.5, Preference::firstOrFail()->incrementFor('DUMBBELL BENCH PRESS'));
        $this->assertSame(2.0, Preference::firstOrFail()->incrementFor('Other exercise'));
    }

    public function test_invalid_steps_are_rejected_without_overwriting_preferences(): void
    {
        $prefs = app(CycleFactory::class)->preferences();
        $prefs->update(['exercise_increments' => ['bench' => 1]]);
        foreach ([0, 11, 'invalid'] as $value) {
            $this->patchJson('/api/preferences', ['exercise_increments' => ['bench' => $value]])
                ->assertUnprocessable()->assertJsonValidationErrors('exercise_increments.bench');
        }
        $this->assertSame(['bench' => 1], $prefs->fresh()->exercise_increments);
    }

    public function test_week_report_advice_and_next_week_use_the_exercise_step_without_changing_logged_work(): void
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        foreach (['mon', 'thu'] as $day) {
            $slot = $cycle->sessions()->where('week', 1)->where('day', $day)->firstOrFail()->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
            $slot->update(['selected_name' => 'Dumbbell Bench Press', 'progression_plan' => ['weight' => 6, 'reps' => 12, 'minReps' => 8, 'maxReps' => 12], 'target_reps' => 12]);
            foreach ($slot->sets as $set) {
                $set->update(['weight' => '6', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
            }
        }
        $this->patchJson('/api/preferences', ['exercise_increments' => ['dumbbell bench press' => 0.5]])->assertOk();
        $report = $this->getJson('/api/weeks/1/report')->assertOk()->json();
        $exercise = collect($report['exercises'])->firstWhere('name', 'Dumbbell Bench Press');
        $this->assertSame(6.5, $exercise['next']['weight']);
        $state = $this->getJson('/api/state')->assertOk()->json();
        $next = collect($state['weeks'][2]['mon']['slots'])->firstWhere('slotKey', 'slot_a1');
        $this->assertSame(6.5, $next['advice']['advisedWeight']);
        $this->assertSame(['weight' => 6, 'reps' => 12, 'minReps' => 8, 'maxReps' => 12], $slot->fresh()->progression_plan);
        $this->assertSame('6', $slot->sets[0]->fresh()->weight);
        $this->assertSame('12', $slot->sets[0]->fresh()->reps);
    }

    public function test_clearing_overrides_restores_the_default(): void
    {
        $prefs = app(CycleFactory::class)->preferences();
        $prefs->update(['exercise_increments' => ['bench' => 0.5]]);
        $this->patchJson('/api/preferences', ['exercise_increments' => []])->assertOk();
        $this->assertSame([], $prefs->fresh()->exercise_increments);
        $this->assertSame(2.0, $prefs->fresh()->incrementFor('bench'));
    }
}
