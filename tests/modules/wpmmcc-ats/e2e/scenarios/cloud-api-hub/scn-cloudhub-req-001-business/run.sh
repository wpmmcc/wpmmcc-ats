#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
SPEC_FILE="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/scenarios/cloud-api-hub/scn-cloudhub-req-001-business/spec.json"

bash "${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/scenarios/lib/run-standard-scenario.sh" --spec "${SPEC_FILE}" "$@"
