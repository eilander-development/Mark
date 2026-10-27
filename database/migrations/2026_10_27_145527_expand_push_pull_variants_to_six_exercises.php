<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('program_variants')->lockForUpdate()->get() as $variant) {
                if (! preg_match('/^(push|pull)_[a-f]$/', $variant->key)) {
                    continue;
                }
                $exercises = json_decode($variant->exercises, true, flags: JSON_THROW_ON_ERROR);
                $updated = $this->extend($exercises, $variant->family, $variant->key);
                if ($updated !== $exercises) {
                    DB::table('program_variants')->where('id', $variant->id)->update([
                        'exercises' => json_encode($updated, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                }
            }

            foreach (DB::table('cycles')->where('is_current', true)->lockForUpdate()->get() as $cycle) {
                $definition = json_decode($cycle->program_definition ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                if (($definition['type'] ?? null) !== 'push_pull') {
                    continue;
                }
                foreach ($definition['days'] as $day => &$dayDefinition) {
                    $family = $dayDefinition['family'];
                    $key = $family === 'push' ? 'slot_b3' : 'slot_b1';
                    $original = $dayDefinition['exercises'];
                    $dayDefinition['exercises'] = $this->extend($original, $family, $dayDefinition['variantKey'] ?? '');
                    if ($original === $dayDefinition['exercises']) {
                        continue;
                    }
                    $item = $dayDefinition['exercises'][$key];
                    $dayDefinition['version'] = hash('sha256', json_encode([$dayDefinition['variantName'], $dayDefinition['exercises']], JSON_THROW_ON_ERROR));
                    if (isset($definition['splits'][$day])) {
                        $definition['splits'][$day]['slots'] = array_keys($dayDefinition['exercises']);
                    }
                    foreach (DB::table('workout_sessions')->where('cycle_id', $cycle->id)->where('day', $day)->get() as $session) {
                        if (DB::table('workout_slots')->where('workout_session_id', $session->id)->where('slot_key', $key)->exists()) {
                            throw new RuntimeException('De extra oefeningspositie is al bezet; bestaande prestaties blijven behouden.');
                        }
                        $slotId = DB::table('workout_slots')->insertGetId([
                            'workout_session_id' => $session->id, 'slot_key' => $key,
                            'selected_name' => $item['selectedName'], 'target_reps' => $item['minReps'],
                            'note' => '', 'prescription' => json_encode($item, JSON_THROW_ON_ERROR),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $sets = (int) $session->week % 7 === 0 ? 2 : 3;
                        for ($position = 1; $position <= $sets; $position++) {
                            DB::table('workout_sets')->insert([
                                'workout_slot_id' => $slotId, 'position' => $position,
                                'weight' => '', 'reps' => '', 'completed' => false, 'is_pr' => false,
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                    }
                }
                unset($dayDefinition);
                DB::table('cycles')->where('id', $cycle->id)->update([
                    'program_definition' => json_encode($definition, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $exercises
     * @return array<string, array<string, mixed>>
     */
    private function extend(array $exercises, string $family, string $variantKey): array
    {
        $key = $family === 'push' ? 'slot_b3' : 'slot_b1';
        if (isset($exercises[$key])) {
            return $exercises;
        }
        $names = array_column($exercises, 'selectedName');
        $preferred = match ($variantKey) {
            'push_b', 'push_d' => 'Incline Dumbbell Press',
            'push_c', 'push_e' => 'Dumbbell Bench Press',
            default => 'Dumbbell Chest Flyes',
        };
        $candidates = $family === 'push'
            ? [$preferred, 'Dumbbell Chest Flyes', 'Incline Dumbbell Press', 'Dumbbell Bench Press', 'Incline Dumbbell Flyes', 'Dumbbell Floor Press']
            : ['Dumbbell Pullover'];
        $name = collect($candidates)->first(fn (string $candidate): bool => ! in_array($candidate, $names, true));
        if ($name === null) {
            throw new RuntimeException('Geen unieke extra oefening beschikbaar voor '.$variantKey.'.');
        }
        $press = str_contains($name, 'Press');
        $item = [
            'selectedName' => $name, 'label' => $family === 'push' ? 'Extra borst' : 'Pullover · borst en rug',
            'family' => $family, 'primary' => $family === 'push' ? 'chest' : 'back',
            'secondary' => $family === 'push' ? ($press ? ['triceps', 'shoulders'] : ['shoulders']) : ['chest', 'triceps'],
            'sets' => 3, 'minReps' => $press ? 8 : 10, 'maxReps' => $press ? 12 : 15,
            'baseMinReps' => $press ? 8 : 10, 'baseMaxReps' => $press ? 12 : 15,
            'rest' => $press ? 150 : 90, 'priority' => false,
        ];
        $updated = array_slice($exercises, 0, 2, true) + [$key => $item] + array_slice($exercises, 2, null, true);
        $position = 1;
        foreach ($updated as &$exercise) {
            $exercise['position'] = $position++;
        }
        unset($exercise);

        return $updated;
    }

    public function down(): void
    {
        throw new RuntimeException('Deze aanvulling kan opgeslagen trainingen bevatten. Gebruik een gerichte vervolgmigratie om die te behouden.');
    }
};
