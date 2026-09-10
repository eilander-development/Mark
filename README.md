# IronForge

Periodized workout tracker (thuisgym). Ombouw van de AI Studio-export naar een installeerbare Vue-PWA tegen Laravel.

**Stack:** Laravel 13 + Vue 3 PWA (Vite, Tailwind, `vite-plugin-pwa`) in **Laravel Sail** (PHP 8.4 + MySQL). Database is de enige schrijfplek. Val Town alleen GET-import, nooit schrijven.

De vanilla tracker in `marker/` op [http://localhost:3000](http://localhost:3000) is de **marker**.

Zie [PLAN.md](./PLAN.md).

## Lokaal met Sail (WSL)

```bash
cd /home/marke/code/projecten/Mark
composer install
npm install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm run dev
```

App: [http://localhost:8080](http://localhost:8080)

Marker (blijft ernaast draaien):

```bash
npm run marker   # http://localhost:3000
```

Val-dump inlezen (alleen GET):

```bash
./vendor/bin/sail artisan ironforge:import-val --preview
./vendor/bin/sail artisan ironforge:import-val --force
```

Tests:

```bash
./vendor/bin/sail artisan test
```
