#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${RUNTIME_DIR}"

MODE="dry-run"
KEEP_PASSED_PER_LEVEL=8
KEEP_FAILED_DAYS=14

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/prune-runtime-artifacts.sh [options]

Options:
  --dry-run                    Show prune plan only (default)
  --apply                      Delete candidate artifacts
  --keep-passed-per-level N    Keep latest N passed runs for smoke/business (default: 8)
  --keep-failed-days N         Keep failed runs within N days (default: 14)
  -h, --help                   Show help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      MODE="dry-run"
      shift
      ;;
    --apply)
      MODE="apply"
      shift
      ;;
    --keep-passed-per-level)
      KEEP_PASSED_PER_LEVEL="${2:-}"
      shift 2
      ;;
    --keep-failed-days)
      KEEP_FAILED_DAYS="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[prune-runtime] unknown arg: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if ! [[ "${KEEP_PASSED_PER_LEVEL}" =~ ^[0-9]+$ ]]; then
  echo "[prune-runtime] --keep-passed-per-level must be non-negative integer" >&2
  exit 1
fi
if ! [[ "${KEEP_FAILED_DAYS}" =~ ^[0-9]+$ ]]; then
  echo "[prune-runtime] --keep-failed-days must be non-negative integer" >&2
  exit 1
fi

bash "${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/build-run-index.sh" >/dev/null

INDEX_JSON="${RUNTIME_DIR}/run-index-latest.json"
if [[ ! -f "${INDEX_JSON}" ]]; then
  echo "[prune-runtime] missing run index: ${INDEX_JSON}" >&2
  exit 1
fi

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
PLAN_JSON="${RUNTIME_DIR}/prune-runtime-artifacts-${TIMESTAMP}.json"
LATEST_PLAN_JSON="${RUNTIME_DIR}/prune-runtime-artifacts-latest.json"

python3 - <<'PY' \
  "${ROOT_DIR}" "${RUNTIME_DIR}" "${INDEX_JSON}" "${PLAN_JSON}" "${LATEST_PLAN_JSON}" \
  "${MODE}" "${KEEP_PASSED_PER_LEVEL}" "${KEEP_FAILED_DAYS}"
import json
import pathlib
import re
import shutil
import sys
from datetime import datetime, timedelta

repo_root = pathlib.Path(sys.argv[1]).resolve()
runtime_dir = pathlib.Path(sys.argv[2]).resolve()
index_json = pathlib.Path(sys.argv[3]).resolve()
plan_json = pathlib.Path(sys.argv[4]).resolve()
latest_plan_json = pathlib.Path(sys.argv[5]).resolve()
mode = sys.argv[6]
keep_passed_per_level = int(sys.argv[7])
keep_failed_days = int(sys.argv[8])

payload = json.loads(index_json.read_text(encoding="utf-8", errors="replace"))
runs = payload.get("runs") or []


def to_dt(value):
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except Exception:
        return None


def referenced_run_ids():
    pattern = re.compile(r"\btest-host-[A-Za-z0-9-]+\b")
    run_ids = set()
    task_dir = repo_root / "task"
    if not task_dir.exists():
        return run_ids
    for path in task_dir.rglob("*.md"):
        try:
            text = path.read_text(encoding="utf-8", errors="replace")
        except Exception:
            continue
        for match in pattern.findall(text):
            run_ids.add(match)
    return run_ids


protected_refs = referenced_run_ids()
keep_ids = set(protected_refs)
reason_map = {run_id: ["referenced_in_task_docs"] for run_id in protected_refs}

for level in ("smoke", "business"):
    passed = [r for r in runs if r.get("status") == "passed" and r.get("level") == level]
    passed.sort(key=lambda r: to_dt(r.get("finished_at") or r.get("started_at")) or datetime.min, reverse=True)
    for row in passed[:keep_passed_per_level]:
        run_id = row.get("run_id")
        if not run_id:
            continue
        keep_ids.add(run_id)
        reason_map.setdefault(run_id, []).append(f"keep_latest_passed_{level}")

cutoff = datetime.now().astimezone() - timedelta(days=keep_failed_days)
for row in runs:
    run_id = row.get("run_id")
    if not run_id:
        continue
    status = row.get("status")
    if status == "passed":
        continue
    finished = to_dt(row.get("finished_at") or row.get("started_at"))
    if finished is None:
        keep_ids.add(run_id)
        reason_map.setdefault(run_id, []).append("missing_timestamp_keep")
        continue
    if finished >= cutoff:
        keep_ids.add(run_id)
        reason_map.setdefault(run_id, []).append("recent_failed_keep")

all_ids = [row.get("run_id") for row in runs if row.get("run_id")]
prune_candidates = []
removed_paths = []

for run_id in all_ids:
    if run_id in keep_ids:
        continue

    candidate_paths = [
        runtime_dir / "remote-artifacts" / run_id,
        runtime_dir / "remote-pipeline-runs" / run_id,
        runtime_dir / "test-host-runs" / run_id,
    ]
    existing_paths = [p for p in candidate_paths if p.exists()]
    if not existing_paths:
        continue

    prune_candidates.append(
        {
            "run_id": run_id,
            "paths": [str(p) for p in existing_paths],
            "keep_reasons": reason_map.get(run_id, []),
        }
    )

    if mode == "apply":
        for path in existing_paths:
            shutil.rmtree(path, ignore_errors=True)
            removed_paths.append(str(path))

report = {
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "mode": mode,
    "policy": {
        "keep_passed_per_level": keep_passed_per_level,
        "keep_failed_days": keep_failed_days,
        "protected_referenced_run_ids": len(protected_refs),
    },
    "summary": {
        "indexed_runs": len(all_ids),
        "keep_runs": len(keep_ids),
        "prune_runs": len(prune_candidates),
        "removed_paths": len(removed_paths),
    },
    "keep_run_ids": sorted(keep_ids),
    "prune_candidates": prune_candidates,
    "removed_paths": removed_paths,
}

body = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
plan_json.write_text(body, encoding="utf-8")
latest_plan_json.write_text(body, encoding="utf-8")

print(f"[prune-runtime] mode={mode}")
print(f"[prune-runtime] indexed_runs={len(all_ids)}")
print(f"[prune-runtime] prune_runs={len(prune_candidates)}")
print(f"[prune-runtime] removed_paths={len(removed_paths)}")
print(f"[prune-runtime] report={plan_json}")
print(f"[prune-runtime] latest={latest_plan_json}")
PY
