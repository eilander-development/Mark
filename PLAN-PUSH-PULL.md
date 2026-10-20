# Push/Pull — definitief bouwplan

## Afspraken

- Doel: bovenlichaamspiergroei en meetbare progressive overload, vier dagen per week.
- Maandag/donderdag Push: borst, voorste/zijkant schouders, triceps.
- Dinsdag/vrijdag Pull: rug, achterste schouders, biceps en trapezius.
- Zes vaste dagvarianten per type (A–F) zijn genoeg. Oefeningen blijven binnen een periode gelijk; basisoefeningen mogen in meerdere varianten terugkomen.
- Vier selecteerbare periodevoorstellen combineren de twaalf varianten. De huidige indeling herhalen blijft mogelijk.
- Beheer onder Beheer → Programma: dagvarianten bewerken/dupliceren/archiveren; periodevoorstellen samenstellen met vier variantkeuzes. Bestaande bediening en oefeningsbibliotheek hergebruiken.
- Een gestarte periode bewaart een eigen versie van dagen, oefeningen, volgorde en voorschriften. Bibliotheekwijzigingen wijzigen die periode niet.

## Inhoud

Push: primaire borstpress 3 × 8–12, aanvullende borst 3 × 10–15 (press 8–12), schouderpress 2 × 8–12, lateral raises 3 × 12–20, triceps 3 × 10–15.
Pull: eerste row 3 × 8–12, tweede row/latbeweging 3 × 8–12, rear delts 2 × 12–20, biceps 3 × 10–15, shrugs 2 × 10–15.
Rust: circa 150 seconden presses/rows, 90 seconden isolatie; gebruikersvoorkeur blijft mogelijk. Inspanning doorgaans 1–3 herhalingen over, bij inregelen 2–3.
Materiaalgeschikte alternatieven, geen ongetoetste landmine/hoge-bankopstelling als standaard. Kies alternatieven voor de hele trainingsdag samen: geen dubbele oefeningen, bestaande passende keuzes zo veel mogelijk behouden. Benoem bij vervanging welk materiaal in het profiel ontbreekt. Ook zelf kiezen en beheer weigeren dubbele oefeningen binnen dezelfde dag. De twaalf exacte lijsten staan in config/push_pull.php en worden eenmalig in de beheerde bibliotheek gezet.
Vier voorstellen: A/B, C/D, E/F en A/D (Push) met C/F (Pull). Beide types mogen onafhankelijk gecombineerd worden.
Nieuwe Push/Pull-periode: zes trainingsweken. In het bestaande weekrapport daarna een hersteladvies en de keuze tussen een lichte zevende week of een nieuwe periode. Zes weken is een praktisch startpunt, geen bewezen optimum. Een nieuwe periode is geen vervanging voor herstel. Bestaande periodes blijven hun zeven weken houden. Herstelresultaten zijn geen normale startreferentie.

## Gebruikersflow

1. Doel kiezen (spiergroei/kracht/combinatie).
2. Periodevoorstel kiezen of huidige indeling herhalen; vier dagen en weekbelasting bekijken.
3. Via bestaande knop ‘Schema zelf kiezen’ per dag een vaste A–F-variant kiezen en eigen startgewichten instellen. Er is geen losse oefeningskeuze. De inhoud van A–F is uitsluitend aanpasbaar via Beheer → Programma.
4. Onder de oefeningskeuze ‘▶ Video & uitleg’: bestaande gids toont de conceptkeuze vóór opslaan, opstelling/spieren/cues en eigen laatste sets. Vergelijkbare video expliciet markeren; geen video is geen verzonnen exacte match.
5. Concept blijft intact bij teruggaan, video openen en doel wijzigen. Gewichten uit eigen invoer behouden.
6. Alleen ‘Periode starten’ sluit de oude periode en maakt de nieuwe aan. Beschermen tegen dubbel starten en verouderde concepten.

## Startgewichten en progressie

- Exact dezelfde oefening uit eigen historie; datum, sets en herhalingen tonen. Historie volgt de oefening, niet het slot.
- Geen automatische gewichten uit leeftijd/lichaamsgewicht, geen norm/piek als persoonlijk voorschrift. Zonder bruikbare historie: startgewicht inregelen.
- Recente normale werksets met passend herhalingsbereik als referentie; oudere prestaties en andere ranges duidelijk laten inregelen. Geen blind overgenomen deloadgewichten.
- Gewichtseenheden expliciet: per dumbbell, één dumbbell met twee handen, totaal of lichaamsgewicht. Beschikbare gewichtsstap per oefening gebruiken.
- Eerst reps binnen de range opbouwen, daarna gewicht verhogen als alle geplande werksets de bovengrens met passende inspanning halen. Kleine repstappen; geen verplichte verhoging per kalenderweek.
- Een gemist doel: werkelijk behaalde reps opslaan, doorgaans herhalen. Duidelijk onder de ondergrens: lichter vervolgen adviseren. Herhaald onder de ondergrens met maximale inspanning: één stap terug adviseren. Onbekende inspanning/onvolledig: niet verhogen.
- Eenmalig minder reps is geen automatische permanente verlaging. Vervolgsets en oorspronkelijk doel gescheiden houden.
- Spiergroei: basisranges hierboven. Kracht: geschikte eerste press/row en shoulder press 4–6. Combinatie: eerste press/row 6–8; accessoires behouden hun range.
- Weekrapport: directe/ondersteunende sets apart, geen ideale percentages of claim dat tonnage spiergroei meet.

## Techniek en bescherming

- Genormaliseerde beheerrecords voor dagvarianten en periodevoorstellen; trainingssessies/slots/sets blijven de primaire trainingsopslag.
- Eigen periodedefinitie en voorschrift per slot: aantal sets, range, rust, functie en variantidentiteit.
- Losse oefeningswijzigingen in nieuwe Push/Pull-periodes worden ook door de API geweigerd. De geselecteerde variant bepaalt de oefeningen. Legacy-opslag blijft leesbaar en compatibel; historie blijft behouden.
- Backend bepaalt bibliotheek, doelvoorschriften, historie/startadvies en opbouw. UI gebruikt API-uitkomsten.
- Alle tellers/trainermodals/rapporten ondersteunen sets per oefening, inclusief herstelweek.
- Huidige en historische periodes blijven inhoudelijk intact; oude indeling niet achteraf Push/Pull noemen. Nieuwe periode expliciet Push/Pull.
- Back-up/herstel bewaart periodedefinities, voorschriften en variantbibliotheek. Legacy-back-ups blijven bruikbaar.
- Buikspieren blijven optioneel na de hoofdtraining, uitsluitend in bestaande trainer-modal.
- ‘Schema vast/open’ vervalt volledig uit de bediening. Dagvarianten kiezen loopt via ‘Schema zelf kiezen’; de oude flow ‘Huidig schema aanpassen’ vervalt. Video en uitleg tonen de gekozen oefening en bieden geen vervangknop. Opgeslagen sets blijven beschermd.
- Bij API-laadfouten geen leeg schema tonen of opslaan; duidelijke foutmelding met opnieuw proberen. De lokale MySQL-driver herstellen.
- Het menu biedt de nieuwe periode bovenaan en toont Instellingen en Beheer als gelijkvormige knoppen. De oude ingang voor ‘Huidig schema aanpassen’ is verwijderd. In het instelscherm staat een directe link naar A–F beheren.
- Geen nieuwe dependencies, geen brede frontendherschrijving en geen automatische uitrol.

## Bouw en validatie

1. Opslag/bibliotheek/voorschriften en beheer.
2. Periodeadvies/starten, gewichten, dagonafhankelijkheid en progressie.
3. Selectie/wizard/gids/trainer/rapportage en Nederlandse Push/Pull-teksten.
4. Featuretests: twaalf geldige varianten, vier voorstellen, beheer/versionering, complete schema-opslag, grenzen bij vervangen, doelkeuze, gewichten, gemiste sets, meerdere sets-aantallen, rollback/dubbel starten, back-up/herstel en bestaande periodebescherming.
5. Gerichte tests, volledige bestaande suite, Pint, frontendbuild en controle in de browser.

De coachinggetallen zijn praktische startregels; persoonlijke prestaties en herstel bepalen vervolgadvies.

## Uitvoering en controle — 20 oktober 2026

De bovenstaande aanpassingen zijn gebouwd. De variantbibliotheek staat onder Beheer → Programma. Nieuwe periodes gebruiken zes trainingsweken met een optionele lichte zevende week; bestaande periodes behouden hun planning en prestaties.

De drie toevoegende migraties zijn toegepast. De lokale databaseverbinding werkt weer. De API bevestigt periode 1, week 7 en 448 voltooide sets; er is geen nieuwe echte trainingsperiode gestart of trainingshistorie gewist.

Gecontroleerd met de PHP-regressiesuite, gerichte featuretests voor programma/beheer en trainingsvoorschriften, zeventien JavaScript-tests voor schermlogica, Pint, frontendbuild en lokale HTTP-verzoeken. De gerichte PHP-tests voor vaste dagvarianten, beheer en voorschriften slagen. De lokale voorstellen bevatten geen dubbele oefeningen. Visuele browsercontrole kon niet worden uitgevoerd omdat de browserkoppeling niet beschikbaar is.
