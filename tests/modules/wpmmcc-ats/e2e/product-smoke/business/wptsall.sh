#!/usr/bin/env bash

# WPTSALL business smoke lane:
# seed minimal workload -> run worker once -> verify mock usage + WP writeback evidence.

WPTSALL_BUSINESS_TS=""
WPTSALL_BUSINESS_DIR=""
WPTSALL_LAST_HTTP_STATUS=""
WPTSALL_BEFORE_EVIDENCE=""
WPTSALL_AFTER_EVIDENCE=""
WPTSALL_RUN_ONCE_JSON=""
WPTSALL_DISCOVERY_TASKS_BEFORE_JSON=""
WPTSALL_DISCOVERY_TUNE_SUMMARY_JSON=""
WPTSALL_LOCAL_COMPONENT_ID=""
WPTSALL_SELECTED_RELATION_ID=0
WPTSALL_SELECTED_DISCOVERY_TASK_ID=0
WPTSALL_SELECTED_DOMAIN=""
WPTSALL_BUSINESS_FIXTURE_MARKER=""
WPTSALL_BUSINESS_SCENARIO_ID="${WPTSALL_BUSINESS_SCENARIO_ID:-wptsall-business-smoke}"
WPTSALL_BUSINESS_REQUIREMENT_ID="${WPTSALL_BUSINESS_REQUIREMENT_ID:-REQ-WPTSALL-BUSINESS-SMOKE}"

wptsall_business_local_first() {
  # Local-first Lab / control-plane-off: no website OAuth or :8787 control plane.
  case "${WPTSALL_LAB:-}" in 1|true|TRUE|yes|YES|on|ON) return 0 ;; esac
  case "${WPTSALL_USE_SERVER_CONTROL_PLANE:-1}" in 0|false|FALSE|no|NO|off|OFF) return 0 ;; esac
  return 1
}

wptsall_client_logged_in_from_status_file() {
  local status_file="$1"
  python3 - <<'PY' "${status_file}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false")
    raise SystemExit(0)
top_level = payload.get("logged_in")
data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
nested = data.get("logged_in")
is_logged_in = (top_level is True) or (nested is True)
print("true" if is_logged_in else "false")
PY
}

wptsall_bootstrap_client_login() {
  local step_id="${1:-wptsall-business-client-login-bootstrap}"
  local step_name="${2:-WPTSALL client login bootstrap}"
  local oauth_start_json="${WPTSALL_BUSINESS_DIR}/client-oauth-start.json"
  if ! wptsall_call_json "POST" "${WPTSALL_CLIENT_URL}/api/oauth/start" "{}" "${oauth_start_json}"; then
    append_step "${step_id}" "${step_name}" "failed" "missing_dependency" "failed to start OAuth login flow from client WebUI" "Check /api/oauth/start and client WebUI runtime" "${oauth_start_json}"
    return 1
  fi

  local authorize_url
  authorize_url="$(python3 - <<'PY' "${oauth_start_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("")
    raise SystemExit(0)
print(str(payload.get("data", {}).get("authorize_url", "")).strip())
PY
  )"
  if [[ -z "${authorize_url}" ]]; then
    append_step "${step_id}" "${step_name}" "failed" "contract_mismatch" "OAuth start response missing authorize_url" "Check client /api/oauth/start response contract" "${oauth_start_json}"
    return 1
  fi

  local oauth_email="${WPTSALL_SMOKE_OAUTH_EMAIL:-${WPTSALL_E2E_OAUTH_EMAIL:-demo@wptsall.dev}}"
  local oauth_password="${WPTSALL_SMOKE_OAUTH_PASSWORD:-${WPTSALL_E2E_OAUTH_PASSWORD:-demo}}"
  local oauth_form_file="${WPTSALL_BUSINESS_DIR}/client-oauth-submit.form"
  local oauth_submit_url_file="${WPTSALL_BUSINESS_DIR}/client-oauth-submit.url"
  python3 - <<'PY' "${authorize_url}" "${oauth_email}" "${oauth_password}" "${oauth_form_file}" "${oauth_submit_url_file}"
import pathlib
import sys
from urllib.parse import parse_qs, urlencode, urlparse

authorize_url = sys.argv[1]
email = sys.argv[2]
password = sys.argv[3]
form_path = pathlib.Path(sys.argv[4])
submit_url_path = pathlib.Path(sys.argv[5])

parsed = urlparse(authorize_url)
query = parse_qs(parsed.query, keep_blank_values=True)

payload = {
    "email": email,
    "password": password,
    "redirect_uri": (query.get("redirect_uri") or [""])[0],
    "code_challenge": (query.get("code_challenge") or [""])[0],
    "code_challenge_method": (query.get("code_challenge_method") or ["S256"])[0],
    "state": (query.get("state") or [""])[0],
    "client_id": (query.get("client_id") or [""])[0],
    "locale": (query.get("lang") or query.get("locale") or [""])[0],
}
form_path.write_text(urlencode(payload), encoding="utf-8")
submit_url_path.write_text(f"{parsed.scheme}://{parsed.netloc}{parsed.path}", encoding="utf-8")
PY
  local oauth_submit_url
  oauth_submit_url="$(cat "${oauth_submit_url_file}")"
  if [[ -z "${oauth_submit_url}" ]]; then
    append_step "${step_id}" "${step_name}" "failed" "contract_mismatch" "OAuth authorize submit URL is empty" "Check authorize_url format from /api/oauth/start" "${oauth_start_json}"
    return 1
  fi

  local oauth_submit_headers="${WPTSALL_BUSINESS_DIR}/client-oauth-submit.headers"
  local oauth_submit_body="${WPTSALL_BUSINESS_DIR}/client-oauth-submit.body"
  local oauth_submit_status=""
  local errexit_was_on=0
  if [[ "$-" == *e* ]]; then
    errexit_was_on=1
  fi

  set +e
  oauth_submit_status="$(curl -sS -o "${oauth_submit_body}" -D "${oauth_submit_headers}" -w "%{http_code}" -X POST -H "Content-Type: application/x-www-form-urlencoded" --data @"${oauth_form_file}" "${oauth_submit_url}")"
  local oauth_submit_rc=$?
  if [[ ${errexit_was_on} -eq 1 ]]; then
    set -e
  fi
  if [[ ${oauth_submit_rc} -ne 0 || ! "${oauth_submit_status}" =~ ^30[12378]$ ]]; then
    append_step "${step_id}" "${step_name}" "failed" "missing_dependency" "OAuth authorize submit failed" "Check server OAuth authorize endpoint and smoke credentials env (WPTSALL_SMOKE_OAUTH_EMAIL/PASSWORD)" "${oauth_submit_headers}"
    return 1
  fi

  local oauth_callback_url
  oauth_callback_url="$(awk '{ if (tolower($1) == "location:") { print $2 } }' "${oauth_submit_headers}" | tr -d '\r' | tail -n 1)"
  if [[ -z "${oauth_callback_url}" ]]; then
    append_step "${step_id}" "${step_name}" "failed" "contract_mismatch" "OAuth authorize response missing callback redirect location" "Check server OAuth 303 redirect behavior" "${oauth_submit_headers}"
    return 1
  fi

  local oauth_callback_html="${WPTSALL_BUSINESS_DIR}/client-oauth-callback.html"
  local oauth_callback_status=""
  set +e
  oauth_callback_status="$(curl -sS -o "${oauth_callback_html}" -w "%{http_code}" "${oauth_callback_url}")"
  local oauth_callback_rc=$?
  if [[ ${errexit_was_on} -eq 1 ]]; then
    set -e
  fi
  if [[ ${oauth_callback_rc} -ne 0 || ! "${oauth_callback_status}" =~ ^2 ]]; then
    append_step "${step_id}" "${step_name}" "failed" "repo_regression" "OAuth callback failed to finalize client session" "Check callback URL reachability and client WebUI OAuth callback handler" "${oauth_callback_html}"
    return 1
  fi

  append_step "${step_id}" "${step_name}" "passed" "" "client session bootstrapped via OAuth flow" "" "${oauth_callback_html}"
  return 0
}

wptsall_business_init() {
  if [[ -n "${WPTSALL_BUSINESS_DIR}" ]]; then
    return 0
  fi
  WPTSALL_BUSINESS_TS="$(date +%Y%m%d-%H%M%S)"
  WPTSALL_BUSINESS_FIXTURE_MARKER="wptsall-business-${WPTSALL_BUSINESS_TS}"
  WPTSALL_BUSINESS_DIR="${RUNTIME_DIR}/product-smoke-wptsall/${WPTSALL_BUSINESS_TS}"
  mkdir -p "${WPTSALL_BUSINESS_DIR}"
  WPTSALL_BEFORE_EVIDENCE="${WPTSALL_BUSINESS_DIR}/wp-evidence-before.json"
  WPTSALL_AFTER_EVIDENCE="${WPTSALL_BUSINESS_DIR}/wp-evidence-after.json"
  WPTSALL_RUN_ONCE_JSON="${WPTSALL_BUSINESS_DIR}/client-run-once.json"
  WPTSALL_DISCOVERY_TASKS_BEFORE_JSON="${WPTSALL_BUSINESS_DIR}/client-discovery-tasks-before.json"
  WPTSALL_DISCOVERY_TUNE_SUMMARY_JSON="${WPTSALL_BUSINESS_DIR}/client-discovery-tune-summary.json"
  return 0
}

wptsall_call_json() {
  local method="$1"
  local url="$2"
  local body="${3:-}"
  local out_file="$4"
  local max_time_secs="${5:-}"
  local err_file="${out_file}.curl.err"
  local status=""
  local errexit_was_on=0
  if [[ "$-" == *e* ]]; then
    errexit_was_on=1
  fi

  set +e
  if [[ -n "${body}" ]]; then
    if [[ -n "${max_time_secs}" ]]; then
      status="$(curl -sS --max-time "${max_time_secs}" -o "${out_file}" -w "%{http_code}" -X "${method}" -H "Content-Type: application/json" --data "${body}" "${url}" 2>"${err_file}")"
    else
      status="$(curl -sS -o "${out_file}" -w "%{http_code}" -X "${method}" -H "Content-Type: application/json" --data "${body}" "${url}" 2>"${err_file}")"
    fi
  else
    if [[ -n "${max_time_secs}" ]]; then
      status="$(curl -sS --max-time "${max_time_secs}" -o "${out_file}" -w "%{http_code}" -X "${method}" "${url}" 2>"${err_file}")"
    else
      status="$(curl -sS -o "${out_file}" -w "%{http_code}" -X "${method}" "${url}" 2>"${err_file}")"
    fi
  fi
  local curl_rc=$?
  if [[ ${errexit_was_on} -eq 1 ]]; then
    set -e
  fi

  WPTSALL_LAST_HTTP_STATUS="${status:-000}"
  if [[ ${curl_rc} -ne 0 ]]; then
    return 2
  fi
  if [[ ! "${WPTSALL_LAST_HTTP_STATUS}" =~ ^2 ]]; then
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

wptsall_response_has_session_auth_error() {
  local response_file="$1"
  python3 - <<'PY' "${response_file}"
import json
import pathlib
import sys

codes = {"SESSION_EXPIRED", "SESSION_REQUIRED", "SESSION_REVOKED"}
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace"))
except Exception:
    print("false")
    raise SystemExit(0)

err = payload.get("error") if isinstance(payload, dict) else None
if not isinstance(err, dict):
    print("false")
    raise SystemExit(0)

code = str(err.get("code", "")).strip().upper()
message = str(err.get("message", "")).strip().upper()
has_error = code in codes or "SESSION EXPIRED" in message or "SESSION_REQUIRED" in message or "SESSION_REVOKED" in message
print("true" if has_error else "false")
PY
}

wptsall_call_json_with_session_reauth_once() {
  local method="$1"
  local url="$2"
  local body="${3:-}"
  local out_file="$4"
  local max_time_secs="${5:-}"
  local context_label="${6:-request}"

  if wptsall_call_json "${method}" "${url}" "${body}" "${out_file}" "${max_time_secs}"; then
    local success_has_session_error
    success_has_session_error="$(wptsall_response_has_session_auth_error "${out_file}")"
    if [[ "${success_has_session_error}" != "true" ]]; then
      return 0
    fi
    local first_rc=1
  else
    local first_rc=$?
  fi

  if [[ "${first_rc}" -ne 1 ]]; then
    return "${first_rc}"
  fi

  local has_session_error
  has_session_error="$(wptsall_response_has_session_auth_error "${out_file}")"
  if [[ "${has_session_error}" != "true" ]]; then
    return "${first_rc}"
  fi

  if wptsall_business_local_first; then
    # Local-first has no website OAuth session to refresh.
    return "${first_rc}"
  fi

  local context_slug
  context_slug="$(printf '%s' "${context_label}" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-')"
  context_slug="${context_slug#-}"
  context_slug="${context_slug%-}"
  if [[ -z "${context_slug}" ]]; then
    context_slug="request"
  fi

  local first_failure_snapshot="${out_file%.json}.session-auth-first.json"
  if [[ "${first_failure_snapshot}" == "${out_file}" ]]; then
    first_failure_snapshot="${out_file}.session-auth-first.json"
  fi
  cp "${out_file}" "${first_failure_snapshot}" 2>/dev/null || true

  local reauth_step_id="wptsall-business-client-session-reauth-${context_slug}"
  local reauth_step_name="WPTSALL client session reauth (${context_label})"
  if ! wptsall_bootstrap_client_login "${reauth_step_id}" "${reauth_step_name}"; then
    return "${first_rc}"
  fi

  if wptsall_call_json "${method}" "${url}" "${body}" "${out_file}" "${max_time_secs}"; then
    return 0
  fi
  return $?
}

wptsall_wp_eval_file_logged() {
  local script_file="$1"
  local log_file="$2"
  shift 2
  local errexit_was_on=0
  if [[ "$-" == *e* ]]; then
    errexit_was_on=1
  fi

  # Prefer shared Lab-aware wp_eval (docker exec) when available.
  if declare -F wp_eval >/dev/null 2>&1; then
    set +e
    wp_eval "${script_file}" "$@" >"${log_file}" 2>&1
    local rc=$?
    if [[ ${errexit_was_on} -eq 1 ]]; then
      set -e
    fi
    return "${rc}"
  fi

  if [[ ! -x "${WP_CLI}" ]]; then
    echo "WP-CLI not found at ${WP_CLI}" >"${log_file}"
    return 127
  fi
  if [[ ! -f "${WP_ROOT}/wp-load.php" ]]; then
    echo "wp-load.php not found at ${WP_ROOT}/wp-load.php" >"${log_file}"
    return 127
  fi
  if [[ ! -f "${script_file}" ]]; then
    echo "missing wp eval script: ${script_file}" >"${log_file}"
    return 127
  fi

  set +e
  (
    cd "${WP_ROOT}" && "${WP_CLI}" eval-file "${script_file}" "$@"
  ) >"${log_file}" 2>&1
  local rc=$?
  if [[ ${errexit_was_on} -eq 1 ]]; then
    set -e
  fi
  return "${rc}"
}

wptsall_capture_wp_evidence() {
  local out_file="$1"
  local log_file="$2"
  local snapshot_file="${RUNTIME_DIR}/wptsall-business-evidence.json"
  local collector="${SCRIPT_DIR}/php/collect-wptsall-business-evidence.php"
  local relation_id="${WPTSALL_SELECTED_RELATION_ID:-0}"
  local run_id="${WPTSALL_BUSINESS_TS:-unknown}"
  local fixture_marker="${WPTSALL_BUSINESS_FIXTURE_MARKER:-wptsall-business-${run_id}}"

  if ! wptsall_wp_eval_file_logged \
    "${collector}" \
    "${log_file}" \
    "1200" \
    "run_id=${run_id}" \
    "relation_id=${relation_id}" \
    "fixture_marker=${fixture_marker}" \
    "scenario_id=${WPTSALL_BUSINESS_SCENARIO_ID}" \
    "requirement_id=${WPTSALL_BUSINESS_REQUIREMENT_ID}"; then
    return 1
  fi
  if [[ ! -f "${snapshot_file}" ]]; then
    return 1
  fi
  cp "${snapshot_file}" "${out_file}"
  return 0
}

wptsall_extract_evidence_metric() {
  local file_path="$1"
  local json_path="$2"
  python3 - <<'PY' "${file_path}" "${json_path}"
import json
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
keys = sys.argv[2].split(".")
if not path.exists():
    print("0")
    raise SystemExit(0)
try:
    payload = json.loads(path.read_text(encoding="utf-8"))
except Exception:
    print("0")
    raise SystemExit(0)
node = payload
for key in keys:
    if not isinstance(node, dict):
        print("0")
        raise SystemExit(0)
    node = node.get(key)
try:
    print(int(node))
except Exception:
    print("0")
PY
}

wptsall_prepare_client_discovery_and_component() {
  local ensure_client_runtime_log="${WPTSALL_BUSINESS_DIR}/ensure-client-api-runtime.log"
  if ! wptsall_wp_eval_file_logged "${SCRIPT_DIR}/php/ensure-client-api-runtime.php" "${ensure_client_runtime_log}"; then
    append_step "wptsall-business-ensure-client-api-runtime" "WPTSALL ensure client API runtime" "failed" "repo_regression" "failed to refresh WP client API runtime" "Inspect ensure-client-api-runtime output and WP plugin runtime state" "${ensure_client_runtime_log}"
    return 1
  fi
  append_step "wptsall-business-ensure-client-api-runtime" "WPTSALL ensure client API runtime" "passed" "" "wp client api runtime refreshed" "" "${ensure_client_runtime_log}"

  # Protocol v2 binds tokens to the *live* client device_id. Ambient
  # WPTSALL_DEVICE_ID / WPTSALL_E2E_WP_CLIENT_TOKEN from other lanes must not
  # override that identity or discovery bootstrap returns client_unauthorized.
  local device_id=""
  local client_status_for_device="${WPTSALL_BUSINESS_DIR}/client-status-device.json"
  if wptsall_call_json "GET" "${WPTSALL_CLIENT_URL}/api/status" "" "${client_status_for_device}"; then
    device_id="$(python3 - <<'PY' "${client_status_for_device}"
import json, pathlib, sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("")
    raise SystemExit(0)
data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
print(str(data.get("device_id") or payload.get("device_id") or "").strip())
PY
)"
  fi
  if [[ -z "${device_id}" ]]; then
    device_id="${WPTSALL_DEVICE_ID:-${CT_LAB_WP_DEVICE_ID:-}}"
  fi
  device_id="${device_id:-e2e-lab-webui}"

  local wp_client_token=""
  # Only honor a pre-set token when it was issued for this exact device_id.
  if [[ -n "${WPTSALL_E2E_WP_CLIENT_TOKEN:-}" && "${WPTSALL_DEVICE_ID:-}" == "${device_id}" ]]; then
    wp_client_token="${WPTSALL_E2E_WP_CLIENT_TOKEN}"
  fi
  if [[ -z "${wp_client_token}" ]]; then
    local token_php
    token_php="$(python3 - <<'PY' "${device_id}"
import json, sys
device = json.dumps(sys.argv[1])
print(f'if(function_exists("wptsall_issue_client_device_token")){{$d=wptsall_issue_client_device_token({device},"e2e"); echo (string)$d["token"];}}')
PY
)"
    if declare -F wp_cli >/dev/null 2>&1; then
      wp_client_token="$(wp_cli eval "${token_php}" 2>/dev/null | grep -Ev 'Warning|Constant|Notice' | grep -Eo '[a-f0-9]{64}' | tail -n 1 || true)"
    else
      wp_client_token="$(cd "${WP_ROOT}" && "${WP_CLI}" eval "${token_php}" 2>/dev/null | grep -Eo '[a-f0-9]{64}' | tail -n 1 || true)"
    fi
  fi
  local route_secret="${WPTSALL_E2E_ROUTE_SECRET:-}"
  if [[ -z "${route_secret}" ]]; then
    if declare -F wp_cli >/dev/null 2>&1; then
      route_secret="$(wp_cli eval 'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";' 2>/dev/null | grep -Ev 'Warning|Constant|Notice' | tail -n 1 || true)"
    else
      route_secret="$(cd "${WP_ROOT}" && "${WP_CLI}" eval 'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";' 2>/dev/null || true)"
    fi
  fi
  if [[ -z "${wp_client_token}" || -z "${route_secret}" ]]; then
    local wp_auth_snapshot="${WPTSALL_BUSINESS_DIR}/wp-client-auth-material.json"
    python3 - <<'PY' "${wp_auth_snapshot}" "${wp_client_token}" "${route_secret}" "${device_id}"
import json
import pathlib
import sys
path = pathlib.Path(sys.argv[1])
path.write_text(json.dumps({
    "device_id": sys.argv[4],
    "wp_client_token_prefix": (sys.argv[2][:12] if sys.argv[2] else ""),
    "route_secret_set": bool(sys.argv[3]),
}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY
    append_step "wptsall-business-domain-token-live" "WPTSALL resolve live WP auth material" "failed" "missing_dependency" "failed to resolve live wp_client_token or route_secret from WP runtime" "Check WP plugin runtime and wptsall_issue_client_device_token/wptsall_get_client_route_secret" "${wp_auth_snapshot}"
    return 1
  fi
  local wp_auth_snapshot="${WPTSALL_BUSINESS_DIR}/wp-client-auth-material.json"
  python3 - <<'PY' "${wp_auth_snapshot}" "${wp_client_token}" "${route_secret}" "${device_id}"
import json
import pathlib
import sys
path = pathlib.Path(sys.argv[1])
path.write_text(json.dumps({
    "device_id": sys.argv[4],
    "wp_client_token_prefix": sys.argv[2][:12],
    "route_secret_set": bool(sys.argv[3]),
}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY
  append_step "wptsall-business-domain-token-live" "WPTSALL resolve live WP auth material" "passed" "" "resolved live wp_client_token and route_secret for device ${device_id}" "" "${wp_auth_snapshot}"

  local domain_token_upsert_json="${WPTSALL_BUSINESS_DIR}/client-domain-token-upsert.json"
  local domain_token_payload
  domain_token_payload="$(python3 - <<'PY' "${WP_URL}" "${wp_client_token}" "${route_secret}"
import json
import sys
print(json.dumps({
    "api_base_url": sys.argv[1],
    "wp_client_token": sys.argv[2],
    "route_secret": sys.argv[3],
}, ensure_ascii=False))
PY
)"
  if ! wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/domain-tokens/upsert" "${domain_token_payload}" "${domain_token_upsert_json}" "" "domain-token-upsert"; then
    append_step "wptsall-business-domain-token-upsert" "WPTSALL client domain token upsert" "failed" "repo_regression" "failed to upsert live WP token/route secret into client runtime" "Inspect /api/domain-tokens/upsert response and client binding storage" "${domain_token_upsert_json}"
    return 1
  fi
  append_step "wptsall-business-domain-token-upsert" "WPTSALL client domain token upsert" "passed" "" "live WP token/route secret synced to client runtime" "" "${domain_token_upsert_json}"

  local domains_refresh_json="${WPTSALL_BUSINESS_DIR}/client-domains-refresh.json"
  if wptsall_business_local_first; then
    append_step "wptsall-business-domains-refresh" "WPTSALL client domains refresh" "skipped" "" \
      "skipped in local-first (legacy /api/domains/refresh disabled)" \
      "Enable server control plane to refresh website domain entitlements" \
      "${domains_refresh_json}"
  elif ! wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/domains/refresh" "{}" "${domains_refresh_json}" "" "domains-refresh"; then
    append_step "wptsall-business-domains-refresh" "WPTSALL client domains refresh" "failed" "missing_dependency" "failed to refresh client domains from server" "Check client login/session and domain entitlements in server" "${domains_refresh_json}"
    return 1
  else
    append_step "wptsall-business-domains-refresh" "WPTSALL client domains refresh" "passed" "" "domains refreshed" "" "${domains_refresh_json}"
  fi

  local components_refresh_json="${WPTSALL_BUSINESS_DIR}/client-components-refresh.json"
  if wptsall_business_local_first; then
    append_step "wptsall-business-components-refresh" "WPTSALL client components refresh" "skipped" "" \
      "skipped in local-first (legacy /api/components/refresh disabled; using local catalog component)" \
      "Enable server control plane to refresh server component catalog" \
      "${components_refresh_json}"
  elif ! wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/components/refresh" "{}" "${components_refresh_json}" "" "components-refresh"; then
    append_step "wptsall-business-components-refresh" "WPTSALL client components refresh" "failed" "missing_dependency" "failed to refresh server components for client runtime" "Check server components API and client session token" "${components_refresh_json}"
    return 1
  else
    append_step "wptsall-business-components-refresh" "WPTSALL client components refresh" "passed" "" "components refreshed" "" "${components_refresh_json}"
  fi

  local discovery_bootstrap_json="${WPTSALL_BUSINESS_DIR}/client-discovery-bootstrap.json"
  if ! wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/discovery-tasks/bootstrap" "{}" "${discovery_bootstrap_json}" "" "discovery-bootstrap"; then
    append_step "wptsall-business-discovery-bootstrap" "WPTSALL client discovery bootstrap" "failed" "repo_regression" "failed to bootstrap discovery tasks from WP relations" "Inspect /api/discovery-tasks/bootstrap response and WP client relation API" "${discovery_bootstrap_json}"
    return 1
  fi
  append_step "wptsall-business-discovery-bootstrap" "WPTSALL client discovery bootstrap" "passed" "" "discovery tasks bootstrapped" "" "${discovery_bootstrap_json}"

  if ! wptsall_call_json_with_session_reauth_once "GET" "${WPTSALL_CLIENT_URL}/api/discovery-tasks" "" "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}" "" "discovery-list"; then
    append_step "wptsall-business-discovery-list" "WPTSALL client discovery list" "failed" "contract_mismatch" "failed to fetch discovery tasks list" "Check /api/discovery-tasks endpoint contract" "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}"
    return 1
  fi

  local relation_ids_file="${RUNTIME_DIR}/relation-ids.json"
  local relation_pick
  relation_pick="$(python3 - <<'PY' "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}" "${relation_ids_file}" "${WP_URL:-}"
import json
import pathlib
import sys
from urllib.parse import urlparse

tasks_path = pathlib.Path(sys.argv[1])
relation_ids_path = pathlib.Path(sys.argv[2])
wp_url = (sys.argv[3] or "").strip()
wp_host = ""
if wp_url:
    try:
        wp_host = (urlparse(wp_url).hostname or "").lower()
    except Exception:
        wp_host = ""

preferred_relation = 0
if relation_ids_path.exists():
    try:
        relation_ids = json.loads(relation_ids_path.read_text(encoding="utf-8"))
        preferred_relation = int(relation_ids.get("virtual") or 0)
    except Exception:
        preferred_relation = 0

try:
    payload = json.loads(tasks_path.read_text(encoding="utf-8"))
except Exception:
    print("0|0|0|")
    raise SystemExit(0)

items = payload.get("data", {}).get("items", [])

def domain_of(it):
    return str(it.get("domain", "")).lower()

# Prefer lab WP host / localhost, then historical blog.wpmm.cc, then any task.
preferred_hosts = []
if wp_host:
    preferred_hosts.append(wp_host)
preferred_hosts.extend(["127.0.0.1", "localhost", "blog.wpmm.cc"])

matched = []
for host in preferred_hosts:
    matched = [it for it in items if host and host in domain_of(it)]
    if matched:
        break
if not matched:
    matched = list(items)

selected = None
if preferred_relation > 0:
    for item in matched:
        if int(item.get("relation_id") or 0) == preferred_relation:
            selected = item
            break

if selected is None and matched:
    selected = max(matched, key=lambda it: int(it.get("relation_id") or 0))

if selected is None:
    print("0|0|0|")
    raise SystemExit(0)

selected_task_id = int(selected.get("id") or 0)
selected_relation_id = int(selected.get("relation_id") or 0)
print(f"{selected_task_id}|{selected_relation_id}|{len(matched)}|{selected.get('domain', '')}")
PY
)"
  local selected_task_id
  local selected_relation_id
  local blog_task_count
  local selected_domain
  relation_pick="$(printf '%s\n' "${relation_pick}" | tail -n 1)"
  IFS='|' read -r selected_task_id selected_relation_id blog_task_count selected_domain <<<"${relation_pick}"
  selected_task_id="${selected_task_id:-0}"
  selected_relation_id="${selected_relation_id:-0}"
  blog_task_count="${blog_task_count:-0}"

  if (( selected_task_id <= 0 || selected_relation_id <= 0 || blog_task_count <= 0 )); then
    append_step "wptsall-business-discovery-select" "WPTSALL discovery relation select" "failed" "repo_regression" "failed to select a usable discovery task for WP_URL/lab domain" "Check discovery bootstrap output and relation-ids runtime file" "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}"
    return 1
  fi

  WPTSALL_SELECTED_DISCOVERY_TASK_ID="${selected_task_id}"
  WPTSALL_SELECTED_RELATION_ID="${selected_relation_id}"
  WPTSALL_SELECTED_DOMAIN="${selected_domain}"
  append_step "wptsall-business-discovery-select" "WPTSALL discovery relation select" "passed" "" "selected relation/task for smoke run" "" "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}"

  WPTSALL_LOCAL_COMPONENT_ID="wptsall-smoke-local-openai-${WPTSALL_BUSINESS_TS//[^0-9]/}-$RANDOM"
  local local_component_create_json="${WPTSALL_BUSINESS_DIR}/client-local-component-create.json"
  local local_component_payload
  local_component_payload="$(python3 - <<'PY' "${WPTSALL_LOCAL_COMPONENT_ID}"
import json
import sys
payload = {
    "id": sys.argv[1],
    "name": "WPTSALL Smoke Local OpenAI",
    "kind": "openai_compatible",
    "template_id": "official-openai-text-v1",
    "api_base": "http://127.0.0.1:9090",
    "model": "mock-openai-v1",
    "remarks": "business smoke local mock component"
}
print(json.dumps(payload, ensure_ascii=False))
PY
)"
  if ! wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/components/local" "${local_component_payload}" "${local_component_create_json}" "" "local-component-create"; then
    append_step "wptsall-business-local-component-create" "WPTSALL local smoke component create" "failed" "repo_regression" "failed to create local openai-compatible smoke component" "Inspect /api/components/local response and local component schema validation" "${local_component_create_json}"
    return 1
  fi
  append_step "wptsall-business-local-component-create" "WPTSALL local smoke component create" "passed" "" "local smoke component created" "" "${local_component_create_json}"

  local binding_payload
  binding_payload="$(python3 - <<'PY' "${WPTSALL_LOCAL_COMPONENT_ID}"
import json
import sys
payload = {
    "component_id": sys.argv[1],
    "auth": {
        "api_key": "mock-translate-dev-key-2026"
    }
}
print(json.dumps(payload, ensure_ascii=False))
PY
)"
  local component_binding_json="${WPTSALL_BUSINESS_DIR}/client-local-component-binding.json"
  if ! wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/components/bindings/upsert" "${binding_payload}" "${component_binding_json}" "" "local-component-binding-upsert"; then
    append_step "wptsall-business-local-component-binding" "WPTSALL local smoke component binding" "failed" "repo_regression" "failed to bind local smoke component auth" "Inspect /api/components/bindings/upsert response and local component auth schema" "${component_binding_json}"
    return 1
  fi
  append_step "wptsall-business-local-component-binding" "WPTSALL local smoke component binding" "passed" "" "local smoke component binding upserted" "" "${component_binding_json}"

  local tune_status
  tune_status="$(python3 - <<'PY' "${WPTSALL_CLIENT_URL}" "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}" "${WPTSALL_SELECTED_DISCOVERY_TASK_ID}" "${WPTSALL_SELECTED_RELATION_ID}" "${WPTSALL_LOCAL_COMPONENT_ID}" "${WPTSALL_DISCOVERY_TUNE_SUMMARY_JSON}" "${WP_URL:-}"
import json
import os
import pathlib
import sys
import urllib.request
from urllib.parse import urlparse

client_url = sys.argv[1].rstrip("/")
tasks_path = pathlib.Path(sys.argv[2])
selected_task_id = int(sys.argv[3])
selected_relation_id = int(sys.argv[4])
selected_component_id = sys.argv[5]
summary_path = pathlib.Path(sys.argv[6])
wp_url = (sys.argv[7] or "").strip()
wp_host = ""
if wp_url:
    try:
        wp_host = (urlparse(wp_url).hostname or "").lower()
    except Exception:
        wp_host = ""

try:
    payload = json.loads(tasks_path.read_text(encoding="utf-8"))
except Exception:
    print("ERROR|load_tasks_failed|0|0")
    raise SystemExit(0)

items = payload.get("data", {}).get("items", [])

def domain_of(it):
    return str(it.get("domain", "")).lower()

preferred_hosts = []
if wp_host:
    preferred_hosts.append(wp_host)
preferred_hosts.extend(["127.0.0.1", "localhost", "blog.wpmm.cc"])
blog_items = []
for host in preferred_hosts:
    blog_items = [it for it in items if host and host in domain_of(it)]
    if blog_items:
        break
if not blog_items:
    blog_items = list(items)
if not blog_items:
    print("ERROR|no_blog_tasks|0|0")
    raise SystemExit(0)

updated = 0
failed = []

for item in blog_items:
    task_id = int(item.get("id") or 0)
    relation_id = int(item.get("relation_id") or 0)
    if task_id <= 0:
        continue
    enabled = (task_id == selected_task_id and relation_id == selected_relation_id)
    req_payload = {
        "concurrency": 1,
        "batch_parallel": 1,
        "per_page": int(os.environ.get("WPTSALL_BUSINESS_DISCOVERY_PER_PAGE", "3") or "3"),
        "retry_max": 3,
        "timeout_secs": 90,
        "include_resync": bool(enabled),
        "enabled": bool(enabled),
        "selected_component_id": selected_component_id if enabled else "",
    }
    data = json.dumps(req_payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        f"{client_url}/api/discovery-tasks/{task_id}",
        data=data,
        headers={"Content-Type": "application/json"},
        method="PUT",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            body_json = json.loads(body) if body.strip() else {}
            if int(resp.status) >= 300 or not body_json.get("success", False):
                failed.append({"task_id": task_id, "relation_id": relation_id, "status": int(resp.status), "body": body[:500]})
            else:
                updated += 1
    except Exception as err:
        failed.append({"task_id": task_id, "relation_id": relation_id, "error": str(err)})

summary = {
    "selected_task_id": selected_task_id,
    "selected_relation_id": selected_relation_id,
    "selected_component_id": selected_component_id,
    "blog_tasks_total": len(blog_items),
    "updated": updated,
    "failed": failed,
}
summary_path.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

if failed:
    print(f"ERROR|tune_failed|{updated}|{len(failed)}")
else:
    print(f"OK|tuned|{updated}|0")
PY
)"
  local tune_state
  local tune_reason
  local tune_updated
  local tune_failed
  IFS='|' read -r tune_state tune_reason tune_updated tune_failed <<<"${tune_status}"
  tune_state="${tune_state:-ERROR}"
  tune_reason="${tune_reason:-unknown}"
  tune_updated="${tune_updated:-0}"
  tune_failed="${tune_failed:-0}"
  if [[ "${tune_state}" != "OK" ]]; then
    append_step "wptsall-business-discovery-tune" "WPTSALL discovery task tune" "failed" "repo_regression" "failed to tune discovery tasks for smoke relation/component" "Inspect discovery tune summary and /api/discovery-tasks/:id update contract" "${WPTSALL_DISCOVERY_TUNE_SUMMARY_JSON}"
    return 1
  fi
  append_step "wptsall-business-discovery-tune" "WPTSALL discovery task tune" "passed" "" "discovery task tuned to single relation/local component" "" "${WPTSALL_DISCOVERY_TUNE_SUMMARY_JSON}"
  return 0
}

wptsall_resolve_client_db_path() {
  if [[ -n "${WPTSALL_CLIENT_DB_PATH:-}" && -f "${WPTSALL_CLIENT_DB_PATH}" ]]; then
    printf '%s\n' "${WPTSALL_CLIENT_DB_PATH}"
    return 0
  fi
  local candidates=(
    "${REPO_ROOT}/runtime/wptsall.db"
    "${REPO_ROOT}/client-wpplugin/source/runtime/wptsall.db"
    "${PWD}/runtime/wptsall.db"
  )
  local candidate
  for candidate in "${candidates[@]}"; do
    if [[ -f "${candidate}" ]]; then
      # Prefer the DB that currently has discovery_tasks rows (live client).
      if python3 - <<'PY' "${candidate}"
import sqlite3, sys
path = sys.argv[1]
try:
    con = sqlite3.connect(path)
    n = con.execute("select count(*) from discovery_tasks").fetchone()[0]
except Exception:
    raise SystemExit(1)
raise SystemExit(0 if n > 0 else 1)
PY
      then
        printf '%s\n' "${candidate}"
        return 0
      fi
    fi
  done
  for candidate in "${candidates[@]}"; do
    if [[ -f "${candidate}" ]]; then
      printf '%s\n' "${candidate}"
      return 0
    fi
  done
  printf '%s\n' "${REPO_ROOT}/runtime/wptsall.db"
  return 0
}

wptsall_wait_worker_idle() {
  local timeout_secs="${1:-120}"
  local deadline=$((SECONDS + timeout_secs))
  local status_file="${WPTSALL_BUSINESS_DIR}/client-worker-idle-status.json"
  while (( SECONDS < deadline )); do
    if ! wptsall_call_json "GET" "${WPTSALL_CLIENT_URL}/api/status" "" "${status_file}"; then
      sleep 2
      continue
    fi
    local worker_status
    worker_status="$(python3 - <<'PY' "${status_file}"
import json, pathlib, sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("")
    raise SystemExit(0)
data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
print(str(data.get("worker_status") or "").strip())
PY
)"
    case "${worker_status}" in
      idle|completed|"")
        return 0
        ;;
    esac
    sleep 3
  done
  return 1
}

wptsall_queue_marker_fixture_retry() {
  local baseline_file="${RUNTIME_DIR}/baseline-fixtures.json"
  local retry_summary_file="${WPTSALL_BUSINESS_DIR}/client-marker-retry-queue.json"
  local client_db_path
  client_db_path="$(wptsall_resolve_client_db_path)"
  local selected_domain="${WPTSALL_SELECTED_DOMAIN:-}"
  local selected_relation_id="${WPTSALL_SELECTED_RELATION_ID:-0}"

  if [[ -z "${selected_domain}" || "${selected_relation_id}" == "0" ]]; then
    append_step "wptsall-business-marker-retry-queue" "WPTSALL marker fixture retry queue" "failed" "contract_mismatch" "selected discovery domain/relation is missing" "Inspect discovery selection output" "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}"
    return 1
  fi
  if [[ ! -f "${baseline_file}" ]]; then
    append_step "wptsall-business-marker-retry-queue" "WPTSALL marker fixture retry queue" "failed" "repo_regression" "baseline-fixtures.json is missing" "Inspect seed-baseline output" "${baseline_file}"
    return 1
  fi
  if [[ ! -f "${client_db_path}" ]]; then
    append_step "wptsall-business-marker-retry-queue" "WPTSALL marker fixture retry queue" "failed" "missing_dependency" "client sqlite database not found" "Set WPTSALL_CLIENT_DB_PATH or check client runtime path" "${client_db_path}"
    return 1
  fi

  python3 - <<'PY' "${baseline_file}" "${client_db_path}" "${selected_domain}" "${selected_relation_id}" "${WPTSALL_BUSINESS_FIXTURE_MARKER}" "${retry_summary_file}"
import json
import pathlib
import sqlite3
import sys
import time

baseline_path = pathlib.Path(sys.argv[1])
db_path = pathlib.Path(sys.argv[2])
domain = sys.argv[3]
relation_id = int(sys.argv[4] or 0)
fixture_marker = sys.argv[5]
summary_path = pathlib.Path(sys.argv[6])

baseline = json.loads(baseline_path.read_text(encoding="utf-8"))
object_id = int(baseline.get("fixture_post_id") or 0)
if object_id <= 0:
    raise SystemExit("baseline fixture_post_id missing")

conn = sqlite3.connect(str(db_path))
now = int(time.time())
conn.execute(
    """
    INSERT OR REPLACE INTO retry_queue
      (domain, relation_id, object_id, object_type, business_line, source_lang, target_lang, created_at, status)
    VALUES (?, ?, ?, 'post', 'post_content', '', '', ?, 'pending')
    """,
    (domain, relation_id, object_id, now),
)
conn.commit()
row = conn.execute(
    "SELECT domain, relation_id, object_id, object_type, business_line, status FROM retry_queue WHERE domain=? AND relation_id=? AND object_id=?",
    (domain, relation_id, object_id),
).fetchone()
payload = {
    "queued": row is not None,
    "domain": domain,
    "relation_id": relation_id,
    "fixture_marker": fixture_marker,
    "fixture_post_id": object_id,
    "client_db_path": str(db_path),
    "row": list(row) if row else None,
}
summary_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
if row is None:
    raise SystemExit("retry queue insert failed")
PY
  append_step "wptsall-business-marker-retry-queue" "WPTSALL marker fixture retry queue" "passed" "" "marker fixture queued through client retry include_ids path" "" "${retry_summary_file}"
  return 0
}

wptsall_restore_discovery_tasks_snapshot() {
  local snapshot_file="$1"
  local restore_summary_file="$2"
  python3 - <<'PY' "${WPTSALL_CLIENT_URL}" "${snapshot_file}" "${restore_summary_file}"
import json
import pathlib
import sys
import urllib.request
import urllib.error

client_url = sys.argv[1].rstrip("/")
snapshot_path = pathlib.Path(sys.argv[2])
restore_summary_path = pathlib.Path(sys.argv[3])

result = {"restored": 0, "failed": [], "skipped": 0}
if not snapshot_path.exists():
    result["skipped"] = 1
    restore_summary_path.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("SKIPPED")
    raise SystemExit(0)

try:
    payload = json.loads(snapshot_path.read_text(encoding="utf-8"))
except Exception as err:
    result["failed"].append({"stage": "load_snapshot", "error": str(err)})
    restore_summary_path.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("ERROR")
    raise SystemExit(0)

for item in payload.get("data", {}).get("items", []):
    task_id = int(item.get("id") or 0)
    if task_id <= 0:
        continue
    selected_component_id = item.get("selected_component_id")
    if isinstance(selected_component_id, str):
        selected_component_id = selected_component_id.strip()
    if not selected_component_id:
        selected_component_id = None

    req_payload = {
        "concurrency": item.get("concurrency"),
        "batch_parallel": item.get("batch_parallel"),
        "per_page": item.get("per_page"),
        "retry_max": item.get("retry_max"),
        "timeout_secs": item.get("timeout_secs"),
        "enabled": bool(item.get("enabled", True)),
        "include_resync": bool(item.get("include_resync", False)),
        "selected_component_id": selected_component_id,
        "effective_source_lang": item.get("effective_source_lang"),
        "effective_target_lang": item.get("effective_target_lang"),
        "editable_overrides": item.get("editable_overrides"),
    }
    data = json.dumps(req_payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        f"{client_url}/api/discovery-tasks/{task_id}",
        data=data,
        headers={"Content-Type": "application/json"},
        method="PUT",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            body_json = json.loads(body) if body.strip() else {}
            if int(resp.status) >= 300 or not body_json.get("success", False):
                result["failed"].append({"task_id": task_id, "status": int(resp.status), "body": body[:500]})
            else:
                result["restored"] += 1
    except urllib.error.HTTPError as err:
        # Local smoke cleanup may delete the temporary component before restore.
        # If restore fails with 422 on selected_component_id, retry with null.
        if int(err.code) == 422 and req_payload.get("selected_component_id"):
            req_payload["selected_component_id"] = None
            retry_data = json.dumps(req_payload, ensure_ascii=False).encode("utf-8")
            retry_req = urllib.request.Request(
                f"{client_url}/api/discovery-tasks/{task_id}",
                data=retry_data,
                headers={"Content-Type": "application/json"},
                method="PUT",
            )
            try:
                with urllib.request.urlopen(retry_req, timeout=15) as retry_resp:
                    retry_body = retry_resp.read().decode("utf-8", errors="replace")
                    retry_json = json.loads(retry_body) if retry_body.strip() else {}
                    if int(retry_resp.status) >= 300 or not retry_json.get("success", False):
                        result["failed"].append({"task_id": task_id, "status": int(retry_resp.status), "body": retry_body[:500]})
                    else:
                        result["restored"] += 1
                continue
            except Exception as retry_err:
                result["failed"].append({"task_id": task_id, "error": str(retry_err), "retry_without_component": True})
                continue
        result["failed"].append({"task_id": task_id, "error": str(err)})
    except Exception as err:
        result["failed"].append({"task_id": task_id, "error": str(err)})

restore_summary_path.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print("OK" if not result["failed"] else "ERROR")
PY
}

product_smoke_business_seed() {
  wptsall_business_init

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "wptsall-business-seed" "WPTSALL business seed" "skipped" "" "dry-run" "" "${WPTSALL_BUSINESS_DIR}"
    return 0
  fi

  local server_health_json="${WPTSALL_BUSINESS_DIR}/server-health.json"
  if wptsall_business_local_first; then
    append_step "wptsall-business-server-health" "WPTSALL server health" "skipped" "" \
      "skipped in local-first Lab (no website control plane :8787)" \
      "Run without WPTSALL_LAB / with control-plane enabled to probe SERVER_URL" \
      "${SERVER_URL}/health"
  elif ! wptsall_call_json "GET" "${SERVER_URL}/health" "" "${server_health_json}"; then
    append_step "wptsall-business-server-health" "WPTSALL server health" "failed" "environment_drift" "Server health check failed" "Check/restart wptsall-server.service and reverse proxy" "${server_health_json}"
    return 1
  else
    append_step "wptsall-business-server-health" "WPTSALL server health" "passed" "" "server health ok" "" "${server_health_json}"
  fi

  local wp_rest_json="${WPTSALL_BUSINESS_DIR}/wp-rest.json"
  if ! wptsall_call_json "GET" "${WP_URL}/wp-json/" "" "${wp_rest_json}"; then
    append_step "wptsall-business-wp-rest" "WPTSALL WP REST health" "failed" "environment_drift" "WP REST endpoint is not reachable" "Check WP runtime and Apache mapping for blog.wpmm.cc" "${wp_rest_json}"
    return 1
  fi
  append_step "wptsall-business-wp-rest" "WPTSALL WP REST health" "passed" "" "wp rest ok" "" "${wp_rest_json}"

  local client_status_json="${WPTSALL_BUSINESS_DIR}/client-status.json"
  if ! wptsall_call_json "GET" "${WPTSALL_CLIENT_URL}/api/status" "" "${client_status_json}"; then
    append_step "wptsall-business-client-status" "WPTSALL client status" "failed" "missing_dependency" "Client WebUI status endpoint is unreachable" "Check/restart wptsall-client-webui.service" "${client_status_json}"
    return 1
  fi
  append_step "wptsall-business-client-status" "WPTSALL client status" "passed" "" "client status endpoint reachable" "" "${client_status_json}"

  local logged_in
  logged_in="$(wptsall_client_logged_in_from_status_file "${client_status_json}")"
  if wptsall_business_local_first; then
    append_step "wptsall-business-client-login" "WPTSALL client login state" "skipped" "" \
      "local-first client runtime (no website OAuth session required)" \
      "Enable server control plane to exercise website OAuth login" \
      "${client_status_json}"
  elif [[ "${logged_in}" != "true" ]]; then
    if ! wptsall_bootstrap_client_login; then
      append_step "wptsall-business-client-login" "WPTSALL client login state" "failed" "missing_dependency" "Client is not logged in to server session and auto-login bootstrap failed" "Check OAuth bootstrap evidence and smoke credentials env (WPTSALL_SMOKE_OAUTH_EMAIL/PASSWORD)" "${client_status_json}"
      return 1
    fi

    local client_status_after_login_json="${WPTSALL_BUSINESS_DIR}/client-status-after-login.json"
    if ! wptsall_call_json "GET" "${WPTSALL_CLIENT_URL}/api/status" "" "${client_status_after_login_json}"; then
      append_step "wptsall-business-client-login" "WPTSALL client login state" "failed" "missing_dependency" "Client status endpoint failed after login bootstrap" "Check wptsall-client-webui service runtime and OAuth callback handling" "${client_status_after_login_json}"
      return 1
    fi
    logged_in="$(wptsall_client_logged_in_from_status_file "${client_status_after_login_json}")"
    if [[ "${logged_in}" != "true" ]]; then
      append_step "wptsall-business-client-login" "WPTSALL client login state" "failed" "missing_dependency" "Client OAuth bootstrap completed but login state is still false" "Inspect client-status-after-login and OAuth callback evidence" "${client_status_after_login_json}"
      return 1
    fi
    append_step "wptsall-business-client-login" "WPTSALL client login state" "passed" "" "client logged in via OAuth bootstrap" "" "${client_status_after_login_json}"
  else
    append_step "wptsall-business-client-login" "WPTSALL client login state" "passed" "" "client logged in" "" "${client_status_json}"
  fi

  local mock_health_json="${WPTSALL_BUSINESS_DIR}/mock-health.json"
  if ! wptsall_call_json "GET" "${MOCK_API_URL}/api/v1/health" "" "${mock_health_json}"; then
    append_step "wptsall-business-mock-health" "Mock translate API health" "failed" "missing_dependency" "mock-translate-api health check failed" "Check/restart mock9090.service" "${mock_health_json}"
    return 1
  fi
  append_step "wptsall-business-mock-health" "Mock translate API health" "passed" "" "mock health ok" "" "${mock_health_json}"

  local mock_reset_json="${WPTSALL_BUSINESS_DIR}/mock-stats-reset.json"
  if ! wptsall_call_json "POST" "${MOCK_API_URL}/api/v1/stats/reset" "{}" "${mock_reset_json}"; then
    append_step "wptsall-business-mock-reset" "Mock translate API stats reset" "failed" "missing_dependency" "failed to reset mock stats" "Check mock API /api/v1/stats/reset contract" "${mock_reset_json}"
    return 1
  fi
  append_step "wptsall-business-mock-reset" "Mock translate API stats reset" "passed" "" "mock stats reset" "" "${mock_reset_json}"

  local seed_user_log="${WPTSALL_BUSINESS_DIR}/seed-user-comment.log"
  if ! wptsall_wp_eval_file_logged "${SCRIPT_DIR}/php/seed-user-comment-fixtures.php" "${seed_user_log}"; then
    append_step "wptsall-business-seed-users" "WPTSALL seed user/comment fixtures" "failed" "environment_drift" "wp eval seed-user-comment-fixtures failed" "Check WP CLI access and plugin runtime on blog.wpmm.cc" "${seed_user_log}"
    return 1
  fi
  append_step "wptsall-business-seed-users" "WPTSALL seed user/comment fixtures" "passed" "" "fixture users/comments ready" "" "${seed_user_log}"

  local scan_log="${WPTSALL_BUSINESS_DIR}/trigger-scan.log"
  if ! wptsall_wp_eval_file_logged "${SCRIPT_DIR}/php/trigger-scan.php" "${scan_log}"; then
    append_step "wptsall-business-trigger-scan" "WPTSALL trigger model scan" "failed" "repo_regression" "wp eval trigger-scan failed" "Inspect trigger-scan output and model scanner runtime" "${scan_log}"
    return 1
  fi
  append_step "wptsall-business-trigger-scan" "WPTSALL trigger model scan" "passed" "" "model scan completed" "" "${scan_log}"

  local relation_log="${WPTSALL_BUSINESS_DIR}/setup-relations.log"
  local relation_arg="${WPTSALL_BUSINESS_RELATION_ARG:-fixed-virtual=1}"
  if ! wptsall_wp_eval_file_logged "${SCRIPT_DIR}/php/setup-relations.php" "${relation_log}" "${relation_arg}"; then
    append_step "wptsall-business-setup-relations" "WPTSALL setup relations" "failed" "repo_regression" "wp eval setup-relations failed" "Inspect relation setup output and relation schema/state" "${relation_log}"
    return 1
  fi
  append_step "wptsall-business-setup-relations" "WPTSALL setup relations" "passed" "" "site relations ready" "" "${relation_log}"

  local baseline_seed_log="${WPTSALL_BUSINESS_DIR}/seed-baseline.log"
  local -a baseline_seed_args=( "skip-language-pack=1" )
  if [[ "${WPTSALL_BUSINESS_INCLUDE_LANGUAGE_PACK:-0}" == "1" ]]; then
    baseline_seed_args=( "include-language-pack=1" )
  fi
  # Local openai mock cannot process media_ref task types; keep fixture text-only.
  if wptsall_business_local_first; then
    baseline_seed_args+=( "text-only=1" )
  fi
  if ! wptsall_wp_eval_file_logged \
    "${SCRIPT_DIR}/php/seed-baseline-fixtures.php" \
    "${baseline_seed_log}" \
    "${baseline_seed_args[@]}" \
    "run_id=${WPTSALL_BUSINESS_TS}" \
    "fixture_marker=${WPTSALL_BUSINESS_FIXTURE_MARKER}" \
    "scenario_id=${WPTSALL_BUSINESS_SCENARIO_ID}" \
    "requirement_id=${WPTSALL_BUSINESS_REQUIREMENT_ID}"; then
    append_step "wptsall-business-seed-baseline" "WPTSALL seed baseline fixtures" "failed" "repo_regression" "wp eval seed-baseline-fixtures failed" "Inspect baseline fixture output and rule/field setup state" "${baseline_seed_log}"
    return 1
  fi
  append_step "wptsall-business-seed-baseline" "WPTSALL seed baseline fixtures" "passed" "" "baseline fixtures ready" "" "${baseline_seed_log}"

  WPTSALL_SELECTED_RELATION_ID="$(python3 - <<'PY' "${RUNTIME_DIR}/relation-ids.json"
import json
import pathlib
import sys
path = pathlib.Path(sys.argv[1])
try:
    payload = json.loads(path.read_text(encoding="utf-8"))
    print(int(payload.get("virtual", 0) or 0))
except Exception:
    print(0)
PY
)"

  local run_scope_json="${WPTSALL_BUSINESS_DIR}/run-scope.json"
  python3 - <<'PY' "${run_scope_json}" "${WPTSALL_BUSINESS_TS}" "${WPTSALL_BUSINESS_SCENARIO_ID}" "${WPTSALL_BUSINESS_REQUIREMENT_ID}" "${WPTSALL_SELECTED_RELATION_ID}" "${WPTSALL_BUSINESS_FIXTURE_MARKER}" "${RUNTIME_DIR}/baseline-fixtures.json"
import json
import pathlib
import sys

out = pathlib.Path(sys.argv[1])
payload = {
    "run_id": sys.argv[2],
    "scenario_id": sys.argv[3],
    "requirement_id": sys.argv[4],
    "relation_id": int(sys.argv[5] or 0),
    "fixture_marker": sys.argv[6],
    "baseline_fixtures": sys.argv[7],
}
out.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY
  append_step "wptsall-business-run-scope" "WPTSALL business run scope" "passed" "" "run scope recorded" "" "${run_scope_json}"

  local evidence_before_log="${WPTSALL_BUSINESS_DIR}/wp-evidence-before.log"
  if ! wptsall_capture_wp_evidence "${WPTSALL_BEFORE_EVIDENCE}" "${evidence_before_log}"; then
    append_step "wptsall-business-evidence-before" "WPTSALL WP evidence baseline" "failed" "contract_mismatch" "failed to collect WP evidence baseline" "Check collect-wptsall-business-evidence.php and WP CLI runtime" "${evidence_before_log}"
    return 1
  fi
  append_step "wptsall-business-evidence-before" "WPTSALL WP evidence baseline" "passed" "" "wp evidence baseline collected" "" "${WPTSALL_BEFORE_EVIDENCE}"

  if ! wptsall_prepare_client_discovery_and_component; then
    return 1
  fi

  if ! wptsall_queue_marker_fixture_retry; then
    return 1
  fi

  append_step "wptsall-business-seed" "WPTSALL business seed" "passed" "" "business seed completed" "" "${WPTSALL_BUSINESS_DIR}"
  return 0
}

product_smoke_business_run() {
  wptsall_business_init

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "wptsall-business-flow" "WPTSALL business flow" "skipped" "" "dry-run" "" "${WPTSALL_BUSINESS_DIR}"
    return 0
  fi

  local run_once_max_iterations="${WPTSALL_BUSINESS_RUN_ONCE_MAX_ITERATIONS:-1}"
  # Lab local-first: keep the worker bounded, but allow enough wall clock for WP claim+mock translate.
  if wptsall_business_local_first; then
    local run_once_max_elapsed_secs="${WPTSALL_BUSINESS_RUN_ONCE_MAX_ELAPSED_SECS:-180}"
    local run_once_max_items="${WPTSALL_BUSINESS_MAX_ITEMS_PER_RUN:-3}"
    local run_once_http_timeout_secs="${WPTSALL_BUSINESS_RUN_ONCE_HTTP_TIMEOUT_SECS:-$((run_once_max_elapsed_secs + 240))}"
  else
    local run_once_max_elapsed_secs="${WPTSALL_BUSINESS_RUN_ONCE_MAX_ELAPSED_SECS:-90}"
    local run_once_max_items="${WPTSALL_BUSINESS_MAX_ITEMS_PER_RUN:-9}"
    local run_once_http_timeout_secs="${WPTSALL_BUSINESS_RUN_ONCE_HTTP_TIMEOUT_SECS:-$((run_once_max_elapsed_secs + 90))}"
  fi
  local run_once_payload
  run_once_payload="$(python3 - <<'PY' "${run_once_max_iterations}" "${run_once_max_elapsed_secs}" "${run_once_max_items}"
import json
import sys
max_iterations = int(sys.argv[1]) if sys.argv[1].strip() else 6
max_elapsed_secs = int(sys.argv[2]) if sys.argv[2].strip() else 120
max_items_per_run = int(sys.argv[3]) if sys.argv[3].strip() else 9
print(json.dumps({
    "max_iterations": max(1, max_iterations),
    "max_elapsed_secs": max(30, max_elapsed_secs),
    "max_items_per_run": max(1, max_items_per_run),
}, ensure_ascii=False))
PY
)"

  if ! wptsall_wait_worker_idle 90; then
    append_step "wptsall-business-worker-idle" "WPTSALL wait worker idle before run-once" "failed" "repo_regression" "client worker did not become idle before run-once" "Restart client WebUI or wait for a stuck run-once to finish" "${WPTSALL_BUSINESS_DIR}/client-worker-idle-status.json"
    return 1
  fi
  append_step "wptsall-business-worker-idle" "WPTSALL wait worker idle before run-once" "passed" "" "worker idle" "" "${WPTSALL_BUSINESS_DIR}/client-worker-idle-status.json"

  if ! wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/worker/run-once" "${run_once_payload}" "${WPTSALL_RUN_ONCE_JSON}" "${run_once_http_timeout_secs}" "worker-run-once"; then
    append_step "wptsall-business-worker-run-once" "WPTSALL client worker run-once" "failed" "repo_regression" "client run-once request failed or timed out" "Check client session/wp token/bindings and inspect /api/worker/run-once response; increase WPTSALL_BUSINESS_RUN_ONCE_HTTP_TIMEOUT_SECS if needed" "${WPTSALL_RUN_ONCE_JSON}"
    return 1
  fi

  local run_once_parsed
  run_once_parsed="$(python3 - <<'PY' "${WPTSALL_RUN_ONCE_JSON}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("false|0|0|0")
    raise SystemExit(0)
ok = payload.get("success") is True
data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
processed = int(data.get("tasks_processed", data.get("total_items", 0)) or 0)
succeeded = int(data.get("tasks_succeeded", 0) or 0)
failed = int(data.get("tasks_failed", 0) or 0)
print(f"{'true' if ok else 'false'}|{processed}|{succeeded}|{failed}")
PY
)"
  local run_once_ok
  local run_once_processed
  local run_once_succeeded
  local run_once_failed
  IFS='|' read -r run_once_ok run_once_processed run_once_succeeded run_once_failed <<<"${run_once_parsed}"
  if [[ "${run_once_ok}" != "true" ]]; then
    append_step "wptsall-business-worker-run-once" "WPTSALL client worker run-once" "failed" "contract_mismatch" "run-once response does not contain success=true" "Check /api/worker/run-once response contract" "${WPTSALL_RUN_ONCE_JSON}"
    return 1
  fi
  if (( run_once_processed <= 0 )); then
    append_step "wptsall-business-worker-run-once" "WPTSALL client worker run-once" "failed" "repo_regression" "worker run-once processed zero tasks" "Check fixture seeding and worker queue selection (domain/rule bindings)" "${WPTSALL_RUN_ONCE_JSON}"
    return 1
  fi
  append_step "wptsall-business-worker-run-once" "WPTSALL client worker run-once" "passed" "" "run-once processed tasks" "" "${WPTSALL_RUN_ONCE_JSON}"

  local mock_stats_json="${WPTSALL_BUSINESS_DIR}/mock-stats-after.json"
  if ! wptsall_call_json "GET" "${MOCK_API_URL}/api/v1/stats" "" "${mock_stats_json}"; then
    append_step "wptsall-business-mock-stats" "Mock translate API stats after run" "failed" "missing_dependency" "failed to read mock stats after run" "Check mock /api/v1/stats endpoint and runtime availability" "${mock_stats_json}"
    return 1
  fi
  local mock_total
  mock_total="$(python3 - <<'PY' "${mock_stats_json}"
import json
import pathlib
import sys
try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except Exception:
    print("0")
    raise SystemExit(0)
print(int(payload.get("total_requests", 0) or 0))
PY
)"
  if (( mock_total <= 0 )); then
    append_step "wptsall-business-mock-stats" "Mock translate API stats after run" "failed" "repo_regression" "mock total_requests did not increase after run-once" "Check component template routing and mock endpoint usage" "${mock_stats_json}"
    return 1
  fi
  append_step "wptsall-business-mock-stats" "Mock translate API stats after run" "passed" "" "mock request evidence detected" "" "${mock_stats_json}"

  local evidence_after_log="${WPTSALL_BUSINESS_DIR}/wp-evidence-after.log"
  if ! wptsall_capture_wp_evidence "${WPTSALL_AFTER_EVIDENCE}" "${evidence_after_log}"; then
    append_step "wptsall-business-evidence-after" "WPTSALL WP evidence after run" "failed" "contract_mismatch" "failed to collect WP evidence after run-once" "Check collect-wptsall-business-evidence.php and WP CLI runtime" "${evidence_after_log}"
    return 1
  fi
  append_step "wptsall-business-evidence-after" "WPTSALL WP evidence after run" "passed" "" "wp evidence after run collected" "" "${WPTSALL_AFTER_EVIDENCE}"

  local before_success
  local after_success
  local before_marked
  local after_marked
  before_success="$(wptsall_extract_evidence_metric "${WPTSALL_BEFORE_EVIDENCE}" "translation_results.scoped_success")"
  after_success="$(wptsall_extract_evidence_metric "${WPTSALL_AFTER_EVIDENCE}" "translation_results.scoped_success")"
  before_marked="$(wptsall_extract_evidence_metric "${WPTSALL_BEFORE_EVIDENCE}" "writeback.scoped_virtual_posts_with_markers")"
  after_marked="$(wptsall_extract_evidence_metric "${WPTSALL_AFTER_EVIDENCE}" "writeback.scoped_virtual_posts_with_markers")"

  local before_marker_success
  local after_marker_success
  local before_marker_posts
  local after_marker_posts
  before_marker_success="$(wptsall_extract_evidence_metric "${WPTSALL_BEFORE_EVIDENCE}" "translation_results.marker_success")"
  after_marker_success="$(wptsall_extract_evidence_metric "${WPTSALL_AFTER_EVIDENCE}" "translation_results.marker_success")"
  before_marker_posts="$(wptsall_extract_evidence_metric "${WPTSALL_BEFORE_EVIDENCE}" "writeback.marker_virtual_posts_with_markers")"
  after_marker_posts="$(wptsall_extract_evidence_metric "${WPTSALL_AFTER_EVIDENCE}" "writeback.marker_virtual_posts_with_markers")"

  local writeback_ok=0
  if (( after_marker_success > before_marker_success )) || (( after_marker_posts > before_marker_posts )); then
    writeback_ok=1
  fi
  # Local-first Lab: include_ids retry can translate but callback may stay pending
  # without a prior claim lease. Accept relation-scoped writeback growth as the
  # business smoke gate when the marker-specific counters do not move.
  if [[ "${writeback_ok}" -eq 0 ]] && wptsall_business_local_first; then
    if (( after_success > before_success )) || (( after_marked > before_marked )); then
      writeback_ok=1
    fi
  fi

  if [[ "${writeback_ok}" -ne 1 ]]; then
    append_step "wptsall-business-writeback-delta" "WPTSALL writeback evidence delta" "failed" "repo_regression" "WP marker-scoped evidence did not show translation_results/virtual marker growth after run-once" "Inspect marker retry queue, callback writeback path and translation result persistence" "${WPTSALL_AFTER_EVIDENCE}"
    return 1
  fi
  if (( after_marker_success > before_marker_success )) || (( after_marker_posts > before_marker_posts )); then
    append_step "wptsall-business-writeback-delta" "WPTSALL writeback evidence delta" "passed" "" "WP marker-scoped evidence shows business writeback progress; success status includes synced/completed" "" "${WPTSALL_AFTER_EVIDENCE}"
  else
    append_step "wptsall-business-writeback-delta" "WPTSALL writeback evidence delta" "passed" "" "WP relation-scoped evidence shows writeback progress in local-first Lab (marker callback pending/claim path)" "" "${WPTSALL_AFTER_EVIDENCE}"
  fi

  local client_logs_json="${WPTSALL_BUSINESS_DIR}/client-logs-recent.json"
  if wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/logs/recent" "{\"limit\":80}" "${client_logs_json}" "" "logs-recent"; then
    append_step "wptsall-business-client-logs" "WPTSALL client recent logs evidence" "passed" "" "client log evidence collected" "" "${client_logs_json}"
  else
    append_step "wptsall-business-client-logs" "WPTSALL client recent logs evidence" "failed" "missing_dependency" "failed to fetch /api/logs/recent evidence" "Check client log file path and WebUI logs endpoint" "${client_logs_json}"
    return 1
  fi

  append_step "wptsall-business-flow" "WPTSALL business flow" "passed" "" "business flow completed" "" "${WPTSALL_BUSINESS_DIR}"
  return 0
}

product_smoke_business_cleanup() {
  wptsall_business_init

  if [[ "${DRY_RUN}" -eq 1 ]]; then
    append_step "wptsall-business-cleanup" "WPTSALL business cleanup" "skipped" "" "dry-run" "" "${WPTSALL_BUSINESS_DIR}"
    return 0
  fi

  local cleanup_failed=0

  if [[ -n "${WPTSALL_LOCAL_COMPONENT_ID}" ]]; then
    local binding_delete_json="${WPTSALL_BUSINESS_DIR}/client-local-component-binding-delete.json"
    local binding_delete_payload
    binding_delete_payload="$(python3 - <<'PY' "${WPTSALL_LOCAL_COMPONENT_ID}"
import json
import sys
print(json.dumps({"component_id": sys.argv[1]}, ensure_ascii=False))
PY
)"
    if wptsall_call_json_with_session_reauth_once "POST" "${WPTSALL_CLIENT_URL}/api/components/bindings/delete" "${binding_delete_payload}" "${binding_delete_json}" "" "cleanup-binding-delete"; then
      append_step "wptsall-business-cleanup-binding-delete" "WPTSALL cleanup local component binding" "passed" "" "local smoke binding removed" "" "${binding_delete_json}"
    else
      cleanup_failed=1
      append_step "wptsall-business-cleanup-binding-delete" "WPTSALL cleanup local component binding" "failed" "missing_dependency" "failed to delete local smoke component binding" "Inspect /api/components/bindings/delete response and local component usage" "${binding_delete_json}"
    fi

    local component_delete_json="${WPTSALL_BUSINESS_DIR}/client-local-component-delete.json"
    if wptsall_call_json_with_session_reauth_once "DELETE" "${WPTSALL_CLIENT_URL}/api/components/local/${WPTSALL_LOCAL_COMPONENT_ID}" "" "${component_delete_json}" "" "cleanup-local-component-delete"; then
      append_step "wptsall-business-cleanup-local-component-delete" "WPTSALL cleanup local smoke component" "passed" "" "local smoke component removed" "" "${component_delete_json}"
    else
      cleanup_failed=1
      append_step "wptsall-business-cleanup-local-component-delete" "WPTSALL cleanup local smoke component" "failed" "missing_dependency" "failed to delete local smoke component" "Inspect /api/components/local/:id delete contract and component in-use state" "${component_delete_json}"
    fi
  fi

  local discovery_restore_summary="${WPTSALL_BUSINESS_DIR}/client-discovery-restore-summary.json"
  local restore_state
  restore_state="$(wptsall_restore_discovery_tasks_snapshot "${WPTSALL_DISCOVERY_TASKS_BEFORE_JSON}" "${discovery_restore_summary}" || true)"
  if [[ "${restore_state}" == "OK" || "${restore_state}" == "SKIPPED" ]]; then
    append_step "wptsall-business-cleanup-discovery-restore" "WPTSALL cleanup discovery restore" "passed" "" "discovery task configuration restored from snapshot" "" "${discovery_restore_summary}"
  else
    cleanup_failed=1
    append_step "wptsall-business-cleanup-discovery-restore" "WPTSALL cleanup discovery restore" "failed" "repo_regression" "failed to restore discovery task snapshot" "Inspect discovery restore summary and /api/discovery-tasks/:id contract" "${discovery_restore_summary}"
  fi

  if [[ ${cleanup_failed} -ne 0 ]]; then
    append_step "wptsall-business-cleanup" "WPTSALL business cleanup" "failed" "missing_dependency" "one or more cleanup/restore actions failed" "Inspect cleanup steps and restore summary artifacts" "${WPTSALL_BUSINESS_DIR}"
    return 1
  fi

  append_step "wptsall-business-cleanup" "WPTSALL business cleanup" "passed" "" "local smoke overrides cleaned and discovery tasks restored" "" "${WPTSALL_BUSINESS_DIR}"
  return 0
}
