#!/usr/bin/env bash
# Namespaced slot cleanup for ISS T2.
#
# The historical name is kept because it is already referenced by Lab tooling.
# With no flag this script only reports its plan; --apply reaps the exact slot
# client processes and volatile files. It never removes run-manifest.json,
# slot-db.json, WordPress containers, Docker volumes, or MySQL schemas.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "${SCRIPT_DIR}/../../../../.." && pwd)}"
SLOT="${E2E_SLOT:-slot-a}"
APPLY=0
OUTCOME="${E2E_CLEANUP_OUTCOME:-pass}"
EXPECTED_RUN_ID="${E2E_RUN_ID:-}"

usage() {
  cat <<'EOF'
Usage: cleanup-slot-dry-run.sh [slot-a..slot-n] [--apply] [--outcome pass|fail] [--run-id <id>]

Without --apply, emit a namespaced cleanup plan only. --apply stops only
processes whose environment points at the selected slot DB and port, then
removes only volatile slot-local client files. Persistent run/infrastructure
manifests are retained as evidence.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    slot-a|slot-b|slot-c|slot-d|slot-e|slot-f|slot-g|slot-h|slot-i|slot-j|slot-k|slot-l|slot-m|slot-n)
      SLOT="$1"; shift ;;
    --apply)
      APPLY=1; shift ;;
    --outcome)
      OUTCOME="${2:-}"; shift 2 ;;
    --run-id)
      EXPECTED_RUN_ID="${2:-}"; shift 2 ;;
    --help|-h)
      usage; exit 0 ;;
    *)
      echo "[slot-cleanup] unsupported argument: $1" >&2
      usage >&2
      exit 2 ;;
  esac
done

case "${SLOT}" in
  slot-a|slot-b|slot-c|slot-d|slot-e|slot-f|slot-g|slot-h|slot-i|slot-j|slot-k|slot-l|slot-m|slot-n) ;;
  *) echo "[slot-cleanup] unsupported slot: ${SLOT}" >&2; exit 2 ;;
esac
case "${OUTCOME}" in
  pass|fail) ;;
  *) echo "[slot-cleanup] --outcome must be pass or fail" >&2; exit 2 ;;
esac

slot_port() {
  case "$1" in
    slot-a) echo 9077;; slot-b) echo 9078;; slot-c) echo 9079;; slot-d) echo 9084;;
    slot-e) echo 9085;; slot-f) echo 9086;; slot-g) echo 9087;; slot-h) echo 9088;;
    slot-i) echo 9089;; slot-j) echo 9091;; slot-k) echo 9092;; slot-l) echo 9093;;
    slot-m) echo 9094;; slot-n) echo 9095;;
  esac
}

SLOT_DIR="${E2E_DIR}/runtime/${SLOT}"
MANIFEST="${SLOT_DIR}/run-manifest.json"
CLIENT_DB="${SLOT_DIR}/client-db/wptsall.db"
CLIENT_LOG="${SLOT_DIR}/wptsall-client.log"
SESSION_TOKEN="${SLOT_DIR}/session-token.enc"
PID_FILE="${SLOT_DIR}/client.pid"
OUT="${SLOT_DIR}/cleanup.json"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/slot-cleanup"
PORT="$(slot_port "${SLOT}")"
SAFE_RUN_ID="$(printf '%s' "${EXPECTED_RUN_ID:-manual-$(date +%Y%m%d-%H%M%S)}" | tr -cd 'A-Za-z0-9._-')"
[[ -n "${SAFE_RUN_ID}" ]] || SAFE_RUN_ID="manual-$(date +%Y%m%d-%H%M%S)"
ARCHIVE="${REPORT_DIR}/${SLOT}-${SAFE_RUN_ID}-cleanup.json"

mkdir -p "${SLOT_DIR}" "${REPORT_DIR}"

# Never clean a newer lane. This additionally protects manual invocations and
# stale shells; the parallel matrix also waits for a lane to exit before reuse.
if [[ "${APPLY}" -eq 1 && -n "${EXPECTED_RUN_ID}" ]]; then
  python3 - "${MANIFEST}" "${EXPECTED_RUN_ID}" <<'PY'
import json, os, sys
path, expected = sys.argv[1:3]
if not os.path.isfile(path):
    raise SystemExit("[slot-cleanup] missing run manifest for --run-id guard")
try:
    current = json.load(open(path)).get("run_id", "")
except Exception as exc:
    raise SystemExit(f"[slot-cleanup] unreadable run manifest: {exc}")
if current != expected:
    raise SystemExit(f"[slot-cleanup] run-id mismatch; expected={expected!r} manifest={current!r}")
PY
fi

slot_process_matches() {
  local pid="$1"
  [[ "${pid}" =~ ^[0-9]+$ ]] || return 1
  [[ -r "/proc/${pid}/environ" ]] || return 1
  cat "/proc/${pid}/environ" 2>/dev/null | tr '\0' '\n' | grep -Fqx "WPTSALL_DB_PATH=${CLIENT_DB}" \
    && cat "/proc/${pid}/environ" 2>/dev/null | tr '\0' '\n' | grep -Fqx "WPTSALL_WEB_UI_PORT=${PORT}"
}

slot_processes() {
  local proc pid
  for proc in /proc/[0-9]*; do
    pid="${proc##*/}"
    slot_process_matches "${pid}" && echo "${pid}"
  done
}

mapfile -t MATCHED_PIDS < <(slot_processes || true)
PID_STATES=()
if [[ "${APPLY}" -eq 1 ]]; then
  for pid in "${MATCHED_PIDS[@]}"; do
    kill -TERM "${pid}" 2>/dev/null || true
  done
  for _ in $(seq 1 20); do
    [[ "$(slot_processes | wc -l | tr -d ' ')" == "0" ]] && break
    sleep 0.1
  done
  mapfile -t REMAINING_PIDS < <(slot_processes || true)
  for pid in "${REMAINING_PIDS[@]}"; do
    kill -KILL "${pid}" 2>/dev/null || true
  done
  for pid in "${MATCHED_PIDS[@]}"; do
    if slot_process_matches "${pid}"; then
      PID_STATES+=("${pid}:still_running")
    else
      PID_STATES+=("${pid}:reaped")
    fi
  done
else
  for pid in "${MATCHED_PIDS[@]}"; do
    PID_STATES+=("${pid}:would_reap")
  done
fi

PID_STATE_CSV="$(IFS=,; echo "${PID_STATES[*]:-}")"
python3 - "${SLOT_DIR}" "${MANIFEST}" "${OUT}" "${ARCHIVE}" "${SLOT}" "${APPLY}" \
  "${OUTCOME}" "${EXPECTED_RUN_ID}" "${CLIENT_DB}" "${CLIENT_LOG}" "${SESSION_TOKEN}" "${PID_FILE}" "${PID_STATE_CSV}" <<'PY'
import json
import os
import sys
import time

(slot_dir, manifest, out, archive, slot, apply_flag, outcome, expected_run_id,
 client_db, client_log, session_token, pid_file, pid_state_csv) = sys.argv[1:]
apply = apply_flag == "1"
root = os.path.realpath(slot_dir)
files = [pid_file, client_db, client_db + "-shm", client_db + "-wal", client_log, session_token]

def in_slot(path):
    return os.path.commonpath([root, os.path.realpath(path)]) == root

entries = []
failures = []
for path in files:
    exists = os.path.lexists(path)
    entry = {"path": path, "exists": exists, "action": "delete" if apply else "report"}
    if not in_slot(path):
        entry["safe"] = False
        entry["result"] = "refused_outside_slot"
        failures.append(entry["result"])
    elif os.path.islink(path):
        entry["safe"] = False
        entry["result"] = "refused_symlink"
        failures.append(entry["result"])
    else:
        entry["safe"] = True
        if apply and exists:
            try:
                os.remove(path)
                entry["result"] = "deleted"
            except OSError as exc:
                entry["result"] = f"delete_failed:{exc.__class__.__name__}"
                failures.append(entry["result"])
        else:
            entry["result"] = "would_delete" if exists and not apply else "absent"
    entries.append(entry)

try:
    manifest_data = json.load(open(manifest)) if os.path.isfile(manifest) else {}
except Exception:
    manifest_data = {}
if expected_run_id and manifest_data.get("run_id") != expected_run_id:
    failures.append("run_id_changed_during_cleanup")

pid_states = []
if pid_state_csv:
    for state in pid_state_csv.split(","):
        if not state:
            continue
        pid, _, result = state.partition(":")
        pid_states.append({"pid": int(pid), "result": result})
        if result == "still_running":
            failures.append("process_still_running")

report = {
    "slot": slot,
    "run_id": expected_run_id or manifest_data.get("run_id"),
    "apply": apply,
    "outcome": outcome,
    "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    "files": entries,
    "processes": pid_states,
    "preserved": [manifest, os.path.join(slot_dir, "slot-db.json")],
    "resource_reaped": not failures,
    "failures": failures,
}

os.makedirs(os.path.dirname(out), exist_ok=True)
os.makedirs(os.path.dirname(archive), exist_ok=True)
for destination in (out, archive):
    with open(destination, "w") as fh:
        json.dump(report, fh, indent=2)

if manifest_data:
    manifest_data["cleanup"] = {
        "state": "applied" if apply and not failures else ("failed" if failures else "planned"),
        "at": report["generated_at"],
        "report": archive,
        "resource_reaped": report["resource_reaped"],
    }
    with open(manifest, "w") as fh:
        json.dump(manifest_data, fh, indent=2)

print(json.dumps({
    "slot": slot,
    "apply": apply,
    "resource_reaped": report["resource_reaped"],
    "count": len(entries),
    "out": out,
    "archive": archive,
}, indent=2))
raise SystemExit(1 if apply and failures else 0)
PY
