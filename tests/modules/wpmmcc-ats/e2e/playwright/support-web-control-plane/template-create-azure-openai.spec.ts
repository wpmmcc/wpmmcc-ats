import { test, expect, type Locator, type Page } from '../lib/page-errors'
import { loginAsAdmin } from './helpers'

async function setCheckbox(locator: Locator, checked: boolean): Promise<void> {
  await expect(locator).toBeVisible()
  const current = await locator.isChecked()
  if (current === checked) return
  if (checked) {
    await locator.check()
  } else {
    await locator.uncheck()
  }
}

async function upsertAuthField(
  authSection: Locator,
  spec: {
    name: string
    label: string
    description: string
    required: boolean
    secret: boolean
  },
): Promise<void> {
  const rows = authSection.locator('.bg-gray-50.border.border-gray-200')
  const count = await rows.count()
  for (let i = 0; i < count; i += 1) {
    const row = rows.nth(i)
    const current = (await row.locator('input[type="text"]').first().inputValue()).trim()
    if (current === spec.name) {
      const textInputs = row.locator('input[type="text"]')
      await textInputs.nth(0).fill(spec.name)
      await textInputs.nth(1).fill(spec.label)
      await textInputs.nth(2).fill(spec.description)
      const flags = row.locator('input[type="checkbox"]')
      await setCheckbox(flags.nth(0), spec.required)
      await setCheckbox(flags.nth(1), spec.secret)
      return
    }
  }

  await authSection.locator('button').filter({ hasText: /add auth field|添加认证字段/i }).click()
  const newRows = authSection.locator('.bg-gray-50.border.border-gray-200')
  const row = newRows.nth((await newRows.count()) - 1)
  const textInputs = row.locator('input[type="text"]')
  await textInputs.nth(0).fill(spec.name)
  await textInputs.nth(1).fill(spec.label)
  await textInputs.nth(2).fill(spec.description)
  const flags = row.locator('input[type="checkbox"]')
  await setCheckbox(flags.nth(0), spec.required)
  await setCheckbox(flags.nth(1), spec.secret)
}

async function addTranslationMode(
  modesSection: Locator,
  spec: { id: string; label: string; fmt: string },
) {
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

async function setDefaultValuesJson(modal: Locator, raw: string) {
  const defaultValuesSection = modal.locator('fieldset').nth(8)
  await defaultValuesSection.locator('textarea').fill(raw)
}

async function setRequestBodyJson(modal: Locator, bodyJson: string) {
  const requestSection = modal.locator('fieldset').nth(3)
  await requestSection.locator('[data-testid="request-body-editor-mode"]').selectOption('json')
  await requestSection.locator('[data-testid="request-body-json"]').fill(bodyJson)
}

async function openTemplateEditorById(page: Page, templateId: string): Promise<boolean> {
  await page.goto('/templates')
  const searchInput = page.locator('input[type="text"]').first()
  await searchInput.fill(templateId)
  await searchInput.press('Enter')
  await page.waitForTimeout(600)

  const row = page.locator('table tbody tr', { hasText: templateId }).first()
  if ((await row.count()) === 0) {
    return false
  }
  const editBtn = row.locator('button').filter({ hasText: /edit|编辑/i }).first()
  await editBtn.click()
  await expect(page.locator('.fixed.inset-0')).toBeVisible()
  return true
}

test.describe('Create Missing Azure OpenAI Template', () => {
  test('create official-azure-openai-text-v1 if missing via WebUI', async ({ page }) => {
    test.setTimeout(90 * 1000)
    console.log('[STEP] login')
    await loginAsAdmin(page)

    const templateId = 'official-azure-openai-text-v1'
    console.log('[STEP] check existing')
    const exists = await openTemplateEditorById(page, templateId)
    if (exists) {
      console.log(`[SKIP] already exists: ${templateId}`)
      return
    }

    console.log('[STEP] open create modal')
    await page.goto('/templates')
    await page.locator('button').filter({ hasText: /create template|create|新建/i }).first().click()
    const modal = page.locator('.fixed.inset-0')
    await expect(modal).toBeVisible()

    console.log('[STEP] fill basic')
    const basic = modal.locator('fieldset').nth(0)
    await basic.locator('input[type="text"]').nth(0).fill('Azure OpenAI Text Translation')
    await basic.locator('input[type="text"]').nth(1).fill(templateId)

    const basicSelects = basic.locator('select')
    if ((await basicSelects.count()) >= 1) {
      await basicSelects.nth(0).selectOption('official')
    }
    if ((await basicSelects.count()) >= 2) {
      await basicSelects.nth(1).selectOption('text_translation')
    }

    const apiDocsUrlInput = basic.locator('input[type="url"]').first()
    if ((await apiDocsUrlInput.count()) > 0) {
      await apiDocsUrlInput.fill('https://learn.microsoft.com/azure/ai-services/openai/reference')
    }

    await setCheckbox(
      basic.locator('label:has-text("text") input[type="checkbox"]').first(),
      true,
    )
    await setCheckbox(
      basic.locator('label:has-text("custom_model") input[type="checkbox"]').first(),
      true,
    )
    await setCheckbox(
      basic.locator('label:has-text("post_content") input[type="checkbox"]').first(),
      true,
    )
    await setCheckbox(
      basic.locator('label:has-text("taxonomy_content") input[type="checkbox"]').first(),
      true,
    )
    await setCheckbox(
      basic.locator('label:has-text("plugin_i18n") input[type="checkbox"]').first(),
      true,
    )
    await setCheckbox(
      basic.locator('label:has-text("theme_i18n") input[type="checkbox"]').first(),
      true,
    )

    console.log('[STEP] fill sign')
    const signSection = modal.locator('fieldset').nth(1)
    await signSection.locator('select').first().selectOption('custom_header')
    await signSection.locator('textarea').first().fill(
      JSON.stringify(
        {
          auth_value_key: 'auth.access_token',
          auth_prefix: 'Bearer',
        },
        null,
        2,
      ),
    )

    console.log('[STEP] fill auth')
    const authSection = modal.locator('fieldset').nth(2)
    await setCheckbox(authSection.locator('label:has-text("Key") input[type="checkbox"]').first(), true)
    await setCheckbox(authSection.locator('label:has-text("OAuth") input[type="checkbox"]').first(), true)
    await setCheckbox(authSection.locator('label:has-text("None") input[type="checkbox"]').first(), false)

    await upsertAuthField(authSection, {
      name: 'api_key',
      label: 'API Key',
      description: 'Azure OpenAI key',
      required: false,
      secret: true,
    })
    await upsertAuthField(authSection, {
      name: 'access_token',
      label: 'Access Token',
      description: 'Microsoft Entra ID bearer token (OAuth 2.0)',
      required: false,
      secret: true,
    })
    console.log('[STEP] fill request')
    const requestSection = modal.locator('fieldset').nth(3)
    const reqSelects = requestSection.locator('select')
    await reqSelects.nth(0).selectOption('POST')
    await reqSelects.nth(1).selectOption('json')
    await requestSection
      .locator('input[type="text"]')
      .first()
      .fill(
        'https://{{default_values.resource_name}}.openai.azure.com/openai/deployments/{{default_values.deployment}}/chat/completions?api-version={{default_values.api_version}}',
      )
    await setRequestBodyJson(
      modal,
      JSON.stringify(
        {
          messages: [
            {
              role: 'system',
              content: 'You are a translator. Translate to {{input.target_lang}} and output only translation.',
            },
            { role: 'user', content: '{{input.text}}' },
          ],
          temperature: 0.1,
        },
        null,
        2,
      ),
    )

    console.log('[STEP] fill response')
    const responseSection = modal.locator('fieldset').nth(4)
    await responseSection.locator('input[type="text"]').nth(0).fill('choices.0.message.content')
    await responseSection.locator('input[type="text"]').last().fill('error.message')

    console.log('[STEP] fill constraints')
    const constraintsSection = modal.locator('fieldset').nth(5)
    await constraintsSection
      .locator('label')
      .filter({ hasText: /^text$/i })
      .locator('input[type="checkbox"]')
      .first()
      .check()
    for (const fmt of ['plain_text', 'rich_html', 'json_structured', 'serialized_php']) {
      await constraintsSection
        .locator('label')
        .filter({ hasText: new RegExp(`^${fmt}$`) })
        .locator('input[type="checkbox"]')
        .check()
    }

    console.log('[STEP] fill translation modes')
    const modesSection = modal.locator('fieldset').nth(6)
    for (const [id, label, fmt] of [
      ['plain', 'Plain Text', 'plain_text'],
      ['html', 'HTML', 'rich_html'],
      ['json', 'JSON Structured', 'json_structured'],
      ['serialized', 'Serialized PHP', 'serialized_php'],
    ]) {
      await addTranslationMode(modesSection, { id, label, fmt })
    }

    console.log('[STEP] fill default values')
    await setDefaultValuesJson(
      modal,
      JSON.stringify(
        {
          resource_name: 'openai',
          deployment: 'gpt-4o-mini',
          api_version: '2024-10-21',
        },
        null,
        2,
      ),
    )

    console.log('[STEP] submit')
    await modal.locator('button[type="submit"]').click()
    await expect(modal).toBeHidden({ timeout: 20_000 })

    console.log('[STEP] verify created')
    const foundAfterCreate = await openTemplateEditorById(page, templateId)
    expect(foundAfterCreate).toBe(true)
    if (foundAfterCreate) {
      const openedModal = page.locator('.fixed.inset-0')
      await openedModal.locator('button').filter({ hasText: /cancel|取消/i }).first().click()
      await expect(openedModal).toBeHidden({ timeout: 5000 })
    }
    console.log(`[OK] created: ${templateId}`)
  })
})
