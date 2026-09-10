<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StateAssembler;
use Illuminate\Http\JsonResponse;

class StateController extends Controller
{
    public function __invoke(StateAssembler $assembler): JsonResponse
    {
        return response()->json($assembler->payload());
    }
}
