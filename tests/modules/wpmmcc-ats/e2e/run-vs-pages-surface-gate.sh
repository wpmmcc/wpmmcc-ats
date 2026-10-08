#!/usr/bin/env bash
# Lean VS pages surface gate — release-required (RG-PAGES-SURFACE).
# Discover fixtures in-container; HTTP assert from host (Lab :9083 is host-published).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/config.sh"

export WPTSALL_LAB="${WPTSALL_LAB:-1}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RAW_REPORT="${REPORTS_DIR}/vs-pages-surface-gate-${STAMP}.raw.log"
PHP_REPORT="${REPORTS_DIR}/vs-pages-surface-gate-${STAMP}.php.json"
FINAL_REPORT="${REPORTS_DIR}/vs-pages-surface-gate-${STAMP}.json"
mkdir -p "${REPORTS_DIR}"

WP_BASE="${WP_URL%/}"
echo "== VS pages surface gate =="
echo "  WP: ${WP_BASE}"
echo "  Report: ${FINAL_REPORT}"

set +e
wp_eval "${SCRIPT_DIR}/php/vs-pages-surface-gate.php" >"${RAW_REPORT}" 2>&1
RC=$?
set -e

php -r '
$raw = file_get_contents($argv[1]);
$lines = array_reverse(preg_split("/\r\n|\n|\r/", $raw));
$json = null;
foreach ($lines as $line) {
  $line = trim($line);
  if ($line === "" || $line[0] !== "{") { continue; }
  $j = json_decode($line, true);
  if (is_array($j) && array_key_exists("ok", $j)) { $json = $j; break; }
}
if (!is_array($json)) {
  fwrite(STDERR, "vs-pages-surface: no JSON payload in wp eval output\n");
  file_put_contents($argv[2], json_encode(["ok"=>false,"error"=>"no_json","raw"=>substr($raw,0,4000)], JSON_PRETTY_PRINT)."\n");
  exit(2);
}
file_put_contents($argv[2], json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
exit(!empty($json["ok"]) ? 0 : 1);
' "${RAW_REPORT}" "${PHP_REPORT}"
PHP_RC=$?
if [[ "${PHP_RC}" -ne 0 || "${RC}" -ne 0 ]]; then
  python3 - <<PY
import json
from pathlib import Path
Path("${FINAL_REPORT}").write_text(json.dumps({
  "status": "failed",
  "gate": "vs-pages-surface",
  "error": "fixture_discovery_failed",
  "evidence": {"php_json": "${PHP_REPORT}", "raw_log": "${RAW_REPORT}"},
}, indent=2) + "\n")
print("STATUS=failed")
PY
  exit 1
fi

http_check() {
  local name="$1" path="$2"
  local url="${WP_BASE}${path}"
  local body code title
  body="$(mktemp)"
  code="$(curl -sS -L -o "$body" -w '%{http_code}' --max-time 30 --noproxy '*' "$url" 2>/dev/null || echo ERR)"
  title="$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/<title[^>]*>(.*?)<\\/title>/is",$h,$m)){echo trim(html_entity_decode(strip_tags($m[1])));}' "$body" 2>/dev/null || true)"
  local ok=0
  if [[ "$code" == "200" ]] && [[ "${title,,}" != *"page not found"* ]] && [[ "${title,,}" != *"untitled"* ]]; then
    ok=1
  fi
  local marker=0
  if grep -Eqi 'wptsall-virtual-site|wptsall-vs-' "$body"; then
    marker=1
  fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$name" "$ok" "$code" "$marker" "$url" "$title"
  rm -f "$body"
  [[ "$ok" -eq 1 ]]
}

CHECKS_TSV="$(mktemp)"
FAIL=0
{
  http_check "source_home" "/" || FAIL=1
  VS_PATH="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["paths"]["vs_home"]??"";' "${PHP_REPORT}")"
  if [[ -n "$VS_PATH" ]]; then
    http_check "vs_home" "$VS_PATH" || FAIL=1
    S6_MODE="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["s6"]["mode"]??"";' "${PHP_REPORT}")"
    S6_TITLE="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["s6"]["expect_title"]??"";' "${PHP_REPORT}")"
    if [[ "$S6_MODE" == "mapped" && -n "$S6_TITLE" ]]; then
      body="$(mktemp)"
      code="$(curl -sS -L -o "$body" -w '%{http_code}' --max-time 30 --noproxy '*' "${WP_BASE}${VS_PATH}" 2>/dev/null || echo ERR)"
      if [[ "$code" == "200" ]] && grep -Fq -- "$S6_TITLE" "$body"; then
        printf 's6_static_front\t1\t%s\t0\t%s%s\t%s\n' "$code" "${WP_BASE}" "${VS_PATH}" "$S6_TITLE"
      else
        printf 's6_static_front\t0\t%s\t0\t%s%s\t%s\n' "$code" "${WP_BASE}" "${VS_PATH}" "$S6_TITLE"
        FAIL=1
      fi
      rm -f "$body"
    else
      printf 's6_static_front\t1\t0\t0\t\tskipped mode=%s\n' "${S6_MODE:-none}"
    fi
  else
    echo -e "vs_home\t0\t0\t0\t\tmissing path" >>"${CHECKS_TSV}"
    FAIL=1
  fi
  SH_PATH="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["paths"]["shadow_singular"]??"";' "${PHP_REPORT}")"
  if [[ -n "$SH_PATH" ]]; then
    http_check "shadow_singular" "$SH_PATH" || FAIL=1
  else
    printf 'shadow_singular\t1\t0\t0\t\tskipped no shadow\n'
  fi
} | tee "${CHECKS_TSV}"

python3 - <<PY
import json
from pathlib import Path
php = json.loads(Path("${PHP_REPORT}").read_text())
checks = {}
for line in Path("${CHECKS_TSV}").read_text().splitlines():
    parts = line.split("\t")
    if len(parts) < 6:
        continue
    name, ok, code, marker, url, title = parts[0], parts[1], parts[2], parts[3], parts[4], parts[5]
    checks[name] = {
        "ok": ok == "1",
        "code": None if code in ("", "ERR") else int(code) if code.isdigit() else code,
        "has_marker": marker == "1",
        "url": url,
        "title": title,
    }
status = "passed" if ${FAIL} == 0 else "failed"
payload = {
  "status": status,
  "gate": "vs-pages-surface",
  "timestamp": php.get("timestamp"),
  "wp_url": "${WP_BASE}",
  "vs": php.get("vs"),
  "paths": php.get("paths") or {},
  "checks": checks,
  "evidence": {"php_json": "${PHP_REPORT}", "raw_log": "${RAW_REPORT}"},
}
Path("${FINAL_REPORT}").write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n")
print("STATUS=" + status)
print("REPORT=${FINAL_REPORT}")
PY
rm -f "${CHECKS_TSV}"
[[ "${FAIL}" -eq 0 ]]
