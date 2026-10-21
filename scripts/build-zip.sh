#!/usr/bin/env bash
# Paczka wtyczki do WordPress.org / instalacji ręcznej: build/<slug>.zip z katalogiem <slug>/ w środku.
# Pomija pliki z .distignore (narzędzia deweloperskie, testy).
# Usage: scripts/build-zip.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="wc-ship-today-notice"
OUT="$ROOT/build"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# .distignore: wzorce zaczynające się od "/" dotyczą tylko katalogu głównego (jak w rsync)
grep -v '^\s*#' "$ROOT/.distignore" | grep -v '^\s*$' > "$STAGE/exclude"

mkdir -p "$STAGE/$SLUG" "$OUT"
rsync -a --exclude-from="$STAGE/exclude" "$ROOT/" "$STAGE/$SLUG/"

rm -f "$OUT/$SLUG.zip"
(cd "$STAGE" && python3 -m zipfile -c "$OUT/$SLUG.zip" "$SLUG")

echo "build: $OUT/$SLUG.zip"
(cd "$STAGE" && find "$SLUG" -type f | sort)
