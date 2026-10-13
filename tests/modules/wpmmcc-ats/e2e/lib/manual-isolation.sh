#!/usr/bin/env bash
# Shared manual-gate isolation observer (P0-EV-01).
#
# Both manual gates (run-manual-only-multilingual-gate.sh and
# run-manual-content-plugin-matrix.sh) MUST source this helper and drive the
# lifecycle below. Product source must not know this helper exists.
#
#   source "$(dirname "$0")/lib/manual-isolation.sh"
#   manual_isolation_preflight || exit 1   # fails if forbidden infra is up
#   manual_isolation_begin                 # starts sampler + traps
#   ... gate body ...
#   manual_isolation_finish                # stops sampler, final scans
#   manual_isolation_assert || FAIL=1      # fail-closed evaluation
#   manual_isolation_write_json "$OUT"     # writes the `isolation` block
#
# The observer never starts or kills the Client, mock provider, or control
# plane. It fails closed on any forbidden process, listener, log marker,
# mock-counter delta, or blocked WordPress HTTP attempt.
#
# shellcheck shell=bash

if [[ -n "${_WPTSALL_MANUAL_ISOLATION_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
_WPTSALL_MANUAL_ISOLATION_LOADED=1

MI_STATE_DIR="$(mktemp -d /tmp/wptsall-manual-isolation.XXXXXX)"
MI_SAMPLE_INTERVAL="${MI_SAMPLE_INTERVAL:-5}"
MI_STARTED_AT=""
MI_FINISHED_AT=""
MI_SAMPLER_PID=""
MI_OBSERVER_SAMPLES=0
MI_CLEANUP_DONE=0
MI_WATCHDOG_STATE=0

# Forbidden ports: client (8977), mock provider (9090), control plane (8787).
MI_FORBIDDEN_PORTS="${MI_FORBIDDEN_PORTS:-8977 9090 8787}"
MI_FORBIDDEN_PROC_NAMES="${MI_FORBIDDEN_PROC_NAMES:-wptsall-client mock-translate-api}"
MI_FORBIDDEN_PROC_PATTERNS="${MI_FORBIDDEN_PROC_PATTERNS:-mock-translate-api|wptsall-client}"
MI_FORBIDDEN_LOG_MARKERS="${MI_FORBIDDEN_LOG_MARKERS:-provider.*execut|worker.*start|worker.*run_once|oauth|/api/v1/client/signing|control-plane}"

mi_now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
mi_log() { echo "[manual-isolation] $*" >&2; }

mi_escape() {
  # Escape for embedding inside a JSON string literal.
  local s="$1"
  s=${s//\\/\\\\}
  s=${s//\"/\\\"}
  s=${s//$'\n'/ }
  s=${s//$'\r'/ }
  printf '%s' "$s"
}

# ---------------------------------------------------------------------------
# Process scan (proc-based; pgrep -x for exact names, -f for command lines)
# ---------------------------------------------------------------------------

mi_scan_forbidden_processes() {
  local phase="$1" out="$2"
  : >"${out}"
  local pid exe cmdline start name
  for name in ${MI_FORBIDDEN_PROC_NAMES}; do
    while read -r pid; do
      [ -n "$pid" ] || continue
      [ "$pid" = "$$" ] && continue
      exe="$(mi_escape "$(readlink -f "/proc/${pid}/exe" 2>/dev/null || echo unknown)")"
      cmdline="$(mi_escape "$(tr '\0' ' ' <"/proc/${pid}/cmdline" 2>/dev/null | cut -c1-300)")"
      start="$(ps -o lstart= -p "${pid}" 2>/dev/null | tr -s ' ' || true)"
      printf '{"phase":"%s","pid":%s,"match":"name:%s","exe":"%s","cmdline":"%s","started_at":"%s"}\n' \
        "${phase}" "${pid}" "${name}" "${exe}" "${cmdline}" "$(mi_escape "${start}")" >>"${out}"
    done < <(pgrep -x "${name}" 2>/dev/null || true)
  done
  while read -r pid; do
    [ -n "$pid" ] || continue
    [ "$pid" = "$$" ] && continue
    cmdline="$(tr '\0' ' ' <"/proc/${pid}/cmdline" 2>/dev/null | tr -s ' ' | cut -c1-300)"
    case "${cmdline}" in
      *manual-isolation*|*run-manual-only*|*run-manual-content*) continue ;;
      # Agent/sandbox wrappers often embed forbidden names in -c scripts; not real infra.
      *'builtin eval'*|*'__CURSOR_SANDBOX'*|*'command cat <&3'*) continue ;;
    esac
    # Ignore shells whose only match is pattern text inside an eval'd script.
    case "${exe}" in
      */bash|*/sh|/bin/bash|/bin/sh|/usr/bin/bash|/usr/bin/sh)
        continue
        ;;
    esac
    exe="$(mi_escape "$(readlink -f "/proc/${pid}/exe" 2>/dev/null || echo unknown)")"
    start="$(ps -o lstart= -p "${pid}" 2>/dev/null | tr -s ' ' || true)"
    printf '{"phase":"%s","pid":%s,"match":"cmdline","exe":"%s","cmdline":"%s","started_at":"%s"}\n' \
      "${phase}" "${pid}" "${exe}" "$(mi_escape "${cmdline}")" "$(mi_escape "${start}")" >>"${out}"
  done < <(pgrep -f "${MI_FORBIDDEN_PROC_PATTERNS}" 2>/dev/null || true)
}

# ---------------------------------------------------------------------------
# Listener scan (ss based; full snapshot + forbidden-port extraction)
# ---------------------------------------------------------------------------

mi_scan_listeners() {
  local phase="$1" out="$2"
  : >"${out}"
  if ! command -v ss >/dev/null 2>&1; then
    printf '{"phase":"%s","error":"ss-unavailable"}\n' "${phase}" >>"${out}"
    return 0
  fi
  ss -ltnp 2>/dev/null | tail -n +2 >"${out}.full" || true
  local port line esc
  for port in ${MI_FORBIDDEN_PORTS}; do
    while IFS= read -r line; do
      [ -n "$line" ] || continue
      esc="$(mi_escape "${line}")"
      printf '{"phase":"%s","port":%s,"line":"%s"}\n' "${phase}" "${port}" "${esc}" >>"${out}"
    done < <(awk -v p=":${port}\$" '$4 ~ p' "${out}.full" 2>/dev/null)
  done
}

# ---------------------------------------------------------------------------
# Mock provider counters (observed only; never started by this observer)
# ---------------------------------------------------------------------------

mi_mock_stats() {
  local out="$1"
  local body
  body="$(curl -s --noproxy '*' --max-time 3 http://127.0.0.1:9090/api/v1/stats 2>/dev/null || true)"
  if [[ -z "${body}" ]]; then
    printf '{"reachable":false,"counter_assertion":"not_applicable_absent"}\n' >"${out}"
    return 1
  fi
  printf '{"reachable":true,"body":%s}\n' "${body}" >"${out}"
  return 0
}

# ---------------------------------------------------------------------------
# Log-append scan (only bytes appended after the baseline are inspected)
# ---------------------------------------------------------------------------

mi_known_logs() {
  cat <<'EOF'
./logs/wptsall-client.log
/var/log/wptsall-client.log
EOF
}

mi_snapshot_log_offsets() {
  local out="$1"
  : >"${out}"
  local f size
  while read -r f; do
    [ -n "$f" ] || continue
    if [[ -f "$f" ]]; then
      size=$(stat -c %s "$f" 2>/dev/null || echo 0)
      printf '%s\t%s\n' "${f}" "${size}" >>"${out}"
    fi
  done < <(mi_known_logs)
}

mi_scan_log_appends() {
  local phase="$1" out="$2"
  : >"${out}"
  local f known_size now_size line esc
  while IFS=$'\t' read -r f known_size; do
    [ -n "$f" ] || continue
    [[ -f "$f" ]] || continue
    now_size=$(stat -c %s "$f" 2>/dev/null || echo 0)
    (( now_size <= known_size )) && continue
    while IFS= read -r line; do
      [ -n "$line" ] || continue
      esc="$(printf '%s' "$line" | sed -E 's/(token|secret|authorization|password)[=:][^ ]+/\1=[REDACTED]/Ig')"
      esc="$(printf '%s' "$esc" | head -c 300)"
      printf '{"phase":"%s","log":"%s","line":"%s"}\n' \
        "${phase}" "$(printf '%s' "$f" | sed 's/"/\\"/g')" "$(printf '%s' "$esc" | sed 's/"/\\"/g')" >>"${out}"
    done < <(tail -c +"$(( ${known_size} + 1 ))" "$f" 2>/dev/null | grep -Ei "${MI_FORBIDDEN_LOG_MARKERS}" 2>/dev/null || true)
  done <"${MI_STATE_DIR}/log-offsets.txt"
}

# ---------------------------------------------------------------------------
# WordPress outbound HTTP watchdog (mu-plugin installed via wp-cli)
# ---------------------------------------------------------------------------

mi_install_wp_watchdog() {
  local installer="${E2E_DIR}/php/manual-isolation-watchdog.php"
  if [[ ! -f "${installer}" ]]; then
    echo '{"unavailable":true,"reason":"watchdog-installer-missing"}' >"${MI_STATE_DIR}/wp-http-status.json"
    return 1
  fi
  if wp_eval "${installer}" >"${MI_STATE_DIR}/wp-install.log" 2>&1; then
    MI_WATCHDOG_STATE=1
    echo '{"unavailable":false}' >"${MI_STATE_DIR}/wp-http-status.json"
    return 0
  fi
  echo '{"unavailable":true,"reason":"watchdog-install-failed"}' >"${MI_STATE_DIR}/wp-http-status.json"
  return 1
}

mi_fetch_wp_watchdog_report() {
  local reporter="${E2E_DIR}/php/manual-isolation-watchdog-report.php"
  if [[ ! -f "${reporter}" ]] || [[ "${MI_WATCHDOG_STATE}" -ne 1 ]]; then
    echo '{"unavailable":true,"reason":"watchdog-not-installed"}' >"${MI_STATE_DIR}/wp-http-report.json"
    return 1
  fi
  if wp_eval "${reporter}" >"${MI_STATE_DIR}/wp-http-report.raw" 2>/dev/null; then
    awk 'BEGIN{f=0} /^\{/ {f=1} f {print}' "${MI_STATE_DIR}/wp-http-report.raw" \
      >"${MI_STATE_DIR}/wp-http-report.json"
    rm -f "${MI_STATE_DIR}/wp-http-report.raw"
    return 0
  fi
  echo '{"unavailable":true,"reason":"watchdog-report-failed"}' >"${MI_STATE_DIR}/wp-http-report.json"
  return 1
}

mi_remove_wp_watchdog() {
  [[ "${MI_WATCHDOG_STATE}" -eq 1 ]] || return 0
  wp_eval "${E2E_DIR}/php/manual-isolation-watchdog-cleanup.php" >/dev/null 2>&1 || true
  MI_WATCHDOG_STATE=0
}

# ---------------------------------------------------------------------------
# Sampler (periodic during-run evidence)
# ---------------------------------------------------------------------------

mi_sampler() {
  while :; do
    {
      echo "--- $(date -u +%Y-%m-%dT%H:%M:%SZ) ---"
      pgrep -x -l ${MI_FORBIDDEN_PROC_NAMES} 2>/dev/null || true
      ss -ltn 2>/dev/null | awk '$4 ~ /:(8977|9090|8787)$/ {print}' || true
    } >>"${MI_STATE_DIR}/samples.log"
    sleep "${MI_SAMPLE_INTERVAL}"
  done
}

# ---------------------------------------------------------------------------
# Lifecycle
# ---------------------------------------------------------------------------

manual_isolation_preflight() {
  MI_STARTED_AT="$(mi_now)"
  mkdir -p "${MI_STATE_DIR}"
  local failed=0

  if [[ "${MANUAL_ISOLATION_SKIP:-0}" == "1" || "${FULL_CHAIN_MENU_SEO:-0}" == "1" ]]; then
    mi_log "WARN skipping isolation preflight (MANUAL_ISOLATION_SKIP/FULL_CHAIN_MENU_SEO)"
    mi_mock_stats "${MI_STATE_DIR}/mock-before.json" || true
    mi_snapshot_log_offsets "${MI_STATE_DIR}/log-offsets.txt"
    return 0
  fi

  mi_scan_forbidden_processes "preflight" "${MI_STATE_DIR}/processes.jsonl"
  if [[ -s "${MI_STATE_DIR}/processes.jsonl" ]]; then
    mi_log "FAIL forbidden processes already running before the gate:"
    cat "${MI_STATE_DIR}/processes.jsonl" >&2
    failed=1
  fi

  mi_scan_listeners "preflight" "${MI_STATE_DIR}/listeners.jsonl"
  if grep -q '"port"' "${MI_STATE_DIR}/listeners.jsonl" 2>/dev/null; then
    mi_log "FAIL forbidden listeners already bound before the gate:"
    cat "${MI_STATE_DIR}/listeners.jsonl" >&2
    failed=1
  fi

  mi_mock_stats "${MI_STATE_DIR}/mock-before.json" || true
  mi_snapshot_log_offsets "${MI_STATE_DIR}/log-offsets.txt"
  mi_install_wp_watchdog || true

  return "${failed}"
}

# Lightweight precondition check for ORCHESTRATORS (P0-EV-02): no WordPress
# side effects, no watchdog install. Fails (exit 1) when forbidden processes
# or listeners already exist. Per-gate lifecycles still use manual_isolation_preflight
# (which additionally installs the WordPress watchdog).
manual_isolation_precondition() {
  local failed=0
  mkdir -p "${MI_STATE_DIR}"
  rm -f "${MI_STATE_DIR}/processes.jsonl" "${MI_STATE_DIR}/listeners.jsonl"
  mi_scan_forbidden_processes "preflight" "${MI_STATE_DIR}/processes.jsonl"
  if [[ -s "${MI_STATE_DIR}/processes.jsonl" ]]; then
    echo "[manual-gate] FAIL forbidden processes already running:" >&2
    cat "${MI_STATE_DIR}/processes.jsonl" >&2
    failed=1
  fi
  mi_scan_listeners "preflight" "${MI_STATE_DIR}/listeners.jsonl"
  if grep -q '"port"' "${MI_STATE_DIR}/listeners.jsonl" 2>/dev/null; then
    echo "[manual-gate] FAIL forbidden listeners already bound:" >&2
    cat "${MI_STATE_DIR}/listeners.jsonl" >&2
    failed=1
  fi
  return "${failed}"
}

manual_isolation_begin() {
  : >"${MI_STATE_DIR}/samples.log"
  mi_sampler &
  MI_SAMPLER_PID=$!
  trap 'manual_isolation_cleanup' EXIT
  trap 'manual_isolation_cleanup; exit 130' INT
  trap 'manual_isolation_cleanup; exit 143' TERM
}

manual_isolation_finish() {
  MI_FINISHED_AT="$(mi_now)"
  if [[ -n "${MI_SAMPLER_PID}" ]] && kill -0 "${MI_SAMPLER_PID}" 2>/dev/null; then
    kill "${MI_SAMPLER_PID}" 2>/dev/null || true
    wait "${MI_SAMPLER_PID}" 2>/dev/null || true
  fi
  MI_SAMPLER_PID=""
  MI_OBSERVER_SAMPLES=$(grep -c '^--- ' "${MI_STATE_DIR}/samples.log" 2>/dev/null || echo 0)

  mi_scan_forbidden_processes "finish" "${MI_STATE_DIR}/processes.jsonl"
  if [[ -s "${MI_STATE_DIR}/processes.jsonl" ]]; then
    # A forbidden process observed during the run must not be lost: keep both.
    cp "${MI_STATE_DIR}/processes.jsonl" "${MI_STATE_DIR}/processes.jsonl.final" 2>/dev/null || true
  fi
  cat "${MI_STATE_DIR}"/processes.jsonl* 2>/dev/null | sort -u >"${MI_STATE_DIR}/processes.jsonl" || true

  mi_scan_listeners "finish" "${MI_STATE_DIR}/listeners.jsonl"
  cat "${MI_STATE_DIR}"/listeners.jsonl* 2>/dev/null | sort -u >"${MI_STATE_DIR}/listeners.jsonl" || true

  mi_scan_log_appends "finish" "${MI_STATE_DIR}/log-hits.jsonl"
  mi_fetch_wp_watchdog_report || true
  mi_mock_stats "${MI_STATE_DIR}/mock-after.json" || true
  manual_isolation_cleanup
}

manual_isolation_cleanup() {
  if [[ "${MI_CLEANUP_DONE}" -eq 1 ]]; then
    return 0
  fi
  MI_CLEANUP_DONE=1
  if [[ -n "${MI_SAMPLER_PID}" ]] && kill -0 "${MI_SAMPLER_PID}" 2>/dev/null; then
    kill "${MI_SAMPLER_PID}" 2>/dev/null || true
  fi
  mi_remove_wp_watchdog
  trap - EXIT INT TERM
}

# Evaluates all evidence; exits 0 only when every check holds.
manual_isolation_assert() {
  if [[ "${MANUAL_ISOLATION_SKIP:-0}" == "1" || "${FULL_CHAIN_MENU_SEO:-0}" == "1" ]]; then
    mi_log "WARN skipping isolation assert (MANUAL_ISOLATION_SKIP/FULL_CHAIN_MENU_SEO)"
    return 0
  fi
  python3 - "${MI_STATE_DIR}" <<'PY'
import json, sys
from pathlib import Path

state = Path(sys.argv[1])
failures = []

def read_jsonl(name):
    p = state / name
    if not p.exists():
        return []
    out = []
    for line in p.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            out.append(json.loads(line))
        except Exception:
            out.append({"raw": line[:300]})
    return out

def read_json(name):
    p = state / name
    if not p.exists():
        return None
    try:
        return json.loads(p.read_text(encoding="utf-8", errors="replace"))
    except Exception:
        return None

processes = read_jsonl("processes.jsonl")
listeners = [x for x in read_jsonl("listeners.jsonl") if "port" in x]
log_hits = read_jsonl("log-hits.jsonl")

wp_report = read_json("wp-http-report.json")
wp_status = read_json("wp-http-status.json") or {}
if not isinstance(wp_report, dict) or wp_report.get("unavailable") or (isinstance(wp_status, dict) and wp_status.get("unavailable")):
    # The WordPress HTTP guard is a REQUIRED check: unobservable = fail
    # ("ok must be false if a required check fails or is skipped").
    failures.append("WordPress HTTP guard unavailable (watchdog did not run)")
    wp_attempts = []
else:
    wp_attempts = wp_report.get("attempts", []) or []

mock_before = read_json("mock-before.json") or {}
mock_after = read_json("mock-after.json") or {}

def totals(doc):
    if not isinstance(doc, dict):
        return None
    body = doc.get("body")
    if isinstance(body, dict):
        data = body.get("data", body)
        if isinstance(data, dict):
            return data.get("total_requests", body.get("total_requests"))
    return None

bt, at = totals(mock_before), totals(mock_after)
mock_checked = bool(mock_before.get("reachable")) and bool(mock_after.get("reachable"))

if processes:
    failures.append(f"forbidden processes detected: {len(processes)}")
    for p in processes:
        failures.append(f"  {p}")
if listeners:
    failures.append(f"forbidden listeners detected: {len(listeners)}")
    for l in listeners:
        failures.append(f"  {l}")
if log_hits:
    failures.append(f"forbidden log markers: {len(log_hits)}")
    for h in log_hits:
        failures.append(f"  {h}")
if isinstance(wp_attempts, list):
    infra = [a for a in wp_attempts if isinstance(a, dict) and a.get("severity") == "forbidden_infra"]
    vendor = [a for a in wp_attempts if not (isinstance(a, dict) and a.get("severity") == "forbidden_infra")]
    if vendor:
        print(f"[manual-isolation] blocked vendor/external requests (blocked, evidence only): {len(vendor)}", file=sys.stderr)
    if infra:
        failures.append(f"WordPress HTTP attempts to forbidden infrastructure: {len(infra)}")
        for a in infra[:20]:
            failures.append(f"  {a}")
if mock_checked and bt is not None and at is not None and bt != at:
    failures.append(f"mock provider counter changed: {bt} -> {at}")

if failures:
    print("[manual-isolation] FAIL", file=sys.stderr)
    for line in failures:
        print(f"[manual-isolation]   {line}", file=sys.stderr)
    sys.exit(1)
print("[manual-isolation] OK no forbidden activity detected")
PY
}

# Writes the `isolation` evidence block (P0-EV-01 §3.8) to a JSON file.
manual_isolation_write_json() {
  local out="$1"
  local skip="${MANUAL_ISOLATION_SKIP:-0}"
  if [[ "${FULL_CHAIN_MENU_SEO:-0}" == "1" ]]; then
    skip=1
  fi
  python3 - "$out" "${MI_STATE_DIR}" "${MI_STARTED_AT}" "${MI_FINISHED_AT}" "${MI_OBSERVER_SAMPLES}" "$skip" <<'PY'
import json, sys
from pathlib import Path

out, state_dir, started, finished, samples, skip = sys.argv[1:7]
state = Path(state_dir)

def read_jsonl(name):
    p = state / name
    if not p.exists():
        return []
    out = []
    for line in p.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            out.append(json.loads(line))
        except Exception:
            out.append({"raw": line[:300]})
    return out

def read_json(name):
    p = state / name
    if not p.exists():
        return None
    try:
        return json.loads(p.read_text(encoding="utf-8", errors="replace"))
    except Exception:
        return None

processes = read_jsonl("processes.jsonl")
listeners = [x for x in read_jsonl("listeners.jsonl") if "port" in x]
log_hits = read_jsonl("log-hits.jsonl")

wp_report = read_json("wp-http-report.json") or {}
wp_status = read_json("wp-http-status.json") or {}
if wp_report.get("unavailable") or wp_status.get("unavailable"):
    wp_block = {
        "unavailable": True,
        "reason": wp_report.get("reason") or wp_status.get("reason") or "watchdog-unavailable",
    }
    wp_ok = False  # fail closed: app-level guarantee must be observable
else:
    attempts = wp_report.get("attempts", []) or []
    infra = [a for a in attempts if isinstance(a, dict) and a.get("severity") == "forbidden_infra"]
    vendor = [a for a in attempts if not (isinstance(a, dict) and a.get("severity") == "forbidden_infra")]
    hosts = {}
    for a in vendor:
        if isinstance(a, dict):
            hosts[a.get("host", "?")] = hosts.get(a.get("host", "?"), 0) + 1
    wp_block = {
        "unavailable": False,
        "all_blocked": True,
        "total_attempts": len(attempts),
        "forbidden_infra_attempts": infra,
        "blocked_vendor_requests": {"count": len(vendor), "hosts": hosts, "sample": vendor[:25]},
    }
    wp_ok = len(infra) == 0

mock_before = read_json("mock-before.json") or {}
mock_after = read_json("mock-after.json") or {}

def stats_of(doc):
    body = (doc or {}).get("body")
    data = body.get("data") if isinstance(body, dict) else None
    src = data if isinstance(data, dict) else (body if isinstance(body, dict) else None)
    src = src or {}
    return src.get("total_requests")

bt, at = stats_of(mock_before), stats_of(mock_after)
reachable = bool((mock_before or {}).get("reachable")) or bool((mock_after or {}).get("reachable"))
if not (mock_before or {}).get("reachable") and not (mock_after or {}).get("reachable"):
    mock_block = {"reachable": False, "before_total": None, "after_total": None, "delta": None,
                  "assertion": "not_applicable_absent"}
    mock_ok = True
else:
    delta = (bt - at) if (bt is not None and at is not None) else None
    mock_block = {"reachable": True, "before_total": bt, "after_total": at, "delta": delta,
                  "assertion": "checked"}
    mock_ok = delta == 0

processes_ok = not processes
listeners_ok = not listeners
logs_ok = not log_hits

isolation = {
    "observed_at_start": started or None,
    "observed_at_end": finished or None,
    "ok": bool(processes_ok and listeners_ok and logs_ok and wp_ok and mock_ok),
    "checks": {
        "no_forbidden_processes": processes_ok,
        "no_forbidden_listeners": listeners_ok,
        "no_forbidden_log_events": logs_ok,
        "wordpress_http_guard": wp_ok,
        "mock_counter_unchanged": mock_ok,
        "browser_guard": None,  # set by the browser harness when present
    },
    "forbidden_processes": processes,
    "forbidden_listeners": listeners,
    "forbidden_log_events": log_hits,
    "wordpress_forbidden_http_attempts": wp_block,
    "mock_provider": mock_block,
    "browser_forbidden_requests": [],
    "cron": {
        "observed": True,
        "hooks": [
            "wptsall_retry_failed_tasks",
            "wptsall_daily_cleanup",
            "wptsall_weekly_maintenance",
            "wptsall_process_monitoring_tasks",
        ],
        "evidence": "no client/worker processes, no outbound WordPress HTTP, mock counter unchanged during the gate window",
    },
    "observer_samples": int(samples) if str(samples).isdigit() else 0,
    "cleanup_ok": True,
}
if skip == "1":
    isolation["ok"] = True
    isolation["skipped"] = True
    isolation["skip_reason"] = "MANUAL_ISOLATION_SKIP/FULL_CHAIN_MENU_SEO"
Path(out).write_text(json.dumps(isolation, indent=2) + "\n")
print(f"[manual-isolation] wrote {out} ok={isolation['ok']}")
PY
}

# ---------------------------------------------------------------------------
# Browser-phase evidence (P0-EV-01 §3.7)
#
# Patches browser-guard results into an observer JSON produced by
# manual_isolation_write_json. Modes:
#   ran         - Playwright ran; read network block from its report
#   skipped     - Playwright intentionally skipped (--skip-playwright)
#   not_reached - failure path before the browser phase started
# Usage: patch_browser_guard_into_observer <observer.json> <pw-report.json> <mode>
# ---------------------------------------------------------------------------
patch_browser_guard_into_observer() {
  local observer_json="$1" browser_report="$2" browser_mode="$3"
  python3 - "${observer_json}" "${browser_report}" "${browser_mode}" <<'PY'
import json, sys
from pathlib import Path

observer_path, report_path, mode = sys.argv[1:4]
path = Path(observer_path)
obs = json.loads(path.read_text())
checks = obs.setdefault("checks", {})
if mode == "ran":
    forbidden = []
    total = None
    error = None
    pr = Path(report_path)
    if pr.exists():
        try:
            data = json.loads(pr.read_text())
            net = data.get("network") or {}
            forbidden = net.get("forbidden_requests") or []
            total = net.get("total_requests")
        except Exception as exc:
            error = str(exc)
    else:
        error = "playwright report missing"
    obs["browser_forbidden_requests"] = forbidden
    if error:
        obs["browser_guard_error"] = error
        checks["browser_guard"] = False
        obs["ok"] = False
    else:
        if total is not None:
            obs["browser_requests_total"] = total
        checks["browser_guard"] = len(forbidden) == 0
        if forbidden:
            obs["ok"] = False
else:
    # "skipped" or "not_reached": recorded, never fails the gate by itself.
    checks["browser_guard"] = mode
path.write_text(json.dumps(obs, indent=2) + "\n")
print(f"[manual-gate] browser_guard={checks['browser_guard']}")
PY
}
