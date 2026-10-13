#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
REQUIREMENTS_DIR="${ROOT_DIR}/docs/current/requirements"
REQUIREMENTS_MAP_FILE="${ROOT_DIR}/tests/infra/validation-requirements-map.json"
RUN_INDEX_FILE="${RUNTIME_DIR}/run-index-latest.json"

mkdir -p "${RUNTIME_DIR}"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_JSON="${RUNTIME_DIR}/requirement-coverage-${TIMESTAMP}.json"
LATEST_JSON="${RUNTIME_DIR}/requirement-coverage-latest.json"

python3 - <<'PY' "${REQUIREMENTS_DIR}" "${REQUIREMENTS_MAP_FILE}" "${RUN_INDEX_FILE}" "${REPORT_JSON}" "${LATEST_JSON}"
import json
import pathlib
import re
import sys
from collections import Counter
from datetime import datetime

requirements_dir = pathlib.Path(sys.argv[1]).resolve()
requirements_map_file = pathlib.Path(sys.argv[2]).resolve()
run_index_file = pathlib.Path(sys.argv[3]).resolve()
report_path = pathlib.Path(sys.argv[4]).resolve()
latest_path = pathlib.Path(sys.argv[5]).resolve()

remote_layers = {"smoke", "business", "e2e", "release"}


def read_json(path: pathlib.Path):
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8", errors="replace"))
    except Exception:
        return None


def parse_requirements_registry(root: pathlib.Path):
    items = {}
    if not root.exists():
        return items

    files = sorted(root.glob("*.md"))
    for file in files:
        lines = file.read_text(encoding="utf-8", errors="replace").splitlines()
        current_id = None
        for line in lines:
            header = re.match(r"^##\s+(REQ-[A-Z0-9-]+)\s*$", line.strip())
            if header:
                current_id = header.group(1)
                items.setdefault(
                    current_id,
                    {
                        "requirement_id": current_id,
                        "scope": None,
                        "risk_level": None,
                        "test_layers": [],
                        "source_file": str(file),
                    },
                )
                continue

            if not current_id:
                continue

            scope_match = re.match(r"^- `scope`:\s*`([^`]+)`\s*$", line.strip())
            if scope_match:
                items[current_id]["scope"] = scope_match.group(1).strip()
                continue

            risk_match = re.match(r"^- `risk_level`:\s*`([^`]+)`\s*$", line.strip())
            if risk_match:
                items[current_id]["risk_level"] = risk_match.group(1).strip().lower()
                continue

            layer_match = re.match(r"^- `test_layers`:\s*`([^`]+)`\s*$", line.strip())
            if layer_match:
                raw = layer_match.group(1).strip()
                layers = [x.strip().lower() for x in raw.split(",") if x.strip()]
                items[current_id]["test_layers"] = list(dict.fromkeys(layers))
                continue
    return items


def parse_dt(value):
    if not value:
        return datetime.min
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except Exception:
        return datetime.min


def command_to_layers(command: str):
    c = (command or "").strip().lower()
    if not c:
        return set()

    layers = set()

    if "run-unit-tests" in c or "test:unit" in c or "tests/unit" in c:
        layers.add("unit")
    if "cargo test" in c and "--manifest-path" in c:
        layers.add("unit")
    if "tests/integration" in c or "integration/run.php" in c or "--suite=main" in c:
        layers.add("integration")

    if "--level smoke" in c or "smoke-fast" in c or "smoke-full" in c or "--mode health" in c:
        layers.add("smoke")
    if "--level business" in c or "--mode business" in c:
        layers.add("business")
    if "--level e2e" in c or "run.sh --with-journeys" in c or "journey" in c:
        layers.add("e2e")

    if "--level release" in c:
        layers.add("release")
    elif "run-release-gate.sh" in c and "--level business" not in c:
        layers.add("release")

    return layers


registry = parse_requirements_registry(requirements_dir)
requirements_map = read_json(requirements_map_file) or {}
run_index = read_json(run_index_file) or {}

map_entries = {}
for entry in requirements_map.get("requirements", []):
    req_id = str(entry.get("requirement_id") or "").strip()
    if req_id:
        map_entries[req_id] = entry

runs = [r for r in (run_index.get("runs") or []) if isinstance(r, dict)]
runs.sort(key=lambda row: parse_dt(row.get("started_at")), reverse=True)


def latest_passed(levels):
    if not levels:
        return None
    level_set = set(levels)
    for row in runs:
        if (row.get("status") or "").lower() != "passed":
            continue
        if (row.get("level") or "").lower() in level_set:
            return row.get("run_id")
    return None


all_requirement_ids = sorted(set(registry.keys()) | set(map_entries.keys()))
results = []

for req_id in all_requirement_ids:
    reg = registry.get(req_id) or {}
    m = map_entries.get(req_id) or {}

    expected_layers = list(dict.fromkeys([x for x in reg.get("test_layers", []) if x]))
    mapped_tests = []
    for key in ("required_checks", "optional_checks", "remote_commands"):
        for cmd in m.get(key, []) or []:
            if cmd and cmd not in mapped_tests:
                mapped_tests.append(cmd)

    mapped_layers = set()
    for cmd in mapped_tests:
        mapped_layers.update(command_to_layers(cmd))

    if expected_layers:
        covered_layers = [x for x in expected_layers if x in mapped_layers]
        missing_layers = [x for x in expected_layers if x not in mapped_layers]
    else:
        covered_layers = sorted(mapped_layers)
        missing_layers = []

    remote_lookup_layers = [x for x in expected_layers if x in remote_layers]
    if not remote_lookup_layers:
        remote_lookup_layers = [x for x in mapped_layers if x in remote_layers]
    last_passed_run_id = latest_passed(remote_lookup_layers)

    status = "covered"
    if not mapped_tests:
        status = "missing"
    elif expected_layers:
        if not covered_layers:
            status = "missing"
        elif missing_layers:
            status = "partial"
    elif not mapped_layers:
        status = "missing"

    if status == "covered" and remote_lookup_layers and not last_passed_run_id:
        status = "partial"

    next_action = "none"
    if status == "missing":
        next_action = "Add requirement mapping in tests/infra/validation-requirements-map.json and bind required checks"
    elif status == "partial":
        if missing_layers:
            next_action = f"Add checks to cover missing layers: {', '.join(missing_layers)}"
        elif remote_lookup_layers and not last_passed_run_id:
            next_action = f"Run remote gate for layers: {', '.join(remote_lookup_layers)} and capture passed run_id"
        else:
            next_action = "Review requirement mapping and run evidence freshness"

    results.append(
        {
            "requirement_id": req_id,
            "scope": reg.get("scope"),
            "risk_level": reg.get("risk_level"),
            "expected_layers": expected_layers,
            "mapped_tests": mapped_tests,
            "covered_layers": covered_layers,
            "missing_layers": missing_layers,
            "last_passed_run_id": last_passed_run_id,
            "status": status,
            "next_action": next_action,
            "source_file": reg.get("source_file"),
        }
    )


status_counts = Counter([x["status"] for x in results])
missing_or_partial = [x for x in results if x["status"] in {"missing", "partial"}]

risk_rank = {"high": 0, "medium": 1, "low": 2, None: 3}
missing_or_partial.sort(
    key=lambda item: (
        0 if item["status"] == "missing" else 1,
        risk_rank.get(item.get("risk_level"), 3),
        item["requirement_id"],
    )
)

priority_fixes = []
for item in missing_or_partial[:10]:
    priority_fixes.append(
        {
            "requirement_id": item["requirement_id"],
            "status": item["status"],
            "risk_level": item.get("risk_level"),
            "missing_layers": item.get("missing_layers") or [],
            "next_action": item["next_action"],
        }
    )

overall_status = "covered"
if status_counts.get("missing", 0) > 0:
    overall_status = "missing"
elif status_counts.get("partial", 0) > 0:
    overall_status = "partial"

payload = {
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "status": overall_status,
    "sources": {
        "requirements_registry_dir": str(requirements_dir),
        "requirements_map_file": str(requirements_map_file),
        "run_index_file": str(run_index_file),
    },
    "summary": {
        "total_requirements": len(results),
        "mapped_requirements": sum(1 for x in results if len(x["mapped_tests"]) > 0),
        "unmapped_requirements": sum(1 for x in results if len(x["mapped_tests"]) == 0),
        "status_counts": dict(status_counts),
        "priority_fix_count": len(priority_fixes),
    },
    "priority_fixes": priority_fixes,
    "requirements": results,
}

body = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
report_path.write_text(body, encoding="utf-8")
latest_path.write_text(body, encoding="utf-8")

print(f"[requirement-coverage] status={overall_status}")
print(f"[requirement-coverage] total_requirements={len(results)}")
print(f"[requirement-coverage] mapped_requirements={payload['summary']['mapped_requirements']}")
print(f"[requirement-coverage] report={report_path}")
print(f"[requirement-coverage] latest={latest_path}")
PY
