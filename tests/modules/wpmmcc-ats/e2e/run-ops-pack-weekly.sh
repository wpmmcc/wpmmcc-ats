#!/usr/bin/env bash
# W1-8: weekly ops pack — Languages/SEO/Menu (16) + Strings TM (18).
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-ops-pack-weekly.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

PLAYWRIGHT_DIR="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
REPORT_DIR="${REPO_ROOT:-$(cd "$(dirname "$0")/../../.." ${SCRIPT_DIR}/reports${SCRIPT_DIR}/reports pwd)/tests/reports/e2e/wpmmcc-ats}/ops-pack/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "${REPORT_DIR}"

print_stage "SUPPORT" "Ops pack weekly: 16 languages-seo-menu + 18 strings-tm (wp=${WP_URL:-})"

export WP_BASE="${WP_URL:-${WP_BASE:-http://127.0.0.1:9083}}"
export WP_BASE_URL="${WP_BASE}"

cd "${PLAYWRIGHT_DIR}"
FAIL=0

info "Running 16-languages-seo-menu-submit..."
if npm run test:support:plugin-languages-seo-menu -- --reporter=line 2>&1 | tee "${REPORT_DIR}/16-languages-seo-menu.log"; then
  ok "16 languages-seo-menu passed"
else
  err "16 languages-seo-menu failed"
  FAIL=1
fi

info "Running 18-strings-tm-submit..."
if npm run test:support:plugin-strings-tm -- --reporter=line 2>&1 | tee "${REPORT_DIR}/18-strings-tm.log"; then
  ok "18 strings-tm passed"
else
  err "18 strings-tm failed"
  FAIL=1
fi

python3 - <<PY
import json
from pathlib import Path
report = {
  "status": "passed" if ${FAIL} == 0 else "failed",
  "pack": ["16-languages-seo-menu", "18-strings-tm"],
  "report_dir": "${REPORT_DIR}",
}
Path("${REPORT_DIR}/ops-pack-summary.json").write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps(report, indent=2))
PY

exit "${FAIL}"
