#!/usr/bin/env bash
# ISS 8-user-scenario evidence aggregator (ISS T6).
# Maps each scenario to a concrete proof artifact produced by Lab scripts.
set -euo pipefail
E2E_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$(dirname "$0")/../../../../.." && pwd)}"
REPORTS="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats"
TS="$(date +%Y%m%d-%H%M%S)"
OUT="${REPORTS}/iss-eight-scenarios-${TS}.json"
mkdir -p "${REPORTS}"

latest() {
  local pat="$1"
  ls -1t ${pat} 2>/dev/null | head -1 || true
}

# Prefer the detailed acceptance artifact from the newest run. During the
# full acceptance flow this is already written before this aggregator runs.
ACC="$(latest "${REPORTS}/iss-acceptance-*/wp-acceptance.json")"
[[ -z "${ACC}" ]] && ACC="$(latest "${REPORTS}/iss-acceptance-*/SUMMARY.json")"
FAULT="$(latest "${REPORTS}/provider-fault-runtime-*.json")"
SEO="$(latest "${REPORTS}/iss-acceptance-*/seo.json")"
# Fallback: run SEO now if missing
if [[ -z "${SEO}" || ! -f "${SEO}" ]]; then
  SEO="${REPORTS}/seo-eight-${TS}.json"
  php "${E2E_DIR}/scripts/verify-seo-html-xml-matrix.php" --base="${WP_BASE_URL:-http://127.0.0.1:9083}" >"${SEO}" || true
fi

python3 - "${OUT}" "${ACC}" "${FAULT}" "${SEO}" <<'PY'
import json, os, sys, time
out, acc_path, fault_path, seo_path = sys.argv[1:5]

def load(p):
    if p and os.path.isfile(p):
        try:
            value = json.load(open(p))
            return value if isinstance(value, dict) else {}
        except Exception:
            return {}
    return {}

acc = load(acc_path)
if not isinstance(acc, dict):
    acc = {}
# The acceptance runner's top-level SUMMARY is intentionally compact and may
# contain only check IDs (rather than the detailed check objects). Resolve the
# adjacent detailed artifact so scenario 4/5/8 can consume hard assertions.
checks = acc.get("checks") if isinstance(acc, dict) else None
if (not isinstance(checks, list) or not all(isinstance(c, dict) for c in checks)) and acc_path:
    sibling = os.path.join(os.path.dirname(acc_path), "wp-acceptance.json")
    detailed = load(sibling)
    if isinstance(detailed, dict) and detailed:
        acc = detailed
fault = load(fault_path)
seo = load(seo_path)
acc_failures = acc.get("wp_hard_fail", acc.get("hard_fail"))
acc_ok = bool(acc.get("ok") or (acc_failures is not None and int(acc_failures or 0) == 0))
fault_ok = bool(fault.get("ok") or (fault.get("hard_fail") == 0 and fault))
seo_ok = int(seo.get("hard_fail") or 0) == 0 and bool(seo)

def acceptance_checks(*prefixes):
    checks = {
        str(c.get("id")): bool(c.get("ok"))
        for c in acc.get("checks", [])
        if isinstance(c, dict) and c.get("id") is not None
    }
    matched = [ok for ident, ok in checks.items() if any(ident.startswith(p) for p in prefixes)]
    return bool(matched) and all(matched)

media_frontend_ok = acceptance_checks("PA1_media_frontend_")
menu_ok = acceptance_checks("PA4_")
lifecycle_ok = acceptance_checks("PA9_")

# Scenario mapping to available evidence.
scenarios = [
  {"id": 1, "name": "install_scan_translate_review_publish", "status": "partial_evidence", "evidence": [acc_path], "ok": acc_ok, "note": "acceptance covers auth/CAS/snapshot; full journey via existing e2e lanes"},
  {"id": 2, "name": "gutenberg_rest_stale_rebase", "status": "covered_by_PA10", "evidence": [acc_path], "ok": acc_ok, "note": "stale source_revision rejected in acceptance"},
  {"id": 3, "name": "manual_edit_conflict_no_overwrite", "status": "covered_by_PA6", "evidence": [acc_path], "ok": acc_ok, "note": "Field_Ownership CAS proven in acceptance"},
  {"id": 4, "name": "media_upload_srcset_og", "status": "covered_by_PA1_media_frontend", "evidence": [acc_path], "ok": media_frontend_ok, "note": "binary upload plus target URL/src/srcset/HTML/OG no-source-leak assertions"},
  {"id": 5, "name": "menu_source_update_delete_sync", "status": "covered_by_PA4", "evidence": [acc_path], "ok": menu_ok, "note": "clone, pending update, in-place refresh, virtual menu swap and delete cascade"},
  {"id": 6, "name": "frontend_no_source_leak_seo", "status": "covered_by_T4", "evidence": [seo_path], "ok": seo_ok, "note": "SEO HTML/XML hard assertions"},
  {"id": 7, "name": "provider_fault_dup_restart_le1_apply", "status": "covered_by_T5", "evidence": [fault_path], "ok": fault_ok, "note": "fault mock runtime + WP dual-callback rows=1"},
  {"id": 8, "name": "trash_untrash_delete_status", "status": "covered_by_PA9", "evidence": [acc_path], "ok": lifecycle_ok, "note": "real mapped source/target status, trash, untrash and force-delete propagation"},
]

hard_fail = 0
for s in scenarios:
    s["hard"] = True
    if not s["ok"]:
        hard_fail += 1

report = {
    "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    "scenarios": scenarios,
    "hard_fail": hard_fail,
    "ok": hard_fail == 0,
    "inputs": {"acceptance": acc_path, "fault": fault_path, "seo": seo_path},
}
open(out, "w").write(json.dumps(report, indent=2))
print(json.dumps({"ok": report["ok"], "hard_fail": hard_fail, "out": out}, indent=2))
sys.exit(0 if hard_fail == 0 else 1)
PY
