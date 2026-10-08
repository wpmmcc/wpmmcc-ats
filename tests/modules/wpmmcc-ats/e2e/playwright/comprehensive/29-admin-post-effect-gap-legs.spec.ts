/**
 * Admin-post service-effect legs for the four cataloged actions that had
 * L2 contract coverage only (U-4, 12号 §17 挂账 → 批 N1; catalog
 * wp-admin-write-paths.yaml: 41 admin_post entries, 8 partial — of those,
 * locale_save/clear already had legs in 02, strings_save/string_delete in
 * 18; the true gap is these four):
 *
 *   admin_post_wptsall_model_export  — submit → JSON download artifact
 *   admin_post_wptsall_model_import  — dry-run redirect msg + apply dialog →
 *                                      APPLIED msg + row-count stability
 *   admin_post_wptsall_wizard_step   — step 1→2 redirect + default_language
 *                                      persisted into wptsall_settings
 *   admin_post_wptsall_wizard_skip   — completed state + plugin-admin redirect
 *
 * Effect oracle pattern (L3): submit the REAL admin form → assert the
 * handler's redirect/msg effect → assert persistence via wp-cli option
 * reads or artifact content.
 *
 * Run (Lab):
 *   WP_BASE=http://127.0.0.1:9182 LAB_WP_CONTAINER=wptsall-wp-lab-wordpress-slot-b \
 *     bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 29
 */
import { test, expect, type Page } from '@playwright/test'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { wpLogin, WP_BASE_URL, findFatalError } from './helpers'
import { wpCli } from './wp-cli'

const BACKUP_PAGE = `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-models-backup`
const WIZARD_PAGE = `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-wizard`

async function adminPostForm(page: Page, action: string, extra?: { dryRun?: '0' | '1' }) {
  const forms = page.locator('form[action*="admin-post.php"]').filter({
    has: page.locator(`input[name="action"][value="${action}"]`),
  })
  if (extra?.dryRun) {
    // Disambiguate the two import forms (dry-run vs apply) by their hidden
    // dry_run value.
    const matching = forms.filter({
      has: page.locator(`input[name="dry_run"][value="${extra.dryRun}"]`),
    })
    await expect(matching).toHaveCount(1)
    return matching
  }
  await expect(forms).toHaveCount(1)
  return forms
}

function decodedUrl(url: string): string {
  try {
    return decodeURIComponent(url)
  } catch {
    return url
  }
}

function optionJson(name: string): Record<string, unknown> | null {
  const out = wpCli(`option get ${name} --format=json`)
  // The lab wp-cli emits PHP Warning lines ("[26-Sep-2026 ... UTC] PHP
  // Warning: Constant WP_DEBUG already defined ...") before the JSON
  // payload. With TWO or more warning lines, the old anchor scheme
  // (candidates = first '{' + first line-start '[', then min()) let the
  // SECOND warning's leading '[' beat the object payload's '{' — the slice
  // started mid-warning, JSON.parse failed, and every object read returned
  // null. Strip the warning lines first, then parse whatever remains
  // (object '{' or array '[' payloads both work).
  const cleaned = out
    .split('\n')
    .filter((l) => !/^\[[^\]]+\] PHP (Warning|Deprecated|Notice|Fatal error)/.test(l))
    .join('\n')
    .trim()
  try {
    return JSON.parse(cleaned) as Record<string, unknown>
  } catch {
    return null
  }
}

test.describe('29 Admin-post effect legs (U-4 gap: model backup + setup wizard)', () => {
  test.describe.configure({ mode: 'serial' })
  // 2026-09-27 slow-lab headroom: every leg pairs wpLogin + a backup-page
  // goto with 45s URL/download waiters — measured 24-32s per leg on the
  // accreted Lab, and the 30s default budget killed model_import apply
  // mid-flight (which also broke the serial group: the wizard legs behind
  // it went "did not run"). 120s per leg matches the waiters' own scale
  // (20-spec SLOW_ADMIN_LIST_GOTO_TIMEOUT precedent).
  test.setTimeout(120_000)

  let exportPath = ''
  let exportCount = 0

  test('model_export: submit → JSON download with schema + rows', async ({ page }) => {
    await wpLogin(page)
    await page.goto(BACKUP_PAGE, { waitUntil: 'domcontentloaded' })
    await expect(page.getByRole('heading', { name: 'Models Backup & Restore' })).toBeVisible()
    const before = findFatalError(await page.content())
    expect(before, 'no fatal on backup page').toBeNull()

    const form = await adminPostForm(page, 'wptsall_model_export')
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 45_000 }),
      form.locator('button[type="submit"]').click(),
    ])
    exportPath = path.join(os.tmpdir(), `wptsall-models-export-${Date.now()}.json`)
    await download.saveAs(exportPath)

    const payload = JSON.parse(fs.readFileSync(exportPath, 'utf-8')) as {
      schema: string
      count: number
      rows: unknown[]
    }
    // Artifact contract: the export the import legs below round-trip.
    expect(payload.schema).toBe('wptsall-models/1')
    expect(payload.count).toBeGreaterThanOrEqual(1)
    expect(payload.rows).toHaveLength(payload.count)
    exportCount = payload.count
  })

  test('model_import dry-run: upload export → DRY RUN redirect msg, zero applied', async ({ page }) => {
    test.skip(!exportPath, 'export artifact missing (prior leg failed)')
    await wpLogin(page)
    await page.goto(BACKUP_PAGE, { waitUntil: 'domcontentloaded' })

    const form = await adminPostForm(page, 'wptsall_model_import', { dryRun: '1' })
    await form.locator('input[name="backup_file"]').setInputFiles(exportPath)
    await Promise.all([
      page.waitForURL(/wptsall-models-backup/, { timeout: 45_000 }),
      form.locator('button[type="submit"]').click(),
    ])
    // Dry-run effect: redirect back with the DRY RUN summary in msg.
    expect(decodedUrl(page.url())).toContain('DRY RUN')
  })

  test('model_import apply: confirm dialog → APPLIED msg + re-export count stable', async ({ page }) => {
    test.skip(!exportPath, 'export artifact missing (prior leg failed)')
    await wpLogin(page)
    await page.goto(BACKUP_PAGE, { waitUntil: 'domcontentloaded' })

    page.on('dialog', (dialog) => void dialog.accept())
    const form = await adminPostForm(page, 'wptsall_model_import', { dryRun: '0' })
    await form.locator('input[name="backup_file"]').setInputFiles(exportPath)
    await Promise.all([
      page.waitForURL(/wptsall-models-backup/, { timeout: 45_000 }),
      form.locator('button[type="submit"]').click(),
    ])
    expect(decodedUrl(page.url())).toContain('APPLIED')

    // Service effect + persistence, artifact oracle: re-exporting after the
    // apply yields the SAME row count (rows updated in place, none
    // duplicated). The page's sprintf() subtitle never renders —
    // Admin_Page_Helper::render_header's second parameter is a module class
    // suffix, not display text — so the export artifact is the count truth.
    const exportForm = await adminPostForm(page, 'wptsall_model_export')
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 45_000 }),
      exportForm.locator('button[type="submit"]').click(),
    ])
    const rePath = path.join(os.tmpdir(), `wptsall-models-reexport-${Date.now()}.json`)
    await download.saveAs(rePath)
    const rePayload = JSON.parse(fs.readFileSync(rePath, 'utf-8')) as { count: number }
    expect(rePayload.count).toBe(exportCount)
  })

  test('wizard_step: step 1→2 redirect, default_language persisted into settings', async ({ page }) => {
    // Clean slate: the wizard state + target languages from earlier runs.
    wpCli('option delete wptsall_wizard_state')
    wpCli('option delete wptsall_wizard_target_languages')

    await wpLogin(page)
    await page.goto(WIZARD_PAGE, { waitUntil: 'domcontentloaded' })
    await expect(page.getByRole('heading', { name: 'WPTSALL Setup Wizard' })).toBeVisible()
    await expect(page.getByText('Welcome to WPTSALL')).toBeVisible()

    const stepForm = await adminPostForm(page, 'wptsall_wizard_step')
    await Promise.all([
      page.waitForURL(/wptsall-wizard.*step=2/, { timeout: 45_000 }),
      stepForm.locator('button[type="submit"]').click(),
    ])
    // Step-2 effect: the default-language form rendered.
    await expect(page.getByRole('heading', { name: 'Default language' })).toBeVisible()

    // Pick a language DIFFERENT from the current default (falls back to the
    // first option when the lab only has one language).
    const settingsBefore = optionJson('wptsall_settings') || {}
    const currentDefault = String(settingsBefore.default_language || '')
    const select = stepForm.locator('select[name="default_language"]')
    const optionValues = await select.locator('option').evaluateAll((opts) =>
      opts.map((o) => (o as HTMLOptionElement).value),
    )
    expect(optionValues.length).toBeGreaterThanOrEqual(1)
    const picked = optionValues.find((v) => v && v !== currentDefault) ?? optionValues[0]

    await select.selectOption(picked)
    await Promise.all([
      page.waitForURL(/wptsall-wizard.*step=3/, { timeout: 45_000 }),
      stepForm.locator('button[type="submit"]').click(),
    ])
    await expect(page.getByRole('heading', { name: 'Target languages' })).toBeVisible()

    // Service effect: default_language landed in the wptsall_settings option
    // (the wizard's own Done summary renders it from there).
    const settingsAfter = optionJson('wptsall_settings')
    expect(settingsAfter).not.toBeNull()
    expect(String((settingsAfter as Record<string, unknown>).default_language)).toBe(picked)
  })

  test('wizard_skip: skip form → completed state + plugin-admin redirect', async ({ page }) => {
    wpCli('option delete wptsall_wizard_state')
    await wpLogin(page)
    await page.goto(WIZARD_PAGE, { waitUntil: 'domcontentloaded' })

    const skipForm = await adminPostForm(page, 'wptsall_wizard_skip')
    await Promise.all([
      page.waitForURL(/page=wpmmcc-ats(&|$|\?)/, { timeout: 45_000 }),
      skipForm.locator('button[type="submit"]').click(),
    ])

    // Service effect: the persisted state records completion (drives the
    // dashboard-widget nudge removal in is_completed()).
    const state = optionJson('wptsall_wizard_state')
    expect(state).not.toBeNull()
    expect((state as Record<string, unknown>).completed).toBe(true)
  })
})
