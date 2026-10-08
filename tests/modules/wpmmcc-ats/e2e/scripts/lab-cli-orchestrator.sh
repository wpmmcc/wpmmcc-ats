#!/usr/bin/env bash
# CLI parent wake handler: consume matrix-events → plan/queue/pending Task actions.
# Idempotent via byte offset. Safe to run on every AGENT_LOOP_WAKE / heartbeat.
set -euo pipefail

SCRIPTS="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPTS}/../lib/lab-paths.sh"
LOGDIR="${E2E_LAB_LOGDIR}"
EVENTS="${E2E_MATRIX_EVENTS_FILE}"
OFFSET_FILE="$LOGDIR/orchestrator.offset"
QUEUE="$LOGDIR/FIX-QUEUE.md"
STATUS="$LOGDIR/FOLLOWUP-AGENT-STATUS.md"
PENDING="$LOGDIR/PENDING-ACTIONS.json"
PLAN="$LOGDIR/TEST-PLAN.md"
DIGEST="$LOGDIR/orchestrator-digest.log"
REPORTS="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"

mkdir -p "$LOGDIR"
touch "$EVENTS" "$DIGEST"
[[ -f "$OFFSET_FILE" ]] || echo 0 >"$OFFSET_FILE"

offset=$(cat "$OFFSET_FILE" 2>/dev/null || echo 0)
size=$(wc -c <"$EVENTS" | tr -d ' ')
if [[ "$size" -lt "$offset" ]]; then
  offset=0
fi

# Extract new lines
new_lines=$(tail -c +"$((offset + 1))" "$EVENTS" 2>/dev/null || true)
echo "$size" >"$OFFSET_FILE"

ts=$(date -Iseconds)
actions_tmp=$(mktemp)
: >"$actions_tmp"

classify_line() {
  local line="$1"
  local project kind class
  project=$(echo "$line" | sed -n 's/.*project=\([^ ]*\).*/\1/p')
  kind=$(echo "$line" | awk '{print $2}')
  class=other
  case "$line" in
    *SIGNAL_CLIENT_SHARED*|*hit_shared_8977*|*:8977*) class=client_slot ;;
    *SIGNAL_CLIENT_SLOT*|*slot_econnrefused*|*refusing\ fallback*) class=client_slot ;;
    *SIGNAL_FRONTEND*|*search-home*|*Frontend\ route*) class=stage7_frontend ;;
    *SIGNAL_TIMEOUT_FN*|*wp_lab_docker_exec*) class=timeout_fn ;;
    *SIGNAL_WRITEBACK*|*writeback_fail*|*posts\ exist*) class=writeback_zero ;;
    *STAGE_FAILED*stage=6*name=client-translate*) class=stage6 ;;
    *STAGE_FAILED*stage=7*name=verify*) class=stage7_frontend ;;
    *JOURNEY_LANE_FAILED*|*"plugin journeys failed"*) class=journey_failed ;;
    *JOURNEY_WAVE_FAILED*) class=journey_wave_failed ;;
    *MATRIX_LANE_FAILED*|*RUN_FAILED*|*ABORT*|*SIGNAL_ABORT*) class=lane_failed ;;
    *NIGHTLY_PHASE_FAILED*) class=phase_failed ;;
    *NIGHTLY_PHASE_PASSED*name=content_matrix*) class=matrix_done ;;
    *MATRIX_LANE_PASSED*|*JOURNEY_LANE_PASSED*) class=lane_passed ;;
    *JOURNEY_WAVE_START*|*JOURNEY_BATCH_DONE*|*JOURNEY_WAVE_PASSED*) class=progress ;;
    *STAGE_START*|*STAGE_PASSED*|*NIGHTLY_PHASE_START*|*NIGHTLY_PHASE_PASSED*) class=progress ;;
    *) class=other ;;
  esac
  printf '%s\t%s\t%s\t%s\n' "$ts" "$kind" "${project:-?}" "$class"
}

needs_task() {
  case "$1" in
    client_slot|stage7_frontend|timeout_fn|writeback_zero|stage6|lane_failed|phase_failed|journey_failed|journey_wave_failed) return 0 ;;
    *) return 1 ;;
  esac
}

task_prompt_for() {
  local class="$1" project="$2" line="$3"
  case "$class" in
    client_slot)
      echo "Fix Lab parallel CLIENT_BASE/slot client so Playwright uses 9077-9084 not :8977. Project hint=${project}. Event=${line}. Repo=/home/john/wpmmcc-ats3.0. Do not kill shared :8977. Patch and verify with a quick curl/slot status."
      ;;
    stage7_frontend)
      echo "Fix Stage7 virtual frontend HTTP failure (prefer /en_us/ not search-home-2 500). Project=${project}. Event=${line}. Repo=/home/john/wpmmcc-ats3.0. Patch resolve-virtual-frontend-target.php and/or 07-verify.sh; prove curl http://127.0.0.1:9083/en_us/ returns 200."
      ;;
    timeout_fn)
      echo "Fix run_with_timeout so bash functions like wp_lab_docker_exec work (no GNU timeout on functions). Event=${line}. Repo=/home/john/wpmmcc-ats3.0 tests/modules/wpmmcc-ats/e2e/config.sh."
      ;;
    writeback_zero)
      echo "Diagnose Stage6/7 write-back 0 posts for project=${project}. Event=${line}. Check slot CLIENT_BASE, worker run-once, Lab SERVER_BASE. Repo=/home/john/wpmmcc-ats3.0. Fix root cause; do not deep-reset WP while other lanes run."
      ;;
    stage6)
      echo "Stage6 failed for project=${project}. Event=${line}. Diagnose PW/client/auth and fix. Repo=/home/john/wpmmcc-ats3.0."
      ;;
    lane_failed|phase_failed)
      echo "Triage failed lane/phase for project=${project}. Event=${line}. Read newest matching matrix-lane log under ${REPORTS}, identify root class, apply minimal fix. Repo=/home/john/wpmmcc-ats3.0. Do not source run-project-matrix.sh."
      ;;
    journey_failed)
      echo "Fix Stage8 plugin-content journey failure for project=${project}. Event=${line}. Read ${LOGDIR}/journey-wave-*-${project}.log and ${LOGDIR}/JOURNEY-WAVE-STATUS.json failures[${project}]. Prefer generic Virtual_Site_Router / Playwright helpers / plugin-journeys.yaml fixes — no hardcoded post IDs or sample URLs. Repo=${REPO_ROOT}. After fix: bash tests/modules/wpmmcc-ats/e2e/scripts/lab-cli-redo-lane.sh ${project} --with-journeys (pick free slot). Then mark action done."
      ;;
    journey_wave_failed)
      echo "Journey wave failed. Event=${line}. Read ${LOGDIR}/JOURNEY-WAVE-STATUS.json + LIVE-STATUS.md. Triage open journey_failed actions, fix root causes generically, redo failed projects with --with-journeys. Repo=${REPO_ROOT}."
      ;;
    *)
      echo "Inspect event for project=${project}: ${line}"
      ;;
  esac
}

new_task_count=0
progress_count=0
pass_count=0
fail_projects=()

if [[ -n "${new_lines}" ]]; then
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    echo "$line" >>"$DIGEST"
    row=$(classify_line "$line")
    kind=$(echo "$row" | cut -f2)
    project=$(echo "$row" | cut -f3)
    class=$(echo "$row" | cut -f4)

    case "$class" in
      progress) progress_count=$((progress_count + 1)) ;;
      lane_passed) pass_count=$((pass_count + 1)) ;;
    esac

    if needs_task "$class"; then
      # dedupe open actions by class+project
      if grep -q "\"class\":\"${class}\".*\"project\":\"${project}\"" "$PENDING" 2>/dev/null; then
        continue
      fi
      if grep -q $'\t'"${class}"$'\t'"${project}"$'\t'open "$QUEUE" 2>/dev/null; then
        continue
      fi
      prompt=$(task_prompt_for "$class" "$project" "$line")
      printf '%s\n' "$prompt" >>"$actions_tmp"
      {
        echo "| ${ts} | ${kind} | ${project} | ${class} | Task | open |"
      } >>"$QUEUE"
      fail_projects+=("$project")
      new_task_count=$((new_task_count + 1))
      # structured pending line for parent Task tool
      python3 - "$PENDING" "$class" "$project" "$prompt" "$line" <<'PY'
import json,sys,pathlib,time
path, cls, proj, prompt, ev = sys.argv[1:6]
p = pathlib.Path(path)
data = {"updated": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "actions": []}
if p.exists():
    try:
        data = json.loads(p.read_text() or "{}")
    except Exception:
        pass
actions = data.get("actions") or []
# drop completed
actions = [a for a in actions if a.get("status") != "done"]
key = f"{cls}:{proj}"
if not any(a.get("key")==key and a.get("status")=="open" for a in actions):
    actions.append({
        "key": key,
        "class": cls,
        "project": proj,
        "status": "open",
        "prompt": prompt,
        "event": ev,
    })
data["actions"] = actions
data["updated"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
p.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
PY
    fi
  done <<<"$new_lines"
fi

# Live tally from events + lane logs
lane_pass=$(grep -c 'MATRIX_LANE_PASSED' "$EVENTS" 2>/dev/null || true)
lane_fail=$(grep -c 'MATRIX_LANE_FAILED' "$EVENTS" 2>/dev/null || true)
lane_pass=${lane_pass:-0}
lane_fail=${lane_fail:-0}
lane_pass=$(printf '%s' "$lane_pass" | head -n1 | tr -cd '0-9')
lane_fail=$(printf '%s' "$lane_fail" | head -n1 | tr -cd '0-9')
lane_pass=${lane_pass:-0}
lane_fail=${lane_fail:-0}
nightly_alive=0
pgrep -f 'run-lab-nightly.sh --no-fail-fast' >/dev/null 2>&1 && nightly_alive=1

cat >"$STATUS" <<EOF
# CLI Parent Orchestrator Status

**Updated:** ${ts}  
**Verdict:** $([ "$nightly_alive" -eq 1 ] && echo RUNNING || echo NIGHTLY_DOWN)  
**Nightly alive:** ${nightly_alive}  
**Events processed offset:** ${size}  
**This wake:** new_tasks=${new_task_count} progress=${progress_count} passes_seen=${pass_count}

## Counts (event bus)
- MATRIX_LANE_PASSED: ${lane_pass}
- MATRIX_LANE_FAILED: ${lane_fail}

## Pending Task actions
\`\`\`json
$(cat "$PENDING" 2>/dev/null || echo '{"actions":[]}')
\`\`\`

## Parent duty on this wake
1. If PENDING-ACTIONS has status=open → launch flat Task subagent(s) with each prompt
2. On Task completion → mark action done; schedule redo of project if lane_failed
3. On matrix phase passed → run redo_failed batch then allow CT/cross/hotplug
4. Never \`source run-project-matrix.sh\` (executes matrix)

## Plan
See ${PLAN}
EOF

# Machine-readable summary for wake payload
LANE_PASS="$lane_pass" LANE_FAIL="$lane_fail" NIGHTLY_ALIVE="$nightly_alive" NEW_TASKS="$new_task_count" \
TS="$ts" PENDING_PATH="$PENDING" python3 - <<'PY'
import json, os, pathlib
pending = pathlib.Path(os.environ["PENDING_PATH"])
actions = []
if pending.exists():
    try:
        actions = [a for a in json.loads(pending.read_text()).get("actions", []) if a.get("status")=="open"]
    except Exception:
        pass
print(json.dumps({
  "ts": os.environ.get("TS", ""),
  "nightly_alive": int(os.environ.get("NIGHTLY_ALIVE") or 0),
  "new_tasks": int(os.environ.get("NEW_TASKS") or 0),
  "open_actions": len(actions),
  "lane_pass": int(os.environ.get("LANE_PASS") or 0),
  "lane_fail": int(os.environ.get("LANE_FAIL") or 0),
  "actions": actions,
}, ensure_ascii=False))
PY

# Wake hint for parent when there is work
if [[ "$new_task_count" -gt 0 ]]; then
  echo "AGENT_ORCH_NEED_TASK open=${new_task_count}"
fi
