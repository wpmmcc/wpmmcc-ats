#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"

PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
MOCK_HEALTH_URL="${MOCK_API_URL}/api/v1/health"
CLIENT_STATUS_URL="${CLIENT_BASE:-http://127.0.0.1:8977}/api/status"
MOCK_LOG_FILE="${RUNTIME_DIR}/mock-translate-api.log"
CLIENT_LOG_FILE="${RUNTIME_DIR}/client-webui-local.log"

ensure_mock_translate_api() {
  if check_url "${MOCK_HEALTH_URL}"; then
    ok "mock-translate-api already reachable at ${MOCK_HEALTH_URL}"
    return
  fi

  info "Building mock-translate-api..."
  cargo build --release --manifest-path "${REPO_ROOT}/tests/infra/mock-api/Cargo.toml"

  info "Starting mock-translate-api..."
  mkdir -p "${RUNTIME_DIR}"
  nohup "${REPO_ROOT}/tests/infra/mock-api/target/release/mock-translate-api" >"${MOCK_LOG_FILE}" 2>&1 &

  for _ in $(seq 1 30); do
    if check_url "${MOCK_HEALTH_URL}"; then
      ok "mock-translate-api reachable at ${MOCK_HEALTH_URL}"
      return
    fi
    sleep 1
  done

  abort "mock-translate-api did not become ready at ${MOCK_HEALTH_URL}"
}

ensure_client_ready_for_mock() {
  local client_port="${WPTSALL_WEB_UI_PORT:-8977}"
  local mock_oauth_port=8787

  # Always restart the client with mock config — an existing client running
  # against the production server will not satisfy mock-mode OAuth tests.
  info "Stopping any existing Client WebUI to ensure mock config is active..."
  systemctl --user stop wptsall-client-webui 2>/dev/null || true

  # Clear client port (8977)
  local existing_pid
  existing_pid=$(lsof -ti :"${client_port}" 2>/dev/null || true)
  if [ -n "${existing_pid}" ]; then
    info "Killing existing client process (PID ${existing_pid}) on port ${client_port}"
    kill "${existing_pid}" 2>/dev/null || true
    sleep 2
  fi

  # Clear mock OAuth port (8787) — a previous failed test run may have left the
  # Node.js mock server running if afterAll did not execute.
  local stale_mock_pid
  stale_mock_pid=$(lsof -ti :"${mock_oauth_port}" 2>/dev/null || true)
  if [ -n "${stale_mock_pid}" ]; then
    info "Clearing stale mock server on port ${mock_oauth_port} (PID ${stale_mock_pid})"
    kill "${stale_mock_pid}" 2>/dev/null || true
    sleep 1
  fi

  info "Starting Client WebUI in mock mode (control-plane flag off; provider mocks are not the website)..."
  mkdir -p "${RUNTIME_DIR}"
  export WPTSALL_COMPONENT_BINDINGS_SECRET="${WPTSALL_COMPONENT_BINDINGS_SECRET:-runner-client-local-secret}"
  export WPTSALL_SKIP_SIGNATURE_CHECK="${WPTSALL_SKIP_SIGNATURE_CHECK:-true}"
  # P0-LF-07: the mock-provider lane must run with control-plane mode 0.
  export WPTSALL_USE_SERVER_CONTROL_PLANE=0
  export WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE:-http://127.0.0.1:8787}"
  # Client CWD is the repo root, so relative frontend/dist is missing; point at
  # the real SPA so Auth/local-component UI changes are picked up without a
  # cargo rebuild of the embedded include_str fallback.
  export WPTSALL_WEB_UI_PATH="${WPTSALL_WEB_UI_PATH:-${REPO_ROOT}/client-wpplugin/source/frontend/dist}"
  nohup bash "${REPO_ROOT}/client-wpplugin/source/scripts/run-web-ui-local.sh" >"${CLIENT_LOG_FILE}" 2>&1 &

  for _ in $(seq 1 60); do
    if check_url "${CLIENT_STATUS_URL}"; then
      ok "Client WebUI reachable at ${CLIENT_STATUS_URL} (mock mode)"
      return
    fi
    sleep 1
  done

  abort "Client WebUI did not become ready at ${CLIENT_STATUS_URL}"
}

restore_client_from_systemd() {
  local client_port="${WPTSALL_WEB_UI_PORT:-8977}"
  info "Restoring Client WebUI via systemd..."
  local pid
  pid=$(lsof -ti :"${client_port}" 2>/dev/null || true)
  if [ -n "${pid}" ]; then
    kill "${pid}" 2>/dev/null || true
    sleep 1
  fi
  systemctl --user start wptsall-client-webui 2>/dev/null || true
}

print_stage "SUPPORT" "Playwright Client Mock"
ensure_mock_translate_api
ensure_client_ready_for_mock

cd "${PLAYWRIGHT_DIR}"
npx playwright test -c playwright.client-mock-support.config.ts "$@"
PW_EXIT=$?

restore_client_from_systemd
exit ${PW_EXIT}
