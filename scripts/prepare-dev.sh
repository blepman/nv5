#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

rm -rf "$ROOT/sis/shared" "$ROOT/reise/shared"
cp -a "$ROOT/shared" "$ROOT/sis/shared"
cp -a "$ROOT/shared" "$ROOT/reise/shared"

# Reise uses shared icons until it has its own set
mkdir -p "$ROOT/reise/icons"
cp -a "$ROOT/sis/icons/"* "$ROOT/reise/icons/" 2>/dev/null || true

echo "Dev copies: sis/shared, reise/shared, reise/icons"
