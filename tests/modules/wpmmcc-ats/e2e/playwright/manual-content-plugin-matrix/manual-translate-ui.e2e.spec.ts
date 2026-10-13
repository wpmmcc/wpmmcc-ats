/**
 * P2 — Manual translate editor UI chrome for priority projects.
 *
 * Proves the official-fixture editor loads, title is editable in the DOM, and
 * Save is clickable without fatals. REST persistence is covered by the PHP
 * manual matrix; this gate focuses on the real admin UI surface.
 */
import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { wpLogin } from '../plugin-content-journeys/helpers'

const runtimeDir = process.env.E2E_RUNTIME_DIR
  ? path.resolve(process.env.E2E_RUNTIME_DIR)
  : path.resolve(__dirname, '../../runtime')
const targetsFile = path.join(runtimeDir, 'manual-content-plugin-matrix-targets.json')

const PRIORITY = ['woocommerce-content', 'learnpress-content', 'give-content']

type ProjectTarget = {
  project: string
  ok: boolean
  admin: { manual_editor_url: string }
}

type Payload = { projects: ProjectTarget[] }

function loadPayload(): Payload | null {
  if (!fs.existsSync(targetsFile)) return null
  try {
    return JSON.parse(fs.readFileSync(targetsFile, 'utf-8')) as Payload
  } catch {
    return null
  }
}

test.describe('P2 Manual translate editor UI (official priority)', () => {
  test.setTimeout(240_000)
  const payload = loadPayload()

  for (const projectKey of PRIORITY) {
    test(`${projectKey}: editor chrome editable`, async ({ page }) => {
      test.skip(!payload, `missing or unreadable matrix targets: ${targetsFile}`)
      const project = payload!.projects.find((p) => p.project === projectKey)
      test.skip(!project, `project ${projectKey} missing from matrix targets`)
      test.skip(!project!.ok, `${projectKey} PHP matrix failed`)
      test.skip(!project!.admin?.manual_editor_url, 'no manual editor URL')

      await wpLogin(page)
      await page.goto(project!.admin.manual_editor_url, { waitUntil: 'domcontentloaded', timeout: 90_000 })
      await expect(page.locator('#wptsall-translation-editor')).toBeVisible({ timeout: 60_000 })
      await expect(page.locator('#wptsall-save-translation')).toBeVisible()
      await expect(page.locator('body')).toContainText('Source Content')
      await expect(page.locator('body')).toContainText('Target Content')

      const stamp = Date.now().toString(36)
      const titleInput = page.locator('#wptsall-target-post_title')
      await expect(titleInput).toBeVisible({ timeout: 30_000 })
      const newTitle = `UI Manuel FR ${projectKey} ${stamp}`
      await titleInput.fill(newTitle)
      await expect(titleInput).toHaveValue(newTitle)

      // Click Save: must not fatal. Persistence is covered by PHP matrix REST;
      // after-save the editor may rehydrate from server (field value can change).
      await page.locator('#wptsall-save-translation').click()
      await page.waitForTimeout(2000)
      const html = await page.content()
      expect(html).not.toMatch(/Fatal error:|There has been a critical error/i)
      await expect(page.locator('#wptsall-translation-editor')).toBeVisible()
      await expect(titleInput).toBeVisible()
    })
  }
})
