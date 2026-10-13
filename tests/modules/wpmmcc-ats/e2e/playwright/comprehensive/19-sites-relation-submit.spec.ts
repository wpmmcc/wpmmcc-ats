/**
 * WP Admin Sites: Add Relation UI → POST /site-relations → cleanup DELETE.
 *
 * opus5 P-2: the relation target is self-owned. The spec used to skip when
 * the lab happened to have no virtual site in the form's select, and its
 * "already exists" recovery path adopted whatever shared relation matched
 * (doc 04 §4.3). It now seeds its own `p2fx-` virtual site in beforeAll so
 * the form always has a known target, and a failed create fails loudly.
 *
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-sites-relation
 */
import { test, expect, type Page, type APIRequestContext } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'
import { cleanupOwned, seedOwnedVirtualSite } from './lib/fixture-owner'

function normVirtualId(id: string | number | undefined | null): string {
  const s = String(id ?? '')
  if (!s) return ''
  return s.startsWith('v_') ? s : `v_${s}`
}

async function apiJson(
  request: APIRequestContext,
  method: string,
  url: string,
  nonce: string,
  body?: unknown,
): Promise<{ status: number; body: unknown }> {
  const res = await request.fetch(url, {
    method,
    headers: {
      'X-WP-Nonce': nonce,
      ...(body != null ? { 'Content-Type': 'application/json' } : {}),
    },
    data: body != null ? JSON.stringify(body) : undefined,
    timeout: 60_000,
  })
  let parsed: unknown = null
  try {
    parsed = await res.json()
  } catch {
    parsed = await res.text().catch(() => null)
  }
  return { status: res.status(), body: parsed }
}

async function restNonce(page: Page): Promise<string> {
  return page.evaluate(() => {
    const w = window as unknown as {
      wpApiSettings?: { nonce?: string }
      wptsallSites?: { nonce?: string }
    }
    return String(w?.wpApiSettings?.nonce || w?.wptsallSites?.nonce || '')
  })
}

test.describe.configure({ mode: 'serial' })

test.describe('19 Sites Add Relation submit', () => {
  test.setTimeout(180_000)

  /** opus5 P-2: the virtual-site target is self-owned (seeded in beforeAll). */
  let ownedVsId = 0

  test.beforeAll(async ({ browser }, testInfo) => {
    // Seeding through the product UI surfaces needs more than the 30s hook default.
    testInfo.setTimeout(240_000)
    const page = await browser.newPage()
    try {
      await wpLogin(page)
      const vs = await seedOwnedVirtualSite(page, { nameSuffix: '19-relation-form' })
      ownedVsId = vs.id
    } finally {
      await page.close()
    }
  })

  test.afterAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(240_000)
    const page = await browser.newPage()
    try {
      await wpLogin(page)
      await cleanupOwned(page)
    } finally {
      await page.close()
    }
  })

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Add Relation form posts /site-relations and can be deleted', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-sites&tab=add`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()

    const form = page.locator('#wptsall-create-relation-form')
    await expect(form).toBeVisible({ timeout: 20_000 })

    const nonce = await restNonce(page)
    expect(nonce).toBeTruthy()

    const vsSelect = page.locator('#target_virtual_site')
    // opus5 P-2: the target select is part of the form under test and this
    // spec owns its target — both conditions are hard expectations now.
    expect((await vsSelect.count()) === 0, 'target_virtual_site select must exist').toBeFalsy()
    const vsOptions = vsSelect.locator('option').filter({ hasNotText: /Select|选择/i })
    expect((await vsOptions.count()) === 0, 'virtual site options must exist').toBeFalsy()

    // opus5 P-2: pick the SELF-OWNED `p2fx-` virtual site (seeded in
    // beforeAll) instead of whatever shared lab state is untaken.
    expect(ownedVsId, 'P-2 fixture: seeded virtual site must exist').toBeGreaterThan(0)
    const ownedVsNorm = normVirtualId(ownedVsId)
    let vsValue: string | null = null
    const optCount = await vsOptions.count()
    for (let i = 0; i < optCount; i++) {
      const val = await vsOptions.nth(i).getAttribute('value')
      if (val && normVirtualId(val) === ownedVsNorm) {
        vsValue = val
        break
      }
    }
    expect(vsValue, `owned virtual site ${ownedVsNorm} must appear in the form select`).toBeTruthy()
    const vsNorm = normVirtualId(vsValue)

    await vsSelect.selectOption(String(vsValue))
    await page.evaluate((val) => {
      const sel = document.querySelector('#target_virtual_site') as HTMLSelectElement | null
      if (!sel) return
      sel.value = val
      const opt = sel.selectedOptions[0]
      const lang = opt?.getAttribute('data-lang') || ''
      const idEl = document.querySelector('#target_site_id') as HTMLInputElement | null
      const langEl = document.querySelector('#target_lang') as HTMLInputElement | null
      if (idEl) idEl.value = val
      if (langEl) langEl.value = lang
      sel.dispatchEvent(new Event('change', { bubbles: true }))
      const w = window as unknown as { jQuery?: (s: string) => { trigger: (e: string) => void } }
      if (typeof w.jQuery === 'function') {
        try {
          w.jQuery('#target_virtual_site').trigger('change')
        } catch {
          /* ignore */
        }
      }
    }, String(vsValue))

    page.on('dialog', (d) => d.accept())

    const srcLang = page.locator('#source_lang')
    if (await srcLang.count()) {
      const val = await srcLang.evaluate((el: HTMLSelectElement) => {
        const opts = Array.from(el.options).filter((o) => o.value)
        if (!el.value && opts[0]) {
          el.value = opts[0].value
          el.dispatchEvent(new Event('change', { bubbles: true }))
        }
        return el.value
      })
      expect(val).toBeTruthy()
    }

    const template = page.locator('#template')
    if (await template.count()) {
      await template.selectOption({ index: 0 }).catch(() => undefined)
    }

    const hiddenReady = await page.evaluate(() => {
      const id = (document.querySelector('#target_site_id') as HTMLInputElement | null)?.value
      const lang = (document.querySelector('#target_lang') as HTMLInputElement | null)?.value
      return Boolean(id && lang)
    })
    expect(hiddenReady, 'target_site_id + target_lang must be set before submit').toBeTruthy()

    // opus5 P-2: the create JS's response body is disposed the moment its
    // .then() navigates (observed 2026-09-21: createdRes.json() → null
    // mid-race), so success is asserted from the landing URL the JS only
    // sets on success, and rid is resolved from the relations list — the
    // owned VS is fresh, so exactly one relation targets it. A failed
    // create alerts (auto-accepted) and never lands → hard failure.
    const landing = page.waitForURL(
      /page=wptsall-sites.*wptsall_relation_created=1/,
      { timeout: 60_000, waitUntil: 'domcontentloaded' },
    )
    await form.locator('button[type="submit"], input[type="submit"]').first().click()
    await landing

    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-sites`, {
      waitUntil: 'domcontentloaded',
    })
    const nonce2 = await restNonce(page)
    expect(nonce2).toBeTruthy()

    const again = await apiJson(
      page.request,
      'GET',
      `${WP_BASE_URL}/wp-json/wptsall/v2/site-relations?per_page=200`,
      nonce2,
    )
    const match = Array.isArray(again.body)
      ? (
          again.body as Array<{ id?: number | string; target_site_id?: string }>
        ).find((r) => normVirtualId(r.target_site_id) === vsNorm)
      : undefined
    const rid = Number(match?.id || 0)
    expect(
      rid,
      `relation for owned target ${vsNorm} must exist after create (list lookup)`,
    ).toBeGreaterThan(0)

    const del = await apiJson(
      page.request,
      'DELETE',
      `${WP_BASE_URL}/wp-json/wptsall/v2/site-relations/${rid}`,
      nonce2,
    )
    expect(del.status, `delete body=${JSON.stringify(del.body)}`).toBeLessThan(500)
    expect(
      [200, 204].includes(del.status) || (del.body as { success?: boolean })?.success === true,
    ).toBeTruthy()
  })
})
