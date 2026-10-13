import { test, expect, type Locator, type Page } from '../lib/page-errors'
import { loginAsAdmin } from './helpers'

type AuthFieldPatch = {
  name: string
  label: string
  description: string
  required: boolean
  secret: boolean
}

type TemplateAuthPatch = {
  id: string
  authModes: Array<'key' | 'oauth' | 'none'>
  signAlgorithm: 'custom_header'
  signAdvanced: Record<string, unknown>
  authFields: AuthFieldPatch[]
  requestUrl?: string
  requestHeaders?: Array<{ key: string; value: string }>
  removeAuthorizationHeader?: boolean
  defaultValuesJson?: string
}

const PATCHES: TemplateAuthPatch[] = [
  {
    id: 'official-azure-v1',
    authModes: ['key', 'oauth'],
    signAlgorithm: 'custom_header',
    signAdvanced: {
      auth_value_key: 'auth.access_token',
      auth_prefix: 'Bearer',
    },
    authFields: [
      {
        name: 'subscription_key',
        label: 'Subscription Key',
        description: 'Azure Cognitive Services subscription key',
        required: false,
        secret: true,
      },
      {
        name: 'access_token',
        label: 'Access Token',
        description: 'Microsoft Entra ID bearer token (OAuth 2.0)',
        required: false,
        secret: true,
      },
      {
        name: 'region',
        label: 'Region',
        description: 'Azure region (e.g. eastus)',
        required: true,
        secret: false,
      },
    ],
  },
  {
    id: 'official-azure-doc-v1',
    authModes: ['key', 'oauth'],
    signAlgorithm: 'custom_header',
    signAdvanced: {
      auth_value_key: 'auth.access_token',
      auth_prefix: 'Bearer',
    },
    authFields: [
      {
        name: 'subscription_key',
        label: 'Subscription Key',
        description: 'Azure Cognitive Services subscription key',
        required: false,
        secret: true,
      },
      {
        name: 'access_token',
        label: 'Access Token',
        description: 'Microsoft Entra ID bearer token (OAuth 2.0)',
        required: false,
        secret: true,
      },
      {
        name: 'endpoint',
        label: 'Endpoint',
        description: 'Azure resource endpoint URL',
        required: true,
        secret: false,
      },
    ],
  },
  {
    id: 'official-azure-openai-text-v1',
    authModes: ['key', 'oauth'],
    signAlgorithm: 'custom_header',
    signAdvanced: {
      auth_value_key: 'auth.access_token',
      auth_prefix: 'Bearer',
    },
    authFields: [
      {
        name: 'api_key',
        label: 'API Key',
        description: 'Azure OpenAI key',
        required: false,
        secret: true,
      },
      {
        name: 'access_token',
        label: 'Access Token',
        description: 'Microsoft Entra ID bearer token (OAuth 2.0)',
        required: false,
        secret: true,
      },
    ],
    requestHeaders: [
      { key: 'Content-Type', value: 'application/json' },
      { key: 'api-key', value: '{{auth.api_key}}' },
    ],
    requestUrl: 'https://{{default_values.resource_name}}.openai.azure.com/openai/deployments/{{default_values.deployment}}/chat/completions?api-version={{default_values.api_version}}',
    defaultValuesJson: JSON.stringify({
      resource_name: 'openai',
      deployment: 'gpt-4o-mini',
      api_version: '2024-10-21',
    }, null, 2),
  },
  {
    id: 'official-azure-openai-v1',
    authModes: ['key', 'oauth'],
    signAlgorithm: 'custom_header',
    signAdvanced: {
      auth_value_key: 'auth.access_token',
      auth_prefix: 'Bearer',
    },
    authFields: [
      {
        name: 'api_key',
        label: 'API Key',
        description: 'Azure OpenAI key',
        required: false,
        secret: true,
      },
      {
        name: 'access_token',
        label: 'Access Token',
        description: 'Microsoft Entra ID bearer token (OAuth 2.0)',
        required: false,
        secret: true,
      },
    ],
    defaultValuesJson: JSON.stringify({
      resource_name: 'openai',
      deployment: 'gpt-4o-mini',
      api_version: '2024-10-21',
    }, null, 2),
  },
  {
    id: 'official-yandex-v2',
    authModes: ['key', 'oauth'],
    signAlgorithm: 'custom_header',
    signAdvanced: {
      auth_candidates: [
        { value_key: 'auth.access_token', prefix: 'Bearer' },
        { value_key: 'auth.api_key', prefix: 'Api-Key' },
      ],
    },
    authFields: [
      {
        name: 'api_key',
        label: 'API Key',
        description: 'Yandex Cloud API key',
        required: false,
        secret: true,
      },
      {
        name: 'access_token',
        label: 'IAM Token',
        description: 'Yandex IAM token (OAuth 2.0 style bearer)',
        required: false,
        secret: true,
      },
      {
        name: 'folder_id',
        label: 'Folder ID',
        description: 'Yandex Cloud folder ID',
        required: true,
        secret: false,
      },
    ],
    removeAuthorizationHeader: true,
  },
  {
    id: 'official-systran-text-v1',
    authModes: ['key', 'oauth'],
    signAlgorithm: 'custom_header',
    signAdvanced: {
      auth_candidates: [
        { value_key: 'auth.access_token', prefix: 'Bearer' },
        { value_key: 'auth.api_key', prefix: 'Key' },
      ],
    },
    authFields: [
      {
        name: 'api_key',
        label: 'API Key',
        description: 'Systran API key',
        required: false,
        secret: true,
      },
      {
        name: 'access_token',
        label: 'Access Token',
        description: 'Systran OAuth2 bearer token',
        required: false,
        secret: true,
      },
    ],
    removeAuthorizationHeader: true,
  },
]

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

async function findAuthFieldRow(authSection: Locator, fieldName: string): Promise<Locator | null> {
  const rows = authSection.locator('.bg-gray-50.border.border-gray-200')
  const count = await rows.count()
  for (let i = 0; i < count; i += 1) {
    const row = rows.nth(i)
    const nameValue = (await row.locator('input[type="text"]').first().inputValue()).trim()
    if (nameValue === fieldName) {
      return row
    }
  }
  return null
}

async function upsertAuthField(authSection: Locator, spec: AuthFieldPatch): Promise<void> {
  let row = await findAuthFieldRow(authSection, spec.name)
  if (!row) {
    await authSection.locator('button').filter({ hasText: /add auth field|添加认证字段/i }).click()
    const rows = authSection.locator('.bg-gray-50.border.border-gray-200')
    row = rows.nth((await rows.count()) - 1)
  }

  const textInputs = row.locator('input[type="text"]')
  await textInputs.nth(0).fill(spec.name)
  await textInputs.nth(1).fill(spec.label)
  await textInputs.nth(2).fill(spec.description)

  const flags = row.locator('input[type="checkbox"]')
  await setCheckbox(flags.nth(0), spec.required)
  await setCheckbox(flags.nth(1), spec.secret)
}

async function pruneAuthFields(page: Page, authSection: Locator, allowedNames: string[]): Promise<void> {
  while (true) {
    const rows = authSection.locator('.bg-gray-50.border.border-gray-200')
    const count = await rows.count()
    let removed = false
    for (let i = 0; i < count; i += 1) {
      const row = rows.nth(i)
      const current = (await row.locator('input[type="text"]').first().inputValue()).trim()
      if (current && !allowedNames.includes(current)) {
        await row.locator('button').filter({ hasText: /remove|移除|删除/i }).click()
        await pageWaitTick(page)
        removed = true
        break
      }
    }
    if (!removed) break
  }
}

async function removeAuthorizationHeader(page: Page, requestSection: Locator): Promise<void> {
  while (true) {
    const headerRows = requestSection.locator('div.flex.gap-2.mb-1:has(input[placeholder="Value"])')

    const count = await headerRows.count()
    let removed = false
    for (let i = 0; i < count; i += 1) {
      const row = headerRows.nth(i)
      const keyValue = (await row.locator('input[placeholder="Key"]').inputValue()).trim().toLowerCase()
      if (keyValue === 'authorization') {
        await row.locator('button').filter({ hasText: /remove|移除|删除/i }).click()
        removed = true
        await pageWaitTick(page)
        break
      }
    }
    if (!removed) {
      break
    }
  }
}

async function upsertRequestHeader(
  requestSection: Locator,
  key: string,
  value: string,
): Promise<void> {
  const headerRows = requestSection.locator('div.flex.gap-2.mb-1:has(input[placeholder="Value"])')
  const count = await headerRows.count()
  for (let i = 0; i < count; i += 1) {
    const row = headerRows.nth(i)
    const keyInput = row.locator('input[placeholder="Key"]')
    const current = (await keyInput.inputValue()).trim().toLowerCase()
    if (current === key.trim().toLowerCase()) {
      await keyInput.fill(key)
      await row.locator('input[placeholder="Value"]').fill(value)
      return
    }
  }
  const requestAddBtn = requestSection.locator('button').filter({ hasText: /\+ add|\+ 添加/i }).first()
  await requestAddBtn.click()
  const updatedRows = requestSection.locator('div.flex.gap-2.mb-1:has(input[placeholder="Value"])')
  const row = updatedRows.nth((await updatedRows.count()) - 1)
  await row.locator('input[placeholder="Key"]').fill(key)
  await row.locator('input[placeholder="Value"]').fill(value)
}

async function setRequestUrl(requestSection: Locator, value: string): Promise<void> {
  await requestSection.locator('input[type="text"]').first().fill(value)
}

async function setDefaultValuesJson(modal: Locator, raw: string): Promise<void> {
  const defaultValues = modal.locator('fieldset').nth(8)
  await defaultValues.locator('textarea').fill(raw)
}

async function pageWaitTick(page: Page): Promise<void> {
  await page.waitForTimeout(150)
}

async function readModalFormError(modal: Locator): Promise<string> {
  const error = modal.locator('p.text-sm.text-red-600').first()
  if ((await error.count()) === 0) {
    return ''
  }
  const visible = await error.isVisible().catch(() => false)
  if (!visible) {
    return ''
  }
  return ((await error.textContent()) || '').trim()
}

async function applyPatchToModal(page: Page, patch: TemplateAuthPatch): Promise<void> {
  const modal = page.locator('.fixed.inset-0')
  await expect(modal).toBeVisible()

  const signSection = modal.locator('fieldset').nth(1)
  await signSection.locator('select').first().selectOption(patch.signAlgorithm)
  const signAdvanced = signSection.locator('textarea').first()
  await signAdvanced.fill(JSON.stringify(patch.signAdvanced, null, 2))

  const authSection = modal.locator('fieldset').nth(2)
  await setCheckbox(authSection.locator('label:has-text("Key") input[type="checkbox"]').first(), patch.authModes.includes('key'))
  await setCheckbox(authSection.locator('label:has-text("OAuth") input[type="checkbox"]').first(), patch.authModes.includes('oauth'))
  await setCheckbox(authSection.locator('label:has-text("None") input[type="checkbox"]').first(), patch.authModes.includes('none'))

  for (const field of patch.authFields) {
    await upsertAuthField(authSection, field)
  }
  await pruneAuthFields(page, authSection, patch.authFields.map((field) => field.name))

  if (patch.removeAuthorizationHeader) {
    const requestSection = modal.locator('fieldset').nth(3)
    await removeAuthorizationHeader(page, requestSection)
  }
  if (patch.requestUrl) {
    const requestSection = modal.locator('fieldset').nth(3)
    await setRequestUrl(requestSection, patch.requestUrl)
  }
  if (patch.requestHeaders && patch.requestHeaders.length > 0) {
    const requestSection = modal.locator('fieldset').nth(3)
    for (const header of patch.requestHeaders) {
      await upsertRequestHeader(requestSection, header.key, header.value)
    }
  }
  if (patch.defaultValuesJson !== undefined) {
    await setDefaultValuesJson(modal, patch.defaultValuesJson)
  }

  await modal.locator('button[type="submit"]').click()
  try {
    await expect(modal).toBeHidden({ timeout: 20_000 })
  } catch (err) {
    const formError = await readModalFormError(modal)
    const detail = formError || (err instanceof Error ? err.message : String(err))
    throw new Error(`save failed: ${detail}`)
  }
}

test.describe('Template Auth Modes Migration', () => {
  test('batch update existing templates through admin WebUI', async ({ page }) => {
    test.setTimeout(15 * 60 * 1000)
    await loginAsAdmin(page)

    const missing: string[] = []
    const updated: string[] = []
    const failed: string[] = []

    for (const patch of PATCHES) {
      // Keep per-template operations isolated: open page, search, edit, save.
      console.log(`[RUN] processing template: ${patch.id}`)
      const found = await openTemplateEditorById(page, patch.id)
      if (!found) {
        console.log(`[SKIP] template not found: ${patch.id}`)
        missing.push(patch.id)
        continue
      }
      try {
        await applyPatchToModal(page, patch)
        console.log(`[OK] updated template: ${patch.id}`)
        updated.push(patch.id)
      } catch (err) {
        const detail = err instanceof Error ? err.message : String(err)
        console.log(`[FAIL] ${patch.id}: ${detail}`)
        failed.push(patch.id)

        const modal = page.locator('.fixed.inset-0')
        if (await modal.isVisible()) {
          const cancelBtn = modal.locator('button').filter({ hasText: /cancel|取消/i }).first()
          if ((await cancelBtn.count()) > 0) {
            await cancelBtn.click()
            await page.waitForTimeout(300)
          }
        }
      }
    }

    console.log(`[SUMMARY] updated=${updated.length}, missing=${missing.length}, failed=${failed.length}`)
    if (missing.length > 0) {
      console.log(`[MISSING] ${missing.join(', ')}`)
    }
    if (failed.length > 0) {
      console.log(`[FAILED] ${failed.join(', ')}`)
    }

    expect(updated.length).toBeGreaterThan(0)
    expect(failed).toEqual([])
  })
})
