#!/usr/bin/env bash
# Sample cross matrix: content plugin projects × mock signature families.
# Does not run full Cartesian product — generates a bounded run list + JSON report.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"

CATALOG="${REPO_ROOT}/tests/infra/fixtures/VENDOR_CATALOG.json"
SPECS="${E2E_PROJECT_SPECS_FILE}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_JSON="${REPORTS_DIR}/content-vendor-sample-${TIMESTAMP}.json"
DRY_RUN=1
APPLY=0
EXECUTE_WORKER=0
WORKER_LIMIT="${E2E_CROSS_WORKER_LIMIT:-8}"

usage() {
  cat <<EOF
Usage: bash tests/modules/wpmmcc-ats/e2e/run-content-vendor-sample-matrix.sh [options]

Options:
  --apply            Execute lightweight preflight checks for each sample pair
  --execute-worker   After planning, run bounded CT-3 worker samples (Lab client)
  --worker-limit N   Max CT-3 template samples to execute (default: ${WORKER_LIMIT})
  --dry-run          Print plan only (default)
  -h, --help         Show help

Report: tests/reports/e2e/wpmmcc-ats/content-vendor-sample-*.json

Sample policy: tests/modules/wpmmcc-ats/e2e/cross.yaml

Parallel-friendly companion (no Lab lock):
  python3 tests/infra/tools/m4-sign-family-parallel-smoke.py
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply) APPLY=1; DRY_RUN=0 ;;
    --execute-worker) EXECUTE_WORKER=1; APPLY=1; DRY_RUN=0 ;;
    --worker-limit)
      WORKER_LIMIT="${2:-8}"
      shift
      ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
  esac
  shift
done

ensure_dirs

if [[ ! -f "${CATALOG}" ]]; then
  echo "Missing ${CATALOG} — run: node tests/infra/tools/generate-vendor-catalog.mjs --write" >&2
  exit 1
fi

python3 - <<'PY' "${CATALOG}" "${SPECS}" "${REPORT_JSON}" "${DRY_RUN}" "${APPLY}" "${MOCK_API_URL}" "${WP_URL}"
import json, sys, urllib.request
from datetime import datetime, timezone

catalog_path, specs_path, report_path, dry_run, apply, mock_url, wp_url = sys.argv[1:8]
dry_run = dry_run == "1"
apply = apply == "1"

catalog = json.load(open(catalog_path))
specs = json.load(open(specs_path))
projects = specs.get("plugin_projects", {})

# Signature families for cross sampling
priority_families = ["baidu_md5", "bearer_v1", "youdao_sha256", "oauth_jwt"]
templates_by_family = {}
for t in catalog.get("templates", []):
    fam = t.get("signature_family")
    if fam and t.get("mock_route"):
        templates_by_family.setdefault(fam, []).append(t)

def pick_template(family):
    items = templates_by_family.get(family) or []
    return items[0] if items else None

# Core plugins for deep cross
core_slugs = {"wptsall", "woocommerce", "elementor"}
core_projects = [k for k, v in projects.items() if v.get("plugin_slug") in core_slugs]

samples = []
seen = set()

# Each required_rule_format × 2 signature families
for proj_name, proj in sorted(projects.items()):
    slug = proj.get("plugin_slug") or proj_name
    formats = proj.get("required_rule_formats") or ["plain_text"]
    for fmt in formats:
        for fam in priority_families[:2]:
            tmpl = pick_template(fam)
            if not tmpl:
                continue
            key = (proj_name, tmpl["template_id"])
            if key in seen:
                continue
            seen.add(key)
            samples.append({
                "plugin_project": proj_name,
                "plugin_slug": slug,
                "rule_format": fmt,
                "signature_family": fam,
                "template_id": tmpl["template_id"],
                "mock_route": tmpl.get("mock_route"),
                "recommended_e2e": f"E2E_PROJECT={proj_name} E2E_SCOPE=core-only WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run.sh",
                "recommended_ct3_filter": f"WPTSALL_USER_LOCAL_MOCK_IDS='{tmpl['template_id']}' WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh ct3",
            })

# P0-EV-04 modality representatives: guarantee every required content format
# appears on at least one automatic cross edge with one bounded sample each
# (representative bearer_v1/sha256 template × one core plugin). Required
# modality set mirrors cross-integrated.yaml vendors.
modality_by_template = {
    "official-mock-text-v1": "text",
    "official-mock-structured-v1": "structured",
    "official-mock-image-v1": "image",
    "official-mock-audio-v1": "audio",
    "official-mock-video-v1": "video",
    "official-mock-document-v1": "document",
    "mock-sign-md5": "text",
    "mock-sign-sha256": "rich_html",
    "mock-sign-oauth": "text",
}
required_modalities = ["text", "rich_html", "structured", "image", "audio", "video", "document"]
modality_templates = {
    "text": "official-mock-text-v1",
    "rich_html": "mock-sign-sha256",
    "structured": "official-mock-structured-v1",
    "image": "official-mock-image-v1",
    "audio": "official-mock-audio-v1",
    "video": "official-mock-video-v1",
    "document": "official-mock-document-v1",
}
templates_all = {t["template_id"]: t for t in catalog.get("templates", [])}
anchor_project = core_projects[0] if core_projects else (sorted(projects)[0] if projects else "")
covered_modalities = set()
for s in samples:
    m = modality_by_template.get(s.get("template_id"))
    if m:
        covered_modalities.add(m)
for mod in required_modalities:
    if mod in covered_modalities:
        continue
    tid = modality_templates.get(mod)
    tmpl = templates_all.get(tid)
    if not tmpl or not anchor_project:
        continue
    key = (anchor_project, tid)
    if key in seen:
        continue
    seen.add(key)
    samples.append({
        "plugin_project": anchor_project,
        "plugin_slug": projects[anchor_project].get("plugin_slug") if anchor_project in projects else anchor_project,
        "rule_format": (projects.get(anchor_project, {}).get("required_rule_formats") or ["plain_text"])[0],
        "signature_family": tmpl.get("signature_family"),
        "template_id": tid,
        "mock_route": tmpl.get("mock_route"),
        "sample_tier": "modality_representative",
        "modality": mod,
        "recommended_e2e": f"E2E_PROJECT={anchor_project} E2E_SCOPE=core-only WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run.sh",
        "recommended_ct3_filter": f"WPTSALL_USER_LOCAL_MOCK_IDS='{tid}' WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh ct3",
    })

# CT-3 style: 5 templates × 3 core plugins
extra_templates = []
for fam in priority_families:
    for t in templates_by_family.get(fam, [])[:2]:
        extra_templates.append(t)
extra_templates = extra_templates[:5]

for tmpl in extra_templates:
    for proj_name in core_projects:
        key = (proj_name, tmpl["template_id"])
        if key in seen:
            continue
        seen.add(key)
        proj = projects[proj_name]
        samples.append({
            "plugin_project": proj_name,
            "plugin_slug": proj.get("plugin_slug"),
            "rule_format": (proj.get("required_rule_formats") or ["plain_text"])[0],
            "signature_family": tmpl.get("signature_family"),
            "template_id": tmpl["template_id"],
            "mock_route": tmpl.get("mock_route"),
            "sample_tier": "core_cross",
            "recommended_e2e": f"E2E_PROJECT={proj_name} E2E_SCOPE=core-only WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run.sh",
        })

for s in samples:
    s.setdefault("modality", modality_by_template.get(s.get("template_id"), "text"))
    s.setdefault("endpoint_kind", "mock")

results = []
if apply:
    for s in samples:
        ok_mock = False
        ok_wp = False
        route = s.get("mock_route")
        if route:
            try:
                # Signed routes expect vendor auth; preflight only checks route exists via health + OPTIONS-ish GET
                urllib.request.urlopen(f"{mock_url.rstrip('/')}/api/v1/health", timeout=5)
                # Treat reachable mock + known route family as ok for sample planning
                ok_mock = True
            except Exception as e:
                s["mock_error"] = str(e)
        try:
            urllib.request.urlopen(wp_url, timeout=5)
            ok_wp = True
        except Exception as e:
            s["wp_error"] = str(e)
        s["preflight"] = {"mock": ok_mock, "wp": ok_wp}
        results.append(s)
else:
    results = samples

# P0-EV-04 broad-evidence axes. Every sample in this lane targets the local
# mock provider by construction (mock_route templates only), so mock-only
# evidence can never label a family live-supported; live support requires a
# live-endpoint sample recorded in live_evidence_families (empty here).
families_present = sorted({s.get("signature_family") for s in results if s.get("signature_family")})
provider_family_axis = {}
for fam in families_present:
    fam_samples = [s for s in results if s.get("signature_family") == fam]
    provider_family_axis[fam] = {
        "sample_count": len(fam_samples),
        "templates": sorted({s.get("template_id") for s in fam_samples}),
        "endpoint_kinds": sorted({s.get("endpoint_kind", "mock") for s in fam_samples}),
        "live_supported": False,
        "live_evidence": [],
    }
content_axis = {}
for proj in sorted({s.get("plugin_project") for s in results}):
    proj_samples = [s for s in results if s.get("plugin_project") == proj]
    content_axis[proj] = {
        "sample_count": len(proj_samples),
        "rule_formats": sorted({s.get("rule_format") for s in proj_samples if s.get("rule_format")}),
        "modalities": sorted({s.get("modality") for s in proj_samples if s.get("modality")}),
        "signature_families": sorted({s.get("signature_family") for s in proj_samples if s.get("signature_family")}),
    }
modalities_covered = sorted({s.get("modality") for s in results if s.get("modality")})
report = {
    "suite": "content-vendor-sample-matrix",
    "status": "planned" if dry_run else "incomplete",
    "dry_run": dry_run,
    "finished_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "summary": {
        "sample_count": len(results),
        "plugin_projects": len(projects),
        "catalog_templates": catalog.get("summary", {}).get("total_templates"),
    },
    "axes": {
        "content_axis": content_axis,
        "provider_family_axis": provider_family_axis,
        "cross_sample": {
            "sample_count": len(results),
            "bound": 120,
            "bounded_by": "representative families x projects (no Cartesian product); cross-integrated.yaml",
            "modalities_covered": modalities_covered,
            "required_modalities": required_modalities,
        },
        "release_only_edges": [],
    },
    "endpoint_kinds_used": sorted({s.get("endpoint_kind", "mock") for s in results}),
    "live_supported_families": [],
    "live_evidence_families": [],
    "labeling_rule": "A signature family may be labeled live_supported only when at least one sample for that family used a live endpoint (endpoint_kind=live) recorded in live_evidence_families; mock-only evidence never grants live support.",
    "samples": results,
}
json.dump(report, open(report_path, "w"), indent=2)
print(f"Wrote {report_path} samples={len(results)}")
if dry_run:
    for s in results[:5]:
        print(f"  - {s['plugin_project']} × {s['signature_family']} ({s['template_id']})")
    if len(results) > 5:
        print(f"  ... and {len(results) - 5} more")
PY

ok "Cross sample matrix report: ${REPORT_JSON}"

if [ "${EXECUTE_WORKER}" = "1" ]; then
  info "Executing bounded CT-3 worker samples (limit=${WORKER_LIMIT})..."
  mapfile -t SAMPLE_TEMPLATE_IDS < <(
    python3 - <<'PY' "${REPORT_JSON}" "${WORKER_LIMIT}" "${CATALOG}"
import json, sys
report=json.load(open(sys.argv[1]))
limit=int(sys.argv[2])
catalog=json.load(open(sys.argv[3]))
seen=set()
ids=[]
priority=("baidu_md5","bearer_v1","youdao_sha256","oauth_jwt","hmac_sha256","alibaba_v1","aws_sigv4","azure_subscription","tencent_tc3","volcengine_sigv4","kakao_ak")
samples=report.get("samples") or []
ordered=sorted(samples, key=lambda s: (0 if s.get("signature_family") in priority else 1, s.get("plugin_project","")))
for s in ordered:
    tid=s.get("template_id")
    if not tid or tid in seen:
        continue
    seen.add(tid)
    ids.append(tid)
    if len(ids)>=limit:
        break
# Fill remaining slots from catalog templates that have mock_route (sign-family smoke path)
if len(ids) < limit:
    by_fam={}
    for t in catalog.get("templates") or []:
        fam=t.get("signature_family")
        tid=t.get("template_id")
        if not fam or not tid or not t.get("mock_route") or tid in seen:
            continue
        by_fam.setdefault(fam, []).append(tid)
    for fam in priority:
        for tid in by_fam.get(fam, []):
            if tid in seen:
                continue
            seen.add(tid)
            ids.append(tid)
            if len(ids)>=limit:
                break
        if len(ids)>=limit:
            break
    if len(ids) < limit:
        for fam, tids in by_fam.items():
            for tid in tids:
                if tid in seen:
                    continue
                seen.add(tid)
                ids.append(tid)
                if len(ids)>=limit:
                    break
            if len(ids)>=limit:
                break
print("\n".join(ids))
PY
  )
  WORKER_REPORT="${REPORTS_DIR}/content-vendor-worker-${TIMESTAMP}.json"
  WORKER_LOG="/tmp/content-vendor-worker-${TIMESTAMP}.log"
  : > "${WORKER_LOG}"
  declare -a WORKER_RESULTS=()
  idx=0
  for tid in "${SAMPLE_TEMPLATE_IDS[@]}"; do
    idx=$((idx + 1))
    info "[${idx}/${#SAMPLE_TEMPLATE_IDS[@]}] CT-3 filter template=${tid}"
    set +e
    (
      export WPTSALL_LAB=1
      export WPTSALL_USER_LOCAL_MOCK_IDS="${tid}"
      bash "${SCRIPT_DIR}/run-component-template-lane.sh" ct3
    ) >>"${WORKER_LOG}" 2>&1
    rc=$?
    set -e
    if [ "$rc" -eq 0 ]; then
      ok "worker sample passed: ${tid}"
      WORKER_RESULTS+=("{\"template_id\":\"${tid}\",\"status\":\"passed\",\"exit_code\":0}")
    else
      err "worker sample failed: ${tid} (exit=${rc})"
      WORKER_RESULTS+=("{\"template_id\":\"${tid}\",\"status\":\"failed\",\"exit_code\":${rc}}")
    fi
  done
  python3 - <<'PY' "${WORKER_REPORT}" "${WORKER_LOG}" "${WORKER_RESULTS[@]}"
import json, sys
from datetime import datetime, timezone
path, log = sys.argv[1], sys.argv[2]
rows=[json.loads(x) for x in sys.argv[3:]]
passed=sum(1 for r in rows if r.get("status")=="passed")
failed=sum(1 for r in rows if r.get("status")!="passed")
json.dump({
  "suite":"content-vendor-worker-samples",
  "status":"passed" if failed==0 else "failed",
  "dry_run": False,
  "finished_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
  "log": log,
  "evidence": [log] if failed==0 and passed>0 else [],
  "passed": passed,
  "failed": failed,
  "results": rows,
}, open(path,"w"), indent=2)
print(f"Wrote {path} passed={passed} failed={failed}")
PY
  ok "Worker sample report: ${WORKER_REPORT}"
fi
