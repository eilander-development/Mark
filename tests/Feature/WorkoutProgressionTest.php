<?php

namespace Tests\Feature;

use App\Models\ProgramSlot;
use App\Models\WorkoutSlot;
use App\Services\CycleFactory;
use App\Services\TrainingBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkoutProgressionTest extends TestCase
{
    use RefreshDatabase;

    private function bench(int $week, string $day, string $key = 'slot_a1'): WorkoutSlot
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        $slot = $cycle->sessions()->where('week', $week)->where('day', $day)->firstOrFail()
            ->slots()->where('slot_key', $key)->with('sets')->firstOrFail();
        $slot->update(['selected_name' => 'Dumbbell Bench Press', 'target_reps' => 12,
            'progression_plan' => ['weight' => 15, 'reps' => 12]]);

        return $slot;
    }

    public function test_day_and_week_report_identify_one_missing_rep_and_do_not_raise_weight(): void
    {
        $monday = $this->bench(1, 'mon');
        $thursday = $this->bench(1, 'thu');
        foreach ([$monday, $thursday] as $slot) {
            foreach ($slot->sets as $set) {
                $set->update(['weight' => '15', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
            }
        }
        $thursday->sets->last()->update(['reps' => '11']);

        $state = $this->getJson('/api/state')->assertOk()->json();
        $day = collect($state['weeks'][1]['thu']['slots'])->firstWhere('id', $thursday->id);
        $this->assertSame(97, $day['progress']['percent']);
        $this->assertSame(2, $day['progress']['achievedSets']);
        $this->assertSame(1, $day['progress']['remainingReps']);
        $this->assertFalse($day['isTargetAchieved']);
        $report = $this->getJson('/api/weeks/1/report')->assertOk()->json();
        $exercise = collect($report['exercises'])->firstWhere('name', 'Dumbbell Bench Press');
        $this->assertSame(5, $exercise['progress']['achievedSets']);
        $this->assertSame(6, $exercise['progress']['requiredSets']);
        $this->assertSame(1, $exercise['progress']['remainingReps']);
        $this->assertSame(15.0, (float) $exercise['next']['weight']);
        $this->assertSame('Herhalen', $exercise['next']['status']);
    }

    public function test_plan_and_own_input_survive_reload_and_marker_state_round_trip(): void
    {
        $slot = $this->bench(1, 'mon');
        $set = $slot->sets->first();
        $this->patchJson('/api/slots/'.$slot->id, ['progressionPlan' => ['weight' => 15, 'reps' => 12], 'targetReps' => 12])->assertOk();
        $this->patchJson('/api/sets/'.$set->id, ['weight' => 17, 'reps' => 10, 'completed' => false,
            'inputFields' => ['weight' => true, 'reps' => true]])->assertOk();
        $payload = $this->getJson('/api/marker-state')->assertOk()->json();
        $this->putJson('/api/marker-state', $payload)->assertOk();

        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.progressionPlan.weight', 15)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.progressionPlan.reps', 12)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.inputFields.weight', true)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.reps', '10')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', false);
        $this->assertDatabaseHas('workout_sets', ['id' => $set->id, 'weight' => '17', 'reps' => '10', 'completed' => false]);
    }

    public function test_next_week_advice_follows_the_exercise_when_moved_and_does_not_overwrite_input(): void
    {
        $monday = $this->bench(1, 'mon');
        $thursday = $this->bench(1, 'thu');
        foreach ([$monday, $thursday] as $slot) {
            foreach ($slot->sets as $set) {
                $set->update(['weight' => '15', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
            }
        }
        $future = $this->bench(2, 'tue', 'slot_b2');
        $future->update(['progression_plan' => null]);
        $this->patchJson('/api/preferences', ['overload_frequency' => 'weekly', 'overload_increment' => 2])->assertOk();
        $slots = $this->getJson('/api/state')->assertOk()->json('weeks.2.tue.slots');
        $advice = collect($slots)->firstWhere('id', $future->id)['advice'];
        $this->assertSame(17.0, (float) $advice['advisedWeight']);
        $future->sets->first()->update(['weight' => '16', 'reps' => '10', 'input_fields' => ['weight' => true, 'reps' => true]]);

        $this->postJson('/api/weeks/advance', ['week' => 1])->assertOk();

        $this->assertDatabaseHas('workout_sets', ['id' => $future->sets->first()->id, 'weight' => '16', 'reps' => '10', 'completed' => false]);
        $this->assertNull($future->fresh()->progression_plan);
    }

    public function test_lighter_sets_and_incomplete_sessions_cannot_earn_an_increase(): void
    {
        $slot = $this->bench(1, 'mon');
        foreach ($slot->sets as $index => $set) {
            $set->update(['weight' => $index === 1 ? '10' : '15', 'reps' => '12', 'completed' => $index !== 2]);
        }

        $slots = $this->getJson('/api/state')->assertOk()->json('weeks.1.mon.slots');
        $progress = collect($slots)->firstWhere('id', $slot->id)['progress'];
        $this->assertSame(1, $progress['achievedSets']);
        $this->assertSame(33, $progress['percent']);
        $this->assertFalse($progress['details'][1]['weightMet']);
        $this->assertSame(2, $progress['completedSets']);
    }

    public function test_deload_report_counts_only_two_working_sets(): void
    {
        $slot = $this->bench(7, 'mon');
        foreach ($slot->sets as $set) {
            $set->update(['weight' => '15', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
        }

        $this->getJson('/api/weeks/7/report')->assertOk()
            ->assertJsonPath('totalSets', 48)
            ->assertJsonPath('completedSets', 2)
            ->assertJsonPath('volume', 720);
    }

    public function test_switching_an_exercise_does_not_relabel_completed_history(): void
    {
        $past = $this->bench(1, 'mon');
        $past->sets->first()->update(['weight' => '15', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
        $future = $this->bench(2, 'mon');

        $this->patchJson('/api/slots/'.$future->id, ['selectedName' => 'Barbell Bench Press', 'context' => 'setup'])->assertOk();

        $this->assertSame('Dumbbell Bench Press', $past->fresh()->selected_name);
        $this->assertNull($future->fresh()->progression_plan);
        $this->patchJson('/api/slots/'.$past->id, ['selectedName' => 'Barbell Bench Press', 'context' => 'setup'])
            ->assertUnprocessable()->assertJsonValidationErrors('selectedName');
        $this->assertSame('Dumbbell Bench Press', $past->fresh()->selected_name);
    }

    public function test_week_report_and_advance_build_reps_then_weight_and_keep_the_range(): void
    {
        $this->patchJson('/api/preferences', ['overload_frequency' => 'weekly', 'overload_increment' => 2])->assertOk();
        for ($week = 1; $week <= 4; $week++) {
            foreach (['mon', 'thu'] as $day) {
                $slot = $this->bench($week, $day);
                $slot->update(['target_reps' => 8, 'progression_plan' => null]);
                if ($week === 1) {
                    $this->patchJson('/api/slots/'.$slot->id, ['progressionPlan' => ['weight' => 15, 'reps' => 8, 'minReps' => 8, 'maxReps' => 12]])->assertOk();
                }
            }
        }
        foreach ([1 => [8, 15, 10, 'reps'], 2 => [10, 15, 12, 'reps'], 3 => [12, 17, 8, 'weight']] as $week => [$performedReps, $nextWeight, $nextReps, $change]) {
            $cycle = app(CycleFactory::class)->ensureCurrent();
            foreach ($cycle->sessions()->where('week', $week)->with('slots.sets')->get() as $session) {
                foreach ($session->slots->where('selected_name', 'Dumbbell Bench Press') as $slot) {
                    foreach ($slot->sets as $set) {
                        $this->patchJson('/api/sets/'.$set->id, ['weight' => 15, 'reps' => $performedReps, 'completed' => true, 'exertion' => 'good'])->assertOk();
                    }
                }
            }

            $report = $this->getJson('/api/weeks/'.$week.'/report')->assertOk()->json('exercises');
            $next = collect($report)->firstWhere('name', 'Dumbbell Bench Press')['next'];
            $this->assertSame((float) $nextWeight, (float) $next['weight']);
            $this->assertSame($nextReps, $next['reps']);
            $this->assertSame($change, $next['change']);
            $this->assertFalse($next['provisional']);
            $this->postJson('/api/weeks/advance', ['week' => $week])->assertOk();
            $plan = $this->getJson('/api/marker-state')->assertOk()->json('appState.weeks.'.($week + 1).'.mon.slot_a1.progressionPlan');
            $this->assertSame((float) $nextWeight, (float) $plan['weight']);
            $this->assertSame($nextReps, $plan['reps']);
            $this->assertSame(8, $plan['minReps']);
            $this->assertSame(12, $plan['maxReps']);
        }
    }

    public function test_rep_range_validation_and_marker_round_trip(): void
    {
        $slot = $this->bench(1, 'mon');
        $this->patchJson('/api/slots/'.$slot->id, ['progressionPlan' => ['weight' => 6, 'reps' => 14, 'minReps' => 12, 'maxReps' => 15]])->assertOk();
        $payload = $this->getJson('/api/marker-state')->assertOk()->json();
        $this->putJson('/api/marker-state', $payload)->assertOk();
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.progressionPlan.minReps', 12)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.progressionPlan.maxReps', 15);

        $this->patchJson('/api/slots/'.$slot->id, ['progressionPlan' => ['weight' => 6, 'reps' => 14, 'minReps' => 15, 'maxReps' => 8]])
            ->assertUnprocessable()->assertJsonValidationErrors('progressionPlan.maxReps');
        $this->patchJson('/api/slots/'.$slot->id, ['progressionPlan' => ['weight' => 6, 'reps' => 14, 'minReps' => 12]])
            ->assertUnprocessable()->assertJsonValidationErrors('progressionPlan.maxReps');
        $this->assertSame(15, $slot->fresh()->progression_plan['maxReps']);
    }

    public function test_rest_measurement_count_survives_session_update_and_marker_round_trip(): void
    {
        $slot = $this->bench(1, 'mon');
        $session = $slot->session;
        $this->patchJson('/api/sessions/'.$session->id, [
            'actual_duration' => 120, 'actual_avg_rest' => 75, 'actual_rest_count' => 4,
        ])->assertOk()->assertJsonPath('session.actual_rest_count', 4);
        $this->assertDatabaseHas('workout_sessions', ['id' => $session->id, 'actual_rest_count' => 4, 'actual_avg_rest' => 75]);
        $payload = $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.actualRestCount', 4)->json();
        $this->putJson('/api/marker-state', $payload)->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.actualRestCount', 4);
        $this->patchJson('/api/sessions/'.$session->id, ['actual_rest_count' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('actual_rest_count');
        $this->patchJson('/api/sessions/'.$session->id, ['actual_rest_count' => 0])->assertOk();
        $this->assertDatabaseHas('workout_sessions', ['id' => $session->id, 'actual_rest_count' => 0, 'actual_avg_rest' => null]);
    }

    public function test_archived_sets_are_exported_and_records_survive_backup_restore(): void
    {
        Storage::fake('local');
        $slot = $this->bench(1, 'mon');
        $slot->sets->first()->update(['weight' => '20', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();
        $this->postJson('/api/cycles', ['close_current_period' => true])->assertOk();
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.cyclesHistory.0.snapshot.weeksSnapshot.1.mon.slot_a1.sets.0.weight', '20');
        $current = $this->bench(1, 'mon');
        $current->sets->first()->update(['weight' => '12', 'reps' => '8', 'completed' => true, 'exertion' => 'good']);
        $payload = app(TrainingBackup::class)->payload();
        app(TrainingBackup::class)->import($payload);
        $state = $this->getJson('/api/state')->assertOk()->json();
        $bench = collect($state['weeks'][1]['mon']['slots'])->firstWhere('selectedName', 'Dumbbell Bench Press');
        $this->assertSame(20.0, (float) $bench['record']['maxWeight']);
        $this->assertSame(28.0, (float) $bench['record']['max1RM']);
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.cyclesHistory.0.snapshot.weeksSnapshot.1.mon.slot_a1.sets.0.reps', '12');
    }

    public function test_new_cycle_advice_does_not_use_draft_or_invalid_peak_weights(): void
    {
        $slot = $this->bench(6, 'mon');
        ProgramSlot::query()->where('slot_key', 'slot_a1')->update(['alternatives' => ['Dumbbell Bench Press']]);
        $slot->sets[0]->update(['weight' => '50', 'reps' => '12', 'completed' => false]);
        $slot->sets[1]->update(['weight' => '40', 'reps' => '0', 'completed' => true, 'exertion' => 'good']);
        $slot->sets[2]->update(['weight' => '12', 'reps' => '10', 'completed' => true, 'exertion' => 'good']);
        $this->getJson('/api/cycles/next-advice')->assertOk()
            ->assertJsonPath('schema.mon.slot_a1.weight', 12);
    }

    public function test_unknown_effort_is_preserved_and_does_not_authorize_progression(): void
    {
        $monday = $this->bench(1, 'mon');
        $thursday = $this->bench(1, 'thu');
        foreach ([$monday, $thursday] as $slot) {
            foreach ($slot->sets as $set) {
                $set->update(['weight' => '15', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
            }
        }
        $this->patchJson('/api/sets/'.$monday->sets->first()->id, ['exertion' => 'unknown'])->assertOk();
        $report = $this->getJson('/api/weeks/1/report')->assertOk()->json();
        $bench = collect($report['exercises'])->firstWhere('name', 'Dumbbell Bench Press');
        $this->assertSame(1, $bench['progress']['unknownSets']);
        $this->assertSame('Inspanning niet beoordeeld', $bench['next']['status']);
        $this->assertSame('repeat', $bench['next']['change']);
        $this->assertDatabaseHas('workout_sets', ['id' => $monday->sets->first()->id, 'exertion' => 'unknown', 'completed' => true]);
        $payload = $this->getJson('/api/marker-state')->assertOk()->json();
        $this->putJson('/api/marker-state', $payload)->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.exertion', 'unknown');
    }

    public function test_skipping_resolves_pending_work_preserves_sets_and_can_be_undone(): void
    {
        $monday = $this->bench(1, 'mon');
        $thursday = $this->bench(1, 'thu');
        foreach ($monday->sets as $set) {
            $set->update(['weight' => '15', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
        }
        $thursday->sets->first()->update(['weight' => '15', 'reps' => '12', 'completed' => true, 'exertion' => 'good']);
        $this->patchJson('/api/sessions/'.$thursday->workout_session_id, ['skipped' => true])->assertOk();
        $report = $this->getJson('/api/weeks/1/report')->assertOk()->json();
        $bench = collect($report['exercises'])->firstWhere('name', 'Dumbbell Bench Press');
        $this->assertSame(4, $bench['progress']['completedSets']);
        $this->assertSame(2, $bench['progress']['skippedSets']);
        $this->assertFalse($bench['next']['provisional']);
        $this->assertSame('repeat', $bench['next']['change']);
        $payload = $this->getJson('/api/marker-state')->assertOk()->json();
        $this->putJson('/api/marker-state', $payload)->assertOk()->assertJsonPath('appState.weeks.1.thu.skipped', true);
        $this->patchJson('/api/sets/'.$thursday->sets->last()->id, ['weight' => 15, 'reps' => 12, 'completed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('set');
        $this->assertDatabaseHas('workout_sets', ['id' => $thursday->sets->first()->id, 'completed' => true]);
        $this->patchJson('/api/sessions/'.$thursday->workout_session_id, ['skipped' => false])->assertOk();
        $this->assertDatabaseHas('workout_sessions', ['id' => $thursday->workout_session_id, 'skipped' => false]);
        $this->patchJson('/api/sets/'.$thursday->sets->last()->id, ['weight' => 15, 'reps' => 12, 'completed' => true])->assertOk();
    }
}
