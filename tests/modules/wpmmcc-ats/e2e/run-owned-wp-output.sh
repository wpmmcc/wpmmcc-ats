#!/usr/bin/env bash
# Explicit support entry, no new default live scope. Owns every WP/Client resource.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../../.." && pwd)"
MANUAL_EDITOR=0
CONTENT_ADMIN=0
CONTENT_MATRIX=0
PLUGIN=wpmmcc
FIXTURE_FLAGS=()
if [[ "${1:-}" == "--manual-editor" || "${1:-}" == "--content-admin" || "${1:-}" == "--content-matrix" ]]; then
  MANUAL_EDITOR=1
  PLUGIN=wpmmcc-ats
  if [[ "${1:-}" == "--content-admin" || "${1:-}" == "--content-matrix" ]]; then
    CONTENT_ADMIN=1
    FIXTURE_FLAGS=(--content-admin)
  fi
  if [[ "${1:-}" == "--content-matrix" ]]; then
    CONTENT_MATRIX=1
  fi
  shift
  if [[ $# -ne 0 ]]; then
    echo "owned manual editor accepts no extra selectors or infrastructure options" >&2
    exit 2
  fi
fi
CONTEXT_DIR="$(mktemp -d /tmp/wptsall-owned-wp-output.XXXXXX)"
CONTEXT="$CONTEXT_DIR/context.json"
cleanup() {
  local result=$?
  trap - EXIT INT TERM
  if [[ -f "$CONTEXT" ]]; then
    if ! python3 "$ROOT/tests/infra/tools/owned-wp-sites.py" stop --context "$CONTEXT"; then
      echo "owned WP cleanup failed; context retained at $CONTEXT (private, do not publish)" >&2
      exit 1
    fi
  fi
  # This directory was made above and holds only this wrapper's private context.
  rm -f "$CONTEXT"
  if [[ "$MANUAL_EDITOR" -eq 0 ]]; then
    rmdir "$CONTEXT_DIR"
  else
    echo "Owned manual diagnostics retained at $CONTEXT_DIR/artifacts (private context removed)"
  fi
  exit "$result"
}
trap cleanup EXIT INT TERM
python3 "$ROOT/tests/infra/tools/owned-wp-sites.py" start --context "$CONTEXT" --plugin "$PLUGIN" "${FIXTURE_FLAGS[@]}"
export WPTSALL_OWNED_WP_CONTEXT="$CONTEXT"
if [[ "$MANUAL_EDITOR" -eq 1 ]]; then
  unset WP_BASE WP_URL WP_ADMIN_USER WP_ADMIN_PASS WP_DOCKER_CONTAINER CONTENT_MATRIX_SAVE CONTENT_MATRIX_LIMIT CONTENT_MATRIX_NO_WPCLI
  unset WPTSALL_CONTENT_MATRIX_OWNED
  export WPTSALL_MANUAL_EDITOR_OWNED=1
  export WPTSALL_MANUAL_ARTIFACTS_DIR="$CONTEXT_DIR/artifacts"
  mkdir -m 700 "$WPTSALL_MANUAL_ARTIFACTS_DIR"
  echo "Owned ATS manual editor: no Client, worker or mock is started"
  cd "$HERE/playwright"
  CONFIG=playwright.manual-editor-owned.config.ts
  EVIDENCE=owned-manual-editor-output.evidence
  REPORT_KIND=owned-manual-editor
  if [[ "$CONTENT_ADMIN" -eq 1 ]]; then
    export WPTSALL_CONTENT_ADMIN_OWNED=1
    CONFIG=playwright.content-admin-owned.config.ts
    EVIDENCE=owned-content-admin-output.evidence
    REPORT_KIND=owned-content-admin
  else
    unset WPTSALL_CONTENT_ADMIN_OWNED
  fi
  if [[ "$CONTENT_MATRIX" -eq 1 ]]; then
    export WPTSALL_CONTENT_MATRIX_OWNED=1
    CONFIG=playwright.content-matrix-owned.config.ts
    EVIDENCE=owned-content-matrix-output.evidence
    REPORT_KIND=owned-content-matrix
  fi
  set +e
  npx playwright test -c "$CONFIG"
  RESULT=$?
  set -e
  if [[ "$RESULT" -eq 0 && ! -s "$WPTSALL_MANUAL_ARTIFACTS_DIR/$EVIDENCE" ]]; then
    echo "owned manual editor passed without nonempty output; refusing success" >&2
    RESULT=1
  fi
  # Reports contain only test-owned fields/IDs, never the private context.
  REPORT_DIR="$ROOT/tests/reports/e2e/wpmmcc-ats/$REPORT_KIND/$(date -u +%Y%m%dT%H%M%SZ)-$$"
  mkdir -p "$REPORT_DIR"
  if [[ -f "$WPTSALL_MANUAL_ARTIFACTS_DIR/$EVIDENCE" ]]; then
    cp "$WPTSALL_MANUAL_ARTIFACTS_DIR/$EVIDENCE" "$REPORT_DIR/"
  fi
  echo "Owned manual editor artifacts: $REPORT_DIR"
  exit "$RESULT"
fi
bash "$HERE/run-playwright-support-client-local-ui.sh" "$@"
