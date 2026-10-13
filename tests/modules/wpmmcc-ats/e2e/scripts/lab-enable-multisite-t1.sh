#!/usr/bin/env bash
# Enable WP Multisite (subdirectory) on Lab wordpress-test and bootstrap T1 blog.
#
# Creates blog slug "en" at /en/ (avoids clash with virtual /en_us/),
# network-activates wptsall, activates representative content plugins on the
# subsite, then runs setup-relations.php so Relation#2 (target_site_type=wp) exists.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-enable-multisite-t1.sh
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/scripts/lab-enable-multisite-t1.sh --force-recreate-site
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=/dev/null
source "${E2E_DIR}/config.sh"

export WPTSALL_LAB=1
CONTAINER="${WPTSALL_LAB_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
WP_BASE="${WP_URL:-http://127.0.0.1:9083}"
SITE_SLUG="${WPTSALL_T1_SITE_SLUG:-en}"
SITE_TITLE="${WPTSALL_T1_SITE_TITLE:-English Subsite}"
FORCE_RECREATE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --force-recreate-site) FORCE_RECREATE=1; shift ;;
    *) echo "Unknown: $1" >&2; exit 1 ;;
  esac
done

wp_c() {
  docker exec "$CONTAINER" wp "$@" --allow-root
}

info "Lab Multisite T1 bootstrap (container=${CONTAINER})"

IS_MS=0
if docker exec "$CONTAINER" wp eval 'echo is_multisite() ? "1" : "0";' --allow-root 2>/dev/null | grep -v Warning | grep -q '^1$'; then
  IS_MS=1
fi

if [[ "$IS_MS" -eq 0 ]]; then
  info "Converting single-site → multisite (subdirectory)…"
  wp_c config set WP_ALLOW_MULTISITE true --raw --type=constant 2>/dev/null || true
  # DOMAIN must match siteurl host (no port in DOMAIN_CURRENT_SITE for WP MS)
  DOMAIN="$(docker exec "$CONTAINER" wp option get siteurl --allow-root 2>/dev/null | grep -v Warning | sed -E 's#https?://##' | cut -d/ -f1 | cut -d: -f1)"
  DOMAIN="${DOMAIN:-127.0.0.1}"
  wp_c core multisite-convert --title="WPTSALL Lab" 2>&1 | grep -v Warning || true
  # Ensure constants are present (convert usually writes them)
  for key in MULTISITE SUBDOMAIN_INSTALL; do
    wp_c config get "$key" --type=constant 2>/dev/null | grep -v Warning || true
  done
  # Flush rewrites / htaccess for MS
  wp_c rewrite flush --hard 2>/dev/null || true
  info "Multisite convert done (domain=${DOMAIN})"
else
  info "Already multisite — skipping convert"
fi

# Resolve or create target blog
BLOG_ID="$(docker exec "$CONTAINER" wp eval '
if ( ! is_multisite() ) { echo 0; return; }
$slug = getenv("WPTSALL_T1_SITE_SLUG") ?: "en";
$path = "/" . trim($slug, "/") . "/";
foreach ( get_sites( array( "number" => 50 ) ) as $s ) {
  if ( (string) $s->path === $path ) { echo (int) $s->blog_id; return; }
}
echo 0;
' --allow-root 2>/dev/null | grep -v Warning | tr -d '[:space:]')"

if [[ "${BLOG_ID:-0}" -le 1 ]]; then
  info "Creating subsite slug=${SITE_SLUG}…"
  # Prefer admin email from blog 1
  EMAIL="$(docker exec "$CONTAINER" wp user get 1 --field=user_email --allow-root 2>/dev/null | grep -v Warning | head -1)"
  EMAIL="${EMAIL:-admin@example.com}"
  OUT="$(wp_c site create --slug="$SITE_SLUG" --title="$SITE_TITLE" --email="$EMAIL" --porcelain 2>&1 | grep -v Warning || true)"
  BLOG_ID="$(echo "$OUT" | tr -d '[:space:]')"
  if ! [[ "${BLOG_ID}" =~ ^[0-9]+$ ]] || [[ "${BLOG_ID}" -le 1 ]]; then
    # Fallback: list and pick
    BLOG_ID="$(docker exec "$CONTAINER" wp site list --field=blog_id --allow-root 2>/dev/null | grep -v Warning | awk '$1>1{print; exit}')"
  fi
fi

if [[ "${FORCE_RECREATE}" -eq 1 ]] && [[ "${BLOG_ID:-0}" -gt 1 ]]; then
  warn "force-recreate requested but site delete is destructive — skipped (blog_id=${BLOG_ID})"
fi

if [[ "${BLOG_ID:-0}" -le 1 ]]; then
  err "Failed to resolve/create T1 blog_id"
  exit 1
fi

SUB_URL="${WP_BASE}/${SITE_SLUG}/"
info "T1 blog_id=${BLOG_ID} url=${SUB_URL}"

# Network-activate WPTSALL; activate representative plugins on subsite
wp_c plugin activate wpmmcc-ats --network 2>/dev/null || wp_c plugin activate wpmmcc-ats 2>/dev/null || true

# Representative content plugins for T1 (6).
# Avoid Tutor on subsite: Tutor Ecommerce joins wp_{blog}_wp_users (fatal on Multisite).
PLUGINS=(
  woocommerce
  easy-digital-downloads
  learnpress
  wordpress-seo
  bbpress
  wp-job-manager
)

for p in "${PLUGINS[@]}"; do
  if docker exec "$CONTAINER" wp plugin is-installed "$p" --allow-root 2>/dev/null; then
    wp_c plugin activate "$p" --url="$SUB_URL" 2>/dev/null || true
    info "  activated on T1: $p"
  else
    warn "  plugin not installed: $p"
  fi
done

# Deactivate known Multisite-admin breakers on T1 if present
wp_c plugin deactivate tutor --url="$SUB_URL" 2>/dev/null || true
wp_c plugin deactivate the-events-calendar --url="$SUB_URL" 2>/dev/null || true

# Ensure smoke admins can manage the subsite
for u in e2esmokeadmin admin; do
  wp_c user set-role "$u" administrator --url="$SUB_URL" 2>/dev/null || true
done
mkdir -p /tmp/wptsall-t1-uploads 2>/dev/null || true
docker exec "$CONTAINER" bash -c "mkdir -p /var/www/html/wp-content/uploads/sites/${BLOG_ID}/wc-logs && chown -R www-data:www-data /var/www/html/wp-content/uploads/sites/${BLOG_ID} 2>/dev/null || true"

# Permalinks on both sites
wp_c rewrite structure '/%postname%/' 2>/dev/null || true
wp_c rewrite flush --hard 2>/dev/null || true
wp_c rewrite structure '/%postname%/' --url="$SUB_URL" 2>/dev/null || true
wp_c rewrite flush --hard --url="$SUB_URL" 2>/dev/null || true

# Multisite subdirectory .htaccess (convert often leaves single-site rules)
info "Writing Multisite subdirectory .htaccess…"
docker exec "$CONTAINER" bash -c 'cat > /var/www/html/.htaccess << "EOF"
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
</IfModule>
# END WordPress
EOF
chown www-data:www-data /var/www/html/.htaccess'

# Setup relations (creates wp relation when blog≥2 exists)
info "Running setup-relations.php…"
if docker exec "$CONTAINER" test -f /opt/wptsall-e2e/php/setup-relations.php 2>/dev/null; then
  docker exec "$CONTAINER" wp eval-file /opt/wptsall-e2e/php/setup-relations.php --allow-root 2>&1 | grep -v Warning
elif [[ -f "${SCRIPT_DIR}/php/setup-relations.php" ]]; then
  docker cp "${SCRIPT_DIR}/php/setup-relations.php" "${CONTAINER}:/tmp/setup-relations.php"
  docker exec "$CONTAINER" wp eval-file /tmp/setup-relations.php --allow-root 2>&1 | grep -v Warning
else
  err "setup-relations.php not found"
  exit 1
fi

# Seed + map sample posts for topology smoke
if docker exec "$CONTAINER" test -f /opt/wptsall-e2e/php/lab-subsite-seed-mirrors.php 2>/dev/null; then
  docker exec -e WPTSALL_T1_BLOG_ID="$BLOG_ID" "$CONTAINER" \
    wp eval-file /opt/wptsall-e2e/php/lab-subsite-seed-mirrors.php --allow-root 2>&1 | grep -v Warning
elif [[ -f "${SCRIPT_DIR}/php/lab-subsite-seed-mirrors.php" ]]; then
  docker cp "${SCRIPT_DIR}/php/lab-subsite-seed-mirrors.php" "${CONTAINER}:/tmp/lab-subsite-seed-mirrors.php"
  docker exec -e WPTSALL_T1_BLOG_ID="$BLOG_ID" "$CONTAINER" \
    wp eval-file /tmp/lab-subsite-seed-mirrors.php --allow-root 2>&1 | grep -v Warning
fi

ok "Multisite T1 ready: blog_id=${BLOG_ID} ${SUB_URL}"
echo "WPTSALL_T1_BLOG_ID=${BLOG_ID}"
echo "WPTSALL_T1_URL=${SUB_URL}"
