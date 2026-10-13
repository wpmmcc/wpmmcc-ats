import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { execFileSync } from 'node:child_process'

// 批 O1 / U-3 (12号 §26): the review dual-UI closed loop in ONE serial spec.
// wp-admin entry (task-modal + translation editor) → client review UI
// (review-mode toggle → pending queue → review modal → approve & writeback →
// batch) → public frontend visibility (translated permalink carries markers).
//
// Stack: local-first (lab WP + loopback client + mock translate API) —
// launched by run-playwright-review-loop.sh, which owns every base URL and
// credential below. No website / server :8787 / PG in this lane.
//
// The three-system legacy lane (client-review-approve.journey.e2e.spec.ts)
// already covers the client review loop against the website account; this
// spec closes U-3's remaining gap — the wp-admin entry legs + the frontend
// visibility leg — in a single serial thread.

const CLIENT_BASE = (process.env.WPTSALL_REVIEW_LOOP_CLIENT_BASE ?? '').replace(/\/+$/, '')
const WP_BASE = (process.env.REVIEW_LOOP_WP_BASE ?? '').replace(/\/+$/, '')
const ADMIN_USER = process.env.REVIEW_LOOP_WP_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.REVIEW_LOOP_WP_ADMIN_PASS ?? 'admin123'
const WP_RELATION_ID = Number(process.env.REVIEW_LOOP_WP_RELATION_ID ?? 0)
const VIRTUAL_RELATION_ID = Number(process.env.REVIEW_LOOP_VIRTUAL_RELATION_ID ?? 0)
const WP_CONTAINER = process.env.REVIEW_LOOP_WP_CONTAINER ?? 'wptsall-wp-lab-wordpress-test-1'

test.describe.configure({ mode: 'serial' })

if (!CLIENT_BASE || !WP_BASE) {
  test.skip(true, 'lane env missing — run via run-playwright-review-loop.sh')
}

interface PendingItem {
  id: number
  job_id: number
  relation_id: number
  wp_object_id: number
  object_type: string
  wp_object_subtype: string
  task_type: string
}

async function clientApi(
  request: APIRequestContext,
  method: string,
  path: string,
  body?: unknown,
): Promise<Record<string, any>> {
  const res = await request.fetch(`${CLIENT_BASE}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    data: body === undefined ? '{}' : JSON.stringify(body),
  })
  const json = (await res.json().catch(() => null)) as Record<string, any> | null
  expect(json, `${method} ${path} HTTP ${res.status()}`).toBeTruthy()
  return json as Record<string, any>
}

async function pendingItems(request: APIRequestContext): Promise<PendingItem[]> {
  const json = await clientApi(request, 'GET', '/api/items/pending-review?limit=500')
  const items = ((json.data ?? {}) as Record<string, any>).items ?? []
  return items as PendingItem[]
}

async function waitForPendingItems(request: APIRequestContext, timeoutMs = 300_000) {
  const deadline = Date.now() + timeoutMs
  let items: PendingItem[] = []
  while (Date.now() < deadline) {
    items = await pendingItems(request)
    if (items.length > 0) return items
    await new Promise((r) => setTimeout(r, 2_000))
  }
  return items
}

// The pending list fills progressively (translations sync one by one), and
// the FIRST item can be an i18n-path item (product / wp_global_styles)
// whose approve the mock provider cannot satisfy. Wait for a rich_html
// item before driving the review modal.
const RICH_HTML_SUBTYPES = ['wp_navigation', 'wp_template', 'wp_template_part', 'post', 'page']

async function waitForRichHtmlItem(request: APIRequestContext, timeoutMs = 300_000) {
  const deadline = Date.now() + timeoutMs
  let items: PendingItem[] = []
  while (Date.now() < deadline) {
    items = await pendingItems(request)
    const hit = items.find((it) => RICH_HTML_SUBTYPES.includes(it.wp_object_subtype))
    if (hit) return { item: hit, items }
    await new Promise((r) => setTimeout(r, 2_000))
  }
  return { item: items[0], items }
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

async function wpLogin(page: Page) {
  await page.goto(
    `${WP_BASE}/wp-login.php?redirect_to=${encodeURIComponent(`${WP_BASE}/wp-admin/`)}`,
    { waitUntil: 'domcontentloaded' },
  )
  await page.fill('#user_login', ADMIN_USER)
  await page.fill('#user_pass', ADMIN_PASS)
  await page.click('#wp-submit')
  await page.waitForSelector('#wpadminbar', { timeout: 30_000 })
}

test('wp-admin task modal + translation editor entry → client review UI → public frontend markers', async ({ page, request }) => {
  // Full closed loop in one serial test: two run-once waves (discovery
  // flood + targeted outbox post), a modal approve, a batch approve with a
  // reject fallback for un-approvable i18n items, and the frontend check —
  // up to 30 minutes.
  test.setTimeout(1_800_000)

  // ── Leg 1: wp-admin task-modal entry (the real pipeline entry UI) ──────
  await wpLogin(page)
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=wptsall-tasks&tab=monitoring`, {
    waitUntil: 'domcontentloaded',
  })
  const addBtn = page.locator('#wptsall-add-task-btn')
  if (await addBtn.count()) {
    await addBtn.first().click()
    await expect(page.locator('#wptsall-add-task-modal')).toBeVisible()
    const relationOptions = page.locator('#add-task-relation option[value]:not([value=""])')
    if (await relationOptions.count()) {
      let relationValue = ''
      if (WP_RELATION_ID) {
        const preferred = await page
          .locator(`#add-task-relation option[value="${WP_RELATION_ID}"]`)
          .count()
        if (preferred) relationValue = String(WP_RELATION_ID)
      }
      if (!relationValue) {
        relationValue = (await relationOptions.first().getAttribute('value')) ?? ''
      }
      expect(relationValue).not.toBe('')
      const monitorRespPromise = page.waitForResponse(
        (resp) =>
          resp.request().method() === 'POST' &&
          resp.url().includes('/wp-json/wptsall/v2/tasks/monitor/start'),
      )
      await page.selectOption('#add-task-relation', relationValue)
      await page.click('#wptsall-create-task-btn')
      const monitorResp = await monitorRespPromise
      expect(
        monitorResp.ok(),
        `HTTP ${monitorResp.status()} from ${monitorResp.url()}`,
      ).toBeTruthy()
    }
  }

  // ── Leg 2: translation editor entry + contract (wp-admin surface) ─────
  const posts = await request.get(`${WP_BASE}/wp-json/wp/v2/posts?per_page=1&_fields=id`)
  const postsJson = (await posts.json().catch(() => [])) as Array<{ id: number }>
  expect(postsJson.length, 'lab WP should expose at least one post via REST').toBeGreaterThan(0)
  const sourcePostId = postsJson[0].id

  // Prefer the true pending-translations entry ("Translate" button) when the
  // list has rows; fall back to the editor's direct URL (same page, same
  // contract) so the leg is deterministic regardless of pending state.
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=wptsall-pending`, {
    waitUntil: 'domcontentloaded',
  })
  const translateBtn = page.locator('a.button:has-text("Translate")').first()
  if (await translateBtn.count()) {
    await translateBtn.click()
  } else {
    const relationParam = WP_RELATION_ID ? `&relation_id=${WP_RELATION_ID}` : ''
    await page.goto(
      `${WP_BASE}/wp-admin/admin.php?page=wptsall-translate&source_post_id=${sourcePostId}${relationParam}`,
      { waitUntil: 'domcontentloaded' },
    )
  }
  await expect(page.locator('#wptsall-translation-editor')).toBeVisible({ timeout: 30_000 })
  await expect(page.locator('#wptsall-source-fields')).toBeVisible()
  await expect(page.locator('#wptsall-save-translation')).toBeVisible()
  await expect(page.locator('#wptsall-auto-translate')).toBeVisible()

  // ── Leg 3: client Settings — review mode ON via UI ────────────────────
  await page.goto(CLIENT_BASE, { waitUntil: 'domcontentloaded' })
  await page.click('nav button:has-text("Settings")')
  await expect(page.locator('h2:has-text("Settings")').first()).toBeVisible()
  // The review-mode toggle lives in the Worker section (Proxy|Worker|Log|Access).
  await page.getByRole('button', { name: 'Worker', exact: true }).click()
  const reviewToggle = page.locator('[data-testid="settings-review-toggle"]')
  await expect(reviewToggle).toBeVisible()
  if ((await reviewToggle.getAttribute('aria-label')) !== 'Disable Review') {
    await reviewToggle.click()
  }
  await expect(reviewToggle).toHaveAttribute('aria-label', 'Disable Review')
  await page.getByRole('button', { name: 'Save Worker' }).click()
  await expect(page.locator('text=Worker Saved')).toBeVisible({ timeout: 30_000 })
  const config = await clientApi(request, 'GET', '/api/worker/config')
  expect(config?.data?.review_mode, 'review_mode must persist after Save Worker').toBe(true)

  // ── Leg 4: discovery bootstrap + run-once via client UI ───────────────
  await page.click('nav button:has-text("Tasks")')
  await expect(page.locator('h2:has-text("Tasks")').first()).toBeVisible()
  await page.getByRole('button', { name: 'Discovery', exact: true }).click()
  await page.click('[data-testid="tasks-bootstrap-discovery"]')
  await expect(page.locator('text=Discovery tasks refreshed from site relations')).toBeVisible({
    timeout: 120_000,
  })

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
  const items = await waitForPendingItems(request)
  expect(
    items.length,
    'review mode must hold translated items in the pending queue (run-once output)',
  ).toBeGreaterThan(0)
  // Leg 5 approves one discovery item through the modal (the modal flow is
  // type-agnostic — the flood is FSE template shells because the
  // untranslated listing is ordered by post ID ASC and the lab's WP.com
  // import carries thousands of low-ID wp_navigation/wp_template posts, so
  // ordinary posts are unreachable through the listing). The targeted
  // routable post arrives via the outbox drain lane in Leg 6.5.
  // The pending list fills progressively and the first item can be an
  // i18n-path item (product / wp_global_styles) whose approve the mock
  // provider cannot satisfy (review.item_approve_failed "missing field
  // entries" — ledgered lane finding), so wait for a rich_html item.
  const { item: firstItem } = await waitForRichHtmlItem(request)

  // ── Leg 5: review modal leg — single item through the full review UI ──
  await page.click('nav button:has-text("Tasks")')
  await expect(page.locator('h2:has-text("Tasks")').first()).toBeVisible()
  await page.getByRole('button', { name: 'Pending', exact: true }).click()
  const row = page.locator(`[data-testid="pending-review-${firstItem.id}"]`)
  await expect(row).toBeVisible({ timeout: 30_000 })
  await row.click()
  // Review page: translated fields render + the approve control exists.
  await expect(page.locator('[data-testid="review-approve"]')).toBeVisible({ timeout: 30_000 })
  const fieldCount = await page.locator('[data-testid^="review-field-"]').count()
  expect(fieldCount, 'review modal should render translated fields').toBeGreaterThan(0)
  await page.click('[data-testid="review-approve"]')
  await expect(page.locator('text=Translation item approved')).toBeVisible({ timeout: 60_000 })
  await page.getByRole('button', { name: 'Back', exact: true }).click()
  await expect(page.locator('h2:has-text("Tasks")').first()).toBeVisible()

  // ── Leg 6: batch approve the remainder via UI ─────────────────────────
  let remaining = await pendingItems(request)
  if (remaining.length > 0) {
    await page.getByRole('button', { name: 'Pending', exact: true }).click()
    await page.click('[data-testid="pending-select-all"]')
    await expect(page.locator('[data-testid="batch-approve"]')).toBeEnabled()
    await page.click('[data-testid="batch-approve"]')
    // Full-spread discovery (~190 items across all relations) — the batch
    // writebacks dominate the drain time.
    const batchDeadline = Date.now() + 600_000
    while (Date.now() < batchDeadline) {
      remaining = await pendingItems(request)
      if (remaining.length === 0) break
      await new Promise((r) => setTimeout(r, 2_000))
    }
    if (remaining.length > 0) {
      // i18n-path items (product / wp_global_styles) cannot approve against
      // the mock provider (review.item_approve_failed "missing field
      // entries" — ledgered lane finding). The product-true disposition for
      // un-approvable items is the batch REJECT flow. The select-all toggle
      // can land on deselect when the batch-approve step left everything
      // selected, so drive the toggle until the reject button enables.
      for (let i = 0; i < 3; i++) {
        if (await page.locator('[data-testid="batch-reject"]').isEnabled()) break
        await page.click('[data-testid="pending-select-all"]')
        await new Promise((r) => setTimeout(r, 500))
      }
      await expect(page.locator('[data-testid="batch-reject"]')).toBeEnabled()
      await page.click('[data-testid="batch-reject"]')
      await page.click('[data-testid="task-confirm-reject-btn"]')
      const rejectDeadline = Date.now() + 120_000
      while (Date.now() < rejectDeadline) {
        remaining = await pendingItems(request)
        if (remaining.length === 0) break
        await new Promise((r) => setTimeout(r, 2_000))
      }
    }
    expect(
      remaining.length,
      'batch approve (+ reject for un-approvable i18n items) must drain the pending queue',
    ).toBe(0)
  }

  // ── Leg 6.5: targeted routable post via the outbox drain lane ─────────
  // The discovery content listing can never surface an ordinary post in
  // this lab (ID-ASC ordering + thousands of low-ID FSE shells), but the
  // outbox drain lane delivers a fresh post_created event in the SAME
  // run-once: insert a unique source post (the dispatcher queues one
  // outbox row per matching active relation; the runner's prep sweeps the
  // two lane relations' stale rows so the fresh event sits at the claim
  // head instead of starving behind hundreds of dead events), then run
  // once again and approve the virtual relation's copy through the same
  // review modal.
  const stamp = Date.now()
  const postSlug = `review-loop-post-${stamp}`
  const insertPhp =
    '$pid = wp_insert_post(array(' +
    '  "post_type" => "post", "post_status" => "publish", "post_author" => 1,' +
    `  "post_title" => "Review Loop Target Post " . ${stamp},` +
    `  "post_name" => "${postSlug}",` +
    `  "post_content" => "<p>Review loop target source content at ${stamp}. This paragraph carries enough realistic words for a provider round trip and the public frontend marker verification on the virtual site.</p>",` +
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

  await page.click('nav button:has-text("Overview")')
  await expect(page.locator('h2:has-text("Overview")').first()).toBeVisible()
  await page.click('[data-testid="overview-run-once"]')
  const preflight2 = Date.now() + 45_000
  while (Date.now() < preflight2) {
    const confirm = page.locator('[data-testid="overview-preflight-continue"]')
    if ((await confirm.count()) && (await confirm.isEnabled())) {
      await confirm.click()
      break
    }
    await new Promise((r) => setTimeout(r, 500))
  }
  // The targeted copy also arrives progressively — poll for the EXACT item
  // (virtual relation + post id + subtype) instead of accepting the first
  // non-empty list.
  const targetDeadline = Date.now() + 300_000
  let targetedItems: PendingItem[] = []
  let targetItem: PendingItem | undefined
  while (Date.now() < targetDeadline) {
    targetedItems = await pendingItems(request)
    targetItem = targetedItems.find(
      (it) =>
        it.wp_object_subtype === 'post' &&
        it.wp_object_id === inserted.post_id &&
        it.relation_id === VIRTUAL_RELATION_ID,
    )
    if (targetItem) break
    await new Promise((r) => setTimeout(r, 2_000))
  }
  expect(
    targetItem,
    `the targeted post must reach the pending queue via the outbox drain lane (relation ${VIRTUAL_RELATION_ID}, post ${inserted.post_id})`,
  ).toBeTruthy()

  await page.click('nav button:has-text("Tasks")')
  await expect(page.locator('h2:has-text("Tasks")').first()).toBeVisible()
  await page.getByRole('button', { name: 'Pending', exact: true }).click()
  const targetRow = page.locator(`[data-testid="pending-review-${targetItem!.id}"]`)
  await expect(targetRow).toBeVisible({ timeout: 30_000 })
  await targetRow.click()
  await expect(page.locator('[data-testid="review-approve"]')).toBeVisible({ timeout: 30_000 })
  await page.click('[data-testid="review-approve"]')
  await expect(page.locator('text=Translation item approved')).toBeVisible({ timeout: 60_000 })
  await page.getByRole('button', { name: 'Back', exact: true }).click()
  // Drain the rest via the API in server-cap chunks (100 per batch). The
  // UI batch flow is already proven end-to-end by Leg 6; here the UI list
  // can be stale relative to the second wave (select-all would submit
  // already-disposed ids and skip everything), so the cleanup is driven
  // directly against the queue the poll sees. The second run-once can
  // claim multiple flood iterations (~200 items beyond the targeted
  // copies) — the chunked writebacks need the wider window.
  {
    const drainDeadline = Date.now() + 600_000
    remaining = await pendingItems(request)
    while (remaining.length > 0 && Date.now() < drainDeadline) {
      for (let i = 0; i < remaining.length; i += 100) {
        await clientApi(request, 'POST', '/api/items/batch-approve', {
          ids: remaining.slice(i, i + 100).map((it) => it.id),
        })
      }
      await new Promise((r) => setTimeout(r, 2_000))
      remaining = await pendingItems(request)
    }
    expect(remaining.length, 'batch approve must drain the targeted copies').toBe(0)
  }
  const approvedItem = targetItem!

  // ── Leg 7: public frontend visibility of the approved translation ─────
  // The loop's writebacks span many post types; only a subset is
  // frontend-routable (P2's resolver contract filters CPT template shells —
  // wp_navigation/wp_template — for exactly this reason). The approved
  // targeted item IS an ordinary post on the virtual relation, so resolve
  // its writeback target directly; the gate's resolver sample stays as a
  // defensive fallback (same assertion strength as Stage 7P2). Either way
  // the public page must carry translation markers.
  let permalink = ''
  {
    // The writeback target is a real published post; its permalink already
    // carries the virtual site prefix + the site's permalink structure
    // (e.g. /en_us/blog/{slug}/ — the flat /{prefix}/{slug}/ guess 404s
    // when the source permalink structure has a /blog/ front segment).
    const mappingPhp =
      `global $wpdb; $rid = ${Number(approvedItem.relation_id)}; $sid = ${Number(approvedItem.wp_object_id)};` +
      `$t = function_exists('wptsall_table') ? wptsall_table('post_mappings') : $wpdb->prefix . 'wptsall_post_mappings';` +
      `$row = $wpdb->get_row($wpdb->prepare("SELECT target_post_id FROM {$t} WHERE relation_id = %d AND source_post_id = %d ORDER BY id DESC LIMIT 1", $rid, $sid), ARRAY_A);` +
      `$out = ''; if ($row && (int) $row['target_post_id'] > 0) {` +
      `  $target = get_post((int) $row['target_post_id']);` +
      `  if ($target) { $p = get_permalink($target->ID); if (is_string($p) && '' !== $p) { $out = $p; } }` +
      `}` +
      `if ('' !== $out) { echo $out; }`
    permalink =
      wpEval(mappingPhp)
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => /^https?:\/\//.test(l))
        .pop() ?? ''
  }
  if (!permalink) {
    // The gate's resolver: a safe, routable, translated virtual sample
    // (same script Stage 7P2 runs, mapped into the lab container at
    // /opt/wptsall-e2e/php/).
    const resolved = execFileSync(
      'docker',
      [
        'exec',
        '-e',
        'WPTSALL_LAB=1',
        WP_CONTAINER,
        'wp',
        '--allow-root',
        '--path=/var/www/html',
        'eval-file',
        '/opt/wptsall-e2e/php/resolve-virtual-frontend-target.php',
      ],
      { encoding: 'utf-8', timeout: 60_000 },
    )
    const jsonStart = resolved.indexOf('{')
    const resolvedJson = JSON.parse(resolved.slice(jsonStart, resolved.lastIndexOf('}') + 1)) as {
      ok?: boolean
      sample_path?: string
    }
    expect(resolvedJson.ok, 'virtual frontend resolver must find a target').toBe(true)
    permalink = `${WP_BASE}${resolvedJson.sample_path ?? ''}`
  }
  expect(permalink, 'a routable public URL must resolve for the writeback surface').not.toBe('')
  const front = await request.get(permalink)
  expect(front.status(), `translated page must be public: ${permalink}`).toBe(200)
  const body = await front.text()
  expect(
    /【[a-z]{2}_[A-Z]{2}】/.test(body),
    `the translation must be visible on the public frontend with markers: ${permalink}`,
  ).toBeTruthy()
})
