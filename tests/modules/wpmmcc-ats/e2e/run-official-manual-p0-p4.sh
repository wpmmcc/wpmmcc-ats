#!/usr/bin/env bash
# P0–P4: official fixtures → manual matrix seams → problem families → UI → 22/23.
#
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-official-manual-p0-p4.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-official-manual-p0-p4.sh \
#     --projects woocommerce-content,learnpress-content,give-content
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED E2E_SLOT_WP_BASE LAB_WP_CONTAINER \
  WP_URL WP_BASE CLIENT_BASE WPTSALL_DB_PATH CLIENT_URL \
  WPTSALL_WEB_UI_BIND WPTSALL_WEB_UI_PORT \
  2>/dev/null || true
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib/manual-isolation.sh"

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
export DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
export WP_BASE="${WP_URL:-http://127.0.0.1:9083}"
export WP_URL="${WP_BASE}"
# P0–P4 product seam gate may run while Client/mock are up for other lanes.
# Isolation fail-closed is enforced by matrix when MANUAL_ISOLATION_SKIP=0.
export MANUAL_ISOLATION_SKIP="${MANUAL_ISOLATION_SKIP:-1}"

PROJECTS_CSV="${E2E_MANUAL_PROJECTS:-}"
SKIP_UI=0
SKIP_CORRESPONDENCE=0
SKIP_PLAYWRIGHT_MATRIX=0
STAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_DIR="${REPORTS_DIR}/official-manual-p0-p4-${STAMP}"
mkdir -p "${REPORT_DIR}" "${RUNTIME_DIR}"

usage() {
  cat <<'EOF'
Usage: bash tests/modules/wpmmcc-ats/e2e/run-official-manual-p0-p4.sh [options]
  --projects <csv>              Limit content projects
  --skip-ui                     Skip P2 Playwright editor UI save
  --skip-correspondence         Skip 22/23 correspondence suites
  --skip-playwright-matrix      Skip matrix Playwright browse
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --projects) PROJECTS_CSV="$2"; shift 2 ;;
    --skip-ui) SKIP_UI=1; shift ;;
    --skip-correspondence) SKIP_CORRESPONDENCE=1; shift ;;
    --skip-playwright-matrix) SKIP_PLAYWRIGHT_MATRIX=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown: $1" >&2; usage >&2; exit 2 ;;
  esac
done

info() { echo "[p0-p4] $*"; }
abort() { echo "[p0-p4] ERROR: $*" >&2; exit 1; }

info "P0 tag official fixtures"
wp_eval "${E2E_DIR}/php/tag-official-manual-fixtures.php" \
  2>&1 | tee "${REPORT_DIR}/p0-tag-fixtures.log"
cp -f "${RUNTIME_DIR}/official-manual-fixtures.json" "${REPORT_DIR}/" 2>/dev/null || true

info "P1 identity-meta API static gate"
bash "${SCRIPT_DIR}/check-identity-meta-api.sh" | tee "${REPORT_DIR}/identity-meta-api-gate.log"

info "P1+P4 manual content matrix (REST + public seams)"
MATRIX_CMD=( bash "${SCRIPT_DIR}/run-manual-content-plugin-matrix.sh" )
if [[ -n "${PROJECTS_CSV}" ]]; then
  MATRIX_CMD+=( --projects "${PROJECTS_CSV}" )
fi
if [[ "${SKIP_PLAYWRIGHT_MATRIX}" -eq 1 ]]; then
  MATRIX_CMD+=( --skip-playwright )
fi
set +e
WPTSALL_LAB=1 E2E_MANUAL_PROJECTS="${PROJECTS_CSV}" MANUAL_ISOLATION_SKIP="${MANUAL_ISOLATION_SKIP}" \
  "${MATRIX_CMD[@]}" \
  2>&1 | tee "${REPORT_DIR}/p1-p4-matrix.log"
MATRIX_RC=${PIPESTATUS[0]}
set -e
[[ ${MATRIX_RC} -eq 0 ]] || abort "manual matrix failed rc=${MATRIX_RC}"

info "P3 problem-family regression"
set +e
wp_eval "${E2E_DIR}/php/problem-family-regression.php" \
  2>&1 | tee "${REPORT_DIR}/p3-problem-families.log"
P3_RC=${PIPESTATUS[0]}
set -e
cp -f "${RUNTIME_DIR}/problem-family-regression.json" "${REPORT_DIR}/" 2>/dev/null || true
[[ ${P3_RC} -eq 0 ]] || abort "problem-family regression failed rc=${P3_RC}"

if [[ "${SKIP_UI}" -eq 0 ]]; then
  info "P2 editor UI save (priority)"
  set +e
  (
    cd "${SCRIPT_DIR}/playwright"
    WP_BASE="${WP_BASE}" WP_URL="${WP_BASE}" E2E_RUNTIME_DIR="${RUNTIME_DIR}" \
      npx playwright test -c playwright.manual-content-plugin-matrix.config.ts \
        manual-content-plugin-matrix/manual-translate-ui.e2e.spec.ts --workers=1 --reporter=line
  ) 2>&1 | tee "${REPORT_DIR}/p2-ui.log"
  UI_RC=${PIPESTATUS[0]}
  set -e
  [[ ${UI_RC} -eq 0 ]] || abort "P2 UI failed rc=${UI_RC}"
fi

if [[ "${SKIP_CORRESPONDENCE}" -eq 0 ]]; then
  info "Correspondence 22/23"
  set +e
  (
    cd "${SCRIPT_DIR}/playwright"
    WP_BASE="${WP_BASE}" npm run test:support:plugin-correspondence -- --reporter=line
    WP_BASE="${WP_BASE}" npm run test:support:plugin-public-surfaces -- --reporter=line
  ) 2>&1 | tee "${REPORT_DIR}/correspondence-22-23.log"
  CORR_RC=${PIPESTATUS[0]}
  set -e
  [[ ${CORR_RC} -eq 0 ]] || abort "22/23 failed rc=${CORR_RC}"
fi

info "OK — reports in ${REPORT_DIR}"
echo "${REPORT_DIR}"
