<?php

namespace App\Services;

use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Models\WorkoutSlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CoreWorkout
{
    /** @return array<string, array{name: string, reps: int, maxReps: int, perSide: bool, tips: list<string>, videoId: string, channel: string}> */
    public function catalog(): array
    {
        return [
            'reverse_crunch' => [
                'name' => 'Reverse crunch', 'reps' => 10, 'maxReps' => 15, 'perSide' => false,
                'tips' => ['Lig op je rug met je knieën gebogen en je armen naast je.', 'Rol je bekken rustig omhoog en breng je knieën richting je borst.', 'Laat je heupen gecontroleerd zakken; zwaai niet met je benen.'],
                'videoId' => 'yH-oSzE5_g0', 'channel' => 'Well+Good',
            ],
            'dead_bug' => [
                'name' => 'Dead bug', 'reps' => 8, 'maxReps' => 12, 'perSide' => true,
                'tips' => ['Lig op je rug, span je buik aan en houd je ribben laag.', 'Beweeg afwisselend je tegenovergestelde arm en been, rustig en gecontroleerd.', 'Houd je onderrug stabiel. Maak de beweging kleiner als je rug hol trekt.'],
                'videoId' => 'bxn9FBrt4-A', 'channel' => 'NASM',
            ],
            'crunch' => [
                'name' => 'Crunch', 'reps' => 12, 'maxReps' => 15, 'perSide' => false,
                'tips' => ['Lig op je rug met gebogen knieën en je voeten op de vloer.', 'Til je schouderbladen een klein stukje op terwijl je uitademt.', 'Trek niet aan je nek en laat je bovenrug langzaam terugzakken.'],
                'videoId' => 'NIqgTCTd2MM', 'channel' => 'Howcast',
            ],
            'bird_dog' => [
                'name' => 'Bird dog', 'reps' => 8, 'maxReps' => 12, 'perSide' => true,
                'tips' => ['Begin op handen en knieën, handen onder schouders en knieën onder heupen.', 'Strek één arm en het tegenovergestelde been zonder je heupen te draaien.', 'Keer rustig terug en wissel van kant. Houd je rug in een neutrale positie.'],
                'videoId' => 'ZdAHe9_HeEw', 'channel' => 'NASM',
            ],
        ];
    }

    /**
     * Read-only slots let supplemental sets use the existing reporting calculations.
     *
     * @return Collection<int, WorkoutSlot>
     */
    public function reportingSlots(WorkoutSession $session): Collection
    {
        $slots = $session->slots->toBase();
        foreach ($session->core_workout ?? [] as $index => $item) {
            $exercise = $this->catalog()[$item['exercise']];
            $reps = $item['targetReps'] ?? $exercise['reps'];
            $slot = new WorkoutSlot([
                'slot_key' => 'slot_core_'.$index, 'selected_name' => $exercise['name'], 'target_reps' => $reps,
                'progression_plan' => ['weight' => 0, 'reps' => $reps, 'minReps' => 8, 'maxReps' => $exercise['maxReps']],
            ]);
            $slot->setRelation('session', $session);
            $slot->setRelation('sets', collect($item['sets'])->map(fn (array $set, int $position): WorkoutSet => new WorkoutSet([
                'position' => $position + 1, 'weight' => 0, 'reps' => $set['reps'],
                'completed' => $set['completed'], 'exertion' => $set['exertion'] ?? 'unknown',
            ])));
            $slots->push($slot);
        }

        return $slots;
    }

    /** @return array{catalog: array<string, mixed>, defaults: array<string, list<string>>} */
    public function rules(): array
    {
        return [
            'catalog' => $this->catalog(),
            'defaults' => [
                'mon' => ['reverse_crunch', 'dead_bug'], 'tue' => ['crunch', 'bird_dog'],
                'thu' => ['reverse_crunch', 'dead_bug'], 'fri' => ['crunch', 'bird_dog'],
            ],
        ];
    }

    /**
     * @return list<array{exercise: string, targetReps?: int, sets: list<array{reps: int, completed: bool, exertion?: string}>}>
     */
    public function validate(mixed $workout): array
    {
        $data = Validator::make(['core_workout' => $workout], [
            'core_workout' => ['array', 'list', $workout === [] ? 'size:0' : 'size:2'],
            'core_workout.*' => ['required', 'array:exercise,targetReps,sets'],
            'core_workout.*.exercise' => ['required', 'string', Rule::in(array_keys($this->catalog()))],
            'core_workout.*.targetReps' => ['sometimes', 'integer', 'between:1,50'],
            'core_workout.*.sets' => ['required', 'array', 'list', 'min:2', 'max:3'],
            'core_workout.*.sets.*' => ['required', 'array:reps,completed,exertion'],
            'core_workout.*.sets.*.reps' => ['required', 'integer', 'between:1,50'],
            'core_workout.*.sets.*.completed' => ['required', 'boolean'],
            'core_workout.*.sets.*.exertion' => ['sometimes', 'in:unknown,easy,good,max'],
        ])->validate();

        return array_map(fn (array $exercise): array => [
            'exercise' => $exercise['exercise'],
            ...(isset($exercise['targetReps']) ? ['targetReps' => (int) $exercise['targetReps']] : []),
            'sets' => array_map(fn (array $set): array => [
                'reps' => (int) $set['reps'], 'completed' => (bool) $set['completed'],
                ...(isset($set['exertion']) ? ['exertion' => $set['exertion']] : []),
            ], $exercise['sets']),
        ], $data['core_workout']);
    }
}
