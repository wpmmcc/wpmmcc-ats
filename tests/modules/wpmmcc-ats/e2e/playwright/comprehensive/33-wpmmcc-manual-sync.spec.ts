/**
 * 33 — WPMMCC manual sync console (P1-03): Test Connection / Push Now / Pull Now.
 *
 * Coverage gap (3.8flash B1 / VERIFIED-REPAIR-PLAN-20260925):
 * the wpmmcc lifecycle previously ended at pairing — no UI path ever
 * verified a peer's reachability, shipped pending outbound CDC to a peer,
 * or pulled content the peer had that this site lacked. Content sync was
 * only ever client-driven (wptsall-client pulls from A and pushes to B);
 * the server itself was passive beyond the cron reconcile summary.
 *
 * The repair (wpmmcc/source/includes/sync/class-wpmmcc-manual-sync.php +
 * three admin_post handlers in class-wpmmcc-admin.php) adds the three
 * manual legs on the SERVER side using the exact REST wire primitives:
 *   - Test Connection: HMAC-signed /sync/digest probe (proves network path,
 *     key decryption, signature, anti-replay and peer registration in one
 *     call) + connection_test journal row
 *   - Push Now: pending CDC (consumed=0) → Packet::export_post →
 *     HMAC-signed /sync/push per packet → consumed on ack; transport
 *     failure aborts the loop and leaves the CDC entry queued (fail-closed)
 *   - Pull Now: HMAC /sync/digest → missing canonical UUIDs → peer
 *     /sync/pull → ingest through Rest_Push::handle_push() (the same
 *     materialize path a live peer push takes)
 *
 * Why an unreachable-peer fixture: a genuine two-site manual sync is not
 * reachable in this lab topology (one wpmmcc site; see spec 31), and the
 * plan's acceptance is precisely the failure contract — an unreachable
 * endpoint must produce journal failed rows and UI error indication, never
 * a silent pass. The seed inserts the peers row exactly as
 * handle_connect_peer_site() would (endpoint under the reserved .invalid
 * TLD, so DNS fails fast with NXDOMAIN — no timeout hang), plus one pending
 * CDC journal row pointing at a real post so Push Now exercises the
 * transport-failure path rather than the empty-queue path.
 *
 * Journey (against the WPMMCC lab site, container wptsall-wp-lab-wordpress-wpmmcc-1):
 *   1. Seeded peer row renders the three manual-sync forms (nonce,
 *      peer_id, action) in the Actions cell
 *   2. Test Connection → .invalid transport failure → redirect
 *      test_error=… + notice-error + journal connection_test
 *      outcome=failed consumed=1
 *   3. Push Now with a pending CDC row → export ok, transport failed →
 *      notice-warning + journal manual_push failed (Transport failed) +
 *      manual_push_summary failed + the CDC row stays consumed=0
 *      (fail-closed: unconsumed stays queued for retry)
 *   4. Pull Now → digest transport failure → notice-warning + journal
 *      manual_pull_summary failed (Digest fetch failed)
 *   5. Fail-closed: tampered nonce POST → 403, no journal row written
 *
 * Site routing note (same as spec 31): the lab container's WP_HOME is the
 * provisioning host http://192.168.1.12:9082, published on 127.0.0.1:9082;
 * the spec browses the canonical SITE origin through a transparent proxy.
 *   WPMMCC_LAB_BASE / WPMMCC_LAB_BACKEND / WPMMCC_LAB_ADMIN_USER / PASS
 *   WPMMCC_LAB_WP_CONTAINER (default wptsall-wp-lab-wordpress-wpmmcc-1)
 *
 * Run:
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 33-wpmmcc-manual-sync
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

// Deterministic fixture identity (hex-only, UUID-shaped). Distinct from
// spec 31's so both fixtures can coexist in the same lab.
const PEER_UUID = 'e2e0m001-0000-4000-8000-000000000001'
const PEER_NAME = 'E2E Manual Sync Peer (P1-03)'
const CANON_PUSH = 'e2e0m001-0000-4000-8000-0000000000b1'
// Reserved TLD (RFC 2606): guaranteed NXDOMAIN, fails in milliseconds.
const PEER_ENDPOINT = 'http://e2e-manual.invalid/wp-json/wpmmcc/v1'

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

/**
 * Idempotent fixture: wipe leftovers, then seed the unreachable peers row
 * (exactly the shape handle_connect_peer_site() writes) plus one pending CDC
 * journal row (consumed=0, direction=outbound) pointing at a REAL post so
 * Push Now exercises export-ok → transport-failed.
 */
function seedFixture(): void {
  const php = `
global $wpdb;
$peers = $wpdb->prefix . 'wpmmcc_peers';
$journal = $wpdb->prefix . 'wpmmcc_journal';
$uuid = ${JSON.stringify(PEER_UUID)};
// wipe leftovers from any earlier run
$wpdb->query($wpdb->prepare("DELETE FROM {$peers} WHERE peer_uuid = %s", $uuid));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$journal} WHERE peer_uuid = %s AND event_type IN ('connection_test', 'manual_push', 'manual_push_summary', 'manual_pull_summary')",
  $uuid
));
// peers row, exactly the shape handle_connect_peer_site() writes — but under
// the reserved .invalid TLD so every outbound call fails fast (NXDOMAIN).
// A real at-rest secret: the manual ops decrypt the peer shared_secret
// BEFORE any transport (Rest_Middleware::decrypt_shared_secret), so a
// garbage blob would fail the operation before DNS ever runs. Seeding via
// the plugin's own encrypt_shared_secret() primitive keeps the fixture on
// the real path: key decryption succeeds, HMAC signing succeeds, and the
// failure lands exactly on the transport leg (.invalid → NXDOMAIN).
$secret = \\WPMMCC\\REST\\Rest_Middleware::encrypt_shared_secret('e2e-manual-sync-secret', 'seededsaltseededsalt');
$wpdb->insert($peers, array(
  'peer_uuid' => $uuid,
  'peer_name' => ${JSON.stringify(PEER_NAME)},
  'peer_type' => 'standalone',
  'endpoint_url' => ${JSON.stringify(PEER_ENDPOINT)},
  'shared_secret' => $secret,
  'key_salt' => 'seededsaltseededsalt',
  'direction' => 'bidirectional',
  'sync_mode' => 'sync_only',
  'source_lang' => 'en_US',
  'target_lang' => 'zh_CN',
  'conflict_strategy' => 'lww',
  'status' => 'active',
  'install_signature' => 'e2e-seed-signature',
  'created_at' => current_time('mysql'),
  'updated_at' => current_time('mysql'),
));
// one pending CDC row (the shape Event_Recorder writes on post save):
// consumed=0 outbound, pointing at a real post so export_post() succeeds
// and the failure lands on the transport leg.
$postId = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID ASC LIMIT 1");
$wpdb->insert($journal, array(
  'occurred_at' => current_time('mysql'),
  'event_type' => 'update',
  'direction' => 'outbound',
  'canonical_uuid' => ${JSON.stringify(CANON_PUSH)},
  'object_type' => 'post',
  'local_object_id' => $postId,
  'peer_uuid' => $uuid,
  'outcome' => 'success',
  'consumed' => 0,
));
echo 'seeded:' . $postId . ':' . (string) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$peers} WHERE peer_uuid = %s", $uuid));
`
  const out = wpEval(php.replace(/^\n/, ''))
  if (!out.trim().startsWith('seeded:')) {
    throw new Error(`fixture seed failed: ${out}`)
  }
  const postId = Number(out.trim().split(':')[1])
  if (!Number.isFinite(postId) || postId <= 0) {
    throw new Error(`fixture seed found no real post for the CDC row: ${out}`)
  }
}

/** Remove every fixture row (peer + the four manual-sync journal kinds + CDC row). */
function cleanFixture(): void {
  wpEval(`
global $wpdb;
$uuid = ${JSON.stringify(PEER_UUID)};
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s", $uuid));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$wpdb->prefix}wpmmcc_journal WHERE peer_uuid = %s AND event_type IN ('connection_test', 'manual_push', 'manual_push_summary', 'manual_pull_summary')",
  $uuid
));
$wpdb->query($wpdb->prepare(
  "DELETE FROM {$wpdb->prefix}wpmmcc_journal WHERE canonical_uuid = %s AND consumed = 0",
  ${JSON.stringify(CANON_PUSH)}
));
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

/** The seeded peer's row in the Connected Peer Sites card. */
async function peerRow(page: import('@playwright/test').Page) {
  const peersCard = page.locator('div.card', { hasText: 'Connected Peer Sites' }).first()
  await expect(peersCard).toBeVisible()
  const row = peersCard.locator(`tr:has-text("${PEER_NAME}")`)
  await expect(row).toBeVisible({ timeout: 10_000 })
  return row
}

test.describe('33 WPMMCC manual sync console: Test Connection / Push Now / Pull Now (P1-03)', () => {
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

  test('seeded peer renders the three manual-sync forms (nonce, peer_id, action)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const row = await peerRow(page)

      // All three operations live in the Actions cell as nonce'd admin_post
      // forms (same affordance family as Disconnect).
      for (const action of ['wpmmcc_peer_test_connection', 'wpmmcc_peer_push_now', 'wpmmcc_peer_pull_now']) {
        const form = row.locator(`form:has(input[name="action"][value="${action}"])`)
        await expect(form).toBeVisible()
        const peerId = await form.locator('input[name="peer_id"]').inputValue()
        expect(Number(peerId), `${action} peer_id must carry the seeded row id`).toBeGreaterThan(0)
        expect(await form.locator('input[name="_wpnonce"]').getAttribute('value')).toBeTruthy()
      }
      // The three buttons are distinguishable affordances.
      await expect(row.getByRole('button', { name: 'Test Connection' })).toBeVisible()
      await expect(row.getByRole('button', { name: 'Push Now' })).toBeVisible()
      await expect(row.getByRole('button', { name: 'Pull Now' })).toBeVisible()

      expect(findFatalError(await page.content()), 'fatal on peers render').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('Test Connection against an unreachable peer: UI error notice + journal failed row', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })
      const row = await peerRow(page)

      await Promise.all([
        page.waitForURL(/test_error=/, { timeout: 30_000 }),
        row.getByRole('button', { name: 'Test Connection' }).click(),
      ])

      // UI error indication (not a silent pass): the redirect carries
      // test_error and the page renders the error notice with the reason the
      // call failed. The peer host is under the reserved .invalid TLD and what
      // that leads to depends on the machine's DNS: Peer_Http refuses it (the
      // name does not resolve, or fake-IP DNS sends it to 198.18/15 — "Peer URL
      // … was refused: …"), or a resolver that answers for it lets the call
      // reach the transport, which fails. Assert that class, not a particular
      // text, and never WordPress's opaque "A valid URL was not provided."
      const notice = page.locator('.notice-error', { hasText: 'Connection test failed:' })
      await expect(notice).toBeVisible()
      const noticeText = (await notice.textContent()) ?? ''
      expect(noticeText, 'error notice must carry the reason the call failed').toMatch(
        /was refused|cURL error|Could not resolve|Empty reply|Failed to connect|timed out/i,
      )
      expect(noticeText, 'and not core\'s unexplained URL rejection').not.toMatch(/valid URL was not provided/i)

      // Journal: connection_test failed row, consumed=1 (audit, not CDC).
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'connection_test' AND peer_uuid = %s AND outcome = 'failed' AND consumed = 1`,
          PEER_UUID,
        ),
        'connection_test must journal exactly one failed row (consumed=1)',
      ).toBe('1')

      // The journal card renders the audit event (Event column = event_type).
      const journalCard = page.locator('div.card', { hasText: 'Recent Sync Journal' }).first()
      await expect(journalCard.locator('code', { hasText: 'connection_test' }).first()).toBeVisible({ timeout: 10_000 })

      expect(findFatalError(await page.content()), 'fatal after failed test connection').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('Push Now with pending CDC: transport failure journaled, CDC row stays queued (fail-closed)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })
      const row = await peerRow(page)

      await Promise.all([
        page.waitForURL(/push_done=1/, { timeout: 30_000 }),
        row.getByRole('button', { name: 'Push Now' }).click(),
      ])

      // UI warning (failed>0 must NOT render the green success class).
      const notice = page.locator('.notice-warning', { hasText: 'Manual push finished' })
      await expect(notice).toBeVisible()
      expect(await notice.textContent()).toContain('0 packet(s) delivered to the peer, 1 failed')

      // DB: manual_push transport-failure row + manual_push_summary failed.
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'manual_push' AND peer_uuid = %s AND outcome = 'failed' AND message LIKE '%%Transport failed%%'`,
          PEER_UUID,
        ),
        'manual_push must journal the transport failure',
      ).toBe('1')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'manual_push_summary' AND peer_uuid = %s AND outcome = 'failed'`,
          PEER_UUID,
        ),
        'manual_push_summary must journal outcome=failed',
      ).toBe('1')

      // Fail-closed: the pending CDC row must NOT be marked consumed by a
      // failed push (unconsumed stays queued for retry/next client run).
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE canonical_uuid = %s AND consumed = 0`,
          CANON_PUSH,
        ),
        'the pending CDC row must survive unconsumed after a failed push',
      ).toBe('1')

      expect(findFatalError(await page.content()), 'fatal after failed push').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('Pull Now against an unreachable peer: warning notice + failed digest summary', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })
      const row = await peerRow(page)

      await Promise.all([
        page.waitForURL(/pull_done=1/, { timeout: 30_000 }),
        row.getByRole('button', { name: 'Pull Now' }).click(),
      ])

      // Transport failure on the digest leg is an operation failure (1
      // failed), not a green "0 pulled, 0 failed".
      const notice = page.locator('.notice-warning', { hasText: 'Manual pull finished' })
      await expect(notice).toBeVisible()
      expect(await notice.textContent()).toContain('0 packet(s) ingested from the peer, 1 failed')

      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'manual_pull_summary' AND peer_uuid = %s AND outcome = 'failed' AND message LIKE '%%Digest fetch failed%%'`,
          PEER_UUID,
        ),
        'manual_pull_summary must journal the digest transport failure',
      ).toBe('1')

      expect(findFatalError(await page.content()), 'fatal after failed pull').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('tampered nonce is rejected fail-closed (403, no journal row written)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })
      const row = await peerRow(page)
      const form = row.locator('form:has(input[name="action"][value="wpmmcc_peer_test_connection"])')
      const peerId = await form.locator('input[name="peer_id"]').inputValue()

      const before = dbScalar(
        `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'connection_test' AND peer_uuid = %s`,
        PEER_UUID,
      )

      // Direct POST with a garbage nonce: check_admin_referer must wp_die and
      // write NOTHING. Same-origin page fetch (the context proxy is a plain
      // HTTP forwarder without CONNECT support).
      const result = await page.evaluate(async ({ url, peerId }) => {
        const body = new URLSearchParams({
          action: 'wpmmcc_peer_test_connection',
          peer_id: peerId,
          _wpnonce: 'deadbeefdeadbeefdeadbeefdeadbeef',
        })
        const res = await fetch(url, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          redirect: 'manual',
        })
        return { status: res.status, body: await res.text() }
      }, { url: `${SITE}/wp-admin/admin-post.php`, peerId })
      expect(result.status, 'tampered nonce must fail with 403, not silently pass').toBe(403)
      expect(result.body).toContain('The link you followed has expired')

      // Fail-closed: no journal row was written by the rejected request.
      const after = dbScalar(
        `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'connection_test' AND peer_uuid = %s`,
        PEER_UUID,
      )
      expect(after, 'rejected request must not journal anything').toBe(before)
    } finally {
      await context.close()
    }
  })
})
