<?php

namespace Tests\Feature;

use App\Models\Cycle;
use App\Models\ProgramVariant;
use App\Services\CycleFactory;
use App\Services\ProgramLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpandPushPullVariantsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_27_145527_expand_push_pull_variants_to_six_exercises.php');
    }

    private function fiveExerciseCycle(int $totalWeeks = 6): Cycle
    {
        $factory = app(CycleFactory::class);
        $old = $factory->ensureCurrent();
        $program = app(ProgramLibrary::class)->build($old, 'balanced');
        $program['totalWeeks'] = $totalWeeks;
        foreach ($program['days'] as &$day) {
            foreach (['slot_b2', 'slot_b5', 'slot_a4'] as $key) {
                if (isset($day['exercises'][$key])) {
                    $day['exercises'][$key]['sets'] = 2;
                }
            }
            unset($day['exercises'][$day['family'] === 'push' ? 'slot_b3' : 'slot_b1']);
        }
        unset($day);
        foreach (ProgramVariant::query()->get() as $variant) {
            $items = $variant->exercises;
            unset($items[$variant->family === 'push' ? 'slot_b3' : 'slot_b1']);
            $variant->update(['exercises' => $items]);
        }

        return $factory->createCycle(2, program: $program);
    }

    public function test_upgrade_adds_sixth_exercises_to_variants_and_current_sessions_without_changing_existing_results(): void
    {
        $cycle = $this->fiveExerciseCycle();
        $session = $cycle->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $set = $session->slots()->firstOrFail()->sets()->firstOrFail();
        $set->update(['weight' => '25', 'reps' => '11', 'completed' => true, 'exertion' => 'good']);
        $beforeSets = DB::table('workout_sets')->orderBy('id')->get()->keyBy('id')->toArray();
        $beforeSlots = DB::table('workout_slots')->orderBy('id')->get()->keyBy('id')->toArray();
        $archive = DB::table('cycles')->where('is_current', false)->first();

        $this->migration()->up();
        $this->migration()->up();

        foreach ($beforeSets as $id => $before) {
            $this->assertEquals($before, DB::table('workout_sets')->find($id));
        }
        foreach ($beforeSlots as $id => $before) {
            $this->assertEquals($before, DB::table('workout_slots')->find($id));
        }
        $this->assertEquals($archive, DB::table('cycles')->where('id', $archive->id)->first());
        foreach (ProgramVariant::query()->get() as $variant) {
            $this->assertCount(6, $variant->exercises);
            $this->assertCount(6, array_unique(array_column($variant->exercises, 'selectedName')));
        }
        foreach ($cycle->sessions()->with('slots.sets')->get() as $updatedSession) {
            $this->assertCount(6, $updatedSession->slots);
            $key = in_array($updatedSession->day, ['mon', 'thu'], true) ? 'slot_b3' : 'slot_b1';
            $extra = $updatedSession->slots->firstWhere('slot_key', $key);
            $this->assertCount(3, $extra->sets);
            $this->assertFalse($extra->sets->contains('completed', true));
            $this->assertSame('', $extra->sets->first()->weight);
        }
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.currentCycle', 2)
            ->assertJsonPath('appState.weeks.1.mon.slot_b3.selectedName', 'Dumbbell Chest Flyes')
            ->assertJsonPath('appState.weeks.1.thu.slot_b3.selectedName', 'Incline Dumbbell Press')
            ->assertJsonPath('appState.weeks.1.tue.slot_b1.selectedName', 'Dumbbell Pullover')
            ->assertJsonPath('appState.weeks.1.fri.slot_b1.requiredSets', 3);
        $this->assertSame(['slot_a1', 'slot_a3', 'slot_b3', 'slot_b2', 'slot_a5', 'slot_b6'],
            array_keys($cycle->fresh()->program_definition['days']['mon']['exercises']));
    }

    public function test_occupied_position_rolls_back_without_overwriting_a_saved_exercise(): void
    {
        $cycle = $this->fiveExerciseCycle();
        $session = $cycle->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $slot = $session->slots()->create(['slot_key' => 'slot_b3', 'selected_name' => 'Dumbbell Floor Press', 'target_reps' => 10, 'note' => 'behouden']);
        $before = $cycle->program_definition;
        try {
            $this->migration()->up();
            $this->fail('Een bezette positie moet de wijziging blokkeren.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('al bezet', $exception->getMessage());
        }
        $this->assertSame($before, $cycle->fresh()->program_definition);
        $this->assertSame('Dumbbell Floor Press', $slot->fresh()->selected_name);
        $this->assertCount(5, ProgramVariant::query()->where('key', 'push_a')->firstOrFail()->exercises);
    }

    public function test_three_work_sets_preserve_performances_and_keep_two_sets_in_the_recovery_week(): void
    {
        $cycle = $this->fiveExerciseCycle(7);
        $slot = $cycle->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail()
            ->slots()->where('slot_key', 'slot_b2')->firstOrFail();
        $slot->sets()->firstOrFail()->update(['weight' => '15', 'reps' => '10', 'completed' => true, 'exertion' => 'good']);
        $before = DB::table('workout_sets')->orderBy('id')->get()->keyBy('id')->toArray();
        $archive = DB::table('cycles')->where('is_current', false)->first();
        $this->migration()->up();
        $migration = require database_path('migrations/2026_10_27_145924_use_three_work_sets_in_push_pull_programs.php');

        $migration->up();
        $migration->up();

        foreach ($before as $id => $set) {
            $this->assertEquals($set, DB::table('workout_sets')->find($id));
        }
        $this->assertEquals($archive, DB::table('cycles')->where('id', $archive->id)->first());
        foreach ($cycle->sessions()->with('slots.sets')->get() as $session) {
            $this->assertCount(6, $session->slots);
            foreach ($session->slots as $exercise) {
                $this->assertSame(3, $exercise->prescription['sets']);
                $this->assertCount((int) $session->week === 7 ? 2 : 3, $exercise->sets);
            }
        }
        foreach (ProgramVariant::query()->get() as $variant) {
            $this->assertSame([3], array_values(array_unique(array_column($variant->exercises, 'sets'))));
        }
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_b2.requiredSets', 3)
            ->assertJsonPath('appState.weeks.7.mon.slot_b2.requiredSets', 2);
    }
}
