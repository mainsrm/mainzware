#!/usr/bin/env bash
#
# MainzWorld stack verifier / cleaner — companion to start-mainzworld.sh
#
# Run from ANY directory (cwd doesn't matter for any of these forms):
#   ~/MainzWare/check-mainzworld.sh            # absolute path, works anywhere
#   npm run check:stack                        # from the repo root or portal/frontend
#   npm run clean:stack                        # same, with --clean
# Only `./check-mainzworld.sh` requires your cwd to already be the repo root
# (a bare `./` is a relative path) — prefer one of the forms above instead.
#
# Reports whether Postgres/PHP-FPM/Apache are up and flags duplicate PIDs on
# their ports (never kills them — they're brew daemons managed separately).
# Also checks php-fpm.log for workers that have segfaulted since the last
# restart (e.g. the macOS Kerberos/libpq GSS fork-safety crash) and reports
# Vite dev/preview ports plus any orphaned vite/esbuild processes not attached
# to a listening port (typically hung/stale). --clean restarts a crashing
# PHP-FPM and kills stray/duplicate frontend processes. Checks Live Worship's
# photo-service health endpoint; --clean also stops confirmed stuck OCR workers.
#
set -uo pipefail

green()  { printf '\033[32m%s\033[0m' "$1"; }
red()    { printf '\033[31m%s\033[0m' "$1"; }
yellow() { printf '\033[33m%s\033[0m' "$1"; }

CLEAN=false
[[ "${1:-}" == "--clean" ]] && CLEAN=true
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
WORSHIP_OCR_DIR="$ROOT_DIR/live-worship/ocr"

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
echo "== Live Worship photo service (:8765) =="
ocr_needs_attention=false
ocr_stuck_pids=()
is_worship_ocr_pid() {
  local cwd command
  cwd=$(lsof -a -p "$1" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')
  command=$(ps -p "$1" -o command= 2>/dev/null)
  [[ "$cwd" == "$WORSHIP_OCR_DIR" && "$command" == *Python*server.py* ||
     "$cwd" == "$WORSHIP_OCR_DIR" && "$command" == *python*server.py* ]]
}
ocr_pids=$(pids_on_port 8765)
if [[ -z "$ocr_pids" ]]; then
  echo "  $(yellow 'NOT RUNNING') — photo imports are unavailable."
  ocr_needs_attention=true
else
  ocr_health=$(curl --noproxy '*' --silent --fail --connect-timeout 2 --max-time 5 http://127.0.0.1:8765/health 2>/dev/null)
  ocr_health_status=$?
  if [[ "$ocr_health_status" -eq 0 ]] && printf '%s' "$ocr_health" | grep -Eq '"ok"[[:space:]]*:[[:space:]]*true'; then
    echo "  $(green HEALTHY) — photo service responds to its health check."
  else
    ocr_needs_attention=true
    echo "  $(red UNHEALTHY) — port is open, but the photo-service health check failed."
    for pid in $ocr_pids; do
      if is_worship_ocr_pid "$pid"; then
        echo "  Stuck Live Worship photo-service pid: $pid"
        ocr_stuck_pids+=("$pid")
      else
        echo "  Unrecognized listener pid $pid — inspect manually; it will not be stopped."
      fi
    done
  fi
fi

echo
echo "== PHP-FPM stability (segfault check since last restart) =="
FPM_LOG="$(brew --prefix)/var/log/php-fpm.log"
fpm_needs_restart=false
if [[ -f "$FPM_LOG" ]]; then
  last_start_line=$(grep -n 'NOTICE: fpm is running' "$FPM_LOG" | tail -1 | cut -d: -f1 || true)
  if [[ -n "$last_start_line" ]]; then
    segfaults=$(tail -n +"$last_start_line" "$FPM_LOG" | grep -c 'SIGSEGV' || true)
  else
    segfaults=$(grep -c 'SIGSEGV' "$FPM_LOG" || true)
  fi
  if [[ "${segfaults:-0}" -gt 0 ]]; then
    echo "  $(red "${segfaults} crash(es)") since PHP-FPM last started — workers are segfaulting on every request."
    fpm_needs_restart=true
  else
    echo "  $(green 'No crashes') since PHP-FPM last started."
  fi
else
  echo "  log not found, skipping ($FPM_LOG)"
fi

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
  for pid in "${ocr_stuck_pids[@]:-}"; do
    [[ -n "$pid" ]] || continue
    # Recheck ownership before stopping anything, including after the grace period.
    if is_worship_ocr_pid "$pid"; then
      echo "Stopping unresponsive Live Worship photo-service pid $pid..."
      kill -TERM "$pid" 2>/dev/null || true
      sleep 2
      if is_worship_ocr_pid "$pid"; then
        kill -KILL "$pid" 2>/dev/null || true
      fi
    fi
  done
  if [[ ${#kill_list[@]} -eq 0 ]]; then
    echo "Nothing to clean on the Vite ports."
  else
    unique=($(printf '%s\n' "${kill_list[@]}" | sort -un))
    echo "Killing stray/duplicate Vite processes: ${unique[*]}"
    kill -9 "${unique[@]}" 2>/dev/null || true
    echo "$(green Done.)"
  fi
  if [[ "$fpm_needs_restart" == true ]]; then
    echo "Restarting PHP-FPM to clear crashed workers..."
    brew services restart php >/dev/null 2>&1 && echo "$(green Done.) PHP-FPM restarted."
  fi
  [[ ${#kill_list[@]} -eq 0 && "$fpm_needs_restart" == false && "$ocr_needs_attention" == false ]] && echo "$(green Stack is clean.)"
else
  if [[ ${#ocr_stuck_pids[@]} -gt 0 ]]; then
    echo "Run with --clean to stop the stuck Live Worship photo service."
  fi
  if [[ ${#kill_list[@]} -gt 0 ]]; then
    echo "Run with --clean to kill the stray/duplicate Vite processes listed above."
  fi
  if [[ "$fpm_needs_restart" == true ]]; then
    echo "Run with --clean to restart PHP-FPM and clear the crashed workers."
  fi
  if [[ ${#kill_list[@]} -eq 0 && "$fpm_needs_restart" == false && "$ocr_needs_attention" == false ]]; then
    echo "Stack looks clean."
  fi
fi
if [[ "$ocr_needs_attention" == true ]]; then
  echo "Start the photo service with start-mainzworld.sh after resolving any unhealthy listener."
  echo "Setup details: live-worship/README.md"
fi
