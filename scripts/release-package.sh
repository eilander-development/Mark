#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source scripts/deploy-config.sh
output="${1:?Absoluut bundelpad ontbreekt}"
[[ "$output" = /* ]]
app=.
[ ! -f backend/artisan ] || app=backend
public=public
[ "$app" != backend ] || public=dist
test -f "$app/vendor/autoload.php"
test -f "$public/index.html" || test -f "$public/index.php"
test ! -e "$public/hot"
stage=$(mktemp -d)
trap 'chmod -R u+rwX -- "$stage"; rm -rf -- "$stage"' EXIT
mkdir -p "$stage/backend" "$stage/public" "$stage/scripts"
paths=(app bootstrap config database resources routes vendor artisan composer.json composer.lock)
[ ! -d "$app/lang" ] || paths+=(lang)
tar --exclude='bootstrap/cache/*.php' --exclude='database/*.sqlite*' --exclude='.env*' --exclude='node_modules' --exclude='.git' --exclude='qa' -cf - -C "$app" "${paths[@]}" | tar -xf - -C "$stage/backend"
tar --exclude='./hot' --exclude='./storage' --exclude='./.env*' -cf - -C "$public" . | tar -xf - -C "$stage/public"
cp scripts/deploy-config.sh scripts/release-backup.php "$stage/scripts/"
if [ "$app" != backend ]; then
    sed "s|__BACKEND__|$DEPLOY_APP-backend|g" scripts/release-index.php > "$stage/public/index.php"
    test -f "$public/build/manifest.json"
else
    cp backend/public/.htaccess "$stage/public/.htaccess"
fi
printf '{"revision":"%s"}\n' "$(git rev-parse HEAD)" > "$stage/public/release.json"
tar -czf "$output" -C "$stage" backend public scripts
