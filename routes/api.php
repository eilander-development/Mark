<?php

use App\Http\Controllers\Api\MarkerStateController;
use App\Http\Controllers\Api\StateController;
use App\Http\Controllers\Api\ValImportController;
use App\Http\Controllers\Api\WorkoutController;
use Illuminate\Support\Facades\Route;

Route::get('/state', StateController::class);
Route::get('/marker-state', [MarkerStateController::class, 'show']);
Route::put('/marker-state', [MarkerStateController::class, 'update']);
Route::get('/weeks/{week}/report', [WorkoutController::class, 'report']);
Route::patch('/sets/{set}', [WorkoutController::class, 'updateSet']);
Route::patch('/slots/{slot}', [WorkoutController::class, 'updateSlot']);
Route::patch('/sessions/{session}', [WorkoutController::class, 'updateSession']);
Route::patch('/preferences', [WorkoutController::class, 'updatePreferences']);
Route::patch('/profile', [WorkoutController::class, 'updateProfile']);
Route::post('/days/clear', [WorkoutController::class, 'clearDay']);
Route::post('/weeks/advance', [WorkoutController::class, 'applyOverload']);
Route::get('/cycles/next-advice', [WorkoutController::class, 'nextCycleAdvice']);
Route::post('/cycles', [WorkoutController::class, 'startCycle']);
Route::get('/import/val', [ValImportController::class, 'preview']);
Route::post('/import/val', [ValImportController::class, 'import']);
