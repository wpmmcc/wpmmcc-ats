#!/usr/bin/env bash
# Identity-chain PERFORMANCE smoke — N-binding verify pass under one
# worker run, with a wall-time budget.
#
# What it measures (Identity Contract v1.1 §3 verify pass):
#   - The inline web-UI worker (`POST /api/worker/run-once`) verifying N
#     wpmmcc-family bindings against N isolated php mock responders in a
#     single pass: every binding must land plugin_identity + a fresh
#     identity_verified_at, with zero identity.* failure events.
#   - Wall time of the run-once call vs. a per-binding budget (default
#     5s/binding, min 30s). This is a smoke budget, not a benchmark: it
#     catches O(N^2) regressions, per-binding retry storms, or accidental
#     serialization on locks — not fine-grained latency.
#
# Each binding gets its OWN mock origin (distinct loopback port), so the
# domain-token map sees N distinct api_base_urls exactly like production
# multi-site fleets.
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-identity-perf-smoke.sh [N]
#   N default 12 (range 2..40). PERF_BUDGET_SECS_PER_BINDING overrides.
#
# Isolated lane; NOT part of release-gate / Lab matrix.
# Requires: client binary built (client-wpplugin), php-cli on the host.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
export WPTSALL_LAB="${WPTSALL_LAB:-1}"

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

N_BINDINGS="${1:-12}"
[[ "${N_BINDINGS}" =~ ^[0-9]+$ ]] || abort "N must be an integer (got '${N_BINDINGS}')"
[[ "${N_BINDINGS}" -ge 2 && "${N_BINDINGS}" -le 40 ]] || abort "N out of range 2..40 (got '${N_BINDINGS}')"

PERF_CLIENT_BASE="${PERF_CLIENT_BASE:-http://127.0.0.1:8997}"
PERF_MOCK_PORT_FIRST="${PERF_MOCK_PORT_FIRST:-9300}"
PERF_BUDGET_PER_BINDING="${PERF_BUDGET_SECS_PER_BINDING:-5}"
PERF_BUDGET_MIN="${PERF_BUDGET_MIN_SECS:-30}"
BUDGET=$(( N_BINDINGS * PERF_BUDGET_PER_BINDING ))
(( BUDGET < PERF_BUDGET_MIN )) && BUDGET=${PERF_BUDGET_MIN}

REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/identity-perf-smoke"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${RUNTIME_DIR}/identity-perf-smoke"
PERF_TOKEN="identity-perf-token-c3d4"
PERF_SECRET="identity-perf-secret"
mkdir -p "${REPORT_ROOT}" "${RUN_DIR}"
RESULTS_FILE="${RUN_DIR}/results.json"

PERF_CLIENT_PORT="$(echo "${PERF_CLIENT_BASE}" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|')"

port_of() { echo "$1" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|'; }

resolve_client_binary() {
  local candidates=(
    "${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release-lab/wptsall-client"
  )
  local c
  for c in "${candidates[@]}"; do
    if [[ -x "$c" ]]; then echo "$c"; return 0; fi
  done
  return 1
}

MOCK_PIDS=()
MOCK_URLS=()
# Origins are deterministic (port range), so build the full list up front —
# the seed writer needs it BEFORE the mock processes start.
for i in $(seq 1 "${N_BINDINGS}"); do
  MOCK_URLS+=("http://127.0.0.1:$(( PERF_MOCK_PORT_FIRST + i - 1 ))")
done

start_all_mocks() {
  local router="${RUN_DIR}/mock-perf-router.php"
  cat >"${router}" <<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'];
file_put_contents(getenv('GATE_HITS_FILE'), $_SERVER['REQUEST_METHOD'] . ' ' . $uri . "\n", FILE_APPEND);
if ($uri === '/' || $uri === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return;
}
// wpmmcc family only: identity matches what the bindings declare, so the
// whole pass verifies (perf lane asserts the happy path at volume).
$body = json_encode([
    'success' => true,
    'data' => [
        'plugin_identity' => 'wpmmcc',
        'plugin_version' => '9.9.9-mock',
        'site_platform' => 'wp',
    ],
]);
$key = hash_hkdf('sha256', getenv('GATE_MOCK_TOKEN'), 32, 'wptsall-signing-v1', 'request-signing');
$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
header('Content-Type: application/json');
header('X-WPTSALL-Transport: plaintext');
header('X-WPTSALL-Response-Signature: ' . $sig);
header('Content-Length: ' . strlen($body));
echo $body;
PHP
  local i port base
  for i in $(seq 1 "${N_BINDINGS}"); do
    port=$(( PERF_MOCK_PORT_FIRST + i - 1 ))
    base="${MOCK_URLS[$(( i - 1 ))]}"
    GATE_HITS_FILE="${RUN_DIR}/mock-hits.log" GATE_MOCK_TOKEN="${PERF_TOKEN}" \
      nohup php -S "127.0.0.1:${port}" "${router}" \
      >"${RUN_DIR}/mock-${port}.log" 2>&1 </dev/null &
    MOCK_PIDS+=($!)
  done
  local ready=0
  for _ in $(seq 1 40); do
    ready=0
    for base in "${MOCK_URLS[@]}"; do
      check_url "${base}/" || { ready=1; break; }
    done
    [[ "${ready}" == "0" ]] && return 0
    sleep 1
  done
  abort "perf mock fleet not ready (see ${RUN_DIR}/mock-*.log)"
}

stop_all_mocks() {
  local pid
  for pid in "${MOCK_PIDS[@]:-}"; do
    [[ -n "${pid}" ]] && kill "${pid}" 2>/dev/null || true
    [[ -n "${pid}" ]] && wait "${pid}" 2>/dev/null || true
  done
  MOCK_PIDS=()
}

CLIENT_PID=""
start_case_client() {
  local case_dir="$1"
  local bin
  bin="$(resolve_client_binary)" || abort "wptsall-client binary not found; build client-wpplugin first"
  mkdir -p "${case_dir}/data"
  nohup env \
    WPTSALL_WEB_UI=1 \
    WPTSALL_WEB_UI_PORT="${PERF_CLIENT_PORT}" \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${PERF_CLIENT_PORT}" \
    WPTSALL_DATA_DIR="${case_dir}/data" \
    WPTSALL_DB_PATH="${case_dir}/data/wptsall.db" \
    WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${case_dir}/domain-token-bindings.json" \
    WPTSALL_PROVIDER_ALLOWLIST="127.0.0.1,localhost" \
    WPTSALL_LOG_ENABLED=1 \
    WPTSALL_LOG_FILE="${case_dir}/structured.log" \
    WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
    WPTSALL_SERVER_URL= \
    "$bin" >"${case_dir}/client.log" 2>&1 </dev/null &
  CLIENT_PID=$!
  for _ in $(seq 1 60); do
    if check_url "${PERF_CLIENT_BASE}/api/status"; then return 0; fi
    sleep 1
  done
  abort "client not ready in ${case_dir} (see ${case_dir}/client.log)"
}

stop_case_client() {
  if [[ -n "${CLIENT_PID}" ]]; then
    kill "${CLIENT_PID}" 2>/dev/null || true
    wait "${CLIENT_PID}" 2>/dev/null || true
    CLIENT_PID=""
  fi
}

cleanup() {
  stop_case_client
  stop_all_mocks
}
trap cleanup EXIT

echo "══════════════════════════════════════════════════════"
echo "  IDENTITY PERF SMOKE (N=${N_BINDINGS} bindings, budget ${BUDGET}s)"
echo "  CLIENT=${PERF_CLIENT_BASE}  MOCKS=${PERF_MOCK_PORT_FIRST}..$(( PERF_MOCK_PORT_FIRST + N_BINDINGS - 1 ))"
echo "══════════════════════════════════════════════════════"

[[ "$(command -v php)" ]] || abort "php-cli is required for the perf mock fleet"

CASE_DIR="${RUN_DIR}/case-perf"
rm -rf "${CASE_DIR}"; mkdir -p "${CASE_DIR}"
: >"${RUN_DIR}/mock-hits.log"

# Seed N distinct origins, all unverified wpmmcc bindings sharing the lane
# token/secret (mock fleet trusts one token — the volume is the domain count).
python3 - "${CASE_DIR}/domain-token-bindings.json" "${PERF_TOKEN}" "${PERF_SECRET}" "${MOCK_URLS[@]}" <<'PY'
import json, sys
out_path, token, secret = sys.argv[1], sys.argv[2], sys.argv[3]
domains = {}
for base in sys.argv[4:]:
    domains[base] = {
        "wp_client_token": token,
        "route_secret": secret,
        "plugin_identity": "wpmmcc",
        "identity_verified_at": None,
    }
print(json.dumps({"version": 3, "domains": domains}, indent=2), end="", file=open(out_path, "w"))
PY

info "PERF: starting ${N_BINDINGS} mock responders"
start_all_mocks
info "PERF: booting isolated client with ${N_BINDINGS} unverified bindings"
start_case_client "${CASE_DIR}"

info "PERF: single run-once pass (budget ${BUDGET}s)"
T0=$(date +%s.%N)
curl -sf -X POST "${PERF_CLIENT_BASE}/api/worker/run-once" \
  -H 'Content-Type: application/json' \
  -d "{\"max_iterations\":1,\"max_elapsed_secs\":$(( BUDGET + 60 )),\"max_items_per_run\":$(( N_BINDINGS + 10 ))}" >/dev/null
T1=$(date +%s.%N)
WALL="$(python3 -c 'import sys; print(f"{float(sys.argv[1])-float(sys.argv[2]):.2f}")' "${T1}" "${T0}")"
info "PERF: run-once wall time ${WALL}s for ${N_BINDINGS} bindings"

# Gather per-binding status from /api/status.
STATUS_JSON="${CASE_DIR}/status.json"
curl -sf "${PERF_CLIENT_BASE}/api/status" >"${STATUS_JSON}"
python3 - "${STATUS_JSON}" "${MOCK_URLS[@]}" >"${RUN_DIR}/binding-check.json" <<'PY'
import json, sys
doc = json.load(open(sys.argv[1]))
items = (doc.get("data") or {}).get("domain_token_bindings") or []
by_base = { (i.get("api_base_url") or "").rstrip("/").lower(): i for i in items }
total = len(sys.argv) - 2
verified = 0
unverified = []
for base in sys.argv[2:]:
    item = by_base.get(base.rstrip("/").lower())
    if not item:
        unverified.append([base, "missing-from-status"])
        continue
    ident, ts = item.get("plugin_identity"), item.get("identity_verified_at")
    if ident == "wpmmcc" and ts:
        verified += 1
    else:
        unverified.append([base, f"identity={ident} verified_at={ts}"])
print(json.dumps({
    "total": total,
    "verified": verified,
    "unverified": unverified,
    "bindings_in_status": len(items),
}, indent=2))
PY

# Per-domain failure events must be zero (happy path at volume). Lane-level
# discoverer events ({"binding_identity","lane"} shape, no api_base_url) are
# expected multi-lane probing noise — same classification as the identity
# chain gate — so only same-line api_base_url events count.
FAIL_EVENTS=$(rg '"event":"identity\.(verify_failed|mismatch|unknown|stale)"' "${CASE_DIR}/structured.log" 2>/dev/null | rg -c "api_base_url" || echo 0)
PING_HITS=$(rg -c "sync/ping" "${RUN_DIR}/mock-hits.log" 2>/dev/null || echo 0)
DISPATCH_LEAKS=$(rg -c "site-relations|/tasks|/content" "${RUN_DIR}/mock-hits.log" 2>/dev/null || echo 0)

VERIFIED=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["verified"])' "${RUN_DIR}/binding-check.json")
BUDGET_OK=$(python3 -c 'import sys; print(1 if float(sys.argv[1]) <= float(sys.argv[2]) else 0)' "${WALL}" "${BUDGET}")

PASS=1 REASON=""
if [[ "${VERIFIED}" != "${N_BINDINGS}" ]]; then
  PASS=0
  REASON="verified ${VERIFIED}/${N_BINDINGS}; see ${RUN_DIR}/binding-check.json"
fi
if [[ "${FAIL_EVENTS}" != "0" ]]; then
  PASS=0; REASON="${REASON:+${REASON}; }identity failure events: ${FAIL_EVENTS}"
fi
if [[ "${DISPATCH_LEAKS}" != "0" ]]; then
  PASS=0; REASON="${REASON:+${REASON}; }dispatch leaked to mocks: ${DISPATCH_LEAKS}"
fi
if [[ "${BUDGET_OK}" != "1" ]]; then
  PASS=0; REASON="${REASON:+${REASON}; }wall ${WALL}s exceeded budget ${BUDGET}s"
fi

AVG="$(python3 -c 'import sys; print(f"{float(sys.argv[1])/int(sys.argv[2]):.2f}")' "${WALL}" "${N_BINDINGS}")"
python3 - "${RESULTS_FILE}" "${N_BINDINGS}" "${WALL}" "${AVG}" "${BUDGET}" "${VERIFIED}" "${FAIL_EVENTS}" "${PING_HITS}" "${DISPATCH_LEAKS}" "${PASS}" "${REASON}" <<'PY'
import json, sys
p = sys.argv[1]
n, wall, avg, budget, verified, fail_events, ping_hits, leaks, ok, reason = sys.argv[2:12]
doc = {
    "task": "identity-perf-smoke",
    "n_bindings": int(n),
    "wall_secs": float(wall),
    "avg_secs_per_binding": float(avg),
    "budget_secs": int(budget),
    "verified": int(verified),
    "identity_failure_events": int(fail_events),
    "ping_hits": int(ping_hits),
    "dispatch_leaks": int(leaks),
    "pass": ok == "1",
    "reason": reason or None,
}
json.dump(doc, open(p, "w"), indent=2)
PY
cp "${RESULTS_FILE}" "${REPORT_ROOT}/summary-${STAMP}.json"

stop_case_client
stop_all_mocks

echo "──────────────────────────────────────────────────────"
if [[ "${PASS}" == "1" ]]; then
  ok "IDENTITY PERF SMOKE PASS: ${VERIFIED}/${N_BINDINGS} verified in ${WALL}s (avg ${AVG}s/binding, budget ${BUDGET}s, pings ${PING_HITS}, failures 0)"
else
  warn "IDENTITY PERF SMOKE FAIL: ${REASON} (report: ${REPORT_ROOT}/summary-${STAMP}.json)"
  abort "identity perf smoke failed"
fi
