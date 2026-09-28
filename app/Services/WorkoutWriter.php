<?php

namespace App\Services;

use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Models\WorkoutSlot;
use Illuminate\Validation\ValidationException;

class WorkoutWriter
{
    public function __construct(
        private readonly CycleFactory $factory,
        private readonly Periodization $periodization,
        private readonly PersonalRecord $records,
        private readonly StateAssembler $state,
        private readonly NextCycleAdvisor $nextCycle,
        private readonly SlotAdvisor $advisor,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateSet(WorkoutSet $set, array $data, string $context = 'live'): array
    {
        $this->guardMainScreen($context);
        $set->loadMissing('slot.session');
        if ($set->slot->session->skipped) {
            throw ValidationException::withMessages(['set' => 'Maak overslaan eerst ongedaan voordat je deze training wijzigt.']);
        }

        if (array_key_exists('weight', $data)) {
            $set->weight = $data['weight'] === null || $data['weight'] === '' ? '' : (string) $data['weight'];
        }
        if (array_key_exists('reps', $data)) {
            $set->reps = $data['reps'] === null || $data['reps'] === '' ? '' : (string) $data['reps'];
        }
        if (array_key_exists('completed', $data)) {
            $set->completed = (bool) $data['completed'];
        }
        if (array_key_exists('exertion', $data) && in_array($data['exertion'], ['unknown', 'easy', 'good', 'max'], true)) {
            $set->exertion = $data['exertion'];
        }

        if (array_key_exists('inputFields', $data)) {
            $set->input_fields = $data['inputFields'];
        }

        $name = $set->slot->selected_name;
        if ($this->periodization->isBodyweight($name)) {
            $set->weight = '0';
        }

        if ($set->completed && ! $this->periodization->isBodyweight($name) && (float) $set->weight <= 0) {
            throw ValidationException::withMessages([
                'weight' => 'Vul een gewicht in voordat je de set afrondt.',
            ]);
        }

        $set->is_pr = $set->completed && $this->records->isNewPr($name, (string) $set->weight, (string) $set->reps, $set->id);
        $set->save();

        return [
            'set' => [
                'id' => $set->id,
                'weight' => $set->weight,
                'reps' => $set->reps,
                'completed' => (bool) $set->completed,
                'isPr' => (bool) $set->is_pr,
                'exertion' => $set->exertion ?: 'unknown',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateSlot(WorkoutSlot $slot, array $data, string $context = 'main'): array
    {
        if (isset($data['selectedName'])) {
            $prefs = $this->factory->preferences();
            if ($prefs->routine_locked && $context !== 'setup') {
                throw ValidationException::withMessages([
                    'selectedName' => 'Schema staat vast. Ontgrendel eerst om te wisselen.',
                ]);
            }
            if ($slot->selected_name !== $data['selectedName']) {
                if ($slot->sets()->where('completed', true)->exists()) {
                    throw ValidationException::withMessages(['selectedName' => 'Deze oefening bevat opgeslagen prestaties. Wissel in een nog niet uitgevoerde training.']);
                }
                $slot->progression_plan = null;
            }
            $slot->selected_name = (string) $data['selectedName'];
        }
        if (array_key_exists('note', $data)) {
            $this->guardMainScreen($context);
            $slot->note = (string) $data['note'];
        }
        if (array_key_exists('progressionPlan', $data)) {
            $slot->progression_plan = $data['progressionPlan'];
        }
        if (isset($data['targetReps'])) {
            $slot->target_reps = $data['targetReps'];
        }
        $slot->save();

        if (isset($data['selectedName'])) {
            $this->syncNameAcrossCycle($slot);
        }

        return [
            'slot' => [
                'id' => $slot->id,
                'selectedName' => $slot->selected_name,
                'note' => $slot->note,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearDay(int $week, string $day): array
    {
        $cycle = $this->factory->ensureCurrent();
        $session = $cycle->sessions()->where('week', $week)->where('day', $day)->with('slots.sets')->firstOrFail();
        foreach ($session->slots as $slot) {
            $slot->update(['progression_plan' => null]);
            foreach ($slot->sets as $set) {
                $set->fill(['weight' => '', 'reps' => '', 'completed' => false, 'is_pr' => false, 'exertion' => 'unknown', 'input_fields' => null])->save();
            }
        }
        $session->actual_duration = null;
        $session->actual_avg_rest = null;
        $session->actual_rest_count = null;
        $session->skipped = false;
        $session->recovery = null;
        $session->save();

        return $this->state->payload();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updatePreferences(array $data): array
    {
        $prefs = $this->factory->preferences();
        $allowed = [
            'sound_enabled', 'routine_locked', 'show_live_video_panel',
            'overload_increment', 'overload_frequency', 'current_week', 'current_day',
            'preferred_rest_times', 'custom_exercise_videos',
        ];
        $prefs->fill(collect($data)->only($allowed)->all());
        if (isset($data['current_day']) && ! in_array($data['current_day'], config('ironforge.days'), true)) {
            throw ValidationException::withMessages(['current_day' => 'Ongeldige dag.']);
        }
        $prefs->save();

        return [
            'preferences' => [
                'current_week' => (int) $prefs->current_week,
                'current_day' => $prefs->current_day,
                'routine_locked' => (bool) $prefs->routine_locked,
                'sound_enabled' => (bool) $prefs->sound_enabled,
                'show_live_video_panel' => (bool) $prefs->show_live_video_panel,
                'overload_increment' => (float) $prefs->overload_increment,
                'overload_frequency' => $prefs->overload_frequency,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateProfile(array $data): array
    {
        $profile = $this->factory->profile();
        $profile->fill(collect($data)->only([
            'birth_year', 'body_weight_kg', 'experience_level', 'equipment',
        ])->all());
        $profile->save();

        return [
            'profile' => [
                'birth_year' => $profile->birth_year,
                'body_weight_kg' => $profile->body_weight_kg,
                'experience_level' => $profile->experience_level,
                'equipment' => $profile->equipment,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateSession(WorkoutSession $session, ?int $duration, ?int $avgRest, ?int $restCount = null, ?bool $skipped = null, ?string $recovery = null): array
    {
        if ($recovery !== null && $recovery !== ($session->recovery ?? 'unknown')) {
            $session->loadMissing('slots.sets');
            if ($session->slots->contains(fn ($slot): bool => $slot->sets->contains('completed', true))) {
                throw ValidationException::withMessages(['recovery' => 'De herstelcheck staat vast zodra je sets hebt opgeslagen.']);
            }
            $session->recovery = $recovery;
            if ($this->periodization->isDeloadWeek((int) $session->week)) {
                foreach ($session->slots as $slot) {
                    $slot->update(['progression_plan' => null]);
                    foreach ($slot->sets as $set) {
                        $changes = [];
                        foreach (['weight', 'reps'] as $field) {
                            if (! ($set->input_fields[$field] ?? false)) {
                                $changes[$field] = '';
                            }
                        }
                        $set->update($changes);
                    }
                }
            }
        }
        if ($skipped !== null) {
            $session->skipped = $skipped;
        }
        if ($duration !== null) {
            $session->actual_duration = $duration;
        }
        if ($avgRest !== null) {
            $session->actual_avg_rest = $avgRest;
        }
        if ($restCount !== null) {
            $session->actual_rest_count = $restCount;
            if ($restCount === 0) {
                $session->actual_avg_rest = null;
            }
        }
        $session->save();

        return [
            'session' => [
                'id' => $session->id,
                'actual_duration' => $session->actual_duration,
                'actual_avg_rest' => $session->actual_avg_rest,
                'actual_rest_count' => $session->actual_rest_count,
                'skipped' => (bool) $session->skipped,
                'recovery' => $session->recovery ?? 'unknown',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyOverloadAndAdvance(int $fromWeek): array
    {
        $cycle = $this->factory->ensureCurrent();
        $prefs = $this->factory->preferences();
        $nextWeek = min($cycle->total_weeks, $fromWeek + 1);

        $toSessions = $cycle->sessions()->where('week', $nextWeek)->with('slots.sets')->get();
        foreach ($toSessions as $session) {
            foreach ($session->slots as $slot) {
                if ($slot->sets->contains(fn ($set): bool => $set->completed || ! empty($set->input_fields) || $set->reps !== '')) {
                    continue;
                }
                $progress = $this->advisor->exerciseProgress($cycle, $fromWeek, $slot->selected_name);
                if (! $progress['completedSets']) {
                    continue;
                }
                $next = $this->periodization->nextProgression($progress, $nextWeek, (float) $prefs->overload_increment, $prefs->overload_frequency);
                $slot->update(['progression_plan' => ['weight' => $next['weight'], 'reps' => $next['reps'], 'minReps' => $next['minReps'], 'maxReps' => $next['maxReps']], 'target_reps' => $next['reps']]);
                foreach ($slot->sets->take($next['requiredSets']) as $set) {
                    if (! ($set->input_fields['weight'] ?? false)) {
                        $set->update(['weight' => (string) $next['weight']]);
                    }
                }
            }
        }

        $prefs->current_week = $nextWeek;
        $prefs->current_day = 'mon';
        $prefs->save();

        return $this->state->payload();
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>|null  $schema
     * @return array<string, mixed>
     */
    public function startNextCycle(?array $schema = null, bool $closeCurrentPeriod = false): array
    {
        $current = $this->factory->ensureCurrent();
        $prefs = $this->factory->preferences();
        $advice = $this->nextCycle->forCycle($current, (int) $prefs->current_week);
        if (! $advice['available']) {
            throw ValidationException::withMessages([
                'cycle' => $advice['reason'],
            ]);
        }

        if (! $closeCurrentPeriod) {
            throw ValidationException::withMessages([
                'close_current_period' => 'Sluit eerst de huidige periode bewust af. Open trainingen blijven onvoltooid in het archief.',
            ]);
        }

        $this->factory->createCycle(
            ((int) $current->number) + 1,
            true,
            $current,
            $schema ?: $advice['schema'],
        );

        return $this->state->payload();
    }

    private function guardMainScreen(string $context): void
    {
        if ($context !== 'main') {
            return;
        }
        $prefs = $this->factory->preferences();
        if ($prefs->routine_locked) {
            throw ValidationException::withMessages([
                'set' => 'Schema staat vast op het hoofdscherm. Wijzig via live training.',
            ]);
        }
    }

    private function syncNameAcrossCycle(WorkoutSlot $slot): void
    {
        $slot->loadMissing('session');
        $sessions = WorkoutSession::query()
            ->where('cycle_id', $slot->session->cycle_id)
            ->where('week', '>=', $slot->session->week)
            ->pluck('id');

        WorkoutSlot::query()
            ->whereIn('workout_session_id', $sessions)
            ->where('slot_key', $slot->slot_key)
            ->where('selected_name', '!=', $slot->selected_name)
            ->whereDoesntHave('sets', fn ($query) => $query->where('completed', true))
            ->update(['selected_name' => $slot->selected_name, 'progression_plan' => null]);
    }
}
