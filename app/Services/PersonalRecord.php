<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkoutSet;

class PersonalRecord
{
    public function __construct(private readonly Periodization $periodization) {}

    /**
     * @return array{max1RM: float, maxWeight: float, maxReps: int}
     */
    public function allTime(string $exerciseName, ?int $exceptSetId = null): array
    {
        $max1Rm = 0.0;
        $maxWeight = 0.0;
        $maxReps = 0;
        $isBw = $this->periodization->isBodyweight($exerciseName);

        $sets = WorkoutSet::query()
            ->where('completed', true)
            ->when($exceptSetId, fn ($q) => $q->where('id', '!=', $exceptSetId))
            ->whereHas('slot', fn ($q) => $q->where('selected_name', $exerciseName))
            ->with('slot')
            ->get();

        $archived = Cycle::query()->where('is_current', false)->doesntHave('sessions')->get(['snapshot']);
        foreach ($archived as $cycle) {
            $weeks = $cycle->snapshot['weeksSnapshot'] ?? $cycle->snapshot['weeks'] ?? [];
            foreach ($weeks as $days) {
                foreach (config('ironforge.days') as $day) {
                    foreach ($days[$day] ?? [] as $slot) {
                        if (! is_array($slot) || mb_strtolower(trim($slot['selectedName'] ?? '')) !== mb_strtolower(trim($exerciseName))) {
                            continue;
                        }
                        foreach ($slot['sets'] ?? [] as $set) {
                            if ($set['completed'] ?? false) {
                                $sets->push((object) $set);
                            }
                        }
                    }
                }
            }
        }

        foreach ($sets as $set) {
            $reps = (int) ($set->reps ?? 0);
            if ($reps <= 0 || (! $isBw && (float) ($set->weight ?? 0) <= 0)) {
                continue;
            }
            if ($reps > $maxReps) {
                $maxReps = $reps;
            }
            $weight = (float) ($set->weight ?? 0);
            if (! $isBw && $weight > 0) {
                if ($weight > $maxWeight) {
                    $maxWeight = $weight;
                }
                $est = $this->periodization->calculate1Rm($weight, $reps);
                if ($est !== null && $est > $max1Rm) {
                    $max1Rm = (float) $est;
                }
            }
        }

        return [
            'max1RM' => $max1Rm,
            'maxWeight' => $maxWeight,
            'maxReps' => $maxReps,
        ];
    }

    public function isNewPr(string $exerciseName, string $weight, string $reps, int $setId): bool
    {
        $record = $this->allTime($exerciseName, $setId);
        if ($this->periodization->isBodyweight($exerciseName)) {
            $cur = (int) $reps;

            return $cur > 0 && $cur > $record['maxReps'];
        }
        $w = (float) $weight;
        $r = (int) $reps;
        if ($w <= 0 || $r <= 0) {
            return false;
        }

        return $w > $record['maxWeight'] || ($this->periodization->calculate1Rm($w, $r) ?? 0) > $record['max1RM'];
    }

    public function previousMaxWeight(Cycle $cycle, string $exerciseName, int $beforeWeek): ?float
    {
        $max = null;
        $sessions = $cycle->sessions()->where('week', '<', $beforeWeek)->with('slots.sets')->get();
        foreach ($sessions as $session) {
            foreach ($session->slots as $slot) {
                if ($slot->selected_name !== $exerciseName) {
                    continue;
                }
                foreach ($slot->sets as $set) {
                    if (! $set->completed) {
                        continue;
                    }
                    $w = (float) $set->weight;
                    if ($w > 0 && ($max === null || $w > $max)) {
                        $max = $w;
                    }
                }
            }
        }

        return $max;
    }
}
