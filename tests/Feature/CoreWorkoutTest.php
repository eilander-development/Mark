<?php

namespace Tests\Feature;

use App\Services\CycleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoreWorkoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_core_sets_share_report_totals_and_progress_without_adding_weight(): void
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        $session = $cycle->sessions()->where('week', 5)->where('day', 'mon')->firstOrFail();
        $slot = $session->slots()->with('sets')->firstOrFail();
        $slot->update(['selected_name' => 'Dumbbell Bench Press']);
        $slot->sets[0]->update(['weight' => '20', 'reps' => '10', 'completed' => true]);
        $workout = $this->workout();

        $this->patchJson('/api/sessions/'.$session->id, ['core_workout' => $workout])->assertOk()
            ->assertJsonPath('session.core_workout', $workout);
        $this->patchJson('/api/sessions/'.$session->id, ['actual_duration' => 1800])->assertOk();
        $this->assertSame($workout, $session->fresh()->core_workout);
        $this->getJson('/api/marker-state')->assertOk()->assertJsonPath('appState.weeks.5.mon.coreWorkout', $workout)
            ->assertJsonCount(4, 'appState.trainingRules.coreWorkout.catalog');
        $this->getJson('/api/state')->assertOk()->assertJsonPath('weeks.5.mon.coreWorkout', $workout)
            ->assertJsonPath('weeks.5.tue.coreWorkout', []);
        $this->getJson('/api/weeks/5/report')->assertOk()->assertJsonPath('volume', 400)
            ->assertJsonPath('completedSets', 3)->assertJsonPath('totalSets', 77)->assertJsonPath('coreCompletedSets', 2)
            ->assertJsonPath('muscleSets.groups.core.completed', 2)->assertJsonPath('muscleSets.groups.core.planned', 5);
        $report = $this->getJson('/api/weeks/5/report')->assertOk()->json();
        $crunch = collect($report['exercises'])->firstWhere('name', 'Reverse crunch');
        $this->assertNotNull($crunch);
        $this->assertSame(1, $crunch['sets']);
        $this->assertSame(1, $crunch['progress']['completedSets']);
        $this->assertSame(3, $crunch['progress']['requiredSets']);
        $this->assertTrue($crunch['progress']['isBodyweight']);
        $this->assertSame(0, $crunch['volume']);
    }

    public function test_report_prescribes_a_third_core_set_only_after_controlled_results_at_the_upper_limit(): void
    {
        $session = app(CycleFactory::class)->ensureCurrent()->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $workout = [
            ['exercise' => 'reverse_crunch', 'targetReps' => 15, 'sets' => array_fill(0, 2, ['reps' => 15, 'completed' => true, 'exertion' => 'good'])],
            ['exercise' => 'dead_bug', 'targetReps' => 8, 'sets' => array_fill(0, 2, ['reps' => 8, 'completed' => true, 'exertion' => 'unknown'])],
        ];
        $this->patchJson('/api/sessions/'.$session->id, ['core_workout' => $workout])->assertOk();
        $exercises = collect($this->getJson('/api/weeks/1/report')->assertOk()->json('exercises'));
        $crunch = $exercises->firstWhere('name', 'Reverse crunch');
        $deadBug = $exercises->firstWhere('name', 'Dead bug');
        $this->assertSame(3, $crunch['next']['requiredSets']);
        $this->assertSame(10, $crunch['next']['reps']);
        $this->assertSame(2, $deadBug['next']['requiredSets']);
        $this->assertSame(8, $deadBug['next']['reps']);
        $this->assertSame(6, $session->slots()->count());
        $this->assertSame($workout, $session->fresh()->core_workout);
    }

    #[DataProvider('invalidWorkouts')]
    public function test_invalid_core_workouts_return_422_without_changing_saved_sets(mixed $value, string $field): void
    {
        $session = app(CycleFactory::class)->ensureCurrent()->sessions()->firstOrFail();
        $workout = $this->workout();
        $session->update(['core_workout' => $workout]);
        $this->patchJson('/api/sessions/'.$session->id, ['core_workout' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($workout, $session->fresh()->core_workout);
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidWorkouts(): array
    {
        $exercise = ['exercise' => 'crunch', 'sets' => [['reps' => 12, 'completed' => true], ['reps' => 12, 'completed' => false]]];
        $invalid = [
            'null' => [null, 'core_workout'],
            'not a list' => [['first' => $exercise, 'second' => $exercise], 'core_workout'],
            'too many exercises' => [[$exercise, $exercise, $exercise], 'core_workout'],
            'unknown exercise' => [[array_replace($exercise, ['exercise' => '<script>']), $exercise], 'core_workout.0.exercise'],
            'invalid target' => [[array_replace($exercise, ['targetReps' => 0]), $exercise], 'core_workout.0.targetReps'],
            'fractional target' => [[array_replace($exercise, ['targetReps' => 8.5]), $exercise], 'core_workout.0.targetReps'],
            'too many sets' => [[array_replace($exercise, ['sets' => array_fill(0, 4, $exercise['sets'][0])]), $exercise], 'core_workout.0.sets'],
            'too few sets' => [[array_replace($exercise, ['sets' => [$exercise['sets'][0]]]), $exercise], 'core_workout.0.sets'],
        ];
        foreach (['zero' => ['reps', 0], 'fraction' => ['reps', 1.5], 'too high' => ['reps', 51], 'completion' => ['completed', 'yes'], 'weight' => ['weight', 20], 'effort' => ['exertion', 'invalid']] as $name => [$key, $value]) {
            $changed = $exercise;
            $changed['sets'][0][$key] = $value;
            $invalid[$name] = [[$changed, $exercise], 'core_workout.0.sets.0'.($key === 'weight' ? '' : '.'.$key)];
        }

        return $invalid;
    }

    public function test_core_choices_and_sets_survive_backup_restore_and_old_backups_remain_compatible(): void
    {
        Storage::fake('local');
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['coreWorkout'] = $this->workout();
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = ['weight' => '20', 'reps' => '10', 'completed' => true];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.coreWorkout', $this->workout());
        $backup = $this->getJson('/api/backup')->assertOk()->json();
        $this->post('/api/backup', ['confirm' => '1', 'backup' => UploadedFile::fake()->createWithContent('backup.json', json_encode($backup))], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/marker-state')->assertOk()->assertJsonPath('appState.weeks.1.mon.coreWorkout', $this->workout());

        unset($state['weeks'][1]['mon']['coreWorkout']);
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.coreWorkout', []);
    }

    public function test_invalid_core_import_rolls_back_existing_training(): void
    {
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['coreWorkout'] = $this->workout();
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();
        $state['weeks'][1]['mon']['coreWorkout'] = 'invalid';
        $this->putJson('/api/marker-state', ['appState' => $state])->assertUnprocessable();
        $this->getJson('/api/marker-state')->assertOk()->assertJsonPath('appState.weeks.1.mon.coreWorkout', $this->workout());
    }

    public function test_skipped_day_rejects_core_updates_and_clearing_day_resets_core_sets(): void
    {
        $session = app(CycleFactory::class)->ensureCurrent()->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $session->update(['skipped' => true, 'core_workout' => $this->workout()]);
        $this->patchJson('/api/sessions/'.$session->id, ['core_workout' => []])->assertUnprocessable();
        $this->assertSame($this->workout(), $session->fresh()->core_workout);
        $this->postJson('/api/days/clear', ['week' => 1, 'day' => 'mon'])->assertOk()->assertJsonPath('weeks.1.mon.coreWorkout', []);
        $this->assertNull($session->fresh()->core_workout);
    }

    public function test_new_cycle_preserves_archived_core_sets_and_starts_with_empty_core_sessions(): void
    {
        $session = app(CycleFactory::class)->ensureCurrent()->sessions()->where('week', 7)->where('day', 'fri')->firstOrFail();
        $session->update(['core_workout' => $this->workout()]);
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();
        $this->postJson('/api/cycles', ['close_current_period' => true])->assertOk();
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.coreWorkout', [])
            ->assertJsonPath('appState.cyclesHistory.0.snapshot.weeksSnapshot.7.fri.coreWorkout', $this->workout());
    }

    public function test_prescribed_targets_and_effort_survive_save_reload_and_backup_restore(): void
    {
        Storage::fake('local');
        $session = app(CycleFactory::class)->ensureCurrent()->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $session->slots()->firstOrFail()->sets()->firstOrFail()->update(['weight' => '20', 'reps' => '10', 'completed' => true]);
        $workout = $this->workout();
        $workout[0] = ['exercise' => 'dead_bug', 'targetReps' => 10, 'sets' => $workout[0]['sets']];
        $workout[0]['sets'][0]['exertion'] = 'max';
        $workout[0]['sets'][1]['exertion'] = 'unknown';
        $workout[1] = ['exercise' => 'reverse_crunch', 'targetReps' => 12, 'sets' => $workout[1]['sets']];
        $workout[1]['sets'][0]['exertion'] = 'good';
        $this->patchJson('/api/sessions/'.$session->id, ['core_workout' => $workout])->assertOk()
            ->assertJsonPath('session.core_workout', $workout);
        $this->assertSame($workout, $session->fresh()->core_workout);
        $backup = $this->getJson('/api/backup')->assertOk()->json();
        $this->post('/api/backup', ['confirm' => '1', 'backup' => UploadedFile::fake()->createWithContent('backup.json', json_encode($backup))], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/marker-state')->assertOk()->assertJsonPath('appState.weeks.1.mon.coreWorkout', $workout);
    }

    /** @return list<array{exercise: string, sets: list<array{reps: int, completed: bool}>}> */
    private function workout(): array
    {
        return [
            ['exercise' => 'dead_bug', 'sets' => [['reps' => 8, 'completed' => true], ['reps' => 8, 'completed' => false]]],
            ['exercise' => 'reverse_crunch', 'sets' => [['reps' => 10, 'completed' => true], ['reps' => 10, 'completed' => false], ['reps' => 9, 'completed' => false]]],
        ];
    }
}
