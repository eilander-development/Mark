<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $replacements = [
        'push_b' => ['Barbell Overhead Press (OHP)', 'Arnold Press'],
        'push_d' => ['Seated Dumbbell Shoulder Press', 'Seated Neutral-Grip Dumbbell Shoulder Press'],
        'push_e' => ['Standing Dumbbell Shoulder Press', 'Seated Neutral-Grip Dumbbell Shoulder Press'],
        'push_f' => ['Seated Barbell Press', 'Seated Dumbbell Shoulder Press'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $neutralName = 'Seated Neutral-Grip Dumbbell Shoulder Press';
            $catalogSlot = DB::table('program_slots')->where('slot_key', 'slot_b2')->lockForUpdate()->first();
            if ($catalogSlot) {
                $alternatives = json_decode($catalogSlot->alternatives ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                if (! in_array($neutralName, $alternatives, true)) {
                    $alternatives[] = $neutralName;
                    DB::table('program_slots')->where('id', $catalogSlot->id)->update([
                        'alternatives' => json_encode($alternatives, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('exercises')->insertOrIgnore([
                'name' => $neutralName, 'title' => $neutralName, 'channel' => '',
                'cues' => json_encode(['Zit met je rug tegen de leuning.', 'Houd je handpalmen naar elkaar tijdens de hele beweging.', 'Druk gecontroleerd omhoog zonder je onderrug hol te trekken.'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach (DB::table('program_variants')->where('family', 'push')->lockForUpdate()->get() as $variant) {
                $exercises = json_decode($variant->exercises, true, flags: JSON_THROW_ON_ERROR);
                $original = $exercises;
                if (isset($exercises['slot_b2'])) {
                    $exercises['slot_b2'] = $this->replace($exercises['slot_b2'], $variant->key);
                }
                if ($exercises !== $original) {
                    DB::table('program_variants')->where('id', $variant->id)->update([
                        'exercises' => json_encode($exercises, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                }
            }

            foreach (DB::table('cycles')->where('is_current', true)->lockForUpdate()->get() as $cycle) {
                $definition = json_decode($cycle->program_definition ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                if (($definition['type'] ?? null) !== 'push_pull') {
                    continue;
                }
                $original = $definition;
                foreach ($definition['days'] as $day => &$dayDefinition) {
                    $variantKey = $dayDefinition['variantKey'] ?? '';
                    if (! isset($dayDefinition['exercises']['slot_b2'])) {
                        continue;
                    }
                    $dayDefinition['exercises']['slot_b2'] = $this->replace($dayDefinition['exercises']['slot_b2'], $variantKey);
                    $dayDefinition['version'] = hash('sha256', json_encode([$dayDefinition['variantName'], $dayDefinition['exercises']], JSON_THROW_ON_ERROR));
                    $sessionIds = DB::table('workout_sessions')->where('cycle_id', $cycle->id)->where('day', $day)->pluck('id');
                    foreach (DB::table('workout_slots')->whereIn('workout_session_id', $sessionIds)->where('slot_key', 'slot_b2')->lockForUpdate()->get() as $slot) {
                        $prescription = json_decode($slot->prescription ?? 'null', true, flags: JSON_THROW_ON_ERROR) ?? $dayDefinition['exercises']['slot_b2'];
                        $prescription['selectedName'] = $slot->selected_name;
                        $updated = $this->replace($prescription, $variantKey);
                        if ($updated['selectedName'] === $slot->selected_name) {
                            continue;
                        }
                        $hasResults = DB::table('workout_sets')->where('workout_slot_id', $slot->id)
                            ->where(function ($query): void {
                                $query->where('completed', true)->orWhere(function ($query): void {
                                    $query->whereNotNull('reps')->where('reps', '!=', '');
                                });
                            })->exists();
                        if ($hasResults) {
                            continue;
                        }
                        DB::table('workout_slots')->where('id', $slot->id)->update([
                            'selected_name' => $updated['selectedName'], 'prescription' => json_encode($updated, JSON_THROW_ON_ERROR),
                            'progression_plan' => null, 'updated_at' => now(),
                        ]);
                        DB::table('workout_sets')->where('workout_slot_id', $slot->id)->update([
                            'weight' => '', 'input_fields' => null, 'updated_at' => now(),
                        ]);
                    }
                }
                unset($dayDefinition);
                if ($definition !== $original) {
                    DB::table('cycles')->where('id', $cycle->id)->update([
                        'program_definition' => json_encode($definition, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function replace(array $item, string $variantKey): array
    {
        $replacement = $this->replacements[$variantKey] ?? null;
        if ($replacement && $item['selectedName'] === $replacement[0]) {
            $item['selectedName'] = $replacement[1];
            unset($item['weight'], $item['weightEdited'], $item['weightSource'], $item['weightReason'], $item['lastPerformed'], $item['fromName'], $item['rotated']);
            $item['requirements'] = ['dumbbells', 'bench', 'adjustable_bench'];
            $item['weightLabel'] = 'Kg per dumbbell';
        }
        if ($item['selectedName'] === 'Arnold Press') {
            $item['focusCue'] = 'Zittende Arnold press: houd je rug tegen de leuning en draai je handpalmen tijdens het drukken gecontroleerd naar voren.';
        } elseif ($item['selectedName'] === 'Seated Neutral-Grip Dumbbell Shoulder Press') {
            $item['focusCue'] = 'Zittende schouderpress: houd je handpalmen naar elkaar en je rug tegen de leuning; druk zonder je onderrug hol te trekken.';
        }

        return $item;
    }

    public function down(): void
    {
        throw new RuntimeException('De vervangende presses kunnen prestaties bevatten. Gebruik een gerichte vervolgmigratie om die te behouden.');
    }
};
