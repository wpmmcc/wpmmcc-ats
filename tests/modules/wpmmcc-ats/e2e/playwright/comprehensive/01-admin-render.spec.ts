/**
 * Fix/expand admin page render inventory + Tasks/Sites/SEO/Models chrome.
 * Run: WP_BASE=http://127.0.0.1:9083 bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 01
 *
 * catalog: WP-CLASS-Dashboard_Page
 * oracle: L1
 * catalog: WP-CLASS-Languages_Page
 * oracle: L1
 * catalog: WP-CLASS-WPTSALL_Tasks_List_Table
 * oracle: L1
 */
import { test, expect } from '@playwright/test'
import { wpLogin, gotoAdminPage, findFatalError, isAdminPageRendered, WP_BASE_URL, SLOW_ADMIN_GOTO_TIMEOUT } from './helpers'

const ADMIN_PAGES = [
  { slug: 'wpmmcc-ats', label: 'Models (parent)' },
  { slug: 'wptsall-languages', label: 'Languages' },
  { slug: 'wptsall-settings', label: 'Settings' },
  { slug: 'wptsall-sites', label: 'Sites' },
  { slug: 'wptsall-tasks', label: 'Tasks' },
  { slug: 'wptsall-templates', label: 'Language Packs' },
  { slug: 'wptsall-models-backup', label: 'Models Backup' },
  { slug: 'wptsall-seo', label: 'SEO' },
  { slug: 'wptsall-menu-sync', label: 'Menu Sync' },
  { slug: 'wptsall-strings', label: 'String Translation' },
  { slug: 'wptsall-tm', label: 'Translation Memory' },
  { slug: 'wptsall-media', label: 'Media Translation' },
  { slug: 'wptsall-custom-fields', label: 'Custom Fields' },
  { slug: 'wptsall-users', label: 'User Translation' },
  { slug: 'wptsall-manual', label: 'Manual Hub' },
  { slug: 'wptsall-url-discovery', label: 'URL Discovery' },
  { slug: 'wptsall-pending', label: 'Pending Translations' },
  { slug: 'wptsall-tax-translations', label: 'Taxonomy Translation' },
  { slug: 'wptsall-field-discovery', label: 'Field Discovery' },
  { slug: 'wptsall-content-types', label: 'Content Types Config' },
  { slug: 'wptsall-theme-plugin-loc', label: 'Theme & Plugin Loc' },
  { slug: 'wptsall-wizard', label: 'Setup Wizard' },
  { slug: 'wptsall-dashboard', label: 'Dashboard' },
]

const TASK_TABS = ['monitoring', 'jobs', 'relations', 'sync_records', 'lang_packs', 'authorization', 'features']
const SITE_TABS = ['virtual', 'conflicts', 'add', 'add_virtual', 'relations']

test.describe('01 Admin Render Smoke', () => {
  // 2026-09-27 slow-lab headroom (29/02 precedent): the accreted Lab +
  // external load spikes push heavy admin pages past the 30s default test
  // budget — the same-day full run lost legs at 30.7-32.7s that re-passed
  // on quiet windows; 120s matches the goto headroom scale.
  test.setTimeout(120_000)
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  for (const { slug, label } of ADMIN_PAGES) {
    test(`${slug} (${label}) renders without fatal errors`, async ({ page }) => {
      const { html, status } = await gotoAdminPage(page, slug)
      const fatal = findFatalError(html)
      expect(fatal, `Fatal error on ${slug}: ${fatal}`).toBeNull()
      expect(isAdminPageRendered(html), `Page ${slug} did not render as admin page`).toBe(true)
      expect(status).toBeGreaterThanOrEqual(200)
      expect(status).toBeLessThan(400)
    })
  }
})

test.describe('01b Admin Menu Visibility', () => {
  test.setTimeout(120_000)
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('WPTSALL menu is visible in admin sidebar', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
    const wptsallMenu = page.locator('#adminmenu a:has-text("WPTSALL"), #adminmenu a:has-text("WPTS")')
    await expect(wptsallMenu.first()).toBeVisible({ timeout: 5000 })
  })

  test('All submenu items present', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
    await page.hover('#adminmenu a:has-text("WPTSALL"), #adminmenu a:has-text("WPTS")')
    await page.waitForTimeout(500)
    const submenu = page.locator('#adminmenu .wp-submenu a:has-text("Languages")')
    await expect(submenu.first()).toBeVisible({ timeout: 3000 })
  })
})

test.describe('01c Tasks / Sites tabs render', () => {
  test.setTimeout(120_000)
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  for (const tab of TASK_TABS) {
    test(`Tasks tab=${tab} renders`, async ({ page }) => {
      await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-tasks&tab=${tab}`, {
        waitUntil: 'domcontentloaded',
        timeout: SLOW_ADMIN_GOTO_TIMEOUT,
      })
      expect(findFatalError(await page.content())).toBeNull()
      expect(isAdminPageRendered(await page.content())).toBe(true)
    })
  }

  for (const tab of SITE_TABS) {
    test(`Sites tab=${tab} renders`, async ({ page }) => {
      await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-sites&tab=${tab}`, {
        waitUntil: 'domcontentloaded',
        timeout: SLOW_ADMIN_GOTO_TIMEOUT,
      })
      expect(findFatalError(await page.content())).toBeNull()
      expect(isAdminPageRendered(await page.content())).toBe(true)
    })
  }
})
