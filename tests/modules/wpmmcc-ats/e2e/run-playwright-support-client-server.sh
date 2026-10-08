#!/usr/bin/env bash
# P0-LF-07: one isolated legacy server-control-plane lane.
#
# Explicit compatibility lane for retained control-plane behavior:
#   - WPTSALL_USE_SERVER_CONTROL_PLANE=1 (set here, never assumed);
#   - WPTSALL_SERVER_BASE is REQUIRED (no default control-plane URL — an
#     unset base is an explicit configuration error);
#   - one owned Client process on an owned loopback port with its own DB,
#     config, data, log and session file (never the developer 8977 client,
#     never systemd);
#   - runs support-client-server specs only;
#   - reports are labeled legacy_server_control_plane;
#   - never a prerequisite for local UI, manual WP or automatic direct-WP
#     tests — run it explicitly.
#
# Modes:
#   default          : full legacy suite (WP Lab bootstrap + DEFAULT_SUPPORT_SPECS)
#   WPTSALL_LEGACY_ISOLATION_ONLY=1 : only the isolation assertion spec, no WP Lab
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
CLIENT_SRC="${REPO_ROOT}/client-wpplugin/source"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
REPORT_DIR="${REPO_ROOT:-$(cd "$(dirname "$0")/../../.." ${SCRIPT_DIR}/reports${SCRIPT_DIR}/reports pwd)/tests/reports/e2e/wpmmcc-ats}/client-server-legacy/${RUN_ID}"
LANE_LABEL="legacy_server_control_plane"
mkdir -p "${REPORT_DIR}"

LANE_CLIENT_PID=""
LANE_PORT=""
STATE_ROOT=""
LANE_MODE="unknown"
FLAG_OFF_RESTORE="not-run"
# FL-6 (Wave-2): same defect class as the sim lane — the report used to
# hardcode an "ok"-ish result even when playwright failed (set -e jumped to
# the EXIT trap). Track the real playwright exit code instead.
LANE_TEST_RC=""

abort() { err "legacy lane: $*"; exit 1; }

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
  local result="ok"
  if [ -n "${LANE_TEST_RC}" ] && [ "${LANE_TEST_RC}" -ne 0 ]; then
    result="failed (playwright rc=${LANE_TEST_RC})"
  elif [ -z "${LANE_TEST_RC}" ]; then
    result="aborted (no playwright rc recorded)"
  fi
  cat > "${REPORT_DIR}/lane-report.txt" <<EOF
P0-LF-07 legacy lane report
label: ${LANE_LABEL}
run_id: ${RUN_ID}
mode: ${LANE_MODE}
flag_off_restore: ${FLAG_OFF_RESTORE}
client_pid: ${LANE_CLIENT_PID:-not-started}
client_port: ${LANE_PORT}
client_base_url: ${LANE_PORT:+http://127.0.0.1:${LANE_PORT}}
server_base: ${WPTSALL_SERVER_BASE:-<unset>}
state_root: ${STATE_ROOT}
cleanup_result: ok
result: ${result}
artifacts: ${REPORT_DIR}
EOF
  cat "${REPORT_DIR}/lane-report.txt"
}

stop_client_pid() {
  if [ -n "${LANE_CLIENT_PID}" ] && kill -0 "${LANE_CLIENT_PID}" 2>/dev/null; then
    kill "${LANE_CLIENT_PID}" 2>/dev/null || true
    for _ in $(seq 1 20); do
      kill -0 "${LANE_CLIENT_PID}" 2>/dev/null || break
      sleep 0.5
    done
    kill -9 "${LANE_CLIENT_PID}" 2>/dev/null || true
  fi
}

cleanup() {
  stop_client_pid
  if [ -n "${STATE_ROOT}" ] && [ -d "${STATE_ROOT}" ]; then
    write_lane_report >/dev/null 2>&1 || true
    cp "${STATE_ROOT}"/state/client-*.log "${REPORT_DIR}/" 2>/dev/null || true
    rm -rf "${STATE_ROOT}"
  else
    write_lane_report >/dev/null 2>&1 || true
  fi
  ok "cleanup: owned legacy client stopped, temp state root removed (report kept in ${REPORT_DIR})"
}
trap cleanup EXIT INT TERM

print_stage "SUPPORT" "Playwright Client Server Support (P0-LF-07 legacy lane)"

# ---------------------------------------------------------------------------
# 1. Explicit configuration gate — no default control-plane URL, ever.
# ---------------------------------------------------------------------------
if [ -z "${WPTSALL_SERVER_BASE:-}" ]; then
  abort "WPTSALL_SERVER_BASE must be set explicitly for the legacy server-control-plane lane (no default URL; see P0-LF-07 / package matrix)"
fi
export WPTSALL_USE_SERVER_CONTROL_PLANE=1
info "Legacy lane server base: ${WPTSALL_SERVER_BASE} (flag on, explicit)"

# ---------------------------------------------------------------------------
# 2. Owned loopback port + temporary state root (never 8977 / 8787)
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
info "Owned legacy loopback port: ${LANE_PORT} (8977/8787 untouched)"

STATE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wptsall-client-legacy-lane.XXXXXX")"
mkdir -p "${STATE_ROOT}/state"
info "Temporary legacy state root: ${STATE_ROOT}"

export WPTSALL_WEB_UI=1
export WPTSALL_WEB_UI_BIND="127.0.0.1:${LANE_PORT}"
export WPTSALL_WEB_UI_PORT="${LANE_PORT}"
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
mkdir -p "${WPTSALL_DATA_DIR}"

# Specs resolve the owned legacy client through resolveSlotClientBase().
export CLIENT_BASE="http://127.0.0.1:${LANE_PORT}"
export CLIENT_URL="${CLIENT_BASE}"
export WPTSALL_CLIENT_LEGACY_BASE_URL="${CLIENT_BASE}"

# ---------------------------------------------------------------------------
# 3. One owned legacy Client process (debug build, fail fast on early exit)
# ---------------------------------------------------------------------------
TARGET_DIR="${CARGO_TARGET_DIR:-${CLIENT_SRC}/target}"
info "Building owned legacy Client (debug, incremental)..."
(cargo build --manifest-path "${CLIENT_SRC}/Cargo.toml" --bin wptsall-client --quiet) \
  || abort "cargo build --bin wptsall-client failed"
CLIENT_BIN="${TARGET_DIR}/debug/wptsall-client"
[ -x "${CLIENT_BIN}" ] || abort "client binary not found at ${CLIENT_BIN}"

launch_owned_client() {
  # $1 = control-plane flag value (1 legacy / 0 restore check)
  (cd "${CLIENT_SRC}" && WPTSALL_USE_SERVER_CONTROL_PLANE="$1" exec "${CLIENT_BIN}" \
    >"${STATE_ROOT}/state/client-stdout.log" 2>"${STATE_ROOT}/state/client-stderr.log") &
  LANE_CLIENT_PID=$!
  sleep 0.5
  kill -0 "${LANE_CLIENT_PID}" 2>/dev/null || abort "owned legacy Client exited immediately"
}

launch_owned_client 1

ready=""
for _ in $(seq 1 60); do
  if ! kill -0 "${LANE_CLIENT_PID}" 2>/dev/null; then
    abort "owned legacy Client exited early during readiness wait (see ${STATE_ROOT}/state/client-stderr.log)"
  fi
  if check_url "${CLIENT_BASE}/api/status"; then
    ready="yes"
    break
  fi
  sleep 1
done
[ -n "${ready}" ] || {
  warn "owned legacy Client did not become ready; last client stderr:"
  tail -5 "${STATE_ROOT}/state/client-stderr.log" 2>/dev/null || true
  abort "owned legacy Client did not become ready at ${CLIENT_BASE}"
}

LANE_MODE="$(curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" \
  | python3 -c 'import json,sys; print(json.load(sys.stdin).get("data", {}).get("runtime_mode", "unknown"))' \
  2>/dev/null || echo unknown)"
info "Owned legacy Client ready: pid=${LANE_CLIENT_PID} port=${LANE_PORT} runtime_mode=${LANE_MODE}"
if [ "${LANE_MODE}" != "${LANE_LABEL}" ]; then
  abort "expected runtime_mode=${LANE_LABEL}, got ${LANE_MODE}"
fi

# ---------------------------------------------------------------------------
# 4. Optional WP Lab bootstrap (skipped in isolation-only mode)
# ---------------------------------------------------------------------------
if [ "${WPTSALL_LEGACY_ISOLATION_ONLY:-0}" = "1" ]; then
  info "WPTSALL_LEGACY_ISOLATION_ONLY=1: skipping WP Lab bootstrap, running isolation spec only"
  PLAYWRIGHT_TARGETS=("support-client-server/legacy-lane-isolation.spec.ts")
else
  info "Refreshing live WP Client API runtime..."
  wp_eval "${E2E_DIR}/php/ensure-client-api-runtime.php" >/dev/null
  ok "Live WP Client API runtime refreshed"

  WP_CLIENT_TOKEN="${WPTSALL_E2E_WP_CLIENT_TOKEN:-$(cd "$WP_ROOT" && "$WP_CLI" eval 'if(function_exists("wptsall_issue_client_device_token")){$d=wptsall_issue_client_device_token("e2e-shell","e2e"); echo (string)$d["token"];}' 2>/dev/null)}"
  ROUTE_SECRET="${WPTSALL_E2E_ROUTE_SECRET:-$(cd "$WP_ROOT" && "$WP_CLI" eval 'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";' 2>/dev/null)}"
  [ -n "${WP_CLIENT_TOKEN}" ] || abort "failed to resolve live wp_client_token (or set WPTSALL_E2E_WP_CLIENT_TOKEN)"
  [ -n "${ROUTE_SECRET}" ] || abort "failed to resolve live route_secret (or set WPTSALL_E2E_ROUTE_SECRET)"
  ok "Resolved live token prefix: ${WP_CLIENT_TOKEN:0:12}"

  # Bootstrap binding goes through the OWNED client (isolated DB/state);
  # the developer's config/domain-token-bindings.json is never touched.
  upsert_payload="$(jq -n \
    --arg api_base_url "${WP_URL}" \
    --arg wp_client_token "${WP_CLIENT_TOKEN}" \
    --arg route_secret "${ROUTE_SECRET}" \
    '{api_base_url: $api_base_url, wp_client_token: $wp_client_token, route_secret: $route_secret}')"
  upsert_response="$(curl --noproxy '*' -fsS -X POST "${CLIENT_BASE}/api/domain-tokens/upsert" \
    -H 'Content-Type: application/json' -d "${upsert_payload}")"
  echo "${upsert_response}" | jq -e '.success == true' >/dev/null \
    || abort "failed to upsert runtime binding on the owned legacy client: ${upsert_response}"
  ok "Runtime binding upserted on owned legacy client"

  if [ "$#" -gt 0 ]; then
    PLAYWRIGHT_TARGETS=("$@")
  else
    PLAYWRIGHT_TARGETS=(
      "support-client-server/components-server-filters.support.spec.ts"
      "support-client-server/components-sync-ui.spec.ts"
      "support-client-server/components-webui-migration.support.spec.ts"
      "support-client-server/overview-preflight.support.spec.ts"
    )
  fi
fi

# ---------------------------------------------------------------------------
# 5. Playwright (support-client-server only)
# ---------------------------------------------------------------------------
cd "${PLAYWRIGHT_DIR}"
# FL-6: capture the real playwright exit code (set +e) so the lane report
# and this script's own exit status both tell the truth.
set +e
npx playwright test -c playwright.client-server-support.config.ts "${PLAYWRIGHT_TARGETS[@]}"
LANE_TEST_RC=$?
set -e
if [ "${LANE_TEST_RC}" -ne 0 ]; then
  err "legacy lane: playwright failed (rc=${LANE_TEST_RC})"
  exit "${LANE_TEST_RC}"
fi

# ---------------------------------------------------------------------------
# 6. Flag-off restore check: same stale state, flag 1→0 must restore local.
# ---------------------------------------------------------------------------
info "Flag-off restore check: relaunching same state root with control-plane flag off..."
stop_client_pid
launch_owned_client 0

restore_ready=""
for _ in $(seq 1 30); do
  if ! kill -0 "${LANE_CLIENT_PID}" 2>/dev/null; then
    break
  fi
  if check_url "${CLIENT_BASE}/api/status"; then
    restore_ready="yes"
    break
  fi
  sleep 1
done
if [ "${restore_ready}" = "yes" ]; then
  RESTORED_MODE="$(curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin).get("data", {}).get("runtime_mode", "unknown"))' \
    2>/dev/null || echo unknown)"
  if [ "${RESTORED_MODE}" = "local" ]; then
    FLAG_OFF_RESTORE="ok"
    ok "flag-off restore: runtime_mode=local with the same state root"
  else
    FLAG_OFF_RESTORE="failed (runtime_mode=${RESTORED_MODE})"
    abort "flag-off restore check failed: expected runtime_mode=local, got ${RESTORED_MODE}"
  fi
else
  FLAG_OFF_RESTORE="failed (not ready)"
  abort "flag-off restore check failed: client did not become ready with the flag off"
fi

write_lane_report
ok "legacy lane passed (label=${LANE_LABEL} pid=${LANE_CLIENT_PID} port=${LANE_PORT} flag_off_restore=${FLAG_OFF_RESTORE})"
