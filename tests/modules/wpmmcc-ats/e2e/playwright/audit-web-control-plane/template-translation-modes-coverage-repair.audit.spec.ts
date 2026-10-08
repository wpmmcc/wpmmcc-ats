import { test, expect, type Locator, type Page } from '@playwright/test'
import { loginAsAdmin } from '../support-web-control-plane/helpers'

type ComponentItem = {
  id: string
  owner_type?: string
  status?: string
  supported_content_formats?: string[]
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
      if (!resp.ok) return { items: [] as ComponentItem[], totalPages: 1 }
      const json = await resp.json()
      return {
        items: (json?.data?.items || []) as ComponentItem[],
        totalPages: Number(json?.data?.total_pages || 1),
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

async function findBestModeCard(cards: Locator, required: string[]): Promise<Locator | null> {
  const count = await cards.count()
  if (count === 0) return null

  // Prefer the mode that already covers plain_text (or whose id hints "plain/default").
  for (let i = 0; i < count; i += 1) {
    const card = cards.nth(i)
    const plainBox = card.locator('input[type="checkbox"][value="plain_text"]')
    if ((await plainBox.count()) > 0 && (await plainBox.isChecked().catch(() => false))) {
      return card
    }
    const idInput = card.locator('input[type="text"]').nth(0)
    const idValue = (await idInput.inputValue().catch(() => '')).trim().toLowerCase()
    if (idValue.includes('plain') || idValue.includes('default')) {
      return card
    }
  }

  // Fallback to the first card.
  return cards.first()
}

async function computeCoveredFormats(cards: Locator, required: string[]): Promise<Set<string>> {
  const out = new Set<string>()
  const count = await cards.count()
  for (let i = 0; i < count; i += 1) {
    const card = cards.nth(i)
    for (const fmt of required) {
      const box = card.locator(`input[type="checkbox"][value="${fmt}"]`)
      if ((await box.count()) === 0) continue
      if (await box.isChecked().catch(() => false)) out.add(fmt)
    }
  }
  return out
}

async function ensureModeCoverage(modal: Locator, required: string[]): Promise<boolean> {
  const modesSection = modal.locator('fieldset').filter({ hasText: /翻译模式|translation modes/i }).first()
  await expect(modesSection).toBeVisible()

  const cards = modesSection.locator('div.bg-gray-50.border.border-gray-200.rounded-md.p-3')
  const covered = await computeCoveredFormats(cards, required)
  const missing = required.filter((fmt) => !covered.has(fmt))
  if (missing.length === 0) return false

  const cardCount = await cards.count()
  if (cardCount === 0) {
    // Create a single "default" mode that covers all required formats.
    await modesSection.locator('button').filter({ hasText: /添加翻译模式|add/i }).first().click()
    const card = cards.last()
    await expect(card).toBeVisible()
    const inputs = card.locator('input[type="text"]')
    await inputs.nth(0).fill('default')
    await inputs.nth(1).fill('Default')
    for (const fmt of required) {
      await card.locator(`input[type="checkbox"][value="${fmt}"]`).check()
    }
    return true
  }

  const best = await findBestModeCard(cards, required)
  if (!best) return false
  for (const fmt of missing) {
    await best.locator(`input[type="checkbox"][value="${fmt}"]`).check()
  }
  return true
}

test.describe('Template Translation Modes Coverage Repair', () => {
  test('repair disabled official text templates caused by incomplete translation_modes coverage', async ({ page }) => {
    test.setTimeout(15 * 60 * 1000)
    await loginAsAdmin(page)

    const requiredFormats = ['plain_text', 'rich_html', 'json_structured', 'serialized_php']

    const allBefore = await fetchAllComponents(page)
    const toFix = allBefore
      .filter((it) => (it.owner_type || '').toLowerCase() === 'official')
      .filter((it) => (it.supported_content_formats || []).length > 1)
      .filter((it) => (it.status || '').toLowerCase() === 'disabled')
      .map((it) => it.id)
      .sort()

    console.log(`[REPAIR] candidates=${toFix.length} ids=${toFix.join(', ')}`)

    const fixed: string[] = []
    const skipped: string[] = []
    const missing: string[] = []
    const failed: Array<{ id: string; err: string }> = []

    for (const id of toFix) {
      const modal = await openEditModal(page, id)
      if (!modal) {
        missing.push(id)
        continue
      }
      try {
        const changed = await ensureModeCoverage(modal, requiredFormats)
        if (!changed) {
          skipped.push(id)
          await modal.locator('button').filter({ hasText: /cancel|取消/i }).first().click()
          await expect(modal).toBeHidden()
          continue
        }
        await modal.locator('button[type="submit"]').click()
        await expect(modal).toBeHidden({ timeout: 20_000 })
        fixed.push(id)
      } catch (err) {
        const detail = err instanceof Error ? err.message : String(err)
        failed.push({ id, err: detail })
        const cancelBtn = modal.locator('button').filter({ hasText: /cancel|取消/i }).first()
        if ((await cancelBtn.count()) > 0) {
          await cancelBtn.click()
          await page.waitForTimeout(250)
        }
      }
    }

    console.log(
      `[SUMMARY] fixed=${fixed.length}, skipped=${skipped.length}, missing=${missing.length}, failed=${failed.length}`,
    )
    if (failed.length > 0) console.log(`[FAILED] ${failed.map((f) => `${f.id}: ${f.err}`).join(' | ')}`)

    expect(failed).toEqual([])

    const allAfter = await fetchAllComponents(page)
    const byId = new Map(allAfter.map((it) => [it.id, it]))
    for (const id of fixed) {
      const item = byId.get(id)
      expect(item, `component should exist after repair: ${id}`).toBeTruthy()
      expect((item?.status || '').toLowerCase(), `status should become active: ${id}`).toBe('active')
    }
  })
})
