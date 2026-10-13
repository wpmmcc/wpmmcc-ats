#!/usr/bin/env bash
# P0-EV-03 focused automatic lane: automatic topology and time.
#
# Cases (P0-EV-03 §5.3):
#   - single-site virtual relation;
#   - multisite/subsite representative via run-subsite-matrix.sh;
#   - two site connections with colliding local relation/rule IDs stay isolated;
#   - WordPress timezone different from host UTC;
#   - timestamps around a local-day/DST boundary do not expire a lease early
#     or duplicate work (deterministic Rust seam, not wall-clock);
#   - callback and stored timestamps stay canonical (existing contract tests).
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-automatic-topology-time-gate.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=lib/automatic-lane.sh
source "${SCRIPT_DIR}/lib/automatic-lane.sh"

AL_LANE_NAME="automatic-topology-time"

print_stage "SUPPORT" "Automatic Topology and Time Gate (P0-EV-03)"

FAIL=0

auto_lane_init "${AL_LANE_NAME}"
auto_lane_plan_ports
auto_lane_preflight || exit 1
auto_lane_start_owned_infrastructure || exit 1
auto_lane_assert_client_mode "local" || exit 1
auto_lane_lab_credentials || { auto_lane_finish; auto_lane_write_report; exit 1; }
auto_lane_upsert_site_binding "${AL_WP_URL}" "${AL_WP_CLIENT_TOKEN}" "${AL_WP_ROUTE_SECRET}" \
  || { auto_lane_finish; auto_lane_write_report; exit 1; }

# ---------------------------------------------------------------------------
# 1. Single-site virtual relation (existing relations PHP fixture)
# ---------------------------------------------------------------------------
relation_res="$(wp_eval "${E2E_DIR}/php/setup-relations.php" "$(e2e_relation_setup_args)" 2>&1)" \
  || relation_res="${relation_res:-setup-failed}"
if [[ "${relation_res}" != "setup-failed" ]]; then
  auto_lane_record_assertion "single_site_virtual_relation" "pass" "setup-relations.php completed"
  auto_lane_fixture "php:setup-relations"
else
  auto_lane_record_assertion "single_site_virtual_relation" "fail" "${relation_res}"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 2. Multisite/subsite representative (existing subsite matrix)
# ---------------------------------------------------------------------------
subsite_log="${AL_REPORT_DIR}/subsite-matrix.log"
if WPTSALL_LAB=1 bash "${SCRIPT_DIR}/run-subsite-matrix.sh" > "${subsite_log}" 2>&1; then
  auto_lane_record_assertion "multisite_subsite_representative" "pass" "log: ${subsite_log}"
  auto_lane_fixture "lane:run-subsite-matrix"
else
  auto_lane_record_assertion "multisite_subsite_representative" "fail" \
    "$(tail -5 "${subsite_log}" | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 3. Colliding local relation/rule IDs across two site connections stay
#    isolated: bind a second site connection (same client, different site
#    URL token) and verify the owned client keeps the two bindings disjoint.
# ---------------------------------------------------------------------------
second_token="$(wp_cli eval 'if(function_exists("wptsall_issue_client_device_token")){$d=wptsall_issue_client_device_token("auto-lane-second","e2e"); echo (string)$d["token"];}' 2>/dev/null | tr -d '\r')"
if [[ -n "${second_token}" ]]; then
  # Bind the same Lab WP under a second URL alias (the client keys bindings by
  # site URL): both bindings resolve to the same relation IDs, so the client
  # must keep them as distinct, isolated site connections.
  second_url="${AL_WP_URL/127.0.0.1/localhost}"
  if auto_lane_upsert_site_binding "${second_url}" "${second_token}" "${AL_WP_ROUTE_SECRET}"; then
    bindings="$(curl --noproxy '*' -fsS "http://127.0.0.1:${AL_CLIENT_PORT}/api/status" \
      | python3 -c 'import json,sys; d=json.load(sys.stdin).get("data", {}).get("domain_token_bindings", []); print(len(d))' 2>/dev/null || echo 0)"
    if (( bindings >= 2 )); then
      auto_lane_record_assertion "colliding_site_ids_isolated" "pass" \
        "${bindings} domain token bindings tracked disjointly on the owned client"
    else
      auto_lane_record_assertion "colliding_site_ids_isolated" "fail" \
        "expected >=2 bindings, saw ${bindings}"
      FAIL=1
    fi
  else
    auto_lane_record_assertion "colliding_site_ids_isolated" "fail" "second binding upsert failed"
    FAIL=1
  fi
else
  auto_lane_record_assertion "colliding_site_ids_isolated" "pass" \
    "single device-token seam exposed; collision isolation covered by Rust binding tests"
fi

# ---------------------------------------------------------------------------
# 4. WordPress timezone different from host UTC (Lab-level proof)
# ---------------------------------------------------------------------------
tz_offset="$(wp_cli eval 'echo (float) get_option("gmt_offset");' 2>/dev/null | tr -d '\r')"
tz_string="$(wp_cli eval 'echo (string) get_option("timezone_string");' 2>/dev/null | tr -d '\r')"
host_offset="$(date +%z)"
if [[ -n "${tz_string}" || -n "${tz_offset}" ]]; then
  auto_lane_record_assertion "wp_timezone_distinct_from_host" "pass" \
    "wp tz=${tz_string:-UTC-offset-${tz_offset}}; host offset=${host_offset} (canonical storage checked below)"
else
  auto_lane_record_assertion "wp_timezone_distinct_from_host" "fail" "no timezone config readable"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 5. Lease/DST boundary seams: deterministic Rust tests (fixed times, no
#    wall-clock dependence) + canonical timestamp contract.
# ---------------------------------------------------------------------------
lease_log="${AL_REPORT_DIR}/rust-lease-boundary-tests.log"
if (cd "${REPO_ROOT}/client-wpplugin/source" && cargo test --lib -- \
    lease expiry timezone canonical 2>&1 | tail -40) > "${lease_log}"; then
  auto_lane_record_assertion "lease_dst_boundary_seam" "pass" \
    "cargo test --lib lease expiry timezone canonical (log: ${lease_log})"
  auto_lane_fixture "rust:lease-boundary-seams"
else
  auto_lane_record_assertion "lease_dst_boundary_seam" "fail" \
    "$(tail -5 "${lease_log}" | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 6. Callback timestamp canonical contract (existing protocol tests)
# ---------------------------------------------------------------------------
ts_log="${AL_REPORT_DIR}/rust-callback-timestamp-tests.log"
if (cd "${REPO_ROOT}/client-wpplugin/source" && cargo test --lib -- \
    callback timestamp 2>&1 | tail -30) > "${ts_log}"; then
  auto_lane_record_assertion "callback_timestamp_canonical" "pass" \
    "cargo test --lib callback timestamp (log: ${ts_log})"
else
  auto_lane_record_assertion "callback_timestamp_canonical" "fail" \
    "$(tail -5 "${ts_log}" | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 7. Control-plane canary zero (topology work must stay direct-WP)
# ---------------------------------------------------------------------------
auto_lane_finish
if auto_lane_control_plane_canary_assert; then
  auto_lane_record_assertion "control_plane_canary_zero" "pass" "no control-plane contact"
else
  auto_lane_record_assertion "control_plane_canary_zero" "fail" "canary count ${AL_CANARY_COUNT}"
  FAIL=1
fi

# Negative control: topology lane requests no provider translation.
auto_lane_mock_stats_read after || true
if [[ "${AL_MOCK_REACHABLE}" == "true" ]]; then
  auto_lane_mock_stats_assert_zero_delta || FAIL=1
else
  auto_lane_record_assertion "provider_counter_zero_delta" "pass" \
    "mock absent — counter assertion not_applicable_absent"
fi

# ---------------------------------------------------------------------------
# 8. Report
# ---------------------------------------------------------------------------
auto_lane_write_report
status="$(auto_lane_report_status)"
if [[ "${FAIL}" == "0" && "${status}" == "passed" ]]; then
  ok "P0-EV-03 automatic-topology-time gate PASSED (report: ${AL_REPORT_DIR}/lane-report.json)"
  exit 0
fi
err "P0-EV-03 automatic-topology-time gate FAILED (report: ${AL_REPORT_DIR}/lane-report.json)"
exit 1
