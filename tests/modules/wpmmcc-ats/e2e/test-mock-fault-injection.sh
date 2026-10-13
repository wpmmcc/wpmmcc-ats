#!/usr/bin/env bash
# Systematic Mock API Fault Injection & Error Recovery Verification Lane
# Validates:
#   1. Querying current fault injection config (GET /api/v1/fault-injection)
#   2. Latency / delay injection (mode: delay) with verified elapsed time
#   3. Rate-limiting (HTTP 429) with retry-after header and automatic error recovery
#   4. Upstream server error (HTTP 500) with automatic recovery
#   5. Upstream gateway timeout (HTTP 504) with automatic recovery
#   6. Explicit fault reset (POST /api/v1/fault-injection/reset)
set -euo pipefail

_SD="$(cd "$(dirname "$0")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"

MOCK_URL="${MOCK_API_URL:-http://127.0.0.1:9090}"

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }

echo "== Systematic Mock API Fault Injection Lane =="

# 1. Verify Mock API reachability
if ! curl -sf --max-time 3 "${MOCK_URL}/api/v1/health" >/dev/null 2>&1; then
  fail "Mock API is not reachable at ${MOCK_URL}"
fi
pass "mock API reachable"

# Ensure clean slate
curl -s -X POST "${MOCK_URL}/api/v1/fault-injection/reset" >/dev/null

# 2. Verify Initial Config
INIT_CONF="$(curl -s "${MOCK_URL}/api/v1/fault-injection")"
python3 -c "
import json
data = json.loads('''${INIT_CONF}''')
assert data.get('enabled') is False, f'Expected disabled initially, got {data}'
"
pass "initial fault-injection state verified disabled"

# 3. Test Delay Injection (Latency Simulation)
echo "Testing delay injection (250ms)..."
curl -s -X POST "${MOCK_URL}/api/v1/fault-injection" \
  -H 'Content-Type: application/json' \
  -d '{"enabled":true,"mode":"delay","delay_ms":250,"remaining_count":1}' >/dev/null

python3 - <<PY
import time, urllib.request, json

start = time.time()
req = urllib.request.Request(
    "${MOCK_URL}/api/v1/translate/text",
    headers={"Authorization": "Bearer test", "Content-Type": "application/json"},
    data=json.dumps({"text": "latency test", "source_lang": "en", "target_lang": "zh"}).encode()
)
with urllib.request.urlopen(req) as resp:
    assert resp.status == 200
    body = json.loads(resp.read().decode())
    assert "【zh】" in body.get("translated_text", "")

elapsed_ms = (time.time() - start) * 1000
print(f"Delayed request elapsed: {elapsed_ms:.1f}ms")
assert elapsed_ms >= 240, f"Expected >= 240ms, got {elapsed_ms:.1f}ms"
PY
pass "delay fault injection verified (elapsed >= 250ms)"

# 4. Test 429 Rate Limiting with Auto-Recovery
echo "Testing 429 rate-limiting with auto-recovery (2 failures then recover)..."
curl -s -X POST "${MOCK_URL}/api/v1/fault-injection" \
  -H 'Content-Type: application/json' \
  -d '{"enabled":true,"mode":"429","retry_after_secs":2,"remaining_count":2}' >/dev/null

python3 - <<PY
import urllib.request, urllib.error, json

url = "${MOCK_URL}/api/v1/translate/text"
headers = {"Authorization": "Bearer test", "Content-Type": "application/json"}
payload = json.dumps({"text": "rate limit test", "source_lang": "en", "target_lang": "zh"}).encode()

# Call 1: expect 429
req1 = urllib.request.Request(url, headers=headers, data=payload)
try:
    urllib.request.urlopen(req1)
    assert False, "Call 1 should have returned 429"
except urllib.error.HTTPError as e:
    assert e.code == 429, f"Expected 429, got {e.code}"
    assert e.headers.get("retry-after") == "2", f"Expected retry-after: 2, got {e.headers.get('retry-after')}"
    body = json.loads(e.read().decode())
    assert body.get("error", {}).get("code") == "rate_limit_exceeded"
    print("Call 1: correctly received HTTP 429 with retry-after: 2")

# Call 2: expect 429
req2 = urllib.request.Request(url, headers=headers, data=payload)
try:
    urllib.request.urlopen(req2)
    assert False, "Call 2 should have returned 429"
except urllib.error.HTTPError as e:
    assert e.code == 429, f"Expected 429, got {e.code}"
    print("Call 2: correctly received HTTP 429")

# Call 3: expect 200 OK (auto-recovered!)
req3 = urllib.request.Request(url, headers=headers, data=payload)
with urllib.request.urlopen(req3) as resp:
    assert resp.status == 200, f"Expected 200, got {resp.status}"
    body = json.loads(resp.read().decode())
    assert "【zh】" in body.get("translated_text", "")
    print("Call 3: auto-recovered to HTTP 200 OK")
PY
pass "429 rate limit injection and automatic error recovery verified"

# 5. Test 500 Internal Server Error with Auto-Recovery
echo "Testing 500 error injection with auto-recovery..."
curl -s -X POST "${MOCK_URL}/api/v1/fault-injection" \
  -H 'Content-Type: application/json' \
  -d '{"enabled":true,"mode":"500","remaining_count":1}' >/dev/null

python3 - <<PY
import urllib.request, urllib.error, json

url = "${MOCK_URL}/api/v1/translate/text"
headers = {"Authorization": "Bearer test", "Content-Type": "application/json"}
payload = json.dumps({"text": "server error test", "source_lang": "en", "target_lang": "zh"}).encode()

# Call 1: expect 500
req1 = urllib.request.Request(url, headers=headers, data=payload)
try:
    urllib.request.urlopen(req1)
    assert False, "Call 1 should have returned 500"
except urllib.error.HTTPError as e:
    assert e.code == 500, f"Expected 500, got {e.code}"
    print("Call 1: correctly received HTTP 500")

# Call 2: expect 200 OK (auto-recovered!)
req2 = urllib.request.Request(url, headers=headers, data=payload)
with urllib.request.urlopen(req2) as resp:
    assert resp.status == 200
    print("Call 2: auto-recovered to HTTP 200 OK")
PY
pass "500 server error injection and automatic recovery verified"

# 6. Test 504 Timeout with Auto-Recovery
echo "Testing timeout injection with auto-recovery..."
curl -s -X POST "${MOCK_URL}/api/v1/fault-injection" \
  -H 'Content-Type: application/json' \
  -d '{"enabled":true,"mode":"timeout","delay_ms":100,"remaining_count":1}' >/dev/null

python3 - <<PY
import urllib.request, urllib.error, json

url = "${MOCK_URL}/api/v1/translate/text"
headers = {"Authorization": "Bearer test", "Content-Type": "application/json"}
payload = json.dumps({"text": "timeout test", "source_lang": "en", "target_lang": "zh"}).encode()

# Call 1: expect 504
req1 = urllib.request.Request(url, headers=headers, data=payload)
try:
    urllib.request.urlopen(req1)
    assert False, "Call 1 should have returned 504"
except urllib.error.HTTPError as e:
    assert e.code == 504, f"Expected 504, got {e.code}"
    print("Call 1: correctly received HTTP 504 Gateway Timeout")

# Call 2: expect 200 OK (auto-recovered!)
req2 = urllib.request.Request(url, headers=headers, data=payload)
with urllib.request.urlopen(req2) as resp:
    assert resp.status == 200
    print("Call 2: auto-recovered to HTTP 200 OK")
PY
pass "timeout injection and automatic recovery verified"

# 7. Test Explicit Reset
echo "Testing explicit reset endpoint..."
curl -s -X POST "${MOCK_URL}/api/v1/fault-injection" \
  -H 'Content-Type: application/json' \
  -d '{"enabled":true,"mode":"429","remaining_count":100}' >/dev/null

RESET_RESP="$(curl -s -X POST "${MOCK_URL}/api/v1/fault-injection/reset")"
python3 -c "
import json
data = json.loads('''${RESET_RESP}''')
assert data.get('success') is True
"
CONF_AFTER_RESET="$(curl -s "${MOCK_URL}/api/v1/fault-injection")"
python3 -c "
import json
data = json.loads('''${CONF_AFTER_RESET}''')
assert data.get('enabled') is False
"
pass "explicit reset verified"

REPORT_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${REPORT_DIR}"
cat <<EOF > "${REPORT_DIR}/mock-fault-injection-latest.json"
{
  "test_id": "TEST-E2E-MOCK-FAULT-INJECTION-001",
  "requirement_id": "REQ-WPTSALL-MOCK-FAULT-001",
  "scenario_id": "SCENARIO-MOCK-FAULT-INJECTION-AND-RECOVERY",
  "user_journey_id": "UJ9",
  "user_journey_ids": ["UJ9", "UJ12"],
  "status": "passed",
  "fault_modes_tested": ["delay", "429_rate_limit", "500_server_error", "504_timeout"],
  "auto_recovery_verified": true,
  "explicit_reset_verified": true,
  "timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
}
EOF

echo "✅ [test:mock-fault-injection] passed (all fault modes & error recoveries verified)"
