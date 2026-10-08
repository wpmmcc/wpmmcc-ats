#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Stage 3: 数据填充
#
# 调用 seeding 框架填充 15 个插件数据。
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 3 "Data Seeding"

START=$(stage_start_time)
ALLOW_PARTIAL_SEED="${E2E_ALLOW_PARTIAL_SEED:-0}"
SEED_FAILED=0

SEED_SCRIPT="${REPO_ROOT}/tests/modules/wpmmcc-ats/seeding/run-plan.php"

if [ ! -f "$SEED_SCRIPT" ]; then
  abort "Seeding script not found: ${SEED_SCRIPT}"
fi

mark_seed_failure() {
  local message="$1"
  err "$message"
  SEED_FAILED=1
  if [ "$ALLOW_PARTIAL_SEED" = "1" ]; then
    warn "E2E_ALLOW_PARTIAL_SEED=1 set, continuing with partial seed"
  fi
}

# ── 逐 Plan 填充 ─────────────────────────────────────────────────────────────
mapfile -t _ACTIVE_SEED_PLANS < <(e2e_seed_plans)
if [ "${#_ACTIVE_SEED_PLANS[@]}" -eq 0 ]; then
  info "No seed plans for current project/scope — skipping plan loop"
fi

# P2 parallelization (PLAN-2026-08-31): plans target disjoint plugins, so
# with more than one plan the scheduler runs the core baseline (first plan)
# alone, then batches the rest (SEED_PARALLEL_JOBS at a time, declared order
# preserved). SEED_SEQUENTIAL=1 restores the legacy serial loop.
# MEASURED 2026-09-02: inside isolated matrix slots the parallel scheduler
# stalled (nested `wp import` deadlocked on its output pipe ~40 min, run 15
# slot-c/slot-e lanes), so slot lanes keep the serial loop that the main
# environment has long exercised.
SEED_PARALLEL_ELIGIBLE=0
if [ "${#_ACTIVE_SEED_PLANS[@]}" -gt 1 ] && [ "${SEED_SEQUENTIAL:-0}" != "1" ] \
  && [ "${E2E_SLOT_WP_ISOLATED:-0}" != "1" ] \
  && [ -f "${E2E_DIR}/scripts/run-seed-plans-parallel.sh" ]; then
  SEED_PARALLEL_ELIGIBLE=1
fi

if [ "${SEED_PARALLEL_ELIGIBLE}" -eq 1 ]; then
  info "Seeding ${#_ACTIVE_SEED_PLANS[@]} plans via parallel scheduler (jobs=${SEED_PARALLEL_JOBS:-4})"
  if bash "${E2E_DIR}/scripts/run-seed-plans-parallel.sh" "${_ACTIVE_SEED_PLANS[@]}"; then
    ok "All seed plans completed (parallel scheduler)"
  else
    mark_seed_failure "Parallel seed plan execution failed"
  fi
else
  for plan in "${_ACTIVE_SEED_PLANS[@]}"; do
    info "Plan ${plan}: running pre-setup..."
    if wp_eval "$SEED_SCRIPT" "$plan" pre 2>&1; then
      ok "Plan ${plan} pre-setup completed"
    else
      mark_seed_failure "Plan ${plan} pre-setup failed"
    fi

    info "Plan ${plan}: seeding..."
    if wp_eval "$SEED_SCRIPT" "$plan" seed 2>&1; then
      ok "Plan ${plan} seeded"
    else
      mark_seed_failure "Plan ${plan} seeding failed"
    fi

    info "Plan ${plan}: running post-setup..."
    if wp_eval "$SEED_SCRIPT" "$plan" post 2>&1; then
      ok "Plan ${plan} post-setup completed"
    else
      mark_seed_failure "Plan ${plan} post-setup failed"
    fi
    echo ""
  done
fi

if [ "$SEED_FAILED" -eq 1 ] && [ "$ALLOW_PARTIAL_SEED" != "1" ]; then
  abort "Seeding plans failed. Set E2E_ALLOW_PARTIAL_SEED=1 only when debugging partial datasets."
fi

# ── 用户行为夹具（注册/登录/评论/商品评价/论坛回复）────────────────────────────
info "Seeding user & comment fixtures..."
if wp_eval "${E2E_DIR}/php/seed-user-comment-fixtures.php"; then
  ok "User/comment fixtures seeded"
else
  mark_seed_failure "User/comment fixture seeding failed"
fi

# ── 插件前台初始化 & URL 配置补丁（统一收敛到数据填充阶段）───────────────────
info "Initializing plugin front pages/options..."
if wp_eval "${E2E_DIR}/php/init-plugin-front-pages.php"; then
  ok "Plugin front pages/options initialized"
else
  mark_seed_failure "Plugin front page init failed"
fi

info "Applying URL access config patches..."
if wp_eval "${E2E_DIR}/php/fix-url-access-config.php"; then
  ok "URL access config patches applied"
else
  mark_seed_failure "URL access config patch failed"
fi

# ── 数据真实性增强（独立步骤，不覆盖已有手工字段）─────────────────────────────
# Keep this after all seed/init steps because plugin front pages and URL patches
# create additional pages that also need SEO/excerpt/media realism fields.
info "Enriching realism fields (SEO/ALT/excerpt/date spread)..."
if wp_eval "${E2E_DIR}/php/enrich-seeding-realism.php"; then
  ok "Realism enrichment completed"
else
  if [ "$ALLOW_PARTIAL_SEED" = "1" ]; then
    err "Realism enrichment failed"
    warn "E2E_ALLOW_PARTIAL_SEED=1 set, continuing with partial seed"
  else
    abort "Realism enrichment failed."
  fi
fi

# ── Matrix lane minimums (tags / woo counts on isolated slots) ───────────────
if [[ "${E2E_MATRIX_PARALLEL:-0}" == "1" ]] || e2e_is_slot_mode; then
  info "Applying matrix lane minimum fixups..."
  if wp_eval "${E2E_DIR}/php/seed-matrix-minimum-fixups.php"; then
    ok "Matrix lane minimum fixups applied"
  else
    mark_seed_failure "Matrix lane minimum fixups failed"
  fi
fi

# ── 验证填充结果 ──────────────────────────────────────────────────────────────
info "Verifying seeding data..."
if wp_eval "${E2E_DIR}/php/verify-seeding.php"; then
  ok "Seeding verification passed"
else
  mark_seed_failure "Seeding verification failed"
fi

if [ "$SEED_FAILED" -eq 1 ] && [ "$ALLOW_PARTIAL_SEED" != "1" ]; then
  abort "Data seeding stage failed. Set E2E_ALLOW_PARTIAL_SEED=1 only when debugging partial datasets."
fi

stage_elapsed "$START"
