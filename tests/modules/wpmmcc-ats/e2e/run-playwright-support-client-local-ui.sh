#!/usr/bin/env bash
# P0-LF-06: deterministic default-network and state-isolation lane.
#
# This lane OWNS everything it needs and never reuses a pre-existing Client:
#   - one owned loopback port (never the shared 8977, never 8787);
#   - one temporary state root with every persisted-path env isolated;
#   - one owned Client process (trapped; fails loudly if it exits early);
#   - one owned recording canary HTTP server as WPTSALL_SERVER_BASE/URL
#     (asserted to receive zero requests by the Playwright contract spec);
#   - WPTSALL_USE_SERVER_CONTROL_PLANE=0 (local-first, no website session).
#
# The selected base URL is exported as WPTSALL_CLIENT_LOCAL_UI_BASE_URL and
# read by playwright.client-local-ui.config.ts (no hardcoded 8977 here).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

REVIEW_OWNED=0
if [[ "${1:-}" == "--review-owned" ]]; then
  REVIEW_OWNED=1
  shift
  if [[ -n "${WPTSALL_OWNED_WP_CONTEXT:-}" ]]; then
    err "review-owned and owned-WP selectors cannot be combined"
    exit 2
  fi
fi

PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
CLIENT_SRC="${REPO_ROOT}/client-wpplugin/source"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/client-local-ui/${RUN_ID}"
mkdir -p "${REPORT_DIR}"

LANE_CLIENT_PID=""
LANE_CANARY_PID=""
STATE_ROOT=""
# FL-6 (Wave-2): same defect class as the sim lane — the report used to
# hardcode an "ok" result even when playwright failed (set -e jumped to the
# EXIT trap). Track the real playwright exit code instead.
LANE_TEST_RC=""

abort() { err "client-local-ui lane: $*"; exit 1; }

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
  local client_port="$1" state_root="$2" mode="$3" cleanup_result="$4" result="$5"
  local canary_count="unknown"
  if [ -f "${state_root}/canary/requests.log" ]; then
    canary_count="$(grep -c . "${state_root}/canary/requests.log" 2>/dev/null || echo 0)"
  else
    canary_count="0"
  fi
  cat > "${REPORT_DIR}/lane-report.txt" <<EOF
P0-LF-06 client-local-ui lane report
run_id: ${RUN_ID}
mode: ${mode}
client_pid: ${LANE_CLIENT_PID:-not-started}
client_port: ${client_port}
client_base_url: http://127.0.0.1:${client_port}
state_root: ${state_root}
canary_count: ${canary_count}
cleanup_result: ${cleanup_result}
result: ${result}
artifacts: ${REPORT_DIR}
EOF
  if [ -f "${state_root}/artifacts/recorded-requests.json" ]; then
    cp "${state_root}/artifacts/recorded-requests.json" "${REPORT_DIR}/recorded-requests.json"
  fi
  cat "${REPORT_DIR}/lane-report.txt"
}

cleanup() {
  local cleanup_result="ok"
  local result="ok"
  if [ -n "${LANE_TEST_RC}" ] && [ "${LANE_TEST_RC}" -ne 0 ]; then
    result="failed (playwright rc=${LANE_TEST_RC})"
  elif [ -z "${LANE_TEST_RC}" ]; then
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
  if [ -n "${LANE_CANARY_PID}" ] && kill -0 "${LANE_CANARY_PID}" 2>/dev/null; then
    kill "${LANE_CANARY_PID}" 2>/dev/null || true
  fi
  if [ -n "${STATE_ROOT}" ] && [ -d "${STATE_ROOT}" ]; then
    write_lane_report "${LANE_PORT:-unknown}" "${STATE_ROOT}" \
      "${LANE_MODE:-unknown}" "${cleanup_result}" "${result}" >/dev/null 2>&1 || true
    # Playwright's hidden last-run marker is framework state, not a lane
    # execution report. Keep its bytes under a non-report extension.
    if [ -f "${STATE_ROOT}/artifacts/playwright/.last-run.json" ]; then
      mv "${STATE_ROOT}/artifacts/playwright/.last-run.json" \
        "${STATE_ROOT}/artifacts/playwright/.last-run.playwright"
    fi
    cp -r "${STATE_ROOT}/artifacts" "${REPORT_DIR}/" 2>/dev/null || true
    # Keep client/canary logs as post-mortem evidence, then drop the state root.
    cp "${STATE_ROOT}"/state/client-*.log "${REPORT_DIR}/" 2>/dev/null || true
    if [ -f "${STATE_ROOT}/state/client.log" ]; then
      cp "${STATE_ROOT}/state/client.log" "${REPORT_DIR}/client-events.log"
    fi
    cp "${STATE_ROOT}"/canary/requests.log "${REPORT_DIR}/canary-requests.log" 2>/dev/null || true
    rm -rf "${STATE_ROOT}"
  else
    write_lane_report "${LANE_PORT:-unknown}" "${STATE_ROOT:-unknown}" \
      "${LANE_MODE:-unknown}" "${cleanup_result}" "${result}" >/dev/null 2>&1 || true
  fi
  ok "cleanup: owned client and canary stopped, temp state root removed (report kept in ${REPORT_DIR})"
}
trap cleanup EXIT INT TERM

print_stage "SUPPORT" "Playwright Client Local UI (P0-LF-06 owned lane)"

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

STATE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wptsall-client-local-ui-lane.XXXXXX")"
mkdir -p "${STATE_ROOT}/state" "${STATE_ROOT}/canary" "${STATE_ROOT}/artifacts"
info "Temporary state root: ${STATE_ROOT}"

# ---------------------------------------------------------------------------
# 2. Recording canary for the control-plane base (must receive 0 requests)
# ---------------------------------------------------------------------------
LANE_CANARY_PORT="$(choose_free_port)"
cat > "${STATE_ROOT}/canary/canary_server.py" <<EOF
import http.server
import socketserver
import sys

port = int(sys.argv[1])
log_path = sys.argv[2]

class CanaryHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _record(self):
        with open(log_path, "a") as f:
            f.write("%s %s\\n" % (self.command, self.path))

    def _respond(self):
        self._record()
        body = b"canary: no content"
        self.send_response(404)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = _respond

    def log_message(self, *args):
        pass

socketserver.TCPServer.allow_reuse_address = True
with socketserver.TCPServer(("127.0.0.1", port), CanaryHandler) as httpd:
    httpd.serve_forever()
EOF
python3 "${STATE_ROOT}/canary/canary_server.py" "${LANE_CANARY_PORT}" \
  "${STATE_ROOT}/canary/requests.log" &
LANE_CANARY_PID=$!
sleep 0.5
kill -0 "${LANE_CANARY_PID}" 2>/dev/null || abort "canary server failed to start"
LANE_CANARY_URL="http://127.0.0.1:${LANE_CANARY_PORT}"
info "Recording canary: ${LANE_CANARY_URL} (control-plane base; must stay empty)"

# ---------------------------------------------------------------------------
# 3. Isolated state: every persisted path lives under the temp state root
# ---------------------------------------------------------------------------
: > "${STATE_ROOT}/state/session-token.enc"
printf 'stale-session-token-not-a-real-credential\n' > "${STATE_ROOT}/state/session-token.enc"
CLIENT_LOG="${STATE_ROOT}/state/client-stderr.log"

export WPTSALL_USE_SERVER_CONTROL_PLANE=0
if [[ "${REVIEW_OWNED}" == "1" ]]; then
  # The in-spec ATS double implements the signed plaintext SIM contract.
  export WPTSALL_WP_TRANSPORT_ENCRYPT=off
fi
export WPTSALL_WEB_UI=1
export WPTSALL_WEB_UI_BIND="127.0.0.1:${LANE_PORT}"
# PORT override wins over BIND's port in the client; must match owned lane port
# or ambient WPTSALL_WEB_UI_PORT=8977 from a shared client will collide.
export WPTSALL_WEB_UI_PORT="${LANE_PORT}"
export WPTSALL_SERVER_BASE="${LANE_CANARY_URL}"
export WPTSALL_SERVER_URL="${LANE_CANARY_URL}"   # legacy alias, same canary
export WPTSALL_DB_PATH="${STATE_ROOT}/state/wptsall.db"
export WPTSALL_DATA_DIR="${STATE_ROOT}/state/data"
export WPTSALL_LOG_FILE="${STATE_ROOT}/state/client.log"
# 批C (X-5/GAP-06): owned-instance observability — the client log defaults
# to disabled; lanes must capture log_event evidence (trace timeline).
export WPTSALL_LOG_ENABLED=true
export WPTSALL_SESSION_TOKEN_FILE="${STATE_ROOT}/state/session-token.enc"
export WPTSALL_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/component-bindings.json"
export WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${STATE_ROOT}/state/domain-token-bindings.json"
export WPTSALL_TASK_TYPE_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/task-type-component-bindings.json"
export WPTSALL_RULE_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/rule-component-bindings.json"
export WPTSALL_COMPONENTS_LOCAL_FILE="${STATE_ROOT}/state/components-local.json"
export WPTSALL_PROVIDER_CATALOG_FILE="${STATE_ROOT}/state/provider-catalog.json"
export WPTSALL_SYNC_PAIRS_FILE="${STATE_ROOT}/state/sync-pairs.json"
export WPTSALL_SYNC_STATE_FILE="${STATE_ROOT}/state/sync-state.json"
export WPTSALL_SYNC_REVIEW_FILE="${STATE_ROOT}/state/sync-review.json"
export WPTSALL_SYNC_PEER_CREDENTIALS_FILE="${STATE_ROOT}/state/sync-peer-credentials.json"
mkdir -p "${WPTSALL_DATA_DIR}"

# Playwright contract inputs (no hardcoded 8977 anywhere).
export WPTSALL_CLIENT_LOCAL_UI_BASE_URL="http://127.0.0.1:${LANE_PORT}"
export WPTSALL_SIMULATION_BASE_URL="${WPTSALL_CLIENT_LOCAL_UI_BASE_URL}"
export WPTSALL_LANE_STATE_ROOT="${STATE_ROOT}"
export WPTSALL_LANE_CANARY_URL="${LANE_CANARY_URL}"
export WPTSALL_LANE_ARTIFACTS_DIR="${STATE_ROOT}/artifacts"

# ---------------------------------------------------------------------------
# 4. One owned Client process (debug build, fail fast on early exit)
# ---------------------------------------------------------------------------
TARGET_DIR="${CARGO_TARGET_DIR:-${CLIENT_SRC}/target}"
info "Building owned Client (debug, incremental)..."
(cargo build --manifest-path "${CLIENT_SRC}/Cargo.toml" --bin wptsall-client --quiet) \
  || abort "cargo build --bin wptsall-client failed"
CLIENT_BIN="${TARGET_DIR}/debug/wptsall-client"
[ -x "${CLIENT_BIN}" ] || abort "client binary not found at ${CLIENT_BIN}"

info "Launching owned Client on ${WPTSALL_CLIENT_LOCAL_UI_BASE_URL}..."
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
# 5. Playwright (baseURL comes from WPTSALL_CLIENT_LOCAL_UI_BASE_URL)
# ---------------------------------------------------------------------------
cd "${PLAYWRIGHT_DIR}"
# FL-6: capture the real playwright exit code (set +e) so the lane report
# and this script's own exit status both tell the truth.
set +e
if [[ "${REVIEW_OWNED}" == "1" ]]; then
  npx playwright test -c playwright.client-review-owned.config.ts "$@"
elif [[ -n "${WPTSALL_OWNED_WP_CONTEXT:-}" ]]; then
  npx playwright test -c playwright.owned-wp.config.ts "$@"
else
  npx playwright test -c playwright.client-local-ui.config.ts "$@"
fi
LANE_TEST_RC=$?
set -e
if [ "${LANE_TEST_RC}" -ne 0 ]; then
  err "client-local-ui lane: playwright failed (rc=${LANE_TEST_RC})"
  exit "${LANE_TEST_RC}"
fi

write_lane_report "${LANE_PORT}" "${STATE_ROOT}" "${LANE_MODE}" "ok" "ok"
ok "client-local-ui lane passed (pid=${LANE_CLIENT_PID} port=${LANE_PORT} canary=0 requests)"
