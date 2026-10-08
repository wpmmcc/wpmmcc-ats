/**
 * 34 — WPMMCC conflict review surface (B3): shadow drafts become reviewable.
 *
 * Coverage gap (3.8flash B3 / VERIFIED-REPAIR-PLAN-20260925):
 * the manual_review strategy materializes conflicted inbound packets as
 * shadow drafts ([Sync Conflict Review] title, draft status, three postmeta
 * markers), but that is where the lifecycle STALLED — no surface ever listed
 * them (findable only by direct postmeta query), no diff was rendered, and
 * no resolution path existed: a shadow draft sat forever as an orphan draft
 * while the canonical mapping pointed at it (the displaced original kept
 * drifting as an unmapped post).
 *
 * The repair (class-wpmmcc-admin.php B3 handlers + Conflict Review card +
 * one rest-push meta line) closes the review loop:
 *   - rest-push records _wpmmcc_shadow_local_id (the displaced local
 *     counterpart) at shadow time — enables both the diff and Keep Local
 *   - Conflict Review Queue card: the meta IS the queue (posts carrying
 *     _wpmmcc_shadow_conflict=1), each row an expandable details block with
 *     a native wp_text_diff (local left, remote right) + Accept Remote /
 *     Keep Local nonce'd admin_post forms
 *   - Accept Remote: strip the review prefix, adopt the counterpart's
 *     publish state, clear the shadow metas, journal the decision
 *   - Keep Local: re-point the canonical mapping onto the displaced
 *     original (keeping checksum/clock so the peer's next digest of the
 *     SAME revision verdicts in_sync instead of re-offering it), stamp
 *     last_synced_at now, discard the staging shadow — all inside
 *     run_in_ingestion_scope so the shadow deletion cannot echo an
 *     outbound delete CDC row a peer would tombstone against
 *
 * Why a seeded fixture: a genuine manual_review conflict needs a live
 * inbound packet from a second wpmmcc site with local drift — not reachable
 * in this lab topology (see spec 31). The seed writes exactly the state
 * handle_push's shadow path leaves behind (shadow post + three metas +
 * original post + canonical mapping row + origin peer row), then the REAL
 * review surface runs end-to-end against it (query, diff render, nonce,
 * capability, admin_post, redirect, notice, DB closure).
 *
 * Journey (against the WPMMCC lab site, container wptsall-wp-lab-wordpress-wpmmcc-1):
 *   1. Seeded pairs render in the Conflict Review Queue card (peer name,
 *      truncated canonical with full-uuid title, native diff table, both
 *      action forms with nonce + post_id; Keep Local carries a confirm)
 *   2. Accept Remote → redirect conflict_accepted=1 + notice + DB: shadow
 *      promoted (counterpart status, prefix stripped, shadow metas cleared,
 *      canonical meta KEPT), journal conflict_accept_remote consumed=1,
 *      displaced original untouched
 *   3. Keep Local → confirm dialog → redirect conflict_kept=1 + notice +
 *      DB: mapping re-pointed onto the original (outbound direction,
 *      last_synced_at advanced, checksum/clock KEPT), shadow post gone,
 *      original content intact, journal conflict_keep_local consumed=1,
 *      and NO outbound CDC echo rows appeared for the deletion
 *   4. Fail-closed: tampered nonce POST → 403, no journal row written
 *
 * Site routing note (same as spec 31/33): the lab container's WP_HOME is
 * the provisioning host http://192.168.1.12:9082, published on 127.0.0.1:9082;
 * the spec browses the canonical SITE origin through a transparent proxy.
 *   WPMMCC_LAB_BASE / WPMMCC_LAB_BACKEND / WPMMCC_LAB_ADMIN_USER / PASS
 *   WPMMCC_LAB_WP_CONTAINER (default wptsall-wp-lab-wordpress-wpmmcc-1)
 *
 * Run:
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 34-wpmmcc-conflict-review
 *
 * catalog: WP-CLASS-WPMMCC\Admin\Admin
 * oracle: L1
 */
import { test, expect, Browser, BrowserContext } from '@playwright/test'
import * as http from 'http'
import { AddressInfo } from 'net'
import { execFileSync } from 'child_process'
import { findFatalError } from './helpers'

const SITE = (process.env.WPMMCC_LAB_BASE ?? 'http://192.168.1.12:9082').replace(/\/+$/, '')
const BACKEND = (process.env.WPMMCC_LAB_BACKEND ?? 'http://127.0.0.1:9082').replace(/\/+$/, '')
const ADMIN_USER = process.env.WPMMCC_LAB_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.WPMMCC_LAB_ADMIN_PASS ?? 'admin123456'
const WPMCC_WP_CONTAINER = process.env.WPMMCC_LAB_WP_CONTAINER ?? 'wptsall-wp-lab-wordpress-wpmmcc-1'

// Deterministic fixture identity (hex-only, UUID-shaped; distinct from 31/33).
const PEER_UUID = 'e2e0c001-0000-4000-8000-000000000001'
const PEER_NAME = 'E2E Conflict Review Peer (B3)'
// Pair A exercises Accept Remote; pair B exercises Keep Local; pair C is
// left untouched for the tampered-nonce fail-closed test (serial order: the
// first two pairs are consumed by their own tests).
const CANON_A = 'e2e0c001-0000-4000-8000-0000000000c1'
const CANON_B = 'e2e0c001-0000-4000-8000-0000000000c2'
const CANON_C = 'e2e0c001-0000-4000-8000-0000000000c3'
const SHADOW_A_TITLE = '[Sync Conflict Review] Remote Alpha'
const SHADOW_B_TITLE = '[Sync Conflict Review] Remote Beta'
const SHADOW_C_TITLE = '[Sync Conflict Review] Remote Gamma'
// Promoted titles (prefix stripped by Accept Remote): the stale-wipe must
// cover BOTH forms, or every run leaves one promoted post behind and the
// canonical-meta counts accumulate run over run.
const PROMOTED_A_TITLE = 'Remote Alpha'
const PROMOTED_B_TITLE = 'Remote Beta'
const PROMOTED_C_TITLE = 'Remote Gamma'
const LOCAL_A_CONTENT = 'LOCAL ALPHA BODY 3.8flash B3'
const LOCAL_B_CONTENT = 'LOCAL BETA BODY 3.8flash B3'
const LOCAL_C_CONTENT = 'LOCAL GAMMA BODY 3.8flash B3'
const REMOTE_A_CONTENT = 'REMOTE ALPHA BODY pushed by peer'
const REMOTE_B_CONTENT = 'REMOTE BETA BODY pushed by peer'
const REMOTE_C_CONTENT = 'REMOTE GAMMA BODY pushed by peer'

const siteUrl = new URL(SITE)
const backendUrl = new URL(BACKEND)
const NEED_PROXY = siteUrl.host !== backendUrl.host

let proxy: http.Server | null = null
let proxyPort = 0

/** Run a wp eval snippet inside the WPMMCC lab container (no shell layer). */
function wpEval(php: string): string {
  try {
    return execFileSync(
      'docker',
      [
        'exec',
        '-e',
        'PAGER=cat',
        WPMCC_WP_CONTAINER,
        'wp',
        'eval',
        php,
        '--allow-root',
        '--path=/var/www/html',
      ],
      { encoding: 'utf-8' },
    )
  } catch (e: any) {
    return String((e.stdout ?? '') + (e.stderr ?? ''))
  }
}

/** wp eval one scalar SQL (prepare-safe), trimmed. */
function dbScalar(sql: string, ...args: string[]): string {
  return wpEval(
    `global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare(${JSON.stringify(sql)}, ${args
      .map((a) => JSON.stringify(a))
      .join(', ')}));`,
  ).trim()
}

/** Idempotent seed: two full conflict pairs + the origin peer row. */
function seedFixture(): void {
  const php = `
global $wpdb;
$peers = $wpdb->prefix . 'wpmmcc_peers';
$maps  = $wpdb->prefix . 'wpmmcc_cross_mappings';
$journal = $wpdb->prefix . 'wpmmcc_journal';
$uuid = ${JSON.stringify(PEER_UUID)};
// wipe leftovers from any earlier run (by canonical + by titles)
$wpdb->query($wpdb->prepare("DELETE FROM {$peers} WHERE peer_uuid = %s", $uuid));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$maps} WHERE canonical_uuid IN (%s, %s, %s)",
  ${JSON.stringify(CANON_A)}, ${JSON.stringify(CANON_B)}, ${JSON.stringify(CANON_C)}
));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$journal} WHERE peer_uuid = %s AND event_type IN ('conflict_accept_remote', 'conflict_keep_local')",
  $uuid
));
// remove stale seed posts (originals + shadows from any earlier run) —
// ingestion-scoped so cleanup never echoes outbound CDC delete rows.
$wpmmccQuietDelete = function ($title) {
  global $wpdb;
  $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE post_title = %s", $title));
  foreach ((array) $ids as $pid) {
    \\WPMMCC\\Sync\\Event_Recorder::run_in_ingestion_scope(
      static function () use ($pid) { wp_delete_post((int) $pid, true); }
    );
  }
};
foreach (array(${JSON.stringify('E2E B3 Local Alpha')}, ${JSON.stringify('E2E B3 Local Beta')}, ${JSON.stringify(
    'E2E B3 Local Gamma',
  )}, ${JSON.stringify(SHADOW_A_TITLE)}, ${JSON.stringify(SHADOW_B_TITLE)}, ${JSON.stringify(
    SHADOW_C_TITLE,
  )}, ${JSON.stringify(PROMOTED_A_TITLE)}, ${JSON.stringify(PROMOTED_B_TITLE)}, ${JSON.stringify(
    PROMOTED_C_TITLE,
  )}) as $t) { $wpmmccQuietDelete($t); }
// origin peer row (name lookup for the queue card) with manual_review —
// exactly the strategy that produces shadow drafts.
$wpdb->insert($peers, array(
  'peer_uuid' => $uuid,
  'peer_name' => ${JSON.stringify(PEER_NAME)},
  'peer_type' => 'standalone',
  'endpoint_url' => 'http://e2e-conflict.invalid/wp-json/wpmmcc/v1',
  'shared_secret' => 'seeded-not-used-by-review-surface',
  'key_salt' => 'seededsaltseededsalt',
  'direction' => 'bidirectional',
  'sync_mode' => 'sync_only',
  'source_lang' => 'en_US',
  'target_lang' => 'zh_CN',
  'conflict_strategy' => 'manual_review',
  'status' => 'active',
  'install_signature' => 'e2e-seed-signature',
  'created_at' => current_time('mysql'),
  'updated_at' => current_time('mysql'),
));
// pair A (accept flow): displaced original + shadow + mapping on shadow.
// Post inserts run inside run_in_ingestion_scope — exactly the scope
// handle_push's materialize runs in — so the seed leaves ZERO outbound CDC
// journal rows (the fixture state equals the real post-shadow state; no
// queue noise in the lab DB).
$mk = function ($title, $content, $status) {
  return \\WPMMCC\\Sync\\Event_Recorder::run_in_ingestion_scope(
    static function () use ($title, $content, $status) {
      return wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => $status, 'post_type' => 'post'), true);
    }
  );
};
$origA = $mk('E2E B3 Local Alpha', ${JSON.stringify(LOCAL_A_CONTENT)}, 'publish');
$shadowA = $mk(${JSON.stringify(SHADOW_A_TITLE)}, ${JSON.stringify(REMOTE_A_CONTENT)}, 'draft');
update_post_meta($shadowA, '_wpmmcc_canonical_uuid', ${JSON.stringify(CANON_A)});
update_post_meta($shadowA, '_wpmmcc_shadow_conflict', 1);
update_post_meta($shadowA, '_wpmmcc_conflict_origin_uuid', $uuid);
update_post_meta($shadowA, '_wpmmcc_shadow_local_id', (int) $origA);
// pair B (keep flow): same shape
$origB = $mk('E2E B3 Local Beta', ${JSON.stringify(LOCAL_B_CONTENT)}, 'publish');
$shadowB = $mk(${JSON.stringify(SHADOW_B_TITLE)}, ${JSON.stringify(REMOTE_B_CONTENT)}, 'draft');
// pair C (tampered-nonce fail-closed flow; left untouched by its test)
$origC = $mk('E2E B3 Local Gamma', ${JSON.stringify(LOCAL_C_CONTENT)}, 'publish');
$shadowC = $mk(${JSON.stringify(SHADOW_C_TITLE)}, ${JSON.stringify(REMOTE_C_CONTENT)}, 'draft');
update_post_meta($shadowC, '_wpmmcc_canonical_uuid', ${JSON.stringify(CANON_C)});
update_post_meta($shadowC, '_wpmmcc_shadow_conflict', 1);
update_post_meta($shadowC, '_wpmmcc_conflict_origin_uuid', $uuid);
update_post_meta($shadowC, '_wpmmcc_shadow_local_id', (int) $origC);
update_post_meta($shadowB, '_wpmmcc_canonical_uuid', ${JSON.stringify(CANON_B)});
update_post_meta($shadowB, '_wpmmcc_shadow_conflict', 1);
update_post_meta($shadowB, '_wpmmcc_conflict_origin_uuid', $uuid);
update_post_meta($shadowB, '_wpmmcc_shadow_local_id', (int) $origB);
// mapping rows exactly as handle_push's replace leaves them: canonical →
// shadow id (the displaced originals are UNMAPPED by that same replace).
$wpdb->insert($maps, array(
  'canonical_uuid' => ${JSON.stringify(CANON_A)},
  'local_object_type' => 'post',
  'local_object_id' => (int) $shadowA,
  'peer_uuid' => $uuid,
  'remote_object_id' => 888801,
  'content_checksum' => 'e2e-b3-checksum-a',
  'vector_clock' => 5,
  'last_sync_direction' => 'inbound',
  'last_synced_at' => gmdate('Y-m-d H:i:s', time() - 3600),
));
$wpdb->insert($maps, array(
  'canonical_uuid' => ${JSON.stringify(CANON_B)},
  'local_object_type' => 'post',
  'local_object_id' => (int) $shadowB,
  'peer_uuid' => $uuid,
  'remote_object_id' => 888802,
  'content_checksum' => 'e2e-b3-checksum-b',
  'vector_clock' => 6,
  'last_sync_direction' => 'inbound',
  'last_synced_at' => gmdate('Y-m-d H:i:s', time() - 3600),
));
$wpdb->insert($maps, array(
  'canonical_uuid' => ${JSON.stringify(CANON_C)},
  'local_object_type' => 'post',
  'local_object_id' => (int) $shadowC,
  'peer_uuid' => $uuid,
  'remote_object_id' => 888803,
  'content_checksum' => 'e2e-b3-checksum-c',
  'vector_clock' => 7,
  'last_sync_direction' => 'inbound',
  'last_synced_at' => gmdate('Y-m-d H:i:s', time() - 3600),
));
echo 'seeded:' . (int) $origA . ':' . (int) $shadowA . ':' . (int) $origB . ':' . (int) $shadowB . ':' . (int) $shadowC;
`
  const out = wpEval(php.replace(/^\n/, ''))
  if (!out.trim().startsWith('seeded:')) {
    throw new Error(`fixture seed failed: ${out}`)
  }
  const ids = out.trim().split(':').slice(1).map(Number)
  if (ids.some((n) => !Number.isFinite(n) || n <= 0)) {
    throw new Error(`fixture seed produced invalid ids: ${out}`)
  }
}

/** Remove every fixture row (peer + mappings + posts + journal audit rows). */
function cleanFixture(): void {
  wpEval(`
global $wpdb;
$uuid = ${JSON.stringify(PEER_UUID)};
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s", $uuid));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid IN (%s, %s, %s)",
  ${JSON.stringify(CANON_A)}, ${JSON.stringify(CANON_B)}, ${JSON.stringify(CANON_C)}
));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$wpdb->prefix}wpmmcc_journal WHERE peer_uuid = %s AND event_type IN ('conflict_accept_remote', 'conflict_keep_local')",
  $uuid
));
$wpmmccQuietDelete = function ($title) {
  global $wpdb;
  $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE post_title = %s", $title));
  foreach ((array) $ids as $pid) {
    \\WPMMCC\\Sync\\Event_Recorder::run_in_ingestion_scope(
      static function () use ($pid) { wp_delete_post((int) $pid, true); }
    );
  }
};
foreach (array(${JSON.stringify('E2E B3 Local Alpha')}, ${JSON.stringify('E2E B3 Local Beta')}, ${JSON.stringify(
    'E2E B3 Local Gamma',
  )}, ${JSON.stringify(SHADOW_A_TITLE)}, ${JSON.stringify(SHADOW_B_TITLE)}, ${JSON.stringify(
    SHADOW_C_TITLE,
  )}, ${JSON.stringify(PROMOTED_A_TITLE)}, ${JSON.stringify(PROMOTED_B_TITLE)}, ${JSON.stringify(
    PROMOTED_C_TITLE,
  )}) as $t) { $wpmmccQuietDelete($t); }
echo 'clean';
`)
}

async function startProxy(): Promise<void> {
  proxy = http.createServer((req, res) => {
    const target = new URL(req.url ?? '/')
    const upstream = http.request(
      {
        host: backendUrl.hostname,
        port: Number(backendUrl.port || 80),
        method: req.method,
        path: `${target.pathname}${target.search}`,
        headers: { ...req.headers, host: target.host },
      },
      (upstreamRes) => {
        res.writeHead(upstreamRes.statusCode ?? 502, upstreamRes.headers)
        upstreamRes.pipe(res)
      },
    )
    upstream.on('error', () => res.destroy())
    req.pipe(upstream)
  })
  proxy.listen(0, '127.0.0.1')
  if (!proxy.listening) {
    await new Promise<void>((resolve) => proxy?.once('listening', resolve))
  }
  proxyPort = (proxy.address() as AddressInfo).port
}

async function newSession(browser: Browser): Promise<{ context: BrowserContext; page: import('@playwright/test').Page }> {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    ...(NEED_PROXY ? { proxy: { server: `http://127.0.0.1:${proxyPort}` } } : {}),
  })
  const page = await context.newPage()
  return { context, page }
}

async function wpmmccLogin(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(`${SITE}/wp-login.php?redirect_to=${encodeURIComponent(`${SITE}/wp-admin/`)}&reauth=1`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await page.fill('#user_login', ADMIN_USER)
  await page.fill('#user_pass', ADMIN_PASS)
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => null),
    page.click('#wp-submit'),
  ])
  const cookies = await page.context().cookies()
  expect(
    cookies.some((c) => c.name.startsWith('wordpress_logged_in_')),
    `login failed (url=${page.url()})`,
  ).toBe(true)
}

/** The Conflict Review Queue card. */
function conflictCard(page: import('@playwright/test').Page) {
  return page.locator('div.card', { hasText: 'Conflict Review Queue' }).first()
}

test.describe('34 WPMMCC conflict review surface: shadow drafts reviewable (B3)', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(async () => {
    if (NEED_PROXY) await startProxy()
    cleanFixture()
    seedFixture()
  })

  test.afterAll(async () => {
    cleanFixture()
    await proxy?.close()
    proxy = null
  })

  test('seeded shadow pairs render in the review queue (peer, canonical, diff, actions)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const card = conflictCard(page)
      await expect(card).toBeVisible()

      // Both seeded pairs appear as expandable review rows.
      const rowA = card.locator('details', { hasText: SHADOW_A_TITLE }).first()
      const rowB = card.locator('details', { hasText: SHADOW_B_TITLE }).first()
      await expect(rowA).toBeVisible({ timeout: 10_000 })
      await expect(rowB).toBeVisible()

      // Peer identity is resolved to the peers row name, not a raw uuid.
      await expect(rowA.locator('summary')).toContainText(PEER_NAME)

      // Canonical affordance: truncated with full-uuid title (32-spec family).
      const uuidCode = rowA.locator('code[title]')
      await expect(uuidCode).toHaveAttribute('title', CANON_A)

      // Expand the details block: the diff table lives inside and is hidden
      // while the block is collapsed.
      await rowA.locator('summary').click()

      // Native diff renderer present with local/remote column titles.
      await expect(rowA.locator('table.diff').first()).toBeVisible()
      await expect(rowA.locator('td.diff-title, th', { hasText: 'Local version (kept)' }).first()).toBeVisible()

      // Both action forms carry nonce + post_id + correct admin_post action.
      for (const action of ['wpmmcc_accept_remote', 'wpmmcc_keep_local']) {
        const form = rowA.locator(`form:has(input[name="action"][value="${action}"])`)
        await expect(form).toBeVisible()
        const pid = await form.locator('input[name="post_id"]').inputValue()
        expect(Number(pid), `${action} post_id must be the shadow id`).toBeGreaterThan(0)
        expect(await form.locator('input[name="_wpnonce"]').getAttribute('value')).toBeTruthy()
      }
      // Keep Local is destructive: it carries a confirm affordance.
      expect(
        await rowA.locator('form:has(input[name="action"][value="wpmmcc_keep_local"])').getAttribute('onsubmit'),
      ).toContain('confirm(')

      expect(findFatalError(await page.content()), 'fatal on review queue render').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('Accept Remote: shadow promoted in place, metas cleared, original untouched', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const card = conflictCard(page)
      const rowA = card.locator('details', { hasText: SHADOW_A_TITLE }).first()
      await expect(rowA).toBeVisible({ timeout: 10_000 })
      // Expand: the action forms live inside the collapsible block.
      await rowA.locator('summary').click()

      await Promise.all([
        page.waitForURL(/conflict_accepted=1/, { timeout: 30_000 }),
        rowA.locator('form:has(input[name="action"][value="wpmmcc_accept_remote"])').getByRole('button', { name: 'Accept Remote' }).click(),
      ])

      await expect(
        page.locator('.notice-success', { hasText: 'Conflict resolved: the remote (shadow draft) version was accepted' }),
      ).toBeVisible()

      // DB closure: shadow promoted (counterpart status publish, prefix
      // stripped), shadow metas cleared, canonical meta KEPT.
      expect(
        dbScalar(
          `SELECT post_status FROM {$wpdb->prefix}posts WHERE post_title = %s`,
          SHADOW_A_TITLE.replace(/^\[Sync Conflict Review\] /, ''),
        ),
        'promoted shadow must carry the counterpart publish state',
      ).toBe('publish')
      expect(
        dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}postmeta pm JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id WHERE pm.meta_key = '_wpmmcc_shadow_conflict' AND p.post_title = %s`, SHADOW_A_TITLE.replace(/^\[Sync Conflict Review\] /, '')),
        'shadow_conflict meta must be cleared on accept',
      ).toBe('0')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}posts p JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID WHERE pm.meta_key = '_wpmmcc_canonical_uuid' AND pm.meta_value = %s`,
          CANON_A,
        ),
        'promoted post must KEEP the canonical uuid meta (it is the mapped representative)',
      ).toBe('1')

      // Journal: exactly one decision row, consumed=1 (audit, not CDC).
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'conflict_accept_remote' AND peer_uuid = %s AND outcome = 'success' AND consumed = 1`,
          PEER_UUID,
        ),
        'conflict_accept_remote must journal exactly one consumed=1 row',
      ).toBe('1')

      // The displaced original is untouched by acceptance (never an
      // implicit delete of user content).
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_title = %s AND post_content = %s`,
          'E2E B3 Local Alpha',
          LOCAL_A_CONTENT,
        ),
        'displaced original must survive accept untouched',
      ).toBe('1')

      // Pair B is still queued (independent pairs).
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}posts p JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID WHERE pm.meta_key = '_wpmmcc_shadow_conflict' AND pm.meta_value = '1' AND p.post_title = %s`,
          SHADOW_B_TITLE,
        ),
        'pair B shadow must still be in the queue',
      ).toBe('1')

      expect(findFatalError(await page.content()), 'fatal after accept remote').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('Keep Local: mapping re-pointed onto the original, shadow discarded, no CDC echo', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const card = conflictCard(page)
      const rowB = card.locator('details', { hasText: SHADOW_B_TITLE }).first()
      await expect(rowB).toBeVisible({ timeout: 10_000 })
      // Expand: the action forms live inside the collapsible block.
      await rowB.locator('summary').click()

      // The before-state: outbound CDC queue snapshot (the echo-hazard guard).
      const cdcBefore = dbScalar(
        `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE direction = 'outbound' AND consumed = 0`,
      )

      const dialogMessages: string[] = []
      page.on('dialog', async (dialog) => {
        dialogMessages.push(dialog.message())
        await dialog.accept()
      })

      await Promise.all([
        page.waitForURL(/conflict_kept=1/, { timeout: 30_000 }),
        rowB.locator('form:has(input[name="action"][value="wpmmcc_keep_local"])').getByRole('button', { name: 'Keep Local' }).click(),
      ])
      expect(dialogMessages[0] ?? '', 'Keep Local confirm must warn about the shadow removal').toContain('Keep the local version')

      await expect(
        page.locator('.notice-success', { hasText: 'Conflict resolved: the local version was kept' }),
      ).toBeVisible()

      // Mapping closure: canonical B re-pointed onto the ORIGINAL post with
      // outbound direction + advanced last_synced_at; checksum/clock KEPT
      // (the peer's next digest of the SAME revision verdicts in_sync).
      const mapping = wpEval(`
global $wpdb;
$row = $wpdb->get_row($wpdb->prepare("SELECT local_object_id, content_checksum, vector_clock, last_sync_direction FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s", ${JSON.stringify(CANON_B)}));
if ($row) { echo (int) $row->local_object_id . '|' . (string) $row->content_checksum . '|' . (int) $row->vector_clock . '|' . (string) $row->last_sync_direction; } else { echo 'missing'; }
`).trim()
      const origB = dbScalar(
        `SELECT ID FROM {$wpdb->prefix}posts WHERE post_title = %s`,
        'E2E B3 Local Beta',
      )
      expect(mapping.startsWith(`${origB}|e2e-b3-checksum-b|6|outbound`), `mapping must be re-pointed with checksum/clock kept: got ${mapping}`).toBe(true)

      // Shadow post gone; original content intact.
      expect(
        dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_title = %s`, SHADOW_B_TITLE),
        'shadow staging post must be deleted',
      ).toBe('0')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_title = %s AND post_content = %s`,
          'E2E B3 Local Beta',
          LOCAL_B_CONTENT,
        ),
        'kept local original must be untouched',
      ).toBe('1')

      // Journal: exactly one decision row.
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'conflict_keep_local' AND peer_uuid = %s AND outcome = 'success' AND consumed = 1`,
          PEER_UUID,
        ),
        'conflict_keep_local must journal exactly one consumed=1 row',
      ).toBe('1')

      // Echo-hazard guard: the ingestion-scoped shadow deletion must NOT
      // have produced any new outbound CDC rows (a peer would materialize
      // such a delete as a tombstone of its original).
      const cdcAfter = dbScalar(
        `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE direction = 'outbound' AND consumed = 0`,
      )
      expect(cdcAfter, 'shadow deletion must not echo outbound CDC rows').toBe(cdcBefore)

      expect(findFatalError(await page.content()), 'fatal after keep local').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('tampered nonce is rejected fail-closed (403, no journal row, shadow untouched)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      // Pair C: untouched by the previous tests (serial order consumed A and B).
      const card = conflictCard(page)
      const rowC = card.locator('details', { hasText: SHADOW_C_TITLE }).first()
      await expect(rowC).toBeVisible({ timeout: 10_000 })
      const form = rowC.locator('form:has(input[name="action"][value="wpmmcc_accept_remote"])')
      const postId = await form.locator('input[name="post_id"]').inputValue()

      const before = dbScalar(
        `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'conflict_accept_remote' AND peer_uuid = %s`,
        PEER_UUID,
      )

      // Direct POST with a garbage nonce: check_admin_referer must wp_die
      // and resolve NOTHING. Same-origin page fetch (plain HTTP proxy).
      const result = await page.evaluate(async ({ url, postId }) => {
        const body = new URLSearchParams({
          action: 'wpmmcc_accept_remote',
          post_id: postId,
          _wpnonce: 'deadbeefdeadbeefdeadbeefdeadbeef',
        })
        const res = await fetch(url, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          redirect: 'manual',
        })
        return { status: res.status, body: await res.text() }
      }, { url: `${SITE}/wp-admin/admin-post.php`, postId })
      expect(result.status, 'tampered nonce must fail with 403, not silently pass').toBe(403)
      expect(result.body).toContain('The link you followed has expired')

      // Fail-closed: no journal row, shadow still draft-queued.
      const after = dbScalar(
        `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'conflict_accept_remote' AND peer_uuid = %s`,
        PEER_UUID,
      )
      expect(after, 'rejected request must not journal anything').toBe(before)
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}posts p JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID WHERE pm.meta_key = '_wpmmcc_shadow_conflict' AND pm.meta_value = '1' AND p.post_title = %s`,
          SHADOW_C_TITLE,
        ),
        'shadow must still be queued after a rejected request',
      ).toBe('1')
    } finally {
      await context.close()
    }
  })
})
