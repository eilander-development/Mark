<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkoutSession;
use App\Models\WorkoutSlot;

class SlotAdvisor
{
    public function __construct(
        private readonly Periodization $periodization,
        private readonly PersonalRecord $records,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSlot(Cycle $cycle, WorkoutSession $session, WorkoutSlot $slot, float $increment, string $frequency): array
    {
        $catalog = $this->catalog->slot($slot->slot_key);
        $name = $slot->selected_name;
        $targetReps = (int) ($slot->target_reps ?: ($catalog['targetReps'] ?? 8));
        $isDeload = $this->periodization->isDeloadWeek((int) $session->week);
        $isBw = $this->periodization->isBodyweight($name);
        $currentLogged = $this->firstLoggedWeight($slot, $isBw);

        $sameWeek = $this->sameWeekMatch($cycle, $session, $name, $isBw);
        if ($sameWeek) {
            $weight = $isBw ? 0.0 : $sameWeek['weight'];

            return $this->payload(
                adviceType: 'same_week',
                advisedWeight: $weight,
                targetReps: $targetReps,
                prevMax: $weight,
                prevWeekFound: 'deze week ('.$sameWeek['dayName'].')',
                sameWeekDay: $sameWeek['dayName'],
                currentLoggedWeight: $currentLogged,
                isBodyweight: $isBw,
                increment: $increment,
                targetRpe: $this->periodization->targetRpe((int) $session->week),
            );
        }

        if ((int) $session->week === 1) {
            return $this->payload(
                adviceType: $currentLogged !== null ? 'inregel_logged' : 'inregel_baseline',
                advisedWeight: $currentLogged,
                targetReps: $targetReps,
                prevMax: null,
                prevWeekFound: null,
                sameWeekDay: null,
                currentLoggedWeight: $currentLogged,
                isBodyweight: $isBw,
                increment: $increment,
                targetRpe: $this->periodization->targetRpe(1),
            );
        }

        $prior = $this->previousPerformance($cycle, $session, $slot, $name, $isBw, $targetReps);
        $prevMax = $prior['max'];
        $prevOverload = $prior['overload'];
        $prevWeekFound = $prior['weekLabel'];
        $prevExertion = $prior['exertion'];

        if ($prevMax === null) {
            $record = $this->records->allTime($name);
            if ($record['maxWeight'] > 0 || $isBw) {
                $prevMax = $isBw ? 0.0 : $record['maxWeight'];
                $prevOverload = false;
                $prevWeekFound = 'vorige cyclus';
            }
        }

        $advised = null;
        $type = 'initial_no_data';

        $week = (int) $session->week;
        if ($isDeload) {
            $type = 'deload';
            $advised = ($prevMax !== null && ! $isBw)
                ? $this->periodization->advisedWeight($prevMax, $week, $increment, $frequency)
                : ($isBw ? 0.0 : null);
        } elseif ($prevMax !== null) {
            if ($isBw) {
                $hold = $this->periodization->isBiweeklyHoldWeek($week, $frequency);
                $type = ($prevOverload && ! $hold) ? 'overload' : 'repeat';
                $advised = 0.0;
            } else {
                $advised = $this->periodization->advisedWeight(
                    $prevMax,
                    $week,
                    $increment,
                    $frequency,
                    $prevExertion,
                    $prevOverload,
                );
                $type = ($advised !== null && $advised > $prevMax) ? 'overload' : 'repeat';
            }
        } elseif ($isBw) {
            $advised = 0.0;
        }

        return $this->payload(
            adviceType: $type,
            advisedWeight: $advised,
            targetReps: $targetReps,
            prevMax: $prevMax,
            prevWeekFound: $prevWeekFound,
            sameWeekDay: null,
            currentLoggedWeight: $currentLogged,
            isBodyweight: $isBw,
            increment: $increment,
            targetRpe: $this->periodization->targetRpe($week),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $adviceType,
        ?float $advisedWeight,
        int $targetReps,
        ?float $prevMax,
        ?string $prevWeekFound,
        ?string $sameWeekDay,
        ?float $currentLoggedWeight,
        bool $isBodyweight,
        float $increment,
        float $targetRpe = 8.0,
    ): array {
        return [
            'adviceType' => $adviceType,
            'advisedWeight' => $advisedWeight,
            'targetReps' => $targetReps,
            'prevMax' => $prevMax,
            'prevWeekFound' => $prevWeekFound,
            'sameWeekDay' => $sameWeekDay,
            'currentLoggedWeight' => $currentLoggedWeight,
            'isBodyweight' => $isBodyweight,
            'increment' => $increment,
            'targetRpe' => $targetRpe,
        ];
    }

    private function firstLoggedWeight(WorkoutSlot $slot, bool $isBw): ?float
    {
        foreach ($slot->sets as $set) {
            if ($set->weight === '' && ! $isBw) {
                continue;
            }
            $weight = (float) $set->weight;
            if ($isBw || $weight > 0) {
                return $isBw ? 0.0 : $weight;
            }
        }

        return null;
    }

    /**
     * @return array{weight: float, dayName: string}|null
     */
    private function sameWeekMatch(Cycle $cycle, WorkoutSession $session, string $name, bool $isBw): ?array
    {
        $days = config('ironforge.days');
        $currentIndex = array_search($session->day, $days, true);
        if ($currentIndex === false || $currentIndex === 0) {
            return null;
        }

        $earlier = array_slice($days, 0, (int) $currentIndex);
        $sessions = $cycle->sessions()
            ->where('week', $session->week)
            ->whereIn('day', $earlier)
            ->with('slots.sets')
            ->get();

        foreach ($sessions as $other) {
            foreach ($other->slots as $slot) {
                if ($slot->selected_name !== $name) {
                    continue;
                }
                foreach ($slot->sets as $set) {
                    if (! $set->completed) {
                        continue;
                    }
                    $weight = (float) $set->weight;
                    if ($isBw || $weight > 0) {
                        return [
                            'weight' => $isBw ? 0.0 : $weight,
                            'dayName' => $this->periodization->dayName($other->day),
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{max: ?float, overload: bool, weekLabel: ?string, exertion: string}
     */
    private function previousPerformance(
        Cycle $cycle,
        WorkoutSession $session,
        WorkoutSlot $slot,
        string $name,
        bool $isBw,
        int $targetReps,
    ): array {
        $sessions = $cycle->sessions()
            ->where('week', '<', $session->week)
            ->where('day', $session->day)
            ->orderByDesc('week')
            ->with('slots.sets')
            ->get();

        foreach ($sessions as $prior) {
            $match = $prior->slots->firstWhere('slot_key', $slot->slot_key);
            if (! $match || $match->selected_name !== $name) {
                continue;
            }
            $completed = $match->sets->filter(fn ($set) => $set->completed && ($isBw || (float) $set->weight > 0));
            if ($completed->isEmpty()) {
                continue;
            }
            $max = $isBw ? 0.0 : (float) $completed->max(fn ($set) => (float) $set->weight);
            $counts = $completed->countBy(fn ($set) => $set->exertion ?: 'good');
            $exertion = 'good';
            if (($counts['easy'] ?? 0) >= (int) ceil($completed->count() / 2)) {
                $exertion = 'easy';
            } elseif (($counts['max'] ?? 0) >= (int) ceil($completed->count() / 2)) {
                $exertion = 'max';
            }

            return [
                'max' => $max,
                'overload' => $this->periodization->targetAchieved($match->sets, $targetReps, (int) $prior->week, $isBw),
                'weekLabel' => 'Week '.$prior->week,
                'exertion' => $exertion,
            ];
        }

        return ['max' => null, 'overload' => false, 'weekLabel' => null, 'exertion' => 'good'];
    }
}
