#!/usr/bin/env bash
# Syntax check for every PHP and JS file in the plugin (no WordPress needed).
# Usage: scripts/lint.sh [dir]   (default: repo root)
set -uo pipefail

ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
fail=0

while IFS= read -r -d '' f; do
  out="$(php -l "$f" 2>&1)" || { echo "$out"; fail=1; }
done < <(find "$ROOT" -name '*.php' -not -path '*/.git/*' -not -path '*/node_modules/*' -print0)

if command -v node >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do
    node --check "$f" || fail=1
  done < <(find "$ROOT/assets/js" -name '*.js' -print0)
fi

[ "$fail" = 0 ] && echo "lint: OK"
exit "$fail"
