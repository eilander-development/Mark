<?php

namespace Tests\Feature;

use App\Models\Cycle;
use App\Models\WorkoutSet;
use App\Services\TrainingBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_downloads_the_current_cycle_as_importable_json(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-09-11 15:00:00');

        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();

        $response = $this->getJson('/api/backup');

        $response->assertOk()
            ->assertJsonPath('format', 'ironforge-backup')
            ->assertJsonPath('version', 1)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '80')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', true);
        $this->assertStringContainsString(
            'ironforge-backup-2026-09-11.json',
            (string) $response->headers->get('Content-Disposition'),
        );
        Storage::disk('local')->assertExists(TrainingBackup::LATEST_PATH);
    }

    public function test_import_returns_422_when_the_file_is_missing(): void
    {
        $this->postJson('/api/backup', ['confirm' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['backup']);
    }

    public function test_import_returns_422_when_the_file_is_not_json(): void
    {
        Storage::fake('local');

        $this->post('/api/backup', [
            'confirm' => '1',
            'backup' => UploadedFile::fake()->createWithContent('backup.json', 'niet-json'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Backup is geen geldige JSON.');
    }

    public function test_import_returns_422_when_weeks_are_missing(): void
    {
        Storage::fake('local');

        $this->post('/api/backup', [
            'confirm' => '1',
            'backup' => $this->backupFile(['format' => 'ironforge-backup', 'appState' => []]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Backup heeft geen weeks.');
    }

    public function test_empty_backup_does_not_overwrite_completed_sets(): void
    {
        Storage::fake('local');
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();

        $this->post('/api/backup', [
            'confirm' => '1',
            'backup' => $this->backupFile([
                'format' => 'ironforge-backup',
                'appState' => [
                    'currentWeek' => 1,
                    'weeks' => [
                        '1' => [
                            'mon' => [
                                'slot_a1' => [
                                    'sets' => [['weight' => '', 'reps' => '', 'completed' => false]],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Backup heeft geen voltooide sets. Bestaande training blijft staan.');

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '80')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', true);
    }

    public function test_import_overwrites_mysql_and_deletes_the_latest_export(): void
    {
        Storage::fake('local');
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();
        $this->getJson('/api/backup')->assertOk();
        Storage::disk('local')->assertExists(TrainingBackup::LATEST_PATH);

        $this->post('/api/backup', [
            'confirm' => '1',
            'backup' => $this->backupFile([
                'format' => 'ironforge-backup',
                'version' => 1,
                'updatedAt' => '2026-09-11T13:00:00+00:00',
                'appState' => [
                    'currentWeek' => 1,
                    'currentDay' => 'thu',
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
                        ],
                    ],
                ],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imported', true)
            ->assertJsonPath('deletedExport', true)
            ->assertJsonPath('completedSets', 1);

        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.currentDay', 'thu')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.selectedName', 'Dumbbell Bench Press')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '15')
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.completed', true)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.reps', '8');
        Storage::disk('local')->assertMissing(TrainingBackup::LATEST_PATH);
    }

    public function test_import_replaces_every_cycle_not_only_the_current_one(): void
    {
        Storage::fake('local');
        $state = $this->getJson('/api/marker-state')->assertOk()->json('appState');
        $state['weeks'][1]['mon']['slot_a1']['sets'][0] = [
            'weight' => '80',
            'reps' => '8',
            'completed' => true,
            'exertion' => 'good',
        ];
        $this->putJson('/api/marker-state', ['appState' => $state])->assertOk();
        $this->patchJson('/api/preferences', ['current_week' => 7])->assertOk();
        $this->postJson('/api/cycles')->assertOk()->assertJsonPath('currentCycle', 2);
        $this->assertSame(2, Cycle::query()->count());
        $this->assertTrue(WorkoutSet::query()->where('weight', '80')->where('completed', true)->exists());

        $this->post('/api/backup', [
            'confirm' => '1',
            'backup' => $this->backupFile([
                'format' => 'ironforge-backup',
                'version' => 1,
                'appState' => [
                    'currentCycle' => 1,
                    'currentWeek' => 1,
                    'currentDay' => 'mon',
                    'cyclesHistory' => [
                        [
                            'number' => 3,
                            'started_at' => '2026-08-01',
                            'completed_at' => '2026-08-28',
                            'snapshot' => ['note' => 'oude periode'],
                        ],
                    ],
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
                        ],
                    ],
                ],
            ]),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(2, Cycle::query()->count());
        $this->assertSame(1, (int) Cycle::query()->where('is_current', true)->value('number'));
        $this->assertSame(3, (int) Cycle::query()->where('is_current', false)->value('number'));
        $this->assertSame(['note' => 'oude periode'], Cycle::query()->where('is_current', false)->first()?->snapshot);
        $this->assertFalse(WorkoutSet::query()->where('weight', '80')->where('completed', true)->exists());
        $this->getJson('/api/marker-state')
            ->assertOk()
            ->assertJsonPath('appState.currentCycle', 1)
            ->assertJsonPath('appState.weeks.1.mon.slot_a1.sets.0.weight', '15')
            ->assertJsonPath('appState.cyclesHistory.0.number', 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function backupFile(array $payload): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'ironforge-backup.json',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
