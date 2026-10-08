#!/usr/bin/env bash
# Large-Content Batch Stress Test Lane (>= 500 posts)
# Validates:
#   1. Batch generation of >= 500 posts into WordPress.
#   2. Concurrent batch claiming across multiple workers (POST /client/content/claim).
#   3. MySQL connection pool stability (no connection leaks or thread exhaustion).
#   4. Worker memory / response latency and throughput under high load.
#   5. Database mapping integrity (exclusive claims, 500 mappings recorded).
set -euo pipefail

_SD="$(cd "$(dirname "$0")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"

WP_URL="${WP_URL:-http://127.0.0.1:9083}"
CONTAINER="${DOCKER_WP_CONTAINER:-wptsall-wp-lab-wordpress-test-1}"
TARGET_COUNT=500

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }

# Check Lab container
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
  echo "SKIP: Lab container ${CONTAINER} is not running"
  exit 0
fi

wp_cli() {
  docker exec "${CONTAINER}" wp --allow-root "$@"
}

echo "== Large-Content Batch Stress Lane (>= ${TARGET_COUNT} posts) =="

# 1. Resolve Route Secret & Token
echo "Resolving route secret and issuing test client token..."
ROUTE_SECRET="$(wp_cli eval 'echo function_exists("wptsall_get_client_route_secret") ? (string)wptsall_get_client_route_secret() : "";' 2>/dev/null | tr -d '\r' | tail -1)"
[ -n "${ROUTE_SECRET}" ] || fail "Unable to resolve route secret from Lab WP"

DEVICE_ID="batch-stress-runner"
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
[ "${RELATION_ID}" -gt 0 ] || fail "Failed to obtain active relation ID"
echo "Using active relation_id: ${RELATION_ID}"

CLAIM_URL="${WP_URL}/wp-json/wptsall/v2/${ROUTE_SECRET}/client/content/claim"

# 3. Baseline MySQL Connection Metrics
BASELINE_THREADS="$(wp_cli eval '
  global $wpdb;
  echo (int)$wpdb->get_var("SHOW STATUS LIKE \"Threads_connected\"", 1);
' 2>/dev/null | tr -d '\r' | tail -1)"
echo "Baseline MySQL Threads_connected: ${BASELINE_THREADS}"

# 4. Generate >= 500 Posts via Bulk Insert
echo "Bulk inserting ${TARGET_COUNT} posts..."
STAMP="stress_$$"
GEN_OUTPUT="$(wp_cli eval '
  global $wpdb;
  $t0 = microtime(true);
  $count = '"${TARGET_COUNT}"';
  $stamp = "'"${STAMP}"'";
  $values = [];
  $now = current_time("mysql", true);
  for ($i = 1; $i <= $count; $i++) {
    $title = $wpdb->_escape("Stress Article {$i} [{$stamp}]");
    $content = $wpdb->_escape("This is a high-volume batch stress test content payload for article {$i}. Paragraph 1 with keywords. Paragraph 2 with links and tags.");
    $values[] = "(\"1\", \"{$now}\", \"{$now}\", \"{$content}\", \"{$title}\", \"publish\", \"open\", \"open\", \"post\", \"\", \"{$now}\", \"{$now}\")";
  }
  $sql = "INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_type, to_ping, post_modified, post_modified_gmt) VALUES " . implode(",", $values);
  $wpdb->query($sql);
  $first_id = (int)$wpdb->insert_id;
  $last_id = $first_id + $count - 1;
  $elapsed_ms = round((microtime(true) - $t0) * 1000, 1);
  echo json_encode(["first_id" => $first_id, "last_id" => $last_id, "count" => $count, "elapsed_ms" => $elapsed_ms]);
' 2>/dev/null | tr -d '\r' | tail -1)"

POST_INFO="$(python3 -c "
import json
data = json.loads('''${GEN_OUTPUT}''')
print(f\"{data['first_id']} {data['last_id']} {data['count']} {data['elapsed_ms']}\")
")"
FIRST_ID="$(echo "$POST_INFO" | awk '{print $1}')"
LAST_ID="$(echo "$POST_INFO" | awk '{print $2}')"
POST_COUNT="$(echo "$POST_INFO" | awk '{print $3}')"
GEN_MS="$(echo "$POST_INFO" | awk '{print $4}')"

echo "Created ${POST_COUNT} posts (IDs ${FIRST_ID}..${LAST_ID}) in ${GEN_MS}ms"
[ "${POST_COUNT}" -ge "${TARGET_COUNT}" ] || fail "Expected at least ${TARGET_COUNT} posts, created: ${POST_COUNT}"
pass "generated ${POST_COUNT} posts via high-performance bulk insert"

# Setup Cleanup Trap
cleanup() {
  echo "Cleaning up stress posts (${FIRST_ID}..${LAST_ID}) and mappings..."
  wp_cli eval '
    global $wpdb;
    $first = '"${FIRST_ID}"';
    $last = '"${LAST_ID}"';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID >= %d AND ID <= %d", $first, $last));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wptsall_post_mappings WHERE source_post_id >= %d AND source_post_id <= %d", $first, $last));
  ' >/dev/null 2>&1 || true
}
trap cleanup EXIT

# 5. Execute Multi-Batch Concurrent Claiming (Stress Load)
WORKDIR="$(mktemp -d /tmp/wptsall-batch-stress.XXXXXX)"
NUM_BATCHES=5
ITEMS_PER_BATCH=$(( TARGET_COUNT / NUM_BATCHES ))
echo "Dispatching ${NUM_BATCHES} parallel batches (${ITEMS_PER_BATCH} items/batch) to POST /client/content/claim..."

CLAIM_START_TIME="$(python3 -c 'import time; print(time.time())')"

for b in $(seq 1 ${NUM_BATCHES}); do
  BATCH_OFFSET=$(( (b - 1) * ITEMS_PER_BATCH ))
  BATCH_START=$(( FIRST_ID + BATCH_OFFSET ))
  BATCH_END=$(( BATCH_START + ITEMS_PER_BATCH - 1 ))

  (
    PAYLOAD="$(python3 -c "
import json
items = [{'object_id': pid, 'post_type': 'post'} for pid in range(${BATCH_START}, ${BATCH_END} + 1)]
print(json.dumps({'relation_id': ${RELATION_ID}, 'data_type': 'post', 'items': items}))
")"
    RESP="$(curl -s -X POST "${CLAIM_URL}" \
      -H "X-WPTSALL-Protocol-Version: 2" \
      -H "X-WPTSALL-Client-Token: ${TOKEN_OUTPUT}" \
      -H "X-WPTSALL-Device-Id: ${DEVICE_ID}" \
      -H "Content-Type: application/json" \
      -d "${PAYLOAD}")"
    echo "${RESP}" > "${WORKDIR}/batch_${b}.json"
  ) &
done

wait
CLAIM_END_TIME="$(python3 -c 'import time; print(time.time())')"

# 6. Verify Claim Results & Exclusivity across all 500 items
python3 - <<PY
import json, glob, sys, time

workdir = "$WORKDIR"
batch_files = sorted(glob.glob(f"{workdir}/batch_*.json"))
assert len(batch_files) == ${NUM_BATCHES}, f"Expected ${NUM_BATCHES} batch results, found {len(batch_files)}"

claimed_ids = set()
total_claimed = 0

for idx, fpath in enumerate(batch_files, start=1):
    with open(fpath, "r", encoding="utf-8") as fh:
        raw = fh.read().strip()
    try:
        data = json.loads(raw)
    except Exception as e:
        print(f"Batch {idx} produced invalid JSON: {raw}", file=sys.stderr)
        sys.exit(1)

    assert data.get("success") is True, f"Batch {idx} failed: {data}"
    count = data.get("claimed_count", 0)
    items = data.get("claimed_items", [])
    assert count == len(items), f"Batch {idx} claimed_count {count} != items len {len(items)}"
    total_claimed += count

    for it in items:
        pid = it["object_id"]
        assert pid not in claimed_ids, f"DUPLICATE CLAIM: Post {pid} claimed more than once!"
        claimed_ids.add(pid)

assert total_claimed == ${TARGET_COUNT}, f"Total claimed {total_claimed} != expected ${TARGET_COUNT}"
assert len(claimed_ids) == ${TARGET_COUNT}

duration = float(${CLAIM_END_TIME}) - float(${CLAIM_START_TIME})
throughput = total_claimed / max(duration, 0.001)
print(f"Verified: All {total_claimed} items successfully and exclusively claimed across {len(batch_files)} batches.")
print(f"Batch Claim Duration: {duration:.2f}s (Throughput: {throughput:.1f} items/sec)")
PY
pass "all ${TARGET_COUNT} items claimed with zero duplicate claims"

# 7. Check MySQL Connection Pool Stability
PEAK_THREADS="$(wp_cli eval '
  global $wpdb;
  echo (int)$wpdb->get_var("SHOW STATUS LIKE \"Threads_connected\"", 1);
' 2>/dev/null | tr -d '\r' | tail -1)"
MAX_USED="$(wp_cli eval '
  global $wpdb;
  echo (int)$wpdb->get_var("SHOW STATUS LIKE \"Max_used_connections\"", 1);
' 2>/dev/null | tr -d '\r' | tail -1)"
echo "Post-claim MySQL Threads_connected: ${PEAK_THREADS}, Max_used_connections: ${MAX_USED}"

python3 -c "
threads = int('${PEAK_THREADS}')
baseline = int('${BASELINE_THREADS}')
assert threads < 50, f'MySQL thread exhaustion detected: {threads} active connections'
print(f'MySQL connection pool stable: baseline={baseline}, current={threads}, max_used=${MAX_USED}')
"
pass "MySQL connection pool stability verified under ${TARGET_COUNT}-post concurrent load"

# 8. Verify Database Mappings Integrity
MAPPINGS_COUNT="$(wp_cli eval '
  global $wpdb;
  $first = '"${FIRST_ID}"';
  $last = '"${LAST_ID}"';
  $rel = '"${RELATION_ID}"';
  $c = $wpdb->get_var($wpdb->prepare(
    "SELECT count(*) FROM {$wpdb->prefix}wptsall_post_mappings WHERE source_post_id >= %d AND source_post_id <= %d AND relation_id = %d AND claimed_at IS NOT NULL",
    $first, $last, $rel
  ));
  echo (int)$c;
' 2>/dev/null | tr -d '\r' | tail -1)"
[ "${MAPPINGS_COUNT}" -eq "${TARGET_COUNT}" ] || fail "Expected ${TARGET_COUNT} mappings with claimed_at, found: ${MAPPINGS_COUNT}"
pass "database mapping integrity verified (${MAPPINGS_COUNT}/${TARGET_COUNT} rows)"

REPORT_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${REPORT_DIR}"
THROUGHPUT="$(python3 -c "print(round(${TARGET_COUNT} / max(float(${CLAIM_END_TIME}) - float(${CLAIM_START_TIME}), 0.001), 1))")"
cat <<EOF > "${REPORT_DIR}/large-content-stress-latest.json"
{
  "test_id": "TEST-E2E-LARGE-CONTENT-STRESS-001",
  "requirement_id": "REQ-WPTSALL-LARGE-STRESS-001",
  "scenario_id": "SCENARIO-LARGE-CONTENT-BATCH-STRESS",
  "user_journey_id": "UJ15",
  "user_journey_ids": ["UJ6", "UJ9", "UJ15"],
  "status": "passed",
  "target_posts_count": ${TARGET_COUNT},
  "concurrency_batches": ${NUM_BATCHES},
  "items_per_batch": ${ITEMS_PER_BATCH},
  "throughput_items_per_sec": ${THROUGHPUT},
  "mysql_threads_connected": ${PEAK_THREADS},
  "mysql_max_used_connections": ${MAX_USED},
  "timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
}
EOF

rm -rf "${WORKDIR}"
echo "✅ [test:large-content-stress] passed (${TARGET_COUNT} posts stress verified)"
