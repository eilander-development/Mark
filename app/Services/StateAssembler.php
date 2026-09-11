<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkoutSet;

class StateAssembler
{
    public function __construct(
        private readonly CycleFactory $factory,
        private readonly Periodization $periodization,
        private readonly PersonalRecord $records,
        private readonly SlotAdvisor $advisor,
        private readonly Catalog $catalog,
    ) {}

    public function payload(): array
    {
        $cycle = $this->factory->ensureCurrent();
        $prefs = $this->factory->preferences();
        $profile = $this->factory->profile();
        $cycle->load(['sessions.slots.sets']);

        $weeks = [];
        foreach ($cycle->sessions as $session) {
            $week = (string) $session->week;
            $weeks[$week] ??= [];
            $slots = [];
            foreach ($session->slots as $slot) {
                $record = $this->records->allTime($slot->selected_name);
                $advice = $this->advisor->forSlot(
                    $cycle,
                    $session,
                    $slot,
                    (float) $prefs->overload_increment,
                    (string) $prefs->overload_frequency,
                );
                $isBw = $this->periodization->isBodyweight($slot->selected_name);
                $targetReps = (int) ($slot->target_reps ?: ($advice['targetReps'] ?? 8));
                $slots[] = [
                    'id' => $slot->id,
                    'slotKey' => $slot->slot_key,
                    'selectedName' => $slot->selected_name,
                    'note' => $slot->note,
                    'targetReps' => $targetReps,
                    'catalog' => $this->catalog->slot($slot->slot_key),
                    'advisedWeight' => $advice['advisedWeight'],
                    'advice' => $advice,
                    'record' => $record,
                    'isBodyweight' => $isBw,
                    'isTargetAchieved' => $this->periodization->targetAchieved(
                        $slot->sets,
                        $targetReps,
                        (int) $session->week,
                        $isBw,
                    ),
                    'sets' => $slot->sets->map(fn (WorkoutSet $set) => [
                        'id' => $set->id,
                        'position' => $set->position,
                        'weight' => $set->weight,
                        'reps' => $set->reps,
                        'completed' => $set->completed,
                        'isPr' => $set->is_pr,
                        'exertion' => $set->exertion ?: 'good',
                        'estimated1Rm' => $this->periodization->calculate1Rm($set->weight, $set->reps),
                    ])->values(),
                ];
            }
            $weeks[$week][$session->day] = [
                'id' => $session->id,
                'title' => config('ironforge.splits.'.$session->day.'.title'),
                'duration' => $session->actual_duration,
                'avgRest' => $session->actual_avg_rest,
                'dayName' => $this->periodization->dayName($session->day),
                'dayShort' => $this->periodization->dayShort($session->day),
                'slots' => $slots,
            ];
        }

        ksort($weeks, SORT_NUMERIC);

        $history = Cycle::query()
            ->where('is_current', false)
            ->orderByDesc('number')
            ->get(['id', 'number', 'started_at', 'completed_at', 'snapshot']);

        return [
            'currentWeek' => (int) $prefs->current_week,
            'currentDay' => $prefs->current_day,
            'totalWeeks' => (int) $cycle->total_weeks,
            'currentCycle' => (int) $cycle->number,
            'cycleId' => $cycle->id,
            'cycleStartedAt' => optional($cycle->started_at)?->toDateString(),
            'routineLocked' => (bool) $prefs->routine_locked,
            'soundEnabled' => (bool) $prefs->sound_enabled,
            'overloadIncrement' => (float) $prefs->overload_increment,
            'overloadFrequency' => $prefs->overload_frequency,
            'profile' => [
                'birthYear' => $profile->birth_year,
                'bodyWeightKg' => $profile->body_weight_kg,
                'experienceLevel' => $profile->experience_level,
                'equipment' => $profile->equipment,
            ],
            'weeks' => $weeks,
            'splits' => $this->catalog->splits(),
            'catalog' => $this->catalog->allSlots(),
            'days' => config('ironforge.days'),
            'cyclesHistory' => $history,
            'deloadWeeks' => collect(range(1, $cycle->total_weeks))
                ->filter(fn ($w) => $this->periodization->isDeloadWeek($w))
                ->values(),
        ];
    }

    public function weekReport(int $week): array
    {
        $cycle = $this->factory->ensureCurrent();
        $sessions = $cycle->sessions()->where('week', $week)->with('slots.sets')->get();
        $volume = 0.0;
        $completedSets = 0;
        $totalSets = 0;
        $exercises = [];

        foreach ($sessions as $session) {
            foreach ($session->slots as $slot) {
                $ex = $slot->selected_name;
                $exercises[$ex] ??= ['name' => $ex, 'sets' => 0, 'volume' => 0.0, 'maxWeight' => 0.0];
                foreach ($slot->sets as $set) {
                    $totalSets++;
                    if (! $set->completed) {
                        continue;
                    }
                    $completedSets++;
                    $w = (float) $set->weight;
                    $r = (int) $set->reps;
                    $vol = $w * $r;
                    $volume += $vol;
                    $exercises[$ex]['sets']++;
                    $exercises[$ex]['volume'] += $vol;
                    if ($w > $exercises[$ex]['maxWeight']) {
                        $exercises[$ex]['maxWeight'] = $w;
                    }
                }
            }
        }

        return [
            'week' => $week,
            'isDeload' => $this->periodization->isDeloadWeek($week),
            'volume' => round($volume, 1),
            'completedSets' => $completedSets,
            'totalSets' => $totalSets,
            'exercises' => array_values($exercises),
        ];
    }
}
