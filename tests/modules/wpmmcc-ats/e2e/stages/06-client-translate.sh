#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Stage 6: Client 翻译
#
# 启动 mock-translate-api + Client Web UI + 运行 Playwright 测试。
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 6 "Client Translation"

START=$(stage_start_time)

HEADED="${1:-}"
SKIP_CLIENT="${SKIP_CLIENT:-}"
MOCK_API_PID=""
CLIENT_PID=""
CLIENT_SUITE_STATUS_FILE="${RUNTIME_DIR}/client-suite-status.env"
WEB_APP_URL="${WPTSALL_WEB_BASE:-http://127.0.0.1:5173}"
WEB_APP_LOG="${RUNTIME_DIR}/web-app-vite.log"

write_client_suite_status() {
  local ran="${1:-0}"
  local skipped="${2:-0}"
  local reason="${3:-none}"
  local oauth_status="${4:-}"

  mkdir -p "${RUNTIME_DIR}"
  cat > "${CLIENT_SUITE_STATUS_FILE}" <<EOF
CLIENT_SUITE_RAN=${ran}
CLIENT_SUITE_SKIPPED=${skipped}
CLIENT_SUITE_SKIP_REASON=${reason}
CLIENT_OAUTH_HTTP_STATUS=${oauth_status}
EOF
}

write_client_suite_status 0 0 none ""

ensure_client_binary_fresh() {
  local client_crate="${REPO_ROOT}/client-wpplugin/source"
  # Lab: release-lab (thin LTO + incremental) for fast 2nd/3rd builds.
  # Shipping / non-Lab: keep fat-LTO --release.
  local profile="release"
  local out_dir="release"
  if _is_lab_mode; then
    profile="release-lab"
    out_dir="release-lab"
  fi
  local build_cmd=(cargo build --profile "${profile}")
  local preferred_bin="${client_crate}/target/${out_dir}/wptsall-client"

  # Prefer matching the currently selected binary target triple when present.
  case "$CLIENT_BIN" in
    */target/x86_64-unknown-linux-gnu/release/*)
      build_cmd=(cargo build --release --target x86_64-unknown-linux-gnu)
      preferred_bin=""
      ;;
    */target/aarch64-unknown-linux-gnu/release/*)
      build_cmd=(cargo build --release --target aarch64-unknown-linux-gnu)
      preferred_bin=""
      ;;
  esac

  if [ -n "$preferred_bin" ] && [ -x "$preferred_bin" ]; then
    CLIENT_BIN="$preferred_bin"
  fi

  if [ ! -x "$CLIENT_BIN" ]; then
    info "Client binary missing, building profile=${profile}..."
    (cd "${client_crate}" && "${build_cmd[@]}" >/tmp/wptsall-client-build.log 2>&1) || {
      tail -n 80 /tmp/wptsall-client-build.log || true
      abort "Client build failed"
    }
    CLIENT_BIN="$(resolve_first_existing \
      "${preferred_bin}" \
      "${WPTSALL_CLIENT_BIN:-}" \
      "${REPO_ROOT}/client-wpplugin/source/target/release-lab/wptsall-client" \
      "${REPO_ROOT}/client-wpplugin/source/target/release/wptsall-client" \
      "${REPO_ROOT}/client-wpplugin/source/target/x86_64-unknown-linux-gnu/release/wptsall-client" \
      "${REPO_ROOT}/runtime/bin/client-wpplugin" \
      "${REPO_ROOT}/runtime/bin/wptsall-client")"
    ok "Client binary built: ${CLIENT_BIN}"
    return
  fi

  if find "${client_crate}/src" -type f -newer "$CLIENT_BIN" | grep -q .; then
    # Lab: if Client WebUI is already healthy, keep the running binary to avoid long rebuilds.
    if _is_lab_mode && check_url "${CLIENT_URL%/}/"; then
      warn "Client binary is stale but Lab WebUI is healthy at ${CLIENT_URL}; skipping rebuild"
      return
    fi
    info "Client binary is stale, rebuilding profile=${profile}..."
    (cd "${client_crate}" && "${build_cmd[@]}" >/tmp/wptsall-client-build.log 2>&1) || {
      tail -n 80 /tmp/wptsall-client-build.log || true
      abort "Client rebuild failed"
    }
    if [ -n "$preferred_bin" ] && [ -x "$preferred_bin" ]; then
      CLIENT_BIN="$preferred_bin"
    fi
    ok "Client binary rebuilt: ${CLIENT_BIN}"
  fi
}

ensure_wp_admin_account() {
  local admin_user="${WP_ADMIN_USER:-e2esmokeadmin}"
  local admin_pass="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
  local admin_email="${WP_ADMIN_EMAIL:-e2esmokeadmin@wpmm.test}"

  if wp_cli user get "$admin_user" --field=ID >/dev/null 2>&1; then
    # Keep the password in sync with the Playwright helpers default. Resetting
    # on an existing user invalidates old sessions/nonces, but Stage 6
    # Playwright always logs in with a fresh browser context, so stale
    # sessions are irrelevant; a stale password instead fails wpLogin with
    # "incorrect password" (observed gate 4: envira lane, 582ms abort while
    # a standalone rerun passed because the user had been recreated).
    wp_cli user update "$admin_user" --role=administrator --user_pass="$admin_pass" >/dev/null
  else
    wp_cli user create "$admin_user" "$admin_email" --role=administrator --user_pass="$admin_pass" >/dev/null
  fi
}

ensure_web_app_frontend() {
  if check_url "${WEB_APP_URL%/}/login"; then
    ok "Web app frontend already running at ${WEB_APP_URL}"
    return 0
  fi

  info "Starting Web app frontend at ${WEB_APP_URL}..."
  mkdir -p "${RUNTIME_DIR}"
  if [ ! -d "${REPO_ROOT}/web/app/node_modules" ]; then
    (cd "${REPO_ROOT}/web/app" && npm install --registry https://registry.npmjs.org >>"${WEB_APP_LOG}" 2>&1) || {
      tail -n 80 "${WEB_APP_LOG}" || true
      abort "Web app dependency install failed"
    }
  fi

  (
    cd "${REPO_ROOT}/web/app"
    nohup npm run dev -- --host 127.0.0.1 --port 5173 >"${WEB_APP_LOG}" 2>&1 &
  )

  echo -n "  Waiting for Web app frontend"
  for _ in $(seq 1 60); do
    if check_url "${WEB_APP_URL%/}/login"; then
      echo " ready!"
      ok "Web app frontend started at ${WEB_APP_URL}"
      return 0
    fi
    sleep 0.5
    echo -n "."
  done

  echo ""
  tail -n 80 "${WEB_APP_LOG}" || true
  abort "Web app frontend failed to start"
}

# ── Trap: 清理后台进程 ───────────────────────────────────────────────────────
cleanup() {
  if [ -n "$CLIENT_PID" ] && ! _is_lab_mode; then
    info "Stopping Client (PID=$CLIENT_PID)..."
    kill "$CLIENT_PID" 2>/dev/null || true
  fi
  if [ -n "$MOCK_API_PID" ]; then
    info "Stopping mock-translate-api (PID=$MOCK_API_PID)..."
    kill "$MOCK_API_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

# ── 1. 启动 mock-translate-api ───────────────────────────────────────────────
info "Checking mock-translate-api..."
if check_url "${MOCK_API_URL}/api/v1/health"; then
  ok "mock-translate-api already running at :9090"
else
  info "Starting mock-translate-api..."
  "$MOCK_API_BIN" &
  MOCK_API_PID=$!
  sleep 2

  if check_url "${MOCK_API_URL}/api/v1/health"; then
    ok "mock-translate-api started (PID=$MOCK_API_PID)"
  else
    err "mock-translate-api failed to start"
    # Continue — some tests may still work
  fi
fi

# Protocol v2: issue WP device token before Client start so WPTSALL_DEVICE_ID is never empty at boot.
if [ -z "${WP_CLIENT_TOKEN:-}" ]; then
  # Non-slot runs must use the shared lab device identity so the token
  # matches the device the client presents (and the CT lane's fixture
  # token issuance); slot runs keep their per-slot device.
  if e2e_is_slot_mode; then
    PREBOOT_DEVICE="e2e-${E2E_SLOT:-slot-a}"
  else
    PREBOOT_DEVICE="${CT_LAB_WP_DEVICE_ID}"
  fi
  DEVICE_JSON="$(wp_cli eval "if(function_exists(\"wptsall_issue_client_device_token\")){ \$d=wptsall_issue_client_device_token(\"${PREBOOT_DEVICE}\",\"e2e\"); echo wp_json_encode(\$d); }" 2>/dev/null || true)"
  if [ -n "${DEVICE_JSON}" ] && command -v python3 >/dev/null 2>&1; then
    WP_CLIENT_TOKEN="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("token",""))' "${DEVICE_JSON}" 2>/dev/null || true)"
    WPTSALL_DEVICE_ID="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("device_id",""))' "${DEVICE_JSON}" 2>/dev/null || true)"
  fi
fi
export WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-}"
export WPTSALL_WP_DEVICE_ID="${WPTSALL_DEVICE_ID}"
if [ -z "${ROUTE_SECRET:-}" ]; then
  ROUTE_SECRET="$(wp_cli eval 'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";' 2>/dev/null || true)"
fi
export WP_CLIENT_TOKEN ROUTE_SECRET WPTSALL_WP_CLIENT_TOKEN="${WP_CLIENT_TOKEN}"
if [ -n "${WPTSALL_DEVICE_ID}" ]; then
  ok "Pre-boot device token device_id=${WPTSALL_DEVICE_ID}"
fi

# ── 2. 启动 Client Web UI ───────────────────────────────────────────────────
_stop_client_web_ui() {
  curl -s -X POST "${CLIENT_URL}/api/worker/stop" >/dev/null 2>&1 || true
  _pid_file="$(e2e_client_pid_file)"
  if [[ -f "${_pid_file}" ]]; then
    _pid="$(tr -d ' \n' < "${_pid_file}" || true)"
    [[ -n "${_pid}" ]] && kill "${_pid}" 2>/dev/null || true
    rm -f "${_pid_file}"
  fi
  pkill -f "WPTSALL_WEB_UI_BIND=127.0.0.1:${CLIENT_PORT}" 2>/dev/null || true
  if command -v fuser >/dev/null 2>&1; then
    fuser -k "${CLIENT_PORT}/tcp" 2>/dev/null || true
  fi
  sleep 1
}

if [ -z "$SKIP_CLIENT" ]; then
  info "Checking Client..."
  ensure_client_binary_fresh
  need_client_restart=0
  if _is_lab_mode && check_url "${CLIENT_URL}/api/status"; then
    client_server_base="$(curl -sf "${CLIENT_URL}/api/status" 2>/dev/null | python3 -c "import sys,json; d=json.load(sys.stdin); print((d.get('data') or {}).get('server_base',''))" 2>/dev/null || true)"
    lab_server_base="${SERVER_URL:-http://127.0.0.1:8787}"
    if [[ -n "$client_server_base" && "$client_server_base" != "$lab_server_base" && "$client_server_base" != "${WPTSALL_SERVER_BASE_LOCAL:-}" ]]; then
      warn "Client server_base=${client_server_base} (expected Lab ${lab_server_base}); restarting slot ${E2E_SLOT}"
      need_client_restart=1
      _stop_client_web_ui
    fi
  fi
  if check_url "${CLIENT_URL}/api/status" && [ "$need_client_restart" -eq 0 ]; then
    # Reusing a pre-existing client: drop any stale pid file from an earlier
    # run so shared-mode on_exit hygiene (run.sh cleanup_lane_resources)
    # cannot kill an unrelated process via pid reuse.
    rm -f "$(e2e_client_pid_file)"
    ok "Client already running at :${CLIENT_PORT} (slot=${E2E_SLOT})"
  else
    info "Starting Client Web UI on :${CLIENT_PORT} (slot=${E2E_SLOT})..."

    # Source client .env if exists (Lab overrides WPTSALL_SERVER_BASE below).
    if [ -f "$CLIENT_ENV" ]; then
      set -a
      # shellcheck source=/dev/null
      source "$CLIENT_ENV"
      set +a
      if _is_lab_mode; then
        unset WPTSALL_SERVER_BASE
      fi
    fi
    # Slot isolation: CLIENT_ENV defaults to :8977 — never leave that in the shell.
    if e2e_is_slot_mode; then
      export WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}"
      export WPTSALL_WEB_UI_PORT="${CLIENT_PORT}"
    fi

    client_runtime_dir="$(e2e_client_runtime_dir)"
    client_log_file="$(e2e_client_log_file)"
    session_token_file="$(e2e_client_session_token_file)"
    mkdir -p "${client_runtime_dir}" "$(dirname "${client_log_file}")" "$(dirname "$(e2e_client_pid_file)")" "$(dirname "${session_token_file}")"

    # Lab must talk to local wptsall-server for OAuth PKCE; production URL returns 403.
    lab_server_base="${SERVER_URL:-http://127.0.0.1:8787}"
    if _is_lab_mode; then
      # Local-first (AGENTS.md §0.1): the client never talks to a website
      # control plane. The former gate required a live :8787 wptsall-server
      # here only to serve the removed website-OAuth suite; the client itself
      # starts fine without it (verified: it boots and serves /api/status
      # regardless of SERVER_BASE reachability).
      export WPTSALL_USE_SERVER_CONTROL_PLANE=0
      export WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE_LOCAL:-${lab_server_base}}"
      export WPTSALL_SERVER_BASE_LOCAL="${WPTSALL_SERVER_BASE}"
      # Prevent nested dotenv / leftover production base from winning.
      unset WPTSALL_CLOUD_BASE WPTSALL_API_BASE 2>/dev/null || true
    else
      export WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE:-https://www.wpmm.cc}"
    fi
    export WPTSALL_DB_PATH="$(e2e_client_db_path)"
    export WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="$(e2e_client_domain_token_bindings_file)"
    info "Client launch: SERVER_BASE=${WPTSALL_SERVER_BASE} DB=${WPTSALL_DB_PATH} BIND=:${CLIENT_PORT}"

    # Parallel matrix: raise client worker concurrency to use multi-CPU host.
    rel_c="${WPTSALL_RELATION_CONCURRENCY:-2}"
    glob_t="${WPTSALL_GLOBAL_TRANSLATION_CONCURRENCY:-12}"
    glob_c="${WPTSALL_GLOBAL_CALLBACK_CONCURRENCY:-12}"
    task_c="${WPTSALL_TASK_CONCURRENCY:-4}"
    if [[ "${E2E_MATRIX_PARALLEL:-0}" == "1" ]]; then
      rel_c="${WPTSALL_RELATION_CONCURRENCY:-6}"
      glob_t="${WPTSALL_GLOBAL_TRANSLATION_CONCURRENCY:-24}"
      glob_c="${WPTSALL_GLOBAL_CALLBACK_CONCURRENCY:-24}"
      task_c="${WPTSALL_TASK_CONCURRENCY:-12}"
    fi
    # Stable per-slot device_id so parallel matrix lanes keep independent server sessions.
    if e2e_is_slot_mode; then
      export WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-wptsall-e2e-${E2E_SLOT}}"
    else
      # Non-slot runs still need a stable WP device identity: Protocol v2
      # validates device-scoped tokens against the device id that presents
      # them, and the CT lane issues fixture tokens for this same shared
      # identity (CT_LAB_WP_DEVICE_ID from config.sh).
      export WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-${CT_LAB_WP_DEVICE_ID}}"
    fi
    export WPTSALL_WP_DEVICE_ID="${WPTSALL_WP_DEVICE_ID:-${WPTSALL_DEVICE_ID}}"
    export WPTSALL_DEVICE_ID WPTSALL_WP_DEVICE_ID

    (
      cd "${client_runtime_dir}"
      WPTSALL_WEB_UI=true \
      WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}" \
      WPTSALL_WEB_UI_PORT="${CLIENT_PORT}" \
      WPTSALL_LOG_FILE="${client_log_file}" \
      WPTSALL_SESSION_TOKEN_FILE="${session_token_file}" \
      WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE}" \
      WPTSALL_DB_PATH="${WPTSALL_DB_PATH}" \
      WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE}" \
      WPTSALL_SKIP_SIGNATURE_CHECK=true \
      WPTSALL_RELATION_CONCURRENCY="${rel_c}" \
      WPTSALL_GLOBAL_TRANSLATION_CONCURRENCY="${glob_t}" \
      WPTSALL_GLOBAL_CALLBACK_CONCURRENCY="${glob_c}" \
      WPTSALL_TASK_CONCURRENCY="${task_c}" \
      "$CLIENT_BIN"
    ) &
    CLIENT_PID=$!
    echo "${CLIENT_PID}" > "$(e2e_client_pid_file)"

    echo -n "  Waiting for Client :${CLIENT_PORT}"
    # Parallel cold-start of 4 clients can exceed 30s; allow 90s for slots.
    client_wait_loops=60
    if e2e_is_slot_mode || [[ "${E2E_MATRIX_PARALLEL:-0}" == "1" ]]; then
      client_wait_loops=180
    fi
    for i in $(seq 1 "${client_wait_loops}"); do
      if check_url "${CLIENT_URL}/api/status"; then
        echo " ready!"
        break
      fi
      sleep 0.5
      echo -n "."
    done

    if check_url "${CLIENT_URL}/api/status"; then
      ok "Client started (PID=$CLIENT_PID port=${CLIENT_PORT})"
    else
      err "Client failed to start on :${CLIENT_PORT}"
      if [[ -f "${client_log_file}" ]]; then
        warn "Last 40 lines of ${client_log_file}:"
        tail -40 "${client_log_file}" || true
      fi
      if [[ "${E2E_MATRIX_PARALLEL:-0}" == "1" ]] || e2e_is_slot_mode; then
        abort "Slot client :${CLIENT_PORT} failed to start (refusing fallback to shared :8977)"
      fi
    fi
  fi
else
  info "SKIP_CLIENT set, skipping Client startup"
fi

# ── 3. 安装 Playwright 依赖 ──────────────────────────────────────────────────
PLAYWRIGHT_DIR="${E2E_DIR}/playwright"

if [ ! -d "${PLAYWRIGHT_DIR}/node_modules" ]; then
  info "Installing Playwright dependencies..."
  if command -v pnpm &>/dev/null; then
    (cd "$PLAYWRIGHT_DIR" && pnpm install --registry https://registry.npmjs.org 2>&1 | tail -3)
  else
    (cd "$PLAYWRIGHT_DIR" && npm install --registry https://registry.npmjs.org 2>&1 | tail -3)
  fi
  ok "Dependencies installed"
fi

# Check chromium
if ! (cd "$PLAYWRIGHT_DIR" && npx playwright install --dry-run chromium 2>/dev/null); then
  info "Installing Playwright Chromium..."
  (cd "$PLAYWRIGHT_DIR" && npx playwright install chromium 2>&1 | tail -3)
  ok "Chromium installed"
fi

# ── 4.5 Playwright 登录账号兜底 ─────────────────────────────────────────────
echo ""
info "Ensuring WP Playwright admin account..."
if ensure_wp_admin_account; then
  ok "WP Playwright admin account ready"
else
  abort "Failed to ensure WP Playwright admin account"
fi

echo ""
info "Checking Web app frontend..."
ensure_web_app_frontend
export WPTSALL_WEB_BASE="${WEB_APP_URL}"

# ── 4. Stage 6 兜底准备（仅在缺少审计文件时执行）────────────────────────────
if [ ! -f "${RUNTIME_DIR}/url-audit.json" ]; then
  echo ""
  warn "url-audit.json missing. Running Stage 6 fallback prep..."
  if wp_eval "${E2E_DIR}/php/audit-rule-urls.php"; then
    ok "Fallback rule URL audit generated"
  else
    abort "Fallback rule URL audit failed"
  fi
  if wp_eval "${E2E_DIR}/php/normalize-rule-login-flags.php"; then
    ok "Fallback rule login flags normalized"
  else
    abort "Fallback rule login flag normalization failed"
  fi
  if wp_eval "${E2E_DIR}/php/audit-rule-urls.php"; then
    ok "Fallback rule URL audit refreshed"
  else
    abort "Fallback rule URL audit refresh failed"
  fi
fi

# ── 5. 运行 Playwright（先插件侧，再客户端侧）───────────────────────────────
echo ""
info "Running Playwright plugin-side suite (WebUI clicks)..."
echo ""

export WP_BASE="${WP_URL}"
export E2E_RUNTIME_DIR="${RUNTIME_DIR}"
export E2E_PROJECT="${E2E_PROJECT:-core-content}"

if [ -z "${WPTSALL_PG_DSN:-}" ] && [ -f "${REPO_ROOT}/web/server/.env" ]; then
  WPTSALL_PG_DSN="$(
    awk -F= '
      $1 == "WPTSALL_DATABASE_URL" { print $2; found=1; exit }
      $1 == "DATABASE_URL" && ! found { fallback=$2 }
      END { if (!found && fallback != "") print fallback }
    ' "${REPO_ROOT}/web/server/.env"
  )"
fi
export WPTSALL_PG_DSN

if [ -z "${WP_CLIENT_TOKEN:-}" ]; then
  # Device-scoped token only (PRE-RELEASE-SINGLE-TRUTH).
  DEVICE_JSON="$(wp_cli eval 'if(function_exists("wptsall_issue_client_device_token")){ $d=wptsall_issue_client_device_token("e2e-".(getenv("E2E_SLOT")?: "slot-a"),"e2e"); echo wp_json_encode($d); }' 2>/dev/null || true)"
  if [ -n "${DEVICE_JSON}" ] && command -v python3 >/dev/null 2>&1; then
    WP_CLIENT_TOKEN="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("token",""))' "${DEVICE_JSON}" 2>/dev/null || true)"
    WPTSALL_DEVICE_ID="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("device_id",""))' "${DEVICE_JSON}" 2>/dev/null || true)"
  fi
fi
export WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-}"
export WPTSALL_WP_DEVICE_ID="${WPTSALL_DEVICE_ID}"
if [ -z "${ROUTE_SECRET:-}" ]; then
  ROUTE_SECRET="$(wp_cli eval 'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";' 2>/dev/null || true)"
fi
export WP_CLIENT_TOKEN ROUTE_SECRET WPTSALL_WP_CLIENT_TOKEN="${WP_CLIENT_TOKEN}"
if [ -n "${WPTSALL_DEVICE_ID}" ]; then
  ok "Using device-scoped client token device_id=${WPTSALL_DEVICE_ID}"
fi
if [ -n "${WP_CLIENT_TOKEN}" ] && [ -n "${ROUTE_SECRET}" ] && [ -n "${WPTSALL_DEVICE_ID}" ]; then
  ok "Resolved live WP client auth material for Playwright"
else
  warn "Live WP client auth material missing (need device token + route secret); client-side suite may fail"
fi

# Protocol v2: the running Client must use the same device_id as the WP token Playwright upserts.
if [ -z "${SKIP_CLIENT:-}" ] && [ -n "${WPTSALL_DEVICE_ID:-}" ] && check_url "${CLIENT_URL}/api/status"; then
  running_device="$(curl -sf "${CLIENT_URL}/api/status" 2>/dev/null | python3 -c "import sys,json; d=json.load(sys.stdin); print((d.get('data') or {}).get('device_id',''))" 2>/dev/null || true)"
  need_restart=0
  if [ -n "${running_device}" ] && [ "${running_device}" != "${WPTSALL_DEVICE_ID}" ]; then
    warn "Client device_id=${running_device} != E2E ${WPTSALL_DEVICE_ID}; restarting for WP Protocol v2"
    need_restart=1
  elif [ -z "${running_device}" ]; then
    warn "Client device_id missing; restarting with E2E ${WPTSALL_DEVICE_ID} for WP Protocol v2"
    need_restart=1
  fi
  if [ "${need_restart}" -eq 1 ]; then
    _stop_client_web_ui
    client_runtime_dir="$(e2e_client_runtime_dir)"
    client_log_file="$(e2e_client_log_file)"
    session_token_file="$(e2e_client_session_token_file)"
    lab_server_base="${SERVER_URL:-http://127.0.0.1:8787}"
    if _is_lab_mode; then
      # Local-first: never use the website control plane (see main path above).
      export WPTSALL_USE_SERVER_CONTROL_PLANE=0
      export WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE_LOCAL:-${lab_server_base}}"
      export WPTSALL_SERVER_BASE_LOCAL="${WPTSALL_SERVER_BASE}"
    fi
    export WPTSALL_DB_PATH="$(e2e_client_db_path)"
    export WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="$(e2e_client_domain_token_bindings_file)"
    export WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}"
    export WPTSALL_WEB_UI_PORT="${CLIENT_PORT}"
  (
    cd "${client_runtime_dir}"
    WPTSALL_WEB_UI=true \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}" \
    WPTSALL_WEB_UI_PORT="${CLIENT_PORT}" \
    WPTSALL_LOG_FILE="${client_log_file}" \
    WPTSALL_SESSION_TOKEN_FILE="${session_token_file}" \
    WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE}" \
    WPTSALL_DB_PATH="${WPTSALL_DB_PATH}" \
    WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE}" \
    WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID}" \
    WPTSALL_WP_DEVICE_ID="${WPTSALL_WP_DEVICE_ID}" \
    WPTSALL_SKIP_SIGNATURE_CHECK=true \
    "$CLIENT_BIN"
  ) &
    CLIENT_PID=$!
    echo "${CLIENT_PID}" > "$(e2e_client_pid_file)"
    for _i in $(seq 1 60); do
      if check_url "${CLIENT_URL}/api/status"; then
        ok "Client restarted with device_id=${WPTSALL_DEVICE_ID} (PID=${CLIENT_PID})"
        break
      fi
      sleep 0.5
    done
  fi
fi

if [ -z "${E2E_API_BASE_URL:-}" ] && [ -f "${RUNTIME_DIR}/core-component-template-sources.json" ]; then
  E2E_API_BASE_URL="$(python3 - <<'PY' "${RUNTIME_DIR}/core-component-template-sources.json"
import json,sys
print((json.load(open(sys.argv[1])).get("api_base_url") or "").strip())
PY
)"
fi
if [ -z "${E2E_API_BASE_URL:-}" ] && [ -n "${ROUTE_SECRET:-}" ]; then
  E2E_API_BASE_URL="${WP_URL%/}/wp-json/wptsall/v2/${ROUTE_SECRET}/client"
fi
export E2E_API_BASE_URL
if [ -n "${E2E_API_BASE_URL}" ]; then
  ok "E2E_API_BASE_URL=${E2E_API_BASE_URL}"
fi

# Lab plugin lanes: keep worker runs bounded so run-once completes under Playwright HTTP timeout.
if _is_lab_mode; then
  export E2E_WORKER_GATE_MAX_ITEMS="${E2E_WORKER_GATE_MAX_ITEMS:-48}"
  export E2E_WORKER_GATE_MAX_ITERATIONS="${E2E_WORKER_GATE_MAX_ITERATIONS:-24}"
  export E2E_WORKER_RUN_ONCE_HTTP_TIMEOUT_MS="${E2E_WORKER_RUN_ONCE_HTTP_TIMEOUT_MS:-360000}"
  export DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
fi
# Matrix slots: bootstrapWorkerSetup already runs worker before execution gate re-runs it.
if [ -n "${E2E_SLOT:-}" ]; then
  export E2E_ALLOW_IDEMPOTENT_REPLAY="${E2E_ALLOW_IDEMPOTENT_REPLAY:-1}"
fi

PW_ARGS=()
if [ "$HEADED" = "--headed" ]; then
  PW_ARGS+=(--headed)
fi

# Force Playwright to the slot client (ambient CLIENT_BASE must not win).
export CLIENT_BASE="${CLIENT_URL}"
export CLIENT_URL
export E2E_SLOT

# Re-assert slot CLIENT_BASE and restart slot client if it died mid-stage
# (overlapping matrix Stage2 can SIGTERM the prior lane's client → ECONNREFUSED).
ensure_slot_client_for_playwright() {
  export CLIENT_BASE="${CLIENT_URL}"
  export CLIENT_URL
  if e2e_is_slot_mode; then
    case "${CLIENT_BASE}" in
      *:8977|*:8977/)
        abort "Slot ${E2E_SLOT} CLIENT_BASE=${CLIENT_BASE} refuses shared :8977"
        ;;
    esac
  fi
  if [ -n "${SKIP_CLIENT:-}" ]; then
    return 0
  fi
  if check_url "${CLIENT_URL}/api/status"; then
    return 0
  fi
  if ! e2e_is_slot_mode && [[ "${E2E_MATRIX_PARALLEL:-0}" != "1" ]]; then
    warn "Client :${CLIENT_PORT} unreachable before Playwright"
    return 0
  fi
  warn "Slot client :${CLIENT_PORT} down before Playwright; restarting (refusing :8977 fallback)"
  client_runtime_dir="$(e2e_client_runtime_dir)"
  client_log_file="$(e2e_client_log_file)"
  session_token_file="$(e2e_client_session_token_file)"
  mkdir -p "${client_runtime_dir}" "$(dirname "${client_log_file}")" "$(dirname "$(e2e_client_pid_file)")" "$(dirname "${session_token_file}")"
  lab_server_base="${SERVER_URL:-http://127.0.0.1:8787}"
  if _is_lab_mode; then
    # Local-first: never use the website control plane (see main path above).
    export WPTSALL_USE_SERVER_CONTROL_PLANE=0
    export WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE_LOCAL:-${lab_server_base}}"
    export WPTSALL_SERVER_BASE_LOCAL="${WPTSALL_SERVER_BASE}"
    unset WPTSALL_CLOUD_BASE WPTSALL_API_BASE 2>/dev/null || true
  fi
  export WPTSALL_DB_PATH="$(e2e_client_db_path)"
  export WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}"
  export WPTSALL_WEB_UI_PORT="${CLIENT_PORT}"
  rel_c="${WPTSALL_RELATION_CONCURRENCY:-2}"
  glob_t="${WPTSALL_GLOBAL_TRANSLATION_CONCURRENCY:-8}"
  glob_c="${WPTSALL_GLOBAL_CALLBACK_CONCURRENCY:-8}"
  task_c="${WPTSALL_TASK_CONCURRENCY:-4}"
  (
    cd "${client_runtime_dir}"
    WPTSALL_WEB_UI=true \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_PORT}" \
    WPTSALL_WEB_UI_PORT="${CLIENT_PORT}" \
    WPTSALL_LOG_FILE="${client_log_file}" \
    WPTSALL_SESSION_TOKEN_FILE="${session_token_file}" \
    WPTSALL_SERVER_BASE="${WPTSALL_SERVER_BASE}" \
    WPTSALL_DB_PATH="${WPTSALL_DB_PATH}" \
    WPTSALL_SKIP_SIGNATURE_CHECK=true \
    WPTSALL_RELATION_CONCURRENCY="${rel_c}" \
    WPTSALL_GLOBAL_TRANSLATION_CONCURRENCY="${glob_t}" \
    WPTSALL_GLOBAL_CALLBACK_CONCURRENCY="${glob_c}" \
    WPTSALL_TASK_CONCURRENCY="${task_c}" \
    "$CLIENT_BIN"
  ) &
  CLIENT_PID=$!
  echo "${CLIENT_PID}" > "$(e2e_client_pid_file)"
  for _i in $(seq 1 60); do
    if check_url "${CLIENT_URL}/api/status"; then
      ok "Slot client restarted (PID=${CLIENT_PID} port=${CLIENT_PORT})"
      return 0
    fi
    sleep 0.5
  done
  abort "Slot client :${CLIENT_PORT} failed restart before Playwright (refusing fallback to shared :8977)"
}

run_playwright_group() {
  local group_name="$1"
  shift

  ensure_slot_client_for_playwright

  set +e
  local _pw_config="playwright.config.ts"
  [ -f "${PLAYWRIGHT_DIR}/playwright.config.cjs" ] && _pw_config="playwright.config.cjs"
  (cd "$PLAYWRIGHT_DIR" && \
    CLIENT_BASE="${CLIENT_BASE}" CLIENT_URL="${CLIENT_URL}" E2E_SLOT="${E2E_SLOT}" \
    WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-wptsall-e2e-${E2E_SLOT:-shared}}" \
    WP_ADMIN_USER="${WP_ADMIN_USER:-e2esmokeadmin}" \
    WP_ADMIN_PASS="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}" \
    WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-}" \
    npx playwright test -c "$_pw_config" "${PW_ARGS[@]}" "$@")
  local group_exit=$?
  set -e

  if [ "$group_exit" -eq 0 ]; then
    ok "${group_name} passed"
    return 0
  fi

  if [ "${E2E_CONTINUE_ON_PW_FAIL:-0}" = "1" ]; then
    warn "${group_name} failed (exit=${group_exit}), continuing by E2E_CONTINUE_ON_PW_FAIL=1"
    return 0
  fi

  abort "${group_name} failed (exit=${group_exit}). Set E2E_CONTINUE_ON_PW_FAIL=1 only for debugging."
}

# 插件侧：任务模拟、后台/前台可用性、URL 门禁校验
# Per-slot WP_ADMIN_* (config.sh) — no global flock; lanes run plugin Playwright in parallel.
run_playwright_group \
  "Playwright plugin-side suite" \
  --workers=1 \
  official-gate/plugin-task-simulation.gate.e2e.spec.ts

echo ""
# P0 local-first boundary (PLAN-2026-08-31 / AGENTS.md §0.1): the client is a
# standalone product and never logs into the website. The former client-side
# suite drove the legacy website OAuth flow (www.wpmm.cc, lab-mirrored by a
# local :8787 wptsall-server); it does not test any current-product behavior
# and is removed from the gate. Client-side translation coverage (device-
# scoped token, direct Protocol v2, zero control-plane traffic) lives in the
# P0-EV-03 automatic lanes; the plugin-side suite above keeps WebUI-level
# task coverage.
info "Client-side website-OAuth suite removed (local-first: no website login)."
write_client_suite_status 0 1 removed_local_first_no_website_login ""

stage_elapsed "$START"
