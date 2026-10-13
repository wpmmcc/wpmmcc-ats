#!/usr/bin/env bash
# Ensure event-driven wake monitor is running (for parent CLI notifications).
set -euo pipefail
SCRIPTS="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPTS}/../lib/lab-paths.sh"
LOGDIR="${E2E_LAB_LOGDIR}"
PID_FILE="${LOGDIR}/cli-event-wake.pid"

if pgrep -f 'lab-cli-event-wake.sh' >/dev/null 2>&1; then
  echo "event-wake: already running"
  pgrep -af 'lab-cli-event-wake.sh' | grep -v pgrep | head -2
  exit 0
fi

nohup bash "${SCRIPTS}/lab-cli-event-wake.sh" >>"${LOGDIR}/cli-event-wake.log" 2>&1 &
echo $! >"$PID_FILE"
sleep 1
if pgrep -f 'lab-cli-event-wake.sh' >/dev/null 2>&1; then
  echo "event-wake: started pid=$(cat "$PID_FILE")"
else
  echo "event-wake: failed to start" >&2
  exit 1
fi
