#!/usr/bin/env bash
# Stage 8: Post-translation user journeys (Playwright — page completeness, not HTTP-only)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 8 "Plugin User Journeys (Playwright)"
START=$(stage_start_time)

mkdir -p "${RUNTIME_DIR}"

PLAYWRIGHT_DIR="${E2E_DIR}/playwright"
info "Generating journey targets for ${E2E_PROJECT}..."
if ! wp_eval "${E2E_DIR}/php/generate-plugin-journey-targets.php"; then
  abort "generate-plugin-journey-targets.php failed"
fi

TARGETS="${RUNTIME_DIR}/plugin-journey-targets.json"
if [[ ! -f "${TARGETS}" ]]; then
  abort "missing ${TARGETS}"
fi

info "Waiting for WordPress before Playwright journeys..."
for _i in $(seq 1 30); do
  if curl -sf -o /dev/null --max-time 3 "${WP_URL}/"; then
    break
  fi
  sleep 2
done
if ! curl -sf -o /dev/null --max-time 5 "${WP_URL}/"; then
  abort "WordPress not reachable at ${WP_URL} before Stage 8 Playwright"
fi

WP_ADMIN_USER="${WP_ADMIN_USER:-e2esmokeadmin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
export WP_ADMIN_USER WP_ADMIN_PASS

info "Ensuring WP admin account ${WP_ADMIN_USER} for journeys..."
if ! e2e_ensure_wp_admin_account; then
  abort "Failed to ensure WP admin account for Stage 8"
fi

info "Running Playwright plugin-content-journeys..."
(
  cd "${PLAYWRIGHT_DIR}"
  export WP_BASE="${WP_URL}"
  export E2E_RUNTIME_DIR="${RUNTIME_DIR}"
  export WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-wptsall-e2e-${E2E_SLOT:-shared}}"
  export WP_ADMIN_USER
  export WP_ADMIN_PASS
  # Stage-8-only / skip-translate waves often lack filled virtual pages.
  export FULL_CHAIN_JOURNEY_SOFT_404="${FULL_CHAIN_JOURNEY_SOFT_404:-${E2E_JOURNEY_SOFT_404:-1}}"
  export E2E_JOURNEY_SOFT_404="${E2E_JOURNEY_SOFT_404:-1}"
  npx playwright test -c playwright.plugin-content-journeys.config.ts \
    plugin-content-journeys/content-plugin-journey.gate.e2e.spec.ts
)

ok "Plugin user journeys passed"
stage_elapsed "$START"
