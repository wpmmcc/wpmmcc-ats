#!/usr/bin/env bash
# Layer A/B/C deep matrix across content-plugin projects (not Stage 1–7 replacement).
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-layer-abc-plugin-matrix.sh
#   bash tests/modules/wpmmcc-ats/e2e/run-layer-abc-plugin-matrix.sh --projects woocommerce-content,tutor-content
#   WPTSALL_ABC_SKIP_SCAN=1 bash ...   # skip heavy gettext source scan
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

CONTAINER="${LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/layer-abc"
STAMP="$(date +%Y%m%d-%H%M%S)"
SUMMARY_MD="${REPORT_DIR}/abc-matrix-${STAMP}.md"
PROJECTS_CSV="${E2E_PROJECTS:-}"
SKIP_SCAN="${WPTSALL_ABC_SKIP_SCAN:-0}"

mkdir -p "${REPORT_DIR}"

usage() {
  cat <<'EOF'
Usage: bash run-layer-abc-plugin-matrix.sh [--projects csv] [--skip-scan]

Runs verify-layer-abc-plugin-deep.php once per plugin project from project-specs.json.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --projects) PROJECTS_CSV="$2"; shift 2 ;;
    --skip-scan) SKIP_SCAN=1; shift ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown: $1"; usage; exit 2 ;;
  esac
done

mapfile -t ALL_PROJECTS < <(
  python3 - <<'PY' "${E2E_PROJECT_SPECS_FILE}"
import json, sys
from pathlib import Path
spec = json.loads(Path(sys.argv[1]).read_text())
for name in spec.get("plugin_projects", {}):
    print(name)
PY
)

if [[ -n "${PROJECTS_CSV}" ]]; then
  IFS=',' read -r -a PROJECTS <<< "${PROJECTS_CSV}"
else
  PROJECTS=("${ALL_PROJECTS[@]}")
fi

# Ensure strings table / migrations
docker exec "${CONTAINER}" wp eval 'if (function_exists("wptsall_run_migrations")) { wptsall_run_migrations(); } echo "db_ok\n";' --allow-root 2>/dev/null | grep -v Warning || true

{
  echo "# Layer ABC deep matrix — ${STAMP}"
  echo
  echo "| Project | Pass | Fail | Warn | Skip | Result |"
  echo "|---|---:|---:|---:|---:|---|"
} > "${SUMMARY_MD}"

TOTAL_FAIL=0
TOTAL_PASS_PROJ=0
TOTAL_FAIL_PROJ=0

for project in "${PROJECTS[@]}"; do
  project="$(echo "${project}" | xargs)"
  [[ -z "${project}" ]] && continue
  echo "==== ${project} ===="
  set +e
  OUT="$(
    docker exec \
      -e "E2E_PROJECT=${project}" \
      -e "E2E_PROJECT_SPECS_FILE=/opt/wptsall-e2e/project-specs.json" \
      -e "WPTSALL_ABC_SKIP_SCAN=${SKIP_SCAN}" \
      -e "WPTSALL_ABC_REPORT_DIR=/opt/wptsall-e2e/reports/layer-abc" \
      "${CONTAINER}" \
      wp eval-file /opt/wptsall-e2e/php/verify-layer-abc-plugin-deep.php --allow-root 2>&1
  )"
  RC=$?
  set -e
  echo "${OUT}" | grep -vE 'Warning:|already defined' || true

  SUMMARY_LINE="$(echo "${OUT}" | grep '^SUMMARY ' | tail -1 || true)"
  PASS_N="$(echo "${SUMMARY_LINE}" | sed -n 's/.*pass=\([0-9]*\).*/\1/p')"
  FAIL_N="$(echo "${SUMMARY_LINE}" | sed -n 's/.*fail=\([0-9]*\).*/\1/p')"
  WARN_N="$(echo "${SUMMARY_LINE}" | sed -n 's/.*warn=\([0-9]*\).*/\1/p')"
  SKIP_N="$(echo "${SUMMARY_LINE}" | sed -n 's/.*skip=\([0-9]*\).*/\1/p')"
  PASS_N="${PASS_N:-0}"
  FAIL_N="${FAIL_N:-0}"
  WARN_N="${WARN_N:-0}"
  SKIP_N="${SKIP_N:-0}"

  if [[ "${RC}" -eq 0 && "${FAIL_N}" -eq 0 ]]; then
    RESULT=PASS
    TOTAL_PASS_PROJ=$((TOTAL_PASS_PROJ + 1))
  else
    RESULT=FAIL
    TOTAL_FAIL_PROJ=$((TOTAL_FAIL_PROJ + 1))
    TOTAL_FAIL=$((TOTAL_FAIL + FAIL_N))
  fi
  echo "| ${project} | ${PASS_N} | ${FAIL_N} | ${WARN_N} | ${SKIP_N} | ${RESULT} |" >> "${SUMMARY_MD}"
done

{
  echo
  echo "## Totals"
  echo
  echo "- projects_pass: ${TOTAL_PASS_PROJ}"
  echo "- projects_fail: ${TOTAL_FAIL_PROJ}"
  echo "- check_fails: ${TOTAL_FAIL}"
  echo
  echo "Per-project JSON: \`tests/cross/playwright/reports/layer-abc/abc-*.json\`"
} >> "${SUMMARY_MD}"

echo
echo "Wrote ${SUMMARY_MD}"
echo "projects_pass=${TOTAL_PASS_PROJ} projects_fail=${TOTAL_FAIL_PROJ}"
exit "$([[ ${TOTAL_FAIL_PROJ} -eq 0 ]] && echo 0 || echo 1)"
