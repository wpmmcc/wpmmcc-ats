#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# Release gate is repo-global. Ambient matrix/slot exports (E2E_SLOT, slot WP URL,
# isolated client ports) must not reroute manual phases to slot-a/b/… — those lanes
# use wordpress-test (:9083) and reports/ (not reports/slot-a/).
unset E2E_SLOT E2E_SLOT_WP_ISOLATED E2E_SLOT_WP_BASE LAB_WP_CONTAINER \
  WP_URL WP_BASE CLIENT_BASE WPTSALL_DB_PATH CLIENT_URL \
  WPTSALL_WEB_UI_BIND WPTSALL_WEB_UI_PORT \
  WPTSALL_DEVICE_ID WPTSALL_WP_DEVICE_ID CT_LAB_WP_DEVICE_ID \
  WPTSALL_E2E_WP_CLIENT_TOKEN WPTSALL_E2E_ROUTE_SECRET \
  2>/dev/null || true
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
source "${SCRIPT_DIR}/config.sh"
# Manual-phase observer library (P0-EV-01/02): the manual preflight step uses
# its precondition check (no forbidden client/mock/infra processes or ports).
MANUAL_OBSERVER_LIB="$(ls "${SCRIPT_DIR}/lib/"manual-*.sh 2>/dev/null | head -1)"
if [[ -n "${MANUAL_OBSERVER_LIB}" ]]; then
  # shellcheck source=/dev/null
  source "${MANUAL_OBSERVER_LIB}"
fi
source "${SCRIPT_DIR}/lib/failure-classification.sh"

WITH_CORE_GATE=1
WITH_COMPONENT_TEMPLATES=1
WITH_PLUGIN_MATRIX=1
WITH_MANUAL_ONLY=1
# P0-EV-02: the 20-plugin manual content matrix is REQUIRED evidence for the
# full release level (default on); other levels keep it opt-in. Env override
# and --with/--skip flags still win (resolved after arg parsing).
WITH_MANUAL_CONTENT_MATRIX="${E2E_RELEASE_WITH_MANUAL_CONTENT_MATRIX:-}"
WITH_OFFICIAL_MANUAL_P0_P4="${E2E_RELEASE_WITH_OFFICIAL_MANUAL_P0_P4:-}"
LEVEL="${E2E_RELEASE_LEVEL:-full}"
CORE_PROJECT="${E2E_RELEASE_CORE_PROJECT:-core-content}"
CORE_SCOPE="${E2E_RELEASE_CORE_SCOPE:-full}"
MATRIX_SCOPE="${E2E_RELEASE_MATRIX_SCOPE:-full}"
SYNC_APPLY=0
HEADED=0
DRY_RUN=0
ALLOW_MISSING_SNAPSHOT=0
FROM_STEP=""
SKIP_PRODUCTS=()
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
# Canonical order for --from-step index comparison (must match run order).
RG_STEP_ORDER=(
  RG-MANUAL-PREFLIGHT
  RG-PAGES-SURFACE
  RG-MANUAL-ONLY
  RG-MANUAL-CONTENT-MATRIX
  RG-OFFICIAL-MANUAL-P0-P4
  RG-AUTOMATIC-PREFLIGHT
  RG-AUTO-LANES
  RG-ISS-ACCEPTANCE
  RG-CORE
  RG-CT
  RG-MATRIX
)
SUMMARY_TSV="$(mktemp)"
SUMMARY_JSON="${REPORTS_DIR}/e2e-release-gate-${TIMESTAMP}.json"
SUMMARY_MD="${REPORTS_DIR}/e2e-release-gate-${TIMESTAMP}.md"
BUDGET_PRE_JSON="${RUNTIME_DIR}/heavy-gate-budget-release-full-${TIMESTAMP}-pre.json"
BUDGET_POST_JSON="${RUNTIME_DIR}/heavy-gate-budget-release-full-${TIMESTAMP}-post.json"

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh [options]

Options:
  --level <smoke|business|full>
                              选择发布门禁层级，默认 full
  --skip-product <product>    跳过指定产品 smoke/business，并写入 report
  --skip-core-gate             跳过 core-content 业务主门禁
  --skip-component-templates   跳过组件模板专项 lane
  --skip-plugin-matrix         跳过独立插件矩阵 lane
  --skip-manual-only           跳过 WP 插件独立手动多语言 gate
  --with-manual-content-matrix 额外运行 20 内容插件手动矩阵（full level 默认开启）
  --skip-manual-content-matrix 跳过 20 内容插件手动矩阵（full level 跳过 => 报告 不得为 passed）
  --with-official-manual-p0-p4 运行官方件 P0–P4（full/business 默认开启）
  --skip-official-manual-p0-p4 跳过官方件 P0–P4（full/business 跳过 => 报告 不得为 passed）
  --core-project <project>     core gate project，默认 core-content
  --core-scope <core-only|full>
  --matrix-scope <core-only|full>
  --sync-apply                 先执行 CT0 official->user local mock sync apply
  --headed                     透传 headed 到支持的子入口
  --dry-run                    full level 只输出预算和执行计划，不执行重型门禁
  --allow-missing-snapshot     full level 允许无 snapshot 继续运行（默认阻断）
  --from-step <STEP_ID>        从指定步骤起跑（之前步骤记为 prior skip；不因跳过 REQUIRED 判 incomplete）
  --help|-h                    显示帮助

Execution order (P0-EV-02 4.1, manual phases first):
  1. RG-MANUAL-PREFLIGHT      WordPress reachable + no forbidden client/mock/infra
  1b. RG-PAGES-SURFACE        lean VS home / shadow singular HTTP surface (REQUIRED)
  2. RG-MANUAL-ONLY           WP-plugin manual multilingual gate (no client)
  3. RG-MANUAL-CONTENT-MATRIX 20-plugin manual content matrix (default on for --level full)
  3b. RG-OFFICIAL-MANUAL-P0-P4 official fixtures + identity gate + priority seams
  4. RG-AUTOMATIC-PREFLIGHT   WordPress + mock API + Client checks (automation infra)
  5. RG-AUTO-* lanes          (P0-EV-03: content surfaces, consistency, topology/time)
  6. RG-ISS-ACCEPTANCE        security + full-flow acceptance
  7. RG-CORE                  core business gate
  8. RG-CT                    component template suite
  9. RG-MATRIX                plugin matrix (website journeys are legacy-only)
Manual-step failure prevents all automation steps from running (fail-fast).
Resume: bash …/run-release-gate.sh --from-step RG-AUTO-LANES
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --level)
      LEVEL="${2:-}"
      shift 2
      ;;
    --skip-product)
      SKIP_PRODUCTS+=("${2:-}")
      shift 2
      ;;
    --skip-core-gate)
      WITH_CORE_GATE=0
      shift
      ;;
    --skip-component-templates)
      WITH_COMPONENT_TEMPLATES=0
      shift
      ;;
    --skip-plugin-matrix)
      WITH_PLUGIN_MATRIX=0
      shift
      ;;
    --skip-manual-only)
      WITH_MANUAL_ONLY=0
      shift
      ;;
    --with-manual-content-matrix)
      WITH_MANUAL_CONTENT_MATRIX=1
      shift
      ;;
    --skip-manual-content-matrix)
      WITH_MANUAL_CONTENT_MATRIX=0
      shift
      ;;
    --with-official-manual-p0-p4)
      WITH_OFFICIAL_MANUAL_P0_P4=1
      shift
      ;;
    --skip-official-manual-p0-p4)
      WITH_OFFICIAL_MANUAL_P0_P4=0
      shift
      ;;
    --core-project)
      CORE_PROJECT="$2"
      shift 2
      ;;
    --core-scope)
      CORE_SCOPE="$2"
      shift 2
      ;;
    --matrix-scope)
      MATRIX_SCOPE="$2"
      shift 2
      ;;
    --sync-apply)
      SYNC_APPLY=1
      shift
      ;;
    --headed)
      HEADED=1
      shift
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --allow-missing-snapshot)
      ALLOW_MISSING_SNAPSHOT=1
      shift
      ;;
    --from-step)
      FROM_STEP="${2:-}"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

rg_step_index() {
  local id="$1" i=0
  for s in "${RG_STEP_ORDER[@]}"; do
    if [[ "$s" == "$id" ]]; then
      echo "$i"
      return 0
    fi
    i=$((i + 1))
  done
  echo "-1"
}

FROM_STEP_INDEX=-1
if [[ -n "${FROM_STEP}" ]]; then
  FROM_STEP_INDEX="$(rg_step_index "${FROM_STEP}")"
  if [[ "${FROM_STEP_INDEX}" -lt 0 ]]; then
    echo "Unknown --from-step: ${FROM_STEP}" >&2
    echo "Known: ${RG_STEP_ORDER[*]}" >&2
    exit 1
  fi
fi

rg_should_skip_as_prior() {
  local id="$1" idx
  [[ -z "${FROM_STEP}" ]] && return 1
  idx="$(rg_step_index "$id")"
  [[ "$idx" -ge 0 && "$idx" -lt "${FROM_STEP_INDEX}" ]]
}

case "${LEVEL}" in
  full) : ;;
  *) : ;;
esac
if [[ -z "${WITH_MANUAL_CONTENT_MATRIX}" ]]; then
  if [[ "${LEVEL}" == "full" ]]; then
    WITH_MANUAL_CONTENT_MATRIX=1
  else
    WITH_MANUAL_CONTENT_MATRIX=0
  fi
fi
case "${WITH_MANUAL_CONTENT_MATRIX}" in
  1|true|TRUE|yes|YES) WITH_MANUAL_CONTENT_MATRIX=1 ;;
  *) WITH_MANUAL_CONTENT_MATRIX=0 ;;
esac

ensure_dirs

case "${LEVEL}" in
  smoke|business|full)
    ;;
  *)
    echo "Invalid --level: ${LEVEL}" >&2
    usage >&2
    exit 1
    ;;
esac

for product in "${SKIP_PRODUCTS[@]}"; do
  case "${product}" in
    wptsall|cloud-api-hub|github-deployer)
      ;;
    *)
      echo "Invalid --skip-product: ${product}" >&2
      usage >&2
      exit 1
      ;;
  esac
done

product_is_skipped() {
  local product="$1"
  local skipped=""
  for skipped in "${SKIP_PRODUCTS[@]}"; do
    if [[ "${skipped}" == "${product}" ]]; then
      return 0
    fi
  done
  return 1
}

print_stage "RELEASE" "Unified Release Gate"
echo -e "  Level:               ${BOLD}${LEVEL}${NC}"
echo -e "  Core gate:           ${BOLD}${WITH_CORE_GATE}${NC}"
echo -e "  Component templates: ${BOLD}${WITH_COMPONENT_TEMPLATES}${NC}"
echo -e "  Plugin matrix:       ${BOLD}${WITH_PLUGIN_MATRIX}${NC}"
echo -e "  Manual-only gate:    ${BOLD}${WITH_MANUAL_ONLY}${NC}"
echo -e "  Manual content mx:   ${BOLD}${WITH_MANUAL_CONTENT_MATRIX}${NC}"
echo -e "  Core project/scope:  ${BOLD}${CORE_PROJECT} / ${CORE_SCOPE}${NC}"
echo -e "  Matrix scope:        ${BOLD}${MATRIX_SCOPE}${NC}"
echo -e "  Sync apply:          ${BOLD}${SYNC_APPLY}${NC}"
echo -e "  Dry-run:             ${BOLD}${DRY_RUN}${NC}"
echo -e "  From-step:           ${BOLD}${FROM_STEP:-}${NC}"
echo -e "  Report:              ${BOLD}${SUMMARY_JSON}${NC}"
echo -e "  Summary:             ${BOLD}${SUMMARY_MD}${NC}"
echo ""

latest_matching_file() {
  local pattern="$1"
  local latest
  latest="$(ls -1t ${pattern} 2>/dev/null | head -1 || true)"
  echo "${latest}"
}

latest_product_report() {
  local product="$1"
  local mode="$2"
  if [[ "${mode}" == "business" ]]; then
    latest_matching_file "${REPORTS_DIR}/product-smoke-${product}-business-*.json"
  else
    ls -1t "${REPORTS_DIR}/product-smoke-${product}-"*.json 2>/dev/null | grep -v -- "-business-" | head -1 || true
  fi
}

write_staging_summary() {
  local status="$1"
  python3 - <<'PY' "$SUMMARY_TSV" "$SUMMARY_JSON" "$SUMMARY_MD" "$LEVEL" "$status" "${SKIP_PRODUCTS[*]:-}"
import csv
import json
import sys
from pathlib import Path

tsv_path = Path(sys.argv[1])
json_path = Path(sys.argv[2])
md_path = Path(sys.argv[3])
level = sys.argv[4]
status = sys.argv[5]
skip_products = [item for item in sys.argv[6].split() if item]

rows = []
if tsv_path.exists():
    with tsv_path.open() as fh:
        reader = csv.reader(fh, delimiter="\t")
        for row in reader:
            if len(row) != 14:
                continue
            step_id, label, product, mode, step_status, exit_code, duration_secs, times, evidence, category, surface, message, next_action, skip_reason = row
            started_at, finished_at = times.split("|", 1)
            rows.append({
                "step_id": step_id,
                "label": label,
                "product": product or None,
                "mode": mode or None,
                "status": step_status,
                "exit_code": int(exit_code),
                "duration_secs": int(duration_secs),
                "started_at": started_at,
                "finished_at": finished_at,
                "evidence": evidence or None,
                "category": None if category == "none" else category,
                "surface": None if surface == "none" else surface,
                "message": message or None,
                "next_action": next_action or None,
                "skip_reason": skip_reason or None,
            })

phases = [
    {
        "name": row["step_id"],
        "status": row["status"],
        "exit_code": row.get("exit_code"),
        "detail": (row.get("message") or row.get("label") or ""),
        "duration_secs": row.get("duration_secs"),
    }
    for row in rows
]
payload = {
    "status": status,
    "dry_run": False,
    "timestamp": rows[-1]["finished_at"] if rows else None,
    "level": level,
    "skip_products": skip_products,
    "summary": {
        "passed": sum(1 for row in rows if row["status"] == "passed"),
        "failed": sum(1 for row in rows if row["status"] == "failed"),
        "skipped": sum(1 for row in rows if row["status"] == "skipped"),
        "total": len(rows),
    },
    "phases": phases,
    "steps": rows,
}
json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n")

lines = [
    "# Release Gate Staging",
    "",
    f"- Status: **{status}**",
    f"- Level: `{level}`",
    f"- Skipped products: `{', '.join(skip_products) if skip_products else '-'}`",
    "",
    "| Step | Product | Mode | Status | Duration(s) | Evidence |",
    "| --- | --- | --- | --- | ---: | --- |",
]
for row in rows:
    evidence = Path(row["evidence"]).name if row.get("evidence") else "-"
    lines.append(
        f"| {row['step_id']} {row['label']} | {row.get('product') or '-'} | "
        f"{row.get('mode') or '-'} | {row['status']} | {row['duration_secs']} | {evidence} |"
    )
md_path.write_text("\n".join(lines) + "\n")
PY
}

append_staging_row() {
  local step_id="$1"
  local label="$2"
  local product="$3"
  local mode="$4"
  local status="$5"
  local exit_code="$6"
  local duration_secs="$7"
  local started_at="$8"
  local finished_at="$9"
  local evidence="${10}"
  local category="${11}"
  local surface="${12}"
  local message="${13}"
  local next_action="${14}"
  local skip_reason="${15}"

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$step_id" "$label" "$product" "$mode" "$status" "$exit_code" "$duration_secs" \
    "$started_at|$finished_at" "$evidence" "$category" "$surface" "$message" "$next_action" "$skip_reason" >> "$SUMMARY_TSV"
}

append_skipped_product() {
  local product="$1"
  local mode="$2"
  local now
  now="$(date -Iseconds)"
  append_staging_row "RG-${mode}-${product}" "product ${mode} smoke" "$product" "$mode" \
    "skipped" "0" "0" "$now" "$now" "" "none" "none" "skipped by --skip-product" "" "--skip-product ${product}"
}

run_staging_step() {
  local step_id="$1"
  local label="$2"
  local product="$3"
  local mode="$4"
  shift 4

  local started_at finished_at start_ts finish_ts duration_secs exit_code status
  local category surface message next_action evidence before_product after_product before_preflight after_preflight

  before_product=""
  after_product=""
  before_preflight="${RUNTIME_DIR}/preflight-latest.json"
  if [[ -n "${product}" ]]; then
    before_product="$(latest_product_report "${product}" "${mode}")"
  fi

  started_at="$(date -Iseconds)"
  start_ts="$(date +%s)"
  echo ""
  echo -e "${BOLD}${BLUE}>>> [${step_id}] ${label}${NC}"

  set +e
  "$@"
  exit_code=$?
  set -e

  finish_ts="$(date +%s)"
  finished_at="$(date -Iseconds)"
  duration_secs=$((finish_ts - start_ts))
  status="passed"
  category="none"
  surface="none"
  message=""
  next_action=""
  evidence=""

  if [[ -n "${product}" ]]; then
    after_product="$(latest_product_report "${product}" "${mode}")"
    if [[ -n "${after_product}" && "${after_product}" != "${before_product}" ]]; then
      evidence="${after_product}"
    elif [[ -n "${after_product}" ]]; then
      evidence="${after_product}"
    fi
  elif [[ -f "${before_preflight}" ]]; then
    after_preflight="${before_preflight}"
    evidence="${after_preflight}"
  fi

  if [[ "$exit_code" -ne 0 ]]; then
    status="failed"
    e2e_classify_failure "release-gate:${step_id}" "${exit_code}"
    category="${E2E_FAILURE_CATEGORY}"
    surface="${E2E_FAILURE_SURFACE}"
    message="${E2E_FAILURE_MESSAGE}"
    next_action="${E2E_FAILURE_NEXT_ACTION}"
    err "Release gate staging step failed: ${label} (exit=${exit_code}, ${duration_secs}s, category=${category})"
  else
    ok "Release gate staging step passed: ${label} (${duration_secs}s)"
  fi

  append_staging_row "$step_id" "$label" "$product" "$mode" "$status" "$exit_code" "$duration_secs" \
    "$started_at" "$finished_at" "$evidence" "$category" "$surface" "$message" "$next_action" ""

  if [[ "$exit_code" -ne 0 ]]; then
    write_staging_summary "failed"
    rm -f "$SUMMARY_TSV"
    echo -e "  Report: ${BOLD}${SUMMARY_JSON}${NC}"
    echo -e "  Summary: ${BOLD}${SUMMARY_MD}${NC}"
    exit "$exit_code"
  fi
}

run_staging_level() {
  # Current release scope is WebUI + Desktop. Cloud API Hub and GitHub
  # Deployer remain explicit legacy reference lanes and are never run here.
  local products=(wptsall)
  local product=""
  local mode=""

  case "${LEVEL}" in
    smoke)
      local preflight_profile="smoke"
      case "${WPTSALL_LAB:-}" in
        1|true|yes|on) preflight_profile="lab-smoke" ;;
      esac
      run_staging_step "RG-SMOKE-PREFLIGHT" "preflight smoke (${preflight_profile})" "" "preflight" \
        bash "${REPO_ROOT}/tests/infra/test-host/preflight.sh" --profile "${preflight_profile}"
      run_staging_step "RG-SMOKE-SECRET-SCAN" "secret scan reports" "" "security" \
        bash "${SCRIPT_DIR}/scripts/secret-scan-reports.sh"
      run_staging_step "RG-SMOKE-PROVIDER-FAULT" "provider fault fixture matrix" "" "security" \
        bash "${SCRIPT_DIR}/scripts/run-provider-fault-matrix.sh"
      run_staging_step "RG-SMOKE-PROVIDER-FAULT-RT" "provider fault runtime writeback<=1" "" "security" \
        bash "${SCRIPT_DIR}/scripts/prove-provider-fault-writeback.sh"
      mode="health"
      for product in "${products[@]}"; do
        if product_is_skipped "${product}"; then
          append_skipped_product "${product}" "${mode}"
          continue
        fi
        run_staging_step "RG-SMOKE-${product}" "product health smoke" "${product}" "${mode}" \
          bash "${SCRIPT_DIR}/run-product-smoke.sh" --product "${product}" --mode health
      done
      ;;
    business)
      mode="business"
      for product in "${products[@]}"; do
        if product_is_skipped "${product}"; then
          append_skipped_product "${product}" "${mode}"
          continue
        fi
        run_staging_step "RG-BUSINESS-${product}" "product business smoke" "${product}" "${mode}" \
          bash "${SCRIPT_DIR}/run-product-smoke.sh" --product "${product}" --mode business
      done
      ;;
  esac

  write_staging_summary "passed"
  rm -f "$SUMMARY_TSV"
  ok "Release gate staging completed"
  echo -e "  Report: ${BOLD}${SUMMARY_JSON}${NC}"
  echo -e "  Summary: ${BOLD}${SUMMARY_MD}${NC}"
}

if [[ "${LEVEL}" != "full" ]]; then
  run_staging_level
  exit 0
fi

# C7 — Lab bypass flags must not be active for full release green
assert_no_lab_bypass_for_full() {
  local bad=0
  if [[ "${WPTSALL_SKIP_SECURITY:-}" == "1" || "${WPTSALL_SKIP_SECURITY:-}" == "true" ]]; then
    echo "ERROR: WPTSALL_SKIP_SECURITY is set — forbidden for --level full (C7)" >&2
    bad=1
  fi
  if [[ "${WPTSALL_ALLOW_INSECURE_TLS:-}" == "true" || "${WPTSALL_ALLOW_INSECURE_TLS:-}" == "1" ]]; then
    if [[ "${WPTSALL_ALLOW_LAB_BYPASS_IN_FULL:-0}" != "1" ]]; then
      echo "ERROR: WPTSALL_ALLOW_INSECURE_TLS is enabled — forbidden for --level full (C7)" >&2
      echo "  Set WPTSALL_ALLOW_LAB_BYPASS_IN_FULL=1 only for explicit Lab debug (not release)." >&2
      bad=1
    else
      echo "WARN: WPTSALL_ALLOW_LAB_BYPASS_IN_FULL=1 — insecure TLS allowed; not a release-green claim" >&2
    fi
  fi
  if [[ "${WPTSALL_ALLOW_UNSIGNED_MANIFEST:-}" == "1" || "${WPTSALL_ALLOW_UNSIGNED_MANIFEST:-}" == "true" ]]; then
    if [[ "${WPTSALL_ALLOW_LAB_BYPASS_IN_FULL:-0}" != "1" ]]; then
      echo "ERROR: WPTSALL_ALLOW_UNSIGNED_MANIFEST is set — forbidden for --level full (C7)" >&2
      bad=1
    fi
  fi
  [[ "$bad" -eq 0 ]] || exit 2
  echo "C7: lab-bypass assert ok (SKIP_SECURITY unset; insecure TLS not enabled for release)"
}
assert_no_lab_bypass_for_full

budget_args=(
  --lane release-full
  --phase pre
  --run-id "release-full-${TIMESTAMP}"
  --output "${BUDGET_PRE_JSON}"
)
if [[ "${DRY_RUN}" -eq 1 ]]; then
  budget_args+=(--dry-run --warn-only)
elif [[ "${ALLOW_MISSING_SNAPSHOT}" -eq 1 || "${WPTSALL_ALLOW_HEAVY_WITHOUT_SNAPSHOT:-0}" == "1" ]]; then
  budget_args+=(--warn-only)
else
  budget_args+=(--require-snapshot)
fi
bash "${SCRIPT_DIR}/heavy-gate-budget.sh" "${budget_args[@]}"

if [[ "${DRY_RUN}" -eq 1 ]]; then
  python3 - <<'PY' "$SUMMARY_JSON" "$SUMMARY_MD" "$LEVEL" "$CORE_PROJECT" "$CORE_SCOPE" "$MATRIX_SCOPE" "$SYNC_APPLY" "$BUDGET_PRE_JSON" "$WITH_MANUAL_ONLY" "$WITH_MANUAL_CONTENT_MATRIX"
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

json_path = Path(sys.argv[1])
md_path = Path(sys.argv[2])
level = sys.argv[3]
core_project = sys.argv[4]
core_scope = sys.argv[5]
matrix_scope = sys.argv[6]
sync_apply = sys.argv[7] == "1"
budget_pre = sys.argv[8]
with_manual_only = sys.argv[9] == "1"
with_manual_content_matrix = sys.argv[10] == "1"

# Plan order follows P0-EV-02 4.1: manual phases first, then automation.
steps = [
    {"step_id": "RG-MANUAL-PREFLIGHT", "label": "manual phases preflight (WordPress reachable, no forbidden infra)", "status": "planned"},
    {"step_id": "RG-PAGES-SURFACE", "label": "lean VS pages surface (home + shadow singular)", "status": "planned"},
]
if with_manual_only:
    steps.append({"step_id": "RG-MANUAL-ONLY", "label": "manual-only multilingual plugin gate", "status": "planned"})
if with_manual_content_matrix:
    steps.append({"step_id": "RG-MANUAL-CONTENT-MATRIX", "label": "20-plugin manual content matrix", "status": "planned"})
steps.extend([
    {"step_id": "RG-AUTOMATIC-PREFLIGHT", "label": "automation preflight (WordPress + mock API + Client)", "status": "planned"},
    {"step_id": "RG-AUTO-LANES", "label": "P0-EV-03 automatic lanes (parallel)", "status": "planned"},
    {"step_id": "RG-ISS-ACCEPTANCE", "label": "ISS security + full-flow acceptance", "status": "planned"},
    {"step_id": "RG-CORE", "label": "core business gate", "status": "planned"},
    {"step_id": "RG-CT", "label": "component template suite", "status": "planned"},
    {"step_id": "RG-MATRIX", "label": "plugin matrix", "status": "planned"},
])
payload = {
    "status": "planned",
    "dry_run": True,
    "timestamp": datetime.now(timezone.utc).isoformat(),
    "level": level,
    "config": {
        "core_project": core_project,
        "core_scope": core_scope,
        "matrix_scope": matrix_scope,
        "sync_apply": sync_apply,
        "with_manual_only": with_manual_only,
        "with_manual_content_matrix": with_manual_content_matrix,
        "heavy_budget_pre": budget_pre,
    },
    "steps": steps,
}
json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
md_path.write_text(
    "# Unified Release Gate\n\n"
    "- Status: **planned**\n"
    f"- Core gate: `{core_project} / {core_scope}`\n"
    f"- Matrix scope: `{matrix_scope}`\n"
    f"- Manual-only gate: `{str(with_manual_only).lower()}`\n"
    f"- Manual content matrix: `{str(with_manual_content_matrix).lower()}`\n"
    f"- Heavy budget: `{Path(budget_pre).name}`\n",
    encoding="utf-8",
)
print(json.dumps(payload, ensure_ascii=False))
PY
  rm -f "$SUMMARY_TSV"
  ok "Unified release gate dry-run planned successfully"
  exit 0
fi

run_step() {
  local step_id="$1"
  local label="$2"
  shift 2

  if rg_should_skip_as_prior "$step_id"; then
    record_skipped_step "$step_id" "prior to --from-step=${FROM_STEP}"
    return 0
  fi

  local started_at finished_at start_ts finish_ts duration_secs exit_code status
  local category surface message next_action
  local previous_project
  local before_e2e_json after_e2e_json before_e2e_txt after_e2e_txt
  local before_matrix_json after_matrix_json before_ct_json after_ct_json before_iss_summary after_iss_summary
  local before_manual_only_json after_manual_only_json before_manual_content_json after_manual_content_json
  local evidence_json evidence_txt evidence_matrix evidence_ct evidence_iss evidence_manual journey_flag

  before_e2e_json="$(latest_matching_file "${REPORTS_DIR}/e2e-v2-*.json")"
  before_e2e_txt="$(latest_matching_file "${REPORTS_DIR}/e2e-v2-*.txt")"
  before_matrix_json="$(latest_matching_file "${REPORTS_DIR}/e2e-project-matrix-*.json")"
  before_ct_json="$(latest_matching_file "${REPORTS_DIR}/component-template-*.json")"
  before_iss_summary="$(latest_matching_file "${REPORTS_DIR}/iss-acceptance-*/SUMMARY.json")"
  before_manual_only_json="$(latest_matching_file "${REPORTS_DIR}/manual-only-multilingual-gate-*.json")"
  before_manual_content_json="$(latest_matching_file "${REPORTS_DIR}/manual-content-plugin-matrix-*.json")"
  started_at="$(date -Iseconds)"
  start_ts="$(date +%s)"

  echo ""
  echo -e "${BOLD}${BLUE}>>> [${step_id}] ${label}${NC}"

  set +e
  "$@"
  exit_code=$?
  set -e

  finish_ts="$(date +%s)"
  finished_at="$(date -Iseconds)"
  duration_secs=$((finish_ts - start_ts))
  status="passed"
  category="none"
  surface="none"
  message=""
  next_action=""
  if [[ "$exit_code" -ne 0 ]]; then
    status="failed"
    previous_project="${E2E_PROJECT:-}"
    if [[ "${step_id}" == "RG-CORE" ]]; then
      E2E_PROJECT="${CORE_PROJECT}"
    fi
    e2e_classify_failure "release-gate:${step_id}" "${exit_code}"
    E2E_PROJECT="${previous_project}"
    category="${E2E_FAILURE_CATEGORY}"
    surface="${E2E_FAILURE_SURFACE}"
    message="${E2E_FAILURE_MESSAGE}"
    next_action="${E2E_FAILURE_NEXT_ACTION}"
    err "Release gate step failed: ${label} (exit=${exit_code}, ${duration_secs}s, category=${category})"
  else
    ok "Release gate step passed: ${label} (${duration_secs}s)"
  fi

  after_e2e_json="$(latest_matching_file "${REPORTS_DIR}/e2e-v2-*.json")"
  after_e2e_txt="$(latest_matching_file "${REPORTS_DIR}/e2e-v2-*.txt")"
  after_matrix_json="$(latest_matching_file "${REPORTS_DIR}/e2e-project-matrix-*.json")"
  after_ct_json="$(latest_matching_file "${REPORTS_DIR}/component-template-*.json")"
  after_iss_summary="$(latest_matching_file "${REPORTS_DIR}/iss-acceptance-*/SUMMARY.json")"
  after_manual_only_json="$(latest_matching_file "${REPORTS_DIR}/manual-only-multilingual-gate-*.json")"
  after_manual_content_json="$(latest_matching_file "${REPORTS_DIR}/manual-content-plugin-matrix-*.json")"

  evidence_json=""
  evidence_txt=""
  evidence_matrix=""
  evidence_ct=""
  evidence_iss=""
  evidence_manual=""
  journey_flag="false"

  if [[ -n "$after_e2e_json" && "$after_e2e_json" != "$before_e2e_json" ]]; then
    evidence_json="$after_e2e_json"
  fi
  if [[ -n "$after_e2e_txt" && "$after_e2e_txt" != "$before_e2e_txt" ]]; then
    evidence_txt="$after_e2e_txt"
  fi
  if [[ -n "$after_matrix_json" && "$after_matrix_json" != "$before_matrix_json" ]]; then
    evidence_matrix="$after_matrix_json"
  fi
  if [[ -n "$after_ct_json" && "$after_ct_json" != "$before_ct_json" ]]; then
    evidence_ct="$after_ct_json"
  fi
  if [[ -n "$after_iss_summary" && "$after_iss_summary" != "$before_iss_summary" ]]; then
    evidence_iss="$after_iss_summary"
  fi
  if [[ -n "$after_manual_only_json" && "$after_manual_only_json" != "$before_manual_only_json" ]]; then
    evidence_manual="$after_manual_only_json"
  fi
  if [[ -n "$after_manual_content_json" && "$after_manual_content_json" != "$before_manual_content_json" ]]; then
    if [[ -n "$evidence_manual" ]]; then
      evidence_manual="${evidence_manual};${after_manual_content_json}"
    else
      evidence_manual="$after_manual_content_json"
    fi
  fi
  if [[ "$label" == *"journeys"* || "$label" == *"matrix"* ]]; then
    journey_flag="true"
  fi

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$step_id" "$label" "$status" "$exit_code" "$duration_secs" "$started_at|$finished_at" \
    "$evidence_json" "$evidence_txt" "$evidence_matrix" "$evidence_ct" "$evidence_iss" "$evidence_manual" "$journey_flag" \
    "$category" "$surface" "$message" "$next_action" >> "$SUMMARY_TSV"

  if [[ "$exit_code" -ne 0 ]]; then
    python3 - <<'PY' "$SUMMARY_TSV" "$SUMMARY_JSON" "$SUMMARY_MD" "$CORE_PROJECT" "$CORE_SCOPE" "$MATRIX_SCOPE" "$SYNC_APPLY" "$WITH_MANUAL_ONLY" "$WITH_MANUAL_CONTENT_MATRIX" "$LEVEL"
import csv
import json
import sys
from pathlib import Path

tsv_path = Path(sys.argv[1])
json_path = Path(sys.argv[2])
md_path = Path(sys.argv[3])
core_project = sys.argv[4]
core_scope = sys.argv[5]
matrix_scope = sys.argv[6]
sync_apply = sys.argv[7] == "1"
with_manual_only = sys.argv[8] == "1"
with_manual_content_matrix = sys.argv[9] == "1"
level = sys.argv[10]
rows = []
with tsv_path.open() as fh:
    reader = csv.reader(fh, delimiter="\t")
    for row in reader:
        if len(row) != 17:
            continue
        step_id, label, status, exit_code, duration_secs, times, e2e_json, e2e_txt, matrix_json, ct_json, iss_summary, manual_json, journey_flag, category, surface, message, next_action = row
        started_at, finished_at = times.split("|", 1)
        rows.append({
            "step_id": step_id,
            "label": label,
            "status": status,
            "exit_code": (None if exit_code == "none" else int(exit_code)),
            "duration_secs": int(duration_secs),
            "started_at": started_at,
            "finished_at": finished_at,
            "evidence": {
                "e2e_json": e2e_json,
                "e2e_txt": e2e_txt,
                "matrix_json": matrix_json,
                "component_template_json": ct_json,
                "iss_acceptance_summary": iss_summary,
                "manual_json": manual_json,
                "journey_invoked": journey_flag == "true",
            },
            "category": None if category == "none" else category,
            "surface": None if surface == "none" else surface,
            "message": message or None,
            "next_action": next_action or None,
        })
phases = [
    {
        "name": row["step_id"],
        "status": row["status"],
        "exit_code": row["exit_code"],
        "detail": (row.get("message") or row["label"]),
        "started_at": row["started_at"],
        "finished_at": row["finished_at"],
        "duration_secs": row["duration_secs"],
    }
    for row in rows
]
payload = {
    "status": "failed",
    "dry_run": False,
    "timestamp": rows[-1]["finished_at"] if rows else None,
    "level": level,
    "config": {
        "core_project": core_project,
        "core_scope": core_scope,
        "matrix_scope": matrix_scope,
        "sync_apply": sync_apply,
        "with_manual_only": with_manual_only,
        "with_manual_content_matrix": with_manual_content_matrix,
    },
    "phases": phases,
    "steps": rows,
}
json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n")

lines = [
    "# Unified Release Gate",
    "",
    f"- Status: **failed**",
    f"- Core gate: `{core_project} / {core_scope}`",
    f"- Matrix scope: `{matrix_scope}`",
    f"- Manual-only gate: `{str(with_manual_only).lower()}`",
    f"- Manual content matrix: `{str(with_manual_content_matrix).lower()}`",
    f"- Sync apply: `{str(sync_apply).lower()}`",
    "",
    "| Step | Status | Duration(s) | Evidence |",
    "| --- | --- | ---: | --- |",
]
for row in rows:
    evidence = []
    for key in ("e2e_json", "e2e_txt", "matrix_json", "component_template_json", "iss_acceptance_summary", "manual_json"):
        value = row["evidence"][key]
        if value:
            evidence.extend(Path(item).name for item in value.split(";") if item)
    if row["evidence"]["journey_invoked"]:
        evidence.append("journey-three-system")
    lines.append(f"| {row['step_id']} {row['label']} | {row['status']} | {row['duration_secs']} | {'; '.join(evidence) or '-'} |")
md_path.write_text("\n".join(lines) + "\n")
PY
    rm -f "$SUMMARY_TSV"
    # Validate even the failure report (P0-EV-02 4.2).
    if [[ -f "${REPO_ROOT}/tests/infra/tools/report-schema-validator.py" ]]; then
      python3 "${REPO_ROOT}/tests/infra/tools/report-schema-validator.py" "${SUMMARY_JSON}" --profile generic >/dev/null 2>&1 \
        || err "failure report failed schema validation"
    fi
    exit "$exit_code"
    fi
}

# --- helpers for the P0-EV-02 ordered execution (manual phases first) -------
MANUAL_STEPS_FAILED=0

record_skipped_step() {
  local step_id="$1" reason="$2"
  local now
  now="$(date -Iseconds)"
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "${step_id}" "skipped: ${reason}" "skipped" "none" "0" "${now}|${now}" \
    "" "" "" "" "" "" "false" \
    "none" "none" "skipped: ${reason}" "" >> "$SUMMARY_TSV"
  echo -e "${YELLOW}== skipped [${step_id}] ${reason}${NC}"
}

# RG-MANUAL-PREFLIGHT (P0-EV-02 4.1): WordPress reachable + no forbidden
# client/mock/control-plane processes or listeners. No WordPress mutation and
# no watchdog install happens here (the per-gate lifecycles do that).
rg_manual_preflight_impl() {
  if ! curl -sf --max-time 8 --noproxy '*' "${WP_URL}/" >/dev/null 2>&1; then
    echo "WordPress not reachable at ${WP_URL}" >&2
    return 1
  fi
  if declare -F manual_isolation_precondition >/dev/null 2>&1; then
    manual_isolation_precondition
  else
    echo "manual-observer library missing; cannot verify manual-only preconditions" >&2
    return 1
  fi
}

# RG-AUTOMATIC-PREFLIGHT (P0-EV-02 4.1): the infrastructure the AUTOMATION
# steps need. Deliberately does NOT check the legacy website server: the
# default release flow must run with it absent.
# P0-EV-02 4.2: this orchestrator starts and OWNS the automatic infrastructure
# (mock provider + WebUI client) after the manual phases complete; PID/port
# ownership is recorded under the report dir and cleaned up on gate exit.
RG_AUTO_INFRA_DIR=""
RG_AUTO_MOCK_PID=""
RG_AUTO_CLIENT_PID=""
RG_AUTO_SERVER_PID=""

rg_stop_pid() {
  local pid="$1" name="$2"
  [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null || return 0
  kill "${pid}" 2>/dev/null || true
  for _ in $(seq 1 10); do
    kill -0 "${pid}" 2>/dev/null || return 0
    sleep 1
  done
  kill -9 "${pid}" 2>/dev/null || true
  echo "  warning: ${name} (pid ${pid}) required SIGKILL" >&2
}

rg_auto_infra_cleanup() {
  local rc=$?
  rg_stop_pid "${RG_AUTO_MOCK_PID}" "mock provider"
  rg_stop_pid "${RG_AUTO_CLIENT_PID}" "WebUI client"
  rg_stop_pid "${RG_AUTO_SERVER_PID}" "wptsall-server"
  # Sweep replacements: Stage 6 may have restarted the client with a new PID.
  local sweep_pid
  sweep_pid="$(cat "$(e2e_client_pid_file)" 2>/dev/null || true)"
  rg_stop_pid "${sweep_pid}" "WebUI client (replacement)"
  rm -f "$(e2e_client_pid_file)" 2>/dev/null || true
  if command -v fuser >/dev/null 2>&1; then
    fuser -k "${CLIENT_PORT}/tcp" 2>/dev/null || true
  fi
  if [[ -n "${RG_AUTO_INFRA_DIR}" && -d "${RG_AUTO_INFRA_DIR}" ]]; then
    rm -rf "${RG_AUTO_INFRA_DIR}"
  fi
  return "${rc}"
}

rg_wait_url() {
  local url="$1" tries="${2:-30}"
  for _ in $(seq 1 "${tries}"); do
    curl -sf --max-time 3 --noproxy '*' "${url}" >/dev/null 2>&1 && return 0
    sleep 1
  done
  return 1
}

rg_auto_infra_start() {
  RG_AUTO_INFRA_DIR="$(mktemp -d /tmp/wptsall-rg-auto-infra.XXXXXX)"
  # Mock provider (shared :9090). Build only when the debug binary is missing.
  if ! curl -sf --max-time 4 --noproxy '*' "${MOCK_API_URL}/api/v1/health" >/dev/null 2>&1; then
    local mock_bin="${REPO_ROOT}/tests/infra/mock-api/target/debug/mock-translate-api"
    if [[ ! -x "${mock_bin}" ]]; then
      echo "  building mock-translate-api (debug)..."
      (cd "${REPO_ROOT}/tests/infra/mock-api" && cargo build --bin mock-translate-api) || return 1
    fi
    MOCK_TRANSLATE_PORT="${MOCK_API_URL##*:}" nohup "${mock_bin}" \
      >"${RG_AUTO_INFRA_DIR}/mock.log" 2>&1 &
    RG_AUTO_MOCK_PID=$!
    echo "  owned mock provider pid=${RG_AUTO_MOCK_PID} port=${MOCK_API_URL##*:}"
    rg_wait_url "${MOCK_API_URL}/api/v1/health" 30 || {
      echo "owned mock provider did not become healthy" >&2
      return 1
    }
  fi
  # WebUI client (shared :8977). Build only when the debug binary is missing.
  if ! curl -sf --max-time 4 --noproxy '*' "${CLIENT_URL}/api/status" >/dev/null 2>&1; then
    local client_bin="${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
    if [[ ! -x "${client_bin}" ]]; then
      echo "  building wptsall-client (debug)..."
      (cd "${REPO_ROOT}/client-wpplugin/source" && cargo build --bin wptsall-client) || return 1
    fi
    bash -c "cd '${REPO_ROOT}/client-wpplugin/source' && exec env WPTSALL_LOG_ENABLED=true WPTSALL_LOG_FILE=${RG_AUTO_INFRA_DIR}/client-runtime.log WPTSALL_WEB_UI=1 WPTSALL_WEB_UI_BIND='127.0.0.1:${CLIENT_PORT}' '${client_bin}'" \
      >"${RG_AUTO_INFRA_DIR}/client.log" 2>&1 &
    RG_AUTO_CLIENT_PID=$!
    # Share the PID via the same pid file Stage 2/6 use (stop_this_slot_client);
    # after `exec env` the cmdline no longer contains the bind var, so the
    # pkill fallback in Stage 2 cannot match this process.
    mkdir -p "$(dirname "$(e2e_client_pid_file)")"
    echo "${RG_AUTO_CLIENT_PID}" > "$(e2e_client_pid_file)"
    echo "  owned WebUI client pid=${RG_AUTO_CLIENT_PID} port=${CLIENT_PORT}"
    rg_wait_url "${CLIENT_URL}/api/status" 30 || {
      echo "owned WebUI client did not become healthy" >&2
      return 1
    }
  fi
  # Local wptsall-server (shared :8787) — legacy website control-plane lane
  # ONLY (WPTSALL_USE_SERVER_CONTROL_PLANE=1). Per the local-first boundary
  # (AGENTS.md §0.1) the plugin and clients never need it: the only in-gate
  # consumer was the website-OAuth client suite, which has been removed.
  # Started only after the manual phases (manual isolation forbids 8787).
  if [[ "${WPTSALL_USE_SERVER_CONTROL_PLANE:-0}" != "1" ]]; then
    echo "  skipping wptsall-server (local-first: website control plane not required)"
  elif ! curl -sf --max-time 4 --noproxy '*' "${SERVER_URL}/health" >/dev/null 2>&1; then
    local server_bin="${REPO_ROOT}/web/source/server/target/release/wptsall-server"
    if [[ ! -x "${server_bin}" ]]; then
      echo "  building wptsall-server (release)..."
      (cd "${REPO_ROOT}/web/source/server" && cargo build --release --bin wptsall-server) || return 1
    fi
    mkdir -p "${RG_AUTO_INFRA_DIR}/server-data"
    bash -c "exec env WPTSALL_ENV=development WPTSALL_SEED_DATA=true WPTSALL_AUDIT_LOG_FILE='' WPTSALL_ADMIN_EMAILS='admin@wptsall.dev' WPTSALL_DATA_DIR='${RG_AUTO_INFRA_DIR}/server-data' '${server_bin}'" \
      >"${RG_AUTO_INFRA_DIR}/server.log" 2>&1 &
    RG_AUTO_SERVER_PID=$!
    echo "  owned wptsall-server pid=${RG_AUTO_SERVER_PID} port=${SERVER_URL##*:}"
    rg_wait_url "${SERVER_URL}/health" 30 || {
      echo "owned wptsall-server did not become healthy" >&2
      tail -20 "${RG_AUTO_INFRA_DIR}/server.log" >&2 || true
      return 1
    }
  fi
  # Ownership record for the report trail (P0-EV-02 4.2).
  mkdir -p "${REPORTS_DIR}"
  python3 - "${REPORTS_DIR}" "${RG_AUTO_MOCK_PID}" "${RG_AUTO_CLIENT_PID}" "${RG_AUTO_SERVER_PID}" \
    "${MOCK_API_URL}" "${CLIENT_URL}" "${SERVER_URL}" <<'PY'
import json, pathlib, sys
out = pathlib.Path(sys.argv[1]) / "owned-automatic-infrastructure.json"
out.write_text(json.dumps({
    "mock_pid": sys.argv[2] or None,
    "client_pid": sys.argv[3] or None,
    "server_pid": sys.argv[4] or None,
    "mock_url": sys.argv[5],
    "client_url": sys.argv[6],
    "server_url": sys.argv[7],
    "ownership": "release-gate (started after manual phases; cleaned up on exit)",
}, indent=2) + "\n", encoding="utf-8")
PY
  return 0
}

rg_automation_preflight_impl() {
  if ! curl -sf --max-time 8 --noproxy '*' "${WP_URL}/" >/dev/null 2>&1; then
    echo "WordPress not reachable at ${WP_URL}" >&2
    return 1
  fi
  if ! curl -sf --max-time 8 --noproxy '*' "${MOCK_API_URL}/api/v1/health" >/dev/null 2>&1; then
    echo "mock API not reachable at ${MOCK_API_URL}/api/v1/health" >&2
    return 1
  fi
  if ! curl -sf --max-time 8 --noproxy '*' "${CLIENT_URL}/api/status" >/dev/null 2>&1; then
    echo "Client not reachable at ${CLIENT_URL}/api/status" >&2
    return 1
  fi
  # Website control plane (8787) is only required by the legacy lane
  # (P0-EV-06): the default local-first gate neither starts nor needs it.
  if [[ "${WPTSALL_USE_SERVER_CONTROL_PLANE:-0}" == "1" ]]; then
    if ! curl -sf --max-time 8 --noproxy '*' "${SERVER_URL}/health" >/dev/null 2>&1; then
      echo "wptsall-server not reachable at ${SERVER_URL}/health" >&2
      return 1
    fi
  fi
}

# ---- 1. RG-MANUAL-PREFLIGHT -------------------------------------------------
if rg_should_skip_as_prior "RG-MANUAL-PREFLIGHT"; then
  record_skipped_step "RG-MANUAL-PREFLIGHT" "prior to --from-step=${FROM_STEP}"
else
  run_step "RG-MANUAL-PREFLIGHT" "manual phases preflight (WordPress reachable, no forbidden infra)" \
    rg_manual_preflight_impl
fi

# ---- 1b. RG-PAGES-SURFACE (lean VS HTTP surface; REQUIRED) -----------------
if rg_should_skip_as_prior "RG-PAGES-SURFACE"; then
  record_skipped_step "RG-PAGES-SURFACE" "prior to --from-step=${FROM_STEP}"
else
  run_step "RG-PAGES-SURFACE" "lean VS pages surface (home + shadow singular)" \
    bash "${SCRIPT_DIR}/run-vs-pages-surface-gate.sh" \
    || MANUAL_STEPS_FAILED=1
fi

# ---- 2. RG-MANUAL-ONLY -------------------------------------------------------
if rg_should_skip_as_prior "RG-MANUAL-ONLY"; then
  record_skipped_step "RG-MANUAL-ONLY" "prior to --from-step=${FROM_STEP}"
elif [[ "$WITH_MANUAL_ONLY" -eq 1 ]]; then
  run_step "RG-MANUAL-ONLY" "manual-only multilingual plugin gate" bash "${SCRIPT_DIR}/run-manual-only-multilingual-gate.sh" \
    || MANUAL_STEPS_FAILED=1
else
  record_skipped_step "RG-MANUAL-ONLY" "disabled by --skip-manual-only"
fi

# ---- 3. RG-MANUAL-CONTENT-MATRIX (default on for --level full) --------------
if rg_should_skip_as_prior "RG-MANUAL-CONTENT-MATRIX"; then
  record_skipped_step "RG-MANUAL-CONTENT-MATRIX" "prior to --from-step=${FROM_STEP}"
elif [[ "$WITH_MANUAL_CONTENT_MATRIX" -eq 1 ]]; then
  run_step "RG-MANUAL-CONTENT-MATRIX" "20-plugin manual content matrix" bash "${SCRIPT_DIR}/run-manual-content-plugin-matrix.sh" \
    || MANUAL_STEPS_FAILED=1
else
  if [[ "${LEVEL}" == "full" ]]; then
    record_skipped_step "RG-MANUAL-CONTENT-MATRIX" "disabled by --skip-manual-content-matrix (report cannot be passed for level=full)"
  else
    record_skipped_step "RG-MANUAL-CONTENT-MATRIX" "not enabled for level=${LEVEL} (use --with-manual-content-matrix)"
  fi
fi

# ---- 3b. RG-OFFICIAL-MANUAL-P0-P4 (fixtures + identity gate + priority seams) -
WITH_OFFICIAL_MANUAL_P0_P4="${WITH_OFFICIAL_MANUAL_P0_P4:-}"
if [[ -z "${WITH_OFFICIAL_MANUAL_P0_P4}" ]]; then
  if [[ "${LEVEL}" == "full" || "${LEVEL}" == "business" ]]; then
    WITH_OFFICIAL_MANUAL_P0_P4=1
  else
    WITH_OFFICIAL_MANUAL_P0_P4=0
  fi
fi
case "${WITH_OFFICIAL_MANUAL_P0_P4}" in
  1|true|TRUE|yes|YES) WITH_OFFICIAL_MANUAL_P0_P4=1 ;;
  *) WITH_OFFICIAL_MANUAL_P0_P4=0 ;;
esac
if rg_should_skip_as_prior "RG-OFFICIAL-MANUAL-P0-P4"; then
  record_skipped_step "RG-OFFICIAL-MANUAL-P0-P4" "prior to --from-step=${FROM_STEP}"
elif [[ "${WITH_OFFICIAL_MANUAL_P0_P4}" -eq 1 && "${MANUAL_STEPS_FAILED}" -eq 0 ]]; then
  run_step "RG-OFFICIAL-MANUAL-P0-P4" "official fixtures + identity gate + priority manual seams" \
    env WPTSALL_LAB=1 MANUAL_ISOLATION_SKIP=1 \
    bash "${SCRIPT_DIR}/run-official-manual-p0-p4.sh" \
      --projects woocommerce-content,learnpress-content,give-content \
      --skip-playwright-matrix \
    || MANUAL_STEPS_FAILED=1
elif [[ "${WITH_OFFICIAL_MANUAL_P0_P4}" -eq 0 ]]; then
  record_skipped_step "RG-OFFICIAL-MANUAL-P0-P4" "not enabled for level=${LEVEL}"
else
  record_skipped_step "RG-OFFICIAL-MANUAL-P0-P4" "prior manual steps failed"
fi

# Manual steps failed -> the automation steps must not start (fail-fast already
# stops at the failed step; this block covers --no-fail-fast style flows and
# makes the skipped remainder explicit in the report).
if [[ "${MANUAL_STEPS_FAILED}" -eq 1 ]]; then
  err "manual steps failed; marking all automation steps as skipped (manual-first rule)"
  record_skipped_step "RG-AUTOMATIC-PREFLIGHT" "manual steps failed"
  record_skipped_step "RG-AUTO-LANES" "manual steps failed"
  record_skipped_step "RG-ISS-ACCEPTANCE" "manual steps failed"
  record_skipped_step "RG-CORE" "manual steps failed"
  record_skipped_step "RG-CT" "manual steps failed"
  record_skipped_step "RG-MATRIX" "manual steps failed"
  python3 - "$SUMMARY_TSV" "$SUMMARY_JSON" "$LEVEL" <<'PY'
import csv, json, sys
from pathlib import Path
rows = []
with Path(sys.argv[1]).open() as fh:
    for row in csv.reader(fh, delimiter="\t"):
        if len(row) != 17:
            continue
        rows.append(row)
payload = {
    "status": "failed",
    "dry_run": False,
    "level": sys.argv[3],
    "phases": [
        {
            "name": r[0],
            "status": r[2],
            "exit_code": None if r[3] == "none" else int(r[3]),
            "detail": r[16] or r[1],
            "reason": r[16] or None,
        }
        for r in rows
    ],
    "steps": [{"step_id": r[0], "status": r[2]} for r in rows],
}
Path(sys.argv[2]).write_text(json.dumps(payload, indent=2) + "\n")
print("STATUS=failed (manual steps failed; automation skipped)")
PY
  rm -f "$SUMMARY_TSV"
  exit 1
fi

# ---- 4. RG-AUTOMATIC-PREFLIGHT ----------------------------------------------
trap rg_auto_infra_cleanup EXIT
rg_auto_preflight_full() {
  rg_auto_infra_start || return 1
  rg_automation_preflight_impl
}
run_step "RG-AUTOMATIC-PREFLIGHT" "automation preflight (WordPress + mock API + Client)" \
  rg_auto_preflight_full

# ---- 5. RG-AUTO-* focused automatic lanes (P0-EV-03) ------------------------
# Direct-WP only, owned infrastructure, control-plane canary must stay zero.
# P3 parallelization: each lane owns isolated infrastructure (kernel-assigned
# ports, own client/mock/canary), so the three lanes run concurrently and the
# wall time drops to roughly the slowest lane. Per-lane logs are replayed
# into the gate log by scripts/run-auto-lanes-parallel.sh; it exits non-zero
# if any lane fails, preserving the previous gate-failure semantics.
run_step "RG-AUTO-LANES" "P0-EV-03 automatic lanes (content surfaces + consistency + topology/time, parallel)" \
  bash "${SCRIPT_DIR}/scripts/run-auto-lanes-parallel.sh"

# ---- 6. RG-ISS-ACCEPTANCE ---------------------------------------------------
# This one hard gate contains the security acceptance (S1-S7), SEO, provider
# faults, T1/T2 isolation/cleanup and all eight ISS scenarios. It remains
# mandatory even when an operator explicitly skips a business sub-lane.
run_step "RG-ISS-ACCEPTANCE" "ISS security + full-flow acceptance" bash "${SCRIPT_DIR}/scripts/run-iss-acceptance.sh"

# ---- 7. RG-CORE ----

if rg_should_skip_as_prior "RG-CORE"; then
  record_skipped_step "RG-CORE" "prior to --from-step=${FROM_STEP}"
elif [[ "$WITH_CORE_GATE" -eq 1 ]]; then
  if [[ "$HEADED" -eq 1 ]]; then
    run_step "RG-CORE" "core business gate" env E2E_PROJECT="$CORE_PROJECT" E2E_SCOPE="$CORE_SCOPE" E2E_AUTO_ACTIVATE_PLUGINS=1 \
      bash "${SCRIPT_DIR}/run.sh" --headed
  else
    run_step "RG-CORE" "core business gate" env E2E_PROJECT="$CORE_PROJECT" E2E_SCOPE="$CORE_SCOPE" E2E_AUTO_ACTIVATE_PLUGINS=1 \
      bash "${SCRIPT_DIR}/run.sh"
  fi
else
  record_skipped_step "RG-CORE" "disabled by --skip-core-gate (report cannot be passed)"
fi

if rg_should_skip_as_prior "RG-CT"; then
  record_skipped_step "RG-CT" "prior to --from-step=${FROM_STEP}"
elif [[ "$WITH_COMPONENT_TEMPLATES" -eq 1 ]]; then
  # Full release: do not default to insecure TLS (C7). Lab may opt in explicitly.
  CT_ALLOW_INSECURE_TLS="${WPTSALL_ALLOW_INSECURE_TLS:-false}"
  CT_ALLOW_EMPTY_BATCH="${WPTSALL_ALLOW_EMPTY_USER_LOCAL_MOCK_BATCH:-false}"
  ct_env=(
    env
    "WPTSALL_ALLOW_INSECURE_TLS=${CT_ALLOW_INSECURE_TLS}"
    "WPTSALL_ALLOW_EMPTY_USER_LOCAL_MOCK_BATCH=${CT_ALLOW_EMPTY_BATCH}"
  )
  if [[ "$SYNC_APPLY" -eq 1 && "$HEADED" -eq 1 ]]; then
    run_step "RG-CT" "component template suite" "${ct_env[@]}" bash "${SCRIPT_DIR}/run-component-template-suite.sh" --sync-apply --headed
  elif [[ "$SYNC_APPLY" -eq 1 ]]; then
    run_step "RG-CT" "component template suite" "${ct_env[@]}" bash "${SCRIPT_DIR}/run-component-template-suite.sh" --sync-apply
  elif [[ "$HEADED" -eq 1 ]]; then
    run_step "RG-CT" "component template suite" "${ct_env[@]}" bash "${SCRIPT_DIR}/run-component-template-suite.sh" --headed
  else
    run_step "RG-CT" "component template suite" "${ct_env[@]}" bash "${SCRIPT_DIR}/run-component-template-suite.sh"
  fi
else
  record_skipped_step "RG-CT" "disabled by --skip-component-templates (report cannot be passed)"
fi

if rg_should_skip_as_prior "RG-MATRIX"; then
  record_skipped_step "RG-MATRIX" "prior to --from-step=${FROM_STEP}"
elif [[ "$WITH_PLUGIN_MATRIX" -eq 1 ]]; then
  # Local-first boundary (P0-EV-06): the matrix runs plugin lanes only. The
  # legacy journey-three-system lane (website public-auth journeys:
  # onboarding / password recovery / website template sync) tests website
  # functionality and requires a live :8787 control plane, so the release
  # gate no longer appends it. It remains runnable explicitly via
  # run-playwright-journey-three-system.sh for the legacy lane.
  matrix_args=(--scope "$MATRIX_SCOPE")
  if [[ "$ALLOW_MISSING_SNAPSHOT" -eq 1 ]]; then
    if [[ "$LEVEL" == "full" ]]; then
      echo "ERROR: --allow-missing-snapshot is forbidden for --level full (release evidence incomplete)" >&2
      exit 2
    fi
    matrix_args+=(--allow-missing-snapshot)
  fi
  if [[ "$HEADED" -eq 1 ]]; then
    run_step "RG-MATRIX" "plugin matrix" bash "${SCRIPT_DIR}/run-project-matrix.sh" "${matrix_args[@]}" -- --headed
  else
    run_step "RG-MATRIX" "plugin matrix" bash "${SCRIPT_DIR}/run-project-matrix.sh" "${matrix_args[@]}"
  fi
else
  record_skipped_step "RG-MATRIX" "disabled by --skip-plugin-matrix (report cannot be passed)"
fi

bash "${SCRIPT_DIR}/heavy-gate-budget.sh" \
  --lane release-full \
  --phase post \
  --run-id "release-full-${TIMESTAMP}" \
  --output "${BUDGET_POST_JSON}" \
  --before "${BUDGET_PRE_JSON}" \
  --warn-only

python3 - <<'PY' "$SUMMARY_TSV" "$SUMMARY_JSON" "$SUMMARY_MD" "$CORE_PROJECT" "$CORE_SCOPE" "$MATRIX_SCOPE" "$SYNC_APPLY" "$WITH_MANUAL_ONLY" "$WITH_MANUAL_CONTENT_MATRIX" "$LEVEL" "${FROM_STEP}"
import csv
import json
import sys
from pathlib import Path

tsv_path = Path(sys.argv[1])
json_path = Path(sys.argv[2])
md_path = Path(sys.argv[3])
core_project = sys.argv[4]
core_scope = sys.argv[5]
matrix_scope = sys.argv[6]
sync_apply = sys.argv[7] == "1"
with_manual_only = sys.argv[8] == "1"
with_manual_content_matrix = sys.argv[9] == "1"
level = sys.argv[10]
from_step = sys.argv[11] if len(sys.argv) > 11 else ""
rows = []
with tsv_path.open() as fh:
    reader = csv.reader(fh, delimiter="\t")
    for row in reader:
        if len(row) != 17:
            continue
        step_id, label, status, exit_code, duration_secs, times, e2e_json, e2e_txt, matrix_json, ct_json, iss_summary, manual_json, journey_flag, category, surface, message, next_action = row
        started_at, finished_at = times.split("|", 1)
        rows.append({
            "step_id": step_id,
            "label": label,
            "status": status,
            "exit_code": (None if exit_code == "none" else int(exit_code)),
            "duration_secs": int(duration_secs),
            "started_at": started_at,
            "finished_at": finished_at,
            "evidence": {
                "e2e_json": e2e_json,
                "e2e_txt": e2e_txt,
                "matrix_json": matrix_json,
                "component_template_json": ct_json,
                "iss_acceptance_summary": iss_summary,
                "manual_json": manual_json,
                "journey_invoked": journey_flag == "true",
            },
            "category": None if category == "none" else category,
            "surface": None if surface == "none" else surface,
            "message": message or None,
            "next_action": next_action or None,
        })

# P0-EV-02 4.3: status semantics — failed > incomplete (skipped REQUIRED
# step) > passed. Skipped REQUIRED evidence can never yield "passed".
# Exception: skips recorded as "prior to --from-step=…" are resume priors
# and do not force incomplete.
REQUIRED_STEPS = {
    "RG-MANUAL-PREFLIGHT",
    "RG-PAGES-SURFACE",
    "RG-MANUAL-ONLY",
    "RG-MANUAL-CONTENT-MATRIX",
    "RG-AUTOMATIC-PREFLIGHT",
    # P0-S1: the three P0-EV-03 focused automatic lanes (content surfaces,
    # consistency, topology/time) are now a hard gate. They passed as real
    # non-dry-run evidence on 2026-09-02 against the live WP lab, so skipping
    # them can no longer yield a "passed" release summary.
    "RG-AUTO-LANES",
    "RG-ISS-ACCEPTANCE",
    "RG-CORE",
    "RG-CT",
    "RG-MATRIX",
}
# Official P0–P4 is required for full/business only (smoke stays lean).
if level in ("full", "business"):
    REQUIRED_STEPS.add("RG-OFFICIAL-MANUAL-P0-P4")

def is_from_step_prior(row):
    blob = " ".join(
        str(x or "")
        for x in (row.get("message"), row.get("label"), row.get("next_action"))
    )
    return "prior to --from-step=" in blob

phases = []
for row in rows:
    phases.append({
        "name": row["step_id"],
        "status": row["status"],
        "exit_code": row["exit_code"],
        "label": row["label"],
        "detail": (row.get("message") or row["label"]),
        "required": row["step_id"] in REQUIRED_STEPS,
        "started_at": row["started_at"],
        "finished_at": row["finished_at"],
        "duration_secs": row["duration_secs"],
    })

statuses = [p["status"] for p in phases]
if any(s == "failed" for s in statuses):
    status = "failed"
elif any(
    p["status"] == "skipped"
    and p["required"]
    and not is_from_step_prior(rows[i])
    for i, p in enumerate(phases)
):
    status = "incomplete"
elif phases and all(
    s == "passed" or (s == "skipped" and is_from_step_prior(rows[i]))
    for i, s in enumerate(statuses)
):
    status = "passed"
elif phases and all(s == "passed" for s in statuses):
    status = "passed"
else:
    status = "incomplete"

evidence_paths = []
for row in rows:
    for key in ("e2e_json", "e2e_txt", "matrix_json", "component_template_json", "iss_acceptance_summary", "manual_json"):
        value = row["evidence"][key]
        if value:
            for item in value.split(";"):
                if item and Path(item).exists():
                    evidence_paths.append(item)
if md_path.exists():
    evidence_paths.append(str(md_path))

payload = {
    "status": status,
    "dry_run": False,
    "timestamp": rows[-1]["finished_at"] if rows else None,
    "level": level,
    "config": {
        "core_project": core_project,
        "core_scope": core_scope,
        "matrix_scope": matrix_scope,
        "sync_apply": sync_apply,
        "with_manual_only": with_manual_only,
        "with_manual_content_matrix": with_manual_content_matrix,
        "from_step": from_step or None,
    },
    "phases": phases,
    "steps": rows,
    "evidence": evidence_paths,
}
json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n")

lines = [
    "# Unified Release Gate",
    "",
    f"- Status: **{status}**",
    f"- Core gate: `{core_project} / {core_scope}`",
    f"- Matrix scope: `{matrix_scope}`",
    f"- Manual-only gate: `{str(with_manual_only).lower()}`",
    f"- Manual content matrix: `{str(with_manual_content_matrix).lower()}`",
    f"- Sync apply: `{str(sync_apply).lower()}`",
    "",
    "| Step | Status | Duration(s) | Evidence |",
    "| --- | --- | ---: | --- |",
]
for row in rows:
    evidence = []
    for key in ("e2e_json", "e2e_txt", "matrix_json", "component_template_json", "iss_acceptance_summary", "manual_json"):
        value = row["evidence"][key]
        if value:
            evidence.extend(Path(item).name for item in value.split(";") if item)
    if row["evidence"]["journey_invoked"]:
        evidence.append("journey-three-system")
    lines.append(f"| {row['step_id']} {row['label']} | {row['status']} | {row['duration_secs']} | {'; '.join(evidence) or '-'} |")
md_path.write_text("\n".join(lines) + "\n")
PY

rm -f "$SUMMARY_TSV"

# P0-EV-02 4.2: validate the report against the P0-TF-01 schema contract.
# The release profile expects the three P0-EV-03 automation lanes too; until
# they exist we validate with the generic profile and say so.
VALIDATOR="${REPO_ROOT}/tests/infra/tools/report-schema-validator.py"
if [[ -f "${VALIDATOR}" ]]; then
  if grep -q "RG-AUTO-LANES" "${SUMMARY_JSON}"; then
    PROFILE="release"
  else
    PROFILE="generic"
    echo "note: P0-EV-03 automation lanes not present; validating with profile=${PROFILE}" >&2
  fi
  if python3 "${VALIDATOR}" "${SUMMARY_JSON}" --profile "${PROFILE}" >/dev/null 2>&1; then
    ok "release report passed schema validation (profile: ${PROFILE})"
  else
    err "release report FAILED schema validation (profile: ${PROFILE})"
    python3 "${VALIDATOR}" "${SUMMARY_JSON}" --profile "${PROFILE}" || true
    exit 1
  fi
fi

FINAL_STATUS="$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['status'])" "${SUMMARY_JSON}")"
if [[ "${FINAL_STATUS}" != "passed" ]]; then
  err "Release gate ${FINAL_STATUS} — see ${SUMMARY_JSON}"
  exit 1
fi
ok "Unified release gate completed"
echo -e "  Report: ${BOLD}${SUMMARY_JSON}${NC}"
echo -e "  Summary: ${BOLD}${SUMMARY_MD}${NC}"
