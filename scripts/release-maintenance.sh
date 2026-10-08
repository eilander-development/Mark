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
[[ "$operation" = inspect || "$operation" = cleanup ]]
exec 9>"$parent/.$app-deploy.lock"
flock -n 9 || { echo 'Er draait al een deployment of onderhoud.' >&2; exit 1; }
cron=$(crontab -l 2>/dev/null || true)
if [ "$operation" = inspect ]; then
    printf 'ACTIVE=%s\nSTORAGE=%s\n' "$active" "$(realpath -- "$shared/storage")"
    find "$parent" -mindepth 1 -maxdepth 1 -printf '%f\t%y\n' | sort
    printf 'CRON_OWN_REFERENCES=%s\n' "$(printf '%s\n' "$cron" | grep -Fc "${url#https://}" || true)"
    exit 0
fi
release=$(cat "$shared/current-release")
[[ "$release" =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]]
[ "$active" = "$parent/$app-releases/$release/backend" ]
[ "$(realpath -- "$shared")" = "$shared" ]
[ "$(realpath -- "$active/storage")" = "$(realpath -- "$shared/storage")" ]
[ "$(realpath -- "$active/.env")" = "$shared/.env" ]
# Cron changes need an explicit review; do not rewrite another project's jobs.
if printf '%s\n' "$cron" | grep -Fq "${url#https://}"; then
    echo 'Controleer eerst de domeinverwijzingen in crontab.' >&2; exit 1
fi
legacy=()
shopt -s nullglob dotglob
for entry in "$parent"/*; do
    name=${entry##*/}
    case "$name" in public|"$app-releases"|"$app-shared"|"$app-backend"|".$app-deploy.lock"|.well-known|.htaccess|.htpasswd) continue;; esac
    legacy+=("$entry")
done
if [ "${#legacy[@]}" = 0 ]; then echo 'Hoofdmap is al opgeruimd.'; exit 0; fi
for cwd in /proc/[0-9]*/cwd; do
    target=$(readlink "$cwd" 2>/dev/null || true)
    for entry in "${legacy[@]}"; do
        if [[ "$target" = "$entry" || "$target" = "$entry/"* ]]; then
            echo 'Er draait nog een proces vanuit de oude installatie.' >&2; exit 1
        fi
    done
done
[ ! -f "$active/storage/framework/down" ]
archive="$shared/backups/legacy-$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$archive"
moved=()
maintenance=false
old_storage_link="$shared/.legacy-storage-link"
recover() {
    status=$?
    trap - ERR
    set +e
    for entry in "${moved[@]}"; do mv -- "$archive/${entry##*/}" "$entry"; done
    if [ ! -e "$shared/storage" ] && [ -L "$old_storage_link" ]; then mv -- "$old_storage_link" "$shared/storage"; fi
    if [ "$maintenance" = true ]; then (cd "$active" && php artisan up); fi
    echo 'Opruimen afgebroken; verplaatste oude bestanden zijn teruggezet.' >&2
    exit "$status"
}
trap recover ERR
(cd "$active" && php artisan down --retry=5 --no-interaction)
maintenance=true
if [ -L "$shared/storage" ]; then
    [ "$(realpath -- "$shared/storage")" = "$parent/storage" ]
    [ ! -e "$shared/storage.new" ] && [ ! -L "$old_storage_link" ]
    mkdir "$shared/storage.new"
    rsync -a "$parent/storage/" "$shared/storage.new/"
    mv -- "$shared/storage" "$old_storage_link"
    mv -- "$shared/storage.new" "$shared/storage"
fi
[ "$(realpath -- "$shared/storage")" = "$shared/storage" ]
DEPLOY_PERSISTENT_ENV_FILES=()
source "$(dirname "$active")/scripts/deploy-config.sh"
for setting in "${DEPLOY_PERSISTENT_ENV_FILES[@]}"; do
    [[ "$setting" =~ ^[A-Z][A-Z0-9_]*$ ]]
    key_path=$(php -r 'require $argv[1]."/vendor/autoload.php"; $values=Dotenv\Dotenv::parse(file_get_contents($argv[2])); echo $values[$argv[3]]??"";' "$active" "$shared/.env" "$setting")
    [[ "$key_path" =~ ^[A-Za-z0-9_./-]+$ && "$key_path" != /* && "$key_path" != *'..'* && "$key_path" != public/* ]]
    original=$(realpath -- "$active/$key_path")
    [[ "$original" == "$parent/"* && "$original" != "$public/"* ]]
    persistent="$shared/private-files/$key_path"
    mkdir -p "$(dirname "$persistent")"
    if [ -f "$persistent" ]; then cmp "$original" "$persistent"; else cp -- "$original" "$persistent"; fi
    chmod 600 "$persistent"
    for candidate in "$parent/$app-releases/"*/backend; do
        if [ -L "$candidate/$key_path" ]; then
            [ "$(realpath -- "$candidate/$key_path")" = "$original" ]
            ln -sfn "$persistent" "$candidate/$key_path"
        fi
    done
    [ "$(realpath -- "$active/$key_path")" = "$persistent" ]
done
for entry in "${legacy[@]}"; do
    [ "$(dirname "$entry")" = "$parent" ]
    mv -- "$entry" "$archive/"
    moved+=("$entry")
done
(cd "$active" && php artisan up --no-interaction)
maintenance=false
curl --fail --silent --show-error --location --retry 3 --max-time 30 "$url/?cleanup=$release" -o "$archive/live.html"
curl --fail --silent --show-error --max-time 30 "$url/release.json?cleanup=$release" -o "$archive/release.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true); if(($r["revision"]??null)!==$argv[2])exit(1);' "$archive/release.json" "${release:0:40}"
if [ -L "$old_storage_link" ]; then rm -- "$old_storage_link"; fi
trap - ERR
printf 'Oude installatie gearchiveerd: %s onderdelen. Actieve release en opslag gecontroleerd.\n' "${#moved[@]}"
find "$parent" -mindepth 1 -maxdepth 1 -printf '%f\t%y\n' | sort
