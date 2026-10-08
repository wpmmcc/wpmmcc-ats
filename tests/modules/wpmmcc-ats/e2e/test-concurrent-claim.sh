#!/usr/bin/env bash
# Concurrent Claim & Lease Lock Verification Lane
# Multi-process concurrent claim test against POST /wptsall/v2/{secret}/client/content/claim
# Verifies that:
#   1. Concurrent worker claims on identical item sets are strictly exclusive (lease lock).
#   2. Exactly one worker claims each item; zero double claims occur.
#   3. Expired leases can be reclaimed after lease cutoff timeout.
set -euo pipefail

_SD="$(cd "$(dirname "$0")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"

WP_URL="${WP_URL:-http://127.0.0.1:9083}"
CONTAINER="${DOCKER_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }

# Check Lab container and HTTP reachability
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
  echo "SKIP: Lab container ${CONTAINER} is not running"
  exit 0
fi

wp_cli() {
  docker exec "${CONTAINER}" wp --allow-root "$@"
}

echo "== Concurrent Claim Lane (POST /client/content/claim) =="

# 1. Resolve Route Secret and Token
echo "Resolving route secret and issuing test client token..."
ROUTE_SECRET="$(wp_cli eval 'echo function_exists("wptsall_get_client_route_secret") ? (string)wptsall_get_client_route_secret() : "";' 2>/dev/null | tr -d '\r' | tail -1)"
[ -n "${ROUTE_SECRET}" ] || fail "Unable to resolve route secret from Lab WP"

DEVICE_ID="concurrent-claim-runner"
TOKEN_OUTPUT="$(wp_cli eval '
  if(function_exists("wptsall_issue_client_device_token")){
    $d = wptsall_issue_client_device_token("'"${DEVICE_ID}"'", "e2e");
    echo (string)$d["token"];
  }
' 2>/dev/null | tr -d '\r' | tail -1)"
[ -n "${TOKEN_OUTPUT}" ] || fail "Unable to issue device token"

# 2. Resolve Active Relation ID
RELATION_ID="$(wp_cli eval '
  global $wpdb;
  $id = $wpdb->get_var("SELECT id FROM " . $wpdb->prefix . "wptsall_site_relations WHERE status = \"active\" LIMIT 1");
  echo (int)$id;
' 2>/dev/null | tr -d '\r' | tail -1)"

if [ -z "${RELATION_ID}" ] || [ "${RELATION_ID}" -le 0 ]; then
  echo "No active relation found, creating a test relation..."
  RELATION_ID="$(wp_cli eval '
    global $wpdb;
    $wpdb->insert($wpdb->prefix . "wptsall_site_relations", [
      "source_site_id" => 1,
      "source_lang" => "en_US",
      "target_site_id" => "v_test_claim",
      "target_site_type" => "virtual",
      "target_lang" => "zh_CN",
      "status" => "active",
      "sync_mode" => "new_only",
      "created_at" => current_time("mysql", true),
      "updated_at" => current_time("mysql", true)
    ]);
    echo (int)$wpdb->insert_id;
  ' 2>/dev/null | tr -d '\r' | tail -1)"
fi
[ "${RELATION_ID}" -gt 0 ] || fail "Failed to obtain active relation ID"
echo "Using active relation_id: ${RELATION_ID}"

CLAIM_URL="${WP_URL}/wp-json/wptsall/v2/${ROUTE_SECRET}/client/content/claim"

# 3. Create Batch of Test Posts
echo "Creating 6 test posts for concurrent claiming..."
POST_IDS=($(wp_cli post generate --count=6 --post_type=post --post_status=publish --format=ids 2>/dev/null | tr -d '\r' | tail -1))
[ "${#POST_IDS[@]}" -eq 6 ] || fail "Failed to generate 6 test posts: ${POST_IDS[*]}"
echo "Created test post IDs: ${POST_IDS[*]}"

cleanup() {
  echo "Cleaning up test posts..."
  if [ "${#POST_IDS[@]}" -gt 0 ]; then
    wp_cli post delete "${POST_IDS[@]}" --force >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# 4. Multi-Process Concurrent Claim Execution
WORKDIR="$(mktemp -d /tmp/wptsall-concurrent-claim.XXXXXX)"
CONCURRENCY=4
echo "Spawning ${CONCURRENCY} parallel worker processes to claim all ${#POST_IDS[@]} posts simultaneously..."

ITEMS_JSON="$(python3 -c "
import json
ids = [int(x) for x in '${POST_IDS[*]}'.split()]
items = [{'object_id': pid, 'post_type': 'post'} for pid in ids]
print(json.dumps(items))
")"

for w in $(seq 1 ${CONCURRENCY}); do
  (
    RESP="$(curl -s -X POST "${CLAIM_URL}" \
      -H "X-WPTSALL-Protocol-Version: 2" \
      -H "X-WPTSALL-Client-Token: ${TOKEN_OUTPUT}" \
      -H "X-WPTSALL-Device-Id: ${DEVICE_ID}" \
      -H "Content-Type: application/json" \
      -d "{\"relation_id\": ${RELATION_ID}, \"data_type\": \"post\", \"items\": ${ITEMS_JSON}}")"
    echo "${RESP}" > "${WORKDIR}/worker_${w}.json"
  ) &
done

wait

# 5. Assert Each Post is Claimed by Exactly One Worker
python3 - <<PY
import json, glob, sys

workdir = "$WORKDIR"
post_ids = set(int(x) for x in "${POST_IDS[*]}".split())
worker_files = sorted(glob.glob(f"{workdir}/worker_*.json"))

claimed_by = {} # post_id -> list of worker_indexes
total_claimed_count = 0

for idx, fpath in enumerate(worker_files, start=1):
    with open(fpath, "r", encoding="utf-8") as fh:
        raw = fh.read().strip()
    try:
        data = json.loads(raw)
    except Exception as e:
        print(f"Worker {idx} produced invalid JSON: {raw}", file=sys.stderr)
        sys.exit(1)

    assert data.get("success") is True, f"Worker {idx} failed: {data}"
    claimed_items = data.get("claimed_items", [])
    total_claimed_count += len(claimed_items)
    print(f"Worker {idx} claimed {len(claimed_items)} items: {[it['object_id'] for it in claimed_items]}")

    for it in claimed_items:
        pid = it["object_id"]
        claimed_by.setdefault(pid, []).append(idx)

# Verify lease exclusivity: each post must be claimed by at most 1 worker
for pid, workers in claimed_by.items():
    assert len(workers) == 1, f"CONFLICT! Post {pid} was double-claimed by workers: {workers}"

# Verify all posts were claimed
missing = post_ids - set(claimed_by.keys())
assert not missing, f"Missing posts were not claimed by any worker: {missing}"

assert total_claimed_count == len(post_ids), (
    f"Total claimed {total_claimed_count} != expected {len(post_ids)}"
)

print(f"Verified: All {len(post_ids)} items claimed exclusively across {len(worker_files)} concurrent workers.")
PY
pass "concurrent claim lease lock exclusivity confirmed"

# 6. Verify Conflict Retry on Active Lease
TEST_PID="${POST_IDS[0]}"
echo "Verifying immediate retry collision on already leased post ${TEST_PID}..."
COLLISION_RESP="$(curl -s -X POST "${CLAIM_URL}" \
  -H "X-WPTSALL-Protocol-Version: 2" \
  -H "X-WPTSALL-Client-Token: ${TOKEN_OUTPUT}" \
  -H "X-WPTSALL-Device-Id: ${DEVICE_ID}" \
  -H "Content-Type: application/json" \
  -d "{\"relation_id\": ${RELATION_ID}, \"data_type\": \"post\", \"items\": [{\"object_id\": ${TEST_PID}, \"post_type\": \"post\"}]}")"

python3 -c "
import json
data = json.loads('''${COLLISION_RESP}''')
assert data.get('success') is True
assert data.get('claimed_count') == 0, f'Expected 0 claimed on active lease collision, got: {data}'
assert len(data.get('claimed_items', [])) == 0
"
pass "active lease collision correctly returns 0 claimed items"

# 7. Verify Lease Expiration and Recovery (Reclaim after Timeout)
echo "Expiring lease for post ${TEST_PID} in mapping table..."
wp_cli eval '
  global $wpdb;
  $mappings_table = $wpdb->prefix . "wptsall_post_mappings";
  $wpdb->query($wpdb->prepare(
    "UPDATE %i SET claimed_at = \"2000-01-01 00:00:00\" WHERE source_post_id = %d AND relation_id = %d",
    $mappings_table, '"${TEST_PID}"', '"${RELATION_ID}"'
  ));
' >/dev/null 2>&1

echo "Retrying claim on post ${TEST_PID} after lease expiration..."
RECLAIM_RESP="$(curl -s -X POST "${CLAIM_URL}" \
  -H "X-WPTSALL-Protocol-Version: 2" \
  -H "X-WPTSALL-Client-Token: ${TOKEN_OUTPUT}" \
  -H "X-WPTSALL-Device-Id: ${DEVICE_ID}" \
  -H "Content-Type: application/json" \
  -d "{\"relation_id\": ${RELATION_ID}, \"data_type\": \"post\", \"items\": [{\"object_id\": ${TEST_PID}, \"post_type\": \"post\"}]}")"

python3 -c "
import json
data = json.loads('''${RECLAIM_RESP}''')
assert data.get('success') is True
assert data.get('claimed_count') == 1, f'Expected 1 claimed on expired lease retry, got: {data}'
assert data.get('claimed_items')[0]['object_id'] == int('${TEST_PID}')
"
pass "lease recovery and expired lease reclaiming succeeded"

REPORT_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${REPORT_DIR}"
cat <<EOF > "${REPORT_DIR}/concurrent-claim-latest.json"
{
  "test_id": "TEST-E2E-CONCURRENT-CLAIM-001",
  "requirement_id": "REQ-WPTSALL-CONCURRENT-CLAIM-001",
  "scenario_id": "SCENARIO-CONCURRENT-CLAIM-EXCLUSIVITY",
  "user_journey_id": "UJ9",
  "status": "passed",
  "posts_count": ${#POST_IDS[@]},
  "workers_count": ${CONCURRENCY},
  "lease_exclusivity": true,
  "collision_protection": true,
  "lease_recovery": true,
  "timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
}
EOF

rm -rf "${WORKDIR}"
echo "✅ [test:concurrent-claim] passed"

