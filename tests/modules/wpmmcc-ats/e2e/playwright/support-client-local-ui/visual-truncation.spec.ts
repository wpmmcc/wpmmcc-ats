/**
 * 3.8flash D3 (VERIFIED-REPAIR-PLAN-20260925): visual truncation assertions
 * for the default local UI (feedback blind spot 3).
 *
 * Coverage gap: the run-once item cap input (CLIENT-P2-03, fixed in C3 as
 * w-20 → w-28 + a text label) had its truncation verified only by eyeball —
 * no automated surface ever asserted that an input actually RENDERS its
 * content ("Run-onc" was shipped by exactly this class of gap). The same
 * class applies to every key input on the default page.
 *
 * The honest machine-oracle for "not truncated" is the element box itself:
 * scrollWidth <= clientWidth (content does not overflow the element box).
 * This spec asserts the C3-fixed input plus EVERY visible text/number input
 * on the default page.
 *
 * Lane: run-playwright-support-client-local-ui.sh (one owned loopback Client
 * per lane run; the base URL arrives via WPTSALL_CLIENT_LOCAL_UI_BASE_URL).
 */
import { expect, test } from '@playwright/test'

const BASE = process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL

test.beforeAll(() => {
  expect(BASE, 'WPTSALL_CLIENT_LOCAL_UI_BASE_URL must be exported by the lane runner').toBeTruthy()
})

test('default page key inputs render untruncated (scrollWidth <= clientWidth)', async ({ page }) => {
  await page.goto(`${BASE}/`)

  // The C3-fixed run-once item cap input (data-testid contract).
  const runOnce = page.getByTestId('overview-run-once-max-items')
  await expect(runOnce).toBeVisible()

  const runOnceBox = await runOnce.evaluate((el) => ({
    scrollWidth: el.scrollWidth,
    clientWidth: el.clientWidth,
  }))
  expect(
    runOnceBox.scrollWidth,
    `run-once input must not truncate (scrollWidth ${runOnceBox.scrollWidth} > clientWidth ${runOnceBox.clientWidth})`,
  ).toBeLessThanOrEqual(runOnceBox.clientWidth)

  // The batch leg: every OTHER visible text/number input on the default
  // page must render its content untruncated too (same defect class).
  const boxes = await page
    .locator('input[type="number"]:visible, input[type="text"]:visible')
    .evaluateAll((els) =>
      els.map((el) => ({
        testid: (el as HTMLElement).dataset?.testid ?? el.getAttribute('aria-label') ?? '',
        scrollWidth: el.scrollWidth,
        clientWidth: el.clientWidth,
      })),
    )
  expect(boxes.length, 'the default page must have key inputs to assert').toBeGreaterThan(0)
  const truncated = boxes.filter((b) => b.scrollWidth > b.clientWidth)
  expect(
    truncated,
    `no visible input may truncate its content: ${JSON.stringify(truncated)}`,
  ).toEqual([])
})
