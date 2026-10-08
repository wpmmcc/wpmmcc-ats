#!/usr/bin/env bash
# Write / update a run-manifest.json for an E2E lane (ISS T2 / W4).
set -euo pipefail

SLOT="${E2E_SLOT:-slot-a}"
PROJECT="${E2E_PROJECT:-unknown}"
SCOPE="${E2E_SCOPE:-full}"
RUN_ID="${E2E_RUN_ID:-$(date +%Y%m%d-%H%M%S)-${SLOT}-${PROJECT}}"
STATUS="${1:-running}" # running|pass|fail|cleanup
ROOT_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
OUT_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime/${SLOT}"
mkdir -p "${OUT_DIR}"
MANIFEST="${OUT_DIR}/run-manifest.json"

EXISTING="{}"
if [[ -f "${MANIFEST}" ]]; then
  EXISTING="$(cat "${MANIFEST}")"
fi

python3 - "${MANIFEST}" "${RUN_ID}" "${SLOT}" "${PROJECT}" "${SCOPE}" "${STATUS}" "${EXISTING}" <<'PY'
import json, sys, os, time
path, run_id, slot, project, scope, status, existing = sys.argv[1:8]
try:
    data = json.loads(existing) if existing.strip() else {}
except Exception:
    data = {}
data.update({
    "run_id": run_id,
    "slot": slot,
    "project": project,
    "scope": scope,
    "status": status,
    "updated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    "pid": os.getpid(),
    "cwd": os.getcwd(),
})
if status == "running" and "started_at" not in data:
    data["started_at"] = data["updated_at"]
if status in ("pass", "fail"):
    data["finished_at"] = data["updated_at"]
with open(path, "w") as f:
    json.dump(data, f, indent=2)
print(path)
PY

export E2E_RUN_ID="${RUN_ID}"
echo "[run-manifest] ${MANIFEST} status=${STATUS} run_id=${RUN_ID}"
