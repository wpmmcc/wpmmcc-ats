#!/usr/bin/env python3
"""Aggregate phase/project results into one summary JSON."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path


def main() -> int:
    if len(sys.argv) < 3:
        print("Usage: summarize.py <report_dir> <out_json> [gate_failed]", file=sys.stderr)
        return 2
    report_dir = Path(sys.argv[1])
    out = Path(sys.argv[2])
    gate_failed = int(sys.argv[3]) if len(sys.argv) > 3 else 0
    results = []
    for path in sorted(report_dir.glob("phase-*.json")):
        try:
            results.append(json.loads(path.read_text()))
        except Exception as exc:  # noqa: BLE001
            results.append({"file": path.name, "ok": False, "error": str(exc)})
    failed = [r for r in results if not r.get("ok")]
    # Phases that abort before write_phase_result leave no JSON — honor gate FAILED flag.
    ok = len(failed) == 0 and gate_failed == 0
    summary = {
        "task": "content-plugin-full-chain",
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "total_phases": len(results),
        "passed": len(results) - len(failed),
        "failed": len(failed),
        "gate_failed_flag": gate_failed,
        "ok": ok,
        "mock_marker": "【{locale}】…【/{locale}】",
        "phases": results,
    }
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n")
    print(out)
    return 0 if summary["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
