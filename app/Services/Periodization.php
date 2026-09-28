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

    /** @return array{minReps: int, maxReps: int} */
    public function repetitionRange(int $baseReps): array
    {
        return ['minReps' => $baseReps >= 12 ? 12 : 8, 'maxReps' => $baseReps >= 12 ? 15 : 12];
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array{factor: float, reason: string}
     */
    public function recoveryLoad(array $progress): array
    {
        $recovery = $progress['recovery'] ?? 'unknown';
        $completed = (int) ($progress['completedSets'] ?? 0);
        $maximal = (int) ($progress['maxSets'] ?? 0);
        if ($recovery === 'exhausted' || ($completed >= 3 && $maximal >= ceil($completed / 2) && ! $progress['achieved'])) {
            return ['factor' => 0.6, 'reason' => $recovery === 'exhausted' ? 'Veel vermoeidheid gemeld: extra ontlasting.' : 'Doelen gemist en minstens de helft van de opgeslagen sets maximaal: extra ontlasting.'];
        }
        $complete = $completed > 0 && $completed >= $progress['requiredSets'] && ! ($progress['skippedSets'] ?? 0);
        if ($recovery === 'recovered' && $complete && $progress['achieved'] && ! ($progress['unknownSets'] ?? 0) && in_array($progress['exertion'] ?? 'unknown', ['easy', 'good'], true)) {
            return ['factor' => 0.8, 'reason' => 'Goed hersteld gemeld en alle referentiesets met controle gehaald.'];
        }

        return ['factor' => 0.7, 'reason' => $recovery === 'tired' ? 'Nog vermoeid gemeld: standaard ontlasting.' : 'Onvoldoende bevestiging voor een lichtere deload: standaard ontlasting.'];
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array{weight: float, reps: int, minReps: int, maxReps: int, status: string, reason: string, requiredSets: int, provisional: bool, change: string}
     */
    public function nextProgression(array $progress, int $nextWeek, float $increment, string $frequency): array
    {
        $increment = $increment > 0 ? $increment : 2.0;
        $weight = (float) $progress['targetWeight'];
        $reps = (int) $progress['targetReps'];
        $minReps = (int) ($progress['minReps'] ?? 8);
        $maxReps = max($minReps, (int) ($progress['maxReps'] ?? 12));
        $skippedSets = (int) ($progress['skippedSets'] ?? 0);
        $provisional = $progress['requiredSets'] > $progress['completedSets'] + $skippedSets;
        $status = 'Herhalen';
        $change = 'repeat';
        $reason = 'Alle sets uitgevoerd, maar het doel nog niet gehaald. Bouw eerst de ontbrekende herhalingen op.';
        if ($skippedSets > 0 && ! $progress['completedSets']) {
            $reason = 'Training overgeslagen; geen nieuwe prestaties om een verhoging te adviseren.';
        } elseif (! $progress['completedSets']) {
            $status = 'Nog te beoordelen';
            $reason = 'Deze week nog geen sets opgeslagen. Het advies voor volgende week staat nog niet vast.';
        } elseif ($this->isDeloadWeek($nextWeek)) {
            $status = 'Herstelweek';
            $change = 'deload';
            $recoveryLoad = $this->recoveryLoad($progress);
            $factor = $recoveryLoad['factor'];
            if ($progress['isBodyweight']) {
                $reps = max(1, (int) round($reps * $factor));
            } else {
                $lighter = $this->roundToIncrement($weight * $factor, $increment);
                if ($lighter > 0 && $lighter < $weight) {
                    $weight = $lighter;
                } else {
                    $reps = max(1, (int) round($reps * $factor));
                }
            }
            $reason = '2 herstelsets op circa '.(int) round($factor * 100).'% van de referentiebelasting. '.$recoveryLoad['reason'].' Afgerond op gewichtstappen; als lager gewicht niet kan, minder reps.';
        } elseif ($provisional) {
            $status = 'Nog te beoordelen';
            $reason = 'Nog '.($progress['requiredSets'] - $progress['completedSets'] - $skippedSets).' sets deze week te beoordelen. Nog geen definitief advies voor volgende week.';
        } elseif ($skippedSets > 0) {
            $reason = 'Een training is overgeslagen. Herhaal het doel; er zijn minder prestaties om opbouw te beoordelen.';
        } elseif (($progress['unknownSets'] ?? 0) > 0 || $progress['exertion'] === 'unknown') {
            $status = 'Inspanning niet beoordeeld';
            $reason = 'Gewicht en reps zijn opgeslagen. Beoordeel de inspanning voordat een verhoging wordt geadviseerd.';
        } elseif ($progress['achieved'] && $this->isBiweeklyHoldWeek($nextWeek, $frequency)) {
            $status = 'Consolideren';
            $reason = 'Doel gehaald; nog een week hetzelfde gewicht en dezelfde reps volgens je 2-weken schema.';
        } elseif ($progress['exertion'] === 'max' && ! ($progress['confirmedEffort'] ?? false)) {
            $status = $progress['achieved'] ? 'Doel gehaald, maar zwaar' : 'Doel nog bevestigen';
            $reason = 'Een maximale set is een signaal, geen automatische achteruitgang. Herhaal het doel; bevestiging in een volgende opbouwweek kan alsnog opbouw toestaan.';
            $reduced = round($weight - $increment, 1);
            if (! $progress['achieved'] && ($progress['repeatedMaxMisses'] ?? false) && ! $progress['isBodyweight'] && $reduced > 0 && $reduced < $weight) {
                $weight = $reduced;
                $status = 'Lichter herhalen';
                $change = 'reduce';
                $reason = 'Herhaald doelen gemist met maximale inspanning: één ingestelde gewichtsstap terug.';
            }
        } elseif ($progress['achieved']) {
            $achievedReps = max($reps, (int) ($progress['achievedReps'] ?? $reps));
            $repStep = $minReps < 8 ? 1 : 2;
            if ($progress['isBodyweight'] || $achievedReps < $maxReps) {
                $reps = $progress['isBodyweight'] ? $achievedReps + $repStep : min($maxReps, $achievedReps + $repStep);
                $status = 'Reps opbouwen';
                $change = 'reps';
                $reason = 'Doel gehaald. Zelfde gewicht, maximaal '.$repStep.' herhalingen per set erbij'.($progress['isBodyweight'] ? '.' : ' richting '.$maxReps.' reps.');
            } else {
                $weight = round($weight + $increment, 1);
                $reps = $minReps;
                $status = 'Gewicht verhogen';
                $change = 'weight';
                $reason = 'Alle geplande sets aan de bovengrens van '.$maxReps.' reps gehaald. Eén gewichtsstap erbij; opnieuw opbouwen vanaf '.$minReps.' reps.';
            }
        }

        return ['weight' => $weight, 'reps' => $reps, 'minReps' => $minReps, 'maxReps' => $maxReps,
            'status' => $status, 'reason' => $reason, 'requiredSets' => $this->requiredSets($nextWeek), 'provisional' => $provisional, 'change' => $change];
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
