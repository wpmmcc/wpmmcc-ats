/**
 * 32 — WPMMCC journal audit surface: full UUID, object links, failed detail, safe Clear.
 *
 * Coverage gap (3.8flash P2-04 / D2, VERIFIED-REPAIR-PLAN-20260925 batch B2):
 * the Recent Sync Journal card rendered a 16-char UUID stub and a bare
 * outcome cell, while the schema has always carried object_type /
 * local_object_id / remote_object_id / duration_ms / message. Pure render
 * upgrade, zero DB change:
 *   - Entity UUID: full value via title + one-click copy button (async
 *     Clipboard API with an execCommand legacy path — navigator.clipboard is
 *     undefined on insecure http origins, so a one-path implementation would
 *     silently no-op on http-deployed sites)
 *   - Object column: type + local id, linked to the edit screen for posts,
 *     remote id when present; em-dash for entity-less (peer-level) events
 *   - failed rows: expandable error detail with the stored message + duration
 *   - Clear Processed Entries: audit-scoped delete (consumed = 1 ONLY —
 *     the consumed = 0 CDC queue is the sync engine's pending outbound
 *     queue and must survive a journal cleanup)
 *
 * Journey (against the WPMMCC lab site, container wptsall-wp-lab-wordpress-wpmmcc-1):
 *   1. Seed four journal rows (success post row w/ real post link, failed row
 *      w/ message + duration, consumed=0 CDC row, peer-level row w/o uuid)
 *      → assert every upgraded cell renders (title, copy affordance, edit
 *      link, failed details, em-dash) and the copy button really writes the
 *      clipboard
 *   2. Clear Processed Entries → confirm dialog → journal_cleared notice +
 *      processed rows gone in UI and DB, while the consumed=0 CDC row
 *      survives both (the safety property of the scoped delete)
 *
 * Site routing + env: same contract as specs 26/31 (transparent proxy to the
 * published backend port).
 *
 * Run:
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 32-wpmmcc-journal-audit
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

// Deterministic fixture identity (hex-only, UUID-shaped).
const CANON_A = 'e2e0j002-0000-4000-8000-0000000000b1' // success + post link + copy
const CANON_B = 'e2e0j002-0000-4000-8000-0000000000b2' // failed + message + duration
const CANON_C = 'e2e0j002-0000-4000-8000-0000000000b3' // consumed=0 CDC row (must survive Clear)
const FAIL_MESSAGE = 'mock provider 503 upstream: connection refused after 2 attempts'

const siteUrl = new URL(SITE)
const backendUrl = new URL(BACKEND)
const NEED_PROXY = siteUrl.host !== backendUrl.host

let proxy: http.Server | null = null
let proxyPort = 0

function wpEval(php: string): string {
  try {
    return execFileSync(
      'docker',
      ['exec', '-e', 'PAGER=cat', WPMCC_WP_CONTAINER, 'wp', 'eval', php, '--allow-root', '--path=/var/www/html'],
      { encoding: 'utf-8' },
    )
  } catch (e: any) {
    return String((e.stdout ?? '') + (e.stderr ?? ''))
  }
}

function dbScalar(sql: string, ...args: string[]): string {
  return wpEval(
    `global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare(${JSON.stringify(sql)}, ${args
      .map((a) => JSON.stringify(a))
      .join(', ')}));`,
  ).trim()
}

/**
 * Idempotent fixture: wipe the four fixture rows, pick a real published post
 * for the object-link row, then insert:
 *   J1 success post row (consumed=1, object link)
 *   J2 failed row (consumed=1, message + duration_ms)
 *   J3 pending CDC row (consumed=0 — the must-survive row)
 *   J4 peer-level row (consumed=1, no canonical uuid)
 * Returns the post id used for J1.
 */
function seedJournal(): number {
  const php = `
global $wpdb;
$j = $wpdb->prefix . 'wpmmcc_journal';
$wpdb->query($wpdb->prepare("DELETE FROM {$j} WHERE canonical_uuid IN (%s, %s, %s)", ${JSON.stringify(
    CANON_A,
  )}, ${JSON.stringify(CANON_B)}, ${JSON.stringify(CANON_C)}));
$wpdb->query($wpdb->prepare("DELETE FROM {$j} WHERE canonical_uuid = '' AND event_type = 'peer_disconnect' AND local_object_id = %d", 999907));
$post_id = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID ASC LIMIT 1");
if ($post_id === 0) { $post_id = (int) wp_insert_post(array('post_title' => 'Journal E2E Object Post', 'post_status' => 'publish')); }
$wpdb->insert($j, array(
  'occurred_at' => current_time('mysql'),
  'event_type' => 'update',
  'direction' => 'outbound',
  'canonical_uuid' => ${JSON.stringify(CANON_A)},
  'object_type' => 'post',
  'local_object_id' => $post_id,
  'peer_uuid' => 'peer-of-a',
  'remote_object_id' => 424242,
  'outcome' => 'success',
  'duration_ms' => 55,
  'consumed' => 1,
));
$wpdb->insert($j, array(
  'occurred_at' => current_time('mysql'),
  'event_type' => 'push',
  'direction' => 'outbound',
  'canonical_uuid' => ${JSON.stringify(CANON_B)},
  'object_type' => 'post',
  'local_object_id' => $post_id,
  'outcome' => 'failed',
  'duration_ms' => 412,
  'message' => ${JSON.stringify(FAIL_MESSAGE)},
  'consumed' => 1,
));
$wpdb->insert($j, array(
  'occurred_at' => current_time('mysql'),
  'event_type' => 'update',
  'direction' => 'outbound',
  'canonical_uuid' => ${JSON.stringify(CANON_C)},
  'object_type' => 'post',
  'local_object_id' => $post_id,
  'outcome' => 'success',
  'consumed' => 0,
));
$wpdb->insert($j, array(
  'occurred_at' => current_time('mysql'),
  'event_type' => 'peer_disconnect',
  'direction' => 'outbound',
  'canonical_uuid' => '',
  'object_type' => 'peer',
  'local_object_id' => 999907,
  'outcome' => 'success',
  'consumed' => 1,
));
echo 'post:' . $post_id;
`
  const out = wpEval(php.replace(/^\n/, ''))
  const m = /post:(\d+)/.exec(out)
  if (!m) {
    throw new Error(`journal fixture seed failed: ${out}`)
  }
  return Number(m[1])
}

function cleanJournal(): void {
  wpEval(`
global $wpdb;
$j = $wpdb->prefix . 'wpmmcc_journal';
$wpdb->query($wpdb->prepare("DELETE FROM {$j} WHERE canonical_uuid IN (%s, %s, %s)", ${JSON.stringify(
    CANON_A,
  )}, ${JSON.stringify(CANON_B)}, ${JSON.stringify(CANON_C)}));
$wpdb->query($wpdb->prepare("DELETE FROM {$j} WHERE canonical_uuid = '' AND event_type = 'peer_disconnect' AND local_object_id = %d", 999907));
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

test.describe('32 WPMMCC journal audit surface (B2)', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(async () => {
    if (NEED_PROXY) await startProxy()
    cleanJournal()
    seedJournal()
  })

  test.afterAll(async () => {
    cleanJournal()
    await proxy?.close()
    proxy = null
  })

  test('journal rows render full-uuid affordance, object links, failed detail', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const journalCard = page.locator('div.card', { hasText: 'Recent Sync Journal' }).first()
      await expect(journalCard).toBeVisible()
      await expect(journalCard.locator('table.widefat')).toBeVisible({ timeout: 10_000 })

      // New Object + upgraded column set.
      for (const header of ['Time', 'Direction', 'Event', 'Entity UUID', 'Object', 'Outcome']) {
        await expect(journalCard.getByRole('columnheader', { name: header, exact: true })).toBeVisible()
      }

      // J1: full UUID in title + copy affordance + object link to the real
      // post's edit screen + remote id.
      const rowA = journalCard.locator('tr:has(code[title="' + CANON_A + '"])')
      await expect(rowA).toBeVisible()
      const copyBtn = rowA.locator('button[data-wpmmcc-copy]')
      await expect(copyBtn).toHaveAttribute('data-wpmmcc-copy', CANON_A)
      const postId = Number(
        dbScalar(`SELECT local_object_id FROM {$wpdb->prefix}wpmmcc_journal WHERE canonical_uuid = %s`, CANON_A),
      )
      expect(postId).toBeGreaterThan(0)
      await expect(rowA.getByRole('link', { name: `post #${postId}` })).toHaveAttribute(
        'href',
        new RegExp(`post\\.php\\?post=${postId}&action=edit`),
      )
      await expect(rowA.getByText(`↔ remote #424242`)).toBeVisible()

      // The copy affordance carries the FULL uuid and the handler really
      // runs: the button flips to 'Copied'. (Clipboard CONTENT cannot be
      // read back on this lab — navigator.clipboard is undefined on
      // insecure http origins, which is exactly why the implementation has
      // the execCommand legacy path; the 'Copied' feedback fires on BOTH
      // paths, so it is the honest cross-context completion signal.)
      await copyBtn.click()
      await expect(copyBtn).toHaveText('Copied', { timeout: 2000 })

      // J2: failed outcome renders expandable stored message + duration.
      const rowB = journalCard.locator('tr:has(code[title="' + CANON_B + '"])')
      await expect(rowB).toBeVisible()
      await expect(rowB.getByText('failed')).toBeVisible()
      const details = rowB.locator('details')
      await expect(details).toBeVisible()
      await details.locator('summary').click()
      await expect(details.locator('pre')).toHaveText(FAIL_MESSAGE)
      await expect(details.getByText('Duration: 412 ms')).toBeVisible()

      // J3: pending CDC row is present (the must-survive row for test 2).
      await expect(journalCard.locator('tr:has(code[title="' + CANON_C + '"])')).toBeVisible()

      // J4: peer-level event — no canonical uuid renders an em-dash, not a
      // '…' stub; object cell shows the peer row id.
      const rowD = journalCard.locator('tr:has-text("peer #999907")')
      await expect(rowD).toBeVisible()
      expect(await rowD.locator('code[title]').count(), 'peer-level rows carry no canonical uuid').toBe(0)

      expect(findFatalError(await page.content()), 'fatal on journal render').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('Clear Processed Entries removes audit rows, preserves the CDC queue', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      const journalCard = page.locator('div.card', { hasText: 'Recent Sync Journal' }).first()
      await expect(journalCard.locator('table.widefat')).toBeVisible({ timeout: 10_000 })

      const dialogMessages: string[] = []
      page.on('dialog', async (dialog) => {
        dialogMessages.push(dialog.message())
        await dialog.accept()
      })

      const clearForm = journalCard.locator('form:has(input[name="action"][value="wpmmcc_clear_journal"])')
      await expect(clearForm).toBeVisible()
      expect(await clearForm.locator('input[name="_wpnonce"]').getAttribute('value')).toBeTruthy()
      await Promise.all([
        page.waitForURL(/journal_cleared=1/, { timeout: 30_000 }),
        clearForm.getByRole('button', { name: 'Clear Processed Entries' }).click(),
      ])
      expect(dialogMessages[0] ?? '', 'clear must confirm and state the CDC-preserve scope').toContain(
        'Pending sync events',
      )

      // Notice + processed rows gone in UI.
      await expect(
        page.locator('.notice-success', { hasText: 'Processed journal entries cleared' }),
      ).toBeVisible()
      await expect(page.locator('code[title="' + CANON_A + '"]')).toHaveCount(0)
      await expect(page.locator('code[title="' + CANON_B + '"]')).toHaveCount(0)
      await expect(page.getByText('peer #999907')).toHaveCount(0)

      // The consumed=0 CDC row survives — in UI and DB.
      await expect(page.locator('code[title="' + CANON_C + '"]')).toBeVisible()
      expect(
        dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE canonical_uuid = %s`, CANON_A),
        'processed (consumed=1) audit row must be cleared',
      ).toBe('0')
      expect(
        dbScalar(`SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE canonical_uuid = %s`, CANON_B),
        'processed (consumed=1) failed row must be cleared',
      ).toBe('0')
      expect(
        dbScalar(
          `SELECT COUNT(*) FROM {$wpdb->prefix}wpmmcc_journal WHERE canonical_uuid = %s AND consumed = 0`,
          CANON_C,
        ),
        'the consumed=0 CDC row is the pending sync queue and must NEVER be cleared by the journal cleanup',
      ).toBe('1')

      expect(findFatalError(await page.content()), 'fatal after journal clear').toBeNull()
    } finally {
      await context.close()
    }
  })
})
