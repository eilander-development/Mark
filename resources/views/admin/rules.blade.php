@extends('admin.layout')
@section('title', 'Trainingsregels')
@section('content')
    <h1 class="mb-1 text-xl font-black">Trainingsregels & onderbouwing</h1>
    <p class="mb-6 text-sm text-slate-400">Dit zijn de huidige beslisregels. Onderzoek onderbouwt de principes; de exacte stappen blijven keuzes van dit schema.</p>
    <div class="space-y-4">
        <article class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="font-bold">Push/Pull: zes trainingsweken, daarna kiezen</h2>
            <p class="mt-2 text-sm text-slate-300">Nieuwe Push/Pull-periodes: week 1 inregelen, daarna progressive overload tot en met week 6. Kies vervolgens een lichte herstelweek of een nieuwe periode. Zes weken is een praktisch evaluatiemoment, geen bewezen optimum. Een nieuwe periode vervangt herstel niet. Het aantal werksets volgt de oefening; een herstelweek gebruikt maximaal twee sets. Bestaande periodes behouden hun oorspronkelijke zeven weken.</p>
            <details class="mt-3 text-sm text-slate-300"><summary class="cursor-pointer text-blue-300">Reps, gewicht en inspanning</summary>
                <ul class="mt-3 list-disc space-y-2 pl-5">
                    <li>Alle weeksets worden samen beoordeeld. Extra reps compenseren geen te lichte of ontbrekende set.</li>
                    <li>Nieuwe Push/Pull-periodes: spiergroei gebruikt oefeningsspecifieke bereiken van 8–12, 10–15 of 12–20 reps. Kleine repstappen van maximaal één per set. Krachtaccent: geschikte hoofdoefeningen 4–6 reps. Combinatie: eerste borstpress en row 6–8 reps. Bestaande periodes houden hun opgeslagen voorschriften. Aan de bovengrens: één gewichtsstap van {{ $increment }} kg, dan opnieuw opbouwen vanaf de ondergrens.</li>
                    <li>Vlot (easy) en goed (good) geven geen dubbele gewichtsstap. Een maximale set vraagt bevestiging in een volgende opbouwweek. Pas bij herhaald gemiste doelen met maximale inspanning wordt een lager positief gewicht geadviseerd.</li>
                    <li>Niet beoordeeld of training overgeslagen: geen verhoging. Uitgevoerde sets blijven wel meetellen als werk.</li>
                    <li>Ritme: {{ $frequency === 'weekly' ? 'wekelijks beoordelen' : 'tweewekelijks; even opbouwweken consolideren' }}. Inspanning is zelfrapportage, geen gemeten RPE.</li>
                </ul>
            </details>
        </article>
        <article class="rounded-2xl border border-purple-500/30 bg-purple-950/30 p-5">
            <h2 class="font-bold text-purple-200">Herstelcheck & adaptieve deload</h2>
            <p class="mt-2 text-sm text-slate-300">De herstelcheck wordt vóór de training opgeslagen. Het advies gebruikt de laatste eerdere week met prestaties voor die oefening, normaal week 6. Eigen invoer en gestarte doelen blijven behouden.</p>
            <details class="mt-3 text-sm text-slate-300"><summary class="cursor-pointer text-purple-200">Waarom 60%, 70% of 80%?</summary>
                <ul class="mt-3 list-disc space-y-2 pl-5">
                    <li>60%: veel vermoeidheid gemeld, of doelen gemist met minstens de helft maximale sets bij minimaal drie opgeslagen sets.</li>
                    <li>80%: goed hersteld gemeld én alle referentiesets met controle gehaald, zonder ontbrekende inspanning.</li>
                    <li>70%: overige situaties. Ontbrekende gegevens gelden niet als goed herstel.</li>
                    <li>Afronden op de ingestelde gewichtsstap; als een lager positief gewicht niet kan, minder reps. Lichaamsgewichtsoefeningen gebruiken reps.</li>
                    <li>Eerder herstelvoorstel: bij minstens twee oefeningen tweemaal minder reps bij hetzelfde gewicht en aantal sets, samen met maximale sets en gemelde vermoeidheid. Het schema verandert niet automatisch.</li>
                </ul>
                <p class="mt-3 text-xs text-slate-400">Timing en percentages zijn praktische appregels. De wetenschap geeft geen universeel persoonlijk optimum. <a href="https://pmc.ncbi.nlm.nih.gov/articles/PMC10511399/" target="_blank" rel="noopener noreferrer" class="text-blue-300 underline">Deloadconsensus (2023)</a>.</p>
            </details>
        </article>
        <details class="rounded-2xl border border-slate-800 bg-slate-900 p-5 text-sm text-slate-300">
            <summary class="cursor-pointer font-bold text-slate-100">Registratie, rapportages en volgende periode</summary>
            <p class="mt-3">Eigen setinvoer gaat vóór overgenomen waarden. Een volgende set gebruikt de eerdere uitgevoerde set. Dezelfde oefening later in de week kan eerdere prestaties overnemen; een expliciete herstelcheck kan het deloadadvies aanpassen.</p>
            <p class="mt-3">Volume telt opgeslagen gewicht × reps. Deload blijft paars; een lager herstelvolume is geen oordeel over krachtverlies. Geschatte 1RM gebruikt gewicht × (1 + reps / 30), behalve bij één rep. Dat is een schatting, geen gemeten maximum.</p>
            <p class="mt-3">De volgende periode wordt bewust afgesloten en voorbereid. Bij dezelfde oefening tellen prestaties uit de laatste opbouwweek mee. Een nieuwe oefening moet opnieuw worden ingeregeld; wisselen is geen verplichting.</p>
            <p class="mt-3">Rusttijden zijn rustrichtlijnen per oefening, aanpasbaar tijdens de training. Zie <a href="{{ route('admin.program') }}" class="text-blue-300 underline">programma</a>.</p>
            <p class="mt-3"><a href="https://journal.iusca.org/index.php/Journal/article/view/81" target="_blank" rel="noopener noreferrer" class="text-blue-300 underline">IUSCA-hypertrofieadvies</a> · <a href="https://pubmed.ncbi.nlm.nih.gov/41343037/" target="_blank" rel="noopener noreferrer" class="text-blue-300 underline">Volume en frequentie (2026)</a> · <a href="https://acsm.org/resistance-training-guidelines-update-2026/" target="_blank" rel="noopener noreferrer" class="text-blue-300 underline">ACSM-richtlijn (2026)</a> · <a href="https://pubmed.ncbi.nlm.nih.gov/39495260/" target="_blank" rel="noopener noreferrer" class="text-blue-300 underline">1RM-validatieonderzoek (2025)</a></p>
        </details>
    </div>
@endsection
