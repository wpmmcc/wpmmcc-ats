#!/usr/bin/env bash
# Run the three P0-EV-03 focused automatic lanes in parallel.
#
# Why: the gates run content-surfaces (83s), consistency (42s) and
# topology/time (175s) sequentially (~5 min wall clock), while each lane
# already owns isolated infrastructure — lib/automatic-lane.sh allocates
# fresh kernel-assigned ports per run and starts its own client, mock
# provider and control-plane canary, then tears them down. Nothing is
# shared between lanes, so running them concurrently is safe and cuts the
# wall time to roughly the slowest lane (~3 min).
#
# Behaviour:
#   - All three lanes start in the background; their full output is kept in
#     per-lane log files under reports/automatic-lanes-parallel-<stamp>/.
#   - Each lane's log is replayed into stdout after it finishes so the gate
#     log stays self-contained for evidence.
#   - Exit code: 0 iff every lane exited 0. A failing lane does NOT stop the
#     other lanes (they are independent evidence, and the caller decides
#     gate failure semantics).
#
# Usage (from run-release-gate.sh, replacing the three sequential run_step
# calls):
#   bash scripts/run-auto-lanes-parallel.sh
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# scripts/ → e2e/ (NOT repo root; ../../../../.. was a layout regression)
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${E2E_DIR}/../../../.." && pwd)"

LANES=(
  "content-surfaces:${E2E_DIR}/run-automatic-content-surfaces-gate.sh"
  "consistency:${E2E_DIR}/run-automatic-consistency-gate.sh"
  "topology-time:${E2E_DIR}/run-automatic-topology-time-gate.sh"
)

for entry in "${LANES[@]}"; do
  script="${entry#*:}"
  if [[ ! -f "${script}" ]]; then
    echo "[lanes-parallel] ERROR: lane script missing: ${script}" >&2
    exit 127
  fi
done

STAMP="$(date +%Y%m%d-%H%M%S)"
LOG_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/automatic-lanes-parallel-${STAMP}"
mkdir -p "${LOG_DIR}"

pids=()
names=()
logs=()

echo "[lanes-parallel] starting ${#LANES[@]} lanes in parallel (logs: ${LOG_DIR})"
for entry in "${LANES[@]}"; do
  name="${entry%%:*}"
  script="${entry#*:}"
  log="${LOG_DIR}/${name}.log"
  echo "[lanes-parallel] starting lane ${name}: ${script}"
  (
    # Each lane sources automatic-lane.sh, picks its own kernel-assigned
    # ports and owns its infrastructure lifecycle; no shared state.
    WPTSALL_LAB="${WPTSALL_LAB:-1}" bash "${script}"
  ) >"${log}" 2>&1 &
  pids+=("$!")
  names+=("${name}")
  logs+=("${log}")
done

failed=0
for i in "${!pids[@]}"; do
  if wait "${pids[$i]}"; then
    echo "[lanes-parallel] lane ${names[$i]} PASSED"
  else
    echo "[lanes-parallel] lane ${names[$i]} FAILED (exit=$?, log: ${logs[$i]})" >&2
    failed=1
  fi
done

for i in "${!logs[@]}"; do
  echo "════════ [lanes-parallel] ${names[$i]} log ════════"
  cat "${logs[$i]}"
done

if [[ "${failed}" -ne 0 ]]; then
  echo "[lanes-parallel] FAILED (one or more lanes failed; logs: ${LOG_DIR})" >&2
  exit 1
fi
echo "[lanes-parallel] all ${#LANES[@]} lanes passed (logs: ${LOG_DIR})"
