<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkoutSlot;

class ExerciseHistory
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $history = [];
        $entries = [];
        $slots = WorkoutSlot::query()->whereHas('sets', fn ($query) => $query->where('completed', true))
            ->with(['session.cycle', 'sets'])->get()
            ->sortBy(fn (WorkoutSlot $slot): string => sprintf('%08d-%02d-%02d-%08d', $slot->session->cycle->number, $slot->session->week, array_search($slot->session->day, config('ironforge.days')), $slot->id));
        foreach ($slots as $slot) {
            if ($slot->session->skipped) {
                continue;
            }
            $bodyweight = app(Periodization::class)->isBodyweight($slot->selected_name);
            $sets = $slot->sets->filter(fn ($set): bool => $set->completed && (int) $set->reps > 0 && ($bodyweight || (float) $set->weight > 0));
            if ($sets->isEmpty()) {
                continue;
            }
            $key = mb_strtolower(trim($slot->selected_name));
            $entry = ['exerciseName' => $slot->selected_name, 'cycle' => (int) $slot->session->cycle->number,
                'week' => (int) $slot->session->week, 'day' => $slot->session->day,
                'date' => $sets->max('updated_at')?->toDateString(),
                'isDeload' => app(Periodization::class)->isDeloadWeek((int) $slot->session->week),
                'targetReps' => (int) $slot->target_reps,
                'sets' => $sets->map(fn ($set): array => ['weight' => $bodyweight ? null : (float) $set->weight,
                    'reps' => (int) $set->reps, 'exertion' => $set->exertion ?: 'unknown'])->values()->all()];
            $entry['_rank'] = [$entry['cycle'], $entry['week'], array_search($entry['day'], config('ironforge.days'))];
            $entries[] = $entry;
        }

        foreach (Cycle::query()->where('is_current', false)->doesntHave('sessions')->get() as $cycle) {
            foreach ($cycle->snapshot['weeksSnapshot'] ?? $cycle->snapshot['weeks'] ?? [] as $week => $days) {
                foreach (config('ironforge.days') as $day) {
                    foreach ($days[$day] ?? [] as $slot) {
                        if (! is_array($slot) || ! isset($slot['selectedName']) || ($days[$day]['skipped'] ?? false)) {
                            continue;
                        }
                        $bodyweight = app(Periodization::class)->isBodyweight($slot['selectedName']);
                        $sets = collect($slot['sets'] ?? [])->filter(fn (array $set): bool => ($set['completed'] ?? false) && (int) ($set['reps'] ?? 0) > 0 && ($bodyweight || (float) ($set['weight'] ?? 0) > 0));
                        if ($sets->isEmpty()) {
                            continue;
                        }
                        $entries[] = ['exerciseName' => $slot['selectedName'], 'cycle' => (int) $cycle->number,
                            'week' => (int) $week, 'day' => $day, 'date' => $sets->pluck('performedAt')->filter()->max() ?: null,
                            'isDeload' => app(Periodization::class)->isDeloadWeek((int) $week), 'targetReps' => (int) ($slot['targetReps'] ?? 0),
                            '_rank' => [(int) $cycle->number, (int) $week, array_search($day, config('ironforge.days'))],
                            'sets' => $sets->map(fn (array $set): array => ['weight' => $bodyweight ? null : (float) $set['weight'], 'reps' => (int) $set['reps'], 'exertion' => $set['exertion'] ?? 'unknown'])->values()->all()];
                    }
                }
            }
        }
        usort($entries, fn (array $a, array $b): int => $a['_rank'] <=> $b['_rank']);
        foreach ($entries as $entry) {
            $key = mb_strtolower(trim($entry['exerciseName']));
            unset($entry['_rank']);
            $normal = $entry['isDeload'] ? ($history[$key]['reference'] ?? null) : $entry;
            $history[$key] = [...$entry, 'reference' => $normal];
        }

        return $history;
    }

    /**
     * @param  array<string, mixed>|null  $history
     * @param  array<string, mixed>  $prescription
     * @return array{weight: ?float, reason: string, source: string}
     */
    public function startAdvice(?array $history, array $prescription): array
    {
        $reference = $history['reference'] ?? null;
        if (! $reference) {
            return ['weight' => null, 'reason' => 'Geen normale werksets bekend. Bepaal een voorzichtig startgewicht tijdens de eerste training.', 'source' => 'inregelen'];
        }
        if ($reference['date'] && now()->diffInDays($reference['date'], true) > 28) {
            return ['weight' => null, 'reason' => 'Laatste normale uitvoering is meer dan vier weken geleden. Regel het gewicht opnieuw in.', 'source' => 'oudere_historie'];
        }
        $target = (int) $reference['targetReps'];
        if ($target < $prescription['minReps'] || $target > $prescription['maxReps']) {
            return ['weight' => null, 'reason' => 'Het herhalingsbereik verschilt van je vorige training. Bepaal het passende startgewicht opnieuw.', 'source' => 'ander_bereik'];
        }
        $usable = collect($reference['sets'])->filter(fn (array $set): bool => $set['weight'] > 0 && $set['reps'] >= $prescription['minReps'] && $set['exertion'] !== 'max');
        if ($usable->isEmpty()) {
            return ['weight' => null, 'reason' => 'De vorige sets bevestigen nog geen passend startgewicht. Regel voorzichtig in.', 'source' => 'inregelen'];
        }

        return ['weight' => (float) $usable->min('weight'), 'reason' => 'Gebaseerd op je laatste normale werksets van dezelfde oefening. Bevestig dit tijdens de eerste training.', 'source' => 'eigen_historie'];
    }
}
