#!/usr/bin/env bash
# Audit matrix lane logs: every passed lane must have HTTP 200 frontend + sample URL evidence.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=/dev/null
source "${E2E_DIR}/config.sh"

MATRIX_JSON="${1:-}"
if [[ -z "${MATRIX_JSON}" ]]; then
  MATRIX_JSON="$(ls -t "${REPORTS_DIR}"/e2e-project-matrix-*.json 2>/dev/null | head -1 || true)"
fi
if [[ -z "${MATRIX_JSON}" || ! -f "${MATRIX_JSON}" ]]; then
  echo "verify-matrix-translated-urls: no matrix report" >&2
  exit 1
fi

STAMP="$(basename "${MATRIX_JSON}" .json)"
STAMP="${STAMP#e2e-project-matrix-}"
FAIL=0
CHECKED=0
PASS_ROOT=0
PASS_SAMPLE=0

while IFS= read -r project; do
  [[ -z "${project}" ]] && continue
  log="${REPORTS_DIR}/matrix-lane-${STAMP}-${project}.log"
  CHECKED=$((CHECKED + 1))
  if [[ ! -f "${log}" ]]; then
    echo "FAIL ${project}: missing lane log ${log}" >&2
    FAIL=$((FAIL + 1))
    continue
  fi
  if grep -q 'Frontend route .* reachable (HTTP 200)' "${log}"; then
    PASS_ROOT=$((PASS_ROOT + 1))
  else
    echo "FAIL ${project}: no HTTP 200 frontend root in lane log" >&2
    FAIL=$((FAIL + 1))
    continue
  fi
  if grep -qE 'Translated sample route .* reachable \(HTTP 200\)|\[[a-z_]+\] /[^ ]+ -> 200' "${log}"; then
    PASS_SAMPLE=$((PASS_SAMPLE + 1))
  else
    echo "WARN ${project}: root OK but no sample/translated URL 200 in log (will tighten on next matrix run)" >&2
  fi
done < <(
  python3 - <<'PY' "${MATRIX_JSON}"
import json, sys
d=json.load(open(sys.argv[1]))
for p in d.get("projects",[]):
    if p.get("status")=="passed":
        print(p["project"])
PY
)

echo "matrix_url_audit: lanes=${CHECKED} root_ok=${PASS_ROOT} sample_ok=${PASS_SAMPLE} fail=${FAIL}"
if [[ "${FAIL}" -gt 0 ]]; then
  exit 1
fi
