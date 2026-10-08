#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
public=$(realpath -- "${1:?}")
app="${2:?}"
url="${3:?}"
operation="${4:?}"
[[ "$app" =~ ^[a-z][a-z0-9-]*$ && "$url" =~ ^https://[A-Za-z0-9][A-Za-z0-9.-]*$ ]]
parent="$HOME/domains/${url#https://}/public_html"
[ "$public" = "$parent/public" ] && [ ! -L "$public" ]
[ "$(cat "$public/.$app-deploy")" = "$app" ]
backend="$parent/$app-backend"
shared="$parent/$app-shared"
active=$(realpath -- "$backend")
[[ "$active" == "$parent/$app-releases/"*/backend ]]
[ -f "$shared/current-release" ] && [ -f "$shared/.env" ]
[ "$operation" = inspect ] || { echo 'Opruimen vereist eerst een gecontroleerde inventarisatie.' >&2; exit 1; }
printf 'ACTIVE=%s\nSTORAGE=%s\n' "$active" "$(realpath -- "$shared/storage")"
find "$parent" -mindepth 1 -maxdepth 1 -printf '%f\t%y\n' | sort
for folder in marker scripts; do
    if [ -d "$parent/$folder" ]; then find "$parent/$folder" -mindepth 1 -maxdepth 2 -printf "$folder/%P\t%y\n" | head -60; fi
done
cron=$(crontab -l 2>/dev/null || true)
printf 'CRON_TOTAL=%s\n' "$(printf '%s\n' "$cron" | grep -c '^[^#[:space:]]' || true)"
printf 'CRON_OWN_REFERENCES=%s\n' "$(printf '%s\n' "$cron" | grep -Fc "$parent" || true)"
# Report only matching file paths, never full cron commands or environment values.
printf '%s\n' "$cron" | grep -oE "$parent/[A-Za-z0-9_./-]+" | sort -u || true
