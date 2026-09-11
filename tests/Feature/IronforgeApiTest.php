<?php

namespace Tests\Feature;

use App\Models\WorkoutSet;
use App\Services\PersonalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IronforgeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_creates_a_seven_week_skeleton(): void
    {
        $response = $this->getJson('/api/state');

        $response->assertOk()
            ->assertJsonPath('currentWeek', 1)
            ->assertJsonPath('currentDay', 'mon')
            ->assertJsonPath('totalWeeks', 7)
            ->assertJsonPath('weeks.1.mon.slots.0.slotKey', 'slot_a1');

        $this->assertCount(7, $response->json('weeks'));
        $this->assertCount(6, $response->json('weeks.1.mon.slots'));
    }

    public function test_logging_a_set_persists_and_returns_in_state(): void
    {
        $this->getJson('/api/state')->assertOk();
        $set = WorkoutSet::query()->firstOrFail();

        $this->patchJson('/api/sets/'.$set->id, [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'context' => 'live',
        ])->assertOk()
            ->assertJsonPath('set.id', $set->id)
            ->assertJsonPath('set.completed', true)
            ->assertJsonMissingPath('weeks');

        $this->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('weeks.1.mon.slots.0.sets.0.completed', true);

        $this->assertDatabaseHas('workout_sets', [
            'id' => $set->id,
            'weight' => '80',
            'reps' => '8',
            'completed' => 1,
        ]);
    }

    public function test_completing_a_weighted_set_without_weight_is_rejected(): void
    {
        $this->getJson('/api/state')->assertOk();
        $set = WorkoutSet::query()->firstOrFail();

        $this->patchJson('/api/sets/'.$set->id, [
            'reps' => '8',
            'completed' => true,
            'context' => 'live',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('workout_sets', [
            'id' => $set->id,
            'completed' => 0,
        ]);
    }

    public function test_locked_main_screen_rejects_set_edits(): void
    {
        $this->getJson('/api/state')->assertOk();
        $this->patchJson('/api/preferences', ['routine_locked' => true])->assertOk();
        $set = WorkoutSet::query()->firstOrFail();

        $this->patchJson('/api/sets/'.$set->id, [
            'weight' => '90',
            'context' => 'main',
        ])->assertUnprocessable();
    }

    public function test_personal_records_survive_a_new_cycle(): void
    {
        $this->getJson('/api/state')->assertOk();
        $set = WorkoutSet::query()->firstOrFail();
        $this->patchJson('/api/sets/'.$set->id, [
            'weight' => '100',
            'reps' => '5',
            'completed' => true,
            'context' => 'live',
        ])->assertOk();

        $name = $set->fresh()->slot->selected_name;
        $this->postJson('/api/cycles')->assertOk()->assertJsonPath('currentCycle', 2);

        $record = app(PersonalRecord::class)->allTime($name);
        $this->assertSame(100.0, $record['maxWeight']);
        $this->assertGreaterThan(100, $record['max1RM']);
    }

    public function test_patching_one_set_does_not_rewrite_sibling_sets(): void
    {
        $this->getJson('/api/state')->assertOk();
        $first = WorkoutSet::query()->orderBy('id')->firstOrFail();
        $sibling = WorkoutSet::query()->where('id', '!=', $first->id)->orderBy('id')->firstOrFail();
        $sibling->update([
            'weight' => '40',
            'reps' => '5',
            'completed' => false,
        ]);

        $this->patchJson('/api/sets/'.$first->id, [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'context' => 'live',
        ])->assertOk();

        $this->assertDatabaseHas('workout_sets', [
            'id' => $sibling->id,
            'weight' => '40',
            'reps' => '5',
            'completed' => 0,
        ]);
        $this->assertDatabaseHas('workout_sets', [
            'id' => $first->id,
            'weight' => '80',
            'reps' => '8',
            'completed' => 1,
        ]);
    }

    public function test_week_report_counts_completed_volume(): void
    {
        $this->getJson('/api/state')->assertOk();
        $set = WorkoutSet::query()->firstOrFail();
        $this->patchJson('/api/sets/'.$set->id, [
            'weight' => '50',
            'reps' => '10',
            'completed' => true,
            'context' => 'live',
        ])->assertOk();

        $this->getJson('/api/weeks/1/report')
            ->assertOk()
            ->assertJsonPath('week', 1)
            ->assertJsonPath('completedSets', 1)
            ->assertJsonPath('volume', 500);
    }

    public function test_spa_is_served_from_root(): void
    {
        $this->get('/')->assertOk();
    }
}
