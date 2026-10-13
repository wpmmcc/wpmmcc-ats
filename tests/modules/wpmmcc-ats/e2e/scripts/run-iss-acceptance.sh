#!/usr/bin/env bash
# Full ISS acceptance runner: sync artifact, run WP proofs, SEO, fault, secret-scan.
set -euo pipefail
# scripts/ → e2e/ (E2E); five ups → repo root (ROOT).
ROOT="$(cd "$(dirname "$0")/../../../../.." && pwd)"
E2E="$(cd "$(dirname "$0")/.." && pwd)"
REPORTS="${ROOT}/tests/reports/e2e/wpmmcc-ats"
TS="$(date +%Y%m%d-%H%M%S)"
OUT="${REPORTS}/iss-acceptance-${TS}"
mkdir -p "${OUT}"

if [[ ! -d "${ROOT}/wpmmcc-ats/source" || ! -d "${E2E}/scripts" ]]; then
  echo "[iss] ERROR: path layout mismatch ROOT=${ROOT} E2E=${E2E}" >&2
  exit 127
fi

echo "[iss] Lab mounts source as wpmmcc-ats only (PRE-RELEASE-SINGLE-TRUTH)"
bash "${ROOT}/tests/docker-lab/scripts/sync-plugin-from-source.sh" | tee "${OUT}/sync.log"

CID="$(docker ps --filter name=wptsall-wp-lab-wordpress-test --format '{{.Names}}' | head -1)"
if [[ -z "${CID}" ]]; then
  echo "Lab wordpress-test container not running" >&2
  exit 1
fi

# The stock WordPress Apache image intentionally does not include WP-CLI.
# Install the cached, pinned Lab helper before invoking eval-file so this
# acceptance gate is reproducible from a freshly started container.
bash "${ROOT}/tests/docker-lab/scripts/ensure-wp-cli.sh" wordpress-test

echo "[iss] WP acceptance proofs"
docker cp "${E2E}/scripts/verify-iss-acceptance.php" "${CID}:/tmp/verify-iss-acceptance.php"
set +e
docker exec "${CID}" wp eval-file /tmp/verify-iss-acceptance.php --allow-root \
  > "${OUT}/wp-acceptance.json" 2>"${OUT}/wp-acceptance.err"
wp_rc=$?
set -e
if [[ ! -s "${OUT}/wp-acceptance.json" ]]; then
  echo "WP acceptance produced no JSON (rc=${wp_rc})" >&2
  exit 1
fi
echo "[iss] WP acceptance JSON written (wp_rc=${wp_rc}; continuing remaining gates)"

echo "[iss] SEO HTML/XML"
php "${E2E}/scripts/verify-seo-html-xml-matrix.php" --base="${WP_BASE_URL:-http://127.0.0.1:9083}" \
  > "${OUT}/seo.json"

echo "[iss] provider fault fixture + runtime writeback≤1"
bash "${E2E}/scripts/run-provider-fault-matrix.sh" | tee "${OUT}/fault.path"
cp -a "$(ls -1t "${REPORTS}"/provider-fault-matrix-*.json | head -1)" "${OUT}/fault.json"
bash "${E2E}/scripts/prove-provider-fault-writeback.sh" | tee "${OUT}/fault-runtime.log"
cp -a "$(ls -1t "${REPORTS}"/provider-fault-runtime-*.json | head -1)" "${OUT}/fault-runtime.json"

echo "[iss] secret scan"
bash "${E2E}/scripts/secret-scan-reports.sh" | tee "${OUT}/secret-scan.txt"

echo "[iss] S4 signed cross-service revocation catalog"
cargo test --manifest-path "${ROOT}/web/source/server/Cargo.toml" --bin wptsall-server \
  component_revocation_catalog_is_published_and_download_is_blocked -- --nocapture \
  > "${OUT}/s4-server-revocation.log" 2>&1
cargo test --manifest-path "${ROOT}/client-wpplugin/source/Cargo.toml" \
  signed_revocation_catalog_ --lib -- --nocapture \
  > "${OUT}/s4-client-revocation.log" 2>&1
touch "${OUT}/s4-revocation.ok"

echo "[iss] S5 external credential references"
cargo test --manifest-path "${ROOT}/client-wpplugin/source/Cargo.toml" \
  credential_references_ --lib -- --nocapture \
  > "${OUT}/s5-credential-references.log" 2>&1
touch "${OUT}/s5-credential-references.ok"

echo "[iss] T2 namespaced slot cleanup apply + resource reaping"
bash "${E2E}/scripts/verify-slot-cleanup.sh" | tee "${OUT}/slot-cleanup.log"
cp -a "${E2E}/runtime/t2-slot-cleanup.json" "${OUT}/slot-cleanup.json"

echo "[iss] T1 isolated WordPress slot proof"
bash "${E2E}/scripts/verify-slot-isolation.sh" | tee "${OUT}/slot-isolation.log"
cp -a "${E2E}/runtime/t1-slot-isolation.json" "${OUT}/slot-isolation.json"

echo "[iss] eight scenarios evidence"
bash "${E2E}/scripts/run-iss-eight-scenarios.sh" | tee "${OUT}/eight-scenarios.log"
cp -a "$(ls -1t "${REPORTS}"/iss-eight-scenarios-*.json | head -1)" "${OUT}/eight-scenarios.json"

echo "[iss] gpt5.5xhigh S1–S7 user-scenario evidence board"
bash "${E2E}/scripts/emit-user-scenario-evidence.sh" | tee "${OUT}/user-scenario-evidence.log"
cp -a "$(ls -1t "${REPORTS}"/user-scenario-evidence-*.json | head -1)" "${OUT}/user-scenario-evidence.json" || true

python3 - "${OUT}" <<'PY'
import json, pathlib, sys
out = pathlib.Path(sys.argv[1])
wp = json.loads((out/"wp-acceptance.json").read_text() or "{}")
seo = json.loads((out/"seo.json").read_text() or "{}")
fault_rt = {}
if (out/"fault-runtime.json").exists():
    fault_rt = json.loads((out/"fault-runtime.json").read_text() or "{}")
eight = {}
if (out/"eight-scenarios.json").exists():
    eight = json.loads((out/"eight-scenarios.json").read_text() or "{}")
slot = {}
if (out/"slot-isolation.json").exists():
    slot = json.loads((out/"slot-isolation.json").read_text() or "{}")
t2 = {}
if (out/"slot-cleanup.json").exists():
    t2 = json.loads((out/"slot-cleanup.json").read_text() or "{}")
s4_ok = (out/"s4-revocation.ok").exists()
s5_ok = (out/"s5-credential-references.ok").exists()
hard = int(wp.get("hard_fail") or 0) + int(seo.get("hard_fail") or 0)
hard += int(fault_rt.get("hard_fail") or 0)
hard += int(eight.get("hard_fail") or 0)
hard += int(slot.get("hard_fail") or 0)
hard += int(t2.get("hard_fail") or 0)
hard += 0 if s4_ok else 1
hard += 0 if s5_ok else 1
failed = [c for c in wp.get("checks", []) if not c.get("ok") and c.get("hard")]
failed += [c for c in seo.get("checks", []) if not c.get("ok") and c.get("hard")]
failed += [c for c in t2.get("checks", []) if not c.get("ok") and c.get("hard")]
summary = {
  "wp_hard_fail": wp.get("hard_fail"),
  "wp_pass": wp.get("pass"),
  "seo_hard_fail": seo.get("hard_fail"),
  "seo_soft_fail": seo.get("soft_fail"),
  "fault_runtime_ok": fault_rt.get("ok"),
  "fault_wp_rows": (fault_rt.get("writeback_wp") or {}).get("rows"),
  "eight_ok": eight.get("ok"),
  "t1_slot_isolation_ok": slot.get("ok"),
  "t2_slot_cleanup_ok": t2.get("ok"),
  "s4_revocation_catalog_ok": s4_ok,
  "s5_credential_references_ok": s5_ok,
  "failed_checks": failed,
  "ok": hard == 0,
  "out": str(out),
}
(out/"SUMMARY.json").write_text(json.dumps(summary, indent=2))
print(json.dumps(summary, indent=2))
sys.exit(0 if hard == 0 else 1)
PY

# P0-EV-04 broad-evidence governance: fail closed when the broad reports
# mislabel mock evidence as live support, miss a required content modality,
# lack deterministic signing-family contract evidence, or exceed the bounded
# cross-sample budget.
echo "[iss] broad-evidence governance (P0-EV-04)"
python3 "${ROOT}/tests/infra/tools/broad-evidence-governance.py" | tee "${OUT}/broad-evidence-governance.log"
gov_rc=$?
gov_report="$(ls -1t "${REPORTS}"/broad-evidence-governance-*.json | head -1)"
cp -a "${gov_report}" "${OUT}/broad-evidence-governance.json"
if [[ ${gov_rc} -ne 0 ]]; then
  echo "[iss] broad-evidence governance FAILED (see ${OUT}/broad-evidence-governance.json)" >&2
  exit "${gov_rc}"
fi
