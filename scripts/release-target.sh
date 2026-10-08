#!/usr/bin/env bash
set -euo pipefail
source scripts/deploy-config.sh
host="${SSH_HOST:-${FTP_SERVER:-}}"
host="${host#ftp://}"
host="${host#ftps://}"
host="${host%%/*}"
port="${SSH_PORT:-26}"
user="${SSH_USERNAME:-${FTP_USERNAME:-}}"
target="${DEPLOY_PATH:-${FOODTRACKER_SSH_PATH:-${SSH_PATH:-}}}"
url="${DEPLOY_URL:-${DEPLOY_DEFAULT_URL:-}}"
[[ "$host" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]]
[[ "$user" =~ ^[A-Za-z0-9_][A-Za-z0-9_-]*$ ]]
[[ "$port" =~ ^[0-9]{1,5}$ ]] && ((10#$port > 0 && 10#$port <= 65535))
[[ "$target" =~ ^[A-Za-z0-9_./-]+$ && "$target" != *'..'* && "$target" != public_html && "$target" != / ]]
[[ "$url" =~ ^https://[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || { echo 'Stel DEPLOY_URL in op de https-site zonder afsluitende slash.' >&2; exit 1; }
printf 'SSH_TARGET_HOST=%s\nSSH_TARGET_PORT=%s\nSSH_TARGET_USER=%s\nDEPLOY_TARGET=%s\nDEPLOY_URL=%s\n' "$host" "$port" "$user" "$target" "$url" >> "${GITHUB_ENV:?}"
