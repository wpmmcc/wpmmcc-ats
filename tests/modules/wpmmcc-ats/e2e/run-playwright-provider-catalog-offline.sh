#!/usr/bin/env bash
# 3.8flash A3+D4 (VERIFIED-REPAIR-PLAN-20260925): provider-catalog offline
# degradation lane.
#
# Owns everything it needs (same pattern as run-playwright-support-client-local-ui.sh):
#   - one owned loopback port (never the shared 8977/8787);
#   - one temporary state root with every persisted path isolated
#     (including WPTSALL_PROVIDER_CATALOG_FILE, so the client starts from the
#     genuine never_fetched state);
#   - one owned 404 server as the catalog source
#     (WPTSALL_PROVIDER_CATALOG_SOURCE_URL -> 127.0.0.1:port/catalog.json,
#     allowed via WPTSALL_PROVIDER_CATALOG_ALLOW_LOOPBACK_SOURCE): every
#     request is RECORDED and answered 404 — a genuinely unreachable/
#     broken catalog source without touching the network;
#   - one owned Client process (trapped; fails loudly on early exit).
#
# The spec then proves the degradation contract: the first catalog list
# serves the built-in seed templates fast (3s-bounded fetch, no 30s hang),
# the manual refresh degrades honestly (offline + fallback), the 404 source
# was actually contacted, and the WebUI vendor-catalog tab renders the seed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
CLIENT_SRC="${REPO_ROOT}/client-wpplugin/source"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/provider-catalog-offline/${RUN_ID}"
mkdir -p "${REPORT_DIR}"

LANE_CLIENT_PID=""
LANE_404_PID=""
STATE_ROOT=""
LANE_TEST_RC=""

abort() { err "provider-catalog-offline lane: $*"; exit 1; }

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
  local client_port="$1" state_root="$2" result="$3" result_404=""
  if [ -f "${state_root}/catalog-404/requests.log" ]; then
    result_404="$(grep -c . "${state_root}/catalog-404/requests.log" 2>/dev/null || echo 0)"
  else
    result_404="0"
  fi
  cat > "${REPORT_DIR}/lane-report.txt" <<EOF
3.8flash A3+D4 provider-catalog-offline lane report
run_id: ${RUN_ID}
client_pid: ${LANE_CLIENT_PID:-not-started}
client_port: ${client_port}
client_base_url: http://127.0.0.1:${client_port}
catalog_404_port: ${LANE_404_PORT:-unknown}
catalog_404_requests: ${result_404}
state_root: ${state_root}
result: ${result}
artifacts: ${REPORT_DIR}
EOF
  cp "${state_root}/catalog-404/requests.log" "${REPORT_DIR}/catalog-404-requests.log" >/dev/null 2>&1 || true
  cat "${REPORT_DIR}/lane-report.txt"
}

cleanup() {
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
  if [ -n "${LANE_404_PID}" ] && kill -0 "${LANE_404_PID}" 2>/dev/null; then
    kill "${LANE_404_PID}" 2>/dev/null || true
  fi
  if [ -n "${STATE_ROOT}" ] && [ -d "${STATE_ROOT}" ]; then
    write_lane_report "${LANE_PORT:-unknown}" "${STATE_ROOT}" "${result}" >/dev/null 2>&1 || true
    cp "${STATE_ROOT}"/state/client-stderr.log "${REPORT_DIR}/client-stderr.log" >/dev/null 2>&1 || true
    rm -rf "${STATE_ROOT}"
  else
    write_lane_report "${LANE_PORT:-unknown}" "${STATE_ROOT:-unknown}" "${result}" >/dev/null 2>&1 || true
  fi
  ok "cleanup: owned client and 404 server stopped, temp state root removed (report kept in ${REPORT_DIR})"
}
trap cleanup EXIT INT TERM

print_stage "A3+D4" "Provider Catalog Offline Degradation lane"

# ---------------------------------------------------------------------------
# 1. Owned ports + temporary state root
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

STATE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wptsall-provider-catalog-offline.XXXXXX")"
mkdir -p "${STATE_ROOT}/state" "${STATE_ROOT}/catalog-404"
info "Temporary state root: ${STATE_ROOT}"

# ---------------------------------------------------------------------------
# 2. Owned 404 server as the catalog source (records every request)
# ---------------------------------------------------------------------------
LANE_404_PORT="$(choose_free_port)"
cat > "${STATE_ROOT}/catalog-404/server_404.py" <<EOF
import http.server
import socketserver
import sys

port = int(sys.argv[1])
log_path = sys.argv[2]

class Handler404(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _record(self):
        with open(log_path, "a") as f:
            f.write("%s %s\n" % (self.command, self.path))

    def _respond(self):
        # Record on receipt, then stall PAST the old 30s timeout before the
        # 404: a client bounded to 3s (the A3 fix) aborts at ~3s and falls
        # back to the built-in seed, while any regression back toward the
        # old 30s timeout hangs long enough to fail the spec's elapsed
        # assertion. A plain instant 404 would pass under BOTH timeouts and
        # pin nothing.
        import time
        self._record()
        time.sleep(float(sys.argv[3]) if len(sys.argv) > 3 else 35.0)
        body = b"offline-lane: catalog source is unavailable"
        self.send_response(404)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = _respond

    def log_message(self, *args):
        pass

socketserver.TCPServer.allow_reuse_address = True
with socketserver.TCPServer(("127.0.0.1", port), Handler404) as httpd:
    httpd.serve_forever()
EOF
python3 "${STATE_ROOT}/catalog-404/server_404.py" "${LANE_404_PORT}" \
  "${STATE_ROOT}/catalog-404/requests.log" 35 &
LANE_404_PID=$!
sleep 0.5
kill -0 "${LANE_404_PID}" 2>/dev/null || abort "catalog 404 server failed to start"
LANE_404_URL="http://127.0.0.1:${LANE_404_PORT}"
info "Owned catalog 404 source (35s stall then 404): ${LANE_404_URL}/catalog.json (requests recorded on receipt)"

# ---------------------------------------------------------------------------
# 3. Isolated state + the A3/D4 catalog envs (source -> the 404 server)
# ---------------------------------------------------------------------------
CLIENT_LOG="${STATE_ROOT}/state/client-stderr.log"

export WPTSALL_USE_SERVER_CONTROL_PLANE=0
export WPTSALL_WEB_UI=1
export WPTSALL_WEB_UI_BIND="127.0.0.1:${LANE_PORT}"
export WPTSALL_WEB_UI_PORT="${LANE_PORT}"
export WPTSALL_DB_PATH="${STATE_ROOT}/state/wptsall.db"
export WPTSALL_DATA_DIR="${STATE_ROOT}/state/data"
export WPTSALL_LOG_FILE="${STATE_ROOT}/state/client.log"
export WPTSALL_LOG_ENABLED=true
# The never_fetched catalog state: the cache path points INSIDE the state
# root but the file itself must NOT exist (same as the client-local-ui lane)
# — an existing-but-empty file is a parse error, not the never_fetched
# state; the loader falls back to the built-in seed only for a missing file.
export WPTSALL_PROVIDER_CATALOG_FILE="${STATE_ROOT}/state/provider-catalog.json"
rm -f "${WPTSALL_PROVIDER_CATALOG_FILE}"
# D4: the catalog source points at the owned 404 server (loopback source
# needs the explicit allow flag; production ignores unofficial sources).
export WPTSALL_PROVIDER_CATALOG_SOURCE_URL="${LANE_404_URL}/catalog.json"
export WPTSALL_PROVIDER_CATALOG_ALLOW_LOOPBACK_SOURCE=1
mkdir -p "${WPTSALL_DATA_DIR}"

export WPTSALL_PROVIDER_CATALOG_OFFLINE_BASE_URL="http://127.0.0.1:${LANE_PORT}"
export WPTSALL_PROVIDER_CATALOG_OFFLINE_404_URL="${LANE_404_URL}"
export WPTSALL_PROVIDER_CATALOG_OFFLINE_404_LOG="${STATE_ROOT}/catalog-404/requests.log"

# ---------------------------------------------------------------------------
# 4. One owned Client process
# ---------------------------------------------------------------------------
TARGET_DIR="${CARGO_TARGET_DIR:-${CLIENT_SRC}/target}"
info "Building owned Client (debug, incremental)..."
(cargo build --manifest-path "${CLIENT_SRC}/Cargo.toml" --bin wptsall-client --quiet) \
  || abort "cargo build --bin wptsall-client failed"
CLIENT_BIN="${TARGET_DIR}/debug/wptsall-client"
[ -x "${CLIENT_BIN}" ] || abort "client binary not found at ${CLIENT_BIN}"

info "Launching owned Client on ${WPTSALL_PROVIDER_CATALOG_OFFLINE_BASE_URL}..."
(cd "${CLIENT_SRC}" && exec "${CLIENT_BIN}" >"${STATE_ROOT}/state/client-stdout.log" 2>"${CLIENT_LOG}") &
LANE_CLIENT_PID=$!
sleep 0.5
kill -0 "${LANE_CLIENT_PID}" 2>/dev/null || abort "owned Client exited immediately (see ${CLIENT_LOG})"

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
info "Owned Client ready: pid=${LANE_CLIENT_PID} port=${LANE_PORT}"

# ---------------------------------------------------------------------------
# 5. Playwright
# ---------------------------------------------------------------------------
cd "${PLAYWRIGHT_DIR}"
set +e
npx playwright test -c playwright.provider-catalog-offline.config.ts "$@"
LANE_TEST_RC=$?
set -e
if [ "${LANE_TEST_RC}" -ne 0 ]; then
  err "provider-catalog-offline lane: playwright failed (rc=${LANE_TEST_RC})"
  exit "${LANE_TEST_RC}"
fi

write_lane_report "${LANE_PORT}" "${STATE_ROOT}" "ok" >/dev/null 2>&1 || true
ok "provider-catalog-offline lane passed (pid=${LANE_CLIENT_PID} port=${LANE_PORT} 404-source=${LANE_404_URL})"
