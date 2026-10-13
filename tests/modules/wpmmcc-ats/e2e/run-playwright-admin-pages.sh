#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Playwright Admin Pages E2E Wrapper
#
# WP 插件管理员后台 E2E 测试（comprehensive suite 的 01-12 套件）。
# 覆盖：
#   - 13 个 admin 页面渲染（不报 fatal error）
#   - 设置页字段、nonce、capability
#   - 手工翻译 hub + 5 子页
#   - 文章/页面/分类列表翻译列
#   - WPTSALL dashboard widget
#   - REST API 根 + namespace
#   - 前端 SEO + WP-CLI 命令
#   - Quick Edit + Bulk Edit 语言选择器
#   - 文章编辑页翻译 meta box
#   - 未登录重定向 + REST 越权 + CSRF nonce
#   - WC/Tribe 第三方插件共存
#
# 用法:
#   bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh         # 跑全部（35 spec 文件/218 test，2026-09-27 实测，~2h）
#   bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 01      # 只跑 01-admin-render
#   bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh --smoke  # 快速冒烟（4 测试）
#   WP_BASE=https://blog.wpmm.cc bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh
#
# 长时监控纪律（AGENTS.md §7.1B）：全量 ~2h 一律后台跑（输出重定向 /tmp/<run-id>.log），
# 前台每 ≤60s tail + grep -c '✓' 轮询进度；见 ✘ 立即处理再重跑；禁止 sleep 300/1800 长等。
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright/comprehensive"
RESULTS_DIR="${PLAYWRIGHT_DIR}/test-results"
REPORT_DIR="${PLAYWRIGHT_DIR}/playwright-report"

WP_BASE="${WP_BASE:-http://127.0.0.1:9083}"
WP_ADMIN_USER="${WP_ADMIN_USER:-e2esmokeadmin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
LAB_WP_CONTAINER="${LAB_WP_CONTAINER:-${WPTSALL_LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}}"

SMOKE=0
SPEC_ARGS=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --smoke)
      SMOKE=1
      shift
      ;;
    --all)
      SMOKE=0
      shift
      ;;
    -h|--help)
      sed -n '3,30p' "$0"
      exit 0
      ;;
    *)
      SPEC_ARGS+=("$1")
      shift
      ;;
  esac
done

export WP_BASE WP_ADMIN_USER WP_ADMIN_PASS LAB_WP_CONTAINER

mkdir -p "${RESULTS_DIR}" "${REPORT_DIR}"

# Verify WP is reachable (fall back to Lab wordpress-test :9083)
echo "[admin-pages] Probing WP at ${WP_BASE}..."
if ! curl -sk -o /dev/null -w "%{http_code}" "${WP_BASE}/wp-login.php" | grep -q "200"; then
  if [[ "${WP_BASE}" != "http://127.0.0.1:9083" ]] && curl -sk -o /dev/null -w "%{http_code}" "http://127.0.0.1:9083/wp-login.php" | grep -q "200"; then
    echo "[admin-pages] ${WP_BASE} unreachable; using Lab WP_BASE=http://127.0.0.1:9083"
    WP_BASE="http://127.0.0.1:9083"
    export WP_BASE
  else
    echo "[admin-pages] FATAL: WP at ${WP_BASE} not reachable" >&2
    exit 1
  fi
fi

cd "${PLAYWRIGHT_DIR}"
echo "[admin-pages] Running in: $(pwd)"

if [[ $SMOKE -eq 1 ]]; then
  echo "[admin-pages] SMOKE: 4 critical tests (01 admin-render + 02 settings + 06 rest smoke + 11 capability)"
  npx playwright test -c playwright.comprehensive.config.ts \
    --reporter=line \
    01-admin-render.spec.ts \
    02-settings.spec.ts \
    06-rest-api-smoke.spec.ts \
    11-capability.spec.ts
  exit $?
fi

if [[ ${#SPEC_ARGS[@]} -gt 0 ]]; then
  echo "[admin-pages] Running specs: ${SPEC_ARGS[*]}"
  npx playwright test -c playwright.comprehensive.config.ts \
    --reporter=list "${SPEC_ARGS[@]}"
  exit $?
fi

echo "[admin-pages] Running ALL comprehensive specs (incl. form-submit round-trip)"
npx playwright test -c playwright.comprehensive.config.ts \
  --reporter=list
