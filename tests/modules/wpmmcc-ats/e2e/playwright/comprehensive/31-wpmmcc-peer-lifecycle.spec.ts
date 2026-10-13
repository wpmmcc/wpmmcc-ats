/**
 * seed: paired-with ../owned-wp/connect-peer.journey.spec.ts
 * 31 — WPMMCC plugin peer lifecycle: Disconnect (P0-01) closes the append-only gap.
 *
 * Coverage gap (3.8flash P0-01 / VERIFIED-REPAIR-PLAN-20260925 batch A1):
 * the wpmmcc_peers table was append-only — no UI, no admin_post handler, no
 * REST route ever removed a row, so a mis-paired or rotated site required
 * direct DB surgery. The repair (wpmmcc/source/includes/admin/
 * class-wpmmcc-admin.php handle_disconnect_peer()) adds the inverse action:
 * Disconnect deletes the peers row AND cascades the peer's cross_mappings
 * rows (orphan mappings would misroute content after a later re-pair with
 * the same remote UUID), refreshes every peer-fed cache key, and writes a
 * consumed=1 peer_disconnect audit entry to the journal.
 *
 * Why a seeded fixture rather than a live pairing: the handshake endpoint
 * enforces two self-pairing guards (wpmmcc_self_pairing_prohibited +
 * ADR-5 same-installation rejection), and the lab exposes exactly ONE
 * wpmmcc site (the 9081 blog container runs wpmmcc-ats, not wpmmcc), so a
 * genuine two-site handshake is not reachable in this topology. The seed
 * inserts the peers row exactly as handle_connect_peer_site() would, then
 * the REAL Disconnect form runs end-to-end (nonce, capability, admin_post,
 * redirect, notice) against it.
 *
 * Journey (against the WPMMCC lab site, container wptsall-wp-lab-wordpress-wpmmcc-1):
 *   1. Seed peers row + a peer-scoped cross_mapping + a SELF mapping row
 *      (peer_uuid='') → admin page renders the row with a Disconnect control
 *      (nonce form action wpmmcc_disconnect_peer, class-wpmmcc-admin.php)
 *   2. Fail-closed: tampered nonce POST → 403 (check_admin_referer wp_die),
 *      peer row untouched in DB
 *   3. Disconnect → redirect peer_deleted=1 + notice + row gone + journal
 *      renders peer_disconnect; DB: peers=0, peer-scoped mappings=0 (cascade),
 *      SELF row=1 (self rows must survive — they are local vector clocks),
 *      journal peer_disconnect consumed=1
 *
 * Site routing note (same as spec 26): the lab container's WP_HOME is the
 * provisioning host http://192.168.1.12:9082, published on 127.0.0.1:9082;
 * the spec browses the canonical SITE origin through a transparent proxy.
 *   WPMMCC_LAB_BASE / WPMMCC_LAB_BACKEND / WPMMCC_LAB_ADMIN_USER / PASS
 *   WPMMCC_LAB_WP_CONTAINER (default wptsall-wp-lab-wordpress-wpmmcc-1)
 *
 * Run:
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 31-wpmmcc-peer-lifecycle
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

// Deterministic fixture identity (hex-only, UUID-shaped so schema/format
// validators stay happy). Distinct canonical UUIDs separate the peer-scoped
// mapping (must be cascaded away) from the SELF mapping (must survive).
const PEER_UUID = 'e2e0p001-0000-4000-8000-000000000001'
const PEER_NAME = 'E2E Lifecycle Peer (P0-01)'
const CANON_PEER = 'e2e0p001-0000-4000-8000-0000000000a1'
const CANON_SELF = 'e2e0p001-0000-4000-8000-0000000000a2'

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

/** Idempotent fixture: wipe leftovers, then seed peers row + peer mapping + self mapping. */
function seedFixture(): void {
  const php = `
global $wpdb;
$peers = $wpdb->prefix . 'wpmmcc_peers';
$maps  = $wpdb->prefix . 'wpmmcc_cross_mappings';
$journal = $wpdb->prefix . 'wpmmcc_journal';
$uuid = ${JSON.stringify(PEER_UUID)};
// wipe leftovers from any earlier run
$wpdb->query($wpdb->prepare("DELETE FROM {$peers} WHERE peer_uuid = %s", $uuid));
$wpdb->query($wpdb->prepare("DELETE FROM {$maps} WHERE canonical_uuid IN (%s, %s) OR peer_uuid = %s", ${JSON.stringify(
    CANON_PEER,
  )}, ${JSON.stringify(CANON_SELF)}, $uuid));
$wpdb->query($wpdb->prepare("DELETE FROM {$journal} WHERE peer_uuid = %s AND event_type = 'peer_disconnect'", $uuid));
// peers row, exactly the shape handle_connect_peer_site() writes
$wpdb->insert($peers, array(
  'peer_uuid' => $uuid,
  'peer_name' => ${JSON.stringify(PEER_NAME)},
  'peer_type' => 'standalone',
  'endpoint_url' => 'http://e2e-lifecycle.invalid/wp-json/wpmmcc/v1',
  'shared_secret' => 'seeded-not-a-real-secret',
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
// peer-scoped mapping: orphan-risk row the Disconnect cascade must remove
$wpdb->insert($maps, array(
  'canonical_uuid' => ${JSON.stringify(CANON_PEER)},
  'local_object_type' => 'post',
  'local_object_id' => 999901,
  'peer_uuid' => $uuid,
  'remote_object_id' => 888801,
  'content_checksum' => 'e2e-checksum',
  'vector_clock' => 3,
  'last_sync_direction' => 'inbound',
  'last_synced_at' => current_time('mysql'),
));
// SELF mapping row (peer_uuid='', remote_object_id=0): local vector clock
// state the cascade must NEVER touch
$wpdb->insert($maps, array(
  'canonical_uuid' => ${JSON.stringify(CANON_SELF)},
  'local_object_type' => 'post',
  'local_object_id' => 999902,
  'peer_uuid' => '',
  'remote_object_id' => 0,
  'content_checksum' => '',
  'vector_clock' => 7,
  'last_sync_direction' => 'outbound',
  'last_synced_at' => current_time('mysql'),
));
echo 'seeded:' . (string) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$peers} WHERE peer_uuid = %s", $uuid));
`
  const out = wpEval(php.replace(/^\n/, ''))
  if (!out.trim().startsWith('seeded:')) {
    throw new Error(`fixture seed failed: ${out}`)
  }
}

/** Remove every fixture row (peers + mappings + journal audit row). */
function cleanFixture(): void {
  wpEval(`
global $wpdb;
$uuid = ${JSON.stringify(PEER_UUID)};
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s", $uuid));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid IN (%s, %s) OR peer_uuid = %s", ${JSON.stringify(
    CANON_PEER,
  )}, ${JSON.stringify(CANON_SELF)}, $uuid));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpmmcc_journal WHERE peer_uuid = %s AND event_type = 'peer_disconnect'", $uuid));
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

test.describe('31 WPMMCC peer lifecycle: Disconnect (P0-01)', () => {
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

  test('seeded peer renders with the Disconnect control (form, nonce, peer_id)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const peersCard = page.locator('div.card', { hasText: 'Connected Peer Sites' }).first()
      await expect(peersCard).toBeVisible()
      // Seeded row present (not the empty state).
      const row = peersCard.locator(`tr:has-text("${PEER_NAME}")`)
      await expect(row).toBeVisible({ timeout: 10_000 })

      // A2's same verb/danger affordances: Actions header + red Disconnect button.
      await expect(peersCard.getByRole('columnheader', { name: 'Actions', exact: true })).toBeVisible()
      const form = row.locator('form:has(input[name="action"][value="wpmmcc_disconnect_peer"])')
      await expect(form).toBeVisible()
      const peerId = await form.locator('input[name="peer_id"]').inputValue()
      expect(Number(peerId), 'peer_id hidden input must carry the seeded row id').toBeGreaterThan(0)
      expect(await form.locator('input[name="_wpnonce"]').getAttribute('value')).toBeTruthy()
      const btn = form.getByRole('button', { name: 'Disconnect' })
      await expect(btn).toBeVisible()
      expect(await btn.getAttribute('class')).toContain('button-link-delete')
      // Confirm-dialg affordance present (onsubmit confirm), not a silent delete.
      expect(await form.getAttribute('onsubmit')).toContain('confirm(')

      // UUID truncation affordance: title carries the full 36-char uuid.
      // (Scope to code[title]: the row also renders Languages/Strategy codes
      // without title attributes.)
      const uuidCode = row.locator('code[title]')
      await expect(uuidCode).toHaveAttribute('title', PEER_UUID)

      expect(findFatalError(await page.content()), 'fatal on seeded peers render').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('tampered nonce is rejected fail-closed (403, peer row untouched)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const peersCard = page.locator('div.card', { hasText: 'Connected Peer Sites' }).first()
      const row = peersCard.locator(`tr:has-text("${PEER_NAME}")`)
      await expect(row).toBeVisible({ timeout: 10_000 })
      const form = row.locator('form:has(input[name="action"][value="wpmmcc_disconnect_peer"])')
      const peerId = await form.locator('input[name="peer_id"]').inputValue()

      // Direct POST with a garbage nonce: check_admin_referer must wp_die
      // (WordPress Failure Notice) and delete NOTHING. Sent as a same-origin
      // page fetch (NOT page.request — the context proxy is a plain HTTP
      // forwarder without CONNECT support, which apiRequestContext needs).
      const result = await page.evaluate(async ({ url, peerId }) => {
        const body = new URLSearchParams({
          action: 'wpmmcc_disconnect_peer',
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
      // wp_nonce_ays() copy for this WP version (functions.php:3692): title
      // "An error occurred." + body "The link you followed has expired."
      expect(result.body).toContain('The link you followed has expired')

      // Fail-closed: the peers row is still in the DB.
      const stillThere = dbScalar(
        `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s`,
        PEER_UUID,
      )
      expect(stillThere, 'peer row must survive a tampered-nonce attempt').toBe('1')
    } finally {
      await context.close()
    }
  })

  test('Disconnect lifecycle: row + cascade + journal audit + self rows survive', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const peersCard = page.locator('div.card', { hasText: 'Connected Peer Sites' }).first()
      const row = peersCard.locator(`tr:has-text("${PEER_NAME}")`)
      await expect(row).toBeVisible({ timeout: 10_000 })

      // The onsubmit confirm() must be answered; capture the message for the
      // destructive-action contract assert.
      const dialogMessages: string[] = []
      page.on('dialog', async (dialog) => {
        dialogMessages.push(dialog.message())
        await dialog.accept()
      })

      await Promise.all([
        page.waitForURL(/peer_deleted=1/, { timeout: 30_000 }),
        row.locator('form:has(input[name="action"][value="wpmmcc_disconnect_peer"])').getByRole('button', { name: 'Disconnect' }).click(),
      ])
      expect(
        dialogMessages[0] ?? '',
        'confirm dialog must warn about the mapping cleanup',
      ).toContain('Disconnect and remove this peer site')

      // Redirect notice (handle_disconnect_peer → peer_deleted=1).
      await expect(
        page.locator('.notice-success', { hasText: 'Peer site disconnected and removed' }),
      ).toBeVisible()

      // Seeded row gone from the peers table.
      await expect(peersCard.locator(`tr:has-text("${PEER_NAME}")`)).toHaveCount(0)

      // Journal renders the audit event (Event column = event_type).
      const journalCard = page.locator('div.card', { hasText: 'Recent Sync Journal' }).first()
      await expect(journalCard.locator('code', { hasText: 'peer_disconnect' }).first()).toBeVisible({ timeout: 10_000 })

      expect(findFatalError(await page.content()), 'fatal after disconnect').toBeNull()

      // DB-level closure (D2): peers row gone, peer-scoped mapping cascaded,
      // SELF row preserved, audit row written with consumed=1 (not CDC).
      expect(
        dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s`, PEER_UUID),
        'peers row must be deleted',
      ).toBe('0')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE peer_uuid = %s`,
          PEER_UUID,
        ),
        'peer-scoped mappings must cascade with the peers row',
      ).toBe('0')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s`,
          CANON_PEER,
        ),
        'the peer-scoped mapping row itself must be gone',
      ).toBe('0')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s`,
          CANON_SELF,
        ),
        'SELF mapping rows (local vector clocks) must survive the cascade',
      ).toBe('1')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE event_type = 'peer_disconnect' AND peer_uuid = %s AND consumed = 1`,
          PEER_UUID,
        ),
        'peer_disconnect audit entry must exist exactly once, consumed=1 (out of CDC queue)',
      ).toBe('1')
    } finally {
      await context.close()
    }
  })
})
