<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\PeriodTemplate;
use App\Models\ProgramVariant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProgramLibrary
{
    public function seedVariants(): void
    {
        foreach (config('push_pull.defaults') as $family => $variants) {
            $keys = config('push_pull.families.'.$family.'.slots');
            foreach ($variants as $letter => $names) {
                $exercises = [];
                foreach ($names as $index => $name) {
                    $exercises[$keys[$index]] = $this->prescription($keys[$index], $name);
                }
                ProgramVariant::query()->firstOrCreate(['key' => $family.'_'.strtolower($letter)], [
                    'family' => $family, 'name' => ucfirst($family).' '.$letter,
                    'description' => config('push_pull.families.'.$family.'.muscles'), 'exercises' => $exercises,
                ]);
            }
        }
    }

    public function seedTemplates(): void
    {
        foreach (config('push_pull.templates') as $key => $template) {
            PeriodTemplate::query()->firstOrCreate(['key' => $key], $template);
        }
    }

    /** @return array<string, mixed> */
    public function backup(): array
    {
        $this->ensureDefaults();

        return ['variants' => ProgramVariant::query()->get()->toArray(), 'templates' => PeriodTemplate::query()->get()->toArray()];
    }

    /** @param array<string, mixed> $library */
    public function restore(array $library): void
    {
        $validated = Validator::make($library, [
            'variants' => ['required', 'array', 'max:100'],
            'variants.*.key' => ['required', 'string', 'max:100'],
            'variants.*.family' => ['required', 'in:push,pull'],
            'variants.*.name' => ['required', 'string', 'max:100'],
            'variants.*.description' => ['nullable', 'string', 'max:600'],
            'variants.*.archived' => ['sometimes', 'boolean'],
            'variants.*.exercises' => ['required', 'array', 'size:5'],
            'variants.*.exercises.*.selectedName' => ['required', 'string', 'max:160'],
            'variants.*.exercises.*.sets' => ['required', 'integer', 'between:1,5'],
            'variants.*.exercises.*.minReps' => ['required', 'integer', 'between:4,30'],
            'variants.*.exercises.*.maxReps' => ['required', 'integer', 'between:4,30'],
            'variants.*.exercises.*.rest' => ['required', 'integer', 'between:30,300'],
            'templates' => ['required', 'array', 'max:100'],
            'templates.*.key' => ['required', 'string', 'max:100'],
            'templates.*.name' => ['required', 'string', 'max:100'],
            'templates.*.description' => ['nullable', 'string', 'max:600'],
            'templates.*.archived' => ['sometimes', 'boolean'],
            'templates.*.days' => ['required', 'array:mon,tue,thu,fri', 'size:4'],
        ])->validate();
        ProgramVariant::query()->whereNotIn('key', array_column($validated['variants'], 'key'))->update(['archived' => true]);
        PeriodTemplate::query()->whereNotIn('key', array_column($validated['templates'], 'key'))->update(['archived' => true]);
        foreach ($validated['variants'] as $variant) {
            $keys = config('push_pull.families.'.$variant['family'].'.slots');
            foreach ($variant['exercises'] as $key => $item) {
                if (! in_array($key, $keys, true) || ! in_array($item['selectedName'], config('push_pull.roles.'.$key.'.exercises', []), true) || $item['maxReps'] < $item['minReps']) {
                    throw ValidationException::withMessages(['library' => 'De backup bevat een ongeldige dagvariant.']);
                }
            }
            ProgramVariant::query()->updateOrCreate(['key' => $variant['key']], array_intersect_key($variant, array_flip(['name', 'family', 'description', 'exercises', 'archived'])));
        }
        foreach ($validated['templates'] as $template) {
            foreach ($template['days'] as $day => $key) {
                if (! ProgramVariant::query()->where('key', $key)->where('family', in_array($day, ['mon', 'thu'], true) ? 'push' : 'pull')->exists()) {
                    throw ValidationException::withMessages(['library' => 'Het periodevoorstel verwijst naar een ontbrekende variant.']);
                }
            }
            PeriodTemplate::query()->updateOrCreate(['key' => $template['key']], array_intersect_key($template, array_flip(['name', 'description', 'days', 'archived'])));
        }
    }

    /** @param array<string, mixed>|null $definition */
    public function validateDefinition(?array $definition): void
    {
        if ($definition === null) {
            return;
        }
        Validator::make(['program' => $definition], [
            'program.type' => ['required', 'in:push_pull,legacy'],
            'program.days' => ['required', 'array:mon,tue,thu,fri', 'size:4'],
            'program.days.*.title' => ['required', 'string', 'max:200'],
            'program.days.*.exercises' => ['required', 'array', 'min:1', 'max:12'],
            'program.days.*.exercises.*.selectedName' => ['required', 'string', 'max:180'],
            'program.days.*.exercises.*.sets' => ['required', 'integer', 'between:1,5'],
            'program.days.*.exercises.*.minReps' => ['required', 'integer', 'between:1,200'],
            'program.days.*.exercises.*.maxReps' => ['required', 'integer', 'between:1,200'],
            'program.totalWeeks' => ['sometimes', 'integer', 'between:6,7'],
        ])->validate();
        foreach ($definition['days'] as $day) {
            foreach ($day['exercises'] as $key => $item) {
                if (! isset(config('ironforge.catalog')[$key]) || $item['maxReps'] < $item['minReps']) {
                    throw ValidationException::withMessages(['program' => 'De backup bevat een ongeldig trainingsvoorschrift.']);
                }
            }
        }
    }

    public function ensureDefaults(): void
    {
        if (! ProgramVariant::query()->exists()) {
            $this->seedVariants();
        }
        if (! PeriodTemplate::query()->exists()) {
            $this->seedTemplates();
        }
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        $this->ensureDefaults();

        return ['families' => config('push_pull.families'), 'roles' => config('push_pull.roles'),
            'exerciseRequirements' => collect(config('push_pull.roles'))->pluck('exercises')->flatten()->unique()->mapWithKeys(fn (string $name): array => [$name => $this->requirements($name)])->all(),
            'variants' => ProgramVariant::query()->where('archived', false)->orderBy('family')->orderBy('name')->get()->toArray(),
            'templates' => PeriodTemplate::query()->where('archived', false)->orderBy('id')->get()->toArray()];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function prescription(string $slotKey, string $name, string $goal = 'hypertrophy', array $overrides = []): array
    {
        $role = config('push_pull.roles.'.$slotKey, []);
        $result = array_merge($role, $overrides, ['selectedName' => $name]);
        $result['minReps'] = $overrides['baseMinReps'] ?? $overrides['minReps'] ?? $role['minReps'];
        $result['maxReps'] = $overrides['baseMaxReps'] ?? $overrides['maxReps'] ?? $role['maxReps'];
        unset($result['exercises']);
        if ($slotKey === 'slot_a3' && str_contains(mb_strtolower($name), 'press') && ! isset($overrides['minReps'])) {
            $result['minReps'] = 8;
            $result['maxReps'] = 12;
            $result['rest'] = max(150, (int) $result['rest']);
            $result['secondary'] = ['triceps', 'shoulders'];
        }
        if ($name === 'Dumbbell Pullover') {
            $result['secondary'] = ['chest', 'triceps'];
        }
        $result['baseMinReps'] = $result['minReps'];
        $result['baseMaxReps'] = $result['maxReps'];
        if (! app(Periodization::class)->isBodyweight($name)) {
            if ($goal === 'strength' && ($role['priority'] ?? false)) {
                $result['minReps'] = 4;
                $result['maxReps'] = 6;
                $result['rest'] = max(180, (int) $result['rest']);
            } elseif ($goal === 'combined' && in_array($slotKey, ['slot_a1', 'slot_a2'], true)) {
                $result['minReps'] = 6;
                $result['maxReps'] = 8;
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function requirements(string $name): array
    {
        $known = config('push_pull.equipment', []);
        if (array_key_exists($name, $known)) {
            return $known[$name];
        }
        $name = mb_strtolower($name);
        $requirements = [];
        if (str_contains($name, 'barbell')) {
            $requirements[] = 'barbell';
        }
        if (str_contains($name, 'dumbbell') || str_contains($name, 'curl') && ! str_contains($name, 'barbell') || str_contains($name, 'rear delt') || str_contains($name, 'arnold')) {
            $requirements[] = 'dumbbells';
        }
        if (str_contains($name, 'bench') && ! str_contains($name, 'floor') || str_contains($name, 'incline') || str_contains($name, 'chest-supported') || str_contains($name, 'seated') || str_contains($name, 'spider') || str_contains($name, 'liggend op bankje')) {
            $requirements[] = 'bench';
        }
        if (str_contains($name, 'incline') || str_contains($name, 'chest-supported') || str_contains($name, 'spider') || str_contains($name, 'seated') && str_contains($name, 'press')) {
            $requirements[] = 'adjustable_bench';
        }
        if (str_contains($name, 'barbell') && (str_contains($name, 'press') || str_contains($name, 'ohp')) && ! str_contains($name, 'extension')) {
            $requirements[] = 'rack';
        }
        if (str_contains($name, 'pull-up') || str_contains($name, 'chin-up')) {
            $requirements[] = 'pullup_bar';
        }

        return array_values(array_unique($requirements));
    }

    /** @param array<string, bool> $equipment */
    public function compatible(string $name, array $equipment): bool
    {
        foreach ($this->requirements($name) as $required) {
            if (! ($equipment[$required] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $dayVariants
     * @param  array<string, array<string, array<string, mixed>>>  $schema
     * @param  array<string, bool>  $equipment
     * @return array<string, mixed>
     */
    public function build(Cycle $current, ?string $templateKey = null, array $dayVariants = [], string $goal = 'hypertrophy', array $schema = [], array $equipment = []): array
    {
        $this->ensureDefaults();
        if ($templateKey === 'repeat') {
            $definition = $current->program_definition ?: $this->legacyDefinition($current);
            $definition['key'] = 'repeat';
            $definition['name'] = 'Huidige indeling herhalen';
            foreach ($definition['days'] as &$dayDefinition) {
                foreach ($dayDefinition['exercises'] as &$item) {
                    unset($item['weight'], $item['weightEdited']);
                }
                unset($item);
            }
            unset($dayDefinition);
            foreach ($dayVariants as $day => $key) {
                if (($definition['days'][$day]['variantKey'] ?? null) === $key) {
                    continue;
                }
                $family = in_array($day, ['mon', 'thu'], true) ? 'push' : 'pull';
                $variant = ProgramVariant::query()->where('key', $key)->where('family', $family)->where('archived', false)->first();
                if (! $variant || $definition['type'] !== 'push_pull') {
                    throw ValidationException::withMessages(['dayVariants.'.$day => 'Kies een beschikbare '.ucfirst($family).'-variant.']);
                }
                $definition['days'][$day] = ['variantKey' => $variant->key, 'variantName' => $variant->name, 'family' => $family,
                    'title' => app(Periodization::class)->dayName($day).': '.$variant->name,
                    'version' => hash('sha256', json_encode([$variant->name, $variant->exercises])), 'exercises' => $variant->exercises];
            }
            $definition['totalWeeks'] = $definition['type'] === 'push_pull' ? 6 : 7;

        } else {
            $query = PeriodTemplate::query()->where('archived', false);
            $template = $templateKey === null ? $query->orderBy('id')->first() : $query->where('key', $templateKey)->first();
            if (! $template) {
                throw ValidationException::withMessages(['templateKey' => 'Kies een beschikbaar periodevoorstel.']);
            }
            $definition = ['type' => 'push_pull', 'trainingWeeks' => 6, 'totalWeeks' => 6, 'optionalRecovery' => true, 'key' => $template->key, 'name' => $template->name, 'focus' => $template->description, 'days' => []];
            foreach (config('ironforge.days') as $day) {
                $family = in_array($day, ['mon', 'thu'], true) ? 'push' : 'pull';
                $key = $dayVariants[$day] ?? $template->days[$day] ?? null;
                $variant = ProgramVariant::query()->where('key', $key)->where('family', $family)->where('archived', false)->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['dayVariants.'.$day => 'Kies een beschikbare '.ucfirst($family).'-variant.']);
                }
                $definition['days'][$day] = ['variantKey' => $variant->key, 'variantName' => $variant->name, 'family' => $family,
                    'title' => app(Periodization::class)->dayName($day).': '.$variant->name,
                    'version' => hash('sha256', json_encode([$variant->name, $variant->exercises])), 'exercises' => $variant->exercises];
            }
        }
        foreach ($schema as $day => $items) {
            if (! isset($definition['days'][$day]) || ! is_array($items)) {
                throw ValidationException::withMessages(['schema' => 'Het schema bevat een onbekende trainingsdag.']);
            }
            foreach ($items as $key => $item) {
                if (! isset($definition['days'][$day]['exercises'][$key]) || ! is_array($item)) {
                    throw ValidationException::withMessages(['schema' => 'Het schema bevat een onbekende oefeningspositie.']);
                }
            }
        }
        foreach ($definition['days'] as $day => &$dayDefinition) {
            $selectedNames = $definition['type'] === 'push_pull'
                ? $this->resolveDayExercises($dayDefinition['exercises'], $equipment, $day) : [];
            foreach ($dayDefinition['exercises'] as $key => &$item) {
                $override = $schema[$day][$key] ?? [];
                $baseName = $item['selectedName'];
                $name = trim((string) ($override['selectedName'] ?? $baseName));
                $allowed = $definition['type'] === 'push_pull' ? config('push_pull.roles.'.$key.'.exercises', []) : app(Catalog::class)->slot($key)['alternatives'];
                if (! in_array($name, $allowed, true)) {
                    throw ValidationException::withMessages(['schema.'.$day.'.'.$key.'.selectedName' => 'Deze oefening past niet bij deze positie.']);
                }
                $originalName = $name;
                if ($definition['type'] === 'push_pull') {
                    if (isset($override['selectedName']) && $name !== $selectedNames[$key]) {
                        throw ValidationException::withMessages(['schema.'.$day.'.'.$key.'.selectedName' => 'Kies een dagvariant. Losse oefeningen aanpassen kan via Beheer → Programma.']);
                    }
                    $name = $selectedNames[$key];
                }
                $missingEquipment = array_values(array_filter($this->requirements($originalName), fn (string $required): bool => ! ($equipment[$required] ?? false)));
                if ($definition['type'] === 'push_pull') {
                    $item = $this->prescription($key, $name, $goal, $name === $baseName ? $item : array_intersect_key($item, array_flip(['sets', 'position'])));
                } else {
                    $item['selectedName'] = $name;
                }
                $item['materialAlternative'] = $name !== $originalName ? $originalName : null;
                $item['missingEquipment'] = $name !== $originalName ? $missingEquipment : [];
                if ($name !== $baseName) {
                    $dayDefinition['customized'] = true;
                }
                if (array_key_exists('weight', $override)) {
                    $item['weight'] = $override['weight'] === null ? null : (float) $override['weight'];
                    $item['weightEdited'] = (bool) ($override['weightEdited'] ?? true);
                }
                $item['targetReps'] = max($item['minReps'], min($item['maxReps'], (int) ($override['targetReps'] ?? $item['minReps'])));
            }
            unset($item);
            if (($dayDefinition['customized'] ?? false) && ! str_ends_with($dayDefinition['title'], ' · aangepast')) {
                $dayDefinition['title'] .= ' · aangepast';
            }
        }
        unset($dayDefinition);

        return $definition;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, bool>  $equipment
     * @return array<string, string>
     */
    private function resolveDayExercises(array $items, array $equipment, string $day): array
    {
        $preferred = [];
        foreach ($items as $key => $item) {
            $name = $item['selectedName'];
            if (! in_array($name, config('push_pull.roles.'.$key.'.exercises', []), true)) {
                throw ValidationException::withMessages(['schema.'.$day.'.'.$key.'.selectedName' => 'Deze oefening past niet bij deze positie.']);
            }
            if ($this->compatible($name, $equipment)) {
                $preferred[$key] = $name;
            }
        }
        $choices = [];
        foreach ($items as $key => $item) {
            $compatible = array_values(array_filter(config('push_pull.roles.'.$key.'.exercises', []),
                fn (string $name): bool => $this->compatible($name, $equipment)));
            $unreserved = array_values(array_filter($compatible, fn (string $name): bool => ! in_array($name, $preferred, true)));
            $choices[$key] = array_values(array_unique([...(isset($preferred[$key]) ? [$preferred[$key]] : []), ...$unreserved, ...$compatible]));
        }
        $selected = $this->matchDistinctExercises($choices);
        if ($selected === null) {
            throw ValidationException::withMessages(['schema.'.$day => 'Geen verschillende, passende oefeningen voor deze dag. Controleer je materiaal in je profiel of kies andere oefeningen of een andere dagvariant.']);
        }

        return $selected;
    }

    /**
     * @param  array<string, list<string>>  $choices
     * @param  array<string, string>  $selected
     * @return array<string, string>|null
     */
    private function matchDistinctExercises(array $choices, array $selected = []): ?array
    {
        if (count($choices) === count($selected)) {
            return $selected;
        }
        $key = array_keys($choices)[count($selected)];
        foreach ($choices[$key] as $name) {
            if (in_array($name, $selected, true)) {
                continue;
            }
            $match = $this->matchDistinctExercises($choices, [...$selected, $key => $name]);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function legacyDefinition(Cycle $cycle): array
    {
        $definition = ['type' => 'legacy', 'key' => 'repeat', 'name' => 'Huidige indeling herhalen', 'days' => []];
        foreach (app(Catalog::class)->splits($cycle->training_goal ?? 'hypertrophy', (int) $cycle->number) as $day => $split) {
            $session = $cycle->sessions()->where('week', 1)->where('day', $day)->with('slots')->first();
            $items = [];
            foreach ($split['slots'] as $key) {
                $slot = $session?->slots->firstWhere('slot_key', $key);
                $catalog = app(Catalog::class)->slot($key);
                $range = app(TrainingPrescription::class)->range($cycle->training_goal ?? 'hypertrophy', $key, $slot?->target_reps ?? $catalog['targetReps']);
                $items[$key] = ['selectedName' => $slot?->selected_name ?? $catalog['defaultName'], 'sets' => 3, 'rest' => $catalog['restTime'], ...$range];
            }
            $definition['days'][$day] = ['title' => $split['title'], 'variantName' => 'Bestaande indeling', 'exercises' => $items];
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, array<string, mixed>>
     */
    public function splits(array $definition): array
    {
        $result = [];
        foreach ($definition['days'] as $day => $item) {
            $result[$day] = ['title' => $item['title'], 'slots' => array_keys($item['exercises']), 'variantKey' => $item['variantKey'] ?? null, 'variantName' => $item['variantName'] ?? '', 'family' => $item['family'] ?? null];
        }

        return $result;
    }
}
