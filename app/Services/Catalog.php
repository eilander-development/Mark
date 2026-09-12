<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\ProgramSlot;
use Illuminate\Support\Collection;

class Catalog
{
    private bool $synced = false;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $slotsCache = null;

    /**
     * @return array<string, mixed>
     */
    public function slot(string $slotKey): array
    {
        return $this->allSlots()[$slotKey] ?? config('ironforge.catalog.'.$slotKey, []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allSlots(): array
    {
        if ($this->slotsCache !== null) {
            return $this->slotsCache;
        }

        $this->sync();

        $this->slotsCache = ProgramSlot::query()
            ->orderBy('slot_key')
            ->get()
            ->mapWithKeys(fn (ProgramSlot $slot) => [$slot->slot_key => $slot->toCatalogArray()])
            ->all();

        return $this->slotsCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function splits(): array
    {
        return config('ironforge.splits');
    }

    /**
     * @return Collection<int, Exercise>
     */
    public function exercises(): Collection
    {
        $this->sync();

        return Exercise::query()->orderBy('name')->get();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function videoLibrary(): array
    {
        $this->sync();

        return Exercise::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Exercise $exercise) => [$exercise->name => $exercise->toVideoArray()])
            ->all();
    }

    public function missingVideoCount(): int
    {
        $this->sync();

        return Exercise::query()->where(function ($query) {
            $query->whereNull('youtube_id')->orWhere('youtube_id', '');
        })->count();
    }

    public function isVerifiedAthlean(?string $youtubeId): bool
    {
        if ($youtubeId === null || $youtubeId === '') {
            return false;
        }

        return in_array($youtubeId, $this->verifiedAthleanIds(), true);
    }

    /**
     * @param  array<string, mixed>  $video
     */
    public function channelForVideo(array $video): string
    {
        $id = isset($video['videoId']) && is_string($video['videoId']) ? $video['videoId'] : null;
        if ($this->isVerifiedAthlean($id)) {
            return 'ATHLEAN-X™';
        }

        $channel = isset($video['channel']) && is_string($video['channel']) ? $video['channel'] : '';
        if ($channel !== '' && str_contains(mb_strtoupper($channel), 'ATHLEAN')) {
            return '';
        }

        return $channel;
    }

    public function nextAlternative(string $slotKey, string $currentName): string
    {
        $slot = $this->slot($slotKey);
        $alternatives = array_values(array_filter(
            array_map(static fn ($name) => is_string($name) ? $name : '', $slot['alternatives'] ?? []),
        ));
        if ($alternatives === []) {
            return $currentName !== '' ? $currentName : (string) ($slot['defaultName'] ?? '');
        }

        $index = array_search($currentName, $alternatives, true);
        if ($index === false) {
            return $alternatives[0];
        }

        return $alternatives[($index + 1) % count($alternatives)];
    }

    public function sync(): void
    {
        if ($this->synced) {
            return;
        }

        $this->synced = true;

        $catalog = config('ironforge.catalog', []);
        $needsSeed = ProgramSlot::query()->count() < count($catalog)
            || Exercise::query()->count() < $this->expectedExerciseCount($catalog);

        if ($needsSeed) {
            $this->seedMissingCatalog($catalog);
        }

        $this->applyBuiltinVideos();
        $this->slotsCache = null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private function seedMissingCatalog(array $catalog): void
    {
        foreach ($catalog as $slotKey => $item) {
            ProgramSlot::query()->firstOrCreate(
                ['slot_key' => $slotKey],
                [
                    'default_name' => $item['defaultName'],
                    'target_reps' => $item['targetReps'],
                    'rest_type' => $item['restType'] ?? '',
                    'rest_time' => $item['restTime'] ?? 90,
                    'muscles' => $item['muscles'] ?? '',
                    'equipment' => $item['equipment'] ?? '',
                    'tips' => $item['tips'] ?? [],
                    'alternatives' => $item['alternatives'] ?? [],
                ],
            );
        }

        $names = [];
        foreach ($this->allSlotsFromDbOrConfig() as $item) {
            foreach ($item['alternatives'] ?? [] as $name) {
                $names[$name] = true;
            }
            if (! empty($item['defaultName'])) {
                $names[$item['defaultName']] = true;
            }
        }
        foreach (array_keys($this->builtinVideos()) as $name) {
            $names[$name] = true;
        }

        foreach (array_keys($names) as $name) {
            $video = $this->resolveBuiltinVideo($name) ?? [];
            $youtubeId = isset($video['videoId']) && is_string($video['videoId']) && $video['videoId'] !== ''
                ? $video['videoId']
                : '';
            Exercise::query()->firstOrCreate(
                ['name' => $name],
                [
                    'youtube_id' => $youtubeId,
                    'title' => $video['title'] ?? $name,
                    'channel' => $this->channelForVideo($video) ?: '',
                    'cues' => $video['cues'] ?? [],
                ],
            );
        }
    }

    /**
     * @return list<string>
     */
    public function verifiedAthleanIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = require database_path('data/athlean-verified-ids.php');
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveBuiltinVideo(string $name): ?array
    {
        $library = $this->builtinVideos();
        if (isset($library[$name])) {
            return $library[$name];
        }

        $canonical = $this->builtinAliases()[$name] ?? null;
        if ($canonical !== null && isset($library[$canonical])) {
            return $library[$canonical];
        }

        return $this->fuzzyMatch($name, $library);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function builtinVideos(): array
    {
        static $videos = null;
        if ($videos === null) {
            $videos = require database_path('data/athlean-videos.php');
        }

        return $videos;
    }

    /**
     * @return array<string, string>
     */
    public function builtinAliases(): array
    {
        static $aliases = null;
        if ($aliases === null) {
            $aliases = require database_path('data/athlean-aliases.php');
        }

        return $aliases;
    }

    public function applyBuiltinVideos(): void
    {
        $names = array_unique(array_merge(
            array_keys($this->builtinVideos()),
            array_keys($this->builtinAliases()),
            Exercise::query()->pluck('name')->all(),
        ));

        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $video = $this->resolveBuiltinVideo($name);
            if ($video === null) {
                continue;
            }

            $exercise = Exercise::query()->firstOrNew(['name' => $name]);
            if ($exercise->exists && ! $this->shouldRefreshFromLibrary($exercise, $video)) {
                continue;
            }

            $youtubeId = isset($video['videoId']) && is_string($video['videoId']) && $video['videoId'] !== ''
                ? $video['videoId']
                : '';

            $exercise->fill([
                'youtube_id' => $youtubeId,
                'title' => $video['title'] ?? $name,
                'channel' => $this->channelForVideo($video) ?: '',
                'cues' => $video['cues'] ?? [],
            ])->save();
        }

        $this->stripFalseAthleanLabels();
    }

    /**
     * @param  array<string, mixed>  $video
     */
    private function shouldRefreshFromLibrary(Exercise $exercise, array $video = []): bool
    {
        if ($exercise->youtube_id === null || $exercise->youtube_id === '') {
            return true;
        }

        if ($this->hasFalseAthleanLabel($exercise)) {
            return true;
        }

        $libraryId = isset($video['videoId']) && is_string($video['videoId']) ? $video['videoId'] : '';
        $currentId = (string) $exercise->youtube_id;

        if ($this->isVerifiedAthlean($libraryId) && ! $this->isVerifiedAthlean($currentId)) {
            return true;
        }

        return $libraryId !== ''
            && $libraryId !== $currentId
            && $this->isReplaceableLibraryId($currentId);
    }

    private function isReplaceableLibraryId(string $youtubeId): bool
    {
        if ($youtubeId === '') {
            return false;
        }

        if (in_array($youtubeId, $this->retiredVideoIds(), true)) {
            return true;
        }

        foreach ($this->builtinVideos() as $video) {
            $id = isset($video['videoId']) && is_string($video['videoId']) ? $video['videoId'] : '';
            if ($id === $youtubeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function retiredVideoIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = require database_path('data/retired-video-ids.php');
        }

        return $ids;
    }

    private function hasFalseAthleanLabel(Exercise $exercise): bool
    {
        return str_contains((string) $exercise->channel, 'ATHLEAN')
            && ! $this->isVerifiedAthlean((string) $exercise->youtube_id);
    }

    private function stripFalseAthleanLabels(): void
    {
        $verified = $this->verifiedAthleanIds();

        $query = Exercise::query()
            ->where('channel', 'like', '%ATHLEAN%')
            ->where(function ($query) use ($verified): void {
                $query->whereNull('youtube_id')
                    ->orWhere('youtube_id', '');
                if ($verified !== []) {
                    $query->orWhereNotIn('youtube_id', $verified);
                }
            });

        if ($query->exists()) {
            $query->update(['channel' => '']);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private function expectedExerciseCount(array $catalog): int
    {
        $names = array_merge(array_keys($this->builtinVideos()), array_keys($this->builtinAliases()));
        foreach ($catalog as $item) {
            $names[] = $item['defaultName'] ?? '';
            foreach ($item['alternatives'] ?? [] as $name) {
                $names[] = $name;
            }
        }

        return count(array_unique(array_filter($names)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function allSlotsFromDbOrConfig(): array
    {
        $rows = ProgramSlot::query()->get();
        if ($rows->isNotEmpty()) {
            return $rows->mapWithKeys(fn (ProgramSlot $slot) => [$slot->slot_key => $slot->toCatalogArray()])->all();
        }

        return config('ironforge.catalog', []);
    }

    /**
     * @param  array<string, array<string, mixed>>  $library
     * @return array<string, mixed>|null
     */
    private function fuzzyMatch(string $name, array $library): ?array
    {
        $n = mb_strtolower($name);
        $rules = [
            ['needles' => ['bench press', 'incline'], 'key' => 'Incline Dumbbell Press'],
            ['needles' => ['bench press'], 'key' => 'Barbell Bench Press'],
            ['needles' => ['floor press'], 'key' => 'Dumbbell Floor Press'],
            ['needles' => ['push-up'], 'key' => 'Opdrukken (Klassieke Push-ups)'],
            ['needles' => ['pushup'], 'key' => 'Opdrukken (Klassieke Push-ups)'],
            ['needles' => ['opdrukken'], 'key' => 'Opdrukken (Klassieke Push-ups)'],
            ['needles' => ['pendlay'], 'key' => 'Pendlay Row (Dead Stop)'],
            ['needles' => ['one-arm'], 'key' => 'Dumbbell One-Arm Row'],
            ['needles' => ['one arm'], 'key' => 'Dumbbell One-Arm Row'],
            ['needles' => ['row', 'dumbbell'], 'key' => 'Dumbbell One-Arm Row'],
            ['needles' => ['row'], 'key' => 'Barbell Row'],
            ['needles' => ['pullover'], 'key' => 'Dumbbell Pullover'],
            ['needles' => ['shoulder press'], 'key' => 'Seated Dumbbell Shoulder Press'],
            ['needles' => ['overhead press'], 'key' => 'Barbell Overhead Press (OHP)'],
            ['needles' => ['ohp'], 'key' => 'Barbell Overhead Press (OHP)'],
            ['needles' => ['military'], 'key' => 'Barbell Overhead Press (OHP)'],
            ['needles' => ['shrug'], 'key' => 'Dumbbell Shrugs'],
            ['needles' => ['farmer'], 'key' => 'Barbell Shrugs'],
            ['needles' => ['upright row'], 'key' => 'Dumbbell Lateral Raises'],
            ['needles' => ['front raise'], 'key' => 'Dumbbell Lateral Raises'],
            ['needles' => ['around the world'], 'key' => 'Dumbbell Lateral Raises'],
            ['needles' => ['lateral raise'], 'key' => 'Dumbbell Lateral Raises'],
            ['needles' => ['rear delt'], 'key' => 'Rear Delt Flyes'],
            ['needles' => ['face pull'], 'key' => 'Face Pulls'],
            ['needles' => ['y-raise'], 'key' => 'Rear Delt Flyes'],
            ['needles' => ['bench dip'], 'key' => 'Bench Dips'],
            ['needles' => ['dip'], 'key' => 'Dips'],
            ['needles' => ['hammer curl'], 'key' => 'Hammer Curls (Dumbbells)'],
            ['needles' => ['curl'], 'key' => 'Dumbbell Bicep Curls'],
            ['needles' => ['skull crusher'], 'key' => 'Lying Dumbbell Tricep Extension / Skull Crushers'],
            ['needles' => ['lying', 'tricep'], 'key' => 'Lying Dumbbell Tricep Extension / Skull Crushers'],
            ['needles' => ['jm press'], 'key' => 'Close-Grip Barbell Bench Press'],
            ['needles' => ['tate press'], 'key' => 'Lying Dumbbell Tricep Extension / Skull Crushers'],
            ['needles' => ['svend'], 'key' => 'Barbell Bench Press'],
            ['needles' => ['fly'], 'key' => 'Dumbbell Chest Flyes'],
            ['needles' => ['hex press'], 'key' => 'Incline Dumbbell Press'],
            ['needles' => ['kickback'], 'key' => 'Dumbbell Overhead Tricep Extension'],
            ['needles' => ['tricep'], 'key' => 'Dumbbell Overhead Tricep Extension'],
            ['needles' => ['extension'], 'key' => 'Dumbbell Overhead Tricep Extension'],
            ['needles' => ['pull-up'], 'key' => 'Pull-ups (Optrekken Bovengreep)'],
            ['needles' => ['chin-up'], 'key' => 'Chin-ups (Optrekken Ondergreep)'],
            ['needles' => ['optrekken'], 'key' => 'Pull-ups (Optrekken Bovengreep)'],
            ['needles' => ['landmine', 'press'], 'key' => 'Barbell Overhead Press (OHP)'],
            ['needles' => ['push press'], 'key' => 'Barbell Overhead Press (OHP)'],
        ];

        foreach ($rules as $rule) {
            $matches = true;
            foreach ($rule['needles'] as $needle) {
                if (! str_contains($n, $needle)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches && isset($library[$rule['key']])) {
                return $library[$rule['key']];
            }
        }

        return null;
    }
}
