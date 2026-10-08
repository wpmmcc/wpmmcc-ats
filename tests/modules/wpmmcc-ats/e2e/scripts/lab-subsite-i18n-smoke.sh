#!/usr/bin/env bash
# Subsite Layer C gettext writeback smoke (blog 2).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../lib/repo-root.sh"
ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"
CONTAINER="${LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
echo "== Subsite i18n smoke =="
docker exec "$CONTAINER" wp plugin list --name=wpmmcc-ats --fields=name,status,version --allow-root 2>/dev/null | grep -v Warning || true
# Helper canonical home since the 2026-09-05 tests-tree migration:
# tests/modules/wpmmcc-ats/e2e/php/ (the old tests/cross/playwright/php/
# path this script used to reference no longer exists).
docker exec "$CONTAINER" wp eval-file /tmp/verify-layer-b-strings.php --allow-root 2>/dev/null || \
  docker cp "$ROOT/tests/modules/wpmmcc-ats/e2e/php/verify-layer-b-strings.php" "$CONTAINER:/tmp/verify-layer-b-strings.php" && \
  docker exec "$CONTAINER" wp eval-file /tmp/verify-layer-b-strings.php --allow-root
docker exec "$CONTAINER" wp site list --allow-root 2>/dev/null | head -5 || true
echo "Done. Deploy updated wpmmcc-ats to Lab before expecting PASS on wptsall_strings DDL."
