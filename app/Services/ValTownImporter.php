<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ValTownImporter
{
    public function __construct(
        private readonly CycleFactory $factory,
    ) {}

    /**
     * @return array{updatedAt: ?string, completedSets: int, weeks: int, preview: bool, imported: bool}
     */
    public function preview(): array
    {
        $payload = $this->fetch();
        $state = $payload['appState'] ?? [];
        $weeks = is_array($state['weeks'] ?? null) ? $state['weeks'] : [];
        $completedSets = $this->countCompleted($weeks);

        return [
            'updatedAt' => $payload['updatedAt'] ?? null,
            'completedSets' => $completedSets,
            'weeks' => count($weeks),
            'currentWeek' => $state['currentWeek'] ?? 1,
            'cycle' => $state['currentCycle'] ?? 1,
            'mysqlCompletedSets' => $this->mysqlCompletedCount(),
            'preview' => true,
            'imported' => false,
        ];
    }

    /**
     * @return array{updatedAt: ?string, completedSets: int, weeks: int, preview: bool, imported: bool}
     */
    public function import(bool $replace = true): array
    {
        $payload = $this->fetch();
        $state = $payload['appState'] ?? [];
        if (! is_array($state['weeks'] ?? null)) {
            throw new RuntimeException('Val Town-dump heeft geen weeks.');
        }

        $weeks = $state['weeks'];
        $incomingCompleted = $this->countCompleted($weeks);
        if ($incomingCompleted === 0) {
            throw new RuntimeException('Val-dump heeft geen voltooide sets. Bestaande training blijft staan.');
        }

        $cycle = $this->factory->ensureCurrent();
        if ($replace) {
            $this->applyState($cycle, $state);
        }

        return [
            'updatedAt' => $payload['updatedAt'] ?? null,
            'completedSets' => $incomingCompleted,
            'weeks' => count($weeks),
            'currentWeek' => $state['currentWeek'] ?? 1,
            'cycle' => $state['currentCycle'] ?? 1,
            'preview' => false,
            'imported' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        $url = rtrim((string) config('ironforge.val_town_url'), '/').'/';
        $response = Http::acceptJson()->timeout(20)->get($url);
        if (! $response->ok()) {
            throw new RuntimeException('Val Town GET faalde: HTTP '.$response->status());
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Val Town gaf geen JSON terug.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function applyState(Cycle $cycle, array $state): void
    {
        DB::transaction(fn () => $this->writeState($cycle, $state));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(Cycle $cycle, array $state): void
    {
        $prefs = $this->factory->preferences();
        $prefs->fill([
            'current_week' => min(7, max(1, (int) ($state['currentWeek'] ?? 1))),
            'current_day' => in_array($state['currentDay'] ?? 'mon', config('ironforge.days'), true)
                ? ($state['currentDay'] ?? 'mon')
                : 'mon',
            'sound_enabled' => (bool) ($state['soundEnabled'] ?? true),
            'routine_locked' => (bool) ($state['routineLocked'] ?? true),
            'show_live_video_panel' => (bool) ($state['showLiveVideoPanel'] ?? true),
            'overload_increment' => (float) ($state['overloadIncrement'] ?? 2),
            'overload_frequency' => (string) ($state['overloadFrequency'] ?? 'weekly'),
            'preferred_rest_times' => $state['preferredRestTimes'] ?? [],
            'custom_exercise_videos' => $state['customExerciseVideos'] ?? [],
        ]);
        $prefs->save();

        if (isset($state['userProfile']) && is_array($state['userProfile'])) {
            $profile = $this->factory->profile();
            $profile->fill([
                'birth_year' => (int) ($state['userProfile']['birthYear'] ?? 1984),
                'body_weight_kg' => (int) ($state['userProfile']['bodyWeightKg'] ?? 82),
                'experience_level' => (string) ($state['userProfile']['experienceLevel'] ?? 'intermediate'),
                'equipment' => $state['userProfile']['equipment'] ?? $profile->equipment,
            ]);
            $profile->save();
        }

        $cycle->number = (int) ($state['currentCycle'] ?? $cycle->number);
        if (! empty($state['cycleStartedAt']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $state['cycleStartedAt'])) {
            $cycle->started_at = substr((string) $state['cycleStartedAt'], 0, 10);
        }
        $cycle->save();

        $this->factory->ensureSkeleton($cycle);
        $cycle->unsetRelation('sessions');
        $cycle->load(['sessions.slots.sets']);
        $this->resetCycleSets($cycle);
        $cycle->unsetRelation('sessions');
        $cycle->load(['sessions.slots.sets']);

        $setRows = [];
        $now = now();

        foreach ($state['weeks'] as $weekNum => $days) {
            $week = (int) $weekNum;
            if ($week < 1 || $week > $cycle->total_weeks || ! is_array($days)) {
                continue;
            }
            foreach ($days as $day => $slots) {
                if (! in_array($day, config('ironforge.days'), true) || ! is_array($slots)) {
                    continue;
                }
                $session = $cycle->sessions->first(
                    fn (WorkoutSession $item) => (int) $item->week === $week && $item->day === $day
                );
                if (! $session) {
                    continue;
                }
                foreach ($slots as $slotKey => $slotData) {
                    if (! str_starts_with((string) $slotKey, 'slot_') || ! is_array($slotData)) {
                        continue;
                    }
                    $slot = $session->slots->firstWhere('slot_key', $slotKey);
                    if (! $slot) {
                        continue;
                    }
                    $slot->selected_name = (string) ($slotData['selectedName'] ?? $slot->selected_name);
                    $slot->note = (string) ($slotData['note'] ?? $slot->note);
                    if (isset($slotData['targetReps'])) {
                        $slot->target_reps = (int) $slotData['targetReps'];
                    }
                    $slot->save();
                    $sets = is_array($slotData['sets'] ?? null) ? $slotData['sets'] : [];
                    foreach ($sets as $index => $setData) {
                        if (! is_array($setData)) {
                            continue;
                        }
                        $set = $slot->sets->firstWhere('position', $index + 1);
                        if (! $set) {
                            continue;
                        }
                        $exertion = $setData['exertion'] ?? 'good';
                        $setRows[] = [
                            'id' => $set->id,
                            'workout_slot_id' => $set->workout_slot_id,
                            'position' => $set->position,
                            'weight' => (string) ($setData['weight'] ?? ''),
                            'reps' => (string) ($setData['reps'] ?? ''),
                            'completed' => (bool) ($setData['completed'] ?? false),
                            'is_pr' => (bool) $set->is_pr,
                            'exertion' => in_array($exertion, ['easy', 'good', 'max'], true) ? $exertion : 'good',
                            'created_at' => $set->created_at ?? $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if (array_key_exists('actualDuration', $slots)) {
                    $session->actual_duration = $slots['actualDuration'] !== null && $slots['actualDuration'] !== ''
                        ? (int) $slots['actualDuration']
                        : null;
                }
                if (array_key_exists('actualAvgRest', $slots)) {
                    $session->actual_avg_rest = $slots['actualAvgRest'] !== null && $slots['actualAvgRest'] !== ''
                        ? (int) $slots['actualAvgRest']
                        : null;
                }
                $session->save();
            }
        }

        if ($setRows !== []) {
            WorkoutSet::query()->upsert(
                $setRows,
                ['id'],
                ['weight', 'reps', 'completed', 'exertion', 'updated_at'],
            );
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
                        if (! empty($set['completed'])) {
                            $n++;
                        }
                    }
                }
            }
        }

        return $n;
    }

    private function mysqlCompletedCount(): int
    {
        return (int) WorkoutSet::query()->where('completed', true)->count();
    }

    private function resetCycleSets(Cycle $cycle): void
    {
        $slotIds = $cycle->sessions
            ->flatMap(fn (WorkoutSession $session) => $session->slots->pluck('id'))
            ->all();

        foreach ($cycle->sessions as $session) {
            $session->actual_duration = null;
            $session->actual_avg_rest = null;
            $session->save();
        }

        if ($slotIds !== []) {
            WorkoutSet::query()->whereIn('workout_slot_id', $slotIds)->update([
                'weight' => '',
                'reps' => '',
                'completed' => false,
                'exertion' => 'good',
            ]);
        }
    }
}
