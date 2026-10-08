#!/usr/bin/env bash
# Identity-chain SECURITY smoke — wire-integrity fail-closed drills.
#
# Security properties under test (Identity Contract v1.1 §2/§3 + client
# Protocol v2 transport):
#   SEC-1  Tampered response signature: the mock signs with the WRONG key.
#          The client must reject the response (transport error), log
#          identity.verify_failed, keep the binding unverified, and never
#          dispatch. A signature check that "passed anyway" would mean the
#          whole signed-plaintext lane is decorative.
#   SEC-2  Invalid client token: the mock answers 401 client_unauthorized.
#          Same fail-closed outcome — no identity is ever learned from an
#          unauthenticated source, no dispatch happens.
#
# Both cases drive the REAL inline web-UI worker (`POST /api/worker/run-once`)
# against a php mock identity responder, mirroring run-identity-chain-gate.sh.
# Isolated lane; NOT part of release-gate / Lab matrix.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-identity-security-smoke.sh
#
# Requires: client binary built (client-wpplugin), php-cli on the host.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
export WPTSALL_LAB="${WPTSALL_LAB:-1}"

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

SEC_CLIENT_BASE="${SEC_CLIENT_BASE:-http://127.0.0.1:8994}"
SEC_MOCK_BASE="${SEC_MOCK_BASE:-http://127.0.0.1:9196}"
REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/identity-security-smoke"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${RUNTIME_DIR}/identity-security-smoke"
SEC_TOKEN="identity-security-token-b2c1"
SEC_SECRET="identity-security-secret"
mkdir -p "${REPORT_ROOT}" "${RUN_DIR}"

SEC_RESULTS_FILE="${RUN_DIR}/results.json"
echo '{"cases":{}}' >"${SEC_RESULTS_FILE}"

port_of() { echo "$1" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|'; }
SEC_CLIENT_PORT="$(port_of "${SEC_CLIENT_BASE}")"
SEC_MOCK_PORT="$(port_of "${SEC_MOCK_BASE}")"

record_result() {
  python3 - "$SEC_RESULTS_FILE" "$1" "$2" "$3" <<'PY'
import json, sys
path, case_id, passed, detail = sys.argv[1:5]
with open(path) as fh:
    doc = json.load(fh)
doc["cases"][case_id] = {"pass": passed == "1", "detail": detail}
with open(path, "w") as fh:
    json.dump(doc, fh, indent=2)
PY
}

resolve_client_binary() {
  local candidates=(
    "${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release-lab/wptsall-client"
  )
  local c
  for c in "${candidates[@]}"; do
    if [[ -x "$c" ]]; then echo "$c"; return 0; fi
  done
  return 1
}

CLIENT_PID=""
start_case_client() {
  local case_dir="$1"
  local bin
  bin="$(resolve_client_binary)" || abort "wptsall-client binary not found; build client-wpplugin first"
  mkdir -p "${case_dir}/data"
  nohup env \
    WPTSALL_WEB_UI=1 \
    WPTSALL_WEB_UI_PORT="${SEC_CLIENT_PORT}" \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${SEC_CLIENT_PORT}" \
    WPTSALL_DATA_DIR="${case_dir}/data" \
    WPTSALL_DB_PATH="${case_dir}/data/wptsall.db" \
    WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${case_dir}/domain-token-bindings.json" \
    WPTSALL_PROVIDER_ALLOWLIST="127.0.0.1,localhost" \
    WPTSALL_LOG_ENABLED=1 \
    WPTSALL_LOG_FILE="${case_dir}/structured.log" \
    WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
    WPTSALL_SERVER_URL= \
    "$bin" >"${case_dir}/client.log" 2>&1 </dev/null &
  CLIENT_PID=$!
  for _ in $(seq 1 60); do
    if check_url "${SEC_CLIENT_BASE}/api/status"; then return 0; fi
    sleep 1
  done
  abort "client not ready in ${case_dir} (see ${case_dir}/client.log)"
}

stop_case_client() {
  if [[ -n "${CLIENT_PID}" ]]; then
    kill "${CLIENT_PID}" 2>/dev/null || true
    wait "${CLIENT_PID}" 2>/dev/null || true
    CLIENT_PID=""
  fi
}

# ---------------------------------------------------------------------------
# Mock identity responder with SECURITY modes (php -S):
#   GATE_MOCK_MODE=sign-with-wrong-key -> valid envelope, signature computed
#     with a DIFFERENT token (simulates a MITM that cannot derive the key).
#   GATE_MOCK_MODE=reject-401 -> 401 client_unauthorized for any request.
# The hit log doubles as the no-dispatch proof (only ping hits allowed).
# ---------------------------------------------------------------------------
MOCK_PID=""
start_mock_responder() {
  local mode="$1"
  local router="${RUN_DIR}/mock-security-router.php"
  cat >"${router}" <<PHP
<?php
\$uri = \$_SERVER['REQUEST_URI'];
file_put_contents(getenv('GATE_HITS_FILE'), \$_SERVER['REQUEST_METHOD'] . ' ' . \$uri . "\n", FILE_APPEND);
\$mode = getenv('GATE_MOCK_MODE');
\$token = getenv('GATE_MOCK_TOKEN');
// Lane readiness probe must stay answerable in every mode.
if (\$uri === '/' || \$uri === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return;
}
if ('reject-401' === \$mode) {
    http_response_code(401);
    header('Content-Type: application/json');
    \$body = json_encode(array('success' => false, 'error' => array('code' => 'client_unauthorized', 'message' => 'Client authentication failed')));
    header('Content-Length: ' . strlen(\$body));
    echo \$body;
    return;
}
\$identity = (strpos(\$uri, '/wptsall/') !== false) ? 'wpmmcc_ats' : 'wpmmcc';
\$body = json_encode([
    'success' => true,
    'data' => [
        'plugin_identity' => \$identity,
        'plugin_version' => '9.9.9-mock',
        'site_platform' => 'wp',
    ],
]);
// Tamper drill: sign with the WRONG shared secret. A MITM without the
// client token cannot derive the HKDF signing key, so its best forgery
// fails exactly like this.
\$signing_token = ('sign-with-wrong-key' === \$mode) ? 'attacker-guessed-token' : \$token;
\$key = hash_hkdf('sha256', \$signing_token, 32, 'wptsall-signing-v1', 'request-signing');
\$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', \$body, \$key, true)), '+/', '-_'), '=');
header('Content-Type: application/json');
header('X-WPTSALL-Transport: plaintext');
header('X-WPTSALL-Response-Signature: ' . \$sig);
header('Content-Length: ' . strlen(\$body));
echo \$body;
PHP
  GATE_HITS_FILE="${RUN_DIR}/mock-hits.log" GATE_MOCK_TOKEN="${SEC_TOKEN}" GATE_MOCK_MODE="${mode}" \
    nohup php -S "127.0.0.1:${SEC_MOCK_PORT}" "${router}" \
    >"${RUN_DIR}/mock-security.log" 2>&1 </dev/null &
  MOCK_PID=$!
  for _ in $(seq 1 20); do
    if check_url "${SEC_MOCK_BASE}/"; then return 0; fi
    sleep 1
  done
  abort "security mock responder not ready (see ${RUN_DIR}/mock-security.log)"
}

stop_mock_responder() {
  if [[ -n "${MOCK_PID}" ]]; then
    kill "${MOCK_PID}" 2>/dev/null || true
    wait "${MOCK_PID}" 2>/dev/null || true
    MOCK_PID=""
  fi
}

cleanup() {
  stop_case_client
  stop_mock_responder
}
trap cleanup EXIT

identity_event_line_present() {
  local case_dir="$1" code="$2" domain="$3"
  rg "\"event\":\"${code}\"" "${case_dir}/structured.log" 2>/dev/null \
    | rg -q "\"api_base_url\":\"${domain}"
}

wait_for_identity_event() {
  local case_dir="$1" code="$2" domain="$3"
  local attempt
  for attempt in $(seq 1 15); do
    if identity_event_line_present "${case_dir}" "${code}" "${domain}"; then
      return 0
    fi
    sleep 1
  done
  return 1
}

binding_identity_fields() {
  curl -sf "${SEC_CLIENT_BASE}/api/status" | python3 -c "
import json, sys
doc = json.load(sys.stdin)
items = (doc.get('data') or {}).get('domain_token_bindings') or []
want = sys.argv[1]
for item in items:
    if item.get('api_base_url', '').rstrip('/').lower() == want.rstrip('/').lower():
        print(json.dumps({'plugin_identity': item.get('plugin_identity'), 'identity_verified_at': item.get('identity_verified_at')}))
        break
else:
    print('null')
" "$1"
}

run_worker_once() {
  curl -sf -X POST "${SEC_CLIENT_BASE}/api/worker/run-once" \
    -H 'Content-Type: application/json' \
    -d '{"max_iterations":1,"max_elapsed_secs":60,"max_items_per_run":10}' >/dev/null
}

run_security_case() {
  local case_id="$1" mode="$2"
  local case_dir="${RUN_DIR}/case-${case_id}"
  rm -rf "${case_dir}"; mkdir -p "${case_dir}"
  : >"${RUN_DIR}/mock-hits.log"

  # Expected identity wpmmcc -> verify pings the wpmmcc endpoint family on
  # the mock; the entry is unverified (identity set, verified_at null) so a
  # REJECTED verify must leave it exactly as seeded.
  python3 -c '
import json, sys
print(json.dumps({
    "version": 3,
    "domains": {
        sys.argv[1]: {
            "wp_client_token": sys.argv[2],
            "route_secret": sys.argv[3],
            "plugin_identity": "wpmmcc",
            "identity_verified_at": None,
        }
    },
}))' "${SEC_MOCK_BASE}" "${SEC_TOKEN}" "${SEC_SECRET}" >"${case_dir}/domain-token-bindings.json"

  info "${case_id}: starting mock in mode ${mode}"
  start_mock_responder "${mode}"
  info "${case_id}: booting isolated client"
  start_case_client "${case_dir}"
  info "${case_id}: running worker pass"
  run_worker_once

  local fields
  fields="$(binding_identity_fields "${SEC_MOCK_BASE}")"
  local identity verified_at
  identity="$(echo "${fields}" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("plugin_identity"))' 2>/dev/null || echo parse-error)"
  verified_at="$(echo "${fields}" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("identity_verified_at"))' 2>/dev/null || echo parse-error)"

  local pass=1 fail_reason=""
  if ! wait_for_identity_event "${case_dir}" "identity.verify_failed" "${SEC_MOCK_BASE}"; then
    pass=0; fail_reason="identity.verify_failed event missing"
  fi
  if [[ "${identity}" != "wpmmcc" || "${verified_at}" != "None" ]]; then
    pass=0; fail_reason="${fail_reason:+${fail_reason}; }binding must stay unverified (identity=${identity} verified_at=${verified_at})"
  fi
  # No-dispatch proof: only the verify ping may reach the mock.
  if rg -q "site-relations|/tasks|/content" "${RUN_DIR}/mock-hits.log" 2>/dev/null; then
    pass=0; fail_reason="${fail_reason:+${fail_reason}; }dispatch leaked to the mock"
  fi

  if [[ "${pass}" == "1" ]]; then
    ok "${case_id} PASS: fail-closed on ${mode} (identity.verify_failed + unverified + no dispatch)"
    record_result "${case_id}" 1 "fail-closed verified for mode ${mode}"
  else
    warn "${case_id} FAIL: ${fail_reason:-unknown} (see ${case_dir}/structured.log)"
    record_result "${case_id}" 0 "${fail_reason:-unknown}"
  fi
  stop_case_client
  stop_mock_responder
}

echo "══════════════════════════════════════════════════════"
echo "  IDENTITY SECURITY SMOKE (wire-integrity fail-closed)"
echo "  CLIENT=${SEC_CLIENT_BASE}  MOCK=${SEC_MOCK_BASE}"
echo "══════════════════════════════════════════════════════"

[[ "$(command -v php)" ]] || abort "php-cli is required for the security mock responder"

run_security_case "SEC-1-tampered-signature" "sign-with-wrong-key"
run_security_case "SEC-2-invalid-token-401" "reject-401"

SUMMARY_PASS="$(python3 -c '
import json, sys
doc = json.load(open(sys.argv[1]))
cases = doc["cases"]
print(1 if cases and all(v["pass"] for v in cases.values()) and len(cases) == 2 else 0)' "${SEC_RESULTS_FILE}")"
python3 -c '
import json, sys
doc = json.load(open(sys.argv[1]))
doc["task"] = "identity-security-smoke"
doc["stamp"] = sys.argv[2]
doc["client_base"] = sys.argv[3]
doc["mock_base"] = sys.argv[4]
with open(sys.argv[1], "w") as fh:
    json.dump(doc, fh, indent=2)' "${SEC_RESULTS_FILE}" "${STAMP}" "${SEC_CLIENT_BASE}" "${SEC_MOCK_BASE}"
cp "${SEC_RESULTS_FILE}" "${REPORT_ROOT}/summary-${STAMP}.json"

echo "──────────────────────────────────────────────────────"
if [[ "${SUMMARY_PASS}" == "1" ]]; then
  ok "IDENTITY SECURITY SMOKE PASS (SEC-1/SEC-2; report: ${REPORT_ROOT}/summary-${STAMP}.json)"
else
  abort "IDENTITY SECURITY SMOKE FAIL (see ${REPORT_ROOT}/summary-${STAMP}.json and ${RUN_DIR}/case-*/structured.log)"
fi
