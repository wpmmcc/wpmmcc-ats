#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Playwright Roles / Non-Admin Capability Lane
#
# Authenticated editor must be locked out of plugin admin pages/menu and
# admin REST routes (wptsall/v2). Complements comprehensive/11-capability
# (unauthenticated only). Wired into lab-nightly as `roles_non_admin`.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-playwright-roles-non-admin.sh
#   WP_BASE=http://127.0.0.1:9181 bash …/run-playwright-roles-non-admin.sh
# Env:
#   WP_BASE            target WP (default Lab wordpress-test :9083)
#   LAB_WP_CONTAINER   container for wp-cli user setup
#                      (default wptsall-wp-lab-wordpress-test-1)
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"

WP_BASE="${WP_BASE:-http://127.0.0.1:9083}"
export WP_BASE
export LAB_WP_CONTAINER="${LAB_WP_CONTAINER:-${WPTSALL_LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}}"

echo "[roles-lane] Probing WP at ${WP_BASE}..."
if ! curl -sk -o /dev/null -w "%{http_code}" "${WP_BASE}/wp-login.php" | grep -q "200"; then
  echo "[roles-lane] FATAL: WP at ${WP_BASE} not reachable" >&2
  exit 1
fi

cd "${PLAYWRIGHT_DIR}"
echo "[roles-lane] Running in: $(pwd)"
npx playwright test -c playwright.roles-non-admin.config.ts --reporter=list
