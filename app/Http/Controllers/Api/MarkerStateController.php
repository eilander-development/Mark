<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MarkerState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkerStateController extends Controller
{
    public function show(MarkerState $state): JsonResponse
    {
        return response()->json($state->export());
    }

    public function update(Request $request, MarkerState $state): JsonResponse
    {
        $request->validate([
            'appState' => ['required', 'array'],
            'appState.weeks' => ['required', 'array'],
        ]);

        return response()->json($state->persist($request->input('appState')));
    }
}
