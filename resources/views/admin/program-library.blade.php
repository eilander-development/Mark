@extends('admin.layout')
@section('title', 'Push/Pull-programma')
@section('content')
    <h1 class="mb-2 text-xl font-black">Push/Pull-programma</h1>
    <p class="mb-3 text-sm text-slate-400">Maandag en donderdag Push: borst, voorste/zijkant schouders en triceps. Dinsdag en vrijdag Pull: rug, achterste schouders, biceps en trapezius. Kies per dag een vaste variant; behoud die tijdens de periode om progressive overload te volgen.</p>
    <p class="mb-5 text-sm text-slate-400">Beheer hier de bouwstenen. Wijzigingen gelden voor nieuwe toepassingen. Gestarte en afgeronde periodes bewaren hun eigen oefeningen en voorschriften. Startgewichten volgen de persoonlijke oefeningshistorie.</p>
    <nav class="mb-6 flex flex-wrap gap-3 text-sm"><a href="#dagvarianten" class="rounded-xl bg-blue-600 px-4 py-2">Dagvarianten</a><a href="#periodevoorstellen" class="rounded-xl border border-slate-700 px-4 py-2">Periodevoorstellen</a><a href="{{ route('admin.exercises') }}" class="rounded-xl border border-slate-700 px-4 py-2">Oefeningen, video en uitleg</a></nav>
    <h2 id="dagvarianten" class="mb-3 text-lg font-bold">Dagvarianten Push A–F en Pull A–F</h2>
    @foreach ($families as $family => $info)
        <h3 class="mb-3 mt-5 font-bold">{{ $info['label'] }} · {{ $info['muscles'] }}</h3>
        <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($variants->where('family', $family) as $variant)
            <details id="variant-{{ $variant->id }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <summary class="cursor-pointer font-bold">{{ $variant->name }}{{ $variant->archived ? ' · Gearchiveerd' : '' }} <span class="text-xs font-normal text-slate-400">· {{ collect($variant->exercises)->sum('sets') }} werksets</span></summary>
                <form method="post" action="{{ route('admin.variants.update', $variant) }}" class="mt-4 space-y-4">
                    @csrf @method('PATCH')
                    <label class="block text-xs text-slate-400">Naam<input name="name" value="{{ $variant->name }}" required maxlength="100" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"></label>
                    <label class="block text-xs text-slate-400">Omschrijving<textarea name="description" rows="2" maxlength="600" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm">{{ $variant->description }}</textarea></label>
                    @foreach ($variant->exercises as $key => $item)
                        <fieldset class="rounded-xl border border-slate-800 p-3">
                            <legend class="px-1 text-xs font-bold">{{ $roles[$key]['label'] }}</legend>
                            <select aria-label="{{ $roles[$key]['label'] }}" name="exercises[{{ $key }}][selectedName]" class="mb-3 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-2 text-sm">
                                @foreach ($roles[$key]['exercises'] as $name)<option @selected($name === $item['selectedName'])>{{ $name }}</option>@endforeach
                            </select>
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                                @foreach (['position' => 'Volgorde', 'sets' => 'Sets', 'minReps' => 'Vanaf reps', 'maxReps' => 'Tot reps', 'rest' => 'Rust (sec)'] as $field => $label)
                                    <label class="text-[11px] text-slate-400">{{ $label }}<input type="number" name="exercises[{{ $key }}][{{ $field }}]" value="{{ $item[$field] ?? $loop->parent->iteration }}" min="{{ $field === 'rest' ? 30 : ($field === 'sets' || $field === 'position' ? 1 : 4) }}" max="{{ $field === 'rest' ? 300 : ($field === 'sets' || $field === 'position' ? 5 : 30) }}" required class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-2 text-sm"></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    <input type="hidden" name="archived" value="0">
                    <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="archived" value="1" @checked($variant->archived)>Archiveren voor nieuwe toepassingen</label>
                    <button class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-bold">Variant opslaan</button>
                </form>
                <form method="post" action="{{ route('admin.variants.duplicate', $variant) }}" class="mt-3">@csrf<button class="rounded-lg border border-slate-700 px-3 py-2 text-xs">Variant dupliceren</button></form>
            </details>
        @endforeach
        </div>
    @endforeach
    <h2 id="periodevoorstellen" class="mb-3 mt-8 text-lg font-bold">Periodevoorstellen samenstellen</h2>
    <p class="mb-4 text-sm text-slate-400">Een voorstel bevat vier dagvarianten. Je kiest het voorstel in de app en kunt het daar nog aanpassen. De weekbelasting hieronder telt alleen directe werksets; ondersteunende belasting komt daar afzonderlijk bij.</p>
    <div class="grid gap-4 lg:grid-cols-2">
    @foreach ($templates as $template)
        <details class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <summary class="cursor-pointer font-bold">{{ $template->name }}{{ $template->archived ? ' · Gearchiveerd' : '' }}</summary>
            <form method="post" action="{{ route('admin.templates.update', $template) }}" class="mt-4 space-y-3">
                @csrf @method('PATCH')
                <label class="block text-xs text-slate-400">Naam<input name="name" value="{{ $template->name }}" required maxlength="100" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"></label>
                <label class="block text-xs text-slate-400">Omschrijving<textarea name="description" rows="2" maxlength="600" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">{{ $template->description }}</textarea></label>
                @php $volume = []; @endphp
                @foreach (['mon' => 'Maandag · Push', 'tue' => 'Dinsdag · Pull', 'thu' => 'Donderdag · Push', 'fri' => 'Vrijdag · Pull'] as $day => $label)
                    @php
                        $family = in_array($day, ['mon', 'thu']) ? 'push' : 'pull';
                        $chosen = $variants->firstWhere('key', $template->days[$day] ?? null);
                        foreach ($chosen?->exercises ?? [] as $key => $item) {
                            $group = $roles[$key]['primary'];
                            $volume[$group] = ($volume[$group] ?? 0) + $item['sets'];
                        }
                    @endphp
                    <label class="block text-xs text-slate-400">{{ $label }}<select name="days[{{ $day }}]" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
                        @foreach ($variants->where('family', $family)->where('archived', false) as $variant)<option value="{{ $variant->key }}" @selected(($template->days[$day] ?? null) === $variant->key)>{{ $variant->name }}</option>@endforeach
                    </select></label>
                @endforeach
                <p class="text-xs text-slate-400">Huidige directe weeksets: @foreach($volume as $group => $sets){{ config('push_pull.roles') ? (['chest'=>'borst','back'=>'rug','shoulders'=>'schouders','biceps'=>'biceps','triceps'=>'triceps','traps'=>'trapezius'][$group] ?? $group) : $group }} {{ $sets }}{{ $loop->last ? '.' : ' · ' }}@endforeach Na opslaan wordt dit opnieuw berekend.</p>
                <input type="hidden" name="archived" value="0">
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="archived" value="1" @checked($template->archived)>Voorstel archiveren</label>
                <button class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-bold">Voorstel opslaan</button>
            </form>
            <form method="post" action="{{ route('admin.templates.duplicate', $template) }}" class="mt-3">@csrf<button class="rounded-lg border border-slate-700 px-3 py-2 text-xs">Voorstel dupliceren</button></form>
        </details>
    @endforeach
    </div>
@endsection
