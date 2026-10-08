#!/usr/bin/env bash
# Mark one PENDING-ACTIONS entry done (parent after Task subagent fix).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/../lib/lab-paths.sh"
PENDING="${WPTSALL_PENDING_ACTIONS:-${E2E_LAB_LOGDIR}/PENDING-ACTIONS.json}"
KEY="${1:?usage: lab-cli-mark-action-done.sh <class:project> [note]}"
NOTE="${2:-done by parent}"
python3 - "$PENDING" "$KEY" "$NOTE" <<'PY'
import json, sys, time, pathlib
path, key, note = sys.argv[1:4]
p = pathlib.Path(path)
data = {"updated": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "actions": []}
if p.exists():
    try:
        data = json.loads(p.read_text() or "{}")
    except Exception:
        pass
for a in data.get("actions") or []:
    if a.get("key") == key and a.get("status") == "open":
        a["status"] = "done"
        a["resolved_at"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
        a["resolve_note"] = note
data["updated"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
p.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
print(f"marked done: {key}")
PY
