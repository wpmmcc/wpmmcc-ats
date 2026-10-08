#!/usr/bin/env bash
# 批 O6 复栈: journey-lane server controller.
#
# The three-system journey lane manages its OWN wptsall-server instance
# (the legacy host systemd unit points at a different machine and is absent
# here). The spec helpers' restartJourneyServer() must be able to RESTART it
# (e.g. to clear the in-memory auth rate limiter — see
# web/source/server/src/services/rate_limit.rs: state is lost on restart).
# Killing without relaunching leaves the lane dead, so the restart flow lives
# here and both the runner and the specs call this script.
#
# Usage: journey-server-controller.sh {restart|ensure|stop}
#   restart - always stop + relaunch (fresh limiter / deterministic state)
#   ensure   - relaunch only when :8787 health check fails (cheap idempotent)
#   stop     - stop the lane server
#
# Launch invariants (do not regress):
#   - argv[0] is the ABSOLUTE binary path so `pgrep -f <abs-path>` (helpers'
#     fallback path) can find the process.
#   - Env: DATABASE_URL from web/source/server/.env (sqlx auto-migrates),
#     WPTSALL_STATIC_DIR=web/source/app/dist (SPA serving).
set -euo pipefail

SERVER_PORT="${JOURNEY_SERVER_PORT:-8787}"
SERVER_HEALTH_URL="http://127.0.0.1:${SERVER_PORT}/health"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../../.." && pwd)"
E2E_RUNTIME_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${E2E_RUNTIME_DIR}"
PID_FILE="${E2E_RUNTIME_DIR}/journey-server.pid"
LOG_FILE="${E2E_RUNTIME_DIR}/journey-server.log"

SERVER_SRC_DIR="${REPO_ROOT}/web/source/server"
SERVER_BIN="${JOURNEY_SERVER_BIN:-${SERVER_SRC_DIR}/target/release/wptsall-server}"
SERVER_ENV_FILE="${SERVER_SRC_DIR}/.env"
SERVER_STATIC_DIR="${REPO_ROOT}/web/source/app/dist"

if [ ! -x "${SERVER_BIN}" ]; then
  echo "[journey-server] ERROR: server binary missing: ${SERVER_BIN} (cargo build --release in web/source/server)" >&2
  exit 1
fi

server_healthy() {
  curl -fsS "${SERVER_HEALTH_URL}" >/dev/null 2>&1
}

stop_server() {
  if [ -f "${PID_FILE}" ]; then
    local old_pid
    old_pid="$(cat "${PID_FILE}" 2>/dev/null || true)"
    if [ -n "${old_pid}" ] && kill -0 "${old_pid}" 2>/dev/null; then
      kill "${old_pid}" 2>/dev/null || true
      sleep 1
      kill -9 "${old_pid}" 2>/dev/null || true
    fi
    rm -f "${PID_FILE}"
  fi
  # Fallback: anything still holding the port (bracket avoids self-match).
  local port_pids
  port_pids="$(ss -ltnp 2>/dev/null | awk -F'pid=' "/:${SERVER_PORT}/ {split(\$2,a,\",\"); print a[1]}" | sort -u || true)"
  for p in ${port_pids}; do
    [ -n "${p}" ] || continue
    kill "${p}" 2>/dev/null || true
  done
  sleep 1
}

start_server() {
  (
    cd "${SERVER_SRC_DIR}"
    # shellcheck disable=SC1090
    [ -f "${SERVER_ENV_FILE}" ] && set -a && source "${SERVER_ENV_FILE}" && set +a
    # 批 O6 复栈 (B 档净室): per-spec overrides applied AFTER sourcing .env
    # so the journey lane can isolate server state from the dev-lab database
    # when callers provide one (JOURNEY_SERVER_DATABASE_URL). Not exported
    # when unset (empty would override the .env value — env::var().filter()
    # in the server treats empty as unset, but stay explicit here).
    if [ -n "${JOURNEY_SERVER_DATABASE_URL:-}" ]; then
      export DATABASE_URL="${JOURNEY_SERVER_DATABASE_URL}"
    fi
    # Server-side state dir (verification keypair etc.) — default into the
    # e2e runtime so a per-spec wipe clears the keypair and each spec gets a
    # fresh X25519 key (bootstrap.rs: WPTSALL_DATA_DIR overrides ./data).
    if [ -n "${JOURNEY_SERVER_DATA_DIR:-}" ]; then
      export WPTSALL_DATA_DIR="${JOURNEY_SERVER_DATA_DIR}"
      mkdir -p "${JOURNEY_SERVER_DATA_DIR}"
    fi
    # Audit log likewise (bootstrap.rs: WPTSALL_AUDIT_LOG_FILE) — keep the
    # dev ./data/audit-logs.jsonl out of the per-spec state.
    if [ -n "${JOURNEY_SERVER_AUDIT_LOG_FILE:-}" ]; then
      export WPTSALL_AUDIT_LOG_FILE="${JOURNEY_SERVER_AUDIT_LOG_FILE}"
    fi
    export WPTSALL_STATIC_DIR="${SERVER_STATIC_DIR}"
    export WPTSALL_SERVER_BIND="127.0.0.1:${SERVER_PORT}"
    export WPTSALL_SEED_DATA="${WPTSALL_SEED_DATA:-true}"
    # 批 O6 复栈 dev-lab switches (development mode only — see
    # config::allow_private_site_verification): let the domain bind/reverify
    # accept the lab slot's *.localhost verification URL (the product guards
    # stay unconditional in production).
    export WPTSALL_ALLOW_PRIVATE_SITE_VERIFICATION="${WPTSALL_ALLOW_PRIVATE_SITE_VERIFICATION:-1}"
    # The lab shell exports corporate HTTP(S)_PROXY; reqwest honors them and
    # NO_PROXY's bare `localhost` entry does NOT cover *.localhost subdomains —
    # without this the bind fetch to blog.localhost:9198 would be routed at
    # the corporate proxy and die.
    export NO_PROXY="${NO_PROXY:-},blog.localhost,*.localhost"
    export no_proxy="${NO_PROXY}"
    # ABSOLUTE path on argv[0]: helpers' pgrep -f fallback matches on it.
    nohup "${SERVER_BIN}" >"${LOG_FILE}" 2>&1 &
    echo $! >"${PID_FILE}"
  )
  local i
  for i in $(seq 1 60); do
    server_healthy && return 0
    sleep 0.5
  done
  echo "[journey-server] server did not become healthy on :${SERVER_PORT} (log: ${LOG_FILE})" >&2
  return 1
}

case "${1:-ensure}" in
  restart)
    stop_server
    start_server
    echo "[journey-server] restarted (pid $(cat "${PID_FILE}"))"
    ;;
  ensure)
    if server_healthy; then
      echo "[journey-server] already healthy on :${SERVER_PORT}"
    else
      stop_server || true
      start_server
      echo "[journey-server] launched (pid $(cat "${PID_FILE}"))"
    fi
    ;;
  stop)
    stop_server
    echo "[journey-server] stopped"
    ;;
  *)
    echo "usage: $0 {restart|ensure|stop}" >&2
    exit 2
    ;;
esac
