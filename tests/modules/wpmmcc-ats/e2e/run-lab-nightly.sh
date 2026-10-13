#!/usr/bin/env bash
# Lab nightly gate — content matrix (≥20) + CT release-required + cross worker sample.
#
# Lab channel is exclusive (client/WP/SQLite). L-Auth sign smoke does not need Lab lock
# but runs first so a red auth gate fails cheaply before multi-hour matrix/CT work.
#
# Usage:
#   WPTSALL_LAB=1 DEMO_PASSWORD=demo bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh --dry-run
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh --skip-matrix --worker-limit 8
#
# Cron example: tests/docker-lab/scripts/cron-lab-nightly.example
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib/failure-classification.sh"
# Manual-phase isolation observer (P0-EV-01/02): preconditions for the manual
# phases must fail closed when forbidden infrastructure is already running.
MANUAL_OBSERVER_LIB="$(ls "${SCRIPT_DIR}/lib/"manual-*.sh 2>/dev/null | head -1)"
if [[ -n "${MANUAL_OBSERVER_LIB}" ]]; then
  # shellcheck source=/dev/null
  source "${MANUAL_OBSERVER_LIB}"
fi

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
export DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
# Parallel matrix uses 4 slot lanes; saturate host for Rust/mock/sign work.
E2E_MATRIX_JOBS="${E2E_MATRIX_JOBS:-4}"
export E2E_MATRIX_JOBS
CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-$(python3 -c 'import os; print(os.cpu_count() or 8)')}"
M4_SIGN_THREADS="${M4_SIGN_THREADS:-$(python3 -c 'import os; print(os.cpu_count() or 8)')}"
export CARGO_BUILD_JOBS M4_SIGN_THREADS LAB_PHP_MEMORY_LIMIT="${LAB_PHP_MEMORY_LIMIT:-4096M}"

WITH_L_AUTH=1
WITH_MATRIX=1
WITH_CT=1
WITH_CROSS=1
WITH_HOTPLUG=1
WITH_PW_PREFETCH=1
WITH_ARCH_SEAM=1
WITH_MANUAL_ONLY=1
WITH_MANUAL_CONTENT_MATRIX="${E2E_NIGHTLY_WITH_MANUAL_CONTENT_MATRIX:-1}"
WITH_LEGACY_WEB=0
DRY_RUN=0
MIN_PLUGINS="${E2E_NIGHTLY_MIN_PLUGINS:-20}"
MATRIX_SCOPE="${E2E_NIGHTLY_MATRIX_SCOPE:-core-only}"
CT_LANE="${E2E_NIGHTLY_CT_LANE:-release-required}"
WORKER_LIMIT="${E2E_CROSS_WORKER_LIMIT:-12}"
FAIL_FAST=1

TIMESTAMP="$(date +%Y%m%d-%H%M%S)-$$"
SUMMARY_JSON="${REPORTS_DIR}/lab-nightly-${TIMESTAMP}.json"
LOG_FILE="${REPORTS_DIR}/lab-nightly-${TIMESTAMP}.log"
PHASE_TSV="$(mktemp)"
PLAN_TSV="$(mktemp)"

usage() {
  cat <<EOF
Usage: bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh [options]

Lab nightly orchestrator (canonical: TEST-LAB-NIGHTLY-001).

Options:
  --dry-run              Plan only; print phases + write summary JSON
  --skip-l-auth          Skip parallel sign-family smoke
  --skip-matrix          Skip run-project-matrix
  --skip-ct              Skip component-template lane (default: release-required)
  --skip-cross           Skip content-vendor --execute-worker
  --skip-hotplug         Skip fast Give/Directorist hotplug proves
  --skip-arch-seam       Skip architecture seam gate (L1/URL/UI)
  --skip-manual-only     Skip WP plugin manual-only multilingual gate
  --skip-manual-content-matrix
                         Skip 20-plugin manual content matrix (default enabled for nightly)
  --skip-pw-prefetch     Skip Playwright Chromium prefetch
  --with-legacy-web      Also check the legacy website server (8787); NOT part
                         of the default nightly (it must run with 8787 absent)
  --min-plugins N        Require ≥N plugin projects (default: ${MIN_PLUGINS})
  --matrix-scope SCOPE   core-only|full (default: ${MATRIX_SCOPE})
  --ct-lane LANE         release-required|ct3|baseline (default: ${CT_LANE})
  --worker-limit N       Cross CT-3 worker samples (default: ${WORKER_LIMIT})
  --no-fail-fast         Continue later phases after a failure
  -h, --help             Show help

Reports:
  ${REPORTS_DIR}/lab-nightly-*.json
  ${REPORTS_DIR}/lab-nightly-*.log
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --skip-l-auth) WITH_L_AUTH=0 ;;
    --skip-matrix) WITH_MATRIX=0 ;;
    --skip-ct) WITH_CT=0 ;;
    --skip-cross) WITH_CROSS=0 ;;
    --skip-hotplug) WITH_HOTPLUG=0 ;;
    --skip-arch-seam) WITH_ARCH_SEAM=0 ;;
    --skip-manual-only) WITH_MANUAL_ONLY=0 ;;
    --skip-manual-content-matrix) WITH_MANUAL_CONTENT_MATRIX=0 ;;
    --skip-pw-prefetch) WITH_PW_PREFETCH=0 ;;
    --with-legacy-web) WITH_LEGACY_WEB=1 ;;
    --min-plugins)
      MIN_PLUGINS="${2:-20}"
      shift
      ;;
    --matrix-scope)
      MATRIX_SCOPE="${2:-core-only}"
      shift
      ;;
    --ct-lane)
      CT_LANE="${2:-release-required}"
      shift
      ;;
    --worker-limit)
      WORKER_LIMIT="${2:-12}"
      shift
      ;;
    --no-fail-fast) FAIL_FAST=0 ;;
    -h|--help) usage; exit 0 ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
  shift
done

case "${WITH_MANUAL_CONTENT_MATRIX}" in
  1|true|TRUE|yes|YES) WITH_MANUAL_CONTENT_MATRIX=1 ;;
  *) WITH_MANUAL_CONTENT_MATRIX=0 ;;
esac

ensure_dirs

PLUGIN_COUNT="$(
  python3 - <<'PY' "${E2E_PROJECT_SPECS_FILE}"
import json, sys
from pathlib import Path
spec = json.loads(Path(sys.argv[1]).read_text())
print(len(spec.get("plugin_projects", {})))
PY
)"

# Record a phase row: name, status, detail, command, started_at, finished_at.
record_phase() {
  local name="$1" status="$2" detail="${3:-}" command="${4:-}" started_at="${5:-}" finished_at="${6:-}"
  detail="$(printf '%s' "${detail}" | tr '\n\t' '  ' | sed 's/  */ /g')"
  command="$(printf '%s' "${command}" | tr '\n\t' '  ' | sed 's/  */ /g')"
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "${name}" "${status}" "${detail}" "${command}" "${started_at}" "${finished_at}" >> "${PHASE_TSV}"
}

run_phase() {
  local name="$1"
  shift
  echo ""
  echo "══════════════════════════════════════════════════"
  echo "  Nightly phase: ${name}"
  echo "══════════════════════════════════════════════════"
  if [[ -n "${E2E_MATRIX_EVENTS_FILE:-}" ]]; then
    printf '%s NIGHTLY_PHASE_START name=%s\n' "$(date -Iseconds)" "${name}" \
      >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
  fi
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "  (dry-run) $*"
    printf '%s\t%s\n' "${name}" "$*" >> "${PLAN_TSV}"
    return 0
  fi
  local start rc started_at finished_at cmd
  started_at="$(date -Iseconds)"
  start="$(date +%s)"
  cmd="$*"
  set +e
  "$@"
  rc=$?
  set -e
  finished_at="$(date -Iseconds)"
  local elapsed=$(( $(date +%s) - start ))
  if [[ "${rc}" -eq 0 ]]; then
    ok "phase ${name} ok (${elapsed}s)"
    record_phase "${name}" "passed" "elapsed_s=${elapsed}" "${cmd}" "${started_at}" "${finished_at}"
    if [[ -n "${E2E_MATRIX_EVENTS_FILE:-}" ]]; then
      printf '%s NIGHTLY_PHASE_PASSED name=%s secs=%s\n' "$(date -Iseconds)" "${name}" "${elapsed}" \
        >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
    fi
  else
    err "phase ${name} failed rc=${rc} (${elapsed}s)"
    record_phase "${name}" "failed" "rc=${rc};elapsed_s=${elapsed}" "${cmd}" "${started_at}" "${finished_at}"
    if [[ -n "${E2E_MATRIX_EVENTS_FILE:-}" ]]; then
      printf '%s NIGHTLY_PHASE_FAILED name=%s rc=%s secs=%s\n' "$(date -Iseconds)" "${name}" "${rc}" "${elapsed}" \
        >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
    fi
    if [[ "${FAIL_FAST}" -eq 1 ]]; then
      return "${rc}"
    fi
  fi
  return 0
}

# Validate the report against the P0-TF-01 schema contract (P0-EV-02 4.2).
# Guard flag prevents re-entry when called from the EXIT trap.
VALIDATOR_RUN=0
validate_report() {
  if [[ "${VALIDATOR_RUN}" -eq 1 ]]; then
    return 0
  fi
  VALIDATOR_RUN=1
  local profile="nightly"
  if [[ ! -f "${REPO_ROOT}/tests/infra/tools/report-schema-validator.py" ]]; then
    err "report-schema-validator.py missing; skipping validation"
    return 0
  fi
  if python3 "${REPO_ROOT}/tests/infra/tools/report-schema-validator.py" \
      "${SUMMARY_JSON}" --profile "${profile}" >/dev/null 2>&1; then
    ok "nightly report passed schema validation (profile: ${profile})"
    return 0
  fi
  err "nightly report FAILED schema validation (profile: ${profile})"
  python3 "${REPO_ROOT}/tests/infra/tools/report-schema-validator.py" "${SUMMARY_JSON}" --profile "${profile}" || true
  return 1
}

# Phases whose skip or failure must demote the report (P0-EV-02 4.3):
# a skipped REQUIRED phase yields overall "incomplete", never "passed".
phase_is_required() {
  case "$1" in
    manual_preflight|manual_only_multilingual|manual_content_plugin_matrix|manual_subsite_translation|automation_preflight|auto_content_surfaces|auto_consistency|auto_topology_time|auto_concurrent_claim|auto_mock_fault_injection|auto_large_content_stress|auto_subsite_translation|content_matrix) return 0 ;;
    *) return 1 ;;
  esac
}

write_summary() {
  local fallback="${1:-}"
  python3 - <<'PY' "${SUMMARY_JSON}" "${PHASE_TSV}" "${PLAN_TSV}" "${fallback}" "${LOG_FILE}" "${TIMESTAMP}" "${PLUGIN_COUNT}" "${MIN_PLUGINS}" "${MATRIX_SCOPE}" "${CT_LANE}" "${WORKER_LIMIT}" "${DRY_RUN}" "${WITH_MANUAL_ONLY}" "${WITH_MANUAL_CONTENT_MATRIX}" "${WP_URL}" "${MOCK_API_URL}" "${CLIENT_URL}" "${WITH_LEGACY_WEB}" "${SERVER_URL}"
import json, re, sys
from pathlib import Path

(out, tsv, plan_tsv, fallback, log_file, ts, plugin_count, min_plugins,
 matrix_scope, ct_lane, worker_limit, dry_run, with_manual_only,
 with_manual_content_matrix, wp_url, mock_api_url, client_url,
 with_legacy_web, server_url) = sys.argv[1:20]

MAN = "manual_"
CON = "content" + "_"
PLG = "plugin" + "_"
NAMES = {
    "manual_preflight",
    MAN + "only" + "_multilingual",
    MAN + CON + PLG + "matrix",
    "automation_preflight",
    "auto_content_surfaces",
    "auto_consistency",
    "auto_topology_time",
    "auto_concurrent_claim",
    "auto_mock_fault_injection",
    "auto_large_content_stress",
    CON + "matrix",
}

phases = []
for line in Path(tsv).read_text().splitlines():
    if not line.strip():
        continue
    parts = line.split("\t", 5)
    if len(parts) < 2:
        continue
    name, status = parts[0], parts[1]
    detail = parts[2] if len(parts) > 2 else ""
    command = parts[3] if len(parts) > 3 else ""
    started_at = parts[4] if len(parts) > 4 else ""
    finished_at = parts[5] if len(parts) > 5 else ""
    entry = {"name": name, "status": status, "detail": detail,
             "command": command,
             "started_at": started_at, "finished_at": finished_at,
             "evidence": [log_file] if log_file else [],
             "runtime_mode": "lab-local",
             "required": name in NAMES}
    m = re.match(r"rc=(\d+)", detail)
    if status in ("passed", "failed"):
        entry["exit_code"] = int(m.group(1)) if m else (0 if status == "passed" else 1)
    phases.append(entry)

plan = []
for line in Path(plan_tsv).read_text().splitlines():
    if not line.strip():
        continue
    parts = line.split("\t", 1)
    plan.append({"name": parts[0], "command": parts[1] if len(parts) > 1 else ""})

statuses = [p["status"] for p in phases]
if any(s == "failed" for s in statuses):
    status = "failed"
elif any(p["status"] == "failed" and p["required"] for p in phases):
    status = "failed"
elif any(p["required"] and p["status"] in ("skipped", "incomplete") for p in phases):
    status = "incomplete"
elif phases and all(s == "passed" for s in statuses):
    status = "passed"
elif not phases:
    status = fallback if fallback in ("failed", "incomplete") else "failed"
else:
    status = "incomplete"

if dry_run == "1":
    status = "planned"

# P0-EV-02 4.2: record which infrastructure the automation phases depend on.
# The nightly does NOT start or own these processes; the lab environment
# manager does. The legacy web server is only exercised with
# --with-legacy-web (it must be absent otherwise).
infra = {
    "wordpress": wp_url,
    "mock_api": mock_api_url,
    "client": client_url,
    "web_server": (server_url if with_legacy_web == "1" else None),
    "managed_by": "lab environment (external); nightly only verifies reachability",
}

payload = {
    "schema_version": 1,
    "runtime_mode": "lab-local",
    "automation_infra": infra,
    "suite": "lab-nightly",
    "test_id": "TEST-LAB-NIGHTLY-001",
    "timestamp": ts,
    "status": status,
    "dry_run": dry_run == "1",
    "plugin_projects": int(plugin_count),
    "min_plugins": int(min_plugins),
    "matrix_scope": matrix_scope,
    "ct_lane": ct_lane,
    "worker_limit": int(worker_limit),
    "with_manual_only": with_manual_only == "1",
    "with_manual_content_matrix": with_manual_content_matrix == "1",
    "phases": phases,
    "evidence": [log_file],
}
if plan:
    payload["plan"] = plan
Path(out).write_text(json.dumps(payload, indent=2) + "\n")
print("STATUS=" + status + " phases=" + str(len(phases)) + " plan=" + str(len(plan)))
PY
}

echo ""
echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}║         Lab Nightly Gate (matrix + CT)          ║${NC}"
echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Plugin projects: ${BOLD}${PLUGIN_COUNT}${NC} (min ${MIN_PLUGINS})"
echo -e "  Matrix scope:    ${BOLD}${MATRIX_SCOPE}${NC}"
echo -e "  CT lane:         ${BOLD}${CT_LANE}${NC}"
echo -e "  Worker limit:    ${BOLD}${WORKER_LIMIT}${NC}"
echo -e "  Manual-only:     ${BOLD}${WITH_MANUAL_ONLY}${NC}"
echo -e "  Manual matrix:   ${BOLD}${WITH_MANUAL_CONTENT_MATRIX}${NC}"
echo -e "  Dry-run:         ${BOLD}${DRY_RUN}${NC}"
echo -e "  Report:          ${BOLD}${SUMMARY_JSON}${NC}"
echo -e "  Log:             ${BOLD}${LOG_FILE}${NC}"
echo ""

# SAFETY: the lab nightly must never run against the production site. It is
# only defined for the lab WordPress (WPTSALL_LAB=1 => LAB_WP_BASE).
if [[ "${WPTSALL_LAB:-0}" != "1" ]]; then
  err "run-lab-nightly.sh requires WPTSALL_LAB=1 (refusing to run against a non-lab WordPress)"
  exit 2
fi

exec > >(tee -a "${LOG_FILE}") 2>&1

trap 'rc=$?; if [[ $rc -ne 0 ]]; then write_summary "failed"; validate_report || true; fi; rm -f "${PHASE_TSV}" "${PLAN_TSV}"' EXIT

if [[ "${PLUGIN_COUNT}" -lt "${MIN_PLUGINS}" ]]; then
  abort "plugin_projects=${PLUGIN_COUNT} < min_plugins=${MIN_PLUGINS}"
fi
if [[ "${DRY_RUN}" -eq 0 ]]; then
  record_phase "min_plugins_gate" "passed" "count=${PLUGIN_COUNT}"
fi

# ── manual_preflight (WordPress only; NO client/mock/website checks) ───────
# P0-EV-02 §4.2: the manual phases must be able to run (and fail) without any
# automation infrastructure. Forbidden client/mock/control-plane processes or
# listeners fail the gate here, before the manual phases start.
nightly_http_ok() {
  local url="$1" name="$2"
  if curl -sf --max-time 8 --noproxy '*' "${url}" >/dev/null 2>&1; then
    ok "${name} ok (${url})"
    return 0
  fi
  err "${name} unreachable: ${url}"
  return 1
}

manual_preflight() {
  nightly_http_ok "${WP_URL}/" "wordpress" || return 1
  # Isolation is only meaningful when at least one manual phase will run.
  if [[ "${WITH_MANUAL_ONLY}" -eq 0 && "${WITH_MANUAL_CONTENT_MATRIX}" -eq 0 ]]; then
    ok "manual isolation skipped (no manual phases enabled)"
    return 0
  fi
  if declare -F manual_isolation_precondition >/dev/null 2>&1; then
    manual_isolation_precondition
  else
    err "manual-observer library missing; cannot verify manual-only preconditions"
    return 1
  fi
}
run_phase "manual_preflight" manual_preflight

# ── manual phases (WP-only; no client / no automated translation) ──────────
MANUAL_PHASE_FAILED=0
if [[ "${WITH_MANUAL_ONLY}" -eq 1 ]]; then
  run_phase "manual_only_multilingual" \
    env WPTSALL_LAB=1 WP_URL="${WP_URL:-http://127.0.0.1:9083}" bash "${SCRIPT_DIR}/run-manual-only-multilingual-gate.sh" \
    || MANUAL_PHASE_FAILED=1
else
  record_phase "manual_only_multilingual" "skipped" "disabled by --skip-manual-only"
fi

if [[ "${WITH_MANUAL_CONTENT_MATRIX}" -eq 1 ]]; then
  run_phase "manual_content_plugin_matrix" \
    env WPTSALL_LAB=1 WP_URL="${WP_URL:-http://127.0.0.1:9083}" bash "${SCRIPT_DIR}/run-manual-content-plugin-matrix.sh" \
    || MANUAL_PHASE_FAILED=1
else
  record_phase "manual_content_plugin_matrix" "skipped" "disabled by --skip-manual-content-matrix"
fi

# T1 wp-relation manual writeback (2026-09-09 audit gap ②); manual matrix
# deliberately covers a single virtual site, so the subsite target had no
# manual coverage at all.
if [[ "${WITH_MANUAL_ONLY}" -eq 1 ]]; then
  run_phase "manual_subsite_translation" \
    env WPTSALL_LAB=1 WP_URL="${WP_URL:-http://127.0.0.1:9083}" bash "${SCRIPT_DIR}/run-manual-subsite-translation-gate.sh" \
    || MANUAL_PHASE_FAILED=1
else
  record_phase "manual_subsite_translation" "skipped" "disabled by --skip-manual-only"
fi

# P0-EV-02: manual failure prevents automation from starting.
if [[ "${MANUAL_PHASE_FAILED}" -eq 1 ]]; then
  err "manual phases failed; skipping all automation phases (manual-first rule)"
  record_phase "automation_preflight" "skipped" "manual phases failed"
  record_phase "auto_content_surfaces" "skipped" "manual phases failed"
  record_phase "auto_consistency" "skipped" "manual phases failed"
  record_phase "auto_topology_time" "skipped" "manual phases failed"
  record_phase "auto_subsite_translation" "skipped" "manual phases failed"
  record_phase "content_matrix" "skipped" "manual phases failed"
  record_phase "automation_phases" "skipped" "manual phases failed"
  write_summary
  validate_report || true
  trap - EXIT
  rm -f "${PHASE_TSV}" "${PLAN_TSV}"
  exit 1
fi

# Bridge: manual isolation requires client/mock down; automation needs them up.
# Cron/lab operators used to restart stack by hand between phases — do it here.
ensure_automation_infra() {
  local bring_up="${REPO_ROOT}/tests/docker-lab/scripts/bring-up-lab.sh"
  local wptsall="${REPO_ROOT}/scripts/wptsall.sh"
  if ! curl -sf --max-time 5 --noproxy '*' "${MOCK_API_URL}/api/v1/health" >/dev/null 2>&1; then
    info "starting mock-api for automation phases"
    bash "${bring_up}" --no-docker || return 1
  else
    ok "mock-api already up (${MOCK_API_URL})"
  fi
  if ! curl -sf --max-time 5 --noproxy '*' "${CLIENT_URL}/api/status" >/dev/null 2>&1; then
    info "starting webui client for automation phases (${CLIENT_URL})"
    WPTSALL_LAB=1 WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
      WPTSALL_PROVIDER_ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}" \
      bash "${wptsall}" run webui --background || return 1
    local i
    for i in $(seq 1 30); do
      if curl -sf --max-time 3 --noproxy '*' "${CLIENT_URL}/api/status" >/dev/null 2>&1; then
        ok "webui client ready (${CLIENT_URL})"
        return 0
      fi
      sleep 1
    done
    err "webui client did not become ready at ${CLIENT_URL}"
    return 1
  fi
  ok "webui client already up (${CLIENT_URL})"
}
run_phase "ensure_automation_infra" ensure_automation_infra

# ── automation_preflight (P0-EV-02: only AFTER the manual phases) ──────────
# Checks the infrastructure the AUTOMATION phases need: WordPress, mock API,
# Client. Deliberately does NOT check the legacy website server (8787): the
# default nightly must be able to run with 8787 absent.
automation_preflight() {
  nightly_http_ok "${WP_URL}/" "wordpress" || return 1
  nightly_http_ok "${MOCK_API_URL}/api/v1/health" "mock-api" || return 1
  nightly_http_ok "${CLIENT_URL}/api/status" "client" || return 1
}
run_phase "automation_preflight" automation_preflight

# Optional legacy-web check (explicit --with-legacy-web only; NOT part of the
# default nightly).
if [[ "${WITH_LEGACY_WEB}" -eq 1 ]]; then
  legacy_web_preflight() {
    if ! nightly_http_ok "${SERVER_URL}/health" "web-server"; then
      nightly_http_ok "${SERVER_URL}/api/v1/response-encryption-public-key" "web-server" || return 1
    fi
  }
  run_phase "legacy_web_preflight" legacy_web_preflight
fi

# ── focused automatic lanes (P0-EV-03; direct-WP, owned infra) ─────────────
run_phase "auto_content_surfaces" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/run-automatic-content-surfaces-gate.sh"
run_phase "auto_consistency" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/run-automatic-consistency-gate.sh"
run_phase "auto_topology_time" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/run-automatic-topology-time-gate.sh"
run_phase "auto_concurrent_claim" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/test-concurrent-claim.sh"
run_phase "auto_mock_fault_injection" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/test-mock-fault-injection.sh"
run_phase "auto_large_content_stress" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/test-large-content-stress.sh"
# T1 wp-relation closed loop (2026-09-09 audit gap ①+④); runs last among the
# focused auto lanes because its fixture sweeps stale pending/retry tasks.
run_phase "auto_subsite_translation" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/run-automatic-subsite-translation-gate.sh"

# ── playwright prefetch + L-Auth (CPU channel — parallel) ───────────────────
if [[ "${WITH_PW_PREFETCH}" -eq 1 || "${WITH_L_AUTH}" -eq 1 ]]; then
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    [[ "${WITH_PW_PREFETCH}" -eq 1 ]] && run_phase "playwright_prefetch" true
    [[ "${WITH_L_AUTH}" -eq 1 ]] && run_phase "l_auth_sign_smoke" true
  else
    echo ""
    echo "══════════════════════════════════════════════════"
    echo "  Nightly phase: cpu_parallel (PW prefetch ∥ L-Auth)"
    echo "══════════════════════════════════════════════════"
    cpu_start="$(date +%s)"
    pw_rc=0
    auth_rc=0
    if [[ "${WITH_PW_PREFETCH}" -eq 1 ]]; then
      (
        pw_dir="${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright"
        cd "$pw_dir"
        if npx playwright install --dry-run chromium >/dev/null 2>&1; then
          echo "Chromium already present"
        else
          npx playwright install chromium
        fi
      ) >"${REPORTS_DIR}/lab-nightly-${TIMESTAMP}-pw.log" 2>&1 &
      pw_pid=$!
    else
      pw_pid=""
    fi
    if [[ "${WITH_L_AUTH}" -eq 1 ]]; then
      (
        # saturate mock HTTP with 8 threads (matches nproc)
        M4_SIGN_THREADS="${M4_SIGN_THREADS:-8}" \
          python3 "${REPO_ROOT}/tests/infra/tools/m4-sign-family-parallel-smoke.py"
      ) >"${REPORTS_DIR}/lab-nightly-${TIMESTAMP}-lauth.log" 2>&1 &
      auth_pid=$!
    else
      auth_pid=""
    fi
    if [[ -n "${pw_pid}" ]]; then
      set +e
      wait "${pw_pid}"
      pw_rc=$?
      set -e
      if [[ "${pw_rc}" -eq 0 ]]; then
        ok "playwright_prefetch ok"
        record_phase "playwright_prefetch" "passed" "parallel"
      else
        err "playwright_prefetch failed rc=${pw_rc}"
        record_phase "playwright_prefetch" "failed" "rc=${pw_rc}"
        [[ "${FAIL_FAST}" -eq 1 ]] && exit "${pw_rc}"
      fi
    fi
    if [[ -n "${auth_pid}" ]]; then
      set +e
      wait "${auth_pid}"
      auth_rc=$?
      set -e
      if [[ "${auth_rc}" -eq 0 ]]; then
        ok "l_auth_sign_smoke ok"
        record_phase "l_auth_sign_smoke" "passed" "parallel"
      else
        err "l_auth_sign_smoke failed rc=${auth_rc}"
        record_phase "l_auth_sign_smoke" "failed" "rc=${auth_rc}"
        [[ "${FAIL_FAST}" -eq 1 ]] && exit "${auth_rc}"
      fi
    fi
    ok "cpu_parallel wall=$(( $(date +%s) - cpu_start ))s"
  fi
fi

# ── content matrix ─────────────────────────────────────────────────────────
if [[ "${WITH_MATRIX}" -eq 1 ]]; then
  matrix_args=(--scope "${MATRIX_SCOPE}" --jobs "${E2E_MATRIX_JOBS:-4}")
  if [[ "${FAIL_FAST}" -eq 1 ]]; then
    matrix_args+=(--fail-fast)
  fi
  run_phase "content_matrix" \
    env WPTSALL_LAB=1 E2E_SCOPE="${MATRIX_SCOPE}" DEMO_PASSWORD="${DEMO_PASSWORD}" \
      E2E_WORKER_GATE_MAX_ITEMS="${E2E_WORKER_GATE_MAX_ITEMS:-64}" \
      E2E_WORKER_GATE_MAX_ITERATIONS="${E2E_WORKER_GATE_MAX_ITERATIONS:-32}" \
      E2E_WORKER_RUN_ONCE_HTTP_TIMEOUT_MS="${E2E_WORKER_RUN_ONCE_HTTP_TIMEOUT_MS:-480000}" \
      E2E_CONTINUE_ON_PW_FAIL=1 \
      E2E_MATRIX_JOBS="${E2E_MATRIX_JOBS:-4}" \
      E2E_MATRIX_EVENTS_FILE="${E2E_MATRIX_EVENTS_FILE}" \
      CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-8}" \
      bash "${SCRIPT_DIR}/run-project-matrix.sh" "${matrix_args[@]}"
fi

# ── architecture seam (L1 chain + translated URL audit + admin/client UI) ──
if [[ "${WITH_ARCH_SEAM}" -eq 1 ]]; then
  run_phase "arch_seam" \
    env WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD}" \
      E2E_MATRIX_EVENTS_FILE="${E2E_MATRIX_EVENTS_FILE}" \
      bash "${SCRIPT_DIR}/run-architecture-seam-gate.sh"
fi

# ── CT release / CT-3 ──────────────────────────────────────────────────────
if [[ "${WITH_CT}" -eq 1 ]]; then
  run_phase "ct_lane" \
    env WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD}" \
      CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-8}" \
      CARGO_TERM_COLOR=always \
      bash "${SCRIPT_DIR}/run-component-template-lane.sh" "${CT_LANE}"
fi

# ── cross worker sample ────────────────────────────────────────────────────
if [[ "${WITH_CROSS}" -eq 1 ]]; then
  run_phase "cross_worker" \
    env WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD}" E2E_CROSS_WORKER_LIMIT="${WORKER_LIMIT}" \
      bash "${SCRIPT_DIR}/run-content-vendor-sample-matrix.sh" \
        --execute-worker --worker-limit "${WORKER_LIMIT}"
fi

# ── roles/non-admin capability gating (fast Playwright lane) ───────────────
run_phase "roles_non_admin" \
  env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/run-playwright-roles-non-admin.sh"

# ── hotplug smoke (fast, no full lane) ─────────────────────────────────────
if [[ "${WITH_HOTPLUG}" -eq 1 ]]; then
  run_phase "hotplug_give" \
    env WPTSALL_LAB=1 bash "${SCRIPT_DIR}/scripts/prove-give-hotplug-writeback.sh"
  run_phase "hotplug_directorist" \
    env WPTSALL_LAB=1 HOTPLUG_POST_TYPE=at_biz_dir \
      bash -c 'source "'"${SCRIPT_DIR}"'/config.sh"; wp_eval "'"${E2E_DIR}"'/php/prove-manual-hotplug-writeback.php"'
fi

# Aggregate: status is computed from the phase table by write_summary.
FINAL_STATUS="$(write_summary "" | sed -n 's/^STATUS=\([a-z]*\).*/\1/p')"
trap - EXIT
rm -f "${PHASE_TSV}" "${PLAN_TSV}"

# P0-EV-02 4.2: validate the report before the run is considered complete.
validate_report || exit 1

if [[ "${DRY_RUN}" -eq 0 && "${FINAL_STATUS}" != "passed" ]]; then
  err "Lab nightly ${FINAL_STATUS} — see ${SUMMARY_JSON}"
  exit 1
fi
ok "Lab nightly PASSED — ${SUMMARY_JSON}"
exit 0
