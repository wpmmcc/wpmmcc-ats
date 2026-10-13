#!/usr/bin/env bash
# Review-loop lane (批 O1 / U-3, 12号 §26): the single serial spec covering
# wp-admin task-modal + translation-editor entry → client review UI →
# public frontend visibility.
#
# Stack is LOCAL-FIRST (mirrors docker-wp-journey): lab WP (docker, shared
# test-1 slot) + loopback web client (--webui, isolated runtime) + mock
# translate API. NO website / server :8787 / PG — the legacy three-system
# stack is a separate restoration project (12号 ledger).
#
# Phases:
#   1. mock translate API on :9090 (skip if healthy)
#   2. WP lane prep — stages 02-05 (clean/seed/scan/relations) on the lab WP
#   3. WP device token + route secret + relation ids (wp-cli eval)
#   4. loopback client on :9079 with a fresh isolated runtime + small
#      discovery caps (a focused review set), logging enabled
#   5. API setup: bind site token, create+auth the local openai_compatible
#      component against the mock API
#   6. playwright review-loop/review-loop.spec.ts (UI legs)
#   7. teardown: client + mock processes; WP lane is re-cleaned by the next
#      run's stage 02
#
# Usage: bash tests/modules/wpmmcc-ats/e2e/run-playwright-review-loop.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../lib/repo-root.sh"
ROOT_DIR="$(wptsall_repo_root "${SCRIPT_DIR}")"
E2E_DIR="$ROOT_DIR/tests/modules/wpmmcc-ats/e2e"
PLAYWRIGHT_DIR="${E2E_DIR}/playwright"

PROJECT="${E2E_PROJECT:-core-content}"
SCOPE="${E2E_SCOPE:-core-only}"
LAB_WP_PORT="${LAB_WP_PORT:-9083}"
WP_BASE="${WP_BASE:-http://127.0.0.1:${LAB_WP_PORT}}"
WP_CONTAINER="${LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
WP_ADMIN_USER="${REVIEW_LOOP_WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${REVIEW_LOOP_WP_ADMIN_PASS:-admin123}"
MOCK_API_BASE="${MOCK_API_BASE:-http://127.0.0.1:9090}"
MOCK_API_KEY="${MOCK_API_KEY:-mock-translate-dev-key-2026}"
CLIENT_BIN="${REVIEW_LOOP_CLIENT_BIN:-$ROOT_DIR/client-wpplugin/source/target/debug/wptsall-client}"
CLIENT_PORT="${REVIEW_LOOP_CLIENT_PORT:-9079}"
CLIENT_BASE="http://127.0.0.1:${CLIENT_PORT}"
DEVICE_ID="${WPTSALL_DEVICE_ID:-review-loop-$(date +%s)}"
RUNTIME_DIR="${E2E_DIR}/runtime/review-loop"
LOG_FILE="${RUNTIME_DIR}/wptsall-client.log"
COMPONENT_ID="review-loop-openai"
CLIENT_PID=""
MOCK_PID=""

cleanup() {
  if [ -n "${CLIENT_PID}" ]; then
    kill "${CLIENT_PID}" 2>/dev/null || true
  fi
  if [ -n "${MOCK_PID}" ]; then
    kill "${MOCK_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }

wait_url() {
  local url="$1" label="$2"
  for _ in $(seq 1 80); do
    curl -fsS "${url}" >/dev/null 2>&1 && return 0
    sleep 0.25
  done
  fail "${label} did not become ready: ${url}"
}

ensure_mock_api() {
  if curl -fsS "$MOCK_API_BASE/api/v1/health" >/dev/null 2>&1; then
    echo "mock translate api already healthy at ${MOCK_API_BASE}"
    return 0
  fi
  local mock_bin="${WPTSALL_MOCK_API_BIN:-$ROOT_DIR/tests/infra/mock-api/target/release/mock-translate-api}"
  [ -x "${mock_bin}" ] || fail "mock api binary missing: ${mock_bin} (build tests/infra/mock-api)"
  "${mock_bin}" >/tmp/wptsall-review-loop-mock-api.log 2>&1 &
  MOCK_PID=$!
  wait_url "$MOCK_API_BASE/api/v1/health" "mock translate api"
  echo "mock translate api started (pid ${MOCK_PID})"
}

wp_cli() {
  docker exec \
    -e WPTSALL_LAB=1 \
    -e E2E_PROJECT="$PROJECT" \
    -e E2E_SCOPE="$SCOPE" \
    -e WPTSALL_LANE_RELATION_IDS="${VIRTUAL_RELATION_ID:-},${WP_RELATION_ID:-}" \
    -e PHP_MEMORY_LIMIT="${LAB_PHP_MEMORY_LIMIT:-4096M}" \
    -e WP_CLI_PHP="php -d memory_limit=${LAB_PHP_MEMORY_LIMIT:-4096M}" \
    "$WP_CONTAINER" wp --allow-root --path=/var/www/html "$@"
}

prepare_wp_lane() {
  echo "== Preparing WP lane: project=$PROJECT scope=$SCOPE wp=$WP_BASE =="
  if [[ "${REVIEW_LOOP_SKIP_WP_PREPARE:-0}" == "1" ]]; then
    echo "skipping WP clean/seed/scan/relation (REVIEW_LOOP_SKIP_WP_PREPARE=1)"
    return 0
  fi
  (
    cd "$ROOT_DIR"
    WPTSALL_LAB=1 \
    E2E_PROJECT="$PROJECT" \
    E2E_SCOPE="$SCOPE" \
    WP_BASE="$WP_BASE" \
    LAB_WP_HOST="127.0.0.1" \
    LAB_WP_PORT="$LAB_WP_PORT" \
    LAB_WP_CONTAINER="$WP_CONTAINER" \
    E2E_SKIP_FRONTEND_VERIFY=0 \
    bash tests/modules/wpmmcc-ats/e2e/stages/02-env-clean.sh
    WPTSALL_LAB=1 E2E_PROJECT="$PROJECT" E2E_SCOPE="$SCOPE" WP_BASE="$WP_BASE" LAB_WP_HOST="127.0.0.1" LAB_WP_PORT="$LAB_WP_PORT" LAB_WP_CONTAINER="$WP_CONTAINER" bash tests/modules/wpmmcc-ats/e2e/stages/03-data-seed.sh
    WPTSALL_LAB=1 E2E_PROJECT="$PROJECT" E2E_SCOPE="$SCOPE" WP_BASE="$WP_BASE" LAB_WP_HOST="127.0.0.1" LAB_WP_PORT="$LAB_WP_PORT" LAB_WP_CONTAINER="$WP_CONTAINER" bash tests/modules/wpmmcc-ats/e2e/stages/04-model-scan.sh
    WPTSALL_LAB=1 E2E_PROJECT="$PROJECT" E2E_SCOPE="$SCOPE" WP_BASE="$WP_BASE" LAB_WP_HOST="127.0.0.1" LAB_WP_PORT="$LAB_WP_PORT" LAB_WP_CONTAINER="$WP_CONTAINER" bash tests/modules/wpmmcc-ats/e2e/stages/05-relation-setup.sh
  )
}

issue_wp_token() {
  ROUTE_SECRET="$(wp_cli eval 'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";' 2>/dev/null | tail -n 1 | tr -d '\r' || true)"
  [ -n "${ROUTE_SECRET}" ] || fail "route_secret unavailable from WP"
  DEVICE_JSON="$(wp_cli eval 'if(function_exists("wptsall_issue_client_device_token")){ $d=wptsall_issue_client_device_token(getenv("WPTSALL_DEVICE_ID") ?: "review-loop", "review-loop"); echo wp_json_encode($d); }' 2>/dev/null | tail -n 1 || true)"
  WP_CLIENT_TOKEN="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("token", ""))' "$DEVICE_JSON" 2>/dev/null || true)"
  [ -n "${WP_CLIENT_TOKEN}" ] || fail "WP_CLIENT_TOKEN unavailable; raw device json: $DEVICE_JSON"
  # The journey contract (docker-wp-journey issue_wp_token): the client must
  # present the SAME device identity the token was issued for, or every WP
  # call 401s with client_unauthorized. Adopt the issued device id.
  ISSUED_DEVICE_ID="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("device_id", ""))' "$DEVICE_JSON" 2>/dev/null || true)"
  if [ -n "${ISSUED_DEVICE_ID}" ]; then
    DEVICE_ID="${ISSUED_DEVICE_ID}"
  fi
  echo "route_secret + device token issued (device_id=${DEVICE_ID})"
}

load_relation_ids() {
  local relation_file="$E2E_DIR/runtime/relation-ids.json"
  [ -f "${relation_file}" ] || fail "relation file missing: ${relation_file}"
  WP_RELATION_ID="$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("wp", ""))' "$relation_file")"
  VIRTUAL_RELATION_ID="$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("virtual", ""))' "$relation_file")"
  [ -n "${WP_RELATION_ID}" ] || fail "wp relation id missing in ${relation_file}"
  echo "relations: wp=${WP_RELATION_ID} virtual=${VIRTUAL_RELATION_ID}"
}

sweep_lane_queues() {
  echo "== Sweeping stale outbox + claim leases for lane relations =="
  # 批 O1 finding: the outbox drain lane claims oldest-first, and the lab
  # accumulates dead events per relation — post_deleted rows carry no
  # post_type in their payload (subtype resolves empty and
  # claim_outbox_source_mapping() rejects them permanently), and
  # post_created rows for since-deleted source posts loop
  # source_object_not_found. Each stale head row consumes a drain-claim
  # slot, so a fresh post_created event waits behind hundreds of dead rows
  # for hours. The lane's targeted routable leg (spec Leg 6.5) depends on a
  # fresh event being claimed in the SAME run-once, so sweep the two lane
  # relations' rows. Also release mapping claim leases: each lane run
  # presents a fresh timestamped device id, and a lease held by a previous
  # run's device blocks the mapping-claim CAS for up to 30 min
  # (wptsall_get_client_claim_timeout_seconds default 1800).
  wp_cli eval 'global $wpdb; $ids = array_map("intval", explode(",", getenv("WPTSALL_LANE_RELATION_IDS"))); $in = implode(",", $ids); $o = (int) $wpdb->query("DELETE FROM {$wpdb->prefix}wptsall_content_change_outbox WHERE relation_id IN ($in)"); $m = (int) $wpdb->query("UPDATE {$wpdb->prefix}wptsall_post_mappings SET claimed_at = NULL, claim_owner_hash = NULL WHERE relation_id IN ($in) AND claim_owner_hash IS NOT NULL"); $t = (int) $wpdb->query("UPDATE {$wpdb->prefix}wptsall_term_mappings SET claimed_at = NULL, claim_owner_hash = NULL WHERE relation_id IN ($in) AND claim_owner_hash IS NOT NULL"); echo wp_json_encode(array("outbox_deleted" => $o, "post_mapping_leases_released" => $m, "term_mapping_leases_released" => $t));' 2>/dev/null | tail -n 1
}

start_client() {
  [ -x "${CLIENT_BIN}" ] || fail "client binary missing: ${CLIENT_BIN}"
  rm -rf "${RUNTIME_DIR}"
  mkdir -p "${RUNTIME_DIR}"
  # Serve the FRESHLY BUILT shared frontend (source testids — the binary's
  # compile-time embed predates UI-source edits; WPTSALL_WEB_UI_PATH wins
  # over the embed per static_html.rs priority).
  local web_dist="$ROOT_DIR/client-wpplugin/source/frontend/dist"
  [ -f "${web_dist}/index.html" ] || fail "frontend dist missing: ${web_dist} (npm run build in client-wpplugin/source/frontend)"
  # No WPTSALL_DISCOVERY_MAX_ITEMS_PER_RUN override: the cap is per data_type
  # per relation per run, and a low cap is consumed entirely by one
  # relation's wp_navigation/wp_template shells before ordinary post/page
  # items are ever reached (the journey's default spread reaches posts).
  WPTSALL_DATA_DIR="${RUNTIME_DIR}" \
  WPTSALL_LOG_FILE="${LOG_FILE}" \
  WPTSALL_LOG_ENABLED=1 \
  WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}" \
  WPTSALL_WEB_UI_PATH="${web_dist}" \
  WPTSALL_DEVICE_ID="${DEVICE_ID}" \
  WPTSALL_RUN_ONCE_MAX_ELAPSED_SECS=600 \
  "${CLIENT_BIN}" --webui >"${RUNTIME_DIR}/client-stdout.log" 2>&1 &
  CLIENT_PID=$!
  wait_url "${CLIENT_BASE}/api/status" "review-loop client"
  echo "review-loop client started (pid ${CLIENT_PID}) at ${CLIENT_BASE}"
}

client_api() {
  local method="$1" path="$2" body="$3"
  if [ -n "${body}" ]; then
    curl -fsS -m 60 -X "${method}" "${CLIENT_BASE}${path}" -H 'Content-Type: application/json' -d "${body}"
  else
    curl -fsS -m 60 -X "${method}" "${CLIENT_BASE}${path}" -H 'Content-Type: application/json' -d '{}'
  fi
}

setup_client_via_api() {
  echo "== Binding site + component via client API =="
  # Protocol v2 bind (same contract the desktop journey drives through the
  # Sites page): api_base_url + wp-issued device token + route secret.
  client_api POST /api/domain-tokens/upsert "{\"api_base_url\":\"${WP_BASE}\",\"wp_client_token\":\"${WP_CLIENT_TOKEN}\",\"route_secret\":\"${ROUTE_SECRET}\"}" >/dev/null \
    || fail "domain-tokens/upsert failed"
  # Local openai_compatible component against the mock translate API (same
  # contract the journey's component-modal drives).
  client_api POST /api/components/local "{\"id\":\"${COMPONENT_ID}\",\"name\":\"Review Loop OpenAI Mock\",\"kind\":\"openai_compatible\",\"api_base\":\"${MOCK_API_BASE}\",\"model\":\"mock-openai-v1\",\"openai_compatible\":true,\"enabled\":true}" >/dev/null \
    || fail "components/local create failed"
  # Auth binding: auth.api_key → Authorization: Bearer <mock key> (the
  # journey's Edit Auth → api_key flow). auth_strategy mirrors the UI default
  # (RoundRobin — the serde enum accepts Random|RoundRobin|Weighted).
  client_api POST /api/components/bindings/upsert "{\"component_id\":\"${COMPONENT_ID}\",\"auth\":{\"api_key\":\"${MOCK_API_KEY}\"},\"key_ids\":[],\"oauth_ids\":[],\"auth_strategy\":\"RoundRobin\"}" >/dev/null \
    || fail "components/bindings/upsert failed"
  pass "client bound + component ${COMPONENT_ID} configured"
}

run_spec() {
  echo "== Running review-loop spec =="
  (
    cd "${PLAYWRIGHT_DIR}"
    export WPTSALL_REVIEW_LOOP_CLIENT_BASE="${CLIENT_BASE}"
    export REVIEW_LOOP_WP_BASE="${WP_BASE}"
    export REVIEW_LOOP_WP_ADMIN_USER="${WP_ADMIN_USER}"
    export REVIEW_LOOP_WP_ADMIN_PASS="${WP_ADMIN_PASS}"
    export REVIEW_LOOP_WP_RELATION_ID="${WP_RELATION_ID}"
    export REVIEW_LOOP_VIRTUAL_RELATION_ID="${VIRTUAL_RELATION_ID}"
    export REVIEW_LOOP_WP_CONTAINER="${WP_CONTAINER}"
    export REVIEW_LOOP_CLIENT_LOG="${LOG_FILE}"
    npx playwright test -c playwright.review-loop.config.ts "$@"
  )
}

main() {
  ensure_mock_api
  prepare_wp_lane
  issue_wp_token
  load_relation_ids
  sweep_lane_queues
  start_client
  setup_client_via_api
  run_spec "$@"
  pass "review-loop lane passed"
}

main "$@"
