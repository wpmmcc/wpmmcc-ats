#!/usr/bin/env bash
# Identity-chain integration gate — Identity Contract v1.1 §5 (T-ID-4..7).
#
# Live, fail-closed verification drills across both plugin identity families:
#   T-ID-4a  wpmmcc binding verifies against a live slot-g container
#            (wpmmcc/v1/{secret}/sync/ping + Protocol v2 signed response).
#   T-ID-4b  wpmmcc_ats binding verifies through the real pairing-pack import
#            flow against the lab WP (wptsall/v2/{secret}/client/ping).
#   T-ID-5   mismatch freeze: stored wpmmcc_ats identity vs live "wpmmcc"
#            responder -> identity_mismatch event, binding frozen, no dispatch.
#   T-ID-6   unknown freeze: live "wpmmcc_pro" identity -> identity_unknown
#            event, identity_verified_at stays null, no dispatch.
#   T-ID-7   v2 -> v3 migration drill: a hand-written version-2 bindings file
#            (pre-identity schema) boot-migrates and verifies live as
#            wpmmcc_ats (migration default) on the first worker pass.
#
# Assertions read the status API's domain_token_bindings identity fields
# (plugin_identity / identity_verified_at — non-credential), the client
# structured event log (identity_mismatch / identity_unknown / identity_stale /
# identity.verify_failed / identity.no_binding_entry), and the mock
# responder's request log (dispatch fail-closed proof).
#
# Isolated lane (like client-content-roundtrip): per-case state roots, a
# dedicated client port (:8996) and a php mock identity responder (:9197).
# NOT part of release-gate / Lab matrix.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-identity-chain-gate.sh
#
# Requires: WPTSALL_LAB=1 lab (WP :9083, slot-g :9187 with wpmmcc active),
# client binary built (client-wpplugin), php-cli on the host.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
export WPTSALL_LAB="${WPTSALL_LAB:-1}"

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

GATE_CLIENT_BASE="${GATE_CLIENT_BASE:-http://127.0.0.1:8996}"
GATE_MOCK_BASE="${GATE_MOCK_BASE:-http://127.0.0.1:9197}"
SLOT_G_CONTAINER="${SLOT_G_CONTAINER:-wptsall-wp-lab-wordpress-slot-g}"
SLOT_G_BASE="${SLOT_G_BASE:-http://127.0.0.1:9187}"
LAB_WP_BASE_LIVE="${LAB_WP_BASE:-http://127.0.0.1:9083}"
REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/identity-chain-gate"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${RUNTIME_DIR}/identity-chain-gate"
MOCK_TOKEN="identity-gate-mock-token-7f3a"
MOCK_SECRET="identity-gate-mock-secret"
STALE_TS="2024-01-01T00:00:00Z"
mkdir -p "${REPORT_ROOT}" "${RUN_DIR}"

GATE_RESULTS_FILE="${RUN_DIR}/results.json"
echo '{"cases":{}}' >"${GATE_RESULTS_FILE}"

port_of() { echo "$1" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|'; }
GATE_CLIENT_PORT="$(port_of "${GATE_CLIENT_BASE}")"

json_field() { python3 -c "import json,sys;d=json.load(sys.stdin);print(d$1)" 2>/dev/null; }

record_result() {
  local case_id="$1" pass="$2" detail="$3"
  python3 - "$GATE_RESULTS_FILE" "$case_id" "$pass" "$detail" <<'PY'
import json, sys
path, case_id, passed, detail = sys.argv[1:5]
with open(path) as fh:
    doc = json.load(fh)
doc["cases"][case_id] = {"pass": passed == "1", "detail": detail}
with open(path, "w") as fh:
    json.dump(doc, fh, indent=2)
PY
}

# ---------------------------------------------------------------------------
# Isolated client (per-case state root so each drill starts from a pristine
# SQLite DB; the boot-time file->DB migration seeds the hand-written binding).
# ---------------------------------------------------------------------------
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
  local device_id="${2:-}"
  local bin
  bin="$(resolve_client_binary)" || abort "wptsall-client binary not found; build client-wpplugin first"
  mkdir -p "${case_dir}/data"
  local -a device_env=()
  if [[ -n "${device_id}" ]]; then
    # Pin a stable device id (run_web_ui honors WPTSALL_DEVICE_ID) so
    # device-scoped tokens can be minted before the single boot.
    device_env=(WPTSALL_DEVICE_ID="${device_id}")
  fi
  nohup env \
    "${device_env[@]}" \
    WPTSALL_WEB_UI=1 \
    WPTSALL_WEB_UI_PORT="${GATE_CLIENT_PORT}" \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${GATE_CLIENT_PORT}" \
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
    if check_url "${GATE_CLIENT_BASE}/api/status"; then return 0; fi
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
# Mock identity responder (php -S): records every request URI (no-dispatch
# proof) and answers with a Protocol v2 signed ping envelope. Identity by
# endpoint family: /wptsall/* -> "wpmmcc" (mismatch fixture), /wpmmcc/* ->
# "wpmmcc_pro" (unknown fixture).
# ---------------------------------------------------------------------------
MOCK_PID=""
start_mock_responder() {
  local router="${RUN_DIR}/mock-identity-router.php"
  cat >"${router}" <<PHP
<?php
\$uri = \$_SERVER['REQUEST_URI'];
file_put_contents(getenv('GATE_HITS_FILE'), \$_SERVER['REQUEST_METHOD'] . ' ' . \$uri . "\n", FILE_APPEND);
\$identity = (strpos(\$uri, '/wptsall/') !== false) ? 'wpmmcc' : 'wpmmcc_pro';
\$body = json_encode([
    'success' => true,
    'data' => [
        'plugin_identity' => \$identity,
        'plugin_version' => '9.9.9-mock',
        'site_platform' => 'wp',
    ],
]);
\$key = hash_hkdf('sha256', getenv('GATE_MOCK_TOKEN'), 32, 'wptsall-signing-v1', 'request-signing');
\$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', \$body, \$key, true)), '+/', '-_'), '=');
header('Content-Type: application/json');
header('X-WPTSALL-Transport: plaintext');
header('X-WPTSALL-Response-Signature: ' . \$sig);
header('Content-Length: ' . strlen(\$body));
echo \$body;
PHP
  GATE_HITS_FILE="${RUN_DIR}/mock-hits.log" GATE_MOCK_TOKEN="${MOCK_TOKEN}" \
    nohup php -S 127.0.0.1:$(port_of "${GATE_MOCK_BASE}") "${router}" \
    >"${RUN_DIR}/mock-identity.log" 2>&1 </dev/null &
  MOCK_PID=$!
  for _ in $(seq 1 20); do
    if check_url "${GATE_MOCK_BASE}/"; then return 0; fi
    sleep 1
  done
  abort "mock identity responder not ready (see ${RUN_DIR}/mock-identity.log)"
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
  # Restore slot-g to its as-found state (no client token provisioned).
  if [[ -n "${SLOT_G_TOKEN_SET:-}" ]]; then
    docker exec "${SLOT_G_CONTAINER}" wp --allow-root --path=/var/www/html \
      option delete wpmmcc_client_token >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Assertion helpers
# ---------------------------------------------------------------------------
binding_status() {
  # Prints the domain_token_bindings status item JSON for a domain base.
  curl -sf "${GATE_CLIENT_BASE}/api/status" | python3 -c "
import json, sys
doc = json.load(sys.stdin)
items = (doc.get('data') or {}).get('domain_token_bindings') or []
want = sys.argv[1]
for item in items:
    if item.get('api_base_url', '').rstrip('/').lower() == want.rstrip('/').lower():
        print(json.dumps(item))
        break
else:
    print('null')
" "$1"
}

assert_binding_identity() {
  local case_id="$1" domain="$2" want_identity="$3" want_verified="$4"
  local item identity verified_at
  item="$(binding_status "${domain}")"
  [[ "${item}" != "null" ]] || { record_result "${case_id}" 0 "binding for ${domain} missing from status"; return 1; }
  identity="$(echo "${item}" | json_field "['plugin_identity']")"
  verified_at="$(echo "${item}" | json_field "['identity_verified_at']")"
  if [[ "${want_identity}" == "null" ]]; then
    [[ "${identity}" == "None" || -z "${identity}" ]] || { info "debug[${case_id}]: identity='${identity}' item=${item}"; record_result "${case_id}" 0 "expected null identity, got ${identity}"; return 1; }
  else
    [[ "${identity}" == "${want_identity}" ]] || { info "debug[${case_id}]: identity='${identity}' want='${want_identity}' item=${item}"; record_result "${case_id}" 0 "expected identity ${want_identity}, got ${identity}"; return 1; }
  fi
  if [[ "${want_verified}" == "set" ]]; then
    [[ -n "${verified_at}" && "${verified_at}" != "None" ]] || { info "debug[${case_id}]: verified_at='${verified_at}' item=${item}"; record_result "${case_id}" 0 "expected identity_verified_at set, got ${verified_at}"; return 1; }
  elif [[ "${want_verified}" == "null" ]]; then
    [[ -z "${verified_at}" || "${verified_at}" == "None" ]] || { info "debug[${case_id}]: verified_at='${verified_at}' item=${item}"; record_result "${case_id}" 0 "expected identity_verified_at null, got ${verified_at}"; return 1; }
  elif [[ "${want_verified}" == "frozen:${STALE_TS}" ]]; then
    [[ "${verified_at}" == "${STALE_TS}" ]] || { info "debug[${case_id}]: verified_at='${verified_at}' want='${STALE_TS}' item=${item}"; record_result "${case_id}" 0 "expected frozen timestamp ${STALE_TS}, got ${verified_at}"; return 1; }
  fi
  return 0
}

# Worker-gate identity events carry the domain in the same JSON line
# (`"api_base_url":"..."`); the discoverer's lane-level guard events carry
# `binding_identity`/`lane` instead (expected for wpmmcc bindings on the
# ATS dispatch lane — C-1 stages identity verification, not wpmmcc dispatch)
# and must NOT trip these assertions. Match event AND domain on ONE line.
identity_event_line_present() {
  local case_dir="$1" code="$2" domain="$3"
  rg "\"event\":\"${code}\"" "${case_dir}/structured.log" 2>/dev/null \
    | rg -q "\"api_base_url\":\"${domain}"
}

wait_for_identity_event() {
  local case_dir="$1" code="$2" domain="$3"
  # The client's structured log flushes asynchronously; poll for up to ~12s
  # so a just-emitted identity event is not missed by a fixed sleep.
  local attempt
  for attempt in $(seq 1 12); do
    if identity_event_line_present "${case_dir}" "${code}" "${domain}"; then
      return 0
    fi
    sleep 1
  done
  return 1
}

log_lacks_identity_event() {
  # Negative assertion (absence of a worker-gate identity failure event for
  # a domain whose run just completed): settle briefly for the async log
  # flush, then a single same-line check.
  local case_dir="$1" code="$2" domain="$3"
  sleep 4
  if identity_event_line_present "${case_dir}" "${code}" "${domain}"; then
    return 1
  fi
  return 0
}

run_worker_once() {
  curl -sf -X POST "${GATE_CLIENT_BASE}/api/worker/run-once" \
    -H 'Content-Type: application/json' \
    -d '{"max_iterations":1,"max_elapsed_secs":60,"max_items_per_run":10}' >/dev/null
}

write_bindings_file() {
  local path="$1" content="$2"
  printf '%s' "${content}" >"${path}"
}

echo "══════════════════════════════════════════════════════"
echo "  IDENTITY-CHAIN GATE (T-ID-4..7, Identity Contract v1.1)"
echo "  ATS=${LAB_WP_BASE_LIVE}  WPMMCC=${SLOT_G_BASE}  CLIENT=${GATE_CLIENT_BASE}  MOCK=${GATE_MOCK_BASE}"
echo "══════════════════════════════════════════════════════"

[[ "$(command -v php)" ]] || abort "php-cli is required for the mock identity responder"
bash "${REPO_ROOT}/tests/docker-lab/scripts/ensure-wp-cli.sh" --container "${SLOT_G_CONTAINER}" >/dev/null 2>&1 || true

# Preflight: wpmmcc active on slot-g.
SLOT_G_PLUGINS="$(docker exec "${SLOT_G_CONTAINER}" wp --allow-root --path=/var/www/html plugin list --status=active --fields=name --format=csv 2>/dev/null | rg -c '^wpmmcc$' || true)"
[[ "${SLOT_G_PLUGINS:-0}" -ge 1 ]] || abort "wpmmcc plugin is not active on ${SLOT_G_CONTAINER}"

start_mock_responder

# ===========================================================================
# T-ID-4a — wpmmcc binding verifies against the live slot-g container
# ===========================================================================
info "T-ID-4a: provisioning wpmmcc client token on slot-g"
SLOT_G_TOKEN="$(openssl rand -hex 32)"
docker exec "${SLOT_G_CONTAINER}" wp --allow-root --path=/var/www/html \
  option update wpmmcc_client_token "${SLOT_G_TOKEN}" >/dev/null
SLOT_G_TOKEN_SET=1
SLOT_G_SECRET="$(docker exec "${SLOT_G_CONTAINER}" wp --allow-root --path=/var/www/html \
  option get wpmmcc_route_secret 2>/dev/null | tr -d '[:space:]')"
[[ -n "${SLOT_G_SECRET}" ]] || abort "slot-g wpmmcc_route_secret is empty"

CASE_DIR="${RUN_DIR}/case-tid4a"
rm -rf "${CASE_DIR}"; mkdir -p "${CASE_DIR}"
write_bindings_file "${CASE_DIR}/domain-token-bindings.json" "$(python3 -c '
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
}))' "${SLOT_G_BASE}" "${SLOT_G_TOKEN}" "${SLOT_G_SECRET}")"
info "T-ID-4a: booting isolated client with wpmmcc binding (unverified)"
start_case_client "${CASE_DIR}"
info "T-ID-4a: running worker pass"
run_worker_once
if assert_binding_identity "T-ID-4a" "${SLOT_G_BASE}" "wpmmcc" "set" \
  && log_lacks_identity_event "${CASE_DIR}" "identity_mismatch" "${SLOT_G_BASE}" \
  && log_lacks_identity_event "${CASE_DIR}" "identity_unknown" "${SLOT_G_BASE}" \
  && log_lacks_identity_event "${CASE_DIR}" "identity_stale" "${SLOT_G_BASE}" \
  && log_lacks_identity_event "${CASE_DIR}" "identity.verify_failed" "${SLOT_G_BASE}"; then
  ok "T-ID-4a PASS: wpmmcc binding verified live on ${SLOT_G_BASE} (identity + timestamp landed)"
  record_result "T-ID-4a" 1 "wpmmcc identity verified against slot-g"
else
  warn "T-ID-4a FAIL (see ${CASE_DIR}/structured.log)"
  record_result "T-ID-4a" 0 "verification did not land; see case log"
fi
stop_case_client

# ===========================================================================
# T-ID-4b — wpmmcc_ats binding verifies via the real pairing-pack import flow
# ===========================================================================
CASE_DIR="${RUN_DIR}/case-tid4b"
rm -rf "${CASE_DIR}"; mkdir -p "${CASE_DIR}"
info "T-ID-4b: booting isolated client for pairing-pack import"
start_case_client "${CASE_DIR}"
GATE_DEVICE_ID="$(curl -sf "${GATE_CLIENT_BASE}/api/status" | json_field "['data']['device_id']")"
[[ -n "${GATE_DEVICE_ID}" ]] || abort "could not read client device_id"
info "T-ID-4b: issuing pairing pack for device ${GATE_DEVICE_ID}"
PACK_RAW="$(wp_cli wptsall security issue_pairing_pack --device-id="${GATE_DEVICE_ID}" --format=json --allow-root 2>/dev/null)"
PACK_JSON="$(echo "${PACK_RAW}" | python3 -c '
import json, sys
raw = sys.stdin.read()
start = raw.find("{")
assert start >= 0, "no JSON object in pairing pack output: %r" % raw[:200]
d = json.JSONDecoder().raw_decode(raw[start:])[0]
assert "pairing_code" in d, d
print(json.dumps(d))')"
PAIRING_CODE="$(echo "${PACK_JSON}" | json_field "['pairing_code']")"
[[ -n "${PAIRING_CODE}" ]] || abort "pairing pack output missing pairing_code: ${PACK_RAW}"
info "T-ID-4b: importing connection pack into client"
IMPORT_BODY="$(python3 -c 'import json,sys; pack=json.loads(sys.argv[1]); print(json.dumps({"site_connection_pack": pack, "pairing_code": pack["pairing_code"], "device_label": "identity-chain-gate"}))' "${PACK_JSON}")"
IMPORT_OUT="$(curl -sf -X POST "${GATE_CLIENT_BASE}/api/site-connections/import" -H 'Content-Type: application/json' -d "${IMPORT_BODY}")"
echo "${IMPORT_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("success") else 1)' \
  || abort "client import failed: ${IMPORT_OUT}"
info "T-ID-4b: running worker pass (verify against live ATS)"
run_worker_once
if assert_binding_identity "T-ID-4b" "${LAB_WP_BASE_LIVE}" "wpmmcc_ats" "set" \
  && log_lacks_identity_event "${CASE_DIR}" "identity_mismatch" "${LAB_WP_BASE_LIVE}" \
  && log_lacks_identity_event "${CASE_DIR}" "identity_unknown" "${LAB_WP_BASE_LIVE}" \
  && log_lacks_identity_event "${CASE_DIR}" "identity.verify_failed" "${LAB_WP_BASE_LIVE}"; then
  ok "T-ID-4b PASS: wpmmcc_ats identity verified through the real pairing flow"
  record_result "T-ID-4b" 1 "wpmmcc_ats identity verified via pairing-pack import"
else
  warn "T-ID-4b FAIL (see ${CASE_DIR}/structured.log)"
  record_result "T-ID-4b" 0 "verification did not land; see case log"
fi
stop_case_client

# ===========================================================================
# T-ID-7 — v2 -> v3 migration drill (pre-identity schema verifies live)
# ===========================================================================
CASE_DIR="${RUN_DIR}/case-tid7"
rm -rf "${CASE_DIR}"; mkdir -p "${CASE_DIR}"
# The boot-time file->DB migration runs once per SQLite DB (json_migration_done
# flag), so the drill must boot exactly ONCE with the v2 file already in place.
# Pin the device id via env (run_web_ui honors WPTSALL_DEVICE_ID) to mint the
# device-scoped token before the single boot.
TID7_DEVICE_ID="$(python3 -c 'import uuid; print(uuid.uuid4())')"
info "T-ID-7: issuing device-scoped token for pinned device ${TID7_DEVICE_ID}"
TID7_TOKEN="$(wp_cli wptsall security issue_device_token --device-id="${TID7_DEVICE_ID}" --label=identity-chain-gate --ttl=1800 --porcelain --allow-root 2>/dev/null | tr -d '[:space:]')"
[[ -n "${TID7_TOKEN}" ]] || abort "issue_device_token produced no token"
TID7_SECRET="$(wp_cli option get wptsall_client_route_secret --allow-root 2>/dev/null | tr -d '[:space:]')"
[[ -n "${TID7_SECRET}" ]] || abort "lab wptsall_client_route_secret is empty"
write_bindings_file "${CASE_DIR}/domain-token-bindings.json" "$(python3 -c '
import json, sys
# Version-2 schema: no identity fields at all (pre-Identity-Contract layout).
print(json.dumps({
    "version": 2,
    "domains": {
        sys.argv[1]: {
            "wp_client_token": sys.argv[2],
            "route_secret": sys.argv[3],
        }
    },
}))' "${LAB_WP_BASE_LIVE}" "${TID7_TOKEN}" "${TID7_SECRET}")"
info "T-ID-7: booting client once with the v2 bindings file (pinned device id)"
start_case_client "${CASE_DIR}" "${TID7_DEVICE_ID}"
info "T-ID-7: running worker pass (v2 load -> default wpmmcc_ats -> live verify)"
run_worker_once
if assert_binding_identity "T-ID-7" "${LAB_WP_BASE_LIVE}" "wpmmcc_ats" "set" \
  && log_lacks_identity_event "${CASE_DIR}" "identity_mismatch" "${LAB_WP_BASE_LIVE}" \
  && log_lacks_identity_event "${CASE_DIR}" "identity_unknown" "${LAB_WP_BASE_LIVE}" \
  && log_lacks_identity_event "${CASE_DIR}" "identity.verify_failed" "${LAB_WP_BASE_LIVE}"; then
  ok "T-ID-7 PASS: v2 binding migrated and verified live as wpmmcc_ats"
  record_result "T-ID-7" 1 "v2 file boot-migrated and verified (default wpmmcc_ats)"
else
  warn "T-ID-7 FAIL (see ${CASE_DIR}/structured.log)"
  record_result "T-ID-7" 0 "migration drill did not verify; see case log"
fi
stop_case_client

# ===========================================================================
# T-ID-5 — mismatch freeze (stored wpmmcc_ats vs live "wpmmcc" responder)
# ===========================================================================
CASE_DIR="${RUN_DIR}/case-tid5"
rm -rf "${CASE_DIR}"; mkdir -p "${CASE_DIR}"
: >"${RUN_DIR}/mock-hits.log"
write_bindings_file "${CASE_DIR}/domain-token-bindings.json" "$(python3 -c '
import json, sys
# Stale-but-set timestamp forces Reverify; a verified_at that is set (not
# null) makes the classify step report identity_mismatch when the live
# identity differs from the stored one.
print(json.dumps({
    "version": 3,
    "domains": {
        sys.argv[1]: {
            "wp_client_token": sys.argv[2],
            "route_secret": sys.argv[3],
            "plugin_identity": "wpmmcc_ats",
            "identity_verified_at": sys.argv[4],
        }
    },
}))' "${GATE_MOCK_BASE}" "${MOCK_TOKEN}" "${MOCK_SECRET}" "${STALE_TS}")"
info "T-ID-5: booting client with a stale wpmmcc_ats binding pointed at the mock"
start_case_client "${CASE_DIR}"
info "T-ID-5: running worker pass (expect mismatch freeze)"
run_worker_once
if wait_for_identity_event "${CASE_DIR}" "identity_mismatch" "${GATE_MOCK_BASE}" \
  && assert_binding_identity "T-ID-5" "${GATE_MOCK_BASE}" "wpmmcc_ats" "frozen:${STALE_TS}"; then
  # No-dispatch proof: the mock saw only the verify ping, no lane requests.
  if rg -q "site-relations|/tasks|/content" "${RUN_DIR}/mock-hits.log"; then
    warn "T-ID-5 FAIL: mock received dispatch requests after mismatch"
    record_result "T-ID-5" 0 "identity_mismatch logged but dispatch leaked to the mock"
  else
    ok "T-ID-5 PASS: mismatch rejected, binding frozen (no identity overwrite, no dispatch)"
    record_result "T-ID-5" 1 "identity_mismatch event + frozen binding + no dispatch"
  fi
else
  warn "T-ID-5 FAIL (see ${CASE_DIR}/structured.log)"
  record_result "T-ID-5" 0 "mismatch not surfaced as identity_mismatch; see case log"
fi
stop_case_client

# ===========================================================================
# T-ID-6 — unknown identity freeze (live "wpmmcc_pro" responder)
# ===========================================================================
CASE_DIR="${RUN_DIR}/case-tid6"
rm -rf "${CASE_DIR}"; mkdir -p "${CASE_DIR}"
: >"${RUN_DIR}/mock-hits.log"
write_bindings_file "${CASE_DIR}/domain-token-bindings.json" "$(python3 -c '
import json, sys
# Expected identity wpmmcc -> verify hits the wpmmcc endpoint family on the
# mock, which answers "wpmmcc_pro" (outside the contract enum) -> Unknown.
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
}))' "${GATE_MOCK_BASE}" "${MOCK_TOKEN}" "${MOCK_SECRET}")"
info "T-ID-6: booting client with a wpmmcc binding pointed at the unknown-identity mock"
start_case_client "${CASE_DIR}"
info "T-ID-6: running worker pass (expect unknown freeze)"
run_worker_once
if wait_for_identity_event "${CASE_DIR}" "identity_unknown" "${GATE_MOCK_BASE}" \
  && assert_binding_identity "T-ID-6" "${GATE_MOCK_BASE}" "wpmmcc" "null"; then
  if rg -q "site-relations|/tasks|/content" "${RUN_DIR}/mock-hits.log"; then
    warn "T-ID-6 FAIL: mock received dispatch requests after unknown identity"
    record_result "T-ID-6" 0 "identity_unknown logged but dispatch leaked to the mock"
  else
    ok "T-ID-6 PASS: unknown identity rejected, binding stays unverified, no dispatch"
    record_result "T-ID-6" 1 "identity_unknown event + unverified freeze + no dispatch"
  fi
else
  warn "T-ID-6 FAIL (see ${CASE_DIR}/structured.log)"
  record_result "T-ID-6" 0 "unknown not surfaced as identity_unknown; see case log"
fi
stop_case_client

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
SUMMARY_PASS="$(python3 -c '
import json, sys
doc = json.load(open(sys.argv[1]))
cases = doc["cases"]
print(1 if cases and all(v["pass"] for v in cases.values()) and len(cases) == 5 else 0)' "${GATE_RESULTS_FILE}")"
python3 -c '
import json, sys
doc = json.load(open(sys.argv[1]))
doc["task"] = "identity-chain-gate"
doc["stamp"] = sys.argv[2]
doc["client_base"] = sys.argv[3]
doc["mock_base"] = sys.argv[4]
doc["slot_g_base"] = sys.argv[5]
doc["lab_wp_base"] = sys.argv[6]
with open(sys.argv[1], "w") as fh:
    json.dump(doc, fh, indent=2)' "${GATE_RESULTS_FILE}" "${STAMP}" "${GATE_CLIENT_BASE}" "${GATE_MOCK_BASE}" "${SLOT_G_BASE}" "${LAB_WP_BASE_LIVE}"
cp "${GATE_RESULTS_FILE}" "${REPORT_ROOT}/summary-${STAMP}.json"

echo "──────────────────────────────────────────────────────"
if [[ "${SUMMARY_PASS}" == "1" ]]; then
  ok "IDENTITY-CHAIN GATE PASS (T-ID-4a/4b/5/6/7 all green; report: ${REPORT_ROOT}/summary-${STAMP}.json)"
else
  abort "IDENTITY-CHAIN GATE FAIL (see ${REPORT_ROOT}/summary-${STAMP}.json and ${RUN_DIR}/case-*/structured.log)"
fi
