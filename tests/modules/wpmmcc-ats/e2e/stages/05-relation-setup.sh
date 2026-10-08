#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Stage 5: 站点关系设置
#
# 创建 3 种站点关系 + 绑定模型 + 配置 post_type。
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 5 "Relation Setup"

START=$(stage_start_time)

ensure_dirs

# ── 创建站点关系 ──────────────────────────────────────────────────────────────
info "Creating site relations..."
RELATION_SETUP_ARG="$(e2e_relation_setup_args)"
if wp_eval "${E2E_DIR}/php/setup-relations.php" "${RELATION_SETUP_ARG}"; then
  ok "Site relations created"
else
  abort "Failed to create site relations"
fi

# ── 验证 relation-ids.json ───────────────────────────────────────────────────
if [ -f "${RUNTIME_DIR}/relation-ids.json" ]; then
  ok "relation-ids.json saved"
  cat "${RUNTIME_DIR}/relation-ids.json"
else
  abort "relation-ids.json not generated"
fi

# ── 绑定模型 ──────────────────────────────────────────────────────────────────
echo ""
info "Binding models to relations..."
if wp_eval "${E2E_DIR}/php/bind-models.php"; then
  ok "Models bound to relations"
else
  abort "Failed to bind models"
fi

# ── 配置 post_type_configs ───────────────────────────────────────────────────
echo ""
info "Configuring post_type_configs..."
if wp_eval "${E2E_DIR}/php/configure-post-types.php"; then
  ok "Post type configs created"
else
  abort "Failed to configure post types"
fi

# ── Seed baseline fixtures (ISS-00) ──────────────────────────────────────────
echo ""
info "Seeding baseline fixtures (relation/manual-field/language_pack/media)..."
if wp_eval "${E2E_DIR}/php/seed-baseline-fixtures.php"; then
  ok "Baseline fixtures seeded"
else
  abort "Failed to seed baseline fixtures"
fi

echo ""
info "Verifying baseline fixtures..."
if wp_eval "${E2E_DIR}/php/verify-baseline-fixtures.php"; then
  ok "Baseline fixtures verified"
else
  abort "Baseline fixture verification failed"
fi

echo ""
info "Verifying relation coverage matrix (all plugins + WP core)..."
if wp_eval "${E2E_DIR}/php/verify-relation-coverage.php"; then
  ok "Relation coverage verified"
else
  abort "Relation coverage verification failed"
fi

stage_elapsed "$START"
