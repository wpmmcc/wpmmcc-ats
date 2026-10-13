#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime/scenario-runs"
mkdir -p "${RUNTIME_DIR}"

SPEC_FILE=""
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/scenarios/lib/run-standard-scenario.sh --spec <spec.json> [--dry-run]

Options:
  --spec <path>   Scenario spec JSON file
  --dry-run       Append --dry-run to command if not present
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --spec)
      SPEC_FILE="${2:-}"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[scenario] unknown arg: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ -z "${SPEC_FILE}" ]]; then
  echo "[scenario] --spec is required" >&2
  exit 1
fi

if [[ ! -f "${SPEC_FILE}" ]]; then
  echo "[scenario] spec file not found: ${SPEC_FILE}" >&2
  exit 1
fi

TS="$(date +%Y%m%d-%H%M%S)"

eval "$(python3 - <<'PY' "${SPEC_FILE}"
import json
import pathlib
import shlex
import sys

spec_path = pathlib.Path(sys.argv[1]).resolve()
spec = json.loads(spec_path.read_text(encoding="utf-8"))

def q(v):
    return shlex.quote(str(v or ""))

fields = {
    "SCENARIO_ID": spec.get("scenario_id"),
    "REQUIREMENT_ID": spec.get("requirement_id"),
    "OWNER": spec.get("owner"),
    "LAYER": spec.get("layer"),
    "PRODUCT": spec.get("product"),
    "SURFACE": spec.get("surface") or "scenario",
    "DESCRIPTION": spec.get("description") or "",
    "COMMAND": (spec.get("execution") or {}).get("command") or "",
    "REPORT_HINT": (spec.get("evidence") or {}).get("report_hint") or "",
}

for key, value in fields.items():
    print(f"{key}={q(value)}")
PY
)"

if [[ -z "${SCENARIO_ID}" || -z "${COMMAND}" ]]; then
  echo "[scenario] spec missing scenario_id or execution.command: ${SPEC_FILE}" >&2
  exit 1
fi

CMD="${COMMAND}"
if [[ "${DRY_RUN}" == "1" ]] && [[ "${CMD}" != *"--dry-run"* ]]; then
  CMD="${CMD} --dry-run"
fi

LOG_FILE="${RUNTIME_DIR}/${SCENARIO_ID}-${TS}.log"
RESULT_FILE="${RUNTIME_DIR}/${SCENARIO_ID}-${TS}.json"
LATEST_FILE="${RUNTIME_DIR}/${SCENARIO_ID}-latest.json"

echo "[scenario] id=${SCENARIO_ID}"
echo "[scenario] requirement=${REQUIREMENT_ID}"
echo "[scenario] layer=${LAYER}"
echo "[scenario] command=${CMD}"
echo "[scenario] log=${LOG_FILE}"

set +e
bash -lc "cd '${ROOT_DIR}' && ${CMD}" > "${LOG_FILE}" 2>&1
RC=$?
set -e

python3 - <<'PY' "${SPEC_FILE}" "${CMD}" "${LOG_FILE}" "${REPORT_HINT}" "${RC}" "${RESULT_FILE}" "${LATEST_FILE}" "${TS}" "${ROOT_DIR}"
import glob
import json
import pathlib
import shlex
import sys
from datetime import datetime

spec_path = pathlib.Path(sys.argv[1]).resolve()
command = sys.argv[2]
log_file = pathlib.Path(sys.argv[3]).resolve()
report_hint = sys.argv[4]
exit_code = int(sys.argv[5])
result_file = pathlib.Path(sys.argv[6]).resolve()
latest_file = pathlib.Path(sys.argv[7]).resolve()
timestamp = sys.argv[8]
repo_root = pathlib.Path(sys.argv[9]).resolve()

spec = json.loads(spec_path.read_text(encoding="utf-8"))

def find_latest_report(pattern):
    if not pattern:
        return None
    path_pattern = (repo_root / pattern).as_posix()
    matches = [pathlib.Path(p) for p in glob.glob(path_pattern)]
    if not matches:
        return None
    matches.sort(key=lambda p: p.stat().st_mtime, reverse=True)
    return matches[0]

status = "passed" if exit_code == 0 else "failed"
category = ""
message = "scenario passed"
next_action = "none"
evidence = str(log_file)

latest_report = find_latest_report(report_hint)
if status == "failed":
    category = "repo_regression"
    message = f"scenario command failed (exit={exit_code})"
    next_action = "Inspect log and related report, then fix failing contract or runtime issue"
    if latest_report and latest_report.exists():
        try:
            report = json.loads(latest_report.read_text(encoding="utf-8", errors="replace"))
            steps = report.get("steps") if isinstance(report, dict) else None
            if isinstance(steps, list):
                failed_steps = [s for s in steps if isinstance(s, dict) and s.get("status") == "failed"]
                if failed_steps:
                    step = failed_steps[0]
                    category = (step.get("category") or category)
                    message = (step.get("message") or message)
                    next_action = (step.get("next_action") or next_action)
                    evidence = (step.get("evidence") or evidence)
        except Exception:
            pass

payload = {
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "scenario_id": spec.get("scenario_id"),
    "requirement_id": spec.get("requirement_id"),
    "owner": spec.get("owner"),
    "layer": spec.get("layer"),
    "product": spec.get("product"),
    "surface": spec.get("surface") or "scenario",
    "description": spec.get("description") or "",
    "status": status,
    "category": category,
    "message": message,
    "next_action": next_action,
    "evidence": evidence,
    "command": command,
    "exit_code": exit_code,
    "log_file": str(log_file),
    "report_file": str(latest_report) if latest_report else None,
    "spec_file": str(spec_path),
    "run_id": f"{spec.get('scenario_id')}-{timestamp}",
}

body = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
result_file.write_text(body, encoding="utf-8")
latest_file.write_text(body, encoding="utf-8")

print(json.dumps(payload, ensure_ascii=False))
PY

if [[ "${RC}" -eq 0 ]]; then
  echo "[scenario] status=passed result=${RESULT_FILE}"
else
  echo "[scenario] status=failed result=${RESULT_FILE}" >&2
fi

exit "${RC}"
