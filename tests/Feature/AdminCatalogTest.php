<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ProgramSlot;
use App\Services\Catalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class]);
    }

    public function test_admin_dashboard_and_rules_explain_the_training_model(): void
    {
        $dashboard = $this->get('/beheer');
        $dashboard
            ->assertOk()
            ->assertSee('IRONFORGE BEHEER')
            ->assertSee('ATHLEAN-X™ alleen als de YouTube-video van Jeff Cavaliere is')
            ->assertDontSee('Elke oefening heeft een Athlean-video')
            ->assertDontSee('muscleAnatomySvg', false)
            ->assertDontSee('Start Training', false);
        $this->assertStringContainsString('no-store', (string) $dashboard->headers->get('Cache-Control'));

        $this->get('/beheer/regels')
            ->assertOk()
            ->assertSee('Week 1 — inregelen')
            ->assertSee('progressive overload')
            ->assertSee('Vlot (easy)')
            ->assertSee('deload');
    }

    public function test_catalog_labels_athlean_only_for_verified_jeff_videos(): void
    {
        $catalog = app(Catalog::class);
        $catalog->sync();

        $this->assertSame('vthMCtgVtFw', Exercise::query()->where('name', 'Barbell Bench Press')->value('youtube_id'));
        $this->assertSame('ATHLEAN-X™', Exercise::query()->where('name', 'Barbell Bench Press')->value('channel'));
        $this->assertTrue($catalog->isVerifiedAthlean('vthMCtgVtFw'));

        $floor = Exercise::query()->where('name', 'Dumbbell Floor Press')->firstOrFail();
        $this->assertSame('uUGDRwge4F8', $floor->youtube_id);
        $this->assertSame('ScottHermanFitness', $floor->channel);
        $this->assertFalse($floor->toVideoArray()['isAthlean']);

        $this->assertSame('tpLnfSQJ0gg', Exercise::query()->where('name', 'Dumbbell Pullover')->value('youtube_id'));
        $this->assertSame('8iPEnn-ltC8', Exercise::query()->where('name', 'Incline Dumbbell Press')->value('youtube_id'));
        $this->assertSame('DbFgADa2PL8', Exercise::query()->where('name', 'Incline Barbell Press')->value('youtube_id'));
        $this->assertSame('IODxDxX7oi4', Exercise::query()->where('name', 'Opdrukken (Klassieke Push-ups)')->value('youtube_id'));
        $this->assertSame('vi1-BOcj3cQ', Exercise::query()->where('name', 'Dips')->value('youtube_id'));
        $this->assertSame('jdFzYGmvDyg', Exercise::query()->where('name', 'Bench Dips')->value('youtube_id'));
        $this->assertSame('ris9tKqMwgU', Exercise::query()->where('name', 'Arnold Press')->value('youtube_id'));

        $retired = require database_path('data/retired-video-ids.php');
        $assigned = Exercise::query()->pluck('youtube_id');
        foreach ($retired as $videoId) {
            $this->assertFalse($assigned->contains($videoId));
        }

        $falseAthlean = Exercise::query()->where('channel', 'like', '%ATHLEAN%')
            ->get()
            ->filter(fn (Exercise $exercise) => ! $catalog->isVerifiedAthlean((string) $exercise->youtube_id));
        $this->assertSame(0, $falseAthlean->count());

        $this->assertSame(0, $catalog->missingVideoCount());
        $this->assertGreaterThan(40, Exercise::query()->count());

        $payload = $this->getJson('/api/marker-state')->assertOk()->json('appState.exerciseVideos');
        $this->assertTrue($payload['Barbell Bench Press']['isAthlean']);
        $this->assertFalse($payload['Dumbbell Floor Press']['isAthlean']);
        $this->assertFalse($payload['Dumbbell Pullover']['isAthlean']);
        $this->assertTrue($payload['Arnold Press']['isAthlean']);

        $this->get('/beheer/oefeningen')
            ->assertOk()
            ->assertSee('ATHLEAN-X™ alleen als het kanaal klopt')
            ->assertSee('Are You Doing Dips Properly')
            ->assertDontSee('Elke rij heeft een Jeff Cavaliere / ATHLEAN-X™ form video');
    }

    public function test_program_slot_can_be_updated_from_admin(): void
    {
        app(Catalog::class)->sync();
        $slot = ProgramSlot::query()->where('slot_key', 'slot_a1')->firstOrFail();

        $this->patch('/beheer/programma/'.$slot->id, [
            'default_name' => 'Dumbbell Bench Press',
            'target_reps' => 10,
            'rest_time' => 120,
            'rest_type' => 'Compound Borst',
            'alternatives' => "Dumbbell Bench Press\nBarbell Bench Press",
        ])->assertRedirect();

        $slot->refresh();
        $this->assertSame('Dumbbell Bench Press', $slot->default_name);
        $this->assertSame(10, $slot->target_reps);
        $this->assertSame(120, $slot->rest_time);
    }

    public function test_exercise_video_can_be_updated(): void
    {
        app(Catalog::class)->sync();
        $exercise = Exercise::query()->where('name', 'Barbell Row')->firstOrFail();

        $this->patch('/beheer/oefeningen/'.$exercise->id, [
            'youtube_url' => 'https://www.youtube.com/watch?v=G8l_8chR5BE',
            'title' => 'Row form',
        ])->assertRedirect();

        $fresh = $exercise->fresh();
        $this->assertSame('G8l_8chR5BE', $fresh->youtube_id);
        $this->assertSame('Row form', $fresh->title);
        $this->assertSame('', $fresh->channel);
        $this->assertFalse($fresh->toVideoArray()['isAthlean']);

        $this->patch('/beheer/oefeningen/'.$exercise->id, [
            'youtube_url' => 'https://www.youtube.com/watch?v=vthMCtgVtFw',
            'title' => 'Official bench checklist',
        ])->assertRedirect();

        $this->assertSame('ATHLEAN-X™', $exercise->fresh()->channel);
        $this->assertTrue($exercise->fresh()->toVideoArray()['isAthlean']);
    }

    public function test_catalog_replaces_retired_compilation_videos_on_later_sync(): void
    {
        app(Catalog::class)->sync();

        $floor = Exercise::query()->where('name', 'Dumbbell Floor Press')->firstOrFail();
        $floor->update([
            'youtube_id' => 'PcThnQTTDAo',
            'title' => 'How to Increase Your Bench Press (FASTEST WAY!)',
            'channel' => 'ATHLEAN-X™',
        ]);

        (new Catalog)->sync();

        $floor->refresh();
        $this->assertSame('uUGDRwge4F8', $floor->youtube_id);
        $this->assertSame('ScottHermanFitness', $floor->channel);
        $this->assertFalse($floor->toVideoArray()['isAthlean']);
        $this->assertNotEmpty($floor->cues);
    }

    public function test_preferences_can_be_updated(): void
    {
        $this->patch('/beheer/voorkeuren', [
            'overload_increment' => 2.5,
            'overload_frequency' => 'biweekly',
            'current_week' => 3,
        ])->assertRedirect();

        $this->get('/beheer/voorkeuren')
            ->assertOk()
            ->assertSee('2.5')
            ->assertSee('biweekly');
    }
}
