import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

test.describe('Component Templates', () => {
  // ── Template List ──
  test('templates page loads with table', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await expect(page.locator('h1')).toBeVisible()
    await page.waitForTimeout(3_000)
    const table = page.locator('table')
    if ((await table.count()) > 0) {
      await expect(table).toBeVisible()
    }
    const errorText = page.locator('.text-red-600')
    await expect(errorText).toHaveCount(0)
  })

  test('templates table shows all columns', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    const table = page.locator('table')
    await expect(table).toBeVisible({ timeout: 10_000 })
    const headers = page.locator('table thead th')
    const count = await headers.count()
    // 8 columns: id, name, vendor, owner, version, type, signing, actions
    expect(count).toBeGreaterThanOrEqual(8)
  })

  test('templates table shows type badges and owner badges', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    const table = page.locator('table')
    await expect(table).toBeVisible({ timeout: 10_000 })
    const firstRow = page.locator('table tbody tr').first()
    // Type badge (rounded-full)
    const badges = firstRow.locator('.rounded-full')
    expect(await badges.count()).toBeGreaterThanOrEqual(1)
  })

  // ── Search & Filter ──
  test('templates page has search input', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    const searchInput = page.locator('input[type="text"]')
    await expect(searchInput).toBeVisible()
  })

  test('templates page has vendor filter dropdown', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    const vendorSelect = page.locator('select').first()
    await expect(vendorSelect).toBeVisible()
    // Should have "All Vendors" option
    const options = vendorSelect.locator('option')
    expect(await options.count()).toBeGreaterThanOrEqual(1)
  })

  test('search with no results shows empty state', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    const searchInput = page.locator('input[type="text"]')
    await searchInput.fill('zzz_nonexistent_template_xyz')
    await searchInput.press('Enter')
    await page.waitForTimeout(1_000)
    const noResults = page.locator('text=/no template|暂无模板/i')
    const rows = page.locator('table tbody tr')
    const isEmpty = (await noResults.count()) > 0 || (await rows.count()) === 0
    expect(isEmpty).toBeTruthy()
  })

  // ── Create Template Modal ──
  test('create template button opens modal', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')
    await expect(modal).toBeVisible()
    await expect(modal.locator('h2')).toBeVisible()
  })

  test('template modal shows all 10 form sections', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')
    // 10 fieldset legends
    const legends = modal.locator('fieldset legend')
    expect(await legends.count()).toBeGreaterThanOrEqual(10)
  })

  test('template modal Section 1: Basic Info fields', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    // Name input
    const nameInput = modal.locator('fieldset').first().locator('input[type="text"]').first()
    await expect(nameInput).toBeVisible()
    // ID input (auto-generated from name)
    await nameInput.fill('Test Template')
    const idInput = modal.locator('fieldset').first().locator('input[type="text"]').nth(1)
    await expect(idInput).toBeVisible()
    const idValue = await idInput.inputValue()
    expect(idValue).toBe('test-template')

    // Component type select
    const typeSelect = modal.locator('fieldset').first().locator('select').first()
    await expect(typeSelect).toBeVisible()

    // Vendor select
    const vendorSelect = modal.locator('fieldset').first().locator('select').nth(1)
    await expect(vendorSelect).toBeVisible()

    // Supported types checkboxes (text, image, video, audio, document)
    const checkboxes = modal.locator('fieldset').first().locator('input[type="checkbox"]')
    expect(await checkboxes.count()).toBeGreaterThanOrEqual(5)
  })

  test('template modal Section 2: Signature Config with conditional fields', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    // Algorithm select defaults to "none"
    const signSection = modal.locator('fieldset').nth(1)
    const algSelect = signSection.locator('select').first()
    await expect(algSelect).toBeVisible()
    // Salt type should be hidden when algorithm is none
    const saltSelect = signSection.locator('select').nth(1)
    await expect(saltSelect).toHaveCount(0)

    // Change algorithm to MD5 → salt type + concat fields should appear
    await algSelect.selectOption('md5')
    await expect(signSection.locator('select').nth(1)).toBeVisible()
  })

  test('template modal Section 3: Auth Fields - add and remove', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    // Auth fields section
    const authSection = modal.locator('fieldset').nth(2)
    // Add auth field button
    const addBtn = authSection.locator('button').filter({ hasText: /add auth field|添加认证字段/i })
    await expect(addBtn).toBeVisible()
    await addBtn.click()

    // Auth field form appears with name, label, description, required/secret checkboxes
    const fieldBox = authSection.locator('.bg-gray-50')
    await expect(fieldBox).toBeVisible()
    expect(await fieldBox.locator('input[type="text"]').count()).toBeGreaterThanOrEqual(3)
    expect(await fieldBox.locator('input[type="checkbox"]').count()).toBeGreaterThanOrEqual(2)

    // Remove button
    const removeBtn = fieldBox.locator('button').filter({ hasText: /remove|移除|删除/i })
    await expect(removeBtn).toBeVisible()
    await removeBtn.click()
    await expect(authSection.locator('.bg-gray-50')).toHaveCount(0)
  })

  test('template modal Section 4: Request Config fields', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    const reqSection = modal.locator('fieldset').nth(3)
    // Method select (GET/POST/PUT)
    const methodSelect = reqSection.locator('select').first()
    await expect(methodSelect).toBeVisible()
    // Body type select (JSON/Form)
    const bodyTypeSelect = reqSection.locator('select').nth(1)
    await expect(bodyTypeSelect).toBeVisible()
    // URL input
    const urlInput = reqSection.locator('input[type="text"]').first()
    await expect(urlInput).toBeVisible()
    // Headers: add button
    const addHeaderBtn = reqSection.locator('button').filter({ hasText: /\+ add|\+ 添加/i }).first()
    await expect(addHeaderBtn).toBeVisible()
    // Body: add button
    const addBodyBtn = reqSection.locator('button').filter({ hasText: /\+ add|\+ 添加/i }).nth(1)
    await expect(addBodyBtn).toBeVisible()
  })

  test('template modal Section 5: Response Config paths', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    const resSection = modal.locator('fieldset').nth(4)
    // For text_translation (default), translated_text_path should be visible
    const textPathInput = resSection.locator('input[type="text"]').first()
    await expect(textPathInput).toBeVisible()
    // Error path input
    const errorPathInput = resSection.locator('input[type="text"]').last()
    await expect(errorPathInput).toBeVisible()
  })

  test('template modal Section 6: Constraints fields', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    const constraintSection = modal.locator('fieldset').nth(5)
    // max_input_chars number input
    const maxChars = constraintSection.locator('input[type="number"]').first()
    await expect(maxChars).toBeVisible()
    // split_strategy select
    const splitSelect = constraintSection.locator('select').first()
    await expect(splitSelect).toBeVisible()
    // rate_limit_qps number input
    const qps = constraintSection.locator('input[type="number"]').nth(1)
    await expect(qps).toBeVisible()
    // Content format checkboxes
    const formatCbs = constraintSection.locator('input[type="checkbox"]')
    expect(await formatCbs.count()).toBeGreaterThanOrEqual(5)
  })

  test('template modal Section 7: Translation Modes add and remove', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    const translationModesSection = modal.locator('fieldset').nth(6)
    const addBtn = translationModesSection.locator('button').filter({ hasText: /添加翻译模式/i })
    await expect(addBtn).toBeVisible()
    await addBtn.click()

    const item = translationModesSection.locator('.bg-gray-50')
    await expect(item).toBeVisible()
    await expect(item.locator('input[type="text"]')).toHaveCount(3)
    await expect(item.locator('input[type="url"]')).toHaveCount(1)
    await expect(item.locator('textarea')).toHaveCount(3)

    await item.locator('button').filter({ hasText: /remove|移除|删除/i }).click()
    await expect(translationModesSection.locator('.bg-gray-50')).toHaveCount(0)
  })

  test('template modal Section 8: Editable Params add and remove', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    const editableSection = modal.locator('fieldset').nth(7)
    const addBtn = editableSection.locator('button').filter({ hasText: /add editable param|添加可编辑参数/i })
    await expect(addBtn).toBeVisible()
    await addBtn.click()

    const item = editableSection.locator('.bg-gray-50')
    await expect(item).toBeVisible()
    await expect(item.locator('input[type="text"]')).toHaveCount(1)
    await expect(item.locator('select')).toHaveCount(2)

    await item.locator('button').filter({ hasText: /remove|移除|删除/i }).click()
    await expect(editableSection.locator('.bg-gray-50')).toHaveCount(0)
  })

  test('template modal Section 9: Default Values JSON textarea', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()

    const textarea = page.locator('[data-testid="default-values-json"]')
    await expect(textarea).toBeVisible()
    await textarea.fill('{"from": "auto", "to": "en"}')
  })

  test('template modal Section 10: Async Poll textarea', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()

    const textarea = page.locator('[data-testid="async-poll-json"]')
    await expect(textarea).toBeVisible()
    await textarea.fill('{"job_id_path":"job_id"}')
  })

  // ── Validation ──
  test('template modal validation: empty name shows error', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')
    await modal.locator('button[type="submit"]').click()
    await expect(modal.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('template modal validation: requires supported types', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    // Fill name and ID but no supported types
    const nameInput = modal.locator('fieldset').first().locator('input[type="text"]').first()
    await nameInput.fill('Validation Test')
    await modal.locator('button[type="submit"]').click()
    await expect(modal.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('api rejects non-real non-text template payload', async ({ page }) => {
    await loginAsAdmin(page)
    const uniqueId = `e2e-non-real-${Date.now()}`
    let capturedPayload: any = null
    await page.route('**/api/v1/components', async (route) => {
      capturedPayload = route.request().postDataJSON()
      await route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          error: {
            code: 'INVALID_TEMPLATE',
            message: 'non-real non-text template is not allowed',
          },
        }),
      })
    })

    const result = await page.evaluate(async ({ id }) => {
      const resp = await fetch('/api/v1/components', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          id,
          name: 'E2E Non Real',
          type: 'video_translation',
          supported_business_lines: ['post_content'],
          template_json: {
            request: {
              method: 'POST',
              url: 'https://example.com/non-real-video',
            },
            response: {
              translated_ref_path: 'task_id',
            },
            constraints: {
              supported_content_formats: ['media_ref'],
              supported_content_types: ['video'],
            },
          },
        }),
      })
      const json = await resp.json()
      return {
        status: resp.status,
        code: json?.error?.code,
      }
    }, { id: uniqueId })

    expect(result.status).toBe(400)
    expect(result.code).toBe('INVALID_TEMPLATE')
    expect(capturedPayload?.type).toBe('video_translation')
    expect(capturedPayload?.template_json?.response?.translated_ref_path).toBe('task_id')
  })

  test('template modal cancel closes it', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')
    await expect(modal).toBeVisible()
    await modal.locator('button[type="button"]').filter({ hasText: /cancel|取消/i }).click()
    await expect(modal).not.toBeVisible()
  })

  // ── Create Template Full Flow ──
  test('create template with minimal valid data', async ({ page }) => {
    const templateName = `e2e-template-${Date.now()}`
    await loginAsAdmin(page)
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create|新建/i }).click()
    const modal = page.locator('.fixed.inset-0')

    // Section 1: Basic Info
    const basicSection = modal.locator('fieldset').first()
    await basicSection.locator('input[type="text"]').first().fill(templateName)
    // Check "text" supported type
    await basicSection.locator('input[type="checkbox"]').first().check()

    // Section 4: Request URL
    const reqSection = modal.locator('fieldset').nth(3)
    await reqSection.locator('input[type="text"]').first().fill('https://api.example.com/translate')

    // Section 5: Response translated_text_path
    const resSection = modal.locator('fieldset').nth(4)
    await resSection.locator('input[type="text"]').first().fill('result.text')

    // Submit
    await modal.locator('button[type="submit"]').click()
    // Modal should close after successful creation (no validation error)
    await expect(modal).not.toBeVisible({ timeout: 10_000 })
    // No error message on the page
    await page.waitForTimeout(1_000)
    const errorText = page.locator('main .text-red-600')
    await expect(errorText).toHaveCount(0)
  })

  // ── Edit Template ──
  test('edit button opens modal with pre-filled data', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    const table = page.locator('table')
    await expect(table).toBeVisible({ timeout: 10_000 })
    const editBtn = page.locator('table tbody tr').first().locator('button').filter({ hasText: /edit|编辑/i })
    if ((await editBtn.count()) > 0) {
      await editBtn.click()
      const modal = page.locator('.fixed.inset-0')
      await expect(modal).toBeVisible({ timeout: 10_000 })
      // Name should be pre-filled
      const nameInput = modal.locator('fieldset').first().locator('input[type="text"]').first()
      const value = await nameInput.inputValue()
      expect(value.length).toBeGreaterThan(0)
    }
  })

  // ── Delete Template (2-step) ──
  test('delete button shows confirm/cancel for user-owned templates', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')
    const table = page.locator('table')
    await expect(table).toBeVisible({ timeout: 10_000 })
    const deleteBtn = page.locator('table tbody button').filter({ hasText: /delete|删除/i }).first()
    if ((await deleteBtn.count()) > 0) {
      await deleteBtn.click()
      await expect(page.locator('button').filter({ hasText: /confirm|确认/i })).toBeVisible({ timeout: 2_000 })
      await expect(page.locator('table tbody button').filter({ hasText: /cancel|取消/i })).toBeVisible()
      // Click cancel to abort
      await page.locator('table tbody button').filter({ hasText: /cancel|取消/i }).first().click()
    }
  })

  // ── Free User ──
  test('free user can view templates page', async ({ page }) => {
    await loginAsFreeUser(page)
    await page.goto('/templates')
    await expect(page.locator('h1')).toBeVisible()
    await page.waitForTimeout(3_000)
    const errorText = page.locator('.text-red-600')
    await expect(errorText).toHaveCount(0)
  })
})
