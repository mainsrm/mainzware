#!/usr/bin/env bash
#
# MainzWorld local dev launcher (VS Code internal terminal friendly).
#
# Run from ANY directory (cwd doesn't matter for any of these forms):
#   ~/MainzWare/start-mainzworld.sh   # absolute path, works anywhere
#   npm run start:stack               # from the repo root or portal/frontend
# Only `./start-mainzworld.sh` requires your cwd to already be the repo root
# (a bare `./` is a relative path) — prefer one of the forms above instead.
#
# STOP:   just close the VS Code terminal / press Ctrl-C — Vite and the
#         app-scoped Live Worship OCR service started by this shell stop.
#         Apache, PHP-FPM and PostgreSQL are lightweight brew daemons and
#         are intentionally left running for next time.
#
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$ROOT_DIR/portal/frontend"
WORSHIP_FRONTEND_DIR="$ROOT_DIR/live-worship/frontend"
WORSHIP_OCR_DIR="$ROOT_DIR/live-worship/ocr"

green() { printf '\033[32m%s\033[0m' "$1"; }
red()   { printf '\033[31m%s\033[0m' "$1"; }

port_up() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }

echo "Starting MainzWorld local stack..."

# 1. Backend daemons (no-op if already running).
echo "Ensuring backend services are running..."
brew services start postgresql@18 >/dev/null 2>&1 || true
brew services start php          >/dev/null 2>&1 || true
brew services start httpd        >/dev/null 2>&1 || true

# 2. Wait for the PHP API behind Apache to answer (any HTTP status = alive).
echo "Waiting for the API on http://localhost:8080 ..."
api_ready=false
for _ in {1..20}; do
  if curl --silent --output /dev/null --write-out '%{http_code}' --max-time 2 \
    http://localhost:8080/api/v1/auth/me 2>/dev/null | grep -Eq '^[1-5][0-9][0-9]$'; then
    api_ready=true
    break
  fi
  sleep 0.5
done

# 3. Print a clear status board for every part of the stack.
echo
echo "  Stack status:"
for entry in "PostgreSQL:5432" "PHP-FPM:9000" "Apache API:8080"; do
  name="${entry%%:*}"; port="${entry##*:}"
  if port_up "$port"; then
    printf '    %-12s %s (:%s)\n' "$name" "$(green UP)" "$port"
  else
    printf '    %-12s %s (:%s)\n' "$name" "$(red DOWN)" "$port"
  fi
done
echo

if [[ "$api_ready" != true ]]; then
  echo "$(red 'The PHP API did not respond on http://localhost:8080.')" >&2
  echo "  Apache/PHP-FPM may be misconfigured. Fix the backend before continuing." >&2
  exit 1
fi

# 4. Run the independent Live Worship frontend behind the portal path.
cd "$WORSHIP_FRONTEND_DIR"
./node_modules/.bin/vite --host 0.0.0.0 --port 5174 --strictPort &
WORSHIP_VITE_PID=$!

# PaddleOCR is isolated to Live Worship and listens on loopback only.
WORSHIP_OCR_PID=""
if [[ -x "$WORSHIP_OCR_DIR/.venv/bin/python" ]]; then
  if curl --silent --fail --max-time 1 http://127.0.0.1:8765/health >/dev/null 2>&1; then
    echo "Live Worship photo recognition is already running on 127.0.0.1:8765."
  elif ! port_up 8765; then
    (cd "$WORSHIP_OCR_DIR" && exec env PADDLE_PDX_CACHE_HOME="$WORSHIP_OCR_DIR/.cache" LIVE_WORSHIP_OCR_HOST=127.0.0.1 "$WORSHIP_OCR_DIR/.venv/bin/python" server.py) &
    WORSHIP_OCR_PID=$!
    echo "Starting the Live Worship photo recognition service..."
  else
    echo "Port 8765 is in use by another service; Live Worship photo recognition was not started." >&2
  fi
else
  echo "Live Worship photo recognition is not installed. See live-worship/README.md to set up PaddleOCR."
fi

# 5. Portal frontend runs in the FOREGROUND: closing this terminal also stops
#    Live Worship's Vite server and this shell's OCR helper.
cleanup() {
  kill "$WORSHIP_VITE_PID" 2>/dev/null || true
  if [[ -n "$WORSHIP_OCR_PID" ]]; then kill "$WORSHIP_OCR_PID" 2>/dev/null || true; fi
  echo
  echo "Stopping the React dev servers and Live Worship photo service (backend daemons stay running)."
}
trap cleanup EXIT INT TERM

echo "Starting the portal at http://localhost:5173 ..."
echo "Live Worship is available at http://localhost:5173/live-worship/"
echo "   (Press Ctrl-C or close this terminal to stop both frontends and OCR.)"
echo
cd "$FRONTEND_DIR"
npm run dev
