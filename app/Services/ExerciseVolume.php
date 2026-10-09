<?php

namespace App\Services;

class ExerciseVolume
{
    public function __construct(private readonly Periodization $periodization) {}

    /** @return array<string, array{multiplier: int, weightLabel: string, repsLabel: string, hint: string}> */
    public function rules(): array
    {
        $groups = [
            'pair' => [
                'Dumbbell Bench Press', 'Incline Dumbbell Press', 'Decline Dumbbell Press',
                'Dumbbell Floor Press', 'Tempo Dumbbell Bench Press', 'Dumbbell Chest Flyes',
                'Incline Dumbbell Flyes', 'Floor Dumbbell Flyes', 'Incline Dumbbell Hex Press',
                'Chest-Supported Dumbbell Row', 'Incline Dumbbell Row',
                'Seated Dumbbell Shoulder Press', 'Standing Dumbbell Shoulder Press', 'Arnold Press',
                'Dumbbell High Incline Press', 'Dumbbell Lateral Raises', 'Seated Dumbbell Lateral Raises',
                'Prone Incline Lateral Raises', 'Around The World Raises', 'Rear Delt Flyes',
                'Dumbbell Face Pulls (Liggend op Schuin Bankje)', 'Seated Rear Delt Flyes',
                'Bench-Supported Y-Raises', 'Prone Incline Rear Delt Raises', 'Dumbbell Shrugs',
                'Incline Bench Dumbbell Shrugs', 'Dumbbell Shrugs met Pauze (2s Squeeze)',
                'Dumbbell Upright Row', 'Incline Dumbbell Curls', 'Spider Curls (Borst op Bank)',
                'Dumbbell Skull Crushers (Liggend op Bankje)', 'Lying Dumbbell Tricep Extension / Skull Crushers',
                'Incline Dumbbell Overhead Extension', 'Tate Press (Dumbbells)',
            ],
            'per_arm' => [
                'Dumbbell One-Arm Row', 'Dumbbell Gorilla Row', 'Renegade Rows (Dumbbell)',
                'Kroc Rows (Heavy Reps)', 'Leaning Dumbbell Lateral Raises', 'Lying Incline Lateral Raises',
                'Dumbbell Front Raises', 'Dumbbell Bicep Curls', 'Hammer Curls (Dumbbells)',
                'Concentration Curls', 'Dumbbell Kickbacks',
            ],
            'single' => ['Dumbbell Pullover', 'Dumbbell Overhead Tricep Extension', 'Goblet Squat'],
        ];
        $definitions = [
            'pair' => [
                'multiplier' => 2, 'weightLabel' => 'Kg per dumbbell', 'repsLabel' => 'Reps per arm',
                'hint' => 'Vul het gewicht van één dumbbell in. Beide dumbbells tellen mee in het volume.',
            ],
            'per_arm' => [
                'multiplier' => 2, 'weightLabel' => 'Kg per dumbbell', 'repsLabel' => 'Reps per arm',
                'hint' => 'Vul het gewicht van één dumbbell en de reps per arm in. Rond de set af na beide armen; 10 links en 10 rechts vul je in als 10.',
            ],
            'single' => [
                'multiplier' => 1, 'weightLabel' => 'Kg van de dumbbell', 'repsLabel' => 'Herhalingen',
                'hint' => 'Eén dumbbell met beide handen: het gewicht telt één keer mee in het volume.',
            ],
        ];
        $rules = [];
        foreach ($groups as $mode => $names) {
            foreach ($names as $name) {
                $rules[mb_strtolower($name)] = $definitions[$mode];
            }
        }

        return $rules;
    }

    public function multiplier(string $exerciseName): int
    {
        if ($this->periodization->isBodyweight($exerciseName)) {
            return 0;
        }

        return $this->rules()[mb_strtolower(trim($exerciseName))]['multiplier'] ?? 1;
    }

    public function forSet(string $exerciseName, float $weight, int $reps): float
    {
        if (! is_finite($weight) || $weight <= 0 || $reps <= 0) {
            return 0.0;
        }

        return $weight * $reps * $this->multiplier($exerciseName);
    }
}
