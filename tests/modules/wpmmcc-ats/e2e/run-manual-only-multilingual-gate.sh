#!/usr/bin/env bash
# Manual-only multilingual gate for the WP plugin.
#
# This gate deliberately does not start the Rust/Desktop client and does not
# call automatic translation. It validates WPMMCC ATS as a standalone manual
# multilingual plugin surface: languages, virtual-site relation, REST manual
# save, object/meta/term/menu/string/gettext hooks, and SEO alternates.
#
# Usage:
#   WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-manual-only-multilingual-gate.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# Preserve caller slot WP for full-chain; bare unset would force shared :9083.
CALLER_WP_URL="${PRE_WP_WP_URL:-${WP_URL:-}}"
CALLER_SLOT="${PRE_WP_E2E_SLOT:-${E2E_SLOT:-}}"
unset E2E_SLOT E2E_SLOT_WP_ISOLATED E2E_SLOT_WP_BASE LAB_WP_CONTAINER \
  WP_URL WP_BASE CLIENT_BASE WPTSALL_DB_PATH CLIENT_URL \
  WPTSALL_WEB_UI_BIND WPTSALL_WEB_UI_PORT \
  2>/dev/null || true
if [[ -n "${CALLER_SLOT}" && "${CALLER_SLOT}" != "shared" ]]; then
  export E2E_SLOT="${CALLER_SLOT}"
  export E2E_SLOT_WP_ISOLATED=1
fi
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib/manual-isolation.sh"

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
if [[ -n "${CALLER_WP_URL}" ]]; then
  export WP_URL="${CALLER_WP_URL}"
fi
WP_BASE="${WP_URL:-http://127.0.0.1:9083}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RAW_REPORT="${REPORTS_DIR}/manual-only-multilingual-gate-${STAMP}.raw.log"
PHP_REPORT="${REPORTS_DIR}/manual-only-multilingual-gate-${STAMP}.php.json"
FINAL_REPORT="${REPORTS_DIR}/manual-only-multilingual-gate-${STAMP}.json"
mkdir -p "${REPORTS_DIR}"

json_get() {
  local file="$1" path="$2"
  php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($j)) { exit(2); }
    $v = $j;
    foreach (explode(".", $argv[2]) as $p) {
      if (!is_array($v) || !array_key_exists($p, $v)) { exit(3); }
      $v = $v[$p];
    }
    if (is_bool($v)) { echo $v ? "1" : "0"; }
    elseif (is_scalar($v) || $v === null) { echo (string) $v; }
    else { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
  ' "$file" "$path"
}

contains_fixed() {
  local file="$1" needle="$2"
  grep -Fq -- "$needle" "$file"
}

http_fetch() {
  local url="$1" body="$2"
  curl -sS -L -o "$body" -w '%{http_code}' --max-time 30 "$url" 2>/dev/null || echo ERR
}

CHECK_ARGS=()
FAIL=0
add_check() {
  local name="$1" ok_flag="$2" detail="$3"
  CHECK_ARGS+=("$name" "$ok_flag" "$detail")
  if [[ "$ok_flag" == "1" ]]; then
    echo "✅ ${name}: ${detail}"
  else
    echo "❌ ${name}: ${detail}" >&2
    FAIL=$((FAIL + 1))
  fi
}

echo "== Manual-only multilingual gate =="
echo "WP_BASE=${WP_BASE}"
echo "No client / no auto translation"

# --- P0-EV-01: observe runtime isolation for the whole gate window ---------
if ! manual_isolation_preflight; then
  echo "❌ Manual gate precondition failed: forbidden client/mock/8977/9090/8787 infrastructure is already running" >&2
  exit 1
fi
manual_isolation_begin

set +e
wp_eval "${E2E_DIR}/php/manual-only-multilingual-gate.php" 2>&1 | tee "${RAW_REPORT}"
WP_RC=${PIPESTATUS[0]}
set -e

awk 'BEGIN{found=0} /^\{/ {found=1} found {print}' "${RAW_REPORT}" > "${PHP_REPORT}"
if ! php -r '$j=json_decode(file_get_contents($argv[1]), true); exit(is_array($j) ? 0 : 1);' "${PHP_REPORT}"; then
  echo "❌ Could not extract JSON from ${RAW_REPORT}" >&2
  exit 1
fi

PHP_OK="$(json_get "${PHP_REPORT}" ok || echo 0)"
if [[ "${WP_RC}" -ne 0 || "${PHP_OK}" != "1" ]]; then
  php -r '
    $j=json_decode(file_get_contents($argv[1]), true) ?: array();
    $j["wp_cli_exit"]=(int)$argv[3];
    $j["raw_report"]=$argv[4];
    file_put_contents($argv[2], json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  ' "${PHP_REPORT}" "${FINAL_REPORT}" "${WP_RC}" "${RAW_REPORT}"
  echo "❌ PHP manual-only gate failed: ${FINAL_REPORT}" >&2
  exit 1
fi

VIRTUAL_URL="$(json_get "${PHP_REPORT}" urls.virtual)"
HOME_URL="$(json_get "${PHP_REPORT}" urls.home)"
TERM_URL="$(json_get "${PHP_REPORT}" urls.term)"
EXPECTED_TITLE="$(json_get "${PHP_REPORT}" expected.title)"
EXPECTED_CONTENT="$(json_get "${PHP_REPORT}" expected.content)"
EXPECTED_TERM="$(json_get "${PHP_REPORT}" expected.term)"
EXPECTED_HREFLANG="$(json_get "${PHP_REPORT}" expected.hreflang)"
PREFIX="$(json_get "${PHP_REPORT}" prefix)"

BODY_VIRTUAL="${REPORTS_DIR}/manual-only-multilingual-gate-${STAMP}.virtual.html"
BODY_HOME="${REPORTS_DIR}/manual-only-multilingual-gate-${STAMP}.home.html"
BODY_TERM="${REPORTS_DIR}/manual-only-multilingual-gate-${STAMP}.term.html"

code="$(http_fetch "${VIRTUAL_URL}" "${BODY_VIRTUAL}")"
add_check "front virtual post HTTP 200" "$( [[ "$code" == "200" ]] && echo 1 || echo 0 )" "http=${code} url=${VIRTUAL_URL}"
add_check "front virtual post shows manual title" "$( contains_fixed "${BODY_VIRTUAL}" "${EXPECTED_TITLE}" && echo 1 || echo 0 )" "title=${EXPECTED_TITLE}"
add_check "front virtual post shows manual content" "$( contains_fixed "${BODY_VIRTUAL}" "${EXPECTED_CONTENT}" && echo 1 || echo 0 )" "content=${EXPECTED_CONTENT}"
if grep -Eiq "hreflang=\"${EXPECTED_HREFLANG}\"" "${BODY_VIRTUAL}"; then
  add_check "front virtual post emits target hreflang" 1 "hreflang=${EXPECTED_HREFLANG}"
else
  add_check "front virtual post emits target hreflang" 0 "hreflang=${EXPECTED_HREFLANG} not found"
fi
if grep -Eq 'hreflang="x-default"' "${BODY_VIRTUAL}"; then
  add_check "front virtual post emits x-default" 1 "x-default present"
else
  add_check "front virtual post emits x-default" 0 "x-default not found"
fi

code="$(http_fetch "${HOME_URL}" "${BODY_HOME}")"
add_check "front virtual home HTTP 200" "$( [[ "$code" == "200" ]] && echo 1 || echo 0 )" "http=${code} url=${HOME_URL}"
if grep -Eq 'wptsall-virtual-site|hreflang=' "${BODY_HOME}"; then
  add_check "front virtual home has virtual-site markers" 1 "prefix=/${PREFIX}/"
else
  add_check "front virtual home has virtual-site markers" 0 "prefix=/${PREFIX}/ markers missing"
fi

code="$(http_fetch "${TERM_URL}" "${BODY_TERM}")"
add_check "front virtual taxonomy HTTP 200" "$( [[ "$code" == "200" ]] && echo 1 || echo 0 )" "http=${code} url=${TERM_URL}"
add_check "front virtual taxonomy shows association shadow term" "$( contains_fixed "${BODY_TERM}" "${EXPECTED_TERM}" && echo 1 || echo 0 )" "term=${EXPECTED_TERM}"
add_check "front virtual taxonomy lists translated post" "$( contains_fixed "${BODY_TERM}" "${EXPECTED_TITLE}" && echo 1 || echo 0 )" "title=${EXPECTED_TITLE}"

# --- P0-EV-01: finish observation, assert, and attach evidence -------------
GATE_FAILED=0
manual_isolation_finish
if ! manual_isolation_assert; then
  echo "❌ Manual-gate isolation violations detected (see [manual-isolation] output above)" >&2
  GATE_FAILED=1
fi
ISOLATION_JSON="${REPORTS_DIR}/manual-only-multilingual-gate-${STAMP}.isolation.json"
if ! manual_isolation_write_json "${ISOLATION_JSON}"; then
  echo "❌ Failed to write isolation evidence block" >&2
  GATE_FAILED=1
fi

set +e
php -r '
  $base = json_decode(file_get_contents($argv[1]), true) ?: array();
  $checks = array();
  for ($i = 7; $i < count($argv); $i += 3) {
    $checks[] = array(
      "name" => $argv[$i],
      "ok" => $argv[$i + 1] === "1",
      "detail" => $argv[$i + 2],
    );
  }
  $gate_failed = $argv[5] === "1";
  $isolation_path = $argv[6];
  $isolation = is_readable($isolation_path)
    ? json_decode(file_get_contents($isolation_path), true)
    : null;
  if (!is_array($isolation)) {
    $isolation = array("present" => false);
  }
  $ok = !empty($base["ok"]) && !$gate_failed;
  foreach ($checks as $check) {
    if (empty($check["ok"])) { $ok = false; }
  }
  $skip_iso = getenv("MANUAL_ISOLATION_SKIP") === "1" || getenv("FULL_CHAIN_MENU_SEO") === "1";
  if ($skip_iso) {
    if (!is_array($isolation)) { $isolation = array(); }
    $isolation["ok"] = true;
    $isolation["skipped"] = true;
    $isolation["skip_reason"] = "MANUAL_ISOLATION_SKIP/FULL_CHAIN_MENU_SEO";
  } elseif (empty($isolation["ok"])) {
    $ok = false;
  }
  $base["execution"] = array(
    "mode" => "manual_only",
    "gate" => "run-manual-only-multilingual-gate",
    "dry_run" => false,
    "gate_failed" => $gate_failed,
  );
  $base["isolation"] = $isolation;
  $base["ok"] = $ok;
  $base["wp_cli_exit"] = (int) $argv[3];
  $base["raw_report"] = $argv[4];
  $base["http_assertions"] = $checks;
  file_put_contents($argv[2], json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $argv[2] . PHP_EOL;
  exit($ok ? 0 : 1);
' "${PHP_REPORT}" "${FINAL_REPORT}" "${WP_RC}" "${RAW_REPORT}" "${GATE_FAILED}" "${ISOLATION_JSON}" "${CHECK_ARGS[@]}"
PHP_MERGE_RC=$?
set -e

if [[ "${FAIL}" -gt 0 || "${PHP_MERGE_RC}" -ne 0 ]]; then
  echo "❌ Manual-only multilingual gate failed: ${FINAL_REPORT}" >&2
  exit 1
fi

echo "✅ Manual-only multilingual gate passed: ${FINAL_REPORT}"

if [[ "${FAIL}" -gt 0 ]]; then
  echo "❌ Manual-only multilingual gate failed: ${FINAL_REPORT}" >&2
  exit 1
fi

echo "✅ Manual-only multilingual gate passed: ${FINAL_REPORT}"
