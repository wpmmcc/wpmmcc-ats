#!/usr/bin/env bash
# OTA UI lane (批 O3 / U-2, 12号 §29): the Settings → About → check-update →
# Update-Now → settle "Already up to date" journey, driven through the REAL
# web UI (Playwright). Closes the U-2 gap: the secure OTA suite
# (run-ota-secure-suite.sh) gates the CLI/API path; until now NO spec walked
# the UI journey.
#
# Stack (client-only, NO WP / website / server / PG):
#   - source-built client with WPTSALL_MINISIGN_PUBKEY override (the updater
#     pubkey IS build-time configurable — libs/wptsall-client-security/build.rs
#     embeds the env value with rerun-if-env-changed), bound to 127.0.0.1:9085
#   - staged install root: bin/ + ui/webui (fresh dist) + VERSION-WEBUI=2.1.0,
#     NO WPTSALL_WEB_UI_PATH — the client serves the INSTALL ROOT UI so the
#     kit apply is the honest serving path
#   - mock release server (mock-release-server.py) serving the host-signed
#     kit-webui tarball + signed releases manifest; manifest latest = 2.1.2 so
#     the journey SETTLES: staged 2.1.0 < 2.1.2 → update available (kind=ui) →
#     apply swaps the install root UI → re-check = "Already up to date"
#
# Port note: the lab docker maps 0.0.0.0:9081/9082/9083 to the WP container —
# this lane's client binds 9085 (outside the range).
#
# Negative phase (after the positive journey): tamper releases.minisig →
# restart client → the UI must surface about-update-error with the
# 'Update check failed' copy (settings.update_check_failed).
#
# Usage: bash tests/modules/wpmmcc-ats/e2e/run-playwright-ota-ui.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../../../lib/repo-root.sh
source "${SCRIPT_DIR}/../../../lib/repo-root.sh"
ROOT_DIR="$(wptsall_repo_root "${SCRIPT_DIR}")"
E2E_DIR="$ROOT_DIR/tests/modules/wpmmcc-ats/e2e"
PLAYWRIGHT_DIR="${E2E_DIR}/playwright"

INSTALL_CLIENT="$ROOT_DIR/install-client"
KIT_DIR="$INSTALL_CLIENT/dist/kits"
KEY="$INSTALL_CLIENT/security/keys/wptsall-minisign.key"
PUB='RWR7lrdabZEEywfWEfrRJXIyP5h+LHEabOA8JFiNJ3vGLpNtppyabHfP'
HOST_PLAT="linux-$(uname -m | sed 's/amd64/x86_64/;s/arm64/aarch64/')"
HOST_KIT="$KIT_DIR/kit-webui-${HOST_PLAT}.tar.gz"
CLIENT_SRC="$ROOT_DIR/client-wpplugin/source"
WEB_DIST="$CLIENT_SRC/frontend/dist"
# Dedicated cargo target: the pubkey env flip must not churn the shared
# target's security-lib state back and forth across lanes. **/.cache/ is
# gitignored; the dir stays warm across runs of this lane.
OTA_TARGET_DIR="$ROOT_DIR/tests/.cache/ota-ui-cargo-target"

STAGED_UI_VERSION="2.1.0"
KIT_VERSION="$(tar -xzOf "$HOST_KIT" wptsall-client-webui/VERSION-WEBUI 2>/dev/null | tr -d '[:space:]' || true)"
LATEST_VERSION="${OTA_UI_LATEST_VERSION:-$KIT_VERSION}"
CLIENT_PORT="${OTA_UI_CLIENT_PORT:-9085}"
CLIENT_BASE="http://127.0.0.1:${CLIENT_PORT}"

WORKDIR="$(mktemp -d /tmp/wptsall-ota-ui.XXXXXX)"
STAGE="$WORKDIR/install"
MOCK_PID=""
CLIENT_PID=""

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }
wait_url() {
  local url="$1" label="$2"
  for _ in $(seq 1 120); do
    curl -fsS "${url}" >/dev/null 2>&1 && return 0
    sleep 0.25
  done
  fail "${label} did not become ready: ${url}"
}

cleanup() {
  if [ -n "${CLIENT_PID:-}" ]; then kill "${CLIENT_PID}" 2>/dev/null || true; fi
  if [ -n "${MOCK_PID:-}" ]; then kill "${MOCK_PID}" 2>/dev/null || true; fi
  # The ui apply path may revive/spawn helper processes on the staged binary.
  pkill -f "$STAGE/bin/wptsall-client" 2>/dev/null || true
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

[ "$(uname -s)" = "Linux" ] || fail "OTA UI lane requires Linux (host kit)"
[ -f "$HOST_KIT" ] || fail "missing $HOST_KIT — build/sign the webui kit first"
[ -f "$HOST_KIT.minisig" ] || fail "missing $HOST_KIT.minisig"
[ -f "$KEY" ] || fail "missing signing key $KEY"
command -v minisign >/dev/null || fail "minisign required"
command -v cargo >/dev/null || fail "cargo required"
[ -f "$WEB_DIST/index.html" ] || fail "frontend dist missing: $WEB_DIST (npm run build)"

[ -n "$LATEST_VERSION" ] || fail "could not read kit VERSION-WEBUI"
echo "== OTA UI lane: kit=$HOST_KIT kit_version=$KIT_VERSION latest=$LATEST_VERSION =="

bash "$INSTALL_CLIENT/packaging/validate-kit.sh" webui "$HOST_KIT" --version "$KIT_VERSION" \
  || fail "host kit archive validation failed"
minisign -Vm "$HOST_KIT" -P "$PUB" -x "$HOST_KIT.minisig" >/dev/null \
  || fail "host kit minisign verify failed"

echo "-- build client with WPTSALL_MINISIGN_PUBKEY override (dedicated target) --"
if [ -f "${HOME}/.cargo/env" ]; then
  # shellcheck source=/dev/null
  source "${HOME}/.cargo/env"
fi
(
  cd "$CLIENT_SRC"
  export CARGO_TARGET_DIR="$OTA_TARGET_DIR"
  export WPTSALL_MINISIGN_PUBKEY="$PUB"
  cargo build -q --bin wptsall-client
)
BIN="$OTA_TARGET_DIR/debug/wptsall-client"
[ -x "$BIN" ] || fail "built client binary missing: $BIN"

# --- Stage install root: the client serves the INSTALL ROOT UI (no
# WPTSALL_WEB_UI_PATH) so the kit apply is the honest serving path. ---
mkdir -p "$STAGE/bin" "$STAGE/ui/webui"
cp "$BIN" "$STAGE/bin/wptsall-client"
chmod +x "$STAGE/bin/wptsall-client"
cp -r "$WEB_DIST/." "$STAGE/ui/webui/"
echo "$STAGED_UI_VERSION" > "$STAGE/VERSION-WEBUI"

# --- Mock release server: host kit + signed manifest ---
HTTP_DIR="$WORKDIR/http"
mkdir -p "$HTTP_DIR"
ASSET="$(basename "$HOST_KIT")"
cp "$HOST_KIT" "$HOST_KIT.minisig" "$HTTP_DIR/"
if [ -f "$KIT_DIR/RELEASE-SHA256SUMS-webui.txt" ]; then
  cp "$KIT_DIR/RELEASE-SHA256SUMS-webui.txt" "$HTTP_DIR/"
  cp "$KIT_DIR/RELEASE-SHA256SUMS-webui.txt.minisig" "$HTTP_DIR/" 2>/dev/null || true
else
  (cd "$HTTP_DIR" && sha256sum "$ASSET" > RELEASE-SHA256SUMS-webui.txt)
  minisign -Sm "$HTTP_DIR/RELEASE-SHA256SUMS-webui.txt" -s "$KEY" \
    -x "$HTTP_DIR/RELEASE-SHA256SUMS-webui.txt.minisig"
fi

# UI-axis artifact: the production manifest points the client-wpplugin-webui
# axis at a UI-ONLY bundle (webui-ui-{version}.tar.gz — ui/ + VERSION-WEBUI at
# the archive ROOT, the layout apply_ui_bundle expects). The host kit is the
# FULL install kit wrapped under wptsall-client-webui/ — repack its ui/ +
# VERSION-WEBUI as the unwrapped ui-only bundle and sign it (artifact .minisig
# + signed SHA256SUMS — the dual path download_and_verify_signed_artifact
# exercises: detached artifact sig + SUMS match).
UI_ASSET="webui-ui-${LATEST_VERSION}.tar.gz"
UI_BUNDLE_SRC="$WORKDIR/ui-bundle-src"
mkdir -p "$UI_BUNDLE_SRC"
tar -xzf "$HOST_KIT" -C "$UI_BUNDLE_SRC" \
  wptsall-client-webui/ui wptsall-client-webui/VERSION-WEBUI
(cd "$UI_BUNDLE_SRC/wptsall-client-webui" \
  && tar -czf "$HTTP_DIR/$UI_ASSET" ui VERSION-WEBUI)
minisign -Sm "$HTTP_DIR/$UI_ASSET" -s "$KEY" -x "$HTTP_DIR/$UI_ASSET.minisig"
(cd "$HTTP_DIR" && sha256sum "$UI_ASSET" > SHA256SUMS-ui.txt)
minisign -Sm "$HTTP_DIR/SHA256SUMS-ui.txt" -s "$KEY" -x "$HTTP_DIR/SHA256SUMS-ui.txt.minisig"

MOCK_PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')"
DATA_FILE="$WORKDIR/releases-data.json"
MOCK_PORT="$MOCK_PORT" ASSET="$ASSET" UI_ASSET="$UI_ASSET" LATEST_VERSION="$LATEST_VERSION" DATA_FILE="$DATA_FILE" python3 - <<'PY'
import json, os
port = int(os.environ["MOCK_PORT"]); asset = os.environ["ASSET"]
ui_asset = os.environ["UI_ASSET"]; latest = os.environ["LATEST_VERSION"]
data = {
  "schema_version": 1,
  "generated_at": "2026-09-23T00:00:00Z",
  "products": {
    # Binary axis (updater.rs PRODUCT_ID "client-wpplugin"). The source-built
    # binary is 2.1.4 > the kit's 2.1.2, so the binary axis reports up to
    # date and the journey stays on the UI axis.
    "client-wpplugin": {
      "latest_version": latest,
      "min_supported_version": "2.0.0",
      "release_notes_url": f"http://127.0.0.1:{port}/notes",
      "download_url_template": f"http://127.0.0.1:{port}/{asset}",
      "signature_url_template": f"http://127.0.0.1:{port}/RELEASE-SHA256SUMS-webui.txt.minisig",
      "mandatory": False,
    },
    # UI axis (updater.rs PRODUCT_ID_UI "client-wpplugin-webui"): mirrors the
    # production manifest shape — a UI-ONLY bundle (ui/ + VERSION-WEBUI at
    # the archive root, what apply_ui_bundle expects) + signed SHA256SUMS.
    # Without this entry ui_update_available stays false (empty ui product).
    "client-wpplugin-webui": {
      "latest_version": latest,
      "min_supported_version": "2.0.0",
      "release_notes_url": f"http://127.0.0.1:{port}/notes",
      "download_url_template": f"http://127.0.0.1:{port}/{ui_asset}",
      "signature_url_template": f"http://127.0.0.1:{port}/SHA256SUMS-ui.txt.minisig",
      "mandatory": False,
    },
  },
}
json.dump(data, open(os.environ["DATA_FILE"], "w"), indent=2)
PY
bash "$INSTALL_CLIENT/security/scripts/sign-releases-manifest.sh" "$DATA_FILE" "$DATA_FILE.minisig"

export MOCK_PORT MOCK_DIR="$HTTP_DIR"
export MOCK_RELEASES_DATA_FILE="$DATA_FILE"
export MOCK_RELEASES_SIG_FILE="$DATA_FILE.minisig"
export MOCK_LATEST="$LATEST_VERSION"
export MOCK_DOWNLOAD_TEMPLATE="http://127.0.0.1:${MOCK_PORT}/${ASSET}"
export MOCK_SIGNATURE_TEMPLATE="http://127.0.0.1:${MOCK_PORT}/RELEASE-SHA256SUMS-webui.txt.minisig"

python3 "$ROOT_DIR/tests/modules/install-client/tests/webui/mock-release-server.py" >"$WORKDIR/mock.log" 2>&1 &
MOCK_PID=$!
sleep 0.4
curl -fsS "http://127.0.0.1:${MOCK_PORT}/api/v1/client/releases" | grep -q client-wpplugin \
  || fail "mock releases not serving"
curl -fsS "http://127.0.0.1:${MOCK_PORT}/api/v1/client/releases.minisig" -o /dev/null \
  || fail "mock releases.minisig missing"

start_client() {
  local home="$1"
  mkdir -p "$home/data"
  local port="${2:-$CLIENT_PORT}"
  (
    env -u WPTSALL_WEB_UI_PATH -u WPTSALL_SKIP_SECURITY -u WPTSALL_ALLOW_UNSIGNED_MANIFEST \
      WPTSALL_WEB_UI=1 \
      WPTSALL_SERVER_BASE="http://127.0.0.1:${MOCK_PORT}" \
      WPTSALL_WEB_UI_BIND="127.0.0.1:${port}" \
      WPTSALL_WEB_UI_PORT="$port" \
      WPTSALL_DATA_DIR="$home/data" \
      WPTSALL_DB_PATH="$home/wptsall.db" \
      WPTSALL_LOG_FILE="$home/client.log" \
      WPTSALL_INSTALL_ROOT="$STAGE" \
        "$STAGE/bin/wptsall-client" >"$home/client.stdout" 2>&1
  ) &
  CLIENT_PID=$!
  wait_url "http://127.0.0.1:${port}/api/status" "ota-ui client"
  echo "ota-ui client started (pid ${CLIENT_PID}) at http://127.0.0.1:${port}"
}

stop_client() {
  if [ -n "${CLIENT_PID:-}" ]; then
    kill "$CLIENT_PID" 2>/dev/null || true
    wait "$CLIENT_PID" 2>/dev/null || true
    CLIENT_PID=""
  fi
  sleep 0.3
}

run_spec() {
  echo "== Running OTA UI spec: $1 =="
  (
    cd "${PLAYWRIGHT_DIR}"
    export WPTSALL_OTA_UI_CLIENT_BASE="${CLIENT_BASE}"
    export WPTSALL_OTA_UI_STAGED_VERSION="${STAGED_UI_VERSION}"
    export WPTSALL_OTA_UI_LATEST_VERSION="${LATEST_VERSION}"
    if [ "$#" -ge 2 ]; then
      npx playwright test -c playwright.ota-ui.config.ts "$1" "$2"
    else
      npx playwright test -c playwright.ota-ui.config.ts "$1"
    fi
  )
}

# --- ONE client throughout. Phase order is NEGATIVE-FIRST: the positive
# journey's kit apply swaps the install-root UI to the kit's (older) dist,
# which predates the About-tab testids — the negative phase must drive the
# FRESH staged dist, so it runs before the swap. ---
start_client "$WORKDIR/lane"

# --- Negative: tampered releases.minisig must surface the UI error node ---
printf 'untrusted\n' > "$DATA_FILE.minisig"
CODE="$(curl -sS -o "$WORKDIR/neg-check.json" -w "%{http_code}" "${CLIENT_BASE}/api/update-check" || true)"
echo "negative update-check HTTP $CODE: $(cat "$WORKDIR/neg-check.json" 2>/dev/null || true)"
if [ "$CODE" = "200" ] && grep -q '"success":true' "$WORKDIR/neg-check.json" 2>/dev/null; then
  fail "tampered releases.minisig must not yield a successful update-check"
fi
run_spec ota-ui/ota-ui-negative.spec.ts
pass "negative: tampered manifest surfaces the UI error node"

# --- Restore the good manifest signature, then the positive journey ---
bash "$INSTALL_CLIENT/security/scripts/sign-releases-manifest.sh" "$DATA_FILE" "$DATA_FILE.minisig" >/dev/null
CHECK="$(curl -fsS "${CLIENT_BASE}/api/update-check")"
echo "pre-spec update-check: $CHECK"
echo "$CHECK" | grep -q '"update_available":true' || fail "expected update_available true pre-spec"
echo "$CHECK" | grep -q '"update_kind":"ui"' || fail "expected update_kind ui (staged UI ${STAGED_UI_VERSION} < kit ${LATEST_VERSION})"

run_spec ota-ui/ota-ui.spec.ts

# Post-spec gates: the kit apply must have ACTUALLY swapped the install root.
STAGED_AFTER="$(cat "$STAGE/VERSION-WEBUI" 2>/dev/null || true)"
[ "$STAGED_AFTER" = "$LATEST_VERSION" ] \
  || fail "install root VERSION-WEBUI is '$STAGED_AFTER' — expected '$LATEST_VERSION' (kit apply did not swap)"
CHECK_AFTER="$(curl -fsS "${CLIENT_BASE}/api/update-check" || true)"
echo "post-spec update-check: $CHECK_AFTER"
echo "$CHECK_AFTER" | grep -q '"update_available":false' \
  || fail "expected update_available false after the UI apply"
pass "install root swapped (VERSION-WEBUI ${STAGED_UI_VERSION} → ${LATEST_VERSION}); update-check reports up to date"
stop_client

echo ""
echo "OTA UI lane passed: negative (bad manifest → UI error) + journey (check → available → Update Now → Already up to date)."
