/**
 * Shared Playwright entry with uncaught-page-error capture (U-1, 12号 §17
 * 挂账 → 批 N1 统一挂).
 *
 * Forensics: the whole playwright tree had ZERO `pageerror` references —
 * lanes asserted console/network state but uncaught page exceptions were
 * never observed anywhere. This wrapper registers a `pageerror` listener on
 * every page and fails the test in fixture teardown if any uncaught error
 * fired, unless the spec explicitly allowlists a substring via annotation:
 *
 *   test('...submits', { annotation: { type: 'pageerror-allow', description: 'known noise' } }, ...)
 *
 * Drop-in usage — swap the import line only:
 *   import { test, expect } from '../lib/page-errors'
 *
 * Everything else (`expect`, types, `request`, …) is re-exported unchanged
 * from '@playwright/test' (local exports take precedence over the star
 * re-export, so only `test` is replaced).
 */
import { test as base } from '@playwright/test'
import type { Page } from '@playwright/test'

export * from '@playwright/test'

interface PageErrorRecord {
  message: string
}

export const test = base.extend<{ page: Page }>({
  page: async ({ page }, use, testInfo) => {
    const errors: PageErrorRecord[] = []
    page.on('pageerror', (error) => {
      errors.push({ message: error.message })
    })
    await use(page)
    const allowPatterns = testInfo.annotations
      .filter((a) => a.type === 'pageerror-allow')
      .map((a) => (a.description || '').trim())
      .filter(Boolean)
    const fatal = errors.filter(
      (e) => !allowPatterns.some((pattern) => e.message.includes(pattern)),
    )
    if (fatal.length > 0) {
      throw new Error(
        `uncaught page error(s) (${fatal.length}): ${fatal
          .map((e) => e.message)
          .join(' | ')}`,
      )
    }
  },
})
