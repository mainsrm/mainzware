#!/usr/bin/env bash
# Thin redirect — the canonical checker lives at the repo root.
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/check-mainzworld.sh" "$@"
