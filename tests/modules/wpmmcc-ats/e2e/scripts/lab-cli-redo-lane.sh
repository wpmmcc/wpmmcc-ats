#!/usr/bin/env bash
# Re-run one matrix lane after parent Task fix (never source run-project-matrix.sh).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ $# -lt 1 ]]; then
  echo "usage: lab-cli-redo-lane.sh <plugin-project> [slot-a..slot-n] [--with-journeys]" >&2
  exit 1
fi

PROJECT="$1"
shift
SLOT=""
WITH_JOURNEYS=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    slot-[a-n]) SLOT="$1"; shift ;;
    --with-journeys) WITH_JOURNEYS=1; shift ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

# shellcheck source=/dev/null
source "${E2E_DIR}/config.sh"
export WPTSALL_LAB=1
export E2E_MATRIX_PARALLEL=1
export DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
export E2E_PROJECT="${PROJECT}"
export E2E_SCOPE="${E2E_SCOPE:-full}"
export E2E_MATRIX_EVENTS_FILE="${E2E_MATRIX_EVENTS_FILE}"
if [[ -n "${SLOT}" ]]; then
  export E2E_SLOT="${SLOT}"
fi
REDO_ARGS=(--skip-seed)
[[ "$WITH_JOURNEYS" -eq 1 ]] && REDO_ARGS+=(--with-plugin-journeys)
echo "REDO_LANE project=${PROJECT} slot=${E2E_SLOT:-auto} journeys=${WITH_JOURNEYS} $(date -Iseconds)"
cd "${REPO_ROOT}"
exec env WPTSALL_LAB=1 E2E_MATRIX_PARALLEL=1 bash "${E2E_DIR}/run.sh" "${REDO_ARGS[@]}"
