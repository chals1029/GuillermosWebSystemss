#!/usr/bin/env bash
# Source this file from any deploy script that needs VPS_PASSWORD.
# It loads secrets.local.env from the same directory if present, or from
# environment variables already set in the caller's shell.

_SECRETS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_SECRETS_FILE="$_SECRETS_DIR/secrets.local.env"
if [ -f "$_SECRETS_FILE" ]; then
    # shellcheck disable=SC1090
    set -o allexport; . "$_SECRETS_FILE"; set +o allexport
fi

if [ -z "${VPS_PASSWORD:-}" ]; then
    echo "ERROR: VPS_PASSWORD is not set. Copy .deploy/secrets.local.env.example to .deploy/secrets.local.env and fill it in, or export VPS_PASSWORD in your shell." >&2
    exit 1
fi
