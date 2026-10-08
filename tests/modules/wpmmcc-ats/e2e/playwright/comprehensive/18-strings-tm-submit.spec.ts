/**
 * WP Admin: Strings scan/save/delete + Translation Memory add (admin-post).
 *
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-strings-tm
 *
 * opus5 P-2: fixtures are self-owned. String rows come from this spec's
 * own scan submit (never "whatever the lab had"), and the TM form's
 * language options come from two `p2fx_` languages seeded in beforeAll
 * through the Languages admin-post surface and deleted in afterAll —
 * every old "empty lab" skip is now a hard expectation.
 *
 * catalog: WP-CLASS-Translation_Memory_Page
 * oracle: L2
 *
 * Blind-spot closure (doc 28 §四/UI-28-01, UI-28-02): the previous version
 * wrapped the scan redirect in try/catch, so when the nested-form bug made
 * every submit land on ?deleted=1, the spec silently "found no rows",
 * skipped the save, and the suite stayed green while production data
 * was being destroyed. Every redirect here is now a hard URL assertion:
 *   - scan  => page=wptsall-strings&scanned=1   (dead handler = fail)
 *   - save  => page=wptsall-strings&updated=N   (delete-swap = fail)
 *   - delete=> page=wptsall-strings&deleted=1   (followed by row-count
 *              checks: -1 after delete, restored after re-scan)
 */
import { test, expect, type Page } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'
import { cleanupOwned, seedOwnedLanguage, submitAdminPost } from './lib/fixture-owner'

const STRINGS_URL = `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-strings`

/** The big save form (outer form) on the Strings page. */
function stringsSaveForm(page: Page) {
  return page.locator('form[action*="admin-post.php"]').filter({
    has: page.locator('input[name="action"][value="wptsall_strings_save"]'),
  })
}

/** Rows currently rendered in the save form's table. */
async function rowCount(page: Page): Promise<number> {
  return stringsSaveForm(page).locator('tbody tr').count()
}

/**
 * Submit an admin-post form and HARD-assert the landing URL.
 * No try/catch: a dead handler (blank admin-post.php) or a wrong
 * redirect is a failure, never a "no rows" skip.
 */
async function submitAdminPostAndAssertUrl(
  page: Page,
  action: string,
  expectedUrl: RegExp,
): Promise<void> {
  const form = page.locator('form[action*="admin-post.php"]').filter({
    has: page.locator(`input[name="action"][value="${action}"]`),
  })
  await expect(form.first()).toBeVisible({ timeout: 20_000 })
  const submit = form.first().locator('button[type="submit"], input[type="submit"]').first()
  // Pre-register the landing waiter (see delete test note): third-party
  // admin JS scrubs our query params ~0.3–0.7s after landing.
  const landing = page.waitForURL(expectedUrl, { timeout: 45_000, waitUntil: 'commit' })
  const postPromise = page.waitForResponse(
    (res) => res.url().includes('/admin-post.php') && res.request().method() === 'POST',
    { timeout: 45_000 },
  )
  await submit.click()
  const res = await postPromise
  expect(res.status(), `admin-post ${action} HTTP status`).toBeLessThan(500)
  await landing
  expect(findFatalError(await page.content())).toBeNull()
}

test.describe.configure({ mode: 'serial' })

test.describe('18 Strings / TM admin-post submit', () => {
  test.setTimeout(180_000)

  /** opus5 P-2: the TM form's language options are self-owned. */
  let ownedSrcLang = ''
  let ownedTgtLang = ''
  /** opus5 P-2: the TM entry the spec adds is its own fixture — deleted in afterAll. */
  let tmStamp = ''

  test.beforeAll(async ({ browser }, testInfo) => {
    // Two admin-post language seeds need more than the 30s hook default.
    testInfo.setTimeout(240_000)
    const page = await browser.newPage()
    try {
      await wpLogin(page)
      ownedSrcLang = await seedOwnedLanguage(page, { nameSuffix: '18-tm-src' })
      ownedTgtLang = await seedOwnedLanguage(page, { nameSuffix: '18-tm-tgt' })
    } finally {
      await page.close()
    }
  })

  test.afterAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(240_000)
    const page = await browser.newPage()
    try {
      await wpLogin(page)
      await cleanupOwned(page)
      // P-2: the TM entry is test-owned data — delete it via the product's
      // own admin-post delete form (row-parsed id + nonce, like the UI).
      if (tmStamp) {
        await page.goto(
          `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-tm&s=${encodeURIComponent(tmStamp)}`,
          { waitUntil: 'domcontentloaded', timeout: 60_000 },
        )
        const row = page.locator('table tr', { hasText: tmStamp }).first()
        if ((await row.count()) > 0) {
          const delForm = row.locator(
            'form:has(input[name="action"][value="wptsall_tm_delete"])',
          )
          if ((await delForm.count()) > 0) {
            const delNonce = await delForm.locator('input[name="_wpnonce"]').first().inputValue()
            const delId = await delForm.locator('input[name="id"]').first().inputValue()
            const r = await submitAdminPost(page, {
              _wpnonce: delNonce,
              action: 'wptsall_tm_delete',
              id: delId,
            })
            if (!/page=wptsall-tm&deleted=1/.test(r.finalUrl)) {
              console.warn(`[spec-18] TM entry ${tmStamp} delete landed on ${r.finalUrl}`)
            }
          }
        }
      }
    } finally {
      await page.close()
    }
  })

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Strings: Scan redirects with scanned=1 (handler is registered and runs)', async ({
    page,
  }) => {
    await page.goto(STRINGS_URL, { waitUntil: 'domcontentloaded' })
    expect(findFatalError(await page.content())).toBeNull()

    // UI-28-02 pin: the scan button's action must be registered; a dead
    // admin_post handler leaves a blank admin-post.php page and never
    // returns to page=wptsall-strings&scanned=1.
    await submitAdminPostAndAssertUrl(
      page,
      'wptsall_strings_scan',
      /page=wptsall-strings.*[?&]scanned=1/,
    )
  })

  test('Strings: Save Translations persists a cell value WITHOUT deleting rows', async ({
    page,
  }) => {
    await page.goto(STRINGS_URL, { waitUntil: 'domcontentloaded' })
    expect(findFatalError(await page.content())).toBeNull()

    // UI-28-01 structural pin: delete must be a GET link, NOT a nested form
    // (nested <form> tags are dropped by the HTML parser; their action/id
    // hidden inputs used to land inside this save form and PHP last-wins
    // turned every submit into handle_delete(last row)).
    const saveForm = stringsSaveForm(page)
    await expect(saveForm).toBeVisible({ timeout: 20_000 })
    await expect(
      saveForm.locator('input[name="action"][value="wptsall_string_delete"]'),
    ).toHaveCount(0)

    // opus5 P-2: string rows are SELF-OWNED — run the product's scan first
    // so the table is guaranteed non-empty; an empty table is a product
    // break, never an "empty lab" skip.
    await submitAdminPostAndAssertUrl(
      page,
      'wptsall_strings_scan',
      /page=wptsall-strings.*[?&]scanned=1/,
    )
    const cell = saveForm.locator('input[type="text"][name*="[tr]"]').first()
    const rowsBefore = await rowCount(page)
    expect(rowsBefore, 'scan must register string rows').toBeGreaterThan(0)

    // opus5 P-2: the cell belongs to a SHARED lab row (row inventory is the
    // contract; the save surface itself is what is under test) — capture the
    // original value and restore it through the same save flow at the end,
    // mirroring spec 20's Woo title restore, so repeated runs never
    // accumulate stamped values in lab rows.
    const cellName = await cell.getAttribute('name')
    expect(cellName).toBeTruthy()
    const originalValue = await cell.inputValue()

    const stamp = `e2e-str-${Date.now().toString(36)}`
    await cell.fill(stamp)

    const saveBtn = page.getByRole('button', { name: /Save Translations|保存/i }).first()
    await expect(saveBtn).toBeVisible({ timeout: 15_000 })
    // Register the landing waiter BEFORE clicking: third-party admin JS in
    // the lab stack re-navigates to the canonical page URL ~0.3–0.7s after
    // landing (strips our query params), so a waiter registered after the
    // click can miss the window (observed in the delete test trace).
    const landing = page.waitForURL(/page=wptsall-strings.*[?&]updated=\d+/, {
      timeout: 45_000,
      waitUntil: 'commit',
    })
    const postPromise = page.waitForResponse(
      (res) => res.url().includes('/admin-post.php') && res.request().method() === 'POST',
      { timeout: 45_000 },
    )
    await saveBtn.click()
    const res = await postPromise
    expect(res.status()).toBeLessThan(500)
    // Hard assert: the save landed on updated=N, NOT deleted=1 (the old
    // delete-swap bug landed here with one row missing).
    await landing
    expect(findFatalError(await page.content())).toBeNull()

    await page.goto(STRINGS_URL, { waitUntil: 'domcontentloaded' })
    await expect(page.locator(`input[value="${stamp}"]`).first()).toBeVisible({ timeout: 15_000 })
    // Row inventory unchanged: save must never remove rows.
    expect(await rowCount(page)).toBe(rowsBefore)

    // opus5 P-2: restore the shared row's original value through the same
    // save flow (value-residue closure; mirrors spec 20's Woo title restore).
    const restoreCell = stringsSaveForm(page).locator(`input[name="${cellName}"]`)
    if ((await restoreCell.count()) > 0) {
      await restoreCell.fill(originalValue)
      const landingRestore = page.waitForURL(/page=wptsall-strings.*[?&]updated=\d+/, {
        timeout: 45_000,
        waitUntil: 'commit',
      })
      const postRestore = page.waitForResponse(
        (res) => res.url().includes('/admin-post.php') && res.request().method() === 'POST',
        { timeout: 45_000 },
      )
      await saveBtn.click()
      const resRestore = await postRestore
      expect(resRestore.status()).toBeLessThan(500)
      await landingRestore
      await page.goto(STRINGS_URL, { waitUntil: 'domcontentloaded' })
      expect(await rowCount(page)).toBe(rowsBefore)
      await expect(
        stringsSaveForm(page).locator(`input[name="${cellName}"]`),
      ).toHaveValue(originalValue)
    }
  })

  test('Strings: Delete link removes exactly its own row (and scan restores it)', async ({
    page,
  }) => {
    await page.goto(STRINGS_URL, { waitUntil: 'domcontentloaded' })
    expect(findFatalError(await page.content())).toBeNull()

    // opus5 P-2: rows are SELF-OWNED — scan first so a missing delete link
    // is a product break, never a skip.
    await submitAdminPostAndAssertUrl(
      page,
      'wptsall_strings_scan',
      /page=wptsall-strings.*[?&]scanned=1/,
    )
    const deleteLinks = page.locator('a[href*="action=wptsall_string_delete"]')
    expect(
      await deleteLinks.count(),
      'scan must yield deletable string rows',
    ).toBeGreaterThan(0)

    // Structural pin: GET link on admin-post.php carrying action + nonce.
    const href = await deleteLinks.last().getAttribute('href')
    expect(href).toContain('admin-post.php')
    expect(href).toContain('action=wptsall_string_delete')
    expect(href).toMatch(/_wpnonce=[a-f0-9]+/)

    const rowsBefore = await rowCount(page)
    const lastRowId = await deleteLinks
      .last()
      .getAttribute('href')
      .then((h) => h!.match(/[?&]id=(\d+)/)?.[1])

    // Auto-accept the confirm() dialog; register the landing waiter BEFORE
    // the click (waitUntil 'commit' resolves the moment the navigation
    // commits) because third-party admin JS in the lab re-navigates to the
    // canonical page URL ~0.3–0.7s later, stripping the ?deleted=1 param —
    // a post-click waiter observed only the scrub and timed out.
    const landing = page.waitForURL(/page=wptsall-strings.*[?&]deleted=1/, {
      timeout: 45_000,
      waitUntil: 'commit',
    })
    const dialogHandler = (d: import('@playwright/test').Dialog) => d.accept()
    page.once('dialog', dialogHandler)
    await deleteLinks.last().click()
    await landing

    // Stable, DB-backed assertions on a fresh load (the transient notice and
    // URL param are scrub-prone; row inventory is the real contract).
    await page.goto(STRINGS_URL, { waitUntil: 'domcontentloaded' })
    expect(findFatalError(await page.content())).toBeNull()
    // Exactly the clicked row gone. The page auto-registers site_title /
    // site_tagline on every render (String_Translation_Service::register
    // dedupes by key), so if the deleted row was one of those, a new row
    // takes its place and the total stays flat — hence bounds, not an
    // exact count. The invariant under test: no collateral row loss.
    const rowsAfter = await rowCount(page)
    expect(rowsAfter).toBeGreaterThanOrEqual(rowsBefore - 1)
    expect(rowsAfter).toBeLessThanOrEqual(rowsBefore)
    if (lastRowId) {
      await expect(
        stringsSaveForm(page).locator(`input[name^="rows[${lastRowId}]"]`),
      ).toHaveCount(0)
    }

    // Self-heal round-trip: scan re-registers site strings (audit-verified),
    // restoring the row inventory.
    await submitAdminPostAndAssertUrl(
      page,
      'wptsall_strings_scan',
      /page=wptsall-strings.*[?&]scanned=1/,
    )
    // Scan re-registers site strings (audit: the deleted lab row
    // self-restored this way), so inventory may heal back toward
    // rowsBefore; it must never shrink below the post-delete level.
    expect(await rowCount(page)).toBeGreaterThanOrEqual(rowsAfter)
  })

  test('TM: Add entry via admin-post and appear in list', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-tm`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()

    const form = page.locator('form[action*="admin-post.php"]').filter({
      has: page.locator('input[name="action"][value="wptsall_tm_save"]'),
    })
    await expect(form).toBeVisible()

    // opus5 P-2: language options are SELF-OWNED (two `p2fx_` languages
    // seeded in beforeAll via the Languages admin-post surface) — missing
    // options are a product break, never an "empty lab" skip.
    const srcSel = form.locator('select[name="source_lang"]')
    const tgtSel = form.locator('select[name="target_lang"]')
    await expect(srcSel.locator(`option[value="${ownedSrcLang}"]`)).toHaveCount(1)
    await expect(tgtSel.locator(`option[value="${ownedTgtLang}"]`)).toHaveCount(1)
    await srcSel.selectOption(ownedSrcLang)
    await tgtSel.selectOption(ownedTgtLang)

    const stamp = `e2e-tm-${Date.now().toString(36)}`
    tmStamp = stamp // registered for the afterAll self-cleanup
    await form.locator('input[name="source_text"]').fill(`src ${stamp}`)
    await form.locator('input[name="target_text"]').fill(`tgt ${stamp}`)
    await form.locator('input[name="domain"]').fill('e2e')

    const submit = form.locator('button[type="submit"], input[type="submit"]').first()
    const postPromise = page.waitForResponse(
      (res) => res.url().includes('/admin-post.php') && res.request().method() === 'POST',
      { timeout: 45_000 },
    )
    await submit.click()
    const postRes = await postPromise
    expect(postRes.status()).toBeLessThan(500)
    await page.waitForLoadState('domcontentloaded')
    expect(findFatalError(await page.content())).toBeNull()

    // WP admin may strip ?updated=1 via canonical URL replace — assert list persistence.
    await page.goto(
      `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-tm&s=${encodeURIComponent(stamp)}`,
      { waitUntil: 'domcontentloaded' },
    )
    // opus5 P-2: the persisted TM entry IS the assertion target — a missing
    // record is a product break, never a "run migrations / reactivate" skip.
    const listed = page.locator('table.wp-list-table').getByText(stamp)
    await expect(
      listed.first(),
      'saved TM entry must appear in the list',
    ).toBeVisible({ timeout: 15_000 })
  })
})
