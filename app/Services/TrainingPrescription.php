<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkoutSlot;

class TrainingPrescription
{
    public const GOALS = ['hypertrophy' => 'Spiergroei', 'strength' => 'Kracht', 'combined' => 'Combinatie'];

    /** @return array{minReps: int, maxReps: int} */
    public function range(string $goal, string $slotKey, int $baseReps, bool $bodyweight = false): array
    {
        $strengthSlots = $goal === 'strength' ? ['slot_a1', 'slot_a2', 'slot_b2'] : ($goal === 'combined' ? ['slot_a1', 'slot_a2'] : []);
        if (! $bodyweight && in_array($slotKey, $strengthSlots, true)) {
            return ['minReps' => 4, 'maxReps' => 6];
        }

        return $baseReps >= 12 ? ['minReps' => 12, 'maxReps' => 15] : ['minReps' => 8, 'maxReps' => 12];
    }

    /** @return array{minReps: int, maxReps: int} */
    public function rangeForSlot(WorkoutSlot $slot, int $baseReps): array
    {
        $cycle = $slot->session->cycle;
        $goal = $cycle->training_goal ?? 'hypertrophy';
        $key = $slot->slot_key;
        $priorities = $goal === 'strength' ? ['slot_a1', 'slot_a2', 'slot_b2'] : ($goal === 'combined' ? ['slot_a1', 'slot_a2'] : []);
        if ($priorities === []) {
            return $this->range($goal, $key, $baseReps, app(Periodization::class)->isBodyweight($slot->selected_name));
        }
        if (! $cycle->relationLoaded('sessions')) {
            $cycle->load('sessions.slots');
        }
        foreach ($cycle->sessions as $session) {
            foreach ($session->slots as $candidate) {
                if (in_array($candidate->slot_key, $priorities, true) && mb_strtolower(trim($candidate->selected_name)) === mb_strtolower(trim($slot->selected_name))) {
                    $key = $candidate->slot_key;
                    break 2;
                }
            }
        }

        return $this->range($goal, $key, $baseReps, app(Periodization::class)->isBodyweight($slot->selected_name));
    }

    /** @return array<string, array{primary: string, secondary: list<string>}> */
    public function muscleMap(): array
    {
        $groups = [
            'slot_a1' => ['chest', ['triceps', 'shoulders']], 'slot_a2' => ['back', ['biceps', 'shoulders']],
            'slot_a3' => ['chest', ['triceps', 'shoulders']], 'slot_a4' => ['traps', []],
            'slot_a5' => ['shoulders', ['traps']], 'slot_a6' => ['biceps', []],
            'slot_b1' => ['back', ['biceps']], 'slot_b2' => ['shoulders', ['triceps']],
            'slot_b3' => ['chest', ['triceps', 'shoulders']], 'slot_b4' => ['back', ['biceps', 'shoulders']],
            'slot_b5' => ['shoulders', ['back', 'traps']], 'slot_b6' => ['triceps', []],
        ];
        $map = [];
        foreach (config('ironforge.catalog') as $key => $slot) {
            foreach ($slot['alternatives'] as $name) {
                $normalized = mb_strtolower(trim($name));
                $map[$normalized] ??= ['primary' => $groups[$key][0], 'secondary' => $groups[$key][1]];
            }
        }
        foreach (['Close-Grip Barbell Bench Press', 'Diamond Push-ups', 'Close-Grip Push-ups', 'JM Press (Dumbbells of Barbell)', 'Bench Dips'] as $name) {
            $map[mb_strtolower($name)] = ['primary' => 'triceps', 'secondary' => ['chest', 'shoulders']];
        }
        foreach (['Dumbbell Chest Flyes', 'Incline Dumbbell Flyes', 'Floor Dumbbell Flyes', 'Svend Press (Schijven)'] as $name) {
            $map[mb_strtolower($name)] = ['primary' => 'chest', 'secondary' => ['shoulders']];
        }
        foreach (['Dumbbell Pullover', 'Barbell Pullover (Liggend op Bankje)'] as $name) {
            $map[mb_strtolower($name)] = ['primary' => 'back', 'secondary' => ['chest', 'triceps']];
        }
        foreach (['Barbell Upright Row', 'Dumbbell Upright Row'] as $name) {
            $map[mb_strtolower($name)] = ['primary' => 'shoulders', 'secondary' => ['traps', 'biceps']];
        }

        return $map;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['goals' => self::GOALS, 'muscles' => ['chest' => 'Borst', 'back' => 'Rug', 'shoulders' => 'Schouders', 'biceps' => 'Biceps', 'triceps' => 'Triceps', 'traps' => 'Trapezius'], 'exerciseMuscles' => $this->muscleMap(), 'exerciseVolume' => app(ExerciseVolume::class)->rules()];
    }

    /** @return array<string, mixed> */
    public function weekMuscles(Cycle $cycle, int $week): array
    {
        $map = $this->muscleMap();
        $rows = [];
        $unknown = [];
        $periodization = app(Periodization::class);
        $required = $periodization->requiredSets($week);
        foreach ($cycle->sessions()->where('week', $week)->with('slots.sets')->get() as $session) {
            foreach ($session->slots as $slot) {
                $classification = $map[mb_strtolower(trim($slot->selected_name))] ?? null;
                if (! $classification) {
                    $unknown[] = $slot->selected_name;

                    continue;
                }
                $completed = $slot->sets->take($required)->filter(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($periodization->isBodyweight($slot->selected_name) || (float) $set->weight > 0))->count();
                foreach ([$classification['primary'], ...$classification['secondary']] as $group) {
                    $rows[$group] ??= ['planned' => 0, 'completed' => 0, 'indirect' => 0, 'indirectPlanned' => 0, 'exercises' => []];
                    $direct = $group === $classification['primary'];
                    $rows[$group]['planned'] += $direct ? $required : 0;
                    $rows[$group]['completed'] += $direct ? $completed : 0;
                    $rows[$group]['indirect'] += $direct ? 0 : $completed;
                    $rows[$group]['indirectPlanned'] += $direct ? 0 : $required;
                    $rows[$group]['exercises'][] = ['name' => $slot->selected_name, 'day' => $session->day, 'direct' => $direct, 'completed' => $completed, 'planned' => $required, 'skipped' => (bool) $session->skipped];
                }
            }
        }

        return ['groups' => $rows, 'unclassified' => array_values(array_unique($unknown)), 'isDeload' => $periodization->isDeloadWeek($week)];
    }
}
