#!/usr/bin/env bash
# Independent E2E: dual Client WebUI agents ↔ mock (providers + component config).
# NOT part of release-gate / Lab matrix.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-dual-webui-mock-providers.sh
#   WEBUI_A_BASE=http://127.0.0.1:8977 WEBUI_B_BASE=http://127.0.0.1:8978 \
#     bash tests/modules/wpmmcc-ats/e2e/run-dual-webui-mock-providers.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

WEBUI_A_BASE="${WEBUI_A_BASE:-http://127.0.0.1:8977}"
WEBUI_B_BASE="${WEBUI_B_BASE:-http://127.0.0.1:8978}"
MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}"
SKIP_ENSURE="${SKIP_ENSURE:-0}"
REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/dual-webui-mock"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "${REPORT_ROOT}/prove-a" "${REPORT_ROOT}/prove-b"
ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}"
export WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}"

port_of() {
  # http://127.0.0.1:8977 -> 8977
  echo "$1" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|'
}

WEBUI_A_PORT="$(port_of "${WEBUI_A_BASE}")"
WEBUI_B_PORT="$(port_of "${WEBUI_B_BASE}")"

ensure_mock() {
  if check_url "${MOCK_API_BASE}/api/v1/health" || check_url "${MOCK_API_BASE}/health"; then
    ok "mock-api ready at ${MOCK_API_BASE}"
    return
  fi
  local bin="${REPO_ROOT}/tests/infra/mock-api/target/release/mock-translate-api"
  [[ -x "$bin" ]] || abort "mock-api not running and binary missing: ${bin}"
  info "Starting mock-api..."
  mkdir -p "${RUNTIME_DIR}"
  nohup "$bin" >"${RUNTIME_DIR}/mock-translate-api-dual-webui.log" 2>&1 &
  for _ in $(seq 1 40); do
    check_url "${MOCK_API_BASE}/api/v1/health" && { ok "mock-api ready"; return; }
    sleep 1
  done
  abort "mock-api did not become ready (see ${RUNTIME_DIR}/mock-translate-api-dual-webui.log)"
}

resolve_client_binary() {
  local candidates=(
    "${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release-lab/wptsall-client"
  )
  local c
  for c in "${candidates[@]}"; do
    if [[ -x "$c" ]]; then
      echo "$c"
      return 0
    fi
  done
  return 1
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
  bin="$(resolve_client_binary)" || abort "wptsall-client binary not found; build client-wpplugin first"

  mkdir -p "${RUNTIME_DIR}/logs"
  local log="${RUNTIME_DIR}/logs/dual-webui-${label}-${port}.log"
  info "Starting ${label} agent on :${port} (log=${log})"
  # Direct binary: wptsall services only tracks one webui pid.
  nohup env \
    WPTSALL_WEB_UI=1 \
    WPTSALL_WEB_UI_PORT="${port}" \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${port}" \
    WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}" \
    WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
    WPTSALL_SERVER_URL= \
    WPTSALL_LOG_ENABLED=true \
    WPTSALL_LOG_FILE="${RUNTIME_DIR}/logs/dual-webui-${label}-${port}-client.log" \
    "$bin" >"${log}" 2>&1 </dev/null &
  echo $! >"${RUNTIME_DIR}/logs/dual-webui-${label}-${port}.pid"

  for _ in $(seq 1 90); do
    if check_url "${status_url}"; then
      ok "${label} ready at ${status_url}"
      return 0
    fi
    sleep 1
  done
  abort "${label} not ready at ${status_url} (see ${log})"
}

ensure_dual_agents() {
  start_agent_on_port "${WEBUI_A_PORT}" "webui-a"
  start_agent_on_port "${WEBUI_B_PORT}" "webui-b"
}

run_prove_on() {
  local base="$1"
  local out_dir="$2"
  local label="$3"
  info "API prove on ${label} (${base})"
  CLIENT_BASE="${base}" \
    MOCK_API_BASE="${MOCK_API_BASE}" \
    REPORT_DIR="${out_dir}" \
    PROVE_ID_PREFIX="${label}" \
    bash "${REPO_ROOT}/tests/scripts/prove-provider-mock-config.sh"
}

run_playwright() {
  local pw_dir="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
  info "Playwright dual-webui lane"
  cd "${pw_dir}"
  REPORT_DIR="${REPORT_ROOT}" \
    MOCK_API_BASE="${MOCK_API_BASE}" \
    WEBUI_A_BASE="${WEBUI_A_BASE}" \
    WEBUI_B_BASE="${WEBUI_B_BASE}" \
    npx playwright test -c playwright.dual-webui-mock.config.ts \
      dual-webui-mock/dual-webui-providers-components.spec.ts "$@"
}

write_summary() {
  local prove_a_ok="$1"
  local prove_b_ok="$2"
  local pw_ok="$3"
  local overall=failed
  if [[ "${prove_a_ok}" == "1" && "${prove_b_ok}" == "1" && "${pw_ok}" == "1" ]]; then
    overall=passed
  fi
  python3 - <<PY
import json, pathlib
report = {
  "task": "dual-webui-mock-providers-components",
  "independent": True,
  "not_in_release_gate": True,
  "timestamp": "${STAMP}",
  "status": "${overall}",
  "mock_api_base": "${MOCK_API_BASE}",
  "webui_a": {"base": "${WEBUI_A_BASE}", "prove_ok": ${prove_a_ok}},
  "webui_b": {"base": "${WEBUI_B_BASE}", "prove_ok": ${prove_b_ok}},
  "playwright_ok": ${pw_ok},
  "families": ["openai-compatible", "youdao", "deepl", "google-translate"],
  "checks": ["install", "vendor_key", "quick_test_mock", "enable", "plain_text_bind", "list", "run_once", "ui_smoke"],
  "policy": "local mock-api only; no live vendor / 官网",
  "docs": "tests/modules/wpmmcc-ats/e2e/dual-webui-mock/README.md",
}
path = pathlib.Path("${REPORT_ROOT}") / f"summary-${STAMP}.json"
path.write_text(json.dumps(report, indent=2) + "\n")
print(path.read_text())
raise SystemExit(0 if report["status"] == "passed" else 1)
PY
}

print_stage "E2E-INDEPENDENT" "Dual WebUI ↔ mock providers + component config"

if [[ "${SKIP_ENSURE}" != "1" ]]; then
  ensure_mock
  ensure_dual_agents
else
  info "SKIP_ENSURE=1 — expecting mock + both webuis already up"
  check_url "${MOCK_API_BASE}/api/v1/health" || abort "mock missing"
  check_url "${WEBUI_A_BASE}/api/status" || abort "webui-a missing"
  check_url "${WEBUI_B_BASE}/api/status" || abort "webui-b missing"
fi

PROVE_A=0
PROVE_B=0
PW=0

if run_prove_on "${WEBUI_A_BASE}" "${REPORT_ROOT}/prove-a" "webui-a"; then PROVE_A=1; fi
if run_prove_on "${WEBUI_B_BASE}" "${REPORT_ROOT}/prove-b" "webui-b"; then PROVE_B=1; fi
if run_playwright "$@"; then PW=1; fi

write_summary "${PROVE_A}" "${PROVE_B}" "${PW}"
