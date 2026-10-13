#!/usr/bin/env bash
# Mid-flight lane-log → matrix-events (CLI parent near-real-time).
# Scans newest matrix-lane logs for failure signatures not yet emitted.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/../lib/lab-paths.sh"
EVENTS="${E2E_MATRIX_EVENTS_FILE}"
REPORTS="${E2E_REPORTS_DIR:-${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats}"
STATE_DIR="${E2E_LAB_LOGDIR}/scanner-state"
mkdir -p "$(dirname "$EVENTS")" "$STATE_DIR"

emit() {
  local kind="$1" project="$2" detail="$3"
  local key
  key="$(printf '%s|%s|%s' "$kind" "$project" "$detail" | sha256sum | awk '{print $1}')"
  local mark="${STATE_DIR}/${key}"
  [[ -f "$mark" ]] && return 0
  printf '%s %s project=%s slot=scan %s\n' "$(date -Iseconds)" "$kind" "$project" "$detail" >>"$EVENTS"
  : >"$mark"
}

scan_file() {
  local f="$1"
  local base project
  base="$(basename "$f")"
  project="${base#matrix-lane-}"
  project="${project%.log}"
  # strip timestamp prefix YYYYMMDD-HHMMSS-
  project="$(echo "$project" | sed -E 's/^[0-9]{8}-[0-9]{6}-//')"

  local plain
  plain="$(sed 's/\x1b\[[0-9;]*m//g' "$f" 2>/dev/null || true)"

  if echo "$plain" | grep -q 'WPTSALL Client (:8977)\|POST http://127.0.0.1:8977\|connect ECONNREFUSED 127.0.0.1:8977'; then
    emit "SIGNAL_CLIENT_SHARED" "$project" "hit_shared_8977=1"
  fi
  if echo "$plain" | grep -qE 'connect ECONNREFUSED 127.0.0.1:90(77|78|79|84)'; then
    emit "SIGNAL_CLIENT_SLOT" "$project" "slot_econnrefused=1"
  fi
  if echo "$plain" | grep -q "wp_lab_docker_exec.: No such file"; then
    emit "SIGNAL_TIMEOUT_FN" "$project" "timeout_bash_fn=1"
  fi
  if echo "$plain" | grep -q 'Write-back verification had failures\|Virtual: posts exist.*0 posts'; then
    emit "SIGNAL_WRITEBACK" "$project" "writeback_fail=1"
  fi
  if echo "$plain" | grep -qE 'Frontend route .* unreachable \(HTTP'; then
    local path http
    path="$(echo "$plain" | grep -Eo 'Frontend route [^ ]+ unreachable' | tail -1 | awk '{print $3}')"
    http="$(echo "$plain" | grep -Eo 'unreachable \(HTTP [0-9]+' | tail -1 | grep -Eo '[0-9]+' || echo '?')"
    emit "SIGNAL_FRONTEND" "$project" "http=${http} path=${path:-?}"
  fi
  if echo "$plain" | grep -q 'refusing fallback to shared'; then
    emit "SIGNAL_SLOT_ABORT" "$project" "slot_start_failed=1"
  fi
  if echo "$plain" | grep -q 'ABORT: Stage failed'; then
    emit "SIGNAL_ABORT_LINE" "$project" "abort_in_log=1"
  fi
}

# Prefer current wave files (mtime within 2h); cap work.
mapfile -t FILES < <(
  find "${REPORTS}" -maxdepth 1 -name 'matrix-lane-*.log' -mmin -120 -printf '%T@ %p\n' 2>/dev/null \
    | sort -nr | awk '{print $2}' | head -40
)
for f in "${FILES[@]:-}"; do
  [[ -f "$f" ]] || continue
  scan_file "$f"
done
