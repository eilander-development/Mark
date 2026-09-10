<?php

namespace App\Services;

class StrengthBenchmark
{
    public function __construct(private readonly Periodization $periodization) {}

    /**
     * @param  array{birthYear?: int, bodyWeightKg?: float|int, experienceLevel?: string}|null  $profile
     * @return array<string, mixed>
     */
    public function for(string $exerciseName, ?array $profile = null, ?int $year = null): array
    {
        $birthYear = (int) ($profile['birthYear'] ?? 1984);
        $bw = (float) ($profile['bodyWeightKg'] ?? 82);
        $age = ($year ?? (int) date('Y')) - $birthYear;
        $bwFactor = $bw / 82;
        $ageFactor = $age > 50 ? 0.90 : ($age > 45 ? 0.95 : 1.0);
        $name = mb_strtolower($exerciseName);
        $isBw = $this->periodization->isBodyweight($exerciseName);

        if (str_contains($name, 'incline') && (str_contains($name, 'dumbbell') || str_contains($name, 'db'))) {
            $base = (int) round(26 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Borst Schuin (Bovenkant)', false, true, (int) round(16 * $bwFactor), $base, (int) round(34 * $bwFactor), max(16, $base - 2), $base + 2, $base.' kg per dumbbell');
        }

        if (str_contains($name, 'dumbbell') && (str_contains($name, 'bench press') || str_contains($name, 'flat'))) {
            $base = (int) round(28 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Borst Vlak Dumbbells', false, true, (int) round(18 * $bwFactor), $base, (int) round(36 * $bwFactor), max(18, $base - 2), $base + 2, $base.' kg per dumbbell');
        }

        if (str_contains($name, 'barbell bench press') || str_contains($name, 'bench press (barbell)')) {
            $base = (int) round(75 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Borst Zwaar Compound (Barbell)', false, false, (int) round(55 * $bwFactor), $base, (int) round(100 * $bwFactor), max(50, $base - 5), $base + 5, $base.' kg totaal');
        }

        if (str_contains($name, 'incline barbell') || str_contains($name, 'barbell incline')) {
            $base = (int) round(65 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Borst Schuin Barbell', false, false, (int) round(45 * $bwFactor), $base, (int) round(85 * $bwFactor), max(45, $base - 5), $base + 5, $base.' kg totaal');
        }

        if (str_contains($name, 'opdrukken') || str_contains($name, 'push-up') || str_contains($name, 'pushup')) {
            if (str_contains($name, 'decline') || str_contains($name, 'bankje') || str_contains($name, 'voeten')) {
                return $this->row($exerciseName, 'Borst Lichaamsgewicht (Bovenkant)', true, false, 8, 16, 26, 12, 18, '16-18 strikte reps', 'reps');
            }
            if (str_contains($name, 'diamond') || str_contains($name, 'close')) {
                return $this->row($exerciseName, 'Triceps & Midden Borst', true, false, 8, 15, 25, 10, 16, '15-18 reps', 'reps');
            }

            return $this->row($exerciseName, 'Borst Lichaamsgewicht (Klassiek)', true, false, 12, 22, 35, 15, 25, '20-25 strikte reps', 'reps');
        }

        if (str_contains($name, 'pull-up') || str_contains($name, 'chin-up') || str_contains($name, 'optrekken')) {
            return $this->row($exerciseName, 'Rug & Lats (Lichaamsgewicht)', true, false, 3, 8, 15, 6, 10, '8-10 strikte reps', 'reps');
        }

        if (str_contains($name, 'barbell row') || str_contains($name, 'yates') || str_contains($name, 'pendlay')) {
            $base = (int) round(70 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Rug Zwaar Compound (Barbell)', false, false, (int) round(45 * $bwFactor), $base, (int) round(90 * $bwFactor), max(45, $base - 5), $base + 5, $base.' kg totaal');
        }

        if (str_contains($name, 'one-arm row') || str_contains($name, 'single arm row')) {
            $base = (int) round(30 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Rug Accessoire Dumbbell', false, true, (int) round(18 * $bwFactor), $base, (int) round(40 * $bwFactor), max(18, $base - 4), $base + 2, $base.' kg per dumbbell');
        }

        if (str_contains($name, 'pullover')) {
            $base = (int) round(26 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Rug & Borst Stretch', false, false, (int) round(16 * $bwFactor), $base, (int) round(34 * $bwFactor), max(16, $base - 2), $base + 2, $base.' kg');
        }

        if (str_contains($name, 'shoulder press') || str_contains($name, 'overhead press') || str_contains($name, 'ohp')) {
            if (str_contains($name, 'barbell')) {
                $base = (int) round(50 * $bwFactor * $ageFactor);

                return $this->row($exerciseName, 'Schouders Zwaar Barbell', false, false, (int) round(35 * $bwFactor), $base, (int) round(65 * $bwFactor), max(35, $base - 5), $base + 5, $base.' kg totaal');
            }
            $base = (int) round(22 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Schouders Compound Dumbbell', false, true, (int) round(14 * $bwFactor), $base, (int) round(30 * $bwFactor), max(14, $base - 2), $base + 2, $base.' kg per dumbbell');
        }

        if (str_contains($name, 'lateral raise')) {
            $base = (int) round(10 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Schouders Isolatie (Zijkant)', false, true, 6, $base, 14, max(6, $base - 2), $base + 2, $base.' kg per dumbbell');
        }

        if (str_contains($name, 'rear delt') || str_contains($name, 'face pull') || str_contains($name, 'y-raise')) {
            $base = (int) round(10 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Schouders Isolatie (Achterkant)', false, true, 6, $base, 14, max(6, $base - 2), $base + 2, $base.' kg per dumbbell');
        }

        if (str_contains($name, 'curl')) {
            $isHammer = str_contains($name, 'hammer');
            $base = (int) round(($isHammer ? 16 : 14) * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Armen Biceps', false, true, (int) round(10 * $bwFactor), $base, (int) round(20 * $bwFactor), max(10, $base - 2), $base + 2, $base.' kg per dumbbell');
        }

        if (str_contains($name, 'bench dips')) {
            return $this->row($exerciseName, 'Armen Triceps (Lichaamsgewicht)', true, false, 12, 22, 35, 15, 25, '20-25 reps', 'reps');
        }

        if (str_contains($name, 'tricep') || str_contains($name, 'skull crusher') || str_contains($name, 'kickback')) {
            $base = (int) round(26 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Armen Triceps', false, str_contains($name, 'dumbbell') || str_contains($name, 'db'), (int) round(16 * $bwFactor), $base, (int) round(38 * $bwFactor), max(16, $base - 4), $base + 4, $base.' kg');
        }

        if (str_contains($name, 'shrug') || str_contains($name, 'farmer')) {
            $base = (int) round(75 * $bwFactor * $ageFactor);

            return $this->row($exerciseName, 'Nek & Trapezius', false, str_contains($name, 'dumbbell'), (int) round(50 * $bwFactor), $base, (int) round(110 * $bwFactor), max(50, $base - 10), $base + 10, $base.' kg');
        }

        return $this->row(
            $exerciseName,
            'Bovenlichaam Oefening',
            $isBw,
            str_contains($name, 'dumbbell') || str_contains($name, 'db'),
            $isBw ? 10 : 15,
            $isBw ? 18 : 25,
            $isBw ? 28 : 35,
            $isBw ? 12 : 22,
            $isBw ? 20 : 28,
            $isBw ? '18-20 reps' : '25 kg',
            $isBw ? 'reps' : 'kg',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $exerciseName,
        string $category,
        bool $isBodyweight,
        bool $isPerDumbbell,
        int $beginner,
        int $intermediate,
        int $advanced,
        int $suggestedStart,
        int $cycleGoal,
        string $normText,
        string $unit = 'kg',
    ): array {
        return [
            'exerciseName' => $exerciseName,
            'category' => $category,
            'isBodyweight' => $isBodyweight,
            'isPerDumbbell' => $isPerDumbbell,
            'unit' => $unit,
            'beginner' => $beginner,
            'intermediate' => $intermediate,
            'advanced' => $advanced,
            'suggestedStart' => $suggestedStart,
            'cycleGoal' => $cycleGoal,
            'normText' => $normText,
        ];
    }
}
