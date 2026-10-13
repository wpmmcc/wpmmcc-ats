#!/usr/bin/env bash
# Human-readable live status for lab follow-up (parent + user).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/../lib/lab-paths.sh"
LOGDIR="${E2E_LAB_LOGDIR}"
EVENTS="${E2E_MATRIX_EVENTS_FILE}"
REPORTS="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"
OUT="${LOGDIR}/LIVE-STATUS.md"
PENDING="${LOGDIR}/PENDING-ACTIONS.json"
WAVE_MARK="${LOGDIR}/current-wave.mark"

python3 - "$EVENTS" "$PENDING" "$REPORTS" "$OUT" "$WAVE_MARK" "$LOGDIR" <<'PY'
import json, os, re, subprocess, sys
from datetime import datetime
from pathlib import Path

events_path, pending_path, reports, out_path, wave_mark, logdir = sys.argv[1:7]
now = datetime.now().astimezone().strftime("%Y-%m-%dT%H:%M:%S%z")
lab_log = Path(logdir)

# wave anchor
wave_start = ""
if Path(wave_mark).exists():
    wave_start = Path(wave_mark).read_text().strip()

lines = Path(events_path).read_text(errors="replace").splitlines() if Path(events_path).exists() else []
# Prefer journey-wave mark for journey tallies; fall back to nightly wave mark.
journey_mark = lab_log / "current-journey-wave.mark"
journey_wave_id = journey_mark.read_text().strip() if journey_mark.exists() else ""
if wave_start:
    ts_prefix = wave_start[:19] if len(wave_start) >= 19 else wave_start
    lines = [l for l in lines if len(l) >= 19 and l[:19] >= ts_prefix]

# Journey events: only after last JOURNEY_WAVE_START matching mark (or last start).
journey_lines = Path(events_path).read_text(errors="replace").splitlines() if Path(events_path).exists() else []
j_start = 0
for i, l in enumerate(journey_lines):
    if "JOURNEY_WAVE_START" not in l:
        continue
    if journey_wave_id and f"id={journey_wave_id}" in l:
        j_start = i
    elif not journey_wave_id:
        j_start = i
journey_lines = journey_lines[j_start:]

passed, failed, stage_failed, signals, phases = set(), set(), [], [], []
journey_passed, journey_failed, journey_running = set(), set(), set()
last_outcome = {}  # project -> "passed" | "failed"
journey_outcome = {}
last_errors = []
journey_errors = []
for l in journey_lines:
    m = re.search(r"project=(\S+)", l)
    proj = m.group(1).rstrip(",") if m else None
    if "JOURNEY_LANE_PASSED" in l and proj:
        journey_passed.add(proj)
        journey_failed.discard(proj)
        journey_running.discard(proj)
        journey_outcome[proj] = "passed"
    if "JOURNEY_LANE_FAILED" in l and proj:
        journey_failed.add(proj)
        journey_running.discard(proj)
        journey_outcome[proj] = "failed"
        journey_errors.append(l)
        last_errors.append(l)
    if "JOURNEY_LANE_START" in l and proj:
        if journey_outcome.get(proj) not in ("passed", "failed"):
            journey_running.add(proj)

for l in lines:
    m = re.search(r"project=(\S+)", l)
    proj = m.group(1).rstrip(",") if m else None
    if "MATRIX_LANE_PASSED" in l and proj:
        passed.add(proj)
        last_outcome[proj] = "passed"
    if "RUN_PASSED" in l and proj:
        passed.add(proj)
        failed.discard(proj)
        last_outcome[proj] = "passed"
    if ("MATRIX_LANE_FAILED" in l or "RUN_FAILED" in l) and proj:
        if last_outcome.get(proj) != "passed":
            failed.add(proj)
            last_outcome[proj] = "failed"
        last_errors.append(l)
    if "STAGE_FAILED" in l:
        stage_failed.append(l)
        last_errors.append(l)
    if "SIGNAL_" in l:
        signals.append(l)
        last_errors.append(l)
    if "NIGHTLY_PHASE_PASSED" in l or "NIGHTLY_PHASE_FAILED" in l:
        phases.append(l)
    if "NIGHTLY_PHASE_FAILED" in l:
        last_errors.append(l)

open_actions = []
if Path(pending_path).exists():
    try:
        d = json.loads(Path(pending_path).read_text())
        open_actions = [a for a in d.get("actions", []) if a.get("status") == "open"]
    except Exception:
        pass

def pgrep(pattern):
    r = subprocess.run(["pgrep", "-af", pattern], capture_output=True, text=True)
    return [l for l in r.stdout.splitlines() if "pgrep" not in l and "dump_bash_state" not in l]

procs = {
    "nightly": pgrep("run-lab-nightly.sh"),
    "matrix": pgrep("run-project-matrix.sh"),
    "journey_wave": pgrep("run-content-plugin-journey-wave.sh"),
    "journey_lane": pgrep("run.sh --skip-seed --with-plugin-journeys"),
    "ct": pgrep("run-component-template-lane.sh"),
    "cross": pgrep("run-content-vendor"),
}

running = any(procs.values())
verdict = "RUNNING" if running else ("NEEDS_FIX" if (failed or journey_failed or open_actions) else "IDLE")

md = []
md.append(f"# Lab Live Status\n")
md.append(f"**Updated:** {now}  ")
md.append(f"**Verdict:** {verdict}\n")

md.append("## Processes\n")
for k, ps in procs.items():
    md.append(f"- **{k}:** {'yes (' + str(len(ps)) + ')' if ps else 'no'}")
md.append("")

md.append("## Matrix (this wave)\n")
md.append(f"- **Passed lanes:** {len(passed)}/20")
if passed:
    md.append(f"- `{', '.join(sorted(passed)[:8])}{'…' if len(passed)>8 else ''}`")
md.append(f"- **Failed lanes:** {len(failed)}")
if failed:
    md.append(f"- `{', '.join(sorted(failed))}`")
md.append("")

md.append("## Journey wave (Stage 8)\n")
md.append(f"- **Journey passed:** {len(journey_passed)}/20")
if journey_passed:
    md.append(f"- `{', '.join(sorted(journey_passed)[:8])}{'…' if len(journey_passed)>8 else ''}`")
md.append(f"- **Journey failed:** {len(journey_failed)}")
if journey_failed:
    md.append(f"- `{', '.join(sorted(journey_failed))}`")
if journey_running:
    md.append(f"- **Journey running:** `{', '.join(sorted(journey_running))}`")
journey_status = lab_log / "JOURNEY-WAVE-STATUS.json"
if journey_status.exists():
    try:
        js = json.loads(journey_status.read_text())
        for proj, hint in (js.get("failures") or {}).items():
            md.append(f"- **{proj}:** `{hint[:120]}`")
    except Exception:
        pass
md.append(f"- Status JSON: `{journey_status}`")
md.append("")

if phases:
    md.append("## Nightly phases\n")
    for p in phases[-8:]:
        md.append(f"- `{p[:120]}`")
    md.append("")

if open_actions:
    md.append("## Open Task actions (parent must dispatch)\n")
    for a in open_actions[:10]:
        md.append(f"- **{a.get('key')}** ({a.get('class')}) — {a.get('project')}")
    md.append("")

if journey_errors:
    md.append("## Journey lane errors\n")
    for e in journey_errors[-8:]:
        md.append(f"- `{e[:160]}`")
    md.append("")

if last_errors:
    md.append("## Recent errors / signals\n")
    for e in last_errors[-8:]:
        md.append(f"- `{e[:140]}`")
    md.append("")

if stage_failed:
    md.append("## Stage failures\n")
    for s in stage_failed[-5:]:
        md.append(f"- `{s[:140]}`")
    md.append("")

md.append("## Parent follow-up\n")
md.append("1. **ACTION wake** → dispatch flat Task with `PENDING-ACTIONS` prompt")
md.append("2. **mark done** → `lab-cli-mark-action-done.sh <key>`")
md.append("3. **redo** → `lab-cli-redo-lane.sh <project> [slot]`")
md.append("4. **journey redo** → `lab-cli-redo-lane.sh <project> [slot] --with-journeys`")
md.append(f"5. **journey status** → `cat {lab_log}/JOURNEY-WAVE-STATUS.json`")
md.append("6. **PROGRESS wake** → update user briefly; no Task unless open_actions>0")
md.append("")
md.append(f"See also: `{pending_path}`, `{lab_log}/agent-wake.log`")

Path(out_path).write_text("\n".join(md) + "\n")
print(out_path)
PY
