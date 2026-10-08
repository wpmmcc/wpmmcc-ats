#!/usr/bin/env bash
# Merge per-worker Playwright report fragments into the single report file
# consumed by run-manual-content-plugin-matrix.sh (combine_report).
#
# When PW_WORKERS > 1, manual-content-plugin-matrix.gate.e2e.spec.ts writes
# one fragment per worker: <report>.w<workerIndex>. Each fragment holds the
# results recorded by that worker process plus its own network counters.
# This script merges them back into <report> so downstream consumers see the
# same single-file shape as the legacy single-worker run:
#   - results: concatenated in worker order (stable, deterministic)
#   - network.total_requests: summed
#   - network.forbidden_requests: concatenated
# If no fragments exist (single-worker mode), the base file is left untouched.
#
# Usage: bash merge-playwright-worker-reports.sh <report-file>
set -euo pipefail

REPORT="${1:?usage: merge-playwright-worker-reports.sh <report-file>}"

shopt -s nullglob
FRAGMENTS=( "${REPORT}".w* )
if [[ "${#FRAGMENTS[@]}" -eq 0 ]]; then
  if [[ -f "${REPORT}" ]]; then
    echo "[merge-pw] no fragments; single-worker report already at ${REPORT}"
    exit 0
  fi
  echo "[merge-pw] ERROR: no report and no fragments at ${REPORT}" >&2
  exit 1
fi

python3 - "$REPORT" "${FRAGMENTS[@]}" <<'PY'
import json
import sys
from pathlib import Path

report = Path(sys.argv[1])
fragments = [Path(p) for p in sys.argv[2:]]
# Deterministic order: .w0, .w1, ... (lexicographic works while indexes < 10;
# sort numerically to be safe for larger worker counts).
fragments.sort(key=lambda p: int(p.name.rsplit(".w", 1)[1]))

merged = None
results = []
total_requests = 0
forbidden = []
for frag in fragments:
    data = json.loads(frag.read_text())
    if merged is None:
        merged = data
    results.extend(data.get("results", []))
    network = data.get("network") or {}
    total_requests += int(network.get("total_requests", 0) or 0)
    forbidden.extend(network.get("forbidden_requests", []) or [])

merged["results"] = results
merged["network"] = {
    "total_requests": total_requests,
    "forbidden_requests": forbidden,
}
report.write_text(json.dumps(merged, ensure_ascii=False, indent=2) + "\n")
for frag in fragments:
    frag.unlink()
print(f"[merge-pw] merged {len(fragments)} fragment(s) into {report} ({len(results)} results)")
PY
