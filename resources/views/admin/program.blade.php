@extends('admin.layout')
@section('title', 'Programma')
@section('content')
    <h1 class="mb-1 text-xl font-black">Upper A / Upper B programma</h1>
    <p class="mb-6 text-sm text-slate-400">Ma/do = A, di/vr = B. Opslaan wijzigt het schema. Nieuwe periodes gebruiken deze standaardwaarden. Opgeslagen trainingen blijven behouden. Open een oefening om de instellingen te wijzigen.</p>

    @foreach (collect($splits)->unique(fn ($split) => implode('|', $split['slots'])) as $day => $split)
        <section class="mb-8">
            <h2 class="mb-3 text-sm font-black uppercase tracking-wide text-slate-200">{{ $day === 'mon' ? 'Upper A · maandag en donderdag' : ($day === 'tue' ? 'Upper B · dinsdag en vrijdag' : $split['title']) }}</h2>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($split['slots'] as $slotKey)
                    @php $slot = $slots[$slotKey] ?? null; @endphp
                    @if ($slot)
                        <details class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                            <summary class="cursor-pointer text-sm font-bold">{{ $slot->default_name }} <span class="text-xs font-normal text-slate-400">· {{ $slot->target_reps }} reps · {{ $slot->rest_time }}s rust</span></summary>
                        <form method="post" action="{{ route('admin.program.update', $slot) }}" class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900 p-4">
                            @csrf
                            @method('PATCH')
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-xs text-slate-500">{{ $slotKey }}</span>
                                <span class="text-[10px] font-mono text-slate-500">{{ $slot->rest_type }} · rust {{ $slot->rest_time }}s</span>
                            </div>
                            <label class="block text-xs text-slate-400">
                                Default oefening
                                <input name="default_name" value="{{ $slot->default_name }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <label class="text-xs text-slate-400">
                                    Doelreps
                                    <input type="number" name="target_reps" value="{{ $slot->target_reps }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm">
                                </label>
                                <label class="text-xs text-slate-400">
                                    Rust (sec)
                                    <input type="number" name="rest_time" value="{{ $slot->rest_time }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm">
                                </label>
                                <label class="text-xs text-slate-400">
                                    Type
                                    <input name="rest_type" value="{{ $slot->rest_type }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm">
                                </label>
                            </div>
                            <label class="block text-xs text-slate-400">
                                Alternatieven (één per regel)
                                <textarea name="alternatives" rows="5" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs">{{ implode("\n", $slot->alternatives ?? []) }}</textarea>
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-bold text-white">Opslaan</button>
                            </div>
                        </form>
                        </details>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach
@endsection
