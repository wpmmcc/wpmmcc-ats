#!/usr/bin/env bash
# Journey client controller (批 O6 复栈): single source of truth for
# restarting the lane-managed journey client at :9096. Used by BOTH the
# journey runner (restart_client_runtime) and the specs' helpers.ts
# (WPTSALL_JOURNEY_CLIENT_CTRL) — the lab has no systemd user unit, so the
# lane owns the client process.
#
# Usage: journey-client-controller.sh restart [KEY=VAL ...]
#   Restarts the client with the given env pairs applied (e.g. run caps).
# Usage: journey-client-controller.sh stop
#   Stops the lane client (B 档净室 pre-wipe step).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Five levels up: e2e/scripts -> e2e -> wpmmcc-ats -> modules -> tests -> root.
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../../.." && pwd)"
RUNTIME_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/runtime"
# Slot-scoped client runtime (mirrors config.sh e2e_client_db_path() /
# e2e_client_session_token_file() / e2e_client_pid_file() for E2E_SLOT=slot-v
# and stage 02-env-clean's stop_this_slot_client). Without this the client
# reuses client-wpplugin/source/runtime state and shows up ALREADY paired
# (no OAuth login button — the onboarding/review journeys die at step 1) and
# with stale cached site/domain tables.
SLOT_RUNTIME_DIR="${RUNTIME_DIR}/slot-v"
SLOT_CLIENT_DIR="${SLOT_RUNTIME_DIR}/client-db"
CLIENT_PORT="${JOURNEY_CLIENT_PORT:-9096}"
CLIENT_BIN="${JOURNEY_CLIENT_BIN:-${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client}"
# Stage 02 looks for the slot client pid exactly here (e2e_client_pid_file).
CLIENT_PID_FILE="${SLOT_RUNTIME_DIR}/client.pid"
CLIENT_LOG_FILE="${SLOT_RUNTIME_DIR}/wptsall-client.log"
SERVER_BASE="${JOURNEY_SERVER_BASE:-http://127.0.0.1:8787}"

[ -x "${CLIENT_BIN}" ] || { echo "journey client binary missing: ${CLIENT_BIN}" >&2; exit 1; }
mkdir -p "${SLOT_CLIENT_DIR}"

stop_lane_client() {
  if [ -f "${CLIENT_PID_FILE}" ]; then
    local old_pid
    old_pid="$(cat "${CLIENT_PID_FILE}" 2>/dev/null || true)"
    if [ -n "${old_pid}" ] && kill -0 "${old_pid}" 2>/dev/null; then
      kill "${old_pid}" 2>/dev/null || true
      sleep 1
      kill -9 "${old_pid}" 2>/dev/null || true
    fi
    rm -f "${CLIENT_PID_FILE}"
  fi
  # Free the port from any stray holder (bracket pattern avoids self-match).
  local port_pids
  port_pids="$(ss -ltnp 2>/dev/null | awk -F'pid=' "/:${CLIENT_PORT}/ {split(\$2,a,\",\"); print a[1]}" | sort -u || true)"
  for p in ${port_pids}; do
    [ -n "${p}" ] || continue
    kill "${p}" 2>/dev/null || true
  done
  sleep 1
}

start_lane_client() {
  local env_pairs=("$@")
  (
    cd "${REPO_ROOT}/client-wpplugin/source"
    export WPTSALL_WEB_UI=1
    export WPTSALL_SERVER_BASE="${SERVER_BASE}"
    # 批 O6 复栈: the three-system journey exercises the SERVER control plane
    # (web-account OAuth login, server domains/tasks). Since P0-LF-02 the
    # plane is opt-in on the client — WPTSALL_SERVER_BASE alone never enables
    # it (config::server_control_plane_enabled), and without this flag every
    # legacy route (including POST /api/oauth/start) answers
    # LEGACY_CONTROL_PLANE_DISABLED.
    export WPTSALL_USE_SERVER_CONTROL_PLANE="${WPTSALL_USE_SERVER_CONTROL_PLANE:-1}"
    export WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}"
    export WPTSALL_WEB_UI_PORT="${CLIENT_PORT}"
    # Slot-scoped runtime: fresh pairing/session per slot lifecycle, shared
    # nothing with the shared lab client or the matrix slots.
    export WPTSALL_DB_PATH="${SLOT_CLIENT_DIR}/wptsall.db"
    export WPTSALL_SESSION_TOKEN_FILE="${SLOT_RUNTIME_DIR}/session-token.enc"
    export WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${SLOT_RUNTIME_DIR}/domain-token-bindings.json"
    export WPTSALL_LOG_FILE="${CLIENT_LOG_FILE}"
    # 批 O6 复栈 (B 档净室): the client's data dir (raw/translated payloads)
    # defaults to ./data under its CWD — the DEV SOURCE TREE, accumulating
    # every run's files forever. Route it into the slot runtime dir
    # (config::resolve_data_path honors WPTSALL_DATA_DIR) so B-mode's
    # per-spec runtime wipe cleans it with everything else.
    export WPTSALL_DATA_DIR="${JOURNEY_CLIENT_DATA_DIR:-${SLOT_RUNTIME_DIR}/data}"
    mkdir -p "${WPTSALL_DATA_DIR}"
    # The lab shell exports corporate proxies; the client's WP fetches use the
    # slot domain (blog.localhost:9198) which bare `localhost` in NO_PROXY does
    # NOT cover — route it direct like 127.0.0.1.
    export NO_PROXY="${NO_PROXY:-},blog.localhost,*.localhost"
    export no_proxy="${NO_PROXY}"
    # Deterministic WP device identity for the lane (opus5 A-03 boot-level
    # override): the specs' resolveLiveWpClientCredentials issues the site
    # token via wptsall_issue_client_device_token("pw-journey", ...) and the
    # WP plugin binds it to that device id. Without this override the client
    # boots with its random DB UUID and every binding transport 401s with
    # client_unauthorized (verified live: bootstrap DISCOVERY_TASKS_BOOTSTRAP_FAILED).
    export WPTSALL_WP_DEVICE_ID="${WPTSALL_WP_DEVICE_ID:-pw-journey}"
    for pair in "${env_pairs[@]}"; do
      export "${pair}"
    done
    # ABSOLUTE argv[0]: pgrep -f fallbacks in the spec helpers match on it.
    nohup "${CLIENT_BIN}" --webui >"${CLIENT_LOG_FILE}.launcher.log" 2>&1 &
    echo $! >"${CLIENT_PID_FILE}"
  )
}

wait_client_ready() {
  local i
  for i in $(seq 1 60); do
    curl -fsS "http://127.0.0.1:${CLIENT_PORT}/api/status" >/dev/null 2>&1 && return 0
    sleep 0.5
  done
  echo "journey client did not become ready on :${CLIENT_PORT}" >&2
  return 1
}

case "${1:-}" in
  restart)
    shift || true
    stop_lane_client
    start_lane_client "$@"
    wait_client_ready
    echo "journey client restarted (pid $(cat "${CLIENT_PID_FILE}")) on :${CLIENT_PORT}"
    ;;
  stop)
    # 批 O6 复栈 (B 档净室): the runner stops the client BEFORE wiping the
    # slot runtime (DB/data/pairing) — the running client would otherwise
    # recreate its SQLite mid-wipe and hold the old in-memory state.
    stop_lane_client
    echo "journey client stopped"
    ;;
  *)
    echo "usage: $0 restart [KEY=VAL ...]" >&2
    exit 2
    ;;
esac
