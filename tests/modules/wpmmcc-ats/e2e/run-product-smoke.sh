#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"
source "${SCRIPT_DIR}/lib/failure-classification.sh"

PRODUCT=""
MODE="health"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --product)
      PRODUCT="${2:-}"
      shift 2
      ;;
    --mode)
      MODE="${2:-}"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    -h|--help)
      cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/run-product-smoke.sh --product <wptsall|cloud-api-hub|github-deployer> [--mode health|business] [--dry-run]

Examples:
  bash tests/modules/wpmmcc-ats/e2e/run-product-smoke.sh --product wptsall
  bash tests/modules/wpmmcc-ats/e2e/run-product-smoke.sh --product cloud-api-hub --mode business --dry-run
  bash tests/modules/wpmmcc-ats/e2e/run-product-smoke.sh --product github-deployer --mode health --dry-run
EOF
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

case "${PRODUCT}" in
  wptsall|cloud-api-hub|github-deployer)
    ;;
  *)
    echo "Missing/invalid --product: ${PRODUCT:-<empty>}" >&2
    exit 1
    ;;
esac

case "${MODE}" in
  health|business)
    ;;
  *)
    echo "Missing/invalid --mode: ${MODE:-<empty>}" >&2
    exit 1
    ;;
esac

ensure_dirs
mkdir -p "${REPORTS_DIR}"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
STARTED_AT="$(date -Iseconds)"
if [[ "${MODE}" == "health" ]]; then
  REPORT_JSON="${REPORTS_DIR}/product-smoke-${PRODUCT}-${TIMESTAMP}.json"
else
  REPORT_JSON="${REPORTS_DIR}/product-smoke-${PRODUCT}-${MODE}-${TIMESTAMP}.json"
fi
STEPS_JSONL="$(mktemp)"
trap 'rm -f "${STEPS_JSONL}"' EXIT

CATALOG_JSON="${REPO_ROOT}/tests/infra/test-catalog.json"
REQUIREMENTS_MAP_JSON="${REPO_ROOT}/tests/infra/validation-requirements-map.json"

append_step() {
  local id="$1"
  local name="$2"
  local status="$3"
  local category="$4"
  local message="$5"
  local next_action="$6"
  local evidence="$7"

  python3 - <<'PY' "${id}" "${name}" "${status}" "${category}" "${message}" "${next_action}" "${evidence}" >> "${STEPS_JSONL}"
import json
import sys

obj = {
    "id": sys.argv[1],
    "name": sys.argv[2],
    "status": sys.argv[3],
    "category": (sys.argv[4] if sys.argv[4] else None),
    "message": sys.argv[5],
    "next_action": (sys.argv[6] if sys.argv[6] else None),
    "evidence": (sys.argv[7] if sys.argv[7] else None),
}
print(json.dumps(obj, ensure_ascii=False))
PY
}

product_test_id() {
  case "${PRODUCT}:${MODE}" in
    wptsall:business) echo "TEST-WPTSALL-BUSINESS-001" ;;
    cloud-api-hub:business) echo "TEST-CLOUDHUB-BUSINESS-001" ;;
    github-deployer:business) echo "TEST-GHDEPLOY-BUSINESS-001" ;;
    *) echo "" ;;
  esac
}

product_requirement_id() {
  case "${PRODUCT}:${MODE}" in
    wptsall:business) echo "REQ-WPTSALL-001" ;;
    cloud-api-hub:business) echo "REQ-CLOUDHUB-001" ;;
    github-deployer:business) echo "REQ-GHDEPLOY-001" ;;
    *) echo "" ;;
  esac
}

product_scenario_id() {
  case "${PRODUCT}:${MODE}" in
    wptsall:business) echo "SCN-WPTSALL-BUSINESS-SMOKE" ;;
    cloud-api-hub:business) echo "SCN-CLOUDHUB-BUSINESS-SMOKE" ;;
    github-deployer:business) echo "SCN-GHDEPLOY-BUSINESS-SMOKE" ;;
    *) echo "SCN-${PRODUCT^^}-${MODE^^}-SMOKE" | tr -c 'A-Z0-9_-' '-' ;;
  esac
}

probe_json_endpoint() {
  local url="$1"
  local output_file="$2"
  local status_code
  set +e
  status_code="$(curl -sS -L --max-time 10 -o "${output_file}" -w "%{http_code}" "${url}" 2>/dev/null)"
  local curl_exit=$?
  set -e
  if [[ ${curl_exit} -ne 0 ]]; then
    return 2
  fi
  if [[ ! "${status_code}" =~ ^2 ]]; then
    return 3
  fi
  local first_char
  first_char="$(python3 - <<'PY' "${output_file}"
import pathlib
import sys
raw = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace").lstrip()
print(raw[:1] if raw else "")
PY
)"
  if [[ "${first_char}" == "<" ]]; then
    return 4
  fi
  return 0
}

run_json_step() {
  local id="$1"
  local name="$2"
  local url="$3"
  local fail_category="$4"
  local fail_action="$5"
  local out_file
  out_file="$(mktemp)"

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "$id" "$name" "skipped" "" "dry-run" "" "$url"
    rm -f "${out_file}"
    return 0
  fi

  if probe_json_endpoint "${url}" "${out_file}"; then
    append_step "$id" "$name" "passed" "" "ok" "" "$url"
    cat "${out_file}"
    rm -f "${out_file}"
    return 0
  fi

  local rc=$?
  local message
  case "${rc}" in
    2) message="endpoint not reachable: ${url}" ;;
    3) message="non-2xx status from endpoint: ${url}" ;;
    4) message="endpoint returned non-JSON payload: ${url}" ;;
    *) message="endpoint probe failed: ${url}" ;;
  esac
  append_step "$id" "$name" "failed" "${fail_category}" "${message}" "${fail_action}" "$url"
  rm -f "${out_file}"
  return 1
}

run_logged_in_step() {
  local id="$1"
  local name="$2"
  local url="$3"
  local status_json="$4"
  local fail_action="$5"
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "$id" "$name" "skipped" "" "dry-run" "" "$url"
    return 0
  fi

  local logged_in
  logged_in="$(python3 - <<'PY' "${status_json}"
import json
import pathlib
import sys
try:
    data = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
print("true" if data.get("logged_in") is True else "false")
PY
)"
  if [[ "${logged_in}" != "true" ]]; then
    append_step "$id" "$name" "skipped" "" "local product session is not logged in (health lane skip)" "${fail_action}" "$url"
    return 0
  fi
  run_json_step "$id" "$name" "$url" "environment_drift" "${fail_action}" >/dev/null
}

WPTSALL_CLIENT_URL="${WPTSALL_CLIENT_URL:-${CLIENT_URL}}"
CLOUD_HUB_URL="${CLOUD_HUB_URL:-http://127.0.0.1:8978}"
GITHUB_DEPLOYER_URL="${GITHUB_DEPLOYER_URL:-http://127.0.0.1:8979}"

run_health_wptsall() {
  if _is_lab_mode; then
  # P0-EV-06 / local-first: Lab smoke proves WP plugin runtime only.
  # Website server (:8787) and persistent client (:8977) are optional legacy lanes.
    append_step "wptsall-server-health" "Server health (legacy control plane)" "skipped" "" \
      "skipped in WPTSALL_LAB local-first smoke" "Use legacy test-host smoke without WPTSALL_LAB to probe :8787" \
      "${SERVER_URL}/health"
    run_json_step "wptsall-wp-rest" "WP REST health" "${WP_URL}/wp-json/" "environment_drift" "Check WP runtime and Apache mapping" >/dev/null || true
    append_step "wptsall-client-status" "Client WebUI status" "skipped" "" \
      "skipped in WPTSALL_LAB smoke (client started on-demand by full release gate)" \
      "Run full release gate or start client-webui for client probes" \
      "${WPTSALL_CLIENT_URL}/api/status"
    append_step "wptsall-mock-health" "Mock translate API health" "skipped" "" \
      "skipped in WPTSALL_LAB smoke (mock started on-demand by automation lanes)" \
      "Start mock-translate-api on :9090 or run automation preflight" \
      "${MOCK_API_URL}/api/v1/health"
    append_step "wptsall-worker-config" "Worker config endpoint" "skipped" "" \
      "skipped in WPTSALL_LAB smoke without running client" \
      "Start client WebUI before probing worker config" \
      "${WPTSALL_CLIENT_URL}/api/worker/config"
    return 0
  fi

  run_json_step "wptsall-server-health" "Server health" "${SERVER_URL}/health" "environment_drift" "Check wptsall-server.service and reverse proxy" >/dev/null || true
  run_json_step "wptsall-wp-rest" "WP REST health" "${WP_URL}/wp-json/" "environment_drift" "Check WP runtime and Apache mapping" >/dev/null || true
  run_json_step "wptsall-client-status" "Client WebUI status" "${WPTSALL_CLIENT_URL}/api/status" "missing_dependency" "Check/restart wptsall-client-webui.service" >/dev/null || true
  run_json_step "wptsall-mock-health" "Mock translate API health" "${MOCK_API_URL}/api/v1/health" "missing_dependency" "Check/restart mock9090.service" >/dev/null || true
  run_json_step "wptsall-worker-config" "Worker config endpoint" "${WPTSALL_CLIENT_URL}/api/worker/config" "contract_mismatch" "Verify client web API contract and session state" >/dev/null || true
}

run_health_cloud_api_hub() {
  run_json_step "cloud-hub-health" "Cloud API Hub health" "${CLOUD_HUB_URL}/health" "missing_dependency" "Start/restart cloud-api-hub service" >/dev/null || true
  local status_file
  status_file="$(mktemp)"
  if run_json_step "cloud-hub-status" "Cloud API Hub status" "${CLOUD_HUB_URL}/api/status" "missing_dependency" "Check cloud-api-hub runtime" > "${status_file}"; then
    :
  fi
  run_logged_in_step "cloud-hub-entitlements" "Cloud API Hub entitlements" "${CLOUD_HUB_URL}/api/entitlements" "${status_file}" "Login in Cloud API Hub Web UI first" || true
  run_logged_in_step "cloud-hub-templates" "Cloud API Hub templates" "${CLOUD_HUB_URL}/api/templates" "${status_file}" "Login in Cloud API Hub Web UI first" || true
  run_json_step "cloud-hub-providers" "Cloud API Hub providers" "${CLOUD_HUB_URL}/api/providers" "contract_mismatch" "Check provider route and payload contract" >/dev/null || true
  run_json_step "cloud-hub-jobs" "Cloud API Hub job history" "${CLOUD_HUB_URL}/api/jobs" "contract_mismatch" "Check cloud-api-hub jobs route contract" >/dev/null || true
  rm -f "${status_file}"
}

run_health_github_deployer() {
  run_json_step "github-deployer-health" "GitHub Deployer health" "${GITHUB_DEPLOYER_URL}/health" "missing_dependency" "Start/restart github-deployer service" >/dev/null || true
  local status_file
  status_file="$(mktemp)"
  if run_json_step "github-deployer-status" "GitHub Deployer status" "${GITHUB_DEPLOYER_URL}/api/status" "missing_dependency" "Check github-deployer runtime" > "${status_file}"; then
    :
  fi
  run_logged_in_step "github-deployer-entitlements" "GitHub Deployer entitlements" "${GITHUB_DEPLOYER_URL}/api/entitlements" "${status_file}" "Login in GitHub Deployer Web UI first" || true
  run_logged_in_step "github-deployer-templates" "GitHub Deployer templates" "${GITHUB_DEPLOYER_URL}/api/templates" "${status_file}" "Login in GitHub Deployer Web UI first" || true
  run_json_step "github-deployer-docker" "GitHub Deployer docker status" "${GITHUB_DEPLOYER_URL}/api/docker/status" "missing_dependency" "Install/start Docker runtime on test host" >/dev/null || true
  run_json_step "github-deployer-instances" "GitHub Deployer instances list" "${GITHUB_DEPLOYER_URL}/api/instances" "contract_mismatch" "Check instance route and DB schema contract" >/dev/null || true
  rm -f "${status_file}"
}

run_health_lane() {
  case "${PRODUCT}" in
    wptsall) run_health_wptsall ;;
    cloud-api-hub) run_health_cloud_api_hub ;;
    github-deployer) run_health_github_deployer ;;
    *)
      append_step "health-lane-dispatch" "Health lane dispatch" "failed" "contract_mismatch" "unsupported product lane: ${PRODUCT}" "Check --product argument" "${PRODUCT}"
      return 1
      ;;
  esac
  return 0
}

load_business_lane() {
  local lane_file="${SCRIPT_DIR}/product-smoke/business/${PRODUCT}.sh"
  if [[ ! -f "${lane_file}" ]]; then
    append_step "${PRODUCT}-business-lane-missing" "Business lane registration" "failed" "contract_mismatch" "business lane script not found: ${lane_file}" "Add product lane script under tests/modules/wpmmcc-ats/e2e/product-smoke/business/" "${lane_file}"
    return 1
  fi

  # shellcheck disable=SC1090
  source "${lane_file}"
  if ! declare -F product_smoke_business_run >/dev/null 2>&1; then
    append_step "${PRODUCT}-business-lane-invalid" "Business lane registration" "failed" "contract_mismatch" "lane does not define product_smoke_business_run" "Define product_smoke_business_run in lane script" "${lane_file}"
    return 1
  fi

  return 0
}

run_business_lane() {
  local lane_rc=0
  local cleanup_rc=0

  if declare -F product_smoke_business_seed >/dev/null 2>&1; then
    if ! product_smoke_business_seed; then
      lane_rc=1
    fi
  fi

  if [[ ${lane_rc} -eq 0 ]]; then
    if ! product_smoke_business_run; then
      lane_rc=1
    fi
  fi

  if declare -F product_smoke_business_cleanup >/dev/null 2>&1; then
    if ! product_smoke_business_cleanup; then
      cleanup_rc=1
    fi
  fi

  if [[ ${cleanup_rc} -ne 0 && ${lane_rc} -eq 0 ]]; then
    lane_rc=1
  fi

  return "${lane_rc}"
}

echo "Running product smoke: ${PRODUCT}"
echo "Lane mode: ${MODE}"
echo "Execution mode: $([[ "${DRY_RUN}" -eq 1 ]] && echo dry-run || echo live)"
echo "Report: ${REPORT_JSON}"

LANE_EXIT=0
set +e
if [[ "${MODE}" == "health" ]]; then
  run_health_lane
  LANE_EXIT=$?
else
  load_business_lane
  LANE_EXIT=$?
  if [[ ${LANE_EXIT} -eq 0 ]]; then
    run_business_lane
    LANE_EXIT=$?
  fi
fi
set -e

FINISHED_AT="$(date -Iseconds)"

TEST_ID="$(product_test_id)"
REQUIREMENT_ID="$(product_requirement_id)"
SCENARIO_ID="$(product_scenario_id)"

python3 - <<'PY' \
  "${STEPS_JSONL}" "${REPORT_JSON}" "${PRODUCT}" "${STARTED_AT}" "${FINISHED_AT}" "${DRY_RUN}" "${MODE}" "${LANE_EXIT}" \
  "${TEST_ID}" "${REQUIREMENT_ID}" "${SCENARIO_ID}" "${CATALOG_JSON}" "${REQUIREMENTS_MAP_JSON}"
import json
import pathlib
import sys

steps_path = pathlib.Path(sys.argv[1])
report_path = pathlib.Path(sys.argv[2])
product = sys.argv[3]
started_at = sys.argv[4]
finished_at = sys.argv[5]
dry_run = sys.argv[6] == "1"
lane_mode = sys.argv[7]
lane_exit = int(sys.argv[8])
test_id = sys.argv[9]
requirement_id = sys.argv[10]
scenario_id = sys.argv[11]
catalog_path = pathlib.Path(sys.argv[12])
requirements_map_path = pathlib.Path(sys.argv[13])

steps = []
if steps_path.exists():
    for line in steps_path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        steps.append(json.loads(line))

passed = sum(1 for s in steps if s["status"] == "passed")
failed = sum(1 for s in steps if s["status"] == "failed")
skipped = sum(1 for s in steps if s["status"] == "skipped")
status = "passed" if failed == 0 and lane_exit == 0 else "failed"

def read_json(path):
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8", errors="replace"))
    except Exception:
        return None

catalog = read_json(catalog_path) or {}
catalog_tests = {
    str(item.get("test_id")): item
    for item in catalog.get("tests", [])
    if isinstance(item, dict) and item.get("test_id")
}
catalog_user_journeys = set(catalog.get("user_journeys", {}).keys())
catalog_entry = catalog_tests.get(test_id) if test_id else None
user_journey_id = list(catalog_entry.get("user_journey", [])) if isinstance(catalog_entry, dict) else []

requirements_map = read_json(requirements_map_path) or {}
requirement_ids = {
    str(item.get("requirement_id"))
    for item in requirements_map.get("requirements", [])
    if isinstance(item, dict) and item.get("requirement_id")
}

warnings = []
if lane_mode == "business":
    if not test_id:
        warnings.append({"code": "missing_test_id", "message": "business product smoke has no test_id mapping"})
    elif not catalog_entry:
        warnings.append({"code": "unknown_test_id", "message": f"test_id not found in catalog: {test_id}"})
    if requirement_id not in requirement_ids:
        warnings.append({"code": "unknown_requirement_id", "message": f"requirement_id not found in validation map: {requirement_id}"})
    invalid_ujs = [uj for uj in user_journey_id if uj not in catalog_user_journeys]
    if invalid_ujs:
        warnings.append({"code": "invalid_user_journey_id", "message": f"invalid user_journey_id: {invalid_ujs}"})

required_user_journeys = {
    "wptsall": {"UJ5", "UJ6", "UJ9"},
    "cloud-api-hub": {"UJ12"},
    "github-deployer": {"UJ13"},
}.get(product, set())
if lane_mode == "business" and required_user_journeys:
    missing_ujs = sorted(required_user_journeys - set(user_journey_id))
    if missing_ujs:
        warnings.append({"code": "missing_required_user_journey", "message": f"missing required user journeys: {missing_ujs}"})

evidence_paths = []
for step in steps:
    evidence = step.get("evidence")
    if evidence and evidence not in evidence_paths:
        evidence_paths.append(evidence)
evidence_paths.append(str(report_path))

next_action = None
for step in steps:
    if step.get("status") == "failed":
        next_action = step.get("next_action") or step.get("message")
        break

run_id = report_path.stem
for prefix in (
    f"product-smoke-{product}-{lane_mode}-",
    f"product-smoke-{product}-",
):
    if run_id.startswith(prefix):
        run_id = run_id[len(prefix):]
        break

payload = {
    "status": status,
    "test_id": test_id or None,
    "requirement_id": requirement_id or None,
    "user_journey_id": user_journey_id,
    "scenario_id": scenario_id or None,
    "run_id": run_id,
    "product": product,
    "mode": "dry-run" if dry_run else "live",
    "lane_mode": lane_mode,
    "lane_exit_code": lane_exit,
    "started_at": started_at,
    "finished_at": finished_at,
    "summary": {
        "passed": passed,
        "failed": failed,
        "skipped": skipped,
        "total": len(steps),
    },
    "evidence_paths": evidence_paths,
    "next_action": next_action,
    "warnings": warnings,
    "steps": steps,
}

report_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(report_path)
PY

OVERALL_STATUS="$(python3 - <<'PY' "${REPORT_JSON}"
import json
import pathlib
import sys
payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
print(payload["status"])
PY
)"

if [[ "${OVERALL_STATUS}" == "passed" ]]; then
  ok "Product smoke passed: ${PRODUCT}"
  ok "Report written: ${REPORT_JSON}"
  exit 0
fi

warn "Product smoke failed: ${PRODUCT}"
warn "Report written: ${REPORT_JSON}"
exit 1
