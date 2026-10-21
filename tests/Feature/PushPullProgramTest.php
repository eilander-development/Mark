<?php

namespace Tests\Feature;

use App\Models\Cycle;
use App\Models\ProgramVariant;
use App\Services\CycleFactory;
use App\Services\ExerciseHistory;
use App\Services\Periodization;
use App\Services\ProgramLibrary;
use App\Services\TrainingBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PushPullProgramTest extends TestCase
{
    use RefreshDatabase;

    private function ready(): Cycle
    {
        $factory = app(CycleFactory::class);
        $cycle = $factory->ensureCurrent();
        $factory->preferences()->update(['current_week' => 7]);
        $factory->profile()->update(['equipment' => ['barbell' => true, 'dumbbells' => true, 'bench' => true, 'adjustable_bench' => true, 'rack' => true, 'pullup_bar' => true]]);

        return $cycle;
    }

    private function start(string $template = 'balanced', string $goal = 'hypertrophy'): Cycle
    {
        $cycle = $this->ready();
        $this->postJson('/api/cycles', ['close_current_period' => true, 'cycleId' => $cycle->id, 'templateKey' => $template, 'training_goal' => $goal])->assertOk();

        return Cycle::query()->where('is_current', true)->firstOrFail();
    }

    public function test_twelve_variants_and_four_selectable_templates_have_valid_exercises(): void
    {
        $this->ready();
        $library = app(ProgramLibrary::class)->export();
        $this->assertCount(12, $library['variants']);
        $this->assertCount(4, $library['templates']);
        foreach ($library['variants'] as $variant) {
            $this->assertCount(5, $variant['exercises']);
            foreach ($variant['exercises'] as $key => $item) {
                $this->assertSame($variant['family'], config('push_pull.roles.'.$key.'.family'));
                $this->assertContains($item['selectedName'], config('push_pull.roles.'.$key.'.exercises'));
                $this->assertGreaterThanOrEqual(1, $item['sets']);
            }
        }
        $this->getJson('/api/cycles/next-advice')->assertOk()
            ->assertJsonPath('programTemplate.type', 'push_pull')
            ->assertJsonPath('programTemplate.days.mon.variantName', 'Push A')
            ->assertJsonPath('programTemplate.days.tue.variantName', 'Pull A')
            ->assertJsonPath('programTemplate.days.thu.variantName', 'Push B')
            ->assertJsonPath('weekMuscles.chest.direct', 12)
            ->assertJsonPath('weekMuscles.back.direct', 12);
    }

    public function test_selected_template_is_frozen_and_existing_period_is_untouched(): void
    {
        $old = $this->ready();
        $oldSet = $old->sessions()->first()->slots()->first()->sets()->first();
        $oldSet->update(['weight' => '25', 'reps' => '9', 'completed' => true]);
        $this->postJson('/api/cycles', ['close_current_period' => true, 'templateKey' => 'dumbbells'])->assertOk();
        $new = Cycle::query()->where('is_current', true)->firstOrFail();
        $this->assertSame('dumbbells', $new->program_definition['key']);
        ProgramVariant::query()->where('key', 'push_c')->update(['name' => 'Andere naam']);
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.splits.mon.variantName', 'Push C')
            ->assertJsonPath('appState.weeks.1.mon.slot_b2.requiredSets', 2)
            ->assertJsonPath('appState.weeks.1.mon.slot_b2.prescription.sets', 2);
        $this->assertSame('25', $oldSet->fresh()->weight);
        $this->assertNull($old->fresh()->program_definition);
    }

    public function test_day_variant_selection_checks_the_split_and_creates_distinct_days(): void
    {
        $old = $this->ready();
        $this->postJson('/api/cycles', ['close_current_period' => true, 'dayVariants' => ['mon' => 'pull_a']])
            ->assertUnprocessable()->assertJsonValidationErrors('dayVariants.mon');
        $this->assertSame(1, Cycle::query()->count());
        $this->postJson('/api/cycles', ['close_current_period' => true, 'dayVariants' => ['mon' => 'push_e', 'thu' => 'push_d']])
            ->assertOk()->assertJsonPath('splits.mon.variantName', 'Push E')->assertJsonPath('splits.thu.variantName', 'Push D');
        $this->assertFalse($old->fresh()->is_current);
    }

    public function test_fixed_variant_blocks_loose_changes_without_modifying_training(): void
    {
        $cycle = $this->start();
        $monday = $cycle->sessions()->where('week', 1)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->first();
        $thursday = $cycle->sessions()->where('week', 1)->where('day', 'thu')->first()->slots()->where('slot_key', 'slot_a1')->first();
        $futureMonday = $cycle->sessions()->where('week', 2)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->first();
        $this->patchJson('/api/slots/'.$monday->id, ['context' => 'setup', 'selectedName' => 'Dumbbell Floor Press'])
            ->assertUnprocessable()->assertJsonValidationErrors('selectedName');
        $this->assertSame('Barbell Bench Press', $monday->fresh()->selected_name);
        $this->assertSame('Barbell Bench Press', $futureMonday->fresh()->selected_name);
        $this->assertSame('Dumbbell Bench Press', $thursday->fresh()->selected_name);
        $monday->sets()->first()->update(['weight' => '15', 'reps' => '10', 'completed' => true]);
        $this->patchJson('/api/slots/'.$monday->id, ['context' => 'live', 'selectedName' => 'Dumbbell Bench Press'])->assertUnprocessable();
        $this->assertSame('15', $monday->sets()->first()->weight);
    }

    public function test_work_sets_and_deload_counts_follow_each_exercise(): void
    {
        $this->start();
        $this->getJson('/api/weeks/1/report')->assertOk()->assertJsonPath('totalSets', 54)
            ->assertJsonPath('muscleSets.groups.chest.planned', 12)
            ->assertJsonPath('muscleSets.groups.back.planned', 12)
            ->assertJsonPath('muscleSets.groups.biceps.planned', 6);
        app(CycleFactory::class)->preferences()->update(['current_week' => 6]);
        $this->postJson('/api/weeks/advance', ['week' => 6, 'recovery_week' => true])->assertOk();
        $this->getJson('/api/weeks/7/report')->assertOk()->assertJsonPath('totalSets', 40);
    }

    public function test_goal_changes_ranges_without_inventing_a_new_start_weight(): void
    {
        $this->ready();
        $this->getJson('/api/cycles/next-advice?training_goal=strength')->assertOk()
            ->assertJsonPath('schema.mon.slot_a1.minReps', 4)->assertJsonPath('schema.mon.slot_a1.maxReps', 6)
            ->assertJsonPath('schema.mon.slot_a5.minReps', 12)->assertJsonPath('schema.mon.slot_a1.weight', null);
        $this->getJson('/api/cycles/next-advice?training_goal=combined')->assertOk()
            ->assertJsonPath('schema.mon.slot_a1.minReps', 6)->assertJsonPath('schema.tue.slot_a2.maxReps', 8);
    }

    public function test_normal_history_is_used_instead_of_deload_or_other_exercises(): void
    {
        $cycle = $this->ready();
        foreach ([6 => [20, 10], 7 => [12, 10]] as $week => [$weight, $reps]) {
            $slot = $cycle->sessions()->where('week', $week)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->first();
            $slot->update(['selected_name' => 'Barbell Bench Press', 'target_reps' => 10]);
            $slot->sets()->first()->update(['weight' => (string) $weight, 'reps' => (string) $reps, 'completed' => true, 'exertion' => 'good']);
        }
        $this->getJson('/api/cycles/next-advice')->assertOk()
            ->assertJsonPath('schema.mon.slot_a1.weight', 20)
            ->assertJsonPath('schema.mon.slot_a1.lastPerformed.isDeload', true)
            ->assertJsonPath('schema.mon.slot_a3.weight', null);
        $this->getJson('/api/cycles/next-advice?training_goal=strength')->assertOk()
            ->assertJsonPath('schema.mon.slot_a1.weight', null)
            ->assertJsonPath('schema.mon.slot_a1.weightSource', 'ander_bereik');
    }

    public function test_profile_equipment_cannot_change_fixed_variants_in_preview_or_start(): void
    {
        $cycle = $this->ready();
        app(CycleFactory::class)->profile()->update(['equipment' => []]);
        $advice = $this->getJson('/api/cycles/next-advice')->assertOk()->json();
        $this->assertSame('Barbell Bench Press', $advice['schema']['mon']['slot_a1']['selectedName']);
        $this->assertSame('Chest-Supported Dumbbell Row', $advice['schema']['fri']['slot_a2']['selectedName']);
        $this->assertSame('Vrijdag: Pull B', $advice['days'][3]['title']);
        foreach ($advice['proposals'] as $key => $proposal) {
            if ($key === 'repeat') {
                continue;
            }
            $this->assertArrayNotHasKey('unavailableReason', $proposal);
            foreach ($proposal['programTemplate']['days'] as $day => $definition) {
                $variant = ProgramVariant::query()->where('key', $definition['variantKey'])->firstOrFail();
                $this->assertSame(array_column($variant->exercises, 'selectedName'), array_column($proposal['schema'][$day], 'selectedName'));
                foreach ($proposal['schema'][$day] as $item) {
                    $this->assertArrayNotHasKey('materialAlternative', $item);
                    $this->assertArrayNotHasKey('missingEquipment', $item);
                }
            }
        }
        $this->postJson('/api/cycles/preview', ['templateKey' => 'balanced', 'training_goal' => 'hypertrophy'])->assertOk()
            ->assertJsonPath('schema.fri.slot_a2.selectedName', 'Chest-Supported Dumbbell Row');
        $this->postJson('/api/cycles', ['cycleId' => $cycle->id, 'close_current_period' => true, 'templateKey' => 'balanced'])
            ->assertOk();
        $new = Cycle::query()->where('is_current', true)->firstOrFail();
        $this->assertSame('Chest-Supported Dumbbell Row', $new->sessions()->where('week', 1)->where('day', 'fri')->first()->slots()->where('slot_key', 'slot_a2')->value('selected_name'));
        $this->assertFalse($cycle->fresh()->is_current);
    }

    public function test_every_default_variant_has_complementary_rows_and_a_content_based_overview(): void
    {
        $this->ready();
        $library = app(ProgramLibrary::class);
        $fixedEquipment = ['barbell', 'dumbbells', 'bench', 'adjustable_bench', 'rack', 'bodyweight'];
        foreach ($library->export()['variants'] as $variant) {
            $this->assertSame(implode(' + ', array_slice(array_column($variant['exercises'], 'selectedName'), 0, 2)), $variant['subtitle']);
            $this->assertSame(array_sum(array_column($variant['exercises'], 'sets')), $variant['workSets']);
            $this->assertGreaterThan($variant['estimatedMinutes']['min'], $variant['estimatedMinutes']['max']);
            foreach ($variant['exercises'] as $key => $item) {
                $this->assertSame([], array_values(array_diff($library->requirements($item['selectedName']), $fixedEquipment)));
            }
            if ($variant['family'] === 'pull') {
                $cues = array_map(fn (string $key): string => $library->prescription($key, $variant['exercises'][$key]['selectedName'])['focusCue'], ['slot_a2', 'slot_b4']);
                $this->assertTrue(str_contains(implode(' ', $cues), 'Lataccent'));
                $this->assertTrue(str_contains(implode(' ', $cues), 'Bovenrugaccent'));
            }
        }
        $this->getJson('/api/cycles/next-advice')->assertOk()
            ->assertJsonPath('days.0.workSets', 14)->assertJsonPath('days.1.workSets', 13);
    }

    public function test_corrupt_library_duplicates_are_reported_without_replacing_them(): void
    {
        $cycle = $this->ready();
        app(ProgramLibrary::class)->ensureDefaults();
        $variant = ProgramVariant::query()->where('key', 'pull_a')->firstOrFail();
        $items = $variant->exercises;
        $items['slot_b4']['selectedName'] = 'Barbell Row';
        $variant->update(['exercises' => $items]);
        $this->postJson('/api/cycles/preview', ['templateKey' => 'balanced', 'training_goal' => 'hypertrophy'])
            ->assertUnprocessable()->assertJsonValidationErrors('schema.tue');
        $this->postJson('/api/cycles', ['close_current_period' => true, 'templateKey' => 'balanced'])
            ->assertUnprocessable()->assertJsonValidationErrors('schema.tue');
        $this->assertTrue($cycle->fresh()->is_current);
        $this->assertDatabaseCount('cycles', 1);
    }

    public function test_preview_and_start_reject_duplicate_exercise_choices(): void
    {
        $cycle = $this->ready();
        $request = ['close_current_period' => true, 'templateKey' => 'balanced', 'training_goal' => 'hypertrophy', 'schema' => [
            'tue' => ['slot_b4' => ['selectedName' => 'Barbell Row']],
        ]];
        $this->postJson('/api/cycles/preview', $request)->assertUnprocessable()
            ->assertJsonValidationErrors('schema.tue.slot_b4.selectedName');
        $this->postJson('/api/cycles', $request)->assertUnprocessable()
            ->assertJsonValidationErrors('schema.tue.slot_b4.selectedName');
        $this->assertTrue($cycle->fresh()->is_current);
        $this->assertDatabaseCount('cycles', 1);
        $current = $this->start();
        $slot = $current->sessions()->where('week', 1)->where('day', 'tue')->first()->slots()->where('slot_key', 'slot_b4')->first();
        $this->patchJson('/api/slots/'.$slot->id, ['context' => 'setup', 'selectedName' => 'Barbell Row'])
            ->assertUnprocessable()->assertJsonValidationErrors('selectedName');
        $this->assertSame('Dumbbell One-Arm Row', $slot->fresh()->selected_name);
    }

    public function test_preview_and_start_only_accept_exercises_from_the_selected_variant(): void
    {
        $old = $this->ready();
        $request = ['templateKey' => 'balanced', 'training_goal' => 'hypertrophy', 'close_current_period' => true,
            'schema' => ['mon' => ['slot_a1' => ['selectedName' => 'Dumbbell Floor Press']]]];
        $this->postJson('/api/cycles/preview', $request)->assertUnprocessable()
            ->assertJsonValidationErrors('schema.mon.slot_a1.selectedName');
        $this->postJson('/api/cycles', $request)->assertUnprocessable()
            ->assertJsonValidationErrors('schema.mon.slot_a1.selectedName');
        $this->assertTrue($old->fresh()->is_current);
        $this->assertDatabaseCount('cycles', 1);
    }

    public function test_library_changes_define_exercises_and_start_weight_remains_editable(): void
    {
        $this->ready();
        app(ProgramLibrary::class)->ensureDefaults();
        $variant = ProgramVariant::query()->where('key', 'push_a')->firstOrFail();
        $exercises = $variant->exercises;
        $exercises['slot_a1']['selectedName'] = 'Dumbbell Floor Press';
        $variant->update(['exercises' => $exercises]);
        $this->postJson('/api/cycles/preview', ['templateKey' => 'balanced', 'training_goal' => 'hypertrophy',
            'schema' => ['mon' => ['slot_a1' => ['weight' => 17.5, 'weightEdited' => true]]]])
            ->assertOk()->assertJsonPath('schema.mon.slot_a1.selectedName', 'Dumbbell Floor Press')
            ->assertJsonPath('schema.mon.slot_a1.weight', 17.5);
        $this->assertDatabaseCount('cycles', 1);
    }

    public function test_stale_or_duplicate_start_does_not_create_an_extra_period(): void
    {
        $old = $this->ready();
        $request = ['close_current_period' => true, 'cycleId' => $old->id];
        $this->postJson('/api/cycles', $request)->assertOk();
        $this->postJson('/api/cycles', $request)->assertUnprocessable()->assertJsonValidationErrors('cycleId');
        $this->assertSame(2, Cycle::query()->count());
    }

    public function test_changed_variant_version_rejects_start_without_closing_current(): void
    {
        $old = $this->ready();
        $advice = $this->getJson('/api/cycles/next-advice')->assertOk()->json();
        $versions = collect($advice['programTemplate']['days'])->map(fn (array $day): string => $day['version'])->all();
        ProgramVariant::query()->where('key', 'push_a')->update(['name' => 'Push A gewijzigd']);
        $this->postJson('/api/cycles', ['close_current_period' => true, 'programVersions' => $versions])
            ->assertUnprocessable()->assertJsonValidationErrors('programVersions');
        $this->assertTrue($old->fresh()->is_current);
    }

    public function test_six_training_weeks_can_be_followed_by_recovery_or_a_new_period(): void
    {
        $cycle = $this->start();
        $this->assertSame(6, $cycle->total_weeks);
        app(CycleFactory::class)->preferences()->update(['current_week' => 5]);
        $this->postJson('/api/weeks/advance', ['week' => 6, 'recovery_week' => true])->assertUnprocessable();
        app(CycleFactory::class)->preferences()->update(['current_week' => 6]);
        $this->getJson('/api/cycles/next-advice')->assertOk()->assertJsonPath('available', true);
        $this->postJson('/api/weeks/advance', ['week' => 6, 'recovery_week' => true])->assertOk()
            ->assertJsonPath('currentWeek', 7)->assertJsonPath('totalWeeks', 7);
        $this->assertSame(7, $cycle->fresh()->total_weeks);
        $this->postJson('/api/cycles', ['cycleId' => $cycle->id, 'close_current_period' => true, 'templateKey' => 'balanced'])->assertOk()
            ->assertJsonPath('totalWeeks', 6);
    }

    public function test_new_period_can_start_after_six_weeks_without_creating_a_deload(): void
    {
        $cycle = $this->start();
        app(CycleFactory::class)->preferences()->update(['current_week' => 6]);
        $this->postJson('/api/cycles', ['cycleId' => $cycle->id, 'close_current_period' => true, 'templateKey' => 'balanced'])->assertOk();
        $this->assertSame(6, $cycle->fresh()->total_weeks);
        $this->assertSame(0, $cycle->sessions()->where('week', 7)->count());
    }

    public function test_preview_preserves_manual_weight_without_writing_a_period(): void
    {
        $this->ready();
        $this->postJson('/api/cycles/preview', ['templateKey' => 'balanced', 'training_goal' => 'strength',
            'schema' => ['mon' => ['slot_a1' => ['selectedName' => 'Barbell Bench Press', 'weight' => 17.5, 'weightEdited' => true]]]])
            ->assertOk()->assertJsonPath('schema.mon.slot_a1.weight', 17.5)
            ->assertJsonPath('schema.mon.slot_a1.weightSource', 'eigen_invoer')
            ->assertJsonPath('schema.mon.slot_a1.minReps', 4);
        $this->assertSame(1, Cycle::query()->count());
    }

    public function test_backup_restores_period_definition_prescriptions_and_variant_library(): void
    {
        Storage::fake('local');
        $cycle = $this->start('dumbbells');
        $slot = $cycle->sessions()->where('week', 1)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_b2')->first();
        $slot->sets()->first()->update(['weight' => '12', 'reps' => '9', 'completed' => true, 'exertion' => 'good']);
        $variant = ProgramVariant::query()->where('key', 'push_c')->firstOrFail();
        $variant->update(['name' => 'Push C persoonlijk', 'description' => 'Eigen opbouw']);
        $backup = app(TrainingBackup::class);
        $payload = $backup->payload();
        $variant->update(['name' => 'Tijdelijke andere naam']);
        $backup->import($payload);
        $restored = Cycle::query()->where('is_current', true)->firstOrFail();
        $this->assertSame(6, $restored->total_weeks);
        $this->assertSame('dumbbells', $restored->program_definition['key']);
        $this->assertSame('Push C persoonlijk', $variant->fresh()->name);
        $this->assertSame('Eigen opbouw', $variant->fresh()->description);
        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_b2.prescription.sets', 2)
            ->assertJsonPath('appState.weeks.1.mon.slot_b2.sets.0.weight', '12')
            ->assertJsonPath('appState.programDefinition.days.mon.variantName', 'Push C');
    }

    public function test_personal_progression_uses_small_steps_and_repeated_misses_reduce(): void
    {
        $cycle = $this->start();
        $monday = $cycle->sessions()->where('week', 1)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->with('sets')->first();
        $monday->update(['progression_plan' => ['weight' => 20, 'reps' => 8, 'minReps' => 8, 'maxReps' => 12]]);
        foreach ($monday->sets as $set) {
            $set->update(['weight' => '20', 'reps' => '8', 'completed' => true, 'exertion' => 'good']);
        }
        $report = $this->getJson('/api/weeks/1/report')->assertOk()->json('exercises');
        $bench = collect($report)->firstWhere('name', 'Barbell Bench Press');
        $this->assertSame(9, $bench['next']['reps']);
        $this->assertSame(20.0, (float) $bench['next']['weight']);
        $monday->sets->last()->update(['reps' => '7', 'exertion' => 'max']);
        $report = $this->getJson('/api/weeks/1/report')->assertOk()->json('exercises');
        $this->assertSame('repeat', collect($report)->firstWhere('name', 'Barbell Bench Press')['next']['change']);
        $second = $cycle->sessions()->where('week', 2)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->with('sets')->first();
        $second->update(['progression_plan' => ['weight' => 20, 'reps' => 8, 'minReps' => 8, 'maxReps' => 12]]);
        foreach ($second->sets as $set) {
            $set->update(['weight' => '20', 'reps' => '7', 'completed' => true, 'exertion' => 'max']);
        }
        $report = $this->getJson('/api/weeks/2/report')->assertOk()->json('exercises');
        $next = collect($report)->firstWhere('name', 'Barbell Bench Press')['next'];
        $this->assertSame('reduce', $next['change']);
        $this->assertSame(18.0, (float) $next['weight']);
    }

    public function test_same_exercise_with_another_rep_range_does_not_inherit_its_weight(): void
    {
        $this->ready();
        $this->postJson('/api/cycles', ['close_current_period' => true, 'training_goal' => 'strength', 'dayVariants' => ['tue' => 'pull_b', 'fri' => 'pull_f']])->assertOk();
        $cycle = Cycle::query()->where('is_current', true)->firstOrFail();
        $slot = $cycle->sessions()->where('week', 1)->where('day', 'tue')->first()->slots()->where('slot_key', 'slot_a2')->with('sets')->first();
        foreach ($slot->sets as $set) {
            $set->update(['weight' => '50', 'reps' => '4', 'completed' => true, 'exertion' => 'good']);
        }
        $state = $this->getJson('/api/state')->assertOk()->json();
        $friday = collect($state['weeks'][1]['fri']['slots'])->firstWhere('slotKey', 'slot_b4');
        $this->assertNull($friday['advice']['advisedWeight']);
        $this->assertSame(8, $friday['advice']['minReps']);
    }

    public function test_repeating_a_period_recalculates_old_manually_chosen_start_weight(): void
    {
        $cycle = $this->start();
        $definition = $cycle->program_definition;
        $definition['days']['mon']['exercises']['slot_a1']['weightEdited'] = true;
        $definition['days']['mon']['exercises']['slot_a1']['weight'] = 15;
        $cycle->update(['program_definition' => $definition]);
        $slot = $cycle->sessions()->where('week', 1)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->first();
        $slot->sets()->first()->update(['weight' => '20', 'reps' => '8', 'completed' => true, 'exertion' => 'good']);
        $this->getJson('/api/cycles/next-advice?templateKey=repeat')->assertOk()
            ->assertJsonPath('schema.mon.slot_a1.weight', 20)
            ->assertJsonPath('schema.mon.slot_a1.weightSource', 'eigen_historie');
    }

    public function test_history_survives_backup_restore_for_archived_periods(): void
    {
        Storage::fake('local');
        $old = $this->ready();
        $slot = $old->sessions()->where('week', 6)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->first();
        $slot->update(['target_reps' => 8]);
        $slot->sets()->first()->update(['weight' => '72', 'reps' => '8', 'completed' => true, 'exertion' => 'good']);
        $this->postJson('/api/cycles', ['close_current_period' => true])->assertOk();
        $current = Cycle::query()->where('is_current', true)->firstOrFail();
        $current->sessions()->where('week', 1)->where('day', 'tue')->first()->slots()->first()->sets()->first()
            ->update(['weight' => '30', 'reps' => '8', 'completed' => true, 'exertion' => 'good']);
        $backup = app(TrainingBackup::class);
        $backup->import($backup->payload());
        $history = app(ExerciseHistory::class)->all();
        $this->assertSame(72.0, $history['barbell bench press']['reference']['sets'][0]['weight']);
        $this->assertSame(1, $history['barbell bench press']['cycle']);
    }

    public function test_material_requirements_are_explicit_for_every_program_exercise(): void
    {
        $library = app(ProgramLibrary::class);
        foreach (config('push_pull.roles') as $role) {
            foreach ($role['exercises'] as $name) {
                $this->assertArrayHasKey($name, config('push_pull.equipment'));
            }
        }
        $this->assertContains('bench', $library->requirements('Dumbbell Chest Flyes'));
        $this->assertNotContains('bench', $library->requirements('Floor Dumbbell Flyes'));
        $this->assertContains('rack', $library->requirements('Barbell Bench Press'));
    }

    public function test_legacy_exercise_swap_clears_automatic_weights_but_protects_own_input(): void
    {
        $cycle = $this->ready();
        $monday = $cycle->sessions()->where('week', 1)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->with('sets')->first();
        $future = $cycle->sessions()->where('week', 2)->where('day', 'mon')->first()->slots()->where('slot_key', 'slot_a1')->with('sets')->first();
        foreach ([$monday, $future] as $slot) {
            $slot->sets()->update(['weight' => '80', 'input_fields' => '[]']);
        }
        $this->patchJson('/api/slots/'.$monday->id, ['context' => 'setup', 'selectedName' => 'Dumbbell Floor Press'])->assertOk();
        $this->assertSame('', $monday->sets->first()->fresh()->weight);
        $this->assertSame('', $future->sets->first()->fresh()->weight);
        $future->sets->first()->update(['weight' => '17', 'input_fields' => ['weight' => true]]);
        $this->patchJson('/api/slots/'.$future->id, ['context' => 'setup', 'selectedName' => 'Dumbbell Bench Press'])->assertUnprocessable();
        $this->assertSame('17', $future->sets->first()->fresh()->weight);
    }

    public function test_bodyweight_program_does_not_invent_weight_or_exceed_its_rep_range(): void
    {
        $progress = ['targetWeight' => 0, 'targetReps' => 12, 'minReps' => 8, 'maxReps' => 12,
            'completedSets' => 3, 'requiredSets' => 3, 'achieved' => true, 'exertion' => 'good',
            'isBodyweight' => true, 'personalProgram' => true, 'achievedReps' => 12];
        $next = app(Periodization::class)->nextProgression($progress, 2, 2, 'weekly');
        $this->assertSame(12, $next['reps']);
        $this->assertSame(0.0, $next['weight']);
        $this->assertSame('repeat', $next['change']);
    }
}
