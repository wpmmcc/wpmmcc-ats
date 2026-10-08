#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="${SCRIPT_DIR}"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
RUNTIME_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/runtime"
PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
PLAYWRIGHT_RESULTS_DIR="${PLAYWRIGHT_DIR}/test-results"
PLAYWRIGHT_REPORT_DIR="${PLAYWRIGHT_DIR}/playwright-report"
# 批 O6 复栈: slot-scoped client runtime (see journey-client-controller.sh —
# mirrors config.sh e2e_client_db_path()/session helpers for slot-v).
JOURNEY_SLOT_RUNTIME_DIR="${RUNTIME_DIR}/slot-v"
CLIENT_DB="${JOURNEY_SLOT_RUNTIME_DIR}/client-db/wptsall.db"
CLIENT_SESSION_FILE="${JOURNEY_SLOT_RUNTIME_DIR}/session-token.enc"
# 批 O6 复栈: the web tree moved web/server → web/source/server; the old
# path silently matched nothing (recycle/audit both dead).
SERVER_SRC_DIR="${REPO_ROOT}/web/source/server"
SERVER_BIN="${SERVER_SRC_DIR}/target/release/wptsall-server"
SERVER_ENV_FILE="${SERVER_SRC_DIR}/.env"
SERVER_STATIC_DIR="${REPO_ROOT}/web/source/app/dist"
SERVER_PID_FILE="${RUNTIME_DIR}/journey-server.pid"
CLIENT_PORT="${JOURNEY_CLIENT_PORT:-9096}"
CLIENT_BIN_JOURNEY="${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
# Same pid file the controller writes and stage 02 looks for (slot runtime).
CLIENT_PID_FILE="${JOURNEY_SLOT_RUNTIME_DIR}/client.pid"
SERVER_HEALTH_URL="${WPTSALL_SERVER_HEALTH:-http://127.0.0.1:8787/health}"
CLIENT_STATUS_URL="${CLIENT_BASE:-http://127.0.0.1:${CLIENT_PORT}}/api/status"
PG_DSN="${WPTSALL_PG_DSN:-}"
if [ -z "${PG_DSN}" ] && [ -f "${SERVER_ENV_FILE}" ]; then
  PG_DSN="$(
    awk -F= '
      $1 == "WPTSALL_DATABASE_URL" { print $2; found=1; exit }
      $1 == "DATABASE_URL" && ! found { fallback=$2 }
      END { if (!found && fallback != "") print fallback }
    ' "${SERVER_ENV_FILE}"
  )"
fi
PG_DSN="${PG_DSN:-postgres://postgres:postgres@127.0.0.1:5432/wptsall}"
WPTSALL_PG_DSN="${PG_DSN}"
# ── 盘查批 ⑤ (2026-09-24): dedicated journey database — auto-adopt ──────────
# B 档净室 wipes the server PG state per spec (DROP + re-migrate + re-seed).
# Against the SHARED dev/test database that wipe also destroys other lanes'
# residue (the wptsall role cannot createdb — probed — so the lane cannot
# self-provision isolation). It CAN, however, adopt a dedicated database the
# moment one exists: run once
#   sudo -u postgres createdb -O wptsall wptsall_journey
# and every subsequent run binds server (JOURNEY_SERVER_DATABASE_URL) + spec
# helpers (WPTSALL_PG_DSN) + this runner's psql to it automatically; sqlx
# re-migrates the fresh DB on the first per-spec restart. Until then the
# shared database is used with a loud warning printed on every wipe.
JOURNEY_DB_NAME="wptsall_journey"
JOURNEY_DB_DSN="$(printf '%s' "${PG_DSN}" | sed -E 's#/[^/?]+(\?.*)?$#/'"${JOURNEY_DB_NAME}"'#')"
if psql "${JOURNEY_DB_DSN}" -At -c 'SELECT 1' >/dev/null 2>&1; then
  PG_DSN="${JOURNEY_DB_DSN}"
  WPTSALL_PG_DSN="${PG_DSN}"
  export JOURNEY_SERVER_DATABASE_URL="${JOURNEY_DB_DSN}"
  echo "journey: dedicated PG database in use (${JOURNEY_DB_NAME}) — shared dev state is never touched"
else
  echo "journey: WARNING — no dedicated ${JOURNEY_DB_NAME} database; per-spec wipes target the SHARED database." >&2
  echo "journey:   one-time isolation: sudo -u postgres createdb -O wptsall ${JOURNEY_DB_NAME}" >&2
fi
# 批 O6 复栈: the server serves the public SPA (WPTSALL_STATIC_DIR fallback
# to index.html) — the public auth pages live on the same origin as the API.
# B 档净室 (2026-09-24): the web leg targets THIS origin via
# WPTSALL_WEB_BASE — the unowned vite :5173 leg is retired (the playwright
# config no longer spawns a webServer either).
PUBLIC_WEB_BASE="${WPTSALL_WEB_BASE:-http://127.0.0.1:8787}"
JOURNEY_ENV_POLICY="${WPTSALL_JOURNEY_ENV_POLICY:-soft}"
JOURNEY_SKIP_EXIT_CODE="${WPTSALL_JOURNEY_SKIP_EXIT_CODE:-42}"
RUN_ID="journey-three-system-$(date +%Y%m%d-%H%M%S)"
RUN_LOG_DIR="${RUNTIME_DIR}/journey-runs/${RUN_ID}"
FAILURE_ARCHIVE_DIR="${RUNTIME_DIR}/journey-failures/${RUN_ID}"

# P1-J: website public-auth journeys are legacy-only. Callers must pass
# --legacy-only (or set WPTSALL_JOURNEY_LEGACY_ONLY=1). Default path is
# run-playwright-local-first-journey.sh (plugin + client, no :8787).
LEGACY_ONLY=0
FORWARD_ARGS=()
for arg in "$@"; do
  case "$arg" in
    --legacy-only)
      LEGACY_ONLY=1
      ;;
    --help|-h)
      cat <<'EOF'
Usage: bash run-playwright-journey-three-system.sh --legacy-only [filters... | playwright flags...]

Website control-plane / public-auth journeys (requires :8787). Opt-in only (P1-J).
For local-first (plugin + client) use: run-playwright-local-first-journey.sh

B 档净室 (per-spec clean-room, 2026-09-24): each spec file under
journey-three-system/ runs in its OWN freshly built topology — recreated WP
slot (container + volume), wiped lane runtime (client DB/pairing/data,
server keypair/audit), dropped + re-migrated + re-seeded server PG tables,
re-provisioned verification key. Non-flag args are substring filters on the
spec filenames; args starting with '-' pass through to playwright.
EOF
      exit 0
      ;;
    *)
      FORWARD_ARGS+=("$arg")
      ;;
  esac
done
if [[ "${WPTSALL_JOURNEY_LEGACY_ONLY:-0}" == "1" ]]; then
  LEGACY_ONLY=1
fi
if [[ "${LEGACY_ONLY}" -ne 1 ]]; then
  echo "refusing: journey-three-system is legacy website lane; pass --legacy-only or use run-playwright-local-first-journey.sh" >&2
  exit "${JOURNEY_SKIP_EXIT_CODE}"
fi

set -- "${FORWARD_ARGS[@]}"
JOURNEY_ARGS_JSON="$(printf '%s\n' "$@" | python3 -c 'import json,sys; print(json.dumps([line.rstrip("\n") for line in sys.stdin if line.rstrip("\n")]))')"

mkdir -p "${RUNTIME_DIR}" "${RUNTIME_DIR}/journey-failures" "${RUN_LOG_DIR}"

export CLIENT_DB CLIENT_SESSION_FILE PG_DSN WPTSALL_PG_DSN PUBLIC_WEB_BASE RUN_ID JOURNEY_ARGS_JSON JOURNEY_ENV_POLICY JOURNEY_SKIP_EXIT_CODE

wait_for_url() {
  local url="$1"
  local attempts="${2:-30}"
  for _ in $(seq 1 "${attempts}"); do
    if curl -fsS "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

recycle_server_runtime() {
  local server_pids
  server_pids="$(pgrep -f "${SERVER_BIN}" || true)"
  if [ -n "${server_pids}" ]; then
    for pid in ${server_pids}; do
      kill "${pid}" || true
    done
  fi
  # Also reap a lane-managed server (pid file) from a previous run.
  if [ -f "${SERVER_PID_FILE}" ]; then
    local lane_pid
    lane_pid="$(cat "${SERVER_PID_FILE}" 2>/dev/null || true)"
    if [ -n "${lane_pid}" ] && kill -0 "${lane_pid}" 2>/dev/null; then
      kill "${lane_pid}" 2>/dev/null || true
    fi
    rm -f "${SERVER_PID_FILE}"
  fi
}

# 批 O6 复栈: lane-managed server. The original runner assumed a host systemd
# service (wptsall-server.service targeting /home/john/wptsall-unified — a
# different machine); in this lab the lane manages the server itself via
# scripts/journey-server-controller.sh: PG-backed (.env DSN — migrations
# auto-apply on startup), SPA served via WPTSALL_STATIC_DIR, bound to
# 127.0.0.1:8787, ABSOLUTE argv[0] so pgrep -f can find it.
# Args: "restart" (always relaunch — fresh in-memory auth rate limiter, the
# limiter state otherwise survives across lane runs on the persistent nohup
# server), "ensure" (relaunch only when unhealthy) or "stop" (B 档净室:
# pre-wipe, frees the PG pool).
ensure_server_runtime() {
  local mode="${1:-ensure}"
  bash "${E2E_DIR}/scripts/journey-server-controller.sh" "${mode}"
}

# 批 O6 复栈: the original runner hard-depended on the host systemd user unit
# wptsall-client-webui.service (absent in this lab). Prefer the unit when it
# exists; otherwise delegate to the lane client controller (the SAME script
# the specs' helpers call via WPTSALL_JOURNEY_CLIENT_CTRL — one source of
# truth for the lane-managed client on :${CLIENT_PORT}).
restart_client_runtime() {
  if command -v systemctl >/dev/null 2>&1 \
    && systemctl --user cat wptsall-client-webui.service >/dev/null 2>&1; then
    systemctl --user restart wptsall-client-webui.service
    wait_for_url "${CLIENT_STATUS_URL}" 30
    return $?
  fi
  bash "${E2E_DIR}/scripts/journey-client-controller.sh" restart
}

stop_client_runtime() {
  if command -v systemctl >/dev/null 2>&1 \
    && systemctl --user cat wptsall-client-webui.service >/dev/null 2>&1; then
    systemctl --user stop wptsall-client-webui.service || true
    return 0
  fi
  bash "${E2E_DIR}/scripts/journey-client-controller.sh" stop
}

check_postgres_ready() {
  psql "${PG_DSN}" -At -c 'SELECT 1' 2>/dev/null | grep -qx '1'
}

# ── 批 O6 复栈: dedicated lab WP topology ──────────────────────────────────
# The wp-domain-reverify journey needs a REAL-looking domain (the WP plugin's
# site-verification product rule rejects IP/localhost siteurls, and the server
# FETCHES the verification URL). The lab's shared main WP must NOT have its
# siteurl changed (other lanes assert 127.0.0.1:9083 URLs), and the matrix
# slots a..n keep IP bases — so the journey lane owns the DEDICATED slot-v:
#   - ensure-slot-wordpress.sh provisions it with
#     E2E_SLOT_WP_BASE=http://blog.localhost:9198 (pinned via the container's
#     WORDPRESS_CONFIG_EXTRA — that is why per-run option updates are dead)
#   - `*.localhost` names PASS the plugin's domain validation (dotted,
#     RFC-valid) and resolve natively (systemd-resolved ::1; Chromium maps
#     them to 127.0.0.1) — no /etc/hosts needed
#   - a userspace [::1]:9198 → 127.0.0.1:9198 bridge (docker publishes IPv4
#     only) serves the server's verification fetch; stages run via the IP
#     base + docker exec (host resolution not needed there)
JOURNEY_WP_PORT="${JOURNEY_WP_PORT:-9198}"
JOURNEY_WP_DOMAIN="${JOURNEY_WP_DOMAIN:-blog.localhost}"
JOURNEY_WP_BASE="http://${JOURNEY_WP_DOMAIN}:${JOURNEY_WP_PORT}"
JOURNEY_WP_SLOT="${JOURNEY_WP_SLOT:-wptsall-wp-lab-wordpress-slot-v}"
# Volume naming mirrors ensure-slot-wordpress.sh (SLOT_SAFE=slot_v):
JOURNEY_WP_VOLUME="wptsall-wp-lab_wp_slot_v_data"
JOURNEY_WP_ADMIN_USER="${JOURNEY_WP_ADMIN_USER:-e2eadmin_v}"
JOURNEY_WP_ADMIN_PASS="${JOURNEY_WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
JOURNEY_SKIP_WP_PREPARE="${JOURNEY_SKIP_WP_PREPARE:-0}"

journey_wp_cli() {
  docker exec \
    -e WPTSALL_LAB=1 \
    -e E2E_PROJECT="${E2E_PROJECT:-core-content}" \
    -e E2E_SCOPE="${E2E_SCOPE:-core-only}" \
    -e PHP_MEMORY_LIMIT="${LAB_PHP_MEMORY_LIMIT:-4096M}" \
    -e WP_CLI_PHP="php -d memory_limit=${LAB_PHP_MEMORY_LIMIT:-4096M}" \
    "${JOURNEY_WP_SLOT}" wp --allow-root --path=/var/www/html "$@"
}

ensure_localhost_bridge() {
  if ss -ltn 2>/dev/null | grep -q '\[::1\]:'"${JOURNEY_WP_PORT}"' '; then
    return 0
  fi
  BRIDGE_PORT="${JOURNEY_WP_PORT}" \
    nohup python3 "${E2E_DIR}/scripts/journey-localhost-bridge.py" \
    >"${RUNTIME_DIR}/journey-localhost-bridge.log" 2>&1 &
  echo $! >"${RUNTIME_DIR}/journey-localhost-bridge.pid"
  local i
  for i in $(seq 1 20); do
    ss -ltn 2>/dev/null | grep -q '\[::1\]:'"${JOURNEY_WP_PORT}"' ' && return 0
    sleep 0.25
  done
  echo "localhost bridge did not come up on [::1]:${JOURNEY_WP_PORT}" >&2
  return 1
}

prepare_journey_wp() {
  echo "== Preparing journey WP slot (${JOURNEY_WP_SLOT} @ ${JOURNEY_WP_BASE}) =="
  if [[ "${JOURNEY_SKIP_WP_PREPARE}" != "1" ]]; then
    # Provision the dedicated slot with the DOMAIN base (idempotent; the
    # ensure script creates the container/DB, activates the plugin, and
    # writes the standard slot baseline).
    E2E_SLOT_WP_BASE="${JOURNEY_WP_BASE}" \
      bash "${E2E_DIR}/scripts/ensure-slot-wordpress.sh" slot-v
    (
      cd "${REPO_ROOT}"
      # Slot mode (E2E_SLOT=slot-v) turns on the isolated-slot stage paths:
      # matrix minimum fixups (tags etc.), serial seed scheduler, per-slot
      # admin env. E2E_SLOT_WP_ISOLATED keeps the seed scheduler serial
      # (measured 2026-09-02: parallel `wp import` deadlocks in slots).
      export E2E_SLOT=slot-v
      export E2E_SLOT_WP_ISOLATED=1
      WPTSALL_LAB=1 \
      E2E_PROJECT="${E2E_PROJECT:-core-content}" \
      E2E_SCOPE="${E2E_SCOPE:-core-only}" \
      WP_BASE="http://127.0.0.1:${JOURNEY_WP_PORT}" \
      LAB_WP_HOST="127.0.0.1" \
      LAB_WP_PORT="${JOURNEY_WP_PORT}" \
      LAB_WP_CONTAINER="${JOURNEY_WP_SLOT}" \
      E2E_SKIP_FRONTEND_VERIFY=0 \
      bash tests/modules/wpmmcc-ats/e2e/stages/02-env-clean.sh
      WPTSALL_LAB=1 E2E_PROJECT="${E2E_PROJECT:-core-content}" E2E_SCOPE="${E2E_SCOPE:-core-only}" WP_BASE="http://127.0.0.1:${JOURNEY_WP_PORT}" LAB_WP_HOST="127.0.0.1" LAB_WP_PORT="${JOURNEY_WP_PORT}" LAB_WP_CONTAINER="${JOURNEY_WP_SLOT}" E2E_SLOT=slot-v E2E_SLOT_WP_ISOLATED=1 bash tests/modules/wpmmcc-ats/e2e/stages/03-data-seed.sh
      WPTSALL_LAB=1 E2E_PROJECT="${E2E_PROJECT:-core-content}" E2E_SCOPE="${E2E_SCOPE:-core-only}" WP_BASE="http://127.0.0.1:${JOURNEY_WP_PORT}" LAB_WP_HOST="127.0.0.1" LAB_WP_PORT="${JOURNEY_WP_PORT}" LAB_WP_CONTAINER="${JOURNEY_WP_SLOT}" E2E_SLOT=slot-v E2E_SLOT_WP_ISOLATED=1 bash tests/modules/wpmmcc-ats/e2e/stages/04-model-scan.sh
      WPTSALL_LAB=1 E2E_PROJECT="${E2E_PROJECT:-core-content}" E2E_SCOPE="${E2E_SCOPE:-core-only}" WP_BASE="http://127.0.0.1:${JOURNEY_WP_PORT}" LAB_WP_HOST="127.0.0.1" LAB_WP_PORT="${JOURNEY_WP_PORT}" LAB_WP_CONTAINER="${JOURNEY_WP_SLOT}" E2E_SLOT=slot-v E2E_SLOT_WP_ISOLATED=1 bash tests/modules/wpmmcc-ats/e2e/stages/05-relation-setup.sh
    )
  fi
  # Sanity: the slot's siteurl must already be the DOMAIN base (pinned by the
  # container's WORDPRESS_CONFIG_EXTRA at creation).
  local siteurl
  siteurl="$(journey_wp_cli option get siteurl 2>/dev/null | tail -n 1 | tr -d '[:space:]')"
  [ "${siteurl}" = "${JOURNEY_WP_BASE}" ] \
    || echo "warning: slot siteurl is '${siteurl}', expected ${JOURNEY_WP_BASE} (recreate the slot without JOURNEY_SKIP_WP_PREPARE)" >&2
  journey_wp_cli rewrite flush >/dev/null 2>&1 || true
  echo "journey WP ready: ${JOURNEY_WP_BASE} (admin=${JOURNEY_WP_ADMIN_USER})"
}

check_public_auth_pages() {
  local base="$1"
  local path

  if [ -z "${base}" ]; then
    return 2
  fi

  for path in /login /register /forgot-password; do
    if ! curl -fsS "${base%/}${path}" >/dev/null 2>&1; then
      return 1
    fi
  done

  return 0
}

# 批 O6 复栈: provision the site-verification key contract into the slot WP.
# The plugin's Site_Verification encrypts site_secret to the server's X25519
# public key (sealed box — GET /api/v1/domains/verification-public-key); the
# key must be defined as WPTSALL_SERVER_PUBLIC_KEY in the slot's wp-config.
# Refreshed every run after the server restart: the server persists its
# keypair in its data dir, but a fresh data dir rotates it silently.
provision_wp_verification_key() {
  local key_hex
  key_hex="$(curl -fsS "${PUBLIC_WEB_BASE}/api/v1/domains/verification-public-key" 2>/dev/null \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["public_key_hex"])' 2>/dev/null || true)"
  if [ -z "${key_hex}" ] || [ "${#key_hex}" -ne 64 ]; then
    echo "warning: could not fetch the server verification public key; the reverify journey will fail at bind" >&2
    return 0
  fi
  journey_wp_cli config set WPTSALL_SERVER_PUBLIC_KEY "${key_hex}" --type=constant >/dev/null 2>&1 \
    || echo "warning: failed to define WPTSALL_SERVER_PUBLIC_KEY in the slot WP" >&2
  echo "journey WP verification key provisioned (${#key_hex}-char X25519 hex)"
}

archive_failure_evidence() {
  local exit_code="$1"
  local target_dir
  local wp_logs_dir="/var/www/wordpress/wp-content/uploads/wptsall-logs"
  local wp_plugin_log="/var/www/wordpress/wp-content/logs/plugins/wpmmcc-ats.log"
  local wp_debug_log="/var/www/wordpress/wp-content/debug.log"
  local client_log="${JOURNEY_SLOT_RUNTIME_DIR}/wptsall-client.log"
  # B 档净室: the lane server's audit log lives in the per-spec slot runtime
  # (JOURNEY_SERVER_AUDIT_LOG_FILE); keep the dev ./data path as a fallback
  # for callers that started the server outside B-mode.
  local server_audit="${JOURNEY_SLOT_RUNTIME_DIR}/audit-logs.jsonl"
  if [ ! -f "${server_audit}" ]; then
    server_audit="${SERVER_SRC_DIR}/data/audit-logs.jsonl"
  fi

  if [ "${exit_code}" -eq 0 ] || [ "${exit_code}" -eq "${JOURNEY_SKIP_EXIT_CODE}" ]; then
    return
  fi

  target_dir="${FAILURE_ARCHIVE_DIR}"
  mkdir -p "${target_dir}"

  python3 - <<'PY'
import json
import os
from datetime import datetime, timezone
from pathlib import Path

payload = {
    "run_id": os.environ.get("RUN_ID", ""),
    "exit_code": int(os.environ.get("JOURNEY_EXIT_CODE", "1")),
    "generated_at": datetime.now(timezone.utc).isoformat(),
    "argv": json.loads(os.environ.get("JOURNEY_ARGS_JSON", "[]")),
}

Path(os.environ["FAILURE_ARCHIVE_DIR"] + "/metadata.json").write_text(
    json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
    encoding="utf-8",
)
PY

  if [ -d "${PLAYWRIGHT_RESULTS_DIR}" ]; then
    cp -a "${PLAYWRIGHT_RESULTS_DIR}" "${target_dir}/playwright-test-results"
  fi
  if [ -d "${PLAYWRIGHT_REPORT_DIR}" ]; then
    cp -a "${PLAYWRIGHT_REPORT_DIR}" "${target_dir}/playwright-report"
  fi
  if [ -d "${RUN_LOG_DIR}" ]; then
    cp -a "${RUN_LOG_DIR}" "${target_dir}/spec-logs"
  fi

  curl -fsS "${CLIENT_STATUS_URL}" > "${target_dir}/client-status.json" 2>/dev/null || true
  curl -fsS -X POST "${CLIENT_BASE:-http://127.0.0.1:${CLIENT_PORT}}/api/logs/recent" \
    -H 'Content-Type: application/json' \
    -d '{"limit":200}' > "${target_dir}/client-recent-logs.json" 2>/dev/null || true

  if [ -f "${client_log}" ]; then
    tail -n 400 "${client_log}" > "${target_dir}/client-log.tail.log" || true
  fi
  if [ -f "${server_audit}" ]; then
    tail -n 400 "${server_audit}" > "${target_dir}/server-audit.tail.jsonl" || true
  fi
  if [ -d "${wp_logs_dir}" ]; then
    mkdir -p "${target_dir}/wp-logs"
    find "${wp_logs_dir}" -maxdepth 1 -type f -name '*.log' -printf '%T@ %p\n' 2>/dev/null \
      | sort -nr \
      | head -n 20 \
      | cut -d' ' -f2- \
      | while IFS= read -r file; do
      tail -n 200 "${file}" > "${target_dir}/wp-logs/$(basename "${file}")" || true
    done
  fi
  if [ -f "${wp_plugin_log}" ]; then
    tail -n 200 "${wp_plugin_log}" > "${target_dir}/wpmmcc-ats.log" || true
  fi
  if [ -f "${wp_debug_log}" ]; then
    tail -n 200 "${wp_debug_log}" > "${target_dir}/wp-debug.log" || true
  fi

  journalctl --user -u wptsall-client-webui.service -n 200 --no-pager > "${target_dir}/client-journal.log" 2>/dev/null || true
  journalctl -u wptsall-server.service -n 200 --no-pager > "${target_dir}/server-journal.log" 2>/dev/null || true
}

journey_exit_trap() {
  local exit_code="$?"
  export JOURNEY_EXIT_CODE="${exit_code}"
  export FAILURE_ARCHIVE_DIR
  archive_failure_evidence "${exit_code}" "$@"
  exit "${exit_code}"
}

# ── 批 O6 复栈 (B 档净室): per-spec clean-room primitives ───────────────────
# User decision 2026-09-24 (environment-sharing audit + the serial
# job-to-client 0-pull mystery): the six specs previously shared ONE
# topology — one WP slot, one client runtime, one server PG state — so any
# spec could drain the shared task pool, flip global switches (discovery
# tasks off), or consume the site_relations the next spec depended on. B 档:
# every spec gets a factory-fresh topology, rebuilt before it runs.

# Drop the journey server's PG state wholesale (server MUST already be
# stopped — its pool would otherwise re-flush in-memory state into the fresh
# tables mid-wipe). Why DROP and not TRUNCATE: sqlx migrations only CREATE IF
# NOT EXISTS, so with tables present the restart re-runs nothing and the
# seed stage duplicates rows; with _sqlx_migrations dropped too, the restart
# replays every migration and WPTSALL_SEED_DATA rebuilds the deterministic
# demo state. Table names are enumerated from information_schema so future
# migrations are covered without touching this script. (Schema-per-spec is
# impossible here: the PG role lacks createdb, and sqlx 0.8 ignores the DSN
# `options=-csearch_path=...` parameter — probed live 2026-09-24, migrations
# landed in public with probe schemas empty.)
reset_journey_server_db() {
  local drop_stmts
  drop_stmts="$(psql "${PG_DSN}" -At -c "SELECT string_agg(format('DROP TABLE IF EXISTS %I.%I CASCADE', table_schema, table_name), '; ') FROM information_schema.tables WHERE table_schema = 'public' AND (table_name LIKE 'wptsall%' OR table_name = '_sqlx_migrations');" || true)"
  if [ -z "${drop_stmts}" ]; then
    echo "journey: no wptsall_* tables to drop (fresh database)" >&2
    return 0
  fi
  psql "${PG_DSN}" -v ON_ERROR_STOP=1 -c "${drop_stmts};" >/dev/null
  echo "journey: server PG state dropped; restart re-migrates + re-seeds"
}

teardown_journey_wp_slot() {
  # Full clean-room: ensure-slot-wordpress.sh is IDEMPOTENT and never
  # recreates a healthy container, so an explicit container + volume rm is
  # the only way to guarantee factory-fresh WP state (DB, options, plugin
  # tables, uploaded fixture content) per spec.
  docker rm -f "${JOURNEY_WP_SLOT}" >/dev/null 2>&1 || true
  docker volume rm "${JOURNEY_WP_VOLUME}" >/dev/null 2>&1 || true
}

wipe_journey_slot_runtime() {
  # Everything the lane owns per-spec: client SQLite + pairing/session/
  # bindings files, client data dir (raw/translated payloads — was the dev
  # source tree's ./data before the controller override), server data dir
  # (X25519 verification keypair — rotated per spec, re-provisioned into WP)
  # and the lane audit log.
  rm -rf "${JOURNEY_SLOT_RUNTIME_DIR}"
  mkdir -p "${JOURNEY_SLOT_RUNTIME_DIR}"
}

# Build the clean-room topology and run ONE spec in it. Called under `if`
# (set -e suspended inside) so one spec's infra failure cannot abort the
# remaining specs.
run_spec_clean_room() {
  local spec_path="$1"
  local spec_key
  spec_key="$(basename "${spec_path}" .journey.e2e.spec.ts)"
  local spec_log="${RUN_LOG_DIR}/spec-${spec_key}.log"

  echo "════ [B 档净室] ${spec_key}: rebuilding clean topology ════" | tee -a "${spec_log}"
  stop_client_runtime >>"${spec_log}" 2>&1 || true
  ensure_server_runtime stop >>"${spec_log}" 2>&1 || true
  teardown_journey_wp_slot
  wipe_journey_slot_runtime
  reset_journey_server_db >>"${spec_log}" 2>&1

  # Fresh server: re-migrations + WPTSALL_SEED_DATA demo state, per-spec
  # data dir (fresh X25519 keypair) + audit log inside the wiped runtime.
  JOURNEY_SERVER_DATA_DIR="${JOURNEY_SLOT_RUNTIME_DIR}/server-data" \
  JOURNEY_SERVER_AUDIT_LOG_FILE="${JOURNEY_SLOT_RUNTIME_DIR}/audit-logs.jsonl" \
    ensure_server_runtime restart >>"${spec_log}" 2>&1 \
    || { echo "ERROR: server restart failed for ${spec_key} (see ${spec_log})" >&2; return 1; }
  if ! check_public_auth_pages "${PUBLIC_WEB_BASE}"; then
    echo "ERROR: public auth pages unreachable at ${PUBLIC_WEB_BASE} for ${spec_key}" >&2
    return 1
  fi

  prepare_journey_wp >>"${spec_log}" 2>&1 \
    || { echo "ERROR: journey WP preparation failed for ${spec_key} (see ${spec_log})" >&2; return 1; }
  provision_wp_verification_key >>"${spec_log}" 2>&1

  # Fresh client: boots an empty SQLite in the wiped runtime against the
  # fresh server (deterministic device id pw-journey via the controller).
  bash "${E2E_DIR}/scripts/journey-client-controller.sh" restart >>"${spec_log}" 2>&1 \
    || { echo "ERROR: client restart failed for ${spec_key} (see ${spec_log})" >&2; return 1; }

  echo "════ [B 档净室] ${spec_key}: topology ready — running spec ════" | tee -a "${spec_log}"
  set +e
  npx playwright test -c playwright.journey-three-system.config.ts \
    "journey-three-system/$(basename "${spec_path}")" \
    --output="${PLAYWRIGHT_RESULTS_DIR}/${spec_key}" \
    "${PLAYWRIGHT_PASSTHRU[@]}" 2>&1 | tee -a "${spec_log}"
  local rc="${PIPESTATUS[0]}"
  set -e

  # B 档净室 diagnostics: preserve THIS iteration's runtime evidence before
  # the next spec's wipe destroys it (first clean-room run left only
  # playwright artifacts — the per-iteration client log/DB and server audit
  # were unrecoverable).
  mkdir -p "${RUN_LOG_DIR}/${spec_key}"
  cp -a "${JOURNEY_SLOT_RUNTIME_DIR}/wptsall-client.log" "${RUN_LOG_DIR}/${spec_key}/" 2>/dev/null || true
  cp -a "${JOURNEY_SLOT_RUNTIME_DIR}/wptsall-client.log.launcher.log" "${RUN_LOG_DIR}/${spec_key}/" 2>/dev/null || true
  cp -a "${JOURNEY_SLOT_RUNTIME_DIR}/audit-logs.jsonl" "${RUN_LOG_DIR}/${spec_key}/" 2>/dev/null || true
  cp -a "${JOURNEY_SLOT_RUNTIME_DIR}/client-db" "${RUN_LOG_DIR}/${spec_key}/" 2>/dev/null || true
  return "${rc}"
}

trap 'journey_exit_trap "$@"' EXIT

# ── 批 O6 复栈 (B 档净室): per-spec clean-room loop ─────────────────────────
ensure_localhost_bridge || {
  echo "journey: [::1]:${JOURNEY_WP_PORT} bridge unavailable (${JOURNEY_ENV_POLICY} policy)" >&2
  [ "${JOURNEY_ENV_POLICY}" = "soft" ] && exit "${JOURNEY_SKIP_EXIT_CODE}"
  exit 1
}
if ! check_postgres_ready; then
  echo "journey: postgres unavailable via WPTSALL_PG_DSN (${JOURNEY_ENV_POLICY} policy)" >&2
  [ "${JOURNEY_ENV_POLICY}" = "soft" ] && exit "${JOURNEY_SKIP_EXIT_CODE}"
  exit 1
fi

# Spec inventory: every journey spec, alphabetical. Non-flag args act as
# substring filters on the file names; flag args (leading '-') pass through
# to each playwright invocation.
SPEC_SOURCES=()
for f in "${PLAYWRIGHT_DIR}/journey-three-system"/*.journey.e2e.spec.ts; do
  SPEC_SOURCES+=("$f")
done
PLAYWRIGHT_PASSTHRU=()
SPEC_FILTERS=()
for a in "$@"; do
  if [[ "${a}" == -* ]]; then
    PLAYWRIGHT_PASSTHRU+=("${a}")
  else
    SPEC_FILTERS+=("${a}")
  fi
done
if [ "${#SPEC_FILTERS[@]}" -gt 0 ]; then
  FILTERED=()
  for f in "${SPEC_SOURCES[@]}"; do
    for pat in "${SPEC_FILTERS[@]}"; do
      if [[ "${f}" == *"${pat}"* ]]; then
        FILTERED+=("$f")
        break
      fi
    done
  done
  if [ "${#FILTERED[@]}" -gt 0 ]; then
    SPEC_SOURCES=("${FILTERED[@]}")
  fi
fi
if [ "${#SPEC_SOURCES[@]}" -eq 0 ]; then
  echo "journey: no spec files matched (${JOURNEY_SKIP_EXIT_CODE} skip)" >&2
  exit "${JOURNEY_SKIP_EXIT_CODE}"
fi

cd "${PLAYWRIGHT_DIR}"
# 批 O6 复栈 spec env:
#   - WPTSALL_JOURNEY_CLIENT_CTRL: lane client controller (helpers.ts client
#     restart/caps flows — no systemd user unit in the lab)
#   - WPTSALL_JOURNEY_WP_CONTAINER: journey WP slot container (helpers.ts
#     execWpEval routes wp eval through docker exec)
#   - WP_BASE / WP_ADMIN_*: the slot WP origin + seeded admin
#   - JOURNEY_WP_DOMAIN: the domain the reverify journey expects in the
#     web table + client visibility
# B 档净室 additions:
#   - WPTSALL_WEB_BASE: the web leg targets the lane server's STATIC_DIR SPA
#     at :8787 (helpers.ts default was the unowned vite :5173 — every web
#     page hit used to land on a dev server the lane neither owned nor
#     monitored; the playwright config's webServer block is retired with it).
export WPTSALL_JOURNEY_CLIENT_CTRL="${E2E_DIR}/scripts/journey-client-controller.sh"
export WPTSALL_SERVER_CTRL="${E2E_DIR}/scripts/journey-server-controller.sh"
export WPTSALL_SERVER_BIN="${SERVER_BIN}"
export WPTSALL_CLIENT_DB="${CLIENT_DB}"
export WPTSALL_CLIENT_SESSION_FILE="${CLIENT_SESSION_FILE}"
export WPTSALL_JOURNEY_WP_CONTAINER="${JOURNEY_WP_SLOT}"
export WPTSALL_WEB_BASE="${PUBLIC_WEB_BASE}"
# Slot-mode wiring for the spec layer: E2E_SLOT drives the TS client-port map
# (e2e-slot-ports.ts) and bash guard invariants; CLIENT_BASE must point at the
# lane client port (9096), never shared :8977.
export E2E_SLOT=slot-v
export E2E_SLOT_WP_ISOLATED=1
export CLIENT_BASE="http://127.0.0.1:${CLIENT_PORT}"
export CLIENT_URL="http://127.0.0.1:${CLIENT_PORT}"
export JOURNEY_CLIENT_PORT="${CLIENT_PORT}"
export WP_BASE="${JOURNEY_WP_BASE}"
export WP_URL="${JOURNEY_WP_BASE}"
export WP_ADMIN_USER="${JOURNEY_WP_ADMIN_USER}"
export WP_ADMIN_PASS="${JOURNEY_WP_ADMIN_PASS}"
export JOURNEY_WP_DOMAIN="${JOURNEY_WP_DOMAIN}"

SUMMARY_FILE="${RUN_LOG_DIR}/summary.tsv"
printf 'spec\tresult\tduration_s\n' >"${SUMMARY_FILE}"
OVERALL_FAILURES=0
for spec_path in "${SPEC_SOURCES[@]}"; do
  spec_key="$(basename "${spec_path}" .journey.e2e.spec.ts)"
  spec_started="$(date +%s)"
  if run_spec_clean_room "${spec_path}"; then
    spec_result="PASS"
  else
    spec_result="FAIL"
    OVERALL_FAILURES=$((OVERALL_FAILURES + 1))
  fi
  spec_secs=$(( $(date +%s) - spec_started ))
  printf '%s\t%s\t%s\n' "${spec_key}" "${spec_result}" "${spec_secs}" | tee -a "${SUMMARY_FILE}"
done

echo ""
echo "== [B 档净室] journey run ${RUN_ID} summary =="
cat "${SUMMARY_FILE}"
if [ "${OVERALL_FAILURES}" -ne 0 ]; then
  echo "journey: ${OVERALL_FAILURES} spec(s) failed (logs: ${RUN_LOG_DIR})" >&2
  exit 1
fi
echo "journey: all specs green (logs: ${RUN_LOG_DIR})"
