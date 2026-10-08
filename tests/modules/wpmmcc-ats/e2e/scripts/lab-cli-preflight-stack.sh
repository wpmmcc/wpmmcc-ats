#!/usr/bin/env bash
# Quick lab stack health check before starting a wave.
set -euo pipefail
REPO=/home/john/wpmmcc-ats3.0
export WPTSALL_LAB=1
# shellcheck source=/dev/null
source "${REPO}/tests/modules/wpmmcc-ats/e2e/config.sh"

fail=0
check() {
  local name="$1" url="$2" expect="${3:-200}"
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$url" 2>/dev/null || echo "000")
  if [[ "$code" == "$expect" ]]; then
    echo "OK  $name $url ($code)"
  else
    echo "FAIL $name $url (got $code, want $expect)" >&2
    fail=1
  fi
}

check "wp" "${WP_URL}/"
check "mock" "${MOCK_API_URL}/api/v1/health"
check "server" "${SERVER_URL}/health" "200"

# L1 admin API requires WPTSALL_ADMIN_EMAILS on server process
if ! python3 -c "
import urllib.request, sys
try:
    r=urllib.request.urlopen('${SERVER_URL}/api/v1/response-encryption-public-key', timeout=5)
    sys.exit(0 if r.status==200 else 1)
except Exception:
    sys.exit(1)
" 2>/dev/null; then
  echo "WARN server encryption pubkey unreachable" >&2
fi

if [[ "$fail" -ne 0 ]]; then
  echo "lab-preflight: stack not ready — start WP/mock/server before wave" >&2
  exit 1
fi
echo "lab-preflight: ready (WP=${WP_URL})"
