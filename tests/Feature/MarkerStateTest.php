<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarkerStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_serves_the_marker_chrome(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('muscleAnatomySvg', false)
            ->assertSee('strengthBenchmarkModal', false)
            ->assertSee('workoutConfettiCanvas', false)
            ->assertSee('ATHLEAN-X', false)
            ->assertSee('Start Training', false)
            ->assertSee('registerIronForgeServiceWorker', false)
            ->assertSee("updateViaCache: 'none'", false)
            ->assertSee('ironforge-vue', false)
            ->assertSee('persistIncrementalToLaravel', false)
            ->assertSee('syncFromValOnDemand', false)
            ->assertSee('/api/import/val', false)
            ->assertSee('Val-dump heeft geen voltooide sets', false)
            ->assertSee('overschrijft de huidige cyclus in MySQL', false)
            ->assertSee('href="/beheer"', false)
            ->assertSee('appState.exerciseVideos', false)
            ->assertSee('!Array.isArray(slot.sets)) return', false)
            ->assertSee('id="lwVideoArea" class="hidden flex-col', false)
            ->assertSee('canStartNewPeriod', false)
            ->assertSee('soundToggleIcon', false)
            ->assertSee('Val importeren', false)
            ->assertSee('Backup exporteren', false)
            ->assertSee('Backup importeren', false)
            ->assertSee('overschrijft alle trainingsdata', false)
            ->assertSee('id="backupModal"', false)
            ->assertSee('/api/backup', false)
            ->assertSee('id="ironforgeBootOverlay"', false)
            ->assertSee('ironforgeBootOverlay")?.remove()', false)
            ->assertDontSee('JSON Back-up & Herstel', false)
            ->assertDontSee('id="headerCloudSyncBtn"', false)
            ->assertDontSee('id="lwVideoArea" class="hidden md:flex', false)
            ->assertDontSee('Toon bij het openen van de app de cloud synchronisatie vraag', false)
            ->assertDontSee('await fetchDataVanCloud(true)', false);
    }

    public function test_week_report_yellow_legend_follows_overload_frequency(): void
    {
        $this->get('/')
            ->assertDontSee('🟡 Goed = 2 Wk Consolidatie', false)
            ->assertSee('${freq === "biweekly" ? "2 Wk Consolidatie"', false);
    }

    public function test_marker_state_round_trip_persists_a_completed_set(): void
    {
        $exported = $this->getJson('/api/marker-state')->assertOk();
        $state = $exported->json('appState');

        $this->assertSame(1, $state['currentWeek']);
        $this->assertSame('Barbell Bench Press', $state['weeks'][1]['mon']['slot_a1']['selectedName']);
        $this->assertNotEmpty($state['weeks'][1]['mon']['slot_a1']['id']);
        $this->assertNotEmpty($state['weeks'][1]['mon']['slot_a1']['sessionId']);
        $this->assertNotEmpty($state['weeks'][1]['mon']['slot_a1']['sets'][0]['id']);
        $this->assertNotEmpty($state['exerciseVideos']['Barbell Bench Press']['videoId']);
        foreach ($state['weeks'][1]['mon'] as $key => $value) {
            $this->assertNotNull($value, "Day key {$key} must not be null (breaks live workout).");
        }

        $state['currentWeek'] = 2;
        $state['currentDay'] = 'tue';
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];

        $this->putJson('/api/marker-state', ['appState' => $state])
            ->assertOk()
            ->assertJsonPath('appState.currentWeek', 2)
            ->assertJsonPath('appState.currentDay', 'tue')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '80')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', true);

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.currentWeek', 2)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.reps', '8');
    }

    public function test_second_marker_state_get_does_not_rewrite_the_catalog(): void
    {
        $this->getJson('/api/marker-state')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.selectedName', 'Barbell Bench Press');

        $writes = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1)
            ->values()
            ->all();

        $this->assertSame([], $writes);
    }
}
