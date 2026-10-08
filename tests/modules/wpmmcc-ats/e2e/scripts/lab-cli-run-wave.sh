#!/usr/bin/env bash
# Launch a lab test wave with monitoring preflight.
#
# Usage:
#   bash lab-cli-run-wave.sh full              # atomic nightly (matrix+CT+cross+hotplug)
#   bash lab-cli-run-wave.sh cross [N]         # cross worker samples (default N=24)
#   bash lab-cli-run-wave.sh cross-preflight   # 49-pair HTTP preflight only (~few min)
#   bash lab-cli-run-wave.sh matrix-redo       # matrix only, no-fail-fast
#   bash lab-cli-run-wave.sh ct                # CT release-required only
#   bash lab-cli-run-wave.sh arch-seam         # L1 chain + URL audit + admin/client UI
#   bash lab-cli-run-wave.sh status            # print wave queue + LIVE-STATUS
#
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/../lib/lab-paths.sh"
REPO="${REPO_ROOT}"
SCRIPTS="${SCRIPT_DIR}"
E2E="${E2E_DIR}"
LOGDIR="${E2E_LAB_LOGDIR}"
QUEUE="${LOGDIR}/WAVE-QUEUE.json"
EVENTS="${E2E_MATRIX_EVENTS_FILE}"

MODE="${1:-status}"
ARG2="${2:-}"

wave_id() { date +%Y%m%d-%H%M%S; }

lab_export_heavy() {
  export WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
  export E2E_MATRIX_EVENTS_FILE="$EVENTS"
  export CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-$(nproc)}"
  export M4_SIGN_THREADS="${M4_SIGN_THREADS:-$(nproc)}"
  export LAB_PHP_MEMORY_LIMIT="${LAB_PHP_MEMORY_LIMIT:-4096M}"
  export E2E_MATRIX_JOBS="${E2E_MATRIX_JOBS:-4}"
}

log_wave() {
  local id="$1" mode="$2" status="$3" note="${4:-}"
  python3 - "$QUEUE" "$id" "$mode" "$status" "$note" <<'PY'
import json, sys, time, pathlib
path, wid, mode, status, note = sys.argv[1:6]
p = pathlib.Path(path)
data = {"updated": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "waves": []}
if p.exists():
    try:
        data = json.loads(p.read_text())
    except Exception:
        pass
waves = data.get("waves") or []
for w in waves:
    if w.get("id") == wid:
        w.update({"mode": mode, "status": status, "note": note, "updated": data["updated"]})
        break
else:
    waves.append({"id": wid, "mode": mode, "status": status, "note": note, "started": data["updated"]})
data["waves"] = waves[-20:]
p.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
PY
}

start_wave() {
  local id="$1" mode="$2"
  printf '%s NIGHTLY_WAVE_START wave=%s mode=%s\n' "$(date -Iseconds)" "$id" "$mode" >"${LOGDIR}/current-wave.mark"
  printf '%s NIGHTLY_WAVE_START wave=%s mode=%s\n' "$(date -Iseconds)" "$id" "$mode" >>"$EVENTS"
  bash "${SCRIPTS}/lab-cli-ensure-wake.sh"
  bash "${SCRIPTS}/lab-cli-preflight-stack.sh" || {
    log_wave "$id" "$mode" "blocked" "lab stack not ready"
    exit 1
  }
  log_wave "$id" "$mode" "running" "pid=$$"
}

finish_wave() {
  local id="$1" mode="$2" rc="$3"
  local st=passed
  [[ "$rc" -ne 0 ]] && st=failed
  printf '%s NIGHTLY_WAVE_END wave=%s mode=%s status=%s\n' "$(date -Iseconds)" "$id" "$mode" "$st" >>"$EVENTS"
  log_wave "$id" "$mode" "$st" "exit=$rc"
  bash "${SCRIPTS}/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
  return "$rc"
}

run_full() {
  local id
  id=$(wave_id)
  start_wave "$id" "full"
  local log="${LOGDIR}/lab-wave-${id}-full.log"
  set +e
  (
    cd "$REPO"
    lab_export_heavy
    bash "${E2E}/run-lab-nightly.sh" --no-fail-fast
  ) 2>&1 | tee "$log"
  local rc=${PIPESTATUS[0]}
  set -e
  finish_wave "$id" "full" "$rc"
}

run_matrix_redo() {
  local id
  id=$(wave_id)
  start_wave "$id" "matrix-redo"
  local log="${LOGDIR}/lab-wave-${id}-matrix.log"
  set +e
  (
    cd "$REPO"
    export WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
    export E2E_MATRIX_EVENTS_FILE="$EVENTS"
    export E2E_MATRIX_JOBS=4
    bash "${E2E}/run-lab-nightly.sh" --no-fail-fast --skip-ct --skip-cross --skip-hotplug --skip-l-auth --skip-pw-prefetch
  ) 2>&1 | tee "$log"
  local rc=${PIPESTATUS[0]}
  set -e
  finish_wave "$id" "matrix-redo" "$rc"
}

run_ct() {
  local id
  id=$(wave_id)
  start_wave "$id" "ct"
  local log="${LOGDIR}/lab-wave-${id}-ct.log"
  set +e
  (
    cd "$REPO"
    export WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
    export E2E_MATRIX_EVENTS_FILE="$EVENTS"
    export CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-$(nproc)}"
    bash "${E2E}/run-lab-nightly.sh" --no-fail-fast --skip-matrix --skip-cross --skip-hotplug --skip-l-auth --skip-pw-prefetch
  ) 2>&1 | tee "$log"
  local rc=${PIPESTATUS[0]}
  set -e
  finish_wave "$id" "ct" "$rc"
}

run_cross() {
  local limit="${ARG2:-24}"
  local id
  id=$(wave_id)
  start_wave "$id" "cross-${limit}"
  local log="${LOGDIR}/lab-wave-${id}-cross.log"
  set +e
  (
    cd "$REPO"
    export WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
    export E2E_MATRIX_EVENTS_FILE="$EVENTS"
    export E2E_CROSS_WORKER_LIMIT="$limit"
    bash "${E2E}/run-content-vendor-sample-matrix.sh" --execute-worker --worker-limit "$limit"
  ) 2>&1 | tee "$log"
  local rc=${PIPESTATUS[0]}
  set -e
  finish_wave "$id" "cross-${limit}" "$rc"
}

run_cross_preflight() {
  local id
  id=$(wave_id)
  start_wave "$id" "cross-preflight"
  local log="${LOGDIR}/lab-wave-${id}-cross-pf.log"
  set +e
  (
    cd "$REPO"
    export WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
    export E2E_MATRIX_EVENTS_FILE="$EVENTS"
    bash "${E2E}/run-content-vendor-sample-matrix.sh" --apply
  ) 2>&1 | tee "$log"
  local rc=${PIPESTATUS[0]}
  set -e
  finish_wave "$id" "cross-preflight" "$rc"
}

run_arch_seam() {
  local id
  id=$(wave_id)
  start_wave "$id" "arch-seam"
  local log="${LOGDIR}/lab-wave-${id}-arch-seam.log"
  set +e
  (
    cd "$REPO"
    lab_export_heavy
    bash "${E2E}/run-architecture-seam-gate.sh"
  ) 2>&1 | tee "$log"
  local rc=${PIPESTATUS[0]}
  set -e
  finish_wave "$id" "arch-seam" "$rc"
}

show_status() {
  bash "${SCRIPTS}/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
  echo "=== WAVE QUEUE ==="
  python3 - "${LOGDIR}/WAVE-QUEUE.json" <<'PY'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1])
if not p.exists():
    print("(empty)"); raise SystemExit
d = json.loads(p.read_text())
for w in reversed(d.get("waves", [])[-8:]):
    print(f"{w.get('id')} {w.get('mode')} {w.get('status')} — {w.get('note','')}")
PY
  echo ""
  bash "${SCRIPTS}/lab-cli-parent-check.sh"
}

case "$MODE" in
  full) run_full ;;
  matrix-redo) run_matrix_redo ;;
  ct) run_ct ;;
  cross) run_cross ;;
  cross-preflight) run_cross_preflight ;;
  arch-seam) run_arch_seam ;;
  status) show_status ;;
  *)
    echo "Usage: bash lab-cli-run-wave.sh {full|arch-seam|matrix-redo|ct|cross [N]|cross-preflight|status}" >&2
    exit 1
    ;;
esac
