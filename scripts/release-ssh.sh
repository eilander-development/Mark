#!/usr/bin/env bash
set -euo pipefail
mkdir -p ~/.ssh
chmod 700 ~/.ssh
if [ -n "${SSH_KNOWN_HOSTS:-}" ]; then
    printf '%s\n' "$SSH_KNOWN_HOSTS" > ~/.ssh/known_hosts
else
    [ -n "${SSH_HOST_FINGERPRINT:-}" ] || { echo 'SSH_KNOWN_HOSTS of SSH_HOST_FINGERPRINT ontbreekt.' >&2; exit 1; }
    candidate=$(mktemp)
    trap 'rm -f -- "$candidate"' EXIT
    ssh-keyscan -4 -T 30 -t ed25519 -p "$SSH_TARGET_PORT" "$SSH_TARGET_HOST" > "$candidate"
    fingerprint=$(ssh-keygen -lf "$candidate" | awk '{print $2}')
    [ "$fingerprint" = "$SSH_HOST_FINGERPRINT" ] || { echo 'SSH-hostvingerafdruk wijkt af van de geverifieerde host.' >&2; exit 1; }
    cp "$candidate" ~/.ssh/known_hosts
fi
chmod 600 ~/.ssh/known_hosts
