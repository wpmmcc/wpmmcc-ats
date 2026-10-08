#!/usr/bin/env bash
# Architecture seam gate: L1 providers (server+client), translated URLs, control plane UI.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="${SCRIPT_DIR}"
REPO="$(cd "${E2E_DIR}/../../../.." && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)-$$"
REPORT_JSON="${REPORTS_DIR}/architecture-seam-${TIMESTAMP}.json"
LOG_FILE="${REPORTS_DIR}/architecture-seam-${TIMESTAMP}.log"
PHASE_TSV="$(mktemp)"

mkdir -p "${REPORTS_DIR}"
exec > >(tee -a "${LOG_FILE}") 2>&1

record_phase() {
  printf '%s\t%s\t%s\n' "$1" "$2" "${3:-}" >>"${PHASE_TSV}"
}

run_phase() {
  local name="$1"
  shift
  echo ""
  echo "=== Architecture seam: ${name} ==="
  if [[ -n "${E2E_MATRIX_EVENTS_FILE:-}" ]]; then
    printf '%s ARCH_SEAM_START name=%s\n' "$(date -Iseconds)" "${name}" >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
  fi
  local start rc
  start="$(date +%s)"
  set +e
  "$@"
  rc=$?
  set -e
  local elapsed=$(( $(date +%s) - start ))
  if [[ "${rc}" -eq 0 ]]; then
    ok "arch_seam ${name} ok (${elapsed}s)"
    record_phase "${name}" "passed" "elapsed_s=${elapsed}"
    if [[ -n "${E2E_MATRIX_EVENTS_FILE:-}" ]]; then
      printf '%s ARCH_SEAM_PASSED name=%s secs=%s\n' "$(date -Iseconds)" "${name}" "${elapsed}" >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
    fi
  else
    err "arch_seam ${name} failed rc=${rc} (${elapsed}s)"
    record_phase "${name}" "failed" "rc=${rc};elapsed_s=${elapsed}"
    if [[ -n "${E2E_MATRIX_EVENTS_FILE:-}" ]]; then
      printf '%s ARCH_SEAM_FAILED name=%s rc=%s\n' "$(date -Iseconds)" "${name}" "${rc}" >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
    fi
    return "${rc}"
  fi
}

# Local-first (AGENTS.md §0.1): WP plugin + client have no website control-plane
# dependency. Phases that hit :8787 / web app login are skipped unless the
# legacy lane is explicitly enabled.
SEAM_WEBSITE_LEGACY="${WPTSALL_USE_SERVER_CONTROL_PLANE:-0}"

# ── 1. Server L1 admin + client encrypted catalog chain ─────────────────────
if [[ "${SEAM_WEBSITE_LEGACY}" == "1" ]]; then
  run_phase "server_l1_client_chain" \
    python3 "${REPO}/scripts/smoke-admin-endpoints.py" --base "${SERVER_URL}"
else
  warn "server_l1_client_chain skipped (local-first; set WPTSALL_USE_SERVER_CONTROL_PLANE=1 for website)"
  record_phase "server_l1_client_chain" "skipped" "local_first_no_website"
fi

# ── 2. Client WebUI L1 providers tab (owned local-ui lane; no shared :8977) ──
run_phase "client_l1_providers_ui" \
  bash "${SCRIPT_DIR}/run-playwright-support-client-local-ui.sh"

# ── 3. Official site admin L1 providers page ──────────────────────────────────
if [[ "${SEAM_WEBSITE_LEGACY}" == "1" ]]; then
  run_phase "web_admin_l1_providers" \
    bash -c '
      source "'"${SCRIPT_DIR}"'/config.sh"
      if ! check_url "${WEB_APP_URL%/}/login"; then
        err "Web app not reachable at ${WEB_APP_URL}; start vite in web/source/app"
        exit 1
      fi
      if ! check_server_api "${SERVER_URL}"; then
        err "Control plane API not reachable at ${SERVER_URL}"
        exit 1
      fi
      export WEB_APP_URL
      pw_dir="'"${E2E_DIR}"'/playwright"
      cd "$pw_dir"
      npx playwright test -c playwright.web-control-plane.config.ts \
        support-web-control-plane/wp-translation-providers.spec.ts
    '
else
  warn "web_admin_l1_providers skipped (local-first; website admin UI not required)"
  record_phase "web_admin_l1_providers" "skipped" "local_first_no_website"
fi

# ── 4. Matrix translated URL audit (latest report) ───────────────────────────
LATEST_MATRIX="$(ls -t "${REPORTS_DIR}"/e2e-project-matrix-*.json 2>/dev/null | head -1 || true)"
if [[ -n "${LATEST_MATRIX}" ]]; then
  run_phase "matrix_translated_url_audit" \
    bash "${SCRIPT_DIR}/scripts/verify-matrix-translated-urls.sh" "${LATEST_MATRIX}"
else
  warn "matrix_translated_url_audit skipped: no matrix report yet"
  record_phase "matrix_translated_url_audit" "skipped" "no_matrix_report"
fi

# ── 5. Live virtual frontend deep verify (representative plugin state) ───────
run_phase "live_virtual_frontend_p2" \
  bash -c '
    export E2E_PROJECT=wptsall-content E2E_SCOPE=core-only WPTSALL_LAB=1
    source "'"${E2E_DIR}"'/config.sh"
    mkdir -p "${RUNTIME_DIR}"
    if wp_eval "'"${E2E_DIR}"'/php/resolve-virtual-frontend-target.php" >"${RUNTIME_DIR}/virtual-frontend-target.json" 2>/dev/null; then
      bash "'"${E2E_DIR}"'/verify-p2-virtual-frontend.sh"
    else
      echo "live_virtual_frontend_p2: no virtual target (skip when idle)" >&2
      exit 0
    fi
  '

# ── 6. hreflang no-double-emission (Yoast off + on) ──────────────────────────
run_phase "hreflang_no_double_emission" \
  bash "${SCRIPT_DIR}/scripts/verify-hreflang-no-double-emission.sh"

OVERALL="passed"
if grep -q $'\tfailed\t' "${PHASE_TSV}"; then
  OVERALL="failed"
fi

python3 - <<'PY' "${REPORT_JSON}" "${PHASE_TSV}" "${OVERALL}" "${TIMESTAMP}"
import json, sys
from pathlib import Path
out, tsv, overall, ts = sys.argv[1:5]
phases = []
for line in Path(tsv).read_text().splitlines():
    if not line.strip():
        continue
    parts = line.split("\t", 2)
    phases.append({"name": parts[0], "status": parts[1], "detail": parts[2] if len(parts)>2 else ""})
Path(out).write_text(json.dumps({
    "suite": "architecture-seam",
    "test_id": "TEST-LAB-ARCH-SEAM-001",
    "timestamp": ts,
    "status": overall,
    "phases": phases,
}, indent=2) + "\n")
print(f"Wrote {out} status={overall}")
PY

rm -f "${PHASE_TSV}"
if [[ "${OVERALL}" != "passed" ]]; then
  exit 1
fi
ok "Architecture seam gate PASSED — ${REPORT_JSON}"
