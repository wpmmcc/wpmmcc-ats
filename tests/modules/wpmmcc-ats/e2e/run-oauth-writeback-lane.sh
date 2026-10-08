#!/usr/bin/env bash
# OAuth token loop + failure writeback lane (client todo #4 + fix #5 verification).
#
# Drives the full WP -> client -> mock round trip through an OAuth-bound
# component so the client's OAuthTokenManager must:
#   1. fetch a token from the mock /oauth/token endpoint (client_credentials),
#   2. inject it as Authorization: Bearer {{auth.access_token}},
#   3. have the mock profile ACCEPT the mock-issued JWT (requires the
#      JWT-accepting azure/google access-token verifiers),
#   4. write the successful translation back to WP (translation_results).
# Then it routes a second post through a static bad-bearer component to prove
# the failure writeback (fix #5: failed task -> WP failure record).
#
# Requires: Lab mode (WPTSALL_LAB=1), a slot WP whose models/relations are
# already ensured (e.g. after a full-chain gate run), debug binaries built.
#
# Usage:
#   WPTSALL_LAB=1 E2E_SLOT=slot-a bash tests/modules/wpmmcc-ats/e2e/run-oauth-writeback-lane.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
export WPTSALL_LAB="${WPTSALL_LAB:-1}"
export E2E_SLOT="${E2E_SLOT:-slot-a}"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=lib/automatic-lane.sh
source "${SCRIPT_DIR}/lib/automatic-lane.sh"

FAIL=0
OAUTH_CFG="oauth-azure-lane"
COMP_OK="oauth-azure-ok"
COMP_BAD="oauth-azure-bad"
STAMP="$(date -u +%H%M%S)"
POST_OK_TITLE="oauth-lane-ok-${STAMP}"
POST_BAD_TITLE="oauth-lane-bad-${STAMP}"

# Observability: timestamped per-request mock logging + phase marks, so lane
# wall-clock can be attributed to infra boot / WP evals / run-once phases.
export MOCK_LOG_ALL_REQUESTS="${MOCK_LOG_ALL_REQUESTS:-1}"
LANE_T0="$(date +%s)"
lane_mark() {
  al_note "$(date -u +%H:%M:%S) (+$(( $(date +%s) - LANE_T0 ))s) $*"
}
SECONDS=0

print_stage "LANE" "OAuth loop + failure writeback (slot=${E2E_SLOT})"

auto_lane_init "oauth-writeback" || exit 1
# P7 is fixed in the client: component_rt/loader.rs resolves vendor
# oauth/keys through config.rs single-authority functions (same functions
# the WebUI write API uses), so no env alignment workaround is needed
# anymore. The lane now runs the client exactly as a user deployment does.
lane_mark "lane init done (P7 unified paths: no env workaround)"
auto_lane_plan_ports
auto_lane_preflight || { auto_lane_finish; auto_lane_write_report; exit 1; }
auto_lane_start_owned_infrastructure || { auto_lane_finish; auto_lane_write_report; exit 1; }
lane_mark "owned infra ready (client=${AL_CLIENT_PORT} mock=${AL_MOCK_PORT} canary=${AL_CANARY_PORT})"
auto_lane_assert_client_mode "local" || FAIL=1
auto_lane_lab_credentials || { auto_lane_finish; auto_lane_write_report; exit 1; }
lane_mark "lab credentials resolved (wp eval boot cost included)"

# ---------------------------------------------------------------------------
# 2. Purge stale outbox backlog BEFORE the site binding exists (see comment).
#    The client WebUI auto-starts its worker loop (20s poll) as soon as a site
#    registry entry appears, so a purge after the binding races the auto-worker
#    and recently-claimed rows escape it. At this point NO live client is bound
#    to the slot (previous lane clients were killed by their cleanup traps),
#    so every pending row is stale campaign leftover and every processing row
#    is a dead-client lease (up to 900s).
# ---------------------------------------------------------------------------
PURGE="$(wp_eval_value "
if(true){
  \$g=\$GLOBALS['wpdb'];
  \$t=\$g->prefix.'wptsall_content_change_outbox';
  \$del1=(int)\$g->query(\"DELETE FROM {\$t} WHERE status='pending'\");
  \$del2=(int)\$g->query(\"DELETE FROM {\$t} WHERE status='processing'\");
  echo wp_json_encode(array('pending_deleted'=>\$del1,'dead_leases_deleted'=>\$del2));
}" 2>/dev/null || echo '{}')"
auto_lane_record_assertion "stale_outbox_backlog_purged" "pass" "${PURGE}"
lane_mark "outbox purge done: ${PURGE}"

auto_lane_upsert_site_binding "${AL_WP_URL}" "${AL_WP_CLIENT_TOKEN}" "${AL_WP_ROUTE_SECRET}" \
  || { auto_lane_finish; auto_lane_write_report; exit 1; }
lane_mark "site binding upserted (auto-worker wakes within 20s from now)"

MOCK="http://127.0.0.1:${AL_MOCK_PORT}"
CLIENT="http://127.0.0.1:${AL_CLIENT_PORT}"
api() { curl --noproxy '*' -fsS -H 'Content-Type: application/json' "$@"; }
# Inline eval-value helper (same as run-wptsall-language-pack-lane.sh; not in config.sh).
wp_eval_value() {
  local expression="$1"
  if _is_lab_mode; then
    wp_lab_docker_exec eval "${expression}" 2>/dev/null
  else
    (
      cd "${WP_ROOT}"
      "${WP_CLI}" eval "${expression}"
    ) 2>/dev/null
  fi
}

# ---------------------------------------------------------------------------
# 1. Mock self-check: JWT-accepting azure profile (P6 fix) + bad-secret 401
# ---------------------------------------------------------------------------
JWT="$(curl --noproxy '*' -fsS -X POST "${MOCK}/oauth/token" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'grant_type=client_credentials&client_id=mock-oauth-client&client_secret=mock-oauth-secret' \
  | python3 -c 'import json,sys;print(json.load(sys.stdin)["access_token"])')"
JWT_PARTS="$(python3 -c "print('${JWT}'.count('.')+1)" 2>/dev/null || echo 0)"
if [[ "${JWT_PARTS}" == "3" ]]; then
  auto_lane_record_assertion "mock_oauth_token_issued_jwt" "pass" "3-part HS256 JWT"
else
  auto_lane_record_assertion "mock_oauth_token_issued_jwt" "fail" "parts=${JWT_PARTS}"
  FAIL=1
fi

AZ_CODE="$(curl --noproxy '*' -s -o /tmp/oauth-lane-az.json -w '%{http_code}' \
  -X POST "${MOCK}/mock/profiles/azure-translator-oauth/translate?to=zh" \
  -H "Authorization: Bearer ${JWT}" -H 'Content-Type: application/json' \
  -d '[{"Text":"jwt self check"}]')"
if [[ "${AZ_CODE}" == "200" ]] && python3 -c 'import json;json.load(open("/tmp/oauth-lane-az.json"))[0]["translations"]' 2>/dev/null; then
  auto_lane_record_assertion "mock_profile_accepts_issued_jwt" "pass" "azure profile 200 with /oauth/token JWT (P6 fix)"
else
  auto_lane_record_assertion "mock_profile_accepts_issued_jwt" "fail" "HTTP ${AZ_CODE}"
  FAIL=1
fi

BADSEC_CODE="$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' -X POST "${MOCK}/oauth/token" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'grant_type=client_credentials&client_id=mock-oauth-client&client_secret=wrong-secret')"
if [[ "${BADSEC_CODE}" == "401" ]]; then
  auto_lane_record_assertion "mock_oauth_bad_secret_401" "pass" "invalid_client rejected"
else
  auto_lane_record_assertion "mock_oauth_bad_secret_401" "fail" "HTTP ${BADSEC_CODE}"
  FAIL=1
fi
lane_mark "mock self-checks done (JWT issue/accept + bad-secret 401)"

# ---------------------------------------------------------------------------
# 3. Owned client: oauth config + components + bindings
# ---------------------------------------------------------------------------
api -X POST "${CLIENT}/api/vendor-oauth" -d "$(python3 - "$MOCK" <<'PY'
import json, sys
mock = sys.argv[1]
print(json.dumps({
    "id": "oauth-azure-lane",
    "vendor_id": "azure_cognitive",
    "label": "OAuth lane azure (client_credentials)",
    "grant_type": "client_credentials",
    "auth_url": "",
    "token_url": f"{mock}/oauth/token",
    "client_id": "mock-oauth-client",
    "client_secret": "mock-oauth-secret",
    "scopes": "translate",
    "token_field": "access_token",
}))
PY
)" >/dev/null && auto_lane_record_assertion "oauth_config_created" "pass" "client_credentials -> ${MOCK}/oauth/token" \
  || { auto_lane_record_assertion "oauth_config_created" "fail" ""; FAIL=1; }

az_template() {
  python3 - "$1" <<'PY'
import json, sys
print(json.dumps({
    "id": "lab-azure-oauth-template",
    "name": "Lab Azure OAuth template",
    "type": "text_translation",
    "version": "1.0.0",
    "supported_types": ["text"],
    "auth": {"fields": []},
    "client_contract": {
        "input_mode": "text", "output_mode": "translated_text",
        "request_body_type": "json", "result_transport": "inline_json",
        "schema_version": "component-client-contract-v1",
        "stages": ["request"], "submit_response_type": "json",
        "task_kind": "text", "workflow_mode": "sync",
    },
    "constraints": {"max_input_chars": 5000, "supported_content_formats": ["plain_text"]},
    "request": {
        "url": sys.argv[1],
        "method": "POST", "body_type": "json",
        "headers": {
            "Content-Type": "application/json",
            "Authorization": "Bearer {{auth.access_token}}",
        },
        "body": [{"Text": "{{input.text}}"}],
    },
    "response": {"translated_text_path": "0.translations.0.text"},
}))
PY
}

COMPONENT_PAYLOAD_OK="$(az_template "${MOCK}/mock/profiles/azure-translator-oauth/translate?to={{input.target_lang}}")"
api -X POST "${CLIENT}/api/components/local" -d "$(python3 - "$COMPONENT_PAYLOAD_OK" <<'PY'
import json, sys
tpl = json.loads(sys.argv[1])
print(json.dumps({
    "id": "oauth-azure-ok", "name": "OAuth lane azure OK", "kind": "text",
    "vendor_id": "azure_cognitive", "vendor_name": "azure_cognitive",
    "enabled": True, "template_json": tpl,
}))
PY
)" >/dev/null && auto_lane_record_assertion "oauth_component_created" "pass" "template Bearer {{auth.access_token}} -> azure profile" \
  || { auto_lane_record_assertion "oauth_component_created" "fail" ""; FAIL=1; }

api -X POST "${CLIENT}/api/components/bindings/upsert" -d \
  '{"component_id":"oauth-azure-ok","auth":{},"key_ids":[],"oauth_ids":["oauth-azure-lane"]}' \
  >/dev/null && auto_lane_record_assertion "oauth_binding_oauth_ids" "pass" "binding oauth_ids=[oauth-azure-lane]" \
  || { auto_lane_record_assertion "oauth_binding_oauth_ids" "fail" ""; FAIL=1; }

api -X POST "${CLIENT}/api/rule-component-bindings/upsert" -d \
  '{"scope":"global","slot_key":"plain_text","component_id":"oauth-azure-ok"}' \
  >/dev/null && auto_lane_record_assertion "oauth_rule_slot_binding" "pass" "plain_text->oauth-azure-ok" \
  || { auto_lane_record_assertion "oauth_rule_slot_binding" "fail" ""; FAIL=1; }

# Failure component: same template, static INVALID bearer, no oauth pool.
COMPONENT_PAYLOAD_BAD="$(az_template "${MOCK}/mock/profiles/azure-translator-oauth/translate?to={{input.target_lang}}")"
api -X POST "${CLIENT}/api/components/local" -d "$(python3 - "$COMPONENT_PAYLOAD_BAD" <<'PY'
import json, sys
tpl = json.loads(sys.argv[1])
print(json.dumps({
    "id": "oauth-azure-bad", "name": "OAuth lane azure BAD bearer", "kind": "text",
    "vendor_id": "azure_cognitive", "vendor_name": "azure_cognitive",
    "enabled": True, "template_json": tpl,
}))
PY
)" >/dev/null && auto_lane_record_assertion "bad_bearer_component_created" "pass" "static bad bearer component" \
  || { auto_lane_record_assertion "bad_bearer_component_created" "fail" ""; FAIL=1; }

api -X POST "${CLIENT}/api/components/bindings/upsert" -d \
  '{"component_id":"oauth-azure-bad","auth":{"access_token":"mock-invalid-oauth-token"},"key_ids":[],"oauth_ids":[]}' \
  >/dev/null && auto_lane_record_assertion "bad_bearer_binding" "pass" "static invalid bearer auth" \
  || { auto_lane_record_assertion "bad_bearer_binding" "fail" ""; FAIL=1; }
lane_mark "client oauth config + components + bindings done"

# ---------------------------------------------------------------------------
# 4. Phase A: success round trip (post -> task -> oauth component -> writeback)
# ---------------------------------------------------------------------------
auto_lane_mock_stats_read before || true

POST_OK_ID="$(wp_cli post create \
  --post_title="${POST_OK_TITLE}" \
  --post_content="OAuth lane success probe ${STAMP}: the quick brown fox." \
  --post_status=publish --porcelain 2>/dev/null | tr -d '\r' || true)"
if [[ "${POST_OK_ID}" =~ ^[0-9]+$ ]]; then
  auto_lane_record_assertion "success_post_created" "pass" "post_id=${POST_OK_ID} title=${POST_OK_TITLE}"
  auto_lane_fixture "wp:post:${POST_OK_ID}"
else
  auto_lane_record_assertion "success_post_created" "fail" "${POST_OK_ID}"
  FAIL=1
fi
lane_mark "post A created id=${POST_OK_ID} (wp post create cost included)"

RUN_T0=$SECONDS
RUN_OK="$(curl --noproxy '*' -fsS --max-time 300 -X POST "${CLIENT}/api/worker/run-once" \
  -H 'Content-Type: application/json' \
  -d '{"max_elapsed_secs": 240, "max_items_per_run": 40}' 2>&1 || true)"
lane_mark "run-once A returned in $(( SECONDS - RUN_T0 ))s"
if echo "${RUN_OK}" | jq -e '.success == true' >/dev/null 2>&1; then
  auto_lane_record_assertion "worker_run_once_success_phase" "pass" \
    "$(echo "${RUN_OK}" | jq -c '.data // {}' | head -c 160)"
else
  auto_lane_record_assertion "worker_run_once_success_phase" "fail" "${RUN_OK}"
  FAIL=1
fi

auto_lane_mock_stats_read after || true
OAUTH_HITS="$(echo "${AL_MOCK_STATS_AFTER}" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
    keys = d.get("keys", {})
    hits = sum(v.get("total_requests", 0) for k, v in keys.items() if k.startswith("oauth:"))
    print(hits)
except Exception:
    print(-1)
' 2>/dev/null || echo -1)"
if [[ "${OAUTH_HITS}" -ge 1 ]]; then
  auto_lane_record_assertion "oauth_token_fetched_via_worker" "pass" \
    "mock stats oauth:* requests=${OAUTH_HITS} (client auto token fetch)"
else
  auto_lane_record_assertion "oauth_token_fetched_via_worker" "fail" "oauth hits=${OAUTH_HITS}"
  FAIL=1
fi

OK_RESULT="$(wp_eval_value "
if(true){
  \$g=\$GLOBALS['wpdb'];
  \$t=function_exists('wptsall_table')?wptsall_table('translation_results'):\$g->prefix.'wptsall_translation_results';
  \$rows=\$g->get_results(\"SELECT status, LEFT(translated_fields,60) AS snippet, target_lang FROM {\$t} WHERE translated_fields LIKE '%${POST_OK_TITLE}%' ORDER BY id DESC LIMIT 2\", ARRAY_A);
  echo wp_json_encode(\$rows);
}" 2>/dev/null || echo '[]')"
if echo "${OK_RESULT}" | jq -e 'length > 0 and (.[0].status == "synced" or .[0].status == "completed")' >/dev/null 2>&1; then
  auto_lane_record_assertion "oauth_success_writeback" "pass" \
    "$(echo "${OK_RESULT}" | jq -c '.[0] | {status, snippet, target_lang}')"
else
  auto_lane_record_assertion "oauth_success_writeback" "fail" "rows=${OK_RESULT}"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 5. Phase B: failure round trip (bad bearer -> provider 401 -> failure writeback, fix #5)
# ---------------------------------------------------------------------------
api -X POST "${CLIENT}/api/rule-component-bindings/upsert" -d \
  '{"scope":"global","slot_key":"plain_text","component_id":"oauth-azure-bad"}' >/dev/null

POST_BAD_ID="$(wp_cli post create \
  --post_title="${POST_BAD_TITLE}" \
  --post_content="OAuth lane failure probe ${STAMP}: this must fail and write back." \
  --post_status=publish --porcelain 2>/dev/null | tr -d '\r' || true)"
if [[ "${POST_BAD_ID}" =~ ^[0-9]+$ ]]; then
  auto_lane_record_assertion "failure_post_created" "pass" "post_id=${POST_BAD_ID} title=${POST_BAD_TITLE}"
  auto_lane_fixture "wp:post:${POST_BAD_ID}"
else
  auto_lane_record_assertion "failure_post_created" "fail" "${POST_BAD_ID}"
  FAIL=1
fi
lane_mark "post B created id=${POST_BAD_ID}"

RUN_T0=$SECONDS
# Failure evidence (outbox last_error) lands on the FIRST failed cycle; a long
# budget would only burn time re-claiming returned-to-pending failures.
RUN_BAD="$(curl --noproxy '*' -fsS --max-time 90 -X POST "${CLIENT}/api/worker/run-once" \
  -H 'Content-Type: application/json' \
  -d '{"max_elapsed_secs": 45, "max_items_per_run": 10}' 2>&1 || true)"
lane_mark "run-once B returned in $(( SECONDS - RUN_T0 ))s"
if echo "${RUN_BAD}" | jq -e '.success == true' >/dev/null 2>&1; then
  auto_lane_record_assertion "worker_run_once_failure_phase" "pass" \
    "$(echo "${RUN_BAD}" | jq -c '.data // {}' | head -c 160)"
else
  auto_lane_record_assertion "worker_run_once_failure_phase" "fail" "${RUN_BAD}"
  FAIL=1
fi

BAD_RESULT="$(wp_eval_value "
if(true){
  \$g=\$GLOBALS['wpdb'];
  \$t=function_exists('wptsall_table')?wptsall_table('content_change_outbox'):\$g->prefix.'wptsall_content_change_outbox';
  \$rows=\$g->get_results(\"SELECT id, status, LEFT(last_error,80) AS err FROM {\$t} WHERE source_type='post' AND source_id='${POST_BAD_ID}' AND last_error <> '' ORDER BY id DESC LIMIT 3\", ARRAY_A);
  echo wp_json_encode(\$rows);
}" 2>/dev/null || echo '[]')"
if echo "${BAD_RESULT}" | jq -e 'length > 0' >/dev/null 2>&1; then
  auto_lane_record_assertion "failure_writeback_fix5" "pass" \
    "$(echo "${BAD_RESULT}" | jq -c '.[0]') (outbox last_error recorded, fix #5)"
else
  auto_lane_record_assertion "failure_writeback_fix5" "fail" "no outbox last_error rows for post ${POST_BAD_ID}"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 6. Finish
# ---------------------------------------------------------------------------
lane_mark "all result checks done (total $(( SECONDS ))s)"
auto_lane_finish
auto_lane_write_report

if [[ "${FAIL}" == "0" ]]; then
  ok "oauth-writeback lane: ALL PASS (report: ${AL_REPORT_DIR})"
  exit 0
fi
err "oauth-writeback lane: FAILURES present (report: ${AL_REPORT_DIR})"
exit 1
