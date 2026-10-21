<?php

namespace App\Services;

use App\Models\Cycle;
use Illuminate\Validation\ValidationException;

class NextCycleAdvisor
{
    public function __construct(
        private readonly ProgramLibrary $library,
        private readonly ExerciseHistory $history,
    ) {}

    /**
     * @param  array<string, string>  $dayVariants
     * @param  array<string, array<string, array<string, mixed>>>  $overrides
     * @return array<string, mixed>
     */
    public function forCycle(Cycle $cycle, int $currentWeek, ?string $templateKey = null, array $dayVariants = [], ?string $goal = null, array $overrides = []): array
    {
        $goal ??= $cycle->training_goal ?? 'hypertrophy';
        $library = $this->library->export();
        $history = $this->history->all();
        $keys = array_column($library['templates'], 'key');
        $previousKey = $cycle->program_definition['key'] ?? null;
        $index = array_search($previousKey, $keys, true);
        $recommended = $keys[$index === false ? 0 : ($index + 1) % max(1, count($keys))] ?? null;
        $selected = $templateKey ?? $recommended ?? 'repeat';
        $proposals = [];
        foreach ([...$library['templates'], ['key' => 'repeat', 'name' => 'Huidige indeling herhalen', 'description' => 'Dezelfde indeling, met een nieuw startadvies.']] as $template) {
            try {
                $definition = $this->library->build($cycle, $template['key'], $template['key'] === $selected ? $dayVariants : [], $goal,
                    $template['key'] === $selected ? $overrides : []);
                $proposals[$template['key']] = $this->enrich($cycle, $definition, $history);
            } catch (ValidationException $exception) {
                if (($templateKey !== null || $dayVariants !== [] || $overrides !== []) && $selected === $template['key']) {
                    throw $exception;
                }
                $proposals[$template['key']] = ['programTemplate' => ['key' => $template['key'], 'name' => $template['name']],
                    'schema' => [], 'days' => [], 'unavailableReason' => collect($exception->errors())->flatten()->first()];
            }
        }
        if ($templateKey !== null && ! isset($proposals[$selected])) {
            throw ValidationException::withMessages(['templateKey' => 'Kies een beschikbaar periodevoorstel.']);
        }
        $proposal = $proposals[$selected] ?? ['schema' => [], 'days' => [], 'programTemplate' => null];

        return [...$proposal, 'available' => $currentWeek >= $cycle->total_weeks,
            'cycleId' => $cycle->id, 'currentWeek' => $currentWeek, 'totalWeeks' => (int) $cycle->total_weeks,
            'reason' => $currentWeek >= $cycle->total_weeks
                ? 'Kies je volgende Push/Pull-periode. Start pas wanneer je de huidige periode bewust afsluit; open trainingen blijven onvoltooid.'
                : 'Een nieuwe periode start na week '.$cycle->total_weeks.'. Je kunt het voorstel alvast bekijken.',
            'exerciseHistory' => $history, 'library' => $library, 'proposals' => $proposals,
            'recommendedTemplateKey' => $recommended, 'trainingGoal' => $goal];
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    public function schema(Cycle $from): array
    {
        return $this->forCycle($from, (int) $from->total_weeks)['schema'];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function enrich(Cycle $cycle, array $definition, array $history): array
    {
        $cycle->loadMissing('sessions.slots');
        $schema = [];
        $days = [];
        $muscles = [];
        foreach ($definition['days'] as $day => &$dayDefinition) {
            $slots = [];
            $source = $cycle->sessions->where('week', app(Periodization::class)->lastHeavyWeek((int) $cycle->total_weeks))->firstWhere('day', $day);
            foreach ($dayDefinition['exercises'] as $key => &$item) {
                $name = $item['selectedName'];
                $last = $history[mb_strtolower(trim($name))] ?? null;
                $start = $this->history->startAdvice($last, $item);
                $item['weight'] = ($item['weightEdited'] ?? false) ? $item['weight'] : $start['weight'];
                $item['weightSource'] = ($item['weightEdited'] ?? false) ? 'eigen_invoer' : $start['source'];
                $item['weightReason'] = ($item['weightEdited'] ?? false) ? 'Jouw eigen startgewicht.' : $start['reason'];
                $item['lastPerformed'] = $last;
                $item['fromName'] = $source?->slots->firstWhere('slot_key', $key)?->selected_name ?? '';
                $item['rotated'] = $item['fromName'] !== $name;
                $item['isBodyweight'] = app(Periodization::class)->isBodyweight($name);
                $item['requirements'] = $this->library->requirements($name);
                $item['weightLabel'] = app(ExerciseVolume::class)->rules()[mb_strtolower($name)]['weightLabel'] ?? ($item['isBodyweight'] ? 'Lichaamsgewicht' : 'Kg totaal');
                $schema[$day][$key] = $item;
                $slots[] = ['slotKey' => $key, ...$item];
                if (isset($item['primary'])) {
                    $muscles[$item['primary']] ??= ['direct' => 0, 'supporting' => 0];
                    $muscles[$item['primary']]['direct'] += $item['sets'];
                    foreach ($item['secondary'] ?? [] as $secondary) {
                        $muscles[$secondary] ??= ['direct' => 0, 'supporting' => 0];
                        $muscles[$secondary]['supporting'] += $item['sets'];
                    }
                }
            }
            unset($item);
            $days[] = ['day' => $day, 'title' => $dayDefinition['title'], 'variantKey' => $dayDefinition['variantKey'] ?? null,
                ...$this->library->overview($dayDefinition['exercises']), 'slots' => $slots];
        }
        unset($dayDefinition);
        $definition['splits'] = $this->library->splits($definition);
        $definition['cycleNumber'] = (int) $cycle->number + 1;

        return ['programTemplate' => $definition, 'schema' => $schema, 'days' => $days, 'weekMuscles' => $muscles];
    }
}
