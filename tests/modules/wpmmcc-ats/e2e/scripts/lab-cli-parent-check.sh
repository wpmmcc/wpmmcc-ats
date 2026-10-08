#!/usr/bin/env bash
# Parent handler cheat sheet — run when woken by ACTION or PROGRESS.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/../lib/lab-paths.sh"
LOGDIR="${E2E_LAB_LOGDIR}"
echo "=== LIVE-STATUS ==="
cat "$LOGDIR/LIVE-STATUS.md" 2>/dev/null || echo "(no snapshot yet)"
echo ""
echo "=== LAST WAKES ==="
tail -5 "$LOGDIR/agent-wake.log" 2>/dev/null || true
echo ""
echo "=== OPEN ACTIONS ==="
python3 - "${LOGDIR}/PENDING-ACTIONS.json" <<'PY'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1])
if not p.exists():
    print("none"); raise SystemExit
d = json.loads(p.read_text())
for a in d.get("actions", []):
    if a.get("status") == "open":
        print(f"- {a['key']}: {a.get('prompt','')[:200]}")
PY
