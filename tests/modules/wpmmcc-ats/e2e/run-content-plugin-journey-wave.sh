#!/usr/bin/env bash
# Integrated content-plugin journey wave: Stage 8 Playwright journeys (default) + monitor hooks
#
# Usage:
#   WPTSALL_LAB=1 DEMO_PASSWORD=demo bash tests/modules/wpmmcc-ats/e2e/run-content-plugin-journey-wave.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-content-plugin-journey-wave.sh --jobs 14 --scope full
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-content-plugin-journey-wave.sh --full-translate --jobs 14
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib/failure-classification.sh"

export WPTSALL_LAB=1
export DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
JOBS="${E2E_MATRIX_JOBS:-14}"
SCOPE="${E2E_SCOPE:-full}"
# Default: Stage 8 only (skip Stages 1–7). Use --full-translate for full run.sh per lane.
SKIP_TRANSLATE="${E2E_JOURNEY_SKIP_TRANSLATE:-1}"
PROJECTS_CSV="${E2E_PROJECTS:-}"
LOGDIR="${E2E_LAB_LOGDIR}"
mkdir -p "$LOGDIR"
WAVE_ID="$(date +%Y%m%d-%H%M%S)"
WAVE_LOG="${LOGDIR}/journey-wave-${WAVE_ID}.log"
JOURNEY_STATUS="${LOGDIR}/JOURNEY-WAVE-STATUS.json"
exec > >(tee -a "$WAVE_LOG") 2>&1

extract_lane_failure() {
  local log="$1"
  local hint=""
  hint=$(grep -E "plugin journeys failed:|STAGE_FAILED|E2E run classified|ABORT:|Slot live runs cannot" "$log" 2>/dev/null | tail -1 || true)
  if [[ -z "$hint" ]]; then
    hint=$(tail -6 "$log" 2>/dev/null | tr '\n' ' ' | cut -c1-220)
  fi
  printf '%s' "$hint" | tr '\n' ' ' | cut -c1-220
}

refresh_journey_status() {
  python3 "${SCRIPT_DIR}/scripts/lab-cli-journey-wave-snapshot.py" >/dev/null 2>&1 || true
}

emit() {
  printf '%s %s\n' "$(date -Iseconds)" "$*" >>"${E2E_MATRIX_EVENTS_FILE:-$LOGDIR/matrix-events.log}" 2>/dev/null || true
}

list_projects() {
  python3 - <<'PY' "${E2E_PROJECT_SPECS_FILE}"
import json, sys
from pathlib import Path
spec = json.loads(Path(sys.argv[1]).read_text())
for n in spec.get("plugin_projects", {}):
    print(n)
PY
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --jobs) JOBS="$2"; shift 2 ;;
    --scope) SCOPE="$2"; shift 2 ;;
    --projects) PROJECTS_CSV="$2"; shift 2 ;;
    --skip-translate) SKIP_TRANSLATE=1; shift ;;
    --full-translate) SKIP_TRANSLATE=0; shift ;;
    *) echo "Unknown: $1" >&2; exit 1 ;;
  esac
done

mapfile -t PROJECTS < <(list_projects)
if [[ -n "$PROJECTS_CSV" ]]; then
  IFS=',' read -ra PROJECTS <<<"$PROJECTS_CSV"
fi

bash "${SCRIPT_DIR}/scripts/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
printf '%s\n' "$WAVE_ID" >"${LOGDIR}/current-journey-wave.mark"
emit "JOURNEY_WAVE_START id=${WAVE_ID} projects=${#PROJECTS[@]} scope=${SCOPE} jobs=${JOBS} log=${WAVE_LOG}"
refresh_journey_status

run_lane() {
  local project="$1" slot="$2"
  export E2E_PROJECT="$project" E2E_SLOT="$slot" E2E_SCOPE="$SCOPE" E2E_MATRIX_PARALLEL=1
  local log="${LOGDIR}/journey-wave-${WAVE_ID}-${project}.log"
  local rc=0
  set +e
  if [[ "$SKIP_TRANSLATE" -eq 1 ]]; then
    bash "${SCRIPT_DIR}/stages/08-plugin-journeys.sh" >>"$log" 2>&1
    rc=$?
  else
    bash "${SCRIPT_DIR}/run.sh" --skip-seed --with-plugin-journeys >>"$log" 2>&1
    rc=$?
  fi
  set -e
  # Emit as soon as this lane finishes (do not wait for batch siblings).
  if [[ "$rc" -eq 0 ]]; then
    emit "JOURNEY_LANE_PASSED project=${project} slot=${slot} log=${log}"
  else
    local reason
    reason="$(extract_lane_failure "$log")"
    emit "JOURNEY_LANE_FAILED project=${project} slot=${slot} reason=${reason} log=${log}"
  fi
  return "$rc"
}

# shellcheck disable=SC2207
SLOTS=($(e2e_matrix_slots))
FAIL=0
idx=0
pids=()
running=()
running_slots=()

launch_batch() {
  running=()
  running_slots=()
  pids=()
  local batch=("$@")
  for project in "${batch[@]}"; do
    slot="${SLOTS[$((idx % ${#SLOTS[@]}))]}"
    idx=$((idx + 1))
    info "Launch ${project} on ${slot}"
    emit "JOURNEY_LANE_START project=${project} slot=${slot} wave=${WAVE_ID}"
    run_lane "$project" "$slot" &
    pids+=($!)
    running+=("$project")
    running_slots+=("$slot")
  done
  local i=0
  for pid in "${pids[@]}"; do
    local proj="${running[$i]}"
    local slot="${running_slots[$i]}"
    local lane_log="${LOGDIR}/journey-wave-${WAVE_ID}-${proj}.log"
    if wait "$pid"; then
      # Event already emitted inside run_lane; refresh status here.
      ok "Journey lane passed: ${proj}"
    else
      local reason
      reason="$(extract_lane_failure "$lane_log")"
      err "Journey lane failed: ${proj} — ${reason}"
      FAIL=1
    fi
    bash "${SCRIPT_DIR}/scripts/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
    refresh_journey_status
    i=$((i + 1))
  done
  emit "JOURNEY_BATCH_DONE wave=${WAVE_ID} batch_done=${idx} fail=${FAIL}"
  refresh_journey_status
}

info "Phase A: Stage 8 plugin journeys (${#PROJECTS[@]} projects, jobs=${JOBS}, skip_translate=${SKIP_TRANSLATE})"
for ((i = 0; i < ${#PROJECTS[@]}; i += JOBS)); do
  batch=("${PROJECTS[@]:i:JOBS}")
  launch_batch "${batch[@]}"
done

info "Phase B: arch_seam gate"
# Cloud server L1 chain is not part of local Lab / full-chain mock runs.
if [[ "${E2E_SKIP_ARCH_SEAM:-0}" == "1" || "${FULL_CHAIN_SKIP_ARCH_SEAM:-0}" == "1" ]]; then
  warn "skipping arch_seam (E2E_SKIP_ARCH_SEAM/FULL_CHAIN_SKIP_ARCH_SEAM=1)"
elif [[ "${WPTSALL_LAB:-0}" == "1" ]]; then
  server_base="${SERVER_URL:-${WPTSALL_SERVER_BASE:-http://127.0.0.1:8787}}"
  if ! curl --noproxy '*' -fsS -m 2 "${server_base%/}/api/v1/health" >/dev/null 2>&1 \
    && ! curl --noproxy '*' -fsS -m 2 "${server_base%/}/health" >/dev/null 2>&1; then
    warn "skipping arch_seam — server not reachable at ${server_base} (local Lab)"
  elif ! bash "${SCRIPT_DIR}/run-architecture-seam-gate.sh"; then
    FAIL=1
  fi
elif ! bash "${SCRIPT_DIR}/run-architecture-seam-gate.sh"; then
  FAIL=1
fi

bash "${SCRIPT_DIR}/scripts/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
refresh_journey_status
if [[ "$FAIL" -eq 0 ]]; then
  emit "JOURNEY_WAVE_PASSED id=${WAVE_ID} log=${WAVE_LOG}"
  ok "Journey wave PASSED — ${WAVE_LOG}"
  exit 0
fi
emit "JOURNEY_WAVE_FAILED id=${WAVE_ID} log=${WAVE_LOG} status=${JOURNEY_STATUS}"
err "Journey wave FAILED — ${WAVE_LOG}"
exit 1
