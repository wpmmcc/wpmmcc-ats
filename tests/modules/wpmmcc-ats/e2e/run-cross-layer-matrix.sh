#!/usr/bin/env bash
# T-XL: bounded cross-layer matrix — 5 content plugins × 3 provider families = 15.
# Each cell runs run-automatic-plugin-slice.sh with E2E_PROVIDER_FAMILY set.
#
# Optimization: ensure each plugin once per slot, then run all 3 families on
# that same slot (avoids 15× full e2e run.sh provision).
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-cross-layer-matrix.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-cross-layer-matrix.sh --dry-run
#   WPTSALL_LAB=1 E2E_XL_LIMIT=3 bash ...   # smoke first N cells
set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
# 批 L (U 系卫生): canonical reports tree (was root-level reports/cross-layer).
REPORTS_DIR="${ROOT_DIR}/tests/reports/e2e/wpmmcc-ats/cross-layer"
mkdir -p "${REPORTS_DIR}"

DRY_RUN=0
LIMIT="${E2E_XL_LIMIT:-0}"
SKIP_ENSURE="${E2E_XL_SKIP_ENSURE:-0}"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --skip-ensure) SKIP_ENSURE=1 ;;
    --limit=*) LIMIT="${1#--limit=}" ;;
    -h|--help)
      echo "Usage: $0 [--dry-run] [--skip-ensure] [--limit=N]"
      exit 0
      ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
  shift
done

# One slot per plugin so ensure runs once, then 3 family slices reuse it.
PLUGINS=(
  "woocommerce-content|slot-a"
  "elementor-content|slot-b"
  "wordpress-seo-content|slot-c"
  "advanced-custom-fields-content|slot-d"
  "wptsall-content|slot-e"
)
FAMILIES=(
  openai_compatible
  http_mt_bearer
  http_mt_signed
)

CELLS=()
for row in "${PLUGINS[@]}"; do
  IFS='|' read -r plugin slot <<<"${row}"
  for fam in "${FAMILIES[@]}"; do
    CELLS+=("${plugin}|${fam}|${slot}")
  done
done

if [[ "${LIMIT}" =~ ^[0-9]+$ && "${LIMIT}" -gt 0 && "${LIMIT}" -lt ${#CELLS[@]} ]]; then
  CELLS=("${CELLS[@]:0:${LIMIT}}")
fi

TS="$(date -u +%Y%m%dT%H%M%SZ)"
SUMMARY="${REPORTS_DIR}/summary-${TS}.json"
echo "T-XL cells: ${#CELLS[@]} (dry_run=${DRY_RUN} skip_ensure=${SKIP_ENSURE})"

RESULTS_JSON='[]'
PASS=0
FAIL=0
declare -A ENSURED=()
i=0
for cell in "${CELLS[@]}"; do
  i=$((i + 1))
  IFS='|' read -r plugin fam slot <<<"${cell}"
  echo "== [${i}/${#CELLS[@]}] ${plugin} × ${fam} @ ${slot} =="
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    RESULTS_JSON="$(jq -c --arg p "$plugin" --arg f "$fam" --arg s "$slot" \
      '. + [{project:$p, family:$f, slot:$s, status:"planned", dry_run:true}]' <<<"${RESULTS_JSON}")"
    PASS=$((PASS + 1))
    continue
  fi

  cell_log="${REPORTS_DIR}/${plugin}-${fam}-${TS}.log"
  cell_rc=0
  : >"${cell_log}"

  ensure_key="${plugin}@${slot}"
  if [[ "${SKIP_ENSURE}" -eq 0 && -z "${ENSURED[${ensure_key}]:-}" ]]; then
    echo "   ensure ${plugin} on ${slot}..." | tee -a "${cell_log}"
    env -u WP_URL -u WP_BASE -u LAB_WP_CONTAINER -u CLIENT_BASE \
      WPTSALL_LAB=1 E2E_SLOT="${slot}" E2E_SLOT_WP_ISOLATED=1 \
      bash "${ROOT_DIR}/tests/scripts/ensure-plugin-project.sh" "${plugin}" >>"${cell_log}" 2>&1 || cell_rc=$?
    if [[ "${cell_rc}" -eq 0 ]]; then
      ENSURED["${ensure_key}"]=1
    fi
  else
    echo "   skip ensure (${ensure_key} already provisioned or --skip-ensure)" | tee -a "${cell_log}"
  fi

  if [[ "${cell_rc}" -eq 0 ]]; then
    env -u WP_URL -u WP_BASE -u LAB_WP_CONTAINER -u CLIENT_BASE \
      WPTSALL_LAB=1 E2E_SLOT="${slot}" E2E_SLOT_WP_ISOLATED=1 \
      E2E_PROVIDER_FAMILY="${fam}" \
      bash "${SCRIPT_DIR}/run-automatic-plugin-slice.sh" "${plugin}" >>"${cell_log}" 2>&1 || cell_rc=$?
  fi

  status="failed"
  evidence=""
  if [[ "${cell_rc}" -eq 0 ]]; then
    evidence="$(ls -t "${ROOT_DIR}/tests/reports/e2e/wpmmcc-ats/auto-slice/${plugin}-"*.json 2>/dev/null | head -1 || true)"
    if [[ -n "${evidence}" ]] && jq -e '.status == "passed"' "${evidence}" >/dev/null 2>&1; then
      # Require this cell's family assertion when present in the newest report.
      if jq -e --arg f "$fam" '
          (.assertions // [])
          | map(select(.name == "provider_family"))
          | length == 0
            or any(.detail == $f or .message == $f or (.detail | tostring | contains($f)))
        ' "${evidence}" >/dev/null 2>&1 \
        || jq -e --arg f "$fam" '(.provider_family // .family // "") == $f' "${evidence}" >/dev/null 2>&1; then
        status="passed"
        PASS=$((PASS + 1))
      else
        # Family may only be in lane report; still accept slice passed + our log marker.
        if grep -q "provider_family: pass" "${cell_log}" 2>/dev/null \
          || grep -q "family=${fam}" "${cell_log}" 2>/dev/null \
          || grep -q "assertion provider_family: pass" "${cell_log}" 2>/dev/null; then
          status="passed"
          PASS=$((PASS + 1))
        else
          FAIL=$((FAIL + 1))
        fi
      fi
    else
      FAIL=$((FAIL + 1))
    fi
  else
    FAIL=$((FAIL + 1))
  fi

  RESULTS_JSON="$(jq -c --arg p "$plugin" --arg f "$fam" --arg s "$slot" \
    --arg st "$status" --arg ev "$evidence" --arg lg "$cell_log" --argjson rc "$cell_rc" \
    '. + [{project:$p, family:$f, slot:$s, status:$st, exit_code:$rc, evidence:$ev, log:$lg, dry_run:false}]' \
    <<<"${RESULTS_JSON}")"
  echo "   -> ${status} (rc=${cell_rc})"
done

jq -n \
  --arg ts "$TS" \
  --argjson results "${RESULTS_JSON}" \
  --argjson pass "$PASS" \
  --argjson fail "$FAIL" \
  --argjson total "${#CELLS[@]}" \
  --argjson dry "$DRY_RUN" \
  '{
    task: "T-XL",
    timestamp: $ts,
    dry_run: ($dry == 1),
    passed: $pass,
    failed: $fail,
    total: $total,
    status: (if $dry == 1 then "planned" elif $fail == 0 and $pass == $total then "passed" else "failed" end),
    cells: $results
  }' >"${SUMMARY}"

echo "summary: ${SUMMARY}"
jq '{status, passed, failed, total, dry_run}' "${SUMMARY}"
status="$(jq -r .status "${SUMMARY}")"
[[ "${status}" == "passed" || "${status}" == "planned" ]]
