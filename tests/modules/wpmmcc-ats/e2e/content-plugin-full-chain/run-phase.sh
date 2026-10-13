#!/usr/bin/env bash
# Run a single full-chain phase. Usage: run-phase.sh <phase_id> [project_id]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../lib/repo-root.sh"
E2E_DIR="$(wptsall_path e2e "${SCRIPT_DIR}")"
REPO_ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"
PHASE="${1:-}"
PROJECT_ID="${2:-}"

[[ -n "${PHASE}" ]] || { echo "Usage: $0 <phase_id> [project_id]" >&2; exit 2; }

unset E2E_SLOT_WP_ISOLATED || true
# Inherit slot from parent gate; default slot-a for ensure/slice.
export E2E_SLOT="${FULL_CHAIN_SLOT:-${E2E_SLOT:-slot-a}}"
if [[ "${E2E_SLOT}" == "shared" ]]; then
  export E2E_SLOT=slot-a
fi
export E2E_SLOT_WP_ISOLATED="${E2E_SLOT_WP_ISOLATED:-1}"
# shellcheck source=../config.sh
source "${E2E_DIR}/config.sh"
# Client UI agent (shared :8977) — do not use slot client port from config.sh.
export WEBUI_A_BASE="${FULL_CHAIN_CLIENT_BASE:-http://127.0.0.1:8977}"
export CLIENT_BASE="${FULL_CHAIN_CLIENT_BASE:-http://127.0.0.1:8977}"

REPORT_DIR="${FULL_CHAIN_REPORT_DIR:-${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/content-plugin-full-chain}"
mkdir -p "${REPORT_DIR}"
export FULL_CHAIN_REPORT_DIR="${REPORT_DIR}"

write_phase_result() {
  local phase="$1" ok="$2" detail="${3:-}"
  local stamp project
  stamp="$(date +%Y%m%dT%H%M%S)"
  project="${PROJECT_ID:-_global}"
  python3 - "$REPORT_DIR/phase-${phase}-${project}-${stamp}.json" "$phase" "$project" "$ok" "$detail" <<'PY'
import json, sys
path, phase, project, ok, detail = sys.argv[1:6]
open(path, "w").write(json.dumps({
  "phase": phase,
  "project_id": project,
  "ok": ok == "1",
  "detail": detail,
}, indent=2, ensure_ascii=False) + "\n")
print(path)
PY
}

case "${PHASE}" in
  p0|p0_preflight)
    print_stage "FULL-CHAIN" "P0 preflight slot=${E2E_SLOT} wp=${WP_URL}"
    check_url "${WP_URL}" || abort "WP missing at ${WP_URL}"
    check_url "${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}/api/v1/health" || abort "mock missing"
    check_url "${CLIENT_BASE:-http://127.0.0.1:8977}/api/status" || abort "Client missing at ${CLIENT_BASE}"
    write_phase_result p0_preflight 1 "wp=${WP_URL} client=${CLIENT_BASE} mock ok"
    ;;
  p1|p1_wp_plugins)
    print_stage "FULL-CHAIN" "P1 install content plugins (wp=${WP_URL} container=${LAB_WP_CONTAINER:-})"
    if [[ -x "${REPO_ROOT}/tests/docker-lab/scripts/install-content-plugins.sh" ]]; then
      LAB_WP_CONTAINER="${LAB_WP_CONTAINER:-}" \
        bash "${REPO_ROOT}/tests/docker-lab/scripts/install-content-plugins.sh" \
        || abort "install-content-plugins failed"
      write_phase_result p1_wp_plugins 1 "install-content-plugins ok container=${LAB_WP_CONTAINER:-default}"
    else
      warn "install-content-plugins.sh missing — skip"
      write_phase_result p1_wp_plugins 1 "skipped"
    fi
    ;;
  p2|p2_wp_wptsall_prep)
    [[ -n "${PROJECT_ID}" ]] || abort "p2 requires project_id"
    print_stage "FULL-CHAIN" "P2 ensure-plugin-project ${PROJECT_ID} slot=${E2E_SLOT}"
    ENSURE_SH="${REPO_ROOT}/tests/scripts/ensure-plugin-project.sh"
    if [[ -x "${ENSURE_SH}" ]]; then
      # ensure-plugin-project sets its own slot defaults; pass ours explicitly.
      WPTSALL_LAB=1 E2E_SLOT="${E2E_SLOT}" E2E_SLOT_WP_ISOLATED=1 \
        bash "${ENSURE_SH}" "${PROJECT_ID}" \
        || abort "ensure-plugin-project ${PROJECT_ID} failed"
      write_phase_result p2_wp_wptsall_prep 1 "ensure ${PROJECT_ID} slot=${E2E_SLOT}"
    else
      abort "tests/scripts/ensure-plugin-project.sh missing"
    fi
    ;;
  p3|p3_client_ui)
    print_stage "FULL-CHAIN" "P3 Client UI pre-wp-bind (client=${CLIENT_BASE} wp=${WP_URL})"
    # Export site fixture from the slot WP that P2/P4 use, then fill Client UI.
    PRE_WP_SITE_FIXTURE="${E2E_DIR}/client-ui-setup/pre-wp-bind/fixtures/site.local.json" \
      PRE_WP_WP_URL="${WP_URL}" PRE_WP_E2E_SLOT="${E2E_SLOT}" \
      PRE_WP_WP_CONTAINER="${LAB_WP_CONTAINER:-}" \
      WP_URL="${WP_URL}" LAB_WP_CONTAINER="${LAB_WP_CONTAINER:-}" \
      FULL_CHAIN_CLIENT_BASE="${CLIENT_BASE}" \
      WEBUI_A_BASE="${CLIENT_BASE}" CLIENT_BASE="${CLIENT_BASE}" \
      bash "${E2E_DIR}/client-ui-setup/pre-wp-bind/export-site-fixture.sh" \
      || abort "export-site-fixture failed for ${WP_URL}"
    PRE_WP_WP_URL="${WP_URL}" PRE_WP_E2E_SLOT="${E2E_SLOT}" \
      FULL_CHAIN_CLIENT_BASE="${CLIENT_BASE}" \
      WEBUI_A_BASE="${CLIENT_BASE}" CLIENT_BASE="${CLIENT_BASE}" \
      PRE_WP_SITE_FIXTURE="${E2E_DIR}/client-ui-setup/pre-wp-bind/fixtures/site.local.json" \
      bash "${E2E_DIR}/client-ui-setup/pre-wp-bind/run-gate.sh" \
      || abort "pre-wp-bind UI gate failed"
    write_phase_result p3_client_ui 1 "pre-wp-bind ok wp=${WP_URL}"
    ;;
  p3_touch|p3_client_ui_touch)
    [[ -n "${PROJECT_ID}" ]] || abort "p3_touch requires project_id"
    print_stage "FULL-CHAIN" "P3-touch Client UI per-project (${PROJECT_ID} client=${CLIENT_BASE})"
    cd "${E2E_DIR}/playwright"
    REPORT_DIR="${REPORT_DIR}" \
      FULL_CHAIN_PROJECT_ID="${PROJECT_ID}" \
      CLIENT_BASE="${CLIENT_BASE}" \
      WEBUI_A_BASE="${CLIENT_BASE}" \
      MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}" \
      npx playwright test -c playwright.client-ui-setup.config.ts \
        client-ui-setup/per-project-ui-touch.journey.spec.ts \
      || abort "per-project UI touch failed for ${PROJECT_ID}"
    write_phase_result p3_client_ui_touch 1 "ui touch ${PROJECT_ID}"
    ;;
  p3b|p3b_lab_provider)
    print_stage "FULL-CHAIN" "P3b lab-provider smoke"
    LAB_CASES_LIMIT="${LAB_CASES_LIMIT:-3}" bash "${E2E_DIR}/run-lab-provider-ui-gate.sh" \
      || abort "lab-provider UI gate failed"
    write_phase_result p3b_lab_provider 1 "lab-provider limit=${LAB_CASES_LIMIT:-3}"
    ;;
  p4|p4_translate_slice)
    [[ -n "${PROJECT_ID}" ]] || abort "p4 requires project_id"
    print_stage "FULL-CHAIN" "P4 translate slice ${PROJECT_ID} slot=${E2E_SLOT}"
    # Clear dual-UI agent port pollution (PORT overrides BIND in the client).
    unset WPTSALL_WEB_UI_BIND WPTSALL_WEB_UI_PORT || true
    if WPTSALL_LAB=1 E2E_SLOT="${E2E_SLOT}" E2E_SLOT_WP_ISOLATED=1 \
      bash "${E2E_DIR}/run-automatic-plugin-slice.sh" "${PROJECT_ID}"; then
      write_phase_result p4_translate_slice 1 "slice ${PROJECT_ID} slot=${E2E_SLOT}"
    else
      write_phase_result p4_translate_slice 0 "automatic-plugin-slice ${PROJECT_ID} failed"
      abort "automatic-plugin-slice ${PROJECT_ID} failed"
    fi
    ;;
  p5|p5_verify_markers)
    [[ -n "${PROJECT_ID}" ]] || abort "p5 requires project_id"
    print_stage "FULL-CHAIN" "P5 markers ${PROJECT_ID}"
    bash "${SCRIPT_DIR}/verify/run-marker-check.sh" "${PROJECT_ID}" \
      || abort "marker check ${PROJECT_ID} failed"
    ;;
  p6|p6_verify_front)
    [[ -n "${PROJECT_ID}" ]] || PROJECT_ID="wptsall-content"
    print_stage "FULL-CHAIN" "P6 front ${PROJECT_ID}"
    bash "${SCRIPT_DIR}/verify/run-front-asserts.sh" "${PROJECT_ID}" \
      || abort "front asserts ${PROJECT_ID} failed"
    if [[ "${FULL_CHAIN_JOURNEYS:-0}" == "1" ]]; then
      WPTSALL_LAB=1 E2E_PROJECTS="${PROJECT_ID}" \
        E2E_SKIP_ARCH_SEAM=1 FULL_CHAIN_SKIP_ARCH_SEAM=1 \
        FULL_CHAIN_JOURNEY_SOFT_404=1 \
        bash "${E2E_DIR}/run-content-plugin-journey-wave.sh" --skip-translate --jobs 1 \
        || abort "journey wave ${PROJECT_ID} failed"
      write_phase_result p6_verify_front_journeys 1 "journey ${PROJECT_ID}"
    fi
    ;;
  p7|p7_verify_menu_seo)
    print_stage "FULL-CHAIN" "P7 menu/SEO (wp=${WP_URL})"
    PRE_WP_WP_URL="${WP_URL}" PRE_WP_E2E_SLOT="${E2E_SLOT}" \
      FULL_CHAIN_MENU_SEO=1 MANUAL_ISOLATION_SKIP=1 \
      WPTSALL_LAB=1 bash "${E2E_DIR}/run-manual-only-multilingual-gate.sh" \
      || abort "manual-only multilingual gate failed"
    write_phase_result p7_verify_menu_seo 1 "manual-only ok wp=${WP_URL}"
    ;;
  *)
    abort "unknown phase: ${PHASE}"
    ;;
esac

ok "phase ${PHASE} done"
