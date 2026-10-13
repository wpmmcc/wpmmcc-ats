#!/usr/bin/env bash
# Capability-family runner entry (ISS T3 / W4).
# Usage: bash run-family.sh --family core,seo [--jobs 2]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
FAMILY="core"
JOBS="${E2E_JOBS:-1}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --family) FAMILY="$2"; shift 2 ;;
    --jobs) JOBS="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

map_family() {
  case "$1" in
    core) echo "core-content" ;;
    seo) echo "wordpress-seo-content" ;;
    commerce) echo "woocommerce-content" ;;
    lms) echo "learnpress-content" ;;
    *) echo "$1" ;;
  esac
}

IFS=',' read -r -a FAMILIES <<< "${FAMILY}"
PROJECTS=()
for f in "${FAMILIES[@]}"; do
  f="$(echo "$f" | xargs)"
  [[ -z "$f" ]] && continue
  PROJECTS+=("$(map_family "$f")")
done

if [[ ${#PROJECTS[@]} -eq 0 ]]; then
  echo "No projects resolved from --family ${FAMILY}" >&2
  exit 2
fi

echo "[family] families=${FAMILY} projects=${PROJECTS[*]} jobs=${JOBS}"
export E2E_RUN_ID="${E2E_RUN_ID:-family-$(date +%Y%m%d-%H%M%S)}"
bash "${E2E_DIR}/scripts/write-run-manifest.sh" running || true

# Prefer project matrix with explicit project list if supported.
if [[ -x "${E2E_DIR}/run-project-matrix.sh" ]]; then
  PROJECT_CSV="$(IFS=','; echo "${PROJECTS[*]}")"
  bash "${E2E_DIR}/run-project-matrix.sh" --projects "${PROJECT_CSV}" --jobs "${JOBS}"
else
  echo "run-project-matrix.sh missing" >&2
  exit 1
fi
