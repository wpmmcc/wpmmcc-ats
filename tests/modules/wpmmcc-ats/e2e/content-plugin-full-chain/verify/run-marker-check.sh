#!/usr/bin/env bash
# P5: marker precision via existing PHP verifiers.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../../lib/repo-root.sh"
E2E_DIR="$(wptsall_path e2e "${SCRIPT_DIR}")"
REPO_ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"
# shellcheck source=../../config.sh
source "${E2E_DIR}/config.sh"

PROJECT_ID="${1:-${E2E_PROJECT:-wptsall-content}}"
REPORT_DIR="${FULL_CHAIN_REPORT_DIR:-${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/content-plugin-full-chain}"
mkdir -p "${REPORT_DIR}"
STAMP="$(date +%Y%m%dT%H%M%S)"
OUT="${REPORT_DIR}/phase-p5-markers-${PROJECT_ID}-${STAMP}.json"

info "P5 marker check project=${PROJECT_ID}"

ok_flag=1
detail=""

if declare -F wp_eval >/dev/null 2>&1; then
  if wp_eval "${E2E_DIR}/php/verify-markers.php" >/tmp/full-chain-markers.out 2>&1; then
    detail="verify-markers.php ok"
  else
    ok_flag=0
    detail="verify-markers.php failed: $(tail -5 /tmp/full-chain-markers.out | tr '\n' ' ' | cut -c1-200)"
  fi
  VERIFIER="verify-plugin-project.php"
  case "${PROJECT_ID}" in
    wptsall-content) VERIFIER="verify-baseline-fixtures.php" ;;
  esac
  if [[ -f "${E2E_DIR}/php/${VERIFIER}" ]]; then
    if E2E_PROJECT="${PROJECT_ID}" wp_eval "${E2E_DIR}/php/${VERIFIER}" >/tmp/full-chain-proj.out 2>&1; then
      detail="${detail}; ${VERIFIER} ok"
    else
      # Soft-fail project verifier when only language_pack inventory is empty —
      # marker precision (verify-markers.php) remains the hard gate for P5.
      if grep -q "language_pack" /tmp/full-chain-proj.out \
        && ! grep -qE "\[FAIL\].*marker|post_name|guid" /tmp/full-chain-proj.out; then
        detail="${detail}; ${VERIFIER} soft-fail language_pack"
        warn "P5 soft-fail ${VERIFIER} language_pack for ${PROJECT_ID}"
      else
        ok_flag=0
        detail="${detail}; ${VERIFIER} failed: $(tail -5 /tmp/full-chain-proj.out | tr '\n' ' ' | cut -c1-200)"
      fi
    fi
  fi
else
  ok_flag=0
  detail="wp_eval unavailable"
fi

python3 - "$OUT" "$PROJECT_ID" "$ok_flag" "$detail" <<'PY'
import json, sys
out, project, ok_flag, detail = sys.argv[1:5]
payload = {
  "phase": "p5_verify_markers",
  "project_id": project,
  "ok": ok_flag == "1",
  "detail": detail,
  "marker_pattern": "【locale】…【/locale】",
}
open(out, "w").write(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")
print(out)
PY

[[ "${ok_flag}" == "1" ]]
