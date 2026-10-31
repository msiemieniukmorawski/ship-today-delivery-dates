#!/usr/bin/env bash
# Paczka wtyczki do WordPress.org / instalacji ręcznej: build/ship-today-delivery-dates.zip z katalogiem wc-ship-today-notice/ w środku.
# Pomija pliki z .distignore (narzędzia deweloperskie, testy).
# Usage: scripts/build-zip.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="wc-ship-today-notice"
ZIP="ship-today-delivery-dates.zip"
OUT="$ROOT/build"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# .distignore: wzorce zaczynające się od "/" dotyczą tylko katalogu głównego (jak w rsync)
grep -v '^\s*#' "$ROOT/.distignore" | grep -v '^\s*$' > "$STAGE/exclude"

mkdir -p "$STAGE/$SLUG" "$OUT"
# Tylko pliki śledzone przez Git (ostatni commit): lokalne narzędzia poza repo nie trafiają do paczki
mkdir "$STAGE/src"
git -C "$ROOT" archive HEAD | tar -x -C "$STAGE/src"
rsync -a --exclude-from="$STAGE/exclude" "$STAGE/src/" "$STAGE/$SLUG/"

rm -f "$OUT/$ZIP"
(cd "$STAGE" && python3 -m zipfile -c "$OUT/$ZIP" "$SLUG")

echo "build: $OUT/$ZIP"
(cd "$STAGE" && find "$SLUG" -type f | sort)
