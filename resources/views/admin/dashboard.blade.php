@extends('admin.layout')
@section('title', 'Overzicht')
@section('content')
    <h1 class="mb-1 text-xl font-black">Beheer overzicht</h1>
    <p class="mb-6 text-sm text-slate-400">Hier pas je de inrichting van de app aan. Voor wat je vandaag moet trainen en je volgende opbouwstap gebruik je de trainer en het weekrapport.</p>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('admin.program') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Programma</div>
            <div class="text-2xl font-black text-emerald-300">{{ $slotCount }} oefenplekken</div>
            <p class="mt-1 text-xs text-slate-400">Basisschema: oefeningen, startreps en rust</p>
        </a>
        <a href="{{ route('admin.exercises') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Oefeningen</div>
            <div class="text-2xl font-black text-sky-300">{{ $exerciseCount }}</div>
            <p class="mt-1 text-xs text-slate-400">{{ $missingVideos === 0 ? 'Elke catalogusrij heeft een form-video' : $missingVideos.' zonder video' }}</p>
        </a>
        <a href="{{ route('admin.rules') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Overload</div>
            <div class="text-2xl font-black text-amber-300">Stap: {{ $overloadIncrement }} kg</div>
            <p class="mt-1 text-xs text-slate-400">Eerst reps · {{ $overloadFrequency === 'weekly' ? 'wekelijks beoordelen' : 'tweewekelijks beoordelen' }}</p>
        </a>
        <a href="{{ route('admin.preferences') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Voorkeuren</div>
            <div class="text-2xl font-black text-slate-100">Instellen</div>
            <p class="mt-1 text-xs text-slate-400">Gewichtsstap, opbouwritme en weekkeuze</p>
        </a>
    </div>

    <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5 text-sm leading-relaxed text-slate-300">
        <h2 class="mb-2 text-sm font-bold text-slate-200">Wanneer gebruik je welke pagina?</h2>
        <ul class="list-disc space-y-2 pl-5">
            <li><strong>Programma:</strong> verander een standaardoefening, startreps of rusttijd. Controleer vóór toepassen welke nog niet gestarte trainingen worden aangepast.</li>
            <li><strong>Oefeningen:</strong> controleer of vervang een instructievideo. De uitklapbare spierindeling verklaart de weekteller; dit is geen trainingsadvies.</li>
            <li><strong>Voorkeuren:</strong> kies de standaard gewichtsstap en het opbouwritme. Je eigen stappen per oefening stel je in via Instellingen in de trainer.</li>
            <li><strong>Trainingsregels:</strong> lees hoe adviezen worden berekend en wat onderzoek ondersteunt. Je hoeft hier niets in te vullen.</li>
        </ul>
        <a href="/" class="mt-4 inline-block text-blue-300 underline">Terug naar mijn training en rapportages</a>
    </div>
@endsection
