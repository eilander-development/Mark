<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Models\WorkoutSlot;
use Illuminate\Support\Facades\DB;

class CycleFactory
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Periodization $periodization,
        private readonly NextCycleAdvisor $nextCycle,
    ) {}

    public function ensureCurrent(): Cycle
    {
        $current = Cycle::query()->where('is_current', true)->first();
        if ($current) {
            $this->ensureSkeleton($current);

            return $current;
        }

        return $this->createCycle(1);
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>|null  $schema
     */
    public function createCycle(int $number, bool $carryForward = false, ?Cycle $from = null, ?array $schema = null, string $goal = 'hypertrophy', ?array $program = null): Cycle
    {
        return DB::transaction(function () use ($number, $carryForward, $from, $schema, $goal, $program) {
            Cycle::query()->where('is_current', true)->update([
                'is_current' => false,
                'completed_at' => now(),
            ]);

            $cycle = Cycle::query()->create([
                'number' => $number,
                'training_goal' => $goal,
                'program_definition' => $program,
                'is_current' => true,
                'total_weeks' => $program ? (int) ($program['totalWeeks'] ?? 6) : (int) config('ironforge.total_weeks', 7),
                'started_at' => now()->toDateString(),
            ]);

            $this->ensureSkeleton($cycle);

            if (is_array($schema) && $schema !== []) {
                $this->applyWizardSchema($cycle, $schema);
            } elseif ($carryForward && $from) {
                $this->applyWizardSchema($cycle, $this->nextCycle->schema($from));
            }

            $cycle->load('sessions.slots');
            foreach ($cycle->sessions as $session) {
                $session->setRelation('cycle', $cycle);
                foreach ($session->slots as $slot) {
                    $slot->setRelation('session', $session);
                    $range = app(TrainingPrescription::class)->rangeForSlot($slot, (int) $this->catalog->slot($slot->slot_key)['targetReps']);
                    if ($range['minReps'] < 8 && $slot->target_reps > $range['maxReps']) {
                        $slot->update(['target_reps' => $range['minReps']]);
                    }
                }
            }

            $prefs = $this->preferences();
            $prefs->current_week = 1;
            $prefs->current_day = 'mon';
            $prefs->save();

            return $cycle->fresh();
        });
    }

    public function profile(): Profile
    {
        return Profile::query()->firstOrCreate([], [
            'birth_year' => 1984,
            'body_weight_kg' => 82,
            'experience_level' => 'intermediate',
            'equipment' => [
                'dumbbells' => true,
                'barbell' => true,
                'bench' => true,
                'bodyweight' => true,
                'pullup_bar' => false,
                'bands' => false,
            ],
        ]);
    }

    public function preferences(): Preference
    {
        return Preference::query()->firstOrCreate([], [
            'sound_enabled' => true,
            'routine_locked' => true,
            'show_live_video_panel' => true,
            'overload_increment' => config('ironforge.default_overload_increment'),
            'overload_frequency' => config('ironforge.default_overload_frequency'),
            'current_week' => 1,
            'current_day' => 'mon',
            'preferred_rest_times' => [],
            'custom_exercise_videos' => [],
        ]);
    }

    public function ensureSkeleton(Cycle $cycle): void
    {
        $days = config('ironforge.days');
        $expectedSessions = (int) $cycle->total_weeks * count($days);
        if ($cycle->sessions()->count() >= $expectedSessions) {
            return;
        }

        $catalog = $this->catalog->allSlots();
        $splits = $this->catalog->splitsForCycle($cycle);
        $setCount = (int) config('ironforge.sets_per_slot', 3);
        for ($week = 1; $week <= $cycle->total_weeks; $week++) {
            $isDeload = $this->periodization->isDeloadWeek($week);
            foreach ($days as $day) {
                $session = WorkoutSession::query()->firstOrCreate([
                    'cycle_id' => $cycle->id,
                    'week' => $week,
                    'day' => $day,
                ]);
                foreach ($splits[$day]['slots'] as $slotKey) {
                    $item = $catalog[$slotKey];
                    $prescription = $cycle->program_definition['days'][$day]['exercises'][$slotKey] ?? null;
                    $slot = WorkoutSlot::query()->firstOrCreate(
                        [
                            'workout_session_id' => $session->id,
                            'slot_key' => $slotKey,
                        ],
                        [
                            'selected_name' => $prescription['selectedName'] ?? $item['defaultName'],
                            'prescription' => $prescription ? array_intersect_key($prescription, array_flip(['sets', 'rest', 'minReps', 'maxReps', 'baseMinReps', 'baseMaxReps', 'primary', 'secondary', 'label', 'priority', 'family'])) : null,
                            'note' => $isDeload ? 'Deload Week: 70% intensiteit, 2 sets' : '',
                            'target_reps' => $prescription['targetReps'] ?? (app(TrainingPrescription::class)->range($cycle->training_goal ?? 'hypertrophy', $slotKey, (int) $item['targetReps'], $this->periodization->isBodyweight($item['defaultName']))['minReps'] < 8
                                ? 4 : $item['targetReps']),
                        ],
                    );
                    for ($i = 1; $i <= ($prescription ? $slot->requiredSetCount($week) : $setCount); $i++) {
                        WorkoutSet::query()->firstOrCreate([
                            'workout_slot_id' => $slot->id,
                            'position' => $i,
                        ], [
                            'weight' => '',
                            'reps' => '',
                            'completed' => false,
                            'is_pr' => false,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $schema
     */
    private function applyWizardSchema(Cycle $to, array $schema): void
    {
        $days = config('ironforge.days');
        foreach ($to->sessions()->with('slots.sets')->get() as $session) {
            if (! in_array($session->day, $days, true)) {
                continue;
            }
            $daySchema = $schema[$session->day] ?? [];
            if (! is_array($daySchema)) {
                continue;
            }
            foreach ($session->slots as $slot) {
                $item = $daySchema[$slot->slot_key] ?? null;
                if (! is_array($item)) {
                    continue;
                }
                $name = trim((string) ($item['selectedName'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $allowed = ($to->program_definition['type'] ?? null) === 'push_pull'
                    ? config('push_pull.roles.'.$slot->slot_key.'.exercises', []) : ($this->catalog->slot($slot->slot_key)['alternatives'] ?? []);
                if (is_array($allowed) && $allowed !== [] && ! in_array($name, $allowed, true)) {
                    continue;
                }
                $slot->selected_name = $name;
                $range = $slot->prescription ? ['minReps' => $slot->prescription['minReps'], 'maxReps' => $slot->prescription['maxReps']]
                    : app(TrainingPrescription::class)->range($to->training_goal ?? 'hypertrophy', $slot->slot_key, (int) $this->catalog->slot($slot->slot_key)['targetReps'], $this->periodization->isBodyweight($name));
                $requestedReps = (int) ($item['targetReps'] ?? $range['minReps']);
                $slot->target_reps = $range['minReps'] < 8 && $requestedReps > $range['maxReps'] ? $range['minReps'] : max($range['minReps'], min($range['maxReps'], $requestedReps));
                $slot->save();

                $weight = isset($item['weight']) ? (float) $item['weight'] : 0.0;
                if ($weight <= 0 || (int) $session->week !== 1) {
                    continue;
                }
                foreach ($slot->sets as $set) {
                    if ($set->weight === '') {
                        $set->weight = (string) $weight;
                        $set->save();
                    }
                }
            }
        }
    }
}
