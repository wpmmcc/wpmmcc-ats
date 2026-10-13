#!/usr/bin/env bash
# D-2 "Nightly phase truth" (roadmap §7 D-2; plan §7 round 8).
#
# Verifies the REAL report/aggregation code of run-lab-nightly.sh:
#   1. per-phase actual command / started_at / finished_at / exit_code /
#      evidence / runtime_mode / required fields are recorded;
#   2. a skipped (or incomplete) REQUIRED phase demotes the overall status
#      to "incomplete" — it can never be reported as "passed";
#   3. a failed phase reports "failed" with the phase exit code preserved;
#   4. --dry-run produces a plan-only report: status "planned", dry_run true,
#      empty phases (no execution claims), non-empty plan;
#   5. an empty phase table yields "failed" (never a silent pass).
#
# No lab infrastructure is required. The write_summary python payload is
# extracted VERBATIM from the shipped script and driven with synthetic phase
# tables, so the tested logic is the real aggregation, not a reimplementation.
# The dry-run half invokes the actual orchestrator end-to-end (all phases are
# plan-only in that mode; no network, no docker, no cargo).
#
# Usage: bash tests/modules/wpmmcc-ats/e2e/test-lab-nightly-report-truth.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NIGHTLY="${SCRIPT_DIR}/run-lab-nightly.sh"
VALIDATOR="${REPO_ROOT:-$(cd "${SCRIPT_DIR}/../../../.." && pwd)}/tests/infra/tools/report-schema-validator.py"
PY_BIN="${PY_BIN:-python3}"

TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

PASS=0
FAIL=0
ok() { echo "  [ok] $*"; PASS=$((PASS + 1)); }
err() { echo "  [ERR] $*"; FAIL=$((FAIL + 1)); }
report() {
  echo ""
  if [[ "${FAIL}" -eq 0 ]]; then
    echo "lab-nightly report truth: PASS (${PASS} checks, 0 failures)"
    exit 0
  fi
  echo "lab-nightly report truth: FAIL (${PASS} passed, ${FAIL} failed)"
  exit 1
}

[[ -f "${NIGHTLY}" ]] || { err "run-lab-nightly.sh not found at ${NIGHTLY}"; report; }

# ---------------------------------------------------------------------------
# Extract the REAL write_summary payload (the heredoc whose invocation line
# carries SUMMARY_JSON; the plugin-count heredoc must not match).
# ---------------------------------------------------------------------------
SUMMARY_PY="${TMP}/write_summary.py"
awk '
  /python3 - <<.PY./ && /SUMMARY_JSON/ && !f { f = 1; next }
  f { if ($0 == "PY") { exit } print }
' "${NIGHTLY}" >"${SUMMARY_PY}"
if [[ ! -s "${SUMMARY_PY}" ]] \
  || ! grep -q '"phases"' "${SUMMARY_PY}" \
  || ! grep -q 'STATUS=' "${SUMMARY_PY}"; then
  err "write_summary payload extraction failed (script layout drifted?)"
  report
fi
ok "extracted write_summary payload verbatim ($(wc -l <"${SUMMARY_PY}") lines)"

LOG_FILE="${TMP}/nightly.log"
: >"${LOG_FILE}"

# run_summary <out.json> <phases.tsv> <plan.tsv> <fallback> <dry_run 0|1>
run_summary() {
  "${PY_BIN}" "${SUMMARY_PY}" \
    "$1" "$2" "$3" "$4" "${LOG_FILE}" "20260908T000000+0800" \
    "24" "20" "core-only" "release-required" "12" "$5" "1" "1" \
    "http://127.0.0.1:9083" "http://127.0.0.1:9090" "http://127.0.0.1:8977" \
    "0" "http://127.0.0.1:8787"
}

# phase_row <name> <status> <detail> <command> <started_at> <finished_at>
phase_row() {
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" "$5" "$6"
}

# ---------------------------------------------------------------------------
# Case A — all phases passed: status "passed" + full per-phase truth fields.
# ---------------------------------------------------------------------------
A_TSV="${TMP}/a.tsv"
: >"${A_TSV}"
phase_row "manual_preflight" "passed" "elapsed_s=2" "manual_preflight" \
  "2026-09-08T00:00:01+08:00" "2026-09-08T00:00:03+08:00" >>"${A_TSV}"
phase_row "manual_only_multilingual" "passed" "elapsed_s=9" \
  "env WPTSALL_LAB=1 bash run-manual-only-multilingual-gate.sh" \
  "2026-09-08T00:00:04+08:00" "2026-09-08T00:00:13+08:00" >>"${A_TSV}"
phase_row "content_matrix" "passed" "elapsed_s=600" \
  "env WPTSALL_LAB=1 bash run-project-matrix.sh --scope core-only" \
  "2026-09-08T00:01:00+08:00" "2026-09-08T00:11:00+08:00" >>"${A_TSV}"
phase_row "roles_non_admin" "passed" "parallel" \
  "env WPTSALL_LAB=1 bash run-playwright-roles-non-admin.sh" \
  "2026-09-08T00:12:00+08:00" "2026-09-08T00:13:00+08:00" >>"${A_TSV}"
: >"${TMP}/empty.tsv"

run_summary "${TMP}/a.json" "${A_TSV}" "${TMP}/empty.tsv" "" "0"
if "${PY_BIN}" - "${TMP}/a.json" <<'PY'
import json, sys
p = json.loads(open(sys.argv[1]).read())
def need(cond, msg):
    if not cond:
        print(msg, file=sys.stderr)
        sys.exit(1)
need(p["status"] == "passed", f"status={p['status']!r} want 'passed'")
phases = {e["name"]: e for e in p["phases"]}
need(set(phases) == {"manual_preflight", "manual_only_multilingual",
                     "content_matrix", "roles_non_admin"},
     f"phase names drifted: {sorted(phases)}")
m = phases["manual_only_multilingual"]
need(m["required"] is True, "manual_only_multilingual must be required=True")
need("run-manual-only-multilingual-gate.sh" in m["command"],
     f"command not preserved: {m['command']!r}")
need(m["started_at"] == "2026-09-08T00:00:04+08:00", f"started_at={m['started_at']!r}")
need(m["finished_at"] == "2026-09-08T00:00:13+08:00", f"finished_at={m['finished_at']!r}")
need(m["exit_code"] == 0, f"exit_code={m['exit_code']!r}")
need(m["evidence"] and isinstance(m["evidence"], list), "evidence list empty")
need(m["runtime_mode"] == "lab-local", f"runtime_mode={m['runtime_mode']!r}")
c = phases["content_matrix"]
need(c["required"] is True, "content_matrix must be required=True")
r = phases["roles_non_admin"]
need(r["required"] is False, "roles_non_admin must be required=False")
PY
then ok "all-passed: status passed + per-phase command/start/end/exit/evidence fields"
else err "all-passed case (see messages above)"; fi

# ---------------------------------------------------------------------------
# Case B — required phase skipped: overall must NOT be passed ("incomplete").
# ---------------------------------------------------------------------------
B_TSV="${TMP}/b.tsv"
: >"${B_TSV}"
phase_row "manual_preflight" "passed" "elapsed_s=2" "manual_preflight" \
  "2026-09-08T00:00:01+08:00" "2026-09-08T00:00:03+08:00" >>"${B_TSV}"
phase_row "manual_only_multilingual" "skipped" "disabled by --skip-manual-only" "" "" "" >>"${B_TSV}"
phase_row "content_matrix" "passed" "elapsed_s=600" \
  "env WPTSALL_LAB=1 bash run-project-matrix.sh" \
  "2026-09-08T00:01:00+08:00" "2026-09-08T00:11:00+08:00" >>"${B_TSV}"

run_summary "${TMP}/b.json" "${B_TSV}" "${TMP}/empty.tsv" "" "0"
if "${PY_BIN}" - "${TMP}/b.json" <<'PY'
import json, sys
p = json.loads(open(sys.argv[1]).read())
def need(cond, msg):
    if not cond:
        print(msg, file=sys.stderr)
        sys.exit(1)
need(p["status"] == "incomplete",
     f"required-phase skip must demote to incomplete, got {p['status']!r}")
m = [e for e in p["phases"] if e["name"] == "manual_only_multilingual"][0]
need(m["required"] is True and m["status"] == "skipped", "skipped row not recorded")
need("exit_code" not in m, "skipped phase must not claim an exit_code")
PY
then ok "required-phase skip: overall 'incomplete' (never passed), no fake exit_code"
else err "required-skip case (see messages above)"; fi

# ---------------------------------------------------------------------------
# Case B2 — required phase "incomplete" (same demotion bucket as skipped).
# ---------------------------------------------------------------------------
B2_TSV="${TMP}/b2.tsv"
: >"${B2_TSV}"
phase_row "content_matrix" "incomplete" "phase aborted mid-run" \
  "env WPTSALL_LAB=1 bash run-project-matrix.sh" \
  "2026-09-08T00:01:00+08:00" "2026-09-08T00:05:00+08:00" >>"${B2_TSV}"

run_summary "${TMP}/b2.json" "${B2_TSV}" "${TMP}/empty.tsv" "" "0"
if "${PY_BIN}" - "${TMP}/b2.json" <<'PY'
import json, sys
p = json.loads(open(sys.argv[1]).read())
if p["status"] != "incomplete":
    print(f"required-phase incomplete must demote, got {p['status']!r}", file=sys.stderr)
    sys.exit(1)
PY
then ok "required-phase incomplete: overall 'incomplete'"
else err "required-incomplete case"; fi

# ---------------------------------------------------------------------------
# Case C — failed phase: overall "failed" with the real exit code preserved.
# ---------------------------------------------------------------------------
C_TSV="${TMP}/c.tsv"
: >"${C_TSV}"
phase_row "manual_preflight" "passed" "elapsed_s=2" "manual_preflight" \
  "2026-09-08T00:00:01+08:00" "2026-09-08T00:00:03+08:00" >>"${C_TSV}"
phase_row "auto_mock_fault_injection" "failed" "rc=7;elapsed_s=31" \
  "env WPTSALL_LAB=1 bash test-mock-fault-injection.sh" \
  "2026-09-08T00:02:00+08:00" "2026-09-08T00:02:31+08:00" >>"${C_TSV}"

run_summary "${TMP}/c.json" "${C_TSV}" "${TMP}/empty.tsv" "" "0"
if "${PY_BIN}" - "${TMP}/c.json" <<'PY'
import json, sys
p = json.loads(open(sys.argv[1]).read())
def need(cond, msg):
    if not cond:
        print(msg, file=sys.stderr)
        sys.exit(1)
need(p["status"] == "failed", f"failed phase must yield 'failed', got {p['status']!r}")
f = [e for e in p["phases"] if e["name"] == "auto_mock_fault_injection"][0]
need(f["exit_code"] == 7, f"exit_code must be preserved as 7, got {f['exit_code']!r}")
need(f["required"] is True, "auto_mock_fault_injection must be required=True")
PY
then ok "failed phase: overall 'failed', exit_code=7 preserved"
else err "failed-phase case (see messages above)"; fi

# ---------------------------------------------------------------------------
# Case D — dry-run: status "planned" even with an all-passed table.
# ---------------------------------------------------------------------------
run_summary "${TMP}/d.json" "${A_TSV}" "${TMP}/empty.tsv" "" "1"
if "${PY_BIN}" - "${TMP}/d.json" <<'PY'
import json, sys
p = json.loads(open(sys.argv[1]).read())
if p["status"] != "planned" or p.get("dry_run") is not True:
    print(f"dry-run must be planned/dry_run=true, got {p['status']!r}/{p.get('dry_run')!r}",
          file=sys.stderr)
    sys.exit(1)
PY
then ok "dry-run: status 'planned' (plan-only, no pass claim)"
else err "dry-run case"; fi

# ---------------------------------------------------------------------------
# Case E — empty phase table: "failed" fallback (never a silent pass).
# ---------------------------------------------------------------------------
run_summary "${TMP}/e.json" "${TMP}/empty.tsv" "${TMP}/empty.tsv" "" "0"
if "${PY_BIN}" - "${TMP}/e.json" <<'PY'
import json, sys
p = json.loads(open(sys.argv[1]).read())
if p["status"] != "failed":
    print(f"empty phase table must be 'failed', got {p['status']!r}", file=sys.stderr)
    sys.exit(1)
PY
then ok "empty phase table: 'failed' fallback"
else err "empty-table case"; fi

# ---------------------------------------------------------------------------
# Live half: run the ACTUAL orchestrator in --dry-run (plan-only; no phases
# execute, no network, no docker). --min-plugins 1 keeps this robust against
# future project-specs.json edits (the min-plugins gate is not under test).
# ---------------------------------------------------------------------------
echo ""
echo "── live --dry-run of run-lab-nightly.sh ──────────────────────────"
DRY_LOG="${TMP}/dry-run.log"
DRY_RC=0
WPTSALL_LAB=1 bash "${NIGHTLY}" --dry-run --min-plugins 1 >"${DRY_LOG}" 2>&1 || DRY_RC=$?
if [[ "${DRY_RC}" -eq 0 ]]; then
  ok "live --dry-run exit 0"
else
  err "live --dry-run exit ${DRY_RC} (tail follows)"
  tail -20 "${DRY_LOG}" >&2
  report
fi

# The orchestrator prints its report path early ("Report: <path>") — parse it
# from the log (ANSI-stripped).
DRY_JSON="$(sed 's/\x1b\[[0-9;]*m//g' "${DRY_LOG}" | sed -n 's/^  Report: *//p' | head -1)"
if [[ -z "${DRY_JSON}" || ! -f "${DRY_JSON}" ]]; then
  err "could not locate dry-run summary JSON from log"
  report
fi
ok "dry-run summary JSON at ${DRY_JSON}"

if "${PY_BIN}" - "${DRY_JSON}" <<'PY'
import json, sys
p = json.loads(open(sys.argv[1]).read())
def need(cond, msg):
    if not cond:
        print(msg, file=sys.stderr)
        sys.exit(1)
need(p["status"] == "planned", f"status={p['status']!r} want 'planned'")
need(p.get("dry_run") is True, "dry_run must be true")
need(p.get("phases") == [], f"plan-only run must record no executed phases, got {p.get('phases')}")
plan = p.get("plan") or []
need(len(plan) > 0, "plan must be non-empty in dry-run")
names = {e["name"] for e in plan}
for required in ("manual_preflight", "manual_only_multilingual",
                 "manual_content_plugin_matrix", "content_matrix"):
    need(required in names, f"plan missing required phase {required}")
need(all(e.get("command") for e in plan), "every plan entry must carry its command")
need(isinstance(p.get("evidence"), list) and p["evidence"],
     "top-level evidence (log path) must be recorded")
PY
then ok "dry-run JSON: planned + plan-only (no phase claims) + required phases in plan"
else err "dry-run JSON content (see messages above)"; fi

# The shipped schema validator must accept the planned report (profile nightly).
if "${PY_BIN}" "${VALIDATOR}" "${DRY_JSON}" --profile nightly >/dev/null 2>&1; then
  ok "dry-run report passes report-schema-validator (profile: nightly)"
else
  err "dry-run report failed schema validation"
fi

report
