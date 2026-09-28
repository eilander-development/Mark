<?php

namespace Tests\Feature;

use App\Services\CycleFactory;
use App\Services\Periodization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdaptiveRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_is_validated_persisted_exported_and_locked_after_logging(): void
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        $session = $cycle->sessions()->where('week', 7)->where('day', 'mon')->firstOrFail();
        $slot = $session->slots()->with('sets')->firstOrFail();
        $slot->update(['progression_plan' => ['weight' => 14, 'reps' => 8]]);
        $slot->sets[0]->update(['weight' => '13', 'input_fields' => ['weight' => true]]);
        $slot->sets[1]->update(['weight' => '14']);

        $this->patchJson('/api/sessions/'.$session->id, ['recovery' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/sessions/'.$session->id, ['recovery' => 'exhausted'])->assertOk()->assertJsonPath('session.recovery', 'exhausted');
        $this->assertNull($slot->fresh()->progression_plan);
        $this->assertSame('13', $slot->sets[0]->fresh()->weight);
        $this->assertSame('', $slot->sets[1]->fresh()->weight);
        $this->getJson('/api/marker-state')->assertOk()->assertJsonPath('appState.weeks.7.mon.recovery', 'exhausted');
        $this->getJson('/api/state')->assertOk()->assertJsonPath('weeks.7.mon.recovery', 'exhausted');

        $slot->sets[0]->update(['completed' => true, 'reps' => '8']);
        $this->patchJson('/api/sessions/'.$session->id, ['recovery' => 'recovered'])->assertUnprocessable();
        $this->assertSame('exhausted', $session->fresh()->recovery);
    }

    public function test_recovery_changes_deload_advice_using_week_six_without_compounding(): void
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        foreach (['mon', 'thu'] as $day) {
            $session = $cycle->sessions()->where('week', 6)->where('day', $day)->firstOrFail();
            $slot = $session->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
            $slot->update(['selected_name' => 'Dumbbell Bench Press', 'progression_plan' => ['weight' => 20, 'reps' => 8], 'target_reps' => 8]);
            foreach ($slot->sets as $set) {
                $set->update(['completed' => true, 'weight' => '20', 'reps' => '8', 'exertion' => 'good']);
            }
        }
        $session = $cycle->sessions()->where('week', 7)->where('day', 'thu')->firstOrFail();
        $session->slots()->where('slot_key', 'slot_a1')->firstOrFail()->update(['selected_name' => 'Dumbbell Bench Press']);
        $monday = $cycle->sessions()->where('week', 7)->where('day', 'mon')->firstOrFail()->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
        $monday->update(['selected_name' => 'Dumbbell Bench Press']);
        $monday->sets[0]->update(['weight' => '14', 'reps' => '8', 'completed' => true, 'exertion' => 'good']);
        foreach (['exhausted' => 12, 'tired' => 14, 'recovered' => 16] as $recovery => $weight) {
            $this->patchJson('/api/sessions/'.$session->id, ['recovery' => $recovery])->assertOk();
            $state = $this->getJson('/api/state')->assertOk()->json();
            $slot = collect($state['weeks'][7]['thu']['slots'])->firstWhere('slotKey', 'slot_a1');
            $this->assertEquals($weight, $slot['advice']['advisedWeight']);
            $this->assertSame('Week 6', $slot['advice']['prevWeekFound']);
            $this->assertStringContainsString('herstelsets', $slot['advice']['adviceText']);
        }
    }

    public function test_recovery_survives_marker_state_round_trip(): void
    {
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][6]['mon']['recovery'] = 'tired';
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk()
            ->assertJsonPath('appState.weeks.6.mon.recovery', 'tired');
        $this->getJson('/api/marker-state')->assertOk()->assertJsonPath('appState.weeks.6.mon.recovery', 'tired');
    }

    public function test_unknown_or_incomplete_evidence_never_selects_the_lightest_deload(): void
    {
        $periodization = app(Periodization::class);
        $base = ['recovery' => 'recovered', 'completedSets' => 6, 'requiredSets' => 6, 'achieved' => true, 'exertion' => 'good'];
        $this->assertSame(0.8, $periodization->recoveryLoad($base)['factor']);
        foreach ([['unknownSets' => 1], ['completedSets' => 3], ['skippedSets' => 3], ['recovery' => 'unknown'], ['exertion' => 'max']] as $override) {
            $this->assertSame(0.7, $periodization->recoveryLoad(array_replace($base, $override))['factor']);
        }
        $this->assertSame(0.6, $periodization->recoveryLoad(array_replace($base, ['achieved' => false, 'maxSets' => 3]))['factor']);
    }
}
