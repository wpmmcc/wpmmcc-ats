#!/usr/bin/env bash

# Cloud API Hub business smoke lane:
# provider availability -> credential save -> sqlite plaintext check -> translate -> job history evidence.

CLOUD_HUB_BUSINESS_TS=""
CLOUD_HUB_BUSINESS_DIR=""
CLOUD_HUB_SECRET=""
CLOUD_HUB_CREDENTIAL_ID=""
CLOUD_HUB_JOB_ID=""
CLOUD_HUB_DB_PATH=""
CLOUD_HUB_LAST_HTTP_STATUS=""

cloud_hub_business_init() {
  if [[ -n "${CLOUD_HUB_BUSINESS_DIR}" ]]; then
    return 0
  fi
  CLOUD_HUB_BUSINESS_TS="${TIMESTAMP:-$(date +%Y%m%d-%H%M%S)}"
  CLOUD_HUB_BUSINESS_DIR="${RUNTIME_DIR}/product-smoke-cloud-api-hub/${CLOUD_HUB_BUSINESS_TS}"
  mkdir -p "${CLOUD_HUB_BUSINESS_DIR}"
  return 0
}

cloud_hub_call_json() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local out_file="$4"
  local err_file="${out_file}.curl.err"
  local url="${CLOUD_HUB_URL}${path}"
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

  CLOUD_HUB_LAST_HTTP_STATUS="${status:-000}"
  if [[ ${curl_rc} -ne 0 ]]; then
    return 2
  fi
  if [[ ! "${CLOUD_HUB_LAST_HTTP_STATUS}" =~ ^2 ]]; then
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

cloud_hub_resolve_db_path() {
  local explicit="${CLOUD_API_HUB_DB_PATH:-}"
  if [[ -n "${explicit}" && -f "${explicit}" ]]; then
    echo "${explicit}"
    return 0
  fi

  local data_dir="${CLOUD_API_HUB_DATA_DIR:-}"
  if [[ -n "${data_dir}" && -f "${data_dir}/cloud-api-hub.db" ]]; then
    echo "${data_dir}/cloud-api-hub.db"
    return 0
  fi

  local candidates=(
    "${REPO_ROOT}/client-cloud-api-hub/data/cloud-api-hub.db"
    "${REPO_ROOT}/data/cloud-api-hub.db"
  )
  local candidate=""
  for candidate in "${candidates[@]}"; do
    if [[ -f "${candidate}" ]]; then
      echo "${candidate}"
      return 0
    fi
  done

  return 1
}

cloud_hub_clear_records() {
  local out_file="$1"
  local api_path="$2"
  local step_id="$3"
  local step_name="$4"
  local action="$5"

  if ! cloud_hub_call_json "POST" "${api_path}" "{}" "${out_file}"; then
    append_step "${step_id}" "${step_name}" "failed" "missing_dependency" "failed to reach Cloud API Hub clear endpoint" "${action}" "${out_file}"
    return 1
  fi

  local ok_value
  ok_value="$(python3 - <<'PY' "${out_file}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
print("true" if payload.get("success") is True else "false")
PY
)"
  if [[ "${ok_value}" != "true" ]]; then
    append_step "${step_id}" "${step_name}" "failed" "contract_mismatch" "clear endpoint returned unexpected payload" "${action}" "${out_file}"
    return 1
  fi

  append_step "${step_id}" "${step_name}" "passed" "" "ok" "" "${out_file}"
  return 0
}

product_smoke_business_seed() {
  cloud_hub_business_init

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "cloud-hub-business-seed" "Cloud API Hub business seed" "skipped" "" "dry-run" "" "${CLOUD_HUB_BUSINESS_DIR}"
    return 0
  fi

  local providers_json="${CLOUD_HUB_BUSINESS_DIR}/providers.json"
  if ! cloud_hub_call_json "GET" "/api/providers" "" "${providers_json}"; then
    append_step "cloud-hub-business-providers" "Cloud API Hub providers contract" "failed" "missing_dependency" "cloud-api-hub providers endpoint is unreachable" "Start/restart cloud-api-hub service and verify /api/providers" "${providers_json}"
    return 1
  fi

  local has_mock_echo
  has_mock_echo="$(python3 - <<'PY' "${providers_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
print("true" if any(item.get("id") == "mock-echo" for item in payload if isinstance(item, dict)) else "false")
PY
)"
  if [[ "${has_mock_echo}" != "true" ]]; then
    append_step "cloud-hub-business-providers" "Cloud API Hub providers contract" "failed" "contract_mismatch" "provider list does not include mock-echo" "Deploy cloud-api-hub binary containing mock-echo provider from ISS-20260425-020" "${providers_json}"
    return 1
  fi
  append_step "cloud-hub-business-providers" "Cloud API Hub providers contract" "passed" "" "mock-echo provider is available" "" "${providers_json}"

  CLOUD_HUB_SECRET="cloud-hub-smoke-secret-${CLOUD_HUB_BUSINESS_TS}"
  local save_credential_json="${CLOUD_HUB_BUSINESS_DIR}/credential-save.json"
  local save_payload
  save_payload="$(python3 - <<'PY' "${CLOUD_HUB_SECRET}"
import json
import sys
print(json.dumps({"provider": "mock-echo", "data": sys.argv[1]}))
PY
)"
  if ! cloud_hub_call_json "POST" "/api/credentials" "${save_payload}" "${save_credential_json}"; then
    append_step "cloud-hub-business-save-credential" "Cloud API Hub save credential" "failed" "repo_regression" "failed to save test credential" "Check /api/credentials and secure_store wiring" "${save_credential_json}"
    return 1
  fi

  local save_parsed
  save_parsed="$(python3 - <<'PY' "${save_credential_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false|")
    raise SystemExit(0)
ok = payload.get("success") is True
cid = payload.get("id") or ""
print(f"{'true' if ok and cid else 'false'}|{cid}")
PY
)"
  local save_ok
  IFS='|' read -r save_ok CLOUD_HUB_CREDENTIAL_ID <<<"${save_parsed}"
  if [[ "${save_ok}" != "true" ]]; then
    append_step "cloud-hub-business-save-credential" "Cloud API Hub save credential" "failed" "contract_mismatch" "save credential response missing success/id" "Check /api/credentials response contract" "${save_credential_json}"
    return 1
  fi
  append_step "cloud-hub-business-save-credential" "Cloud API Hub save credential" "passed" "" "credential saved" "" "${save_credential_json}"

  local list_credential_json="${CLOUD_HUB_BUSINESS_DIR}/credential-list.json"
  if ! cloud_hub_call_json "GET" "/api/credentials?provider=mock-echo" "" "${list_credential_json}"; then
    append_step "cloud-hub-business-list-credential" "Cloud API Hub list credential" "failed" "contract_mismatch" "failed to list saved credential" "Check /api/credentials?provider=mock-echo route" "${list_credential_json}"
    return 1
  fi
  local list_ok
  list_ok="$(python3 - <<'PY' "${list_credential_json}" "${CLOUD_HUB_CREDENTIAL_ID}" "${CLOUD_HUB_SECRET}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
cid = sys.argv[2]
secret = sys.argv[3]
for item in payload:
    if not isinstance(item, dict):
        continue
    if item.get("id") == cid and item.get("provider") == "mock-echo" and item.get("data") == secret:
        print("true")
        break
else:
    print("false")
PY
)"
  if [[ "${list_ok}" != "true" ]]; then
    append_step "cloud-hub-business-list-credential" "Cloud API Hub list credential" "failed" "repo_regression" "saved credential cannot be read back from API" "Check credential save/list handlers in web_ui.rs" "${list_credential_json}"
    return 1
  fi
  append_step "cloud-hub-business-list-credential" "Cloud API Hub list credential" "passed" "" "credential is visible through API" "" "${list_credential_json}"

  CLOUD_HUB_DB_PATH="$(cloud_hub_resolve_db_path || true)"
  if [[ -z "${CLOUD_HUB_DB_PATH}" ]]; then
    append_step "cloud-hub-business-sqlite-path" "Cloud API Hub sqlite path detection" "failed" "missing_dependency" "cannot locate cloud-api-hub.db" "Set CLOUD_API_HUB_DB_PATH (or CLOUD_API_HUB_DATA_DIR) to the runtime database path" "${CLOUD_HUB_BUSINESS_DIR}"
    return 1
  fi
  append_step "cloud-hub-business-sqlite-path" "Cloud API Hub sqlite path detection" "passed" "" "sqlite path detected" "" "${CLOUD_HUB_DB_PATH}"

  local sqlite_check_out="${CLOUD_HUB_BUSINESS_DIR}/sqlite-secret-check.json"
  local secret_leaked
  secret_leaked="$(python3 - <<'PY' "${CLOUD_HUB_DB_PATH}" "${CLOUD_HUB_SECRET}" "${sqlite_check_out}"
import json
import pathlib
import sys

db_path = pathlib.Path(sys.argv[1])
secret = sys.argv[2]
out_path = pathlib.Path(sys.argv[3])
wal_path = db_path.with_name(db_path.name + "-wal")

raw = b""
if db_path.exists():
    raw += db_path.read_bytes()
if wal_path.exists():
    raw += wal_path.read_bytes()

text = raw.decode("utf-8", errors="ignore")
leaked = secret in text
payload = {
    "db_path": str(db_path),
    "wal_path": str(wal_path),
    "secret_leaked": leaked,
}
out_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print("true" if leaked else "false")
PY
)"
  if [[ "${secret_leaked}" == "true" ]]; then
    append_step "cloud-hub-business-sqlite-secret" "Cloud API Hub credential at-rest encryption" "failed" "repo_regression" "plaintext credential leaked to sqlite/wal" "Check secure_store encrypt/decrypt integration before save_credential" "${sqlite_check_out}"
    return 1
  fi
  append_step "cloud-hub-business-sqlite-secret" "Cloud API Hub credential at-rest encryption" "passed" "" "plaintext credential not found in sqlite/wal" "" "${sqlite_check_out}"

  append_step "cloud-hub-business-seed" "Cloud API Hub business seed" "passed" "" "business seed completed" "" "${CLOUD_HUB_BUSINESS_DIR}"
  return 0
}

product_smoke_business_run() {
  cloud_hub_business_init

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "cloud-hub-business-flow" "Cloud API Hub business flow" "skipped" "" "dry-run" "" "${CLOUD_HUB_BUSINESS_DIR}"
    return 0
  fi

  local translate_json="${CLOUD_HUB_BUSINESS_DIR}/translate-response.json"
  local translate_payload
  translate_payload="$(python3 - <<'PY' "${CLOUD_HUB_BUSINESS_TS}"
import json
import sys
text = f"cloud-api-hub business smoke {sys.argv[1]}"
print(json.dumps({
    "text": text,
    "source_lang": "en",
    "target_lang": "zh",
    "provider": "mock-echo",
    "api_key": None,
}))
PY
)"
  if ! cloud_hub_call_json "POST" "/api/translate" "${translate_payload}" "${translate_json}"; then
    append_step "cloud-hub-business-translate" "Cloud API Hub translate request" "failed" "repo_regression" "mock-echo translate request failed" "Check /api/translate dispatch and mock-echo provider wiring" "${translate_json}"
    return 1
  fi

  local translate_parsed
  translate_parsed="$(python3 - <<'PY' "${translate_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false||")
    raise SystemExit(0)
ok = payload.get("success") is True
job_id = payload.get("job_id") or ""
translated = (payload.get("result") or {}).get("translated_text") or ""
provider = (payload.get("result") or {}).get("provider") or ""
print(f"{'true' if ok and job_id else 'false'}|{job_id}|{translated}|{provider}")
PY
)"
  local translate_ok
  local translated_text
  local translated_provider
  IFS='|' read -r translate_ok CLOUD_HUB_JOB_ID translated_text translated_provider <<<"${translate_parsed}"
  if [[ "${translate_ok}" != "true" || "${translated_provider}" != "mock-echo" ]]; then
    append_step "cloud-hub-business-translate" "Cloud API Hub translate request" "failed" "contract_mismatch" "translate response missing success/job_id/provider=mock-echo" "Check /api/translate response contract" "${translate_json}"
    return 1
  fi
  append_step "cloud-hub-business-translate" "Cloud API Hub translate request" "passed" "" "mock provider request executed" "" "${translate_json}"

  local jobs_json="${CLOUD_HUB_BUSINESS_DIR}/jobs-list.json"
  if ! cloud_hub_call_json "GET" "/api/jobs?limit=30" "" "${jobs_json}"; then
    append_step "cloud-hub-business-jobs-list" "Cloud API Hub jobs list" "failed" "repo_regression" "failed to read job history" "Check /api/jobs handler and sqlite availability" "${jobs_json}"
    return 1
  fi
  local jobs_ok
  jobs_ok="$(python3 - <<'PY' "${jobs_json}" "${CLOUD_HUB_JOB_ID}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
job_id = sys.argv[2]
for item in payload:
    if not isinstance(item, dict):
        continue
    if item.get("id") == job_id and item.get("provider") == "mock-echo" and item.get("status") == "success":
        print("true")
        break
else:
    print("false")
PY
)"
  if [[ "${jobs_ok}" != "true" ]]; then
    append_step "cloud-hub-business-jobs-list" "Cloud API Hub jobs list" "failed" "repo_regression" "job history missing expected mock-echo success job" "Check db::save_job and /api/jobs contract" "${jobs_json}"
    return 1
  fi
  append_step "cloud-hub-business-jobs-list" "Cloud API Hub jobs list" "passed" "" "job history contains mock-echo success record" "" "${jobs_json}"

  local job_detail_json="${CLOUD_HUB_BUSINESS_DIR}/job-detail.json"
  if ! cloud_hub_call_json "GET" "/api/jobs/${CLOUD_HUB_JOB_ID}" "" "${job_detail_json}"; then
    append_step "cloud-hub-business-job-detail" "Cloud API Hub job detail" "failed" "repo_regression" "failed to read created job detail" "Check /api/jobs/:id route contract" "${job_detail_json}"
    return 1
  fi
  append_step "cloud-hub-business-job-detail" "Cloud API Hub job detail" "passed" "" "job detail is readable" "" "${job_detail_json}"

  append_step "cloud-hub-business-flow" "Cloud API Hub business flow" "passed" "" "business flow completed" "" "${CLOUD_HUB_BUSINESS_DIR}"
  return 0
}

product_smoke_business_cleanup() {
  cloud_hub_business_init

  local cleanup_report="${CLOUD_HUB_BUSINESS_DIR}/cleanup-dry-run.json"
  python3 - <<'PY' \
    "${cleanup_report}" \
    "${CLOUD_HUB_BUSINESS_TS}" \
    "${CLOUD_HUB_CREDENTIAL_ID}" \
    "${CLOUD_HUB_JOB_ID}" \
    "${CLOUD_HUB_DB_PATH}" \
    "${CLOUD_HUB_BUSINESS_DIR}"
import json
import pathlib
import sqlite3
import sys

report_path = pathlib.Path(sys.argv[1])
run_id = sys.argv[2]
credential_id = sys.argv[3]
job_id = sys.argv[4]
db_path = pathlib.Path(sys.argv[5]) if sys.argv[5] else None
evidence_dir = pathlib.Path(sys.argv[6])

def count_sql(sql, params=()):
    if not db_path or not db_path.exists():
        return 0
    try:
        with sqlite3.connect(str(db_path)) as conn:
            return int(conn.execute(sql, params).fetchone()[0] or 0)
    except Exception:
        return 0

credential_count = count_sql("SELECT COUNT(*) FROM credentials WHERE id = ?", (credential_id,)) if credential_id else 0
job_count = count_sql("SELECT COUNT(*) FROM job_history WHERE id = ?", (job_id,)) if job_id else 0
artifact_count = len([p for p in evidence_dir.glob("*") if p.is_file()])

payload = {
    "run_id": run_id,
    "test_id": "TEST-CLOUDHUB-BUSINESS-001",
    "user_journey_id": ["UJ12"],
    "product": "cloud-api-hub",
    "fixture_marker": f"cloud-api-hub-business-{run_id}",
    "candidate_counts": {
        "provider_fixture": 1,
        "credential_test_records": credential_count,
        "job_task_records": job_count,
        "logs_report_records": artifact_count,
    },
    "sql_or_file_preview": {
        "sqlite_db": str(db_path) if db_path else "",
        "credential_sql": "DELETE FROM credentials WHERE id = ?",
        "credential_params": [credential_id] if credential_id else [],
        "job_sql": "DELETE FROM job_history WHERE id = ?",
        "job_params": [job_id] if job_id else [],
        "artifact_dir": str(evidence_dir),
        "artifact_policy": "remove this run-scoped evidence directory only after report retention expires",
    },
    "safe_to_apply": bool(credential_id or job_id),
    "requires_backup": True,
    "dry_run_only": True,
    "apply_blocked_without_snapshot": True,
}
report_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY

  append_step "cloud-hub-business-cleanup-dry-run" "Cloud API Hub cleanup dry-run report" "passed" "" "cleanup candidates reported without deleting data" "" "${cleanup_report}"
  return 0
}
