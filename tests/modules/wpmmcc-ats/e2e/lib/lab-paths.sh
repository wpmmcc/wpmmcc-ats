#!/usr/bin/env bash
# Canonical Lab paths — source from orchestration scripts.
# shellcheck disable=SC2034
_lab_paths_script="${BASH_SOURCE[0]}"
_lab_paths_dir="$(cd "$(dirname "$_lab_paths_script")" && pwd)"
# shellcheck source=/dev/null
source "${_lab_paths_dir}/../../../../lib/repo-root.sh"
REPO_ROOT="${REPO_ROOT:-$(wptsall_repo_root "${_lab_paths_dir}")}"
E2E_DIR="${E2E_DIR:-$(wptsall_path e2e "${_lab_paths_dir}")}"
E2E_LAB_LOGDIR="${E2E_LAB_LOGDIR:-${E2E_DIR}/lab-logs}"
mkdir -p "${E2E_LAB_LOGDIR}"
export REPO_ROOT E2E_DIR E2E_LAB_LOGDIR
export E2E_MATRIX_EVENTS_FILE="${E2E_MATRIX_EVENTS_FILE:-${E2E_LAB_LOGDIR}/matrix-events.log}"
