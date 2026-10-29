<?php

namespace Tests\Feature;

use App\Models\ProgramVariant;
use App\Services\Catalog;
use App\Services\CycleFactory;
use App\Services\ExerciseVolume;
use App\Services\ProgramLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeatedDumbbellPressTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_replaces_all_four_old_variant_presses_without_changing_other_exercises(): void
    {
        app(ProgramLibrary::class)->ensureDefaults();
        $cases = [
            'push_b' => ['Barbell Overhead Press (OHP)', 'Arnold Press'],
            'push_d' => ['Seated Dumbbell Shoulder Press', 'Seated Neutral-Grip Dumbbell Shoulder Press'],
            'push_e' => ['Standing Dumbbell Shoulder Press', 'Seated Neutral-Grip Dumbbell Shoulder Press'],
            'push_f' => ['Seated Barbell Press', 'Seated Dumbbell Shoulder Press'],
        ];
        $otherExercises = [];
        foreach ($cases as $key => [$oldName, $newName]) {
            $variant = ProgramVariant::query()->where('key', $key)->firstOrFail();
            $items = $variant->exercises;
            $items['slot_b2']['selectedName'] = $oldName;
            $items['slot_b2']['sets'] = 2;
            $variant->update(['exercises' => $items]);
            unset($items['slot_b2']);
            $otherExercises[$key] = $items;
        }
        $pullBefore = ProgramVariant::query()->where('family', 'pull')->get()->toArray();
        $migration = require database_path('migrations/2026_10_29_122029_use_seated_dumbbell_shoulder_presses.php');

        $migration->up();

        foreach ($cases as $key => [$oldName, $newName]) {
            $items = ProgramVariant::query()->where('key', $key)->firstOrFail()->exercises;
            $this->assertSame($newName, $items['slot_b2']['selectedName']);
            $this->assertSame(2, $items['slot_b2']['sets']);
            unset($items['slot_b2']);
            $this->assertSame($otherExercises[$key], $items);
        }
        $this->assertSame($pullBefore, ProgramVariant::query()->where('family', 'pull')->get()->toArray());
    }

    public function test_library_uses_three_seated_dumbbell_variations_and_counts_both_dumbbells(): void
    {
        $library = app(ProgramLibrary::class);
        $library->ensureDefaults();
        $expected = [
            'push_a' => 'Seated Dumbbell Shoulder Press',
            'push_b' => 'Arnold Press',
            'push_c' => 'Arnold Press',
            'push_d' => 'Seated Neutral-Grip Dumbbell Shoulder Press',
            'push_e' => 'Seated Neutral-Grip Dumbbell Shoulder Press',
            'push_f' => 'Seated Dumbbell Shoulder Press',
        ];

        foreach ($expected as $key => $name) {
            $variant = ProgramVariant::query()->where('key', $key)->firstOrFail();
            $this->assertSame($name, $variant->exercises['slot_b2']['selectedName']);
            $this->assertSame(['dumbbells', 'bench', 'adjustable_bench'], $library->requirements($name));
        }
        $this->assertSame('Dumbbell Overhead Tricep Extension', ProgramVariant::query()->where('key', 'push_c')->firstOrFail()->exercises['slot_b6']['selectedName']);
        $this->assertSame(240.0, app(ExerciseVolume::class)->forSet('Seated Neutral-Grip Dumbbell Shoulder Press', 12, 10));
        $this->assertStringContainsString('Zittende', $library->prescription('slot_b2', 'Arnold Press')['focusCue']);
    }

    public function test_upgrade_changes_unperformed_presses_from_week_one_and_preserves_results_and_customizations(): void
    {
        $factory = app(CycleFactory::class);
        $archive = $factory->ensureCurrent();
        $library = app(ProgramLibrary::class);
        $program = $library->build($archive, 'balanced');
        $program['days']['thu']['exercises']['slot_b2']['selectedName'] = 'Barbell Overhead Press (OHP)';
        $program['days']['thu']['exercises']['slot_b2']['weight'] = 40;
        $program['days']['thu']['exercises']['slot_b2']['requirements'] = ['barbell', 'rack'];
        $program['days']['thu']['exercises']['slot_b2']['weightLabel'] = 'Kg totaal';
        $cycle = $factory->createCycle(2, program: $program);
        $factory->preferences()->update(['current_week' => 2]);
        $thursday = $cycle->sessions()->where('week', 1)->where('day', 'thu')->firstOrFail();
        $slot = $thursday->slots()->where('slot_key', 'slot_b2')->firstOrFail();
        $slot->update(['progression_plan' => ['weight' => 40, 'reps' => 8]]);
        $slot->sets()->update(['weight' => '40']);
        $logged = $cycle->sessions()->where('week', 2)->where('day', 'thu')->firstOrFail()->slots()->where('slot_key', 'slot_b2')->firstOrFail();
        $logged->sets()->firstOrFail()->update(['weight' => '40', 'reps' => '10', 'completed' => true]);
        $partial = $cycle->sessions()->where('week', 3)->where('day', 'thu')->firstOrFail()->slots()->where('slot_key', 'slot_b2')->firstOrFail();
        $partial->sets()->firstOrFail()->update(['weight' => '30', 'reps' => '5']);
        $custom = $cycle->sessions()->where('week', 4)->where('day', 'thu')->firstOrFail()->slots()->where('slot_key', 'slot_b2')->firstOrFail();
        $custom->update(['selected_name' => 'Seated Dumbbell Shoulder Press']);
        $variant = ProgramVariant::query()->where('key', 'push_b')->firstOrFail();
        $items = $variant->exercises;
        $items['slot_b2']['selectedName'] = 'Barbell Overhead Press (OHP)';
        $variant->update(['exercises' => $items]);
        $beforeLogged = $logged->toArray();
        $beforePartial = $partial->toArray();
        $beforeSets = DB::table('workout_sets')->whereIn('workout_slot_id', [$logged->id, $partial->id])->orderBy('id')->get()->toJson();
        $beforeArchive = $archive->fresh()->toArray();
        $otherSlots = DB::table('workout_slots')->where('slot_key', '!=', 'slot_b2')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_29_122029_use_seated_dumbbell_shoulder_presses.php');

        $migration->up();
        $migration->up();

        $this->assertSame('Arnold Press', $slot->fresh()->selected_name);
        $this->assertNull($slot->fresh()->progression_plan);
        $this->assertSame(['', '', ''], $slot->sets()->pluck('weight')->all());
        $this->assertArrayNotHasKey('weight', $slot->fresh()->prescription);
        $this->assertSame('Kg per dumbbell', $slot->fresh()->prescription['weightLabel']);
        $this->assertSame($beforeLogged, $logged->fresh()->toArray());
        $this->assertSame($beforePartial, $partial->fresh()->toArray());
        $this->assertSame($beforeSets, DB::table('workout_sets')->whereIn('workout_slot_id', [$logged->id, $partial->id])->orderBy('id')->get()->toJson());
        $this->assertSame($beforeArchive, $archive->fresh()->toArray());
        $this->assertSame($otherSlots, DB::table('workout_slots')->where('slot_key', '!=', 'slot_b2')->orderBy('id')->get()->toJson());
        $this->assertSame('Seated Dumbbell Shoulder Press', $custom->fresh()->selected_name);
        $this->assertSame('Arnold Press', $variant->fresh()->exercises['slot_b2']['selectedName']);
        $this->assertSame('Arnold Press', $cycle->fresh()->program_definition['days']['thu']['exercises']['slot_b2']['selectedName']);
        $this->getJson('/api/marker-state')->assertJsonPath('appState.weeks.1.thu.slot_b2.selectedName', 'Arnold Press');
        $this->assertContains('Seated Neutral-Grip Dumbbell Shoulder Press', app(Catalog::class)->slot('slot_b2')['alternatives']);
    }
}
