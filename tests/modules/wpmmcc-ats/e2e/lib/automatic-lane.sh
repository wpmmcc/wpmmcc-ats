#!/usr/bin/env bash
# Shared focused automatic-lane infrastructure (P0-EV-03).
#
# The three focused lanes (run-automatic-content-surfaces-gate.sh,
# run-automatic-consistency-gate.sh, run-automatic-topology-time-gate.sh)
# MUST source this helper. It owns and isolates everything a lane needs and
# never reuses a developer/systemd Client, a stray mock provider, or the
# website control plane:
#
#   source "$(dirname "$0")/lib/automatic-lane.sh"
#   auto_lane_init "<lane-name>" || exit 1          # report dir, state root, traps
#   auto_lane_plan_ports                            # pick owned ports (call once)
#   auto_lane_preflight || exit 1                   # port sanity vs shared ports
#   auto_lane_start_owned_infrastructure || exit 1  # canary + mock + client(flag 0)
#   auto_lane_assert_client_mode <expected> || exit 1
#   auto_lane_lab_credentials || exit 1             # Lab WP token/route secret
#   auto_lane_upsert_site_binding <wp_url> <token> <secret>
#   auto_lane_mock_stats_read before|after
#   auto_lane_mock_stats_assert_expected <min_total_delta> || FAIL=1
#   auto_lane_mock_stats_assert_zero_delta || FAIL=1
#   auto_lane_control_plane_canary_assert || FAIL=1
#   auto_lane_record_assertion <id> <pass|fail> [detail]
#   auto_lane_fixture <fixture-id>
#   auto_lane_finish                                # stop at a terminal state
#   auto_lane_write_report                          # timestamped lane-report.json
#
# Report fields (P0-EV-03 "Shared focused-lane report"): dry_run, direct-WP
# mode + control-plane flag, owned infrastructure, fixture ids, assertions +
# counts, provider counter delta, control-plane canary count, cleanup result.
#
# The recording canary doubles as WPTSALL_SERVER_BASE/SERVER_URL: any website
# or control-plane contact by the client is captured there and must be zero.
# shellcheck shell=bash

if [[ -n "${_WPTSALL_AUTOMATIC_LANE_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
_WPTSALL_AUTOMATIC_LANE_LOADED=1

AL_LANE_NAME="${AL_LANE_NAME:-automatic-lane}"
AL_RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
AL_REPORT_DIR=""
AL_STATE_ROOT=""
AL_CLIENT_PID=""
AL_MOCK_PID=""
AL_CANARY_PID=""
AL_CLIENT_PORT=""
AL_MOCK_PORT=""
AL_CANARY_PORT=""
AL_CANARY_URL=""
AL_MOCK_STATS_BEFORE=""
AL_MOCK_STATS_AFTER=""
AL_MOCK_REACHABLE="true"
AL_CANARY_COUNT="unknown"
AL_CLIENT_MODE="unknown"
AL_WP_URL=""
AL_FIXTURE_IDS=()
AL_ASSERTION_IDS=()
AL_ASSERTION_RESULTS=()
AL_ASSERTION_DETAILS=()
AL_CLEANUP_RESULT="pending"
AL_FINISHED="0"
AL_STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

al_note() { info "auto-lane[${AL_LANE_NAME}]: $*"; }
al_json_escape() { python3 -c 'import json, sys; sys.stdout.write(json.dumps(sys.argv[1]))' "$1"; }

# ---------------------------------------------------------------------------
# Init + cleanup trap
# ---------------------------------------------------------------------------
auto_lane_init() {
  AL_LANE_NAME="${1:-${AL_LANE_NAME}}"
  AL_REPORT_DIR="${REPO_ROOT:-$(cd "$(dirname "$0")/../../.." ${SCRIPT_DIR}/reports${SCRIPT_DIR}/reports pwd)/tests/reports/e2e/wpmmcc-ats}/automatic-lanes/${AL_LANE_NAME}/${AL_RUN_ID}"
  mkdir -p "${AL_REPORT_DIR}" || return 1
  AL_STATE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wptsall-auto-lane.XXXXXX")"
  mkdir -p "${AL_STATE_ROOT}/state" "${AL_STATE_ROOT}/artifacts" "${AL_STATE_ROOT}/canary"
  al_note "run ${AL_RUN_ID}; state root ${AL_STATE_ROOT}"
  trap auto_lane_cleanup EXIT INT TERM
  return 0
}

auto_lane_cleanup() {
  if [[ -n "${AL_CLIENT_PID}" ]] && kill -0 "${AL_CLIENT_PID}" 2>/dev/null; then
    kill "${AL_CLIENT_PID}" 2>/dev/null || true
    for _ in $(seq 1 20); do
      kill -0 "${AL_CLIENT_PID}" 2>/dev/null || break
      sleep 0.5
    done
    kill -9 "${AL_CLIENT_PID}" 2>/dev/null || true
  fi
  [[ -z "${AL_MOCK_PID}" ]] || kill "${AL_MOCK_PID}" 2>/dev/null || true
  [[ -z "${AL_CANARY_PID}" ]] || kill "${AL_CANARY_PID}" 2>/dev/null || true
  if [[ -n "${AL_STATE_ROOT}" && -d "${AL_STATE_ROOT}" ]]; then
    cp "${AL_STATE_ROOT}"/state/client-*.log "${AL_REPORT_DIR}/" 2>/dev/null || true
    # P8 observability: preserve the structured client log (log_event output,
    # incl. outbox_backoff_skip / circuit_open evidence) with the report.
    cp "${AL_STATE_ROOT}"/state/client.log "${AL_REPORT_DIR}/client-runtime.log" 2>/dev/null || true
    cp "${AL_STATE_ROOT}"/state/mock-stdout.log "${AL_REPORT_DIR}/" 2>/dev/null || true
    cp "${AL_STATE_ROOT}"/canary/requests.log "${AL_REPORT_DIR}/canary-requests.log" 2>/dev/null || true
    cp -r "${AL_STATE_ROOT}/artifacts" "${AL_REPORT_DIR}/" 2>/dev/null || true
    rm -rf "${AL_STATE_ROOT}"
  fi
  AL_CLEANUP_RESULT="ok"
  auto_lane_write_report >/dev/null 2>&1 || true
  ok "auto-lane[${AL_LANE_NAME}] cleanup: owned client/mock/canary stopped, temp state removed (report: ${AL_REPORT_DIR})"
}

# ---------------------------------------------------------------------------
# Ports + preflight
# ---------------------------------------------------------------------------
auto_lane_plan_ports() {
  AL_CLIENT_PORT="$(auto_lane_choose_free_port)"
  AL_MOCK_PORT="$(auto_lane_choose_free_port)"
  AL_CANARY_PORT="$(auto_lane_choose_free_port)"
}

auto_lane_choose_free_port() {
  python3 - <<'PY'
import socket
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()
PY
}

auto_lane_preflight() {
  local p
  for p in "${AL_CLIENT_PORT}" "${AL_MOCK_PORT}" "${AL_CANARY_PORT}"; do
    case "${p}" in
      8977|9090|8787|'')
        err "auto-lane[${AL_LANE_NAME}]: owned port selection collided with shared ports (${p})"
        return 1
        ;;
    esac
  done
  al_note "preflight ok: client=${AL_CLIENT_PORT} mock=${AL_MOCK_PORT} canary=${AL_CANARY_PORT} (8977/9090/8787 untouched)"
  return 0
}

# ---------------------------------------------------------------------------
# Owned infrastructure: recording canary + mock provider + owned client
# ---------------------------------------------------------------------------
# 批C (X-8) shared binary resolution. Priority: AL_*_BIN override (a
# prebuilt binary keeps build time out of lane timing / performance
# evidence) → existing FRESH binary → build only when stale. Freshness =
# any *.rs under the crate's src/ or the Cargo.toml/Cargo.lock newer than
# the binary (mtime). The old guard only checked existence, so an
# outdated mock/client binary silently ran the lane.
auto_lane_bin_is_stale() {
  local bin="$1" src_dir="$2" crate_dir="$3"
  [[ -x "${bin}" ]] || return 0
  if [[ -n "$(find "${src_dir}" -name '*.rs' -newer "${bin}" -print -quit 2>/dev/null)" ]]; then
    return 0
  fi
  local f
  for f in "${crate_dir}/Cargo.toml" "${crate_dir}/Cargo.lock"; do
    [[ -f "${f}" && "${f}" -nt "${bin}" ]] && return 0
  done
  return 1
}

auto_lane_start_owned_infrastructure() {
  local client_src="${REPO_ROOT}/client-wpplugin/source"
  local mock_src="${REPO_ROOT}/tests/infra/mock-api"

  # 1. Recording canary (control-plane base; must stay empty).
  cat > "${AL_STATE_ROOT}/canary/canary_server.py" <<'PYEOF'
import http.server
import socketserver
import sys

port = int(sys.argv[1])
log_path = sys.argv[2]

class CanaryHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _respond(self):
        with open(log_path, "a") as f:
            f.write("%s %s\n" % (self.command, self.path))
        body = b"canary: no content"
        self.send_response(404)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = _respond

    def log_message(self, *args):
        pass

socketserver.TCPServer.allow_reuse_address = True
with socketserver.TCPServer(("127.0.0.1", port), CanaryHandler) as httpd:
    httpd.serve_forever()
PYEOF
  python3 "${AL_STATE_ROOT}/canary/canary_server.py" "${AL_CANARY_PORT}" \
    "${AL_STATE_ROOT}/canary/requests.log" &
  AL_CANARY_PID=$!
  sleep 0.5
  kill -0 "${AL_CANARY_PID}" 2>/dev/null || { err "canary failed to start"; return 1; }
  AL_CANARY_URL="http://127.0.0.1:${AL_CANARY_PORT}"
  al_note "recording canary at ${AL_CANARY_URL}"

  # 2. Mock translate provider (owned instance, owned port).
  # 批C (X-8): AL_MOCK_BIN override skips the build entirely (prebuilt
  # mock for timing-sensitive lanes); otherwise rebuild only when stale.
  local mock_bin="${AL_MOCK_BIN:-${mock_src}/target/debug/mock-translate-api}"
  if [[ -n "${AL_MOCK_BIN:-}" ]]; then
    [[ -x "${mock_bin}" ]] || { err "AL_MOCK_BIN override is not executable: ${mock_bin}"; return 1; }
    al_note "mock provider: AL_MOCK_BIN override ${mock_bin} (build skipped)"
  elif auto_lane_bin_is_stale "${mock_bin}" "${mock_src}/src" "${mock_src}"; then
    al_note "mock provider binary stale (source newer) — rebuilding"
    (cargo build --manifest-path "${mock_src}/Cargo.toml" --quiet) \
      || { err "mock-translate-api build failed"; return 1; }
  fi
  [[ -x "${mock_bin}" ]] || { err "mock-translate-api binary missing at ${mock_bin}"; return 1; }
  (cd "${mock_src}" && MOCK_TRANSLATE_PORT="${AL_MOCK_PORT}" exec "${mock_bin}" \
    >"${AL_STATE_ROOT}/state/mock-stdout.log" 2>&1) &
  AL_MOCK_PID=$!
  sleep 1
  kill -0 "${AL_MOCK_PID}" 2>/dev/null || { err "mock provider exited immediately"; return 1; }
  local mock_ready=""
  for _ in $(seq 1 15); do
    if check_url "http://127.0.0.1:${AL_MOCK_PORT}/api/v1/health"; then mock_ready="yes"; break; fi
    sleep 1
  done
  [[ -n "${mock_ready}" ]] || { err "mock provider did not become ready on port ${AL_MOCK_PORT}"; return 1; }

  # 3. Owned direct-WP Client (flag 0, isolated state root).
  # 批C (X-8): explicit prebuild — build ONLY when the binary is missing
  # or stale. The old unconditional `cargo build` inside every lane run
  # put build time into lane timing/perf evidence; AL_CLIENT_BIN lets a
  # caller hand the lane a prebuilt (e.g. release) binary instead.
  local target_dir="${CARGO_TARGET_DIR:-${client_src}/target}"
  local client_bin="${AL_CLIENT_BIN:-${target_dir}/debug/wptsall-client}"
  if [[ -n "${AL_CLIENT_BIN:-}" ]]; then
    [[ -x "${client_bin}" ]] || { err "AL_CLIENT_BIN override is not executable: ${client_bin}"; return 1; }
    al_note "client: AL_CLIENT_BIN override ${client_bin} (build skipped)"
  elif auto_lane_bin_is_stale "${client_bin}" "${client_src}/src" "${client_src}"; then
    al_note "client binary stale (source newer) — rebuilding"
    (cargo build --manifest-path "${client_src}/Cargo.toml" --bin wptsall-client --quiet) \
      || { err "wptsall-client build failed"; return 1; }
  fi
  [[ -x "${client_bin}" ]] || { err "client binary missing at ${client_bin}"; return 1; }

  : > "${AL_STATE_ROOT}/state/session-token.enc"
  printf 'stale-session-token-not-a-real-credential\n' > "${AL_STATE_ROOT}/state/session-token.enc"

  export WPTSALL_USE_SERVER_CONTROL_PLANE=0
  export WPTSALL_WEB_UI=1
  # Owned-instance observability (P8/R3 standard): the client log defaults to
  # disabled; lanes must capture log_event evidence (backoff/circuit events).
  export WPTSALL_LOG_ENABLED=true
  export WPTSALL_WEB_UI_BIND="127.0.0.1:${AL_CLIENT_PORT}"
  # PORT overrides BIND's port when set — clear parent/shell pollution (e.g. 8978).
  export WPTSALL_WEB_UI_PORT="${AL_CLIENT_PORT}"
  export WPTSALL_SERVER_BASE="${AL_CANARY_URL}"
  export WPTSALL_SERVER_URL="${AL_CANARY_URL}"   # legacy alias, same canary
  export WPTSALL_DB_PATH="${AL_STATE_ROOT}/state/wptsall.db"  export WPTSALL_DATA_DIR="${AL_STATE_ROOT}/state/data"
  export WPTSALL_LOG_FILE="${AL_STATE_ROOT}/state/client.log"
  export WPTSALL_SESSION_TOKEN_FILE="${AL_STATE_ROOT}/state/session-token.enc"
  export WPTSALL_COMPONENT_BINDINGS_FILE="${AL_STATE_ROOT}/state/component-bindings.json"
  export WPTSALL_DOMAIN_TOKEN_BINDINGS_FILE="${AL_STATE_ROOT}/state/domain-token-bindings.json"
  export WPTSALL_TASK_TYPE_COMPONENT_BINDINGS_FILE="${AL_STATE_ROOT}/state/task-type-component-bindings.json"
  export WPTSALL_RULE_COMPONENT_BINDINGS_FILE="${AL_STATE_ROOT}/state/rule-component-bindings.json"
  export WPTSALL_COMPONENTS_LOCAL_FILE="${AL_STATE_ROOT}/state/components-local.json"
  export WPTSALL_PROVIDER_CATALOG_FILE="${AL_STATE_ROOT}/state/provider-catalog.json"
  mkdir -p "${WPTSALL_DATA_DIR}"

  # The owned client must present the device id its device-scoped token was
  # issued for (auto_lane_lab_credentials issues tokens for device
  # "auto-lane"). Without this, the client presents its own generated UUID
  # and every Protocol v2 request 401s, making worker run-once silently
  # process zero tasks (fixed 2026-09-09; see the subsite-translation lane).
  # Inline (not exported): child lanes like the language-pack lane drive the
  # protocol with their own device-scoped tokens and must not inherit this.
  (cd "${client_src}" && WPTSALL_DEVICE_ID="${WPTSALL_DEVICE_ID:-auto-lane}" \
    exec "${client_bin}" \
    >"${AL_STATE_ROOT}/state/client-stdout.log" 2>"${AL_STATE_ROOT}/state/client-stderr.log") &
  AL_CLIENT_PID=$!
  sleep 0.5
  kill -0 "${AL_CLIENT_PID}" 2>/dev/null || { err "owned client exited immediately"; return 1; }

  local ready=""
  for _ in $(seq 1 60); do
    kill -0 "${AL_CLIENT_PID}" 2>/dev/null || { err "owned client exited during readiness"; return 1; }
    if check_url "http://127.0.0.1:${AL_CLIENT_PORT}/api/status"; then ready="yes"; break; fi
    sleep 1
  done
  [[ -n "${ready}" ]] || { err "owned client did not become ready on port ${AL_CLIENT_PORT}"; return 1; }
  al_note "owned client pid=${AL_CLIENT_PID} port=${AL_CLIENT_PORT}; owned mock on ${AL_MOCK_PORT}"
  return 0
}

auto_lane_assert_client_mode() {
  local expected="$1" mode
  mode="$(curl --noproxy '*' -fsS "http://127.0.0.1:${AL_CLIENT_PORT}/api/status" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin).get("data", {}).get("runtime_mode", "unknown"))' \
    2>/dev/null || echo unknown)"
  if [[ "${mode}" != "${expected}" ]]; then
    err "auto-lane[${AL_LANE_NAME}]: expected client runtime_mode=${expected}, got ${mode}"
    return 1
  fi
  AL_CLIENT_MODE="${mode}"
  al_note "client runtime_mode=${mode}"
  return 0
}

# ---------------------------------------------------------------------------
# Lab WordPress credentials + owned-client site binding
# ---------------------------------------------------------------------------
auto_lane_lab_credentials() {
  if ! _is_lab_mode; then
    err "auto-lane[${AL_LANE_NAME}]: focused automatic lanes require WPTSALL_LAB=1 (Lab WordPress)"
    return 1
  fi
  AL_WP_URL="${WP_URL}"
  local probe
  probe="$(curl --noproxy '*' -fsS --max-time 5 -o /dev/null -w '%{http_code}' "${AL_WP_URL}/wp-json/" 2>/dev/null || echo 000)"
  [[ "${probe}" == "200" ]] || {
    err "auto-lane[${AL_LANE_NAME}]: Lab WordPress not reachable at ${AL_WP_URL} (HTTP ${probe})"
    return 1
  }
  AL_WP_CLIENT_TOKEN="${WPTSALL_E2E_WP_CLIENT_TOKEN:-}"
  AL_WP_ROUTE_SECRET="${WPTSALL_E2E_ROUTE_SECRET:-}"
  if [[ -z "${AL_WP_CLIENT_TOKEN}" || -z "${AL_WP_ROUTE_SECRET}" ]]; then
    local resolved
    # wp_eval maps to `wp eval-file` in Lab mode (files only); inline PHP must
    # go through wp_cli (docker exec wp eval '...').
    resolved="$(wp_cli eval 'if(function_exists("wptsall_issue_client_device_token")){$d=wptsall_issue_client_device_token("auto-lane","e2e"); echo (string)$d["token"]."\n".(string) wptsall_get_client_route_secret();}' 2>/dev/null | tr -d '\r')"
    AL_WP_CLIENT_TOKEN="${AL_WP_CLIENT_TOKEN:-$(printf '%s\n' "${resolved}" | sed -n '1p')}"
    AL_WP_ROUTE_SECRET="${AL_WP_ROUTE_SECRET:-$(printf '%s\n' "${resolved}" | sed -n '2p')}"
  fi
  [[ -n "${AL_WP_CLIENT_TOKEN}" && -n "${AL_WP_ROUTE_SECRET}" ]] || {
    err "auto-lane[${AL_LANE_NAME}]: failed to resolve Lab WP device token / route secret"
    return 1
  }
  al_note "Lab WP credentials resolved for ${AL_WP_URL} (token ${AL_WP_CLIENT_TOKEN:0:12}...)"
  return 0
}

auto_lane_upsert_site_binding() {
  local wp_url="$1" token="$2" secret="$3" payload response
  payload="$(jq -n --arg u "${wp_url}" --arg t "${token}" --arg s "${secret}" \
    '{api_base_url: $u, wp_client_token: $t, route_secret: $s}')"
  response="$(curl --noproxy '*' -fsS -X POST "http://127.0.0.1:${AL_CLIENT_PORT}/api/domain-tokens/upsert" \
    -H 'Content-Type: application/json' -d "${payload}" 2>&1)" || {
    err "auto-lane[${AL_LANE_NAME}]: site binding upsert failed: ${response}"
    return 1
  }
  echo "${response}" | jq -e '.success == true' >/dev/null || {
    err "auto-lane[${AL_LANE_NAME}]: site binding upsert rejected: ${response}"
    return 1
  }
  auto_lane_fixture "site-binding:${wp_url}"
  al_note "site binding upserted on owned client for ${wp_url}"
  return 0
}

# ---------------------------------------------------------------------------
# Fixtures + assertions
# ---------------------------------------------------------------------------
auto_lane_fixture() {
  AL_FIXTURE_IDS+=("$1")
}

auto_lane_record_assertion() {
  local id="$1" result="$2" detail="${3:-}"
  AL_ASSERTION_IDS+=("${id}")
  AL_ASSERTION_RESULTS+=("${result}")
  AL_ASSERTION_DETAILS+=("$(al_json_escape "${detail}")")
  if [[ "${result}" == "pass" ]]; then
    ok "assertion ${id}: pass"
  else
    err "assertion ${id}: FAIL ${detail}"
  fi
}

# ---------------------------------------------------------------------------
# Mock provider counters (read before/after; never reset; never start a second)
# ---------------------------------------------------------------------------
auto_lane_mock_stats_read() {
  local label="$1" stats
  stats="$(curl --noproxy '*' -fsS --max-time 5 "http://127.0.0.1:${AL_MOCK_PORT}/api/v1/stats" 2>/dev/null || true)"
  if [[ -z "${stats}" ]]; then
    AL_MOCK_REACHABLE="false"
    al_note "mock stats (${label}): provider unreachable"
    return 1
  fi
  if [[ "${label}" == "before" ]]; then
    AL_MOCK_STATS_BEFORE="${stats}"
  else
    AL_MOCK_STATS_AFTER="${stats}"
  fi
  al_note "mock stats (${label}) recorded"
  return 0
}

auto_lane_mock_stats_delta_json() {
  jq -n --argjson b "${AL_MOCK_STATS_BEFORE:-null}" --argjson a "${AL_MOCK_STATS_AFTER:-null}" '
    def flat(o): reduce (o | to_entries[]) as $e ({};
      . + (if ($e.value | type) == "object" then ($e.value | to_entries | map({key: ($e.key + "." + .key), value: .value}) | from_entries | flat(.)) else {($e.key): $e.value} end));
    { before: (flat($b.data // $b // {})), after: (flat($a.data // $a // {})) }
    | .delta = (.after as $a2 | .before as $b2 | reduce ($a2 | keys[]) as $k ({}; .[$k] = ($a2[$k] // 0) - ($b2[$k] // 0)))
  '
}

auto_lane_mock_stats_assert_zero_delta() {
  if [[ "${AL_MOCK_REACHABLE}" != "true" ]]; then
    auto_lane_record_assertion "provider_counter_absent" "pass" "mock provider absent — counter assertion not_applicable_absent"
    return 0
  fi
  local delta
  delta="$(auto_lane_mock_stats_delta_json)"
  local total_delta
  total_delta="$(printf '%s' "${delta}" | jq -r '.delta.total_requests // 0')"
  if [[ "${total_delta}" != "0" ]]; then
    auto_lane_record_assertion "provider_counter_zero_delta" "fail" "total_requests delta=${total_delta} (expected 0): ${delta}"
    return 1
  fi
  auto_lane_record_assertion "provider_counter_zero_delta" "pass" "all counters unchanged"
  return 0
}

auto_lane_mock_stats_assert_min_total() {
  local min_delta="$1"
  if [[ "${AL_MOCK_REACHABLE}" != "true" ]]; then
    auto_lane_record_assertion "provider_counter_positive_delta" "fail" "mock provider unreachable"
    return 1
  fi
  local delta total_delta
  delta="$(auto_lane_mock_stats_delta_json)"
  total_delta="$(printf '%s' "${delta}" | jq -r '.delta.total_requests // 0')"
  if (( total_delta < min_delta )); then
    auto_lane_record_assertion "provider_counter_positive_delta" "fail" "total_requests delta ${total_delta} < ${min_delta}"
    return 1
  fi
  auto_lane_record_assertion "provider_counter_positive_delta" "pass" "total_requests delta ${total_delta} >= ${min_delta}"
  return 0
}

# ---------------------------------------------------------------------------
# Canary + finish + report
# ---------------------------------------------------------------------------
auto_lane_control_plane_canary_assert() {
  local log_file="${AL_STATE_ROOT}/canary/requests.log" count
  if [[ -f "${log_file}" ]]; then
    count="$(grep -c . "${log_file}" 2>/dev/null || echo 0)"
  else
    count=0
  fi
  AL_CANARY_COUNT="${count}"
  if [[ "${count}" != "0" ]]; then
    err "auto-lane[${AL_LANE_NAME}]: control-plane canary received ${count} request(s)"
    return 1
  fi
  al_note "control-plane canary request count: 0"
  return 0
}

auto_lane_finish() {
  AL_FINISHED="1"
  if auto_lane_control_plane_canary_assert; then
    AL_CANARY_COUNT="0"
  else
    AL_CANARY_COUNT="$(grep -c . "${AL_STATE_ROOT}/canary/requests.log" 2>/dev/null || echo unknown)"
  fi
  return 0
}

auto_lane_write_report() {
  local assertions_json="[]" fixtures_json="[]"
  local i
  if (( ${#AL_ASSERTION_IDS[@]} > 0 )); then
    assertions_json="["
    for i in "${!AL_ASSERTION_IDS[@]}"; do
      [[ "${i}" -gt 0 ]] && assertions_json+=","
      assertions_json+="{\"id\":$(al_json_escape "${AL_ASSERTION_IDS[$i]}"),\"result\":\"${AL_ASSERTION_RESULTS[$i]}\",\"detail\":$(al_json_escape "${AL_ASSERTION_DETAILS[$i]}")}"
    done
    assertions_json+="]"
  fi
  if (( ${#AL_FIXTURE_IDS[@]} > 0 )); then
    fixtures_json="["
    for i in "${!AL_FIXTURE_IDS[@]}"; do
      [[ "${i}" -gt 0 ]] && fixtures_json+=","
      fixtures_json+="$(al_json_escape "${AL_FIXTURE_IDS[$i]}")"
    done
    fixtures_json+="]"
  fi

  local status="passed"
  for i in "${!AL_ASSERTION_RESULTS[@]}"; do
    [[ -z "${AL_ASSERTION_RESULTS[$i]:-}" ]] && continue
    if [[ "${AL_ASSERTION_RESULTS[$i]}" != "pass" ]]; then status="failed"; break; fi
  done
  [[ "${AL_FINISHED}" == "1" ]] || status="failed"

  local provider_delta
  provider_delta="$(auto_lane_mock_stats_delta_json 2>/dev/null || echo '{}')"

  jq -n \
    --arg lane "${AL_LANE_NAME}" \
    --arg run_id "${AL_RUN_ID}" \
    --arg status "${status}" \
    --arg mode "${AL_CLIENT_MODE}" \
    --arg client_port "${AL_CLIENT_PORT}" \
    --arg mock_port "${AL_MOCK_PORT}" \
    --arg canary_port "${AL_CANARY_PORT}" \
    --arg client_pid "${AL_CLIENT_PID}" \
    --arg mock_pid "${AL_MOCK_PID}" \
    --arg canary_pid "${AL_CANARY_PID}" \
    --arg state_root "${AL_STATE_ROOT}" \
    --arg started_at "${AL_STARTED_AT}" \
    --arg cleanup "${AL_CLEANUP_RESULT}" \
    --arg wp_url "${AL_WP_URL}" \
    --arg canary_count "${AL_CANARY_COUNT}" \
    --argjson dry_run false \
    --argjson fixtures "${fixtures_json}" \
    --argjson assertions "${assertions_json}" \
    --argjson provider_delta "${provider_delta}" \
    --argjson mock_reachable "${AL_MOCK_REACHABLE}" \
    --argjson finished "${AL_FINISHED}" \
    '{
      lane: $lane,
      run_id: $run_id,
      status: $status,
      dry_run: $dry_run,
      mode: $mode,
      direct_wp: ($mode == "local"),
      control_plane_flag: 0,
      control_plane_canary_count: $canary_count,
      owned_infrastructure: {
        client_pid: $client_pid,
        client_port: $client_port,
        client_base_url: ("http://127.0.0.1:" + $client_port),
        mock_pid: $mock_pid,
        mock_port: $mock_port,
        canary_pid: $canary_pid,
        canary_port: $canary_port,
        state_root: $state_root,
        note: "owned debug client + owned mock provider instance + owned recording canary; temp state root removed on cleanup"
      },
      fixture_ids: $fixtures,
      wp_url: $wp_url,
      assertions: $assertions,
      assertion_counts: {
        total: ($assertions | length),
        passed: ([$assertions[] | select(.result == "pass")] | length),
        failed: ([$assertions[] | select(.result != "pass")] | length)
      },
      provider_counter: { reachable: $mock_reachable, delta: $provider_delta },
      started_at: $started_at,
      lane_finished: $finished,
      cleanup_result: $cleanup
    }' > "${AL_REPORT_DIR}/lane-report.json"
  printf '%s\n' "${AL_REPORT_DIR}/lane-report.json"
}

auto_lane_report_status() {
  jq -r '.status' "${AL_REPORT_DIR}/lane-report.json" 2>/dev/null || echo unknown
}
