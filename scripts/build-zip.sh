#!/usr/bin/env bash
# Plugin package for WordPress.org or manual install: build/ship-today-delivery-dates.zip with wc-ship-today-notice/ inside.
# Skips files from .distignore (dev tools, tests).
# Usage: scripts/build-zip.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="wc-ship-today-notice"
ZIP="ship-today-delivery-dates.zip"
OUT="$ROOT/build"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# .distignore: patterns starting with "/" apply to the root only (as in rsync)
grep -v '^\s*#' "$ROOT/.distignore" | grep -v '^\s*$' > "$STAGE/exclude"

mkdir -p "$STAGE/$SLUG" "$OUT"
# Tracked files of the last commit only, so local tools stay out of the package
mkdir "$STAGE/src"
git -C "$ROOT" archive HEAD | tar -x -C "$STAGE/src"
rsync -a --exclude-from="$STAGE/exclude" "$STAGE/src/" "$STAGE/$SLUG/"

rm -f "$OUT/$ZIP"
(cd "$STAGE" && python3 -m zipfile -c "$OUT/$ZIP" "$SLUG")

echo "build: $OUT/$ZIP"
(cd "$STAGE" && find "$SLUG" -type f | sort)
