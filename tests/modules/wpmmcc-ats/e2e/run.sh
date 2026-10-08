#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# E2E v2 主编排器
#
# 当前现役 project：
#   - E2E_PROJECT=core-content
#   - E2E_PROJECT=<plugin>-content  (15 个独立插件 project)
#   - legacy family projects 仍兼容，但不再是长期目标口径
#
# 项目内仍按 7 阶段自动化测试执行：
#   1. 环境检查    2. 环境清理    3. 数据填充
#   4. 模型扫描    5. 站点关系    6. Client 翻译
#   7. 验证 + 报告
#
# 用法:
#   E2E_PROJECT=core-content E2E_SCOPE=core-only bash tests/modules/wpmmcc-ats/e2e/run.sh
#   E2E_PROJECT=woocommerce-content E2E_SCOPE=core-only bash tests/modules/wpmmcc-ats/e2e/run.sh
#   E2E_PROJECT=tutor-content E2E_SCOPE=core-only bash tests/modules/wpmmcc-ats/e2e/run.sh
#   E2E_PROJECT=wordpress-seo-content E2E_SCOPE=core-only bash tests/modules/wpmmcc-ats/e2e/run.sh
#   组件模板专项 lane 走:
#     bash tests/modules/wpmmcc-ats/e2e/run-component-template-suite.sh
#     # suite 内默认包含 ct0-smoke / ct1 / ct2 / ct2-support-ui / ct3
#   bash tests/modules/wpmmcc-ats/e2e/run.sh --headed
#   bash tests/modules/wpmmcc-ats/e2e/run.sh --stage 6
#   bash tests/modules/wpmmcc-ats/e2e/run.sh --skip-seed
#   bash tests/modules/wpmmcc-ats/e2e/run.sh --verify
#   bash tests/modules/wpmmcc-ats/e2e/run.sh --with-journeys
#
# 环境变量:
#   E2E_PROJECT=core-content|<plugin>-content|legacy-family-project  当前受支持的项目
#   E2E_SCOPE=core-only|full  当前项目内的 baseline / extended lane
#   SKIP_CLIENT=1             跳过 Client 启动（Stage 6 中已运行时）
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"
source "${SCRIPT_DIR}/lib/failure-classification.sh"

RUN_STARTED_AT="$(date -Iseconds)"
RUN_TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
RUN_FAILURE_REPORT="${REPORTS_DIR}/e2e-v2-runner-failure-${RUN_TIMESTAMP}.json"
E2E_RUN_ID="${E2E_RUN_ID:-e2e-${RUN_TIMESTAMP}-${E2E_SLOT}-${E2E_PROJECT}}"
export E2E_RUN_ID

write_lane_manifest() {
  local status="${1:-running}"
  local manifest_script="${E2E_DIR}/scripts/write-run-manifest.sh"
  if [[ -x "${manifest_script}" ]]; then
    E2E_SLOT="${E2E_SLOT}" E2E_PROJECT="${E2E_PROJECT}" E2E_SCOPE="${E2E_SCOPE}" \
      E2E_RUN_ID="${E2E_RUN_ID}" bash "${manifest_script}" "${status}" >/dev/null 2>&1 || true
  fi
}

cleanup_lane_resources() {
  # Matrix slots own their client process and volatile SQLite/log files, so
  # they must be reaped before the slot is available to the next project.
  if e2e_is_slot_mode && [[ "${E2E_SLOT_CLEANUP:-1}" != "0" ]]; then
    local cleanup_script="${E2E_DIR}/scripts/cleanup-slot-dry-run.sh"
    if [[ ! -x "${cleanup_script}" ]]; then
      err "Required slot cleanup script is missing: ${cleanup_script}"
      return 1
    fi
    E2E_SLOT="${E2E_SLOT}" E2E_RUN_ID="${E2E_RUN_ID}" \
      bash "${cleanup_script}" "${E2E_SLOT}" --apply --outcome "$1" --run-id "${E2E_RUN_ID}"
    return 0
  fi
  # Shared-mode hygiene (2026-09-02, gate6 preflight lesson): Stage 6 in Lab
  # mode intentionally leaves a developer-owned client on :8977 when it REUSED
  # one that was already running, but a client STARTED by this run (pid file
  # written by the Stage 6 start branch) must not survive the run — the next
  # gate's RG-MANUAL-PREFLIGHT fails on it ("forbidden processes already
  # running"). Reap only the recorded pid after verifying /proc cmdline still
  # matches wptsall-client, so pid reuse can never kill an unrelated process.
  local pid_file
  pid_file="$(e2e_client_pid_file)"
  if [[ -f "${pid_file}" ]]; then
    local client_pid
    client_pid="$(tr -d ' \n' < "${pid_file}" 2>/dev/null || true)"
    if [[ -n "${client_pid}" ]] && grep -qa "wptsall-client" "/proc/${client_pid}/cmdline" 2>/dev/null; then
      kill "${client_pid}" 2>/dev/null || true
    fi
    rm -f "${pid_file}"
  fi
  return 0
}

on_exit() {
  local exit_code=$?
  trap - EXIT
  local outcome="pass"
  if [[ "${exit_code}" -ne 0 ]]; then
    outcome="fail"
    write_lane_manifest fail
  else
    write_lane_manifest pass
  fi

  if ! cleanup_lane_resources "${outcome}"; then
    err "Required slot resource cleanup failed for ${E2E_SLOT}"
    exit_code=1
    write_lane_manifest fail
  fi

  if [[ "${exit_code}" -ne 0 ]]; then
    local finished_at
    finished_at="$(date -Iseconds)"
    mkdir -p "${REPORTS_DIR}"
    e2e_classify_failure "run.sh" "${exit_code}"
    e2e_emit_failure_json "${RUN_FAILURE_REPORT}" "run.sh" "${exit_code}" "${RUN_STARTED_AT}" "${finished_at}" >/dev/null
    err "E2E run classified as ${E2E_FAILURE_CATEGORY} on ${E2E_FAILURE_SURFACE}: ${E2E_FAILURE_MESSAGE}"
    warn "Next action: ${E2E_FAILURE_NEXT_ACTION}"
    warn "Failure classification report: ${RUN_FAILURE_REPORT}"
    e2e_emit_event "RUN_FAILED" \
      "exit=${exit_code} category=${E2E_FAILURE_CATEGORY:-?} surface=${E2E_FAILURE_SURFACE:-?} msg=$(printf '%s' "${E2E_FAILURE_MESSAGE:-}" | tr '\n' ' ' | cut -c1-160)"
  else
    e2e_emit_event "RUN_PASSED" "ok=1"
  fi
  exit "${exit_code}"
}
trap on_exit EXIT

# Emit stage boundaries for CLI parent near-real-time wake.
run_e2e_stage() {
  local stage_num="$1"
  local stage_name="$2"
  shift 2
  e2e_emit_event "STAGE_START" "stage=${stage_num} name=${stage_name}"
  set +e
  "$@"
  local rc=$?
  set -e
  if [[ "${rc}" -eq 0 ]]; then
    e2e_emit_event "STAGE_PASSED" "stage=${stage_num} name=${stage_name}"
    return 0
  fi
  e2e_emit_event "STAGE_FAILED" "stage=${stage_num} name=${stage_name} rc=${rc}"
  return "${rc}"
}

e2e_assert_supported_project

# ── 参数解析 ──────────────────────────────────────────────────────────────────
HEADED=""
START_STAGE="${E2E_START_STAGE:-1}"
SKIP_SEED=0
VERIFY_ONLY=0
WITH_JOURNEYS=0
WITH_PLUGIN_JOURNEYS=0
STAGE4_PRE_CLEAN="${E2E_STAGE4_PRE_CLEAN:-1}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --headed)
      HEADED="--headed"
      shift
      ;;
    --stage)
      START_STAGE="$2"
      shift 2
      ;;
    --skip-seed)
      SKIP_SEED=1
      shift
      ;;
    --verify)
      VERIFY_ONLY=1
      shift
      ;;
    --with-journeys)
      WITH_JOURNEYS=1
      shift
      ;;
    --with-plugin-journeys)
      WITH_PLUGIN_JOURNEYS=1
      shift
      ;;
    *)
      echo "Unknown option: $1"
      echo "Usage: $0 [--headed] [--stage N] [--skip-seed] [--verify] [--with-journeys] [--with-plugin-journeys]"
      exit 1
      ;;
  esac
done

if [ "$VERIFY_ONLY" -eq 1 ]; then
  START_STAGE=7
fi

# ── 开始 ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}║         E2E v2 — Project Orchestrator           ║${NC}"
printf "${BOLD}${CYAN}║   %-46s ║${NC}\n" "$(e2e_banner_subtitle)"
echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Project:      ${BOLD}$(e2e_project_label)${NC}"
echo -e "  Scope:        ${BOLD}$(e2e_scope_label)${NC}"
echo -e "  Slot:         ${BOLD}${E2E_SLOT}${NC}"
echo -e "  WP plugins:   ${BOLD}$(e2e_required_wp_plugins_csv)${NC}"
echo -e "  Seed plans:   ${BOLD}$(e2e_seed_plans_csv)${NC}"
echo -e "  Seed allow:   ${BOLD}${WPTSALL_E2E_SEED_PLUGIN_ALLOWLIST:-(all plan plugins)}${NC}"
echo -e "  Bind models:  ${BOLD}$(e2e_model_binding_plugins_csv)${NC}"
echo -e "  Content set:  ${BOLD}$(e2e_content_types_csv)${NC}"
echo -e "  Runtime dir:  ${BOLD}${RUNTIME_DIR}${NC}"
echo -e "  Reports dir:  ${BOLD}${REPORTS_DIR}${NC}"
echo -e "  Client URL:   ${BOLD}${CLIENT_URL}${NC}"
echo -e "  Start stage: ${BOLD}$START_STAGE${NC}"
echo -e "  Skip seed:   ${BOLD}$SKIP_SEED${NC}"
echo -e "  Headed:      ${BOLD}${HEADED:-no}${NC}"
echo -e "  Journeys:    ${BOLD}$WITH_JOURNEYS${NC}"
echo ""

TOTAL_START=$(stage_start_time)

ensure_dirs
write_lane_manifest running

if e2e_is_slot_mode && [ "$VERIFY_ONLY" -eq 0 ] && [ "$START_STAGE" -le 3 ] \
  && [[ "${E2E_MATRIX_PARALLEL:-0}" != "1" ]]; then
  abort "Slot live runs cannot start from Stage ${START_STAGE}. Run a shared (non-slot) full init first: bash tests/modules/wpmmcc-ats/e2e/run.sh — its Stages 1-3 (env-check + env-clean + data-seed) write the shared global-init marker — then start slot runs from --stage 4. Or set E2E_MATRIX_PARALLEL=1 for matrix --jobs."
fi

if e2e_is_slot_mode && [ "$VERIFY_ONLY" -eq 0 ] && [ "$START_STAGE" -le 6 ]; then
  GLOBAL_INIT_MARKER="$(e2e_global_init_marker)"
  if [ ! -f "$GLOBAL_INIT_MARKER" ]; then
    if [[ "${E2E_MATRIX_PARALLEL:-0}" == "1" ]]; then
      mkdir -p "$(dirname "$GLOBAL_INIT_MARKER")"
      printf '{"parallel_matrix":true,"created_at":"%s","note":"auto-created for E2E_MATRIX_PARALLEL"}\n' "$(date -Iseconds)" \
        > "$GLOBAL_INIT_MARKER"
      warn "Created global-init marker for parallel matrix: ${GLOBAL_INIT_MARKER}"
    else
      abort "Missing shared global-init marker: ${GLOBAL_INIT_MARKER}. Run a shared (non-slot) full init first: bash tests/modules/wpmmcc-ats/e2e/run.sh (Stages 1-3 write the marker) before starting slot live runs."
    fi
  fi
fi

# ── Stage 4 Partial-Start Pre-clean ─────────────────────────────────────────
# `run.sh --stage 4` is the main "full chain from scan onward" entry for many
# project lanes. Without a cleanup pass it can silently reuse stale mappings /
# targets from a previous project run and end up with zero claimable workload.
if e2e_is_slot_mode && [ "$START_STAGE" -eq 4 ] && [ "$VERIFY_ONLY" -eq 0 ] && [ "$STAGE4_PRE_CLEAN" = "1" ]; then
  echo ""
  warn "Stage 4 partial start detected in ${E2E_SLOT}; shared pre-clean is skipped in slot mode. Use a shared (non-slot) run — bash tests/modules/wpmmcc-ats/e2e/run.sh (Stage 2 env-clean) — for one-time shared cleanup."
elif [ "$START_STAGE" -eq 4 ] && [ "$VERIFY_ONLY" -eq 0 ] && [ "$STAGE4_PRE_CLEAN" = "1" ]; then
  echo ""
  warn "Stage 4 partial start detected; running pre-clean to clear stale mappings/targets before scan."
  bash "${SCRIPT_DIR}/stages/02-env-clean.sh"
fi

# ── Stage 1: 环境检查 ────────────────────────────────────────────────────────
if [ "$START_STAGE" -le 1 ]; then
  run_e2e_stage 1 env-check bash "${SCRIPT_DIR}/stages/01-env-check.sh"
fi

# ── Stage 2: 环境清理 ────────────────────────────────────────────────────────
if [ "$START_STAGE" -le 2 ]; then
  run_e2e_stage 2 env-clean bash "${SCRIPT_DIR}/stages/02-env-clean.sh"
fi

# ── Stage 3: 数据填充 ────────────────────────────────────────────────────────
if [ "$START_STAGE" -le 3 ] && [ "$SKIP_SEED" -eq 0 ]; then
  run_e2e_stage 3 data-seed bash "${SCRIPT_DIR}/stages/03-data-seed.sh"
elif [ "$START_STAGE" -le 3 ]; then
  echo ""
  warn "Stage 3 (Data Seeding) skipped by --skip-seed"
  if [ "$E2E_PROJECT" = "elementor-content" ]; then
    info "Ensuring elementor_library fixture for elementor-content..."
    if wp_eval "${E2E_DIR}/php/seed-elementor-library-fixture.php"; then
      ok "Elementor library fixture ready"
    else
      abort "Elementor library fixture seed failed (--skip-seed path)"
    fi
  fi
  e2e_emit_event "STAGE_PASSED" "stage=3 name=data-seed skipped=1"
fi

# ── Shared Global-Init Marker ────────────────────────────────────────────────
# A shared (non-slot) run that executed Stages 1-3 IS the shared global init
# slot runs depend on (env-check + env-clean + data-seed leave the shared
# runtime state slots reuse). Record it so slot runs can verify the
# prerequisite instead of silently reusing stale state. --skip-seed runs do
# NOT write the marker: unseeded shared state would leave slot Stage 4+ with
# zero claimable workload. Slot runs never reach this point (aborted above
# when START_STAGE<=3); a later full run refreshes the marker timestamp.
if ! e2e_is_slot_mode && [ "$VERIFY_ONLY" -eq 0 ] && [ "$START_STAGE" -le 3 ] && [ "$SKIP_SEED" -eq 0 ]; then
  GLOBAL_INIT_MARKER="$(e2e_global_init_marker)"
  mkdir -p "$(dirname "$GLOBAL_INIT_MARKER")"
  printf '{"shared_global_init":true,"created_at":"%s","note":"written by run.sh after Stages 1-3 (env-check+env-clean+data-seed)"}\n' "$(date -Iseconds)" \
    > "$GLOBAL_INIT_MARKER"
  warn "Shared global-init marker written: ${GLOBAL_INIT_MARKER}"
fi

# ── Stage 4: 模型扫描 ────────────────────────────────────────────────────────
if [ "$START_STAGE" -le 4 ]; then
  # Matrix parallel: lane-scoped scan in trigger-scan.php (no flock).
  run_e2e_stage 4 model-scan bash "${SCRIPT_DIR}/stages/04-model-scan.sh"
fi

# ── Stage 5: 站点关系 ────────────────────────────────────────────────────────
if [ "$START_STAGE" -le 5 ]; then
  run_e2e_stage 5 relation-setup bash "${SCRIPT_DIR}/stages/05-relation-setup.sh"
fi

# ── Stage 6: Client 翻译 ─────────────────────────────────────────────────────
if [ "$START_STAGE" -le 6 ]; then
  run_e2e_stage 6 client-translate bash "${SCRIPT_DIR}/stages/06-client-translate.sh" "$HEADED"
fi

# ── Stage 7: 验证 + 报告 ─────────────────────────────────────────────────────
if [ "$START_STAGE" -le 7 ]; then
  run_e2e_stage 7 verify bash "${SCRIPT_DIR}/stages/07-verify.sh"
fi

if [ "$WITH_PLUGIN_JOURNEYS" -eq 1 ]; then
  run_e2e_stage 8 plugin-journeys bash "${SCRIPT_DIR}/stages/08-plugin-journeys.sh"
fi

if [ "$WITH_JOURNEYS" -eq 1 ]; then
  echo ""
  info "Running appended journey-three-system lane..."
  JOURNEY_SKIP_EXIT_CODE="${WPTSALL_JOURNEY_SKIP_EXIT_CODE:-42}"
  set +e
  bash "${SCRIPT_DIR}/run-playwright-journey-three-system.sh" --legacy-only
  JOURNEY_EXIT_CODE=$?
  set -e
  if [ "$JOURNEY_EXIT_CODE" -eq "$JOURNEY_SKIP_EXIT_CODE" ]; then
    warn "Journey lane skipped by environment policy (exit=${JOURNEY_EXIT_CODE})"
  elif [ "$JOURNEY_EXIT_CODE" -ne 0 ]; then
    exit "$JOURNEY_EXIT_CODE"
  fi
fi

# ── 总结 ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
TOTAL_END=$(date +%s)
TOTAL_ELAPSED=$(( TOTAL_END - TOTAL_START ))
echo -e "${BOLD}  E2E v2 completed in ${TOTAL_ELAPSED}s${NC}"

# 查看最新报告
LATEST_REPORT=""
if latest_report_list="$(ls -t "${REPORTS_DIR}"/e2e-v2-*.json 2>/dev/null)"; then
  LATEST_REPORT="${latest_report_list%%$'\n'*}"
fi
if [ -n "$LATEST_REPORT" ]; then
  echo -e "  Report: ${LATEST_REPORT}"
fi
echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
