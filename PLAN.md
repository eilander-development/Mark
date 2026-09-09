# IronForge — ombouwplan

Ja: jouw zes punten zijn het plan. Dit document zet ze in een volgorde die lokaal testen, databehoud en “morgen nog de oude app” combineert.

**Doel:** een echte installeerbare PWA (geen browserbalk), lokaal draaien, Val Town alleen nog als importbron tot de cutover, en alle trainingslogica in de nieuwe app.

**Niet het doel (nu):** VPS/MariaDB, Val Town uitzetten, of een herschrijving die bestaande logs weggooit.

## Stack (vast)

| Laag | Keuze | Niet |
|---|---|---|
| UI | Vue 3 + TypeScript | React, Nuxt |
| Build | Vite | |
| Styling | Tailwind via Vite | jsDelivr-CDN |
| App-schil | PWA (`vite-plugin-pwa`, `display: standalone`) | Capacitor tenzij standalone tegenvallt |
| Data nu | IndexedDB + Val Town-import | Val als bron van waarheid |
| Data later | PHP-FPM + MariaDB op Plesk | Node/Nuxt op de VPS |

Trainingslogica (overload, deload, PR, cycli) als gewone TS-modules, niet in de Vue-componenten.

---

## Uitgangspunt

De GitHub-repo is nu een AI Studio-export. De echte app zit in één `index.html` (~540 KB). `src/App.tsx` is leeg; `npm run build` levert geen werkende tracker.

Data zit op twee plekken:

| Bron | Wat | Rol tot cutover |
|---|---|---|
| `localStorage` (`ironforge_periodized_v8`) | echte log op het toestel | bron van waarheid op dat toestel |
| Val Town (`https://fit.val.run/`) | JSON-blob, geen login | morgen nog de oude app; daarna eenmalig importeren |

De nieuwe app wordt de enige plek waar overload, deload, PR’s en cycli berekend worden. Val Town blijft een dump: ophalen en omzetten, niet “de cloud denkt mee”.

---

## Volgorde (waarom niet 1:1 jouw lijst)

PWA-installatie als alleréérste stap op de huidige HTML-blob levert weer een kapotte service worker op. Eerst een echte app die lokaal draait, dáárna installeren zonder chrome. Val-koppeling blijft tot na morgen. UI-polish komt als data en PWA staan.

```text
1. App uit de HTML  →  lokaal draaien
2. Echte PWA        →  installeren zonder browserbalk
3. Datamodel        →  verwerking in de nieuwe app
4. Val blijven      →  import (oude app morgen)
5. Finetunen        →  gedrag, met behoud van data
6. Verbeteren       →  UI, UX, performance
```

---

## Fase 1 — Echte app, lokaal draaien

**Jouw punt 2, voorwaarde voor 1.**

Uit `index.html` een Vite + Vue 3 + TypeScript-app maken (`src/`), Tailwind via de bundler (geen jsDelivr-CDN). Zelfde schermen en flow: week, live workout, lock, wake lock, weekrapport.

Lokaal:

```bash
cd /home/marke/code/projecten/Mark
npm install   # of bun
npm run dev   # http://localhost:3000
```

Klaar als:

- `src/App.vue` is de tracker, niet een lege React-stub
- `npm run dev` toont het huidige schema
- `npm run build` faalt niet en opent niet leeg
- Gemini/Express-resten die niets doen zijn weg of ongebruikt

Oude `index.html` bewaren tot fase 4 (referentie + extractie), daarna alleen nog als fallback.

---

## Fase 2 — Echte PWA (installeren zonder chrome)

**Jouw punt 1.**

`display: standalone` staat al in `manifest.json`; de huidige `public/sw.js` is cache-first en cachet Tailwind-CDN niet. Dat voelt niet als app en breekt updates.

Aanpak:

- `vite-plugin-pwa` (of gelijkwaardig) tegen de **build-output**, niet tegen de HTML-blob
- `display: standalone` (geen adres- of tabbalk)
- iconen die er al zijn hergebruiken
- nieuwe service worker: precache van gebundelde assets, geen stale HTML
- installeren testen op **localhost** (telt als secure) en op Android Chrome / iOS Safari “Zet op beginscherm”

Klaar als:

- Chrome/Edge: “App installeren”, venster zonder browserbalk
- Android: icoon op beginscherm, standalone
- iOS: Add to Home Screen (Safari heeft geen echte SW-install, wel standalone-weergave)
- heropenen toont de laatste workout, niet een lege staat

---

## Fase 3 — Dataverwerking in de nieuwe app

**Jouw punt 5.**

Nu is alles één `appState`-JSON. Overload, deload en PR zitten in dezelfde file als de UI, met bekende fouten (PR alleen huidige cyclus, week 8–30 wordt gewist, cloud overschrijft lokaal).

Eigen datalaag in de app:

- **profiel** (leeftijd, gewicht, materiaal)
- **cyclus** (meso van 7 weken, start, historie)
- **sessie** (dag, duur, rust)
- **sets** (gewicht, reps, voltooid, PR-vlag)
- **voorkeuren** (rusttijden, overload-stap, video’s)

Lokaal: IndexedDB (of gestructureerde store), niet alleen `localStorage`. Migratie vanaf `ironforge_periodized_v8` zodat bestaande lokale logs binnenkomen.

Domeinregels hier, niet in Val Town:

- week 1 inregelen, week 2–6 overload, week 7 deload
- PR over **alle** cycli in `cyclesHistory`, niet alleen week 1–7
- geen stille delete van week 8+ bij boot
- cloud/import vult de store; de store wint bij conflict tenzij de gebruiker “overschrijven” kiest

Klaar als:

- een set loggen → zichtbaar in store, UI en (optioneel) export
- nieuwe cyclus start zonder de vorige PR’s te verliezen
- unit tests op overload / deload / 1RM / PR

---

## Fase 4 — Val Town blijft, tot import na morgen

**Jouw punt 4.**

Morgen: oude app. Daarna: die Val-dump in de nieuwe app. Tot die import blijft de koppeling.

In de nieuwe app:

- **GET** van `https://fit.val.run/` als import
- mapping JSON-blob → eigen store
- **geen** automatische download die `localStorage`/IndexedDB overschrijft
- import alleen via knop, met preview (datum, aantal voltooide sets) en bevestiging
- schrijven naar Val alleen als we de oude app nog parallel voeden; default tot na morgen: **niet** autowrite (Val Town heeft geen login; iedereen kan POST’en)

Na geslaagde import: Val-adapter mag op “alleen archief”. Uitzetten is een latere beslissing, geen fase-nu.

Klaar als:

- knop “Importeren uit Val Town” vult de nieuwe store
- lokale data verdwijnt niet zonder expliciete bevestiging
- morgen de oude app ongemoeid laten (geen breaking change op Val)

---

## Fase 5 — Finetunen met behoud van data

**Jouw punt 3.**

Pas als fase 1–4 staan. Schema-wijzigingen via genummerde migraties (`v8` → `v9` → …), nooit “leegmaken bij update”.

Voorbeelden (niet uitputtend):

- deload-banner vs. harde 7-weken-lock recht trekken
- `cycleStartedAt` als ISO, niet `toLocaleDateString('nl-NL')`
- live-workout / rest-timer / wake lock op de geïnstalleerde PWA
- export JSON als extra backup naast Val

Klaar als: een week trainen in de nieuwe app, app sluiten, weer openen, sets en PR’s er nog staan.

---

## Fase 6 — Verbeteren en optimaliseren

**Jouw punt 6.** Komt ná een stabiele store en PWA, anders polish je de blob.

Richting (te toetsen in gebruik):

- UI: minder modals/emoji, duidelijkere live-workout op telefoon, grotere tikdoelen
- UX: geen `user-scalable=no`; install-prompt; heldere sync-status (“lokaal” vs “geïmporteerd”)
- Performance: geen 540 KB inline HTML, geen Tailwind-runtime in de browser
- Toegankelijkheid: zoom, contrast, geen 166× `onclick` in één file
- Later, niet in deze ronde: eigen database op de Strato-VPS i.p.v. alleen device-store

---

## Wat we expres níet in deze ronde doen

- Finance-app of die databases aanraken
- Val Town als primaire database houden
- Node extra op de VPS zetten
- Blind `npm run build` van de huidige stub deployen
- Data wissen “om schoon te beginnen”

---

## Cutover (na morgen)

1. Oude app: laatste workout loggen, cloud-sync in de oude UI (zodat Val de dump heeft).
2. Nieuwe app: **Importeren uit Val Town** + controleren tegen het toestel (`localStorage`).
3. Een week alleen de geïnstalleerde PWA.
4. Val-write uitzetten; dump bewaren.

---

## Status

| Fase | Punt | Status |
|---|---|---|
| 1. App + lokaal | 2 | open |
| 2. PWA installeren | 1 | open |
| 3. Datamodel / verwerking | 5 | open |
| 4. Val-import | 4 | open (koppeling houden) |
| 5. Finetunen | 3 | open |
| 6. UI / optimalisatie | 6 | open |

Volgende concrete stap: fase 1 — tracker uit `index.html` naar `src/`, `npm run dev` op localhost:3000.
