<?php

namespace Tests\Feature;

use App\Services\CycleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseVolumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_week_volume_counts_both_dumbbells_and_both_arms_without_changing_logged_sets(): void
    {
        $cycle = app(CycleFactory::class)->ensureCurrent();
        $monday = $cycle->sessions()->where('week', 1)->where('day', 'mon')->firstOrFail();
        $exercises = [
            'slot_a1' => ['Barbell Bench Press', '40', '10'],
            'slot_a2' => ['Dumbbell One-Arm Row', '20', '8'],
            'slot_a3' => ['Dumbbell Bench Press', '20', '10'],
            'slot_a4' => ['Dumbbell Pullover', '20', '10'],
            'slot_a5' => ['Dumbbell Lateral Raises', '7.5', '12'],
            'slot_a6' => ['Dumbbell Overhead Tricep Extension', '20', '10'],
        ];
        foreach ($exercises as $key => [$name, $weight, $reps]) {
            $slot = $monday->slots()->where('slot_key', $key)->with('sets')->firstOrFail();
            $slot->update(['selected_name' => $name]);
            $slot->sets[0]->update(['weight' => $weight, 'reps' => $reps, 'completed' => true]);
            $slot->sets[1]->update(['weight' => '100', 'reps' => '12', 'completed' => false]);
            $slot->sets[2]->update(['weight' => '0', 'reps' => '12', 'completed' => true]);
        }
        $thursday = $cycle->sessions()->where('week', 1)->where('day', 'thu')->firstOrFail()
            ->slots()->where('slot_key', 'slot_a1')->with('sets')->firstOrFail();
        $thursday->update(['selected_name' => 'Dumbbell Bench Press']);
        $thursday->sets[0]->update(['weight' => '20', 'reps' => '6', 'completed' => true]);
        $thursday->sets[1]->update(['weight' => '20', 'reps' => '0', 'completed' => true]);
        $bodyweight = $cycle->sessions()->where('week', 1)->where('day', 'tue')->firstOrFail()
            ->slots()->where('slot_key', 'slot_b6')->with('sets')->firstOrFail();
        $bodyweight->sets[0]->update(['weight' => '20', 'reps' => '10', 'completed' => true]);

        $response = $this->getJson('/api/weeks/1/report')->assertOk()
            ->assertJsonPath('volume', 1940)
            ->assertJsonPath('completedSets', 8);

        $bench = collect($response->json('exercises'))->firstWhere('name', 'Dumbbell Bench Press');
        $this->assertSame(640, $bench['volume']);
        $this->assertSame(20, $bench['maxWeight']);
        $this->assertSame(2, $bench['sets']);
        $this->assertSame('20', $thursday->sets[0]->fresh()->weight);
        $this->assertSame('6', $thursday->sets[0]->fresh()->reps);
    }

    public function test_both_frontends_receive_the_volume_rules_for_the_selected_exercise(): void
    {
        $state = $this->getJson('/api/state')->assertOk()->json();
        $incline = collect($state['weeks'][1]['mon']['slots'])->firstWhere('slotKey', 'slot_a3');
        $this->assertSame(2, $incline['volumeMultiplier']);
        $barbell = collect($state['weeks'][1]['mon']['slots'])->firstWhere('slotKey', 'slot_a1');
        $this->assertSame(1, $barbell['volumeMultiplier']);
        $bodyweight = collect($state['weeks'][1]['tue']['slots'])->firstWhere('slotKey', 'slot_b6');
        $this->assertSame(0, $bodyweight['volumeMultiplier']);

        $this->getJson('/api/marker-state')->assertOk()
            ->assertJsonPath('appState.trainingRules.exerciseVolume.dumbbell bench press.multiplier', 2)
            ->assertJsonPath('appState.trainingRules.exerciseVolume.dumbbell one-arm row.repsLabel', 'Reps per arm')
            ->assertJsonPath('appState.trainingRules.exerciseVolume.dumbbell pullover.multiplier', 1)
            ->assertJsonPath('appState.trainingRules.exerciseVolume', $state['trainingRules']['exerciseVolume']);
    }
}
