#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: 环境清理
#
# 清理三系统翻译产物，确保纯净环境。
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 2 "Environment Cleanup"

START=$(stage_start_time)

# Shared parallel matrices reset once in their parent. Isolated slot WordPress
# containers must reset independently, otherwise a later project inherits the
# prior project's posts/relations/tasks.
if [[ "${E2E_SKIP_WP_RESET:-0}" == "1" ]] \
  || { [[ "${E2E_MATRIX_PARALLEL:-0}" == "1" ]] && ! e2e_uses_isolated_slot_wordpress; }; then
  info "Skipping WP deep reset (shared matrix / E2E_SKIP_WP_RESET=1)"
else
  # ── WP 侧清理 ────────────────────────────────────────────────────────────────
  info "Running WP-side deep reset..."
  wp_eval "${E2E_DIR}/php/reset.php"
fi

# ── Client 侧清理 ────────────────────────────────────────────────────────────
# Lab uses WPTSALL_DB_PATH under ~/projects/runtime/...; wiping the in-repo
# default path leaves discovery "done" and Stage 6 becomes dedup_or_noop with
# zero virtual write-backs after WP deep reset.
info "Resetting Client translation state..."
CLIENT_DB="$(e2e_client_db_path)"
curl -s -X POST "${CLIENT_URL}/api/worker/stop" >/dev/null 2>&1 || true
# Only stop *this slot's* client. Parallel matrix runs multiple clients.
stop_this_slot_client() {
  local pid_file
  pid_file="$(e2e_client_pid_file)"
  if [[ -f "${pid_file}" ]]; then
    local pid
    pid="$(tr -d ' \n' < "${pid_file}" || true)"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
      kill "${pid}" 2>/dev/null || true
      sleep 0.3
      kill -9 "${pid}" 2>/dev/null || true
    fi
    rm -f "${pid_file}"
  fi
  # Fallback: match bind address for this slot port only
  local port
  port="$(e2e_client_port)"
  pkill -f "WPTSALL_WEB_UI_BIND=127.0.0.1:${port}" 2>/dev/null || true
  pkill -f "WPTSALL_WEB_UI_PORT=${port}" 2>/dev/null || true
}

if e2e_is_slot_mode; then
  stop_this_slot_client
elif _is_lab_mode && check_url "${CLIENT_URL}/api/status"; then
  warn "Stopping Lab client before SQLite workload cleanup"
  stop_this_slot_client
  # Shared lab (no slot): also clear any leftover release client on default port
  pkill -f "WPTSALL_WEB_UI_BIND=127.0.0.1:8977" 2>/dev/null || true
  for _i in $(seq 1 20); do
    check_url "${CLIENT_URL}/api/status" || break
    sleep 0.25
  done
fi

if [ -f "${CLIENT_DB}" ]; then
  if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "${CLIENT_DB}" <<'SQL' >/dev/null 2>&1 || true
DELETE FROM pending_callbacks;
DELETE FROM translation_records;
DELETE FROM translation_in_progress;
DELETE FROM translation_items;
DELETE FROM translation_jobs;
DELETE FROM object_sync_coordination;
DELETE FROM discovery_tasks;
DELETE FROM retry_queue;
DELETE FROM task_queue_state;
DELETE FROM system_config WHERE key = 'domain_token_bindings_doc';
SQL
  elif command -v python3 >/dev/null 2>&1; then
    python3 - "${CLIENT_DB}" <<'PY' >/dev/null 2>&1 || true
import sqlite3, sys
con = sqlite3.connect(sys.argv[1])
for t in (
    "pending_callbacks",
    "translation_records",
    "translation_in_progress",
    "translation_items",
    "translation_jobs",
    "object_sync_coordination",
    "discovery_tasks",
    "retry_queue",
    "task_queue_state",
):
    try:
        con.execute(f"DELETE FROM {t}")
    except Exception:
        pass
try:
    con.execute("DELETE FROM system_config WHERE key = 'domain_token_bindings_doc'")
except Exception:
    pass
con.commit()
con.close()
PY
  else
    warn "Neither sqlite3 nor python3 available; cannot clear ${CLIENT_DB}"
  fi
  # Drop WAL leftovers so next read sees cleared tables.
  rm -f "${CLIENT_DB}-shm" "${CLIENT_DB}-wal" 2>/dev/null || true
  rm -f "$(e2e_client_domain_token_bindings_file)" 2>/dev/null || true
  ok "Client workload cleared at ${CLIENT_DB}"
else
  warn "Client DB missing (${CLIENT_DB:-unset}); fallback file wipe"
  rm -f \
    "${REPO_ROOT}/client-wpplugin/source/runtime/wptsall.db" \
    "${REPO_ROOT}/client-wpplugin/source/runtime/wptsall.db-shm" \
    "${REPO_ROOT}/client-wpplugin/source/runtime/wptsall.db-wal" \
    "${REPO_ROOT}/client-wpplugin/source/runtime/pending-callbacks.json" \
    "${REPO_ROOT}/client-wpplugin/source/runtime/task-queue-state.json"
  if [ -n "${CLIENT_DB:-}" ] && [ "${CLIENT_DB}" != "${REPO_ROOT}/client-wpplugin/source/runtime/wptsall.db" ]; then
    rm -f "${CLIENT_DB}" "${CLIENT_DB}-shm" "${CLIENT_DB}-wal" 2>/dev/null || true
  fi
  ok "Client SQLite DB reset (fallback)"
fi

info "Cleaning Client logs..."
rm -f "${REPO_ROOT}/client-wpplugin/source/logs/wptsall-client.log"
rm -f "${HOME}/projects/runtime/clients/wpplugin/logs/cli-worker.log" 2>/dev/null || true
ok "Client logs cleaned"

# ── Runtime 目录 ──────────────────────────────────────────────────────────────
info "Cleaning runtime directory..."
# Preserve the host snapshot evidence across gate-stage cleanup: the
# release gate's later heavy-gate-budget checks (RG-MATRIX) re-read
# test-host-snapshot-latest.json, and RG-CORE runs this stage internally.
SNAPSHOT_PRESERVE_DIR=""
if compgen -G "${RUNTIME_DIR}/test-host-snapshot*" >/dev/null 2>&1; then
  SNAPSHOT_PRESERVE_DIR="$(mktemp -d /tmp/wptsall-snapshot-preserve.XXXXXX)"
  mv "${RUNTIME_DIR}"/test-host-snapshot* "${SNAPSHOT_PRESERVE_DIR}/" 2>/dev/null || true
fi
rm -rf "${RUNTIME_DIR}"
ensure_dirs
if [ -n "${SNAPSHOT_PRESERVE_DIR}" ]; then
  mv "${SNAPSHOT_PRESERVE_DIR}"/test-host-snapshot* "${RUNTIME_DIR}/" 2>/dev/null || true
  rm -rf "${SNAPSHOT_PRESERVE_DIR}"
fi
ok "Runtime directory cleaned"

stage_elapsed "$START"
ok "Environment cleanup complete!"
