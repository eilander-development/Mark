<?php

namespace App\Http\Controllers;

use App\Models\PeriodTemplate;
use App\Models\ProgramVariant;
use App\Services\ProgramLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProgramLibraryController extends Controller
{
    public function __construct(private readonly ProgramLibrary $library) {}

    public function index(): View
    {
        $this->library->ensureDefaults();

        return view('admin.program-library', [
            'variants' => ProgramVariant::query()->orderBy('family')->orderBy('name')->get(),
            'templates' => PeriodTemplate::query()->orderBy('id')->get(),
            'families' => config('push_pull.families'), 'roles' => config('push_pull.roles'),
            'variantOverviews' => ProgramVariant::query()->get()->mapWithKeys(fn (ProgramVariant $variant): array => [$variant->id => $this->library->overview($variant->exercises)]),
        ]);
    }

    public function updateVariant(Request $request, ProgramVariant $variant): RedirectResponse
    {
        $keys = config('push_pull.families.'.$variant->family.'.slots');
        $rules = ['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:600'],
            'archived' => ['sometimes', 'boolean'], 'exercises' => ['required', 'array:'.implode(',', $keys), 'size:'.count($keys)]];
        foreach ($keys as $key) {
            $prefix = 'exercises.'.$key;
            $rules[$prefix] = ['required', 'array'];
            $rules[$prefix.'.selectedName'] = ['required', Rule::in(config('push_pull.roles.'.$key.'.exercises'))];
            $rules[$prefix.'.sets'] = ['required', 'integer', 'between:1,5'];
            $rules[$prefix.'.minReps'] = ['required', 'integer', 'between:4,30'];
            $rules[$prefix.'.maxReps'] = ['required', 'integer', 'between:4,30', 'gte:'.$prefix.'.minReps'];
            $rules[$prefix.'.rest'] = ['required', 'integer', 'between:30,300'];
            $rules[$prefix.'.position'] = ['required', 'integer', 'between:1,5'];
        }
        $data = $request->validate($rules);
        if (($data['archived'] ?? false) && PeriodTemplate::query()->where('archived', false)->get()->contains(fn (PeriodTemplate $template): bool => in_array($variant->key, $template->days, true))) {
            throw ValidationException::withMessages(['archived' => 'Deze variant staat nog in een actief periodevoorstel. Kies daar eerst een andere variant. Gestarte periodes blijven behouden.']);
        }
        $exercises = [];
        foreach ($data['exercises'] as $key => $item) {
            $exercises[$key] = $this->library->prescription($key, $item['selectedName'], 'hypertrophy', [
                'sets' => (int) $item['sets'], 'minReps' => (int) $item['minReps'], 'maxReps' => (int) $item['maxReps'],
                'rest' => (int) $item['rest'], 'position' => (int) $item['position'],
            ]);
        }
        if (count(array_unique(array_column($exercises, 'selectedName'))) !== count($keys)) {
            throw ValidationException::withMessages(['exercises' => 'Kies per dag verschillende oefeningen.']);
        }
        if (count(array_unique(array_column($exercises, 'position'))) !== count($keys)) {
            throw ValidationException::withMessages(['exercises' => 'Geef iedere oefening een unieke positie van 1 tot 5.']);
        }
        uasort($exercises, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        $variant->update(['name' => $data['name'], 'description' => $data['description'] ?? '',
            'exercises' => $exercises, 'archived' => (bool) ($data['archived'] ?? false)]);

        return back()->with('status', $variant->name.' opgeslagen. Alleen nieuwe toepassingen gebruiken deze versie.');
    }

    public function duplicateVariant(ProgramVariant $variant): RedirectResponse
    {
        $variant->replicate()->fill(['key' => $variant->family.'_'.Str::uuid(), 'name' => $variant->name.' (kopie)', 'archived' => false])->save();

        return back()->with('status', 'Variant gekopieerd. Pas de kopie hieronder aan.');
    }

    public function updateTemplate(Request $request, PeriodTemplate $template): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:600'], 'archived' => ['sometimes', 'boolean'],
            'days' => ['required', 'array:mon,tue,thu,fri', 'size:4'], 'days.*' => ['required', 'string', 'max:100']]);
        foreach (config('ironforge.days') as $day) {
            $family = in_array($day, ['mon', 'thu'], true) ? 'push' : 'pull';
            if (! ProgramVariant::query()->where('key', $data['days'][$day] ?? null)->where('family', $family)->where('archived', false)->exists()) {
                throw ValidationException::withMessages(['days.'.$day => 'Kies een actieve '.ucfirst($family).'-variant.']);
            }
        }
        $template->update(['name' => $data['name'], 'description' => $data['description'] ?? '',
            'days' => $data['days'], 'archived' => (bool) ($data['archived'] ?? false)]);

        return back()->with('status', 'Periodevoorstel opgeslagen. Gestarte periodes blijven ongewijzigd.');
    }

    public function duplicateTemplate(PeriodTemplate $template): RedirectResponse
    {
        $template->replicate()->fill(['key' => 'custom_'.Str::uuid(), 'name' => $template->name.' (kopie)', 'archived' => false])->save();

        return back()->with('status', 'Periodevoorstel gekopieerd.');
    }
}
