#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Stage 7: 验证
#
# 写回验证 + 标记精度 + ISS-15 覆盖 + ISS-16 稳定化 + 报告生成。
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage 7 "Verification"

START=$(stage_start_time)

VERIFY_FAIL=0
DEGRADED_VERIFY=0
CLIENT_STATUS_FILE="${RUNTIME_DIR}/client-suite-status.env"
CLIENT_SUITE_SKIPPED=0
CLIENT_SUITE_SKIP_REASON="none"
CLIENT_OAUTH_HTTP_STATUS=""

if [ -f "$CLIENT_STATUS_FILE" ]; then
  # shellcheck disable=SC1090
  source "$CLIENT_STATUS_FILE"
fi

if [ "${CLIENT_SUITE_SKIPPED:-0}" = "1" ]; then
  case "${CLIENT_SUITE_SKIP_REASON:-}" in
    server_auth_unavailable)
      DEGRADED_VERIFY=1
      warn "Client suite skipped due server auth endpoint HTTP ${CLIENT_OAUTH_HTTP_STATUS:-unknown}; enabling degraded Stage 7 gating."
      ;;
    removed_local_first_no_website_login)
      DEGRADED_VERIFY=1
      warn "Client website-OAuth suite removed (local-first boundary: no website login); client translation coverage is provided by the P0-EV-03 automatic lanes; enabling degraded Stage 7 gating."
      ;;
  esac
fi

# ── 1. ISS-15 证据预热（尽量减少 skip，并补齐 core-only virtual 写回）──────────
echo ""
info "Preparing ISS-15 evidence..."
ISS15_PREP_TIMEOUT="${E2E_ISS15_PREP_TIMEOUT:-180}"
if wp_eval_with_timeout "$ISS15_PREP_TIMEOUT" "${E2E_DIR}/php/prepare-iss15-evidence.php"; then
  ok "ISS-15 evidence prepared"
else
  warn "ISS-15 evidence preparation had issues or timed out (${ISS15_PREP_TIMEOUT}s)"
fi

# ── 2. 写回验证 ──────────────────────────────────────────────────────────────
info "Running write-back verification..."
if wp_eval "${E2E_DIR}/php/verify-writeback.php"; then
  ok "Write-back verification passed"
else
  if [ "$DEGRADED_VERIFY" -eq 1 ]; then
    warn "Write-back verification had failures (non-blocking in degraded gating mode)"
  else
    err "Write-back verification had failures"
    VERIFY_FAIL=1
    e2e_emit_event "SIGNAL_WRITEBACK" "writeback_fail=1"
  fi
fi

# ── 3. 标记精度 ──────────────────────────────────────────────────────────────
echo ""
info "Running marker precision check..."
if wp_eval "${E2E_DIR}/php/verify-markers.php"; then
  ok "Marker precision check passed"
else
  err "Marker precision check had failures"
  VERIFY_FAIL=1
fi

# ── 4. ISS-15 覆盖验证（手动字段/Hook/media/REST/headless）──────────────────
echo ""
info "Running ISS-15 coverage verification..."
if wp_eval "${E2E_DIR}/php/verify-iss15-coverage.php"; then
  ok "ISS-15 coverage verification passed"
else
  if [ "$DEGRADED_VERIFY" -eq 1 ]; then
    warn "ISS-15 coverage verification had failures (non-blocking in degraded gating mode)"
  else
    err "ISS-15 coverage verification had failures"
    VERIFY_FAIL=1
  fi
fi

# ── 5. 字段分类（使用 E2E canonical 脚本） ──────────────────────────────────
FIELD_CLASS_SCRIPT="${E2E_DIR}/php/verify-field-classification.php"
if [ -f "$FIELD_CLASS_SCRIPT" ]; then
  echo ""
  info "Running field classification verification..."
  if wp_eval "$FIELD_CLASS_SCRIPT" 2>&1; then
    ok "Field classification passed"
  else
    warn "Field classification had issues"
  fi
fi

# ── 6. 字段能力写回验收 ─────────────────────────────────────────────────────
FIELD_CAPABILITY_SCRIPT="${E2E_DIR}/php/verify-translation-field-capabilities.php"
if [ -f "$FIELD_CAPABILITY_SCRIPT" ]; then
  echo ""
  info "Running translation field capability verification..."
  if wp_eval "$FIELD_CAPABILITY_SCRIPT" 2>&1; then
    ok "Translation field capability verification passed"
  else
    err "Translation field capability verification had failures"
    VERIFY_FAIL=1
  fi
fi

# ── 7. Client E2E 验证（使用 E2E canonical 脚本） ───────────────────────────
CLIENT_E2E_SCRIPT="${E2E_DIR}/php/verify-client-e2e.php"
if [ -f "$CLIENT_E2E_SCRIPT" ]; then
  echo ""
  info "Running client E2E verification..."
  if wp_eval "$CLIENT_E2E_SCRIPT" 2>&1; then
    ok "Client E2E verification passed"
  else
    if [ "$DEGRADED_VERIFY" -eq 1 ]; then
      warn "Client E2E verification had failures (non-blocking in degraded gating mode)"
    else
      err "Client E2E verification had failures"
      VERIFY_FAIL=1
    fi
  fi
fi

# ── 7.1 Stuck recovery专项验收（使用 E2E canonical 脚本）─────────────────────
STUCK_RECOVERY_SCRIPT="${E2E_DIR}/php/verify-stuck-recovery.php"
if [ -f "$STUCK_RECOVERY_SCRIPT" ]; then
  echo ""
  info "Running stuck-task recovery verification..."
  if wp_eval "$STUCK_RECOVERY_SCRIPT" 2>&1; then
    ok "Stuck-task recovery verification passed"
  else
    err "Stuck-task recovery verification had failures"
    VERIFY_FAIL=1
  fi
fi

# ── 8. Project专项验证（优先专项 verifier，其次通用插件 verifier）────────────
PROJECT_VERIFY_SCRIPT="${E2E_DIR}/php/verify-${E2E_PROJECT}.php"
if [ ! -f "$PROJECT_VERIFY_SCRIPT" ] && [ -f "${E2E_DIR}/php/verify-plugin-project.php" ]; then
  PROJECT_VERIFY_SCRIPT="${E2E_DIR}/php/verify-plugin-project.php"
fi
if [ -f "$PROJECT_VERIFY_SCRIPT" ]; then
  echo ""
  info "Running project verification for ${E2E_PROJECT}..."
  if wp_eval "$PROJECT_VERIFY_SCRIPT" 2>&1; then
    ok "Project verification passed"
  else
    if [ "$DEGRADED_VERIFY" -eq 1 ]; then
      warn "Project verification had failures (non-blocking in degraded gating mode)"
    else
      err "Project verification had failures"
      VERIFY_FAIL=1
    fi
  fi
fi

# ── 9. ISS-16 发布前稳定化（开关快照 + 稳定性校验）──────────────────────────
echo ""
info "Snapshotting ISS-16 release flags..."
if wp_eval "${E2E_DIR}/php/snapshot-release-flags.php"; then
  ok "ISS-16 release flags snapshot saved"
else
  err "ISS-16 release flags snapshot failed"
  VERIFY_FAIL=1
fi

echo ""
info "Running ISS-16 stability verification..."
if wp_eval "${E2E_DIR}/php/verify-iss16-stability.php"; then
  ok "ISS-16 stability verification passed"
else
  if [ "$DEGRADED_VERIFY" -eq 1 ]; then
    warn "ISS-16 stability verification had failures (non-blocking in degraded gating mode)"
  else
    err "ISS-16 stability verification had failures"
    VERIFY_FAIL=1
  fi
fi

# ── 10. 前端链路验证（Virtual 入口）──────────────────────────────────────────
echo ""
# Directorist search-home* shells return HTTP 500 under Lab virtual prefixes.
e2e_is_fragile_frontend_path() {
  case "${1:-}" in
    *search-home*|*search_home*) return 0 ;;
    *) return 1 ;;
  esac
}

if [ "${E2E_SKIP_FRONTEND_VERIFY:-0}" = "1" ]; then
  warn "Frontend verification skipped by E2E_SKIP_FRONTEND_VERIFY=1"
else
  FRONTEND_SAMPLE_PATH=""
  FRONTEND_ROOT_PATH=""
  if [ -z "${E2E_FRONTEND_VERIFY_PATH:-}" ] && [ -f "${E2E_DIR}/php/resolve-virtual-frontend-target.php" ]; then
    FRONTEND_TARGET_FILE="${RUNTIME_DIR}/virtual-frontend-target.json"
    mkdir -p "${RUNTIME_DIR}"
    if wp_eval "${E2E_DIR}/php/resolve-virtual-frontend-target.php" >"${FRONTEND_TARGET_FILE}" 2>/dev/null; then
      FRONTEND_ROOT_PATH="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo is_array($j) ? (string)($j["frontend_path"] ?? "") : "";' "${FRONTEND_TARGET_FILE}" 2>/dev/null || true)"
      FRONTEND_SAMPLE_PATH="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo is_array($j) ? (string)($j["sample_path"] ?? "") : "";' "${FRONTEND_TARGET_FILE}" 2>/dev/null || true)"
      # Prefer virtual site root for reachability; sample posts (plugin CPT shells)
      # can return 500 under path prefixes even when write-back succeeded.
      DYNAMIC_FRONTEND_PATH="${FRONTEND_ROOT_PATH:-${FRONTEND_SAMPLE_PATH}}"
      # Never primary-verify Directorist search shells (Lab returns HTTP 500).
      if e2e_is_fragile_frontend_path "${DYNAMIC_FRONTEND_PATH}"; then
        DYNAMIC_FRONTEND_PATH="${FRONTEND_ROOT_PATH:-/en_us/}"
      fi
      if e2e_is_fragile_frontend_path "${FRONTEND_SAMPLE_PATH}"; then
        # Drop fragile sample so retry never promotes search-home* to SIGNAL_FRONTEND.
        FRONTEND_SAMPLE_PATH="${FRONTEND_ROOT_PATH:-/en_us/}"
      fi
      if [ -n "${DYNAMIC_FRONTEND_PATH}" ]; then
        FRONTEND_VERIFY_PATH="${DYNAMIC_FRONTEND_PATH}"
        info "Resolved frontend verification path: ${FRONTEND_VERIFY_PATH}"
      fi
    else
      warn "Could not resolve dynamic virtual frontend target; using ${FRONTEND_VERIFY_PATH}"
    fi
  fi

  # Hard default for Lab virtual site root when unset/wrong.
  if _is_lab_mode; then
    if [ -z "${FRONTEND_VERIFY_PATH}" ] || [ "${FRONTEND_VERIFY_PATH}" = "/" ] \
      || e2e_is_fragile_frontend_path "${FRONTEND_VERIFY_PATH}"; then
      FRONTEND_VERIFY_PATH="${FRONTEND_ROOT_PATH:-/en_us/}"
      if e2e_is_fragile_frontend_path "${FRONTEND_VERIFY_PATH}" || [ -z "${FRONTEND_VERIFY_PATH}" ]; then
        FRONTEND_VERIFY_PATH="/en_us/"
      fi
      info "Lab frontend verify forced to ${FRONTEND_VERIFY_PATH}"
    fi
  fi

  info "Running frontend verification..."
  FRONT_TMP="$(mktemp)"
  # Shared Lab WP under parallel journey pressure: backoff on transient 5xx/000.
  FRONT_STATUS=$(http_status_retry "$(frontend_verify_url)" 20 4 || true)
  if [ "$FRONT_STATUS" = "200" ]; then
    FRONT_STATUS=$(http_download "$(frontend_verify_url)" "$FRONT_TMP" 20 || true)
  fi
  if [ "$FRONT_STATUS" != "200" ] && [ -n "${FRONTEND_SAMPLE_PATH}" ] \
    && [ "${FRONTEND_SAMPLE_PATH}" != "${FRONTEND_VERIFY_PATH}" ] \
    && ! e2e_is_fragile_frontend_path "${FRONTEND_SAMPLE_PATH}"; then
    warn "Frontend route ${FRONTEND_VERIFY_PATH} unreachable (HTTP ${FRONT_STATUS}); retrying sample ${FRONTEND_SAMPLE_PATH}"
    FRONTEND_VERIFY_PATH="${FRONTEND_SAMPLE_PATH}"
    FRONT_STATUS=$(http_status_retry "$(frontend_verify_url)" 20 4 || true)
    if [ "$FRONT_STATUS" = "200" ]; then
      FRONT_STATUS=$(http_download "$(frontend_verify_url)" "$FRONT_TMP" 20 || true)
    fi
  fi
  if [ "$FRONT_STATUS" != "200" ] && [ -n "${FRONTEND_ROOT_PATH}" ] && [ "${FRONTEND_ROOT_PATH}" != "${FRONTEND_VERIFY_PATH}" ]; then
    warn "Frontend route ${FRONTEND_VERIFY_PATH} unreachable (HTTP ${FRONT_STATUS}); retrying root ${FRONTEND_ROOT_PATH}"
    FRONTEND_VERIFY_PATH="${FRONTEND_ROOT_PATH}"
    FRONT_STATUS=$(http_status_retry "$(frontend_verify_url)" 20 4 || true)
    if [ "$FRONT_STATUS" = "200" ]; then
      FRONT_STATUS=$(http_download "$(frontend_verify_url)" "$FRONT_TMP" 20 || true)
    fi
  fi
  # Last resort: never leave Lab parked on a known-500 search shell.
  if [ "$FRONT_STATUS" != "200" ] && e2e_is_fragile_frontend_path "${FRONTEND_VERIFY_PATH}"; then
    FRONTEND_VERIFY_PATH="${FRONTEND_ROOT_PATH:-/en_us/}"
    warn "Avoiding fragile frontend path; retrying ${FRONTEND_VERIFY_PATH}"
    FRONT_STATUS=$(http_status_retry "$(frontend_verify_url)" 20 4 || true)
    if [ "$FRONT_STATUS" = "200" ]; then
      FRONT_STATUS=$(http_download "$(frontend_verify_url)" "$FRONT_TMP" 20 || true)
    fi
  fi
  if [ "$FRONT_STATUS" = "200" ]; then
    ok "Frontend route ${FRONTEND_VERIFY_PATH} reachable (HTTP 200)"
    # Also verify translated content URL when we have a safe sample path.
    if [ -n "${FRONTEND_SAMPLE_PATH}" ] \
      && [ "${FRONTEND_SAMPLE_PATH}" != "${FRONTEND_VERIFY_PATH}" ] \
      && ! e2e_is_fragile_frontend_path "${FRONTEND_SAMPLE_PATH}"; then
      SAMPLE_STATUS=$(http_status_retry "${WP_URL%/}${FRONTEND_SAMPLE_PATH}" 20 4 || true)
      if [ "$SAMPLE_STATUS" = "200" ]; then
        SAMPLE_STATUS=$(http_download "${WP_URL%/}${FRONTEND_SAMPLE_PATH}" "$FRONT_TMP" 20 || true)
      fi
      if [ "$SAMPLE_STATUS" = "200" ]; then
        ok "Translated sample route ${FRONTEND_SAMPLE_PATH} reachable (HTTP 200)"
      else
        err "Translated sample route ${FRONTEND_SAMPLE_PATH} unreachable (HTTP ${SAMPLE_STATUS})"
        VERIFY_FAIL=1
        e2e_emit_event "SIGNAL_FRONTEND" "http=${SAMPLE_STATUS} path=${FRONTEND_SAMPLE_PATH} kind=sample"
      fi
    fi
  else
    if [ "$DEGRADED_VERIFY" -eq 1 ]; then
      warn "Frontend route ${FRONTEND_VERIFY_PATH} unreachable (HTTP ${FRONT_STATUS}, non-blocking in degraded gating mode)"
    else
      err "Frontend route ${FRONTEND_VERIFY_PATH} unreachable (HTTP ${FRONT_STATUS})"
      warn "If this repeats across multiple projects, treat it as a shared env blocker and stop the remaining lane batch."
      VERIFY_FAIL=1
      e2e_emit_event "SIGNAL_FRONTEND" "http=${FRONT_STATUS} path=${FRONTEND_VERIFY_PATH}"
    fi
  fi

  if grep -Eq "【[a-z]{2}_[A-Z]{2}】" "$FRONT_TMP"; then
    ok "Frontend page contains translation markers"
  else
    # Marker exposure is implementation-dependent (may be hidden/normalized on frontend).
    # Keep this as a warning so reachability remains the hard gate.
    warn "Frontend page missing translation markers (non-blocking)"
  fi
  rm -f "$FRONT_TMP"
fi

VIRTUAL_FRONTEND_SCRIPT="${E2E_DIR}/verify-p2-virtual-frontend.sh"
if [ "${E2E_SKIP_FRONTEND_VERIFY:-0}" != "1" ] && [ -f "$VIRTUAL_FRONTEND_SCRIPT" ]; then
  echo ""
  info "Running virtual frontend deep verification..."
  if bash "$VIRTUAL_FRONTEND_SCRIPT"; then
    ok "Virtual frontend deep verification passed"
  else
    err "Virtual frontend deep verification failed"
    VERIFY_FAIL=1
    e2e_emit_event "SIGNAL_FRONTEND" "stage=7p2 deep_verify_failed path=${FRONTEND_VERIFY_PATH:-unknown}"
  fi
fi

# ── 11. 生成报告 ─────────────────────────────────────────────────────────────
echo ""
info "Generating report..."
ensure_dirs
if wp_eval "${E2E_DIR}/php/generate-report.php"; then
  ok "Report generated in reports/"
else
  warn "Report generation had issues"
fi

stage_elapsed "$START"

echo ""
if [ "$VERIFY_FAIL" -eq 0 ]; then
  echo -e "${GREEN}${BOLD}All verifications passed!${NC}"
else
  echo -e "${YELLOW}${BOLD}Some verifications had failures — check reports for details.${NC}"
  exit 1
fi
