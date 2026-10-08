#!/usr/bin/env bash
# Independent E2E: S1 lifecycle — Gutenberg fidelity + incremental update +
# taxonomy cascade (doc 16 scenarios 1.1/1.2/1.3), on the local Lab.
#
# PARALLEL design (v2) — uses the machine instead of serialized round-trips:
#   - TWO client agents (A :8992, B :8995), both paired to the WP site, both
#     running DAEMON workers (WPTSALL_POLL_SECONDS=2) → concurrent claiming
#     drains the historical backlog in parallel (this is also S6-style
#     mutual-exclusion evidence: mapping_unique asserts zero duplicates).
#   - Polling uses FAST DB queries against the lab MySQL (outbox pending
#     count / mapping existence) — no docker-exec wp-cli boots in the loop.
#   - The authoritative wp_eval PHP verify runs ONCE per scenario, with
#     fail-fast classification: pending (no mapping yet) keeps waiting,
#     an actual assertion failure aborts immediately with the check list
#     (never burns rounds on a deterministic failure).
#
# NOT part of release-gate (independent lane, like client-content-roundtrip).
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-client-s1-lifecycle.sh
#
# Requires: WPTSALL_LAB=1 lab (WP :9083 + db container), mock :9090, client
# binary built, template repo at ../wptsall-provider-templates (R1 catalog).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
# Lab-only lane: default to lab mode so wp_eval maps into the docker lab
# (WPTSALL_LAB unset would silently take the host-wp branch and fail on
# `cd $WP_ROOT`).
export WPTSALL_LAB="${WPTSALL_LAB:-1}"
source "${SCRIPT_DIR}/config.sh"

BASE_A="${S1_BASE_A:-http://127.0.0.1:8992}"
BASE_B="${S1_BASE_B:-http://127.0.0.1:8995}"
MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}"
COMPONENT_ID="${CLIENT_RT_COMPONENT_ID:-client-s1-openai-mock}"
REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/client-s1-lifecycle"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${RUNTIME_DIR}/client-s1-lifecycle"
ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}"
export WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}"
# R1 clean release: the isolated clients ship zero builtin templates — point
# them at the local template repo catalog (same wiring as the 112 lane).
PROVIDER_TEMPLATES_REPO="${PROVIDER_TEMPLATES_REPO:-${REPO_ROOT}/../wptsall-provider-templates}"
[[ -f "${PROVIDER_TEMPLATES_REPO}/catalog.json" ]] || abort "template repo catalog missing at ${PROVIDER_TEMPLATES_REPO}/catalog.json"
[[ -f "${PROVIDER_TEMPLATES_REPO}/keys/catalog-signing.public.pem" ]] || abort "template repo public key missing"
export WPTSALL_PROVIDER_CATALOG_SOURCE_URL="file://${PROVIDER_TEMPLATES_REPO}/catalog.json"
export WPTSALL_PROVIDER_CATALOG_ALLOW_FILE_SOURCE=1
export WPTSALL_PROVIDER_CATALOG_PUBLIC_KEY_FILE="${PROVIDER_TEMPLATES_REPO}/keys/catalog-signing.public.pem"

mkdir -p "${REPORT_ROOT}" "${RUN_DIR}"

port_of() { echo "$1" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|'; }
json_field() { python3 -c "import json,sys;d=json.load(sys.stdin);print(d$1)" 2>/dev/null; }

# ---- Fast DB helpers (lab MySQL container; no wp-cli boot in hot loops) ----
LAB_DB_CONTAINER="${LAB_DB_CONTAINER:-wptsall-wp-lab-db-1}"
LAB_DB_NAME="${LAB_DB_NAME:-wp_test}"
dbq() {
  # $1 (the SQL) is passed through docker exec -e so it survives quoting;
  # a positional arg to sh -c would be empty because sh -c gets no args.
  docker exec -e WPTSALL_E2E_SQL="$1" "${LAB_DB_CONTAINER}" \
    sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" '"${LAB_DB_NAME}"' -N -e "$WPTSALL_E2E_SQL"' 2>/dev/null
}
outbox_active() {
  # pending + processing rows for the shared WP (all relations of this site).
  dbq "SELECT COUNT(*) FROM wp_wptsall_content_change_outbox WHERE status IN ('pending','processing');" | tr -d '[:space:]'
}
mapping_exists() {
  local src="$1" rel="$2"
  local n
  # target_post_id > 0: claim-placeholder rows (target 0) mean the writeback
  # has NOT landed yet and must not count as "mapped".
  n="$(dbq "SELECT COUNT(*) FROM wp_wptsall_post_mappings WHERE source_post_id=${src} AND relation_id=${rel} AND target_post_id>0;" | tr -d '[:space:]')"
  [[ "${n:-0}" -ge 1 ]]
}

ensure_mock() {
  if check_url "${MOCK_API_BASE}/api/v1/health" || check_url "${MOCK_API_BASE}/health"; then
    ok "mock-api ready at ${MOCK_API_BASE}"
    return 0
  fi
  abort "mock-api not reachable at ${MOCK_API_BASE} (start it first)"
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

# start_client_agent <base> <label> <datadir>
start_client_agent() {
  local base="$1" label="$2" datadir="$3"
  local port; port="$(port_of "${base}")"
  if check_url "${base}/api/status"; then
    ok "agent ${label} already up at ${base}"
    return 0
  fi
  local bin
  bin="$(resolve_client_binary)" || abort "wptsall-client binary not found; build client-wpplugin first"
  info "Starting agent ${label} on :${port} (daemon worker, poll 2s)"
  mkdir -p "${datadir}"
  nohup env \
    WPTSALL_WEB_UI=1 \
    WPTSALL_WEB_UI_PORT="${port}" \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${port}" \
    WPTSALL_DATA_DIR="${datadir}" \
    WPTSALL_POLL_SECONDS=2 \
    WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}" \
    WPTSALL_PROVIDER_CATALOG_SOURCE_URL="${WPTSALL_PROVIDER_CATALOG_SOURCE_URL}" \
    WPTSALL_PROVIDER_CATALOG_ALLOW_FILE_SOURCE=1 \
    WPTSALL_PROVIDER_CATALOG_PUBLIC_KEY_FILE="${WPTSALL_PROVIDER_CATALOG_PUBLIC_KEY_FILE}" \
    WPTSALL_LOG_ENABLED=1 \
    WPTSALL_LOG_FILE="${RUN_DIR}/structured-${label}.log" \
    WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
    WPTSALL_SERVER_URL= \
    "$bin" >"${RUN_DIR}/client-${label}.log" 2>&1 </dev/null &
  AGENT_PIDS+=("$!")
  for _ in $(seq 1 90); do
    if check_url "${base}/api/status"; then ok "agent ${label} ready"; return 0; fi
    sleep 1
  done
  abort "agent ${label} not ready (see ${RUN_DIR}/client-${label}.log)"
}
AGENT_PIDS=()

cleanup() {
  local base label
  for base in "${BASE_A}" "${BASE_B}"; do
    curl -sf -m 5 -X POST "${base}/api/worker/stop" >/dev/null 2>&1 || true
  done
  local pid
  for pid in "${AGENT_PIDS[@]:-}"; do
    [[ -n "${pid}" ]] && kill "${pid}" 2>/dev/null || true
  done
  info "stopped agents (${AGENT_PIDS[*]:-none})"
}
trap cleanup EXIT

echo "══════════════════════════════════════════════════"
echo "  E2E-INDEPENDENT: S1 lifecycle (parallel A/B daemons)"
echo "  WP=${LAB_WP_BASE}  MOCK=${MOCK_API_BASE}  A=${BASE_A}  B=${BASE_B}"
echo "══════════════════════════════════════════════════"

check_url "${LAB_WP_BASE}" || abort "lab WP not reachable at ${LAB_WP_BASE}"
ensure_mock

# S0 guards: pause dev agents, reset mock faults, recycle dead leases
info "S0 guard: pausing dev agents (:8977/:8978) to avoid outbox contention"
for dev_port in 8977 8978; do
  curl -sf -m 2 -X POST "http://127.0.0.1:${dev_port}/api/worker/stop" >/dev/null 2>&1 || true
done
curl -sf -X POST "${MOCK_API_BASE}/api/v1/fault-injection/reset" >/dev/null 2>&1 || true
# Dead-lease recovery, mirroring Content_Change_Dispatcher::claim_outbox()
# defaults (claimed_at column, 900s lease, UTC timestamps). The previous form
# referenced a non-existent lease_expires_at column, so MySQL errored and the
# reset silently did nothing.
dbq "UPDATE wp_wptsall_content_change_outbox SET status='pending', claimed_at=NULL WHERE status='processing' AND claimed_at < UTC_TIMESTAMP() - INTERVAL 900 SECOND;" >/dev/null 2>&1 || true
ok "S0 preflight guards passed"

# ---- 1. Provision scenario 1.1 ----
info "Provisioning WP side (Gutenberg post + category + tag)"
PROVISION_OUT="$(wp_eval "${SCRIPT_DIR}/php/provision-client-s1-lifecycle.php" 2>/dev/null | tail -n 1)"
PROVISION_JSON="$(echo "${PROVISION_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("success"), d; print(json.dumps(d["data"]))')"
RELATION_ID="$(echo "${PROVISION_JSON}" | json_field "['relation_id']")"
POST_ID="$(echo "${PROVISION_JSON}" | json_field "['post_id']")"
CAT_ID="$(echo "${PROVISION_JSON}" | json_field "['cat_id']")"
TAG_ID="$(echo "${PROVISION_JSON}" | json_field "['tag_id']")"
POST_TITLE="$(echo "${PROVISION_JSON}" | json_field "['post_title']")"
[[ -n "${RELATION_ID}" && -n "${POST_ID}" ]] || abort "provision output missing ids: ${PROVISION_OUT}"
ok "provisioned relation=${RELATION_ID} post=${POST_ID} cat=${CAT_ID} tag=${TAG_ID} (${POST_TITLE})"

# ---- 2. Start + pair both agents ----
start_client_agent "${BASE_A}" "a" "${RUN_DIR}/data-a"
start_client_agent "${BASE_B}" "b" "${RUN_DIR}/data-b"

pair_client() {
  local base="$1" label="$2"
  local device_id pack_raw pack_json pairing_code import_body import_out
  device_id="$(curl -sf "${base}/api/status" | json_field "['data']['device_id']")"
  [[ -n "${device_id}" ]] || abort "could not read device_id from ${base}"
  info "Pairing ${label} (${device_id})"
  pack_raw="$(wp_cli wptsall security issue_pairing_pack --device-id="${device_id}" --format=json --allow-root 2>/dev/null)"
  pack_json="$(echo "${pack_raw}" | python3 -c '
import json, sys
raw = sys.stdin.read()
start = raw.find("{")
assert start >= 0, "no JSON object in pairing pack output: %r" % raw[:200]
d = json.JSONDecoder().raw_decode(raw[start:])[0]
assert "pairing_code" in d, d
print(json.dumps(d))')"
  pairing_code="$(echo "${pack_json}" | json_field "['pairing_code']")"
  [[ -n "${pairing_code}" ]] || abort "pairing pack output missing pairing_code: ${pack_raw}"
  import_body="$(python3 -c 'import json,sys; pack=json.loads(sys.argv[1]); print(json.dumps({"site_connection_pack": pack, "pairing_code": pack["pairing_code"], "device_label": sys.argv[2]}))' "${pack_json}" "client-s1-${label}")"
  import_out="$(curl -sf -X POST "${base}/api/site-connections/import" -H 'Content-Type: application/json' -d "${import_body}")"
  echo "${import_out}" | python3 -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("success") else 1)' \
    || abort "client import failed (${label}): ${import_out}"
  ok "${label} bound to ${LAB_WP_BASE}"
}
pair_client "${BASE_A}" "a"
pair_client "${BASE_B}" "b"

# ---- 3. Component setup on both agents (idempotent) ----
setup_component() {
  local base="$1"
  local catalog_list catalog_count install_out key_out
  catalog_list="$(curl -sf -m 30 "${base}/api/provider-catalog")"
  catalog_count="$(echo "${catalog_list}" | python3 -c '
import json, sys
d = json.load(sys.stdin)
items = d.get("data", {}).get("items") or []
assert d.get("success") and items, json.dumps(d)[:200]
print(len(items))')" || abort "provider catalog warm-up failed (${base})"
  ok "catalog warmed on ${base} (${catalog_count} entries)"

  api_ok_or_duplicate() {
    local out="$1"
    echo "${out}" | python3 -c '
import json, sys
raw = sys.stdin.read()
try:
    d = json.loads(raw)
except Exception:
    sys.exit(1)
if d.get("success"):
    sys.exit(0)
code = (d.get("error") or {}).get("code", "")
sys.exit(0 if code == "DUPLICATE_ID" else 1)'
  }
  install_out="$(curl -s -X POST "${base}/api/components/local/install-from-catalog" \
    -H 'Content-Type: application/json' \
    -d "{\"entry_id\":\"openai-compatible\",\"local_id\":\"${COMPONENT_ID}\",\"name\":\"Client S1 OpenAI Mock\"}")"
  api_ok_or_duplicate "${install_out}" || abort "install-from-catalog failed (${base}): ${install_out}"
  key_out="$(curl -s -X POST "${base}/api/vendor-keys" \
    -H 'Content-Type: application/json' \
    -d "{\"id\":\"${COMPONENT_ID}-key\",\"vendor_id\":\"openai\",\"label\":\"client-s1\",\"auth_values\":{\"api_key\":\"mock-translate-dev-key-2026\"},\"enabled\":true}")"
  api_ok_or_duplicate "${key_out}" || abort "vendor-keys failed (${base}): ${key_out}"
  curl -sf -X POST "${base}/api/components/local/${COMPONENT_ID}/versions" \
    -H 'Content-Type: application/json' \
    -d "{\"version\":\"v1\",\"key_ids\":[\"${COMPONENT_ID}-key\"],\"auth_type\":\"key\"}" >/dev/null || true
  curl -sf -X PUT "${base}/api/components/local/${COMPONENT_ID}" \
    -H 'Content-Type: application/json' -d '{"enabled":true}' >/dev/null
  curl -sf -X POST "${base}/api/components/bindings/upsert" \
    -H 'Content-Type: application/json' \
    -d "{\"component_id\":\"${COMPONENT_ID}\",\"auth\":{\"api_key\":\"mock-translate-dev-key-2026\"},\"key_ids\":[\"${COMPONENT_ID}-key\"],\"request_overrides\":{\"url\":\"${MOCK_API_BASE}/v1/chat/completions\",\"body\":{\"model\":\"mock-openai-v1\"}}}" >/dev/null
  curl -sf -X POST "${base}/api/rule-component-bindings/upsert" \
    -H 'Content-Type: application/json' \
    -d "{\"scope\":\"global\",\"slot_key\":\"plain_text\",\"component_id\":\"${COMPONENT_ID}\"}" >/dev/null
  curl -sf -X POST "${base}/api/rule-component-bindings/upsert" \
    -H 'Content-Type: application/json' \
    -d "{\"scope\":\"global\",\"slot_key\":\"rich_html\",\"component_id\":\"${COMPONENT_ID}\"}" >/dev/null
  ok "component ${COMPONENT_ID} bound on ${base} (plain_text + rich_html)"
}
setup_component "${BASE_A}"
setup_component "${BASE_B}"

# ---- 4. Discovery + daemon workers on both agents ----
for base in "${BASE_A}" "${BASE_B}"; do
  info "Bootstrapping discovery on ${base}"
  BOOTSTRAP_OUT="$(curl -sf -X POST "${base}/api/discovery-tasks/bootstrap")"
  echo "${BOOTSTRAP_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin)["data"]; failed=d.get("failed",[]); assert not failed, failed' \
    || abort "discovery bootstrap failed (${base}): ${BOOTSTRAP_OUT}"
done

for base in "${BASE_A}" "${BASE_B}"; do
  curl -sf -X POST "${base}/api/worker/start" -H 'Content-Type: application/json' -d '{"force":true}' >/dev/null \
    || abort "worker/start failed on ${base}"
done
ok "daemon workers started on A+B (concurrent claiming, poll 2s)"

# ---- 5. Drain the historical backlog in parallel (progress via fast DB) ----
info "Draining outbox backlog with parallel agents (max ~6 min)"
DRAIN_START="$(date +%s)"
LAST_COUNT=""
for round in $(seq 1 180); do
  ACTIVE="$(outbox_active)"
  [[ -n "${ACTIVE}" && "${ACTIVE}" != "0" ]] || { ok "outbox drained (0 active) after $(( $(date +%s) - DRAIN_START ))s"; break; }
  if [[ "${ACTIVE}" != "${LAST_COUNT}" ]]; then
    info "backlog: ${ACTIVE} active outbox rows (t+$(( $(date +%s) - DRAIN_START ))s)"
    LAST_COUNT="${ACTIVE}"
  fi
  if mapping_exists "${POST_ID}" "${RELATION_ID}"; then
    ok "S1 post ${POST_ID} mapped while draining (backlog left: ${ACTIVE})"
    break
  fi
  sleep 2
  if [[ "${round}" = "180" ]]; then abort "backlog drain timed out (${ACTIVE} still active)"; fi
done

# ---- 6. Scenario 1.1 verify (fail-fast: pending vs assertion-failed) ----
wait_for_mapping() {
  local src="$1" rel="$2" timeout_s="${3:-240}"
  local waited=0
  until mapping_exists "${src}" "${rel}"; do
    sleep 2; waited=$((waited + 2))
    [[ ${waited} -ge ${timeout_s} ]] && return 1
  done
  return 0
}

verify_json() {
  local mode="$1"; shift
  wp_eval "${SCRIPT_DIR}/php/verify-client-s1-lifecycle.php" "relation_id=${RELATION_ID}" "post_id=${POST_ID}" "cat_id=${CAT_ID}" "tag_id=${TAG_ID}" "mode=${mode}" "$@" 2>/dev/null | tail -n 1
}

verify_failfast() {
  local mode="$1"; shift
  local out
  out="$(verify_json "${mode}" "$@")"
  # In increment mode the update writeback may still be in flight: content
  # diffs (block_fidelity / increment_appended / title) are PENDING-capable
  # and must not abort the lane; only mapping/target regressions are hard.
  echo "${out}" | VERIFY_MODE="${mode}" python3 -c '
import json, os, sys
d = json.load(sys.stdin)
if d.get("pass"):
    print("PASS")
    sys.exit(0)
checks = d.get("checks") or []
mode = os.environ.get("VERIFY_MODE", "initial")
pending_prefixes = ("block_fidelity", "increment_appended", "title_marker", "attr_", "inline_") if mode == "increment" else ()
hard = [c for c in checks
        if not c.get("ok")
        and "no mapping" not in c.get("detail", "")
        and "not found" not in c.get("detail", "")
        and not c.get("check", "").startswith(pending_prefixes)]
if hard:
    # Deterministic assertion failure: fail FAST with the details.
    print("FAILED " + json.dumps(hard, ensure_ascii=False))
    sys.exit(2)
print("PENDING")
sys.exit(1)'
}

info "Scenario 1.1: waiting for mapping + verify (fail-fast)"
wait_for_mapping "${POST_ID}" "${RELATION_ID}" 240 || abort "scenario 1.1: mapping never appeared for post ${POST_ID}"
# give the writeback a moment to settle term rows, then verify
sleep 3
V1="$(verify_failfast initial || true)"
case "${V1}" in
  PASS) ;;
  PENDING)
    # mapping existed but verify saw a transient miss — retry a few times
    for retry in $(seq 1 10); do
      sleep 3
      V1="$(verify_failfast initial || true)"
      [[ "${V1}" = "PASS" ]] && break
      [[ "${V1}" = FAILED* ]] && break
    done
    ;;
esac
case "${V1}" in
  PASS) ok "scenario 1.1 PASS (Gutenberg fidelity + markers + cascade)" ;;
  *) abort "scenario 1.1 assertion failure: ${V1}" ;;
esac
INITIAL_OUT="$(verify_json initial)"
TARGET_ID="$(echo "${INITIAL_OUT}" | json_field "['target_post_id']")"
ok "target post ${TARGET_ID} for source ${POST_ID}"

# ---- 7. Scenario 1.2: incremental update ----
info "Scenario 1.2: updating source post ${POST_ID} (new title + appended paragraph)"
UPDATE_OUT="$(wp_eval "${SCRIPT_DIR}/php/provision-client-s1-lifecycle.php" "mode=update" "post_id=${POST_ID}" 2>/dev/null | tail -n 1)"
echo "${UPDATE_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("success"), d' \
  || abort "update provisioning failed: ${UPDATE_OUT}"

info "Waiting for the incremental update to land (fail-fast, max ~4 min)"
V2="PENDING"
for round in $(seq 1 120); do
  V2="$(verify_failfast increment "expected_target_id=${TARGET_ID}" || true)"
  [[ "${V2}" = "PASS" || "${V2}" = FAILED* ]] && break
  # heartbeat so a silent poll loop never looks stuck
  if (( round % 5 == 1 )); then
    info "scenario 1.2 in flight (t+$(( round * 2 ))s, state: $(echo "${V2}" | head -c 60))"
  fi
  sleep 2
done
case "${V2}" in
  PASS) ok "scenario 1.2 PASS (same target ${TARGET_ID} updated, no duplicate)" ;;
  *) abort "scenario 1.2 failure: ${V2}" ;;
esac
INCREMENT_OUT="$(verify_json increment "expected_target_id=${TARGET_ID}")"

# ---- 8. Stop daemons + report ----
for base in "${BASE_A}" "${BASE_B}"; do
  curl -sf -m 5 -X POST "${base}/api/worker/stop" >/dev/null 2>&1 || true
done

SUMMARY="$(python3 -c 'import json,sys; print(json.dumps({"task":"client-s1-lifecycle","pass":True,"stamp":sys.argv[1],"relation_id":int(sys.argv[2]),"post_id":int(sys.argv[3]),"target_post_id":int(sys.argv[4]),"cat_id":int(sys.argv[5]),"tag_id":int(sys.argv[6]),"agents":[sys.argv[7],sys.argv[8]],"mock_base":sys.argv[9]}))' \
  "${STAMP}" "${RELATION_ID}" "${POST_ID}" "${TARGET_ID}" "${CAT_ID}" "${TAG_ID}" "${BASE_A}" "${BASE_B}" "${MOCK_API_BASE}")"
echo "${SUMMARY}" | python3 -m json.tool >"${REPORT_ROOT}/summary-${STAMP}.json"
echo "${INITIAL_OUT}" >"${REPORT_ROOT}/verify-initial-${STAMP}.json"
echo "${INCREMENT_OUT}" >"${REPORT_ROOT}/verify-increment-${STAMP}.json"

ok "S1 lifecycle PASS (report: ${REPORT_ROOT}/summary-${STAMP}.json)"
