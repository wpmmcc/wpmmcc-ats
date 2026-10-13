#!/usr/bin/env bash
# Independent gate: sync lab cases from mock → WebUI Playwright (all catalog vendors).
# Parameters come from mock GET /api/v1/lab-provider-cases — not hardcoded.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-ui-provider-from-lab-cases.sh
#   LAB_CASES_LIMIT=5 bash tests/modules/wpmmcc-ats/e2e/run-ui-provider-from-lab-cases.sh   # smoke
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

print_stage "E2E-INDEPENDENT" "UI provider wizards from mock lab-provider-cases"

check_url "${MOCK_API_BASE}/api/v1/health" || abort "mock-api missing at ${MOCK_API_BASE}"
check_url "${MOCK_API_BASE}/api/v1/lab-provider-cases" || abort "lab-provider-cases endpoint missing — rebuild mock-api"
check_url "${WEBUI_A_BASE}/api/status" || abort "WebUI agent missing at ${WEBUI_A_BASE}"

info "Sync per-provider case JSON from mock…"
MOCK_API_BASE="${MOCK_API_BASE}" python3 "${REPO_ROOT}/tests/scripts/sync-lab-provider-ui-cases.py"

info "Playwright WebUI — fill from lab cases"
cd "${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
REPORT_DIR="${REPORT_DIR}" \
  MOCK_API_BASE="${MOCK_API_BASE}" \
  WEBUI_A_BASE="${WEBUI_A_BASE}" \
  CLIENT_BASE="${WEBUI_A_BASE}" \
  LAB_CASES_LIMIT="${LAB_CASES_LIMIT:-0}" \
  LAB_CASE_FILTER="${LAB_CASE_FILTER:-}" \
  LAB_INCLUDE_AUTH_PROFILES="${LAB_INCLUDE_AUTH_PROFILES:-0}" \
  LAB_SHARDS="${LAB_SHARDS:-1}" \
  LAB_SHARD_INDEX="${LAB_SHARD_INDEX:-0}" \
  npx playwright test -c playwright.ui-provider-mock.config.ts \
    ui-provider-mock/provider-wizard-from-lab-cases.spec.ts "$@"
