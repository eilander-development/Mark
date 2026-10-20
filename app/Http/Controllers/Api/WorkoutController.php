<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Models\WorkoutSlot;
use App\Services\CycleFactory;
use App\Services\NextCycleAdvisor;
use App\Services\StateAssembler;
use App\Services\WorkoutWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkoutController extends Controller
{
    public function __construct(private readonly WorkoutWriter $writer) {}

    public function updateSet(Request $request, WorkoutSet $set): JsonResponse
    {
        $data = $request->validate([
            'weight' => ['sometimes', 'nullable'],
            'reps' => ['sometimes', 'nullable'],
            'completed' => ['sometimes', 'boolean'],
            'exertion' => ['sometimes', 'in:unknown,easy,good,max'],
            'inputFields' => ['sometimes', 'array:weight,reps'],
            'inputFields.*' => ['boolean'],
            'context' => ['sometimes', 'in:main,live,setup'],
        ]);

        return response()->json($this->writer->updateSet($set, $data, $data['context'] ?? 'live'));
    }

    public function updateSlot(Request $request, WorkoutSlot $slot): JsonResponse
    {
        $data = $request->validate([
            'selectedName' => ['sometimes', 'string', 'max:180'],
            'targetReps' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'progressionPlan' => ['sometimes', 'nullable', 'array:weight,reps,minReps,maxReps'],
            'progressionPlan.weight' => ['required_with:progressionPlan', 'numeric', 'min:0'],
            'progressionPlan.reps' => ['required_with:progressionPlan', 'integer', 'min:1', 'max:200'],
            'progressionPlan.minReps' => ['integer', 'min:1', 'max:200', 'required_with:progressionPlan.maxReps'],
            'progressionPlan.maxReps' => ['integer', 'min:1', 'max:200', 'required_with:progressionPlan.minReps', 'gte:progressionPlan.minReps'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'context' => ['sometimes', 'in:main,live,setup'],
        ]);

        return response()->json($this->writer->updateSlot($slot, $data, $data['context'] ?? 'main'));
    }

    public function clearDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'week' => ['required', 'integer', 'min:1', 'max:14'],
            'day' => ['required', 'in:mon,tue,thu,fri'],
        ]);

        return response()->json($this->writer->clearDay((int) $data['week'], $data['day']));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sound_enabled' => ['sometimes', 'boolean'],
            'routine_locked' => ['sometimes', 'boolean'],
            'show_live_video_panel' => ['sometimes', 'boolean'],
            'overload_increment' => ['sometimes', 'numeric', 'min:0.5', 'max:10'],
            'exercise_increments' => ['sometimes', 'array', 'max:100'],
            'exercise_increments.*' => ['required', 'numeric', 'min:0.5', 'max:10'],
            'overload_frequency' => ['sometimes', 'in:weekly,biweekly'],
            'current_week' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'current_day' => ['sometimes', 'in:mon,tue,thu,fri'],
            'preferred_rest_times' => ['sometimes', 'array'],
            'custom_exercise_videos' => ['sometimes', 'array'],
        ]);

        return response()->json($this->writer->updatePreferences($data));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'birth_year' => ['sometimes', 'integer', 'min:1940', 'max:2015'],
            'body_weight_kg' => ['sometimes', 'integer', 'min:40', 'max:200'],
            'experience_level' => ['sometimes', 'in:beginner,intermediate,advanced'],
            'equipment' => ['sometimes', 'array'],
        ]);

        return response()->json($this->writer->updateProfile($data));
    }

    public function updateSession(Request $request, WorkoutSession $session): JsonResponse
    {
        $data = $request->validate([
            'actual_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'actual_avg_rest' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'actual_rest_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'skipped' => ['sometimes', 'boolean'],
            'recovery' => ['sometimes', 'in:unknown,recovered,tired,exhausted'],
            'core_workout' => ['sometimes', 'array'],
        ]);

        return response()->json($this->writer->updateSession(
            $session,
            $data['actual_duration'] ?? null,
            $data['actual_avg_rest'] ?? null,
            $data['actual_rest_count'] ?? null,
            $data['skipped'] ?? null,
            $data['recovery'] ?? null,
            $data['core_workout'] ?? null,
        ));
    }

    public function report(int $week, StateAssembler $assembler): JsonResponse
    {
        return response()->json($assembler->weekReport($week));
    }

    public function applyOverload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'week' => ['required', 'integer', 'min:1', 'max:14'],
            'recovery_week' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->writer->applyOverloadAndAdvance((int) $data['week'], (bool) ($data['recovery_week'] ?? false)));
    }

    public function nextCycleAdvice(Request $request, NextCycleAdvisor $advisor, CycleFactory $factory): JsonResponse
    {
        $cycle = $factory->ensureCurrent();

        $data = $request->validate([
            'templateKey' => ['sometimes', 'string', 'max:100'],
            'training_goal' => ['sometimes', 'in:hypertrophy,strength,combined'],
            'dayVariants' => ['sometimes', 'array:mon,tue,thu,fri'],
            'dayVariants.*' => ['string', 'max:100'],
        ]);

        return response()->json($advisor->forCycle($cycle, (int) $factory->preferences()->current_week,
            $data['templateKey'] ?? null, $data['dayVariants'] ?? [], $data['training_goal'] ?? null));
    }

    public function previewCycle(Request $request, NextCycleAdvisor $advisor, CycleFactory $factory): JsonResponse
    {
        $data = $request->validate([
            'templateKey' => ['required', 'string', 'max:100'],
            'training_goal' => ['required', 'in:hypertrophy,strength,combined'],
            'dayVariants' => ['sometimes', 'array:mon,tue,thu,fri'],
            'dayVariants.*' => ['string', 'max:100'],
            'schema' => ['sometimes', 'array:mon,tue,thu,fri'],
            'schema.*.*.selectedName' => ['sometimes', 'string', 'max:160'],
            'schema.*.*.weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'schema.*.*.weightEdited' => ['sometimes', 'boolean'],
        ]);

        return response()->json($advisor->forCycle($factory->ensureCurrent(), (int) $factory->preferences()->current_week,
            $data['templateKey'], $data['dayVariants'] ?? [], $data['training_goal'], $data['schema'] ?? []));
    }

    public function startCycle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'training_goal' => ['sometimes', 'in:hypertrophy,strength,combined'],
            'close_current_period' => ['sometimes', 'boolean'],
            'schema' => ['sometimes', 'array'],
            'schema.*' => ['sometimes', 'array'],
            'schema.*.*' => ['sometimes', 'array'],
            'schema.*.*.selectedName' => ['sometimes', 'string', 'max:160'],
            'schema.*.*.weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'schema.*.*.targetReps' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'schema.*.*.weightEdited' => ['sometimes', 'boolean'],
            'templateKey' => ['sometimes', 'string', 'max:100'],
            'dayVariants' => ['sometimes', 'array:mon,tue,thu,fri'],
            'dayVariants.*' => ['string', 'max:100'],
            'programVersions' => ['sometimes', 'array:mon,tue,thu,fri'],
            'programVersions.*' => ['nullable', 'string', 'max:100'],
            'cycleId' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($this->writer->startNextCycle($data['schema'] ?? null, (bool) ($data['close_current_period'] ?? false), $data['training_goal'] ?? 'hypertrophy', $data));
    }
}
