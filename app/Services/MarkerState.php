<?php

namespace App\Services;

use App\Models\Cycle;
use Illuminate\Support\Facades\DB;

class MarkerState
{
    public function __construct(
        private readonly CycleFactory $factory,
        private readonly ValTownImporter $importer,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @return array{appState: array<string, mixed>}
     */
    public function export(): array
    {
        $cycle = $this->factory->ensureCurrent();
        $prefs = $this->factory->preferences();
        $profile = $this->factory->profile();
        $cycle->load(['sessions.slots.sets']);

        $weeks = [];
        foreach ($cycle->sessions as $session) {
            $week = (int) $session->week;
            $weeks[$week] ??= [];
            $daySlots = [];
            foreach ($session->slots as $slot) {
                $daySlots[$slot->slot_key] = [
                    'id' => $slot->id,
                    'sessionId' => $session->id,
                    'selectedName' => $slot->selected_name,
                    'note' => (string) $slot->note,
                    'targetReps' => $slot->target_reps,
                    'sets' => $slot->sets->map(fn ($set) => [
                        'id' => $set->id,
                        'weight' => $set->weight ?? '',
                        'reps' => $set->reps ?? '',
                        'completed' => (bool) $set->completed,
                        'exertion' => $set->exertion ?: 'good',
                    ])->values()->all(),
                ];
            }
            if ($session->actual_duration !== null) {
                $daySlots['actualDuration'] = (int) $session->actual_duration;
            }
            if ($session->actual_avg_rest !== null) {
                $daySlots['actualAvgRest'] = (int) $session->actual_avg_rest;
            }
            $weeks[$week][$session->day] = $daySlots;
        }
        ksort($weeks, SORT_NUMERIC);

        $history = Cycle::query()
            ->where('is_current', false)
            ->orderByDesc('number')
            ->get()
            ->map(fn (Cycle $item) => [
                'id' => $item->id,
                'number' => $item->number,
                'started_at' => optional($item->started_at)?->toDateString(),
                'completed_at' => optional($item->completed_at)?->toDateString(),
                'snapshot' => $item->snapshot,
            ])
            ->all();

        return [
            'appState' => [
                'currentCycle' => (int) $cycle->number,
                'cyclesHistory' => $history,
                'cycleStartedAt' => optional($cycle->started_at)?->toDateString() ?? now()->toDateString(),
                'currentWeek' => (int) $prefs->current_week,
                'totalWeeks' => (int) $cycle->total_weeks,
                'currentDay' => $prefs->current_day,
                'soundEnabled' => (bool) $prefs->sound_enabled,
                'overloadIncrement' => (float) $prefs->overload_increment,
                'overloadFrequency' => $prefs->overload_frequency,
                'preferredRestTimes' => $prefs->preferred_rest_times ?? [],
                'customExerciseVideos' => $prefs->custom_exercise_videos ?? [],
                'showLiveVideoPanel' => (bool) $prefs->show_live_video_panel,
                'routineLocked' => (bool) $prefs->routine_locked,
                'userProfile' => [
                    'birthYear' => (int) $profile->birth_year,
                    'bodyWeightKg' => (int) $profile->body_weight_kg,
                    'experienceLevel' => $profile->experience_level,
                    'equipment' => $profile->equipment,
                ],
                'weeks' => $weeks,
                'exerciseVideos' => $this->catalog->videoLibrary(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{appState: array<string, mixed>}
     */
    public function persist(array $state): array
    {
        $cycle = $this->factory->ensureCurrent();
        DB::transaction(fn () => $this->importer->applyState($cycle, $state));

        return $this->export();
    }
}
