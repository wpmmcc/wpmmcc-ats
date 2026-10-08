#!/usr/bin/env bash
# Manual-only matrix for the 20 current Lab WP CMS content plugin projects.
#
# This is intentionally independent from the automatic/client E2E matrix:
# - does NOT start Rust/Desktop/WebUI client
# - does NOT call automatic translation
# - uses WPMMCC ATS manual REST/editor/admin surfaces only
# - verifies front-end virtual multilingual browsing plus plugin/WPTSALL wp-admin pages
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-manual-content-plugin-matrix.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-manual-content-plugin-matrix.sh --projects woocommerce-content,tutor-content
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-manual-content-plugin-matrix.sh --skip-playwright

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED E2E_SLOT_WP_BASE LAB_WP_CONTAINER \
  WP_URL WP_BASE CLIENT_BASE WPTSALL_DB_PATH CLIENT_URL \
  WPTSALL_WEB_UI_BIND WPTSALL_WEB_UI_PORT \
  2>/dev/null || true
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib/manual-isolation.sh"

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
export DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"
WP_BASE="${WP_URL:-http://127.0.0.1:9083}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RAW_REPORT="${REPORTS_DIR}/manual-content-plugin-matrix-${STAMP}.raw.log"
PHP_REPORT="${REPORTS_DIR}/manual-content-plugin-matrix-${STAMP}.php.json"
FINAL_REPORT="${REPORTS_DIR}/manual-content-plugin-matrix-${STAMP}.json"
PW_LOG="${REPORTS_DIR}/manual-content-plugin-matrix-${STAMP}.playwright.log"
PLAYWRIGHT_REPORT="${RUNTIME_DIR}/manual-content-plugin-matrix-playwright-report.json"
TARGETS_FILE="${RUNTIME_DIR}/manual-content-plugin-matrix-targets.json"
PROJECTS_CSV="${E2E_MANUAL_PROJECTS:-}"
SKIP_PLAYWRIGHT=0
PREPARE_ONLY=0
# P0-EV-01 observer state (populated once the observation window opens).
OBSERVER_JSON=""
GATE_FAILED=0

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/run-manual-content-plugin-matrix.sh [options]

Options:
  --projects <csv>      Run only selected project keys from project-specs.json
  --skip-playwright     Only run PHP manual REST/data assertions
  --prepare-only        Alias for --skip-playwright
  -h, --help            Show help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --projects)
      PROJECTS_CSV="$2"
      shift 2
      ;;
    --skip-playwright)
      SKIP_PLAYWRIGHT=1
      shift
      ;;
    --prepare-only)
      PREPARE_ONLY=1
      SKIP_PLAYWRIGHT=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

mkdir -p "${REPORTS_DIR}" "${RUNTIME_DIR}"
rm -f "${PLAYWRIGHT_REPORT}" "${TARGETS_FILE}"

json_get() {
  local file="$1" path="$2"
  php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($j)) { exit(2); }
    $v = $j;
    foreach (explode(".", $argv[2]) as $p) {
      if (!is_array($v) || !array_key_exists($p, $v)) { exit(3); }
      $v = $v[$p];
    }
    if (is_bool($v)) { echo $v ? "1" : "0"; }
    elseif (is_scalar($v) || $v === null) { echo (string) $v; }
    else { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
  ' "$file" "$path"
}

extract_json_from_log() {
  local raw="$1" out="$2"
  python3 - <<'PY' "$raw" "$out"
import json, sys
from pathlib import Path
raw = Path(sys.argv[1]).read_text(errors='replace')
out = Path(sys.argv[2])
lines = raw.split('\n')
last_error = None

def emit(obj):
    out.write_text(json.dumps(obj, ensure_ascii=False, indent=2) + '\n')
    raise SystemExit(0)

# 1) Pretty-printed JSON: a line that is exactly '{' ... a line that is '}'.
#    Log lines (WP warnings / DB errors, which may contain '{' e.g. "{closure}")
#    can precede or follow the object, so anchor on standalone brace lines and
#    the first complete block wins (forward order = outermost object).
starts = [i for i, l in enumerate(lines) if l.strip() == '{']
for start in starts:
    buf = ''
    for j in range(start, len(lines)):
        buf += lines[j] + '\n'
        if lines[j].strip() == '}':
            try:
                emit(json.loads(buf))
            except SystemExit:
                raise
            except Exception as exc:
                last_error = exc
# 2) Single-line JSON objects: try each line that looks like one object,
#    last first.
for line in reversed(lines):
    s = line.strip()
    if s.startswith('{') and s.endswith('}') and len(s) > 2:
        try:
            emit(json.loads(s))
        except SystemExit:
            raise
        except Exception as exc:
            last_error = exc
print(last_error or 'no json', file=sys.stderr)
raise SystemExit(1)
PY
}

combine_report() {
  local php_json="$1" final_json="$2" wp_rc="$3" pw_rc="$4" raw="$5" pw_log="$6" pw_report="$7"
  local observer_json="${8:-}" gate_failed="${9:-0}"
  python3 - <<'PY' "$php_json" "$final_json" "$wp_rc" "$pw_rc" "$raw" "$pw_log" "$pw_report" "$observer_json" "$gate_failed"
import json, sys
from pathlib import Path
php_json, final_json, wp_rc, pw_rc, raw, pw_log, pw_report, observer_json, gate_failed = sys.argv[1:10]
base = json.loads(Path(php_json).read_text())
base['wp_cli_exit'] = int(wp_rc)
base['playwright_exit'] = int(pw_rc)
base['raw_report'] = raw
base['playwright_log'] = pw_log if Path(pw_log).exists() else None
if Path(pw_report).exists():
    try:
        base['playwright_report'] = json.loads(Path(pw_report).read_text())
    except Exception as exc:
        base['playwright_report_error'] = str(exc)
else:
    base['playwright_report'] = None
observer = None
observer_ok = False
if observer_json and Path(observer_json).exists():
    try:
        observer = json.loads(Path(observer_json).read_text())
        observer_ok = bool(observer.get('ok'))
    except Exception as exc:
        observer = {'present': False, 'error': str(exc)}
else:
    observer = {'present': False}
base['execution'] = {
    'mode': 'manual_only',
    'gate': 'run-manual-content-plugin-matrix',
    'dry_run': False,
    'gate_failed': gate_failed == '1',
}
base['observer'] = observer
ok = bool(base.get('ok')) and int(wp_rc) == 0 and int(pw_rc) == 0 and observer_ok and gate_failed != '1'
if base.get('playwright_report') and isinstance(base['playwright_report'], dict):
    for result in base['playwright_report'].get('results', []):
        if result.get('required') and not result.get('pass'):
            ok = False
base['ok'] = ok
Path(final_json).write_text(json.dumps(base, ensure_ascii=False, indent=2) + '\n')
print(final_json)
PY
}

# --- P0-EV-01: finish the observation window and persist evidence ----------
finish_observation_and_write() {
  local browser_mode="$1"   # ran | skipped | not_reached
  OBSERVER_JSON="${REPORTS_DIR}/manual-content-plugin-matrix-${STAMP}.observer.json"
  GATE_FAILED=0
  manual_isolation_finish
  if ! manual_isolation_assert; then
    echo "❌ Manual-gate observer violations detected (see [manual-isolation] output above)" >&2
    GATE_FAILED=1
  fi
  if ! manual_isolation_write_json "${OBSERVER_JSON}"; then
    echo "❌ Failed to write observer evidence block" >&2
    GATE_FAILED=1
  fi
  patch_browser_guard_into_observer "${OBSERVER_JSON}" "${PLAYWRIGHT_REPORT}" "${browser_mode}" || true
}

echo "== Manual content plugin matrix =="
echo "WP_BASE=${WP_BASE}"
echo "Projects=${PROJECTS_CSV:-all 20 from project-specs.json}"
echo "No client / no auto translation"

if ! curl -sf -o /dev/null --max-time 8 "${WP_BASE}/"; then
  echo "❌ WordPress not reachable at ${WP_BASE}" >&2
  exit 1
fi

info "Ensuring WP admin account ${WP_ADMIN_USER:-e2esmokeadmin}..."
e2e_ensure_wp_admin_account

# --- P0-EV-01: open the observation window before any gate work ------------
if ! manual_isolation_preflight; then
  echo "❌ Manual gate precondition failed: forbidden client/mock/8977/9090/8787 infrastructure is already running" >&2
  exit 1
fi
manual_isolation_begin

set +e
E2E_MANUAL_PROJECTS="${PROJECTS_CSV}" wp_eval "${E2E_DIR}/php/manual-content-plugin-matrix.php" 2>&1 | tee "${RAW_REPORT}"
WP_RC=${PIPESTATUS[0]}
set -e

if ! extract_json_from_log "${RAW_REPORT}" "${PHP_REPORT}"; then
  echo "❌ Could not extract PHP JSON from ${RAW_REPORT}" >&2
  exit 1
fi

PHP_OK="$(json_get "${PHP_REPORT}" ok || echo 0)"
if [[ "${WP_RC}" -ne 0 || "${PHP_OK}" != "1" ]]; then
  finish_observation_and_write "not_reached"
  combine_report "${PHP_REPORT}" "${FINAL_REPORT}" "${WP_RC}" 1 "${RAW_REPORT}" "${PW_LOG}" "${PLAYWRIGHT_REPORT}" "${OBSERVER_JSON}" "${GATE_FAILED}" >/dev/null || true
  echo "❌ PHP manual matrix failed: ${FINAL_REPORT}" >&2
  exit 1
fi

if [[ ! -f "${TARGETS_FILE}" ]]; then
  echo "❌ Missing Playwright targets: ${TARGETS_FILE}" >&2
  finish_observation_and_write "not_reached"
  combine_report "${PHP_REPORT}" "${FINAL_REPORT}" "${WP_RC}" 1 "${RAW_REPORT}" "${PW_LOG}" "${PLAYWRIGHT_REPORT}" "${OBSERVER_JSON}" "${GATE_FAILED}" >/dev/null || true
  exit 1
fi

PW_RC=0
if [[ "${SKIP_PLAYWRIGHT}" -eq 0 ]]; then
  info "Running Playwright manual-content-plugin-matrix..."
  # P1 parallelization (PLAN-2026-08-31): Playwright config reads PW_WORKERS
  # (default 1 = legacy serial) and per-worker report fragments are merged by
  # scripts/merge-playwright-worker-reports.sh. MEASURED 2026-09-01 (run 12):
  # PW_WORKERS=4 gave NO speedup (1328s vs 1398s serial) and caused WP admin
  # page timeouts (each browser action slowed ~4x under concurrent load), so
  # the default stays serial. Override explicitly (PW_WORKERS=2) only for
  # targeted experiments.
  export PW_WORKERS="${PW_WORKERS:-1}"
  info "Playwright workers: ${PW_WORKERS}"
  set +e
  (
    cd "${E2E_DIR}/playwright"
    export WP_BASE="${WP_BASE}"
    export E2E_RUNTIME_DIR="${RUNTIME_DIR}"
    export WP_ADMIN_USER="${WP_ADMIN_USER:-e2esmokeadmin}"
    export WP_ADMIN_PASS="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
    npx playwright test -c playwright.manual-content-plugin-matrix.config.ts \
      manual-content-plugin-matrix/manual-content-plugin-matrix.gate.e2e.spec.ts
  ) 2>&1 | tee "${PW_LOG}"
  PW_RC=${PIPESTATUS[0]}
  set -e
  # Parallel runs (PW_WORKERS > 1) leave one fragment per worker; merge them
  # back into the single report combine_report expects. No-op otherwise.
  bash "${SCRIPT_DIR}/scripts/merge-playwright-worker-reports.sh" "${PLAYWRIGHT_REPORT}" || {
    echo "❌ Failed to merge per-worker Playwright reports" >&2
    exit 1
  }
else
  warn "Playwright browser/admin/frontend verification skipped"
fi

finish_observation_and_write "$( [[ "${SKIP_PLAYWRIGHT}" -eq 1 ]] && echo skipped || echo ran )"
combine_report "${PHP_REPORT}" "${FINAL_REPORT}" "${WP_RC}" "${PW_RC}" "${RAW_REPORT}" "${PW_LOG}" "${PLAYWRIGHT_REPORT}" "${OBSERVER_JSON}" "${GATE_FAILED}" >/dev/null

if [[ "${PW_RC}" -ne 0 ]]; then
  echo "❌ Manual content plugin matrix failed in Playwright: ${FINAL_REPORT}" >&2
  exit "${PW_RC}"
fi

if [[ "${GATE_FAILED}" -ne 0 ]]; then
  echo "❌ Manual content plugin matrix failed observer checks: ${FINAL_REPORT}" >&2
  exit 1
fi

if [[ "${PREPARE_ONLY}" -eq 1 ]]; then
  echo "✅ Manual content plugin matrix prepared: ${FINAL_REPORT}"
else
  echo "✅ Manual content plugin matrix passed: ${FINAL_REPORT}"
fi
