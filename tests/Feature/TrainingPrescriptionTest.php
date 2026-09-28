<?php

namespace Tests\Feature;

use App\Models\Cycle;
use App\Services\CycleFactory;
use App\Services\MarkerState;
use App\Services\Periodization;
use App\Services\SlotAdvisor;
use App\Services\TrainingBackup;
use App\Services\TrainingPrescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrainingPrescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_is_validated_and_saved_per_period_without_changing_archived_sets(): void
    {
        $old = app(CycleFactory::class)->ensureCurrent();
        $set = $old->sessions()->firstOrFail()->slots()->firstOrFail()->sets()->firstOrFail();
        $set->update(['weight' => '15', 'reps' => '12', 'completed' => true]);
        app(CycleFactory::class)->preferences()->update(['current_week' => 7]);
        $this->postJson('/api/cycles', ['close_current_period' => true, 'training_goal' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('training_goal');
        $this->assertDatabaseCount('cycles', 1);

        $this->postJson('/api/cycles', ['close_current_period' => true, 'training_goal' => 'strength'])->assertOk()->assertJsonPath('trainingGoal', 'strength');
        $this->assertSame('strength', Cycle::where('is_current', true)->firstOrFail()->training_goal);
        $this->assertSame('hypertrophy', $old->fresh()->training_goal);
        $this->assertSame('12', $set->fresh()->reps);
        $this->assertSame('15', $set->fresh()->weight);
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $this->assertSame('hypertrophy', $state['cyclesHistory'][0]['trainingGoal']);
        $this->assertSame(4, $state['weeks'][1]['mon']['slot_a1']['targetReps']);
        $this->assertSame(12, $state['weeks'][1]['mon']['slot_a5']['targetReps']);
    }

    public function test_goals_change_ranges_and_progression_but_preserve_stored_plans(): void
    {
        $factory = app(CycleFactory::class);
        $cycle = $factory->createCycle(1, goal: 'combined');
        $session = $cycle->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $slot = $session->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
        $range = app(SlotAdvisor::class)->slotProgress($slot, 1);
        $this->assertSame(4, $range['minReps']);
        $this->assertSame(6, $range['maxReps']);
        $base = ['targetWeight' => 20, 'targetReps' => 4, 'minReps' => 4, 'maxReps' => 6, 'completedSets' => 6, 'requiredSets' => 6, 'achieved' => true, 'exertion' => 'good', 'isBodyweight' => false];
        $next = app(Periodization::class)->nextProgression($base, 2, 1, 'weekly');
        $this->assertSame(5, $next['reps']);
        $next = app(Periodization::class)->nextProgression(array_replace($base, ['targetReps' => 6]), 2, 1, 'weekly');
        $this->assertSame(21.0, $next['weight']);
        $this->assertSame(4, $next['reps']);
        $this->assertSame(['minReps' => 8, 'maxReps' => 12], app(TrainingPrescription::class)->range('combined', 'slot_b2', 8));
        $slot->update(['progression_plan' => ['weight' => 15, 'reps' => 10, 'minReps' => 8, 'maxReps' => 12]]);
        $this->assertSame(8, app(SlotAdvisor::class)->slotProgress($slot->fresh()->load('sets'), 1)['minReps']);
    }

    public function test_muscle_report_counts_logged_work_separately_from_supporting_sets_and_handles_deload(): void
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        $slot = $cycle->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail()->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
        $slot->sets[0]->update(['weight' => '15', 'reps' => '3', 'completed' => true]);
        $slot->sets[1]->update(['weight' => '15', 'reps' => '12', 'completed' => false]);
        $slot->sets[2]->update(['weight' => '0', 'reps' => '12', 'completed' => true]);
        $report = $this->getJson('/api/weeks/1/report')->assertOk()->json('muscleSets');
        $this->assertSame(1, $report['groups']['chest']['completed']);
        $this->assertSame(18, $report['groups']['chest']['planned']);
        $this->assertSame(0, $report['groups']['triceps']['completed']);
        $this->assertSame(1, $report['groups']['triceps']['indirect']);
        $this->assertGreaterThan(0, $report['groups']['triceps']['indirectPlanned']);
        $deload = $this->getJson('/api/weeks/7/report')->assertOk()->json('muscleSets');
        $this->assertTrue($deload['isDeload']);
        $this->assertSame(12, $deload['groups']['chest']['planned']);
        $slot->update(['selected_name' => 'Unknown custom lift']);
        $this->assertSame(['Unknown custom lift'], app(TrainingPrescription::class)->weekMuscles($cycle, 1)['unclassified']);
    }

    public function test_isolated_maximal_set_can_be_confirmed_next_week_but_repeated_misses_reduce(): void
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        foreach ([1, 2] as $week) {
            foreach (['mon', 'thu'] as $day) {
                $slot = $cycle->sessions()->where('week', $week)->where('day', $day)->firstOrFail()->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
                $slot->update(['selected_name' => 'Dumbbell Bench Press', 'progression_plan' => ['weight' => 15, 'reps' => 8, 'minReps' => 8, 'maxReps' => 12]]);
                foreach ($slot->sets as $index => $set) {
                    $set->update(['weight' => '15', 'reps' => '8', 'completed' => true, 'exertion' => $day === 'mon' && $index === 2 ? 'max' : 'good']);
                }
            }
        }
        $advisor = app(SlotAdvisor::class);
        $periodization = app(Periodization::class);
        $first = $advisor->exerciseProgress($cycle, 1, 'Dumbbell Bench Press');
        $this->assertSame('repeat', $periodization->nextProgression($first, 2, 2, 'weekly')['change']);
        $second = $advisor->exerciseProgress($cycle, 2, 'Dumbbell Bench Press');
        $this->assertTrue($second['confirmedEffort']);
        $this->assertSame(10, $periodization->nextProgression($second, 3, 2, 'weekly')['reps']);
        foreach ($cycle->sessions->where('week', 2) as $session) {
            foreach ($session->slots->where('slot_key', 'slot_a1') as $exercise) {
                foreach ($exercise->sets as $set) {
                    $set->update(['reps' => '6', 'exertion' => 'max']);
                }
            }
        }
        $cycle->unsetRelation('sessions');
        $missed = $advisor->exerciseProgress($cycle, 2, 'Dumbbell Bench Press');
        $this->assertTrue($missed['repeatedMaxMisses']);
        $this->assertSame(13.0, $periodization->nextProgression($missed, 3, 2, 'weekly')['weight']);
    }

    public function test_backup_keeps_current_and_archived_goals(): void
    {
        Storage::fake('local');
        $factory = app(CycleFactory::class);
        $old = $factory->createCycle(1, goal: 'strength');
        $current = $factory->createCycle(2, goal: 'combined');
        $current->sessions()->firstOrFail()->slots()->firstOrFail()->sets()->firstOrFail()->update(['weight' => '20', 'reps' => '4', 'completed' => true]);
        $backup = app(TrainingBackup::class);
        $backup->import($backup->payload());
        $state = app(MarkerState::class)->export()['appState'];
        $this->assertSame('combined', $state['trainingGoal']);
        $this->assertSame('strength', $state['cyclesHistory'][0]['trainingGoal']);
    }

    public function test_repeated_exercise_keeps_the_same_strength_range_across_days(): void
    {
        $factory = app(CycleFactory::class);
        $factory->ensureCurrent();
        $factory->preferences()->update(['current_week' => 7]);
        $schema = ['mon' => ['slot_a2' => ['selectedName' => 'Chest-Supported Dumbbell Row']], 'tue' => ['slot_b4' => ['selectedName' => 'Chest-Supported Dumbbell Row']]];
        $state = $this->postJson('/api/cycles', ['close_current_period' => true, 'training_goal' => 'strength', 'schema' => $schema])->assertOk()->json();
        $row = collect($state['weeks'][1]['tue']['slots'])->firstWhere('slotKey', 'slot_b4');
        $this->assertSame(4, $row['targetReps']);
        $this->assertSame(4, $row['advice']['minReps']);
        $this->assertSame(6, $row['advice']['maxReps']);
        $this->get('/beheer')->assertOk()->assertSee('Wanneer gebruik je welke pagina?');
        $this->get('/beheer/voorkeuren')->assertOk()->assertSee('Periodedoel:')->assertSee('Kracht');
        $this->get('/beheer/oefeningen')->assertOk()->assertSee('Indeling weeksets');
    }
}
