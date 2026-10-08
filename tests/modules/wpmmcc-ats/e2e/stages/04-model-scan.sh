#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Stage 4: 模型扫描
#
# 同步插件代码 + 触发扫描 + 验证规则。
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 4 "Model Scan"

START=$(stage_start_time)

# ── 同步插件代码 ──────────────────────────────────────────────────────────────
info "Syncing plugin code..."
if [ -f "${REPO_ROOT}/sync.sh" ]; then
  bash "${REPO_ROOT}/sync.sh" 2>&1 | tail -3
  ok "Plugin code synced"
else
  warn "sync.sh not found, skipping"
fi

# ── 触发扫描 ──────────────────────────────────────────────────────────────────
info "Triggering model scan..."
if wp_eval "${E2E_DIR}/php/trigger-scan.php"; then
  ok "Model scan completed"
else
  abort "Model scan failed"
fi

# Force-rescan can drop adapter formats / media task_types; restore Stage-4 gates.
info "Enriching rule format gates (serialized_php / media_ref task_types)..."
if wp_eval "${E2E_DIR}/php/enrich-rule-format-gates.php"; then
  ok "Rule format gates enriched"
else
  abort "Rule format gate enrichment failed"
fi

# ── 验证规则 ──────────────────────────────────────────────────────────────────
info "Verifying translation rules..."
if wp_eval "${E2E_DIR}/php/verify-rules.php"; then
  ok "Translation rules verified"
else
  abort "Translation rules verification failed"
fi

# ── 规则 URL 审计与 requires_login 归一化 ────────────────────────────────────
info "Auditing rule URLs..."
if wp_eval "${E2E_DIR}/php/audit-rule-urls.php"; then
  ok "Rule URL audit generated"
else
  abort "Rule URL audit failed"
fi

info "Normalizing requires_login flags..."
if wp_eval "${E2E_DIR}/php/normalize-rule-login-flags.php"; then
  ok "Rule login flags normalized"
else
  abort "Rule login flag normalization failed"
fi

info "Refreshing rule URL audit..."
if wp_eval "${E2E_DIR}/php/audit-rule-urls.php"; then
  ok "Rule URL audit refreshed"
else
  abort "Rule URL audit refresh failed"
fi

# ── 验证字段分类（可选，使用现有脚本） ────────────────────────────────────────
FIELD_CLASS_SCRIPT="${REPO_ROOT}/testing/verify-field-classification.php"
if [ -f "$FIELD_CLASS_SCRIPT" ]; then
  info "Verifying field classification..."
  if wp_eval "$FIELD_CLASS_SCRIPT" 2>&1; then
    ok "Field classification verified"
  else
    warn "Field classification had issues (non-fatal)"
  fi
fi

stage_elapsed "$START"
