<?php

namespace Tests\Feature;

use App\Models\WorkoutSession;
use App\Services\Catalog;
use App\Services\Periodization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotorParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_cycle_carries_peak_from_last_heavy_week_without_adding_increment(): void
    {
        $this->getJson('/api/state')->assertOk();

        $week1 = WorkoutSession::query()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $week6 = WorkoutSession::query()->where('week', 6)->where('day', 'mon')->firstOrFail();
        $slot1 = $week1->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
        $slot6 = $week6->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();

        $slot1->sets->first()->update(['weight' => '70', 'reps' => '8', 'completed' => true]);
        $slot6->sets->first()->update(['weight' => '86', 'reps' => '8', 'completed' => true]);

        $this->postJson('/api/cycles')->assertOk()->assertJsonPath('currentCycle', 2);

        $newWeek1 = WorkoutSession::query()
            ->where('week', 1)
            ->where('day', 'mon')
            ->whereHas('cycle', fn ($query) => $query->where('is_current', true))
            ->firstOrFail();
        $newSlot = $newWeek1->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();

        $this->assertSame('Incline Barbell Press', $newSlot->selected_name);
        $this->assertNotSame($slot6->selected_name, $newSlot->selected_name);
        $this->assertSame('', $newSlot->sets->first()->weight);
    }

    public function test_deload_advice_matches_marker_increment_rounding(): void
    {
        $periodization = app(Periodization::class);

        $this->assertSame(55.0, $periodization->advisedWeight(80, 7, 2.5, 'weekly'));
        $this->assertSame(56.0, $periodization->advisedWeight(80, 7, 2.0, 'weekly'));
        $this->assertTrue($periodization->isBiweeklyHoldWeek(2, 'biweekly'));
        $this->assertFalse($periodization->isBiweeklyHoldWeek(3, 'biweekly'));
        $this->assertFalse($periodization->isBiweeklyHoldWeek(2, 'weekly'));
    }

    public function test_wizard_schema_wins_over_automatic_rotation(): void
    {
        $this->getJson('/api/state')->assertOk();

        $this->postJson('/api/cycles', [
            'schema' => [
                'mon' => [
                    'slot_a1' => [
                        'selectedName' => 'Dumbbell Bench Press',
                        'weight' => 28,
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('currentCycle', 2);

        $newWeek1 = WorkoutSession::query()
            ->where('week', 1)
            ->where('day', 'mon')
            ->whereHas('cycle', fn ($query) => $query->where('is_current', true))
            ->firstOrFail();
        $newSlot = $newWeek1->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();

        $this->assertSame('Dumbbell Bench Press', $newSlot->selected_name);
        $this->assertSame('28', $newSlot->sets->first()->weight);
    }

    public function test_next_alternative_advances_within_the_slot_list(): void
    {
        $catalog = app(Catalog::class);

        $this->assertSame('Incline Barbell Press', $catalog->nextAlternative('slot_a1', 'Barbell Bench Press'));
        $this->assertSame('Barbell Bench Press', $catalog->nextAlternative('slot_a1', 'Tempo Dumbbell Bench Press'));
    }

    public function test_catalog_covers_every_program_name_with_a_form_video(): void
    {
        $catalog = app(Catalog::class);
        $catalog->sync();

        $this->assertSame(0, $catalog->missingVideoCount());
        $this->assertSame('', $catalog->channelForVideo(['videoId' => '', 'channel' => 'ATHLEAN-X™']));
        $this->assertSame('ATHLEAN-X™', $catalog->channelForVideo(['videoId' => 'vthMCtgVtFw', 'channel' => '']));
    }
}
