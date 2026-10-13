#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"

# Fail closed outside lab mode: the host fallback (legacy /var/www/wordpress +
# /usr/local/bin/wp) does not exist on this checkout. A lane run without a
# usable WP environment must exit non-zero, never pass-by-default.
if ! _is_lab_mode; then
  if [[ ! -d "${WP_ROOT}" || ! -x "${WP_CLI}" ]]; then
    echo "[language-pack-lane] ERROR: host WP environment unavailable (WP_ROOT=${WP_ROOT}, WP_CLI=${WP_CLI})." >&2
    echo "[language-pack-lane]        Use lab mode: WPTSALL_LAB=1 (or source tests/docker-lab/lab.env) before running." >&2
    exit 2
  fi
fi

FIXTURE="small"
DRY_RUN=0
MAX_NEW_ENTRIES=20
MAX_SECONDS=180
RUN_ID="$(date +%Y%m%d-%H%M%S)-$$"
FIXTURE_MARKER="wptsall-language-pack-${RUN_ID}"
REQUIREMENT_ID="REQ-WPTSALL-I18N-001"
USER_JOURNEY_ID="UJ11"

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/run-wptsall-language-pack-lane.sh small
  bash tests/modules/wpmmcc-ats/e2e/run-wptsall-language-pack-lane.sh claim-writeback
  bash tests/modules/wpmmcc-ats/e2e/run-wptsall-language-pack-lane.sh --fixture small
  bash tests/modules/wpmmcc-ats/e2e/run-wptsall-language-pack-lane.sh --fixture full --dry-run

Options:
  --fixture <small|claim-writeback|full>
                             small seeds a tiny fixture; claim-writeback proves REST claim + callback writeback;
                             full is explicit heavy lane mode
  --dry-run                  collect evidence and budget only; do not modify WP data
  --max-new-entries <n>      budget for new template_entries (default: 20)
  --max-seconds <n>          runtime budget for non-dry-run execution (default: 180)
  --fixture-marker <value>   override fixture marker
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    small|claim-writeback|full)
      FIXTURE="$1"
      shift
      ;;
    --fixture)
      FIXTURE="${2:-}"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --max-new-entries)
      MAX_NEW_ENTRIES="${2:-}"
      shift 2
      ;;
    --max-seconds)
      MAX_SECONDS="${2:-}"
      shift 2
      ;;
    --fixture-marker)
      FIXTURE_MARKER="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

case "$FIXTURE" in
  small|claim-writeback|full)
    ;;
  *)
    echo "Unsupported fixture: ${FIXTURE}" >&2
    usage
    exit 1
    ;;
esac

if [[ "${FIXTURE}" == "claim-writeback" ]]; then
  TEST_ID="TEST-WPTSALL-LANGUAGE-PACK-CLAIM-WRITEBACK-001"
  SCENARIO_ID="SCN-WPTSALL-LANGUAGE-PACK-CLAIM-WRITEBACK"
elif [[ "${FIXTURE}" == "full" ]]; then
  TEST_ID="TEST-WPTSALL-LANGUAGE-PACK-FULL-BUDGET-001"
  SCENARIO_ID="SCN-WPTSALL-LANGUAGE-PACK-FULL-BUDGET"
else
  TEST_ID="TEST-WPTSALL-LANGUAGE-PACK-FIXTURE-001"
  SCENARIO_ID="SCN-WPTSALL-LANGUAGE-PACK-FIXTURE"
fi

ensure_dirs

LANE_DIR="${RUNTIME_DIR}/wptsall-language-pack/${RUN_ID}"
mkdir -p "${LANE_DIR}"
REPORT_JSON="${REPORTS_DIR}/wptsall-language-pack-${FIXTURE}-${RUN_ID}.json"
BEFORE_JSON="${LANE_DIR}/evidence-before.json"
AFTER_JSON="${LANE_DIR}/evidence-after.json"
SETUP_LOG="${LANE_DIR}/setup-relations.log"
CLIENT_API_LOG="${LANE_DIR}/ensure-client-api-runtime.log"
SEED_LOG="${LANE_DIR}/seed.log"
DISCOVERY_JSON="${LANE_DIR}/language-pack-discovery.json"
CLAIM_REQUEST_JSON="${LANE_DIR}/language-pack-claim-request.json"
CLAIM_RESPONSE_JSON="${LANE_DIR}/language-pack-claim-response.json"
CALLBACK_REQUEST_JSON="${LANE_DIR}/language-pack-callback-request.json"
CALLBACK_RESPONSE_JSON="${LANE_DIR}/language-pack-callback-response.json"
BUDGET_PRE_JSON="${LANE_DIR}/heavy-budget-pre.json"
CATALOG_JSON="${REPO_ROOT}/tests/infra/test-catalog.json"

wp_eval_file_to() {
  local output_file="$1"
  local php_file="$2"
  shift 2
  if _is_lab_mode; then
    # Keep stderr (wp-cli bootstrap warnings) out of the JSON evidence file.
    wp_lab_docker_exec eval-file "$(wp_lab_map_path "${php_file}")" "$@" >"${output_file}"
  else
    (
      cd "${WP_ROOT}"
      "${WP_CLI}" eval-file "${php_file}" "$@"
    ) >"${output_file}" 2>&1
  fi
}

wp_eval_value() {
  local expression="$1"
  if _is_lab_mode; then
    wp_lab_docker_exec eval "${expression}" 2>/dev/null
  else
    (
      cd "${WP_ROOT}"
      "${WP_CLI}" eval "${expression}"
    ) 2>/dev/null
  fi
}

json_metric() {
  local file="$1"
  local expr="$2"
  python3 - <<'PY' "$file" "$expr"
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
if not path.exists():
    print(0)
    raise SystemExit(0)
payload = json.loads(path.read_text(encoding="utf-8"))
value = payload
for part in sys.argv[2].split("."):
    if isinstance(value, dict):
        value = value.get(part, 0)
    else:
        value = 0
        break
print(int(value or 0))
PY
}

write_report() {
  local status="$1"
  local message="$2"
  python3 - <<'PY' "$REPORT_JSON" "$status" "$message" "$FIXTURE" "$DRY_RUN" "$RUN_ID" "$FIXTURE_MARKER" "$BEFORE_JSON" "$AFTER_JSON" "$MAX_NEW_ENTRIES" "$MAX_SECONDS" "$TEST_ID" "$REQUIREMENT_ID" "$USER_JOURNEY_ID" "$SCENARIO_ID" "$DISCOVERY_JSON" "$CLAIM_REQUEST_JSON" "$CLAIM_RESPONSE_JSON" "$CALLBACK_REQUEST_JSON" "$CALLBACK_RESPONSE_JSON" "$CATALOG_JSON"
import json
import sys
from pathlib import Path

(
    report_path,
    status,
    message,
    fixture,
    dry_run,
    run_id,
    fixture_marker,
    before_json,
    after_json,
    max_new_entries,
    max_seconds,
    test_id,
    requirement_id,
    user_journey_id,
    scenario_id,
    discovery_json,
    claim_request_json,
    claim_response_json,
    callback_request_json,
    callback_response_json,
    catalog_json,
) = sys.argv[1:]

import datetime

def load(path):
    p = Path(path)
    if not p.exists() or p.stat().st_size == 0:
        return {}
    try:
        return json.loads(p.read_text(encoding="utf-8"))
    except Exception as exc:
        return {"parse_error": str(exc)}

before = load(before_json)
after = load(after_json)
discovery = load(discovery_json)
claim_response = load(claim_response_json)
callback_response = load(callback_response_json)
catalog = load(catalog_json)

catalog_match = any(item.get("test_id") == test_id for item in catalog.get("tests", []))
fixture_after = after.get("fixture", {}) if isinstance(after, dict) else {}
counts = {
    "claimed": int(claim_response.get("claimed_count") or 0),
    "completed": int(callback_response.get("entries_updated") or 0),
    "synced": int(callback_response.get("entries_updated") or 0),
    "error": 0 if status in {"passed", "planned"} else 1,
    "fixture_entries": int(fixture_after.get("template_entries") or 0),
    "fixture_claimed_entries": int(fixture_after.get("claimed_entries") or 0),
    "fixture_translated_entries": int(fixture_after.get("translated_entries") or 0),
}

warnings = []
if not catalog_match:
    warnings.append(f"test_id {test_id} not found in tests/infra/test-catalog.json")

report = {
    "status": status,
    "message": message,
    # report-schema contract: every report carries a timestamp field.
    "generated": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "test_id": test_id,
    "requirement_id": requirement_id,
    "user_journey_id": [user_journey_id],
    "scenario_id": scenario_id,
    "run_id": run_id,
    "fixture": fixture,
    "fixture_marker": fixture_marker,
    "dry_run": dry_run == "1",
    "lane_scope": "language_pack_claim_writeback" if fixture == "claim-writeback" else "language_pack_fixture_schema",
    "not_full_scan": True,
    "counts": counts,
    "evidence_paths": [
        before_json,
        after_json,
        discovery_json,
        claim_request_json,
        claim_response_json,
        callback_request_json,
        callback_response_json,
        str(Path(before_json).with_name("heavy-budget-pre.json")),
    ],
    "evidence": {
        "before": before_json,
        "after": after_json,
        "discovery": discovery_json,
        "claim_request": claim_request_json,
        "claim_response": claim_response_json,
        "callback_request": callback_request_json,
        "callback_response": callback_response_json,
        "heavy_budget_pre": str(Path(before_json).with_name("heavy-budget-pre.json")),
    },
    "budgets": {
        "max_new_entries": int(max_new_entries),
        "max_seconds": int(max_seconds),
    },
    "catalog": {
        "path": catalog_json,
        "test_id_found": catalog_match,
    },
    "failure_classification": {
        "seed_schema": status == "failed" and "seed" in message.lower(),
        "claim": status == "failed" and "claim" in message.lower(),
        "client_mock": status == "failed" and "client/mock" in message.lower(),
        "callback_writeback": status == "failed" and ("callback" in message.lower() or "writeback" in message.lower()),
        "cleanup_infra": status == "failed" and "infra" in message.lower(),
    },
    "warnings": warnings,
}
Path(report_path).write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
PY
}

http_json() {
  local method="$1"
  local url="$2"
  local token="$3"
  local body="$4"
  local output_file="$5"
  local http_code
  local device_id="${WPTSALL_DEVICE_ID:-e2e-shell}"
  local ts nonce signature

  # Protocol v2 requires timestamp + nonce + HMAC signature (same contract as
  # the Rust client / Transport_Middleware::validate_protocol_v2_request).
  read -r ts nonce signature < <(python3 - <<'PY' "${method}" "${url}" "${token}" "${body}"
import base64, hashlib, hmac, sys, time, uuid
from urllib.parse import urlsplit, parse_qsl, quote

method, url, token, body = sys.argv[1:5]
body_bytes = body.encode("utf-8") if body else b""

def hkdf_sha256(ikm: bytes, salt: bytes, info: bytes, length: int = 32) -> bytes:
    if hasattr(hashlib, "hkdf"):
        return hashlib.hkdf(ikm, salt=salt, info=info, hash=hashlib.sha256, length=length)
    # Fallback HKDF-Extract/Expand
    prk = hmac.new(salt, ikm, hashlib.sha256).digest()
    okm = b""
    prev = b""
    counter = 1
    while len(okm) < length:
        prev = hmac.new(prk, prev + info + bytes([counter]), hashlib.sha256).digest()
        okm += prev
        counter += 1
    return okm[:length]

def canonical_query_component(value: str) -> str:
    out = []
    for byte in value.encode("utf-8"):
        ch = chr(byte)
        if ("A" <= ch <= "Z") or ("a" <= ch <= "z") or ("0" <= ch <= "9") or ch in "-_.~":
            out.append(ch)
        else:
            out.append(f"%{byte:02X}")
    return "".join(out)

parts = urlsplit(url)
path = parts.path
if path.startswith("/wp-json"):
    path = path[len("/wp-json"):]
pairs = parse_qsl(parts.query, keep_blank_values=True)
pairs.sort(key=lambda kv: (kv[0], kv[1]))
if pairs:
    path = path + "?" + "&".join(
        f"{canonical_query_component(k)}={canonical_query_component(v)}" for k, v in pairs
    )

timestamp = str(int(time.time()))
nonce = str(uuid.uuid4())
body_hash = hashlib.sha256(body_bytes).hexdigest()
headers_hash = hashlib.sha256(b"").hexdigest()
canonical = f"{method.upper()}\n{path}\n{timestamp}\n{nonce}\n{body_hash}\n{headers_hash}"
signing_key = hkdf_sha256(
    token.encode("utf-8"),
    b"request-signing",
    b"wptsall-signing-v1",
    32,
)
mac = hmac.new(signing_key, canonical.encode("utf-8"), hashlib.sha256).digest()
signature = base64.urlsafe_b64encode(mac).decode("ascii").rstrip("=")
print(timestamp, nonce, signature)
PY
)

  if [[ "${method}" == "GET" ]]; then
    http_code="$(curl -k -sS -o "${output_file}" -w "%{http_code}" \
      -H "X-WPTSALL-Protocol-Version: 2" \
      -H "X-WPTSALL-Device-Id: ${device_id}" \
      -H "X-WPTSALL-Client-Token: ${token}" \
      -H "X-WPTSALL-Timestamp: ${ts}" \
      -H "X-WPTSALL-Signature-Nonce: ${nonce}" \
      -H "X-WPTSALL-Signature: ${signature}" \
      "${url}")"
  else
    http_code="$(curl -k -sS -o "${output_file}" -w "%{http_code}" \
      -X "${method}" \
      -H "Content-Type: application/json" \
      -H "X-WPTSALL-Protocol-Version: 2" \
      -H "X-WPTSALL-Device-Id: ${device_id}" \
      -H "X-WPTSALL-Client-Token: ${token}" \
      -H "X-WPTSALL-Timestamp: ${ts}" \
      -H "X-WPTSALL-Signature-Nonce: ${nonce}" \
      -H "X-WPTSALL-Signature: ${signature}" \
      --data "${body}" \
      "${url}")"
  fi

  [[ "${http_code}" =~ ^2[0-9][0-9]$ ]]
}

extract_fixture_entry_ids() {
  local evidence_file="$1"
  python3 - <<'PY' "${evidence_file}"
import json
import sys
from pathlib import Path
payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
ids = [str(item["entry_id"]) for item in payload.get("fixture", {}).get("entries", []) if item.get("entry_id")]
print(",".join(ids))
PY
}

build_claim_request() {
  local discovery_file="$1"
  local relation_id="$2"
  local output_file="$3"
  python3 - <<'PY' "${discovery_file}" "${relation_id}" "${output_file}"
import json
import sys
from pathlib import Path
discovery = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
items = []
for item in discovery.get("items", []):
    entry_id = item.get("complete_data", {}).get("entry_id") or item.get("entry_id") or item.get("object_id")
    if entry_id:
        items.append({"entry_id": int(entry_id)})
payload = {"relation_id": int(sys.argv[2]), "data_type": "language_pack", "subtype": "plugin", "items": items}
Path(sys.argv[3]).write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(len(items))
PY
}

build_callback_request() {
  local discovery_file="$1"
  local relation_id="$2"
  local output_file="$3"
  # Callback langs must match the site relation (server validates
  # target_language_mismatch); derive them from the relation row itself.
  local relation_langs src_lang tgt_lang
  relation_langs="$(wp_eval_value 'if(true){$g=$GLOBALS["wpdb"];$r=$g->get_row("SELECT source_lang, target_lang FROM ".$g->prefix."wptsall_site_relations WHERE id = '"${relation_id}"'", ARRAY_A);echo wp_json_encode($r);}' || true)"
  src_lang="$(printf '%s' "${relation_langs}" | python3 -c 'import json,sys
try:
    d = json.loads(sys.stdin.read() or "{}")
except Exception:
    d = {}
print(d.get("source_lang") or "en_US")' 2>/dev/null || echo "en_US")"
  tgt_lang="$(printf '%s' "${relation_langs}" | python3 -c 'import json,sys
try:
    d = json.loads(sys.stdin.read() or "{}")
except Exception:
    d = {}
print(d.get("target_lang") or "zh_CN")' 2>/dev/null || echo "zh_CN")"
  python3 - "$discovery_file" "$relation_id" "$RUN_ID" "$FIXTURE_MARKER" "$output_file" "$src_lang" "$tgt_lang" <<'PY'
import json
import sys
from pathlib import Path
discovery = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
entries = []
for item in discovery.get("items", []):
    complete = item.get("complete_data", {})
    entry_id = complete.get("entry_id") or item.get("entry_id") or item.get("object_id")
    msgid = complete.get("msgid") or f"entry-{entry_id}"
    if entry_id:
        entries.append({
            "entry_id": int(entry_id),
            "msgstr": f"[{sys.argv[4]}] translated {msgid}",
        })
payload = {
    "business_line": "plugin_i18n",
    "client_task_id": f"e2e-language-pack-{sys.argv[3]}",
    "relation_id": int(sys.argv[2]),
    "worker_id": "e2e-language-pack-lane",
    "source_lang": sys.argv[6],
    "target_lang": sys.argv[7],
    "entries": entries,
}
Path(sys.argv[5]).write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(len(entries))
PY
}

start_ts="$(date +%s)"

echo "WPTSALL language pack lane"
echo "  fixture: ${FIXTURE}"
echo "  dry_run: ${DRY_RUN}"
echo "  test_id: ${TEST_ID}"
echo "  report:  ${REPORT_JSON}"

if [[ "${FIXTURE}" == "full" ]]; then
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    bash "${SCRIPT_DIR}/heavy-gate-budget.sh" \
      --lane language-pack-full \
      --phase pre \
      --run-id "${RUN_ID}" \
      --output "${BUDGET_PRE_JSON}" \
      --dry-run \
      --warn-only
    write_report "planned" "full language-pack dry-run budget only; no WP setup or scan executed"
    echo "${REPORT_JSON}"
    exit 0
  else
    bash "${SCRIPT_DIR}/heavy-gate-budget.sh" \
      --lane language-pack-full \
      --phase pre \
      --run-id "${RUN_ID}" \
      --output "${BUDGET_PRE_JSON}" \
      --require-snapshot
  fi
fi

if ! wp_eval_file_to "${SETUP_LOG}" "${E2E_DIR}/php/setup-relations.php" "fixed-virtual=1"; then
  write_report "failed" "setup-relations failed"
  cat "${SETUP_LOG}" >&2 || true
  exit 1
fi

if ! wp_eval_file_to "${CLIENT_API_LOG}" "${E2E_DIR}/php/ensure-client-api-runtime.php"; then
  write_report "failed" "client api runtime setup failed"
  cat "${CLIENT_API_LOG}" >&2 || true
  exit 1
fi

if ! wp_eval_file_to "${BEFORE_JSON}" "${E2E_DIR}/php/collect-wptsall-language-pack-evidence.php" "run_id=${RUN_ID}" "fixture_marker=${FIXTURE_MARKER}"; then
  write_report "failed" "failed to collect before evidence"
  cat "${BEFORE_JSON}" >&2 || true
  exit 1
fi

before_entries="$(json_metric "${BEFORE_JSON}" "totals.template_entries")"

if [[ "${DRY_RUN}" -eq 1 ]]; then
  cp "${BEFORE_JSON}" "${AFTER_JSON}"
  write_report "planned" "dry-run only; no language pack data changed"
  echo "${REPORT_JSON}"
  exit 0
fi

case "$FIXTURE" in
  small|claim-writeback)
    if ! wp_eval_file_to "${SEED_LOG}" "${E2E_DIR}/php/seed-wptsall-language-pack-fixture.php" "fixture_marker=${FIXTURE_MARKER}" "entries=4"; then
      write_report "failed" "${FIXTURE} fixture seed failed"
      cat "${SEED_LOG}" >&2 || true
      exit 1
    fi
    ;;
  full)
    echo "ERROR: full language-pack scan is intentionally not implicit. Re-run with --dry-run first, then wire an explicit scanner command for maintenance cadence." >&2
    write_report "failed" "full fixture without dry-run is blocked until a maintenance scanner command is explicitly selected"
    exit 1
    ;;
esac

if ! wp_eval_file_to "${AFTER_JSON}" "${E2E_DIR}/php/collect-wptsall-language-pack-evidence.php" "run_id=${RUN_ID}" "fixture_marker=${FIXTURE_MARKER}"; then
  write_report "failed" "failed to collect after-seed evidence"
  cat "${AFTER_JSON}" >&2 || true
  exit 1
fi

after_entries="$(json_metric "${AFTER_JSON}" "totals.template_entries")"
fixture_entries="$(json_metric "${AFTER_JSON}" "fixture.template_entries")"
new_entries=$((after_entries - before_entries))
elapsed=$(( $(date +%s) - start_ts ))

if (( new_entries > MAX_NEW_ENTRIES )); then
  write_report "failed" "new template_entries exceeded budget"
  echo "ERROR: new_template_entries=${new_entries}, budget=${MAX_NEW_ENTRIES}" >&2
  exit 1
fi

if (( elapsed > MAX_SECONDS )); then
  write_report "failed" "runtime exceeded budget"
  echo "ERROR: elapsed=${elapsed}s, budget=${MAX_SECONDS}s" >&2
  exit 1
fi

if [[ "${fixture_entries}" -le 0 ]]; then
  write_report "failed" "${FIXTURE} fixture produced no template entries"
  exit 1
fi

if [[ "${FIXTURE}" == "small" ]]; then
  write_report "passed" "language pack small fixture/schema lane passed"
  echo "${REPORT_JSON}"
  echo "  new_template_entries=${new_entries}"
  echo "  fixture_template_entries=${fixture_entries}"
  exit 0
fi

RELATION_ID="$(python3 - <<'PY' "${SEED_LOG}"
import json
import sys
from pathlib import Path
payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
print(int(payload.get("relation_id") or 0))
PY
)"
if [[ "${RELATION_ID}" -le 0 ]]; then
  write_report "failed" "seed relation_id missing"
  exit 1
fi

DEVICE_JSON="${WPTSALL_E2E_DEVICE_JSON:-$(wp_eval_value 'if(function_exists("wptsall_issue_client_device_token")){$d=wptsall_issue_client_device_token("e2e-shell","e2e"); echo wp_json_encode($d);}' || true)}"
if [[ -n "${DEVICE_JSON}" ]] && command -v python3 >/dev/null 2>&1; then
  WP_CLIENT_TOKEN="${WPTSALL_E2E_WP_CLIENT_TOKEN:-$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("token",""))' "${DEVICE_JSON}" 2>/dev/null || true)}"
  WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-$(python3 -c 'import json,sys; d=json.loads(sys.argv[1] or "{}"); print(d.get("device_id",""))' "${DEVICE_JSON}" 2>/dev/null || true)}"
else
  WP_CLIENT_TOKEN="${WPTSALL_E2E_WP_CLIENT_TOKEN:-}"
fi
export WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-e2e-shell}"
ROUTE_SECRET="${WPTSALL_E2E_ROUTE_SECRET:-$(wp_eval_value 'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";' || true)}"
if [[ -z "${WP_CLIENT_TOKEN}" || -z "${ROUTE_SECRET}" || -z "${WPTSALL_DEVICE_ID}" ]]; then
  write_report "failed" "missing live wp device token, device id, or route secret"
  exit 1
fi

ENTRY_IDS="$(extract_fixture_entry_ids "${AFTER_JSON}")"
if [[ -z "${ENTRY_IDS}" ]]; then
  write_report "failed" "no fixture entry ids available for claim"
  exit 1
fi

CLIENT_BASE="${WP_URL%/}/wp-json/wptsall/v2/${ROUTE_SECRET}/client"
DISCOVERY_URL="${CLIENT_BASE}/content?relation_id=${RELATION_ID}&data_type=language_pack&subtype=plugin&include_ids=${ENTRY_IDS}&page=1&per_page=20"
if ! http_json "GET" "${DISCOVERY_URL}" "${WP_CLIENT_TOKEN}" "" "${DISCOVERY_JSON}"; then
  write_report "failed" "language pack discovery failed"
  cat "${DISCOVERY_JSON}" >&2 || true
  exit 1
fi

claim_items="$(build_claim_request "${DISCOVERY_JSON}" "${RELATION_ID}" "${CLAIM_REQUEST_JSON}")"
if [[ "${claim_items}" -le 0 ]]; then
  write_report "failed" "language pack discovery returned no claimable fixture entries"
  exit 1
fi

if ! http_json "POST" "${CLIENT_BASE}/content/claim" "${WP_CLIENT_TOKEN}" "$(tr -d '\n' < "${CLAIM_REQUEST_JSON}")" "${CLAIM_RESPONSE_JSON}"; then
  write_report "failed" "language pack claim failed"
  cat "${CLAIM_RESPONSE_JSON}" >&2 || true
  exit 1
fi

claimed_count="$(json_metric "${CLAIM_RESPONSE_JSON}" "claimed_count")"
if [[ "${claimed_count}" -le 0 ]]; then
  write_report "failed" "language pack claim returned zero claimed entries"
  exit 1
fi

callback_entries="$(build_callback_request "${DISCOVERY_JSON}" "${RELATION_ID}" "${CALLBACK_REQUEST_JSON}")"
if [[ "${callback_entries}" -le 0 ]]; then
  write_report "failed" "language pack callback payload has no entries"
  exit 1
fi

if ! http_json "POST" "${CLIENT_BASE}/translation-callback" "${WP_CLIENT_TOKEN}" "$(tr -d '\n' < "${CALLBACK_REQUEST_JSON}")" "${CALLBACK_RESPONSE_JSON}"; then
  write_report "failed" "language pack callback/writeback failed"
  cat "${CALLBACK_RESPONSE_JSON}" >&2 || true
  exit 1
fi

entries_updated="$(json_metric "${CALLBACK_RESPONSE_JSON}" "entries_updated")"
if [[ "${entries_updated}" -lt "${claimed_count}" ]]; then
  write_report "failed" "language pack callback updated fewer entries than claimed"
  exit 1
fi

if ! wp_eval_file_to "${AFTER_JSON}" "${E2E_DIR}/php/collect-wptsall-language-pack-evidence.php" "run_id=${RUN_ID}" "fixture_marker=${FIXTURE_MARKER}"; then
  write_report "failed" "failed to collect after callback evidence"
  cat "${AFTER_JSON}" >&2 || true
  exit 1
fi

translated_entries="$(json_metric "${AFTER_JSON}" "fixture.translated_entries")"
if [[ "${translated_entries}" -lt "${fixture_entries}" ]]; then
  write_report "failed" "language pack writeback did not translate all fixture entries"
  exit 1
fi

write_report "passed" "language pack claim/writeback lane passed"
echo "${REPORT_JSON}"
echo "  claimed=${claimed_count}"
echo "  callback_entries_updated=${entries_updated}"
echo "  fixture_translated_entries=${translated_entries}"
