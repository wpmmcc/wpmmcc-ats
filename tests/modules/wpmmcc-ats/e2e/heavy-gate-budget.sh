#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
REPORTS_DIR="${ROOT_DIR}/tests/reports/e2e/wpmmcc-ats"

LANE=""
PHASE="pre"
RUN_ID="${WPTSALL_RUN_ID:-heavy-gate-$(date +%Y%m%d-%H%M%S)}"
OUTPUT_JSON=""
BEFORE_JSON=""
DRY_RUN=0
WARN_ONLY=0
REQUIRE_SNAPSHOT=0
WP_ROOT="${WPTSALL_WP_ROOT:-/var/www/wordpress}"

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/heavy-gate-budget.sh --lane <lane> --phase <pre|post> [options]

Lanes:
  language-pack-full
  project-matrix-full
  journey-three-system
  release-full

Options:
  --run-id ID              Include run id in report
  --output PATH            Write report to PATH
  --before PATH            Pre-phase report to compare during post phase
  --dry-run                Mark this as budget-only/dry-run
  --warn-only              Do not fail when snapshot/audit is missing
  --require-snapshot       Require a non-dry-run snapshot before heavy execution
  --wp-root PATH           WordPress root for DB audit
  -h, --help               Show help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --lane)
      LANE="${2:-}"
      shift 2
      ;;
    --phase)
      PHASE="${2:-}"
      shift 2
      ;;
    --run-id)
      RUN_ID="${2:-}"
      shift 2
      ;;
    --output)
      OUTPUT_JSON="${2:-}"
      shift 2
      ;;
    --before)
      BEFORE_JSON="${2:-}"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --warn-only)
      WARN_ONLY=1
      shift
      ;;
    --require-snapshot)
      REQUIRE_SNAPSHOT=1
      shift
      ;;
    --wp-root)
      WP_ROOT="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[heavy-gate-budget] unknown arg: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

case "${LANE}" in
  language-pack-full|project-matrix-full|journey-three-system|release-full)
    ;;
  *)
    echo "[heavy-gate-budget] unsupported --lane: ${LANE}" >&2
    usage >&2
    exit 1
    ;;
esac

case "${PHASE}" in
  pre|post)
    ;;
  *)
    echo "[heavy-gate-budget] unsupported --phase: ${PHASE}" >&2
    usage >&2
    exit 1
    ;;
esac

mkdir -p "${RUNTIME_DIR}" "${REPORTS_DIR}"
if [[ -z "${OUTPUT_JSON}" ]]; then
  OUTPUT_JSON="${RUNTIME_DIR}/heavy-gate-budget-${LANE}-${PHASE}-$(date +%Y%m%d-%H%M%S).json"
fi
mkdir -p "$(dirname "${OUTPUT_JSON}")"

artifact_bytes() {
  python3 - <<'PY' "${RUNTIME_DIR}" "${REPORTS_DIR}"
import pathlib
import sys

total = 0
for root in (pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])):
    if not root.exists():
        continue
    for path in root.rglob("*"):
        if path.is_file():
            try:
                total += path.stat().st_size
            except OSError:
                pass
print(total)
PY
}

run_db_audit() {
  local audit_output audit_rc
  set +e
  audit_output="$(bash "${ROOT_DIR}/tests/infra/test-host/audit-wptsall-db-data.sh" --wp-root "${WP_ROOT}" --runtime-dir "${RUNTIME_DIR}" 2>&1)"
  audit_rc=$?
  set -e
  if [[ ${audit_rc} -eq 0 && -f "${RUNTIME_DIR}/wptsall-db-audit-latest.json" ]]; then
    printf '%s' "${RUNTIME_DIR}/wptsall-db-audit-latest.json"
    return 0
  fi
  printf '%s' "${audit_output}" > "${OUTPUT_JSON}.db-audit-error.log"
  return 1
}

DB_AUDIT_JSON=""
DB_AUDIT_STATUS="skipped"
if DB_AUDIT_JSON="$(run_db_audit)"; then
  DB_AUDIT_STATUS="passed"
else
  DB_AUDIT_JSON=""
  DB_AUDIT_STATUS="failed"
fi

SNAPSHOT_JSON="${RUNTIME_DIR}/test-host-snapshot-latest.json"
ARTIFACT_BYTES="$(artifact_bytes)"

python3 - <<'PY' \
  "${OUTPUT_JSON}" "${LANE}" "${PHASE}" "${RUN_ID}" "${DRY_RUN}" "${WARN_ONLY}" "${REQUIRE_SNAPSHOT}" \
  "${SNAPSHOT_JSON}" "${DB_AUDIT_JSON}" "${DB_AUDIT_STATUS}" "${ARTIFACT_BYTES}" "${BEFORE_JSON}" "${ROOT_DIR}"
import json
import pathlib
import sys
from datetime import datetime

(
    output_json,
    lane,
    phase,
    run_id,
    dry_run,
    warn_only,
    require_snapshot,
    snapshot_json,
    db_audit_json,
    db_audit_status,
    artifact_bytes,
    before_json,
    root_dir,
) = sys.argv[1:]

dry_run = dry_run == "1"
warn_only = warn_only == "1"
require_snapshot = require_snapshot == "1"
artifact_bytes = int(artifact_bytes or 0)
root = pathlib.Path(root_dir)

LANE_BUDGETS = {
    "language-pack-full": {
        "estimated_duration_minutes": "20-45",
        "estimated_new_relations": 0,
        "estimated_new_tasks": 0,
        "estimated_new_translation_results": 0,
        "estimated_new_mappings": 0,
        "estimated_new_language_pack_entries": "plugin/theme scan dependent; can be thousands",
        "estimated_artifact_mb": 50,
        "snapshot_required": True,
        "maintenance_window_required": True,
        "allowed_cadence": "maintenance_window_only",
    },
    "project-matrix-full": {
        "estimated_duration_minutes": "60-120",
        "estimated_new_relations": "15 projects x relation fixtures",
        "estimated_new_tasks": "hundreds to thousands",
        "estimated_new_translation_results": "hundreds to thousands",
        "estimated_new_mappings": "hundreds to thousands",
        "estimated_new_language_pack_entries": "project dependent",
        "estimated_artifact_mb": 300,
        "snapshot_required": True,
        "maintenance_window_required": True,
        "allowed_cadence": "release_or_maintenance_window",
    },
    "journey-three-system": {
        "estimated_duration_minutes": "15-45",
        "estimated_new_relations": "low",
        "estimated_new_tasks": "low to medium",
        "estimated_new_translation_results": "low to medium",
        "estimated_new_mappings": "low to medium",
        "estimated_new_language_pack_entries": 0,
        "estimated_artifact_mb": 150,
        "snapshot_required": True,
        "maintenance_window_required": False,
        "allowed_cadence": "release_or_targeted_user_flow_change",
    },
    "release-full": {
        "estimated_duration_minutes": "90-180",
        "estimated_new_relations": "full release gate aggregate",
        "estimated_new_tasks": "full release gate aggregate",
        "estimated_new_translation_results": "full release gate aggregate",
        "estimated_new_mappings": "full release gate aggregate",
        "estimated_new_language_pack_entries": "if language pack lanes are included",
        "estimated_artifact_mb": 600,
        "snapshot_required": True,
        "maintenance_window_required": True,
        "allowed_cadence": "release_only",
    },
}

def load_json(path):
    p = pathlib.Path(path)
    if not path or not p.exists() or p.stat().st_size == 0:
        return None
    try:
        return json.loads(p.read_text(encoding="utf-8", errors="replace"))
    except Exception as exc:
        return {"parse_error": str(exc)}

def metric_map(audit_payload):
    metrics = {}
    if not isinstance(audit_payload, dict):
        return metrics
    for row in audit_payload.get("rows") or []:
        if row.get("section") != "candidate_counts":
            continue
        key = row.get("key")
        value = row.get("value")
        if not key:
            continue
        try:
            metrics[key] = int(value)
        except Exception:
            metrics[key] = value
    for row in audit_payload.get("rows") or []:
        if row.get("section") != "table_stats":
            continue
        details = row.get("details") or ""
        try:
            rows = int(row.get("value") or 0)
        except Exception:
            rows = 0
        metrics[f"table_rows.{row.get('key')}"] = rows
        if details.startswith("mb="):
            try:
                metrics[f"table_mb.{row.get('key')}"] = float(details[3:])
            except Exception:
                pass
    return metrics

snapshot = load_json(snapshot_json)
snapshot_ok = False
snapshot_message = "missing snapshot report"
if isinstance(snapshot, dict):
    snapshot_ok = snapshot.get("status") == "ok" and not snapshot.get("dry_run")
    snapshot_message = f"status={snapshot.get('status')}; dry_run={snapshot.get('dry_run')}; snapshot_id={snapshot.get('snapshot_id')}"

db_audit = load_json(db_audit_json) if db_audit_json else None
metrics = metric_map(db_audit)

before_payload = load_json(before_json)
before_metrics = {}
before_artifacts = None
if isinstance(before_payload, dict):
    before_metrics = before_payload.get("db_metrics") or {}
    before_artifacts = before_payload.get("artifact_bytes")

delta = {}
for key, value in metrics.items():
    old = before_metrics.get(key)
    if isinstance(value, (int, float)) and isinstance(old, (int, float)):
        delta[key] = value - old
if isinstance(before_artifacts, int):
    delta["artifact_bytes"] = artifact_bytes - before_artifacts

budget = LANE_BUDGETS[lane]
warnings = []
blocking = []
if budget["snapshot_required"] and not snapshot_ok:
    msg = f"snapshot not ready: {snapshot_message}"
    if require_snapshot and not warn_only and not dry_run:
        blocking.append(msg)
    else:
        warnings.append(msg)

if db_audit_status != "passed":
    msg = "db audit unavailable; data delta report will be incomplete"
    if phase == "post" and not warn_only:
        warnings.append(msg)
    else:
        warnings.append(msg)

if phase == "post" and not before_metrics:
    warnings.append("post phase has no --before metrics; delta report is partial")

status = "failed" if blocking else ("warning" if warnings else "passed")
if dry_run and status == "failed":
    status = "warning"

test_id_by_lane = {
    "language-pack-full": "TEST-WPTSALL-LANGUAGE-PACK-FULL-BUDGET-001",
    "project-matrix-full": "TEST-PROJECT-MATRIX-FULL-001",
    "journey-three-system": "TEST-JOURNEY-THREE-SYSTEM-001",
    "release-full": "TEST-RELEASE-FULL-001",
}

payload = {
    "status": status,
    "test_id": test_id_by_lane[lane],
    "requirement_id": "REQ-TEST-GOVERNANCE-HEAVY-GATE-001",
    "user_journey_id": ["UJ15"],
    "scenario_id": f"SCN-HEAVY-GATE-BUDGET-{lane.upper().replace('-', '_')}",
    "run_id": run_id,
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "lane": lane,
    "phase": phase,
    "dry_run": dry_run,
    "budget": budget,
    "cadence": {
        "daily": "core-only and product smoke only",
        "release": "release gate business/full by release risk",
        "maintenance_window": "full language pack, full matrix, full journeys",
    },
    "snapshot": {
        "required": budget["snapshot_required"],
        "require_enforced": require_snapshot,
        "ok": snapshot_ok,
        "report": str(pathlib.Path(snapshot_json)) if pathlib.Path(snapshot_json).exists() else None,
        "message": snapshot_message,
    },
    "db_audit": {
        "status": db_audit_status,
        "report": db_audit_json or None,
    },
    "db_metrics": metrics,
    "delta": delta,
    "artifact_bytes": artifact_bytes,
    "warnings": warnings,
    "blocking": blocking,
    "next_action": "create DB/runtime snapshot before executing this heavy lane" if blocking else "continue only if this lane matches cadence and budget",
}

path = pathlib.Path(output_json)
path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

# Keep a stable "latest" copy next to timed reports (not under retired dev-tool/).
latest = pathlib.Path(output_json).parent / f"heavy-gate-budget-{lane}-{phase}-latest.json"
latest.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

print(f"[heavy-gate-budget] status={status}")
print(f"[heavy-gate-budget] lane={lane}")
print(f"[heavy-gate-budget] phase={phase}")
print(f"[heavy-gate-budget] report={path}")
if warnings:
    print(f"[heavy-gate-budget] warnings={len(warnings)}")
if blocking:
    print(f"[heavy-gate-budget] blocking={len(blocking)}")

if blocking:
    raise SystemExit(2)
PY
