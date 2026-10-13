#!/usr/bin/env bash
# Explicit support protocol vectors, no WP/Client or shared mock service.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${HERE}/../../../lib/repo-root.sh"
ROOT="$(wptsall_repo_root)"
source "${ROOT}/scripts/lib/cargo-cache.sh"
wptsall_setup_cargo_cache mock-golden tests "${ROOT}/tests/infra/mock-api"
exec python3 "${ROOT}/tests/infra/tools/check-mock-translation-golden.py" --check
