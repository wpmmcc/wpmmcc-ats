#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
WP_ROOT="${WPTSALL_WP_ROOT:-/var/www/wordpress}"
DRY_RUN=1

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/release-rollback.sh [--dry-run|--apply]

Options:
  --dry-run  Print rollback commands without applying changes. This is the default.
  --apply    Apply the rollback option changes. Default is dry-run.
  -h, --help Show this help.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --apply)
      DRY_RUN=0
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

run_wp() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    printf '[dry-run] wp %s\n' "$*"
    return 0
  fi
  (cd "${WP_ROOT}" && wp "$@")
}

echo "WPTSALL release rollback"
echo "WP_ROOT=${WP_ROOT}"
echo "mode=$([[ "${DRY_RUN}" == "1" ]] && echo dry-run || echo apply)"

run_wp option update wptsall_require_protocol_v2 0
run_wp option update wptsall_enable_translation_simulation 0
run_wp option update wptsall_translation_marker_mode unified
run_wp option update wptsall_hooks_auto_corrections 0

echo "Rollback commands completed."
