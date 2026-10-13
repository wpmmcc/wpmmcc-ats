#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
RUN_INDEX_FILE="${RUNTIME_DIR}/run-index-latest.json"
CATALOG_FILE="${ROOT_DIR}/tests/infra/test-catalog.json"

mkdir -p "${RUNTIME_DIR}"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_JSON="${RUNTIME_DIR}/gate-cadence-${TIMESTAMP}.json"
LATEST_JSON="${RUNTIME_DIR}/gate-cadence-latest.json"

python3 - <<'PY' "${RUN_INDEX_FILE}" "${REPORT_JSON}" "${LATEST_JSON}" "${CATALOG_FILE}" "${RUNTIME_DIR}"
import json
import pathlib
import sys
from datetime import datetime, timedelta

run_index_file = pathlib.Path(sys.argv[1]).resolve()
report_file = pathlib.Path(sys.argv[2]).resolve()
latest_file = pathlib.Path(sys.argv[3]).resolve()
catalog_file = pathlib.Path(sys.argv[4]).resolve()
runtime_dir = pathlib.Path(sys.argv[5]).resolve()


def parse_dt(value):
    if not value:
        return None
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except Exception:
        return None


def read_json(path):
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8", errors="replace"))
    except Exception:
        return None


payload = read_json(run_index_file) or {}
runs = [r for r in (payload.get("runs") or []) if isinstance(r, dict)]
now = datetime.now().astimezone()
catalog = read_json(catalog_file) or {}

for row in runs:
    row["_dt"] = parse_dt(row.get("started_at"))

runs.sort(key=lambda r: (r["_dt"] is not None, r["_dt"]), reverse=True)
passed = [r for r in runs if (r.get("status") or "").lower() == "passed"]


def latest_passed(level):
    for row in passed:
        if (row.get("level") or "").lower() == level:
            return row
    return None


def count_level(rows, level):
    return len([r for r in rows if (r.get("level") or "").lower() == level])


def within_days(rows, days):
    start = now - timedelta(days=days)
    out = []
    for r in rows:
        dt = r.get("_dt")
        if dt is None:
            continue
        if dt >= start:
            out.append(r)
    return out


passed_7d = within_days(passed, 7)
all_7d = within_days(runs, 7)
passed_24h = within_days(passed, 1)

latest_smoke = latest_passed("smoke")
latest_business = latest_passed("business")
latest_e2e = latest_passed("e2e")
latest_release = latest_passed("release")

total_business_7d = count_level(all_7d, "business")
passed_business_7d = count_level(passed_7d, "business")
if total_business_7d == 0:
    business_pass_rate_7d = None
else:
    business_pass_rate_7d = round((passed_business_7d / total_business_7d) * 100.0, 2)

smoke_to_business_delay_minutes = None
smoke_run_for_delay = None
business_run_for_delay = None
if latest_smoke and latest_smoke.get("_dt"):
    smoke_dt = latest_smoke["_dt"]
    smoke_rev = latest_smoke.get("git_revision")
    candidate = None
    for r in passed:
        if (r.get("level") or "").lower() != "business" or not r.get("_dt"):
            continue
        if smoke_rev and r.get("git_revision") != smoke_rev:
            continue
        if r["_dt"] < smoke_dt:
            continue
        candidate = r
        break
    if candidate is None and latest_business and latest_business.get("_dt"):
        candidate = latest_business
    if candidate is not None and candidate.get("_dt") is not None:
        smoke_run_for_delay = latest_smoke.get("run_id")
        business_run_for_delay = candidate.get("run_id")
        delta = candidate["_dt"] - smoke_dt
        smoke_to_business_delay_minutes = round(delta.total_seconds() / 60.0, 2)
        if smoke_to_business_delay_minutes < 0:
            smoke_to_business_delay_minutes = abs(smoke_to_business_delay_minutes)

slo = {
    "targets": {
        "daily_business_min_pass": 1,
        "weekly_e2e_min_pass": 1,
        "weekly_release_min_pass": 1,
        "smoke_to_business_delay_minutes_max": 1440,
    },
    "actual": {
        "business_pass_24h": count_level(passed_24h, "business"),
        "e2e_pass_7d": count_level(passed_7d, "e2e"),
        "release_pass_7d": count_level(passed_7d, "release"),
        "smoke_to_business_delay_minutes": smoke_to_business_delay_minutes,
    },
    "checks": {},
}

slo["checks"]["daily_business_ok"] = slo["actual"]["business_pass_24h"] >= slo["targets"]["daily_business_min_pass"]
slo["checks"]["weekly_e2e_ok"] = slo["actual"]["e2e_pass_7d"] >= slo["targets"]["weekly_e2e_min_pass"]
slo["checks"]["weekly_release_ok"] = slo["actual"]["release_pass_7d"] >= slo["targets"]["weekly_release_min_pass"]
slo["checks"]["delay_ok"] = (
    smoke_to_business_delay_minutes is not None
    and smoke_to_business_delay_minutes <= slo["targets"]["smoke_to_business_delay_minutes_max"]
)

failed_checks = [k for k, v in slo["checks"].items() if not v]

catalog_checks = {
    "high_cost_data_budget_ok": True,
    "release_gate_uj15_ok": True,
    "high_cost_explicit_args_ok": True,
}
catalog_failures = []
high_cost_tests = []
for item in catalog.get("tests") or []:
    if not isinstance(item, dict) or item.get("category") != "canonical":
        continue
    test_id = item.get("test_id")
    command = item.get("command") or ""
    if item.get("high_cost") is True:
        high_cost_tests.append(test_id)
        if item.get("data_budget_required") is not True:
            catalog_checks["high_cost_data_budget_ok"] = False
            catalog_failures.append(f"{test_id}: missing data_budget_required=true")
        if not any(token in command for token in ["--scope full", "--level full", " full ", "--with-journeys"]):
            catalog_checks["high_cost_explicit_args_ok"] = False
            catalog_failures.append(f"{test_id}: missing explicit full/high-cost args")
    if item.get("level") == "L5_RELEASE_GATE" and "UJ15" not in (item.get("user_journey") or []):
        catalog_checks["release_gate_uj15_ok"] = False
        catalog_failures.append(f"{test_id}: release gate missing UJ15")

budget_latest = {}
for lane in ("language-pack-full", "project-matrix-full", "journey-three-system", "release-full"):
    path = runtime_dir / f"heavy-gate-budget-{lane}-pre-latest.json"
    payload_latest = read_json(path)
    budget_latest[lane] = {
        "path": str(path) if path.exists() else None,
        "status": payload_latest.get("status") if isinstance(payload_latest, dict) else None,
        "run_id": payload_latest.get("run_id") if isinstance(payload_latest, dict) else None,
    }

failed_checks.extend([k for k, v in catalog_checks.items() if not v])
status = "passed" if len(failed_checks) == 0 else "warning"

out = {
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "status": status,
    "sources": {
        "run_index_file": str(run_index_file),
    },
    "metrics": {
        "last_passed_e2e_run_id": latest_e2e.get("run_id") if latest_e2e else None,
        "last_passed_release_run_id": latest_release.get("run_id") if latest_release else None,
        "business_pass_rate_7d": business_pass_rate_7d,
        "smoke_to_business_delay_minutes": smoke_to_business_delay_minutes,
        "smoke_run_for_delay": smoke_run_for_delay,
        "business_run_for_delay": business_run_for_delay,
    },
    "slo": slo,
    "catalog_policy": {
        "path": str(catalog_file),
        "checks": catalog_checks,
        "high_cost_tests": high_cost_tests,
        "failures": catalog_failures,
    },
    "heavy_gate_budget_latest": budget_latest,
    "summary": {
        "total_runs": len(runs),
        "passed_runs": len(passed),
        "failed_checks": failed_checks,
        "next_action": "Schedule/trigger missing heavy gates and rerun cadence audit" if failed_checks else "Cadence meets targets",
    },
}

body = json.dumps(out, ensure_ascii=False, indent=2) + "\n"
report_file.write_text(body, encoding="utf-8")
latest_file.write_text(body, encoding="utf-8")

print(f"[gate-cadence] status={status}")
print(f"[gate-cadence] report={report_file}")
print(f"[gate-cadence] latest={latest_file}")
print(f"[gate-cadence] last_passed_e2e_run_id={out['metrics']['last_passed_e2e_run_id']}")
print(f"[gate-cadence] last_passed_release_run_id={out['metrics']['last_passed_release_run_id']}")
PY
