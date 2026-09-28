<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkoutSession;
use App\Models\WorkoutSlot;

class SlotAdvisor
{
    public function __construct(
        private readonly Periodization $periodization,
        private readonly Catalog $catalog,
    ) {}

    /** @return array<string, mixed> */
    public function slotProgress(WorkoutSlot $slot, int $week): array
    {
        $isBw = $this->periodization->isBodyweight($slot->selected_name);
        $first = $slot->sets->first(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($isBw || (float) $set->weight > 0));
        $planned = $slot->sets->first(fn ($set): bool => (float) $set->weight > 0);
        $plan = $slot->progression_plan;
        $weight = $isBw ? 0.0 : (float) ($plan['weight'] ?? $first?->weight ?? $planned?->weight ?? 0);
        $reps = (int) ($plan['reps'] ?? $slot->target_reps ?: ($this->catalog->slot($slot->slot_key)['targetReps'] ?? 8));

        return $this->periodization->progress($slot->sets, $weight, $reps, $this->periodization->requiredSets($week), $isBw);
    }

    /** @return array<string, mixed> */
    public function exerciseProgress(Cycle $cycle, int $week, string $name): array
    {
        $cycle->loadMissing('sessions.slots.sets');
        $isBw = $this->periodization->isBodyweight($name);
        $sessions = [];
        $sets = collect();
        foreach (config('ironforge.days') as $day) {
            $session = $cycle->sessions->first(fn ($session): bool => (int) $session->week === $week && $session->day === $day);
            foreach ($session?->slots ?? [] as $slot) {
                if (mb_strtolower(trim($slot->selected_name)) !== mb_strtolower(trim($name))) {
                    continue;
                }
                $progress = $this->slotProgress($slot, $week);
                $sessions[] = ['dayKey' => $day, 'slotKey' => $slot->slot_key, 'progress' => $progress];
                $sets = $sets->concat($slot->sets->take($progress['requiredSets'])->filter(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($isBw || (float) $set->weight > 0)));
            }
        }
        $progresses = collect($sessions)->pluck('progress');
        $achieved = $progresses->isNotEmpty() && $progresses->every(fn (array $item): bool => $item['achieved']);
        $counts = $sets->countBy(fn ($set): string => $set->exertion ?: 'good');
        $exertion = ($counts['max'] ?? 0) > 0 ? 'max' : ($sets->isNotEmpty() && ($counts['easy'] ?? 0) >= ceil($sets->count() / 2) ? 'easy' : 'good');
        $requiredReps = (int) $progresses->sum('requiredReps');

        return ['sessions' => $sessions, 'achieved' => $achieved, 'exertion' => $exertion,
            'completedSets' => (int) $progresses->sum('completedSets'), 'achievedSets' => (int) $progresses->sum('achievedSets'),
            'requiredSets' => (int) $progresses->sum('requiredSets'), 'remainingReps' => (int) $progresses->sum('remainingReps'),
            'requiredReps' => $requiredReps, 'creditedReps' => (int) $progresses->sum('creditedReps'),
            'percent' => $requiredReps ? (int) floor(100 * $progresses->sum('creditedReps') / $requiredReps) : 0,
            'targetReps' => (int) ($progresses->max('targetReps') ?? 0),
            'targetWeight' => $isBw ? 0.0 : (float) ($achieved && $sets->isNotEmpty() ? $sets->min(fn ($set): float => (float) $set->weight) : ($progresses->max('targetWeight') ?? 0)),
            'isBodyweight' => $isBw];
    }

    /** @return array<string, mixed> */
    public function forSlot(Cycle $cycle, WorkoutSession $session, WorkoutSlot $slot, float $increment, string $frequency): array
    {
        $cycle->loadMissing('sessions.slots.sets');
        $week = (int) $session->week;
        $name = $slot->selected_name;
        $isBw = $this->periodization->isBodyweight($name);
        $days = config('ironforge.days');
        $currentDayIndex = array_search($session->day, $days, true);
        $previous = null;
        $reference = null;
        for ($priorWeek = $week; $priorWeek >= 1; $priorWeek--) {
            $earlierDays = array_reverse(array_slice($days, 0, $priorWeek === $week ? $currentDayIndex : count($days)));
            foreach ($earlierDays as $day) {
                $candidate = $cycle->sessions->first(fn ($item): bool => (int) $item->week === $priorWeek && $item->day === $day);
                foreach ($candidate?->slots ?? [] as $priorSlot) {
                    if (mb_strtolower(trim($priorSlot->selected_name)) !== mb_strtolower(trim($name))) {
                        continue;
                    }
                    $reference = $priorSlot->sets->take($this->periodization->requiredSets($priorWeek))->first(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($isBw || (float) $set->weight > 0));
                    if ($reference) {
                        $previous = $candidate;
                        break 3;
                    }
                }
            }
        }
        $sameWeek = $previous && (int) $previous->week === $week;
        $prior = $previous ? $this->exerciseProgress($cycle, (int) $previous->week, $name) : null;
        $next = $previous && ! $sameWeek ? $this->periodization->nextProgression($prior, $week, $increment, $frequency) : null;
        $current = $slot->sets->first(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($isBw || (float) $set->weight > 0));
        $plan = $slot->progression_plan;
        $weight = $isBw ? 0.0 : (float) (($plan['weight'] ?? 0) ?: ($sameWeek ? $reference->weight : ($next['weight'] ?? 0)) ?: $current?->weight ?: $slot->sets->first()?->weight ?: 0);
        $reps = (int) ($slot->target_reps ?: ($this->catalog->slot($slot->slot_key)['targetReps'] ?? 8));
        $type = $sameWeek ? 'same_week' : ($this->periodization->isDeloadWeek($week) ? 'deload' : (($next['status'] ?? null) === 'Verhogen' ? 'overload' : ($previous ? 'repeat' : ($current ? 'inregel_logged' : 'inregel_baseline'))));

        return ['advisedWeight' => $isBw || $weight > 0 ? $weight : null, 'targetReps' => $reps,
            'advisedReps' => (int) (($plan['reps'] ?? null) ?: ($sameWeek ? $reps : ($next['reps'] ?? $reps))),
            'sameWeekReps' => $sameWeek ? (int) $reference->reps : null,
            'prevMax' => $prior['targetWeight'] ?? null, 'prevWeekFound' => $previous ? 'Week '.$previous->week : null,
            'prevOverload' => $prior['achieved'] ?? false, 'adviceType' => $type,
            'sameWeekDay' => $sameWeek ? $this->periodization->dayName($previous->day) : null,
            'currentLoggedWeight' => $current ? (float) $current->weight : null, 'isBodyweight' => $isBw,
            'increment' => $increment, 'targetRpe' => $this->periodization->targetRpe($week)];
    }
}
