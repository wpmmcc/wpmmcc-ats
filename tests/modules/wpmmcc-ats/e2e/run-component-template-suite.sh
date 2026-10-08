#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"

WITH_SYNC_APPLY=0
WITH_BUSINESS_GATE=0
BUSINESS_PROJECT="${E2E_COMPONENT_TEMPLATE_GATE_PROJECT:-core-content}"
BUSINESS_SCOPE="${E2E_COMPONENT_TEMPLATE_GATE_SCOPE:-core-only}"
COMPONENT_LANE="${E2E_COMPONENT_TEMPLATE_LANE:-release-required}"
HEADED=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --sync-apply)
      WITH_SYNC_APPLY=1
      shift
      ;;
    --with-business-gate)
      WITH_BUSINESS_GATE=1
      shift
      ;;
    --business-project)
      BUSINESS_PROJECT="$2"
      shift 2
      ;;
    --business-scope)
      BUSINESS_SCOPE="$2"
      shift 2
      ;;
    --lane)
      COMPONENT_LANE="$2"
      shift 2
      ;;
    --headed)
      HEADED="--headed"
      shift
      ;;
    --help|-h)
      echo "Usage: bash tests/modules/wpmmcc-ats/e2e/run-component-template-suite.sh [--sync-apply] [--with-business-gate] [--business-project core-content] [--business-scope core-only] [--lane baseline|release-required|all-nonmutating] [--headed]"
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      echo "Usage: bash tests/modules/wpmmcc-ats/e2e/run-component-template-suite.sh [--sync-apply] [--with-business-gate] [--business-project core-content] [--business-scope core-only] [--lane baseline|release-required|all-nonmutating] [--headed]"
      exit 1
      ;;
  esac
done

ensure_dirs

print_stage "CT-SUITE" "Component Template Suite"
START=$(stage_start_time)

if [ "$WITH_SYNC_APPLY" -eq 1 ]; then
  bash "${SCRIPT_DIR}/run-component-template-ct0-sync.sh" --apply
fi

bash "${SCRIPT_DIR}/run-component-template-lane.sh" "${COMPONENT_LANE}"

if [ "$WITH_BUSINESS_GATE" -eq 1 ]; then
  info "Running business gate after component-template suite..."
  E2E_PROJECT="$BUSINESS_PROJECT" E2E_SCOPE="$BUSINESS_SCOPE" \
    bash "${SCRIPT_DIR}/run.sh" ${HEADED}
fi

stage_elapsed "$START"
ok "Component template suite completed"
