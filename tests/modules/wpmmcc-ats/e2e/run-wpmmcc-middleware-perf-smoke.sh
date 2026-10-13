#!/usr/bin/env bash
# wpmmcc REST middleware PERFORMANCE smoke (doc 21 §6 G2).
#
# Closes the wpmmcc-plugin performance gap: batch throughput and exact
# boundary counts for the two middleware hot paths a fleet of clients
# hits every minute —
#   W-P1  maybe_sign_client_response() signing throughput (every
#         signature re-verified with the client-side HKDF+HMAC formula);
#   W-P2  enforce_rate_limit() fixed-window boundary (explicit limit,
#         bucket isolation, limit=0 disable, default 240/min ceiling);
#   W-P3  the combined stack at the documented per-minute ceiling
#         (240 allowed+signed, 20 rejected 429) — the batch-sync shape
#         under rate limiting.
#
# Hermetic: php-only harness on the wpmmcc test bootstrap (in-memory
# options/transients — no WP install, no HTTP). Requires the wpmmcc
# plugin tree in the working copy (colleague's untracked work, loaded
# read-only; WPMMCC_TREE env overrides the path).
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-wpmmcc-middleware-perf-smoke.sh [N]
#   N default 300 (range 10..2000) — W-P1 signing iteration count.
#
# Isolated lane; NOT part of release-gate / Lab matrix.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
export WPTSALL_LAB="${WPTSALL_LAB:-1}"

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

N_ARG="${1:-300}"
HARNESS="${SCRIPT_DIR}/php/wpmmcc-middleware-perf-harness.php"
WPMMCC_TREE="${WPMMCC_TREE:-${REPO_ROOT}/wpmmcc}"

REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/wpmmcc-middleware-perf-smoke"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${RUNTIME_DIR}/wpmmcc-middleware-perf-smoke"
mkdir -p "${REPORT_ROOT}" "${RUN_DIR}"

[[ -f "${HARNESS}" ]] || abort "harness missing: ${HARNESS}"
[[ -d "${WPMMCC_TREE}" ]] || abort "wpmmcc plugin tree missing: ${WPMMCC_TREE} (set WPMMCC_TREE)"
[[ "$(command -v php)" ]] || abort "php-cli is required"

RESULTS_FILE="${RUN_DIR}/results.json"
info "running middleware perf harness (N=${N_ARG}, tree=${WPMMCC_TREE})"
if WPMMCC_TREE="${WPMMCC_TREE}" php "${HARNESS}" "${RESULTS_FILE}" "${N_ARG}"; then
  cp "${RESULTS_FILE}" "${REPORT_ROOT}/summary-${STAMP}.json"
  python3 - "${RESULTS_FILE}" <<'PY'
import json, sys
doc = json.load(open(sys.argv[1]))
for name, c in doc["cases"].items():
    extra = {k: v for k, v in c.items() if k in ("n", "signed", "verified", "allowed", "limited", "wall_ms", "ops_per_sec", "req_per_sec")}
    print(f"  {name}: wall={c['wall_ms']}ms " + json.dumps(extra, separators=(",", ":")))
PY
  ok "WPMMCC MIDDLEWARE PERF SMOKE PASS (report: ${REPORT_ROOT}/summary-${STAMP}.json)"
else
  rc=$?
  cp "${RESULTS_FILE}" "${REPORT_ROOT}/summary-${STAMP}.json" 2>/dev/null || true
  warn "WPMMCC MIDDLEWARE PERF SMOKE FAIL (rc=${rc}; report: ${REPORT_ROOT}/summary-${STAMP}.json, results: ${RESULTS_FILE})"
  abort "wpmmcc middleware perf smoke failed"
fi
