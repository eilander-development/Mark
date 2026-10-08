#!/usr/bin/env bash
set -euo pipefail
target=$(realpath -- "${1:?}")
url="${2:?}"
app="${3:?}"
[[ "$app" =~ ^[a-z][a-z0-9-]*$ ]]
[[ "$url" =~ ^https://[A-Za-z0-9][A-Za-z0-9.-]*$ ]]
base="$HOME/domains/${url#https://}/public_html"
case "$target" in "$base"|"$base/public") ;; *) echo 'Doel valt buiten de eigen domeinmap.' >&2; exit 1;; esac
if [ "$target" = "$base" ] && { [ -f "$base/public/index.php" ] || [ -f "$base/public/.$app-deploy" ]; }; then public="$base/public"; else public="$target"; fi
[ "$public" = "$base/public" ] || { echo 'Controleer eerst dat de domeinwebroot public_html/public is.' >&2; exit 1; }
[ ! -L "$public" ]
if [ -f "$public/.$app-deploy" ]; then
    [ "$(cat "$public/.$app-deploy")" = "$app" ]
else
    [ -f "$(dirname "$public")/artisan" ]
    [ -f "$(dirname "$public")/.env" ]
fi
printf '%s\n' "$public"
