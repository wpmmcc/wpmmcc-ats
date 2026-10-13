#!/usr/bin/env bash
# Prove Give hot-plug meta roundtrip (content API path optional; PHP drives callback+sync).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../../.." && pwd)"
# shellcheck source=/dev/null
source "${ROOT}/tests/modules/wpmmcc-ats/e2e/config.sh"
export WPTSALL_LAB=1
export GIVE_HOTPLUG_FORM_ID="${GIVE_HOTPLUG_FORM_ID:-1055}"
export E2E_SINGLE_RELATION_ID="${E2E_SINGLE_RELATION_ID:-}"

echo "=== Prove Give hot-plug writeback loop (PHP) ==="
set +e
wp_eval "${E2E_DIR}/php/prove-give-hotplug-writeback.php" 2>&1 \
  | sed 's/\x1b\[[0-9;]*m//g' \
  | grep -vE 'PHP Warning|Constant WP_|HTTP_HOST|Undefined array|already defined'
rc=${PIPESTATUS[0]}
set -e
exit "$rc"
