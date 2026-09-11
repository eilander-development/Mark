@extends('admin.layout')
@section('title', 'Beheer inloggen')
@section('content')
    <div class="mx-auto max-w-sm rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <h1 class="mb-1 text-xl font-black">Beheer</h1>
        <p class="mb-4 text-sm text-slate-400">Voer het beheerwachtwoord in om programma, video’s en regels te wijzigen.</p>
        <form method="POST" action="{{ route('admin.authenticate') }}" class="space-y-3">
            @csrf
            <label class="block text-xs font-mono uppercase tracking-wider text-slate-500">Wachtwoord</label>
            <input type="password" name="password" autofocus class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-emerald-500/60">
            <button type="submit" class="w-full rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-500">Inloggen</button>
        </form>
    </div>
@endsection
