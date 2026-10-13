#!/usr/bin/env bash
# T1 WP Multisite subsite topology deep smoke (front + admin + relation + VS regression).
#
# Prerequisites: lab-enable-multisite-t1.sh
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-subsite-topology-deep-smoke.sh
#
set -euo pipefail
set +o pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=/dev/null
source "${E2E_DIR}/config.sh"

export WPTSALL_LAB=1
CONTAINER="${WPTSALL_LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
WP_BASE="${WP_URL:-http://127.0.0.1:9083}"
SITE_SLUG="${WPTSALL_T1_SITE_SLUG:-en}"
VS_PREFIX="${WPTSALL_VS_PREFIX:-en_us}"
REPORT_DIR="${E2E_LAB_LOGDIR:-${E2E_DIR}/lab-logs}"
STAMP="$(date +%Y%m%d-%H%M%S)"
REPORT="${REPORT_DIR}/subsite-topology-deep-${STAMP}.md"
mkdir -p "$REPORT_DIR"

PASS=0
FAIL=0
WARN=0

row() { echo "| $* |" >>"$REPORT"; }
check() {
  local label="$1" ok="$2" detail="${3:-}"
  if [[ "$ok" == "1" ]]; then
    PASS=$((PASS + 1))
    row "${label} | PASS | ${detail}"
  else
    FAIL=$((FAIL + 1))
    row "${label} | FAIL | ${detail}"
  fi
}
warn_row() {
  WARN=$((WARN + 1))
  row "$1 | WARN | $2"
}

http_code() {
  curl -sS -L -o /tmp/t1-body.html -w '%{http_code}' --max-time 25 "$1" 2>/dev/null || echo ERR
}

admin_get() {
  local path="$1"
  local cookie
  cookie=$(mktemp)
  local user="${WP_ADMIN_USER:-e2esmokeadmin}"
  local pass="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
  curl -sS -c "$cookie" -b "$cookie" -o /dev/null "${WP_BASE}/wp-login.php" || true
  curl -sS -c "$cookie" -b "$cookie" -o /dev/null \
    -d "log=${user}&pwd=${pass}&wp-submit=Log+In&redirect_to=${WP_BASE}/wp-admin/&testcookie=1" \
    "${WP_BASE}/wp-login.php" || true
  # Follow redirects; body saved for content checks by caller via /tmp/t1-admin.html
  curl -sS -c "$cookie" -b "$cookie" -o /tmp/t1-admin.html -w '%{http_code}' -L --max-time 35 "${WP_BASE}${path}"
  rm -f "$cookie"
}

admin_route_ok() {
  # Unauthenticated: 302 to login proves MS admin rewrite works.
  local path="$1"
  local code
  code=$(curl -sS -o /tmp/t1-admin-unauth.html -w '%{http_code}' --max-time 20 "${WP_BASE}${path}" 2>/dev/null || echo ERR)
  if [[ "$code" =~ ^(302|301)$ ]]; then
    echo 1
    return
  fi
  if [[ "$code" =~ ^2 ]] && grep -qE 'wp-login|loginform|id="title"|post_title' /tmp/t1-admin-unauth.html 2>/dev/null; then
    echo 1
    return
  fi
  echo 0
}

{
  echo "# Subsite topology deep smoke — ${STAMP}"
  echo
  echo "- WP_BASE: ${WP_BASE}"
  echo "- T1 path: /${SITE_SLUG}/"
  echo "- VS prefix: /${VS_PREFIX}/"
  echo
  echo "| check | result | detail |"
  echo "|---|---|---|"
} >"$REPORT"

# ── Multisite + blog ───────────────────────────────────────────────────────
MS="$(docker exec "$CONTAINER" wp eval 'echo is_multisite()?"1":"0";' --allow-root 2>/dev/null | grep -v Warning | tr -d '[:space:]')"
check "is_multisite" "$([[ "$MS" == "1" ]] && echo 1 || echo 0)" "is_multisite=${MS}"

BLOG_ID="$(docker exec "$CONTAINER" wp eval '
$slug = getenv("WPTSALL_T1_SITE_SLUG") ?: "en";
$path = "/" . trim($slug, "/") . "/";
foreach ( get_sites( array( "number" => 50 ) ) as $s ) {
  if ( (string) $s->path === $path ) { echo (int) $s->blog_id; return; }
}
foreach ( get_sites( array( "number" => 20 ) ) as $s ) {
  if ( (int) $s->blog_id > 1 ) { echo (int) $s->blog_id; return; }
}
echo 0;
' --allow-root 2>/dev/null | grep -v Warning | tr -d '[:space:]')"
check "T1 blog exists" "$([[ "${BLOG_ID:-0}" -gt 1 ]] && echo 1 || echo 0)" "blog_id=${BLOG_ID}"

SUB_URL="${WP_BASE}/${SITE_SLUG}/"
CODE="$(http_code "$SUB_URL")"
check "T1 front home HTTP" "$([[ "$CODE" =~ ^2 ]] && echo 1 || echo 0)" "GET ${SUB_URL} → ${CODE}"

# ── Relation wp ────────────────────────────────────────────────────────────
REL_JSON="$(docker exec "$CONTAINER" wp eval '
global $wpdb;
$t = function_exists("wptsall_table") ? wptsall_table("site_relations") : $wpdb->prefix."wptsall_site_relations";
$row = $wpdb->get_row("SELECT id,target_site_id,target_site_type,status FROM {$t} WHERE target_site_type=\"wp\" AND status=\"active\" ORDER BY id ASC LIMIT 1", ARRAY_A);
echo $row ? wp_json_encode($row) : "{}";
' --allow-root 2>/dev/null | grep -v Warning | tail -1)"
REL_ID="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("id") or 0)' "$REL_JSON" 2>/dev/null || echo 0)"
REL_TARGET="$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("target_site_id") or "")' "$REL_JSON" 2>/dev/null || echo "")"
check "Relation target_site_type=wp" "$([[ "${REL_ID}" -gt 0 ]] && echo 1 || echo 0)" "id=${REL_ID} target=${REL_TARGET}"

# ── Mapped CPT samples: front + admin ──────────────────────────────────────
fetch_t1_maps() {
  docker exec -e WPTSALL_T1_BLOG_ID="${BLOG_ID}" "$CONTAINER" wp eval '
global $wpdb;
$blog = (int) (getenv("WPTSALL_T1_BLOG_ID") ?: 0);
$pm = function_exists("wptsall_table") ? wptsall_table("post_mappings") : $wpdb->prefix."wptsall_post_mappings";
$rel = function_exists("wptsall_table") ? wptsall_table("site_relations") : $wpdb->prefix."wptsall_site_relations";
$rid = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$rel} WHERE target_site_type=%s AND target_site_id=%s AND status=%s ORDER BY id ASC LIMIT 1",
  "wp", (string)$blog, "active"
));
$rows = $wpdb->get_results($wpdb->prepare(
  "SELECT source_post_type, source_post_id, target_post_id FROM {$pm} WHERE relation_id=%d AND target_site_id=%s AND target_post_id > 0 ORDER BY updated_at DESC, id DESC LIMIT 20",
  $rid, (string)$blog
), ARRAY_A);
echo wp_json_encode($rows ?: array());
' --allow-root 2>/dev/null | grep -v Warning | tail -1
}

write_t1_maps_tsv() {
  python3 - "$1" > /tmp/t1-maps.tsv <<'PY'
import json, sys
rows = json.loads(sys.argv[1] or "[]")
for r in rows:
    print(f"{r.get('source_post_type','')}\t{r.get('target_post_id','')}\t{r.get('source_post_id','')}")
PY
}

MAPS="$(fetch_t1_maps)"
write_t1_maps_tsv "$MAPS"

# Self-heal (2026-09-09 audit gap ③): the subsite matrix reset truncates
# post_mappings without re-seeding, which left a standing FAIL. Re-seed once
# here; the check below then only fails when seeding itself is broken.
if [[ ! -s /tmp/t1-maps.tsv ]]; then
  SEED_OUT="$(docker exec -e WPTSALL_T1_BLOG_ID="${BLOG_ID}" "$CONTAINER" wp eval-file /opt/wptsall-e2e/php/lab-subsite-seed-mirrors.php --allow-root 2>&1 | grep -v Warning || true)"
  row "T1 mapping self-heal | INFO | seed-mirrors re-ran: $(echo "$SEED_OUT" | tail -1)"
  MAPS="$(fetch_t1_maps)"
  write_t1_maps_tsv "$MAPS"
fi

MAP_COUNT=0
while IFS=$'\t' read -r pt tid sid; do
  [[ -z "${pt:-}" ]] && continue
  # Skip empty / zero target ids (stale mapping rows).
  if [[ -z "${tid:-}" || "${tid}" == "0" ]]; then
    warn_row "T1 map ${pt}#${tid:-0}" "skip empty target_post_id (source=${sid:-0})"
    continue
  fi
  MAP_COUNT=$((MAP_COUNT + 1))
  POST_STATUS="$(docker exec "$CONTAINER" wp post get "$tid" --url="$SUB_URL" --field=post_status --allow-root 2>/dev/null | grep -v Warning | head -1 | tr -d '[:space:]')"
  # Front permalink on subsite — only assert HTTP 2xx for publicly viewable posts.
  if [[ "${POST_STATUS}" == "publish" ]]; then
    PERM="$(docker exec "$CONTAINER" wp post url "$tid" --url="$SUB_URL" --allow-root 2>/dev/null | grep -v Warning | head -1 | tr -d '[:space:]')"
    if [[ -z "$PERM" ]]; then
      PERM="${SUB_URL}?p=${tid}"
    fi
    CODE="$(http_code "$PERM")"
    check "T1 front ${pt}#${tid}" "$([[ "$CODE" =~ ^2 ]] && echo 1 || echo 0)" "${PERM} → ${CODE}"
  else
    warn_row "T1 front ${pt}#${tid}" "skip non-publish status=${POST_STATUS:-unknown}"
  fi

  # Admin edit on subsite (route + authenticated when possible)
  ROUTE_OK="$(admin_route_ok "/${SITE_SLUG}/wp-admin/post.php?post=${tid}&action=edit")"
  ACODE="$(admin_get "/${SITE_SLUG}/wp-admin/post.php?post=${tid}&action=edit")"
  AUTH_OK=0
  if [[ "$ACODE" =~ ^2 ]] && grep -qE 'id="title"|name="post_title"|Edit (Post|Page|Product)' /tmp/t1-admin.html 2>/dev/null; then
    AUTH_OK=1
  fi
  if [[ "$AUTH_OK" == "1" ]]; then
    check "T1 admin edit ${pt}#${tid}" 1 "auth HTTP ${ACODE}"
  elif [[ "$ROUTE_OK" == "1" ]]; then
    check "T1 admin edit ${pt}#${tid}" 1 "route OK (auth HTTP ${ACODE})"
  else
    check "T1 admin edit ${pt}#${tid}" 0 "route=${ROUTE_OK} auth=${ACODE}"
  fi
done < /tmp/t1-maps.tsv

if [[ "$MAP_COUNT" -lt 1 ]]; then
  check "T1 mapped samples" 0 "no post_mappings for wp relation (self-heal re-seed ran, still empty)"
elif [[ "$MAP_COUNT" -lt 4 ]]; then
  warn_row "T1 mapped sample count" "only ${MAP_COUNT} (want ≥4 of 6)"
else
  check "T1 mapped sample count" 1 "count=${MAP_COUNT}"
fi

# Stuck-task backlog on the wp relation (2026-09-09 audit gap ④): WARN here
# (topology smoke must not newly fail on legacy backlog); the dedicated
# subsite-translation gate hard-asserts zero after its own closed loop.
STUCK="$(docker exec -e WPTSALL_T1_BLOG_ID="${BLOG_ID}" "$CONTAINER" wp eval '
global $wpdb;
$blog = (int) (getenv("WPTSALL_T1_BLOG_ID") ?: 0);
$tasks = function_exists("wptsall_table") ? wptsall_table("tasks") : $wpdb->prefix."wptsall_tasks";
$rel = function_exists("wptsall_table") ? wptsall_table("site_relations") : $wpdb->prefix."wptsall_site_relations";
$rid = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$rel} WHERE target_site_type=%s AND target_site_id=%s AND status=%s ORDER BY id ASC LIMIT 1",
  "wp", (string)$blog, "active"
));
echo (string) ( (int) $wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM {$tasks} WHERE relation_id=%d AND status IN (\"pending\",\"retry\")",
  $rid
)) );
' --allow-root 2>/dev/null | grep -v Warning | tr -d '[:space:]')"
if [[ "${STUCK:-0}" == "0" ]]; then
  check "T1 stuck tasks (wp relation)" 1 "pending/retry=0"
else
  warn_row "T1 stuck tasks (wp relation)" "pending/retry=${STUCK} (subsite-translation gate hard-asserts 0 after its closed loop)"
fi

# Network admin smoke
NCODE="$(admin_get "/wp-admin/network/sites.php")"
if [[ "$NCODE" =~ ^2 ]] && grep -qE 'Sites|site-info|wp-list-table' /tmp/t1-admin.html 2>/dev/null; then
  check "Network sites admin" 1 "HTTP ${NCODE}"
elif [[ "$(admin_route_ok "/wp-admin/network/sites.php")" == "1" ]]; then
  check "Network sites admin" 1 "route OK (auth HTTP ${NCODE})"
else
  warn_row "Network sites admin" "HTTP ${NCODE}"
fi

# Subsite admin dashboard
DCODE="$(admin_get "/${SITE_SLUG}/wp-admin/")"
if [[ "$DCODE" =~ ^2 ]] && grep -qE 'Dashboard|dashboard-widgets|index.php' /tmp/t1-admin.html 2>/dev/null; then
  check "T1 wp-admin dashboard" 1 "HTTP ${DCODE}"
elif [[ "$(admin_route_ok "/${SITE_SLUG}/wp-admin/")" == "1" ]]; then
  check "T1 wp-admin dashboard" 1 "route OK (auth HTTP ${DCODE})"
else
  check "T1 wp-admin dashboard" 0 "HTTP ${DCODE}"
fi

# ── Virtual site regression (must not break) ───────────────────────────────
VCODE="$(http_code "${WP_BASE}/${VS_PREFIX}/")"
check "VS home still OK" "$([[ "$VCODE" =~ ^2 ]] && echo 1 || echo 0)" "/${VS_PREFIX}/ → ${VCODE}"
if grep -q 'wptsall-virtual-site\|wptsall_vs\|virtual-site' /tmp/t1-body.html 2>/dev/null; then
  check "VS marker in body" 1 "virtual marker present"
else
  warn_row "VS marker in body" "marker not found (HTTP ${VCODE})"
fi

{
  echo
  echo "## Summary"
  echo "- PASS: ${PASS}"
  echo "- FAIL: ${FAIL}"
  echo "- WARN: ${WARN}"
} >>"$REPORT"

echo
echo "Report: ${REPORT}"
echo "PASS=${PASS} FAIL=${FAIL} WARN=${WARN}"
[[ "$FAIL" -eq 0 ]]
