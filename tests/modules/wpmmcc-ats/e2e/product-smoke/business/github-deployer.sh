#!/usr/bin/env bash

# GitHub Deployer business smoke lane:
# template read -> dry-run plan -> live create (expected fail path) -> instance/log/rollback evidence -> cleanup.

GITHUB_DEPLOYER_BUSINESS_TS=""
GITHUB_DEPLOYER_BUSINESS_DIR=""
GITHUB_DEPLOYER_LAST_HTTP_STATUS=""
GITHUB_DEPLOYER_FAIL_INSTANCE_ID=""

github_deployer_business_init() {
  if [[ -n "${GITHUB_DEPLOYER_BUSINESS_DIR}" ]]; then
    return 0
  fi
  GITHUB_DEPLOYER_BUSINESS_TS="${TIMESTAMP:-$(date +%Y%m%d-%H%M%S)}"
  GITHUB_DEPLOYER_BUSINESS_DIR="${RUNTIME_DIR}/product-smoke-github-deployer/${GITHUB_DEPLOYER_BUSINESS_TS}"
  mkdir -p "${GITHUB_DEPLOYER_BUSINESS_DIR}"
  return 0
}

github_deployer_call_json() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local out_file="$4"
  local err_file="${out_file}.curl.err"
  local url="${GITHUB_DEPLOYER_URL}${path}"
  local status=""
  local errexit_was_on=0
  if [[ "$-" == *e* ]]; then
    errexit_was_on=1
  fi

  set +e
  if [[ -n "${body}" ]]; then
    status="$(curl -sS -o "${out_file}" -w "%{http_code}" -X "${method}" -H "Content-Type: application/json" --data "${body}" "${url}" 2>"${err_file}")"
  else
    status="$(curl -sS -o "${out_file}" -w "%{http_code}" -X "${method}" "${url}" 2>"${err_file}")"
  fi
  local curl_rc=$?
  if [[ ${errexit_was_on} -eq 1 ]]; then
    set -e
  fi

  GITHUB_DEPLOYER_LAST_HTTP_STATUS="${status:-000}"
  if [[ ${curl_rc} -ne 0 ]]; then
    return 2
  fi
  if [[ ! "${GITHUB_DEPLOYER_LAST_HTTP_STATUS}" =~ ^2 ]]; then
    return 1
  fi

  local first_char
  first_char="$(python3 - <<'PY' "${out_file}"
import pathlib
import sys
raw = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace").lstrip()
print(raw[:1] if raw else "")
PY
)"
  if [[ "${first_char}" == "<" ]]; then
    return 3
  fi
  return 0
}

github_deployer_step_failed() {
  local step_id="$1"
  local step_name="$2"
  local category="$3"
  local message="$4"
  local next_action="$5"
  local evidence="$6"
  append_step "${step_id}" "${step_name}" "failed" "${category}" "${message}" "${next_action}" "${evidence}"
}

github_deployer_step_passed() {
  local step_id="$1"
  local step_name="$2"
  local message="$3"
  local evidence="$4"
  append_step "${step_id}" "${step_name}" "passed" "" "${message}" "" "${evidence}"
}

product_smoke_business_seed() {
  github_deployer_business_init

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "github-deployer-business-seed" "GitHub Deployer business seed" "skipped" "" "dry-run" "" "${GITHUB_DEPLOYER_BUSINESS_DIR}"
    return 0
  fi

  local status_json="${GITHUB_DEPLOYER_BUSINESS_DIR}/status.json"
  if ! github_deployer_call_json "GET" "/api/status" "" "${status_json}"; then
    github_deployer_step_failed \
      "github-deployer-business-status" \
      "GitHub Deployer status endpoint" \
      "missing_dependency" \
      "github-deployer status endpoint is unreachable" \
      "Start/restart github-deployer service and verify /api/status" \
      "${status_json}"
    return 1
  fi
  github_deployer_step_passed "github-deployer-business-status" "GitHub Deployer status endpoint" "status endpoint reachable" "${status_json}"

  local templates_json="${GITHUB_DEPLOYER_BUSINESS_DIR}/templates.json"
  if ! github_deployer_call_json "GET" "/api/templates" "" "${templates_json}"; then
    github_deployer_step_failed \
      "github-deployer-business-templates" \
      "GitHub Deployer templates endpoint" \
      "missing_dependency" \
      "templates endpoint is unreachable" \
      "Check /api/templates handler and server connectivity" \
      "${templates_json}"
    return 1
  fi

  local templates_contract_ok
  templates_contract_ok="$(python3 - <<'PY' "${templates_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
print("true" if isinstance(payload, dict) and "templates" in payload else "false")
PY
)"
  if [[ "${templates_contract_ok}" != "true" ]]; then
    github_deployer_step_failed \
      "github-deployer-business-templates" \
      "GitHub Deployer templates endpoint" \
      "contract_mismatch" \
      "templates payload does not contain templates field" \
      "Check /api/templates response contract" \
      "${templates_json}"
    return 1
  fi
  github_deployer_step_passed "github-deployer-business-templates" "GitHub Deployer templates endpoint" "templates contract ok" "${templates_json}"

  github_deployer_step_passed "github-deployer-business-seed" "GitHub Deployer business seed" "business seed completed" "${GITHUB_DEPLOYER_BUSINESS_DIR}"
  return 0
}

product_smoke_business_run() {
  github_deployer_business_init

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "github-deployer-business-flow" "GitHub Deployer business flow" "skipped" "" "dry-run" "" "${GITHUB_DEPLOYER_BUSINESS_DIR}"
    return 0
  fi

  local dry_run_payload
  dry_run_payload="$(python3 - <<'PY' "${GITHUB_DEPLOYER_BUSINESS_TS}"
import json
import sys
print(json.dumps({
    "name": f"smoke-dryrun-{sys.argv[1]}",
    "template_id": "builtin-nginx-proxy",
    "env_vars": {"NGINX_PORT": "18080"},
    "dry_run": True,
}))
PY
)"
  local dry_run_json="${GITHUB_DEPLOYER_BUSINESS_DIR}/create-instance-dry-run.json"
  if ! github_deployer_call_json "POST" "/api/instances" "${dry_run_payload}" "${dry_run_json}"; then
    github_deployer_step_failed \
      "github-deployer-business-create-dry-run" \
      "GitHub Deployer create instance dry-run" \
      "repo_regression" \
      "POST /api/instances dry_run request failed" \
      "Check create_instance handler and template validation path" \
      "${dry_run_json}"
    return 1
  fi

  local dry_run_contract
  dry_run_contract="$(python3 - <<'PY' "${dry_run_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
plan = payload.get("plan") if isinstance(payload.get("plan"), dict) else {}
ok = (
    payload.get("ok") is True
    and payload.get("dry_run") is True
    and isinstance(payload.get("instance_id"), str)
    and payload.get("instance_id")
    and isinstance(plan.get("compose_path"), str)
    and "docker-compose.yml" in plan.get("compose_path", "")
    and plan.get("dry_run") is True
    and isinstance(plan.get("env_count"), int)
)
print("true" if ok else "false")
PY
)"
  if [[ "${dry_run_contract}" != "true" ]]; then
    github_deployer_step_failed \
      "github-deployer-business-create-dry-run" \
      "GitHub Deployer create instance dry-run" \
      "contract_mismatch" \
      "dry_run response missing expected plan fields" \
      "Check /api/instances dry_run response contract" \
      "${dry_run_json}"
    return 1
  fi
  github_deployer_step_passed \
    "github-deployer-business-create-dry-run" \
    "GitHub Deployer create instance dry-run" \
    "dry_run plan contract verified" \
    "${dry_run_json}"

  local fail_payload
  fail_payload="$(python3 - <<'PY' "${GITHUB_DEPLOYER_BUSINESS_TS}"
import json
import sys
print(json.dumps({
    "name": f"smoke-fail-{sys.argv[1]}",
    "template_id": "builtin-nginx-proxy",
    "env_vars": {"NGINX_PORT": "not-a-port"},
    "dry_run": False,
}))
PY
)"
  local fail_json="${GITHUB_DEPLOYER_BUSINESS_DIR}/create-instance-live.json"
  if ! github_deployer_call_json "POST" "/api/instances" "${fail_payload}" "${fail_json}"; then
    github_deployer_step_failed \
      "github-deployer-business-create-live" \
      "GitHub Deployer create instance live path" \
      "repo_regression" \
      "live create request failed unexpectedly" \
      "Check /api/instances live path and docker command integration" \
      "${fail_json}"
    return 1
  fi

  local live_create_parsed
  live_create_parsed="$(python3 - <<'PY' "${fail_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false|")
    raise SystemExit(0)
iid = payload.get("instance_id") or ""
print(f"{'true' if isinstance(iid, str) and iid else 'false'}|{iid}")
PY
)"
  local live_has_id
  IFS='|' read -r live_has_id GITHUB_DEPLOYER_FAIL_INSTANCE_ID <<<"${live_create_parsed}"
  if [[ "${live_has_id}" != "true" ]]; then
    github_deployer_step_failed \
      "github-deployer-business-create-live" \
      "GitHub Deployer create instance live path" \
      "contract_mismatch" \
      "live create response missing instance_id" \
      "Check /api/instances live response contract" \
      "${fail_json}"
    return 1
  fi
  github_deployer_step_passed \
    "github-deployer-business-create-live" \
    "GitHub Deployer create instance live path" \
    "live create returned instance_id for evidence chain" \
    "${fail_json}"

  local list_json="${GITHUB_DEPLOYER_BUSINESS_DIR}/instances-list.json"
  if ! github_deployer_call_json "GET" "/api/instances" "" "${list_json}"; then
    github_deployer_step_failed \
      "github-deployer-business-instance-list" \
      "GitHub Deployer instance list evidence" \
      "repo_regression" \
      "failed to read /api/instances after live create" \
      "Check list_instances route and DB state" \
      "${list_json}"
    return 1
  fi
  local list_contains
  list_contains="$(python3 - <<'PY' "${list_json}" "${GITHUB_DEPLOYER_FAIL_INSTANCE_ID}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
iid = sys.argv[2]
instances = payload.get("instances") if isinstance(payload.get("instances"), list) else []
print("true" if any(isinstance(item, dict) and item.get("id") == iid for item in instances) else "false")
PY
)"
  if [[ "${list_contains}" != "true" ]]; then
    github_deployer_step_failed \
      "github-deployer-business-instance-list" \
      "GitHub Deployer instance list evidence" \
      "repo_regression" \
      "created instance_id not found in /api/instances list" \
      "Check instance insert/list contract and owner_client_id filtering" \
      "${list_json}"
    return 1
  fi
  github_deployer_step_passed \
    "github-deployer-business-instance-list" \
    "GitHub Deployer instance list evidence" \
    "instance list contains created instance_id" \
    "${list_json}"

  local logs_json="${GITHUB_DEPLOYER_BUSINESS_DIR}/instance-logs.json"
  if ! github_deployer_call_json "GET" "/api/instances/${GITHUB_DEPLOYER_FAIL_INSTANCE_ID}/logs?lines=120" "" "${logs_json}"; then
    github_deployer_step_failed \
      "github-deployer-business-instance-logs" \
      "GitHub Deployer instance logs evidence" \
      "repo_regression" \
      "failed to read instance logs endpoint" \
      "Check /api/instances/:id/logs route and instance ownership" \
      "${logs_json}"
    return 1
  fi

  local logs_parsed
  logs_parsed="$(python3 - <<'PY' "${logs_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false|0|0")
    raise SystemExit(0)
instance_logs = payload.get("instance_logs") if isinstance(payload.get("instance_logs"), list) else []
rollback = payload.get("rollback_records") if isinstance(payload.get("rollback_records"), list) else []
print(f"{'true' if len(instance_logs) > 0 and len(rollback) > 0 else 'false'}|{len(instance_logs)}|{len(rollback)}")
PY
)"
  local logs_ok
  local instance_log_count
  local rollback_count
  IFS='|' read -r logs_ok instance_log_count rollback_count <<<"${logs_parsed}"
  if [[ "${logs_ok}" != "true" ]]; then
    github_deployer_step_failed \
      "github-deployer-business-instance-logs" \
      "GitHub Deployer instance logs evidence" \
      "repo_regression" \
      "instance logs/rollback records are missing after live create path" \
      "Ensure live create failure path writes instance_logs and rollback_records" \
      "${logs_json}"
    return 1
  fi
  github_deployer_step_passed \
    "github-deployer-business-instance-logs" \
    "GitHub Deployer instance logs evidence" \
    "instance_logs and rollback_records captured" \
    "${logs_json}"

  append_step \
    "github-deployer-business-flow" \
    "GitHub Deployer business flow" \
    "passed" \
    "" \
    "business flow completed with dry_run plan + instance/log/rollback evidence" \
    "" \
    "${GITHUB_DEPLOYER_BUSINESS_DIR}"
  return 0
}

product_smoke_business_cleanup() {
  github_deployer_business_init

  local cleanup_report="${GITHUB_DEPLOYER_BUSINESS_DIR}/cleanup-dry-run.json"
  python3 - <<'PY' \
    "${cleanup_report}" \
    "${GITHUB_DEPLOYER_BUSINESS_TS}" \
    "${GITHUB_DEPLOYER_FAIL_INSTANCE_ID}" \
    "${GITHUB_DEPLOYER_BUSINESS_DIR}"
import json
import pathlib
import sys

report_path = pathlib.Path(sys.argv[1])
run_id = sys.argv[2]
instance_id = sys.argv[3]
evidence_dir = pathlib.Path(sys.argv[4])

dry_run_plan = evidence_dir / "create-instance-dry-run.json"
live_create = evidence_dir / "create-instance-live.json"
logs_file = evidence_dir / "instance-logs.json"

instance_log_count = 0
rollback_count = 0
if logs_file.exists():
    try:
        logs_payload = json.loads(logs_file.read_text(encoding="utf-8"))
        instance_log_count = len(logs_payload.get("instance_logs") or [])
        rollback_count = len(logs_payload.get("rollback_records") or [])
    except Exception:
        pass

payload = {
    "run_id": run_id,
    "test_id": "TEST-GHDEPLOY-BUSINESS-001",
    "user_journey_id": ["UJ13"],
    "product": "github-deployer",
    "fixture_marker": f"github-deployer-business-{run_id}",
    "candidate_counts": {
        "template_fixture": 1,
        "instance_deployment_records": 1 if instance_id else 0,
        "dry_run_plan_records": 1 if dry_run_plan.exists() else 0,
        "expected_failure_records": 1 if live_create.exists() else 0,
        "instance_log_records": instance_log_count,
        "rollback_records": rollback_count,
        "logs_report_records": len([p for p in evidence_dir.glob("*") if p.is_file()]),
    },
    "sql_or_file_preview": {
        "instance_sql": "DELETE FROM instances WHERE id = ?",
        "instance_params": [instance_id] if instance_id else [],
        "instance_logs_sql": "DELETE FROM instance_logs WHERE instance_id = ?",
        "instance_logs_params": [instance_id] if instance_id else [],
        "rollback_sql": "DELETE FROM rollback_records WHERE instance_id = ?",
        "rollback_params": [instance_id] if instance_id else [],
        "deploy_dir_policy": "remove only deploy_dir belonging to the exact instance_id after backup",
        "artifact_dir": str(evidence_dir),
        "artifact_policy": "remove this run-scoped evidence directory only after report retention expires",
    },
    "safe_to_apply": bool(instance_id),
    "requires_backup": True,
    "dry_run_only": True,
    "apply_blocked_without_snapshot": True,
}
report_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY

  github_deployer_step_passed \
    "github-deployer-business-cleanup-dry-run" \
    "GitHub Deployer cleanup dry-run report" \
    "cleanup candidates reported without deleting data" \
    "${cleanup_report}"
  return 0
}
