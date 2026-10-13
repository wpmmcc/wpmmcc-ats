#!/usr/bin/env bash
# ISS T1 acceptance: prove two matrix slots have independent WP runtimes.
# This only writes temporary rows to the dedicated slot databases and never
# resets or mutates the shared wordpress-test service.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../../../../.." && pwd)"
E2E_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e"
SLOTS=(slot-a slot-b)

slot_container() { echo "wptsall-wp-lab-wordpress-$1"; }
slot_port() {
  case "$1" in
    slot-a) echo 9181;; slot-b) echo 9182;; slot-c) echo 9183;; slot-d) echo 9184;;
    slot-e) echo 9185;; slot-f) echo 9186;; slot-g) echo 9187;; slot-h) echo 9188;;
    slot-i) echo 9189;; slot-j) echo 9190;; slot-k) echo 9191;; slot-l) echo 9192;;
    slot-m) echo 9193;; slot-n) echo 9194;; *) return 1;;
  esac
}
slot_wp() {
  local slot="$1"; shift
  docker exec "$(slot_container "${slot}")" wp --allow-root --path=/var/www/html "$@"
}
slot_db_name() {
  local slot="$1"
  echo "wplab_${slot//-/_}"
}

for slot in "${SLOTS[@]}"; do
  E2E_SLOT_WP_COPY_PLUGINS=0 E2E_SLOT="${slot}" \
    bash "${SCRIPT_DIR}/ensure-slot-wordpress.sh" "${slot}" >/dev/null
done

hard_fail=0
checks=()
record() {
  local id="$1" ok="$2" msg="$3"
  checks+=("${id}|${ok}|${msg}")
  if [[ "${ok}" != "true" ]]; then hard_fail=$((hard_fail + 1)); fi
}

container_a="$(slot_container slot-a)"
container_b="$(slot_container slot-b)"
id_a="$(docker inspect -f '{{.Id}}' "${container_a}")"
id_b="$(docker inspect -f '{{.Id}}' "${container_b}")"
record T1_distinct_containers "$([[ "${id_a}" != "${id_b}" ]] && echo true || echo false)" "a=${id_a:0:12} b=${id_b:0:12}"

db_a="$(slot_wp slot-a eval 'global $wpdb; echo $wpdb->dbname;' 2>/dev/null | tail -1 | tr -d '[:space:]')"
db_b="$(slot_wp slot-b eval 'global $wpdb; echo $wpdb->dbname;' 2>/dev/null | tail -1 | tr -d '[:space:]')"
record T1_slot_a_database "$([[ "${db_a}" == "$(slot_db_name slot-a)" ]] && echo true || echo false)" "database=${db_a}"
record T1_slot_b_database "$([[ "${db_b}" == "$(slot_db_name slot-b)" ]] && echo true || echo false)" "database=${db_b}"
record T1_distinct_databases "$([[ "${db_a}" != "${db_b}" ]] && echo true || echo false)" "a=${db_a} b=${db_b}"

marker_a="iss-t1-slot-a-$(date +%s%N)"
marker_b="iss-t1-slot-b-$(date +%s%N)"
post_a="$(slot_wp slot-a post create --post_title="${marker_a}" --post_status=draft --porcelain | tail -1 | tr -d '[:space:]')"
post_b="$(slot_wp slot-b post create --post_title="${marker_b}" --post_status=draft --porcelain | tail -1 | tr -d '[:space:]')"
count_a_own="$(slot_wp slot-a post list --post_type=post --post_status=any --title="${marker_a}" --format=count | tail -1 | tr -d '[:space:]')"
count_a_other="$(slot_wp slot-a post list --post_type=post --post_status=any --title="${marker_b}" --format=count | tail -1 | tr -d '[:space:]')"
count_b_own="$(slot_wp slot-b post list --post_type=post --post_status=any --title="${marker_b}" --format=count | tail -1 | tr -d '[:space:]')"
count_b_other="$(slot_wp slot-b post list --post_type=post --post_status=any --title="${marker_a}" --format=count | tail -1 | tr -d '[:space:]')"
record T1_slot_a_own_row "$([[ "${count_a_own}" == 1 ]] && echo true || echo false)" "own_rows=${count_a_own}"
record T1_slot_b_own_row "$([[ "${count_b_own}" == 1 ]] && echo true || echo false)" "own_rows=${count_b_own}"
record T1_no_cross_row_a "$([[ "${count_a_other}" == 0 ]] && echo true || echo false)" "slot-a sees slot-b rows=${count_a_other}"
record T1_no_cross_row_b "$([[ "${count_b_other}" == 0 ]] && echo true || echo false)" "slot-b sees slot-a rows=${count_b_other}"

for pair in "slot-a:${post_a}" "slot-b:${post_b}"; do
  slot="${pair%%:*}"; post="${pair#*:}"
  [[ "${post}" =~ ^[0-9]+$ ]] && slot_wp "${slot}" post delete "${post}" --force >/dev/null || true
done

for slot in "${SLOTS[@]}"; do
  status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$(slot_port "${slot}")/wp-json/" || true)"
  record "T1_${slot}_http" "$([[ "${status}" == 200 ]] && echo true || echo false)" "status=${status}"
done

python3 - "${E2E_DIR}/runtime/t1-slot-isolation.json" "${hard_fail}" "${checks[@]}" <<'PY'
import json, sys
from datetime import datetime, timezone
out = sys.argv[1]
hard = int(sys.argv[2])
checks = []
for item in sys.argv[3:]:
    ident, ok, msg = item.split('|', 2)
    checks.append({'id': ident, 'ok': ok == 'true', 'msg': msg, 'hard': True})
payload = {'generated': datetime.now(timezone.utc).isoformat(), 'checks': checks,
           'hard_fail': hard, 'pass': sum(1 for c in checks if c['ok']), 'ok': hard == 0}
with open(out, 'w', encoding='utf-8') as f:
    json.dump(payload, f, indent=2)
print(json.dumps(payload, indent=2))
sys.exit(0 if hard == 0 else 1)
PY
