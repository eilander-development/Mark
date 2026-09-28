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

        $range = app(TrainingPrescription::class)->rangeForSlot($slot, (int) ($this->catalog->slot($slot->slot_key)['targetReps'] ?? 8));
        $range = ['minReps' => (int) ($plan['minReps'] ?? $range['minReps']), 'maxReps' => (int) ($plan['maxReps'] ?? $range['maxReps'])];

        return $this->periodization->progress($slot->sets, $weight, $reps, $this->periodization->requiredSets($week), $isBw) + $range;
    }

    /** @return array<string, mixed> */
    public function exerciseProgress(Cycle $cycle, int $week, string $name, bool $includePrevious = true): array
    {
        $cycle->loadMissing('sessions.slots.sets');
        $isBw = $this->periodization->isBodyweight($name);
        $sessions = [];
        $sets = collect();
        $skippedSets = 0;
        foreach (config('ironforge.days') as $day) {
            $session = $cycle->sessions->first(fn ($session): bool => (int) $session->week === $week && $session->day === $day);
            $session?->setRelation('cycle', $cycle);
            foreach ($session?->slots ?? [] as $slot) {
                $slot->setRelation('session', $session);
                if (mb_strtolower(trim($slot->selected_name)) !== mb_strtolower(trim($name))) {
                    continue;
                }
                $progress = $this->slotProgress($slot, $week);
                if ($session->skipped) {
                    $skippedSets += max(0, $progress['requiredSets'] - $progress['completedSets']);
                }
                $sessions[] = ['maxMiss' => ! $progress['achieved'] && $progress['completedSets'] >= $progress['requiredSets'] && $slot->sets->take($progress['requiredSets'])->contains(fn ($set): bool => $set->completed && $set->exertion === 'max'), 'skipped' => (bool) $session->skipped, 'dayKey' => $day, 'slotKey' => $slot->slot_key, 'progress' => $progress];
                $sets = $sets->concat($slot->sets->take($progress['requiredSets'])->filter(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($isBw || (float) $set->weight > 0)));
            }
        }
        $progresses = collect($sessions)->pluck('progress');
        $achieved = $progresses->isNotEmpty() && $progresses->every(fn (array $item): bool => $item['achieved']);
        $counts = $sets->countBy(fn ($set): string => $set->exertion ?: 'unknown');
        $exertion = ($counts['max'] ?? 0) > 0 ? 'max' : (($counts['unknown'] ?? 0) > 0 ? 'unknown' : ($sets->isNotEmpty() && ($counts['easy'] ?? 0) >= ceil($sets->count() / 2) ? 'easy' : 'good'));
        $requiredReps = (int) $progresses->sum('requiredReps');

        $previous = $includePrevious && $week > 1 && ! $this->periodization->isDeloadWeek($week - 1)
            ? $this->exerciseProgress($cycle, $week - 1, $name, false) : null;
        $confirmedEffort = $previous && $previous['achieved'] && ! $previous['unknownSets'] && ! $previous['skippedSets']
            && $previous['maxSets'] <= 1 && $achieved && ($counts['max'] ?? 0) <= 1
            && (float) $previous['targetWeight'] >= (float) $sets->min(fn ($set): float => (float) $set->weight)
            && $previous['achievedReps'] >= (int) $progresses->max('targetReps');
        $maxMisses = collect($sessions)->where('maxMiss', true)->count();
        $repeatedMaxMisses = $maxMisses >= 2 || ($maxMisses > 0 && $previous && ! $previous['achieved']
            && $previous['completedSets'] >= $previous['requiredSets'] && ! $previous['skippedSets'] && $previous['maxSets'] > 0);

        return ['confirmedEffort' => (bool) $confirmedEffort, 'repeatedMaxMisses' => (bool) $repeatedMaxMisses, 'skippedSets' => $skippedSets, 'unknownSets' => (int) ($counts['unknown'] ?? 0), 'maxSets' => (int) ($counts['max'] ?? 0), 'sessions' => $sessions, 'achieved' => $achieved, 'exertion' => $exertion,
            'completedSets' => (int) $progresses->sum('completedSets'), 'achievedSets' => (int) $progresses->sum('achievedSets'),
            'requiredSets' => (int) $progresses->sum('requiredSets'), 'remainingReps' => (int) $progresses->sum('remainingReps'),
            'requiredReps' => $requiredReps, 'creditedReps' => (int) $progresses->sum('creditedReps'),
            'percent' => $requiredReps ? (int) floor(100 * $progresses->sum('creditedReps') / $requiredReps) : 0,
            'targetReps' => (int) ($progresses->max('targetReps') ?? 0),
            'minReps' => (int) ($progresses->max('minReps') ?? 8), 'maxReps' => (int) ($progresses->max('maxReps') ?? 12),
            'achievedReps' => $achieved ? (int) $sets->min(fn ($set): int => (int) $set->reps) : 0,
            'targetWeight' => $isBw ? 0.0 : (float) ($achieved && $sets->isNotEmpty() ? $sets->min(fn ($set): float => (float) $set->weight) : ($progresses->max('targetWeight') ?? 0)),
            'isBodyweight' => $isBw];
    }

    /** @return array<string, mixed> */
    public function forSlot(Cycle $cycle, WorkoutSession $session, WorkoutSlot $slot, float $increment, string $frequency): array
    {
        $cycle->loadMissing('sessions.slots.sets');
        $session->setRelation('cycle', $cycle);
        $slot->setRelation('session', $session);
        $week = (int) $session->week;
        $name = $slot->selected_name;
        $isBw = $this->periodization->isBodyweight($name);
        $days = config('ironforge.days');
        $currentDayIndex = array_search($session->day, $days, true);
        $previous = null;
        $reference = null;
        $referenceSlot = null;
        for ($priorWeek = $week; $priorWeek >= 1; $priorWeek--) {
            if ($priorWeek === $week && $this->periodization->isDeloadWeek($week) && $session->recovery && $session->recovery !== 'unknown') {
                continue;
            }
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
                        $referenceSlot = $priorSlot;
                        break 3;
                    }
                }
            }
        }
        $sameWeek = $previous && (int) $previous->week === $week;
        $prior = $previous ? $this->exerciseProgress($cycle, (int) $previous->week, $name) : null;
        if ($prior) {
            $prior['recovery'] = $session->recovery ?? 'unknown';
        }
        $next = $previous && ! $sameWeek ? $this->periodization->nextProgression($prior, $week, $increment, $frequency) : null;
        $current = $slot->sets->first(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($isBw || (float) $set->weight > 0));
        $plan = $slot->progression_plan;
        $weight = $isBw ? 0.0 : (float) (($plan['weight'] ?? 0) ?: ($sameWeek ? $reference->weight : ($next['weight'] ?? 0)) ?: $current?->weight ?: $slot->sets->first()?->weight ?: 0);
        $reps = (int) ($slot->target_reps ?: ($this->catalog->slot($slot->slot_key)['targetReps'] ?? 8));
        $type = $sameWeek ? 'same_week' : ($this->periodization->isDeloadWeek($week) ? 'deload' : (in_array($next['change'] ?? null, ['weight', 'reps'], true) ? 'overload' : ($previous ? 'repeat' : ($current ? 'inregel_logged' : 'inregel_baseline'))));

        $range = $this->slotProgress($slot, $week);
        $reason = $sameWeek ? 'Herhaal de prestatie van eerder deze week. Opbouw wordt na de week beoordeeld.' : ($next['reason'] ?? 'Leg eerst je startprestatie vast.');
        if ($plan && $next && ((float) $plan['weight'] !== (float) $next['weight'] || (int) $plan['reps'] !== (int) $next['reps'])) {
            $reason = 'Je volgt het bij de start vastgelegde doel. Je opgeslagen prestaties bepalen de volgende stap.';
        }

        return ['advisedWeight' => $isBw || $weight > 0 ? $weight : null, 'targetReps' => $reps,
            'advisedReps' => (int) (($plan['reps'] ?? null) ?: ($sameWeek ? ($referenceSlot?->progression_plan['reps'] ?? $referenceSlot?->target_reps ?? $reps) : ($next['reps'] ?? $reps))),
            'sameWeekReps' => $sameWeek ? (int) $reference->reps : null,
            'prevMax' => $prior['targetWeight'] ?? null, 'prevWeekFound' => $previous ? 'Week '.$previous->week : null,
            'prevOverload' => $prior['achieved'] ?? false, 'adviceType' => $type,
            'sameWeekDay' => $sameWeek ? $this->periodization->dayName($previous->day) : null,
            'currentLoggedWeight' => $current ? (float) $current->weight : null, 'isBodyweight' => $isBw,
            'minReps' => (int) ($plan['minReps'] ?? $prior['minReps'] ?? $range['minReps']),
            'maxReps' => (int) ($plan['maxReps'] ?? $prior['maxReps'] ?? $range['maxReps']),
            'adviceTitle' => $sameWeek ? 'Deze week herhalen' : ($next['status'] ?? 'Startdoel'),
            'adviceText' => $reason,
            'increment' => $increment, 'targetRpe' => $this->periodization->targetRpe($week)];
    }
}
