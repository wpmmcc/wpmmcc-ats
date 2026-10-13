#!/usr/bin/env bash
# Restart event-wake so stdout hits a Cursor monitored shell (required for 5m poll wake).
set -euo pipefail
SCRIPTS="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPTS}/../lib/lab-paths.sh"
LOGDIR="${E2E_LAB_LOGDIR}"
MON_LOG="${LOGDIR}/agent-wake-monitored.log"

pkill -f 'lab-cli-parent-poll-loop.sh' 2>/dev/null || true
pkill -f 'lab-cli-event-wake.sh' 2>/dev/null || true
sleep 1

if pgrep -f 'lab-cli-event-wake.sh' >/dev/null 2>&1; then
  echo "event-wake: still running after pkill; kill manually" >&2
  pgrep -af 'lab-cli-event-wake.sh' >&2
  exit 1
fi

echo "event-wake: stopped. Start in Cursor monitored shell:"
echo "  exec bash ${SCRIPTS}/lab-cli-event-wake.sh 2>&1 | stdbuf -oL tee -a ${MON_LOG}"
echo "  notify pattern: ^AGENT_LOOP_WAKE_labnightly"
