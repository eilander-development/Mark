<?php

namespace Tests\Feature;

use App\Models\PeriodTemplate;
use App\Models\ProgramVariant;
use App\Services\ProgramLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ironforge.admin_password' => '']);
        app(ProgramLibrary::class)->ensureDefaults();
    }

    private function variantData(ProgramVariant $variant): array
    {
        $items = [];
        foreach ($variant->exercises as $key => $item) {
            $items[$key] = [...$item, 'position' => count($items) + 1];
        }

        return ['name' => $variant->name, 'description' => $variant->description, 'exercises' => $items, 'archived' => false];
    }

    public function test_management_shows_push_pull_variants_and_four_day_templates(): void
    {
        $response = $this->get('/beheer/programma')->assertOk();
        foreach (['Push/Pull-programma', 'Push A', 'Push F', 'Pull A', 'Pull F', 'Periodevoorstellen samenstellen', 'Variant dupliceren'] as $text) {
            $response->assertSee($text);
        }
        $response->assertDontSee('Upper A / Upper B programma');
        $response->assertSee('Barbell Bench Press + Incline Dumbbell Press')->assertSee('inclusief korte warming-up');
    }

    public function test_variant_can_be_edited_and_reordered_with_prescriptions(): void
    {
        $variant = ProgramVariant::query()->where('key', 'push_a')->firstOrFail();
        $data = $this->variantData($variant);
        $data['name'] = 'Push A · eigen';
        $data['exercises']['slot_a1']['sets'] = 2;
        $data['exercises']['slot_a1']['position'] = 2;
        $data['exercises']['slot_a3']['position'] = 1;
        $this->patch('/beheer/programma/varianten/'.$variant->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Push A · eigen', $variant->fresh()->name);
        $this->assertSame('slot_a3', array_key_first($variant->fresh()->exercises));
        $this->assertSame(2, $variant->fresh()->exercises['slot_a1']['sets']);
        $exported = collect(app(ProgramLibrary::class)->export()['variants'])->firstWhere('key', 'push_a');
        $this->assertSame(13, $exported['workSets']);
        $this->assertSame('Incline Dumbbell Press + Barbell Bench Press', $exported['subtitle']);
    }

    public function test_cross_split_exercise_and_duplicate_positions_are_rejected(): void
    {
        $variant = ProgramVariant::query()->where('key', 'push_a')->firstOrFail();
        $data = $this->variantData($variant);
        $data['exercises']['slot_a1']['selectedName'] = 'Barbell Bicep Curls';
        $this->patch('/beheer/programma/varianten/'.$variant->id, $data)->assertSessionHasErrors('exercises.slot_a1.selectedName');
        $data = $this->variantData($variant);
        $data['exercises']['slot_a1']['position'] = 2;
        $this->patch('/beheer/programma/varianten/'.$variant->id, $data)->assertSessionHasErrors('exercises');
        $this->assertSame('Barbell Bench Press', $variant->fresh()->exercises['slot_a1']['selectedName']);
    }

    public function test_variant_cannot_contain_the_same_exercise_twice(): void
    {
        $variant = ProgramVariant::query()->where('key', 'pull_a')->firstOrFail();
        $data = $this->variantData($variant);
        $data['exercises']['slot_b4']['selectedName'] = 'Barbell Row';
        $this->patch('/beheer/programma/varianten/'.$variant->id, $data)->assertSessionHasErrors('exercises');
        $this->assertSame('Dumbbell One-Arm Row', $variant->fresh()->exercises['slot_b4']['selectedName']);
    }

    public function test_duplicate_can_be_archived_without_deleting_original(): void
    {
        $variant = ProgramVariant::query()->where('key', 'push_a')->firstOrFail();
        $this->post('/beheer/programma/varianten/'.$variant->id.'/kopieren')->assertRedirect();
        $copy = ProgramVariant::query()->latest('id')->firstOrFail();
        $data = $this->variantData($copy);
        $data['archived'] = true;
        $this->patch('/beheer/programma/varianten/'.$copy->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($copy->fresh()->archived);
        $this->assertFalse($variant->fresh()->archived);
        $this->assertSame(13, ProgramVariant::query()->count());
    }

    public function test_active_template_prevents_archiving_a_referenced_variant(): void
    {
        $variant = ProgramVariant::query()->where('key', 'push_a')->firstOrFail();
        $data = $this->variantData($variant);
        $data['archived'] = true;
        $this->patch('/beheer/programma/varianten/'.$variant->id, $data)->assertSessionHasErrors('archived');
        $this->assertFalse($variant->fresh()->archived);
    }

    public function test_period_template_is_composed_from_matching_day_variants(): void
    {
        $template = PeriodTemplate::query()->where('key', 'balanced')->firstOrFail();
        $data = ['name' => 'Mijn Push/Pull-periode', 'description' => 'Eigen combinatie',
            'days' => ['mon' => 'push_e', 'tue' => 'pull_c', 'thu' => 'push_f', 'fri' => 'pull_d']];
        $this->patch('/beheer/programma/voorstellen/'.$template->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('push_e', $template->fresh()->days['mon']);
        $data['days']['mon'] = 'pull_a';
        $this->patch('/beheer/programma/voorstellen/'.$template->id, $data)->assertSessionHasErrors('days.mon');
        $this->assertSame('push_e', $template->fresh()->days['mon']);
        $this->post('/beheer/programma/voorstellen/'.$template->id.'/kopieren')->assertRedirect();
        $this->assertSame(5, PeriodTemplate::query()->count());
    }
}
