#!/usr/bin/env bash
# Prove ISS T5: provider faults then success + WP writeback ≤ 1.
set -euo pipefail

E2E_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$(dirname "$0")/../../../../.." && pwd)}"
REPORTS="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"
RUNTIME="${E2E_DIR}/runtime"
mkdir -p "${REPORTS}" "${RUNTIME}"
TS="$(date +%Y%m%d-%H%M%S)"
OUT="${REPORTS}/provider-fault-runtime-${TS}.json"
PID_FILE="${RUNTIME}/provider-fault-mock.pid"
LOG_FILE="${RUNTIME}/provider-fault-mock.log"

HOST="${E2E_PROVIDER_FAULT_HOST:-127.0.0.1}"
PORT="${E2E_PROVIDER_FAULT_PORT:-9091}"
BASE="http://${HOST}:${PORT}"
export E2E_PROVIDER_MOCK_URL="${BASE}"

stop_mock() {
  if [[ -f "${PID_FILE}" ]]; then
    kill "$(cat "${PID_FILE}")" 2>/dev/null || true
    rm -f "${PID_FILE}"
  fi
  if command -v fuser >/dev/null 2>&1; then
    fuser -k "${PORT}/tcp" 2>/dev/null || true
  fi
}

start_mock() {
  stop_mock
  nohup python3 "${E2E_DIR}/scripts/provider-fault-mock-server.py" >"${LOG_FILE}" 2>&1 &
  echo $! >"${PID_FILE}"
  for _ in $(seq 1 40); do
    if curl -fsS -m 1 "${BASE}/health" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.15
  done
  echo "[fault-runtime] mock failed to start" >&2
  tail -40 "${LOG_FILE}" >&2 || true
  exit 1
}

trap stop_mock EXIT
start_mock
curl -fsS -X POST "${BASE}/reset" >/dev/null

CID="$(docker ps --filter name=wptsall-wp-lab-wordpress-test --format '{{.Names}}' | head -1 || true)"

python3 - "${BASE}" "${OUT}.profiles.json" <<'PY'
import json, sys, time, urllib.request, urllib.error

base, out_path = sys.argv[1:3]
profiles = ["rate_limit_then_ok", "server_error_recovery", "duplicate_callback"]


def http_json(method, url, body=None, timeout=8):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode()
            return resp.status, json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        raw = e.read().decode() if e.fp else ""
        try:
            payload = json.loads(raw) if raw else {}
        except Exception:
            payload = {"raw": raw}
        return e.code, payload
    except Exception as e:
        return 0, {"error": str(e)}


def run_profile(profile, max_attempts=8):
    http_json("POST", f"{base}/reset")
    attempts = []
    success = False
    for i in range(max_attempts):
        status, _ = http_json(
            "POST",
            f"{base}/fault/{profile}/translate",
            {"text": "hello", "source_lang": "zh", "target_lang": "en", "attempt": i},
        )
        attempts.append({"attempt": i, "status": status})
        if status == 200:
            success = True
            break
        time.sleep(0.12 if status == 429 else 0.05)
    _, st = http_json("GET", f"{base}/fault/{profile}/stats")
    expect = (st or {}).get("expect") or {}
    retries = max(0, len(attempts) - (1 if success else 0))
    ok = bool(success)
    if "retries_min" in expect:
        ok = ok and retries >= int(expect.get("retries_min") or 0)
    # Also record logical writeback simulation (≤1).
    task = f"{profile}-task"
    http_json("POST", f"{base}/simulate/writeback", {"client_task_id": task})
    _, wb1 = http_json("POST", f"{base}/simulate/writeback", {"client_task_id": task})
    wb_ok = int((wb1 or {}).get("writeback_count") or 0) == 2 and (wb1 or {}).get("applied") is False
    # applied False on second call means count==2 but "applied" only true for first;
    # re-check: our server sets applied=(n==1). Second response applied=false ⇒ ok.
    return {
        "profile": profile,
        "ok": ok and wb_ok,
        "success": success,
        "retries": retries,
        "attempts": attempts,
        "expect": expect,
        "writeback_sim": wb1,
        "writeback_sim_ok": wb_ok,
        "stats": (st or {}).get("stats") or {},
    }


results = [run_profile(p) for p in profiles]
payload = {
    "mock": base,
    "profiles": results,
    "hard_fail": sum(1 for r in results if not r.get("ok")),
}
open(out_path, "w").write(json.dumps(payload, indent=2))
print(json.dumps({"profiles_hard_fail": payload["hard_fail"], "path": out_path}, indent=2))
sys.exit(0 if payload["hard_fail"] == 0 else 1)
PY

WB_JSON='{"skipped":true}'
if [[ -n "${CID}" ]]; then
  docker cp "${E2E_DIR}/scripts/prove-fault-writeback.php" "${CID}:/tmp/prove-fault-writeback.php"
  set +e
  WB_JSON="$(docker exec "${CID}" wp eval-file /tmp/prove-fault-writeback.php --allow-root 2>/dev/null | tail -n +1)"
  WB_RC=$?
  set -e
  # Keep only JSON object lines.
  WB_JSON="$(printf '%s\n' "${WB_JSON}" | python3 -c 'import sys,json; t=sys.stdin.read(); i=t.find("{"); print(t[i:] if i>=0 else "{}")')"
else
  WB_RC=0
fi

python3 - "${OUT}" "${OUT}.profiles.json" "${WB_JSON}" "${WB_RC}" <<'PY'
import json, sys
out, profiles_path, wb_raw, wb_rc = sys.argv[1:5]
profiles = json.loads(open(profiles_path).read())
try:
    wb = json.loads(wb_raw)
except Exception:
    wb = {"ok": False, "error": "parse", "raw": wb_raw[:500]}
hard = int(profiles.get("hard_fail") or 0)
if not wb.get("skipped", False) and not wb.get("ok"):
    hard += 1
if int(wb_rc) != 0 and not wb.get("skipped", False):
    hard = max(hard, 1)
report = {
    "mock": profiles.get("mock"),
    "profiles": profiles.get("profiles"),
    "writeback_wp": wb,
    "hard_fail": hard,
    "ok": hard == 0,
}
open(out, "w").write(json.dumps(report, indent=2))
print(json.dumps({"ok": report["ok"], "hard_fail": hard, "out": out, "wp_rows": wb.get("rows"), "wp_ok": wb.get("ok")}, indent=2))
sys.exit(0 if hard == 0 else 1)
PY
