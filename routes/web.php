<?php

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

Route::prefix('beheer')->name('admin.')->middleware('cache.headers:no_store;no_cache;must_revalidate')->group(function () {
    Route::get('/login', [AdminController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminController::class, 'login'])->middleware('throttle:5,1')->name('authenticate');
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');

    Route::middleware('admin.password')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/programma', [AdminController::class, 'program'])->name('program');
        Route::patch('/programma/{slot}', [AdminController::class, 'updateProgram'])->name('program.update');
        Route::post('/programma/{slot}/toepassen', [AdminController::class, 'applyProgram'])->name('program.apply');
        Route::get('/oefeningen', [AdminController::class, 'exercises'])->name('exercises');
        Route::patch('/oefeningen/{exercise}', [AdminController::class, 'updateExercise'])->name('exercises.update');
        Route::get('/regels', [AdminController::class, 'rules'])->name('rules');
        Route::get('/voorkeuren', [AdminController::class, 'preferences'])->name('preferences');
        Route::patch('/voorkeuren', [AdminController::class, 'updatePreferences'])->name('preferences.update');
    });
});

Route::get('/sw.js', function () {
    $path = resource_path('js/ironforge-sw.js');
    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
});

Route::get('/manifest.json', function () {
    $path = public_path('manifest.json');
    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/manifest+json; charset=UTF-8',
        'Cache-Control' => 'no-cache',
    ]);
});

Route::get('/{any?}', function () {
    $path = resource_path('ironforge.html');
    abort_unless(is_file($path), 500, 'Marker-UI ontbreekt. Run: node scripts/port-marker-ui.mjs');

    $html = file_get_contents($path);
    $vite = '';
    try {
        $vite = (string) Vite::useBuildDirectory('build')
            ->withEntryPoints(['resources/css/app.css', 'resources/js/app.ts']);
    } catch (Throwable) {
        $vite = '';
    }
    if ($vite !== '') {
        $html = (string) preg_replace(
            '#<!-- Tailwind CSS v4 CDN -->\s*<script src="https://cdn\.jsdelivr\.net/npm/@tailwindcss/browser@4"></script>#',
            '',
            $html,
        );
        $html = str_replace('</head>', $vite.'</head>', $html);
    }

    return response($html, 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->where('any', '^(?!beheer|api).*');
