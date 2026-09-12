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
        if ($this->isDeloadWeek($weekNum)) {
            return $this->roundToIncrement($previousMax * 0.70, $increment);
        }
        if ($weekNum <= 1) {
            return $previousMax;
        }
        if (! $hitTarget) {
            return $exertion === 'max'
                ? max(0.0, round($previousMax - $increment, 1))
                : $previousMax;
        }
        if ($exertion === 'max') {
            return $previousMax;
        }
        if ($this->isBiweeklyHoldWeek($weekNum, $frequency)) {
            return $previousMax;
        }
        if ($exertion === 'easy') {
            return round($previousMax + (2 * $increment), 1);
        }

        return round($previousMax + $increment, 1);
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
     * @param  iterable<int, object{completed?: bool, weight?: mixed, reps?: mixed}>  $sets
     */
    public function targetAchieved(iterable $sets, int $targetReps, int $weekNum, bool $isBodyweight): bool
    {
        $needed = $this->requiredSets($weekNum);
        $ok = 0;
        $index = 0;
        foreach ($sets as $set) {
            $index++;
            if ($index > $needed) {
                break;
            }
            $completed = (bool) (is_array($set) ? ($set['completed'] ?? false) : $set->completed);
            $reps = (int) (is_array($set) ? ($set['reps'] ?? 0) : $set->reps);
            $weight = (float) (is_array($set) ? ($set['weight'] ?? 0) : $set->weight);
            if ($completed && $reps >= $targetReps && ($isBodyweight || $weight > 0)) {
                $ok++;
            }
        }

        return $ok >= $needed;
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
