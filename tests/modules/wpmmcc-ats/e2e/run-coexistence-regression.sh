#!/usr/bin/env bash
# DUAL-PLUGIN COEXISTENCE regression orchestrator (doc 16 / ADR-7).
#
# Runs the coexistence-sensitive lanes in sequence against the SAME lab
# state (wpmmcc-ats + wpmmcc both active-network) and produces one combined
# report. This is the formalization of the ad-hoc coexistence evidence
# collected when ATS-B-01 landed: the lanes individually prove their slice;
# this orchestrator proves they hold TOGETHER, in one run, without cross-
# lane state bleed (each lane owns its ports and runtime dir).
#
# Stages (each skippable via SKIP_<STAGE>=1 for quick iterations):
#   preflight   both plugins active-network on the lab WP test container
#   wp-unit     full ATS unit suite with BOTH plugins active (2091+)
#   identity    run-identity-chain-gate.sh (T-ID-4..7, live slot-g wpmmcc
#               + ATS pairing-pack import)
#   roundtrip   run-client-content-roundtrip.sh (client ↔ WP product journey)
#   s1          run-client-s1-lifecycle.sh (Gutenberg fidelity + incremental
#               + taxonomy cascade, dual parallel clients)
#   desktop     doc 21 G1 stage 6: tauri-smoke + docker-wp-journey (Desktop
#               journeys through the real Tauri binary in Xvfb). The journey
#               is slot-isolated (slot-b :9182) per its own header guidance —
#               its stage-02 token issuance would otherwise invalidate the
#               .env.test fixture token the webui e2e gate depends on.
#               Auto-skips (recorded) when the desktop debug binary or
#               tauri-driver is absent, mirroring the G4 tauri-smoke
#               semantics; SKIP_DESKTOP=1 forces the skip.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-coexistence-regression.sh
#   SKIP_WP_UNIT=1 SKIP_S1=1 bash ...   # quick mode: preflight+identity+roundtrip
#   SKIP_DESKTOP=1 bash ...             # desktop-less environments
#
# Requires: WPTSALL_LAB=1 lab, client binary built, php-cli, docker lab up.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
export WPTSALL_LAB="${WPTSALL_LAB:-1}"

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/coexistence-regression"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${RUNTIME_DIR}/coexistence-regression"
mkdir -p "${REPORT_ROOT}" "${RUN_DIR}"
RESULTS_FILE="${RUN_DIR}/results.json"
echo '{"stages":{}}' >"${RESULTS_FILE}"

WP_TEST_CONTAINER="${WP_TEST_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"

record_stage() {
  # stage_id status(pass|fail|skip) detail log_path
  python3 - "$RESULTS_FILE" "$1" "$2" "$3" "$4" <<'PY'
import json, sys
path, stage, status, detail, log = sys.argv[1:6]
with open(path) as fh:
    doc = json.load(fh)
doc["stages"][stage] = {"status": status, "detail": detail, "log": log}
with open(path, "w") as fh:
    json.dump(doc, fh, indent=2)
PY
}

run_stage() {
  # run_stage <stage-id> <skip-flag> <timeout-secs> <log-name> <command...>
  local stage_id="$1" skip_flag="$2" timeout_secs="$3" log_name="$4"
  shift 4
  if [[ "${skip_flag}" == "1" ]]; then
    warn "${stage_id}: SKIP (flag set)"
    record_stage "${stage_id}" "skip" "skipped by flag" ""
    return 0
  fi
  local log_path="${RUN_DIR}/${log_name}"
  info "${stage_id}: starting (timeout ${timeout_secs}s, log ${log_path})"
  local t0 t1
  t0=$(date +%s)
  if timeout "${timeout_secs}" "$@" >"${log_path}" 2>&1; then
    t1=$(date +%s)
    ok "${stage_id}: PASS ($(( t1 - t0 ))s)"
    record_stage "${stage_id}" "pass" "$(( t1 - t0 ))s" "${log_path}"
    return 0
  else
    local rc=$?
    t1=$(date +%s)
    warn "${stage_id}: FAIL (rc=${rc}, $(( t1 - t0 ))s) — tail below; full log ${log_path}"
    tail -n 12 "${log_path}" || true
    record_stage "${stage_id}" "fail" "rc=${rc} after $(( t1 - t0 ))s" "${log_path}"
    STAGE_FAILURES=$(( STAGE_FAILURES + 1 ))
    return 0
  fi
}

STAGE_FAILURES=0

echo "══════════════════════════════════════════════════════════"
echo "  DUAL-PLUGIN COEXISTENCE REGRESSION (wpmmcc-ats + wpmmcc)"
echo "  stages: preflight, wp-unit, identity, roundtrip, s1, desktop"
echo "══════════════════════════════════════════════════════════"

# --- Stage: preflight -----------------------------------------------------
# The lab is multisite: coexistence plugins are NETWORK-activated, so the
# status check accepts any "active*" state (active, active-network, ...).
PREFLIGHT_LOG="${RUN_DIR}/preflight.log"
PREFLIGHT_OK=1
ACTIVE_ATS=""
ACTIVE_WPMMCC=""
{
  docker exec "${WP_TEST_CONTAINER}" wp plugin list --allow-root --path=/var/www/html \
    --fields=name,status --format=csv 2>/dev/null | tee /dev/stderr
} >"${PREFLIGHT_LOG}" 2>&1 || PREFLIGHT_OK=0
ACTIVE_ATS="$(rg '^wpmmcc-ats,active' "${PREFLIGHT_LOG}" || true)"
ACTIVE_WPMMCC="$(rg '^wpmmcc,active' "${PREFLIGHT_LOG}" || true)"
echo "active wpmmcc-ats: ${ACTIVE_ATS:-<none>}" >>"${PREFLIGHT_LOG}"
echo "active wpmmcc:     ${ACTIVE_WPMMCC:-<none>}" >>"${PREFLIGHT_LOG}"
if [[ "${PREFLIGHT_OK}" == "1" && -n "${ACTIVE_ATS}" && -n "${ACTIVE_WPMMCC}" ]]; then
  ok "preflight: PASS (both plugins active-network)"
  record_stage "preflight" "pass" "wpmmcc-ats + wpmmcc active" "${PREFLIGHT_LOG}"
else
  warn "preflight: FAIL — both plugins must be active-network for a coexistence run"
  record_stage "preflight" "fail" "expected wpmmcc-ats + wpmmcc active" "${PREFLIGHT_LOG}"
  STAGE_FAILURES=$(( STAGE_FAILURES + 1 ))
fi

# --- Stage: wp-unit (both plugins active) ---------------------------------
# Pin the main wordpress-test container explicitly: this stage's whole point
# is the unit suite running against the SHARED dual-plugin (wpmmcc-ats +
# wpmmcc active-network) coexistence state that preflight just verified.
# The wp-unit default environment is the isolated env-unit slot (slot-u,
# 2026-09-22, tasks/test/22 §3.4), which carries only wpmmcc-ats — without
# this pin the stage would silently stop exercising coexistence.
run_stage "wp-unit" "${SKIP_WP_UNIT:-0}" 1800 "wp-unit.log" \
  env WPTSALL_WP_UNIT_CONTAINER="${WP_TEST_CONTAINER}" \
  bash "${REPO_ROOT}/tests/scripts/run-wp-unit.sh"

# --- Stage: identity chain gate (T-ID-4..7) --------------------------------
run_stage "identity" "${SKIP_IDENTITY:-0}" 600 "identity-gate.log" \
  bash "${SCRIPT_DIR}/run-identity-chain-gate.sh"

# --- Stage: client content roundtrip ---------------------------------------
run_stage "roundtrip" "${SKIP_ROUNDTRIP:-0}" 900 "roundtrip.log" \
  bash "${SCRIPT_DIR}/run-client-content-roundtrip.sh"

# --- Stage: S1 lifecycle ----------------------------------------------------
run_stage "s1" "${SKIP_S1:-0}" 900 "s1.log" \
  bash "${SCRIPT_DIR}/run-client-s1-lifecycle.sh"

# --- Stage: desktop journeys (doc 21 G1, stage 6) ---------------------------
# Light → heavy: tauri-smoke (real window/DOM/navigation) then the full
# docker-wp-journey (WP data prep → device-token binding → mock component →
# UI-driven worker run → WP write-back verify → restart persistence). The
# journey runs against an isolated e2e slot (slot-b :9182) so its token
# issuance cannot poison the main-lab .env.test fixture other gates reuse.
DESKTOP_APP_BIN="${WPTSALL_DESKTOP_APP:-${REPO_ROOT}/client-desktop/src-tauri/target/debug/wptsall-desktop}"
DESKTOP_DRIVER="$(command -v tauri-driver || true)"
if [[ "${SKIP_DESKTOP:-0}" == "1" ]]; then
  warn "desktop: SKIP (SKIP_DESKTOP=1)"
  record_stage "desktop" "skip" "skipped by flag" ""
elif [[ ! -x "${DESKTOP_APP_BIN}" || -z "${DESKTOP_DRIVER}" ]]; then
  warn "desktop: SKIP (missing prerequisites — binary: ${DESKTOP_APP_BIN:-<none>}, driver: ${DESKTOP_DRIVER:-<none>})"
  record_stage "desktop" "skip" "prerequisites missing (desktop binary / tauri-driver)" ""
else
  run_stage "desktop" 0 2400 "desktop.log" \
    bash -c "
      set -e
      echo '=== desktop lane 1/2: tauri-smoke ==='
      bash ${ROOT_DIR}/tests/modules/client-desktop/tests/e2e/tauri-smoke.sh
      echo '=== desktop lane 2/2: docker-wp-journey (slot-b isolated) ==='
      LAB_WP_PORT=9182 LAB_WP_CONTAINER=wptsall-wp-lab-wordpress-slot-b \
        bash ${ROOT_DIR}/tests/modules/client-desktop/tests/e2e/docker-wp-journey.sh
    "
fi

# --- Aggregate -------------------------------------------------------------
python3 - "${RESULTS_FILE}" "${STAMP}" "${STAGE_FAILURES}" <<'PY'
import json, sys
path, stamp, failures = sys.argv[1], sys.argv[2], sys.argv[3]
doc = json.load(open(path))
doc["task"] = "coexistence-regression"
doc["stamp"] = stamp
doc["failed_stages"] = int(failures)
doc["pass"] = int(failures) == 0
json.dump(doc, open(path, "w"), indent=2)
PY
cp "${RESULTS_FILE}" "${REPORT_ROOT}/summary-${STAMP}.json"

echo "──────────────────────────────────────────────────────────"
python3 - "${RESULTS_FILE}" <<'PY'
import json, sys
doc = json.load(open(sys.argv[1]))
for name, st in doc["stages"].items():
    print(f"  {st['status'].upper():4}  {name:10} {st.get('detail','')}")
PY
if [[ "${STAGE_FAILURES}" == "0" ]]; then
  ok "COEXISTENCE REGRESSION PASS (report: ${REPORT_ROOT}/summary-${STAMP}.json)"
else
  warn "COEXISTENCE REGRESSION FAIL (${STAGE_FAILURES} stage(s); report: ${REPORT_ROOT}/summary-${STAMP}.json)"
  abort "coexistence regression failed"
fi
