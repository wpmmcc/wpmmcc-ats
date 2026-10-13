#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# E2E v2 共享配置
#
# 所有 stage 脚本 source 此文件获取路径、颜色和 helper 函数。
# ─────────────────────────────────────────────────────────────────────────────

# ── 路径 ──────────────────────────────────────────────────────────────────────
E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "${E2E_DIR}/lib/lab-paths.sh"

detect_wp_root() {
  local candidates=(
    "${WPTSALL_WP_ROOT:-}"
    "${WP_SITE_PATH:-}"
    "${WP_ROOT:-}"
    "/var/www/wordpress"
    "/usr/local/var/www"
  )

  local candidate normalized
  for candidate in "${candidates[@]}"; do
    if [[ -z "${candidate}" ]]; then
      continue
    fi
    normalized="$(echo "${candidate}" | sed 's#/$##')"
    if [[ -f "${normalized}/wp-load.php" ]]; then
      echo "${normalized}"
      return 0
    fi
  done

  echo "/var/www/wordpress"
  return 0
}

WP_ROOT="$(detect_wp_root)"
WP_CLI="${WPTSALL_WP_CLI:-$(command -v wp 2>/dev/null || echo /usr/local/bin/wp)}"

resolve_first_existing() {
  local candidate
  for candidate in "$@"; do
    if [[ -n "$candidate" && -f "$candidate" ]]; then
      echo "$candidate"
      return 0
    fi
  done
  # Prefer the first (canonical) path even if missing — stage checks report it.
  echo "${1:-}"
  return 0
}

CLIENT_BIN="$(resolve_first_existing \
  "${WPTSALL_CLIENT_BIN:-}" \
  "${REPO_ROOT}/client-wpplugin/source/target/release-lab/wptsall-client" \
  "${REPO_ROOT}/client-wpplugin/source/target/release/wptsall-client" \
  "${REPO_ROOT}/client-wpplugin/source/target/x86_64-unknown-linux-gnu/release/wptsall-client" \
  "${REPO_ROOT}/runtime/bin/client-wpplugin" \
  "${REPO_ROOT}/runtime/bin/wptsall-client")"
MOCK_API_BIN="$(resolve_first_existing \
  "${WPTSALL_MOCK_API_BIN:-}" \
  "${REPO_ROOT}/tests/infra/mock-api/target/release/mock-translate-api" \
  "${REPO_ROOT}/runtime/bin/mock-translate-api")"
CLIENT_ENV="${REPO_ROOT}/client-wpplugin/source/deploy/wptsall-client-webui.env"
CLIENT_ENV_FALLBACK="${REPO_ROOT}/client-wpplugin/source/.env"

# Fixed lab device identity for WP Protocol v2 device-scoped tokens. Every
# client launch (stage 06 client-translate, CT lane fallback/Lab branches)
# must present the same X-WPTSALL-Device-Id, and fixture device tokens
# (seed-core-component-template-sources.php via WPTSALL_DEVICE_ID_FOR_TOKEN)
# must be issued for it; a mismatch fails WP auth with 401 client_unauthorized.
CT_LAB_WP_DEVICE_ID="${CT_LAB_WP_DEVICE_ID:-e2e-lab-webui}"

REPORTS_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"
RUNTIME_DIR="${E2E_DIR}/runtime"
E2E_PROJECT_SPECS_FILE="${E2E_DIR}/project-specs.json"

# ── 服务端点 ──────────────────────────────────────────────────────────────────
# Remote production/test-host defaults. Set WPTSALL_LAB=1 (or source tests/docker-lab/lab.env)
# to target local Docker WP :9083 + local control plane.
_is_lab_mode() {
  case "${WPTSALL_LAB:-}" in
    1|true|yes|on) return 0 ;;
  esac
  if [[ -n "${WP_BASE:-}" && "${WP_BASE}" != "https://blog.wpmm.cc" ]]; then
    return 0
  fi
  return 1
}

LAB_WP_HOST="${LAB_WP_HOST:-127.0.0.1}"
LAB_WP_PORT="${LAB_WP_PORT:-9083}"
LAB_WP_BASE="${WP_BASE:-http://${LAB_WP_HOST}:${LAB_WP_PORT}}"

if _is_lab_mode; then
  WP_URL="${WP_URL:-${LAB_WP_BASE}}"
  SERVER_URL="${SERVER_URL:-http://127.0.0.1:8787}"
  WEB_APP_URL="${WEB_APP_URL:-${WPTSALL_WEB_BASE:-http://127.0.0.1:5173}}"
  export WEB_APP_URL
  FRONTEND_VERIFY_PATH="${E2E_FRONTEND_VERIFY_PATH:-/}"
  export WPTSALL_SERVER_BASE_LOCAL="${WPTSALL_SERVER_BASE_LOCAL:-${SERVER_URL}}"
else
  WP_URL="${WP_URL:-https://blog.wpmm.cc}"
  SERVER_URL="${SERVER_URL:-https://www.wpmm.cc}"
fi

CLIENT_URL=""
MOCK_API_URL="${MOCK_API_URL:-http://127.0.0.1:9090}"
E2E_PROJECT="${E2E_PROJECT:-core-content}"
E2E_SCOPE="${E2E_SCOPE:-full}"
E2E_SLOT="${E2E_SLOT:-shared}"
if [[ -z "${FRONTEND_VERIFY_PATH:-}" ]]; then
  FRONTEND_VERIFY_PATH="${E2E_FRONTEND_VERIFY_PATH:-/en/}"
fi

if _is_lab_mode; then
  # Production provider egress intentionally blocks loopback/private hosts.
  # Lab E2E lanes use a local mock provider on :9090, so they must opt in
  # explicitly instead of weakening the runtime default.
  export WPTSALL_PROVIDER_ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}"
fi

# ── 插件列表（20 个内容插件 + wptsall） ─────────────────────────────────────
PLUGINS=(
  woocommerce
  easy-digital-downloads
  bbpress
  tutor
  learnpress
  lifterlms
  the-events-calendar
  events-manager
  wp-job-manager
  envira-gallery-lite
  seriously-simple-podcasting
  wp-recipe-maker
  elementor
  wordpress-seo
  advanced-custom-fields
  site-reviews
  give
  directorist
  hivepress
  wptsall
)

# ── Seeding Plans ─────────────────────────────────────────────────────────────
# Plan E seeds WordPress core posts/pages and performs a destructive cleanup of
# core posts/pages/attachments/comments. Run it before plugin plans so it does
# not delete media uploaded by A-D.
SEED_PLANS=(E A B C D)
CORE_CONTENT_TYPES=(post page category post_tag attachment)

# ── 颜色 ──────────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'  # No Color

# ── Helper 函数 ──────────────────────────────────────────────────────────────

e2e_is_slot_mode() {
  [ "$E2E_SLOT" != "shared" ]
}

e2e_assert_supported_slot() {
  case "$E2E_SLOT" in
    shared|slot-a|slot-b|slot-c|slot-d|slot-e|slot-f|slot-g|slot-h|slot-i|slot-j|slot-k|slot-l|slot-m|slot-n)
      return
      ;;
    # slot-u: pinned unit/integration environment (not a matrix slot);
    # slot-v: journey-lane dedicated slot (批 O6 复栈) — real-domain base.
    slot-u|slot-v)
      return
      ;;
    *)
      err "Unsupported E2E_SLOT: ${E2E_SLOT}"
      echo "  Supported today: shared, slot-a .. slot-n (14 matrix clients), slot-u (unit/integration), slot-v (journey lane)"
      exit 1
      ;;
  esac
}

e2e_client_port() {
  case "$E2E_SLOT" in
    shared) echo "8977" ;;
    slot-a) echo "9077" ;;
    slot-b) echo "9078" ;;
    slot-c) echo "9079" ;;
    slot-d) echo "9084" ;;
    slot-e) echo "9085" ;;
    slot-f) echo "9086" ;;
    slot-g) echo "9087" ;;
    slot-h) echo "9088" ;;
    slot-i) echo "9089" ;;
    slot-j) echo "9091" ;;
    slot-k) echo "9092" ;;
    slot-l) echo "9093" ;;
    slot-m) echo "9094" ;;
    slot-n) echo "9095" ;;
    # slot-u (unit/integration, 9097) and slot-v (journey lane, 9096) are NOT
    # matrix slots but still need non-shared client ports — the guard below
    # refuses any slot resolving to shared :8977.
    slot-u) echo "9097" ;;
    slot-v) echo "9096" ;;
    *) echo "8977" ;;
  esac
}

e2e_matrix_slots() {
  # Up to 14 parallel Lab clients (9077-9079, 9084-9089, 9091-9095); skip 9080-9083/9090 (lab/WP/mock).
  echo "slot-a slot-b slot-c slot-d slot-e slot-f slot-g slot-h slot-i slot-j slot-k slot-l slot-m slot-n"
}

# Host ports for the isolated WordPress containers used by the parallel matrix.
# Keep this range separate from client WebUI (:9077...) and the shared Lab
# WordPress instances (:9081...:9083).
e2e_slot_wp_port() {
  case "$E2E_SLOT" in
    slot-a) echo "9181" ;;
    slot-b) echo "9182" ;;
    slot-c) echo "9183" ;;
    slot-d) echo "9184" ;;
    slot-e) echo "9185" ;;
    slot-f) echo "9186" ;;
    slot-g) echo "9187" ;;
    slot-h) echo "9188" ;;
    slot-i) echo "9189" ;;
    slot-j) echo "9190" ;;
    slot-k) echo "9191" ;;
    slot-l) echo "9192" ;;
    slot-m) echo "9193" ;;
    slot-n) echo "9194" ;;
    # slot-u: pinned unit/integration WP (9180); slot-v: journey-lane slot
    # (批 O6 复栈) with a real-domain base (blog.localhost:9198).
    slot-u) echo "9180" ;;
    slot-v) echo "9198" ;;
    *) echo "9083" ;;
  esac
}

e2e_slot_wp_container() {
  local slot_safe
  slot_safe="$(echo "${E2E_SLOT}" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-')"
  echo "wptsall-wp-lab-wordpress-${slot_safe}"
}

e2e_slot_wp_base() {
  local port
  port="$(e2e_slot_wp_port)"
  echo "http://${LAB_WP_HOST:-127.0.0.1}:${port}"
}

e2e_uses_isolated_slot_wordpress() {
  [[ "${E2E_SLOT_WP_ISOLATED:-0}" == "1" ]] && e2e_is_slot_mode && _is_lab_mode
}

# Every slot has a separate admin even when it is using an isolated WordPress
# container. This keeps browser state/device identity unambiguous in reports.
e2e_apply_slot_wp_admin_env() {
  if ! e2e_is_slot_mode; then
    return 0
  fi
  local slot_suffix="${E2E_SLOT#slot-}"
  export WP_ADMIN_USER="${WP_ADMIN_USER:-e2eadmin_${slot_suffix}}"
  export WP_ADMIN_PASS="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
  export WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-e2eadmin_${slot_suffix}@wpmm.test}"
}

# Per-lane virtual relation target for matrix parallel (Stage 5).
e2e_relation_setup_args() {
  if [[ "${E2E_MATRIX_PARALLEL:-0}" == "1" ]] && e2e_is_slot_mode; then
    local ns="${E2E_SLOT}_$(echo "${E2E_PROJECT}" | sed 's/-content$//')"
    ns=$(echo "$ns" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_' '_')
    echo "fixed-virtual=1 fixed-virtual-target=v_e2e_${ns}"
  else
    echo "${E2E_RELATION_SETUP_ARG:-fixed-virtual=1}"
  fi
}

# Ensure Playwright admin exists (Stage 6 + Stage 8-only redos on matrix slots).
e2e_ensure_wp_admin_account() {
  local admin_user="${WP_ADMIN_USER:-e2esmokeadmin}"
  local admin_pass="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
  local admin_email="${WP_ADMIN_EMAIL:-e2esmokeadmin@wpmm.test}"

  if wp_cli user get "$admin_user" --field=ID >/dev/null 2>&1; then
    wp_cli user update "$admin_user" \
      --role=administrator \
      --user_pass="$admin_pass" >/dev/null
  else
    wp_cli user create "$admin_user" "$admin_email" --role=administrator --user_pass="$admin_pass" >/dev/null
  fi
}

e2e_runtime_root_dir() {
  echo "${E2E_DIR}/runtime"
}

e2e_reports_root_dir() {
  echo "${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"
}

e2e_global_init_marker() {
  echo "$(e2e_runtime_root_dir)/global-init.json"
}

e2e_slot_init_marker() {
  echo "${RUNTIME_DIR}/global-init.json"
}

e2e_runtime_dir() {
  local root
  root="$(e2e_runtime_root_dir)"
  if e2e_is_slot_mode; then
    echo "${root}/${E2E_SLOT}"
  else
    echo "${root}"
  fi
}

e2e_reports_dir() {
  local root
  root="$(e2e_reports_root_dir)"
  if e2e_is_slot_mode; then
    echo "${root}/${E2E_SLOT}"
  else
    echo "${root}"
  fi
}

e2e_playwright_json_report() {
  echo "$(e2e_reports_dir)/playwright-results.json"
}

e2e_client_runtime_dir() {
  # Lab + slot: keep cwd = crate root (locales/frontend), isolate only DB/log/pid.
  if e2e_is_slot_mode && _is_lab_mode; then
    echo "${REPO_ROOT}/client-wpplugin/source"
    return
  fi
  if e2e_is_slot_mode; then
    echo "$(e2e_runtime_dir)/client-workdir"
  elif _is_lab_mode; then
    # Lab client needs locales/ + frontend/dist (cwd = crate root).
    echo "${REPO_ROOT}/client-wpplugin/source"
  else
    echo "${REPO_ROOT}/client"
  fi
}

e2e_client_log_file() {
  if e2e_is_slot_mode; then
    echo "$(e2e_runtime_dir)/wptsall-client.log"
  else
    echo "${REPO_ROOT}/client-wpplugin/source/logs/wptsall-client.log"
  fi
}

e2e_client_db_path() {
  # Slot isolation wins over ambient WPTSALL_DB_PATH (Lab shared client exports it).
  if e2e_is_slot_mode; then
    local slot_db_dir
    slot_db_dir="$(e2e_runtime_dir)/client-db"
    mkdir -p "${slot_db_dir}"
    echo "${slot_db_dir}/wptsall.db"
    return
  fi
  # Prefer explicit path (Lab client launches with WPTSALL_DB_PATH).
  if [ -n "${WPTSALL_DB_PATH:-}" ]; then
    echo "${WPTSALL_DB_PATH}"
    return
  fi
  if _is_lab_mode; then
    local lab_db="${HOME}/projects/runtime/clients/wpplugin/runtime/wptsall.db"
    if [ -f "$lab_db" ]; then
      echo "$lab_db"
      return
    fi
  fi
  echo "${REPO_ROOT}/client-wpplugin/source/runtime/wptsall.db"
}

e2e_client_session_token_file() {
  if e2e_is_slot_mode; then
    echo "$(e2e_runtime_dir)/session-token.enc"
  else
    echo "${REPO_ROOT}/client-wpplugin/source/runtime/session-token.enc"
  fi
}

e2e_client_domain_token_bindings_file() {
  if e2e_is_slot_mode; then
    echo "$(e2e_runtime_dir)/domain-token-bindings.json"
  else
    echo "${REPO_ROOT}/client-wpplugin/source/config/domain-token-bindings.json"
  fi
}

e2e_client_pid_file() {
  echo "$(e2e_runtime_dir)/client.pid"
}

# 打印带颜色的阶段标题
print_stage() {
  local stage_num="$1"
  local stage_name="$2"
  echo ""
  echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
  echo -e "${BOLD}${CYAN}  Stage ${stage_num}: ${stage_name}${NC}"
  echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
  echo ""
}

# 打印成功消息
ok() {
  echo -e "  ${GREEN}✓${NC} $1"
}

# 打印警告消息
warn() {
  echo -e "  ${YELLOW}⚠${NC} $1"
}

# 打印错误消息
err() {
  echo -e "  ${RED}✗${NC} $1"
}

# 打印信息消息
info() {
  echo -e "  ${BLUE}→${NC} $1"
}

# 中止执行
abort() {
  err "$1"
  echo -e "\n${RED}${BOLD}ABORT: Stage failed, cannot continue.${NC}"
  e2e_emit_event "ABORT" "msg=$(printf '%s' "$1" | tr '\n' ' ' | cut -c1-200)"
  exit 1
}

# CLI parent event bus (parallel matrix discards lane stdout).
e2e_emit_event() {
  local kind="${1:-EVENT}"
  local detail="${2:-}"
  local dest="${E2E_MATRIX_EVENTS_FILE:-}"
  [[ -z "${dest}" ]] && return 0
  mkdir -p "$(dirname "${dest}")" 2>/dev/null || true
  printf '%s %s project=%s slot=%s %s\n' \
    "$(date -Iseconds)" "${kind}" "${E2E_PROJECT:-?}" "${E2E_SLOT:-shared}" "${detail}" \
    >>"${dest}" 2>/dev/null || true
}

# 运行 WP-CLI 命令（自动 cd 到 WP_ROOT；Lab 模式走 shared service 或 isolated slot container）
wp_lab_docker_exec() {
  local lab_dir="${REPO_ROOT}/tests/docker-lab"
  local service="${LAB_WP_SERVICE:-wordpress-test}"
  local php_mem="${LAB_PHP_MEMORY_LIMIT:-4096M}"
  local -a docker_env=(
    -e "PHP_MEMORY_LIMIT=${php_mem}"
    -e "WP_CLI_PHP=php -d memory_limit=${php_mem}"
    -e WPTSALL_LAB=1
  )
  # Forward E2E project/scope into WP-CLI so PHP helpers scope checks correctly.
  local key
  for key in E2E_PROJECT E2E_SCOPE E2E_SLOT E2E_ALLOW_PARTIAL_SEED E2E_AUTO_ACTIVATE_PLUGINS \
    E2E_MATRIX_PARALLEL E2E_MATRIX_JOBS E2E_SKIP_WP_RESET WPTSALL_E2E_SEED_PLUGIN_ALLOWLIST \
    E2E_MANUAL_PROJECTS \
    WPTSALL_DEVICE_ID_FOR_TOKEN \
    HOTPLUG_POST_TYPE HOTPLUG_POST_ID HOTPLUG_FORM_ID HOTPLUG_META_PLAIN HOTPLUG_META_HTML \
    GIVE_HOTPLUG_FORM_ID E2E_SINGLE_RELATION_ID; do
    if [[ -n "${!key:-}" ]]; then
      docker_env+=(-e "${key}=${!key}")
    fi
  done

  # Isolated matrix slots are started dynamically (rather than as a fixed
  # compose service).  docker compose exec cannot address those containers.
  if [[ -n "${LAB_WP_CONTAINER:-}" ]]; then
    if [[ -x "${lab_dir}/scripts/ensure-wp-cli.sh" ]]; then
      bash "${lab_dir}/scripts/ensure-wp-cli.sh" --container "${LAB_WP_CONTAINER}" >/dev/null
    fi
    docker exec "${docker_env[@]}" "${LAB_WP_CONTAINER}" \
      wp --allow-root --path=/var/www/html "$@"
    return
  fi

  if [[ -x "${lab_dir}/scripts/ensure-wp-cli.sh" ]]; then
    bash "${lab_dir}/scripts/ensure-wp-cli.sh" "${service}" >/dev/null
  fi
  (cd "${lab_dir}" && docker compose exec -T \
    "${docker_env[@]}" \
    "${service}" wp --allow-root --path=/var/www/html "$@")
}

# Map host e2e/seeding paths to bind mounts inside wordpress-test.
wp_lab_map_path() {
  local path="$1"
  case "$path" in
    "${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e"/*)
      echo "/opt/wptsall-e2e/${path#"${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/"}"
      ;;
    "${REPO_ROOT}/tests/modules/wpmmcc-ats/seeding"/*)
      echo "/opt/wptsall-seeding/${path#"${REPO_ROOT}/tests/modules/wpmmcc-ats/seeding/"}"
      ;;
    *)
      echo "$path"
      ;;
  esac
}

wp_eval() {
  if _is_lab_mode; then
    local mapped=()
    local arg
    for arg in "$@"; do
      mapped+=("$(wp_lab_map_path "$arg")")
    done
    wp_lab_docker_exec eval-file "${mapped[@]}"
    return
  fi
  cd "$WP_ROOT" && "$WP_CLI" eval-file "$@"
}

wp_cli() {
  if _is_lab_mode; then
    wp_lab_docker_exec "$@"
    return
  fi
  cd "$WP_ROOT" && "$WP_CLI" "$@"
}

run_with_timeout() {
  local timeout_secs="${1:-0}"
  shift || true

  if [[ ! "$timeout_secs" =~ ^[0-9]+$ ]] || [ "$timeout_secs" -le 0 ]; then
    "$@"
    return
  fi

  # GNU timeout/exec cannot run shell functions (e.g. wp_lab_docker_exec).
  # Use an in-shell watchdog so Lab docker helpers still get a deadline.
  if declare -F "$1" >/dev/null 2>&1; then
    "$@" &
    local cmd_pid=$!
    (
      sleep "$timeout_secs"
      kill -TERM "$cmd_pid" 2>/dev/null || true
      sleep 2
      kill -KILL "$cmd_pid" 2>/dev/null || true
    ) &
    local watcher=$!
    set +e
    wait "$cmd_pid"
    local status=$?
    set -e
    kill "$watcher" 2>/dev/null || true
    wait "$watcher" 2>/dev/null || true
    if [[ "$status" -eq 143 || "$status" -eq 137 ]]; then
      return 124
    fi
    return "$status"
  fi

  if command -v timeout >/dev/null 2>&1; then
    timeout "$timeout_secs" "$@"
    return
  fi

  if command -v gtimeout >/dev/null 2>&1; then
    gtimeout "$timeout_secs" "$@"
    return
  fi

  if command -v perl >/dev/null 2>&1; then
    perl -e 'alarm shift; exec @ARGV' "$timeout_secs" "$@"
    return
  fi

  "$@"
}

wp_eval_with_timeout() {
  local timeout_secs="${1:-0}"
  shift || true
  if _is_lab_mode; then
    local mapped=()
    local arg
    for arg in "$@"; do
      mapped+=("$(wp_lab_map_path "$arg")")
    done
    run_with_timeout "$timeout_secs" wp_lab_docker_exec eval-file "${mapped[@]}"
    return
  fi
  cd "$WP_ROOT" && run_with_timeout "$timeout_secs" "$WP_CLI" eval-file "$@"
}

e2e_curl() {
  curl --noproxy '*' "$@"
}

# 检查 URL 是否可达（静默，返回 0/1）
check_url() {
  e2e_curl -sf --max-time 10 "$1" >/dev/null 2>&1
}

http_status() {
  e2e_curl -sS --max-time "${2:-20}" -o /dev/null -w "%{http_code}" "$1"
}

# Retry transient frontend failures (5xx / curl 000) under parallel Lab load.
# Does not soft-pass persistent 4xx or known-fragile paths — callers still decide.
http_status_retry() {
  local url="$1"
  local timeout="${2:-20}"
  local attempts="${3:-4}"
  local status="" i=1
  for ((i = 1; i <= attempts; i++)); do
    status="$(http_status "${url}" "${timeout}" || true)"
    case "${status}" in
      200|201|204|301|302|303|307|308)
        printf '%s' "${status}"
        return 0
        ;;
      5??|000|"")
        if (( i < attempts )); then
          sleep $((i * 2))
          continue
        fi
        ;;
      *)
        printf '%s' "${status:-000}"
        return 0
        ;;
    esac
  done
  printf '%s' "${status:-000}"
}

# 检查 Server 是否可用（兼容 /health 在反向代理中被屏蔽或返回 5xx 的情况）
check_server_api() {
  local base_url="$1"
  local status=""

  # Primary probe
  if check_url "${base_url}/health"; then
    return 0
  fi

  # Fallback probe 1: public key endpoint should be 200 when API is alive
  status="$(http_status "${base_url}/api/v1/response-encryption-public-key" 10 || true)"
  if [ "$status" = "200" ]; then
    return 0
  fi

  # Fallback probe 2: components endpoint may be gated; 200/401/403 all indicate API is reachable
  status="$(http_status "${base_url}/api/v1/components?per_page=1" 10 || true)"
  case "$status" in
    200|401|403)
      return 0
      ;;
  esac

  return 1
}

http_download() {
  e2e_curl -sS --max-time "${3:-20}" -o "$2" -w "%{http_code}" "$1"
}

frontend_verify_url() {
  printf "%s%s" "$WP_URL" "$FRONTEND_VERIFY_PATH"
}

# 检查进程是否在运行（按端口）
check_port() {
  local port="$1"
  e2e_curl -sf --max-time 5 "http://127.0.0.1:${port}/" >/dev/null 2>&1 || \
  e2e_curl -sf --max-time 5 "http://127.0.0.1:${port}/api/status" >/dev/null 2>&1 || \
  e2e_curl -sf --max-time 5 "http://127.0.0.1:${port}/api/v1/health" >/dev/null 2>&1
}

# 记录阶段开始时间
stage_start_time() {
  date +%s
}

# 打印阶段耗时
stage_elapsed() {
  local start="$1"
  local end
  end=$(date +%s)
  local elapsed=$(( end - start ))
  echo -e "\n  ${CYAN}Stage completed in ${elapsed}s${NC}"
}

# 确保报告和运行时目录存在
ensure_dirs() {
  mkdir -p \
    "$(e2e_runtime_root_dir)" \
    "$(e2e_reports_root_dir)" \
    "$RUNTIME_DIR" \
    "$REPORTS_DIR"
}

e2e_plugin_project_exists() {
  [ -f "$E2E_PROJECT_SPECS_FILE" ] && \
    jq -e --arg project "$E2E_PROJECT" '.plugin_projects[$project] != null' "$E2E_PROJECT_SPECS_FILE" >/dev/null 2>&1
}

e2e_plugin_project_scalar_field() {
  local field="$1"
  if ! e2e_plugin_project_exists; then
    return
  fi
  jq -r --arg project "$E2E_PROJECT" --arg field "$field" '.plugin_projects[$project][$field] // empty' "$E2E_PROJECT_SPECS_FILE"
}

e2e_plugin_project_list_field() {
  local field="$1"
  if ! e2e_plugin_project_exists; then
    return
  fi
  jq -r --arg project "$E2E_PROJECT" --arg field "$field" '.plugin_projects[$project][$field][]?' "$E2E_PROJECT_SPECS_FILE"
}

e2e_plugin_project_list_csv() {
  local field="$1"
  local raw
  raw="$(e2e_plugin_project_list_field "$field" | paste -sd, -)"
  if [ -n "$raw" ]; then
    echo "$raw"
  else
    echo "(none)"
  fi
}

e2e_is_core_only() {
  [ "$E2E_SCOPE" = "core-only" ]
}

e2e_is_core_content_project() {
  [ "$E2E_PROJECT" = "core-content" ] || [ "$E2E_PROJECT" = "wptsall-content" ]
}

e2e_is_learning_content_project() {
  [ "$E2E_PROJECT" = "learning-content" ] || [ "$E2E_PROJECT" = "tutor-content" ] || [ "$E2E_PROJECT" = "learnpress-content" ]
}

e2e_is_commerce_content_project() {
  [ "$E2E_PROJECT" = "commerce-content" ] || [ "$E2E_PROJECT" = "woocommerce-content" ] || [ "$E2E_PROJECT" = "easy-digital-downloads-content" ]
}

e2e_is_media_builder_content_project() {
  [ "$E2E_PROJECT" = "media-builder-content" ] || [ "$E2E_PROJECT" = "envira-gallery-lite-content" ] || [ "$E2E_PROJECT" = "elementor-content" ]
}

e2e_is_community_content_project() {
  [ "$E2E_PROJECT" = "community-content" ] || [ "$E2E_PROJECT" = "bbpress-content" ] || [ "$E2E_PROJECT" = "site-reviews-content" ]
}

e2e_is_listings_events_content_project() {
  [ "$E2E_PROJECT" = "listings-events-content" ] || [ "$E2E_PROJECT" = "the-events-calendar-content" ] || [ "$E2E_PROJECT" = "wp-job-manager-content" ]
}

e2e_is_content_meta_content_project() {
  [ "$E2E_PROJECT" = "content-meta-content" ] || [ "$E2E_PROJECT" = "seriously-simple-podcasting-content" ] || [ "$E2E_PROJECT" = "wp-recipe-maker-content" ] || [ "$E2E_PROJECT" = "wordpress-seo-content" ] || [ "$E2E_PROJECT" = "advanced-custom-fields-content" ]
}

e2e_project_plugin_slug() {
  e2e_plugin_project_scalar_field plugin_slug
}

e2e_assert_supported_project() {
  case "$E2E_PROJECT" in
    core-content|learning-content|commerce-content|media-builder-content|community-content|listings-events-content|content-meta-content)
      return
      ;;
    *)
      if e2e_plugin_project_exists; then
        return
      fi
      err "Unsupported E2E_PROJECT: ${E2E_PROJECT}"
      echo "  Supported today: core-content, 6 legacy family projects, and plugin-specific projects declared in ${E2E_PROJECT_SPECS_FILE}"
      echo "  Future plugin projects should extend the shared project spec before adding stage-specific logic"
      exit 1
      ;;
  esac
}

e2e_project_label() {
  echo "$E2E_PROJECT"
}

e2e_scope_label() {
  if e2e_is_core_only; then
    echo "core-only"
  else
    echo "full"
  fi
}

e2e_banner_subtitle() {
  local plugin_slug
  plugin_slug="$(e2e_project_plugin_slug)"
  if [ -n "$plugin_slug" ]; then
    if e2e_is_core_only; then
      echo "${E2E_PROJECT} / baseline lane / ${plugin_slug}"
    else
      echo "${E2E_PROJECT} / extended lane / ${plugin_slug}"
    fi
    return
  fi

  if e2e_is_core_content_project; then
    if e2e_is_core_only; then
      echo "core-content / baseline lane / wp+virtual"
    else
      echo "core-content / extended lane / legacy full"
    fi
    return
  fi

  if e2e_is_learning_content_project; then
    if e2e_is_core_only; then
      echo "learning-content / baseline lane / tutor+learnpress"
    else
      echo "learning-content / extended lane / tutor+learnpress"
    fi
    return
  fi

  if e2e_is_commerce_content_project; then
    if e2e_is_core_only; then
      echo "commerce-content / baseline lane / woocommerce+edd"
    else
      echo "commerce-content / extended lane / woocommerce+edd"
    fi
    return
  fi

  if e2e_is_media_builder_content_project; then
    if e2e_is_core_only; then
      echo "media-builder-content / baseline lane / envira+elementor"
    else
      echo "media-builder-content / extended lane / envira+elementor"
    fi
    return
  fi

  if e2e_is_community_content_project; then
    if e2e_is_core_only; then
      echo "community-content / baseline lane / bbpress+site-reviews"
    else
      echo "community-content / extended lane / bbpress+site-reviews"
    fi
    return
  fi

  if e2e_is_listings_events_content_project; then
    if e2e_is_core_only; then
      echo "listings-events-content / baseline lane / tec+job-manager"
    else
      echo "listings-events-content / extended lane / tec+job-manager"
    fi
    return
  fi

  if e2e_is_content_meta_content_project; then
    if e2e_is_core_only; then
      echo "content-meta-content / baseline lane / podcast+recipe+meta"
    else
      echo "content-meta-content / extended lane / podcast+recipe+meta"
    fi
    return
  fi

  echo "${E2E_PROJECT} / ${E2E_SCOPE}"
}

e2e_assert_supported_slot
e2e_apply_slot_wp_admin_env
RUNTIME_DIR="$(e2e_runtime_dir)"
REPORTS_DIR="$(e2e_reports_dir)"
# Do not let a matrix lane inherit the shared :9083 base.  The provisioner
# exports these variables before run.sh, and this fallback keeps ad-hoc slot
# invocations deterministic as well.
if e2e_uses_isolated_slot_wordpress; then
  WP_URL="${E2E_SLOT_WP_BASE:-$(e2e_slot_wp_base)}"
  # Always rebind the Lab container to the active slot. Ambient
  # LAB_WP_CONTAINER from a prior lane (e.g. slot-a) must not stick while
  # WP_URL points at another slot port — that causes REST route-secret 404s.
  LAB_WP_CONTAINER="$(e2e_slot_wp_container)"
  export WP_URL LAB_WP_CONTAINER
fi
CLIENT_PORT="$(e2e_client_port)"
CLIENT_URL="http://127.0.0.1:${CLIENT_PORT}"
# Playwright suites read CLIENT_BASE; keep it aligned with the active slot port
# so parallel matrix lanes never all hammer shared :8977.
export E2E_SLOT
export CLIENT_URL
export CLIENT_BASE="${CLIENT_URL}"
# Slot lanes must never silently keep an ambient shared CLIENT_BASE.
if e2e_is_slot_mode; then
  case "${CLIENT_BASE}" in
    *:8977|*:8977/)
      err "E2E_SLOT=${E2E_SLOT} produced CLIENT_BASE=${CLIENT_BASE} (shared :8977); refusing"
      exit 1
      ;;
  esac
fi
PLAYWRIGHT_JSON_REPORT="$(e2e_playwright_json_report)"

e2e_required_wp_plugins() {
  if e2e_plugin_project_exists; then
    e2e_plugin_project_list_field required_wp_plugins
    return
  fi

  if e2e_is_core_content_project; then
    if e2e_is_core_only; then
      return
    fi
    for plugin in "${PLUGINS[@]}"; do
      if [ "$plugin" != "wptsall" ]; then
        printf '%s\n' "$plugin"
      fi
    done
    return
  fi

  if e2e_is_learning_content_project; then
    printf '%s\n' tutor learnpress
    return
  fi

  if e2e_is_commerce_content_project; then
    printf '%s\n' woocommerce easy-digital-downloads
    return
  fi

  if e2e_is_media_builder_content_project; then
    printf '%s\n' envira-gallery-lite elementor
    return
  fi

  if e2e_is_community_content_project; then
    printf '%s\n' bbpress site-reviews
    return
  fi

  if e2e_is_listings_events_content_project; then
    printf '%s\n' the-events-calendar wp-job-manager
    return
  fi

  if e2e_is_content_meta_content_project; then
    printf '%s\n' seriously-simple-podcasting wp-recipe-maker wordpress-seo advanced-custom-fields
  fi
}

e2e_seed_plans() {
  if e2e_plugin_project_exists; then
    # Plugin-specific lanes still need a deterministic WordPress core baseline.
    # Stage 2 preserves source content, so omitting Plan E can accidentally
    # depend on whatever a previous lane left behind.  Always run E first, then
    # the plugin project's declared supplemental plan(s).
    {
      printf '%s\n' E
      e2e_plugin_project_list_field seed_plans
    } | awk 'NF && !seen[$0]++'
    return
  fi

  if e2e_is_core_content_project; then
    # core-only still needs a baseline seed: Stage 2 deep-reset leaves empty
    # blog content, and verify-seeding hard-requires >=10 posts/pages.
    if e2e_is_core_only; then
      printf '%s\n' A
      return
    fi
    printf '%s\n' "${SEED_PLANS[@]}"
    return
  fi

  if e2e_is_learning_content_project; then
    printf '%s\n' B D
    return
  fi

  if e2e_is_commerce_content_project; then
    printf '%s\n' A
    return
  fi

  if e2e_is_media_builder_content_project; then
    printf '%s\n' A
    return
  fi

  if e2e_is_community_content_project; then
    printf '%s\n' A
    return
  fi

  if e2e_is_listings_events_content_project; then
    printf '%s\n' C
    return
  fi

  if e2e_is_content_meta_content_project; then
    printf '%s\n' B C
  fi
}

e2e_seed_plugin_allowlist_csv() {
  if ! e2e_plugin_project_exists; then
    return
  fi

  {
    printf '%s\n' wordpress-core
    e2e_project_plugin_slug
    e2e_required_wp_plugins
    e2e_plugin_project_list_field model_plugins
    e2e_plugin_project_list_field meta_only_plugins
    e2e_plugin_project_list_field model_binding_plugins
  } | awk '
    NF {
      slug=$0
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", slug)
      if (slug == "" || slug == "wordpress-blog") next
      if (!seen[slug]++) out[++n]=slug
      if (slug == "woocommerce" && !seen["woocommerce-extra"]++) out[++n]="woocommerce-extra"
    }
    END {
      for (i=1; i<=n; i++) {
        printf "%s%s", sep, out[i]
        sep=","
      }
    }
  '
}

e2e_required_wp_plugins_csv() {
  local raw
  raw="$(e2e_required_wp_plugins | paste -sd, -)"
  if [ -n "$raw" ]; then
    echo "$raw"
  else
    echo "(none)"
  fi
}

e2e_seed_plans_csv() {
  local raw
  raw="$(e2e_seed_plans | paste -sd, -)"
  if [ -n "$raw" ]; then
    echo "$raw"
  else
    echo "(none)"
  fi
}

e2e_model_binding_plugins_csv() {
  if e2e_plugin_project_exists; then
    e2e_plugin_project_list_csv model_binding_plugins
  else
    echo "(derived)"
  fi
}

e2e_content_types_csv() {
  if e2e_plugin_project_exists; then
    e2e_plugin_project_list_csv content_types
  else
    echo "(derived)"
  fi
}

# Always derive from the current E2E_PROJECT. An inherited allowlist from a
# parent matrix/shell (e.g. woocommerce-extra) would starve plugin-specific
# Plan C/A/D seeds (recipe/give/site-reviews found 0 fixtures on 2026-09-03).
if e2e_plugin_project_exists; then
  export WPTSALL_E2E_SEED_PLUGIN_ALLOWLIST="$(e2e_seed_plugin_allowlist_csv)"
fi

declare -Ag E2E_PLUGIN_STATUS_CACHE=()

e2e_load_plugin_status_cache() {
  if [ "${#E2E_PLUGIN_STATUS_CACHE[@]}" -gt 0 ]; then
    return
  fi

  while IFS=, read -r name status; do
    if [ "$name" = "name" ]; then
      continue
    fi
    E2E_PLUGIN_STATUS_CACHE["$name"]="$status"
  done < <(wp_cli plugin list --format=csv --fields=name,status 2>/dev/null)
}

e2e_wp_plugin_status() {
  local plugin="$1"
  e2e_load_plugin_status_cache
  echo "${E2E_PLUGIN_STATUS_CACHE[$plugin]:-missing}"
}

e2e_unquarantine_plugin() {
  local plugin="$1"
  if ! e2e_is_slot_mode; then
    return 0
  fi
  local container="${LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-${E2E_SLOT}}"
  docker exec "${container}" sh -c "
    if [ -d '/var/www/html/wp-content/plugins/${plugin}.e2e-quarantined' ] \
      && [ ! -d '/var/www/html/wp-content/plugins/${plugin}' ]; then
      mv '/var/www/html/wp-content/plugins/${plugin}.e2e-quarantined' \
        '/var/www/html/wp-content/plugins/${plugin}'
    fi
  " 2>/dev/null || true
}
