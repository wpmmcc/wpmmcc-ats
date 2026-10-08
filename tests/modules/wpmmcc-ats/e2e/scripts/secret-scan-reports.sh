#!/usr/bin/env bash
# Secret scan on reports/ and runtime manifests (ISS S5 / W6).
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
TARGETS=(
  "${ROOT_DIR}/tests/reports/e2e/wpmmcc-ats"
  "${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
  "${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/lab-logs"
)
PATTERNS=(
  'sk-[A-Za-z0-9]{20,}'
  'AKIA[0-9A-Z]{16}'
  '-----BEGIN (RSA |OPENSSH )?PRIVATE KEY-----'
  'wptsall_client_api_token["'\'']?\s*[:=]\s*["'\''][a-f0-9]{32,}'
  'api[_-]?key["'\'']?\s*[:=]\s*["'\''][^"'\''\s]{16,}'
)

hits=0
report="${ROOT_DIR}/tests/reports/e2e/wpmmcc-ats/secret-scan-$(date +%Y%m%d-%H%M%S).txt"
mkdir -p "$(dirname "${report}")"
{
  echo "secret-scan $(date -Iseconds)"
  for t in "${TARGETS[@]}"; do
    [[ -d "$t" ]] || continue
    for p in "${PATTERNS[@]}"; do
      # ripgrep if available else grep -R. Excluded: this scanner's own
      # reports (they quote matched excerpts, so scanning them would make
      # every later run "find" the previous run's findings forever).
      if command -v rg >/dev/null 2>&1; then
        matches="$(rg -n --hidden -g '!*.db' -g '!*.sqlite' -g '!secret-scan-*.txt' -g '!secret-scan.txt' -g '!secrets-audit-*.json' -e "$p" "$t" || true)"
      else
        matches="$(grep -RInE --exclude='*.db' --exclude='*.sqlite' --exclude='secret-scan-*.txt' --exclude='secret-scan.txt' --exclude='secrets-audit-*.json' "$p" "$t" 2>/dev/null || true)"
      fi
      if [[ -n "${matches}" ]]; then
        echo "HIT pattern=${p}"
        echo "${matches}" | head -50
        hits=$((hits + 1))
      fi
    done
  done
  echo "hits=${hits}"
} | tee "${report}"

if [[ "${hits}" -gt 0 ]]; then
  echo "[secret-scan] FAIL report=${report}" >&2
  exit 1
fi
echo "[secret-scan] PASS report=${report}"
