#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"

print_stage "7P2" "Virtual Frontend Deep Verification"

if [[ ! -f "${WP_ROOT}/wp-load.php" ]] && ! _is_lab_mode; then
  warn "WP_ROOT missing on host (${WP_ROOT}); skipping Stage 7P2"
  exit 0
fi

TARGET_FILE="${RUNTIME_DIR}/virtual-frontend-target.json"

PASS=0
FAIL=0
TOTAL=0

check_eq() {
  TOTAL=$((TOTAL + 1))
  local label="$1"
  local expected="$2"
  local actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    ok "${label} (got: ${actual})"
    PASS=$((PASS + 1))
  else
    err "${label} (expected: ${expected}, got: ${actual})"
    FAIL=$((FAIL + 1))
  fi
}

check_positive() {
  TOTAL=$((TOTAL + 1))
  local label="$1"
  local actual="$2"
  if [[ "${actual}" =~ ^[0-9]+$ ]] && [[ "$actual" -gt 0 ]]; then
    ok "${label} (count: ${actual})"
    PASS=$((PASS + 1))
  else
    err "${label} (count: ${actual}, expected > 0)"
    FAIL=$((FAIL + 1))
  fi
}

mkdir -p "${RUNTIME_DIR}"
if [[ ! -s "${TARGET_FILE}" ]]; then
  if ! wp_eval "${E2E_DIR}/php/resolve-virtual-frontend-target.php" >"${TARGET_FILE}" 2>/dev/null; then
    warn "Virtual frontend verification skipped: could not resolve virtual frontend target."
    exit 0
  fi
fi

TARGET_OK="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo !empty($j["ok"]) ? "1" : "0";' "${TARGET_FILE}" 2>/dev/null || echo 0)"
if [[ "${TARGET_OK}" != "1" ]]; then
  warn "Virtual frontend verification skipped: no virtual frontend target found."
  exit 0
fi

POST_ROWS="$(
  php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    $prefix = trim((string)($j["path_prefix"] ?? ""), "/");
    foreach (($j["samples"] ?? []) as $sample) {
      $type = (string)($sample["post_type"] ?? "");
      $path = (string)($sample["virtual_path"] ?? "");
      if ($path === "") {
        $slug = trim((string)($sample["post_name"] ?? ""), "/");
        if ($slug !== "") {
          $path = "/" . ($prefix !== "" ? $prefix . "/" : "") . $slug . "/";
        }
      }
      if ($type !== "" && $path !== "") {
        echo $type . "|" . $path . PHP_EOL;
      }
    }
  ' "${TARGET_FILE}" 2>/dev/null || true
)"

if [[ -z "${POST_ROWS}" ]]; then
  warn "Virtual frontend verification skipped: no virtual wp_posts samples found."
  exit 0
fi

PATH_PREFIX="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo trim((string)($j["path_prefix"] ?? ""), "/");' "${TARGET_FILE}" 2>/dev/null || true)"
TARGET_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["target_site_id"] ?? "");' "${TARGET_FILE}" 2>/dev/null || true)"

if [[ -z "${PATH_PREFIX}" ]]; then
  warn "Virtual frontend verification skipped: empty path_prefix for target ${TARGET_ID}."
  exit 0
fi

echo "Resolved sample virtual routes for ${TARGET_ID} at /${PATH_PREFIX}/:"
while IFS='|' read -r subtype virtual_path; do
  [[ -z "${virtual_path}" ]] && continue
  echo "  ${subtype} -> ${virtual_path}"
done <<< "${POST_ROWS}"

while IFS='|' read -r subtype virtual_path; do
  [[ -z "${virtual_path}" ]] && continue
  TOTAL=$((TOTAL + 1))
  if [[ "${virtual_path}" == /* ]]; then
    url="${WP_URL%/}${virtual_path}"
  else
    url="${WP_URL%/}/${virtual_path}"
  fi
  # Parallel journey pressure can briefly 5xx virtual routes; shared backoff helper.
  status="$(http_status_retry "${url}" 20 4 || true)"
  body_file="$(mktemp)"
  markers=0
  route_errors=0

  if [[ "${status}" == "200" ]]; then
    http_download "${url}" "${body_file}" 20 >/dev/null || true
    markers="$(grep -Ec '【[a-z]{2}_[A-Z]{2}】' "${body_file}" || true)"
    route_errors="$(grep -c 'url_pattern_not_registered' "${body_file}" || true)"
  fi

  # 批 N3 / U-8: translated-marker hard gate. The sampled virtual routes are
  # the run's translated post/page copies (resolver drops fragile CPT shells
  # and marker-polluted slugs); batch-M journey evidence showed every sampled
  # route carrying markers 9-14 after a green translation run. A 200 page
  # with zero translation markers means the translation output never reached
  # the public surface — that is a gate failure, not a warning.
  if [[ "${status}" == "200" && "${route_errors}" == "0" && "${markers}" -gt 0 ]]; then
    ok "[${subtype}] ${virtual_path} -> ${status} (markers: ${markers})"
    PASS=$((PASS + 1))
  else
    err "[${subtype}] ${virtual_path} -> ${status} (route_errors: ${route_errors}, markers: ${markers})"
    FAIL=$((FAIL + 1))
  fi

  rm -f "${body_file}"
done <<< "${POST_ROWS}"

echo ""
echo "Summary: checks=${TOTAL}, pass=${PASS}, fail=${FAIL}"
if [[ "${FAIL}" -gt 0 ]]; then
  exit 1
fi
