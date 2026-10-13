#!/usr/bin/env bash
# E2E-B: live mock-api + Client WebUI + Playwright (no page.route for APIs).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# This lane is intentionally shared-port (:8977 / :9090). Drop ambient Lab
# slot exports so config.sh does not redirect CLIENT_BASE to 907x.
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"
CLIENT_BASE="${CLIENT_BASE:-http://127.0.0.1:8977}"
CLIENT_STATUS_URL="${CLIENT_BASE}/api/status"
MOCK_HEALTH_URL="${MOCK_API_URL}/api/v1/health"
CLIENT_LOG_FILE="${RUNTIME_DIR}/client-webui-live-mock.log"
STARTED_CLIENT=0

ensure_mock() {
  if check_url "${MOCK_HEALTH_URL}"; then
    ok "mock-api ready at ${MOCK_HEALTH_URL}"
    return
  fi
  info "Starting mock-api via release-gate helpers is preferred; attempting cargo path..."
  if [[ -x "${REPO_ROOT}/tests/infra/mock-api/target/release/mock-translate-api" ]]; then
    nohup "${REPO_ROOT}/tests/infra/mock-api/target/release/mock-translate-api" \
      >"${RUNTIME_DIR}/mock-translate-api-live-mock.log" 2>&1 &
  else
    abort "mock-api not running at ${MOCK_HEALTH_URL}; start mock9090 / mock-translate-api first"
  fi
  for _ in $(seq 1 30); do
    check_url "${MOCK_HEALTH_URL}" && { ok "mock-api ready"; return; }
    sleep 1
  done
  abort "mock-api did not become ready"
}

ensure_client() {
  export WPTSALL_PROVIDER_ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}"
  export WPTSALL_WEB_UI=1
  export WPTSALL_USE_SERVER_CONTROL_PLANE=0
  # 批C (X-5/GAP-06): owned-instance observability — capture log_event
  # evidence (provider trace timeline) next to the runtime dir.
  export WPTSALL_LOG_ENABLED=true
  export WPTSALL_LOG_FILE="${RUNTIME_DIR}/client-runtime.log"

  if check_url "${CLIENT_STATUS_URL}"; then
    ok "Client already at ${CLIENT_STATUS_URL} (ensure ALLOWLIST includes 127.0.0.1)"
    return
  fi

  info "Starting Client WebUI with SSRF allowlist for mock-api..."
  mkdir -p "${RUNTIME_DIR}"
  STARTED_CLIENT=1
  nohup env \
    WPTSALL_WEB_UI=1 \
    WPTSALL_PROVIDER_ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST}" \
    WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
    bash "${REPO_ROOT}/scripts/wptsall.sh" run webui --background \
    >"${CLIENT_LOG_FILE}" 2>&1 || true

  for _ in $(seq 1 90); do
    if check_url "${CLIENT_STATUS_URL}"; then
      ok "Client ready at ${CLIENT_STATUS_URL}"
      return
    fi
    sleep 1
  done
  abort "Client WebUI not ready at ${CLIENT_STATUS_URL} (see ${CLIENT_LOG_FILE})"
}

print_stage "E2E-B" "Provider live-mock Playwright"
ensure_mock
ensure_client

cd "${PLAYWRIGHT_DIR}"
REPORT_DIR="${REPORT_DIR}" \
  MOCK_API_BASE="${MOCK_API_URL}" \
  CLIENT_BASE="${CLIENT_BASE}" \
  npx playwright test -c playwright.client-mock-support.config.ts \
  support-client-mock/provider-live-mock-flow.spec.ts "$@"
PW_EXIT=$?

if [[ "${STARTED_CLIENT}" == "1" ]]; then
  info "Leaving client running if started via wptsall services; stop with: bash scripts/wptsall.sh services stop webui"
fi

exit "${PW_EXIT}"
