#!/usr/bin/env bash
# T1 subsite matrix: enable Multisite (if needed) + topology deep smoke.
# Covers the 6 representative content plugins seeded onto blog ≥2.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-subsite-matrix.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"

export WPTSALL_LAB=1

bash "${SCRIPT_DIR}/scripts/lab-enable-multisite-t1.sh"
bash "${SCRIPT_DIR}/scripts/lab-subsite-topology-deep-smoke.sh"
