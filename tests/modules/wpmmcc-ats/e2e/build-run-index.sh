#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${RUNTIME_DIR}"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_JSON="${RUNTIME_DIR}/run-index-${TIMESTAMP}.json"
LATEST_JSON="${RUNTIME_DIR}/run-index-latest.json"

python3 - <<'PY' "${RUNTIME_DIR}" "${REPORT_JSON}" "${LATEST_JSON}"
import json
import pathlib
import sys
from collections import Counter
from datetime import datetime

runtime_dir = pathlib.Path(sys.argv[1]).resolve()
report_path = pathlib.Path(sys.argv[2]).resolve()
latest_path = pathlib.Path(sys.argv[3]).resolve()


def read_json(path):
    try:
        return json.loads(path.read_text(encoding="utf-8", errors="replace"))
    except Exception:
        return None


def pick_primary(sources):
    for key in ("remote_artifacts", "remote_pipeline", "test_host_runs"):
        payload = sources.get(key)
        if payload:
            return key, payload
    return None, {}


def to_dt(value):
    if not value:
        return datetime.min
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except Exception:
        return datetime.min


runs = {}
source_roots = {
    "remote_artifacts": runtime_dir / "remote-artifacts",
    "remote_pipeline": runtime_dir / "remote-pipeline-runs",
    "test_host_runs": runtime_dir / "test-host-runs",
}

for source_name, root in source_roots.items():
    if not root.exists():
        continue
    for run_dir in sorted(root.iterdir()):
        if not run_dir.is_dir():
            continue
        manifest_path = run_dir / "manifest.json"
        if not manifest_path.exists():
            continue
        payload = read_json(manifest_path)
        if not isinstance(payload, dict):
            continue
        run_id = str(payload.get("run_id") or run_dir.name)
        entry = runs.setdefault(
            run_id,
            {
                "run_id": run_id,
                "sources": {},
                "artifact_path": str((runtime_dir / "remote-artifacts" / run_id)),
                "remote_pipeline_path": str((runtime_dir / "remote-pipeline-runs" / run_id)),
                "test_host_run_path": str((runtime_dir / "test-host-runs" / run_id)),
            },
        )
        entry["sources"][source_name] = {
            "manifest_path": str(manifest_path),
            "payload": payload,
        }

indexed = []
for run_id, row in runs.items():
    source_name, primary_payload = pick_primary({k: v.get("payload") for k, v in row["sources"].items()})
    if not primary_payload:
        continue

    local_payload = (row["sources"].get("remote_pipeline") or {}).get("payload") or {}
    repo_block = primary_payload.get("repo") or {}
    local_block = local_payload.get("local") or {}
    git_revision = repo_block.get("git_revision") or local_block.get("git_revision")
    started_at = primary_payload.get("started_at") or local_payload.get("started_at")
    finished_at = primary_payload.get("finished_at") or local_payload.get("finished_at")
    level = primary_payload.get("level") or local_payload.get("level")
    status = primary_payload.get("status") or local_payload.get("status")
    failure_category = primary_payload.get("failure_category") or local_payload.get("failure_category")
    validation_profile = primary_payload.get("validation_profile") or local_payload.get("validation_profile")
    validation_duration_ms = primary_payload.get("validation_duration_ms")
    retry_block = primary_payload.get("retry") or {}

    primary_manifest_path = (row["sources"].get(source_name) or {}).get("manifest_path")
    artifact_path = pathlib.Path(row["artifact_path"])

    indexed.append(
        {
            "run_id": run_id,
            "level": level,
            "status": status,
            "started_at": started_at,
            "finished_at": finished_at,
            "git_revision": git_revision,
            "artifact_path": str(artifact_path) if artifact_path.exists() else None,
            "manifest_path": primary_manifest_path,
            "failure_category": failure_category,
            "validation_profile": validation_profile,
            "validation_duration_ms": validation_duration_ms,
            "retry_happened": bool(retry_block.get("happened")),
            "retry_final_pass_depends_on_retry": bool(retry_block.get("final_pass_depends_on_retry")),
            "sources": {
                key: value.get("manifest_path") for key, value in row["sources"].items()
            },
        }
    )

indexed.sort(key=lambda item: to_dt(item.get("started_at")), reverse=True)

status_counter = Counter((item.get("status") or "unknown") for item in indexed)
level_counter = Counter((item.get("level") or "unknown") for item in indexed)


def eligible_for_latest_passed(item, payloads_by_run):
    """A run may only represent 'latest passed' when it is real, clean evidence.

    P0-TF-01: dry-run reports, bypassed runs, and reports without explicit
    dry_run=false semantics must never enter latest-passed.
    """
    if item.get("status") != "passed":
        return False
    payload = payloads_by_run.get(item.get("run_id")) or {}
    # Dry-run semantics must be explicit: missing dry_run field = not eligible.
    if payload.get("dry_run") is not False:
        return False
    for key in ("bypasses", "bypass", "bypass_flags"):
        if payload.get(key):
            return False
    return True


payloads_by_run = {}
for run_id, row in runs.items():
    for source in row["sources"].values():
        payload = source.get("payload")
        if isinstance(payload, dict) and run_id not in payloads_by_run:
            payloads_by_run[run_id] = payload

latest_passed = {}
for level in ("smoke", "business", "e2e", "release"):
    run = next(
        (
            i
            for i in indexed
            if i.get("level") == level and eligible_for_latest_passed(i, payloads_by_run)
        ),
        None,
    )
    latest_passed[level] = run.get("run_id") if run else None

payload = {
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "summary": {
        "total_runs": len(indexed),
        "status_counts": dict(status_counter),
        "level_counts": dict(level_counter),
        "latest_passed_by_level": latest_passed,
    },
    "runs": indexed,
}

body = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
report_path.write_text(body, encoding="utf-8")
latest_path.write_text(body, encoding="utf-8")

print(f"[run-index] total_runs={len(indexed)}")
print(f"[run-index] report={report_path}")
print(f"[run-index] latest={latest_path}")
print(f"[run-index] latest_passed_smoke={latest_passed.get('smoke')}")
print(f"[run-index] latest_passed_business={latest_passed.get('business')}")
PY
