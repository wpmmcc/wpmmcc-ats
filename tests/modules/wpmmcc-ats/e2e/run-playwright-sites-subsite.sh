#!/usr/bin/env bash
# Sites-subsite lane (批 O2 / U-5, 12号 §27): the Sites page UI binding →
# discovery → auto-writeback → subsite public frontend, in ONE serial spec.
#
# Stack is LOCAL-FIRST (mirrors run-playwright-review-loop.sh): lab WP
# (docker, multisite blog 2 = /en/) + loopback web client (--webui, isolated
# runtime) + mock translate API. NO website / server :8787 / PG.
#
# Differences from the review-loop lane:
#   - The site binding is driven through the CLIENT SITES PAGE UI (the
#     product-true bind flow: add-site modal → url + wp token + route
#     secret → Token saved). The runner therefore does NOT pre-bind via
#     domain-tokens/upsert; it only provisions the translation component.
#   - Review mode stays OFF (default): translations auto-writeback — this
#     lane's subject is the bind + subsite writeback, not the review queue
#     (that is the review-loop lane's contract).
#   - The targeted routable post rides the outbox drain lane (same 批 O1
#     finding: the discovery listing is ID-ASC + FSE-flooded) and the
#     writeback lands ON BLOG 2; the front check asserts the SUBSITE
#     permalink (200 + markers).
#   - Prep flushes blog 2 rewrite rules once (idempotent): the lab has
#     shown structure-vs-rules drift where the canonical /en/{slug}/ 404s
#     while stale /en/blog/{slug}/ rules still resolve.
#
# Usage: bash tests/modules/wpmmcc-ats/e2e/run-playwright-sites-subsite.sh
#        SITES_SUBSITE_SKIP_WP_PREPARE=1 ... # warm iteration (stages skipped,
#        sweep + flush still run)

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
WP_ADMIN_USER="${SITES_SUBSITE_WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${SITES_SUBSITE_WP_ADMIN_PASS:-admin123}"
MOCK_API_BASE="${MOCK_API_BASE:-http://127.0.0.1:9090}"
MOCK_API_KEY="${MOCK_API_KEY:-mock-translate-dev-key-2026}"
CLIENT_BIN="${SITES_SUBSITE_CLIENT_BIN:-$ROOT_DIR/client-wpplugin/source/target/debug/wptsall-client}"
CLIENT_PORT="${SITES_SUBSITE_CLIENT_PORT:-9084}"
CLIENT_BASE="http://127.0.0.1:${CLIENT_PORT}"
DEVICE_ID="${WPTSALL_DEVICE_ID:-sites-subsite-$(date +%s)}"
RUNTIME_DIR="${E2E_DIR}/runtime/sites-subsite"
LOG_FILE="${RUNTIME_DIR}/wptsall-client.log"
COMPONENT_ID="sites-subsite-openai"
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
  "${mock_bin}" >/tmp/wptsall-sites-subsite-mock-api.log 2>&1 &
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
  if [[ "${SITES_SUBSITE_SKIP_WP_PREPARE:-0}" == "1" ]]; then
    echo "skipping WP clean/seed/scan/relation (SITES_SUBSITE_SKIP_WP_PREPARE=1)"
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
  DEVICE_JSON="$(wp_cli eval 'if(function_exists("wptsall_issue_client_device_token")){ $d=wptsall_issue_client_device_token(getenv("WPTSALL_DEVICE_ID") ?: "sites-subsite", "sites-subsite"); echo wp_json_encode($d); }' 2>/dev/null | tail -n 1 || true)"
  WP_CLIENT_TOKEN="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("token", ""))' "$DEVICE_JSON" 2>/dev/null || true)"
  [ -n "${WP_CLIENT_TOKEN}" ] || fail "WP_CLIENT_TOKEN unavailable; raw device json: $DEVICE_JSON"
  # Journey contract: the client must present the SAME device identity the
  # token was issued for. Adopt the issued device id.
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
  # 批 O1 finding (same contract as the review-loop lane): the outbox drain
  # claims oldest-first and the lab accumulates dead events per relation
  # (post_deleted rows carry no post_type → permanently unclaimable;
  # post_created rows for deleted sources loop source_object_not_found).
  # The targeted subsite post depends on a fresh event being claimed in the
  # SAME run-once, so sweep the lane relations' rows and release mapping
  # claim leases held by previous runs' timestamped device ids.
  wp_cli eval 'global $wpdb; $ids = array_map("intval", explode(",", getenv("WPTSALL_LANE_RELATION_IDS"))); $in = implode(",", $ids); $o = (int) $wpdb->query("DELETE FROM {$wpdb->prefix}wptsall_content_change_outbox WHERE relation_id IN ($in)"); $m = (int) $wpdb->query("UPDATE {$wpdb->prefix}wptsall_post_mappings SET claimed_at = NULL, claim_owner_hash = NULL WHERE relation_id IN ($in) AND claim_owner_hash IS NOT NULL"); $t = (int) $wpdb->query("UPDATE {$wpdb->prefix}wptsall_term_mappings SET claimed_at = NULL, claim_owner_hash = NULL WHERE relation_id IN ($in) AND claim_owner_hash IS NOT NULL"); echo wp_json_encode(array("outbox_deleted" => $o, "post_mapping_leases_released" => $m, "term_mapping_leases_released" => $t));' 2>/dev/null | tail -n 1
}

flush_subsite_rewrites() {
  echo "== Flushing blog 2 rewrite rules (idempotent) =="
  # The lab has shown blog 2 structure-vs-rules drift: permalink_structure
  # says /%postname%/ (canonical /en/{slug}/) while stale persisted rules
  # still route /en/blog/{slug}/ — the canonical URL 404s. One flush aligns
  # the rules with the structure; it is idempotent on an aligned blog.
  wp_cli --url="${WP_BASE}/en/" rewrite flush >/dev/null 2>&1 || \
    wp_cli eval 'switch_to_blog(2); flush_rewrite_rules(); restore_current_blog(); echo "flushed";' >/dev/null 2>&1 || true
  echo "blog 2 rewrites flushed"
}

start_client() {
  [ -x "${CLIENT_BIN}" ] || fail "client binary missing: ${CLIENT_BIN}"
  rm -rf "${RUNTIME_DIR}"
  mkdir -p "${RUNTIME_DIR}"
  # Port note: the lab docker maps 0.0.0.0:9081/9082/9083 to the WP
  # container — the client must bind a port OUTSIDE that range or its UI
  # traffic silently lands on the WP lab instead.
  local web_dist="$ROOT_DIR/client-wpplugin/source/frontend/dist"
  [ -f "${web_dist}/index.html" ] || fail "frontend dist missing: ${web_dist} (npm run build in client-wpplugin/source/frontend)"
  # Review mode stays OFF (default): this lane verifies the bind + subsite
  # auto-writeback, not the review queue (the review-loop lane owns that).
  WPTSALL_DATA_DIR="${RUNTIME_DIR}" \
  WPTSALL_LOG_FILE="${LOG_FILE}" \
  WPTSALL_LOG_ENABLED=1 \
  WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}" \
  WPTSALL_WEB_UI_PATH="${web_dist}" \
  WPTSALL_DEVICE_ID="${DEVICE_ID}" \
  WPTSALL_RUN_ONCE_MAX_ELAPSED_SECS=600 \
  "${CLIENT_BIN}" --webui >"${RUNTIME_DIR}/client-stdout.log" 2>&1 &
  CLIENT_PID=$!
  wait_url "${CLIENT_BASE}/api/status" "sites-subsite client"
  echo "sites-subsite client started (pid ${CLIENT_PID}) at ${CLIENT_BASE}"
}

client_api() {
  local method="$1" path="$2" body="$3"
  if [ -n "${body}" ]; then
    curl -fsS -m 60 -X "${method}" "${CLIENT_BASE}${path}" -H 'Content-Type: application/json' -d "${body}"
  else
    curl -fsS -m 60 -X "${method}" "${CLIENT_BASE}${path}" -H 'Content-Type: application/json' -d '{}'
  fi
}

setup_component_via_api() {
  echo "== Provisioning translation component via client API (no site bind — the spec binds via the Sites UI) =="
  # Local openai_compatible component against the mock translate API. The
  # SITE BINDING is deliberately NOT done here: the spec's Sites-page modal
  # is the product-true bind flow this lane verifies.
  client_api POST /api/components/local "{\"id\":\"${COMPONENT_ID}\",\"name\":\"Sites Subsite OpenAI Mock\",\"kind\":\"openai_compatible\",\"api_base\":\"${MOCK_API_BASE}\",\"model\":\"mock-openai-v1\",\"openai_compatible\":true,\"enabled\":true}" >/dev/null \
    || fail "components/local create failed"
  client_api POST /api/components/bindings/upsert "{\"component_id\":\"${COMPONENT_ID}\",\"auth\":{\"api_key\":\"${MOCK_API_KEY}\"},\"key_ids\":[],\"oauth_ids\":[],\"auth_strategy\":\"RoundRobin\"}" >/dev/null \
    || fail "components/bindings/upsert failed"
  pass "component ${COMPONENT_ID} provisioned (site bind left to the spec)"
}

run_spec() {
  echo "== Running sites-subsite spec =="
  (
    cd "${PLAYWRIGHT_DIR}"
    export WPTSALL_SITES_SUBSITE_CLIENT_BASE="${CLIENT_BASE}"
    export SITES_SUBSITE_WP_BASE="${WP_BASE}"
    export SITES_SUBSITE_WP_TOKEN="${WP_CLIENT_TOKEN}"
    export SITES_SUBSITE_ROUTE_SECRET="${ROUTE_SECRET}"
    export SITES_SUBSITE_WP_RELATION_ID="${WP_RELATION_ID}"
    export SITES_SUBSITE_WP_CONTAINER="${WP_CONTAINER}"
    export SITES_SUBSITE_CLIENT_LOG="${LOG_FILE}"
    npx playwright test -c playwright.sites-subsite.config.ts "$@"
  )
}

main() {
  ensure_mock_api
  prepare_wp_lane
  issue_wp_token
  load_relation_ids
  sweep_lane_queues
  flush_subsite_rewrites
  start_client
  setup_component_via_api
  run_spec "$@"
  pass "sites-subsite lane passed"
}

main "$@"
