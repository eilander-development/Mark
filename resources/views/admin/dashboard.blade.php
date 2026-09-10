@extends('admin.layout')
@section('title', 'Overzicht')
@section('content')
    <h1 class="mb-1 text-xl font-black">Waar IronForge nu leeft</h1>
    <p class="mb-6 text-sm text-slate-400">Programma, video's en overload zaten in de marker-JS en <code>config/ironforge.php</code>. Dat is nu beheerbaar. Sets en cycli stonden al in MySQL.</p>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('admin.program') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Programma</div>
            <div class="text-2xl font-black text-emerald-300">{{ $slotCount }} slots</div>
            <p class="mt-1 text-xs text-slate-400">4 dagen · default oefening · reps · rust</p>
        </a>
        <a href="{{ route('admin.exercises') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Oefeningen</div>
            <div class="text-2xl font-black text-sky-300">{{ $exerciseCount }}</div>
            <p class="mt-1 text-xs text-slate-400">{{ $missingVideos === 0 ? 'Elke catalogusrij heeft een form-video' : $missingVideos.' zonder video' }}</p>
        </a>
        <a href="{{ route('admin.rules') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Overload</div>
            <div class="text-2xl font-black text-amber-300">+{{ $overloadIncrement }} kg</div>
            <p class="mt-1 text-xs text-slate-400">{{ $overloadFrequency === 'weekly' ? 'Wekelijks' : 'Tweewekelijks' }} · week {{ $currentWeek }}</p>
        </a>
        <a href="{{ route('admin.preferences') }}" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-[10px] font-mono uppercase text-slate-500">Voorkeuren</div>
            <div class="text-2xl font-black text-slate-100">Live</div>
            <p class="mt-1 text-xs text-slate-400">Increment, frequentie, huidige week</p>
        </a>
    </div>

    <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5 text-sm leading-relaxed text-slate-300">
        <h2 class="mb-2 text-sm font-black uppercase tracking-wide text-slate-200">Wat je hier wél / niet beheert</h2>
        <ul class="list-disc space-y-1 pl-5">
            <li><strong class="text-slate-100">Wel:</strong> welke oefening in welk slot hoort, doelreps, rust, form-video, overload-stap. ATHLEAN-X™ alleen als de YouTube-video van Jeff Cavaliere is.</li>
            <li><strong class="text-slate-100">Wel inzichtelijk:</strong> RPE (vlot/goed/max), week 1 inregelen, week 2–6 overload, week 7 deload 70%, 1RM, PR.</li>
            <li><strong class="text-slate-100">Al in de trainer-DB:</strong> gelogde sets, cycli, profiel, lock. Dat blijft het trainingslogboek.</li>
            <li><strong class="text-slate-100">Niet hier:</strong> Val Town. Die dump is alleen nog importeren.</li>
        </ul>
    </div>
@endsection
