#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"
source "${SCRIPT_DIR}/lib/failure-classification.sh"

SCOPE="${E2E_SCOPE:-core-only}"
INCLUDE_CORE=0
FAIL_FAST=0
LIST_ONLY=0
WITH_JOURNEYS=0
DRY_RUN=0
ALLOW_MISSING_SNAPSHOT=0
# Parallel jobs: default min(nproc,14) on Lab hosts (slot-a..n), else 1.
JOBS="${E2E_MATRIX_JOBS:-}"
JOURNEY_SKIP_EXIT_CODE="${WPTSALL_JOURNEY_SKIP_EXIT_CODE:-42}"
PROJECTS_CSV="${E2E_PROJECTS:-}"
RUN_ARGS=()

list_plugin_projects() {
  python3 - <<'PY' "${E2E_PROJECT_SPECS_FILE}"
import json
import sys
from pathlib import Path

spec = json.loads(Path(sys.argv[1]).read_text())
for name in spec.get("plugin_projects", {}).keys():
    print(name)
PY
}

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh [options] [-- run.sh args]

Options:
  --list                     列出现役独立插件 project
  --scope <core-only|full>   统一 scope，默认 core-only
  --include-core             在插件矩阵前先跑 core-content
  --projects <csv>           自定义 project 列表，逗号分隔
  --jobs <N>                 并行 lane 数（Lab：最多 14，对应 slot-a..n）
  --fail-fast                任一 project 失败立即停止
  --with-journeys            全部 project 完成后，再追加一次 journey-three-system lane
  --dry-run                  只输出矩阵计划和 heavy gate 预算，不执行项目
  --allow-missing-snapshot   full/journey 重型矩阵允许无 snapshot 继续运行（默认阻断）

Examples:
  bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --list
  bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --scope core-only --jobs 4
  bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --include-core --scope core-only
  bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --projects woocommerce-content,tutor-content -- --headed
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --list)
      LIST_ONLY=1
      shift
      ;;
    --scope)
      SCOPE="$2"
      shift 2
      ;;
    --include-core)
      INCLUDE_CORE=1
      shift
      ;;
    --projects)
      PROJECTS_CSV="$2"
      shift 2
      ;;
    --jobs)
      JOBS="${2:-1}"
      shift 2
      ;;
    --fail-fast)
      FAIL_FAST=1
      shift
      ;;
    --with-journeys)
      WITH_JOURNEYS=1
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
    --help|-h)
      usage
      exit 0
      ;;
    --)
      shift
      RUN_ARGS+=("$@")
      break
      ;;
    *)
      RUN_ARGS+=("$1")
      shift
      ;;
  esac
done

if [[ "$LIST_ONLY" -eq 1 ]]; then
  list_plugin_projects
  exit 0
fi

ensure_dirs

declare -a PROJECTS=()
if [[ "$INCLUDE_CORE" -eq 1 ]]; then
  PROJECTS+=("core-content")
fi

if [[ -n "$PROJECTS_CSV" ]]; then
  IFS=',' read -r -a CUSTOM_PROJECTS <<< "$PROJECTS_CSV"
  for project in "${CUSTOM_PROJECTS[@]}"; do
    trimmed="$(echo "$project" | xargs)"
    if [[ -n "$trimmed" ]]; then
      PROJECTS+=("$trimmed")
    fi
  done
else
  while IFS= read -r project; do
    [[ -n "$project" ]] && PROJECTS+=("$project")
  done < <(list_plugin_projects)
fi

if [[ "${#PROJECTS[@]}" -eq 0 ]]; then
  abort "No E2E projects selected for matrix run"
fi

# Resolve parallel jobs (Lab default: min(14, nproc) when unset)
if [[ -z "${JOBS}" ]]; then
  if _is_lab_mode && [[ "$(nproc 2>/dev/null || echo 1)" -ge 4 ]]; then
    JOBS=$(( $(nproc 2>/dev/null || echo 4) < 14 ? $(nproc 2>/dev/null || echo 4) : 14 ))
  else
    JOBS=1
  fi
fi
if ! [[ "${JOBS}" =~ ^[0-9]+$ ]] || [[ "${JOBS}" -lt 1 ]]; then
  JOBS=1
fi
if [[ "${JOBS}" -gt 14 ]]; then
  warn "Capping --jobs ${JOBS} → 14 (slot-a..slot-n)"
  JOBS=14
fi
USE_ISOLATED_SLOTS=0
if [[ "${JOBS}" -gt 1 ]]; then
  USE_ISOLATED_SLOTS=1
elif [[ "${E2E_MATRIX_ISOLATED_SLOTS:-}" == "1" ]]; then
  USE_ISOLATED_SLOTS=1
elif _is_lab_mode && [[ "${E2E_MATRIX_ISOLATED_SLOTS:-1}" != "0" ]]; then
  # A Lab matrix with --jobs 1 must still use the same slot-scoped WP/client
  # runtime as parallel matrices. Falling back to the shared runtime can turn
  # unrelated local control-plane drift into a false "all projects failed".
  USE_ISOLATED_SLOTS=1
fi

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
SUMMARY_TSV="$(mktemp)"
SUMMARY_JSON="${REPORTS_DIR}/e2e-project-matrix-${TIMESTAMP}.json"
BUDGET_PRE_JSON="${RUNTIME_DIR}/heavy-gate-budget-project-matrix-${TIMESTAMP}-pre.json"
BUDGET_POST_JSON="${RUNTIME_DIR}/heavy-gate-budget-project-matrix-${TIMESTAMP}-post.json"
HEAVY_GATE=0
HEAVY_LANE="project-matrix-full"
if [[ "${SCOPE}" == "full" || "${WITH_JOURNEYS}" -eq 1 ]]; then
  HEAVY_GATE=1
  if [[ "${SCOPE}" != "full" && "${WITH_JOURNEYS}" -eq 1 ]]; then
    HEAVY_LANE="journey-three-system"
  fi
fi

echo ""
echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}║          E2E Project Matrix Orchestrator        ║${NC}"
echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Scope:        ${BOLD}${SCOPE}${NC}"
echo -e "  Projects:     ${BOLD}${#PROJECTS[@]}${NC}"
echo -e "  Jobs:         ${BOLD}${JOBS}${NC}"
echo -e "  Isolated:     ${BOLD}${USE_ISOLATED_SLOTS}${NC}"
echo -e "  Fail-fast:    ${BOLD}${FAIL_FAST}${NC}"
echo -e "  Journeys:     ${BOLD}${WITH_JOURNEYS}${NC}"
echo -e "  Dry-run:      ${BOLD}${DRY_RUN}${NC}"
echo -e "  Heavy gate:   ${BOLD}${HEAVY_GATE}${NC}"
echo -e "  Report:       ${BOLD}${SUMMARY_JSON}${NC}"
echo ""

if [[ "${HEAVY_GATE}" -eq 1 ]]; then
  budget_args=(
    --lane "${HEAVY_LANE}"
    --phase pre
    --run-id "project-matrix-${TIMESTAMP}"
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
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
  python3 - <<'PY' "$SUMMARY_JSON" "$SCOPE" "$WITH_JOURNEYS" "$INCLUDE_CORE" "$HEAVY_GATE" "$HEAVY_LANE" "$BUDGET_PRE_JSON" "${PROJECTS[@]}"
import json
import sys
from pathlib import Path

summary_json = Path(sys.argv[1])
scope = sys.argv[2]
with_journeys = sys.argv[3] == "1"
include_core = sys.argv[4] == "1"
heavy_gate = sys.argv[5] == "1"
heavy_lane = sys.argv[6]
budget_pre = sys.argv[7]
projects = sys.argv[8:]

payload = {
    "status": "planned",
    "dry_run": True,
    "scope": scope,
    "with_journeys": with_journeys,
    "include_core": include_core,
    "total_projects": len(projects),
    "passed_projects": 0,
    "skipped_projects": 0,
    "failed_projects": 0,
    "heavy_gate": {
        "enabled": heavy_gate,
        "lane": heavy_lane if heavy_gate else None,
        "budget_pre": budget_pre if heavy_gate else None,
    },
    "projects": [{"project": p, "scope": scope, "status": "planned"} for p in projects],
}
summary_json.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps(payload, ensure_ascii=False))
PY
  ok "E2E project matrix dry-run planned successfully"
  exit 0
fi

FAILURES=0
INDEX=0

classify_matrix_lane_log_failure() {
  local log_file="$1"
  local context="${2:-project-matrix}"

  [[ -f "${log_file}" ]] || return 1

  if LC_ALL=C grep -Eq \
    'Data seeding stage failed|Seeding verification failed|Some seeding checks failed' \
    "${log_file}"; then
    E2E_FAILURE_CATEGORY="repo_regression"
    E2E_FAILURE_SURFACE="content_fixture_seed"
    E2E_FAILURE_MESSAGE="Stage 3 content plugin seeding verification failed"
    E2E_FAILURE_NEXT_ACTION="Inspect verify-seeding output for missing post_type/content fixture counts and repair the project seed plan"
    return 0
  fi

  if LC_ALL=C grep -Eq \
    'Task simulation monitoring UI not found on Tasks page|Cannot resolve relation_id from monitoring UI|Scan button for relation not found' \
    "${log_file}"; then
    E2E_FAILURE_CATEGORY="repo_regression"
    E2E_FAILURE_SURFACE="wp_admin_tasks_ui"
    E2E_FAILURE_MESSAGE="Stage 6 plugin-side task simulation UI was not reachable or did not expose monitoring controls"
    E2E_FAILURE_NEXT_ACTION="Inspect the Tasks admin page HTML/screenshot for capability, routing, or conditional-rendering drift"
    return 0
  fi

  # The matrix parent has the complete lane log, so classify assertion-level
  # client translation failures before running live environment probes. A
  # post-failure probe can be stale or point at a different slot and must not
  # overwrite a concrete Stage 6 task failure.
  if LC_ALL=C grep -Eq \
    'discovery\.language_pack_translate_failed|tasks_failed[[:space:]]*:[[:space:]]*[1-9][0-9]*|expect\(tasksFailed\)\.toBe\(0\)' \
    "${log_file}"; then
    E2E_FAILURE_CATEGORY="repo_regression"
    E2E_FAILURE_SURFACE="client_translation_language_pack"
    E2E_FAILURE_MESSAGE="Stage 6 client translation produced failed language-pack tasks"
    E2E_FAILURE_NEXT_ACTION="Inspect preserved slot client log and SQLite translation_items.error_message for translate failure details"
    return 0
  fi

  if LC_ALL=C grep -Eq \
    'Write-back verification had failures|Virtual: posts exist[[:space:]]+0 posts|Write-back completion evidence[[:space:]]+completed_tasks=0' \
    "${log_file}"; then
    E2E_FAILURE_CATEGORY="repo_regression"
    E2E_FAILURE_SURFACE="wp_writeback_verification"
    E2E_FAILURE_MESSAGE="Stage 7 write-back verification failed after client translation completed"
    E2E_FAILURE_NEXT_ACTION="Inspect callback/write-back logs, translation_results status, and relation-scoped target content"
    return 0
  fi

  if LC_ALL=C grep -Eq 'Test timeout of .* exceeded|page\.waitForResponse: Test timeout' "${log_file}"; then
    E2E_FAILURE_CATEGORY="repo_regression"
    E2E_FAILURE_SURFACE="playwright_test_timeout"
    E2E_FAILURE_MESSAGE="Stage 6 Playwright plugin-side suite timed out waiting for a REST response (monitor/scan/job)"
    E2E_FAILURE_NEXT_ACTION="Inspect WP REST URL form (pretty /wp-json/ vs index.php?rest_route=) and Playwright waitForResponse predicates in plugin-task-simulation.gate.e2e.spec.ts"
    return 0
  fi

  if LC_ALL=C grep -Eq 'Playwright client-side suite failed|[0-9]+ failed.*client-translate\.(execution|verification)\.gate' "${log_file}"; then
    E2E_FAILURE_CATEGORY="repo_regression"
    E2E_FAILURE_SURFACE="${context}"
    E2E_FAILURE_MESSAGE="Stage 6 Playwright client-side suite failed after lane startup"
    E2E_FAILURE_NEXT_ACTION="Inspect the lane log and latest Playwright failure output"
    return 0
  fi

  return 1
}

prime_slot_client_db() {
  local slot="$1"
  local shared_db="${HOME}/projects/runtime/clients/wpplugin/runtime/wptsall.db"
  local slot_db_dir="${E2E_DIR}/runtime/${slot}/client-db"

  mkdir -p "${slot_db_dir}" "${E2E_DIR}/runtime/${slot}"
  if [[ -f "${shared_db}" ]]; then
    cp -a "${shared_db}" "${slot_db_dir}/wptsall.db"
    rm -f "${slot_db_dir}/wptsall.db-shm" "${slot_db_dir}/wptsall.db-wal" 2>/dev/null || true
    # Bump concurrency inside each slot DB. The slot DB is intentionally
    # deleted by lane cleanup; prime again before every slot reuse.
    python3 - "${slot_db_dir}/wptsall.db" <<'PY' || true
import sqlite3, sys
con = sqlite3.connect(sys.argv[1])
for k, v in [
    ("callback_concurrency", "8"),
    ("global_callback_concurrency", "8"),
    ("relation_max_pending_callbacks", "12000"),
    ("discovery_concurrency", "4"),
]:
    try:
        con.execute("INSERT OR REPLACE INTO system_config(key,value) VALUES(?,?)", (k, v))
    except Exception:
        pass
try:
    con.execute("DELETE FROM system_config WHERE key = 'domain_token_bindings_doc'")
except Exception:
    pass
con.commit()
con.close()
PY
    ok "Seeded ${slot} client DB → ${slot_db_dir}/wptsall.db"
  else
    warn "Shared client DB not found (${shared_db}); ${slot} will start with an empty DB"
  fi
}

reset_slot_wp_plugin_baseline() {
  local slot="$1"
  local container="wptsall-wp-lab-wordpress-${slot}"

  if ! docker inspect -f '{{.State.Running}}' "${container}" 2>/dev/null | grep -qx true; then
    abort "Cannot reset ${slot}: WordPress container is not running (${container})"
  fi

  # Mirror ensure-slot-wordpress.sh _reset_slot_wptsall_tables(): matrix lanes reuse
  # slot DBs; leaving wpmmcc-ats in active_plugins makes Stage 1 `plugin activate`
  # report success without running the activation hook, so only a handful of legacy
  # wptsall_* tables remain and env-check fails (need >= 24).
  docker exec "${container}" \
    wp --allow-root --path=/var/www/html --skip-plugins --skip-themes eval '
      global $wpdb;
      $like  = $wpdb->esc_like($wpdb->prefix . "wptsall") . "%";
      $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));
      foreach ((array) $tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `" . $table . "`");
      }
      $keep = array();
      foreach (array("wordpress-importer/wordpress-importer.php") as $plugin) {
        if (file_exists(WP_PLUGIN_DIR . "/" . $plugin)) {
          $keep[] = $plugin;
        }
      }
      update_option("active_plugins", array_values(array_unique($keep)));
      delete_option("wptsall_db_version");
      delete_option("wptsall_settings");
      if (function_exists("wp_cache_flush")) {
        wp_cache_flush();
      }
    ' >/dev/null
  ok "Reset ${slot} WP baseline → dropped wptsall tables, active_plugins=wordpress-importer only"
}

run_one_project() {
  local project="$1"
  local slot="$2"
  local index="$3"
  local started_at start_ts finish_ts finished_at duration_secs exit_code status
  local category surface message next_action previous_project
  local log_file row_file

  started_at="$(date -Iseconds)"
  start_ts="$(date +%s)"
  log_file="${REPORTS_DIR}/matrix-lane-${TIMESTAMP}-${project}.log"
  row_file="${SUMMARY_TSV}.${project}.$$"

  echo ""
  echo -e "${BOLD}${BLUE}>>> [${index}/${#PROJECTS[@]}] ${project} (${SCOPE}) slot=${slot} jobs=${JOBS}${NC}"

  set +e
  if [[ "${USE_ISOLATED_SLOTS}" -eq 1 ]]; then
    # Parallel: each lane owns a complete WP+MySQL+client slot. Do not skip
    # reset/seed: the slot can be reused by a later project after this lane.
    # Unset ambient DB/CLIENT/bind so slot ports win — never :8977/:9083.
    env -u WPTSALL_DB_PATH -u CLIENT_BASE -u CLIENT_URL \
      -u WPTSALL_WEB_UI_BIND -u WPTSALL_WEB_UI_PORT \
      -u WPTSALL_E2E_SEED_PLUGIN_ALLOWLIST \
      -u E2E_SEED_PLANS -u WPTSALL_E2E_SEED_PLANS \
      E2E_PROJECT="$project" E2E_SCOPE="$SCOPE" E2E_SLOT="$slot" \
      E2E_MATRIX_PARALLEL=1 E2E_SKIP_WP_RESET=0 E2E_SLOT_WP_ISOLATED=1 \
      E2E_SLOT_CLEANUP="${E2E_SLOT_CLEANUP:-1}" \
      LAB_WP_CONTAINER="wptsall-wp-lab-wordpress-${slot}" \
      E2E_SLOT_WP_BASE="http://127.0.0.1:$(E2E_SLOT="$slot" e2e_slot_wp_port)" \
      E2E_AUTO_ACTIVATE_PLUGINS=1 WPTSALL_LAB=1 \
      E2E_MATRIX_EVENTS_FILE="${E2E_MATRIX_EVENTS_FILE:-}" \
      DEMO_PASSWORD="${DEMO_PASSWORD:-demo}" \
      bash "${SCRIPT_DIR}/run.sh" "${RUN_ARGS[@]}" \
      >"${log_file}" 2>&1
    exit_code=$?
  else
    E2E_PROJECT="$project" E2E_SCOPE="$SCOPE" \
      E2E_MATRIX_EVENTS_FILE="${E2E_MATRIX_EVENTS_FILE:-}" \
      bash "${SCRIPT_DIR}/run.sh" "${RUN_ARGS[@]}"
    exit_code=$?
  fi
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
    E2E_PROJECT="${project}"
    if ! classify_matrix_lane_log_failure "${log_file}" "project-matrix:${project}"; then
      e2e_classify_failure "project-matrix:${project}" "${exit_code}"
    fi
    E2E_PROJECT="${previous_project}"
    category="${E2E_FAILURE_CATEGORY}"
    surface="${E2E_FAILURE_SURFACE}"
    message="${E2E_FAILURE_MESSAGE}"
    next_action="${E2E_FAILURE_NEXT_ACTION}"
    err "Matrix lane failed: ${project} (exit=${exit_code}, ${duration_secs}s, category=${category}, log=${log_file})"
  else
    ok "Matrix lane passed: ${project} (${duration_secs}s slot=${slot})"
  fi

  # CLI parent event bus (parallel stdout is discarded; this file is the wake source).
  if [[ -n "${E2E_MATRIX_EVENTS_FILE:-}" ]]; then
    printf '%s MATRIX_LANE_%s project=%s slot=%s exit=%s secs=%s category=%s log=%s\n' \
      "$(date -Iseconds)" "${status^^}" "${project}" "${slot}" "${exit_code}" \
      "${duration_secs}" "${category}" "${log_file}" \
      >>"${E2E_MATRIX_EVENTS_FILE}" 2>/dev/null || true
  fi

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$project" "$status" "$exit_code" "$duration_secs" "$started_at" "$finished_at" \
    "$category" "$surface" "$message" "$next_action" > "$row_file"

  echo "$exit_code"
}

if [[ "${USE_ISOLATED_SLOTS}" -eq 1 ]]; then
  info "Matrix: provisioning isolated WordPress slots and priming client DBs"
  SLOT_LIST=(slot-a slot-b slot-c slot-d slot-e slot-f slot-g slot-h slot-i slot-j slot-k slot-l slot-m slot-n)
  for slot in "${SLOT_LIST[@]:0:${JOBS}}"; do
    if [[ -x "${E2E_DIR}/scripts/ensure-slot-wordpress.sh" ]]; then
      E2E_SLOT="${slot}" bash "${E2E_DIR}/scripts/ensure-slot-wordpress.sh" "${slot}" \
        || abort "Failed to provision isolated WordPress slot ${slot}"
    else
      abort "Missing isolated WordPress provisioner: ${E2E_DIR}/scripts/ensure-slot-wordpress.sh"
    fi
    if [[ -x "${E2E_DIR}/scripts/write-run-manifest.sh" ]]; then
      E2E_SLOT="${slot}" E2E_PROJECT="matrix-prime" bash "${E2E_DIR}/scripts/write-run-manifest.sh" running >/dev/null || true
    fi
    reset_slot_wp_plugin_baseline "${slot}"
    prime_slot_client_db "${slot}"
  done
  declare -a PIDS=()
  declare -a PID_PROJECTS=()
  declare -a PID_SLOTS=()
  for project in "${PROJECTS[@]}"; do
    INDEX=$((INDEX + 1))

    # Throttle to JOBS concurrent
    while [[ "$(jobs -rp | wc -l)" -ge "${JOBS}" ]]; do
      if [[ "${FAIL_FAST}" -eq 1 ]]; then
        # Check finished children
        for i in "${!PIDS[@]}"; do
          pid="${PIDS[$i]}"
          if [[ -n "${pid}" ]] && ! kill -0 "${pid}" 2>/dev/null; then
            wait "${pid}" || true
            row="${SUMMARY_TSV}.${PID_PROJECTS[$i]}.*"
            # Only inspect the completed lane row here for fail-fast. The final
            # collection pass owns appending rows to SUMMARY_TSV; appending here
            # duplicated completed lanes when --jobs 1 reused a slot.
            # shellcheck disable=SC2086
            if compgen -G "${SUMMARY_TSV}.${PID_PROJECTS[$i]}.*" >/dev/null; then
              ec="$(awk -F'\t' '{print $3; exit}' ${SUMMARY_TSV}."${PID_PROJECTS[$i]}".* 2>/dev/null || echo 1)"
              if [[ "${ec}" != "0" ]]; then
                warn "Fail-fast: stopping new launches after ${PID_PROJECTS[$i]}"
                # Final TSV collection owns FAILURES accounting; do not count
                # this early detection twice.
                wait || true
                break 2
              fi
            fi
            PIDS[$i]=""
          fi
        done
      fi
      sleep 2
    done

    # A slot may be reused only after its prior lane has exited. The former
    # round-robin assignment could launch slot-a twice when slot-b completed
    # first, causing cross-project client/WP state contamination.
    slot=""
    while [[ -z "${slot}" ]]; do
      for candidate in "${SLOT_LIST[@]:0:${JOBS}}"; do
        candidate_busy=0
        for i in "${!PIDS[@]}"; do
          if [[ "${PID_SLOTS[$i]:-}" == "${candidate}" ]] \
            && [[ -n "${PIDS[$i]:-}" ]] \
            && kill -0 "${PIDS[$i]}" 2>/dev/null; then
            candidate_busy=1
            break
          fi
        done
        if [[ "${candidate_busy}" -eq 0 ]]; then
          slot="${candidate}"
          break
        fi
      done
      [[ -n "${slot}" ]] || sleep 1
    done
    reset_slot_wp_plugin_baseline "${slot}"
    prime_slot_client_db "${slot}"

    # Keep parent stdout quiet during fan-out, but append lane verdicts to a
    # progress log so supervisors can see pass/fail without waiting for wait().
    MATRIX_PROGRESS_LOG="${REPORTS_DIR}/matrix-progress-${TIMESTAMP}.log"
    : >>"${MATRIX_PROGRESS_LOG}"
    (
      # shellcheck disable=SC2094
      run_one_project "${project}" "${slot}" "${INDEX}" \
        > >(tee -a "${MATRIX_PROGRESS_LOG}" >/dev/null) \
        2> >(tee -a "${MATRIX_PROGRESS_LOG}" >/dev/null)
    ) &
    PIDS+=("$!")
    PID_PROJECTS+=("${project}")
    PID_SLOTS+=("${slot}")
  done

  wait || true
  # Collect TSV rows
  for project in "${PROJECTS[@]}"; do
    if compgen -G "${SUMMARY_TSV}.${project}.*" >/dev/null; then
      # shellcheck disable=SC2086
      cat ${SUMMARY_TSV}."${project}".* >> "${SUMMARY_TSV}"
      ec="$(awk -F'\t' '{print $3; exit}' ${SUMMARY_TSV}."${project}".* 2>/dev/null || echo 1)"
      if [[ "${ec}" != "0" ]]; then
        FAILURES=$((FAILURES + 1))
      fi
      rm -f ${SUMMARY_TSV}."${project}".*
    else
      printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$project" "failed" "1" "0" "" "" "matrix_missing_row" "e2e_pipeline" "no result row" "check lane logs" \
        >> "$SUMMARY_TSV"
      FAILURES=$((FAILURES + 1))
    fi
  done
else
  for project in "${PROJECTS[@]}"; do
    INDEX=$((INDEX + 1))
    ec="$(run_one_project "${project}" "shared" "${INDEX}" | tail -1)"
    # shellcheck disable=SC2086
    if compgen -G "${SUMMARY_TSV}.${project}.*" >/dev/null; then
      cat ${SUMMARY_TSV}."${project}".* >> "${SUMMARY_TSV}"
      rm -f ${SUMMARY_TSV}."${project}".*
    fi
    if [[ "${ec}" != "0" ]]; then
      FAILURES=$((FAILURES + 1))
      if [[ "$FAIL_FAST" -eq 1 ]]; then
        warn "Fail-fast enabled; stopping matrix after ${project}"
        break
      fi
    fi
  done
fi

if [[ "$WITH_JOURNEYS" -eq 1 ]]; then
  started_at="$(date -Iseconds)"
  start_ts="$(date +%s)"

  echo ""
  echo -e "${BOLD}${BLUE}>>> [journey] journey-three-system${NC}"

  set +e
  bash "${SCRIPT_DIR}/run-playwright-journey-three-system.sh" --legacy-only
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
  if [[ "$exit_code" -eq "$JOURNEY_SKIP_EXIT_CODE" ]]; then
    status="skipped"
    warn "Journey lane skipped by environment policy: journey-three-system (${duration_secs}s)"
  elif [[ "$exit_code" -ne 0 ]]; then
    status="failed"
    e2e_classify_failure "project-matrix:journey-three-system" "${exit_code}"
    category="${E2E_FAILURE_CATEGORY}"
    surface="${E2E_FAILURE_SURFACE}"
    message="${E2E_FAILURE_MESSAGE}"
    next_action="${E2E_FAILURE_NEXT_ACTION}"
    FAILURES=$((FAILURES + 1))
    err "Journey lane failed: journey-three-system (exit=${exit_code}, ${duration_secs}s, category=${category})"
  else
    ok "Journey lane passed: journey-three-system (${duration_secs}s)"
  fi

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "journey-three-system" "$status" "$exit_code" "$duration_secs" "$started_at" "$finished_at" \
    "$category" "$surface" "$message" "$next_action" >> "$SUMMARY_TSV"
fi

if [[ "${HEAVY_GATE}" -eq 1 ]]; then
  bash "${SCRIPT_DIR}/heavy-gate-budget.sh" \
    --lane "${HEAVY_LANE}" \
    --phase post \
    --run-id "project-matrix-${TIMESTAMP}" \
    --output "${BUDGET_POST_JSON}" \
    --before "${BUDGET_PRE_JSON}" \
    --warn-only
fi

python3 - <<'PY' "$SUMMARY_TSV" "$SUMMARY_JSON" "$SCOPE" "$HEAVY_GATE" "$HEAVY_LANE" "$BUDGET_PRE_JSON" "$BUDGET_POST_JSON"
import csv
import json
import sys
from pathlib import Path

tsv_path = Path(sys.argv[1])
json_path = Path(sys.argv[2])
scope = sys.argv[3]
heavy_gate = sys.argv[4] == "1"
heavy_lane = sys.argv[5]
budget_pre = sys.argv[6]
budget_post = sys.argv[7]
rows = []
with tsv_path.open() as fh:
    reader = csv.reader(fh, delimiter="\t")
    for project, status, exit_code, duration_secs, started_at, finished_at, category, surface, message, next_action in reader:
        rows.append({
            "project": project,
            "scope": scope,
            "status": status,
            "exit_code": int(exit_code),
            "duration_secs": int(duration_secs),
            "started_at": started_at,
            "finished_at": finished_at,
            "category": None if category == "none" else category,
            "surface": None if surface == "none" else surface,
            "message": message or None,
            "next_action": next_action or None,
        })

payload = {
    "scope": scope,
    "total_projects": len(rows),
    "passed_projects": sum(1 for row in rows if row["status"] == "passed"),
    "skipped_projects": sum(1 for row in rows if row["status"] == "skipped"),
    "failed_projects": sum(1 for row in rows if row["status"] == "failed"),
    "failed_categories": {
        category: sum(1 for row in rows if row["status"] == "failed" and row.get("category") == category)
        for category in sorted({row.get("category") for row in rows if row.get("category")})
    },
    "heavy_gate": {
        "enabled": heavy_gate,
        "lane": heavy_lane if heavy_gate else None,
        "budget_pre": budget_pre if heavy_gate else None,
        "budget_post": budget_post if heavy_gate else None,
    },
    "projects": rows,
}
json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n")
print(json.dumps(payload, ensure_ascii=False))
PY

rm -f "$SUMMARY_TSV"

echo ""
if [[ "$FAILURES" -gt 0 ]]; then
  err "E2E project matrix completed with ${FAILURES} failed lane(s)"
  exit 1
fi

ok "E2E project matrix completed successfully"
