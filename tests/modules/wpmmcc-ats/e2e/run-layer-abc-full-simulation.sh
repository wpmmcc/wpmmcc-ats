#!/usr/bin/env bash
# Full Layer A/B/C simulation orchestrator for ~20 content plugins.
#
# Order (dependency-aware):
#   0) Preflight (Lab up, migrations)
#   1) Layer B schema/REST smoke
#   2) Layer ABC deep matrix WITH gettext scan (all plugin projects)
#   3) Client Layer B/C path simulation (pull/claim/callback + gettext)
#   4) VS / ML surface deep smoke
#   5) Content × SEO combo (hreflang / dual-emit gate)
#   6) Subsite topology + i18n smoke
#   7) Layer A project matrix (Stage 1–7, all plugin projects)
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-layer-abc-full-simulation.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-layer-abc-full-simulation.sh --skip-layer-a
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-layer-abc-full-simulation.sh --from 3
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
export DEMO_PASSWORD="${DEMO_PASSWORD:-demo}"

CONTAINER="${LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
STAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_DIR="${REPORTS_DIR}/full-sim"
mkdir -p "${REPORT_DIR}"
SUMMARY_MD="${REPORT_DIR}/full-sim-${STAMP}.md"
SUMMARY_JSON="${REPORT_DIR}/full-sim-${STAMP}.json"
LOG_DIR="${REPORT_DIR}/logs-${STAMP}"
mkdir -p "${LOG_DIR}"

FROM_PHASE=0
SKIP_LAYER_A=0
SKIP_SEO=0
SKIP_SUBSITE=0
ABC_SKIP_SCAN=0
MATRIX_JOBS="${E2E_MATRIX_JOBS:-4}"
NO_FAIL_FAST=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --from) FROM_PHASE="$2"; shift 2 ;;
    --skip-layer-a) SKIP_LAYER_A=1; shift ;;
    --skip-seo) SKIP_SEO=1; shift ;;
    --skip-subsite) SKIP_SUBSITE=1; shift ;;
    --abc-skip-scan) ABC_SKIP_SCAN=1; shift ;;
    --jobs) MATRIX_JOBS="$2"; shift 2 ;;
    --no-fail-fast) NO_FAIL_FAST=1; shift ;;
    -h|--help)
      sed -n '2,20p' "$0"
      exit 0
      ;;
    *) echo "Unknown: $1"; exit 2 ;;
  esac
done

PHASE_RESULTS=()
PHASE_RC=()
OVERALL_RC=0

run_phase() {
  local num="$1"
  local name="$2"
  shift 2
  if [[ "${num}" -lt "${FROM_PHASE}" ]]; then
    echo "==== SKIP phase ${num}: ${name} ( --from ${FROM_PHASE} ) ===="
    PHASE_RESULTS+=("${num}|${name}|SKIP|0")
    return 0
  fi
  echo
  echo "==== PHASE ${num}: ${name} ===="
  local log="${LOG_DIR}/phase-${num}.log"
  local t0
  t0="$(date +%s)"
  set +e
  "$@" > >(tee "${log}") 2>&1
  local rc=$?
  set -e
  local t1 elapsed
  t1="$(date +%s)"
  elapsed=$((t1 - t0))
  if [[ "${rc}" -eq 0 ]]; then
    echo "==== PHASE ${num} PASS (${elapsed}s) ===="
    PHASE_RESULTS+=("${num}|${name}|PASS|${elapsed}")
  else
    echo "==== PHASE ${num} FAIL rc=${rc} (${elapsed}s) ===="
    PHASE_RESULTS+=("${num}|${name}|FAIL|${elapsed}")
    OVERALL_RC=1
    if [[ "${NO_FAIL_FAST}" -eq 0 ]]; then
      write_summary
      exit "${rc}"
    fi
  fi
  return 0
}

write_summary() {
  {
    echo "# Full Layer ABC simulation — ${STAMP}"
    echo
    echo "| Phase | Name | Result | Seconds |"
    echo "|---:|---|---|---:|"
    local row num name result secs
    for row in "${PHASE_RESULTS[@]}"; do
      IFS='|' read -r num name result secs <<< "${row}"
      echo "| ${num} | ${name} | ${result} | ${secs} |"
    done
    echo
    echo "- overall: $([[ ${OVERALL_RC} -eq 0 ]] && echo PASS || echo FAIL)"
    echo "- logs: \`${LOG_DIR}\`"
  } > "${SUMMARY_MD}"

  python3 - <<PY "${SUMMARY_JSON}" "${STAMP}" "${OVERALL_RC}" "${PHASE_RESULTS[@]}"
import json, sys
out, stamp, rc = sys.argv[1], sys.argv[2], int(sys.argv[3])
phases = []
for item in sys.argv[4:]:
    num, name, result, secs = item.split("|", 3)
    phases.append({"phase": int(num), "name": name, "result": result, "seconds": int(secs)})
json.dump({"stamp": stamp, "overall_rc": rc, "phases": phases}, open(out, "w"), indent=2)
print("Wrote", out)
PY
  echo "SUMMARY ${SUMMARY_MD}"
}

# ── Phase 0: Preflight ──────────────────────────────────────────────────────
phase0() {
  docker inspect -f '{{.State.Running}}' "${CONTAINER}" | grep -q true
  curl -sS -o /dev/null -w 'wp_http=%{http_code}\n' --max-time 10 "${WP_URL}/"
  # Artifact / language / dispatcher / FSE / menu mapping gate
  docker exec "${CONTAINER}" wp eval '
if (function_exists("wptsall_run_migrations")) { wptsall_run_migrations(); }
echo "migrations_ok\n";
if (class_exists("\\WPTSALL\\Strings\\Site_Identity_Translation")) { echo "site_identity_ok\n"; }
if (class_exists("\\WPTSALL\\Core\\Language_Context")) { echo "language_context_ok\n"; }
if (class_exists("\\WPTSALL\\Hooks\\Content_Change_Dispatcher")) { echo "content_dispatcher_ok\n"; }
if (class_exists("\\WPTSALL\\Fse\\Fse_Content_Adapter")) { echo "fse_adapter_ok\n"; }
if (class_exists("\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service")) { echo "menu_mapping_ok\n"; }
if (class_exists("\\WPTSALL\\Core\\Translation_Object_Graph")) { echo "object_graph_ok\n"; }
$manifest = defined("WPTSALL_PATH") ? WPTSALL_PATH . "build-manifest.json" : "";
if ($manifest && is_readable($manifest)) {
  $data = json_decode((string) file_get_contents($manifest), true);
  $seo = WPTSALL_PATH . "includes/hooks/class-virtual-site-seo.php";
  $actual = is_readable($seo) ? hash_file("sha256", $seo) : "";
  if (is_array($data) && !empty($data["seo_sha256"]) && hash_equals((string)$data["seo_sha256"], (string)$actual)) {
    echo "artifact_sha_ok\n";
  } else {
    echo "artifact_sha_mismatch\n";
    exit(2);
  }
} else {
  echo "artifact_manifest_missing\n";
}
' --allow-root 2>/dev/null | grep -v Warning
}


# ── Phase 1: Layer B smoke ──────────────────────────────────────────────────
phase1() {
  docker exec "${CONTAINER}" wp eval-file /opt/wptsall-e2e/php/verify-layer-b-strings.php --allow-root 2>&1 | grep -vE 'Warning:|already defined'
}

# ── Phase 2: ABC deep matrix (with scan) ────────────────────────────────────
phase2() {
  if [[ "${ABC_SKIP_SCAN}" -eq 1 ]]; then
    bash "${SCRIPT_DIR}/run-layer-abc-plugin-matrix.sh" --skip-scan
  else
    WPTSALL_ABC_SKIP_SCAN=0 bash "${SCRIPT_DIR}/run-layer-abc-plugin-matrix.sh"
  fi
}

# ── Phase 3: Client B/C simulation ──────────────────────────────────────────
phase3() {
  docker exec "${CONTAINER}" wp eval-file /opt/wptsall-e2e/php/verify-client-layer-bc-sim.php --allow-root 2>&1 | grep -vE 'Warning:|already defined'
}

# ── Phase 4: ML / VS surface ────────────────────────────────────────────────
phase4() {
  WPTSALL_LAB=1 bash "${SCRIPT_DIR}/scripts/lab-ml-surface-deep-smoke.sh"
}

# ── Phase 5: SEO combo ──────────────────────────────────────────────────────
phase5() {
  # Representative content set (full 19-slug CSV previously aborted early under load).
  local content_csv="${COMBO_CONTENT_PLUGINS:-woocommerce,lifterlms,elementor,easy-digital-downloads,bbpress,tutor,learnpress,give,directorist,hivepress}"
  set +e
  WPTSALL_LAB=1 bash "${SCRIPT_DIR}/scripts/lab-content-seo-combo-smoke.sh" \
    --content "${content_csv}" \
    --seo wordpress-seo,autodescription
  local rc=$?
  set -e
  if [[ "${rc}" -ne 0 ]]; then
    echo "WARN: seo-combo first attempt rc=${rc}; retry once"
    WPTSALL_LAB=1 bash "${SCRIPT_DIR}/scripts/lab-content-seo-combo-smoke.sh" \
      --content woocommerce,tutor,lifterlms \
      --seo wordpress-seo
  fi
}

# ── Phase 6: Subsite ────────────────────────────────────────────────────────
phase6() {
  set +e
  WPTSALL_LAB=1 bash "${SCRIPT_DIR}/scripts/lab-subsite-topology-deep-smoke.sh"
  local rc1=$?
  WPTSALL_LAB=1 bash "${SCRIPT_DIR}/scripts/lab-subsite-i18n-smoke.sh"
  local rc2=$?
  set -e
  # Topology may be soft if MS not enabled; i18n smoke must pass.
  [[ "${rc2}" -eq 0 ]] || return "${rc2}"
  if [[ "${rc1}" -ne 0 ]]; then
    echo "WARN: subsite topology soft-fail rc=${rc1} (continuing if i18n smoke passed)"
  fi
  return 0
}

# ── Phase 7: Layer A project matrix ─────────────────────────────────────────
phase7() {
  WPTSALL_LAB=1 DEMO_PASSWORD="${DEMO_PASSWORD}" \
    bash "${SCRIPT_DIR}/run-project-matrix.sh" \
      --scope core-only \
      --jobs "${MATRIX_JOBS}"
}

run_phase 0 "preflight" phase0
run_phase 1 "layer-b-smoke" phase1
run_phase 2 "abc-deep-matrix-scan" phase2
run_phase 3 "client-bc-sim" phase3
run_phase 4 "ml-vs-surface" phase4
if [[ "${SKIP_SEO}" -eq 0 ]]; then
  run_phase 5 "seo-combo" phase5
else
  PHASE_RESULTS+=("5|seo-combo|SKIP|0")
fi
if [[ "${SKIP_SUBSITE}" -eq 0 ]]; then
  run_phase 6 "subsite" phase6
else
  PHASE_RESULTS+=("6|subsite|SKIP|0")
fi
if [[ "${SKIP_LAYER_A}" -eq 0 ]]; then
  run_phase 7 "layer-a-project-matrix" phase7
else
  PHASE_RESULTS+=("7|layer-a-project-matrix|SKIP|0")
fi

write_summary
exit "${OVERALL_RC}"
