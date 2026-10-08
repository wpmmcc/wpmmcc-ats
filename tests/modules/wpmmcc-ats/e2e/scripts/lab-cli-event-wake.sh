#!/usr/bin/env bash
# Event-driven parent wake: ACTION (fail/fix), PROGRESS (milestone), HEARTBEAT (fallback only).
# Monitored shell: notify on ^AGENT_LOOP_WAKE_labnightly (ACTION|PROGRESS)
set -euo pipefail
SCRIPTS="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPTS}/../lib/lab-paths.sh"
EVENTS="${E2E_MATRIX_EVENTS_FILE}"
LOGDIR="${E2E_LAB_LOGDIR}"
PENDING="${LOGDIR}/PENDING-ACTIONS.json"
WAKE_LOG="${LOGDIR}/agent-wake.log"
FOLLOWUP_LOG="${LOGDIR}/LAB-FOLLOWUP.log"
WAVE_MARK="${LOGDIR}/current-wave.mark"
FALLBACK_SEC="${LAB_WAKE_FALLBACK_SEC:-120}"
POLL_SEC="${LAB_PARENT_POLL_SEC:-300}"
mkdir -p "$LOGDIR"
touch "$EVENTS" "$WAKE_LOG" "$FOLLOWUP_LOG"
[[ -f "$WAVE_MARK" ]] || date -Iseconds >"$WAVE_MARK"

wake_level() {
  case "$1" in
    *MATRIX_LANE_FAILED*|*RUN_FAILED*|*STAGE_FAILED*|*NIGHTLY_PHASE_FAILED*|*ABORT*)
      echo ACTION ;;
    *JOURNEY_LANE_FAILED*|*JOURNEY_WAVE_FAILED*)
      echo ACTION ;;
    *SIGNAL_CLIENT_*|*SIGNAL_FRONTEND*|*SIGNAL_TIMEOUT_FN*|*SIGNAL_WRITEBACK*|*SIGNAL_SLOT_ABORT*|*SIGNAL_ABORT*)
      echo ACTION ;;
    *NIGHTLY_WAVE_START*|*NIGHTLY_RESUME*|*NIGHTLY_PHASE_START*|*NIGHTLY_PHASE_PASSED*)
      echo PROGRESS ;;
    *MATRIX_LANE_PASSED*|*JOURNEY_LANE_PASSED*|*JOURNEY_WAVE_START*|*JOURNEY_BATCH_DONE*)
      echo PROGRESS ;;
    *JOURNEY_WAVE_PASSED*)
      echo PROGRESS ;;
    *) echo SKIP ;;
  esac
}

emit_wake() {
  local level="$1" detail="$2"
  bash "$SCRIPTS/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
  python3 "$SCRIPTS/lab-cli-journey-wave-snapshot.py" >/dev/null 2>&1 || true
  local orch_json open=0 prompt="" summary="" journey_summary=""
  orch_json=$(bash "$SCRIPTS/lab-cli-orchestrator.sh" 2>>"$LOGDIR/orch.err" || echo '{}')
  printf '%s\n' "$orch_json" >"$LOGDIR/last-orch.json"
  open=$(printf '%s' "$orch_json" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("open_actions",0))' 2>/dev/null || echo 0)
  if [[ "$open" -gt 0 ]]; then
    prompt=$(printf '%s' "$orch_json" | python3 -c 'import json,sys; d=json.load(sys.stdin); a=d.get("actions") or []; print(a[0].get("prompt","") if a else "")' 2>/dev/null || true)
    level=ACTION
  fi
  summary=$(grep -E '^\*\*Verdict|Passed lanes|Failed lanes|Journey passed|Journey failed|Open Task' "$LOGDIR/LIVE-STATUS.md" 2>/dev/null | head -8 | tr '\n' ' ' || true)
  journey_summary=$(python3 -c "import json; d=json.load(open('$LOGDIR/JOURNEY-WAVE-STATUS.json')); print(f\"journey {d.get('passed_count',0)}/{d.get('total',20)} pass failed={d.get('failed_count',0)} running={len(d.get('running',[]))}\")" 2>/dev/null || true)
  [[ -n "$journey_summary" ]] && detail="${detail} | ${journey_summary}"
  local payload
  payload=$(LEVEL="$level" DETAIL="$detail" OPEN="$open" PROMPT="$prompt" SUMMARY="$summary" python3 - <<'PY'
import json, os
print(json.dumps({
  "level": os.environ.get("LEVEL",""),
  "detail": os.environ.get("DETAIL",""),
  "open_actions": int(os.environ.get("OPEN") or 0),
  "prompt": os.environ.get("PROMPT",""),
  "summary": os.environ.get("SUMMARY","")[:400],
}, ensure_ascii=False))
PY
)
  local ts
  ts=$(date -Iseconds)
  local line="AGENT_LOOP_WAKE_labnightly ${level} ${payload}"
  printf '%s\n' "$line" | tee -a "$WAKE_LOG"
  # Monitored ACTION wakes: only on NEW open-action keys (dedupe heartbeat/poll spam).
  if [[ "$level" == "ACTION" ]]; then
    local keys_now keys_prev
    keys_now=$(python3 -c "import json; d=json.load(open('$PENDING')); print(','.join(sorted(a['key'] for a in d.get('actions',[]) if a.get('status')=='open')))" 2>/dev/null || true)
    keys_prev=$(cat "${LOGDIR}/last-action-wake-keys.txt" 2>/dev/null || true)
    if [[ -n "$keys_now" && "$keys_now" != "$keys_prev" ]]; then
      printf '%s\n' "$keys_now" >"${LOGDIR}/last-action-wake-keys.txt"
      printf '%s\n' "$line" >>"${LOGDIR}/agent-wake-monitored.log"
    fi
  fi
  printf '%s [%s] %s | %s\n' "$ts" "$level" "$detail" "$summary" >>"$FOLLOWUP_LOG"
}

count_real_procs() {
  local pattern="$1" n=0
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    [[ "$line" == *dump_bash_state* ]] && continue
    [[ "$line" == *"while pgrep -f"* ]] && continue
    [[ "$line" == *lab-cli-event-wake* ]] && continue
    n=$((n + 1))
  done < <(pgrep -af "$pattern" 2>/dev/null || true)
  echo "$n"
}

emit_poll_tick() {
  local nightly ct cross journey open verdict passed failed jpass jfail
  nightly=$(count_real_procs 'run-lab-nightly.sh')
  ct=$(count_real_procs 'run-component-template-lane.sh')
  cross=$(count_real_procs 'run-content-vendor-sample-matrix.sh')
  journey=$(count_real_procs 'run-content-plugin-journey-wave.sh')
  [[ "$nightly" == 0 && "$ct" == 0 && "$cross" == 0 && "$journey" == 0 ]] && return 0
  bash "$SCRIPTS/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
  python3 "$SCRIPTS/lab-cli-journey-wave-snapshot.py" >/dev/null 2>&1 || true
  verdict=$(grep '^\*\*Verdict' "$LOGDIR/LIVE-STATUS.md" 2>/dev/null | head -1 | sed 's/\*\*Verdict:\*\* //' || echo UNKNOWN)
  passed=$(grep 'Passed lanes' "$LOGDIR/LIVE-STATUS.md" 2>/dev/null | head -1 | sed 's/- //' || echo '')
  failed=$(grep 'Failed lanes' "$LOGDIR/LIVE-STATUS.md" 2>/dev/null | head -1 | sed 's/- //' || echo '')
  jpass=$(grep 'Journey passed' "$LOGDIR/LIVE-STATUS.md" 2>/dev/null | head -1 | sed 's/- //' || echo '')
  jfail=$(grep 'Journey failed' "$LOGDIR/LIVE-STATUS.md" 2>/dev/null | head -1 | sed 's/- //' || echo '')
  open=$(python3 -c "import json; d=json.load(open('$PENDING')); print(sum(1 for a in d.get('actions',[]) if a.get('status')=='open'))" 2>/dev/null || echo 0)
  local detail="parent_poll_tick verdict=${verdict} ${passed} ${failed} ${jpass} ${jfail} nightly=${nightly} ct=${ct} cross=${cross} journey=${journey} open=${open}"
  if [[ "$open" -gt 0 ]]; then
    emit_wake "ACTION" "$detail"
  else
    emit_wake "PROGRESS" "$detail"
  fi
}

(
  while true; do
    bash "$SCRIPTS/lab-lane-signal-scanner.sh" >>"$LOGDIR/scanner.log" 2>&1 || true
    sleep 10
  done
) &
SCAN_PID=$!

(
  while true; do
    sleep "$FALLBACK_SEC"
    if pgrep -f 'run-lab-nightly.sh' >/dev/null 2>&1 || pgrep -f 'run-content-plugin-journey-wave.sh' >/dev/null 2>&1; then
      bash "$SCRIPTS/lab-cli-status-snapshot.sh" >/dev/null 2>&1 || true
      python3 "$SCRIPTS/lab-cli-journey-wave-snapshot.py" >/dev/null 2>&1 || true
      open=$(python3 -c "import json; d=json.load(open('$PENDING')); print(sum(1 for a in d.get('actions',[]) if a.get('status')=='open'))" 2>/dev/null || echo 0)
      if [[ "$open" -gt 0 ]]; then
        emit_wake "ACTION" "heartbeat_stale_open_actions=${open}"
      else
        nightly=$(pgrep -c -f 'run-lab-nightly.sh' 2>/dev/null || echo 0)
        summary=$(grep 'Passed lanes' "$LOGDIR/LIVE-STATUS.md" 2>/dev/null | head -1 || echo '')
        printf 'AGENT_LOOP_WAKE_labnightly HEARTBEAT {"nightly":%s,"open_actions":0,"hint":"%s"}\n' "$nightly" "$summary" | tee -a "$WAKE_LOG"
      fi
    fi
  done
) &
HB_PID=$!

(
  emit_poll_tick
  while true; do
    sleep "$POLL_SEC"
    emit_poll_tick || true
  done
) &
POLL_PID=$!

cleanup() {
  kill "$SCAN_PID" "$HB_PID" "$POLL_PID" 2>/dev/null || true
}
trap cleanup EXIT

echo $$ >"$LOGDIR/cli-event-wake.pid"

tail -n0 -F "$EVENTS" 2>/dev/null | while IFS= read -r line; do
  case "$line" in
    *NIGHTLY_WAVE_START*|*NIGHTLY_RESUME*)
      printf '%s\n' "$line" >"$WAVE_MARK"
      ;;
    *JOURNEY_WAVE_START*)
      printf '%s\n' "$(echo "$line" | sed -n 's/.*id=\([^ ]*\).*/\1/p')" >"$LOGDIR/current-journey-wave.mark"
      printf '%s\n' "$line" >"$WAVE_MARK"
      python3 "$SCRIPTS/lab-cli-journey-wave-snapshot.py" >/dev/null 2>&1 || true
      ;;
  esac
  level=$(wake_level "$line")
  [[ "$level" == "SKIP" ]] && continue
  emit_wake "$level" "$line"
done
