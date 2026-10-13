import { test, expect, type Page, type Locator } from '@playwright/test'
import { loginAsAdmin } from '../support-web-control-plane/helpers'

type ComponentItem = {
  id: string
  owner_type?: string
  supported_content_formats?: string[]
  translation_modes?: Array<{
    id?: string
    supported_content_formats?: string[]
  }>
}

async function fetchAllComponents(page: Page): Promise<ComponentItem[]> {
  const perPage = 200
  let pageNo = 1
  const merged: ComponentItem[] = []

  while (true) {
    const chunk = await page.evaluate(async ({ pageNo, perPage }) => {
      const token = localStorage.getItem('session_token') || ''
      const resp = await fetch(`/api/v1/components?page=${pageNo}&per_page=${perPage}`, {
        headers: token ? { 'X-Client-Session': token } : {},
      })
      if (!resp.ok) {
        return { items: [] as ComponentItem[], totalPages: 1, status: resp.status }
      }
      const json = await resp.json()
      return {
        items: (json?.data?.items || []) as ComponentItem[],
        totalPages: Number(json?.data?.total_pages || 1),
        status: resp.status,
      }
    }, { pageNo, perPage })

    merged.push(...chunk.items)
    if (pageNo >= chunk.totalPages) break
    pageNo += 1
  }

  return merged
}

async function searchTemplateById(page: Page, id: string): Promise<void> {
  const search = page.locator('[data-testid="templates-search"]')
  await expect(search).toBeVisible()
  await search.fill(id)
  await search.press('Enter')
  await page.waitForTimeout(500)
}

async function openEditModal(page: Page, id: string): Promise<Locator | null> {
  await page.goto('/templates')
  await searchTemplateById(page, id)

  const row = page.locator('tbody tr').filter({ hasText: id }).first()
  if ((await row.count()) === 0) return null

  await row.locator('button').filter({ hasText: /edit|编辑/i }).click()
  const modal = page.locator('.fixed.inset-0')
  await expect(modal).toBeVisible()
  return modal
}

async function addTranslationMode(
  modesSection: Locator,
  spec: { id: string; label: string; fmt: string },
): Promise<void> {
  await modesSection.locator('button').filter({ hasText: /添加翻译模式|add/i }).first().click()

  const cards = modesSection.locator('div.bg-gray-50.border.border-gray-200.rounded-md.p-3')
  const card = cards.last()
  await expect(card).toBeVisible()

  const inputs = card.locator('input[type="text"]')
  await inputs.nth(0).fill(spec.id)
  await inputs.nth(1).fill(spec.label)

  await card
    .locator('label')
    .filter({ hasText: new RegExp(`^${spec.fmt}$`) })
    .locator('input[type="checkbox"]')
    .check()
}

test.describe('Template Translation Modes Migration', () => {
  test('batch add translation_modes for official text templates through admin WebUI', async ({ page }) => {
    test.setTimeout(25 * 60 * 1000)
    await loginAsAdmin(page)

    const all = await fetchAllComponents(page)
    expect(all.length).toBeGreaterThan(0)

    const needsPatch = all
      .filter((item) => (item.owner_type || '').toLowerCase() === 'official')
      .filter((item) => (item.supported_content_formats || []).length > 1)
      .filter((item) => (item.translation_modes || []).length === 0)
      .map((item) => item.id)
      .sort()

    console.log(`[MIGRATE] official templates fetched=${all.length}, needs_patch=${needsPatch.length}`)
    console.log(`[MIGRATE] needs_patch sample=`, needsPatch.slice(0, 12).join(', '))

    const patched: string[] = []
    const skipped: string[] = []
    const missing: string[] = []
    const failed: Array<{ id: string; err: string }> = []

    for (const id of needsPatch) {
      console.log(`[RUN] patch translation_modes: ${id}`)
      const modal = await openEditModal(page, id)
      if (!modal) {
        missing.push(id)
        console.log(`[SKIP] not found: ${id}`)
        continue
      }

      try {
        const modesSection = modal
          .locator('fieldset')
          .filter({ hasText: /翻译模式|translation modes/i })
          .first()
        await expect(modesSection).toBeVisible()

        const cards = modesSection.locator('div.bg-gray-50.border.border-gray-200.rounded-md.p-3')
        const existing = await cards.count()
        if (existing > 0) {
          skipped.push(id)
          console.log(`[SKIP] already has translation_modes: ${id}`)
          await modal.locator('button').filter({ hasText: /cancel|取消/i }).first().click()
          await expect(modal).toBeHidden()
          continue
        }

        for (const [modeId, label, fmt] of [
          ['plain_text', 'Plain Text', 'plain_text'],
          ['rich_html', 'Rich HTML', 'rich_html'],
          ['json_structured', 'JSON Structured', 'json_structured'],
          ['serialized_php', 'Serialized PHP', 'serialized_php'],
        ]) {
          await addTranslationMode(modesSection, { id: modeId, label, fmt })
        }

        await modal.locator('button[type="submit"]').click()
        await expect(modal).toBeHidden({ timeout: 20_000 })
        patched.push(id)
        console.log(`[OK] patched: ${id}`)
      } catch (err) {
        const detail = err instanceof Error ? err.message : String(err)
        failed.push({ id, err: detail })
        console.log(`[FAIL] ${id}: ${detail}`)

        // try close modal to keep loop going
        const cancelBtn = modal.locator('button').filter({ hasText: /cancel|取消/i }).first()
        if ((await cancelBtn.count()) > 0) {
          await cancelBtn.click()
          await page.waitForTimeout(250)
        }
      }
    }

    console.log(
      `[SUMMARY] patched=${patched.length}, skipped=${skipped.length}, missing=${missing.length}, failed=${failed.length}`,
    )
    if (missing.length > 0) console.log(`[MISSING] ${missing.join(', ')}`)
    if (failed.length > 0) console.log(`[FAILED] ${failed.map((f) => `${f.id}: ${f.err}`).join(' | ')}`)

    expect(failed).toEqual([])
    // Idempotent migration: in an already-migrated environment, needs_patch can be 0.
  })
})
