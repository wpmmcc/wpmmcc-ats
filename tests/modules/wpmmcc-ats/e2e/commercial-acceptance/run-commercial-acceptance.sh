#!/usr/bin/env bash
# Commercial acceptance orchestrator (G0–G5) — see tasks/client2/21-*.md
#
# Usage:
#   CA_MODE=dry-run|daily|rc|release \
#     bash tests/modules/wpmmcc-ats/e2e/commercial-acceptance/run-commercial-acceptance.sh
#
# Env:
#   CA_SKIP_G0=1 … CA_SKIP_G5=1
#   CA_SKIP_G4_TAURI=1          skip Desktop WebView smoke (binary may be absent)
#   CA_KEEP_GOING=1
#   FULL_CHAIN_PROJECTS=…       override G3
#   COMMERCIAL_UI_ONLY=1        expand UI-only lint (forced on rc/release)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0" 2>/dev/null || echo "$0")")" && pwd)"
# shellcheck source=../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../lib/repo-root.sh"
REPO_ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"
E2E_DIR="$(wptsall_path e2e "${SCRIPT_DIR}")"
DESKTOP_E2E="${REPO_ROOT}/tests/modules/client-desktop/tests/e2e"
DESKTOP_BIN="${WPTSALL_DESKTOP_APP:-${REPO_ROOT}/client-desktop/src-tauri/target/debug/wptsall-desktop}"

MODE="${CA_MODE:-daily}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/commercial-acceptance"
REPORT_DIR="${REPORT_ROOT}/run-${STAMP}-${MODE}"
mkdir -p "${REPORT_DIR}"

log() { printf '[commercial] %s\n' "$*"; }
ok() { printf '[commercial] ✓ %s\n' "$*"; }
err() { printf '[commercial] ✗ %s\n' "$*" >&2; }

FAILED=0
GATE_RESULTS=()

record() {
  local gate="$1" status="$2" detail="${3:-}"
  GATE_RESULTS+=("${gate}|${status}|${detail}")
  if [[ "${status}" == "fail" ]]; then
    FAILED=1
  fi
}

run_logged() {
  local gate="$1"
  shift
  local skip_var="CA_SKIP_${gate}"
  if [[ "${!skip_var:-0}" == "1" ]]; then
    log "skip ${gate}"
    record "${gate}" "skip" ""
    return 0
  fi
  log "▶ ${gate}"
  local logf="${REPORT_DIR}/${gate}.log"
  set +e
  "$@" >"${logf}" 2>&1
  local ec=$?
  set -e
  if [[ "${ec}" -eq 0 ]]; then
    ok "${gate}"
    record "${gate}" "pass" "${logf}"
    return 0
  fi
  err "${gate} FAILED (exit ${ec}) — ${logf}"
  record "${gate}" "fail" "${logf}"
  if [[ "${CA_KEEP_GOING:-0}" != "1" ]]; then
    return "${ec}"
  fi
  return 0
}

case "${MODE}" in
  dry-run)
    export CA_SKIP_G0=1 CA_SKIP_G1=1 CA_SKIP_G2=1 CA_SKIP_G3=1 CA_SKIP_G4=1 CA_SKIP_G5=1
    export CA_SKIP_G4_TAURI=1 CA_SKIP_G5_UI=1
    export COMMERCIAL_UI_ONLY="${COMMERCIAL_UI_ONLY:-1}"
    ;;
  daily)
    export FULL_CHAIN_PROJECTS="${FULL_CHAIN_PROJECTS:-default}"
    export COMMERCIAL_UI_ONLY="${COMMERCIAL_UI_ONLY:-1}"
    ;;
  rc)
    export FULL_CHAIN_PROJECTS="${FULL_CHAIN_PROJECTS:-wptsall-content,woocommerce-content,bbpress-content,tutor-content,elementor-content,wordpress-seo-content,advanced-custom-fields-content,give-content}"
    export COMMERCIAL_UI_ONLY=1
    export FULL_CHAIN_COMMERCIAL=1
    ;;
  release)
    export FULL_CHAIN_PROJECTS="${FULL_CHAIN_PROJECTS:-all}"
    export FULL_CHAIN_MENU_SEO=1
    export FULL_CHAIN_RELEASE=1
    export FULL_CHAIN_COMMERCIAL=1
    export COMMERCIAL_UI_ONLY=1
    ;;
  *)
    err "unknown CA_MODE=${MODE} (dry-run|daily|rc|release)"
    exit 2
    ;;
esac

log "mode=${MODE} report=${REPORT_DIR}"
echo "${MODE}" >"${REPORT_DIR}/mode.txt"
printf '%s\n' "${FULL_CHAIN_PROJECTS:-}" >"${REPORT_DIR}/projects.txt"

# Always: static U1–U7 coverage (requires real fill/click + SIM-10)
log "▶ UI_FORMS (U1–U7 action + SIM-10 coverage)"
if python3 "${SCRIPT_DIR}/lib/check-ui-form-coverage.py" \
  --repo "${REPO_ROOT}" \
  --out "${REPORT_DIR}/ui-form-coverage.json"; then
  ok "UI_FORMS"
  record "UI_FORMS" "pass" "${REPORT_DIR}/ui-form-coverage.json"
else
  err "UI_FORMS FAILED"
  record "UI_FORMS" "fail" "${REPORT_DIR}/ui-form-coverage.json"
  FAILED=1
  [[ "${CA_KEEP_GOING:-0}" == "1" ]] || true
fi

# UI-only lint: expanded set.
# STRICT = journeys that must never use bindSite(request) (commercial UI claim).
# SOFT   = journeys that may API-seed but must still have DOM fill/click.
UI_ONLY_STRICT=(
  "${E2E_DIR}/playwright/client-ui-setup/pre-wp-bind.journey.spec.ts"
  "${E2E_DIR}/playwright/simulation/sim-01-ats-onboarding.spec.ts"
  "${E2E_DIR}/playwright/simulation/sim-06-multi-config-list-filter.spec.ts"
  "${E2E_DIR}/playwright/simulation/sim-10-commercial-forms-matrix.spec.ts"
)
UI_ONLY_SOFT=(
  "${E2E_DIR}/playwright/simulation/sim-02-wpmmcc-admin-pairing.spec.ts"
  "${E2E_DIR}/playwright/simulation/sim-07-ats-auto-translate-immediate.spec.ts"
  "${E2E_DIR}/playwright/simulation/sim-09-visual-ui-audit.spec.ts"
)

if [[ "${COMMERCIAL_UI_ONLY:-0}" == "1" ]]; then
  log "▶ UI_ONLY_LINT (strict+soft expanded)"
  : >"${REPORT_DIR}/ui-only-lint.log"
  UI_STRICT_ARGS=()
  for f in "${UI_ONLY_STRICT[@]}"; do
    UI_STRICT_ARGS+=(--file "${f}")
  done
  UI_SOFT_ARGS=()
  for f in "${UI_ONLY_SOFT[@]}"; do
    UI_SOFT_ARGS+=(--file "${f}")
  done
  UI_LINT_OK=1
  if ! python3 "${SCRIPT_DIR}/lib/check-ui-only-journey.py" --strict \
    "${UI_STRICT_ARGS[@]}" >>"${REPORT_DIR}/ui-only-lint.log" 2>&1; then
    UI_LINT_OK=0
  fi
  if ! python3 "${SCRIPT_DIR}/lib/check-ui-only-journey.py" \
    "${UI_SOFT_ARGS[@]}" >>"${REPORT_DIR}/ui-only-lint.log" 2>&1; then
    UI_LINT_OK=0
  fi
  if [[ "${UI_LINT_OK}" == "1" ]]; then
    ok "UI_ONLY_LINT"
    record "UI_ONLY_LINT" "pass" "${REPORT_DIR}/ui-only-lint.log"
  else
    err "UI_ONLY_LINT FAILED"
    record "UI_ONLY_LINT" "fail" "${REPORT_DIR}/ui-only-lint.log"
    FAILED=1
  fi
fi

if [[ "${MODE}" != "dry-run" ]]; then
  run_logged G0 bash -c "
    set -euo pipefail
    cd '${REPO_ROOT}/client-wpplugin/source' && cargo test --lib
    cd '${REPO_ROOT}/client-wpplugin/source/frontend' && npm run test:unit
    cd '${REPO_ROOT}/client-desktop/frontend' && npm test -- --run
  " || [[ "${CA_KEEP_GOING:-0}" == "1" ]]

  run_logged G1 bash -c "
    set -euo pipefail
    cd '${E2E_DIR}/playwright' && npm run test:simulation
  " || [[ "${CA_KEEP_GOING:-0}" == "1" ]]

  # G2 is curl/API orchestration against a live Lab site — NOT UI evidence.
  run_logged G2 bash -c "
    set -euo pipefail
    echo 'G2_NOTE=non_ui_api_roundtrip' >&2
    cd '${REPO_ROOT}' && WPTSALL_LAB=1 bash '${E2E_DIR}/run-client-content-roundtrip.sh'
  " || [[ "${CA_KEEP_GOING:-0}" == "1" ]]
  # Annotate G2 detail if it passed
  if [[ -f "${REPORT_DIR}/G2.log" ]] && grep -q 'G2_NOTE=non_ui_api_roundtrip' "${REPORT_DIR}/G2.log" 2>/dev/null; then
    :
  fi

  run_logged G3 bash -c "
    set -euo pipefail
    cd '${REPO_ROOT}' && WPTSALL_LAB=1 \
      FULL_CHAIN_COMMERCIAL='${FULL_CHAIN_COMMERCIAL:-0}' \
      FULL_CHAIN_PROJECTS='${FULL_CHAIN_PROJECTS}' \
      FULL_CHAIN_MENU_SEO='${FULL_CHAIN_MENU_SEO:-0}' \
      FULL_CHAIN_RELEASE='${FULL_CHAIN_RELEASE:-0}' \
      bash '${E2E_DIR}/content-plugin-full-chain/run-gate.sh'
  " || [[ "${CA_KEEP_GOING:-0}" == "1" ]]

  # G4a: Desktop↔WebUI API/Vitest parity (not WebView)
  run_logged G4 bash "${DESKTOP_E2E}/run-auto-translate-parity.sh" \
    || [[ "${CA_KEEP_GOING:-0}" == "1" ]]

  # G4_TAURI: real Desktop WebView smoke (skip cleanly if binary missing)
  if [[ "${CA_SKIP_G4_TAURI:-0}" == "1" ]]; then
    log "skip G4_TAURI"
    record "G4_TAURI" "skip" "CA_SKIP_G4_TAURI"
  elif [[ ! -x "${DESKTOP_BIN}" ]]; then
    log "skip G4_TAURI (no desktop binary at ${DESKTOP_BIN})"
    record "G4_TAURI" "skip" "no-desktop-binary"
  else
    run_logged G4_TAURI bash "${DESKTOP_E2E}/tauri-smoke.sh" \
      || [[ "${CA_KEEP_GOING:-0}" == "1" ]]
    # G4_TAURI_FORMS: U1–U7 form fill/click in real Desktop WebView
    mkdir -p "${REPORT_DIR}/desktop-forms"
    WPTSALL_DESKTOP_FORMS_REPORT="${REPORT_DIR}/desktop-forms/u1u7.json" \
      run_logged G4_TAURI_FORMS bash "${DESKTOP_E2E}/tauri-commercial-forms-smoke.sh" \
      || [[ "${CA_KEEP_GOING:-0}" == "1" ]]
  fi

  # G5: real multi-site identity topology (Lab :9083 + slot-g) — not "G1 green ⇒ pass"
  # Bound by CA_G5_TIMEOUT_SEC (default 12m) so a stuck T-ID case cannot hang daily forever.
  G5_TIMEOUT="${CA_G5_TIMEOUT_SEC:-720}"
  run_logged G5 bash -c "
    set -euo pipefail
    cd '${REPO_ROOT}' && WPTSALL_LAB=1 \
      timeout --signal=TERM --kill-after=30 '${G5_TIMEOUT}' \
      bash '${E2E_DIR}/run-identity-chain-gate.sh'
  " || [[ "${CA_KEEP_GOING:-0}" == "1" ]]

  # G5_UI: ≥3 live sites bound via Client Sites UI + ATS Tasks pipeline
  if [[ "${CA_SKIP_G5_UI:-0}" == "1" ]]; then
    log "skip G5_UI"
    record "G5_UI" "skip" "CA_SKIP_G5_UI"
  else
    run_logged G5_UI bash "${SCRIPT_DIR}/run-multi-site-client-deep.sh" \
      || [[ "${CA_KEEP_GOING:-0}" == "1" ]]
  fi
fi

# Collect evidence triad paths for summary
EVIDENCE_JOBS=""
EVIDENCE_WP=""
EVIDENCE_UI="${REPORT_DIR}/ui-form-coverage.json"
if [[ -f "${REPORT_DIR}/G2.log" ]]; then
  EVIDENCE_JOBS="$(grep -oE '/[^ ]+wptsall\.db' "${REPORT_DIR}/G2.log" 2>/dev/null | head -1 || true)"
fi
if [[ -f "${REPORT_DIR}/G3.log" ]]; then
  EVIDENCE_WP="$(grep -oE 'tests/reports/[^ ]+' "${REPORT_DIR}/G3.log" 2>/dev/null | head -1 || true)"
fi
if [[ -f "${REPORT_DIR}/G5.log" ]]; then
  G5_REPORT="$(grep -oE 'tests/reports/e2e/wpmmcc-ats/identity-chain-gate[^ ]*' "${REPORT_DIR}/G5.log" 2>/dev/null | head -1 || true)"
fi

# Write gates tsv then summary.json
: >"${REPORT_DIR}/_gates.tsv"
for row in "${GATE_RESULTS[@]}"; do
  printf '%s\n' "${row}" >>"${REPORT_DIR}/_gates.tsv"
done

# Patch G2 detail to declare non-UI
python3 - "${REPORT_DIR}/_gates.tsv" <<'PY' || true
from pathlib import Path
import sys
p = Path(sys.argv[1])
lines = []
for line in p.read_text().splitlines():
    parts = line.split("|", 2)
    while len(parts) < 3:
        parts.append("")
    if parts[0] == "G2" and parts[1] == "pass":
        parts[2] = (parts[2] + ";non_ui_api_roundtrip").lstrip(";")
    lines.append("|".join(parts))
p.write_text("\n".join(lines) + ("\n" if lines else ""))
PY

FAILED="${FAILED}" MODE="${MODE}" STAMP="${STAMP}" REPORT_DIR="${REPORT_DIR}" \
  COMMERCIAL_UI_ONLY="${COMMERCIAL_UI_ONLY:-0}" \
  FULL_CHAIN_PROJECTS="${FULL_CHAIN_PROJECTS:-}" \
  EVIDENCE_JOBS="${EVIDENCE_JOBS:-}" \
  EVIDENCE_WP="${EVIDENCE_WP:-}" \
  EVIDENCE_UI="${EVIDENCE_UI:-}" \
  G5_REPORT="${G5_REPORT:-}" \
  python3 - <<'PY'
import json, os
from pathlib import Path
report = Path(os.environ["REPORT_DIR"])
gates = []
for line in (report / "_gates.tsv").read_text().splitlines():
    if not line.strip():
        continue
    parts = line.split("|", 2)
    while len(parts) < 3:
        parts.append("")
    gates.append({"gate": parts[0], "status": parts[1], "detail": parts[2]})

ui_cov = {}
ui_path = report / "ui-form-coverage.json"
if ui_path.is_file():
    try:
        ui_cov = json.loads(ui_path.read_text())
    except json.JSONDecodeError:
        ui_cov = {}

projects_raw = os.environ.get("FULL_CHAIN_PROJECTS", "").strip()
if projects_raw == "all":
    project_list = ["all"]
elif projects_raw == "default" or not projects_raw:
    project_list = ["wptsall-content", "woocommerce-content"]
else:
    project_list = [p.strip() for p in projects_raw.split(",") if p.strip()]

summary = {
    "mode": os.environ["MODE"],
    "stamp": os.environ["STAMP"],
    "report_dir": str(report),
    "commercial_ui_only": os.environ.get("COMMERCIAL_UI_ONLY", "0") in ("1", "true", "True"),
    "definition": "tasks/client2/21-COMMERCIAL-ACCEPTANCE-MATRIX-DEFINITION.md",
    "projects": project_list,
    "gates": gates,
    "ui_forms": {
        "ok": ui_cov.get("ok"),
        "forms_ok": ui_cov.get("forms_ok"),
        "forms_total": ui_cov.get("forms_total"),
        "sim10_present": ui_cov.get("sim10_present"),
        "path": str(ui_path) if ui_path.is_file() else None,
    },
    "evidence": {
        "summary": str(report / "summary.json"),
        "ui_form_coverage": os.environ.get("EVIDENCE_UI") or None,
        "client_jobs_db_hint": os.environ.get("EVIDENCE_JOBS") or None,
        "wp_ground_truth_hint": os.environ.get("EVIDENCE_WP") or None,
        "identity_chain_report_hint": os.environ.get("G5_REPORT") or None,
        "notes": [
            "G2 is non-UI (curl/API Lab roundtrip); do not cite as UI form evidence",
            "G4 is Desktop API/Vitest parity; G4_TAURI is real WebView smoke when binary exists",
            "G5 is run-identity-chain-gate.sh (Lab :9083 + slot-g), not inferred from G1",
        ],
    },
    "ok": os.environ.get("FAILED", "1") == "0",
}
text = json.dumps(summary, indent=2, ensure_ascii=False) + "\n"
(report / "summary.json").write_text(text)
(report.parent / "latest-summary.json").write_text(text)
print(text)
PY

if [[ "${FAILED}" != "0" ]]; then
  err "commercial acceptance FAILED — ${REPORT_DIR}/summary.json"
  exit 1
fi
ok "commercial acceptance PASSED — ${REPORT_DIR}/summary.json"
