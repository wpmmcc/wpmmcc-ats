#!/usr/bin/env bash
# Content-plugin full chain gate — compose WP prep → Client UI → mock translate → front/SEO.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/content-plugin-full-chain/run-gate.sh
#   FULL_CHAIN_PROJECTS=all WPTSALL_LAB=1 bash …/run-gate.sh
#   FULL_CHAIN_ONLY=p0,p3 bash …/run-gate.sh
#   FULL_CHAIN_SKIP=p1,p7 bash …/run-gate.sh
#   FULL_CHAIN_MENU_SEO=1 FULL_CHAIN_JOURNEYS=1 bash …/run-gate.sh
#   FULL_CHAIN_RELEASE=1 bash …/run-gate.sh   # release-evidence mode: p7 mandatory, cannot skip
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../lib/repo-root.sh"
E2E_DIR="$(wptsall_path e2e "${SCRIPT_DIR}")"
REPO_ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"

unset E2E_SLOT_WP_ISOLATED CLIENT_URL || true
# Avoid dual-UI / desktop agent pollution overriding owned-lane ports (PORT beats BIND).
unset WPTSALL_WEB_UI_BIND WPTSALL_WEB_UI_PORT || true
# Content-plugin slices need a dedicated slot WP (ensure-plugin-project rejects shared).
# Default slot-a; strip ambient matrix "shared" before helpers are available.
if [[ "${FULL_CHAIN_SLOT:-${E2E_SLOT:-slot-a}}" == "shared" ]]; then
  echo "[full-chain] FULL_CHAIN on shared is unsupported for P2/P4; switching to slot-a" >&2
  export E2E_SLOT=slot-a
else
  export E2E_SLOT="${FULL_CHAIN_SLOT:-${E2E_SLOT:-slot-a}}"
fi
export E2E_SLOT_WP_ISOLATED="${E2E_SLOT_WP_ISOLATED:-1}"
export WPTSALL_LAB="${WPTSALL_LAB:-1}"
# shellcheck source=../config.sh
source "${E2E_DIR}/config.sh"

# Client UI uses the shared developer agent (:8977) by default; slot WP is separate.
# config.sh would otherwise point CLIENT_BASE at the slot client port (e.g. 9077).
export WEBUI_A_BASE="${FULL_CHAIN_CLIENT_BASE:-http://127.0.0.1:8977}"
export CLIENT_BASE="${FULL_CHAIN_CLIENT_BASE:-http://127.0.0.1:8977}"

REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/content-plugin-full-chain"
RUN_ID="$(date +%Y%m%dT%H%M%S)"
REPORT_DIR="${REPORT_ROOT}/run-${RUN_ID}"
mkdir -p "${REPORT_DIR}"
export FULL_CHAIN_REPORT_DIR="${REPORT_DIR}"

mapfile -t PROJECTS < <(FULL_CHAIN_PROJECTS="${FULL_CHAIN_PROJECTS:-default}" python3 "${SCRIPT_DIR}/lib/projects.py")
[[ ${#PROJECTS[@]} -gt 0 ]] || abort "no projects resolved"

ONLY_RAW="${FULL_CHAIN_ONLY:-}"
SKIP_RAW="${FULL_CHAIN_SKIP:-}"
IFS=',' read -ra ONLY_LIST <<<"${ONLY_RAW}"
IFS=',' read -ra SKIP_LIST <<<"${SKIP_RAW}"

phase_wanted() {
  local id="$1"
  local o s
  if [[ -n "${ONLY_RAW}" ]]; then
    for o in "${ONLY_LIST[@]}"; do
      [[ -z "$o" ]] && continue
      [[ "$id" == "$o" || "$id" == *"$o"* ]] && return 0
    done
    return 1
  fi
  for s in "${SKIP_LIST[@]}"; do
    [[ -z "$s" ]] && continue
    [[ "$id" == "$s" || "$id" == *"$s"* ]] && return 1
  done
  return 0
}

print_stage "FULL-CHAIN" "content-plugin-full-chain run=${RUN_ID} projects=${PROJECTS[*]}"

FAILED=0

run_global() {
  local phase="$1"
  phase_wanted "${phase}" || { info "skip ${phase}"; return 0; }
  if bash "${SCRIPT_DIR}/run-phase.sh" "${phase}"; then
    ok "${phase}"
  else
    err "${phase} FAILED"
    FAILED=1
    [[ "${FULL_CHAIN_KEEP_GOING:-0}" == "1" ]] || abort "phase ${phase} failed"
  fi
}

run_per_project() {
  local phase="$1"
  phase_wanted "${phase}" || { info "skip ${phase}"; return 0; }
  local project
  for project in "${PROJECTS[@]}"; do
    info "${phase} → ${project}"
    if bash "${SCRIPT_DIR}/run-phase.sh" "${phase}" "${project}"; then
      ok "${phase}/${project}"
    else
      err "${phase}/${project} FAILED"
      FAILED=1
      [[ "${FULL_CHAIN_KEEP_GOING:-0}" == "1" ]] || abort "phase ${phase} project ${project} failed"
    fi
  done
}

# Optional defaults: skip heavy install / menu-seo / journeys unless asked.
# Release-evidence mode (FULL_CHAIN_RELEASE=1): menu/hreflang (p7) is mandatory.
# Full-chain runs consumed as release evidence must not silently skip p7.
if [[ "${FULL_CHAIN_RELEASE:-0}" == "1" ]]; then
  if [[ "${FULL_CHAIN_MENU_SEO:-0}" != "1" ]]; then
    export FULL_CHAIN_MENU_SEO=1
    echo "[full-chain] FULL_CHAIN_RELEASE=1 -> forcing FULL_CHAIN_MENU_SEO=1 (p7 mandatory)" >&2
  fi
  if [[ ",${FULL_CHAIN_SKIP:-}," == *",p7,"* ]]; then
    echo "[full-chain] FULL_CHAIN_RELEASE=1 forbids skipping p7 (menu/SEO)" >&2
    exit 2
  fi
fi

# Commercial mode (FULL_CHAIN_COMMERCIAL=1): Client UI phase p3 is mandatory;
# static U1–U7 coverage must pass; cannot skip p3.
if [[ "${FULL_CHAIN_COMMERCIAL:-0}" == "1" ]]; then
  export COMMERCIAL_UI_ONLY="${COMMERCIAL_UI_ONLY:-1}"
  if [[ ",${FULL_CHAIN_SKIP:-}," == *",p3,"* ]]; then
    echo "[full-chain] FULL_CHAIN_COMMERCIAL=1 forbids skipping p3 (Client UI journey)" >&2
    exit 2
  fi
  CA_LIB="$(cd "${SCRIPT_DIR}/../commercial-acceptance/lib" && pwd)"
  echo "[full-chain] FULL_CHAIN_COMMERCIAL=1 → checking UI form coverage U1–U7" >&2
  python3 "${CA_LIB}/check-ui-form-coverage.py" \
    --repo "${REPO_ROOT}" \
    --out "${REPORT_DIR}/ui-form-coverage.json" \
    || abort "commercial UI form coverage failed — see ${REPORT_DIR}/ui-form-coverage.json"
  python3 "${CA_LIB}/check-ui-only-journey.py" \
    --file "${E2E_DIR}/playwright/client-ui-setup/pre-wp-bind.journey.spec.ts" \
    || abort "commercial UI-only lint failed for pre-wp-bind journey"
fi
[[ "${FULL_CHAIN_INSTALL_PLUGINS:-0}" == "1" ]] || SKIP_RAW="${SKIP_RAW:+$SKIP_RAW,}p1"
[[ "${FULL_CHAIN_MENU_SEO:-0}" == "1" ]] || SKIP_RAW="${SKIP_RAW:+$SKIP_RAW,}p7"
[[ "${FULL_CHAIN_LAB_PROVIDER:-0}" == "1" ]] || SKIP_RAW="${SKIP_RAW:+$SKIP_RAW,}p3b"
IFS=',' read -ra SKIP_LIST <<<"${SKIP_RAW}"

run_global p0
run_global p1
# Same slot WP: ensure for project B reaps project A fixtures. Interleave
# per-project prep → translate → verify so each slice still has its seed.
CLIENT_BOUND=0
# When commercial (or explicitly requested): every project gets a Client UI touch
# after prep (Discovery + Run Once), not only the first project's full pre-wp-bind.
if [[ "${FULL_CHAIN_COMMERCIAL:-0}" == "1" ]]; then
  export FULL_CHAIN_CLIENT_UI_EACH="${FULL_CHAIN_CLIENT_UI_EACH:-1}"
fi
for project in "${PROJECTS[@]}"; do
  if phase_wanted p2; then
    info "p2 → ${project}"
    if bash "${SCRIPT_DIR}/run-phase.sh" p2 "${project}"; then
      ok "p2/${project}"
    else
      err "p2/${project} FAILED"
      FAILED=1
      [[ "${FULL_CHAIN_KEEP_GOING:-0}" == "1" ]] || abort "phase p2 project ${project} failed"
    fi
  else
    info "skip p2 (${project})"
  fi
  if [[ "${CLIENT_BOUND}" == "0" ]]; then
    run_global p3
    run_global p3b
    CLIENT_BOUND=1
  elif [[ "${FULL_CHAIN_CLIENT_UI_EACH:-0}" == "1" ]] && phase_wanted p3; then
    info "p3_touch → ${project}"
    if bash "${SCRIPT_DIR}/run-phase.sh" p3_touch "${project}"; then
      ok "p3_touch/${project}"
    else
      err "p3_touch/${project} FAILED"
      FAILED=1
      [[ "${FULL_CHAIN_KEEP_GOING:-0}" == "1" ]] || abort "phase p3_touch project ${project} failed"
    fi
  fi
  for phase in p4 p5 p6; do
    if phase_wanted "${phase}"; then
      info "${phase} → ${project}"
      if bash "${SCRIPT_DIR}/run-phase.sh" "${phase}" "${project}"; then
        ok "${phase}/${project}"
      else
        err "${phase}/${project} FAILED"
        FAILED=1
        [[ "${FULL_CHAIN_KEEP_GOING:-0}" == "1" ]] || abort "phase ${phase} project ${project} failed"
      fi
    else
      info "skip ${phase} (${project})"
    fi
  done
done
run_global p7

SUMMARY="${REPORT_DIR}/summary-${RUN_ID}.json"
python3 "${SCRIPT_DIR}/lib/summarize.py" "${REPORT_DIR}" "${SUMMARY}" "${FAILED}" || true

# Manifest
python3 - "$SUMMARY" "$RUN_ID" "${PROJECTS[@]}" <<'PY'
import json, sys
from pathlib import Path
summary_path = Path(sys.argv[1])
run_id = sys.argv[2]
projects = sys.argv[3:]
try:
    summary = json.loads(summary_path.read_text())
except Exception:
    summary = {"ok": False}
manifest = {
  "run_id": run_id,
  "projects": projects,
  "summary_path": str(summary_path),
  "ok": bool(summary.get("ok")),
  "architecture": "content-plugin-full-chain",
  "mock_marker": "【locale】…【/locale】",
}
Path(summary_path).with_name("manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
print(json.dumps(manifest, indent=2))
PY

if [[ "${FAILED}" != "0" ]]; then
  abort "full-chain finished with failures — see ${REPORT_DIR}"
fi
ok "full-chain passed — ${REPORT_DIR}"
