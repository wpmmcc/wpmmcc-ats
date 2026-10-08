#!/usr/bin/env bash
# Soft gate: ensure Tested up to has an evidence row (not empty pending forever).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../../.." && pwd)"
README="$ROOT/wpmmcc-ats/source/readme.txt"
EVIDENCE="$ROOT/tests/modules/wpmmcc-ats/e2e/TESTED-UP-TO-EVIDENCE.md"
claimed="$(grep -E '^Tested up to:' "$README" | head -1 | awk '{print $NF}')"
echo "readme Tested up to: $claimed"
if ! grep -q "| ${claimed} |" "$EVIDENCE"; then
  echo "FAIL: no evidence row for Tested up to $claimed in TESTED-UP-TO-EVIDENCE.md" >&2
  exit 1
fi
if grep -E "\| ${claimed} \|.*_pending_" "$EVIDENCE" >/dev/null; then
  echo "WARN: Tested up to $claimed evidence still pending Lab revalidation"
  if [[ "${WPTSALL_REQUIRE_TESTED_UP_TO_EVIDENCE:-0}" == "1" ]]; then
    exit 2
  fi
fi
echo "PASS: evidence file references $claimed"
