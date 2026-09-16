#!/usr/bin/env bash
#
# MainzWorld local dev launcher (VS Code internal terminal friendly).
#
# START:  ./start-mainzworld.sh   (or: npm run start:stack)
# STOP:   just close the VS Code terminal / press Ctrl-C — Vite stops.
#         Apache, PHP-FPM and PostgreSQL are lightweight brew daemons and
#         are intentionally left running for next time.
#
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$ROOT_DIR/portal/frontend"

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

# 4. Frontend runs in the FOREGROUND: closing this terminal stops it.
cleanup() {
  echo
  echo "Stopping the React dev server (backend daemons stay running)."
}
trap cleanup EXIT INT TERM

echo "Starting the React app at http://localhost:5173 ..."
echo "   (Press Ctrl-C or close this terminal to stop.)"
echo
cd "$FRONTEND_DIR"
exec npm run dev
