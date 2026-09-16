#!/usr/bin/env bash
#
# MainzWorld stack verifier / cleaner — companion to start-mainzworld.sh
#
# VERIFY: ./check-mainzworld.sh            (or: npm run check:stack)
# CLEAN:  ./check-mainzworld.sh --clean    (or: npm run clean:stack)
#
# Reports whether Postgres/PHP-FPM/Apache are up and flags duplicate PIDs on
# their ports (never kills them — they're brew daemons managed separately).
# Also reports Vite dev/preview ports and any orphaned vite/esbuild processes
# not attached to a listening port (typically hung/stale). --clean kills only
# those frontend-owned processes.
#
set -uo pipefail

green()  { printf '\033[32m%s\033[0m' "$1"; }
red()    { printf '\033[31m%s\033[0m' "$1"; }
yellow() { printf '\033[33m%s\033[0m' "$1"; }

CLEAN=false
[[ "${1:-}" == "--clean" ]] && CLEAN=true

DAEMON_PORTS=(5432 9000 8080)    # Postgres, PHP-FPM, Apache — report only
VITE_PORTS=(5173 5174 5175 4173) # Vite dev/preview — safe to clean

pids_on_port() { lsof -nP -tiTCP:"$1" -sTCP:LISTEN 2>/dev/null; }

echo "== Daemon ports (report only — never killed by this script) =="
echo "   (multiple pids here is normal: Apache/PHP-FPM run worker pools)"
for port in "${DAEMON_PORTS[@]}"; do
  pids=$(pids_on_port "$port")
  if [[ -z "$pids" ]]; then
    printf '  :%-5s %s\n' "$port" "$(red DOWN)"
  else
    n=$(wc -w <<<"$pids")
    printf '  :%-5s %s  %s worker(s), pid(s): %s\n' "$port" "$(green UP)" "$n" "$(echo "$pids" | tr '\n' ' ')"
  fi
done

echo
echo "== Vite dev/preview ports =="
kill_list=()
for port in "${VITE_PORTS[@]}"; do
  pids=$(pids_on_port "$port")
  if [[ -z "$pids" ]]; then
    printf '  :%-5s not running\n' "$port"
  else
    n=$(wc -w <<<"$pids")
    label=$([[ "$n" -gt 1 ]] && yellow "MULTIPLE ($n)" || green UP)
    printf '  :%-5s %s  pid(s): %s\n' "$port" "$label" "$(echo "$pids" | tr '\n' ' ')"
    kill_list+=($pids)
  fi
done

echo
echo "== Orphaned Vite/esbuild processes (no listening port — usually stale/hung) =="
orphans=$(pgrep -f '[v]ite|[e]sbuild' 2>/dev/null || true)
found_orphan=false
for pid in $orphans; do
  if ! printf '%s\n' "${kill_list[@]:-}" | grep -qx "$pid"; then
    found_orphan=true
    printf '  pid %s: %s\n' "$pid" "$(ps -p "$pid" -o command= 2>/dev/null)"
    kill_list+=("$pid")
  fi
done
[[ "$found_orphan" == false ]] && echo "  none found"

echo
if [[ "$CLEAN" == true ]]; then
  if [[ ${#kill_list[@]} -eq 0 ]]; then
    echo "Nothing to clean."
  else
    unique=($(printf '%s\n' "${kill_list[@]}" | sort -un))
    echo "Killing stray/duplicate Vite processes: ${unique[*]}"
    kill -9 "${unique[@]}" 2>/dev/null || true
    echo "$(green Done.) Postgres/PHP-FPM/Apache daemons were left untouched."
  fi
else
  if [[ ${#kill_list[@]} -gt 0 ]]; then
    echo "Run with --clean to kill the stray/duplicate Vite processes listed above."
  else
    echo "Stack looks clean."
  fi
fi
