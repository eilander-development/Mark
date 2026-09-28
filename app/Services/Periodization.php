<?php

namespace App\Services;

class Periodization
{
    public function isDeloadWeek(int $weekNum): bool
    {
        return $weekNum > 0 && $weekNum % 7 === 0;
    }

    public function calculate1Rm(float|int|string|null $weight, float|int|string|null $reps): ?int
    {
        $w = (float) $weight;
        $r = (float) $reps;
        if ($w <= 0 || $r <= 0) {
            return null;
        }
        if ((int) $r === 1) {
            return (int) round($w);
        }

        return (int) round($w * (1 + $r / 30));
    }

    public function isBodyweight(string $exerciseName): bool
    {
        $n = mb_strtolower($exerciseName);

        return str_contains($n, 'push-up')
            || str_contains($n, 'pushup')
            || str_contains($n, 'opdrukken')
            || str_contains($n, 'pull-up')
            || str_contains($n, 'chin-up')
            || str_contains($n, 'optrekken')
            || str_contains($n, 'dip')
            || str_contains($n, 'lichaamsgewicht')
            || str_contains($n, 'bodyweight');
    }

    public function targetRpe(int $weekNum): float
    {
        if ($this->isDeloadWeek($weekNum)) {
            return 6.5;
        }

        return match (true) {
            $weekNum <= 1 => 7.0,
            $weekNum >= 6 => 9.0,
            default => 8.0,
        };
    }

    public function advisedWeight(
        ?float $previousMax,
        int $weekNum,
        float $increment,
        string $frequency,
        string $exertion = 'good',
        bool $hitTarget = true,
    ): ?float {
        if ($previousMax === null || $previousMax <= 0) {
            return null;
        }
        if ($weekNum <= 1) {
            return $previousMax;
        }

        return $this->nextProgression([
            'targetWeight' => $previousMax, 'targetReps' => 8, 'achieved' => $hitTarget,
            'completedSets' => 3, 'requiredSets' => 3, 'exertion' => $exertion, 'isBodyweight' => false,
        ], $weekNum, $increment, $frequency)['weight'];
    }

    public function isBiweeklyHoldWeek(int $weekNum, string $frequency): bool
    {
        return $frequency === 'biweekly' && $weekNum > 1 && $weekNum % 2 === 0;
    }

    public function lastHeavyWeek(int $totalWeeks): int
    {
        for ($week = $totalWeeks; $week >= 1; $week--) {
            if (! $this->isDeloadWeek($week)) {
                return $week;
            }
        }

        return 1;
    }

    public function roundToIncrement(float $weight, float $increment): float
    {
        if ($increment <= 0) {
            return round($weight, 1);
        }

        return round(round($weight / $increment) * $increment, 1);
    }

    public function requiredSets(int $weekNum): int
    {
        return $this->isDeloadWeek($weekNum) ? 2 : 3;
    }

    /**
     * @param  iterable<array{completed?: bool, weight?: mixed, reps?: mixed}|object>  $sets
     * @return array<string, mixed>
     */
    public function progress(iterable $sets, float $targetWeight, int $targetReps, int $requiredSets, bool $isBodyweight): array
    {
        $items = is_array($sets) ? array_values($sets) : iterator_to_array($sets, false);
        $details = [];
        for ($index = 0; $index < $requiredSets; $index++) {
            $set = $items[$index] ?? [];
            $value = fn (string $key): mixed => is_array($set) ? ($set[$key] ?? null) : ($set->$key ?? null);
            $completed = (bool) $value('completed') && (int) $value('reps') > 0 && ($isBodyweight || (float) $value('weight') > 0);
            $weightMet = $isBodyweight || ((float) $value('weight') > 0 && (float) $value('weight') >= $targetWeight);
            $credited = $completed && $weightMet ? min($targetReps, (int) $value('reps')) : 0;
            $details[] = ['completed' => $completed, 'weightMet' => $weightMet, 'creditedReps' => $credited,
                'missingReps' => $targetReps - $credited, 'achieved' => $completed && $weightMet && (int) $value('reps') >= $targetReps];
        }
        $achieved = count(array_filter($details, fn (array $set): bool => $set['achieved']));
        $credited = array_sum(array_column($details, 'creditedReps'));

        return ['details' => $details, 'completedSets' => count(array_filter($details, fn (array $set): bool => $set['completed'])),
            'achievedSets' => $achieved, 'requiredSets' => $requiredSets, 'creditedReps' => $credited,
            'requiredReps' => $requiredSets * $targetReps, 'remainingReps' => $requiredSets * $targetReps - $credited,
            'percent' => (int) floor(100 * $credited / max(1, $requiredSets * $targetReps)),
            'achieved' => $achieved === $requiredSets, 'targetWeight' => $targetWeight, 'targetReps' => $targetReps];
    }

    /**
     * @param  iterable<array{completed?: bool, weight?: mixed, reps?: mixed}|object>  $sets
     */
    public function targetAchieved(iterable $sets, int $targetReps, int $weekNum, bool $isBodyweight): bool
    {
        $items = is_array($sets) ? array_values($sets) : iterator_to_array($sets, false);
        $weight = 0.0;
        foreach ($items as $set) {
            $completed = is_array($set) ? ($set['completed'] ?? false) : $set->completed;
            $candidate = (float) (is_array($set) ? ($set['weight'] ?? 0) : $set->weight);
            if ($completed && $candidate > 0) {
                $weight = $candidate;
                break;
            }
        }

        return $this->progress($items, $weight, $targetReps, $this->requiredSets($weekNum), $isBodyweight)['achieved'];
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array{weight: float, reps: int, status: string, reason: string, requiredSets: int}
     */
    public function nextProgression(array $progress, int $nextWeek, float $increment, string $frequency): array
    {
        $weight = (float) $progress['targetWeight'];
        $reps = (int) $progress['targetReps'];
        $status = 'Herhalen';
        $reason = 'Doel nog niet volledig gehaald; herhaal het werkgewicht en de herhalingen.';
        if (! $progress['completedSets']) {
            $status = 'Nog niet uitgevoerd';
            $reason = 'Nog geen opgeslagen prestaties om een verhoging te adviseren.';
        } elseif ($this->isDeloadWeek($nextWeek)) {
            $status = 'Herstelweek';
            $weight = $progress['isBodyweight'] ? 0.0 : max($increment, $this->roundToIncrement($weight * 0.7, $increment));
            if ($progress['isBodyweight']) {
                $reps = max(6, (int) round($reps * 0.7));
            }
            $reason = 'Volgende week 2 herstelsets.';
        } elseif ($progress['achieved'] && $this->isBiweeklyHoldWeek($nextWeek, $frequency)) {
            $status = 'Consolideren';
            $reason = 'Doel gehaald; nog een week herhalen volgens je 2-weken schema.';
        } elseif ($progress['exertion'] === 'max') {
            $reason = 'Minstens een set was maximaal; eerst herhalen met controle.';
            if (! $progress['achieved'] && ! $progress['isBodyweight'] && $progress['completedSets'] === $progress['requiredSets']) {
                $weight = max(0.0, round($weight - $increment, 1));
                $status = 'Lichter herhalen';
                $reason = 'Alle sets uitgevoerd, doel gemist en maximale inspanning: een gewichtsstap terug.';
            }
        } elseif ($progress['achieved']) {
            $status = 'Verhogen';
            if ($progress['isBodyweight']) {
                $reps += $progress['exertion'] === 'easy' ? 3 : 2;
            } else {
                $weight = round($weight + $increment * ($progress['exertion'] === 'easy' ? 2 : 1), 1);
            }
            $reason = 'Alle geplande sets op het werkgewicht gehaald'.($progress['exertion'] === 'easy' ? ' en vlot uitgevoerd.' : '.');
        }

        return ['weight' => $weight, 'reps' => $reps, 'status' => $status, 'reason' => $reason, 'requiredSets' => $this->requiredSets($nextWeek)];
    }

    public function dayName(string $day): string
    {
        return match ($day) {
            'mon' => 'Maandag',
            'tue' => 'Dinsdag',
            'thu' => 'Donderdag',
            'fri' => 'Vrijdag',
            default => $day,
        };
    }

    public function dayShort(string $day): string
    {
        return match ($day) {
            'mon' => 'MA',
            'tue' => 'DI',
            'thu' => 'DO',
            'fri' => 'VR',
            default => strtoupper($day),
        };
    }
}
