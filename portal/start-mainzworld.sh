#!/usr/bin/env bash
# Thin redirect — the canonical launcher lives at the repo root.
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/start-mainzworld.sh" "$@"
