#!/usr/bin/env bash
# Fixture-based self-tests for lib/manual-isolation.sh (P0-EV-01 acceptance).
#
# Builds synthetic state directories (fake processes, listeners, log events,
# WP HTTP attempts, mock counters) and asserts that:
#   - a clean fixture passes `manual_isolation_assert`
#   - each individual violation fails it
#   - vendor (non-infrastructure) blocked requests do NOT fail it
#   - `manual_isolation_write_json` reflects the verdicts in its JSON block
#
# Usage: bash tests/modules/wpmmcc-ats/e2e/lib/test-manual-observer.sh
# Exits 0 when all assertions hold; prints a PASS/FAIL line per case.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/manual-isolation.sh"

PASS=0
FAIL=0

note() { echo "[$1] $2"; }

# run_assert <fixture-dir> <expect: 0|1> <label>
run_assert() {
  local dir="$1" expect="$2" label="$3"
  local rc
  MI_STATE_DIR="$dir" manual_isolation_assert >/dev/null 2>&1
  rc=$?
  if [[ "$rc" -eq "$expect" ]]; then
    note "PASS" "$label (exit=$rc)"
    PASS=$((PASS + 1))
  else
    note "FAIL" "$label (exit=$rc, expected $expect)"
    FAIL=$((FAIL + 1))
  fi
}

# write_json_to <fixture-dir> <out-file> <label> <expect-ok: true|false>
run_write_json() {
  local dir="$1" out="$2" label="$3" expect_ok="$4"
  local ok
  MI_STATE_DIR="$dir" manual_isolation_write_json "$out" >/dev/null 2>&1
  if [[ ! -f "$out" ]]; then
    note "FAIL" "$label (no output file)"
    FAIL=$((FAIL + 1))
    return
  fi
  ok=$(python3 -c "import json,sys;print(str(json.load(open('$out'))['ok']).lower())")
  if [[ "$ok" == "$expect_ok" ]]; then
    note "PASS" "$label (json ok=$ok)"
    PASS=$((PASS + 1))
  else
    note "FAIL" "$label (json ok=$ok, expected $expect_ok)"
    FAIL=$((FAIL + 1))
  fi
}

new_fixture() {
  local d
  d=$(mktemp -d /tmp/observer-fixture.XXXXXX)
  : >"${d}/processes.jsonl"
  : >"${d}/listeners.jsonl"
  : >"${d}/log-hits.jsonl"
  echo '{"unavailable":false}' >"${d}/wp-http-status.json"
  echo '{"unavailable":false,"attempts":[],"count":0}' >"${d}/wp-http-report.json"
  echo '{"reachable":false,"body":null}' >"${d}/mock-before.json"
  echo '{"reachable":false,"body":null}' >"${d}/mock-after.json"
  echo "$d"
}

echo "== Fixture tests for manual-isolation.sh =="

# 1. Clean fixture passes.
D=$(new_fixture); run_assert "$D" 0 "clean fixture passes"; rm -rf "$D"

# 1b. Watchdog unavailable -> the REQUIRED WordPress HTTP guard cannot be
#     observed, so the assert must fail (fail-closed, same as write_json).
D=$(new_fixture)
echo '{"unavailable":true,"reason":"watchdog-not-installed"}' >"${D}/wp-http-status.json"
echo '{"unavailable":true,"reason":"watchdog-not-installed"}' >"${D}/wp-http-report.json"
run_assert "$D" 1 "unavailable watchdog fails (requirement: observable or fail)"
rm -rf "$D"

# 2. Forbidden process fails.
D=$(new_fixture)
echo '{"phase":"preflight","pid":999,"match":"cmdline","exe":"/tmp/mock-translate-api","cmdline":"/tmp/mock-translate-api 300","started_at":"x"}' \
  >"${D}/processes.jsonl"
run_assert "$D" 1 "forbidden process fails"
rm -rf "$D"

# 3. Forbidden listener fails.
D=$(new_fixture)
echo '{"phase":"preflight","port":8977,"line":"LISTEN 127.0.0.1:8977"}' >"${D}/listeners.jsonl"
run_assert "$D" 1 "forbidden listener fails"
rm -rf "$D"

# 4. Forbidden log event fails.
D=$(new_fixture)
echo '{"phase":"finish","log":"./logs/wptsall-client.log","line":"worker start"}' >"${D}/log-hits.jsonl"
run_assert "$D" 1 "forbidden log event fails"
rm -rf "$D"

# 5. Forbidden-infrastructure WP HTTP attempt fails.
D=$(new_fixture)
echo '{"unavailable":false,"attempts":[{"host":"127.0.0.1","port":"8977","severity":"forbidden_infra","path":"/api/status"}]}' \
  >"${D}/wp-http-report.json"
run_assert "$D" 1 "forbidden_infra WP HTTP attempt fails"
rm -rf "$D"

# 6. Vendor-only blocked requests do NOT fail (evidence only).
D=$(new_fixture)
echo '{"unavailable":false,"attempts":[{"host":"api.wordpress.org","port":0,"severity":"vendor","path":"/x"},{"host":"assets.elementor.com","port":0,"severity":"vendor","path":"/y"}]}' \
  >"${D}/wp-http-report.json"
run_assert "$D" 0 "vendor-only blocked requests pass (evidence only)"
rm -rf "$D"

# 7. Mock counter drift fails.
D=$(new_fixture)
echo '{"reachable":true,"body":{"data":{"total_requests":5}}}' >"${D}/mock-before.json"
echo '{"reachable":true,"body":{"data":{"total_requests":9}}}' >"${D}/mock-after.json"
run_assert "$D" 1 "mock counter drift fails"
rm -rf "$D"

# 8. Mock counter stable passes.
D=$(new_fixture)
echo '{"reachable":true,"body":{"data":{"total_requests":5}}}' >"${D}/mock-before.json"
echo '{"reachable":true,"body":{"data":{"total_requests":5}}}' >"${D}/mock-after.json"
run_assert "$D" 0 "mock counter stable passes"
rm -rf "$D"

# 9. write_json: clean fixture -> ok=true.
D=$(new_fixture)
run_write_json "$D" "/tmp/observer-out-clean.json" "write_json clean -> ok=true" "true"
python3 -c "
import json
d = json.load(open('/tmp/observer-out-clean.json'))
assert d['checks']['no_forbidden_processes'] is True
assert d['checks']['wordpress_http_guard'] is True
assert d['mock_provider']['assertion'] == 'not_applicable_absent'
assert 'wptsall_retry_failed_tasks' in d['cron']['hooks']
print('PASS structure checks on clean output')
" && PASS=$((PASS + 1)) || FAIL=$((FAIL + 1))
rm -rf "$D" /tmp/observer-out-clean.json

# 10. write_json: vendor-only fixture -> ok=true with evidence recorded.
D=$(new_fixture)
echo '{"unavailable":false,"attempts":[{"host":"api.wordpress.org","port":0,"severity":"vendor","path":"/x"}]}' \
  >"${D}/wp-http-report.json"
run_write_json "$D" "/tmp/observer-out-vendor.json" "write_json vendor-only -> ok=true" "true"
python3 -c "
import json
d = json.load(open('/tmp/observer-out-vendor.json'))
w = d['wordpress_forbidden_http_attempts']
assert w['total_attempts'] == 1
assert w['forbidden_infra_attempts'] == []
assert w['blocked_vendor_requests']['count'] == 1
assert w['blocked_vendor_requests']['hosts']['api.wordpress.org'] == 1
print('PASS vendor evidence recorded in output')
" && PASS=$((PASS + 1)) || FAIL=$((FAIL + 1))
rm -rf "$D" /tmp/observer-out-vendor.json

# 11. write_json: infra fixture -> ok=false.
D=$(new_fixture)
echo '{"unavailable":false,"attempts":[{"host":"127.0.0.1","port":"9090","severity":"forbidden_infra","path":"/stats"}]}' \
  >"${D}/wp-http-report.json"
run_write_json "$D" "/tmp/observer-out-infra.json" "write_json infra -> ok=false" "false"
rm -rf "$D" /tmp/observer-out-infra.json

# 12. write_json: process fixture -> browser_guard stays None for gate 1.
D=$(new_fixture)
echo '{"phase":"finish","pid":123,"match":"cmdline","exe":"/x","cmdline":"y","started_at":"z"}' >"${D}/processes.jsonl"
run_write_json "$D" "/tmp/observer-out-proc.json" "write_json process -> ok=false" "false"
python3 -c "
import json
d = json.load(open('/tmp/observer-out-proc.json'))
assert d['checks']['browser_guard'] is None, 'browser_guard must stay None until the browser harness sets it'
assert len(d['forbidden_processes']) == 1
print('PASS process evidence + browser_guard=None in output')
" && PASS=$((PASS + 1)) || FAIL=$((FAIL + 1))
rm -rf "$D" /tmp/observer-out-proc.json

echo
echo "== Result: $PASS passed, $FAIL failed =="
exit "$FAIL"
