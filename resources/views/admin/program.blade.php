@extends('admin.layout')
@section('title', 'Programma')
@section('content')
    <h1 class="mb-1 text-xl font-black">Upper A / Upper B programma</h1>
    <p class="mb-6 text-sm text-slate-400">Ma/do = A, di/vr = B. Opslaan wijzigt het schema. Nieuwe cycli en nieuwe slots gebruiken deze defaults; bestaande gelogde sets blijven staan tot je ze in de trainer wijzigt.</p>

    @foreach ($splits as $day => $split)
        <section class="mb-8">
            <h2 class="mb-3 text-sm font-black uppercase tracking-wide text-emerald-300">{{ $split['title'] }}</h2>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($split['slots'] as $slotKey)
                    @php $slot = $slots[$slotKey] ?? null; @endphp
                    @if ($slot)
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
                                <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white">Opslaan</button>
                            </div>
                        </form>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach
@endsection
