<?php

namespace App\Services;

use App\Models\Cycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TrainingBackup
{
    public const FORMAT = 'ironforge-backup';

    public const VERSION = 1;

    public const LATEST_PATH = 'backups/ironforge-latest.json';

    public function __construct(
        private readonly MarkerState $marker,
        private readonly ValTownImporter $importer,
        private readonly CycleFactory $factory,
    ) {}

    /**
     * @return array{format: string, version: int, updatedAt: string, appState: array<string, mixed>}
     */
    public function payload(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'updatedAt' => now()->utc()->toIso8601String(),
            'appState' => $this->marker->export()['appState'],
        ];
    }

    public function filename(): string
    {
        return 'ironforge-backup-'.now()->format('Y-m-d').'.json';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeLatest(array $payload): void
    {
        Storage::disk('local')->put(
            self::LATEST_PATH,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    public function deleteLatest(): void
    {
        Storage::disk('local')->delete(self::LATEST_PATH);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{updatedAt: ?string, completedSets: int, weeks: int, currentWeek: int, cycle: int, imported: bool, deletedExport: bool}
     */
    public function import(array $payload): array
    {
        $state = $this->stateFrom($payload);
        $weeks = $state['weeks'];
        $completedSets = $this->countCompleted($weeks);
        if ($completedSets === 0) {
            throw new RuntimeException('Backup heeft geen voltooide sets. Bestaande training blijft staan.');
        }

        DB::transaction(fn () => $this->replaceAll($state));
        $hadExport = Storage::disk('local')->exists(self::LATEST_PATH);
        $this->deleteLatest();

        return [
            'updatedAt' => isset($payload['updatedAt']) && is_string($payload['updatedAt'])
                ? $payload['updatedAt']
                : null,
            'completedSets' => $completedSets,
            'weeks' => count($weeks),
            'currentWeek' => (int) ($state['currentWeek'] ?? 1),
            'cycle' => (int) ($state['currentCycle'] ?? 1),
            'imported' => true,
            'deletedExport' => $hadExport,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stateFrom(array $payload): array
    {
        if (isset($payload['appState']) && is_array($payload['appState'])) {
            $state = $payload['appState'];
        } elseif (isset($payload['weeks']) && is_array($payload['weeks'])) {
            $state = $payload;
        } else {
            throw new RuntimeException('Backup heeft geen appState of weeks.');
        }

        if (! is_array($state['weeks'] ?? null) || $state['weeks'] === []) {
            throw new RuntimeException('Backup heeft geen weeks.');
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function replaceAll(array $state): void
    {
        Cycle::query()->delete();

        $number = max(1, (int) ($state['currentCycle'] ?? 1));
        $cycle = $this->factory->createCycle($number);

        if (! isset($state['userProfile']) || ! is_array($state['userProfile'])) {
            $state['userProfile'] = [
                'birthYear' => 1984,
                'bodyWeightKg' => 82,
                'experienceLevel' => 'intermediate',
                'equipment' => [
                    'dumbbells' => true,
                    'barbell' => true,
                    'bench' => true,
                    'bodyweight' => true,
                    'pullup_bar' => false,
                    'bands' => false,
                ],
            ];
        }

        $this->importer->applyState($cycle, $state);
        $this->restoreHistory($state['cyclesHistory'] ?? [], $number);
    }

    private function restoreHistory(mixed $history, int $currentNumber): void
    {
        if (! is_array($history)) {
            return;
        }

        foreach ($history as $item) {
            if (! is_array($item)) {
                continue;
            }
            $number = (int) ($item['number'] ?? 0);
            if ($number < 1 || $number === $currentNumber) {
                continue;
            }

            $started = is_string($item['started_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $item['started_at'])
                ? substr($item['started_at'], 0, 10)
                : now()->toDateString();
            $completed = is_string($item['completed_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $item['completed_at'])
                ? substr($item['completed_at'], 0, 10)
                : now()->toDateString();

            Cycle::query()->create([
                'number' => $number,
                'is_current' => false,
                'total_weeks' => (int) config('ironforge.total_weeks', 7),
                'started_at' => $started,
                'completed_at' => $completed,
                'snapshot' => is_array($item['snapshot'] ?? null) ? $item['snapshot'] : null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $weeks
     */
    private function countCompleted(array $weeks): int
    {
        $n = 0;
        foreach ($weeks as $days) {
            if (! is_array($days)) {
                continue;
            }
            foreach ($days as $slots) {
                if (! is_array($slots)) {
                    continue;
                }
                foreach ($slots as $slot) {
                    foreach (($slot['sets'] ?? []) as $set) {
                        if (is_array($set) && ! empty($set['completed'])) {
                            $n++;
                        }
                    }
                }
            }
        }

        return $n;
    }
}
