#!/usr/bin/env bash
# Rebuilds DoctrineFMTest.fmp12 from build.ndjson using the `filemaker` CLI (FileMaker Pro must be installed).
#
#   tests/Functional/Database/build.sh            # builds, using the api_test password from ../.env.dist
#
# The file's full-access Admin account gets a random password, written to .admin-password (git-ignored).
set -euo pipefail
cd "$(dirname "$0")"

target="$PWD/DoctrineFMTest.fmp12"
[[ -e "$target" ]] && { echo "$target exists; move it away first." >&2; exit 1; }

api_password="${FM_PASSWORD:-$(grep '^FM_PASSWORD=' ../.env.dist | cut -d= -f2-)}"
umask 077
openssl rand -hex 12 > .admin-password
ops="$(mktemp)"
trap 'rm -f "$ops"' EXIT
sed "s/__API_PASSWORD__/${api_password}/" build.ndjson > "$ops"

filemaker --file="$target" --create --username=Admin --password="$(cat .admin-password)" --abort-on-error=true "$ops" \
    | grep -v '"type":"plugins"'
