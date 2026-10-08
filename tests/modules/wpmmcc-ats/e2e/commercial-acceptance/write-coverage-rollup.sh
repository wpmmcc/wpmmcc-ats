#!/usr/bin/env bash
# Write a coverage rollup from latest multi-site + all successful full-chain runs.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../../lib/repo-root.sh"
REPO_ROOT="$(wptsall_repo_root "${SCRIPT_DIR}")"
OUT_DIR="${REPO_ROOT}/tests/reports/e2e/commercial-acceptance"
mkdir -p "${OUT_DIR}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

python3 - "$REPO_ROOT" "$OUT_DIR" "$STAMP" <<'PY'
import json, sys
from pathlib import Path
repo, out_dir, stamp = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]

multi_root = out_dir / "multi-site-client-deep"
multi_runs = sorted(multi_root.glob("run-*"), key=lambda p: p.name) if multi_root.is_dir() else []
multi = {}
for run in reversed(multi_runs):
    s = run / "summary.json"
    if s.is_file():
        cand = json.loads(s.read_text())
        if cand.get("ok"):
            multi = cand
            break

chain_root = repo / "tests/reports/e2e/wpmmcc-ats/content-plugin-full-chain"
projects = []
seen = set()
ui_touches = []
latest_ok_manifest = None
for run in sorted(chain_root.glob("run-*"), key=lambda p: p.name):
    man = run / "manifest.json"
    if not man.is_file():
        continue
    m = json.loads(man.read_text())
    if not m.get("ok"):
        continue
    latest_ok_manifest = m
    for p in m.get("projects") or []:
        if p not in seen:
            seen.add(p)
            projects.append(p)
    for u in sorted(run.glob("per-project-ui-*.json")):
        ui_touches.append(json.loads(u.read_text()))

# Deduplicate UI touches by project (keep last)
by_proj = {}
for t in ui_touches:
    pid = t.get("project_id")
    if pid:
        by_proj[pid] = t
ui_list = list(by_proj.values())

specs = json.loads((repo / "tests/modules/wpmmcc-ats/e2e/project-specs.json").read_text())
all_projects = list(specs.get("plugin_projects", {}).keys())
missing = [p for p in all_projects if p not in seen]

sites = multi.get("sites") or []
jobs_count = multi.get("jobs_count") or 0
ms_en = multi.get("ms_en") or {}
has_ms_en = bool(ms_en.get("ok")) or any("/en" in str(s) for s in sites)
# Prefer journey step evidence from latest multi summary
if not has_ms_en:
    for step in multi.get("steps") or []:
        if step.get("step") == "ms_en_token_reuse_same_origin" and step.get("ok"):
            has_ms_en = True
            break

# Latest Desktop U1–U7 forms evidence (if any commercial run wrote it)
desktop_forms = {}
for run in sorted(out_dir.glob("run-*"), key=lambda p: p.name, reverse=True):
    cand = run / "desktop-forms" / "u1u7.json"
    if cand.is_file():
        desktop_forms = json.loads(cand.read_text())
        break
# Also accept standalone report path
standalone = out_dir / "desktop-forms" / "u1u7.json"
if not desktop_forms and standalone.is_file():
    desktop_forms = json.loads(standalone.read_text())

still_open = []
if missing:
    still_open.append("content plugins 20/20")
if (multi.get("sites_bound_via_ui") or 0) < 5:
    still_open.append("sites ≥5")
if jobs_count <= 0:
    still_open.append("jobs inventory often 0 (enable discovery + run-once after bootstrap)")
if not desktop_forms.get("ok"):
    still_open.append("Desktop WebView form matrix for U1–U7")
if not has_ms_en:
    still_open.append("multisite child /en/ (token-reuse same-origin check)")

rollup = {
    "stamp": stamp,
    "definition": "tasks/client2/21-COMMERCIAL-ACCEPTANCE-MATRIX-DEFINITION.md",
    "multi_site_client": {
        "ok": multi.get("ok"),
        "sites_bound_via_ui": multi.get("sites_bound_via_ui"),
        "sites": multi.get("sites"),
        "bindings_count": multi.get("bindings_count"),
        "discovery_tasks_count": multi.get("discovery_tasks_count"),
        "jobs_count": jobs_count,
        "has_ms_en": has_ms_en,
        "ms_en": ms_en or None,
        "report_dir": multi.get("report_dir"),
    },
    "content_plugins": {
        "ok": len(missing) == 0,
        "projects_union_ok": projects,
        "project_count": len(projects),
        "projects_total_in_specs": len(all_projects),
        "projects_missing": missing,
        "latest_ok_manifest": latest_ok_manifest.get("summary_path") if latest_ok_manifest else None,
        "per_project_client_ui_touches": [
            {
                "project_id": t.get("project_id"),
                "ok": t.get("ok"),
                "discovery_tasks": t.get("discovery_tasks"),
                "jobs": t.get("jobs"),
            }
            for t in sorted(ui_list, key=lambda x: x.get("project_id") or "")
        ],
        "per_project_ui_touch_count": len(ui_list),
        "note": "Union of all successful full-chain manifests; first project in each run uses full P3, others p3_touch",
    },
    "desktop_webview_u1u7": {
        "ok": bool(desktop_forms.get("ok")),
        "passed": desktop_forms.get("passed"),
        "total": desktop_forms.get("total"),
        "forms": desktop_forms.get("forms"),
    },
    "still_open_vs_release_dod": still_open,
    "ok": bool(multi.get("ok"))
    and len(missing) == 0
    and (multi.get("sites_bound_via_ui") or 0) >= 5
    and jobs_count > 0
    and bool(desktop_forms.get("ok"))
    and has_ms_en,
}
text = json.dumps(rollup, indent=2, ensure_ascii=False) + "\n"
(out_dir / f"coverage-rollup-{stamp}.json").write_text(text)
(out_dir / "latest-coverage-rollup.json").write_text(text)
print(text)
PY
