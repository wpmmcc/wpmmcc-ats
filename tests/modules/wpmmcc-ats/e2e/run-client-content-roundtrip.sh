#!/usr/bin/env bash
# Independent E2E: WP ↔ Client WebUI content round-trip (rule → post → pairing →
# discovery → mock translation → WP mapping + translated target post).
#
# Composes the full product journey on the Lab:
#   1. Provision WP side (zh_CN language, virtual site, en_US→zh_CN relation,
#      post translation rule, fresh source post) — e2e/php/provision-client-content-roundtrip.php
#   2. Pair the client (wp wptsall security issue_pairing_pack → /api/site-connections/import)
#   3. Configure the openai-compatible component against mock-translate-api
#      (same sequence as tests/scripts/prove-provider-mock-config.sh)
#   4. Bootstrap discovery + worker run-once
#   5. Verify: client translation record + WP post mapping + 【zh_CN】 markers
#      — e2e/php/verify-client-content-roundtrip.php
#
# NOT part of release-gate / Lab matrix (independent lane, like dual-webui-mock).
#
# Usage:
#   bash tests/modules/wpmmcc-ats/e2e/run-client-content-roundtrip.sh
#   CLIENT_RT_BASE=http://127.0.0.1:8990 MOCK_API_BASE=http://127.0.0.1:9090 \
#     bash tests/modules/wpmmcc-ats/e2e/run-client-content-roundtrip.sh
#
# Requires: WPTSALL_LAB=1 lab (WP :9083), client binary built (client-wpplugin).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED CLIENT_BASE CLIENT_URL WP_URL || true
export E2E_SLOT=shared
# Lab-only lane: default to lab mode so wp_eval maps into the docker lab
# (WPTSALL_LAB unset would silently take the host-wp branch and fail on
# `cd $WP_ROOT`). Override explicitly if ever needed.
export WPTSALL_LAB="${WPTSALL_LAB:-1}"

# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

CLIENT_RT_BASE="${CLIENT_RT_BASE:-http://127.0.0.1:8990}"
MOCK_API_BASE="${MOCK_API_BASE:-${MOCK_API_URL:-http://127.0.0.1:9090}}"
COMPONENT_ID="${CLIENT_RT_COMPONENT_ID:-client-rt-openai-mock}"
REPORT_ROOT="${REPO_ROOT}/tests/reports/e2e/wpmmcc-ats/client-content-roundtrip"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="${RUNTIME_DIR}/client-content-roundtrip"
ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}"
export WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}"
# R1 clean release: the isolated client ships zero builtin templates — point it
# at the local template repo catalog (same wiring as run-dual-ui-catalog-112-mock.sh).
PROVIDER_TEMPLATES_REPO="${PROVIDER_TEMPLATES_REPO:-${REPO_ROOT}/../wptsall-provider-templates}"
[[ -f "${PROVIDER_TEMPLATES_REPO}/catalog.json" ]] || abort "template repo catalog missing at ${PROVIDER_TEMPLATES_REPO}/catalog.json"
[[ -f "${PROVIDER_TEMPLATES_REPO}/keys/catalog-signing.public.pem" ]] || abort "template repo public key missing"
export WPTSALL_PROVIDER_CATALOG_SOURCE_URL="file://${PROVIDER_TEMPLATES_REPO}/catalog.json"
export WPTSALL_PROVIDER_CATALOG_ALLOW_FILE_SOURCE=1
export WPTSALL_PROVIDER_CATALOG_PUBLIC_KEY_FILE="${PROVIDER_TEMPLATES_REPO}/keys/catalog-signing.public.pem"

mkdir -p "${REPORT_ROOT}" "${RUN_DIR}"

port_of() { echo "$1" | sed -E 's|^https?://[^:]+:([0-9]+).*|\1|'; }
CLIENT_RT_PORT="$(port_of "${CLIENT_RT_BASE}")"

json_field() { python3 -c "import json,sys;d=json.load(sys.stdin);print(d$1)" 2>/dev/null; }

ensure_mock() {
  if check_url "${MOCK_API_BASE}/api/v1/health" || check_url "${MOCK_API_BASE}/health"; then
    ok "mock-api ready at ${MOCK_API_BASE}"
    return 0
  fi
  local bin=""
  for candidate in \
    "${REPO_ROOT}/tests/infra/mock-api/target/release/mock-translate-api" \
    "${REPO_ROOT}/tests/infra/mock-api/target/debug/mock-translate-api"; do
    if [[ -x "$candidate" ]]; then bin="$candidate"; break; fi
  done
  [[ -n "$bin" ]] || abort "mock-api not running and no binary found under tests/infra/mock-api/target"
  info "Starting mock-api..."
  nohup "$bin" >"${RUN_DIR}/mock-translate-api.log" 2>&1 </dev/null &
  MOCK_STARTED_PID=$!
  for _ in $(seq 1 40); do
    if check_url "${MOCK_API_BASE}/api/v1/health"; then ok "mock-api ready"; return 0; fi
    sleep 1
  done
  abort "mock-api did not become ready (see ${RUN_DIR}/mock-translate-api.log)"
}
MOCK_STARTED_PID=""

resolve_client_binary() {
  local candidates=(
    "${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release/wptsall-client"
    "${REPO_ROOT}/client-wpplugin/source/target/release-lab/wptsall-client"
  )
  local c
  for c in "${candidates[@]}"; do
    if [[ -x "$c" ]]; then echo "$c"; return 0; fi
  done
  return 1
}

start_client() {
  local status_url="${CLIENT_RT_BASE}/api/status"
  if check_url "${status_url}"; then
    ok "client already up at ${CLIENT_RT_BASE}"
    return 0
  fi
  local bin
  bin="$(resolve_client_binary)" || abort "wptsall-client binary not found; build client-wpplugin first"
  info "Starting client agent on :${CLIENT_RT_PORT} (isolated data dir)"
  mkdir -p "${RUN_DIR}/data"
  nohup env \
    WPTSALL_WEB_UI=1 \
    WPTSALL_WEB_UI_PORT="${CLIENT_RT_PORT}" \
    WPTSALL_WEB_UI_BIND="127.0.0.1:${CLIENT_RT_PORT}" \
    WPTSALL_DATA_DIR="${RUN_DIR}/data" \
    WPTSALL_PROVIDER_ALLOWLIST="${ALLOWLIST}" \
    WPTSALL_LOG_ENABLED=1 \
    WPTSALL_LOG_FILE="${RUN_DIR}/structured.log" \
    WPTSALL_USE_SERVER_CONTROL_PLANE=0 \
    WPTSALL_SERVER_URL= \
    "$bin" >"${RUN_DIR}/client.log" 2>&1 </dev/null &
  CLIENT_STARTED_PID=$!
  for _ in $(seq 1 90); do
    if check_url "${status_url}"; then ok "client ready at ${status_url}"; return 0; fi
    sleep 1
  done
  abort "client not ready at ${status_url} (see ${RUN_DIR}/client.log)"
}
CLIENT_STARTED_PID=""

cleanup() {
  if [[ -n "${CLIENT_STARTED_PID}" ]]; then
    kill "${CLIENT_STARTED_PID}" 2>/dev/null || true
    info "stopped client (${CLIENT_STARTED_PID})"
  fi
  if [[ -n "${MOCK_STARTED_PID}" ]]; then
    kill "${MOCK_STARTED_PID}" 2>/dev/null || true
    info "stopped mock-api (${MOCK_STARTED_PID})"
  fi
}
trap cleanup EXIT

echo "══════════════════════════════════════════════════"
echo "  E2E-INDEPENDENT: Client content round-trip"
echo "  WP=${LAB_WP_BASE}  MOCK=${MOCK_API_BASE}  CLIENT=${CLIENT_RT_BASE}"
echo "══════════════════════════════════════════════════"

check_url "${LAB_WP_BASE}" || abort "lab WP not reachable at ${LAB_WP_BASE}"
ensure_mock
start_client

# ---- 1. Provision WP side ----
info "Provisioning WP side (language / virtual site / relation / rule / post)"
PROVISION_OUT="$(wp_eval "${SCRIPT_DIR}/php/provision-client-content-roundtrip.php" 2>/dev/null | tail -n 1)"
PROVISION_JSON="$(echo "${PROVISION_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("success"), d; print(json.dumps(d["data"]))')"
RELATION_ID="$(echo "${PROVISION_JSON}" | json_field "['relation_id']")"
POST_ID="$(echo "${PROVISION_JSON}" | json_field "['post_id']")"
POST_TITLE="$(echo "${PROVISION_JSON}" | json_field "['post_title']")"
MEDIA_ATTACHMENT_ID="$(echo "${PROVISION_JSON}" | json_field "['media_attachment_id']")"
MEDIA_SOURCE_URL="$(echo "${PROVISION_JSON}" | json_field "['media_source_url']")"
MEDIA_TRANSLATED_PROBE="$(echo "${PROVISION_JSON}" | json_field "['media_translated_probe_url']")"
[[ -n "${RELATION_ID}" && -n "${POST_ID}" ]] || abort "provision output missing ids: ${PROVISION_OUT}"
ok "provisioned relation=${RELATION_ID} post=${POST_ID} (${POST_TITLE})"

# MEDIA LEG: the provisioned post carries a real image attachment. Fail fast
# with clear diagnostics if the seeded (mock-renamed) counterpart URL is not
# fetchable — the client media lane depends on it.
if [[ -n "${MEDIA_ATTACHMENT_ID}" && -n "${MEDIA_TRANSLATED_PROBE}" ]]; then
  ok "media leg: attachment=${MEDIA_ATTACHMENT_ID} source=${MEDIA_SOURCE_URL}"
  if ! check_url "${MEDIA_TRANSLATED_PROBE}"; then
    abort "seeded translated counterpart not fetchable: ${MEDIA_TRANSLATED_PROBE}"
  fi
  ok "seeded counterpart fetchable (${MEDIA_TRANSLATED_PROBE})"
else
  info "media leg: provision emitted no attachment ids (text-only run)"
fi

# ---- 2. Pair the client ----
DEVICE_ID="$(curl -sf "${CLIENT_RT_BASE}/api/status" | json_field "['data']['device_id']")"
[[ -n "${DEVICE_ID}" ]] || abort "could not read client device_id"
info "Issuing pairing pack for device ${DEVICE_ID}"
# The command prints a multi-line pretty JSON object (plus optional warnings);
# extract the first '{' … matching brace block.
PACK_RAW="$(wp_cli wptsall security issue_pairing_pack --device-id="${DEVICE_ID}" --format=json --allow-root 2>/dev/null)"
PACK_JSON="$(echo "${PACK_RAW}" | python3 -c '
import json, sys
raw = sys.stdin.read()
start = raw.find("{")
assert start >= 0, "no JSON object in pairing pack output: %r" % raw[:200]
d = json.JSONDecoder().raw_decode(raw[start:])[0]
assert "pairing_code" in d, d
print(json.dumps(d))')"
PAIRING_CODE="$(echo "${PACK_JSON}" | json_field "['pairing_code']")"
[[ -n "${PAIRING_CODE}" ]] || abort "pairing pack output missing pairing_code: ${PACK_RAW}"

info "Importing connection pack into client"
IMPORT_BODY="$(python3 -c 'import json,sys; pack=json.loads(sys.argv[1]); print(json.dumps({"site_connection_pack": pack, "pairing_code": pack["pairing_code"], "device_label": "client-content-roundtrip"}))' "${PACK_JSON}")"
IMPORT_OUT="$(curl -sf -X POST "${CLIENT_RT_BASE}/api/site-connections/import" -H 'Content-Type: application/json' -d "${IMPORT_BODY}")"
echo "${IMPORT_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("success") else 1)' \
  || abort "client import failed: ${IMPORT_OUT}"
ok "client bound to ${LAB_WP_BASE}"

# ---- 3. Configure component against mock (idempotent: tolerate duplicates) ----
# R1 clean release: the isolated client starts with an empty catalog cache;
# listing first triggers the first-run auto-fetch (same as the real UI flow:
# open the catalog page, then install).
CATALOG_LIST="$(curl -sf -m 30 "${CLIENT_RT_BASE}/api/provider-catalog")"
CATALOG_COUNT="$(echo "${CATALOG_LIST}" | python3 -c '
import json, sys
d = json.load(sys.stdin)
items = d.get("data", {}).get("items") or []
assert d.get("success") and items, json.dumps(d)[:200]
print(len(items))')"   || abort "provider catalog warm-up failed (empty or error): ${CATALOG_LIST:0:200}"
[[ -n "${CATALOG_COUNT}" ]] || abort "provider catalog warm-up produced no count"
ok "catalog warmed (${CATALOG_COUNT} entries)"
info "Installing openai-compatible component → mock chat completions"
api_ok_or_duplicate() {
  # Succeeds on {"success":true} or a DUPLICATE_ID conflict (re-run case).
  local out="$1"
  echo "${out}" | python3 -c '
import json, sys
raw = sys.stdin.read()
try:
    d = json.loads(raw)
except Exception:
    sys.exit(1)
if d.get("success"):
    sys.exit(0)
code = (d.get("error") or {}).get("code", "")
sys.exit(0 if code == "DUPLICATE_ID" else 1)' \
    || return 1
  return 0
}
INSTALL_OUT="$(curl -s -X POST "${CLIENT_RT_BASE}/api/components/local/install-from-catalog" \
  -H 'Content-Type: application/json' \
  -d "{\"entry_id\":\"openai-compatible\",\"local_id\":\"${COMPONENT_ID}\",\"name\":\"Client RT OpenAI Mock\"}")"
api_ok_or_duplicate "${INSTALL_OUT}" || abort "install-from-catalog failed: ${INSTALL_OUT}"
KEY_OUT="$(curl -s -X POST "${CLIENT_RT_BASE}/api/vendor-keys" \
  -H 'Content-Type: application/json' \
  -d "{\"id\":\"${COMPONENT_ID}-key\",\"vendor_id\":\"openai\",\"label\":\"client-rt\",\"auth_values\":{\"api_key\":\"mock-translate-dev-key-2026\"},\"enabled\":true}")"
api_ok_or_duplicate "${KEY_OUT}" || abort "vendor-keys failed: ${KEY_OUT}"
curl -sf -X POST "${CLIENT_RT_BASE}/api/components/local/${COMPONENT_ID}/versions" \
  -H 'Content-Type: application/json' \
  -d "{\"version\":\"v1\",\"key_ids\":[\"${COMPONENT_ID}-key\"],\"auth_type\":\"key\"}" >/dev/null || true
curl -sf -X PUT "${CLIENT_RT_BASE}/api/components/local/${COMPONENT_ID}" \
  -H 'Content-Type: application/json' -d '{"enabled":true}' >/dev/null
curl -sf -X POST "${CLIENT_RT_BASE}/api/components/bindings/upsert" \
  -H 'Content-Type: application/json' \
  -d "{\"component_id\":\"${COMPONENT_ID}\",\"auth\":{\"api_key\":\"mock-translate-dev-key-2026\"},\"key_ids\":[\"${COMPONENT_ID}-key\"],\"request_overrides\":{\"url\":\"${MOCK_API_BASE}/v1/chat/completions\",\"body\":{\"model\":\"mock-openai-v1\"}}}" >/dev/null
curl -sf -X POST "${CLIENT_RT_BASE}/api/rule-component-bindings/upsert" \
  -H 'Content-Type: application/json' \
  -d "{\"scope\":\"global\",\"slot_key\":\"plain_text\",\"component_id\":\"${COMPONENT_ID}\"}" >/dev/null
ok "component ${COMPONENT_ID} bound (auth + mock URL + plain_text slot)"

QT="$(curl -sf -X POST "${CLIENT_RT_BASE}/api/components/local/${COMPONENT_ID}/quick-test" \
  -H 'Content-Type: application/json' \
  -d "{\"api_key\":\"mock-translate-dev-key-2026\",\"auth_values\":{\"api_key\":\"mock-translate-dev-key-2026\"},\"config_overrides\":{\"request.url\":\"${MOCK_API_BASE}/v1/chat/completions\",\"request.body.model\":\"mock-openai-v1\"},\"text\":\"Hello world\",\"source_lang\":\"en_US\",\"target_lang\":\"zh_CN\"}")"
echo "${QT}" | grep -q 'zh_CN' || abort "component quick-test did not return zh_CN mock output: ${QT}"
ok "quick-test translated (mock marker present)"

# ---- 3b. Media component (image lane, catalog v1.1.0 media_mt family) ----
# The media_ref field group (the post's image attachment) routes by format:
# the openai component above does not declare image_file artifact support, so
# selection falls through to this media_ref:image slot binding. The component
# endpoint is overridden to the mock /api/v1/translate/image (same wiring
# class as the text URL override above).
if [[ -n "${MEDIA_ATTACHMENT_ID}" ]]; then
  MEDIA_COMPONENT_ID="${CLIENT_RT_MEDIA_COMPONENT_ID:-client-rt-media-mock}"
  info "Installing media image component → mock /api/v1/translate/image"
  INSTALL_MEDIA_OUT="$(curl -s -X POST "${CLIENT_RT_BASE}/api/components/local/install-from-catalog" \
    -H 'Content-Type: application/json' \
    -d "{\"entry_id\":\"custom-media-image-mt\",\"local_id\":\"${MEDIA_COMPONENT_ID}\",\"name\":\"Client RT Media Mock\"}")"
  api_ok_or_duplicate "${INSTALL_MEDIA_OUT}" || abort "media install-from-catalog failed: ${INSTALL_MEDIA_OUT}"
  KEY_MEDIA_OUT="$(curl -s -X POST "${CLIENT_RT_BASE}/api/vendor-keys" \
    -H 'Content-Type: application/json' \
    -d "{\"id\":\"${MEDIA_COMPONENT_ID}-key\",\"vendor_id\":\"custom_media_image_mt\",\"label\":\"client-rt-media\",\"auth_values\":{\"api_key\":\"mock-translate-dev-key-2026\"},\"enabled\":true}")"
  api_ok_or_duplicate "${KEY_MEDIA_OUT}" || abort "media vendor-keys failed: ${KEY_MEDIA_OUT}"
  curl -sf -X POST "${CLIENT_RT_BASE}/api/components/local/${MEDIA_COMPONENT_ID}/versions" \
    -H 'Content-Type: application/json' \
    -d "{\"version\":\"v1\",\"key_ids\":[\"${MEDIA_COMPONENT_ID}-key\"],\"auth_type\":\"key\"}" >/dev/null || true
  curl -sf -X PUT "${CLIENT_RT_BASE}/api/components/local/${MEDIA_COMPONENT_ID}" \
    -H 'Content-Type: application/json' -d '{"enabled":true}' >/dev/null
  curl -sf -X POST "${CLIENT_RT_BASE}/api/components/bindings/upsert" \
    -H 'Content-Type: application/json' \
    -d "{\"component_id\":\"${MEDIA_COMPONENT_ID}\",\"auth\":{\"api_key\":\"mock-translate-dev-key-2026\"},\"key_ids\":[\"${MEDIA_COMPONENT_ID}-key\"],\"request_overrides\":{\"url\":\"${MOCK_API_BASE}/api/v1/translate/image\"}}" >/dev/null
  curl -sf -X POST "${CLIENT_RT_BASE}/api/rule-component-bindings/upsert" \
    -H 'Content-Type: application/json' \
    -d "{\"scope\":\"global\",\"slot_key\":\"media_ref:image\",\"component_id\":\"${MEDIA_COMPONENT_ID}\"}" >/dev/null
  ok "media component ${MEDIA_COMPONENT_ID} bound (mock image endpoint + media_ref:image slot)"
fi

# ---- 4. Discovery + worker ----
info "Bootstrapping discovery tasks"
BOOTSTRAP_OUT="$(curl -sf -X POST "${CLIENT_RT_BASE}/api/discovery-tasks/bootstrap")"
echo "${BOOTSTRAP_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin)["data"]; failed=d.get("failed",[]); assert not failed, failed' \
  || abort "discovery bootstrap failed: ${BOOTSTRAP_OUT}"

# Lab WP has a large historical outbox. Scope worker to the provisioned relation
# only — otherwise run-once burns minutes on unrelated relations and this lane
# never observes the 【zh_CN】 mapping under RELATION_ID.
#
# The task is left WITHOUT selected_component_id: a task-scoped selection
# builds an EXCLUSIVE single-component registry (build_task_scoped_runtime_
# registry), so with the media leg the task would carry two formats that need
# two different components (text groups → openai mock via the plain_text slot,
# media_ref::image group → media mock via the media_ref:image slot). An
# unbound task runs on the full registry and both global slot bindings resolve
# per format group (the explicit binding tier is unclaimed-guard-exempt).
info "Scoping discovery tasks to relation ${RELATION_ID} only"
export CLIENT_RT_BASE RELATION_ID
python3 - <<'PY'
import json, os, urllib.request
base = os.environ["CLIENT_RT_BASE"].rstrip("/")
relation_id = int(os.environ["RELATION_ID"])
with urllib.request.urlopen(f"{base}/api/discovery-tasks") as resp:
    d = json.loads(resp.read().decode())
data = d.get("data") or {}
items = data.get("items") if isinstance(data, dict) else data
if not isinstance(items, list):
    items = []
for t in items:
    tid = t.get("id")
    rid = int(t.get("relation_id") or 0)
    if tid is None:
        continue
    enabled = rid == relation_id
    # No selected_component_id anywhere: component choice is per format group
    # via the global slot bindings (plain_text / media_ref:image). A stale
    # selected_component_id on a DISABLED task would still enter the
    # claimed-by-other-relations guard set and fence the anonymous fallback
    # tier for unbound groups (e.g. rich_html), so it is cleared too.
    body = json.dumps({"enabled": enabled, "selected_component_id": ""}).encode()
    req = urllib.request.Request(
        f"{base}/api/discovery-tasks/{tid}",
        data=body,
        headers={"Content-Type": "application/json"},
        method="PUT",
    )
    try:
        urllib.request.urlopen(req).read()
        print(f"task {tid} relation={rid} enabled={enabled}", flush=True)
    except Exception as e:
        print(f"warn: could not set task {tid} enabled={enabled}: {e}", flush=True)
PY

# Drop stale claim placeholders for this relation so the fresh post can be claimed.
info "Clearing stale claim placeholders for relation ${RELATION_ID}"
docker exec wptsall-wp-lab-db-1 mysql -uroot -pwplab_root_2026 -e \
  "DELETE FROM wp_test.wp_wptsall_post_mappings WHERE relation_id=${RELATION_ID} AND (target_post_id=0 OR target_post_id IS NULL);" \
  >/dev/null 2>&1 || true

info "Running worker until post ${POST_ID} is translated (max ~5 min)"
# Poll the WP side (ground truth: mapping + markers), not client-side records:
# record bookkeeping can lag the callback on busy queues.
wp_roundtrip_done() {
  wp_eval "${SCRIPT_DIR}/php/verify-client-content-roundtrip.php" "relation_id=${RELATION_ID}" "post_id=${POST_ID}" 2>/dev/null \
    | tail -n 1 \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("pass") else 1)' 2>/dev/null
}
# Media leg: full verify (text markers + media mapping/attachment/URL rewrite).
wp_media_done() {
  local extra=()
  [[ -n "${MEDIA_ATTACHMENT_ID:-}" ]] && extra=("attachment_id=${MEDIA_ATTACHMENT_ID}")
  wp_eval "${SCRIPT_DIR}/php/verify-client-content-roundtrip.php" "relation_id=${RELATION_ID}" "post_id=${POST_ID}" "${extra[@]}" 2>/dev/null \
    | tail -n 1 \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("pass") else 1)' 2>/dev/null
}
TRANSLATED=0
for round in $(seq 1 12); do
  if wp_roundtrip_done; then TRANSLATED=1; break; fi
  curl -sf -X POST "${CLIENT_RT_BASE}/api/worker/run-once" \
    -H 'Content-Type: application/json' \
    -d '{"max_iterations":8,"max_elapsed_secs":45,"max_items_per_run":20}' >/dev/null || true
  if wp_roundtrip_done; then TRANSLATED=1; break; fi
  info "round ${round}: post ${POST_ID} not yet translated WP-side; continuing"
done
[[ "${TRANSLATED}" = "1" ]] || abort "post ${POST_ID} was not translated (see ${RUN_DIR}/client.log)"
ok "client translated post ${POST_ID} (WP mapping + markers verified)"

# ---- 4b. Media leg: mapping + target attachment + rewritten URL ----
# The media subtask is a separate field group of the same item; on a busy
# queue its /media-upload + mapping write-back can land a round later than
# the text markers, so it gets its own bounded worker loop.
if [[ -n "${MEDIA_ATTACHMENT_ID}" ]]; then
  MEDIA_DONE=0
  for round in $(seq 1 12); do
    if wp_media_done; then MEDIA_DONE=1; break; fi
    curl -sf -X POST "${CLIENT_RT_BASE}/api/worker/run-once" \
      -H 'Content-Type: application/json' \
      -d '{"max_iterations":8,"max_elapsed_secs":45,"max_items_per_run":20}' >/dev/null || true
    if wp_media_done; then MEDIA_DONE=1; break; fi
    info "media round ${round}: mapping for attachment ${MEDIA_ATTACHMENT_ID} not yet landed; continuing"
  done
  [[ "${MEDIA_DONE}" = "1" ]] || abort "media mapping for attachment ${MEDIA_ATTACHMENT_ID} did not land (see ${RUN_DIR}/client.log)"
  ok "media lane verified (mapping + target attachment + rewritten URL)"
fi

# ---- 5. Final verify (re-run the WP-side checks for the report) ----
info "Verifying WP-side mapping + markers"
VERIFY_ARGS=("relation_id=${RELATION_ID}" "post_id=${POST_ID}")
[[ -n "${MEDIA_ATTACHMENT_ID}" ]] && VERIFY_ARGS+=("attachment_id=${MEDIA_ATTACHMENT_ID}")
VERIFY_OUT="$(wp_eval "${SCRIPT_DIR}/php/verify-client-content-roundtrip.php" "${VERIFY_ARGS[@]}" 2>/dev/null | tail -n 1)"
echo "${VERIFY_OUT}" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("pass"), d; print("WP verified: target post", d["target_post_id"])' \
  || abort "WP-side verification failed: ${VERIFY_OUT}"

SUMMARY="$(python3 -c 'import json,sys; print(json.dumps({"task":"client-content-roundtrip","pass":True,"stamp":sys.argv[1],"relation_id":int(sys.argv[2]),"post_id":int(sys.argv[3]),"client_base":sys.argv[4],"mock_base":sys.argv[5],"media_attachment_id":int(sys.argv[6] or 0)}))' "${STAMP}" "${RELATION_ID}" "${POST_ID}" "${CLIENT_RT_BASE}" "${MOCK_API_BASE}" "${MEDIA_ATTACHMENT_ID:-0}")"
echo "${SUMMARY}" | python3 -m json.tool >"${REPORT_ROOT}/summary-${STAMP}.json"

ok "client content round-trip PASS (report: ${REPORT_ROOT}/summary-${STAMP}.json)"
