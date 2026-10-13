#!/usr/bin/env bash
# Multi-site × Client deep gate (RC topology minimum).
#
# 1) Own an isolated Client (never reuse a stale :8977 binding).
# 2) Export UI fixtures for ATS :9083 + WPMMCC :9082 + WPMMCC :9187.
# 3) Playwright: Sites UI bind×3 → badges → wizard → rule → discovery → Run Once.
# 4) Write evidence under tests/reports/e2e/commercial-acceptance/multi-site-client-deep/
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/commercial-acceptance/run-multi-site-client-deep.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../lib/repo-root.sh"
REPO_ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"
E2E_DIR="$(wptsall_path e2e "${SCRIPT_DIR}")"
PRE_WP_DIR="${E2E_DIR}/client-ui-setup/pre-wp-bind"
CLIENT_SRC="${REPO_ROOT}/client-wpplugin/source"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/commercial-acceptance/multi-site-client-deep"
REPORT_DIR="${REPORT_ROOT}/run-${STAMP}"
FIXTURE_DIR="${REPORT_DIR}/fixtures"
mkdir -p "${REPORT_DIR}" "${FIXTURE_DIR}"

log() { printf '[multi-site-deep] %s\n' "$*"; }
ok() { printf '[multi-site-deep] ✓ %s\n' "$*"; }
err() { printf '[multi-site-deep] ✗ %s\n' "$*" >&2; }
warn() { printf "[multi-site-deep] ⚠ %s\n" "$*" >&2; }

export WPTSALL_LAB=1
ATS_URL="${MULTI_SITE_ATS_URL:-http://127.0.0.1:9083}"
WPMMCC_A_URL="${MULTI_SITE_WPMMCC_A_URL:-http://127.0.0.1:9082}"
WPMMCC_B_URL="${MULTI_SITE_WPMMCC_B_URL:-http://127.0.0.1:9187}"
MOCK_API_BASE="${MOCK_API_BASE:-http://127.0.0.1:9090}"

choose_free_port() {
  python3 - <<'PY'
import socket
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()
PY
}

CLIENT_PID=""
STATE_ROOT=""
OWNED_WP_CONTEXT=""
cleanup() {
  local result=$?
  trap - EXIT INT TERM
  if [[ -n "${CLIENT_PID}" ]] && kill -0 "${CLIENT_PID}" 2>/dev/null; then
    kill "${CLIENT_PID}" 2>/dev/null || true
    wait "${CLIENT_PID}" 2>/dev/null || true
  fi
  if [[ -n "${OWNED_WP_CONTEXT}" && -f "${OWNED_WP_CONTEXT}" ]]; then
    if ! python3 "${REPO_ROOT}/tests/infra/tools/owned-wp-sites.py" stop --context "${OWNED_WP_CONTEXT}"; then
      err "owned WP cleanup failed; private context retained at ${OWNED_WP_CONTEXT}"
      exit 1
    fi
  fi
  if [[ -n "${STATE_ROOT}" && -d "${STATE_ROOT}" ]]; then
    cp -a "${STATE_ROOT}/state/." "${REPORT_DIR}/client-state/" 2>/dev/null || true
    rm -rf "${STATE_ROOT}"
  fi
  exit "${result}"
}
trap cleanup EXIT INT TERM

# --- preflight ---
for u in "${ATS_URL}" "${WPMMCC_A_URL}" "${WPMMCC_B_URL}" "${MOCK_API_BASE}/api/v1/health"; do
  curl --noproxy '*' -fsS -o /dev/null --connect-timeout 3 "${u}" \
    || { err "unreachable ${u}"; exit 1; }
done
ok "lab sites + mock reachable"

# --- owned client ---
LANE_PORT="$(choose_free_port)"
STATE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wptsall-multi-site-deep.XXXXXX")"
mkdir -p "${STATE_ROOT}/state" "${REPORT_DIR}/client-state"
CLIENT_BASE="http://127.0.0.1:${LANE_PORT}"

export WPTSALL_USE_SERVER_CONTROL_PLANE=0
export WPTSALL_WEB_UI=1
export WPTSALL_WEB_UI_BIND="127.0.0.1:${LANE_PORT}"
export WPTSALL_WEB_UI_PORT="${LANE_PORT}"
export WPTSALL_SERVER_BASE="http://127.0.0.1:1"
export WPTSALL_DB_PATH="${STATE_ROOT}/state/wptsall.db"
export WPTSALL_DATA_DIR="${STATE_ROOT}/state/data"
export WPTSALL_LOG_FILE="${STATE_ROOT}/state/client.log"
export WPTSALL_SESSION_TOKEN_FILE="${STATE_ROOT}/state/session-token.enc"
export WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${STATE_ROOT}/state/domain-token-bindings.json"
export WPTSALL_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/component-bindings.json"
export WPTSALL_TASK_TYPE_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/task-type-component-bindings.json"
export WPTSALL_RULE_COMPONENT_BINDINGS_FILE="${STATE_ROOT}/state/rule-component-bindings.json"
export WPTSALL_COMPONENTS_LOCAL_FILE="${STATE_ROOT}/state/components-local.json"
export WPTSALL_PROVIDER_CATALOG_FILE="${STATE_ROOT}/state/provider-catalog.json"
export WPTSALL_SYNC_PAIRS_FILE="${STATE_ROOT}/state/sync-pairs.json"
export WPTSALL_SYNC_STATE_FILE="${STATE_ROOT}/state/sync-state.json"
export WPTSALL_SYNC_REVIEW_FILE="${STATE_ROOT}/state/sync-review.json"
export WPTSALL_SYNC_PEER_CREDENTIALS_FILE="${STATE_ROOT}/state/sync-peer-credentials.json"
export WPTSALL_LOG_ENABLED=1
export WPTSALL_WP_TRANSPORT_ENCRYPT=off
export WPTSALL_PROVIDER_ALLOWLIST="127.0.0.1,localhost"
mkdir -p "${WPTSALL_DATA_DIR}"
OWNED_WP_CONTEXT="${STATE_ROOT}/owned-wp-context.json"
python3 "${REPO_ROOT}/tests/infra/tools/owned-wp-sites.py" start --context "${OWNED_WP_CONTEXT}"
export WPTSALL_OWNED_WP_CONTEXT="${OWNED_WP_CONTEXT}"

log "building frontend + client…"
(cd "${CLIENT_SRC}/frontend" && pnpm build) >"${REPORT_DIR}/frontend-build.log" 2>&1 \
  || { err "frontend build failed"; exit 1; }
(cargo build --manifest-path "${CLIENT_SRC}/Cargo.toml" --bin wptsall-client --quiet) \
  >"${REPORT_DIR}/cargo-build.log" 2>&1 \
  || { err "cargo build failed"; exit 1; }
CLIENT_BIN="${CARGO_TARGET_DIR:-${CLIENT_SRC}/target}/debug/wptsall-client"
[[ -x "${CLIENT_BIN}" ]] || CLIENT_BIN="${CLIENT_SRC}/target/debug/wptsall-client"

log "starting client on ${CLIENT_BASE}"
(cd "${CLIENT_SRC}" && exec "${CLIENT_BIN}" >"${STATE_ROOT}/state/client-stdout.log" 2>"${STATE_ROOT}/state/client-stderr.log") &
CLIENT_PID=$!
for _ in $(seq 1 60); do
  if curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" >/dev/null \
  || { err "client not ready"; exit 1; }
ok "client ready pid=${CLIENT_PID} ${CLIENT_BASE}"

# --- export fixtures (ATS via wptsall exporter; WPMMCC via option provision) ---
log "export ATS fixture ${ATS_URL}"
PRE_WP_SITE_FIXTURE="${FIXTURE_DIR}/ats-9083.json" \
  PRE_WP_WP_URL="${ATS_URL}" \
  PRE_WP_E2E_SLOT=shared \
  FULL_CHAIN_CLIENT_BASE="${CLIENT_BASE}" \
  WEBUI_A_BASE="${CLIENT_BASE}" \
  bash "${PRE_WP_DIR}/export-site-fixture.sh" >"${REPORT_DIR}/export-ats.log" 2>&1 \
  || { err "ATS fixture export failed"; cat "${REPORT_DIR}/export-ats.log" >&2; exit 1; }

log "export WPMMCC fixtures"
PRE_WP_SITE_FIXTURE="${FIXTURE_DIR}/wpmmcc-9082.json" \
  PRE_WP_WP_URL="${WPMMCC_A_URL}" \
  PRE_WP_WP_CONTAINER=wptsall-wp-lab-wordpress-wpmmcc-1 \
  FULL_CHAIN_CLIENT_BASE="${CLIENT_BASE}" \
  WEBUI_A_BASE="${CLIENT_BASE}" \
  bash "${PRE_WP_DIR}/export-wpmmcc-site-fixture.sh" >"${REPORT_DIR}/export-wpmmcc-a.log" 2>&1 \
  || { err "WPMMCC :9082 export failed"; cat "${REPORT_DIR}/export-wpmmcc-a.log" >&2; exit 1; }

PRE_WP_SITE_FIXTURE="${FIXTURE_DIR}/wpmmcc-9187.json" \
  PRE_WP_WP_URL="${WPMMCC_B_URL}" \
  PRE_WP_WP_CONTAINER=wptsall-wp-lab-wordpress-slot-g \
  FULL_CHAIN_CLIENT_BASE="${CLIENT_BASE}" \
  WEBUI_A_BASE="${CLIENT_BASE}" \
  bash "${PRE_WP_DIR}/export-wpmmcc-site-fixture.sh" >"${REPORT_DIR}/export-wpmmcc-b.log" 2>&1 \
  || { err "WPMMCC :9187 export failed"; cat "${REPORT_DIR}/export-wpmmcc-b.log" >&2; exit 1; }

ok "fixtures ready under ${FIXTURE_DIR}"

# --- Release extras: slot-a (content) + slot-b (WPMMCC) + multisite /en/ ---
SLOT_A_URL="${MULTI_SITE_SLOT_A_URL:-http://127.0.0.1:9181}"
SLOT_B_URL="${MULTI_SITE_SLOT_B_URL:-http://127.0.0.1:9182}"
MS_EN_URL="${MULTI_SITE_MS_EN_URL:-http://127.0.0.1:9083/en}"

if [[ "${MULTI_SITE_SKIP_SLOT_A:-0}" != "1" ]]; then
  log "ensure wptsall-content on slot-a (${SLOT_A_URL})"
  if WPTSALL_LAB=1 E2E_SLOT=slot-a E2E_SLOT_WP_ISOLATED=1 \
    bash "${REPO_ROOT}/tests/scripts/ensure-plugin-project.sh" wptsall-content \
    >"${REPORT_DIR}/ensure-slot-a.log" 2>&1; then
    ok "slot-a content seeded"
    PRE_WP_SITE_FIXTURE="${FIXTURE_DIR}/slot-a-9181.json" \
      PRE_WP_WP_URL="${SLOT_A_URL}" \
      PRE_WP_E2E_SLOT=slot-a \
      FULL_CHAIN_CLIENT_BASE="${CLIENT_BASE}" \
      WEBUI_A_BASE="${CLIENT_BASE}" \
      bash "${PRE_WP_DIR}/export-site-fixture.sh" >"${REPORT_DIR}/export-slot-a.log" 2>&1 \
      || warn "slot-a fixture export failed"
  else
    warn "slot-a ensure failed — see ${REPORT_DIR}/ensure-slot-a.log"
  fi
fi

if [[ "${MULTI_SITE_SKIP_SLOT_B:-0}" != "1" ]]; then
  log "export WPMMCC slot-b ${SLOT_B_URL}"
  bash /home/john/wpmmcc-ats3.0/tests/docker-lab/scripts/ensure-wp-cli.sh \
    --container wptsall-wp-lab-wordpress-slot-b >/dev/null 2>&1 || true
  PRE_WP_SITE_FIXTURE="${FIXTURE_DIR}/slot-b-9182.json" \
    PRE_WP_WP_URL="${SLOT_B_URL}" \
    PRE_WP_WP_CONTAINER=wptsall-wp-lab-wordpress-slot-b \
    FULL_CHAIN_CLIENT_BASE="${CLIENT_BASE}" \
    WEBUI_A_BASE="${CLIENT_BASE}" \
    bash "${PRE_WP_DIR}/export-wpmmcc-site-fixture.sh" >"${REPORT_DIR}/export-slot-b.log" 2>&1 \
    || warn "slot-b fixture export failed — see ${REPORT_DIR}/export-slot-b.log"
fi

if [[ "${MULTI_SITE_SKIP_MS_EN:-0}" != "1" ]]; then
  log "derive multisite child fixture ${MS_EN_URL} (reuse ATS token — no re-export)"
  # Re-export against /en/ rotates the shared device token and breaks :9083 binds.
  # Copy credentials from ats-9083.json and only rewrite api_base_url.
  if [[ -f "${FIXTURE_DIR}/ats-9083.json" ]]; then
    python3 - "${FIXTURE_DIR}/ats-9083.json" "${FIXTURE_DIR}/ms-9083-en.json" "${MS_EN_URL}" <<'PY'
import json, sys
from pathlib import Path
src, dst, en_url = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3].rstrip("/") + "/"
data = json.loads(src.read_text())
data["api_base_url"] = en_url
# Keep wp_client_token + route_secret identical to parent site.
dst.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
print(f"wrote {dst} api_base_url={en_url}")
PY
    ok "ms-en fixture derived (token reused)"
  else
    warn "ats-9083.json missing — cannot derive ms-en"
  fi
fi

SITE_COUNT="$(find "${FIXTURE_DIR}" -maxdepth 1 -name '*.json' | wc -l | tr -d ' ')"
log "fixture count=${SITE_COUNT} → ${FIXTURE_DIR}"
MIN_SITES="${MULTI_SITE_MIN_SITES:-3}"
if [[ "${SITE_COUNT}" -ge 5 ]]; then
  MIN_SITES="${MULTI_SITE_MIN_SITES:-5}"
fi

# --- Playwright journey ---
log "▶ Playwright multi-site client deep journey (min_sites=${MIN_SITES})"
cd "${E2E_DIR}/playwright"
set +e
REPORT_DIR="${REPORT_DIR}" \
  MULTI_SITE_FIXTURES_DIR="${FIXTURE_DIR}" \
  MULTI_SITE_MIN_SITES="${MIN_SITES}" \
  CLIENT_BASE="${CLIENT_BASE}" \
  WEBUI_A_BASE="${CLIENT_BASE}" \
  MOCK_API_BASE="${MOCK_API_BASE}" \
  npx playwright test -c playwright.client-ui-setup.config.ts \
    client-ui-setup/multi-site-client-deep.journey.spec.ts \
    >"${REPORT_DIR}/playwright.log" 2>&1
PW_EC=$?
set -e
if [[ "${PW_EC}" -ne 0 ]]; then
  err "Playwright failed (exit ${PW_EC}) — ${REPORT_DIR}/playwright.log"
  tail -80 "${REPORT_DIR}/playwright.log" >&2 || true
  exit "${PW_EC}"
fi
ok "Playwright journey passed"

# Snapshot jobs from live client before teardown
curl --noproxy '*' -fsS "${CLIENT_BASE}/api/jobs" >"${REPORT_DIR}/jobs-snapshot.json" 2>/dev/null || true
curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" >"${REPORT_DIR}/status-snapshot.json" 2>/dev/null || true

# --- Log error-rate hard gate (client JSONL) ---
# Fail when translation/media errors are still massively elevated after Run Once.
LOG_GATE_MAX_ALL_FIELDS_FAILED="${LOG_GATE_MAX_ALL_FIELDS_FAILED:-5}"
LOG_GATE_MAX_FIELD_TRANSLATE_FAILED="${LOG_GATE_MAX_FIELD_TRANSLATE_FAILED:-20}"
LOG_GATE_MAX_MEDIA_UPLOAD_FAILED="${LOG_GATE_MAX_MEDIA_UPLOAD_FAILED:-3}"
LOG_GATE_MAX_CALLBACK_FAILED="${LOG_GATE_MAX_CALLBACK_FAILED:-5}"
python3 - "${STATE_ROOT}/state/client.log" "${REPORT_DIR}/log-gate.json" \
  "${LOG_GATE_MAX_ALL_FIELDS_FAILED}" \
  "${LOG_GATE_MAX_FIELD_TRANSLATE_FAILED}" \
  "${LOG_GATE_MAX_MEDIA_UPLOAD_FAILED}" \
  "${LOG_GATE_MAX_CALLBACK_FAILED}" <<'PY'
import json, sys
from collections import Counter
from pathlib import Path

log_path = Path(sys.argv[1])
out_path = Path(sys.argv[2])
limits = {
    "discovery.all_fields_failed": int(sys.argv[3]),
    "discovery.field_translate_failed": int(sys.argv[4]),
    "media.upload_failed": int(sys.argv[5]),
    "discovery.callback_failed": int(sys.argv[6]),
}
counts = Counter()
if log_path.is_file():
    for line in log_path.read_text(errors="replace").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            ev = json.loads(line)
        except json.JSONDecodeError:
            continue
        event = ev.get("event") or ev.get("msg") or ""
        if event in limits:
            counts[event] += 1
        # Also count top-level error events for evidence.
        if ev.get("level") == "error":
            counts["_error_level"] += 1

breaches = {
    k: {"count": counts.get(k, 0), "max": mx}
    for k, mx in limits.items()
    if counts.get(k, 0) > mx
}
result = {
    "ok": not breaches,
    "counts": {k: counts.get(k, 0) for k in limits},
    "error_level_total": counts.get("_error_level", 0),
    "limits": limits,
    "breaches": breaches,
    "log_file": str(log_path),
}
out_path.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
print(json.dumps(result, indent=2, ensure_ascii=False))
if breaches:
    sys.exit(2)
PY
LOG_GATE_EC=$?
if [[ "${LOG_GATE_EC}" -ne 0 ]]; then
  err "client.log error-rate gate failed — ${REPORT_DIR}/log-gate.json"
  cat "${REPORT_DIR}/log-gate.json" >&2 || true
  exit "${LOG_GATE_EC}"
fi
ok "client.log error-rate gate passed"

python3 - "${REPORT_DIR}" "${CLIENT_BASE}" <<'PY'
import json, os, sys
from pathlib import Path
report = Path(sys.argv[1])
journeys = sorted(report.glob("multi-site-client-deep-*.json"))
journey = {}
if journeys:
    journey = json.loads(journeys[-1].read_text())
log_gate = {}
lg = report / "log-gate.json"
if lg.is_file():
    log_gate = json.loads(lg.read_text())
summary = {
    "task": "multi-site-client-deep",
    "report_dir": str(report),
    "client_base": sys.argv[2],
    "sites_bound_via_ui": journey.get("sites_bound_via_ui", 0),
    "sites": journey.get("sites", []),
    "bindings_count": journey.get("bindings_count"),
    "jobs_count": journey.get("jobs_count"),
    "discovery_tasks_count": journey.get("discovery_tasks_count"),
    "component_id": journey.get("component_id"),
    "ms_en": journey.get("ms_en"),
    "steps": journey.get("steps", []),
    "ok": journey.get("ok", False),
    "log_gate": log_gate,
    "evidence": {
        "journey": str(journeys[-1]) if journeys else None,
        "jobs_snapshot": str(report / "jobs-snapshot.json"),
        "status_snapshot": str(report / "status-snapshot.json"),
        "client_db": str(report / "client-state" / "wptsall.db"),
        "playwright_log": str(report / "playwright.log"),
        "log_gate": str(report / "log-gate.json"),
        "client_log": str(report / "client-state" / "client.log"),
    },
}
(report / "summary.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n")
(report.parent / "latest-summary.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n")
print(json.dumps(summary, indent=2, ensure_ascii=False))
if not summary["ok"]:
    sys.exit(1)
PY

ok "multi-site client deep PASSED — ${REPORT_DIR}/summary.json"
