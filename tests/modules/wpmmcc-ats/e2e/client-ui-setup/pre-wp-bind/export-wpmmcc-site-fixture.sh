#!/usr/bin/env bash
# Export a WPMMCC site fixture for Client Sites UI fill (not API upsert).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=../../config.sh
source "${E2E_DIR}/config.sh"

WP_URL="${PRE_WP_WP_URL:-${WP_URL:?WP_URL required}}"
CONTAINER="${PRE_WP_WP_CONTAINER:-}"
OUT="${PRE_WP_SITE_FIXTURE:?PRE_WP_SITE_FIXTURE required}"
CLIENT_BASE="${FULL_CHAIN_CLIENT_BASE:-${WEBUI_A_BASE:-http://127.0.0.1:8977}}"

case "${WP_URL}" in
  *127.0.0.1*|*localhost*) export WPTSALL_LAB=1 ;;
  *) abort "local Lab only: ${WP_URL}" ;;
esac

if [[ -z "${CONTAINER}" ]]; then
  case "${WP_URL}" in
    *:9082*) CONTAINER="wptsall-wp-lab-wordpress-wpmmcc-1" ;;
    *:9182*) CONTAINER="wptsall-wp-lab-wordpress-slot-b" ;;
    *:9187*) CONTAINER="wptsall-wp-lab-wordpress-slot-g" ;;
    *) abort "set PRE_WP_WP_CONTAINER for ${WP_URL}" ;;
  esac
fi

check_url "${WP_URL}" || abort "WP unreachable: ${WP_URL}"
check_url "${CLIENT_BASE}/api/status" || abort "Client unreachable: ${CLIENT_BASE}"

DEVICE_ID="$(
  curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["device_id"])'
)"

TOKEN="$(openssl rand -hex 32)"
# wp-cli often prints PHP warnings on stderr; keep going.
docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html \
  option update wpmmcc_client_token "${TOKEN}" >/dev/null 2>&1 || true

# option get exits non-zero when missing — must not trip set -o pipefail.
SECRET="$(
  docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html \
    option get wpmmcc_route_secret 2>/dev/null | tr -d '[:space:]' || true
)"
if [[ -z "${SECRET}" ]]; then
  SECRET="$(openssl rand -hex 16)"
  docker exec "${CONTAINER}" wp --allow-root --path=/var/www/html \
    option update wpmmcc_route_secret "${SECRET}" >/dev/null 2>&1 \
    || abort "failed to provision wpmmcc_route_secret on ${CONTAINER}"
  info "provisioned missing wpmmcc_route_secret on ${CONTAINER}"
fi
[[ -n "${SECRET}" ]] || abort "empty wpmmcc_route_secret on ${CONTAINER}"

mkdir -p "$(dirname "${OUT}")"
jq -n \
  --arg api_base_url "${WP_URL}" \
  --arg wp_client_token "${TOKEN}" \
  --arg route_secret "${SECRET}" \
  --arg device_id "${DEVICE_ID}" \
  --arg plugin_identity_hint "wpmmcc" \
  --arg exported_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --arg source "export-wpmmcc-site-fixture.sh" \
  --arg container "${CONTAINER}" \
  '{
     api_base_url: $api_base_url,
     wp_client_token: $wp_client_token,
     route_secret: $route_secret,
     device_id: $device_id,
     plugin_identity_hint: $plugin_identity_hint,
     exported_at: $exported_at,
     source: $source,
     container: $container,
     ui_fill_only: true
   }' >"${OUT}"

ok "Wrote WPMMCC UI fixture ${OUT} (${WP_URL})"
echo "${OUT}"
