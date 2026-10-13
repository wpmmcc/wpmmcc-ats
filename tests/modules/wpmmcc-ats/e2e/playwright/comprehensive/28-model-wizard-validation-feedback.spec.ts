/**
 * 28 — Custom Model Wizard: validation feedback + step-4 guidance (UI-27-01).
 *
 * The 2026-09-20 live four-quadrant audit (tasks/client2/27-*) found the
 * wizard's Next button silently did nothing when required input was missing,
 * and step 4 rendered "No fields available" ×4 for unscanned plugins with no
 * path forward. Root cause: showError() wrote into .wptsall-wizard-notices,
 * a container the template never rendered — a jQuery no-op — and the field
 * step had no guidance for the no-plugin-data case.
 *
 * This spec pins the fixes (wpmmcc-ats >= 0.9.x, 2026-09-20):
 *   1. The template ships .wptsall-wizard-notices.
 *   2. Step 1: Next with no plugin selected → visible error notice, step
 *      indicator does not advance.
 *   3. Step 2: Next with an empty URL pattern → visible error notice
 *      (previously: zero feedback, byte-identical screenshots).
 *   4. Step 4: unscanned plugin (no data) → guidance panel with a Rescan
 *      button instead of a bare dead-end; clicking Rescan yields either
 *      loaded fields or an explicit "still no data" notice — never silence.
 *
 * Target: the ATS lab test site (container wptsall-wp-lab-wordpress-test-1,
 * plugin source mounted read-only from wpmmcc-ats/source, so source edits
 * are live without a rebuild).
 *
 * Env:
 *   WP_BASE       (default http://127.0.0.1:9083)
 *   WP_ADMIN_USER (default admin)
 *   WP_ADMIN_PASS (default admin123456 — canonical Lab admin creds per
 *                  tests/docker-lab/STATUS.md; the historical admin123
 *                  default went stale against the Lab's actual password
 *                  and every login attempt failed with "login failed",
 *                  2026-09-27 account)
 *
 * Run:
 *   cd tests/modules/wpmmcc-ats/e2e/playwright
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 28-model-wizard-validation-feedback
 *
 * catalog: WP-CLASS-Model_Editor_Page
 * oracle: L2
 */
import { test, expect, Page } from '@playwright/test'
import { findFatalError } from './helpers'

const WP_BASE = (process.env.WP_BASE ?? 'http://127.0.0.1:9083').replace(/\/+$/, '')
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin'
// Canonical Lab admin password (tests/docker-lab/STATUS.md); the stale
// admin123 default failed every login (2026-09-27 account).
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin123456'

const WIZARD_URL = `${WP_BASE}/wp-admin/admin.php?page=wpmmcc-ats&action=custom_wizard`

async function login(page: Page): Promise<void> {
  await page.context().clearCookies()
  await page.goto(
    `${WP_BASE}/wp-login.php?redirect_to=${encodeURIComponent(WIZARD_URL)}&reauth=1`,
    { waitUntil: 'domcontentloaded', timeout: 60_000 },
  )
  await page.fill('#user_login', ADMIN_USER)
  await page.fill('#user_pass', ADMIN_PASS)
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => null),
    page.click('#wp-submit'),
  ])
  if (page.url().includes('confirm_admin_email')) {
    await page.goto(WIZARD_URL, { waitUntil: 'domcontentloaded' })
  }
  const cookies = await page.context().cookies()
  expect(
    cookies.some((c) => c.name.startsWith('wordpress_logged_in_')),
    `login failed (url=${page.url()})`,
  ).toBeTruthy()
}

async function openWizard(page: Page): Promise<void> {
  await page.goto(WIZARD_URL, { waitUntil: 'domcontentloaded', timeout: 60_000 })
  const html = await page.content()
  const fatal = findFatalError(html)
  expect(fatal, `wizard page fatal: ${fatal}`).toBeNull()
  await expect(page.locator('.wptsall-wizard')).toBeVisible()
  // Plugin list loads via REST on a 20-plugin multisite lab — wait for the
  // round trip to finish (the "Loading..." placeholder option disappears;
  // renderPluginSelect() always replaces it, even with zero plugins).
  await expect(
    page.locator('#wptsall-plugin-select option', { hasText: 'Loading' }),
  ).toHaveCount(0, { timeout: 60_000 })
}

test.describe.configure({ mode: 'serial' })

test.describe('28 — custom model wizard validation feedback (UI-27-01)', () => {
  let page: Page

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage()
    await login(page)
  })

  test.afterAll(async () => {
    await page.close()
  })

  test('wizard page renders with a notices container (template fix)', async () => {
    await openWizard(page)
    // The container the wizard JS writes errors into must exist in the DOM —
    // before the fix every showError() was a silent jQuery no-op.
    await expect(page.locator('.wptsall-wizard-notices')).toHaveCount(1)
  })

  test('step 1: Next with no plugin selected shows a visible error and does not advance', async () => {
    await openWizard(page)
    await page.locator('.wptsall-wizard-next').click()
    await expect(page.locator('.wptsall-wizard-notices .notice-error')).toBeVisible()
    await expect(page.locator('.wptsall-wizard-notices .notice-error')).toContainText(/select/i)
    // Still on step 1.
    await expect(page.locator('.wptsall-wizard-step-content[data-step="1"]')).toHaveClass(/active/)
  })

  test('step 2: Next with an empty URL pattern shows a visible error (was a silent no-op)', async () => {
    await openWizard(page)

    // Reach step 2 by selecting a real unregistered plugin. If the lab has
    // none left, this branch self-skips: the same showError() path is already
    // proven by the step-1 negative above.
    const options = page.locator('#wptsall-plugin-select option[value]:not([value=""])')
    const optionCount = await options.count()
    test.skip(optionCount === 0, 'no unregistered plugins in lab — step-2 branch needs a selectable plugin')

    await page.selectOption('#wptsall-plugin-select', { index: 1 })
    await page.locator('.wptsall-wizard-next').click()
    await expect(page.locator('.wptsall-wizard-step-content[data-step="2"]')).toHaveClass(/active/)

    // The UI-27-01 repro: empty required input + Next = nothing happened.
    await page.locator('#wptsall-url-pattern').fill('')
    await page.locator('.wptsall-wizard-next').click()
    await expect(page.locator('.wptsall-wizard-notices .notice-error')).toBeVisible()
    await expect(page.locator('.wptsall-wizard-notices .notice-error')).toContainText(/URL pattern/i)
    await expect(page.locator('.wptsall-wizard-step-content[data-step="2"]')).toHaveClass(/active/)
  })

  test('step 4: unscanned plugin shows actionable guidance with a Rescan button, never a bare dead-end', async () => {
    await openWizard(page)

    const options = page.locator('#wptsall-plugin-select option[value]:not([value=""])')
    test.skip((await options.count()) === 0, 'no unregistered plugins in lab')

    await page.selectOption('#wptsall-plugin-select', { index: 1 })
    await page.locator('.wptsall-wizard-next').click()
    await page.locator('#wptsall-url-pattern').fill('/wizard-e2e/{id}')
    await page.locator('.wptsall-wizard-next').click()
    // Step 3 (link chains) is optional — advance again.
    await expect(page.locator('.wptsall-wizard-step-content[data-step="3"]')).toHaveClass(/active/)
    await page.locator('.wptsall-wizard-next').click()
    await expect(page.locator('.wptsall-wizard-step-content[data-step="4"]')).toHaveClass(/active/)

    // Either real fields load, or the no-data guidance panel renders with a
    // rescan action. Both are acceptable; a bare "No fields available" with
    // no path forward is the regression this pins.
    const checkboxes = page.locator('.wptsall-field-checkbox')
    const guidance = page.getByTestId('wizard-no-data-guidance')
    const hasFields = (await checkboxes.count()) > 0
    if (!hasFields) {
      await expect(guidance).toBeVisible()
      await expect(page.getByTestId('wizard-rescan-btn')).toBeVisible()

      // Rescan must yield feedback, not silence: fields appear OR an
      // explicit "still no data" error notice.
      await page.getByTestId('wizard-rescan-btn').click()
      await expect
        .poll(async () => (await page.locator('.wptsall-field-checkbox').count()) > 0
          || (await page.locator('.wptsall-wizard-notices .notice').count()) > 0,
        { timeout: 30_000 },
        'rescan must either load fields or show a notice',
      ).toBeTruthy()
    } else {
      await expect(checkboxes.first()).toBeVisible()
    }
  })
})
