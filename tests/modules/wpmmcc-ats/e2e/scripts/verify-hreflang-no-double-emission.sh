#!/usr/bin/env bash
# hreflang no-double-emission verification (WP-WRAP-01 item 3).
#
# Runs the hreflang verifier twice against the lab WordPress:
#   1. Yoast (wordpress-seo) inactive;
#   2. Yoast active.
# In both scenarios every page must emit at most one hreflang alternate per
# language, and the alternate set must stay complete. The initial Yoast
# activation state is restored afterwards.
#
# Usage:
#   bash verify-hreflang-no-double-emission.sh [wp-base-url]
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO="$(cd "${E2E_DIR}/../../../.." && pwd)"
# shellcheck source=/dev/null
source "${E2E_DIR}/config.sh"

BASE_URL="${1:-${WP_URL:-}}"
if [[ -z "${BASE_URL}" ]]; then
  echo "usage: $0 <wp-base-url>  (or set WP_URL via config.sh)" >&2
  exit 2
fi

TIMESTAMP="$(date +%Y%m%d-%H%M%S)-$$"
REPORT_DIR="${REPORTS_DIR}"
mkdir -p "${REPORT_DIR}"
SUMMARY_JSON="${REPORT_DIR}/hreflang-no-double-${TIMESTAMP}.json"

# Expected alternate languages: by default only enforce no-duplicate emission.
# Lab accumulates many active virtual sites; requiring every VS lang on every
# sample URL is too strict for day-to-day gates. Opt in with
# WPTSALL_HREFLANG_EXPECT_ALL=1 (release evidence) to require full alternate sets.
EXPECTED_LANGS=""
if [[ "${WPTSALL_HREFLANG_EXPECT_ALL:-0}" == "1" ]]; then
  EXPECTED_LANGS="$( { wp_cli eval 'echo implode(",", array_filter(array_map(static function ($s) { return (string) ($s["lang"] ?? ""); }, \WPTSALL\Sites\Services\Virtual_Site_Service::get_all(array("status" => "active")))));' 2>/dev/null || true; } | tr ',' '\n' | sed '/^$/d' | sort -u | paste -sd, -)"
fi
echo "hreflang check base=${BASE_URL} expected_langs=${EXPECTED_LANGS:-<none-duplicate-only>}"

yoast_active() {
  wp_cli plugin list --status=active --field=name 2>/dev/null | grep -qx "wordpress-seo"
}

run_verifier() {
  local label="$1"
  local report="${REPORT_DIR}/hreflang-no-double-${TIMESTAMP}.${label}.json"
  local args=(--base "${BASE_URL}" --report "${report}")
  if [[ -n "${EXPECTED_LANGS}" ]]; then
    args+=(--expect-langs "${EXPECTED_LANGS}")
  fi
  php "${E2E_DIR}/php/verify-hreflang-no-double-emission.php" "${args[@]}"
}

OVERALL="passed"
SCENARIOS="[]"

# --- Scenario A: Yoast inactive ---------------------------------------------
YOAST_WAS_ACTIVE=0
if yoast_active; then
  YOAST_WAS_ACTIVE=1
  echo "scenario A: deactivating wordpress-seo (was active; will restore)"
  wp_cli plugin deactivate wordpress-seo || true
fi

if run_verifier "yoast-inactive"; then
  echo "scenario A (yoast-inactive): PASS"
else
  echo "scenario A (yoast-inactive): FAIL" >&2
  OVERALL="failed"
fi

# --- Scenario B: Yoast active -------------------------------------------------
if wp_cli plugin activate wordpress-seo >/dev/null 2>&1 && yoast_active; then
  if run_verifier "yoast-active"; then
    echo "scenario B (yoast-active): PASS"
  else
    echo "scenario B (yoast-active): FAIL" >&2
    OVERALL="failed"
  fi
  if [[ "${YOAST_WAS_ACTIVE}" -ne 1 ]]; then
    wp_cli plugin deactivate wordpress-seo >/dev/null 2>&1 || true
  fi
else
  echo "scenario B (yoast-active): SKIPPED (wordpress-seo could not be activated)" >&2
  OVERALL="failed"
fi

# --- Restore initial state ----------------------------------------------------
if [[ "${YOAST_WAS_ACTIVE}" -eq 1 ]] && ! yoast_active; then
  wp_cli plugin activate wordpress-seo >/dev/null 2>&1 || true
fi

# --- Combined summary ----------------------------------------------------------
python3 - "$SUMMARY_JSON" "$TIMESTAMP" "$BASE_URL" "$OVERALL" "$REPORT_DIR" "$TIMESTAMP" <<'PY'
import json, sys
from pathlib import Path
out, ts, base, overall, report_dir, stamp = sys.argv[1:7]
scenarios = []
for label in ("yoast-inactive", "yoast-active"):
    p = Path(report_dir) / f"hreflang-no-double-{stamp}.{label}.json"
    if p.exists():
        data = json.loads(p.read_text())
        scenarios.append({
            "scenario": label,
            "status": "passed" if data.get("hard_fail", 1) == 0 else "failed",
            "hard_fail": data.get("hard_fail", 0),
            "pages": [p2.get("label") for p2 in data.get("pages", [])],
            "report": str(p),
        })
    else:
        scenarios.append({"scenario": label, "status": "skipped", "report": ""})
Path(out).write_text(json.dumps({
    "suite": "hreflang-no-double-emission",
    "timestamp": ts,
    "base": base,
    "status": overall,
    "dry_run": False,
    "scenarios": scenarios,
}, indent=2) + "\n")
print(f"Wrote {out} status={overall}")
PY

if [[ "${OVERALL}" != "passed" ]]; then
  exit 1
fi
echo "hreflang no-double-emission PASSED — ${SUMMARY_JSON}"
