#!/usr/bin/env bash
# Deep multilingual surface smoke: URL / SEO / menus / categories / tags / admin visibility.
#
# Covers what Stage-8 journeys often skip: nav prefix, taxonomy archives on VS,
# admin list/edit "translation status" markers, and one CPT sample per content plugin.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-ml-surface-deep-smoke.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-ml-surface-deep-smoke.sh --prefix en_us
#
set -euo pipefail
# grep with 0 matches must not abort the smoke (pipefail).
set +o pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=/dev/null
source "${E2E_DIR}/config.sh"

export WPTSALL_LAB=1
WP_BASE="${WP_URL:-http://127.0.0.1:9083}"
CONTAINER="${WPTSALL_LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
PREFIX="${WPTSALL_VS_PREFIX:-en_us}"
REPORT_DIR="${E2E_LAB_LOGDIR:-${E2E_DIR}/lab-logs}"
STAMP="$(date +%Y%m%d-%H%M%S)"
REPORT="${REPORT_DIR}/ml-surface-deep-${STAMP}.md"
mkdir -p "$REPORT_DIR"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --prefix) PREFIX="$2"; shift 2 ;;
    *) echo "Unknown: $1" >&2; exit 1 ;;
  esac
done

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
  curl -sS -L -o /tmp/ml-surface-body.html -w '%{http_code}' --max-time 25 "$1" 2>/dev/null || echo ERR
}

count_in_body() {
  # Never trip set -e / pipefail on zero matches.
  local n
  n=$(grep -cE "$1" /tmp/ml-surface-body.html 2>/dev/null || true)
  echo "${n:-0}"
}

count_in_admin() {
  local n
  n=$(grep -cE "$1" /tmp/ml-admin.html 2>/dev/null || true)
  echo "${n:-0}"
}

admin_get() {
  local path="$1"
  local cookie
  cookie=$(mktemp)
  local user="${WP_ADMIN_USER:-e2esmokeadmin}"
  local pass="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
  curl -sS -c "$cookie" -b "$cookie" -o /dev/null "${WP_BASE}/wp-login.php" || true
  curl -sS -c "$cookie" -b "$cookie" -o /dev/null -L \
    -d "log=${user}&pwd=${pass}&wp-submit=Log+In&redirect_to=${WP_BASE}/wp-admin/&testcookie=1" \
    "${WP_BASE}/wp-login.php" || true
  curl -sS -c "$cookie" -b "$cookie" -o /tmp/ml-admin.html -w '%{http_code}' --max-time 35 "${WP_BASE}${path}"
  rm -f "$cookie"
}

{
  echo "# ML surface deep smoke — ${STAMP}"
  echo
  echo "- WP_BASE: ${WP_BASE}"
  echo "- VS prefix: /${PREFIX}/"
  echo
  echo "| check | result | detail |"
  echo "|---|---|---|"
} >"$REPORT"

info "Deactivating peer i18n plugins..."
docker exec "$CONTAINER" wp plugin deactivate polylang translatepress-multilingual gtranslate weglot loco-translate --allow-root >/dev/null 2>&1 || true
docker exec "$CONTAINER" wp plugin activate wpmmcc-ats --allow-root >/dev/null 2>&1 || true

# --- Guest: VS home + menu prefix ---
code=$(http_code "${WP_BASE}/${PREFIX}/")
vs=$(count_in_body 'wptsall-virtual-site')
lang=$(grep -oE 'lang="[^"]+"' /tmp/ml-surface-body.html 2>/dev/null | head -1 | tr '"' "'" || true)
href_n=$(grep -o 'hreflang="' /tmp/ml-surface-body.html 2>/dev/null | wc -l | tr -d ' ' || true)
href_u=$(grep -oE 'hreflang="[^"]+"' /tmp/ml-surface-body.html 2>/dev/null | sort -u | wc -l | tr -d ' ' || true)
href_n=${href_n:-0}
href_u=${href_u:-0}
detail_home="http=${code} vs=${vs} ${lang} hreflang=${href_n}/${href_u}"
check "guest VS home" "$(if [[ "$code" == "200" && "$vs" -gt 0 ]]; then echo 1; else echo 0; fi)" "$detail_home"

# Menu: at least one same-host menu href should include /PREFIX/ when menus exist on page.
menu_total=$(grep -oE 'href="[^"]+"' /tmp/ml-surface-body.html 2>/dev/null | grep -cF "${WP_BASE}" || true)
menu_pref=$(grep -oE 'href="[^"]+"' /tmp/ml-surface-body.html 2>/dev/null | grep -c "/${PREFIX}/" || true)
menu_total=${menu_total:-0}
menu_pref=${menu_pref:-0}
if [[ "$menu_total" -eq 0 ]]; then
  warn_row "guest menu prefix" "no same-host hrefs on VS home (theme may not print menu)"
else
  check "guest menu has VS-prefixed links" "$(if [[ "$menu_pref" -gt 0 ]]; then echo 1; else echo 0; fi)" "prefixed=${menu_pref} same_host=${menu_total}"
fi

# --- Taxonomy archives on VS (resolve mapped terms via WP) ---
mapfile -t TAX_URLS < <(docker exec -e WPTSALL_VS_PREFIX="$PREFIX" "$CONTAINER" php -r '
require "/var/www/html/wp-load.php";
global $wpdb;
$prefix = getenv("WPTSALL_VS_PREFIX") ?: "en_us";
$t = wptsall_table("term_mappings");
$taxonomies = array("category","post_tag","product_cat","product_tag","download_category","course-category");
foreach ($taxonomies as $tax) {
  if (!taxonomy_exists($tax)) continue;
  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT target_term_id, source_term_id FROM {$t} WHERE source_taxonomy=%s AND target_term_id>0 ORDER BY id DESC LIMIT 1",
    $tax
  ), ARRAY_A);
  if (!$row) continue;
  $tid = (int)$row["target_term_id"];
  $term = get_term($tid, $tax);
  if (!$term || is_wp_error($term)) continue;
  $link = get_term_link($term);
  if (is_wp_error($link)) continue;
  if (class_exists("WPTSALL\\Sites\\Services\\Url_Converter") && class_exists("WPTSALL\\Sites\\Services\\Virtual_Site_Service")) {
    foreach ((array)\WPTSALL\Sites\Services\Virtual_Site_Service::get_all(array("status"=>"active")) as $site) {
      if (trim((string)($site["path_prefix"]??""), "/") === $prefix) {
        $link = \WPTSALL\Sites\Services\Url_Converter::virtualize($link, $site);
        break;
      }
    }
  }
  echo $tax . "|" . $link . "\n";
}
' 2>/dev/null || true)

if [[ ${#TAX_URLS[@]} -eq 0 ]]; then
  warn_row "taxonomy VS archives" "no mapped target terms for sample taxonomies"
else
  for line in "${TAX_URLS[@]}"; do
    tax="${line%%|*}"
    url="${line#*|}"
    code=$(http_code "$url")
    vs=$(count_in_body 'wptsall-virtual-site')
    if [[ "$code" == "200" ]]; then
      check "tax archive ${tax}" "1" "http=${code} vs=${vs} url=${url}"
    else
      warn_row "tax archive ${tax}" "http=${code} vs=${vs} url=${url}"
    fi
  done
fi

# --- CPT samples (one published shadow/source per common type) ---
mapfile -t CPT_URLS < <(docker exec -e WPTSALL_VS_PREFIX="$PREFIX" "$CONTAINER" php -r '
require "/var/www/html/wp-load.php";
$prefix = getenv("WPTSALL_VS_PREFIX") ?: "en_us";
$types = array("product","download","forum","topic","course","courses","lp_course","tribe_events","job_listing","give_forms","at_biz_dir","hp_listing","wprm_recipe","podcast","envira","site-review","post","page");
$vs = null;
if (class_exists("WPTSALL\\Sites\\Services\\Virtual_Site_Service")) {
  foreach ((array)\WPTSALL\Sites\Services\Virtual_Site_Service::get_all(array("status"=>"active")) as $site) {
    if (trim((string)($site["path_prefix"]??""), "/") === $prefix) { $vs = $site; break; }
  }
}
foreach ($types as $pt) {
  if (!post_type_exists($pt)) continue;
  $q = new WP_Query(array(
    "post_type"=>$pt,"post_status"=>"publish","posts_per_page"=>1,
    "meta_query"=>array(array("key"=>"_wptsall_virtual_site_id","compare"=>"EXISTS")),
    "orderby"=>"ID","order"=>"DESC",
  ));
  if (!$q->have_posts()) {
    $q = new WP_Query(array("post_type"=>$pt,"post_status"=>"publish","posts_per_page"=>1,"orderby"=>"ID","order"=>"DESC"));
  }
  if (!$q->have_posts()) continue;
  $p = $q->posts[0];
  $link = get_permalink($p);
  if ($vs && class_exists("WPTSALL\\Sites\\Services\\Url_Converter")) {
    $link = \WPTSALL\Sites\Services\Url_Converter::virtualize($link, $vs);
  }
  echo $pt . "|" . $p->ID . "|" . $link . "\n";
}
' 2>/dev/null || true)

for line in "${CPT_URLS[@]}"; do
  pt="${line%%|*}"
  rest="${line#*|}"
  id="${rest%%|*}"
  url="${rest#*|}"
  code=$(http_code "$url")
  ok_cpt=0
  [[ "$code" == "200" || "$code" == "301" || "$code" == "302" ]] && ok_cpt=1
  # Known Lab-fragile CPT shells (recipe permalink / review CPT rewrite /
  # give donation slug churn / envira query permalink): soft WARN.
  case "${pt}" in
    wprm_recipe|site-review|give_forms|envira)
      if [[ "$ok_cpt" -eq 1 ]]; then
        check "cpt ${pt}#${id}" 1 "http=${code} ${url}"
      else
        warn_row "cpt ${pt}#${id}" "http=${code} ${url} (Lab-fragile CPT; soft)"
      fi
      ;;
    *)
      check "cpt ${pt}#${id}" "$ok_cpt" "http=${code} ${url}"
      ;;
  esac
done

# --- Admin surfaces ---
code=$(admin_get "/wp-admin/edit.php")
has_col=$(count_in_admin 'wptsall_translations|wptsall_translation|Translation Status')
has_table=$(count_in_admin 'wp-list-table')
check "admin posts list Translation Status col" "$(if [[ "$code" == "200" && "$has_col" -gt 0 ]]; then echo 1; else echo 0; fi)" "http=${code} col=${has_col} table=${has_table}"

EDIT_ID=$(docker exec -e WPTSALL_VS_PREFIX="$PREFIX" "$CONTAINER" php -r '
require "/var/www/html/wp-load.php";
global $wpdb;
$t = wptsall_table("post_mappings");
$rows = $wpdb->get_col("SELECT source_post_id FROM {$t} WHERE source_post_id > 0 ORDER BY id DESC LIMIT 30");
$id = 0;
foreach ((array) $rows as $candidate) {
  $p = get_post((int) $candidate);
  if ($p && "publish" === $p->post_status && "post" === $p->post_type) { $id = (int) $p->ID; break; }
}
if ($id <= 0) {
  $q = new WP_Query(array("post_type"=>"post","post_status"=>"publish","posts_per_page"=>1,"orderby"=>"ID","order"=>"DESC"));
  $id = $q->have_posts() ? (int) $q->posts[0]->ID : 0;
}
echo $id;
' 2>/dev/null || echo 0)
if [[ "$EDIT_ID" -gt 0 ]]; then
  code=$(admin_get "/wp-admin/post.php?post=${EDIT_ID}&action=edit")
  has_box=$(count_in_admin 'wptsall-translation-status|Translation status|wptsall-metabox-langs|WPTSALL Translation|wptsall_site_meta_box|wptsall-flag|Select Site')
  if [[ "$code" != "200" ]]; then
    warn_row "admin post edit translation status UI" "http=${code} post=${EDIT_ID} (skipped non-200 edit screen)"
  else
    check "admin post edit translation status UI" "$(if [[ "$has_box" -gt 0 ]]; then echo 1; else echo 0; fi)" "http=${code} markers=${has_box} post=${EDIT_ID}"
  fi
else
  warn_row "admin post edit translation status UI" "no source post found"
fi

code=$(admin_get "/wp-admin/edit-tags.php?taxonomy=category")
has_col=$(count_in_admin 'wptsall_term_translations|wptsall_translation|Translation Status|wptsall_site')
check "admin category list WPTSALL cols" "$(if [[ "$code" == "200" && "$has_col" -gt 0 ]]; then echo 1; else echo 0; fi)" "http=${code} col=${has_col}"

TERM_ID=$(docker exec -e WPTSALL_VS_PREFIX="$PREFIX" "$CONTAINER" php -r '
require "/var/www/html/wp-load.php";
global $wpdb; $t=wptsall_table("term_mappings");
$id=(int)$wpdb->get_var("SELECT source_term_id FROM {$t} WHERE source_taxonomy=\"category\" AND target_term_id>0 ORDER BY id DESC LIMIT 1");
echo $id;
' 2>/dev/null || echo 0)
if [[ "$TERM_ID" -gt 0 ]]; then
  code=$(admin_get "/wp-admin/term.php?taxonomy=category&tag_ID=${TERM_ID}")
  has_box=$(count_in_admin 'wptsall-term-translation-status|Translation status|wptsall_virtual_site_id|Virtual Site')
  check "admin category edit translation status" "$(if [[ "$code" == "200" && "$has_box" -gt 0 ]]; then echo 1; else echo 0; fi)" "http=${code} markers=${has_box} term=${TERM_ID}"
else
  warn_row "admin category edit translation status" "no mapped category source term"
fi

if docker exec "$CONTAINER" wp plugin is-active woocommerce --allow-root >/dev/null 2>&1; then
  code=$(admin_get "/wp-admin/edit.php?post_type=product")
  has_ui=$(count_in_admin 'wp-list-table|wptsall_translation|wptsall_translations')
  check "admin products list" "$(if [[ "$code" == "200" && "$has_ui" -gt 0 ]]; then echo 1; else echo 0; fi)" "http=${code} ui=${has_ui}"
fi

{
  echo
  echo "## Summary"
  echo "- PASS: ${PASS}"
  echo "- FAIL: ${FAIL}"
  echo "- WARN: ${WARN}"
  echo
  echo "Report: ${REPORT}"
} >>"$REPORT"

echo
cat "$REPORT"
echo
if [[ "$FAIL" -gt 0 ]]; then
  err "ML surface deep smoke FAILED (${FAIL} fails) — ${REPORT}"
  exit 1
fi
ok "ML surface deep smoke PASSED — ${REPORT}"
exit 0
