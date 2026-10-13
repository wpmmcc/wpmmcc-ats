import { test, expect, Page } from '@playwright/test'
import { execFileSync } from 'child_process'

/**
 * Roles / non-admin capability gating (authenticated).
 *
 * comprehensive/11-capability.spec.ts covers the unauthenticated case only.
 * This lane proves that a logged-in EDITOR (a legitimate WP role, no
 * manage_wptsall_* caps) is locked out of:
 *   1. the plugin admin menu (not rendered at all),
 *   2. direct plugin admin page URLs (no plugin content / deny),
 *   3. admin REST routes under wptsall/v2 (403, not 200).
 *
 * The editor user is created (idempotently) via wp-cli in the Lab container
 * and deleted in afterAll. No product data is touched.
 */

const WP_BASE_URL = process.env.WP_BASE || 'http://127.0.0.1:9083'
const CONTAINER =
  process.env.LAB_WP_CONTAINER ||
  process.env.WPTSALL_LAB_WP_CONTAINER ||
  'wptsall-wp-lab-wordpress-test-1'
const EDITOR_USER = 'e2eroleseditor'
const EDITOR_PASS = 'Wptsall-Roles-Editor-2026!'

function wpCli(args: string[]): string {
  return execFileSync(
    'docker',
    ['exec', '-w', '/var/www/html', CONTAINER, 'wp', ...args, '--allow-root'],
    { encoding: 'utf-8', stdio: ['ignore', 'pipe', 'pipe'] },
  )
}

async function loginAs(page: Page, user: string, pass: string): Promise<void> {
  await page.goto(`${WP_BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' })
  await page.fill('#user_login', user)
  await page.fill('#user_pass', pass)
  await page.click('input[name="wp-submit"]')
  await page.waitForLoadState('domcontentloaded')
  if (page.url().includes('wp-login.php')) {
    throw new Error(`login as ${user} failed (still on wp-login.php)`)
  }
}

test.describe.serial('Roles: editor cannot reach plugin surfaces', () => {
  test.beforeAll(async () => {
    try {
      wpCli(['user', 'create', EDITOR_USER, 'e2eroleseditor@example.test', '--role=editor', `--user_pass=${EDITOR_PASS}`])
    } catch {
      // exists already -> normalize role + password
      wpCli(['user', 'update', EDITOR_USER, '--role=editor', `--user_pass=${EDITOR_PASS}`])
    }
  })

  test.afterAll(async () => {
    try {
      wpCli(['user', 'delete', EDITOR_USER, '--yes'])
    } catch {
      // user gone or container down; nothing to assert on cleanup
    }
  })

  test('editor login works (sanity — lane is not vacuous)', async ({ page }) => {
    await loginAs(page, EDITOR_USER, EDITOR_PASS)
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })
    await expect(page).toHaveURL(/wp-admin/)
    // Editor sees the WP dashboard, i.e. login itself succeeded.
    await expect(page.locator('#adminmenu')).toBeVisible()
  })

  test('plugin admin menu is not rendered for editor', async ({ page }) => {
    await loginAs(page, EDITOR_USER, EDITOR_PASS)
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })
    const pluginMenuLinks = await page
      .locator('#adminmenu a[href*="page=wpmmcc-ats"]')
      .count()
    expect(pluginMenuLinks).toBe(0)
  })

  test('direct plugin admin page access is denied for editor', async ({ page }) => {
    await loginAs(page, EDITOR_USER, EDITOR_PASS)
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-dashboard`, {
      waitUntil: 'domcontentloaded',
    })
    // The plugin page content must not render for an editor...
    await expect(page.locator('body')).not.toContainText('Translation Dashboard')
    // ...and WP must either bounce the URL or show an explicit deny notice.
    const deniedByUrl = !page.url().includes('wptsall-dashboard')
    const body = (await page.locator('body').innerText()).slice(0, 4000)
    const deniedByNotice = /not allowed|higher level|权限|拒绝/i.test(body)
    expect(deniedByUrl || deniedByNotice).toBeTruthy()
  })

  test('admin REST route inaccessible for editor session (401/403, never 200)', async ({ page }) => {
    await loginAs(page, EDITOR_USER, EDITOR_PASS)
    // page.context().request reuses the browser session cookies. WP cookie
    // auth requires a REST nonce; without it the request is treated as
    // unauthenticated (401). Either way the editor must never read data.
    // (The strict editor -> 403 rest_forbidden case is proven server-side in
    // unit/core/test-rest-route-permissions.php via wp_set_current_user.)
    const res = await page.context().request.get(
      `${WP_BASE_URL}/wp-json/wptsall/v2/site-relations`,
    )
    expect([200, 201]).not.toContain(res.status())
    expect([401, 403]).toContain(res.status())
  })
})
