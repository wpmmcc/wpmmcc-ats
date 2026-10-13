import { expect, test } from '@playwright/test'

// 批 O3 / U-2 (12号 §29): the OTA UI journey — the leg the secure OTA suite
// (run-ota-secure-suite.sh) never walked: the Settings → About →
// Check-for-Updates → Update-Now → settle "Already up to date" flow, driven
// through the REAL web UI against a signed mock manifest + host-signed kit.
//
// Runner contract (run-playwright-ota-ui.sh):
//   - client serves the INSTALL ROOT UI (no WPTSALL_WEB_UI_PATH), staged
//     VERSION-WEBUI = WPTSALL_OTA_UI_STAGED_VERSION (2.1.0)
//   - mock manifest latest = WPTSALL_OTA_UI_LATEST_VERSION (the kit's own
//     VERSION-WEBUI, 2.1.2) → update_kind 'ui' → the apply swaps the install
//     root UI and the journey SETTLES to "Already up to date"
//
// Single serial test (lane pattern): the client is one process.

const CLIENT_BASE = process.env.WPTSALL_OTA_UI_CLIENT_BASE ?? ''
const STAGED = process.env.WPTSALL_OTA_UI_STAGED_VERSION ?? '2.1.0'
const LATEST = process.env.WPTSALL_OTA_UI_LATEST_VERSION ?? '2.1.2'

test.setTimeout(240_000)

test('Settings About OTA journey: check → available → Update Now → Already up to date', async ({ page }) => {
  test.skip(!CLIENT_BASE, 'lane env missing (run via run-playwright-ota-ui.sh)')

  await page.goto(`${CLIENT_BASE}/`, { waitUntil: 'domcontentloaded' })

  // Settings page via the sidebar nav.
  await page.click('nav button:has-text("Settings")')
  await expect(page.locator('h2:has-text("Settings")').first()).toBeVisible()

  // About tab: local versions render pre-check (local /health read; never a
  // blank version screen).
  await page.click('[data-testid="settings-tab-about"]')
  await expect(page.locator('[data-testid="about-local-versions"]')).toBeVisible()
  await expect(page.locator('[data-testid="about-local-versions"]')).toContainText(`Binary: v`)

  // Leg A: Check for Updates → update available with the staged → latest arrow.
  await page.click('[data-testid="about-check-update"]')
  await expect(page.getByText('New version available:')).toBeVisible()
  // Version info block renders the full arrow line "UI: v{staged} → v{latest}"
  // (the local-versions block also shows "UI: v{staged}" — assert the arrowed
  // text to stay strict-mode-clean).
  await expect(page.getByText(`UI: v${STAGED} → v${LATEST}`)).toBeVisible()
  // …and the "New version available" line carries "UI v{latest}" (no colon).
  await expect(page.getByText(`UI v${LATEST}`)).toBeVisible()

  // The Update Now button must exist and be enabled.
  const performBtn = page.locator('[data-testid="about-perform-update"]')
  await expect(performBtn).toBeVisible()
  await expect(performBtn).toBeEnabled()

  // Leg B: Update Now → the service applies the kit (kind=ui: toast + the
  // page re-checks). The re-check may race the service's UI swap — drive it
  // defensively: keep clicking a fresh check until the surface settles on
  // "Already up to date" (the runner's post-spec gate then proves the swap
  // landed on disk).
  await performBtn.click()
  await expect(page.getByText('Update started')).toBeVisible({ timeout: 60_000 })

  let settled = false
  for (let attempt = 0; attempt < 20 && !settled; attempt++) {
    // Fresh check: the button re-enables after each check completes.
    const btn = page.locator('[data-testid="about-check-update"]')
    await expect(btn).toBeEnabled({ timeout: 30_000 })
    await btn.click().catch(() => {})
    try {
      await expect(page.getByText('Already up to date')).toBeVisible({ timeout: 10_000 })
      settled = true
    } catch {
      await page.waitForTimeout(2_000)
    }
  }
  expect(settled, 'journey must settle on "Already up to date" after the UI apply').toBe(true)

  // And the settled surface must carry the NEW UI version (not the staged one).
  await expect(page.getByText(`UI: v${LATEST}`)).toBeVisible()
})
