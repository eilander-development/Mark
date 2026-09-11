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
            'exertion' => ['sometimes', 'in:easy,good,max'],
            'context' => ['sometimes', 'in:main,live,setup'],
        ]);

        return response()->json($this->writer->updateSet($set, $data, $data['context'] ?? 'live'));
    }

    public function updateSlot(Request $request, WorkoutSlot $slot): JsonResponse
    {
        $data = $request->validate([
            'selectedName' => ['sometimes', 'string', 'max:180'],
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
        ]);

        return response()->json($this->writer->updateSession(
            $session,
            $data['actual_duration'] ?? null,
            $data['actual_avg_rest'] ?? null,
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
        ]);

        return response()->json($this->writer->applyOverloadAndAdvance((int) $data['week']));
    }

    public function nextCycleAdvice(NextCycleAdvisor $advisor, CycleFactory $factory): JsonResponse
    {
        $cycle = $factory->ensureCurrent();

        return response()->json($advisor->forCycle($cycle, (int) $factory->preferences()->current_week));
    }

    public function startCycle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'schema' => ['sometimes', 'array'],
            'schema.*' => ['sometimes', 'array'],
            'schema.*.*' => ['sometimes', 'array'],
            'schema.*.*.selectedName' => ['sometimes', 'string', 'max:160'],
            'schema.*.*.weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'schema.*.*.targetReps' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        return response()->json($this->writer->startNextCycle($data['schema'] ?? null));
    }
}
