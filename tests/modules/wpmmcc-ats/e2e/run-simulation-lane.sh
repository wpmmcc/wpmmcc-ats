#!/usr/bin/env bash
# SIM lane: real client + in-spec mock plugin sites (no WP containers).
#
# This lane OWNS everything it needs and never reuses a pre-existing Client:
#   - one owned loopback port (never the shared 8977, never 8787);
#   - one temporary state root with every persisted-path env isolated;
#   - one owned Client process (trapped; fails loudly if it exits early);
#   - WPTSALL_USE_SERVER_CONTROL_PLANE=0 (local-first, no website session);
#   - WPTSALL_WP_TRANSPORT_ENCRYPT=off — the mock plugin sites serve the
#     signed-plaintext protocol (mirroring mock lanes' transport contract);
#   - WPTSALL_PROVIDER_ALLOWLIST for loopback mock providers.
#
# The simulation specs start their own mock WPMMCC / ATS plugin sites on
# ephemeral loopback ports from inside the tests; this script only owns
# the client under test.
#
# The selected base URL is exported as WPTSALL_SIMULATION_BASE_URL and read
# by playwright.simulation.config.ts.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
CLIENT_SRC="${REPO_ROOT}/client-wpplugin/source"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/simulation/${RUN_ID}"
mkdir -p "${REPORT_DIR}"

LANE_CLIENT_PID=""
STATE_ROOT=""
# FL-6 (Wave-2): the lane report used to hardcode result:ok — with set -e a
# failing playwright run jumped straight to the EXIT trap, which still wrote
# "ok". Track the real playwright exit code and reflect it in the report.
LANE_TEST_RC=""

abort() { err "simulation lane: $*"; exit 1; }

choose_free_port() {
  python3 - <<'PY'
import socket
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()
PY
}

write_lane_report() {
  local client_port="$1" state_root="$2" mode="$3" result="$4"
  cat > "${REPORT_DIR}/lane-report.txt" <<EOF
SIM simulation lane report
run_id: ${RUN_ID}
mode: ${mode}
client_pid: ${LANE_CLIENT_PID:-not-started}
client_port: ${client_port}
client_base_url: http://127.0.0.1:${client_port}
state_root: ${state_root}
result: ${result}
artifacts: ${REPORT_DIR}
EOF
  cat "${REPORT_DIR}/lane-report.txt"
}

cleanup() {
  local result="ok"
  if [ -n "${LANE_TEST_RC}" ] && [ "${LANE_TEST_RC}" -ne 0 ]; then
    result="failed (playwright rc=${LANE_TEST_RC})"
  elif [ -z "${LANE_TEST_RC}" ]; then
    # No playwright rc recorded: the lane aborted before/during the run
    # (client never ready, signal, early exit).
    result="aborted (no playwright rc recorded)"
  fi
  if [ -n "${LANE_CLIENT_PID}" ] && kill -0 "${LANE_CLIENT_PID}" 2>/dev/null; then
    kill "${LANE_CLIENT_PID}" 2>/dev/null || true
    for _ in $(seq 1 20); do
      kill -0 "${LANE_CLIENT_PID}" 2>/dev/null || break
      sleep 0.5
    done
    kill -9 "${LANE_CLIENT_PID}" 2>/dev/null || true
  fi
  if [ -n "${STATE_ROOT}" ] && [ -d "${STATE_ROOT}" ]; then
    write_lane_report "${LANE_PORT:-unknown}" "${STATE_ROOT}" \
      "${LANE_MODE:-unknown}" "${result}" >/dev/null 2>&1 || true
    # Preserve every runtime log (client stderr/stdout AND the worker's
    # structured log) plus the state documents and SQLite DB so failed runs
    # keep their full pipeline evidence.
    cp "${STATE_ROOT}"/state/*.log "${REPORT_DIR}/" 2>/dev/null || true
    cp "${STATE_ROOT}"/state/*.json "${REPORT_DIR}/" 2>/dev/null || true
    cp "${STATE_ROOT}"/state/*.db "${REPORT_DIR}/" 2>/dev/null || true
    if [ -d "${STATE_ROOT}/visual-audit" ]; then
      mkdir -p "${REPORT_DIR}/visual-audit"
      cp -a "${STATE_ROOT}/visual-audit/." "${REPORT_DIR}/visual-audit/" 2>/dev/null || true
      info "Preserved visual-audit screenshots → ${REPORT_DIR}/visual-audit"
    fi
    rm -rf "${STATE_ROOT}"
  else
    write_lane_report "${LANE_PORT:-unknown}" "${STATE_ROOT:-unknown}" \
      "${LANE_MODE:-unknown}" "${result}" >/dev/null 2>&1 || true
  fi
  ok "simulation lane cleanup: owned client stopped, temp state root removed (report kept in ${REPORT_DIR})"
}
trap cleanup EXIT INT TERM

print_stage "SIM" "Playwright Simulation Lane (real client + mock plugin sites)"

# ---------------------------------------------------------------------------
# 1. Owned loopback port + temporary state root (never 8977 / 8787)
# ---------------------------------------------------------------------------
for _ in 1 2 3 4 5; do
  LANE_PORT="$(choose_free_port)"
  if [ "${LANE_PORT}" = "8977" ] || [ "${LANE_PORT}" = "8787" ]; then
    continue
  fi
  if ! python3 -c "import socket; s=socket.socket(); s.bind(('127.0.0.1', ${LANE_PORT})); s.close()" 2>/dev/null; then
    continue
  fi
  break
done
case "${LANE_PORT}" in
  8977|8787) abort "port selection failed: got shared port ${LANE_PORT}" ;;
esac
info "Owned loopback port: ${LANE_PORT} (8977/8787 untouched)"

STATE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wptsall-simulation-lane.XXXXXX")"
mkdir -p "${STATE_ROOT}/state"
info "Temporary state root: ${STATE_ROOT}"

# ---------------------------------------------------------------------------
# 2. Isolated state + mock-friendly transport contract
# ---------------------------------------------------------------------------
CLIENT_LOG="${STATE_ROOT}/state/client-stderr.log"

export WPTSALL_USE_SERVER_CONTROL_PLANE=0
export WPTSALL_WEB_UI=1
export WPTSALL_WEB_UI_BIND="127.0.0.1:${LANE_PORT}"
export WPTSALL_WEB_UI_PORT="${LANE_PORT}"
export WPTSALL_SERVER_BASE="http://127.0.0.1:1"   # never contacted in local mode
export WPTSALL_SERVER_URL="http://127.0.0.1:1"
export WPTSALL_DB_PATH="${STATE_ROOT}/state/wptsall.db"
export WPTSALL_DATA_DIR="${STATE_ROOT}/state/data"
export WPTSALL_LOG_FILE="${STATE_ROOT}/state/client.log"
export WPTSALL_SESSION_TOKEN_FILE="${STATE_ROOT}/state/session-token.enc"
export WPTSALL_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/component-bindings.json"
export WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${STATE_ROOT}/state/domain-token-bindings.json"
export WPTSALL_TASK_TYPE_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/task-type-component-bindings.json"
export WPTSALL_RULE_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/rule-component-bindings.json"
export WPTSALL_COMPONENTS_LOCAL_FILE="${STATE_ROOT}/state/components-local.json"
export WPTSALL_PROVIDER_CATALOG_FILE="${STATE_ROOT}/state/provider-catalog.json"
export WPTSALL_SYNC_PAIRS_FILE="${STATE_ROOT}/state/sync-pairs.json"
export WPTSALL_SYNC_PEER_CREDENTIALS_FILE="${STATE_ROOT}/state/sync-peer-credentials.json"
export WPTSALL_SYNC_STATE_FILE="${STATE_ROOT}/state/sync-state.json"
# Structured logging is release-DISABLED by default; enable it for the lane
# so the worker/engine pipeline events land in the preserved client.log.
export WPTSALL_LOG_ENABLED=1
# Mock plugin sites serve the signed-plaintext protocol.
export WPTSALL_WP_TRANSPORT_ENCRYPT=off
# Loopback mock providers must be reachable.
export WPTSALL_PROVIDER_ALLOWLIST="127.0.0.1,localhost"
# SIM-05 offline journey: pin the catalog source to an unroutable loopback
# address (explicitly allowed) so the seed fallback is deterministic and
# no ambient proxy can satisfy the inline first fetch.
export WPTSALL_PROVIDER_CATALOG_ALLOW_LOOPBACK_SOURCE=1
export WPTSALL_PROVIDER_CATALOG_SOURCE_URL="http://127.0.0.1:9/catalog.json"
mkdir -p "${WPTSALL_DATA_DIR}"

export WPTSALL_SIMULATION_BASE_URL="http://127.0.0.1:${LANE_PORT}"
export WPTSALL_LANE_STATE_ROOT="${STATE_ROOT}"

# ---------------------------------------------------------------------------
# 3. One owned Client process (debug build, fail fast on early exit)
# ---------------------------------------------------------------------------
TARGET_DIR="${CARGO_TARGET_DIR:-${CLIENT_SRC}/target}"

# The client serves the WebUI from frontend/dist (CWD path first, then the
# compile-time embed). Both go stale when frontend sources change, so the
# lane always rebuilds the bundle BEFORE cargo: the vite output lands on
# disk for the CWD lookup and is embedded fresh into the binary.
info "Building owned WebUI frontend bundle (vite)..."
(cd "${CLIENT_SRC}/frontend" && pnpm build) \
  || abort "frontend build failed (cd ${CLIENT_SRC}/frontend && pnpm build)"
[ -f "${CLIENT_SRC}/frontend/dist/index.html" ] \
  || abort "frontend build produced no dist/index.html"

info "Building owned Client (debug, incremental)..."
(cargo build --manifest-path "${CLIENT_SRC}/Cargo.toml" --bin wptsall-client --quiet) \
  || abort "cargo build --bin wptsall-client failed"
CLIENT_BIN="${TARGET_DIR}/debug/wptsall-client"
[ -x "${CLIENT_BIN}" ] || abort "client binary not found at ${CLIENT_BIN}"

info "Launching owned Client on ${WPTSALL_SIMULATION_BASE_URL}..."
(cd "${CLIENT_SRC}" && exec "${CLIENT_BIN}" >"${STATE_ROOT}/state/client-stdout.log" 2>"${CLIENT_LOG}") &
LANE_CLIENT_PID=$!
sleep 0.5
kill -0 "${LANE_CLIENT_PID}" 2>/dev/null || abort "owned Client exited immediately (see ${CLIENT_LOG})"

LANE_MODE="unknown"
ready=""
for _ in $(seq 1 60); do
  if ! kill -0 "${LANE_CLIENT_PID}" 2>/dev/null; then
    abort "owned Client exited early during readiness wait (see ${CLIENT_LOG})"
  fi
  if check_url "http://127.0.0.1:${LANE_PORT}/api/status"; then
    ready="yes"
    break
  fi
  sleep 1
done
[ -n "${ready}" ] || {
  warn "owned Client did not become ready; last client stderr:"
  tail -5 "${CLIENT_LOG}" 2>/dev/null || true
  cp "${CLIENT_LOG}" "${REPORT_DIR}/client-stderr-on-failure.log" 2>/dev/null || true
  abort "owned Client did not become ready at http://127.0.0.1:${LANE_PORT}"
}

LANE_MODE="$(curl -fsS "http://127.0.0.1:${LANE_PORT}/api/status" \
  | python3 -c 'import json,sys; print(json.load(sys.stdin).get("data", {}).get("runtime_mode", "unknown"))' \
  2>/dev/null || echo unknown)"
info "Owned Client ready: pid=${LANE_CLIENT_PID} port=${LANE_PORT} runtime_mode=${LANE_MODE}"
if [ "${LANE_MODE}" != "local" ]; then
  abort "expected runtime_mode=local for the isolated Client, got ${LANE_MODE}"
fi

# ---------------------------------------------------------------------------
# 4. Playwright (baseURL comes from WPTSALL_SIMULATION_BASE_URL)
# ---------------------------------------------------------------------------
cd "${PLAYWRIGHT_DIR}"
# FL-6: capture the real playwright exit code (set +e) so the lane report
# and this script's own exit status both tell the truth.
set +e
npx playwright test -c playwright.simulation.config.ts "$@"
LANE_TEST_RC=$?
set -e
if [ "${LANE_TEST_RC}" -ne 0 ]; then
  err "simulation lane: playwright failed (rc=${LANE_TEST_RC})"
  exit "${LANE_TEST_RC}"
fi

write_lane_report "${LANE_PORT}" "${STATE_ROOT}" "${LANE_MODE}" "ok"
ok "simulation lane passed (pid=${LANE_CLIENT_PID} port=${LANE_PORT})"
