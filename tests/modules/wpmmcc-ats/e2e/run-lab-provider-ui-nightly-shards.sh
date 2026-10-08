#!/usr/bin/env bash
# Nightly: shard lab-provider-cases across N workers (WebUI Playwright).
#
#   LAB_SHARDS=4 bash tests/modules/wpmmcc-ats/e2e/run-lab-provider-ui-nightly-shards.sh
#   LAB_SHARD_INDEX=0 LAB_SHARDS=4 bash …   # single shard
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

SHARDS="${LAB_SHARDS:-4}"
INDEX="${LAB_SHARD_INDEX:-}"
MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}"
WEBUI_A_BASE="${WEBUI_A_BASE:-http://127.0.0.1:8977}"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/ui-provider-mock/nightly-shards"
mkdir -p "${REPORT_DIR}"

print_stage "E2E-INDEPENDENT" "Lab provider UI nightly shards (${SHARDS})"

check_url "${MOCK_API_BASE}/api/v1/health" || abort "mock-api missing"
check_url "${MOCK_API_BASE}/api/v1/lab-provider-cases" || abort "lab-provider-cases missing"
check_url "${WEBUI_A_BASE}/api/status" || abort "WebUI missing"

MOCK_API_BASE="${MOCK_API_BASE}" python3 "${REPO_ROOT}/tests/scripts/sync-lab-provider-ui-cases.py"

run_shard() {
  local i="$1"
  info "shard ${i}/${SHARDS}"
  LAB_CASES_LIMIT=0 \
  LAB_SHARD_INDEX="$i" \
  LAB_SHARDS="$SHARDS" \
  WEBUI_A_BASE="$WEBUI_A_BASE" \
  MOCK_API_BASE="$MOCK_API_BASE" \
  REPORT_DIR="${REPORT_DIR}/shard-${i}" \
    bash "${SCRIPT_DIR}/run-ui-provider-from-lab-cases.sh"
}

if [[ -n "$INDEX" ]]; then
  run_shard "$INDEX"
  ok "shard ${INDEX} finished"
  exit 0
fi

fail=0
for ((i = 0; i < SHARDS; i++)); do
  run_shard "$i" || fail=1
done
[[ "$fail" -eq 0 ]] && ok "all shards finished" || abort "one or more shards failed"
