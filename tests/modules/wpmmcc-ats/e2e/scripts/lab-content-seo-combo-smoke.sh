#!/usr/bin/env bash
# Content-plugin × SEO-plugin combo smoke (ONE content OR ONE SEO at a time + WPTSALL).
#
# Product positioning: support many content plugins and many SEO plugins, but
# users typically enable ONE content plugin + ONE SEO plugin alongside WPTSALL —
# never peer translation plugins, and not "all content plugins at once" as the
# correctness unit. This script exercises that contract.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-content-seo-combo-smoke.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-content-seo-combo-smoke.sh --content woocommerce,lifterlms
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-content-seo-combo-smoke.sh --seo wordpress-seo,autodescription
#
set -euo pipefail

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
REPORT="${REPORT_DIR}/combo-smoke-${STAMP}.md"
mkdir -p "$REPORT_DIR"

CONTENT_CSV="${COMBO_CONTENT_PLUGINS:-woocommerce,lifterlms,elementor,easy-digital-downloads,bbpress}"
SEO_CSV="${COMBO_SEO_PLUGINS:-wordpress-seo,autodescription}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --content) CONTENT_CSV="$2"; shift 2 ;;
    --seo) SEO_CSV="$2"; shift 2 ;;
    --prefix) PREFIX="$2"; shift 2 ;;
    *) echo "Unknown: $1" >&2; exit 1 ;;
  esac
done

wp_in() {
  docker exec "$CONTAINER" wp "$@" --allow-root 2>/dev/null
}

curl_check() {
  local label="$1" url="$2"
  local tmp code bytes hreflang_n lang vs docs
  tmp=$(mktemp)
  code=$(curl -sS -L -o "$tmp" -w '%{http_code}' --max-time 25 "$url" || echo ERR)
  bytes=$(wc -c <"$tmp" | tr -d ' ')
  # Avoid pipefail killing the smoke when grep finds zero matches.
  hreflang_n=$(grep -co 'hreflang="' "$tmp" 2>/dev/null || true)
  hreflang_n=${hreflang_n:-0}
  lang=$(grep -oE '<html[^>]*lang="[^"]*"' "$tmp" 2>/dev/null | head -1 || true)
  vs=$(grep -c 'wptsall-virtual-site' "$tmp" 2>/dev/null || true)
  vs=${vs:-0}
  docs=$(grep -cE '<!DOCTYPE html|<!doctype html' "$tmp" 2>/dev/null || true)
  docs=${docs:-0}
  local uniq
  uniq=$( { grep -oE 'hreflang="[^"]+"' "$tmp" 2>/dev/null || true; } | sort -u | wc -l | tr -d ' ')
  uniq=${uniq:-0}
  # When docs>1 (Lab Woo/theme quirk: concatenated full HTML), hreflang_n is not dual-emit.
  local href_note="hreflang=${hreflang_n} (uniq=${uniq})"
  if [[ "${docs}" -gt 1 ]]; then
    href_note="${href_note} docs=${docs}⚠"
  fi
  echo "| ${label} | ${code} | ${bytes} | ${href_note} | ${lang:-?} | vs=${vs} |"
  rm -f "$tmp"
}

admin_check() {
  local label="$1" path="$2"
  local cookie code bytes table
  cookie=$(mktemp)
  local user="${WP_ADMIN_USER:-e2esmokeadmin}"
  local pass="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
  curl -sS -c "$cookie" -b "$cookie" -o /dev/null "${WP_BASE}/wp-login.php" || true
  curl -sS -c "$cookie" -b "$cookie" -o /dev/null -L \
    -d "log=${user}&pwd=${pass}&wp-submit=Log+In&redirect_to=${WP_BASE}/wp-admin/&testcookie=1" \
    "${WP_BASE}/wp-login.php" || true
  code=$(curl -sS -c "$cookie" -b "$cookie" -o /tmp/combo-admin.html -w '%{http_code}' --max-time 30 "${WP_BASE}${path}")
  bytes=$(wc -c </tmp/combo-admin.html | tr -d ' ')
  table=$(grep -c 'wp-list-table\|wrap\|postbox' /tmp/combo-admin.html || true)
  echo "| ${label} | ${code} | ${bytes} | admin_ui=${table} |"
  rm -f "$cookie"
}

{
  echo "# Content × SEO combo smoke — ${STAMP}"
  echo
  echo "- WP_BASE: ${WP_BASE}"
  echo "- VS prefix: /${PREFIX}/"
  echo "- Peer i18n deactivated for this run"
  echo
} >"$REPORT"

info "Deactivating peer translation plugins..."
for peer in polylang translatepress-multilingual gtranslate weglot loco-translate; do
  wp_in plugin deactivate "$peer" 2>/dev/null || true
done
wp_in plugin activate wpmmcc-ats 2>/dev/null || true

# Resolve sample virtual URLs from DB via PHP one-liner
mapfile -t SAMPLES < <(docker exec "$CONTAINER" php -r '
require "/var/www/html/wp-load.php";
global $wpdb;
$pm = $wpdb->prefix . "wptsall_post_mappings";
$wanted = array("product","course","page","post","download","forum");
foreach ($wanted as $pt) {
  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT sp.post_name src, p.post_name tgt, p.post_type
     FROM `$pm` m
     INNER JOIN {$wpdb->posts} p ON p.ID=m.target_post_id
     LEFT JOIN {$wpdb->posts} sp ON sp.ID=m.source_post_id
     WHERE m.target_post_id>0 AND p.post_status=\"publish\" AND p.post_type=%s
       AND (m.target_site_id=\"v_smoke_fixed\" OR m.relation_id=120)
     LIMIT 1", $pt), ARRAY_A);
  if ($row) {
    $base = ($pt==="product") ? "product/" : (($pt==="course") ? "llms-course/" : (($pt==="download") ? "downloads/" : (($pt==="forum") ? "forums/" : "")));
    echo $pt . "\t" . $base . ($row["src"] ?: $row["tgt"]) . "\n";
  }
}
' 2>/dev/null | grep -v 'PHP Warning\|Undefined array\|Constant WP\|PHP Notice' || true)

echo "## Baseline (WPTSALL only + current SEO stack)" >>"$REPORT"
echo "| check | http | bytes | hreflang | lang | vs |" >>"$REPORT"
echo "|---|---|---|---|---|---|" >>"$REPORT"
curl_check "VS home" "${WP_BASE}/${PREFIX}/" | tee -a "$REPORT"
for line in "${SAMPLES[@]:-}"; do
  [[ -z "$line" ]] && continue
  pt=${line%%$'\t'*}
  path=${line#*$'\t'}
  curl_check "guest ${pt}" "${WP_BASE}/${PREFIX}/${path}/" | tee -a "$REPORT"
done

echo >>"$REPORT"
echo "## Admin baseline" >>"$REPORT"
echo "| check | http | bytes | ui |" >>"$REPORT"
echo "|---|---|---|---|" >>"$REPORT"
admin_check "products list" "/wp-admin/edit.php?post_type=product" | tee -a "$REPORT"
admin_check "posts list" "/wp-admin/edit.php" | tee -a "$REPORT"

# --- Per content plugin emphasis (plugin already active in lab; we still record) ---
echo >>"$REPORT"
echo "## Content plugins under test (active alongside WPTSALL — not mutually exclusive in this Lab image)" >>"$REPORT"
echo "Correctness unit is still *per journey lane*; this smoke records guest/admin reachability." >>"$REPORT"
echo >>"$REPORT"
IFS=',' read -ra CONTENTS <<<"$CONTENT_CSV"
for slug in "${CONTENTS[@]}"; do
  slug=$(echo "$slug" | xargs)
  [[ -z "$slug" ]] && continue
  st=$(wp_in plugin get "$slug" --field=status 2>/dev/null || echo missing)
  echo "- \`${slug}\`: ${st}" >>"$REPORT"
done

# --- SEO exclusive matrix ---
echo >>"$REPORT"
echo "## SEO exclusive matrix (one SEO plugin at a time)" >>"$REPORT"
echo "| seo | check | http | bytes | hreflang | lang | vs |" >>"$REPORT"
echo "|---|---|---|---|---|---|---|" >>"$REPORT"

# Remember which SEO were active
ORIG_SEO_ACTIVE=()
IFS=',' read -ra SEOS <<<"$SEO_CSV"
for s in "${SEOS[@]}"; do
  s=$(echo "$s" | xargs)
  st=$(wp_in plugin get "$s" --field=status 2>/dev/null || echo missing)
  if [[ "$st" == "active" ]]; then
    ORIG_SEO_ACTIVE+=("$s")
  fi
done

for seo in "${SEOS[@]}"; do
  seo=$(echo "$seo" | xargs)
  [[ -z "$seo" ]] && continue
  info "SEO exclusive: ${seo}"
  st=$(wp_in plugin get "$seo" --field=status 2>/dev/null || echo missing)
  if [[ "$st" == "missing" ]]; then
    echo "| ${seo} | skip | not installed in Lab | — | — | — | — |" >>"$REPORT"
    continue
  fi
  # Deactivate all listed SEO first
  for other in "${SEOS[@]}"; do
    other=$(echo "$other" | xargs)
    wp_in plugin deactivate "$other" 2>/dev/null || true
  done
  wp_in plugin activate "$seo" 2>/dev/null || {
    echo "| ${seo} | activate | FAIL | — | — | — | — |" >>"$REPORT"
    continue
  }
  # Sample singular
  sample_url="${WP_BASE}/${PREFIX}/"
  for line in "${SAMPLES[@]:-}"; do
    [[ "$line" == post$'\t'* ]] || continue
    path=${line#*$'\t'}
    sample_url="${WP_BASE}/${PREFIX}/${path}/"
    break
  done
  row=$(curl_check "${seo} singular" "$sample_url")
  # Reformat with seo column
  echo "| ${seo} ${row#| }" >>"$REPORT"
done

# Restore originally active SEO plugins
info "Restoring SEO plugins..."
for other in "${SEOS[@]}"; do
  other=$(echo "$other" | xargs)
  wp_in plugin deactivate "$other" 2>/dev/null || true
done
for s in "${ORIG_SEO_ACTIVE[@]:-}"; do
  wp_in plugin activate "$s" 2>/dev/null || true
done
# Lab default often keeps wordpress-seo
wp_in plugin activate wordpress-seo 2>/dev/null || true

echo >>"$REPORT"
echo "## Pass criteria" >>"$REPORT"
echo "- Guest virtual URL: HTTP 200, \`wptsall-virtual-site\` present, \`lang\` matches VS" >>"$REPORT"
echo "- hreflang: uniq count should equal total (no duplicate pairs) when a single SEO plugin owns head/sitemap" >>"$REPORT"
echo "- Admin list screens: HTTP 200 with list UI markers" >>"$REPORT"
echo >>"$REPORT"
echo "Report written: \`${REPORT}\`" >>"$REPORT"

ok "Combo smoke report → ${REPORT}"
echo "$REPORT"
