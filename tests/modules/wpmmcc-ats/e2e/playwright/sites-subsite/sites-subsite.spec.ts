import { test, expect, type Page } from '@playwright/test'
import { execFileSync } from 'node:child_process'

// 批 O2 / U-5 (12号 §27): the Sites page UI binding → discovery →
// auto-writeback → SUBSITE public frontend, in ONE serial spec.
//
// Stack: local-first (lab WP multisite, blog 2 = /en/ + loopback client +
// mock translate API) — launched by run-playwright-sites-subsite.sh, which
// owns every base URL and credential below. The runner provisions ONLY the
// translation component; the site binding is driven here through the
// product-true Sites-page modal. Review mode stays OFF: translations
// auto-writeback (the review queue is the review-loop lane's contract).
//
// The subsite gate (run-automatic-subsite-translation-gate.sh) already
// covers the WP-only closed loop via REST; this lane closes U-5's gap —
// the Sites-page UI bind leg and the client-driven subsite writeback leg.

const CLIENT_BASE = (process.env.WPTSALL_SITES_SUBSITE_CLIENT_BASE ?? '').replace(/\/+$/, '')
const WP_BASE = (process.env.SITES_SUBSITE_WP_BASE ?? '').replace(/\/+$/, '')
const WP_TOKEN = process.env.SITES_SUBSITE_WP_TOKEN ?? ''
const ROUTE_SECRET = process.env.SITES_SUBSITE_ROUTE_SECRET ?? ''
const WP_RELATION_ID = Number(process.env.SITES_SUBSITE_WP_RELATION_ID ?? 0)
const WP_CONTAINER = process.env.SITES_SUBSITE_WP_CONTAINER ?? 'wptsall-wp-lab-wordpress-test-1'

test.describe.configure({ mode: 'serial' })

if (!CLIENT_BASE || !WP_BASE || !WP_TOKEN || !ROUTE_SECRET) {
  test.skip(true, 'lane env missing — run via run-playwright-sites-subsite.sh')
}

function wpEval(php: string): string {
  return execFileSync(
    'docker',
    [
      'exec',
      '-e',
      'WPTSALL_LAB=1',
      WP_CONTAINER,
      'wp',
      '--allow-root',
      '--path=/var/www/html',
      'eval',
      php,
    ],
    { encoding: 'utf-8', timeout: 60_000 },
  )
}

async function clickRunOnce(page: Page) {
  await page.click('nav button:has-text("Overview")')
  await expect(page.locator('h2:has-text("Overview")').first()).toBeVisible()
  await page.click('[data-testid="overview-run-once"]')
  // The worker-start preflight confirm panel (批 M contract): bridge it the
  // same way the desktop journey does — click through when it appears.
  const preflightDeadline = Date.now() + 45_000
  while (Date.now() < preflightDeadline) {
    const confirm = page.locator('[data-testid="overview-preflight-continue"]')
    if ((await confirm.count()) && (await confirm.isEnabled())) {
      await confirm.click()
      break
    }
    await new Promise((r) => setTimeout(r, 500))
  }
}

// Poll the WP-side mapping for the targeted source post on the subsite
// relation until the writeback lands (review OFF: the writeback happens
// inside the run-once, no client queue to poll).
function waitForSubsiteMapping(
  sourcePostId: number,
  timeoutMs = 300_000,
): { targetPostId: number } | null {
  const deadline = Date.now() + timeoutMs
  const php =
    `global $wpdb; $rid = ${WP_RELATION_ID}; $sid = ${sourcePostId};` +
    `$t = function_exists('wptsall_table') ? wptsall_table('post_mappings') : $wpdb->prefix . 'wptsall_post_mappings';` +
    `$row = $wpdb->get_row($wpdb->prepare("SELECT target_post_id FROM {$t} WHERE relation_id = %d AND source_post_id = %d AND target_post_id > 0 ORDER BY id DESC LIMIT 1", $rid, $sid), ARRAY_A);` +
    `if ($row) { echo (string) $row['target_post_id']; }`
  while (Date.now() < deadline) {
    const out = wpEval(php)
      .split('\n')
      .map((l) => l.trim())
      .filter((l) => /^\d+$/.test(l))
      .pop()
    const targetId = Number(out ?? 0)
    if (targetId > 0) return { targetPostId: targetId }
    execFileSync('sleep', ['3'])
  }
  return null
}

test('Sites page bind → discovery → run-once → subsite writeback → public frontend markers', async ({ page, request }) => {
  // Two run-once waves (discovery flood + targeted outbox post) plus the
  // writeback settle — up to 20 minutes.
  test.setTimeout(1_200_000)

  // ── Leg 1: Sites page UI bind (the product-true flow this lane owns) ──
  await page.goto(`${CLIENT_BASE}/`, { waitUntil: 'domcontentloaded' })
  await page.click('nav button:has-text("Sites")')
  await expect(page.locator('h2:has-text("Sites")').first()).toBeVisible()
  await page.click('[data-testid="sites-add-site"]')
  await expect(page.locator('[data-testid="sites-modal-url"]')).toBeVisible()
  await page.fill('[data-testid="sites-modal-url"]', WP_BASE)
  await page.fill('[data-testid="sites-modal-token"]', WP_TOKEN)
  await page.fill('[data-testid="sites-modal-route-secret"]', ROUTE_SECRET)
  await page.click('[data-testid="sites-modal-save"]')
  await expect(page.locator('text=Token saved')).toBeVisible({ timeout: 30_000 })
  // The bound site row is listed with the WP domain.
  await expect(page.locator(`td:has-text("${WP_BASE}")`).first()).toBeVisible({
    timeout: 30_000,
  })

  // ── Leg 2: discovery bootstrap via the Tasks page ──────────────────────
  await page.click('nav button:has-text("Tasks")')
  await expect(page.locator('h2:has-text("Tasks")').first()).toBeVisible()
  await page.getByRole('button', { name: 'Discovery', exact: true }).click()
  await page.click('[data-testid="tasks-bootstrap-discovery"]')
  await expect(page.locator('text=Discovery tasks refreshed from site relations')).toBeVisible({
    timeout: 120_000,
  })

  // ── Leg 3: insert the targeted source post, then run once ──────────────
  // Same 批 O1 contract as the review-loop lane: the discovery listing is
  // ID-ASC and FSE-flooded, so an ordinary post only reaches the loop via
  // the outbox drain lane. The dispatcher queues one post_created event per
  // matching active relation — the subsite relation's (WP_RELATION_ID) copy
  // is the one that writebacks onto blog 2. The runner's sweep leaves the
  // fresh event at the claim head.
  const stamp = Date.now()
  const postSlug = `sites-subsite-post-${stamp}`
  const insertPhp =
    '$pid = wp_insert_post(array(' +
    '  "post_type" => "post", "post_status" => "publish", "post_author" => 1,' +
    `  "post_title" => "Sites Subsite Target Post " . ${stamp},` +
    `  "post_name" => "${postSlug}",` +
    `  "post_content" => "<p>Sites subsite target source content at ${stamp}. This paragraph carries enough realistic words for a provider round trip and the subsite public frontend marker verification on blog 2.</p>",` +
    '), true);' +
    'echo wp_json_encode(array("post_id" => (int) $pid, "is_error" => is_wp_error($pid)));'
  const insertOut =
    wpEval(insertPhp)
      .split('\n')
      .map((l) => l.trim())
      .filter((l) => l.startsWith('{'))
      .pop() ?? '{}'
  const inserted = JSON.parse(insertOut) as { post_id: number; is_error: boolean }
  expect(inserted.is_error, 'target source post must insert cleanly').toBeFalsy()
  expect(inserted.post_id, 'target source post must have an id').toBeGreaterThan(0)

  await clickRunOnce(page)
  // Review mode is OFF: the run-once translates + writebacks directly. The
  // discovery flood (FSE shells for every relation) is expected collateral —
  // wait for the targeted post's SUBSITE mapping to appear.
  const mapping = waitForSubsiteMapping(inserted.post_id)
  expect(
    mapping,
    `the targeted post must write back onto blog 2 via relation ${WP_RELATION_ID} (post ${inserted.post_id})`,
  ).toBeTruthy()

  // ── Leg 4: subsite public frontend (200 + markers) ─────────────────────
  // The writeback target is a real published post on blog 2; its permalink
  // (resolved inside switch_to_blog) is the subsite's public URL. The
  // runner's prep flush keeps the canonical /en/{slug}/ form routable.
  const permalinkPhp =
    `switch_to_blog(2); $p = get_post(${mapping!.targetPostId}); $u = $p ? (string) get_permalink($p->ID) : ""; $t = $p ? (string) $p->post_title : ""; $s = $p ? (string) $p->post_status : ""; restore_current_blog();` +
    'echo wp_json_encode(array("url" => $u, "title" => $t, "status" => $s));'
  const permalinkOut =
    wpEval(permalinkPhp)
      .split('\n')
      .map((l) => l.trim())
      .filter((l) => l.startsWith('{'))
      .pop() ?? '{}'
  const target = JSON.parse(permalinkOut) as { url: string; title: string; status: string }
  expect(target.status, 'the subsite target post must be published').toBe('publish')
  expect(target.url, 'the subsite target permalink must resolve').not.toBe('')
  expect(
    /【[a-z]{2}_[A-Z]{2}】/.test(target.title),
    `the subsite target post title must carry translation markers: ${target.title}`,
  ).toBeTruthy()
  const front = await request.get(target.url)
  expect(front.status(), `the subsite page must be public: ${target.url}`).toBe(200)
  const body = await front.text()
  expect(
    /【[a-z]{2}_[A-Z]{2}】/.test(body),
    `the translation must be visible on the subsite public frontend with markers: ${target.url}`,
  ).toBeTruthy()
})
