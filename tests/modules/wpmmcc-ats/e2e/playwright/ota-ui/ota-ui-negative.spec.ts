import { expect, test } from '@playwright/test'

// 批 O3 / U-2 (12号 §29): the OTA UI negative — a tampered releases.minisig
// must surface the About tab's DEDICATED error node (about-update-error) with
// the 'Update check failed' copy (settings.update_check_failed — added by
// this batch; the key was missing from both locales and the error path
// rendered a raw key instead of copy). The CLI-level rejection (no successful
// update-check) is gated by the runner; this spec owns the UI surfacing.

const CLIENT_BASE = process.env.WPTSALL_OTA_UI_CLIENT_BASE ?? ''

test.setTimeout(120_000)

test('tampered releases manifest surfaces the About-tab update error node', async ({ page }) => {
  test.skip(!CLIENT_BASE, 'lane env missing (run via run-playwright-ota-ui.sh)')

  await page.goto(`${CLIENT_BASE}/`, { waitUntil: 'domcontentloaded' })

  await page.click('nav button:has-text("Settings")')
  await expect(page.locator('h2:has-text("Settings")').first()).toBeVisible()
  await page.click('[data-testid="settings-tab-about"]')

  // No error node before the check runs.
  await expect(page.locator('[data-testid="about-update-error"]')).toHaveCount(0)

  await page.click('[data-testid="about-check-update"]')

  // The failure must surface through the dedicated error node with real
  // copy — never a blank/raw-key surface.
  await expect(page.locator('[data-testid="about-update-error"]')).toBeVisible({ timeout: 30_000 })
  await expect(page.locator('[data-testid="about-update-error"]')).toHaveText('Update check failed')
})
