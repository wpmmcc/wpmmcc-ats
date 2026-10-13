#!/usr/bin/env bash
# Gate: Client UI setup before WP plugin对接 — form fill via Playwright only.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# e2e sits FOUR levels below the repo root (root → tests → modules →
# wpmmcc-ats → e2e). Three ups resolved to tests/ and made this gate write
# its reports to the ghost path tests/tests/reports/... (doctor probe
# "tests/tests/ ghost dir regrew"; last live writes 2026-09-17). Keep the
# sibling scripts' shape (run-architecture-seam-gate.sh et al.).
REPO_ROOT="$(cd "${E2E_DIR}/../../../.." && pwd)"
CALLER_WP_URL="${PRE_WP_WP_URL:-${WP_URL:-}}"
CALLER_SLOT="${PRE_WP_E2E_SLOT:-${E2E_SLOT:-}}"
CALLER_CLIENT="${FULL_CHAIN_CLIENT_BASE:-${WEBUI_A_BASE:-${CLIENT_BASE:-}}}"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
if [[ -n "${CALLER_SLOT}" && "${CALLER_SLOT}" != "shared" ]]; then
  export E2E_SLOT="${CALLER_SLOT}"
  export E2E_SLOT_WP_ISOLATED=1
else
  export E2E_SLOT="${E2E_SLOT:-shared}"
fi
# shellcheck source=../../config.sh
source "${E2E_DIR}/config.sh"
if [[ -n "${CALLER_WP_URL}" ]]; then
  export WP_URL="${CALLER_WP_URL}"
fi

CLIENT_BASE="${CALLER_CLIENT:-${WEBUI_A_BASE:-${CLIENT_BASE:-http://127.0.0.1:8977}}}"
MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}"
FIXTURE="${PRE_WP_SITE_FIXTURE:-${SCRIPT_DIR}/fixtures/site.local.json}"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/client-ui-setup/pre-wp-bind"
mkdir -p "${REPORT_DIR}"

print_stage "E2E-INDEPENDENT" "pre-wp-bind Client UI (Sites → provider → rule bind → Run Once) wp=${WP_URL} client=${CLIENT_BASE}"

check_url "${WP_URL}" || abort "WP Lab missing at ${WP_URL}"
check_url "${MOCK_API_BASE}/api/v1/health" || abort "mock-api missing at ${MOCK_API_BASE}"
check_url "${CLIENT_BASE}/api/status" || abort "Client WebUI missing at ${CLIENT_BASE}"

if [[ ! -f "${FIXTURE}" ]]; then
  info "No fixture at ${FIXTURE} — exporting from Lab WP…"
  PRE_WP_SITE_FIXTURE="${FIXTURE}" PRE_WP_WP_URL="${WP_URL}" PRE_WP_E2E_SLOT="${E2E_SLOT}" \
    bash "${SCRIPT_DIR}/export-site-fixture.sh"
fi
[[ -f "${FIXTURE}" ]] || abort "fixture missing: ${FIXTURE}"

# Fixture freshness/consistency guard (audit finding): a stale fixture pointing
# at a different lab WP (e.g. a recycled slot) or carrying a token issued for
# another device produces 401 client_unauthorized mid-journey. Validate target
# URL + device_id + age; any mismatch triggers a re-export instead of a
# silent soft-failure journey.
if [[ -f "${FIXTURE}" ]]; then
  FIXTURE_OK=1
  FIXTURE_URL="$(jq -r '.api_base_url // empty' "${FIXTURE}" 2>/dev/null || true)"
  if [[ -z "${FIXTURE_URL}" || "${FIXTURE_URL%/}" != "${WP_URL%/}" ]]; then
    warn "fixture targets ${FIXTURE_URL:-?} but run targets ${WP_URL} — re-exporting"
    FIXTURE_OK=0
  fi
  RUN_DEVICE_ID="$(curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" 2>/dev/null | jq -r '.data.device_id // empty' 2>/dev/null || true)"
  FIXTURE_DEVICE_ID="$(jq -r '.device_id // empty' "${FIXTURE}" 2>/dev/null || true)"
  if [[ -n "${RUN_DEVICE_ID}" && -n "${FIXTURE_DEVICE_ID}" && "${RUN_DEVICE_ID}" != "${FIXTURE_DEVICE_ID}" ]]; then
    warn "fixture device ${FIXTURE_DEVICE_ID} differs from client ${RUN_DEVICE_ID} — re-exporting"
    FIXTURE_OK=0
  fi
  # Age window MUST stay under the WP device-token TTL
  # (Client_Token_Service::DEFAULT_DEVICE_TOKEN_TTL = 3600s). A fixture older
  # than the token lifetime carries an expired credential and fails Test
  # Connection with client_unauthorized. Default 3000s (50 min) leaves a safety
  # margin; override with PRE_WP_FIXTURE_MAX_AGE_SECONDS when WP runs a
  # non-default TTL.
  MAX_AGE="${PRE_WP_FIXTURE_MAX_AGE_SECONDS:-3000}"
  FIXTURE_AGE="$(jq -r '.exported_at // empty' "${FIXTURE}" 2>/dev/null || true)"
  FIXTURE_TS="$(date -u -d "${FIXTURE_AGE}" +%s 2>/dev/null || echo 0)"
  NOW_TS="$(date -u +%s)"
  if [[ -z "${FIXTURE_AGE}" ]] || (( NOW_TS - FIXTURE_TS > MAX_AGE )); then
    warn "fixture age exceeds ${MAX_AGE}s (exported_at=${FIXTURE_AGE:-missing}) — re-exporting"
    FIXTURE_OK=0
  fi
  if [[ "${FIXTURE_OK}" != "1" ]]; then
    PRE_WP_SITE_FIXTURE="${FIXTURE}" PRE_WP_WP_URL="${WP_URL}" PRE_WP_E2E_SLOT="${E2E_SLOT}" \
      bash "${SCRIPT_DIR}/export-site-fixture.sh"
    [[ -f "${FIXTURE}" ]] || abort "fixture re-export failed: ${FIXTURE}"
    ok "fixture re-exported fresh at ${FIXTURE}"
  fi
fi

HEADED_ARGS=()
if [[ "${HEADED:-0}" == "1" ]]; then
  HEADED_ARGS=(--headed)
fi

cd "${E2E_DIR}/playwright"
REPORT_DIR="${REPORT_DIR}" \
  PRE_WP_SITE_FIXTURE="${FIXTURE}" \
  CLIENT_BASE="${CLIENT_BASE}" \
  WEBUI_A_BASE="${CLIENT_BASE}" \
  MOCK_API_BASE="${MOCK_API_BASE}" \
  SKIP_PROVIDER="${SKIP_PROVIDER:-0}" \
  PRE_WP_COMPONENT_ID="${PRE_WP_COMPONENT_ID:-}" \
  npx playwright test -c playwright.client-ui-setup.config.ts \
    client-ui-setup/pre-wp-bind.journey.spec.ts \
    "${HEADED_ARGS[@]}" \
    "$@"

ok "pre-wp-bind UI gate finished — reports in ${REPORT_DIR}"
