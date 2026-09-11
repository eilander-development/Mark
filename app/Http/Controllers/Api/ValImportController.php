<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StateAssembler;
use App\Services\ValTownImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ValImportController extends Controller
{
    public function preview(ValTownImporter $importer): JsonResponse
    {
        try {
            return response()->json($importer->preview());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function import(Request $request, ValTownImporter $importer, StateAssembler $assembler): JsonResponse
    {
        $request->validate([
            'confirm' => ['accepted'],
        ]);

        try {
            $result = $importer->import(true);

            return response()->json([
                ...$result,
                'state' => $assembler->payload(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
