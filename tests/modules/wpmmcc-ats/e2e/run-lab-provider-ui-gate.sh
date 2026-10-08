#!/usr/bin/env bash
# Independent gate: mock lab inventory → per-provider case JSON → WebUI Playwright
# (+ optional Desktop Tauri UI). No hardcoded vendor list.
#
#   bash tests/modules/wpmmcc-ats/e2e/run-lab-provider-ui-gate.sh
#   LAB_CASES_LIMIT=3 bash tests/modules/wpmmcc-ats/e2e/run-lab-provider-ui-gate.sh
#   RUN_DESKTOP_UI=1 LAB_CASES_LIMIT=3 bash tests/modules/wpmmcc-ats/e2e/run-lab-provider-ui-gate.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

WEBUI_A_BASE="${WEBUI_A_BASE:-http://127.0.0.1:8977}"
MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/ui-provider-mock"
mkdir -p "${REPORT_DIR}"

print_stage "E2E-INDEPENDENT" "Lab provider UI gate (mock inventory → UI fill)"

check_url "${MOCK_API_BASE}/api/v1/health" || abort "mock-api missing"
check_url "${MOCK_API_BASE}/api/v1/lab-provider-cases" || abort "rebuild/restart mock-api for lab-provider-cases"
check_url "${WEBUI_A_BASE}/api/status" || abort "WebUI agent missing at ${WEBUI_A_BASE}"

info "Sync per-provider scripts from mock…"
MOCK_API_BASE="${MOCK_API_BASE}" python3 "${REPO_ROOT}/tests/scripts/sync-lab-provider-ui-cases.py"

bash "${SCRIPT_DIR}/run-ui-provider-from-lab-cases.sh"

if [[ "${RUN_DESKTOP_UI:-0}" == "1" ]]; then
  info "Desktop Tauri UI from lab cases…"
  REPORT_DIR="${REPORT_DIR}" \
    MOCK_API_BASE="${MOCK_API_BASE}" \
    LAB_CASES_LIMIT="${LAB_CASES_LIMIT:-0}" \
    LAB_CASE_FILTER="${LAB_CASE_FILTER:-}" \
    bash "${REPO_ROOT}/tests/modules/client-desktop/tests/e2e/tauri-provider-wizard-from-lab-cases.sh"
fi

ok "Lab provider UI gate finished — reports in ${REPORT_DIR}"
