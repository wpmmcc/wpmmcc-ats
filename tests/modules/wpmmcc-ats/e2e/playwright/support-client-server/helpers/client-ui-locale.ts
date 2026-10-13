import { expect, type Page } from '@playwright/test'

/** Support specs assert zh-CN copy; Lab slot clients often default to en. */
export const CLIENT_UI_LOCALE = process.env.WPTSALL_CLIENT_UI_LOCALE ?? 'zh-CN'

/** Set locale before first navigation (svelte-i18n reads wptsall_locale from localStorage). */
export async function primeClientUiLocale(page: Page, locale = CLIENT_UI_LOCALE): Promise<void> {
  await page.addInitScript((loc: string) => {
    localStorage.setItem('wptsall_locale', loc)
  }, locale)
}

/** Ensure overview nav shows zh-CN labels; click 中文 toggle if init script was too late. */
export async function ensureClientUiChinese(page: Page): Promise<void> {
  const startLoop = page.getByRole('button', { name: '启动循环' })
  if (await startLoop.isVisible({ timeout: 1500 }).catch(() => false)) {
    return
  }

  const zhBtn = page.getByRole('button', { name: '中文', exact: true })
  if (await zhBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
    const pressed = await zhBtn.getAttribute('aria-pressed')
    if (pressed !== 'true') {
      await zhBtn.click()
    }
  }

  await expect(startLoop).toBeVisible({ timeout: 15_000 })
}
