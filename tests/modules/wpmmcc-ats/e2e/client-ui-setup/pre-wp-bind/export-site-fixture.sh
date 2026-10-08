#!/usr/bin/env bash
# Export live Lab WP site credentials into a UI-fill fixture (secrets, gitignored).
# Does NOT push credentials into the Client via API — Playwright fills Sites UI.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# Preserve caller WP/slot (full-chain passes slot WP); bare unset would force shared :9083.
CALLER_WP_URL="${PRE_WP_WP_URL:-${WP_URL:-}}"
CALLER_SLOT="${PRE_WP_E2E_SLOT:-${E2E_SLOT:-}}"
CALLER_CONTAINER="${PRE_WP_WP_CONTAINER:-${LAB_WP_CONTAINER:-}}"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
if [[ -n "${CALLER_SLOT}" && "${CALLER_SLOT}" != "shared" ]]; then
  export E2E_SLOT="${CALLER_SLOT}"
  export E2E_SLOT_WP_ISOLATED=1
else
  export E2E_SLOT="${E2E_SLOT:-shared}"
fi
# shellcheck source=../../config.sh
source "${E2E_DIR}/config.sh"
if [[ -n "${CALLER_WP_URL}" ]]; then
  export WP_URL="${CALLER_WP_URL}"
fi

OUT="${PRE_WP_SITE_FIXTURE:-${SCRIPT_DIR}/fixtures/site.local.json}"
mkdir -p "$(dirname "${OUT}")"

# Shared Lab WP (:9083) lives in wordpress-test-1. Slot WPs use slot containers.
# Do NOT leave E2E_SLOT=shared's accidental slot-a container when WP_URL is :9083.
if [[ "${WP_URL}" == *":9083"* ]]; then
  export LAB_WP_CONTAINER="${CALLER_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
  info "Using Lab WP container ${LAB_WP_CONTAINER} for ${WP_URL}"
elif [[ -n "${CALLER_CONTAINER}" ]]; then
  export LAB_WP_CONTAINER="${CALLER_CONTAINER}"
  info "Using caller WP container ${LAB_WP_CONTAINER} for ${WP_URL}"
elif [[ -z "${LAB_WP_CONTAINER:-}" || "${LAB_WP_CONTAINER}" == *shared* ]]; then
  export LAB_WP_CONTAINER="$(e2e_slot_wp_container 2>/dev/null || true)"
  info "Using slot WP container ${LAB_WP_CONTAINER:-?} for ${WP_URL}"
fi

check_url "${WP_URL}" || abort "WP Lab not reachable at ${WP_URL} (bash scripts/wptsall.sh lab up)"

# Lab guard (audit finding): this exporter targets the local Docker lab only.
# Without WPTSALL_LAB=1, wp_eval below takes the remote-host branch and
# ensure-client-api-runtime soft-fails against the wrong WP. Force lab mode for
# local URLs and abort loudly on anything else instead of soft-failing.
case "${WP_URL}" in
  *127.0.0.1*|*localhost*)
    export WPTSALL_LAB=1
    ;;
  *)
    abort "export-site-fixture.sh requires the local Lab WP (127.0.0.1/localhost), got ${WP_URL}"
    ;;
esac

info "Refreshing WP Client API runtime…"
if declare -F wp_eval >/dev/null 2>&1; then
  wp_eval "${E2E_DIR}/php/ensure-client-api-runtime.php" >/dev/null || warn "ensure-client-api-runtime soft-failed"
fi

# Resolve Client device_id (token must be issued for this id).
# Prefer full-chain / caller UI agent — do not use slot client port from config.sh.
CLIENT_BASE="${FULL_CHAIN_CLIENT_BASE:-${WEBUI_A_BASE:-http://127.0.0.1:8977}}"
CLIENT_DEVICE_ID="${WPTSALL_E2E_DEVICE_ID:-}"
if [[ -z "${CLIENT_DEVICE_ID}" ]]; then
  CLIENT_DEVICE_ID="$(
    curl --noproxy '*' -fsS "${CLIENT_BASE}/api/status" \
      | python3 -c 'import json,sys; print(json.load(sys.stdin).get("data",{}).get("device_id",""))' \
      2>/dev/null || true
  )"
fi
[[ -n "${CLIENT_DEVICE_ID}" ]] || abort "cannot resolve Client device_id from ${CLIENT_BASE}/api/status"

WP_CLIENT_TOKEN="${WPTSALL_E2E_WP_CLIENT_TOKEN:-}"
ROUTE_SECRET="${WPTSALL_E2E_ROUTE_SECRET:-}"

# Prefer the secret actually registered in WP REST (validate-token route).
if [[ -z "${ROUTE_SECRET}" ]]; then
  ROUTE_SECRET="$(
    curl --noproxy '*' -fsS "${WP_URL%/}/wp-json/" \
      | python3 -c '
import json,re,sys
d=json.load(sys.stdin)
for k in d.get("routes") or {}:
    m=re.search(r"/wptsall/v2/([^/]+)/client/validate-token", k)
    if m:
        print(m.group(1)); break
' 2>/dev/null || true
  )"
fi

if [[ -z "${WP_CLIENT_TOKEN}" ]]; then
  # Prefer docker exec on the resolved Lab container; strip PHP noise to 64-hex.
  issue_php="if(function_exists('wptsall_issue_client_device_token')){\$d=wptsall_issue_client_device_token('${CLIENT_DEVICE_ID}','e2e'); echo (string)\$d['token'];}"
  if [[ -n "${LAB_WP_CONTAINER:-}" ]] && docker inspect "${LAB_WP_CONTAINER}" >/dev/null 2>&1; then
    WP_CLIENT_TOKEN="$(docker exec "${LAB_WP_CONTAINER}" wp eval "${issue_php}" --allow-root 2>/dev/null | grep -oE '[a-f0-9]{64}' | head -1)"
  elif declare -F wp_cli >/dev/null 2>&1; then
    WP_CLIENT_TOKEN="$(wp_cli eval "if(function_exists(\"wptsall_issue_client_device_token\")){\$d=wptsall_issue_client_device_token(\"${CLIENT_DEVICE_ID}\",\"e2e\"); echo (string)\$d[\"token\"];}" 2>/dev/null | grep -oE '[a-f0-9]{64}' | head -1)"
  fi
fi

ROUTES_SECRET="$(
  curl --noproxy '*' -fsS "${WP_URL%/}/wp-json/" \
    | python3 -c '
import json,re,sys
d=json.load(sys.stdin)
for k in d.get("routes") or {}:
    m=re.search(r"/wptsall/v2/([^/]+)/client/validate-token", k)
    if m:
        print(m.group(1)); break
' 2>/dev/null || true
)"
if [[ -n "${ROUTES_SECRET}" ]]; then
  if [[ -n "${ROUTE_SECRET}" && "${ROUTES_SECRET}" != "${ROUTE_SECRET}" ]]; then
    warn "route_secret from wp_cli differs from REST registration; using REST secret"
  fi
  ROUTE_SECRET="${ROUTES_SECRET}"
fi

[[ -n "${WP_CLIENT_TOKEN}" ]] || abort "missing wp_client_token (set WPTSALL_E2E_WP_CLIENT_TOKEN)"
[[ -n "${ROUTE_SECRET}" ]] || abort "missing route_secret (set WPTSALL_E2E_ROUTE_SECRET)"

jq -n \
  --arg api_base_url "${WP_URL}" \
  --arg wp_client_token "${WP_CLIENT_TOKEN}" \
  --arg route_secret "${ROUTE_SECRET}" \
  --arg device_id "${CLIENT_DEVICE_ID}" \
  --arg exported_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --arg source "export-site-fixture.sh" \
  '{
     api_base_url: $api_base_url,
     wp_client_token: $wp_client_token,
     route_secret: $route_secret,
     device_id: $device_id,
     exported_at: $exported_at,
     source: $source,
     ui_fill_only: true,
     notes: "Playwright must fill Sites form from this file — do not POST /api/domain-tokens/upsert in the journey"
   }' >"${OUT}"

ok "Wrote UI fixture ${OUT} (device=${CLIENT_DEVICE_ID} token prefix ${WP_CLIENT_TOKEN:0:12}…)"
warn "This export ROTATES the WP client device token; any token already bound in a running client is now stale (approve/callback → 401). After running this script standalone, re-run the pre-wp-bind gate (run-pre-wp-client-ui-gate.sh) so the Sites journey upserts the fresh token into the client."
echo "${OUT}"
