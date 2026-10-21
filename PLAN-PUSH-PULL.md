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
Vast materiaal: barbell, dumbbells, verstelbaar bankje en bankdrukopstelling. Geen automatische materiaalcontrole, vervanging of melding bij periodevoorstellen. De gekozen variant bepaalt exact de oefeningen. Beheer en periodeopbouw weigeren dubbele oefeningen binnen dezelfde dag. Geen ongetoetste landmine/hoge-bankopstelling als standaard. De twaalf exacte lijsten staan in config/push_pull.php en worden eenmalig in de beheerde bibliotheek gezet.
Vier voorstellen: A/B, C/D, E/F en A/D (Push) met C/F (Pull). Beide types mogen onafhankelijk gecombineerd worden.
Nieuwe Push/Pull-periode: zes trainingsweken. In het bestaande weekrapport daarna een hersteladvies en de keuze tussen een lichte zevende week of een nieuwe periode. Zes weken is een praktisch startpunt, geen bewezen optimum. Een nieuwe periode is geen vervanging voor herstel. Bestaande periodes blijven hun zeven weken houden. Herstelresultaten zijn geen normale startreferentie.

## Gebruikersflow

1. Nieuwe periodes richten zich op spiergroei; de doelkeuze is tijdelijk uit de gebruikersflow verwijderd. Bestaande periodes behouden hun opgeslagen doel.
2. Periodevoorstel kiezen of huidige indeling herhalen; vier dagen en weekbelasting bekijken.
3. Via bestaande knop ‘Schema zelf kiezen’ per dag een vaste A–F-variant kiezen en eigen startgewichten instellen. Er is geen losse oefeningskeuze. De inhoud van A–F is uitsluitend aanpasbaar via Beheer → Programma.
4. Onder de oefeningskeuze ‘▶ Video & uitleg’: bestaande gids toont de conceptkeuze vóór opslaan, opstelling/spieren/cues en eigen laatste sets. Vergelijkbare video expliciet markeren; geen video is geen verzonnen exacte match.
5. Concept blijft intact bij teruggaan, video openen en voorstel wijzigen. Gewichten uit eigen invoer behouden.
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
- Modals zijn op grotere schermen minimaal 80% van de schermbreedte. Dagkaarten, startgewichten, instellingen en oefeningsuitleg gebruiken waar mogelijk kolommen; op kleine schermen staan ze onder elkaar. Actieknoppen van voorstel en inrichting blijven buiten het scrollgebied.
- Geen nieuwe dependencies, geen brede frontendherschrijving en geen automatische uitrol.

## Bouw en validatie

1. Opslag/bibliotheek/voorschriften en beheer.
2. Periodeadvies/starten, gewichten, dagonafhankelijkheid en progressie.
3. Selectie/wizard/gids/trainer/rapportage en Nederlandse Push/Pull-teksten.
4. Featuretests: twaalf geldige varianten, vier voorstellen, beheer/versionering, complete schema-opslag, grenzen bij vervangen, vaste spiergroeivoorstellen, gewichten, gemiste sets, meerdere sets-aantallen, rollback/dubbel starten, back-up/herstel en bestaande periodebescherming.
5. Gerichte tests, volledige bestaande suite, Pint, frontendbuild en controle in de browser.

De coachinggetallen zijn praktische startregels; persoonlijke prestaties en herstel bepalen vervolgadvies.

## Aanvullingen na UI/UX- en trainingsbeoordeling — 21 oktober 2026

- Materiaal is een vast uitgangspunt. Nieuwe voorstellen, previews en gestarte periodes gebruiken exact de beheerde variant. Profielvinkjes veranderen geen oefeningen en veroorzaken geen materiaalwaarschuwingen. Ongeldige dubbele oefeningen geven een beheerfout, geen automatische vervanging.
- De twaalf standaardvarianten zijn gecontroleerd op uitvoerbaarheid met de vaste opstelling, sets, volgorde en overlap. De oefeningslijsten zijn behouden. Bij Pull krijgt iedere standaardvariant een lataccent en een bovenrugaccent via expliciete uitvoeringstips. Bij Pull B wordt de aanvullende Barbell Row met lataccent uitgevoerd; de Chest-Supported Dumbbell Row heeft bovenrugaccent. Beide accenten blijven rugwerk, geen isolatie of garantie op plaatselijke spiergroei.
- Variantkeuzes tonen de eerste twee werkelijke oefeningen als beschrijving. Voorstel, inrichting en beheer tonen werksets en een grove tijdsindicatie op basis van sets, reps, rust, een korte warming-up en wisseltijd. Dit is geen gemeten duur en past mee aan bij beheerwijzigingen.
- Langere weekbelasting- en hersteltoelichting is inklapbaar. Video en uitleg blijven per voorgestelde oefening direct bereikbaar. Bij wisselen van dagvariant blijven eigen startgewichten alleen behouden voor exact dezelfde oefening, ook als de oefeningspositie verandert. Een bewust leeg startgewicht blijft leeg.
- De bestaande trainer toont laatste uitvoering, huidig doel en de (eventueel voorlopige) volgende stap met reden samen. Gewichtseenheden blijven expliciet. Reps, gewicht en inspanning staan centraal; tonnage en geschatte 1RM zijn aanvullende gegevens.
- De trainer en bestaande oefeningsgids bevatten inklapbare instructies voor opwarmen en onderscheid tussen inspanning en pijn. Opwarmsets tellen niet als werksets. Inspanningsknoppen geven een benadering: Vlot circa 4+ reps over, Goed circa 2–3, Maximaal circa 0–1; onbekende inspanning blijft mogelijk. De bestaande conservatieve progressieregels veranderen niet.
- De modals krijgen dialoogsemantiek, focus binnen de bovenste modal, Tab/Shift+Tab-cycli, Escape voor alleen de bovenste laag en terugkeer naar de opener. Onderliggende inhoud wordt inert. Bij een hertekende opener wordt de bijbehorende bediening opnieuw gezocht. Een bezig zijnde periode-inrichting blijft beschermd tegen sluiten.
- De bestaande zesweekse evaluatie, optionele lichte week, weekbelasting met directe/ondersteunende sets, versieopslag en optionele buikspierflow blijven behouden. Nieuwe periodes vervangen herstel niet. Er zijn geen migraties of wijzigingen aan echte trainingssets nodig voor deze aanvulling.

De beoordeling is uitgevoerd vanuit UI/UX- en trainingsprincipes door de assistent, zonder onafhankelijke designer of personal trainer. De uitgangspunten zijn opnieuw vergeleken met de [ACSM-samenvatting uit 2026](https://acsm.org/resistance-training-guidelines-update-2026/), [Pelland et al. over volume en frequentie](https://pubmed.ncbi.nlm.nih.gov/41343037/) en het [W3C-dialoogpatroon](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/). Dit bewijst geen individueel optimaal schema; werkelijke uitvoering en herstel blijven bepalend. De Windows-browserkoppeling blijft onbereikbaar, dus visuele controle op desktop en mobiel staat expliciet open.

## Uitvoering en controle — 20 oktober 2026

De bovenstaande aanpassingen zijn gebouwd. De variantbibliotheek staat onder Beheer → Programma. Nieuwe periodes gebruiken zes trainingsweken met een optionele lichte zevende week; bestaande periodes behouden hun planning en prestaties.

De drie toevoegende migraties zijn toegepast. De lokale databaseverbinding werkt weer. De API bevestigt periode 1, week 7 en 448 voltooide sets; er is geen nieuwe echte trainingsperiode gestart of trainingshistorie gewist.

Gecontroleerd met de PHP-regressiesuite, gerichte featuretests voor programma/beheer en trainingsvoorschriften, negentien JavaScript-tests voor schermlogica, Pint, frontendbuild en lokale HTTP-verzoeken. De gerichte PHP-tests voor vaste dagvarianten, beheer en voorschriften slagen. De lokale voorstellen bevatten geen dubbele oefeningen. Visuele browsercontrole kon niet worden uitgevoerd omdat de browserkoppeling niet beschikbaar is.

## Uitvoering en controle — 21 oktober 2026

De aanvullingen van 21 oktober zijn gebouwd. De volledige PHP-suite slaagt: 149 tests en 1675 assertions. Alle 120 JavaScript-tests slagen, inclusief 24 tests voor de Push/Pull-schermlogica en controles van de echte trainer-renderfunctie. De bestaande tests voor automatische materiaalvervanging zijn aangepast naar expliciete variantkeuze, met behoud van controles op persoonlijke historie en eigen startgewichten. De oudere JavaScript-testomgeving laadt nu ook de gedeelde set-teller en asynchrone periodefunctie.

Pint, frontendbuild en git diff --check slagen. De lokale API bevestigt twaalf varianten, normale Push/Pull-titels, de oorspronkelijke geselecteerde oefeningen en de nieuwe overzichten en uitvoeringstips. De huidige stand bij de alleen-lezen controle is periode 1, week 7, met 460 afgevinkte sets. Tijdens deze uitvoering zijn geen echte trainingssets aangepast of periodes gestart. Er waren geen migraties nodig.

De browserkoppeling blijft ook na herstelpogingen onbereikbaar. Geautomatiseerde controle van focus, lagen, Tab/Shift+Tab, Escape, conceptbehoud en trainerweergave is uitgevoerd; een visuele en interactieve controle in een echte browser op desktop en mobiel blijft open. Er is geen onafhankelijke expertgoedkeuring afgegeven.
