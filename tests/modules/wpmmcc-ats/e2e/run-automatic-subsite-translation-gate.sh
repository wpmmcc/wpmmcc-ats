#!/usr/bin/env bash
# Focused automatic lane: T1 subsite translation closed loop (2026-09-09
# audit gap ① + ④).
#
# Proves the wp-type relation end-to-end round trip on a real subsite
# (previously only covered incidentally by stale lab tasks):
#   - deterministic tasks via REST create_task on the wp relation
#   - owned direct-WP client claims them with a BOUNDED run-once
#     (max_items_per_run/max_iterations/max_elapsed_secs; stale lab tasks
#     are swept to 'cancelled' by the fixture so run-once stays bounded)
#   - provider round trip through the owned mock (counter must move)
#   - callback writeback ON THE SUBSITE: completed task, translation_results
#     row, post_mappings row, target post with 【xx_XX】 markers +
#     _wptsall_source_post_id identity meta
#   - subsite front-end HTTP 200 for the translated permalinks
#   - no stuck pending/retry tasks remain on the relation (audit gap ④)
#   - zero control-plane contact (recording canary)
#
# Owns its infrastructure exactly like the other automatic lanes: recording
# control-plane canary, one mock translate provider, one owned direct-WP
# client (control-plane flag 0). No product logic is copied; WordPress-side
# assertions live in php/subsite-translation-{fixture,verify}.php.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-automatic-subsite-translation-gate.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=lib/automatic-lane.sh
source "${SCRIPT_DIR}/lib/automatic-lane.sh"

AL_LANE_NAME="automatic-subsite-translation"

print_stage "SUPPORT" "Automatic Subsite Translation Gate (T1 wp relation, audit gap 1+4)"

FAIL=0

# ---------------------------------------------------------------------------
# 1. Owned infrastructure (canary + mock provider + direct-WP client)
# ---------------------------------------------------------------------------
auto_lane_init "${AL_LANE_NAME}"
auto_lane_plan_ports
auto_lane_preflight || exit 1
auto_lane_start_owned_infrastructure || exit 1
auto_lane_assert_client_mode "local" || exit 1
auto_lane_record_assertion "owned_direct_wp_client_local" "pass" "client pid ${AL_CLIENT_PID} port ${AL_CLIENT_PORT}, control-plane flag 0"

# ---------------------------------------------------------------------------
# 2. Direct-WP site binding (user-provided bootstrap, not website login)
# ---------------------------------------------------------------------------
auto_lane_lab_credentials || exit 1
auto_lane_upsert_site_binding "${AL_WP_URL}" "${AL_WP_CLIENT_TOKEN}" "${AL_WP_ROUTE_SECRET}" \
  || { auto_lane_finish; auto_lane_write_report; exit 1; }
auto_lane_record_assertion "direct_wp_site_binding" "pass" "${AL_WP_URL}"

# ---------------------------------------------------------------------------
# 3. Provider component: local openai_compatible component -> owned mock
# ---------------------------------------------------------------------------
COMPONENT_ID="subsite-lane-provider-$$"
create_res="$(curl --noproxy '*' -fsS -X POST "http://127.0.0.1:${AL_CLIENT_PORT}/api/components/local" \
  -H 'Content-Type: application/json' \
  -d "$(jq -n --arg id "${COMPONENT_ID}" --arg mock "http://127.0.0.1:${AL_MOCK_PORT}" \
    '{id: $id, name: "Subsite-lane mock provider", vendor_name: "subsite-lane", kind: "openai_compatible", enabled: true, api_base: $mock, model: "mock-translate-model"}')" 2>&1)" \
  || create_res="{\"error\":\"${create_res}\"}"
if echo "${create_res}" | jq -e '.success == true' >/dev/null 2>&1; then
  auto_lane_record_assertion "provider_component_created_local_inline" "pass" "${COMPONENT_ID}"
  auto_lane_fixture "component:${COMPONENT_ID}"
else
  auto_lane_record_assertion "provider_component_created_local_inline" "fail" "${create_res}"
  FAIL=1
fi

bind_res="$(curl --noproxy '*' -fsS -X POST "http://127.0.0.1:${AL_CLIENT_PORT}/api/components/bindings/upsert" \
  -H 'Content-Type: application/json' \
  -d "$(jq -n --arg id "${COMPONENT_ID}" '{component_id: $id, auth: {api_key: "mock-translate-dev-key-2026"}}')" 2>&1)" \
  || bind_res="{\"error\":\"${bind_res}\"}"
if echo "${bind_res}" | jq -e '.success == true' >/dev/null 2>&1; then
  auto_lane_record_assertion "provider_component_binding" "pass" "${COMPONENT_ID}"
else
  auto_lane_record_assertion "provider_component_binding" "fail" "${bind_res}"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 4. T1 topology: ensure multisite + active wp relation (idempotent)
# ---------------------------------------------------------------------------
ms_state="$(wp_cli eval 'echo is_multisite() ? "1" : "0";' 2>/dev/null | tr -d '[:space:]')"
if [[ "${ms_state}" != "1" ]]; then
  if bash "${SCRIPT_DIR}/scripts/lab-enable-multisite-t1.sh" >"${AL_REPORT_DIR}/t1-enable.log" 2>&1; then
    auto_lane_record_assertion "t1_multisite_ready" "pass" "enabled by lab-enable-multisite-t1.sh (log: t1-enable.log)"
  else
    auto_lane_record_assertion "t1_multisite_ready" "fail" "$(tail -3 "${AL_REPORT_DIR}/t1-enable.log" | tr '\n' ' ')"
    auto_lane_finish
    auto_lane_write_report
    exit 1
  fi
else
  auto_lane_record_assertion "t1_multisite_ready" "pass" "multisite already enabled"
fi

wp_rel_count="$(wp_cli eval 'global $wpdb; $t = wptsall_table("site_relations"); echo (string) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE target_site_type = \"wp\" AND status = \"active\"");' 2>/dev/null | tr -d '[:space:]')"
if [[ -z "${wp_rel_count}" || "${wp_rel_count}" == "0" ]]; then
  if ! wp_eval "${E2E_DIR}/php/setup-relations.php" >"${AL_REPORT_DIR}/setup-relations.log" 2>&1; then
    echo "setup-relations.php exited non-zero (see ${AL_REPORT_DIR}/setup-relations.log)" >&2
  fi
  wp_rel_count="$(wp_cli eval 'global $wpdb; $t = wptsall_table("site_relations"); echo (string) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE target_site_type = \"wp\" AND status = \"active\"");' 2>/dev/null | tr -d '[:space:]')"
fi
if [[ -n "${wp_rel_count}" && "${wp_rel_count}" != "0" ]]; then
  auto_lane_record_assertion "t1_wp_relation_ready" "pass" "active wp relations: ${wp_rel_count}"
else
  auto_lane_record_assertion "t1_wp_relation_ready" "fail" "no active wp relation after setup-relations.php"
  auto_lane_finish
  auto_lane_write_report
  exit 1
fi

# ---------------------------------------------------------------------------
# 5. Fixture: sweep stale tasks + 2 source posts + deterministic tasks
# ---------------------------------------------------------------------------
fixture_out="$(wp_eval "${E2E_DIR}/php/subsite-translation-fixture.php" 2>&1 || true)"
fixture_json="$(echo "${fixture_out}" | grep '^SUBSITE-FIXTURE-JSON: ' | tail -1 | cut -d' ' -f2- || true)"
if [[ -n "${fixture_json}" ]] && echo "${fixture_json}" | jq -e '.relation_id > 0 and .blog_id > 1 and (.posts | length == 2)' >/dev/null 2>&1; then
  swept_pending="$(echo "${fixture_json}" | jq -r '.swept.pending')"
  swept_retry="$(echo "${fixture_json}" | jq -r '.swept.retry')"
  rel_id="$(echo "${fixture_json}" | jq -r '.relation_id')"
  blog_id="$(echo "${fixture_json}" | jq -r '.blog_id')"
  auto_lane_record_assertion "subsite_fixture_ready" "pass" \
    "relation #${rel_id} → blog ${blog_id}, 2 posts + 2 pending tasks, swept stale pending=${swept_pending} retry=${swept_retry}"
  auto_lane_fixture "php:subsite-translation-fixture"
else
  auto_lane_record_assertion "subsite_fixture_ready" "fail" "$(echo "${fixture_out}" | grep '^SUBSITE-FIXTURE-FAIL: ' | head -1 || echo "${fixture_out}" | tail -2 | tr '\n' ' ')"
  auto_lane_finish
  auto_lane_write_report
  exit 1
fi

# ---------------------------------------------------------------------------
# 6. Restrict owned-client discovery to the wp relation, then bounded
#    worker run-once until both mappings carry a target post
# ---------------------------------------------------------------------------
# The lab carries ~170 relations; an unfiltered run-once spends minutes in
# per-relation outbox discovery and auto-discovered background tasks. The
# owned client is per-lane ephemeral, so disabling every discovery task
# except the wp relation keeps the closed loop deterministic and bounded.
bootstrap_res="$(curl --noproxy '*' -fsS --max-time 60 -X POST "http://127.0.0.1:${AL_CLIENT_PORT}/api/discovery-tasks/bootstrap" 2>&1 || true)"
if echo "${bootstrap_res}" | jq -e '.success == true' >/dev/null 2>&1; then
  auto_lane_record_assertion "discovery_tasks_bootstrapped" "pass" "rows synced from WP relations"
else
  auto_lane_record_assertion "discovery_tasks_bootstrapped" "fail" "${bootstrap_res}"
  FAIL=1
fi

disabled=0
keep_enabled=0
while read -r row_id row_rel; do
  [[ -z "${row_id}" ]] && continue
  if [[ "${row_rel}" == "${rel_id}" ]]; then
    keep_enabled=$(( keep_enabled + 1 ))
    continue
  fi
  if curl --noproxy '*' -fsS --max-time 10 -X PUT "http://127.0.0.1:${AL_CLIENT_PORT}/api/discovery-tasks/${row_id}" \
    -H 'Content-Type: application/json' -d '{"enabled": false}' >/dev/null 2>&1; then
    disabled=$(( disabled + 1 ))
  fi
done < <(curl --noproxy '*' -fsS --max-time 30 "http://127.0.0.1:${AL_CLIENT_PORT}/api/discovery-tasks" \
  | jq -r '.data.items[]? | "\(.id) \(.relation_id)"' 2>/dev/null || true)
if (( keep_enabled == 1 )); then
  auto_lane_record_assertion "discovery_scoped_to_wp_relation" "pass" \
    "disabled ${disabled} other relation(s); only relation #${rel_id} stays enabled"
else
  auto_lane_record_assertion "discovery_scoped_to_wp_relation" "fail" \
    "expected relation #${rel_id} in discovery tasks (found ${keep_enabled}); disabled ${disabled}"
  FAIL=1
fi

auto_lane_mock_stats_read before || true

map_count() {
  wp_cli eval 'global $wpdb; $t = wptsall_table("post_mappings"); $fx = json_decode((string) file_get_contents("/tmp/wptsall-subsite-gate-fixture.json"), true); $c = 0; foreach ($fx["posts"] as $p) { $c += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE relation_id = %d AND source_post_id = %d AND target_post_id > 0", (int) $fx["relation_id"], (int) $p["post_id"])); } echo (string) $c;' 2>/dev/null | tr -d '[:space:]'
}

worker_rounds_log="${AL_REPORT_DIR}/worker-run-once.log"
: >"${worker_rounds_log}"
round=0
max_rounds=4
while [[ ${round} -lt ${max_rounds} ]]; do
  round=$(( round + 1 ))
  run_once="$(curl --noproxy '*' -fsS --max-time 420 -X POST "http://127.0.0.1:${AL_CLIENT_PORT}/api/worker/run-once" \
    -H 'Content-Type: application/json' \
    -d '{"max_items_per_run": 8, "max_iterations": 6, "max_elapsed_secs": 300}')" \
    || run_once='{"error":"run-once request failed"}'
  echo "[round ${round}] ${run_once}" >>"${worker_rounds_log}"
  if ! echo "${run_once}" | jq -e '.success == true' >/dev/null 2>&1; then
    break
  fi
  mapped="$(map_count)"
  if [[ "${mapped}" == "2" ]]; then
    break
  fi
  sleep 3
done

# grep the file directly: `echo | grep -q` breaks under pipefail (SIGPIPE).
if grep -q '"success": *true' "${worker_rounds_log}"; then
  auto_lane_record_assertion "worker_run_once_bounded" "pass" \
    "bounded run-once (max_items_per_run=8, max_iterations=6, max_elapsed_secs=300) rounds=${round}, log: worker-run-once.log"
else
  auto_lane_record_assertion "worker_run_once_bounded" "fail" "no successful bounded run-once round"
  FAIL=1
fi

mapped="$(map_count)"
if [[ "${mapped}" == "2" ]]; then
  auto_lane_record_assertion "subsite_tasks_claimed_and_written" "pass" "2/2 post_mappings rows created for fixture posts"
else
  auto_lane_record_assertion "subsite_tasks_claimed_and_written" "fail" "post_mappings rows for fixture posts: ${mapped:-0}/2"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 7. WordPress-side closed-loop verification (task/result/mapping/subsite
#    post/markers/identity meta/stuck-task guard)
# ---------------------------------------------------------------------------
verify_out="$(wp_eval "${E2E_DIR}/php/subsite-translation-verify.php" 2>&1 || true)"
verify_json="$(echo "${verify_out}" | grep '^SUBSITE-VERIFY-JSON: ' | tail -1 | cut -d' ' -f2- || true)"
if [[ -n "${verify_json}" ]] && echo "${verify_json}" | jq -e '(.failed // 1) == 0 and (.passed // 0) > 0' >/dev/null 2>&1; then
  auto_lane_record_assertion "subsite_closed_loop_verified" "pass" \
    "$(echo "${verify_json}" | jq -r '"\(.passed) checks passed on blog \(.blog_id) (relation #\(.relation_id))"')"
  auto_lane_fixture "php:subsite-translation-verify"
else
  auto_lane_record_assertion "subsite_closed_loop_verified" "fail" \
    "$(echo "${verify_out}" | grep -E '^(FAIL|SUBSITE-VERIFY-FAIL):' | head -4 | tr '\n' ' ' || echo "${verify_out}" | tail -3 | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 8. Subsite front-end HTTP checks for translated permalinks
# ---------------------------------------------------------------------------
if [[ -n "${verify_json}" ]]; then
  while read -r permalink_url; do
    [[ -z "${permalink_url}" ]] && continue
    code="$(http_status "${permalink_url}" 20 || true)"
    if [[ "${code}" =~ ^2 ]]; then
      auto_lane_record_assertion "subsite_front_http_200" "pass" "${permalink_url} → ${code}"
    else
      auto_lane_record_assertion "subsite_front_http_200" "fail" "${permalink_url} → ${code}"
      FAIL=1
    fi
  done < <(echo "${verify_json}" | jq -r '.permalinks[]?.permalink // empty')
else
  auto_lane_record_assertion "subsite_front_http_200" "fail" "no verify JSON (verify stage failed)"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 9. Provider counter + control-plane canary
# ---------------------------------------------------------------------------
# Preserve the owned client log into the report dir for run-once diagnosis
# (the lane state root is removed by cleanup).
cp -f "${AL_STATE_ROOT}/state/client.log" "${AL_REPORT_DIR}/client.log" 2>/dev/null || true
cp -f "${AL_STATE_ROOT}/state/client-stderr.log" "${AL_REPORT_DIR}/client-stderr.log" 2>/dev/null || true

auto_lane_mock_stats_read after || true
if [[ "${AL_MOCK_REACHABLE}" == "true" ]]; then
  auto_lane_mock_stats_assert_min_total 1 || FAIL=1
else
  auto_lane_record_assertion "provider_counter_positive_delta" "fail" "mock provider unreachable"
  FAIL=1
fi

auto_lane_finish
if [[ "$(auto_lane_control_plane_canary_assert && echo 0 || echo 1)" == "1" ]]; then
  auto_lane_record_assertion "control_plane_canary_zero" "fail" "canary count ${AL_CANARY_COUNT}"
  FAIL=1
else
  auto_lane_record_assertion "control_plane_canary_zero" "pass" "no control-plane contact"
fi

# ---------------------------------------------------------------------------
# 10. Report
# ---------------------------------------------------------------------------
auto_lane_write_report
status="$(auto_lane_report_status)"
if [[ "${FAIL}" == "0" && "${status}" == "passed" ]]; then
  ok "automatic-subsite-translation gate PASSED (report: ${AL_REPORT_DIR}/lane-report.json)"
  exit 0
fi
err "automatic-subsite-translation gate FAILED (report: ${AL_REPORT_DIR}/lane-report.json)"
exit 1
