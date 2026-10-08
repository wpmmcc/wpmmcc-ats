#!/usr/bin/env bash
# Manual subsite translation gate (2026-09-09 audit gap ②).
#
# The manual matrix deliberately covers a single virtual site; manual
# translation against a wp-type relation (real subsite target) had zero
# coverage. This gate closes that gap WP-only, fail-closed against the
# client/mock/control plane (same manual-isolation lifecycle as the other
# manual gates; no client, no worker, no provider is started):
#
#   - T1 topology ensured (multisite + active wp relation, idempotent)
#   - REST manual-translations save against the wp relation
#     (php/manual-subsite-writeback.php: subsite writeback, identity meta,
#     post_mappings row, no-task negative control)
#   - subsite front-end HTTP 200 for the manually translated permalink
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-manual-subsite-translation-gate.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib/manual-isolation.sh"

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
WP_BASE="${WP_URL:-http://127.0.0.1:9083}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RAW_REPORT="${REPORTS_DIR}/manual-subsite-translation-gate-${STAMP}.raw.log"
FINAL_REPORT="${REPORTS_DIR}/manual-subsite-translation-gate-${STAMP}.json"
mkdir -p "${REPORTS_DIR}"

echo "== Manual subsite translation gate =="
echo "WP_BASE=${WP_BASE}"
echo "No client / no worker / no provider (WP-only manual surface)"

FAIL=0

add_check() {
  local name="$1" ok_flag="$2" detail="$3"
  if [[ "$ok_flag" == "1" ]]; then
    echo "✅ ${name}: ${detail}"
  else
    echo "❌ ${name}: ${detail}" >&2
    FAIL=$((FAIL + 1))
  fi
}

# --- P0-EV-01: observe runtime isolation for the whole gate window ---------
if ! manual_isolation_preflight; then
  echo "❌ Manual gate precondition failed: forbidden client/mock/8977/9090/8787 infrastructure is already running" >&2
  exit 1
fi
manual_isolation_begin

# --- T1 topology: ensure multisite + active wp relation (idempotent) --------
ms_state="$(wp_cli eval 'echo is_multisite() ? "1" : "0";' 2>/dev/null | tr -d '[:space:]')"
if [[ "${ms_state}" != "1" ]]; then
  if bash "${SCRIPT_DIR}/scripts/lab-enable-multisite-t1.sh" >"${REPORTS_DIR}/manual-subsite-t1-enable-${STAMP}.log" 2>&1; then
    add_check "t1_multisite_ready" 1 "enabled by lab-enable-multisite-t1.sh"
  else
    add_check "t1_multisite_ready" 0 "lab-enable-multisite-t1.sh failed (see manual-subsite-t1-enable-${STAMP}.log)"
    manual_isolation_finish || true
    exit 1
  fi
else
  add_check "t1_multisite_ready" 1 "multisite already enabled"
fi

wp_rel_count="$(wp_cli eval 'global $wpdb; $t = wptsall_table("site_relations"); echo (string) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE target_site_type = \"wp\" AND status = \"active\"");' 2>/dev/null | tr -d '[:space:]')"
if [[ -z "${wp_rel_count}" || "${wp_rel_count}" == "0" ]]; then
  if ! wp_eval "${E2E_DIR}/php/setup-relations.php" >"${REPORTS_DIR}/manual-subsite-setup-relations-${STAMP}.log" 2>&1; then
    echo "setup-relations.php exited non-zero (see manual-subsite-setup-relations-${STAMP}.log)" >&2
  fi
  wp_rel_count="$(wp_cli eval 'global $wpdb; $t = wptsall_table("site_relations"); echo (string) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE target_site_type = \"wp\" AND status = \"active\"");' 2>/dev/null | tr -d '[:space:]')"
fi
if [[ -n "${wp_rel_count}" && "${wp_rel_count}" != "0" ]]; then
  add_check "t1_wp_relation_ready" 1 "active wp relations: ${wp_rel_count}"
else
  add_check "t1_wp_relation_ready" 0 "no active wp relation after setup-relations.php"
  manual_isolation_finish || true
  exit 1
fi

# --- Manual save against the wp relation + WP-side assertions --------------
set +e
wp_eval "${E2E_DIR}/php/manual-subsite-writeback.php" 2>&1 | tee "${RAW_REPORT}"
WP_RC=${PIPESTATUS[0]}
set -e

payload_json="$(grep '^MANUAL-SUBSITE-JSON: ' "${RAW_REPORT}" | tail -1 | cut -d' ' -f2- || true)"
if [[ "${WP_RC}" -ne 0 || -z "${payload_json}" ]] || ! echo "${payload_json}" | jq -e '(.failed // 1) == 0 and (.passed // 0) > 0' >/dev/null 2>&1; then
  add_check "manual_subsite_writeback" 0 \
    "$(grep -E '^(FAIL|MANUAL-SUBSITE-FAIL):' "${RAW_REPORT}" | head -3 | tr '\n' ' ' || tail -3 "${RAW_REPORT}" | tr '\n' ' ')"
else
  add_check "manual_subsite_writeback" 1 \
    "$(echo "${payload_json}" | jq -r '"\(.passed) checks passed: target #\(.target_post_id) on blog \(.blog_id) (relation #\(.relation_id))"')"
fi

# --- Subsite front-end HTTP check for the manually translated permalink ----
if [[ -n "${payload_json}" ]]; then
  permalink="$(echo "${payload_json}" | jq -r '.permalink // empty')"
  if [[ -n "${permalink}" ]]; then
    code="$(http_status "${permalink}" 30 || echo ERR)"
    if [[ "${code}" =~ ^2 ]]; then
      add_check "subsite_front_http_200" 1 "${permalink} → ${code}"
    else
      add_check "subsite_front_http_200" 0 "${permalink} → ${code}"
    fi
  else
    add_check "subsite_front_http_200" 0 "no permalink in payload (target missing on subsite?)"
  fi
else
  add_check "subsite_front_http_200" 0 "no payload (writeback stage failed)"
fi

# --- Finish isolation observation and write the final report ---------------
GATE_FAILED=0
manual_isolation_finish
if ! manual_isolation_assert; then
  echo "❌ Manual-gate isolation violations detected (see [manual-isolation] output above)" >&2
  GATE_FAILED=1
fi

{
  echo "{"
  echo "  \"gate\": \"run-manual-subsite-translation-gate\","
  echo "  \"stamp\": \"${STAMP}\","
  echo "  \"wp_base\": \"${WP_BASE}\","
  echo "  \"mode\": \"manual_only\","
  echo "  \"gate_failed\": ${GATE_FAILED},"
  echo "  \"wp_cli_exit\": ${WP_RC},"
  echo "  \"payload\": ${payload_json:-null},"
  echo "  \"raw_report\": \"${RAW_REPORT}\""
  echo "}"
} >"${FINAL_REPORT}"

if [[ "${FAIL}" -gt 0 || "${GATE_FAILED}" -ne 0 || "${WP_RC}" -ne 0 ]]; then
  echo "❌ Manual subsite translation gate failed: ${FINAL_REPORT}" >&2
  exit 1
fi

echo "✅ Manual subsite translation gate passed: ${FINAL_REPORT}"
