@extends('admin.layout')
@section('title', 'Trainingsregels')
@section('content')
    <h1 class="mb-1 text-xl font-black">Zo maakt IronForge sterke weken</h1>
    <p class="mb-6 text-sm text-slate-400">Dit is de PHP-logica die uit de marker is overgenomen — geen marketingtekst, dit is wat de API echt doet.</p>

    <div class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="mb-2 font-black text-emerald-300">7-weken mesocycle</h2>
            <ol class="list-decimal space-y-2 pl-5 text-sm text-slate-300">
                <li><strong class="text-slate-100">Week 1 — inregelen.</strong> Geen overload. Jij kiest een gewicht waarmee 3 × doelreps strak gaan. Dat is de nulmeting.</li>
                <li><strong class="text-slate-100">Week 2–6 — progressive overload.</strong> Als je vorige week het doel haalde (alle verplichte sets ≥ doelreps), komt er +{{ $increment }} kg bij. Frequentie nu: <span class="font-mono text-amber-200">{{ $frequency }}</span>.</li>
                <li><strong class="text-slate-100">Week 7 — deload.</strong> 2 sets i.p.v. 3, adviesgewicht = 70% van je recente max, afgerond op je increment ({{ $increment }} kg).</li>
            </ol>
        </article>

        <article class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="mb-2 font-black text-amber-300">RPE / exertion (geen 1–10 schaal)</h2>
            <p class="mb-3 text-sm text-slate-300">Na elke set kies je hoe het voelde. Dat stuurt de volgende week:</p>
            <ul class="space-y-2 text-sm">
                <li><span class="font-bold text-emerald-300">Vlot (easy)</span> — doel gehaald én makkelijk → +{{ number_format($increment * 2, 1) }} kg (2× increment), ook bij biweekly.</li>
                <li><span class="font-bold text-amber-300">Goed (good)</span> — doel gehaald → +{{ $increment }} kg bij weekly; bij biweekly blijft een even week gelijk (W2/W4/W6 hold).</li>
                <li><span class="font-bold text-red-300">Maximaal (max)</span> — doel gehaald maar tegen falen → zelfde gewicht herhalen. Doel gemist op max → −{{ $increment }} kg.</li>
            </ul>
            <p class="mt-3 text-xs text-slate-500">Meerderheid van de voltooide sets telt. Doel-RPE: W1 ≈ 7, W2–5 ≈ 8, W6 ≈ 9, W7 ≈ 6.5 (70% load).</p>
        </article>

        <article class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="mb-2 font-black text-sky-300">Reps, sets, 1RM, PR</h2>
            <ul class="list-disc space-y-2 pl-5 text-sm text-slate-300">
                <li>Doelreps komen uit het <a href="{{ route('admin.program') }}" class="underline">programma-slot</a> (bijv. bench 8, laterals 12).</li>
                <li>Normale week: 3 sets. Deload: 2 sets.</li>
                <li>Geschatte 1RM = gewicht × (1 + reps/30). Eén herhaling = het gewicht zelf.</li>
                <li>PR: zwaarder dan all-time max, of hogere 1RM. Bodyweight (push-up, dip, pull-up): meer reps.</li>
                <li>Zelfde oefening eerder deze week (ma vs do) → dat gewicht wordt overgenomen, geen dubbele sprong.</li>
                <li>Nieuwe cyclus: piekgewicht van de laatste zware week (meestal week 6) op week 1, <strong class="text-slate-100">zonder +kg</strong>.</li>
                <li>Nieuwe periode roteert naar de volgende <strong class="text-slate-100">close variant</strong> in hetzelfde slot (bench → incline, niet een willekeurige swap). Nieuw patroon = week 1 opnieuw inregelen, geen oud kg.</li>
                <li>De setup-wizard wint: als je zelf oefeningen kiest, slaat <span class="font-mono">POST /api/cycles</span> dat schema op in plaats van te roteren.</li>
            </ul>
        </article>

        <article class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="mb-2 font-black text-slate-100">Rust</h2>
            <p class="text-sm text-slate-300">Elk slot heeft een rustrichtlijn (compound langer, isolatie korter). Die staat in het programma en kun je per slot wijzigen. Tijdens live training kun je +10/−10s bijstellen; “voorkeursrust” slaat dat op voor de volgende set van die oefening.</p>
        </article>
    </div>
@endsection
