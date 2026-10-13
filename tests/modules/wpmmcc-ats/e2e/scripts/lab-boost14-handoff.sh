#!/usr/bin/env bash
# Wait for an in-flight journey wave batch to finish, then launch remaining projects at --jobs 14.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/scripts/lab-boost14-handoff.sh
#   bash tests/modules/wpmmcc-ats/e2e/scripts/lab-boost14-handoff.sh --projects acf-content,site-reviews-content
#
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/../config.sh"

WAIT_SEC="${LAB_HANDOFF_WAIT_SEC:-7200}"
POLL_SEC="${LAB_HANDOFF_POLL_SEC:-30}"
JOBS="${E2E_MATRIX_JOBS:-14}"
SCOPE="${E2E_SCOPE:-full}"
PROJECTS_CSV="${E2E_PROJECTS:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --projects) PROJECTS_CSV="$2"; shift 2 ;;
    --jobs) JOBS="$2"; shift 2 ;;
    --scope) SCOPE="$2"; shift 2 ;;
    *) echo "Unknown: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$PROJECTS_CSV" ]]; then
  PROJECTS_CSV="acf-content,site-reviews-content,give-content,directorist-content,lifterlms-content,events-manager-content,hivepress-content,wptsall-content"
fi

info "Handoff: waiting up to ${WAIT_SEC}s for active journey-wave / run.sh lanes to drain..."
deadline=$((SECONDS + WAIT_SEC))
while (( SECONDS < deadline )); do
  active=$(pgrep -af 'run-content-plugin-journey-wave\.sh|tests/modules/wpmmcc-ats/e2e/run\.sh' 2>/dev/null | grep -v 'lab-boost14-handoff' | grep -v pgrep || true)
  if [[ -z "$active" ]]; then
    ok "No active journey/matrix lanes — launching handoff batch"
    break
  fi
  info "Still running ($(echo "$active" | wc -l) proc(s)); sleep ${POLL_SEC}s"
  sleep "$POLL_SEC"
done

if (( SECONDS >= deadline )); then
  err "Timed out waiting for lanes to drain; launching handoff anyway"
fi

emit_line() {
  printf '%s %s\n' "$(date -Iseconds)" "$*" >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
}
emit_line "JOURNEY_HANDOFF_START projects=${PROJECTS_CSV} jobs=${JOBS}"

exec env WPTSALL_LAB=1 E2E_MATRIX_JOBS="${JOBS}" E2E_SCOPE="${SCOPE}" \
  bash "${SCRIPT_DIR}/../run-content-plugin-journey-wave.sh" \
  --jobs "${JOBS}" --scope "${SCOPE}" --projects "${PROJECTS_CSV}"
