#!/usr/bin/env bash
# Feature-utilization audit — which client features (log-event surfaces)
# are actually exercised by test assets, and which are dark.
#
# Method (all static, all greppable, no inference beyond stated rules):
#   1. EVENT CATALOG — every log_event/log_event_global call site in the
#      client Rust source: (file, level, event).
#   2. ROUTE FAMILIES — each web_ui/routes/*.rs module's own URL literals
#      (e.g. review.rs handles "/api/items") define its URL family.
#   3. TOUCH EVIDENCE:
#      a. journey-asserted — the event name literally appears in a SIM
#         simulation spec (the log oracle asserts it end to end).
#      b. handler-tested — the route module's URL family appears in the
#         Rust route/endpoint tests or in spec-driven URL strings.
#      c. subsystem-covered — engine subsystem (sync_engine / task_engine /
#         component_rt / worker) whose owning journey family exists.
#   4. DARK LISTS (the actionable output):
#      - zero-touch — route-module events with no handler test and no
#        journey assertion (the handler path never runs under any test).
#      - zero-negative — failure-branch (warn/error) events that no
#        journey asserts (untested failure paths; where bugs hide).
#
# Output: tests/reports/e2e/wpmmcc-ats/feature-utilization-<ts>.json
#         + console summary. Exit 0 always (audit, not a gate).
#
# Usage: bash tests/modules/wpmmcc-ats/e2e/audit-feature-utilization.sh
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"

CLIENT_SRC="${ROOT_DIR}/client-wpplugin/source"
SIM_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/playwright/simulation"
ROUTE_TESTS="${CLIENT_SRC}/src/web_ui/routes/tests"
REPORTS_DIR="${ROOT_DIR}/tests/reports/e2e/wpmmcc-ats"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_JSON="${REPORTS_DIR}/feature-utilization-${STAMP}.json"
mkdir -p "${REPORTS_DIR}"

python3 - "$CLIENT_SRC" "$SIM_DIR" "$ROUTE_TESTS" "$OUT_JSON" <<'PY'
import glob, json, os, re, sys, collections
from datetime import datetime, timezone

client_src, sim_dir, route_tests_dir, out_json = sys.argv[1:5]

# ---------- 1. Event catalog ----------
pat_global = re.compile(r'log_event_global\(\s*"(\w+)",\s*"([\w.\-:]+)"')
pat_plain = re.compile(r'log_event\(\s*[A-Za-z_][\w.\(\)]*,\s*"(\w+)",\s*"([\w.\-:]+)"')
seen, events = set(), []
for f in glob.glob(os.path.join(client_src, 'src/**/*.rs'), recursive=True):
    rel = os.path.relpath(f, client_src)
    src = open(f, encoding='utf-8').read()
    for pat in (pat_global, pat_plain):
        for m in pat.finditer(src):
            k = (rel, m.group(1), m.group(2))
            if k not in seen:
                seen.add(k)
                events.append({'file': rel, 'level': m.group(1), 'event': m.group(2)})

# ---------- 2. Route families: URL literals inside each route module ----------
# Handler modules often carry no URL literals (the dispatcher in routes.rs
# owns them), so: (a) literals inside the module itself, plus (b) families
# derived from the routes.rs dispatch — every handler call site there is
# paired with the nearest /api/... literal in the same dispatch arm.
route_files = glob.glob(os.path.join(client_src, 'src/web_ui/routes/**/*.rs'), recursive=True)
routes_rs = os.path.join(client_src, 'src/web_ui/routes.rs')
dispatch_src = open(routes_rs, encoding='utf-8').read()
route_families = {}  # module rel path -> set of url prefixes

def handler_module(fn):
    for f in route_files:
        if re.search(rf'async fn {fn}\(', open(f, encoding='utf-8').read()):
            return os.path.relpath(f, client_src)
    return None

for f in route_files:
    rel = os.path.relpath(f, client_src)
    fams = set()
    for m in re.finditer(r'"(/api/[a-z0-9_\-]+)', open(f, encoding='utf-8').read()):
        fams.add(m.group(1))
    if fams:
        route_families.setdefault(rel, set()).update(fams)

# Dispatch arms: a /api/... literal followed (within the same arm) by a
# handle_<fn>( call. Split the dispatcher on '=>' boundaries is fragile;
# use a sliding window: for each handle_ call, take /api/ literals in the
# preceding 400 chars.
for m in re.finditer(r'\b(handle_[a-z0-9_]+)\(', dispatch_src):
    fn, start = m.group(1), m.start()
    window = dispatch_src[max(0, start - 400):start]
    for u in re.finditer(r'"(/api/[a-z0-9_\-]+)', window):
        mod = handler_module(fn)
        if mod:
            route_families.setdefault(mod, set()).add(u.group(1))

# ---------- 3a. SIM spec evidence ----------
spec_files = sorted(glob.glob(os.path.join(sim_dir, '*.spec.ts')))
spec_events, spec_urls = set(), set()
for f in spec_files:
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r"['\"`](/api/[a-z0-9_\-/]+)", src):
        spec_urls.add(m.group(1))
for e in events:
    for f in spec_files:
        if f"'{e['event']}'" in open(f, encoding='utf-8').read():
            spec_events.add(e['event'])
            break

# ---------- 3b. Rust route-test evidence ----------
test_urls = set()
for f in glob.glob(os.path.join(route_tests_dir, '**/*.rs'), recursive=True) + \
          glob.glob(os.path.join(client_src, 'src/web_ui/routes/tests.rs')):
    for m in re.finditer(r'"(/api/[a-z0-9_\-/]+)', open(f, encoding='utf-8').read()):
        test_urls.add(m.group(1))
all_test_urls = spec_urls | test_urls

def family_hit(fams):
    for fam in fams:
        for u in all_test_urls:
            if u == fam or u.startswith(fam + '/'):
                return True
    return False

# ---------- 3c. Subsystem evidence (engine side) ----------
def spec_sources():
    return {f: open(f, encoding='utf-8').read() for f in spec_files}
SRC = spec_sources()
SUBSYSTEMS = {
    'src/sync_engine/': any('sync-pair' in s or 'Sync Now' in s for s in SRC.values()),
    'src/task_engine/': any('run-once' in s or 'runWorkerOnce' in s for s in SRC.values()),
    'src/component_rt/': any('createLocalComponent' in s for s in SRC.values()),
    'src/worker.rs': any('run-once' in s or 'runWorkerOnce' in s for s in SRC.values()),
}

def classify(e):
    f = e['file']
    if f.startswith('src/web_ui/routes'):
        fams = route_families.get(f, set())
        if not fams:
            return 'route-no-url-literal'  # dispatch-only / helpers
        return 'handler-tested' if family_hit(fams) else 'zero-touch'
    for prefix, covered in SUBSYSTEMS.items():
        if f.startswith(prefix) or f == prefix.rstrip('/'):
            return 'subsystem-covered' if covered else 'zero-touch'
    return 'uncategorized-static'

rows = []
for e in sorted(events, key=lambda x: (x['event'], x['file'])):
    cls = classify(e)
    asserted = e['event'] in spec_events
    if asserted:
        cls = 'journey-asserted'
    rows.append({
        'event': e['event'], 'level': e['level'], 'file': e['file'],
        'class': cls,
        'failure_branch': e['level'] in ('warn', 'warning', 'error'),
        'zero_negative': e['level'] in ('warn', 'warning', 'error') and not asserted,
    })

by_class = collections.Counter(r['class'] for r in rows)
zero_touch = sorted({(r['event'], r['file']) for r in rows if r['class'] == 'zero-touch'})
zero_negative = sorted({r['event'] for r in rows if r['zero_negative']})

report = {
    'generated_at_utc': datetime.now(timezone.utc).isoformat(),
    'method': {
        'event_catalog': 'log_event/log_event_global call sites (static)',
        'journey_asserted': 'event name literally asserted in a simulation spec (log oracle)',
        'handler_tested': "route module's URL family appears in Rust route/endpoint tests or spec URLs",
        'subsystem_covered': 'engine subsystem with an owning journey family (weaker: code runs, event may never fire)',
        'zero_touch': 'route module events whose handler path no test exercises',
        'zero_negative': 'failure-branch event no journey asserts (untested failure paths)',
    },
    'totals': {'events': len(rows), 'route_modules': len(route_families), 'sim_specs': len(spec_files)},
    'by_class': dict(by_class),
    'zero_touch': [{'event': ev, 'file': f} for ev, f in zero_touch],
    'zero_negative_failure_events': zero_negative,
    'events': rows,
}
with open(out_json, 'w', encoding='utf-8') as fh:
    json.dump(report, fh, indent=2, ensure_ascii=False)

print(f"feature-utilization audit → {out_json}")
print(f"events: {len(rows)}  |  classes: {dict(by_class)}")
print(f"\nzero-touch ({len(zero_touch)}):")
for ev, f in zero_touch: print(f"  - {ev}  [{f}]")
print(f"\nzero-negative failure branches ({len(zero_negative)}):")
for ev in zero_negative: print(f"  - {ev}")
PY
