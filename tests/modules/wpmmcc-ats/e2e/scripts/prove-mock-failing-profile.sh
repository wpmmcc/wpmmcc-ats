#!/usr/bin/env bash
# T-MOCK fault lane: prove main mock-api mock-failing headers + languages/models/SSE.
# Starts a throwaway mock on E2E_MOCK_FAULT_PORT (default 19090) so it does not
# collide with the shared :9090 lab mock.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../../../../.." && pwd)"
REPORTS="${SCRIPT_DIR}/../reports"
RUNTIME="${SCRIPT_DIR}/../runtime"
mkdir -p "${REPORTS}" "${RUNTIME}"

PORT="${E2E_MOCK_FAULT_PORT:-19090}"
BASE="http://127.0.0.1:${PORT}"
BIN="${ROOT_DIR}/tests/infra/mock-api/target/release/mock-translate-api"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${REPORTS}/mock-failing-prove-${TS}.json"
PID_FILE="${RUNTIME}/mock-failing-prove.pid"
LOG_FILE="${RUNTIME}/mock-failing-prove.log"

if [[ ! -x "${BIN}" ]]; then
  echo "missing ${BIN}; build with: cargo build --release -C tests/infra/mock-api" >&2
  exit 2
fi

stop_mock() {
  if [[ -f "${PID_FILE}" ]]; then
    kill "$(cat "${PID_FILE}")" 2>/dev/null || true
    rm -f "${PID_FILE}"
  fi
}
trap stop_mock EXIT

stop_mock
MOCK_TRANSLATE_PORT="${PORT}" nohup "${BIN}" >"${LOG_FILE}" 2>&1 &
echo $! >"${PID_FILE}"

for _ in $(seq 1 50); do
  if curl -fsS -m 1 "${BASE}/api/v1/health" >/dev/null 2>&1 \
    || curl -fsS -m 1 "${BASE}/health" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done

python3 - "${BASE}" "${OUT}" <<'PY'
import json, sys, urllib.request, urllib.error

base, out_path = sys.argv[1:3]
results = []


def call(method, path, headers=None, body=None, timeout=8):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(base + path, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    req.add_header("Authorization", "Bearer mock-translate-dev-key-2026")
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode()
            ctype = resp.headers.get("content-type", "")
            return resp.status, ctype, raw
    except urllib.error.HTTPError as e:
        raw = e.read().decode() if e.fp else ""
        return e.code, e.headers.get("content-type", ""), raw
    except Exception as e:
        return 0, "", str(e)


def check(name, ok, detail):
    results.append({"name": name, "ok": bool(ok), "detail": detail})
    print(("PASS" if ok else "FAIL"), name, detail)


# Forced faults on a provider route
st, _, raw = call(
    "POST",
    "/v1/chat/completions",
    headers={"X-Mock-Fail-Mode": "429"},
    body={"model": "m", "messages": [{"role": "user", "content": "x"}]},
)
check("fail_mode_429", st == 429, f"status={st} body={raw[:120]}")

st, _, raw = call(
    "POST",
    "/v1/chat/completions",
    headers={"X-Mock-Fail-Mode": "500"},
    body={"model": "m", "messages": [{"role": "user", "content": "x"}]},
)
check("fail_mode_500", st == 500, f"status={st} body={raw[:120]}")

st, _, raw = call(
    "POST",
    "/v1/chat/completions",
    headers={"X-Mock-Fail-Mode": "timeout", "X-Mock-Timeout-Ms": "200"},
    body={"model": "m", "messages": [{"role": "user", "content": "x"}]},
    timeout=5,
)
check("fail_mode_timeout", st in (504, 408, 500) or "timeout" in raw.lower(), f"status={st} body={raw[:120]}")

# Catalog endpoints
st, _, raw = call("GET", "/api/v1/languages")
check("languages", st == 200 and ("language" in raw.lower() or "zh" in raw.lower() or "[" in raw), f"status={st}")

st, _, raw = call("GET", "/api/v1/models")
check("models", st == 200 and ("model" in raw.lower() or "[" in raw), f"status={st}")

# SSE stream
st, ctype, raw = call(
    "POST",
    "/v1/chat/completions",
    body={"model": "m", "stream": True, "messages": [{"role": "user", "content": "hi"}]},
)
check(
    "sse_stream",
    st == 200 and ("text/event-stream" in ctype or "data:" in raw or "[DONE]" in raw),
    f"status={st} ctype={ctype} body={raw[:160]}",
)

# Fail-closed: after forced 429, a clean call still succeeds (mock recovers)
st, _, raw = call(
    "POST",
    "/v1/chat/completions",
    body={"model": "m", "messages": [{"role": "user", "content": "recover"}]},
)
check("recover_after_fault", st == 200, f"status={st}")

passed = sum(1 for r in results if r["ok"])
report = {
    "status": "passed" if passed == len(results) else "failed",
    "dry_run": False,
    "passed": passed,
    "total": len(results),
    "checks": results,
}
open(out_path, "w", encoding="utf-8").write(json.dumps(report, indent=2) + "\n")
print(json.dumps(report["status"], indent=2))
print("report:", out_path)
sys.exit(0 if report["status"] == "passed" else 1)
PY
