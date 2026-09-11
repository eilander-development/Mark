<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StateAssembler;
use App\Services\TrainingBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class BackupController extends Controller
{
    public function export(TrainingBackup $backup): JsonResponse
    {
        $payload = $backup->payload();
        $backup->storeLatest($payload);

        return response()
            ->json($payload)
            ->header('Content-Disposition', 'attachment; filename="'.$backup->filename().'"');
    }

    public function import(Request $request, TrainingBackup $backup, StateAssembler $assembler): JsonResponse
    {
        $request->validate([
            'confirm' => ['accepted'],
            'backup' => ['required', 'file', 'max:2048'],
        ]);

        $raw = (string) $request->file('backup')?->getContent();
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return response()->json(['message' => 'Backup is geen geldige JSON.'], 422);
        }

        if (! is_array($payload)) {
            return response()->json(['message' => 'Backup is geen geldige JSON.'], 422);
        }

        try {
            $result = $backup->import($payload);

            return response()->json([
                ...$result,
                'state' => $assembler->payload(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
