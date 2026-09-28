@extends('admin.layout')
@section('title', 'Voorkeuren')
@section('content')
    <h1 class="mb-1 text-xl font-black">Trainingsvoorkeuren</h1>
    <p class="mb-6 text-sm text-slate-400">Deze instellingen gelden ook in de trainer. Een wijziging geeft geen automatische gewichtsverhoging.</p>
    <p class="mb-4 text-sm text-slate-300">Periodedoel: <strong>{{ $trainingGoal }}</strong>. Kies een nieuw doel bij het voorbereiden van de volgende periode in de trainer.</p>
    <form method="post" action="{{ route('admin.preferences.update') }}" class="max-w-2xl space-y-5">
        @csrf
        @method('PATCH')
        <fieldset class="space-y-4 rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <legend class="px-2 font-bold">Progressive overload</legend>
            <label class="block text-sm text-slate-300">Standaard gewichtsstap (kg)
                <input type="number" step="0.5" min="0.5" max="10" name="overload_increment" value="{{ old('overload_increment', $prefs->overload_increment) }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
            </label>
            <p class="text-xs text-slate-400">Pas na het halen van de bovenkant van de reprange. Voor dumbbells: per dumbbell.</p>
            <p class="text-xs text-slate-400">{{ count($prefs->exercise_increments ?? []) }} oefening(en) met een eigen stap. Deze gaan voor de standaard. <a href="/#instellingen" class="text-blue-300 underline">Per oefening aanpassen in Instellingen</a></p>
            <label class="block text-sm text-slate-300">Opbouwritme
                <select name="overload_frequency" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
                    <option value="weekly" @selected(old('overload_frequency', $prefs->overload_frequency) === 'weekly')>Wekelijks beoordelen: eerst reps, daarna gewicht</option>
                    <option value="biweekly" @selected(old('overload_frequency', $prefs->overload_frequency) === 'biweekly')>Tweewekelijks: opbouwdoel in even weken herhalen</option>
                </select>
            </label>
            <details class="text-xs text-slate-400"><summary class="cursor-pointer text-blue-300">Wanneer krijg ik een verhoging?</summary><p class="mt-2">Alle weekdoelen moeten zijn gehaald en de inspanning moet beoordeeld zijn. Een maximale set vraagt bevestiging. Een ontbrekende beoordeling of overgeslagen training blokkeert verhoging. Deload heeft een apart hersteladvies.</p></details>
        </fieldset>
        <fieldset class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <legend class="px-2 font-bold">Navigatie in deze periode</legend>
            <label class="block text-sm text-slate-300">Geselecteerde week
                <input type="number" name="current_week" value="{{ old('current_week', $prefs->current_week) }}" min="1" max="{{ $totalWeeks }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
            </label>
            <p class="text-xs text-slate-400">Kies week 1 t/m {{ $totalWeeks }}. Dit opent een week; het rondt geen training af en start geen nieuwe periode.</p>
        </fieldset>
        <section class="rounded-2xl border border-purple-500/30 bg-purple-950/30 p-4 text-sm">
            <h2 class="font-bold text-purple-200">Herstel & deload</h2>
            <p class="mt-2 text-xs text-slate-300">Je herstelcheck vul je per training in. Week 7 gebruikt twee sets en een aangepast advies van circa 60%, 70% of 80%. Dit zijn uitlegbare appregels, geen persoonlijk bewezen herstelpercentages.</p>
            <a href="{{ route('admin.rules') }}" class="mt-2 inline-block text-xs text-blue-300 underline">Bekijk de actuele regels en onderbouwing</a>
        </section>
        <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white">Voorkeuren opslaan</button>
    </form>
@endsection
