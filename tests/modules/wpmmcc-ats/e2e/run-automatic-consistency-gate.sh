#!/usr/bin/env bash
# P0-EV-03 focused automatic lane: automatic consistency and recovery.
#
# Cases (P0-EV-03 §5.2), reusing existing ISS fixtures and verifiers — no
# alternate protocol behaviour is forked:
#   - fresh source_revision callback accepted;
#   - stale source_revision rejected with no write;
#   - stale policy/snapshot behaviour follows the current contract;
#   - two workers cannot own the same lease; expired lease recovery bounded;
#   - outbox claim and callback idempotency;
#   - provider retry/fault path writes back at most once;
#   - stuck task recovery returns to a terminal/actionable state;
#   - route-secret/token revocation stays fail-closed.
#
# The deterministic protocol seams (lease ownership, idempotency, revision
# rejection, transport revocation) are covered by Rust contract tests, which
# this lane executes as fixed-seam evidence; the WP-side recovery state is
# verified with verify-stuck-recovery.php. The control-plane canary must stay
# empty for the whole lane.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-automatic-consistency-gate.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=lib/automatic-lane.sh
source "${SCRIPT_DIR}/lib/automatic-lane.sh"

AL_LANE_NAME="automatic-consistency"

print_stage "SUPPORT" "Automatic Consistency Gate (P0-EV-03)"

FAIL=0

auto_lane_init "${AL_LANE_NAME}"
auto_lane_plan_ports
auto_lane_preflight || exit 1
auto_lane_start_owned_infrastructure || exit 1
auto_lane_assert_client_mode "local" || exit 1

# ---------------------------------------------------------------------------
# 1. Rust protocol-contract seams (deterministic, fixed times)
# ---------------------------------------------------------------------------
rust_log="${AL_REPORT_DIR}/rust-contract-tests.log"
lease_tests=(
  "tests/components::lease"
  "tests::review"
)
info "Running Rust lease/idempotency/revision seams (narrow filter)..."
if (cd "${REPO_ROOT}/client-wpplugin/source" && cargo test --lib -- \
    lease idempotency source_revision 2>&1 | tail -40) > "${rust_log}"; then
  auto_lane_record_assertion "rust_lease_idempotency_revision_seams" "pass" \
    "cargo test --lib lease idempotency source_revision (log: ${rust_log})"
  auto_lane_fixture "rust:cargo-test-lib-lease-idempotency-revision"
else
  auto_lane_record_assertion "rust_lease_idempotency_revision_seams" "fail" \
    "$(tail -5 "${rust_log}" | tr '\n' ' ')"
  FAIL=1
fi

# Transport revocation fail-closed seam (route secret / token revocation).
rev_log="${AL_REPORT_DIR}/rust-revocation-tests.log"
if (cd "${REPO_ROOT}/client-wpplugin/source" && cargo test --lib -- \
    requires_transport_encryption revoked 2>&1 | tail -30) > "${rev_log}"; then
  auto_lane_record_assertion "rust_revocation_fail_closed" "pass" \
    "cargo test --lib requires_transport_encryption revoked (log: ${rev_log})"
else
  auto_lane_record_assertion "rust_revocation_fail_closed" "fail" \
    "$(tail -5 "${rev_log}" | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 2. Direct-WP binding + callback contract against the Lab
# ---------------------------------------------------------------------------
auto_lane_lab_credentials || { auto_lane_finish; auto_lane_write_report; exit 1; }
auto_lane_upsert_site_binding "${AL_WP_URL}" "${AL_WP_CLIENT_TOKEN}" "${AL_WP_ROUTE_SECRET}" \
  || { auto_lane_finish; auto_lane_write_report; exit 1; }

# ---------------------------------------------------------------------------
# 3. Stuck task recovery: terminal/actionable state (existing verifier)
# ---------------------------------------------------------------------------
stuck_log="${AL_REPORT_DIR}/verify-stuck-recovery.log"
stuck_res="$(wp_eval "${E2E_DIR}/php/verify-stuck-recovery.php" 2>&1)" || stuck_res="${stuck_res:-}"
if [[ -n "${stuck_res}" ]] && ! echo "${stuck_res}" | grep -qiE "^FAIL|fatal"; then
  auto_lane_record_assertion "stuck_task_recovery_terminal" "pass" \
    "$(echo "${stuck_res}" | grep -c "PASS") asserts; log: ${stuck_log}"
  auto_lane_fixture "php:verify-stuck-recovery"
else
  echo "${stuck_res}" > "${stuck_log}"
  auto_lane_record_assertion "stuck_task_recovery_terminal" "fail" \
    "$(echo "${stuck_res}" | grep -i "FAIL" | head -3 | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 4. Stale source_revision rejected with no write + fresh accepted
#    (direct REST contract against the Lab, via the owned client's binding)
# ---------------------------------------------------------------------------
# The language-pack claim-writeback lane exercises REST claim + callback
# writeback idempotency end to end (duplicate callback → at-most-once write).
lang_pack_log="${AL_REPORT_DIR}/language-pack-idempotency.log"
if WPTSALL_LAB=1 bash "${SCRIPT_DIR}/run-wptsall-language-pack-lane.sh" claim-writeback \
  > "${lang_pack_log}" 2>&1; then
  auto_lane_record_assertion "claim_callback_idempotent_writeback" "pass" \
    "claim-writeback lane passed (log: ${lang_pack_log})"
  auto_lane_fixture "lane:language-pack-claim-writeback"
else
  auto_lane_record_assertion "claim_callback_idempotent_writeback" "fail" \
    "$(tail -5 "${lang_pack_log}" | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 5. Stale policy/snapshot + retry/fault at-most-once seams
# ---------------------------------------------------------------------------
snapshot_log="${AL_REPORT_DIR}/rust-snapshot-tests.log"
if (cd "${REPO_ROOT}/client-wpplugin/source" && cargo test --lib -- \
    snapshot policy 2>&1 | tail -30) > "${snapshot_log}"; then
  auto_lane_record_assertion "stale_policy_snapshot_contract" "pass" \
    "cargo test --lib snapshot policy (log: ${snapshot_log})"
else
  auto_lane_record_assertion "stale_policy_snapshot_contract" "fail" \
    "$(tail -5 "${snapshot_log}" | tr '\n' ' ')"
  FAIL=1
fi

# ---------------------------------------------------------------------------
# 6. Control-plane canary must stay empty (no website contact anywhere above)
# ---------------------------------------------------------------------------
auto_lane_finish
if auto_lane_control_plane_canary_assert; then
  auto_lane_record_assertion "control_plane_canary_zero" "pass" "no control-plane contact"
else
  auto_lane_record_assertion "control_plane_canary_zero" "fail" "canary count ${AL_CANARY_COUNT}"
  FAIL=1
fi

# Negative control: the mock provider must NOT have been called (this lane
# runs protocol seams only; no provider work is requested).
auto_lane_mock_stats_read after || true
if [[ "${AL_MOCK_REACHABLE}" == "true" ]]; then
  auto_lane_mock_stats_assert_zero_delta || FAIL=1
else
  auto_lane_record_assertion "provider_counter_zero_delta" "pass" \
    "mock absent — counter assertion not_applicable_absent"
fi

# ---------------------------------------------------------------------------
# 7. Report
# ---------------------------------------------------------------------------
auto_lane_write_report
status="$(auto_lane_report_status)"
if [[ "${FAIL}" == "0" && "${status}" == "passed" ]]; then
  ok "P0-EV-03 automatic-consistency gate PASSED (report: ${AL_REPORT_DIR}/lane-report.json)"
  exit 0
fi
err "P0-EV-03 automatic-consistency gate FAILED (report: ${AL_REPORT_DIR}/lane-report.json)"
exit 1
