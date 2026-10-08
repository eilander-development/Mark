#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
public=$(realpath -- "${1:?}")
release="${2:?}"
app="${3:?}"
url="${4:?}"
[[ "$app" =~ ^[a-z][a-z0-9-]*$ ]]
[[ "$release" =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]]
[[ "$url" =~ ^https://[A-Za-z0-9][A-Za-z0-9.-]*$ ]]
base="$HOME/domains/${url#https://}/public_html"
[ "$public" = "$base/public" ]
[ ! -L "$public" ]
parent=$(dirname "$public")
backend="$parent/$app-backend"
shared="$parent/$app-shared"
stage="$parent/$app-releases/$release"
[ -f "$stage/payload.tgz" ]
[ ! -e "$backend" ] || [ -L "$backend" ]
exec 9>"$parent/.$app-deploy.lock"
flock -n 9 || { echo 'Er draait al een deployment.' >&2; exit 1; }
tar -xzf "$stage/payload.tgz" -C "$stage"
DEPLOY_PERSISTENT_ENV_FILES=()
source "$stage/scripts/deploy-config.sh"
[ "$DEPLOY_APP" = "$app" ]
if [ -f "$public/.$app-deploy" ]; then
    [ "$(cat "$public/.$app-deploy")" = "$app" ]
else
    [ -f "$parent/artisan" ] && [ -f "$parent/.env" ]
fi
mkdir -p "$shared/backups"
if [ ! -f "$shared/.env" ]; then
    [ -f "$parent/.env" ] || { echo 'Bestaande eigen .env ontbreekt.' >&2; exit 1; }
    cp "$parent/.env" "$shared/.env"
    chmod 600 "$shared/.env"
fi
if [ ! -d "$shared/storage" ]; then
    [ -d "$parent/storage" ] || { echo 'Bestaande storage ontbreekt.' >&2; exit 1; }
    ln -s "$parent/storage" "$shared/storage"
fi
mkdir -p "$shared/storage/app/public" "$shared/storage/framework/cache/data" "$shared/storage/framework/sessions" "$shared/storage/framework/views" "$shared/storage/logs"
ln -s "$shared/.env" "$stage/backend/.env"
ln -s "$shared/storage" "$stage/backend/storage"
for setting in "${DEPLOY_PERSISTENT_ENV_FILES[@]}"; do
    [[ "$setting" =~ ^[A-Z][A-Z0-9_]*$ ]]
    key_path=$(php -r 'require $argv[1]."/vendor/autoload.php"; $values=Dotenv\Dotenv::parse(file_get_contents($argv[2])); echo $values[$argv[3]]??"";' "$stage/backend" "$shared/.env" "$setting")
    [[ "$key_path" =~ ^[A-Za-z0-9_./-]+$ && "$key_path" != /* && "$key_path" != *'..'* && "$key_path" != public/* ]] || { echo "Ongeldig bestaand bestandspad voor $setting." >&2; exit 1; }
    source_path=$(realpath -- "$parent/$key_path")
    [[ "$source_path" == "$parent/"* && "$source_path" != "$public/"* ]]
    [ -f "$source_path" ] || { echo "Bestaand bestand voor $setting ontbreekt." >&2; exit 1; }
    if [ ! -e "$stage/backend/$key_path" ]; then
        mkdir -p "$(dirname "$stage/backend/$key_path")"
        ln -s "$source_path" "$stage/backend/$key_path"
    fi
done
find "$stage/backend/bootstrap/cache" -name '*.php' -delete
backup="$shared/backups/$release"
mkdir "$backup"
tar -czf "$backup/public.tar.gz" -C "$public" .
cp "$shared/.env" "$backup/app.env"
previous=$(readlink "$backend" || true)
printf '%s\n' "$previous" > "$backup/previous-backend"
export DEPLOY_DB_CONNECTIONS DEPLOY_TABLE_PREFIX DEPLOY_EXCLUDE_PREFIX DEPLOY_EXPECTED_DATABASE
php "$stage/scripts/release-backup.php" "$stage/backend" "$backup"
rollback() {
    status=$?
    trap - ERR
    set +e
    echo 'Activering mislukt; openbare bestanden en backend herstellen. Databasewijzigingen blijven behouden.' >&2
    mkdir -p "$backup/restore-public"
    tar -xzf "$backup/public.tar.gz" -C "$backup/restore-public"
    rsync -ac --delete --exclude='/.well-known/' --exclude='/.htaccess' --exclude='/.htpasswd' "$backup/restore-public/" "$public/"
    if [ -n "$previous" ]; then ln -sfn "$previous" "$backend"; else rm -f "$backend"; fi
    exit "$status"
}
trap rollback ERR
cd "$stage/backend"
php artisan migrate --force --no-interaction
if [ "$DEPLOY_CATALOG_MIGRATIONS" = true ]; then
    php artisan migrate --database=catalog --path=database/migrations/catalog --force --no-interaction
fi
APP_PUBLIC_PATH="$public" php artisan optimize
find "$stage/public" -type d -exec chmod 755 {} +
find "$stage/public" -type f -exec chmod 644 {} +
ln -s "$shared/storage/app/public" "$stage/public/storage"
if [ -d "$public/storage" ] && [ ! -L "$public/storage" ]; then rsync -a "$public/storage/" "$shared/storage/app/public/"; fi
ln -sfn "$stage/backend" "$backend"
rsync -ac --delete --exclude="/.$app-deploy" --exclude='/.well-known/' --exclude='/.htaccess' --exclude='/.htpasswd' "$stage/public/" "$public/"
[ -f "$public/.htaccess" ] || cp "$stage/public/.htaccess" "$public/.htaccess"
printf '%s\n' "$app" > "$public/.$app-deploy"
chmod 644 "$public/.$app-deploy"
curl --fail --silent --show-error --location --retry 3 --max-time 30 "$url/?release=$release" -o "$backup/live.html"
curl --fail --silent --show-error --retry 3 --max-time 30 "$url/release.json?release=$release" -o "$backup/live-release.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true); if(($r["revision"]??null)!==$argv[2])exit(1);' "$backup/live-release.json" "${release:0:40}"
if [ "$DEPLOY_SESSION_CHECK" = true ]; then
    curl --fail --silent --show-error --max-time 30 "$url/api.php?route=session" -o "$backup/session.json"
    php -r '$v=json_decode(file_get_contents($argv[1]),true); if(!is_array($v)||!array_key_exists("user",$v)||empty($v["csrf"]))exit(1);' "$backup/session.json"
fi
if [ -f "$stage/public/sw.js" ]; then
    curl --fail --silent --show-error --max-time 30 "$url/sw.js?release=$release" -o "$backup/sw.js"
    cmp "$stage/public/sw.js" "$backup/sw.js"
fi
trap - ERR
printf '%s\n' "$release" > "$shared/current-release"
echo 'Release actief; vorige release, configuratie en back-ups behouden.'
