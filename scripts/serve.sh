#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
"$ROOT/scripts/prepare-dev.sh"
cd "$ROOT"
exec python3 -m http.server "${1:-8080}"
