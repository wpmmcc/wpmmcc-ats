#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: 环境检查
#
# 检查 4 个服务 + 15 个插件 + DB 表 + 二进制文件。
# 任何前提条件不满足则 ABORT。
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 1 "Environment Check"

FAIL=0
AUTO_ACTIVATE_PLUGINS="${E2E_AUTO_ACTIVATE_PLUGINS:-0}"

plugin_is_enabled() {
  local plugin="$1"
  # Logical product name in e2e configs is "wptsall"; WP plugin slug is wpmmcc-ats.
  if [ "$plugin" = "wptsall" ]; then
    plugin="wpmmcc-ats"
  fi
  if wp_cli plugin is-active "$plugin" 2>/dev/null; then
    return 0
  fi

  local status
  status="$(wp_cli plugin list --name="$plugin" --field=status 2>/dev/null | head -n 1 || true)"
  [ "$status" = "active-network" ]
}

# Activate wpmmcc-ats; wp-cli may exit non-zero on schema/PHP noise even when
# the plugin ends up active (same pattern as ensure-slot-wordpress.sh).
activate_wptsall_plugin() {
  wp_cli plugin activate wpmmcc-ats >/dev/null 2>&1 || true
  if plugin_is_enabled wpmmcc-ats; then
    return 0
  fi
  wp_cli eval 'delete_option("wptsall_db_version");' >/dev/null 2>&1 || true
  wp_cli plugin activate wpmmcc-ats >/dev/null 2>&1 || true
  plugin_is_enabled wpmmcc-ats
}

# ── 1. WordPress ──────────────────────────────────────────────────────────────
info "Checking WordPress..."
# Probe the REST root via ?rest_route=/ (valid under both pretty and ugly
# permalinks); /wp-json/ 404s on a slot whose .htaccess was clobbered to an
# empty rule set while rewrite rules are still valid. Retry transient 5xx/000
# under parallel Lab load; a persistent non-2xx/3xx still fails.
WP_STATUS="$(http_status_retry "${WP_URL}/?rest_route=/" 10 4 || true)"
case "${WP_STATUS}" in
  2??|3??)
    ok "WordPress accessible at ${WP_URL}"
    ;;
  *)
    err "WordPress NOT accessible at ${WP_URL} (status=${WP_STATUS:-000})"
    FAIL=1
    ;;
esac

# ── 2. WPTSALL 插件激活 ──────────────────────────────────────────────────────
info "Checking WPTSALL plugin..."
if plugin_is_enabled wpmmcc-ats; then
  ok "WPTSALL plugin is active"
else
  if [ "$AUTO_ACTIVATE_PLUGINS" = "1" ]; then
    info "WPTSALL plugin not active, trying auto-activate..."
    if activate_wptsall_plugin; then
      ok "WPTSALL plugin auto-activated"
    else
      err "WPTSALL plugin is NOT active (auto-activate failed)"
      FAIL=1
    fi
  else
    err "WPTSALL plugin is NOT active"
    FAIL=1
  fi
fi

# ── 3. Server ─────────────────────────────────────────────────────────────────
# Local-first boundary (AGENTS.md §0.1): the website control plane (8787) is
# NOT a dependency of the plugin or the clients. Only the explicit legacy
# lane (WPTSALL_USE_SERVER_CONTROL_PLANE=1) requires it.
if [[ "${WPTSALL_USE_SERVER_CONTROL_PLANE:-0}" == "1" ]]; then
  info "Checking Server (legacy control-plane lane)..."
  if check_server_api "${SERVER_URL}"; then
    ok "Server accessible at ${SERVER_URL}"
  else
    err "Server NOT accessible at ${SERVER_URL}"
    warn "Probe details: /health=$(http_status "${SERVER_URL}/health" 10 || echo 000), /api/v1/response-encryption-public-key=$(http_status "${SERVER_URL}/api/v1/response-encryption-public-key" 10 || echo 000), /api/v1/components=$(http_status "${SERVER_URL}/api/v1/components?per_page=1" 10 || echo 000)"
    FAIL=1
  fi
else
  info "Checking Server..."
  ok "Website control plane not required (local-first; SERVER_URL=${SERVER_URL} unused)"
fi

# ── 4. DB 表完整性 ────────────────────────────────────────────────────────────
info "Checking database tables..."
TABLE_COUNT=$(wp_cli eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}wptsall_%\""));' 2>/dev/null | tr -d '[:space:]')
TABLE_COUNT="${TABLE_COUNT:-0}"
if [[ "$TABLE_COUNT" =~ ^[0-9]+$ ]] && [ "$TABLE_COUNT" -lt 24 ] && [ "$AUTO_ACTIVATE_PLUGINS" = "1" ]; then
  info "WPTSALL schema incomplete (${TABLE_COUNT} tables); forcing deactivate+activate cycle..."
  wp_cli plugin deactivate wpmmcc-ats >/dev/null 2>&1 || true
  wp_cli eval 'delete_option("wptsall_db_version"); delete_option("wptsall_settings");' >/dev/null 2>&1 || true
  if activate_wptsall_plugin; then
    TABLE_COUNT=$(wp_cli eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}wptsall_%\""));' 2>/dev/null | tr -d '[:space:]')
    TABLE_COUNT="${TABLE_COUNT:-0}"
  fi
fi
if [[ "$TABLE_COUNT" =~ ^[0-9]+$ ]] && [ "$TABLE_COUNT" -ge 24 ]; then
  ok "Database tables: ${TABLE_COUNT} wptsall tables found"
else
  err "Database tables: only ${TABLE_COUNT} found (need >= 24)"
  FAIL=1
fi

# ── 5. Client 二进制 ──────────────────────────────────────────────────────────
info "Checking Client binary..."
if [ -f "$CLIENT_BIN" ]; then
  if find "${REPO_ROOT}/client-wpplugin/source/src" -type f -newer "$CLIENT_BIN" | grep -q .; then
    warn "Client binary exists but is stale (source newer than binary): ${CLIENT_BIN}"
    warn "Stage 6 will auto-rebuild before startup."
  else
    ok "Client binary exists and is up to date: ${CLIENT_BIN}"
  fi
else
  err "Client binary NOT found: ${CLIENT_BIN}"
  FAIL=1
fi

# ── 6. mock-translate-api 二进制 ──────────────────────────────────────────────
info "Checking mock-translate-api binary..."
if [ -f "$MOCK_API_BIN" ]; then
  ok "mock-translate-api binary exists: ${MOCK_API_BIN}"
else
  err "mock-translate-api binary NOT found: ${MOCK_API_BIN}"
  FAIL=1
fi

# ── 7. 插件激活检查（按 project scope 动态确定所需插件） ───────────────────────
info "Checking plugins..."
PLUGIN_FAIL=0
mapfile -t REQUIRED_PLUGINS < <(e2e_required_wp_plugins)
# wptsall is always required and already checked above
REQUIRED_PLUGINS+=( wptsall )
for plugin in "${REQUIRED_PLUGINS[@]}"; do
  if plugin_is_enabled "$plugin"; then
    ok "Plugin: $plugin"
  else
    if [ "$plugin" = "wptsall" ]; then
      continue
    fi
    if [ "$AUTO_ACTIVATE_PLUGINS" = "1" ]; then
      info "Plugin not active, trying auto-activate: $plugin"
      e2e_unquarantine_plugin "$plugin"
      if wp_cli plugin activate "$plugin" >/dev/null 2>&1; then
        ok "Plugin auto-activated: $plugin"
      else
        err "Plugin NOT active: $plugin (auto-activate failed)"
        PLUGIN_FAIL=1
      fi
    else
      err "Plugin NOT active: $plugin"
      PLUGIN_FAIL=1
    fi
  fi
done

if [ "$PLUGIN_FAIL" -eq 1 ]; then
  if [ "$AUTO_ACTIVATE_PLUGINS" = "1" ]; then
    warn "Some plugins still not active after auto-activate. Check install status."
  else
    warn "Some plugins are not active. Install/activate them before running E2E."
    warn "Tip: set E2E_AUTO_ACTIVATE_PLUGINS=1 to auto-activate installed plugins."
  fi
  FAIL=1
fi

if [ "$PLUGIN_FAIL" -eq 0 ]; then
  info "Initializing active plugin storage..."
  if wp_eval "${E2E_DIR}/php/init-active-plugin-storage.php" >/dev/null; then
    ok "Active plugin storage initialized"
  else
    err "Active plugin storage initialization failed"
    FAIL=1
  fi
fi

# ── 8. Playwright 依赖 ───────────────────────────────────────────────────────
info "Checking Playwright..."
PLAYWRIGHT_DIR="${E2E_DIR}/playwright"
if [ -d "${PLAYWRIGHT_DIR}/node_modules" ]; then
  ok "Playwright dependencies installed"
else
  warn "Playwright dependencies not installed. Will install in Stage 6."
fi

# ── 结果 ──────────────────────────────────────────────────────────────────────
echo ""
if [ "$FAIL" -eq 1 ]; then
  abort "Environment check failed. Fix the issues above before running E2E."
else
  ok "All environment checks passed!"
fi
