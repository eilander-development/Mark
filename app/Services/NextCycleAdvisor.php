<?php

namespace App\Services;

use App\Models\Cycle;

class NextCycleAdvisor
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Periodization $periodization,
    ) {}

    /**
     * @return array{available: bool, currentWeek: int, totalWeeks: int, reason: string, schema: array<string, array<string, array<string, mixed>>>, days: list<array<string, mixed>>}
     */
    public function forCycle(Cycle $cycle, int $currentWeek): array
    {
        $totalWeeks = (int) $cycle->total_weeks;
        $available = $currentWeek >= $totalWeeks;
        $schema = $this->schema($cycle);
        $reason = $available
            ? 'Deloadweek is bereikt. Wissel naar een nabije variant en start week 1 met piekgewicht of een inregelgewicht.'
            : 'Een nieuwe periode start ná week '.$totalWeeks.' (deload). Je zit nu in week '.$currentWeek.'.';

        return [
            'available' => $available,
            'currentWeek' => $currentWeek,
            'totalWeeks' => $totalWeeks,
            'reason' => $reason,
            'schema' => $schema,
            'days' => $this->daysFromSchema($schema),
        ];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function schema(Cycle $from): array
    {
        $from->loadMissing(['sessions.slots.sets']);
        $peakWeek = $this->periodization->lastHeavyWeek((int) $from->total_weeks);
        $peakSessions = $from->sessions->where('week', $peakWeek);
        $schema = [];

        foreach (config('ironforge.days') as $day) {
            $source = $peakSessions->first(
                fn ($session) => $session->day === $day
            );
            if (! $source) {
                continue;
            }

            foreach ($source->slots as $slot) {
                $catalog = $this->catalog->slot($slot->slot_key);
                $fromName = (string) $slot->selected_name;
                $nextName = $this->catalog->nextAlternative($slot->slot_key, $fromName);
                $rotated = $nextName !== $fromName;
                $peakWeight = 0.0;
                foreach ($slot->sets as $set) {
                    $peakWeight = max($peakWeight, (float) $set->weight);
                }

                $schema[$day][$slot->slot_key] = [
                    'selectedName' => $nextName,
                    'fromName' => $fromName,
                    'weight' => (! $rotated && $peakWeight > 0) ? $peakWeight : null,
                    'targetReps' => (int) ($catalog['targetReps'] ?? $slot->target_reps ?? 8),
                    'rotated' => $rotated,
                    'isBodyweight' => $this->periodization->isBodyweight($nextName),
                ];
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $schema
     * @return list<array<string, mixed>>
     */
    private function daysFromSchema(array $schema): array
    {
        $days = [];
        foreach (config('ironforge.days') as $day) {
            $slots = [];
            foreach ($schema[$day] ?? [] as $slotKey => $item) {
                $slots[] = [
                    'slotKey' => $slotKey,
                    ...$item,
                ];
            }
            $days[] = [
                'day' => $day,
                'title' => (string) config('ironforge.splits.'.$day.'.title', $day),
                'slots' => $slots,
            ];
        }

        return $days;
    }
}
