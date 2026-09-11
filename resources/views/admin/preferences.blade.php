@extends('admin.layout')
@section('title', 'Voorkeuren')
@section('content')
    <h1 class="mb-1 text-xl font-black">Overload-voorkeuren</h1>
    <p class="mb-6 text-sm text-slate-400">Dit zijn dezelfde velden als de trainer naar <code>/api/preferences</code> stuurt.</p>

    <form method="post" action="{{ route('admin.preferences.update') }}" class="max-w-lg space-y-4 rounded-2xl border border-slate-800 bg-slate-900 p-5">
        @csrf
        @method('PATCH')
        <label class="block text-sm text-slate-300">
            Overload-stap (kg)
            <input type="number" step="0.5" name="overload_increment" value="{{ $prefs->overload_increment }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
        </label>
        <label class="block text-sm text-slate-300">
            Frequentie
            <select name="overload_frequency" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
                <option value="weekly" @selected($prefs->overload_frequency === 'weekly')>Wekelijks (elke overload-week +kg)</option>
                <option value="biweekly" @selected($prefs->overload_frequency === 'biweekly')>Tweewekelijks (even week herhalen)</option>
            </select>
        </label>
        <label class="block text-sm text-slate-300">
            Huidige week
            <input type="number" name="current_week" value="{{ $prefs->current_week }}" min="1" max="14" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
        </label>
        <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white">Opslaan</button>
    </form>
@endsection
