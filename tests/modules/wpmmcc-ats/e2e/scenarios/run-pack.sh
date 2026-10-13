#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
SCENARIO_ROOT="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/scenarios"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${RUNTIME_DIR}"

PRODUCT="all"
LAYER="all"
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/scenarios/run-pack.sh [options]

Options:
  --product <all|wptsall|cloud-api-hub|github-deployer>
  --layer <all|smoke|business|e2e|release>
  --dry-run
  -h, --help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --product)
      PRODUCT="${2:-all}"
      shift 2
      ;;
    --layer)
      LAYER="${2:-all}"
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
      echo "[scenario-pack] unknown arg: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

TS="$(date +%Y%m%d-%H%M%S)"
REPORT_JSON="${RUNTIME_DIR}/scenario-pack-${TS}.json"
LATEST_JSON="${RUNTIME_DIR}/scenario-pack-latest.json"
RESULTS_TSV="$(mktemp)"
trap 'rm -f "${RESULTS_TSV}"' EXIT

mapfile -t SCENARIO_SPECS < <(find "${SCENARIO_ROOT}" -type f -name "spec.json" | sort)

run_count=0
for spec in "${SCENARIO_SPECS[@]}"; do
  selection="$(python3 - <<'PY' "${spec}" "${PRODUCT}" "${LAYER}"
import json
import pathlib
import sys

spec = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
want_product = sys.argv[2]
want_layer = sys.argv[3]

product = str(spec.get("product") or "").strip()
layer = str(spec.get("layer") or "").strip()
scenario_id = str(spec.get("scenario_id") or "").strip()

if not scenario_id or not product or not layer:
    print("skip")
    raise SystemExit(0)
if want_product != "all" and product != want_product:
    print("skip")
    raise SystemExit(0)
if want_layer != "all" and layer != want_layer:
    print("skip")
    raise SystemExit(0)
print("run")
PY
)"
  if [[ "${selection}" != "run" ]]; then
    continue
  fi

  scenario_dir="$(dirname "${spec}")"
  runner="${scenario_dir}/run.sh"
  if [[ ! -x "${runner}" ]]; then
    echo "[scenario-pack] skip non-executable runner: ${runner}" >&2
    continue
  fi

  cmd=(bash "${runner}")
  if [[ "${DRY_RUN}" == "1" ]]; then
    cmd+=(--dry-run)
  fi

  echo "[scenario-pack] run ${runner}"
  set +e
  "${cmd[@]}"
  rc=$?
  set -e

  meta="$(python3 - <<'PY' "${spec}"
import json
import pathlib
import sys
spec = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
print(
    f"{spec.get('scenario_id','')}\t"
    f"{spec.get('requirement_id','')}\t"
    f"{spec.get('product','')}\t"
    f"{spec.get('layer','')}\t"
    f"{spec.get('owner','')}"
)
PY
)"
  printf '%s\t%s\n' "${meta}" "${rc}" >> "${RESULTS_TSV}"
  run_count=$((run_count + 1))
done

python3 - <<'PY' "${RESULTS_TSV}" "${REPORT_JSON}" "${LATEST_JSON}" "${PRODUCT}" "${LAYER}" "${DRY_RUN}" "${TS}"
import json
import pathlib
import sys
from collections import Counter
from datetime import datetime

results_file = pathlib.Path(sys.argv[1])
report_file = pathlib.Path(sys.argv[2])
latest_file = pathlib.Path(sys.argv[3])
selected_product = sys.argv[4]
selected_layer = sys.argv[5]
dry_run = sys.argv[6] == "1"
timestamp = sys.argv[7]

rows = []
if results_file.exists():
    for line in results_file.read_text(encoding="utf-8").splitlines():
        parts = line.split("\t")
        if len(parts) != 6:
            continue
        scenario_id, requirement_id, product, layer, owner, rc = parts
        rc_int = int(rc)
        rows.append(
            {
                "scenario_id": scenario_id,
                "requirement_id": requirement_id,
                "product": product,
                "layer": layer,
                "owner": owner,
                "status": "passed" if rc_int == 0 else "failed",
                "exit_code": rc_int,
            }
        )

status_counts = Counter([r["status"] for r in rows])
overall_status = "passed" if status_counts.get("failed", 0) == 0 else "failed"

payload = {
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "status": overall_status,
    "selection": {
        "product": selected_product,
        "layer": selected_layer,
        "dry_run": dry_run,
    },
    "summary": {
        "total": len(rows),
        "status_counts": dict(status_counts),
    },
    "results": rows,
    "run_id": f"scenario-pack-{timestamp}",
}

body = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
report_file.write_text(body, encoding="utf-8")
latest_file.write_text(body, encoding="utf-8")
print(f"[scenario-pack] status={overall_status}")
print(f"[scenario-pack] total={len(rows)}")
print(f"[scenario-pack] report={report_file}")
print(f"[scenario-pack] latest={latest_file}")
PY

if [[ "${run_count}" -eq 0 ]]; then
  echo "[scenario-pack] no matched scenarios for product=${PRODUCT} layer=${LAYER}" >&2
fi
