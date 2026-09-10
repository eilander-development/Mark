<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ValImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_val_dump_is_refused_even_when_mysql_is_empty(): void
    {
        $this->getJson('/api/marker-state')->assertOk();

        Http::preventStrayRequests();
        Http::fake([
            $this->valTownUrl() => Http::response([
                'appState' => [
                    'currentWeek' => 1,
                    'weeks' => ['1' => ['mon' => ['slot_a1' => ['sets' => [['completed' => false]]]]]],
                ],
            ], 200),
        ]);

        $this->postJson('/api/import/val', ['confirm' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Val-dump heeft geen voltooide sets. Bestaande training blijft staan.');
    }

    public function test_empty_val_dump_does_not_overwrite_completed_sets(): void
    {
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();

        Http::preventStrayRequests();
        Http::fake([
            $this->valTownUrl() => Http::response([
                'appState' => [
                    'currentWeek' => 1,
                    'currentDay' => 'mon',
                    'weeks' => [
                        '1' => [
                            'mon' => [
                                'slot_a1' => [
                                    'selectedName' => 'Barbell Bench Press',
                                    'sets' => [
                                        ['weight' => '', 'reps' => '', 'completed' => false],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/import/val', ['confirm' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Val-dump heeft geen voltooide sets. Bestaande training blijft staan.');

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '80')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', true);

        Http::assertSentCount(1);
    }

    public function test_val_import_fills_missing_days_without_overwriting_logged_sets(): void
    {
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();

        Http::preventStrayRequests();
        Http::fake([
            $this->valTownUrl() => Http::response([
                'appState' => [
                    'currentWeek' => 1,
                    'currentDay' => 'tue',
                    'weeks' => [
                        '1' => [
                            'mon' => [
                                'slot_a1' => [
                                    'selectedName' => 'Dumbbell Bench Press',
                                    'sets' => [
                                        ['weight' => '15', 'reps' => '8', 'completed' => true, 'exertion' => 'easy'],
                                    ],
                                ],
                            ],
                            'tue' => [
                                'slot_b1' => [
                                    'selectedName' => 'Dumbbell Pullover',
                                    'sets' => [
                                        ['weight' => '12', 'reps' => '10', 'completed' => true, 'exertion' => 'good'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/import/val', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('imported', true)
            ->assertJsonPath('completedSets', 2);

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '80')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.selectedName', 'Barbell Bench Press')
            ->assertJsonPath('appState.weeks.1.tue.slot_b1.sets.0.weight', '12')
            ->assertJsonPath('appState.weeks.1.tue.slot_b1.sets.0.completed', true)
            ->assertJsonPath('appState.weeks.1.tue.slot_b1.selectedName', 'Dumbbell Pullover');
    }

    public function test_val_import_does_not_fill_empty_sibling_sets_in_a_logged_slot(): void
    {
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['slot_a1']['sets'][1] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();

        Http::preventStrayRequests();
        Http::fake([
            $this->valTownUrl() => Http::response([
                'appState' => [
                    'currentWeek' => 1,
                    'currentDay' => 'tue',
                    'weeks' => [
                        '1' => [
                            'mon' => [
                                'slot_a1' => [
                                    'selectedName' => 'Dumbbell Bench Press',
                                    'sets' => [
                                        ['weight' => '15', 'reps' => '8', 'completed' => true, 'exertion' => 'easy'],
                                        ['weight' => '15', 'reps' => '8', 'completed' => true, 'exertion' => 'easy'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/import/val', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('imported', true);

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.selectedName', 'Barbell Bench Press')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', false)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.1.weight', '80')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.1.completed', true);
    }

    public function test_val_dump_with_completed_sets_replaces_the_current_cycle(): void
    {
        $this->getJson('/api/marker-state')->assertOk();

        Http::preventStrayRequests();
        Http::fake([
            $this->valTownUrl() => Http::response([
                'appState' => [
                    'currentWeek' => 1,
                    'currentDay' => 'tue',
                    'weeks' => [
                        '1' => [
                            'mon' => [
                                'slot_a1' => [
                                    'selectedName' => 'Barbell Bench Press',
                                    'sets' => [
                                        ['weight' => '90', 'reps' => '6', 'completed' => true, 'exertion' => 'max'],
                                        ['weight' => '', 'reps' => '', 'completed' => false],
                                        ['weight' => '', 'reps' => '', 'completed' => false],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/import/val', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('imported', true)
            ->assertJsonPath('completedSets', 1);

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.currentDay', 'tue')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '90')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', true);

        Http::assertSentCount(1);
    }

    private function valTownUrl(): string
    {
        return rtrim((string) config('ironforge.val_town_url'), '/').'/';
    }
}
