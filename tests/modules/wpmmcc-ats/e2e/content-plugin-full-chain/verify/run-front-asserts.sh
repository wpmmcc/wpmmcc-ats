#!/usr/bin/env bash
# P6: front HTTP asserts — locale URL reachable + mock marker present when translated.
#
# Env:
#   FULL_CHAIN_FRONT_URLS   comma URLs (optional; else derive from WP_URL + /en/ home)
#   FULL_CHAIN_EXPECT_MARKER=1  require 【xx_XX】 in HTML (default 1 after translate)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../../lib/repo-root.sh"
E2E_DIR="$(wptsall_path e2e "${SCRIPT_DIR}")"
REPO_ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"
# shellcheck source=../../config.sh
source "${E2E_DIR}/config.sh"

PROJECT_ID="${1:-${E2E_PROJECT:-wptsall-content}}"
REPORT_DIR="${FULL_CHAIN_REPORT_DIR:-${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/content-plugin-full-chain}"
mkdir -p "${REPORT_DIR}"
STAMP="$(date +%Y%m%dT%H%M%S)"
OUT="${REPORT_DIR}/phase-p6-front-${PROJECT_ID}-${STAMP}.json"
EXPECT_MARKER="${FULL_CHAIN_EXPECT_MARKER:-1}"

WP_BASE="${WP_URL:-${LAB_WP_BASE:-http://127.0.0.1:9083}}"
WP_BASE="${WP_BASE%/}"

URLS=()
if [[ -n "${FULL_CHAIN_FRONT_URLS:-}" ]]; then
  IFS=',' read -ra URLS <<<"${FULL_CHAIN_FRONT_URLS}"
else
  # Probe known locale prefixes; only keep URLs that respond (avoid hardcoding /zh/).
  CANDIDATES=("${WP_BASE}/" "${WP_BASE}/en/" "${WP_BASE}/zh/" "${WP_BASE}/zh-hans/" "${WP_BASE}/zh_CN/")
  for u in "${CANDIDATES[@]}"; do
    code="$(curl --noproxy '*' -sS -o /dev/null -m 8 -w '%{http_code}' "$u" 2>/dev/null || echo 000)"
    if [[ "$code" =~ ^2|3 ]]; then
      URLS+=("$u")
    fi
  done
  if [[ ${#URLS[@]} -eq 0 ]]; then
    URLS=("${WP_BASE}/")
  fi
fi

info "P6 front asserts project=${PROJECT_ID} urls=${#URLS[@]}"

python3 - "$OUT" "$PROJECT_ID" "$EXPECT_MARKER" "${URLS[@]}" <<'PY'
import json, re, sys, urllib.error, urllib.request

out, project, expect_marker, *urls = sys.argv[1:]
marker_re = re.compile(r"【[a-z]{2}_[A-Z]{2}】.*?【/[a-z]{2}_[A-Z]{2}】", re.S)
hreflang_re = re.compile(r'rel=["\']alternate["\'][^>]*hreflang=|hreflang=["\'][^"\']+["\'][^>]*rel=["\']alternate["\']', re.I)

results = []
ok_all = True
for url in urls:
    url = url.strip()
    if not url:
        continue
    entry = {"url": url, "http": None, "marker": False, "hreflang": False, "ok": False, "error": None}
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "wptsall-full-chain/1.0"})
        with urllib.request.urlopen(req, timeout=25) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            entry["http"] = resp.status
            entry["marker"] = bool(marker_re.search(body))
            entry["hreflang"] = bool(hreflang_re.search(body))
            # Home pages may not always carry markers; /en/ zh virtual pages should after translate.
            need_marker = expect_marker == "1" and ("/en/" in url or "/zh/" in url)
            entry["ok"] = (200 <= resp.status < 400) and (entry["marker"] if need_marker else True)
    except Exception as exc:  # noqa: BLE001
        entry["error"] = str(exc)
        entry["ok"] = False
    if not entry["ok"]:
        ok_all = False
    results.append(entry)

# Soft: if expect marker but no locale URL had marker, fail; pure / may pass without.
payload = {
    "phase": "p6_verify_front",
    "project_id": project,
    "ok": ok_all,
    "expect_marker": expect_marker == "1",
    "marker_pattern": "【locale】…【/locale】",
    "urls": results,
}
open(out, "w").write(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")
print(out)
raise SystemExit(0 if ok_all else 1)
PY
