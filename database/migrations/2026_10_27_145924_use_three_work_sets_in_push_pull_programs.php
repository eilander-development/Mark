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
                foreach ($exercises as &$item) {
                    $item['sets'] = 3;
                }
                unset($item);
                DB::table('program_variants')->where('id', $variant->id)->update([
                    'exercises' => json_encode($exercises, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }

            foreach (DB::table('cycles')->where('is_current', true)->lockForUpdate()->get() as $cycle) {
                $definition = json_decode($cycle->program_definition ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                if (($definition['type'] ?? null) !== 'push_pull') {
                    continue;
                }
                foreach ($definition['days'] as $day => &$dayDefinition) {
                    foreach ($dayDefinition['exercises'] as &$item) {
                        $item['sets'] = 3;
                    }
                    unset($item);
                    $dayDefinition['version'] = hash('sha256', json_encode([$dayDefinition['variantName'], $dayDefinition['exercises']], JSON_THROW_ON_ERROR));
                    foreach (DB::table('workout_sessions')->where('cycle_id', $cycle->id)->where('day', $day)->get() as $session) {
                        foreach (DB::table('workout_slots')->where('workout_session_id', $session->id)->get() as $slot) {
                            if (! isset($dayDefinition['exercises'][$slot->slot_key])) {
                                continue;
                            }
                            $prescription = json_decode($slot->prescription ?? 'null', true, flags: JSON_THROW_ON_ERROR) ?? $dayDefinition['exercises'][$slot->slot_key];
                            $prescription['sets'] = 3;
                            DB::table('workout_slots')->where('id', $slot->id)->update([
                                'prescription' => json_encode($prescription, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                            ]);
                            $required = (int) $session->week % 7 === 0 ? 2 : 3;
                            for ($position = 1; $position <= $required; $position++) {
                                DB::table('workout_sets')->insertOrIgnore([
                                    'workout_slot_id' => $slot->id, 'position' => $position,
                                    'weight' => '', 'reps' => '', 'completed' => false, 'is_pr' => false,
                                    'created_at' => now(), 'updated_at' => now(),
                                ]);
                            }
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

    public function down(): void
    {
        throw new RuntimeException('Extra werksets kunnen prestaties bevatten. Gebruik een gerichte vervolgmigratie om die te behouden.');
    }
};
