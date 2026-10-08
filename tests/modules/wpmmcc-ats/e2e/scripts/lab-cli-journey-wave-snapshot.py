#!/usr/bin/env python3
"""Update JOURNEY-WAVE-STATUS.json from matrix-events + lane logs (fast parent read)."""
from __future__ import annotations

import json
import re
import sys
from datetime import datetime, timezone
import json, os, sys
from pathlib import Path

E2E_DIR = Path(__file__).resolve().parent.parent
LOGDIR = Path(os.environ.get("E2E_LAB_LOGDIR", str(E2E_DIR / "lab-logs")))


def parse_events(events_path: Path, wave_id: str | None) -> dict:
    all_lines = events_path.read_text(errors="replace").splitlines() if events_path.exists() else []
    # Scope to current wave only: from last matching JOURNEY_WAVE_START (or wave_id mark).
    start_idx = 0
    for i, line in enumerate(all_lines):
        if "JOURNEY_WAVE_START" not in line:
            continue
        if wave_id and f"id={wave_id}" in line:
            start_idx = i
        elif not wave_id:
            start_idx = i
    lines = all_lines[start_idx:]

    state: dict = {
        "wave_id": wave_id,
        "passed": [],
        "failed": [],
        "running": [],
        "last_event": "",
    }
    for line in lines:
        if "JOURNEY_WAVE_START" in line:
            m = re.search(r"id=(\S+)", line)
            if m:
                state["wave_id"] = m.group(1)
                wave_id = state["wave_id"]
                # Reset tallies at wave boundary inside slice
                state["passed"] = []
                state["failed"] = []
                state["running"] = []
        state["last_event"] = line[-200:]
        m = re.search(r"project=(\S+)", line)
        if not m:
            continue
        proj = m.group(1).rstrip(",")
        if "JOURNEY_LANE_START" in line:
            if proj not in state["passed"] and proj not in state["failed"]:
                if proj not in state["running"]:
                    state["running"].append(proj)
        if "JOURNEY_LANE_PASSED" in line:
            state["running"] = [p for p in state["running"] if p != proj]
            if proj not in state["passed"]:
                state["passed"].append(proj)
            state["failed"] = [p for p in state["failed"] if p != proj]
        if "JOURNEY_LANE_FAILED" in line:
            state["running"] = [p for p in state["running"] if p != proj]
            if proj not in state["failed"]:
                state["failed"].append(proj)
            state["passed"] = [p for p in state["passed"] if p != proj]
    return state


def lane_failure_hint(log_path: Path) -> str:
    if not log_path.is_file():
        return ""
    text = log_path.read_text(errors="replace")
    patterns = [
        r"plugin journeys failed:[^\n]+",
        r"STAGE_FAILED[^\n]+",
        r"E2E run classified as[^\n]+",
        r"ABORT:[^\n]+",
        r"Slot live runs cannot[^\n]+",
    ]
    for pat in patterns:
        m = re.search(pat, text)
        if m:
            return m.group(0)[:240]
    tail = " ".join(text.splitlines()[-4:])
    return tail[:240]


def main() -> None:
    events = Path(sys.argv[1]) if len(sys.argv) > 1 else LOGDIR / "matrix-events.log"
    out = Path(sys.argv[2]) if len(sys.argv) > 2 else LOGDIR / "JOURNEY-WAVE-STATUS.json"
    wave_mark = LOGDIR / "current-journey-wave.mark"

    wave_id = wave_mark.read_text().strip() if wave_mark.exists() else None
    state = parse_events(events, wave_id)

    failures: dict[str, str] = {}
    wid = state.get("wave_id") or ""
    for proj in state.get("failed", []):
        log = LOGDIR / f"journey-wave-{wid}-{proj}.log"
        if not log.exists():
            # newest matching log
            matches = sorted(LOGDIR.glob(f"journey-wave-*-{proj}.log"), key=lambda p: p.stat().st_mtime, reverse=True)
            log = matches[0] if matches else log
        hint = lane_failure_hint(log)
        if hint:
            failures[proj] = hint

    payload = {
        "updated_at": datetime.now(timezone.utc).isoformat(),
        "wave_id": state.get("wave_id"),
        "passed": sorted(state.get("passed", [])),
        "failed": sorted(state.get("failed", [])),
        "running": sorted(state.get("running", [])),
        "passed_count": len(state.get("passed", [])),
        "failed_count": len(state.get("failed", [])),
        "total": 20,
        "failures": failures,
        "wave_log": str(LOGDIR / f"journey-wave-{wid}.log") if wid else "",
        "last_event": state.get("last_event", ""),
    }
    out.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")
    print(out)


if __name__ == "__main__":
    main()
