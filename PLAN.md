# IronForge — ombouwplan

**Doel:** installeerbare PWA (geen browserbalk) waarvan de UI 1-op-1 de marker is en tegen PHP praat. Of data uit Val komt of uit de database mag voor de frontend niet uitmaken. Val Town is **alleen lezen, nooit schrijven**.

**Niet het doel:** hele `appState` bij elke wijziging wegschrijven, IndexedDB als bron van waarheid, naar Val POST’en, of finance aanraken.

## Waarom deze volgorde

Eerst de referentie, dan iets om tegen te praten (tabellen + API), dan data erin (Val GET), dan pas de schil (zelfde UI tegen `/api`), dan PWA op díe schil, dan finetunen, dan UX. Deploy naar de server doe je zelf.

Vue vóór de API was de verkeerde afhankelijkheid. PWA vóór een vaste UI ook: je installeert de app die er is, niet een app die er nog moet komen.

---

## Stack (vast)

| Laag | Keuze | Niet |
|---|---|---|
| UI | Vue 3 + TypeScript, **zelfde HTML/CSS als `marker/index.html`** | React, Nuxt, herschreven schermen “ongeveer hetzelfde” |
| Build | Vite in dezelfde Laravel-app | losse Node-server |
| Styling | Zelfde classes als de marker (CDN tijdens port, daarna Vite mét alle classes) | een subset die er anders uitziet |
| App-schil | PWA (`display: standalone`, auto-update na deploy) | Capacitor tenzij standalone tegenvalt |
| API + logica | Laravel, PHP 8.4, Sail/MySQL | losse `index.php`, Slim, Node |
| Opslag | MySQL-tabellen (`profiles`, `preferences`, `cycles`, `workout_sessions`, `workout_slots`, `workout_sets`) | JSON-blob / hele `appState` als primaire store |
| Writes | incrementieel: `PATCH` set / slot / sessie / prefs / profiel | `PUT` van heel `appState` bij elke save |
| Val Town | GET-import in PHP, **blijft beschikbaar** (knop + artisan) | POST, auto-sync, overwrite zonder bevestiging |

De frontend praat **alleen** met `/api/...`. Laravel schrijft **alleen** naar de database. Trainingslogica (overload, deload, PR, cycli) in PHP, niet in Vue en niet in Val Town.

---

## Data tot de cutover

| Bron | Rol |
|---|---|
| `http://localhost:8080` + MySQL | **live app en enige schrijfplek** (nu: week 1, 2/18, 640 kg) |
| Val Town `GET https://fit.val.run/` | GET-only import; dump is **leeg** (0 voltooide sets, 9-9-2026). Niet importeren |
| Oude app + `localStorage` (`:3000`) | referentie-UI; praat niet met MySQL |

De `marker/` blijft op `:3000` om chrome te vergelijken. Data vergelijken via Val of localStorage is nu zinloos: Val is een leeg schema.

**Regel:** een Val-dump zonder voltooide sets mag een gevulde database niet overschrijven. Sync op `:8080` weigert dat.

---

## Volgorde

```text
1–7  Ombouw (klaar)     → marker-HTML op :8080, incrementële PATCH, PWA, /beheer
8    Motor = marker     → nieuwe cyclus, deload-afronding, biweekly
9    Ontbrekende flows  → wizard, equipment, benchmark, Vite-CSS
10   Cutover            → wachtwoord op /beheer, Val alleen als die weer data heeft
11   Trainer-UI         → ruis eraf, één CTA, geen kleuren-redesign
12   Logger-contract    → groen = echt gelogd, copy die klopt
13   Video-attributie   → ATHLEAN-X alleen als de YouTube-video van Jeff is
14   Autoregulatie      → RPE/RIR stuurt het volgende gewicht
```

---

## Fase 1 — Marker lokaal

Referentie: weekweergave, live workout, lock, wake lock, weekrapport, anatomie, Athlean, benchmark, confetti.

```bash
npm run marker   # http://localhost:3000
```

**Klaar als** localhost:3000 dezelfde IronForge toont als nu, en die URL beschikbaar blijft om te vergelijken.

**Status:** klaar.

---

## Fase 2 — Datamodel en incrementele API

Tabellen via migrations. Geen JSON-blob als primaire store. PHP bepaalt week 1 inregelen, 2–6 overload, 7 deload, PR over alle cycli.

```text
UI wijzigt één veld
  → PATCH /api/sets/{id}     weight | reps | completed | exertion
  → PATCH /api/slots/{id}    selectedName | note
  → PATCH /api/sessions/{id} duration | avgRest
  → PATCH /api/preferences   week | dag | lock | geluid | video | overload
  → PATCH /api/profile       gewicht | geboortejaar | …
  → POST  /api/days/clear | /weeks/advance | /cycles
```

`PUT /api/marker-state` alleen voor zeldzame bulk (Val-import, JSON-herstel).

**Klaar als** één `PATCH` één rij raakt, een gelogde set in MySQL staat, en een nieuwe cyclus oude PR’s houdt.

**Status:** klaar (tabellen, services, tests).

---

## Fase 3 — Val Town: alleen lezen (blijft)

Oude app mag tot cutover nog naar Val schrijven. Nieuwe app **nooit**.

- `GET/POST /api/import/val` (preview + bevestiging) en `php artisan ironforge:import-val`
- mapping blob → tabellen
- Sync-knop blijft; opnieuw importeren mag
- geen POST naar Val, geen overwrite zonder bevestiging

**Klaar als** de dump in de database zit, de UI het via `/api` toont, en de browser zelf `fit.val.run` niet aanroept.

**Status:** klaar — knop + artisan blijven. Lege dump wordt geweigerd als MySQL voltooide sets heeft. **Niet `--force` draaien** zolang Val leeg is.

---

## Fase 4 — UI 1-op-1 tegen `/api`

Zelfde schermen als de marker, tegen de API uit fase 2. Hydratie via `GET /api/marker-state` of `GET /api/state`. Elke mutatie via fase 2. Val-knop uit fase 3 blijft.

Eerst de werkende marker-HTML op `:8080` (geen natekenen). Daarna die **zelfde** HTML in Vue + Vite — geen nieuwe schermen “ongeveer hetzelfde”.

**Klaar als**

- `:8080` dezelfde chrome toont als `:3000` (Periode, Sync, anatomie, Athlean, benchmark, confetti, vastgezette oefeningen)
- geen `localStorage` als bron
- writes incrementieel zijn
- Val-import werkt
- de UI een Vue/Vite-app is (zelfde HTML)
- `npm run build` + `php artisan migrate` slaagt
- React/Gemini-resten weg zijn

**Status:** klaar als port — `resources/ironforge.html` is de marker-HTML plus persist-patches (`scripts/port-marker-ui.mjs`). Vue mount op `#ironforge-vue` (verborgen schil, geen component-rewrite). Styling nog via Tailwind-CDN, niet Vite. Header/main hebben `min-w-0` zodat lange knoppen (2/18) het scherm niet meer laten overlopen.

---

## Fase 5 — Echte PWA + auto-update

Pas als fase 4 de UI vastlegt. `display: standalone`, bestaande iconen.

Een deploy op de server is **niet** automatisch de versie op de telefoon. Daarvoor:

- gehashte assets / nieuwe SW-bytes per build
- `skipWaiting` + `clientsClaim`, `updateViaCache: 'none'`
- HTML network-first; `/api` NetworkOnly
- `/sw.js` met `Cache-Control: no-cache`
- bij nieuwe SW: automatisch herladen
- check op foreground (`visibilitychange`)

Server-deploy doe je zelf. De app moet lokaal `npm run build` + `php artisan migrate` aankunnen.

**Klaar als** Chrome/Android installeren zonder browserbalk, heropenen de staat uit de **API/DB** toont, en een nieuwe deploy binnen één herstart zichtbaar is.

**Status:** klaar — SW auto-update, HTML network-first, `/sw.js` no-cache, Val-knop blijft.

---

## Fase 6 — Beheerbaar maken wat in JS/config zat

Oefeningen, Athlean-video’s, slot-defaults (reps/rust/alternatieven) en overload-voorkeuren horen in Laravel, niet in de marker-HTML.

- `/beheer` — programma, oefeningen/video’s, trainingsregels, voorkeuren
- tabellen `exercises` + `program_slots`
- live trainer leest video’s via `appState.exerciseVideos`

**Status:** in gebruik (`http://localhost:8080/beheer`).

---

## Fase 7 — Trainingsmodel inzichtelijk

RPE (vlot/goed/max), week 1 inregelen, week 2–6 overload, week 7 deload, 1RM en PR staan uitgelegd op `/beheer/regels` — dezelfde regels als `Periodization` + `SlotAdvisor`.

**Status:** klaar voor deze ombouw.

---

## Spec van de bouwer vs de code

De builder-spec hieronder is **niet** 1-op-1 wat `marker/index.html` doet. Laravel moet de **marker** volgen. Wat alleen in de spec staat, bouwen we niet.

| Onderwerp | Spec (bouwer) | Marker-code | Laravel (`Periodization` / `SlotAdvisor` / `CycleFactory`) |
|---|---|---|---|
| 4 dagen Upper/Upper, 7 weken, 12 slots | ja | ja | ja (catalog + `/beheer`) |
| Ma 6–8 / Di 8–10; Do/Vr “pomp” | ja | **nee** — zelfde slots als Ma/Di; `targetReps` per slot (8/10/12), niet per weekdag | zelfde catalogus |
| RPE 7.0–9.5 / RIR-tabel per fase | ja | **nee** — set-gevoel `easy` / `good` / `max` (vlot/goed/max) | stoplichten ja; geen RPE-tabel |
| Default increment | +2.0 kg | +2.0 kg | +2.0 kg |
| Default frequentie | **biweekly** | **`weekly`** | **`weekly`** |
| Overload-eis | alle sets done + reps ≥ doel + gewicht > 0 | `checkOverloadAchieved` / zelfde drempel | `targetAchieved` |
| Intra-week geen Ma→Do sprong | ja | `getSameWeekExerciseLogged` | `sameWeekMatch` |
| Overload W>1, niet W7 | W-1 + increment als succes | +increment; **easy = fast-track +inc**; **max = vasthouden** | easy/max hetzelfde idee; afronding `round(..., 1)` |
| Biweekly | twee opeenvolgende geslaagde weken | even week = hold; weekrapport na oneven week hold | zelfde even-week-regel |
| Deload W7 | 70%, 2 sets, `roundToStep(..., 0.5)` | `round((max*0.70)/increment)*increment`; 2 sets | zelfde increment-afronding |
| Nieuwe cyclus W1 | piek W6 **+ increment** | piek laatste **zware** week op W1, **geen +kg** | zelfde: piek week 6, geen +kg |
| Wizard + equipment-filter | ja | ja (`openSetupRoutineModal`) | UI zit in de geportte HTML; `POST /api/cycles` slaat de wizard over |
| Nieuwe oefening → benchmark | 0.85× BW bench, 0.35× DB | `getStrengthBenchmark`: vaste normen × (BW/82) × leeftijd (bench ~75 kg bij 82 kg) | functie zit in de geportte JS; PHP heeft hem niet |
| Volume | Σ(gewicht×reps) voltooide sets | ja | ja |
| 1RM | “Brzycki” maar formule is Epley | `w * (1 + r/30)` | zelfde |
| Voortgang | voltooide / 18 of 12 | ja | ja |
| Lock over 7 weken | ja | ja | ja (`syncNameAcrossCycle`) |

**Kort:** architectuur, intra-week, week 1 inregelen, W7 70%+2 sets, volume, 1RM, nieuwe cyclus (piek W6), deload-afronding en biweekly (even week) zitten erin. De RPE-tabel, Ma/Di-rep-ranges, “W6+increment”, default-biweekly en 0.85×BW zitten **niet** in de marker en dus ook niet “omgezet”.

---

## Fase 8 — Trainingsmotor gelijk aan de marker

PHP gelijk trekken met `marker/index.html` (niet met de builder-spec).

1. Nieuwe cyclus: piek van laatste zware week (meestal 6) op week 1, **zonder +kg**.
2. Deload: `round((max * 0.70) / increment) * increment`.
3. Biweekly: even week = hold (PHP + live-advies). Weekrapport: na oneven week hold — zelfde uitkomst.
4. Tests voor die drie paden.

**Klaar als** dezelfde input in marker-JS en PHP hetzelfde advies-gewicht geeft.

**Geen** dode RPE-lookup uit de builder-spec (week → 7.0–9.5 op papier, zonder dat het kg verandert). RPE als stuur voor overload hoort in fase 14 — nádat deze drie paden gelijk lopen.

**Status:** klaar (10-9-2026). Nieuwe cyclus kopieert piek van laatste zware week zonder +kg. Deload = `round((max*0.70)/inc)*inc`. Biweekly hold = even week.

---

## Fase 9 — Marker-flows die Laravel nog mist

- Setup-wizard + equipment-filter moeten via de API landen (niet alleen in JS, daarna `POST /cycles` dat week 1 kopieert).
- `getStrengthBenchmark` in PHP (dezelfde vaste normen als de marker, geen 0.85× BW).
- Tailwind via Vite **mét alle classes uit de marker-HTML** (CDN eruit, geen dual Tailwind).

**Klaar als** wizard/benchmark op `:8080` hetzelfde doen als `:3000` ná refresh (MySQL), en PWA dezelfde utilities heeft zonder jsdelivr.

**Status:** klaar (10-9-2026). Wizard-schema gaat mee in `POST /api/cycles`. `StrengthBenchmark` in PHP gebruikt dezelfde marker-normen (bench 75 kg bij 82 kg, geen 0.85×BW). Vite scant `ironforge.html`; CDN verdwijnt zodra Vite-CSS geladen is.

---

## Fase 10 — Cutover en harden

- `/beheer` achter een wachtwoord (lokaal mag open blijven)
- Val-import alleen als preview `completedSets > 0` en je dat bevestigt
- Deploy doe je zelf (geen Plesk/serverwerk hier)

**Klaar als** de geïnstalleerde PWA alleen MySQL leest, `/beheer` niet open op de server staat, en een lege Val-dump de gymdata niet kan wissen.

**Status:** klaar voor lokaal (10-9-2026). `/beheer` vraagt een wachtwoord als `ADMIN_PASSWORD` gezet is; leeg = open (lokaal). Val-import weigert elke dump zonder voltooide sets, ook als MySQL nog leeg is. Cutover naar de server doe je zelf.

---

## Fase 11 — Trainer-UI: winnen zonder redesign

De schil is bruikbaar in het donker (slate + emerald/teal, paars voor deload, stoplichten). **Geen nieuw kleurenpalet.** Wat scheelt is hiërarchie en dubbele chrome, niet “andere opmaak”.

### Advies: duidelijkheid

| Oordeel | Waarom |
|---|---|
| Palet houden | Donker + teal leest in de gym; paars = deload is al een taal. Een andere skin kost herkenning en wint niets. |
| Typografie houden | Mono voor kg/reps, caps voor labels — past bij een logger. |
| `max-w-4xl` houden op telefoon/tablet-staand | Breedte winnen op landscape: oefeningen links, tips/video rechts — geen extra kleuren. |
| Eén primaire knop | Nu vechten Schema Vast, Weekrapport, Training Doorgaan, Reset, Sync, Menu om aandacht. |

### Weg uit het hoofdscherm (naar Menu of weg)

- **PWA-banner** — na install of dismiss; op desktop ruis. Install blijft in Menu.
- **Sync in de header** — Val is leeg en gevaarlijk; knop heet “Sync” maar importeert. Alleen Menu: “Val importeren (preview)”.
- **Reset naast Start** — per ongeluk in de gym. Lang indrukken of Menu + bevestigen.
- **Uitlegkaart “Schema staat vast…”** elke keer — één regel of alleen bij de eerste lock.
- **Cloud-modal + “Data pushen naar Val”** — nieuwe app schrijft niet naar Val. Upload-knop weg.
- **Dubbele voortgang** — 2/18 zit in header, dagkop én KPI. Eén plek (header-CTA of dagkop), KPI houdt volume.

### Verbeteren (zelfde skin)

- Week-volume en sessie-volume zijn nu vaak hetzelfde getal — één kaart + “deze sessie” als subscript, of weekvolume pas na 2+ dagen.
- Workload-grafiek W2–W7 leeg: kleiner, of pas tonen vanaf week 2.
- Compacte oefenkaarten: doelreps + laatste gewicht groter; “6 oefeningen • 3 sets” niet drie keer.
- Live workout: dat scherm is de gym-UI. Hoofdscherm = starten + overzicht, geen tweede logger.
- `/beheer` blijft apart; geen programma-editor terug op het trainer-hoofdscherm.

### Niet doen in deze fase

- Licht thema, andere brandkleuren, cards “moderner” maken.
- Vue-schermen natekenen.
- Alles op één scherm proppen om “desktop te vullen”.

**Klaar als** het hoofdscherm één CTA heeft (Start/Doorgaan), Sync/Reset/PWA/Val-upload uit de primaire balk zijn, voortgang één keer getoond wordt, en de kleuren ongewijzigd zijn.

**Status:** klaar (10-9-2026). Palet gehouden (slate + één emerald-CTA). Sync/Reset/PWA-banner uit de primaire balk; Val-upload weg; na een echte finish alleen **Naar Dinsdag**.

---

## Gebruiksvriendelijkheid (naloop 9-9-2026)

Gelopen als gebruiker op `:8080` met echte MySQL-data (Maandag 18/18, 1.280 kg). Eerlijk: **de app is te gebruiken, maar liegt op de plekken die ertoe doen.**

### Wat wél werkt zoals bedoeld

- Openen laadt de cyclus uit MySQL (geen lege localStorage).
- Dagen wisselen, weekpillen, lock (geen set-editor op het hoofdscherm).
- Live training starten, weekrapport openen, `/beheer` bereikbaar.
- Lege Val-dump wordt geweigerd (Sync wist de gymdata niet meer).
- Stoplichten en periodisering zijn zichtbaar als je het rapport opent. De 🟡-legenda volgt `overloadFrequency` (default weekly → +inc kg; biweekly → 2 wk consolidatie).

### Wat níet werkt zoals bedoeld

| Bedoeling | Wat de UI doet | Gevolg |
|---|---|---|
| Een set is “gedaan” als gewicht + reps erin staan | Je kunt alle 18 sets afronden met alleen reps; 5/6 oefeningen hebben **geen kg** | Dashboard: **100% / MA✓ / Alles Groen**. Weekrapport: **Niet uitgevoerd / geen gewicht**. Overload telt ze niet. |
| Rusttijd en duur kloppen | Rapport toont **1 sec** gemiddelde rust, 13 min sessie | Coach-tekst over “korte rust” is onzin; vertrouwen weg. |
| Klaar met de dag → volgende dag | Header toont **Sessie Rapport / Doorgaan** én **Naar Dinsdag** én **Reset** | Drie acties, geen duidelijke volgende stap. |
| Sync = status / backup | Knop **Sync**; menu: “Ophalen & **Opslaan**” naar Val | Laravel schrijft niet naar Val. Naam is fout. |
| Offline & lokaal veilig | Menu-footer zegt dat | Data staat in MySQL. Offline is niet de bron. |
| Week 1 = nulmeting | Rapport zegt terecht “herhaal in week 2” voor bench 80 kg | Hoofdscherm viert 100% alsof de week “gewonnen” is. |

### Oordeel

- **Gym-flow (starten, sets afronden, dag wisselen):** voldoende, als je gewicht invult.
- **Begrijpen of je goed zit:** onvoldoende. Groen vinkje ≠ overload-kwalificatie.
- **Gevaarlijke knoppen:** Reset, Sync, Nieuwe Periode en Ontgrendelen staan even hard als Start.
- **Kleuren/opmaak:** niet het probleem (zie fase 11).

### Fase 12 — Logger-contract (gebruiksvriendelijkheid)

Dit is geen skin. Dit is “doet de knop wat hij belooft”.

1. Set afronden zonder gewicht weigeren (tenzij bodyweight).
2. Dag-vink / 100% alleen als elke oefening gewicht+reps heeft (zelfde eis als overload).
3. Weekrapport en hoofdscherm dezelfde status (geen “groen” vs “niet uitgevoerd”).
4. Gem. rust 0–2s niet tonen als coach-inzicht; verberg of “niet gemeten”.
5. Na een echte volledige dag: één knop **Naar Dinsdag**. Rapport in het menu of secundair.
6. Menu-copy: geen “opslaan naar Val”, geen “offline lokaal veilig”. Sync alleen in Menu als “Val importeren”.

**Klaar als** een Maandag zonder kg niet 100% groen is, het weekrapport dezelfde oefeningen “gedaan” noemt als het dashboard, en de header na een echte finish één volgende-dag-CTA heeft.

**Status:** klaar (10-9-2026). API weigert set zonder kg; dashboard telt alleen kg+reps; rust 0–2s geen coach-tekst; één volgende-dag-CTA; menu zegt “Val importeren” en “Data in MySQL”.

---

## Fase 13 — Video-attributie (ATHLEAN-X alleen als het klopt)

`/beheer/oefeningen` zette **ATHLEAN-X™** op elke rij. De bibliotheek (`database/data/athlean-videos.php`) en `Catalog::sync()` defaultten het kanaal, `Exercise::toVideoArray()` vulde het weer in, en opslaan in beheer forceerde het opnieuw. Fuzzy-match en de JS-fallback plakten bovendien de bench-press-video op onbekende namen.

YouTube oEmbed (9-9-2026): bijna alle oude IDs waren **ScottHermanFitness, Howcast, Calisthenicmovement, LIVESTRONG of Alan Thrall**. Alleen `vthMCtgVtFw` (bench) was al écht ATHLEAN-X.

**Regel:** ATHLEAN-X™ / Jeff Cavaliere alleen als oEmbed-auteur `ATHLEAN-X™` (`@athleanx`) is. Lijst: `database/data/athlean-verified-ids.php`. Geen geverifieerde Jeff-video → form-video houden of leeg, **geen** Athlean-label.

Gevonden en ingezet (geverifieerd `@athleanx`): bench, DB/incline press, rows, face pull, OHP, shoulder press, lateral raise, pull-ups, biceps ranked, triceps/skull crusher, push-up workout, pullover (`y1r9toPQNkM`), dips (`vi1-BOcj3cQ`), floor press (`PcThnQTTDAo`), shrugs (`cYPDveEb1RQ`), chest fly (`6rr5p1jCZC4`), bench dips (`jdFzYGmvDyg`). Varianten delen die form-video via `athlean-aliases.php`.

1. Geen default-kanaal ATHLEAN-X in sync, `toVideoArray` of beheer-opslaan.
2. Beheer: checkbox alleen als het klopt; copy liegt niet meer.
3. Trainer-badge “ATHLEAN-X™ Form Guide” alleen bij `isAthlean`.
4. Fuzzy/fallback stampt geen Athlean en geen bench-video meer op alles.
5. Rest van de catalogus: echte Jeff-formvideo zoeken, anders label weg laten.

**Klaar als** `/beheer/oefeningen` en de trainer alleen ATHLEAN-X tonen bij geverifieerde Jeff-video’s, en ontbrekende rijen geen vals label hebben.

**Status:** klaar voor de catalogus (10-9-2026). Elk programmanaam heeft een geverifieerde Jeff-formvideo (soms gedeeld met een zusteroefening). Handmatig plakken in beheer blijft mogelijk; niet-geverifieerde IDs krijgen geen ATHLEAN-label.

---

## Fase 14 — Autoregulatie (RPE stuurt het gewicht)

Fase 8 zei “geen RPE-tabel” omdat de **builder-spec** een fase-tabel had (W1 = 7.0 … W6 = 9.5) die de marker nooit gebruikte. Dat is iets anders dan “RPE is onbelangrijk”. De stoplichten **zijn** al grove RPE; ze moeten het volgende gewicht sturen, niet alleen een badge zijn.

### Wat er nu is (grof, bruikbaar)

| UI | Interne betekenis | Nu |
|---|---|---|
| 🟢 Vlot | ≈ RPE 7 / RIR 3 | +increment als reps gehaald |
| 🟡 Goed | ≈ RPE 8 / RIR 2 | +increment (of hold bij biweekly) |
| 🔴 Max | ≈ RPE 9.5 / RIR 0–1 | gewicht houden |

Overload blijft: alle sets done + reps ≥ doel + gewicht > 0. Week 1 inregelen, intra-week geen sprong, W7 70% + 2 sets blijven.

### Wat een moderne planner wél moet doen

1. **Doel-RPE per week (voorschrift), niet alleen loggen.** W1 ≈ 7 (gewicht zoeken). W2–5 ≈ 8. W6 ≈ 9. W7 ≈ 6–7 + 70% load. Dat ís periodisering; de spec-tabel wordt pas nuttig als PHP daar een kg bij rekent.
2. **e1RM uit echte sets** (Epley mag blijven: `w*(1+r/30)`). Advies = e1RM × % voor (doelreps @ doel-RPE), daarna afronden op increment. Geen vast +2 kg als de set RPE 7 was op een gewicht dat 8 moest zijn.
3. **Na de sessie (Helms/Tuchscherer-logica, gym-simpel):**
   - reps gehaald én gevoel ≤ doel → +increment (vlot: mag +2× increment)
   - reps gehaald maar te zwaar (max terwijl doel 8 was) → hold
   - reps gemist → hold of −increment
4. **UI blijft 3 knoppen** in de gym. Optioneel long-press voor 6–10 in stappen van 0.5. Geen extra scherm per set.
5. **Zelfde motor in PHP en JS.** Geen tweede tabel alleen in de spec.

Niet in deze fase: Ma 6–8 / Di 8–10 als apart programma (dat is een andere split, geen RPE). Geen RPE-cijfer zonder kg (fase 12).

**Klaar als** het advies-gewicht verandert als doel-RPE of gelogd gevoel verandert, en een set zonder kg geen “RPE 8 gehaald” kan zijn.

**Status:** klaar (10-9-2026). Doel-RPE: W1=7, W2–5=8, W6=9, W7=6.5. Vlot + hit = +2×increment; goed + hit = +increment (of biweekly hold); max + hit = hold; miss + max = −increment. UI blijft 3 knoppen.

---

## Wat we expres níet doen

- Hele `appState` bij elke wijziging naar PHP sturen
- Schrijven naar Val Town vanuit de nieuwe app
- De Val-GET-import weghalen
- IndexedDB of `localStorage` als hoofdstore
- Vue-schermen uit het hoofd natekenen
- Node op de VPS
- Finance-databases hergebruiken
- Data wissen “om schoon te beginnen”
- Een lege Val-dump over MySQL heen zetten
- De builder-spec 1-op-1 bouwen waar die de marker tegenspreekt (Ma 6–8 / Di 8–10 als tweede programma, W6+kg, default biweekly, 0.85× BW)
- Een RPE-tabel die alleen tekst is en het advies-gewicht niet stuurt

---

## Cutover (pas als Val weer echte sets heeft)

1. Train op `:8080`. MySQL is de waarheid.
2. Val **niet** importeren zolang preview `completedSets === 0` (nu het geval).
3. Als de oude app Val ooit weer vult: `ironforge:import-val --preview`, controleren, dan pas bevestigen.
4. Geïnstalleerde PWA alleen tegen Laravel. Niet naar Val schrijven.

---

## Status

| Fase | Wat | Status |
|---|---|---|
| 1. Marker lokaal | `:3000` chrome-referentie | klaar |
| 2. Datamodel + API | incrementële `PATCH` | klaar |
| 3. Val GET-import | knop + artisan; lege dump geblokkeerd | klaar |
| 4. UI 1-op-1 | marker-HTML + AJAX-persist | klaar |
| 5. PWA + auto-update | op die UI | klaar |
| 6. Beheer | `/beheer` programma + video's | klaar |
| 7. Inzicht | regels op `/beheer/regels` | klaar |
| 8. Motor = marker | cyclus / deload / biweekly / rotatie | klaar |
| 9. Ontbrekende flows | wizard, benchmark, Vite-CSS | klaar |
| 10. Cutover | wachtwoord, Val alleen mét data | klaar (lokaal; server-wachtwoord zelf zetten) |
| 11. Trainer-UI | ruis eraf, palet houden | klaar |
| 12. Logger-contract | groen = gewicht+reps; geen valse 100% | klaar |
| 13. Video-attributie | ATHLEAN-X alleen bij geverifieerde Jeff-video | klaar (catalogus) |
| 14. Autoregulatie | RPE/RIR stuurt kg; stoplichten blijven de UI | klaar |

### Nu

Fases 1–14 zijn af voor deze ombouw. Nieuwe periode roteert close variants (Helms/Israetel); de wizard wint als je zelf kiest. Live: **http://localhost:8080**. Zet `ADMIN_PASSWORD` vóór je `/beheer` op de server zet.

### Lokaal

```bash
./vendor/bin/sail up -d          # http://localhost:8080  ← hier trainen
npm run marker                   # http://localhost:3000  ← alleen UI-vergelijk
npm run port-ui                  # marker/index.html → resources/ironforge.html
./vendor/bin/sail artisan ironforge:import-val --preview
# --force alleen als preview completedSets > 0
```
