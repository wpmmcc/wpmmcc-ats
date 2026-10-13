#!/usr/bin/env bash
# Provision one fully isolated WordPress runtime for a parallel E2E slot.
#
# Each slot gets a dedicated MySQL schema, named Docker volume, Apache
# container and host URL.  This is intentionally separate from wordpress-test:
# no shared test data is reset, copied back, or mutated by matrix workers.
#
# Usage: E2E_SLOT=slot-a bash ensure-slot-wordpress.sh [slot-a]
set -euo pipefail

SLOT="${1:-${E2E_SLOT:-slot-a}}"
case "${SLOT}" in
  slot-a|slot-b|slot-c|slot-d|slot-e|slot-f|slot-g|slot-h|slot-i|slot-j|slot-k|slot-l|slot-m|slot-n) ;;
  # slot-u is NOT an e2e matrix slot: it is the pinned unit/integration
  # environment (tasks/test/22 §3.4, 2026-09-22) so wp-unit / wp-integration
  # defaults stop consuming either the shared wp_test (e2e's seeded matrix,
  # main :9083) or the e2e slots a..d (which --shards resets). Port 9180 sits
  # in the WP-slot family below slot-a; 9195-9197 are identity mock ports.
  slot-u) ;;
  # slot-v (批 O6 复栈, 2026-09-24): dedicated journey-lane slot — the
  # three-system lane's WP needs a REAL-domain siteurl (the plugin's site
  # verification rejects IP/localhost). The matrix slots a..n must keep
  # their IP bases, so the journey lane owns this slot and provisions it
  # with E2E_SLOT_WP_BASE=http://blog.localhost:9198.
  slot-v) ;;
  *) echo "[slot-wp] unsupported slot: ${SLOT}" >&2; exit 2 ;;
esac

ROOT_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
LAB_DIR="${ROOT_DIR}/tests/docker-lab"
E2E_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e"
SLOT_SAFE="${SLOT//-/_}"
CONTAINER="wptsall-wp-lab-wordpress-${SLOT}"
VOLUME="wptsall-wp-lab_wp_${SLOT_SAFE}_data"
DB_NAME="wplab_${SLOT_SAFE}"
PORT="$(case "${SLOT}" in
  slot-a) echo 9181;; slot-b) echo 9182;; slot-c) echo 9183;; slot-d) echo 9184;;
  slot-e) echo 9185;; slot-f) echo 9186;; slot-g) echo 9187;; slot-h) echo 9188;;
  slot-i) echo 9189;; slot-j) echo 9190;; slot-k) echo 9191;; slot-l) echo 9192;;
  slot-m) echo 9193;; slot-n) echo 9194;; slot-u) echo 9180;; slot-v) echo 9198;;
esac)"
# 批 O6 复栈: journey-lane slots may pin a REAL-domain base (the plugin's
# site verification rejects IP/localhost siteurls). Default keeps the IP base.
BASE="${E2E_SLOT_WP_BASE:-http://127.0.0.1:${PORT}}"

if [[ -f "${LAB_DIR}/.env" ]]; then
  # shellcheck disable=SC1090
  set -a; source "${LAB_DIR}/.env"; set +a
fi
MYSQL_USER="${MYSQL_USER:-wplab}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-wplab_pass_2026}"
WP_ADMIN_USER="${WP_ADMIN_USER:-e2eadmin_${SLOT#slot-}}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-Wptsall-Smoke-Admin-2026!}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-e2eadmin_${SLOT#slot-}@wpmm.test}"
NETWORK="${WPTSALL_LAB_NETWORK:-wptsall-wp-lab-net}"
# P1-TEST-01 (2026-09-02): pin slots to the lab baseline image (matches
# docker-compose.yml wordpress-test). A newer image made `wp core version`
# drift from the DB and from the unit baseline; tests then failed on
# version-dependent behavior only on slots.
# P1-TEST-04 (2026-09-03): content catalog plugins (WooCommerce 11+) require
# WordPress 6.9+; 6.7 slots fail Stage 1 auto-activate for commerce lanes.
# slot-u (unit/integration env, 2026-09-22): the 6.9 content-catalog minimum
# does NOT apply — the unit baseline is WP 6.7 (compose wordpress-test,
# module-ci pin, --shards pin). Defaulting both here keeps the idempotent
# re-ensure path from classifying a healthy 6.7 slot-u as "old" and
# recreating it on every run.
if [[ "${SLOT}" == "slot-u" ]]; then
  E2E_SLOT_WP_MIN_VERSION="${E2E_SLOT_WP_MIN_VERSION:-6.7.0}"
  IMAGE="${E2E_SLOT_WP_IMAGE:-wordpress:6.7-php8.2-apache}"
else
  E2E_SLOT_WP_MIN_VERSION="${E2E_SLOT_WP_MIN_VERSION:-6.9.0}"
  IMAGE="${E2E_SLOT_WP_IMAGE:-wordpress:6.9-php8.2-apache}"
fi

echo "[slot-wp] ensuring slot=${SLOT} container=${CONTAINER} database=${DB_NAME} base=${BASE}"
E2E_SLOT="${SLOT}" bash "${E2E_DIR}/scripts/ensure-slot-db.sh" "${SLOT}" >/dev/null

if ! docker network inspect "${NETWORK}" >/dev/null 2>&1; then
  echo "[slot-wp] Lab network ${NETWORK} is unavailable; start docker-lab first" >&2
  exit 1
fi

if ! docker volume inspect "${VOLUME}" >/dev/null 2>&1; then
  docker volume create "${VOLUME}" >/dev/null
fi

if ! docker inspect -f '{{.State.Running}}' "${CONTAINER}" >/dev/null 2>&1; then
  NEED_CREATE=1
else
  NEED_CREATE=0
  # Recreate when live product mount is missing (pre-release single truth: wpmmcc-ats).
  if ! docker exec "${CONTAINER}" test -f /var/www/html/wp-content/plugins/wpmmcc-ats/wpmmcc-ats.php >/dev/null 2>&1; then
    echo "[slot-wp] recreating ${CONTAINER}: missing plugins/wpmmcc-ats mount"
    docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true
    NEED_CREATE=1
  elif ! docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html --skip-plugins eval \
    "echo version_compare(get_bloginfo('version'), '${E2E_SLOT_WP_MIN_VERSION}', '>=') ? 'ok' : 'old';" \
    2>/dev/null | grep -qx ok; then
    SLOT_WP_VER="$(docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html core version 2>/dev/null || echo unknown)"
    echo "[slot-wp] recreating ${CONTAINER}: WordPress ${SLOT_WP_VER} < ${E2E_SLOT_WP_MIN_VERSION} (content catalog minimum)"
    docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true
    docker volume rm "${VOLUME}" >/dev/null 2>&1 || true
    docker volume create "${VOLUME}" >/dev/null
    NEED_CREATE=1
  else
    # Recreate when bind mounts still point at pre-tests/ SSOT paths.
    mounts="$(docker inspect -f '{{range .Mounts}}{{.Source}} {{end}}' "${CONTAINER}" 2>/dev/null || true)"
    if [[ "${mounts}" == *"${ROOT_DIR}/docker-lab/"* ]] \
      || [[ "${mounts}" == *"${ROOT_DIR}/wpmmcc-ats/tests/"* ]]; then
      echo "[slot-wp] recreating ${CONTAINER}: stale pre-SSOT bind mounts"
      docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true
      NEED_CREATE=1
    fi
  fi
fi

if [[ "${NEED_CREATE}" == "1" ]]; then
  config_extra="define('WP_HOME', '${BASE}');
define('WP_SITEURL', '${BASE}');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_MEMORY_LIMIT', '2048M');
define('WP_MAX_MEMORY_LIMIT', '2048M');"
  docker run -d --name "${CONTAINER}" --restart unless-stopped \
    --label "com.wptsall.e2e.slot=${SLOT}" \
    --label "com.wptsall.e2e.managed=true" \
    --network "${NETWORK}" \
    -p "127.0.0.1:${PORT}:80" \
    -e "WORDPRESS_DB_HOST=db:3306" \
    -e "WORDPRESS_DB_USER=${MYSQL_USER}" \
    -e "WORDPRESS_DB_PASSWORD=${MYSQL_PASSWORD}" \
    -e "WORDPRESS_DB_NAME=${DB_NAME}" \
    -e "WORDPRESS_TABLE_PREFIX=wp_" \
    -e "WORDPRESS_CONFIG_EXTRA=${config_extra}" \
    -v "${VOLUME}:/var/www/html" \
    -v "${ROOT_DIR}/wpmmcc-ats/source:/var/www/html/wp-content/plugins/wpmmcc-ats:ro" \
    -v "${LAB_DIR}/php/zz-wptsall-memory.ini:/usr/local/etc/php/conf.d/zz-wptsall-memory.ini:ro" \
    -v "${LAB_DIR}/php/zz-wptsall-mpm.conf:/etc/apache2/conf-available/zz-wptsall-mpm.conf:ro" \
    -v "${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e:/opt/wptsall-e2e" \
    -v "${ROOT_DIR}/tests/modules/wpmmcc-ats/seeding:/opt/wptsall-seeding:ro" \
    "${IMAGE}" \
    bash -lc 'a2enconf zz-wptsall-mpm >/dev/null 2>&1 || true; docker-entrypoint.sh apache2-foreground' >/dev/null
fi

for _ in $(seq 1 60); do
  if docker inspect -f '{{.State.Running}}' "${CONTAINER}" 2>/dev/null | grep -qx true; then
    break
  fi
  sleep 1
done
if ! docker inspect -f '{{.State.Running}}' "${CONTAINER}" 2>/dev/null | grep -qx true; then
  docker logs --tail 80 "${CONTAINER}" >&2 || true
  echo "[slot-wp] container failed to start: ${CONTAINER}" >&2
  exit 1
fi

bash "${LAB_DIR}/scripts/ensure-wp-cli.sh" --container "${CONTAINER}"
slot_wp() {
  docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html "$@"
}
slot_wp_skip_plugins() {
  docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html --skip-plugins --skip-themes "$@"
}

_ensure_slot_mu_plugins() {
  local mu_src="${E2E_DIR}/php/mu-plugins/wptsall-e2e-slot-lab.php"
  if [[ ! -f "${mu_src}" ]]; then
    return 0
  fi
  docker exec "${CONTAINER}" mkdir -p /var/www/html/wp-content/mu-plugins 2>/dev/null || true
  docker cp "${mu_src}" "${CONTAINER}:/var/www/html/wp-content/mu-plugins/wptsall-e2e-slot-lab.php" >/dev/null
}
_ensure_slot_mu_plugins

# Tutor (and peers) fatals WP bootstrap on slot volumes when copied from catalogue.
# Quarantine before any wp-cli that loads WordPress; lanes unquarantine per-project.
QUARANTINE_PLUGINS=(tutor)
_quarantine_toxic_slot_plugins() {
  local plugin active quarantined
  for plugin in "${QUARANTINE_PLUGINS[@]}"; do
    active="/var/www/html/wp-content/plugins/${plugin}"
    quarantined="/var/www/html/wp-content/plugins/${plugin}.e2e-quarantined"
    if docker exec "${CONTAINER}" test -d "${active}"; then
      docker exec "${CONTAINER}" rm -rf "${quarantined}" 2>/dev/null || true
      docker exec "${CONTAINER}" mv "${active}" "${quarantined}" 2>/dev/null || true
    fi
  done
  docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html --skip-plugins eval '
    $active = get_option("active_plugins", []);
    if (!is_array($active)) { $active = []; }
  $drop = array("tutor/tutor.php");
    $active = array_values(array_filter($active, static function ($entry) use ($drop) {
      return !in_array((string) $entry, $drop, true);
    }));
    update_option("active_plugins", $active);
  ' 2>/dev/null || true
}
_quarantine_toxic_slot_plugins

_reset_slot_active_plugins_baseline() {
  slot_wp_skip_plugins eval '
    $keep = array();
    foreach (array("wpmmcc-ats/wpmmcc-ats.php", "wordpress-importer/wordpress-importer.php") as $plugin) {
      if (file_exists(WP_PLUGIN_DIR . "/" . $plugin)) {
        $keep[] = $plugin;
      }
    }
    update_option("active_plugins", array_values(array_unique($keep)));
    if (function_exists("wp_cache_flush")) {
      wp_cache_flush();
    }
  ' >/dev/null 2>&1 || true
}
_reset_slot_active_plugins_baseline

# Slot databases persist across runs while plugin code is refreshed from the
# catalogue each provisioning, so a slot DB can hold wptsall tables from an
# older plugin schema. Activating the current plugin then runs its upgrade
# path (ALTERs) against stale tables; the emitted "WordPress database error"
# lines count as unexpected output and wp-cli fails the activation, which
# aborts slot provisioning (run 13: slot-c). Drop the stale tables first so
# activation rebuilds the schema cleanly.
_reset_slot_wptsall_tables() {
  slot_wp_skip_plugins eval '
    global $wpdb;
    $like  = $wpdb->esc_like($wpdb->prefix . "wptsall") . "%";
    $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));
    foreach ((array) $tables as $table) {
      $wpdb->query("DROP TABLE IF EXISTS `" . $table . "`");
    }
    // Also clear the active flag so the explicit activate below runs the real
    // activation hook (which creates the tables) instead of "already active".
    $active = get_option("active_plugins", []);
    $active = array_values(array_filter((array) $active, static function ($entry) {
      return strpos((string) $entry, "wpmmcc-ats/") !== 0;
    }));
    update_option("active_plugins", $active);
    // P1-TEST-01 (2026-09-02): also clear the recorded db version. Without
    // this, re-activation sees wptsall_db_version=2.1.0 and skips every
    // version-gated migration (v0.5.0 field-mapping tables etc.), leaving a
    // 26-table schema where 62 are expected.
    delete_option("wptsall_db_version");
    // P1-TEST-02 (2026-09-02): e2e lanes (manual-only gate / content matrix)
    // persist plugin settings (e.g. default_language=en_US) in the slot
    // options table; stale settings leak into later unit shards and change
    // URL-prefix behavior. Reset the plugin settings option too.
    delete_option("wptsall_settings");
  ' >/dev/null 2>&1 || true
}

if ! slot_wp_skip_plugins core is-installed >/dev/null 2>&1; then
  slot_wp_skip_plugins core install --url="${BASE}" --title="WPTSALL E2E ${SLOT}" \
    --admin_user="${WP_ADMIN_USER}" --admin_password="${WP_ADMIN_PASS}" \
    --admin_email="${WP_ADMIN_EMAIL}" --skip-email >/dev/null
fi

# Slot databases persist across runs while plugin code is refreshed from the
# catalogue each provisioning, so a slot DB can hold wptsall tables from an
# older plugin schema. Activating the current plugin then runs its upgrade
# path (ALTERs) against stale tables; the emitted "WordPress database error"
# lines count as unexpected output and wp-cli fails the activation, which
# aborts slot provisioning (runs 13/15: slot-c, slot-d). Drop the stale
# tables after WP core is installed (the eval needs a working WP) and before
# activation so the activation hook rebuilds the schema cleanly.
_reset_slot_wptsall_tables

# A first activation on a freshly core-installed slot can emit unexpected
# output (schema migration noise while tables are still being created) and
# wp-cli then fails with "No plugins activated" (runs 15: slot-e). The retry
# path below — core installed, stale tables dropped again, activation hook
# re-run — is the verified-stable route to a clean schema.
if ! slot_wp_skip_plugins plugin activate wpmmcc-ats >/dev/null 2>&1; then
  _reset_slot_wptsall_tables
  slot_wp_skip_plugins plugin activate wpmmcc-ats >/dev/null 2>&1 || true
fi
_slot_schema_table_count() {
  slot_wp_skip_plugins eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}wptsall_%\""));' \
    2>/dev/null | tr -d '[:space:]' || echo 0
}
if [[ "$(_slot_schema_table_count)" -lt 24 ]]; then
  echo "[slot-wp] wpmmcc-ats activation did not reach baseline schema (tables=$(_slot_schema_table_count))" >&2
  exit 1
fi
# P1-TEST-01 (2026-09-02): `wp core update --force` downloads the LATEST
# WordPress from wordpress.org, silently overriding the pinned image core
# (slots drifted to 7.1 while the unit baseline runs 6.7) and adding a
# network dependency to every provisioning. DB upgrades stay via
# core update-db below.
slot_wp_skip_plugins core update-db --network >/dev/null 2>&1 || slot_wp_skip_plugins core update-db >/dev/null 2>&1 || true

# Deterministic REST routing across slots: force pretty permalinks and write
# the standard mod_rewrite rules so /wp-json/ is reachable. Freshly
# provisioned slots default to ugly permalinks; after any flush_rewrite_rules()
# with an empty structure WordPress rewrites .htaccess to an empty rule set
# and /wp-json/ 404s (observed run 10: slot-f/g/h failing Stage 1 with
# status=404 while ?rest_route=/ stayed 200). wp-cli `rewrite flush --hard`
# refuses to regenerate .htaccess under a CLI server, so write the canonical
# rule block directly (same bytes WordPress emits for /%postname%/).
slot_wp_skip_plugins option update permalink_structure '/%postname%/' >/dev/null 2>&1 || true
docker exec "${CONTAINER}" sh -c 'cat > /var/www/html/.htaccess <<"HTACCESS"
# BEGIN WordPress
# The directives (lines) between "BEGIN WordPress" and "END WordPress" are
# dynamically generated, and should only be modified via WordPress filters.
# Any changes to the directives between these markers will be overwritten.
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS
chown www-data:www-data /var/www/html/.htaccess 2>/dev/null || true
chmod 644 /var/www/html/.htaccess 2>/dev/null || true'

# Matrix projects need the same plugin code catalogue as wordpress-test, but
# never its database or uploads. Copy each package once into this slot volume.
if [[ "${E2E_SLOT_WP_COPY_PLUGINS:-1}" == "1" ]]; then
  SOURCE_CONTAINER="$(cd "${LAB_DIR}" && docker compose ps -q wordpress-test)"
  if [[ -z "${SOURCE_CONTAINER}" ]]; then
    echo "[slot-wp] wordpress-test is required as the read-only plugin catalogue" >&2
    exit 1
  fi
  COPY_TMP="$(mktemp -d "${TMPDIR:-/tmp}/wptsall-slot-${SLOT_SAFE}.XXXXXX")"
  trap 'rm -rf "${COPY_TMP:-}"' EXIT
  for plugin in wordpress-importer woocommerce easy-digital-downloads bbpress tutor learnpress lifterlms the-events-calendar events-manager wp-job-manager envira-gallery-lite seriously-simple-podcasting wp-recipe-maker elementor wordpress-seo advanced-custom-fields site-reviews give directorist hivepress; do
    if docker exec "${CONTAINER}" test -d "/var/www/html/wp-content/plugins/${plugin}"; then
      continue
    fi
    if docker exec "${SOURCE_CONTAINER}" test -d "/var/www/html/wp-content/plugins/${plugin}"; then
      # docker cp has no container-to-container mode; use an explicitly scoped
      # temporary host directory as the bridge and remove it on exit.
      rm -rf "${COPY_TMP:?}/${plugin}"
      docker cp "${SOURCE_CONTAINER}:/var/www/html/wp-content/plugins/${plugin}" \
        "${COPY_TMP}/${plugin}"
      docker cp "${COPY_TMP}/${plugin}" \
        "${CONTAINER}:/var/www/html/wp-content/plugins/"
    fi
  done
  _quarantine_toxic_slot_plugins
fi

# Clean slot baseline without loading the current active plugin set; lanes
# auto-activate only their project plugins during Stage 1.
_reset_slot_active_plugins_baseline

MANIFEST_DIR="${E2E_DIR}/runtime/slot-infrastructure"
mkdir -p "${MANIFEST_DIR}"
cat > "${MANIFEST_DIR}/${SLOT}.json" <<EOF
{
  "slot": "${SLOT}",
  "container": "${CONTAINER}",
  "volume": "${VOLUME}",
  "database": "${DB_NAME}",
  "base_url": "${BASE}",
  "port": ${PORT},
  "isolated": true,
  "provisioned_at": "$(date -Iseconds)"
}
EOF

echo "[slot-wp] ready slot=${SLOT} container=${CONTAINER} base=${BASE} database=${DB_NAME}"
