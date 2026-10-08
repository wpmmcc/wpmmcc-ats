#!/usr/bin/env bash
# Independent gate: catalog 112 (+ auth profiles) × plugin WebUI + second WebUI agent ↔ mock.
# NOT part of release-gate / Lab matrix.
#
# NAMING CLARIFICATION (2026-09-12, test-architecture verification §7):
#   "desktop-ui-agent" (:8978) is the SECOND WebUI client agent — the same
#   client binary started twice in parallel. It is NOT the Tauri desktop
#   product (that lives in test desktop-unit/desktop-integration/desktop-e2e).
#   The internal agent name is kept for matrix-report compatibility; docs
#   should call it "webui-agent-b (:8978)".
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-dual-ui-catalog-112-mock.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

WEBUI_A_BASE="${WEBUI_A_BASE:-http://127.0.0.1:8977}"
WEBUI_B_BASE="${WEBUI_B_BASE:-http://127.0.0.1:8978}"
MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}"
ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}"
export WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}"
SKIP_ENSURE="${SKIP_ENSURE:-0}"
SKIP_REBUILD="${SKIP_REBUILD:-0}"

# R1 clean release: the client ships zero templates and fetches the official
# template repository (here: the local wptsall-provider-templates checkout via
# the test-gated file source; production uses the pinned GitHub URL). Both
# WebUI agents seed their catalog cache from it on first run.
PROVIDER_TEMPLATES_REPO="${PROVIDER_TEMPLATES_REPO:-${REPO_ROOT}/../wptsall-provider-templates}"
PROVIDER_CATALOG_SOURCE_URL="file://${PROVIDER_TEMPLATES_REPO}/catalog.json"
PROVIDER_CATALOG_PUBLIC_KEY_FILE="${PROVIDER_TEMPLATES_REPO}/keys/catalog-signing.public.pem"

port_of() { echo "$1" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|'; }

WEBUI_A_PORT="$(port_of "${WEBUI_A_BASE}")"
WEBUI_B_PORT="$(port_of "${WEBUI_B_BASE}")"

resolve_client_binary() {
  local candidates=(
    "${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release-lab/wptsall-client"
  )
  local c
  for c in "${candidates[@]}"; do
    [[ -x "$c" ]] && { echo "$c"; return 0; }
  done
  return 1
}

ensure_mock() {
  if check_url "${MOCK_API_BASE}/api/v1/health"; then
    ok "mock-api ready"
    return
  fi
  local bin="${REPO_ROOT}/tests/infra/mock-api/target/release/mock-translate-api"
  [[ -x "$bin" ]] || abort "mock-api missing"
  nohup "$bin" >"${RUNTIME_DIR}/mock-translate-api-dual-ui-112.log" 2>&1 &
  for _ in $(seq 1 40); do
    check_url "${MOCK_API_BASE}/api/v1/health" && { ok "mock-api ready"; return; }
    sleep 1
  done
  abort "mock-api not ready"
}

rebuild_client_if_needed() {
  if [[ "${SKIP_REBUILD}" == "1" ]]; then
    info "SKIP_REBUILD=1"
    return
  fi
  info "Building client-wpplugin (catalog allowlist install fix)…"
  (
    cd "${REPO_ROOT}/client-wpplugin/source"
    cargo test -q --lib -- provider_url_preflight_rejects_private_hosts
    cargo build -q
  )
}

kill_agents_on_ports() {
  # Old Lab agents may lack WPTSALL_WEB_UI_BIND in argv; kill by binary + port listen.
  pkill -x wptsall-client 2>/dev/null || true
  sleep 1
  local port
  for port in "${WEBUI_A_PORT}" "${WEBUI_B_PORT}"; do
    pkill -f "WPTSALL_WEB_UI_BIND=127.0.0.1:${port}" 2>/dev/null || true
    pkill -f "WPTSALL_WEB_UI_PORT=${port}" 2>/dev/null || true
  done
  sleep 1
}

start_agent_on_port() {
  local port="$1"
  local label="$2"
  local status_url="http://127.0.0.1:${port}/api/status"
  if check_url "${status_url}"; then
    ok "${label} already up on :${port}"
    return 0
  fi
  local bin
  bin="$(resolve_client_binary)" || abort "wptsall-client binary missing"
  mkdir -p "${RUNTIME_DIR}/logs"
  local log="${RUNTIME_DIR}/logs/dual-ui-112-${label}-${port}.log"
  info "Starting ${label} on :${port}"
  nohup env \
    WPTSALL_WEB_UI=1 \
    WPTSALL_WEB_UI_PORT="${port}" \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${port}" \
    WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}" \
    WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
    WPTSALL_SERVER_URL= \
    WPTSALL_LOG_ENABLED=true \
    WPTSALL_LOG_FILE="${RUNTIME_DIR}/logs/dual-ui-112-${label}-${port}-client.log" \
    WPTSALL_PROVIDER_CATALOG_SOURCE_URL="${PROVIDER_CATALOG_SOURCE_URL}" \
    WPTSALL_PROVIDER_CATALOG_ALLOW_FILE_SOURCE=1 \
    WPTSALL_PROVIDER_CATALOG_PUBLIC_KEY_FILE="${PROVIDER_CATALOG_PUBLIC_KEY_FILE}" \
    "$bin" >"${log}" 2>&1 </dev/null &
  echo $! >"${RUNTIME_DIR}/logs/dual-ui-112-${label}-${port}.pid"
  for _ in $(seq 1 90); do
    check_url "${status_url}" && { ok "${label} ready"; return 0; }
    sleep 1
  done
  abort "${label} not ready (see ${log})"
}

print_stage "E2E-INDEPENDENT" "Dual UI × catalog 112 ↔ mock"

if [[ "${SKIP_ENSURE}" != "1" ]]; then
  ensure_mock
  rebuild_client_if_needed
  # Clean release: catalog comes from the official template repo source.
  [[ -f "${PROVIDER_TEMPLATES_REPO}/catalog.json" ]] \
    || abort "template repo catalog missing at ${PROVIDER_TEMPLATES_REPO}/catalog.json"
  [[ -f "${PROVIDER_CATALOG_PUBLIC_KEY_FILE}" ]] \
    || abort "template repo public key missing at ${PROVIDER_CATALOG_PUBLIC_KEY_FILE}"
  kill_agents_on_ports
  # Clean release: drop any shared catalog cache so this lane run always
  # starts from the official template repo source (first-run auto-fetch
  # semantics). Otherwise a stale current.json from an earlier lane run
  # suppresses the fetch and installs outdated templates.
  rm -f "${REPO_ROOT}/config/provider-catalog.current.json" \
        "${REPO_ROOT}/config/provider-catalog.lkg.json" \
        "${REPO_ROOT}/config/provider-catalog.metadata.json" \
        "${REPO_ROOT}/config/provider-catalog.json" \
        "config/provider-catalog.current.json" \
        "config/provider-catalog.lkg.json" \
        "config/provider-catalog.metadata.json" \
        "config/provider-catalog.json"
  echo "[dual-ui] starting two WebUI client agents (NOT Tauri): plugin-webui :${WEBUI_A_PORT} + webui-agent-b :${WEBUI_B_PORT}"
  start_agent_on_port "${WEBUI_A_PORT}" "plugin-webui"
  # Internal name kept as desktop-ui-agent for matrix-report compatibility.
  start_agent_on_port "${WEBUI_B_PORT}" "desktop-ui-agent"
else
  check_url "${MOCK_API_BASE}/api/v1/health" || abort "mock missing"
  check_url "${WEBUI_A_BASE}/api/status" || abort "webui-a missing"
  check_url "${WEBUI_B_BASE}/api/status" || abort "webui-b missing"
fi

WEBUI_A_BASE="${WEBUI_A_BASE}" \
  WEBUI_B_BASE="${WEBUI_B_BASE}" \
  MOCK_API_BASE="${MOCK_API_BASE}" \
  EXPAND_AUTH_PROFILES="${EXPAND_AUTH_PROFILES:-1}" \
  python3 "${REPO_ROOT}/tests/scripts/matrix-dual-ui-catalog-112-mock.py"
