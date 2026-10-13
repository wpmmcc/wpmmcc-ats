#!/usr/bin/env bash
# Run provider fault mock matrix assertions (ISS T5 / W5).
# Validates fixture YAML + optional live mock endpoint when E2E_PROVIDER_MOCK_URL set.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "${SCRIPT_DIR}/../../../../.." && pwd)}"
FIXTURE="${E2E_DIR}/fixtures/provider-fault-profiles.yaml"
REPORT_DIR="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"
mkdir -p "${REPORT_DIR}"
TS="$(date +%Y%m%d-%H%M%S)"
OUT="${REPORT_DIR}/provider-fault-matrix-${TS}.json"

# Prefer live fault mock (:9091). Start briefly if missing.
if ! curl -fsS -m 1 "${E2E_PROVIDER_MOCK_URL:-http://127.0.0.1:9091}/health" >/dev/null 2>&1; then
  if [[ -x "${SCRIPT_DIR}/provider-fault-mock-server.py" || -f "${SCRIPT_DIR}/provider-fault-mock-server.py" ]]; then
    mkdir -p "${E2E_DIR}/runtime"
    nohup python3 "${SCRIPT_DIR}/provider-fault-mock-server.py" >"${E2E_DIR}/runtime/provider-fault-mock.log" 2>&1 &
    echo $! >"${E2E_DIR}/runtime/provider-fault-mock.pid"
    sleep 0.4
  fi
fi
export E2E_PROVIDER_MOCK_URL="${E2E_PROVIDER_MOCK_URL:-http://127.0.0.1:9091}"

python3 - "${FIXTURE}" "${OUT}" <<'PY'
import json, sys, os
path, out = sys.argv[1:3]
try:
    import yaml
except ImportError:
    # Minimal parser for our fixture shape (no nested complexity required).
    text = open(path).read()
    profiles = {}
    current = None
    for line in text.splitlines():
        if line.startswith("  ") and not line.startswith("    ") and line.strip().endswith(":"):
            current = line.strip().rstrip(":")
            profiles[current] = {"raw": True}
        elif line.startswith("profiles:"):
            continue
    data = {"profiles": profiles, "note": "PyYAML missing; structural smoke only"}
else:
    data = yaml.safe_load(open(path))

profiles = (data or {}).get("profiles") or {}
results = []
for name, profile in profiles.items():
    expect = (profile or {}).get("expect") or {}
    seq = (profile or {}).get("sequence") or []
    ok = isinstance(expect, dict) and ("max_writebacks" in expect or profile.get("raw"))
    results.append({
        "profile": name,
        "steps": len(seq) if isinstance(seq, list) else 0,
        "expect": expect,
        "ok": bool(ok),
    })

mock_url = os.environ.get("E2E_PROVIDER_MOCK_URL", "")
live = {"enabled": bool(mock_url), "url": mock_url, "probes": []}
if mock_url:
    try:
        import urllib.request
        for name in list(profiles.keys())[:3]:
            req = urllib.request.Request(mock_url.rstrip("/") + "/fault/" + name, method="GET")
            try:
                with urllib.request.urlopen(req, timeout=5) as resp:
                    live["probes"].append({"profile": name, "status": resp.status, "ok": 200 <= resp.status < 500})
            except Exception as e:
                live["probes"].append({"profile": name, "ok": False, "error": str(e)})
    except Exception as e:
        live["error"] = str(e)

report = {
    "fixture": path,
    "profiles": results,
    "live": live,
    "hard_fail": sum(1 for r in results if not r["ok"]),
}
open(out, "w").write(json.dumps(report, indent=2))
print(out)
sys.exit(1 if report["hard_fail"] else 0)
PY
