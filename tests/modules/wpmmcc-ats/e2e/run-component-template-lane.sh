#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"
source "${SCRIPT_DIR}/lib/failure-classification.sh"

LANE="${1:-}"
shift || true

if [ -z "$LANE" ]; then
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh <lane> [args...]

Lanes:
  ct0-sync           Sync official seed -> user local mock templates
  ct0-smoke          Smoke current user local mock templates
  ct1                Run local mock worker task-flow baseline
  ct2                Run WP core live fixture baseline (standard + auth-bound)
  ct2-support-ui     Run WP core rule-discovery support UI baseline
  ct3                Run full 100-component user local mock batch
  baseline           Run ct0-smoke + ct1 + ct2 + ct2-support-ui
  release-required   Run baseline + ct3
  all-nonmutating    Compatibility alias for release-required

Notes:
  - ct0-sync is the only mutating lane. Pass --apply if you want it to write.
  - baseline is the day-to-day component-template smoke set.
  - release-required is the current pre-release component-template gate.
  - ct2 / ct3 require:
      * http://127.0.0.1:9090 mock-translate-api
      * tests/modules/wpmmcc-ats/e2e/runtime/core-component-template-sources.json
      * live WP runtime gate to be healthy
EOF
  exit 1
fi

ensure_dirs

ensure_toolchain_paths() {
  if ! command -v cargo >/dev/null 2>&1 && [ -f "$HOME/.cargo/env" ]; then
    # shellcheck source=/dev/null
    source "$HOME/.cargo/env"
  fi
}

ensure_toolchain_paths

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_JSON="${REPORTS_DIR}/component-template-${LANE:-unknown}-${TIMESTAMP}.json"
STEPS_JSONL="$(mktemp)"
OVERALL_STATUS="running"
OVERALL_ERROR=""
FAIL_CATEGORY="none"
FAIL_SURFACE="none"
FAIL_MESSAGE=""
FAIL_NEXT_ACTION=""

finalize_report() {
  local exit_code=$?
  local finished_at
  finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

  if [ "$OVERALL_STATUS" = "running" ]; then
    if [ "$exit_code" -eq 0 ]; then
      OVERALL_STATUS="passed"
    else
      OVERALL_STATUS="failed"
    fi
  fi

  if [ "$OVERALL_STATUS" = "failed" ] && [ "$FAIL_CATEGORY" = "none" ]; then
    e2e_classify_failure "component-template-lane:${LANE:-unknown}" "${exit_code}"
    FAIL_CATEGORY="${E2E_FAILURE_CATEGORY}"
    FAIL_SURFACE="${E2E_FAILURE_SURFACE}"
    FAIL_MESSAGE="${E2E_FAILURE_MESSAGE}"
    FAIL_NEXT_ACTION="${E2E_FAILURE_NEXT_ACTION}"
  fi

  jq -n \
    --arg lane "${LANE:-unknown}" \
    --arg status "$OVERALL_STATUS" \
    --arg error "$OVERALL_ERROR" \
    --arg category "$FAIL_CATEGORY" \
    --arg surface "$FAIL_SURFACE" \
    --arg message "$FAIL_MESSAGE" \
    --arg next_action "$FAIL_NEXT_ACTION" \
    --arg finished_at "$finished_at" \
    --arg report_json "$REPORT_JSON" \
    --arg evidence_json "${REPORT_JSON%.json}.steps.jsonl" \
    --slurpfile steps "$STEPS_JSONL" \
    '{
      suite: "component-template-lane",
      lane: $lane,
      status: $status,
      dry_run: false,
      finished_at: $finished_at,
      evidence: [$evidence_json],
      error: (if $error == "" then null else $error end),
      category: (if $category == "none" then null else $category end),
      surface: (if $surface == "none" then null else $surface end),
      message: (if $message == "" then null else $message end),
      next_action: (if $next_action == "" then null else $next_action end),
      steps: $steps
    }' >"$REPORT_JSON"

  case "${LANE:-}" in
    ct3|release-required|all-nonmutating)
      if [[ -f "$REPORT_JSON" ]]; then
        cp "$REPORT_JSON" "${REPORTS_DIR}/component-template-ct3-latest.json"
      fi
      ;;
  esac

  # Preserve the steps JSONL as report evidence (schema: passed requires
  # existing evidence paths; the per-step run record IS the evidence).
  mv -f "$STEPS_JSONL" "${REPORT_JSON%.json}.steps.jsonl"

  if [ "$exit_code" -eq 0 ]; then
    ok "Lane report saved: ${REPORT_JSON}"
  else
    warn "Lane report saved (failed run): ${REPORT_JSON}"
  fi
}

trap finalize_report EXIT

print_stage "CT" "Component Template Lane: ${LANE}"
START=$(stage_start_time)

require_file() {
  local file="$1"
  if [ ! -f "$file" ]; then
    abort "Required file not found: ${file}"
  fi
}

ensure_mock_api_running() {
  info "Checking mock-translate-api..."
  if check_url "${MOCK_API_URL}/api/v1/health"; then
    ok "mock-translate-api reachable at ${MOCK_API_URL}"
    return
  fi

  info "mock-translate-api not reachable, attempting local startup..."
  if [ -x "${MOCK_API_BIN}" ]; then
    "${MOCK_API_BIN}" >/tmp/wptsall-e2e-mock-api.log 2>&1 &
    local mock_pid=$!
    for _ in $(seq 1 20); do
      if check_url "${MOCK_API_URL}/api/v1/health"; then
        ok "mock-translate-api started (PID=${mock_pid}) at ${MOCK_API_URL}"
        return
      fi
      sleep 1
    done
    warn "mock-translate-api startup log: /tmp/wptsall-e2e-mock-api.log"
  fi

  abort "mock-translate-api is not reachable at ${MOCK_API_URL}"
}

ensure_server_api_running() {
  info "Checking Server API..."
  if check_server_api "${SERVER_URL}"; then
    ok "Server API reachable at ${SERVER_URL}"
    return
  fi

  abort "Server API is not reachable at ${SERVER_URL} (/health and fallback probes failed)"
}

ensure_core_fixture_manifest() {
  local manifest="${E2E_DIR}/runtime/core-component-template-sources.json"
  info "Checking WP core component-template runtime manifest..."
  require_file "$manifest"
  ok "Found runtime manifest: ${manifest}"
}

sync_client_bootstrap_auth_from_manifest() {
  local manifest bindings_file wp_token route_secret
  manifest="${E2E_DIR}/runtime/core-component-template-sources.json"
  bindings_file="${REPO_ROOT}/client-wpplugin/source/config/domain-token-bindings.json"

  require_file "$manifest"
  info "Syncing Client bootstrap auth from core fixture manifest..."

  wp_token="$(jq -r '.wp_client_token // ""' "$manifest")"
  route_secret="$(jq -r '.route_secret // ""' "$manifest")"
  if [ -z "$wp_token" ] || [ "$wp_token" = "null" ]; then
    abort "core-component-template runtime manifest missing wp_client_token"
  fi
  if [ -z "$route_secret" ] || [ "$route_secret" = "null" ]; then
    abort "core-component-template runtime manifest missing route_secret"
  fi

  mkdir -p "$(dirname "$bindings_file")"
  php -r '
    $file = $argv[1];
    $domain = $argv[2];
    $token = $argv[3];
    $secret = $argv[4];
    $doc = ["version" => 2, "domains" => []];
    if (is_file($file)) {
        $decoded = json_decode(file_get_contents($file), true);
        if (is_array($decoded)) {
            $doc = $decoded;
        }
    }
    if (!isset($doc["domains"]) || !is_array($doc["domains"])) {
        $doc["domains"] = [];
    }
    $doc["version"] = 2;
    $doc["domains"][$domain] = [
        "wp_client_token" => $token,
        "route_secret" => $secret,
    ];
    file_put_contents(
        $file,
        json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );
  ' "$bindings_file" "$WP_URL" "$wp_token" "$route_secret"
  ok "Client bootstrap auth synced: ${bindings_file}"
}

restart_client_webui_service() {
  local client_status_url="http://127.0.0.1:8977/api/status"
  # Fixed lab device identity for WP Protocol v2 requests. The client sends
  # X-WPTSALL-Device-Id from WPTSALL_WP_DEVICE_ID, and the fixture device
  # token issued by refresh_core_fixture_manifest is bound to the same id;
  # a mismatch fails WP auth with 401 client_unauthorized.
  local lab_wp_device_id="${CT_LAB_WP_DEVICE_ID}"
  local fallback_pid_file
  local fallback_log_file
  local fallback_server_base
  local fallback_client_bin
  local fallback_client_bin_debug
  local lab_pid_file
  local lab_client_bin
  local lab_client_env
  local has_systemd_unit=0

  fallback_pid_file="${RUNTIME_DIR}/client-webui-fallback.pid"
  fallback_log_file="${REPO_ROOT}/client-wpplugin/source/logs/wptsall-client.log"
  fallback_server_base="${WPTSALL_SERVER_BASE_LOCAL:-http://127.0.0.1:8787}"
  fallback_client_bin="${CLIENT_BIN}"
  fallback_client_bin_debug="${REPO_ROOT}/client-wpplugin/source/target/debug/wptsall-client"
  lab_pid_file="${HOME}/projects/runtime/logs/wpplugin.pid"
  lab_client_bin="${HOME}/projects/runtime/bin/client-wpplugin"
  lab_client_env="${HOME}/projects/runtime/clients/wpplugin.env"
  mkdir -p "$(dirname "$fallback_log_file")"

  if command -v systemctl >/dev/null 2>&1 \
    && systemctl --user cat wptsall-client-webui.service >/dev/null 2>&1; then
    # NOTE: systemd restarts cannot inject WPTSALL_WP_DEVICE_ID per-run; the
    # unit file must pin the same device id (WPTSALL_WP_DEVICE_ID) as
    # CT_LAB_WP_DEVICE_ID or WP device-token auth will 401 in the CT lane.
    if ! systemctl --user show wptsall-client-webui.service -p Environment --no-pager 2>/dev/null | grep -q "WPTSALL_WP_DEVICE_ID"; then
      warn "systemd unit lacks WPTSALL_WP_DEVICE_ID; CT lane WP auth may 401 (expected device: ${lab_wp_device_id})"
    fi
    has_systemd_unit=1
  fi

  if [[ "$has_systemd_unit" == "1" ]]; then
    info "Restarting Client WebUI service..."
    systemctl --user restart wptsall-client-webui.service
  elif [[ "${WPTSALL_LAB:-}" == "1" ]] && [[ -x "$lab_client_bin" ]]; then
    info "Lab mode: restarting Client WebUI via runtime binary..."
    if [[ -f "$lab_pid_file" ]]; then
      local old_pid
      old_pid="$(cat "$lab_pid_file" 2>/dev/null || true)"
      if [[ -n "${old_pid:-}" ]] && kill -0 "$old_pid" 2>/dev/null; then
        kill "$old_pid" 2>/dev/null || true
        sleep 1
        kill -9 "$old_pid" 2>/dev/null || true
      fi
      rm -f "$lab_pid_file"
    fi
    (
      mkdir -p "${HOME}/projects/runtime/clients/wpplugin"
      cd "${HOME}/projects/runtime/clients/wpplugin" || exit 1
      if [[ -f "$lab_client_env" ]]; then
        set -a
        # shellcheck source=/dev/null
        source "$lab_client_env"
        set +a
      fi
      export WPTSALL_WP_DEVICE_ID="$lab_wp_device_id"
      # Do not inherit component-template lane flock FD onto the client.
      exec 9>&-
      nohup "$lab_client_bin" >"${HOME}/projects/runtime/logs/wpplugin.log" 2>&1 &
      echo $! >"$lab_pid_file"
    )
    info "Lab Client WebUI PID: $(cat "$lab_pid_file")"
  else
    warn "systemctl unit missing; using local Client WebUI binary fallback"
    if check_url "$client_status_url"; then
      local live_device=""
      live_device="$(curl -sf --max-time 5 "$client_status_url" 2>/dev/null \
        | python3 -c 'import json,sys; d=json.load(sys.stdin); print((d.get("data") or d).get("device_id") or "")' 2>/dev/null || true)"
      if [[ -n "$live_device" && "$live_device" == "$lab_wp_device_id" ]]; then
        ok "Client WebUI already reachable with device_id=${lab_wp_device_id}"
        return
      fi
      warn "Client WebUI device_id='${live_device:-unknown}' != '${lab_wp_device_id}'; restarting with WPTSALL_WP_DEVICE_ID"
      # Stop whatever is bound to :8977 so the fixture device id can take over.
      if [[ -x "${REPO_ROOT}/scripts/wptsall.sh" ]]; then
        bash "${REPO_ROOT}/scripts/wptsall.sh" services stop webui >/dev/null 2>&1 || true
      fi
      if [[ -f "$fallback_pid_file" ]]; then
        local old_fb
        old_fb="$(cat "$fallback_pid_file" 2>/dev/null || true)"
        if [[ -n "${old_fb:-}" ]] && kill -0 "$old_fb" 2>/dev/null; then
          kill "$old_fb" 2>/dev/null || true
          sleep 1
          kill -9 "$old_fb" 2>/dev/null || true
        fi
        rm -f "$fallback_pid_file"
      fi
      # Last resort: free the port if a stray debug binary is still listening.
      local port_pids
      port_pids="$(lsof -ti :8977 2>/dev/null || true)"
      if [[ -z "$port_pids" ]]; then
        port_pids="$(ss -ltnp 2>/dev/null | awk -F'pid=' '/:8977/ {split($2,a,","); print a[1]}' | sort -u)"
      fi
      for p in $port_pids; do
        [[ -n "$p" ]] || continue
        kill "$p" 2>/dev/null || true
      done
      sleep 1
      for p in $port_pids; do
        [[ -n "$p" ]] || continue
        kill -9 "$p" 2>/dev/null || true
      done
      fuser -k 8977/tcp >/dev/null 2>&1 || true
      pkill -f "WPTSALL_WEB_UI_PORT=8977" 2>/dev/null || true
      pkill -f "WPTSALL_WEB_UI_BIND=127.0.0.1:8977" 2>/dev/null || true
      for _ in $(seq 1 10); do
        lsof -i :8977 >/dev/null 2>&1 || break
        sleep 0.5
      done
    fi

    # In local macOS runner flows, tests usually rebuild debug artifacts first.
    # Prefer the freshest local binary so route patches are always exercised.
    if [ -x "$fallback_client_bin_debug" ] && { [ ! -x "$fallback_client_bin" ] || [ "$fallback_client_bin_debug" -nt "$fallback_client_bin" ]; }; then
      warn "Using newer debug Client binary for fallback: ${fallback_client_bin_debug}"
      fallback_client_bin="$fallback_client_bin_debug"
    fi

    if [ ! -x "$fallback_client_bin" ]; then
      abort "Client binary is not executable for fallback start: ${fallback_client_bin}"
    fi

    info "Starting Client WebUI from local binary (device_id=${lab_wp_device_id})..."
    if [ -f "$CLIENT_ENV" ]; then
      set -a
      # shellcheck source=/dev/null
      source "$CLIENT_ENV"
      set +a
    elif [ -f "$CLIENT_ENV_FALLBACK" ]; then
      set -a
      # shellcheck source=/dev/null
      source "$CLIENT_ENV_FALLBACK"
      set +a
    fi

    (
      # Lab / monorepo layout uses client-wpplugin/source (not REPO_ROOT/client).
      client_cwd="${REPO_ROOT}/client-wpplugin/source"
      if [[ ! -d "${client_cwd}" ]]; then
        client_cwd="${REPO_ROOT}/client"
      fi
      cd "${client_cwd}" || exit 1
      WPTSALL_WEB_UI=true \
      WPTSALL_WEB_UI_BIND="${WPTSALL_WEB_UI_BIND:-127.0.0.1:8977}" \
      WPTSALL_WEB_UI_PORT="${WPTSALL_WEB_UI_PORT:-8977}" \
      WPTSALL_SERVER_BASE="${fallback_server_base}" \
      WPTSALL_SERVER_BASE_LOCAL="${fallback_server_base}" \
      WPTSALL_DB_PATH="${WPTSALL_DB_PATH:-${HOME}/projects/runtime/clients/wpplugin/runtime/wptsall.db}" \
      WPTSALL_WP_CLIENT_TOKEN="" \
      WPTSALL_WP_DEVICE_ID="$lab_wp_device_id" \
      WPTSALL_DEVICE_ID="$lab_wp_device_id" \
      WPTSALL_SKIP_SIGNATURE_CHECK="${WPTSALL_SKIP_SIGNATURE_CHECK:-true}" \
      WPTSALL_ALLOW_INSECURE_TLS="${WPTSALL_ALLOW_INSECURE_TLS:-true}" \
      WPTSALL_COMPONENT_RUNTIME="${WPTSALL_COMPONENT_RUNTIME:-true}" \
      WPTSALL_USE_SERVER_CONTROL_PLANE="${WPTSALL_USE_SERVER_CONTROL_PLANE:-0}" \
      WPTSALL_PROVIDER_ALLOWLIST="${WPTSALL_PROVIDER_ALLOWLIST:-127.0.0.1,localhost}" \
      WPTSALL_LOG_FILE="${fallback_log_file}" \
      "$fallback_client_bin"
    # Close lane flock FD so the long-lived client does not inherit and pin the lock.
    ) 9>&- >/tmp/wptsall-client-webui-e2e.log 2>&1 &
    echo "$!" >"$fallback_pid_file"
    info "Client WebUI fallback PID: $(cat "$fallback_pid_file") (log: /tmp/wptsall-client-webui-e2e.log)"
  fi

  for _ in $(seq 1 30); do
    if check_url "$client_status_url"; then
      ok "Client WebUI restarted and reachable at ${client_status_url}"
      return
    fi
    sleep 1
  done

  abort "Client WebUI did not come back after restart"
}

upsert_runtime_domain_token_binding_from_manifest() {
  local manifest wp_token route_secret payload response status_response
  local existing_url delete_payload delete_response
  manifest="${E2E_DIR}/runtime/core-component-template-sources.json"

  require_file "$manifest"
  info "Upserting Client runtime domain token binding from core fixture manifest..."

  wp_token="$(jq -r '.wp_client_token // ""' "$manifest")"
  route_secret="$(jq -r '.route_secret // ""' "$manifest")"
  payload="$(jq -n \
    --arg api_base_url "$WP_URL" \
    --arg wp_client_token "$wp_token" \
    --arg route_secret "$route_secret" \
    '{api_base_url: $api_base_url, wp_client_token: $wp_client_token, route_secret: $route_secret}')"

  status_response="$(curl -sS http://127.0.0.1:8977/api/status)"
  if ! echo "$status_response" | jq -e '.success == true' >/dev/null 2>&1; then
    echo "$status_response"
    abort "Failed to query Client runtime status before domain token upsert"
  fi

  while IFS= read -r existing_url; do
    if [ -z "$existing_url" ] || [ "$existing_url" = "$WP_URL" ]; then
      continue
    fi
    delete_payload="$(jq -n --arg api_base_url "$existing_url" '{api_base_url: $api_base_url}')"
    delete_response="$(curl -sS -X POST http://127.0.0.1:8977/api/domain-tokens/delete \
      -H 'Content-Type: application/json' \
      -d "$delete_payload")"
    if ! echo "$delete_response" | jq -e '.success == true' >/dev/null 2>&1; then
      echo "$delete_response"
      abort "Failed to delete stale Client runtime domain token binding: ${existing_url}"
    fi
    info "Removed stale runtime domain token binding: ${existing_url}"
  done < <(echo "$status_response" | jq -r '.data.domain_token_bindings[]?.api_base_url // empty')

  response="$(curl -sS -X POST http://127.0.0.1:8977/api/domain-tokens/upsert \
    -H 'Content-Type: application/json' \
    -d "$payload")"

  if ! echo "$response" | jq -e '.success == true' >/dev/null 2>&1; then
    echo "$response"
    abort "Failed to upsert Client runtime domain token binding"
  fi

  ok "Client runtime domain token binding upserted"
}

ensure_client_api_runtime_gate() {
  info "Ensuring live WP Client API runtime gate is enabled..."
  run_recorded \
    ensure-client-api-runtime \
    wp_eval "${E2E_DIR}/php/ensure-client-api-runtime.php"
}

refresh_core_fixture_manifest() {
  info "Refreshing WP core component-template runtime manifest from live WP..."
  # Device-scoped tokens only validate for the device id that presents them.
  # The client sends X-WPTSALL-Device-Id from WPTSALL_WP_DEVICE_ID (see
  # restart_client_webui_service), so issue the fixture token for that same
  # fixed lab device identity.
  info "Issuing fixture device token for lab device: ${CT_LAB_WP_DEVICE_ID}"
  WPTSALL_DEVICE_ID_FOR_TOKEN="$CT_LAB_WP_DEVICE_ID" \
    run_recorded \
    refresh-core-fixture-manifest \
    wp_eval "${E2E_DIR}/php/seed-core-component-template-sources.php"
  ensure_core_fixture_manifest
}

run_cmd() {
  info "Running: $*"
  "$@"
}

record_step() {
  local step="$1"
  local cmd="$2"
  local status="$3"
  local started_at="$4"
  local finished_at="$5"
  jq -n \
    --arg step "$step" \
    --arg command "$cmd" \
    --arg status "$status" \
    --arg started_at "$started_at" \
    --arg finished_at "$finished_at" \
    '{
      step: $step,
      command: $command,
      status: $status,
      started_at: $started_at,
      finished_at: $finished_at
    }' >>"$STEPS_JSONL"
}

run_recorded() {
  local step="$1"
  shift
  local started_at finished_at cmd_string
  started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  cmd_string="$(printf '%q ' "$@")"
  info "Running [${step}]: ${cmd_string}"

  if "$@"; then
    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    record_step "$step" "$cmd_string" "passed" "$started_at" "$finished_at"
    ok "${step} passed"
  else
    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    record_step "$step" "$cmd_string" "failed" "$started_at" "$finished_at"
    OVERALL_STATUS="failed"
    OVERALL_ERROR="Step failed: ${step}"
    abort "Component-template lane step failed: ${step}"
  fi
}

run_ct0_sync() {
  run_recorded ct0-sync env WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" \
    node "${REPO_ROOT}/web/source/scripts/sync-official-seed-to-user-local-mock.mjs" "$@"
}

run_ct0_smoke() {
  ensure_server_api_running
  ensure_mock_api_running
  local node_opts
  node_opts="${NODE_OPTIONS:-}"
  if [[ " ${node_opts} " != *" --use-openssl-ca "* ]]; then
    node_opts="--use-openssl-ca ${node_opts}"
  fi

  local smoke_soft="${WPTSALL_SMOKE_SOFT:-0}"
  if [[ -z "${WPTSALL_SMOKE_SOFT:-}" && "${WPTSALL_LAB:-}" == "1" ]]; then
    smoke_soft=1
  fi

  if [[ -n "${WPTSALL_NODE_EXTRA_CA_CERTS:-}" ]]; then
    if [[ -f "${WPTSALL_NODE_EXTRA_CA_CERTS}" ]]; then
      run_recorded ct0-smoke env NODE_OPTIONS="${node_opts}" NODE_EXTRA_CA_CERTS="${WPTSALL_NODE_EXTRA_CA_CERTS}" \
        WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" WPTSALL_SMOKE_SOFT="${smoke_soft}" \
        node "${REPO_ROOT}/web/source/scripts/smoke-user-local-mock-batch.mjs" "$@"
      return
    fi
    warn "WPTSALL_NODE_EXTRA_CA_CERTS not found: ${WPTSALL_NODE_EXTRA_CA_CERTS}; fallback to system OpenSSL CA only"
  fi

  run_recorded ct0-smoke env NODE_OPTIONS="${node_opts}" WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" \
    WPTSALL_SMOKE_SOFT="${smoke_soft}" \
    node "${REPO_ROOT}/web/source/scripts/smoke-user-local-mock-batch.mjs" "$@"
}

run_ct1() {
  run_recorded ct1-worker-mock cargo test \
    --manifest-path "${REPO_ROOT}/client-wpplugin/source/Cargo.toml" \
    --test component_template_mock_task_flow \
    -- --nocapture "$@"
}

resolve_rust_tls_ca_file() {
  local candidate=""
  if [[ -n "${WPTSALL_RUST_TLS_CA_CERTS:-}" ]]; then
    candidate="${WPTSALL_RUST_TLS_CA_CERTS}"
  elif [[ -n "${WPTSALL_NODE_EXTRA_CA_CERTS:-}" ]]; then
    candidate="${WPTSALL_NODE_EXTRA_CA_CERTS}"
  fi

  if [[ -n "${candidate}" && -f "${candidate}" ]]; then
    printf '%s\n' "${candidate}"
    return 0
  fi
  return 1
}

run_ct2() {
  ensure_mock_api_running
  ensure_client_api_runtime_gate
  refresh_core_fixture_manifest
  local rust_ca_file=""
  if rust_ca_file="$(resolve_rust_tls_ca_file)"; then
    run_recorded ct2-standard env WPTSALL_ALLOW_INSECURE_TLS=true WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" SSL_CERT_FILE="${rust_ca_file}" cargo test \
      --manifest-path "${REPO_ROOT}/client-wpplugin/source/Cargo.toml" \
      --test wp_core_source_component_flow \
      wp_core_sources_route_through_component_template_standard \
      -- --ignored --nocapture "$@"
    run_recorded ct2-auth-bound env WPTSALL_ALLOW_INSECURE_TLS=true WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" SSL_CERT_FILE="${rust_ca_file}" cargo test \
      --manifest-path "${REPO_ROOT}/client-wpplugin/source/Cargo.toml" \
      --test wp_core_source_component_flow \
      wp_core_sources_route_through_auth_bound_component_template_standard \
      -- --ignored --nocapture "$@"
    return
  fi

  run_recorded ct2-standard env WPTSALL_ALLOW_INSECURE_TLS=true WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" cargo test \
    --manifest-path "${REPO_ROOT}/client-wpplugin/source/Cargo.toml" \
    --test wp_core_source_component_flow \
    wp_core_sources_route_through_component_template_standard \
    -- --ignored --nocapture "$@"
  run_recorded ct2-auth-bound env WPTSALL_ALLOW_INSECURE_TLS=true WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" cargo test \
    --manifest-path "${REPO_ROOT}/client-wpplugin/source/Cargo.toml" \
    --test wp_core_source_component_flow \
    wp_core_sources_route_through_auth_bound_component_template_standard \
    -- --ignored --nocapture "$@"
}

acquire_ct_lane_lock() {
  # Concurrent release-required/ct3 logins revoke each other's client sessions
  # (SESSION_REVOKED) and cascade into ct3-full-batch hard fails.
  local lock="${RUNTIME_DIR}/component-template-lane.lock"
  mkdir -p "${RUNTIME_DIR}"
  exec 9>"${lock}"
  if ! flock -n 9; then
    info "Waiting for component-template lane lock (${lock})..."
    flock 9
  fi
  ok "Acquired component-template lane lock"
}

run_ct3() {
  ensure_mock_api_running
  ensure_client_api_runtime_gate
  refresh_core_fixture_manifest
  local smoke_soft="${WPTSALL_SMOKE_SOFT:-0}"
  if [[ -z "${WPTSALL_SMOKE_SOFT:-}" && "${WPTSALL_LAB:-}" == "1" ]]; then
    smoke_soft=1
  fi
  local rust_ca_file=""
  if rust_ca_file="$(resolve_rust_tls_ca_file)"; then
    run_recorded ct3-full-batch env WPTSALL_ALLOW_INSECURE_TLS=true WPTSALL_ALLOW_EMPTY_USER_LOCAL_MOCK_BATCH=true WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" WPTSALL_SMOKE_SOFT="${smoke_soft}" SSL_CERT_FILE="${rust_ca_file}" cargo test \
      --manifest-path "${REPO_ROOT}/client-wpplugin/source/Cargo.toml" \
      --test wp_core_source_component_flow \
      wp_core_post_fixture_routes_all_user_local_mock_components_through_worker \
      -- --ignored --nocapture "$@"
    return
  fi
  run_recorded ct3-full-batch env WPTSALL_ALLOW_INSECURE_TLS=true WPTSALL_ALLOW_EMPTY_USER_LOCAL_MOCK_BATCH=true WPTSALL_SERVER_BASE_LOCAL="${SERVER_URL}" WPTSALL_SMOKE_SOFT="${smoke_soft}" cargo test \
    --manifest-path "${REPO_ROOT}/client-wpplugin/source/Cargo.toml" \
    --test wp_core_source_component_flow \
    wp_core_post_fixture_routes_all_user_local_mock_components_through_worker \
    -- --ignored --nocapture "$@"
}

run_ct2_support_ui() {
  ensure_client_api_runtime_gate
  refresh_core_fixture_manifest
  sync_client_bootstrap_auth_from_manifest
  restart_client_webui_service
  upsert_runtime_domain_token_binding_from_manifest
  run_recorded ct2-support-ui-rule-discovery bash -lc \
    "cd '${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright' && npx playwright test -c playwright.client-server-support.config.ts support-client-server/components-webui-migration.support.spec.ts -g '规则绑定页：展示 live rule discovery 并支持一键填充' $*"
  run_recorded ct2-support-ui-sites-manual-flow bash -lc \
    "cd '${REPO_ROOT}/tests/modules/wpmmcc-ats/e2e/playwright' && npx playwright test -c playwright.client-server-support.config.ts support-client-server/components-webui-migration.support.spec.ts -g '站点页：手工保存绑定后立即恢复连通测试与规则发现' $*"
}

# Local-first boundary (AGENTS.md §0.1 / PLAN-2026-08-31): ct0-sync/ct0-smoke
# sync and smoke-test the website seed/catalog (web/source scripts against
# the :8787 control plane), and ct2/ct3 log into that website
# (login_live_server_session) to pull component templates. Those are legacy
# website-integration scenarios pending the P1-CAT local-catalog rework, so
# they are skipped unless the explicit legacy lane is enabled. ct1 (local
# mock worker flow) and ct2-support-ui (site-binding / rule-binding UI, now
# device-token based) are local-first and stay in every lane.
CT_WEBSITE_LEGACY="${WPTSALL_USE_SERVER_CONTROL_PLANE:-0}"
run_website_legacy_phase() {
  local phase="$1"
  if [[ "${CT_WEBSITE_LEGACY}" == "1" ]]; then
    "$@" "${@:2}"
  else
    info "Skipping ${phase} (legacy website control-plane scenario; local-first gate skips it; set WPTSALL_USE_SERVER_CONTROL_PLANE=1 to run)"
  fi
}

case "$LANE" in
  ct0-sync)
    run_website_legacy_phase ct0-sync run_ct0_sync "$@"
    ;;
  ct0-smoke)
    run_website_legacy_phase ct0-smoke run_ct0_smoke "$@"
    ;;
  ct1)
    run_ct1 "$@"
    ;;
  ct2)
    acquire_ct_lane_lock
    run_website_legacy_phase ct2 run_ct2 "$@"
    ;;
  ct2-support-ui)
    acquire_ct_lane_lock
    run_ct2_support_ui "$@"
    ;;
  ct3)
    acquire_ct_lane_lock
    run_website_legacy_phase ct3 run_ct3 "$@"
    ;;
  baseline)
    acquire_ct_lane_lock
    run_website_legacy_phase ct0-smoke run_ct0_smoke "$@"
    run_ct1 "$@"
    run_website_legacy_phase ct2 run_ct2 "$@"
    run_ct2_support_ui "$@"
    ;;
  release-required|all-nonmutating)
    acquire_ct_lane_lock
    run_website_legacy_phase ct0-smoke run_ct0_smoke "$@"
    run_ct1 "$@"
    run_website_legacy_phase ct2 run_ct2 "$@"
    run_ct2_support_ui "$@"
    run_website_legacy_phase ct3 run_ct3 "$@"
    ;;
  *)
    OVERALL_ERROR="Unsupported component-template lane: ${LANE}"
    FAIL_CATEGORY="contract_mismatch"
    FAIL_SURFACE="component_template_lane"
    FAIL_MESSAGE="Unsupported component-template lane: ${LANE}"
    FAIL_NEXT_ACTION="Use one of: ct0-sync, ct0-smoke, ct1, ct2, ct2-support-ui, ct3, baseline, release-required"
    abort "Unsupported component-template lane: ${LANE}"
    ;;
esac

stage_elapsed "$START"
OVERALL_STATUS="passed"
ok "Component template lane completed: ${LANE}"
