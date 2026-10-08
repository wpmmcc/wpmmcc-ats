#!/usr/bin/env bash
# Parallel seed-plan scheduler for stages/03-data-seed.sh.
#
# Why: the full gate runs seed plans E,A,B,C,D strictly sequentially; each
# plan spawns many `wp eval-file` calls (PHP startup + WP bootstrap per
# call), which is CPU-idle and takes ~40 minutes for the full set. Plans
# target disjoint plugins (A=WooCommerce/bbPress/BuddyPress/EDD/...,
# C=Sensei, D=LearnPress/LifterLMS), so their DB writes do not overlap.
#
# Ordering guarantees (conservative):
#   - The FIRST plan in the list runs alone first. e2e_seed_plans() always
#     puts Plan E (WordPress core baseline) first, and later plans depend on
#     that baseline existing.
#   - Remaining plans keep their declared relative order but are batched:
#     groups of at most SEED_PARALLEL_JOBS (default 4) run concurrently, and
#     each group fully completes before the next one starts. For E,A,B,C,D
#     with jobs=4 this yields E -> [A,B,C,D], preserving A<B<C<D.
#   - Inside one plan, pre -> seed -> post stay strictly sequential.
#
# Escape hatch: SEED_SEQUENTIAL=1 restores the fully serial legacy behaviour
# (the caller skips this script entirely in that case).
#
# Usage (from stages/03-data-seed.sh):
#   bash scripts/run-seed-plans-parallel.sh E A B C D
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# scripts/ → e2e/
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# e2e/ → wpmmcc-ats → modules → tests → repo
REPO_ROOT="$(cd "${E2E_DIR}/../../../.." && pwd)"
# shellcheck source=/dev/null
source "${E2E_DIR}/config.sh"

# Same script the serial loop in stages/03-data-seed.sh drives.
SEED_SCRIPT="${REPO_ROOT}/tests/modules/wpmmcc-ats/seeding/run-plan.php"
if [ ! -f "${SEED_SCRIPT}" ]; then
  echo "[seed-parallel] Seeding script not found: ${SEED_SCRIPT}" >&2
  exit 2
fi

if [[ $# -eq 0 ]]; then
  echo "[seed-parallel] no plans requested; nothing to do" >&2
  exit 0
fi

PLANS=("$@")
# MEASURED 2026-09-02 (gate5 RG-CORE): with jobs=2 the E,A,B,C,D set took
# ~1481s (E 608s serial -> [A,B] 435s -> [C,D] 438s). Raising the default to 4
# runs A,B,C,D as one batch after the core baseline: E 608s + max(A..D) ~440s
# ≈ 1050s (-430s per core gate). Plans target disjoint plugins, so their DB
# writes do not overlap; [A,B] parallel has long been proven in gates.
JOBS="${SEED_PARALLEL_JOBS:-4}"
if ! [[ "${JOBS}" =~ ^[0-9]+$ ]] || [[ "${JOBS}" -lt 1 ]]; then
  JOBS=4
fi

LOG_DIR="${RUNTIME_DIR}/seed-parallel-$(date +%Y%m%d-%H%M%S)"
mkdir -p "${LOG_DIR}"

# run_one_plan <plan> <log-file>
# Runs one plan's full pre -> seed -> post pipeline exactly like the serial
# loop in stages/03-data-seed.sh, mirroring its per-step messages.
run_one_plan() {
  local plan="$1"
  local log="$2"
  {
    echo "[seed-parallel] Plan ${plan}: running pre-setup..."
    if wp_eval "${SEED_SCRIPT}" "${plan}" pre; then
      echo "[seed-parallel] Plan ${plan} pre-setup completed"
    else
      echo "[seed-parallel] Plan ${plan} pre-setup FAILED"
      exit 1
    fi
    echo "[seed-parallel] Plan ${plan}: seeding..."
    if wp_eval "${SEED_SCRIPT}" "${plan}" seed; then
      echo "[seed-parallel] Plan ${plan} seeded"
    else
      echo "[seed-parallel] Plan ${plan} seeding FAILED"
      exit 1
    fi
    echo "[seed-parallel] Plan ${plan}: running post-setup..."
    if wp_eval "${SEED_SCRIPT}" "${plan}" post; then
      echo "[seed-parallel] Plan ${plan} post-setup completed"
    else
      echo "[seed-parallel] Plan ${plan} post-setup FAILED"
      exit 1
    fi
  } >>"${log}" 2>&1
}

run_group() {
  local -a group=("$@")
  local pids=()
  local plans=()
  local failed=0

  if [[ "${#group[@]}" -eq 1 ]]; then
    # Single plan: keep output inline so the stage log looks familiar.
    if ! run_one_plan "${group[0]}" "${LOG_DIR}/plan-${group[0]}.log"; then
      cat "${LOG_DIR}/plan-${group[0]}.log" >&2
      return 1
    fi
    cat "${LOG_DIR}/plan-${group[0]}.log"
    return 0
  fi

  info "Seeding plans in parallel: ${group[*]} (jobs=${JOBS})"
  local i
  for i in "${!group[@]}"; do
    local plan="${group[$i]}"
    local log="${LOG_DIR}/plan-${plan}.log"
    : >"${log}"
    run_one_plan "${plan}" "${log}" &
    pids+=("$!")
    plans+=("${plan}")
  done

  local idx
  for idx in "${!pids[@]}"; do
    if ! wait "${pids[$idx]}"; then
      failed=1
      echo "[seed-parallel] Plan ${plans[$idx]} FAILED (log: ${LOG_DIR}/plan-${plans[$idx]}.log)" >&2
    else
      echo "[seed-parallel] Plan ${plans[$idx]} done"
    fi
  done

  # Surface every branch log inline (success or failure) so the gate log
  # stays self-contained for evidence/auditing.
  for plan in "${plans[@]}"; do
    echo "----- [seed-parallel] plan ${plan} log -----"
    cat "${LOG_DIR}/plan-${plan}.log"
  done

  return "${failed}"
}

# Group 1: the first plan runs alone (WordPress core baseline dependency).
info "Plan ${PLANS[0]}: running first (core baseline)..."
if ! run_group "${PLANS[0]}"; then
  exit 1
fi

# Remaining plans: chunk into groups of at most JOBS, preserving order.
remaining=("${PLANS[@]:1}")
if [[ "${#remaining[@]}" -gt 0 ]]; then
  for ((start = 0; start < ${#remaining[@]}; start += JOBS)); do
    group=("${remaining[@]:start:JOBS}")
    if ! run_group "${group[@]}"; then
      exit 1
    fi
  done
fi

ok "All seed plans completed (parallel scheduler, logs: ${LOG_DIR})"
