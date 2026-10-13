#!/usr/bin/env bash
# Executable T2 proof: apply namespaced cleanup and verify resource reaping.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLOT="slot-n"
RUN_ID="t2-cleanup-$(date +%Y%m%d-%H%M%S)-$$"
SLOT_DIR="${E2E_DIR}/runtime/${SLOT}"
DB="${SLOT_DIR}/client-db/wptsall.db"
LOG="${SLOT_DIR}/wptsall-client.log"
SESSION="${SLOT_DIR}/session-token.enc"
mkdir -p "${SLOT_DIR}/client-db"

E2E_SLOT="${SLOT}" E2E_PROJECT=t2-cleanup E2E_SCOPE=core-only E2E_RUN_ID="${RUN_ID}" \
  bash "${SCRIPT_DIR}/write-run-manifest.sh" pass >/dev/null
printf 't2-cleanup-fixture\n' >"${DB}"
printf 't2-cleanup-log\n' >"${LOG}"
printf 't2-cleanup-session\n' >"${SESSION}"
# The cleanup matcher uses exact slot DB + WebUI port environment values. A
# harmless sleep gives the proof a real process to reap without starting a WP
# client or touching any shared service.
WPTSALL_DB_PATH="${DB}" WPTSALL_WEB_UI_PORT=9095 WPTSALL_WEB_UI=true sleep 30 &
SLEEP_PID=$!
printf '%s\n' "${SLEEP_PID}" >"${SLOT_DIR}/client.pid"

set +e
E2E_SLOT="${SLOT}" E2E_RUN_ID="${RUN_ID}" \
  bash "${SCRIPT_DIR}/cleanup-slot-dry-run.sh" "${SLOT}" --apply --outcome pass --run-id "${RUN_ID}"
RC=$?
set -e

python3 - "${E2E_DIR}/runtime/${SLOT}/cleanup.json" "${E2E_DIR}/runtime/t2-slot-cleanup.json" \
  "${E2E_DIR}/runtime/${SLOT}/run-manifest.json" "${DB}" "${LOG}" "${SESSION}" "${SLEEP_PID}" "${RC}" <<'PY'
import json, os, signal, sys
cleanup_path, out_path, manifest_path, db, log, session, pid, rc = sys.argv[1:]
checks = []
try:
    report = json.load(open(cleanup_path))
except Exception as exc:
    report = {}
    checks.append({"id": "T2_report_readable", "ok": False, "msg": str(exc), "hard": True})

checks.append({"id": "T2_apply", "ok": report.get("apply") is True, "msg": str(report.get("apply")), "hard": True})
checks.append({"id": "T2_resource_reaped", "ok": report.get("resource_reaped") is True and int(rc) == 0,
               "msg": json.dumps(report.get("processes", [])), "hard": True})
checks.append({"id": "T2_volatile_files_deleted",
               "ok": not any(os.path.exists(p) for p in (db, log, session, os.path.join(os.path.dirname(db), "wptsall.db-shm"), os.path.join(os.path.dirname(db), "wptsall.db-wal"))),
               "msg": "slot client DB/log/session absent", "hard": True})
checks.append({"id": "T2_manifest_preserved", "ok": os.path.isfile(manifest_path),
               "msg": manifest_path, "hard": True})
try:
    manifest = json.load(open(manifest_path))
    cleanup = manifest.get("cleanup", {})
    checks.append({"id": "T2_manifest_cleanup_state", "ok": cleanup.get("state") == "applied" and cleanup.get("resource_reaped") is True,
                   "msg": json.dumps(cleanup), "hard": True})
except Exception as exc:
    checks.append({"id": "T2_manifest_cleanup_state", "ok": False, "msg": str(exc), "hard": True})

try:
    os.kill(int(pid), 0)
    alive = True
except OSError:
    alive = False
checks.append({"id": "T2_process_gone", "ok": not alive, "msg": f"pid={pid} alive={alive}", "hard": True})

hard_fail = sum(1 for check in checks if check["hard"] and not check["ok"])
payload = {"generated": __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat(),
           "slot": "slot-n", "run_id": report.get("run_id"), "checks": checks,
           "hard_fail": hard_fail, "pass": sum(1 for check in checks if check["ok"]), "ok": hard_fail == 0}
with open(out_path, "w") as fh:
    json.dump(payload, fh, indent=2)
print(json.dumps(payload, indent=2))
raise SystemExit(0 if payload["ok"] else 1)
PY
