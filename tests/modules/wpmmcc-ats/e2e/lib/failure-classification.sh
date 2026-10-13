#!/usr/bin/env bash
set -euo pipefail

# shellcheck disable=SC2034
E2E_FAILURE_CATEGORY="${E2E_FAILURE_CATEGORY:-repo_regression}"
# shellcheck disable=SC2034
E2E_FAILURE_SURFACE="${E2E_FAILURE_SURFACE:-e2e_pipeline}"
# shellcheck disable=SC2034
E2E_FAILURE_MESSAGE="${E2E_FAILURE_MESSAGE:-E2E lane failed}"
# shellcheck disable=SC2034
E2E_FAILURE_NEXT_ACTION="${E2E_FAILURE_NEXT_ACTION:-Inspect stage logs and latest report JSON}"

e2e_check_json_not_html() {
  local url="$1"
  local output_file status_code
  output_file="$(mktemp)"
  set +e
  status_code="$(curl -sS -L --max-time 8 -o "${output_file}" -w "%{http_code}" "${url}" 2>/dev/null)"
  local curl_exit=$?
  set -e
  if [[ ${curl_exit} -ne 0 ]]; then
    rm -f "${output_file}"
    return 1
  fi

  local first_char
  first_char="$(python3 - <<'PY' "${output_file}"
import pathlib
import sys

raw = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace").lstrip()
print(raw[:1] if raw else "")
PY
)"
  rm -f "${output_file}"
  if [[ "${first_char}" == "<" ]]; then
    return 1
  fi
  [[ -n "${status_code}" ]]
}

e2e_project_supported_for_classification() {
  case "${E2E_PROJECT:-}" in
    core-content|learning-content|commerce-content|media-builder-content|community-content|listings-events-content|content-meta-content)
      return 0
      ;;
    *)
      ;;
  esac

  if declare -F e2e_plugin_project_exists >/dev/null 2>&1 && e2e_plugin_project_exists; then
    return 0
  fi
  return 1
}

e2e_classify_failure() {
  local context="${1:-generic}"
  local exit_code="${2:-1}"

  E2E_FAILURE_CATEGORY="repo_regression"
  E2E_FAILURE_SURFACE="e2e_pipeline"
  E2E_FAILURE_MESSAGE="Lane failed after environment probes passed"
  E2E_FAILURE_NEXT_ACTION="Inspect failing stage/test output and verifier assertions"

  if ! e2e_project_supported_for_classification; then
    E2E_FAILURE_CATEGORY="contract_mismatch"
    E2E_FAILURE_SURFACE="e2e_project_selection"
    E2E_FAILURE_MESSAGE="Unsupported E2E_PROJECT: ${E2E_PROJECT:-unknown}"
    E2E_FAILURE_NEXT_ACTION="Use supported project IDs from tests/modules/wpmmcc-ats/e2e/project-specs.json or legacy family list"
    return
  fi

  if [[ "${exit_code}" == "124" || "${exit_code}" == "137" ]]; then
    E2E_FAILURE_CATEGORY="flaky_or_timeout"
    E2E_FAILURE_SURFACE="${context}"
    E2E_FAILURE_MESSAGE="Lane exited by timeout or forced termination (exit=${exit_code})"
    E2E_FAILURE_NEXT_ACTION="Re-run once; if reproducible inspect long-running step and timeout budget"
    return
  fi

  # Local-first boundary (AGENTS.md §0.1, P0-EV-06): the website control
  # plane (SERVER_URL, :8787) is NOT a dependency of the plugin or the
  # clients, so a lane failure must never be probed against it. The former
  # server_https_proxy / server_https_api probes here misclassified every
  # real lane failure as "Server API is not reachable at :8787" whenever
  # the website server was (correctly) not running. Server probes remain
  # available only to the explicit legacy lane (WPTSALL_USE_SERVER_CONTROL_PLANE=1).

  if ! check_url "${WP_URL}/wp-json/"; then
    E2E_FAILURE_CATEGORY="environment_drift"
    E2E_FAILURE_SURFACE="wordpress_rest"
    E2E_FAILURE_MESSAGE="WordPress REST endpoint is not reachable at ${WP_URL}/wp-json/"
    E2E_FAILURE_NEXT_ACTION="Check WP runtime health, Apache site mapping, and plugin activation"
    return
  fi

  if ! check_url "${CLIENT_URL}/api/status"; then
    E2E_FAILURE_CATEGORY="missing_dependency"
    E2E_FAILURE_SURFACE="client_webui"
    E2E_FAILURE_MESSAGE="Client Web UI is not reachable at ${CLIENT_URL}/api/status"
    E2E_FAILURE_NEXT_ACTION="Check/restart wptsall-client-webui.service and local binding"
    return
  fi

  if [[ "${context}" == *"component-template"* || "${context}" == *"matrix"* || "${context}" == *"release"* ]]; then
    if ! check_url "${MOCK_API_URL}/api/v1/health"; then
      E2E_FAILURE_CATEGORY="missing_dependency"
      E2E_FAILURE_SURFACE="mock_translate_api"
      E2E_FAILURE_MESSAGE="mock-translate-api is not reachable at ${MOCK_API_URL}/api/v1/health"
      E2E_FAILURE_NEXT_ACTION="Start/restart mock9090.service"
      return
    fi
  fi
}

e2e_emit_failure_json() {
  local report_json="$1"
  local context="$2"
  local exit_code="$3"
  local started_at="$4"
  local finished_at="$5"

  python3 - <<'PY' "${report_json}" "${context}" "${exit_code}" "${started_at}" "${finished_at}" \
    "${E2E_FAILURE_CATEGORY}" "${E2E_FAILURE_SURFACE}" "${E2E_FAILURE_MESSAGE}" "${E2E_FAILURE_NEXT_ACTION}" \
    "${E2E_PROJECT:-unknown}" "${E2E_SCOPE:-unknown}"
import json
import pathlib
import sys

report_path = pathlib.Path(sys.argv[1])
payload = {
    "status": "failed",
    "context": sys.argv[2],
    "exit_code": int(sys.argv[3]),
    "started_at": sys.argv[4],
    "finished_at": sys.argv[5],
    "category": sys.argv[6],
    "surface": sys.argv[7],
    "message": sys.argv[8],
    "next_action": sys.argv[9],
    "project": sys.argv[10],
    "scope": sys.argv[11],
}
report_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(str(report_path))
PY
}
