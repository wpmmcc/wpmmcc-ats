#!/usr/bin/env bash
# Run tests/modules/wpmmcc-ats/seeding/run-plan.php against Lab WP or host WP_ROOT.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
LAB_DIR="${REPO_ROOT}/tests/docker-lab"
LAB_ENV="${LAB_DIR}/lab.env"

PLAN="A"
STAGE="full"
USE_DOCKER="auto"

usage() {
  cat <<EOF
Usage: bash tests/modules/wpmmcc-ats/seeding/run-plan.sh --plan A|B|C|D|E [options]

Options:
  --plan <id>       Seeding plan (default A)
  --stage <name>    pre|seed|post|verify|full (default full)
  --docker          Force docker-lab wordpress-test
  --host            Force host WP_ROOT / wp-cli
  --help            Show help

Examples:
  WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/seeding/run-plan.sh --plan A
  bash tests/modules/wpmmcc-ats/seeding/run-plan.sh --plan D --stage seed
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --plan) PLAN="${2:-A}"; shift 2 ;;
    --stage) STAGE="${2:-full}"; shift 2 ;;
    --docker) USE_DOCKER=1; shift ;;
    --host) USE_DOCKER=0; shift ;;
    -h|--help) usage; exit 0 ;;
    pre|seed|post|verify|full) STAGE="$1"; shift ;;
    [A-Ea-e]) PLAN="${1^^}"; shift ;;
    *) echo "Unknown argument: $1" >&2; usage; exit 1 ;;
  esac
done

if [[ -f "${LAB_ENV}" ]]; then
  # shellcheck source=/dev/null
  source "${LAB_ENV}"
fi

if [[ "${USE_DOCKER}" == "auto" ]]; then
  if [[ "${WPTSALL_LAB:-}" == "1" ]]; then
    USE_DOCKER=1
  else
    USE_DOCKER=0
  fi
fi

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_DIR="${SCRIPT_DIR}/plans/${PLAN}/results"
mkdir -p "${RESULT_DIR}"
LOG_FILE="${RESULT_DIR}/run-plan-${TIMESTAMP}.log"

run_in_docker() {
  local service="${LAB_WP_SERVICE:-wordpress-test}"
  local remote_base="/tmp/wptsall-seeding"
  local cid
  bash "${LAB_DIR}/scripts/ensure-wp-cli.sh" "${service}"
  cid="$(cd "${LAB_DIR}" && docker compose ps -q "${service}")"
  if [[ -z "${cid}" ]]; then
    echo "Container not running: ${service}" >&2
    return 1
  fi
  docker exec "${cid}" rm -rf "${remote_base}"
  docker exec "${cid}" mkdir -p "${remote_base}"
  # Host curl-style: tar stream into container (compose cp between services is unsupported)
  tar -C "${SCRIPT_DIR}" -cf - . | docker exec -i "${cid}" tar -C "${remote_base}" -xf -
  docker compose -f "${LAB_DIR}/docker-compose.yml" exec -T \
    -e PHP_MEMORY_LIMIT=512M \
    -e 'WP_CLI_PHP=php -d memory_limit=512M' \
    "${service}" wp eval-file "${remote_base}/run-plan.php" "${PLAN}" "${STAGE}" \
    --allow-root --path=/var/www/html
}

run_on_host() {
  # shellcheck source=/dev/null
  source "${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/config.sh"
  cd "${WP_ROOT}" && "${WP_CLI}" eval-file "${SCRIPT_DIR}/run-plan.php" "${PLAN}" "${STAGE}"
}

echo "=== Seeding plan ${PLAN} stage ${STAGE} (docker=${USE_DOCKER}) ==="

set +e
if [[ "${USE_DOCKER}" -eq 1 ]]; then
  run_in_docker 2>&1 | tee "${LOG_FILE}"
else
  run_on_host 2>&1 | tee "${LOG_FILE}"
fi
exit_code=${PIPESTATUS[0]}
set -e

RESULT_JSON="${RESULT_DIR}/run-plan-${TIMESTAMP}.json"
python3 - <<PY "${LOG_FILE}" "${RESULT_JSON}" "${PLAN}" "${STAGE}" "${exit_code}"
import json, sys
from datetime import datetime, timezone
log_path, out_path, plan, stage, exit_code = sys.argv[1:6]
text = open(log_path, encoding='utf-8', errors='replace').read()
# Prefer seeding summary over wp-cli exit code (PHP warnings often yield non-zero).
if '数据填充完成' in text or 'Workflow completed successfully' in text:
    status = 'passed'
elif exit_code == '0' and 'Fatal error' not in text and 'ABORT' not in text:
    status = 'passed'
else:
    status = 'failed'
if 'Fatal error' in text or 'ABORT' in text:
    status = 'failed'
doc = {
    'suite': 'seeding-run-plan',
    'plan': plan,
    'stage': stage,
    'status': status,
    'exit_code': int(exit_code),
    'finished_at': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
    'log': log_path,
}
json.dump(doc, open(out_path, 'w'), indent=2)
print(f'Wrote {out_path} status={status}')
PY

exit "${exit_code}"
