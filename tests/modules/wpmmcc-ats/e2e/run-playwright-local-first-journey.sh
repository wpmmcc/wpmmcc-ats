#!/usr/bin/env bash
# P1-J local-first journey entry: WP plugin (+ optional client) page journeys.
# Does NOT require website control plane :8787.
# Contrast: run-playwright-journey-three-system.sh --legacy-only (public-auth / website).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Stage 8 targets need a plugin project from project-specs.json.
# core-content is the orchestrator label; journeys use the wptsall-content spec.
case "${E2E_PROJECT:-core-content}" in
  core-content|'')
    export E2E_PROJECT=wptsall-content
    ;;
esac
export E2E_PROJECT="${E2E_PROJECT:-wptsall-content}"

# Reuse Stage 8 plugin-content journeys (targets generated from current E2E_PROJECT).
exec bash "${SCRIPT_DIR}/stages/08-plugin-journeys.sh" "$@"
