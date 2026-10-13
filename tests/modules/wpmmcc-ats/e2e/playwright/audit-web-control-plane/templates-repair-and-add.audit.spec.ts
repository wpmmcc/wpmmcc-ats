import { test, expect } from '@playwright/test'
import { loginAsAdmin } from '../support-web-control-plane/helpers'

type RepairSpec = {
  id: string
  requestUrl?: string
  requestBodyJson?: string
  responseTranslatedTextPath?: string
  responseErrorPath?: string
  signAlgorithm?: string
  signAdvancedJson?: string
}

async function searchTemplateById(page: import('@playwright/test').Page, id: string) {
  const search = page.locator('[data-testid="templates-search"]')
  await search.fill(id)
  await search.press('Enter')
  await page.waitForTimeout(600)
}

async function openEditModal(page: import('@playwright/test').Page, id: string) {
  await searchTemplateById(page, id)
  const row = page.locator('tbody tr').filter({ hasText: id }).first()
  if ((await row.count()) === 0) return null
  await row.locator('button').filter({ hasText: /edit|编辑/i }).click()
  const modal = page.locator('.fixed.inset-0')
  await expect(modal).toBeVisible()
  return modal
}

async function openCreateModal(page: import('@playwright/test').Page) {
  await page.locator('[data-testid="templates-create"]').click()
  const modal = page.locator('.fixed.inset-0')
  await expect(modal).toBeVisible()
  return modal
}

async function setRequestBodyJson(modal: import('@playwright/test').Locator, bodyJson: string) {
  const req = modal.locator('fieldset').nth(3)
  await req.locator('[data-testid="request-body-editor-mode"]').selectOption('json')
  await req.locator('[data-testid="request-body-json"]').fill(bodyJson)
}

async function setAsyncPollJson(modal: import('@playwright/test').Locator, asyncPollJson: string) {
  const asyncPoll = modal.locator('fieldset').nth(9)
  await asyncPoll.locator('textarea').fill(asyncPollJson)
}

async function setDefaultValuesJson(modal: import('@playwright/test').Locator, raw: string) {
  const defaultValues = modal.locator('fieldset').nth(8)
  await defaultValues.locator('textarea').fill(raw)
}

async function applyRepair(modal: import('@playwright/test').Locator, spec: RepairSpec) {
  if (spec.signAlgorithm) {
    const sign = modal.locator('fieldset').nth(1)
    await sign.locator('select').first().selectOption(spec.signAlgorithm)
    if (spec.signAdvancedJson !== undefined) {
      await sign.locator('textarea').first().fill(spec.signAdvancedJson)
    }
  }

  if (spec.requestUrl || spec.requestBodyJson) {
    const req = modal.locator('fieldset').nth(3)
    if (spec.requestUrl) {
      await req.locator('input[type="text"]').first().fill(spec.requestUrl)
    }
    if (spec.requestBodyJson) {
      await setRequestBodyJson(modal, spec.requestBodyJson)
    }
  }

  if (spec.responseTranslatedTextPath || spec.responseErrorPath) {
    const res = modal.locator('fieldset').nth(4)
    const inputs = res.locator('input[type="text"]')
    if (spec.responseTranslatedTextPath) {
      await inputs.first().fill(spec.responseTranslatedTextPath)
    }
    if (spec.responseErrorPath) {
      await inputs.last().fill(spec.responseErrorPath)
    }
  }

  await modal.locator('button[type="submit"]').click()
  await expect(modal).not.toBeVisible({ timeout: 10_000 })
}

test.describe('Templates Repair + Add Missing', () => {
  test('repair broken official templates and add DeepL Pro', async ({ page }) => {
    test.setTimeout(180_000)
    await loginAsAdmin(page)
    await page.goto('/templates')

    const repairs: RepairSpec[] = [
      {
        id: 'official-deepl-v1',
        requestUrl: 'https://api-free.deepl.com/v2/translate',
        requestBodyJson: JSON.stringify(
          {
            text: ['{{input.text}}'],
            target_lang: '{{input.target_lang}}',
            tag_handling: 'html',
          },
          null,
          2,
        ),
        responseTranslatedTextPath: 'translations.0.text',
        responseErrorPath: 'message',
      },
      {
        id: 'official-aws-doc-v1',
        requestBodyJson: JSON.stringify(
          {
            Document: {
              Content: '{{input.source_base64}}',
              ContentType: '{{input.source_mime}}',
            },
            SourceLanguageCode: '{{input.source_lang}}',
            TargetLanguageCode: '{{input.target_lang}}',
          },
          null,
          2,
        ),
      },
      {
        id: 'official-azure-doc-v1',
        requestBodyJson: JSON.stringify(
          {
            document: '@file:{{input.source_ref}}',
          },
          null,
          2,
        ),
      },
      {
        id: 'official-google-doc-v3',
        requestBodyJson: JSON.stringify(
          {
            sourceLanguageCode: '{{input.source_lang}}',
            targetLanguageCode: '{{input.target_lang}}',
            documentInputConfig: {
              mimeType: '{{input.source_mime}}',
              content: '{{input.source_base64}}',
            },
          },
          null,
          2,
        ),
      },
      {
        id: 'official-openai-text-v1',
        requestUrl: 'https://api.openai.com/v1/chat/completions',
        requestBodyJson: JSON.stringify(
          {
            model: 'gpt-4o-mini',
            messages: [
              {
                role: 'system',
                content:
                  'You are a translator. Translate the following text to {{input.target_lang}}. Output only the translation, nothing else.',
              },
              { role: 'user', content: '{{input.text}}' },
            ],
            temperature: 0.1,
          },
          null,
          2,
        ),
        responseTranslatedTextPath: 'choices.0.message.content',
        responseErrorPath: 'error.message',
      },
      {
        id: 'official-azure-openai-text-v1',
        signAlgorithm: 'custom_header',
        signAdvancedJson: JSON.stringify(
          {
            auth_value_key: 'auth.access_token',
            auth_prefix: 'Bearer',
          },
          null,
          2,
        ),
        requestUrl:
          'https://{{default_values.resource_name}}.openai.azure.com/openai/deployments/{{default_values.deployment}}/chat/completions?api-version={{default_values.api_version}}',
        requestBodyJson: JSON.stringify(
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
        responseTranslatedTextPath: 'choices.0.message.content',
        responseErrorPath: 'error.message',
      },
    ]

    for (const spec of repairs) {
      const modal = await openEditModal(page, spec.id)
      expect(modal, `template ${spec.id} should exist`).toBeTruthy()
      await applyRepair(modal!, spec)
      await page.waitForTimeout(400)
    }

    const azureOpenAiId = 'official-azure-openai-text-v1'
    const azureOpenAiModal = await openEditModal(page, azureOpenAiId)
    if (azureOpenAiModal) {
      const basic = azureOpenAiModal.locator('fieldset').first()
      for (const bl of ['custom_model', 'post_content', 'taxonomy_content', 'plugin_i18n', 'theme_i18n']) {
        await basic
          .locator('label')
          .filter({ hasText: new RegExp(`^${bl}$`, 'i') })
          .locator('input[type="checkbox"]')
          .check()
      }

      const constraints = azureOpenAiModal.locator('fieldset').nth(5)
      await constraints
        .locator('label')
        .filter({ hasText: /^text$/i })
        .locator('input[type="checkbox"]')
        .first()
        .check()
      for (const fmt of ['plain_text', 'rich_html', 'json_structured', 'serialized_php']) {
        await constraints
          .locator('label')
          .filter({ hasText: new RegExp(`^${fmt}$`) })
          .locator('input[type="checkbox"]')
          .check()
      }

      const modes = azureOpenAiModal.locator('fieldset').nth(6)
      for (const [id, label, fmt] of [
        ['plain', 'Plain Text', 'plain_text'],
        ['html', 'HTML', 'rich_html'],
        ['json', 'JSON Structured', 'json_structured'],
        ['serialized', 'Serialized PHP', 'serialized_php'],
      ]) {
        await modes.locator('button').filter({ hasText: /添加翻译模式|add/i }).click()
        const card = modes.locator('div.bg-gray-50').last()
        await card.locator('input[type="text"]').nth(0).fill(id)
        await card.locator('input[type="text"]').nth(1).fill(label)
        await card
          .locator('label')
          .filter({ hasText: new RegExp(`^${fmt}$`) })
          .locator('input[type="checkbox"]')
          .check()
      }

      await setDefaultValuesJson(
        azureOpenAiModal,
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

      await azureOpenAiModal.locator('button[type="submit"]').click()
      await expect(azureOpenAiModal).not.toBeVisible({ timeout: 10_000 })
    }

    // Repair DeepL Document template: add async_poll so the client can fetch the final file (submit -> poll -> download).
    const deeplDocId = 'official-deepl-doc-v1'
    const deeplDocModal = await openEditModal(page, deeplDocId)
    if (deeplDocModal) {
      const basic = deeplDocModal.locator('fieldset').first()
      // status -> active
      await basic.locator('select').nth(1).selectOption('active')
      // Server requires numeric semver for api_version if provided; set an explicit value.
      await basic.locator('input[placeholder="v3"]').fill('1.0.0')
      // Try to attach vendor metadata (best-effort).
      await basic.locator('select').nth(3).selectOption({ label: 'DeepL' }).catch(() => {})

      const req = deeplDocModal.locator('fieldset').nth(3)
      await req.locator('select').first().selectOption('POST')
      await req.locator('select').nth(1).selectOption('multipart')
      await req.locator('input[type="text"]').first().fill('https://api-free.deepl.com/v2/document')
      await setRequestBodyJson(
        deeplDocModal,
        JSON.stringify(
          {
            target_lang: '{{input.target_lang}}',
            source_lang: '{{input.source_lang}}',
            file: '@file:{{input.source_ref}}',
          },
          null,
          2,
        ),
      )

      // Response: keep empty output paths (async_poll provides output); set error_path for debug.
      const res = deeplDocModal.locator('fieldset').nth(4)
      const resInputs = res.locator('input[type="text"]')
      await resInputs.last().fill('message')

      const asyncPollJson = JSON.stringify(
        {
          job_id_path: 'document_id',
          submit_extract: { document_key: 'document_key' },
          request: {
            method: 'GET',
            url: 'https://api-free.deepl.com/v2/document/{{computed.job_id}}?document_key={{computed.document_key}}',
            headers: {
              Authorization: 'DeepL-Auth-Key {{auth.auth_key}}',
            },
            body_type: 'none',
            body: {},
          },
          status_path: 'status',
          done_values: ['done'],
          failed_values: ['error'],
          interval_seconds: 5,
          timeout_seconds: 900,
          result_download: {
            method: 'GET',
            url: 'https://api-free.deepl.com/v2/document/{{computed.job_id}}/result?document_key={{computed.document_key}}',
            headers: {
              Authorization: 'DeepL-Auth-Key {{auth.auth_key}}',
            },
            body_type: 'none',
          },
        },
        null,
        2,
      )
      await setAsyncPollJson(deeplDocModal, asyncPollJson)

      const saveRespPromise = page.waitForResponse(
        (resp) =>
          resp.url().includes(`/api/v1/components/${encodeURIComponent(deeplDocId)}`) &&
          resp.request().method() === 'PUT',
        { timeout: 15_000 },
      )
      await deeplDocModal.locator('button[type="submit"]').click()
      const saveResp = await saveRespPromise
      if (!saveResp.ok()) {
        const body = await saveResp.text().catch(() => '')
        throw new Error(`[${deeplDocId}] save failed status=${saveResp.status()} body=${body.slice(0, 400)}`)
      }
      await expect(deeplDocModal).not.toBeVisible({ timeout: 20_000 })
      await page.waitForTimeout(400)
    } else {
      console.log(`[SKIP] ${deeplDocId} not present`)
    }

    // Add missing DeepL Pro template (idempotent)
    const deeplProId = 'official-deepl-pro-v1'
    await searchTemplateById(page, deeplProId)
    const exists = (await page.locator('tbody tr').filter({ hasText: deeplProId }).count()) > 0
    if (!exists) {
      const modal = await openCreateModal(page)

      const basic = modal.locator('fieldset').first()
      const basicInputs = basic.locator('input')
      await basicInputs.nth(0).fill('DeepL Pro Translate')
      await basicInputs.nth(1).fill(deeplProId)
      await basic.locator('select').first().selectOption('official') // owner
      await basic
        .locator('label')
        .filter({ hasText: /^text$/i })
        .locator('input[type="checkbox"]')
        .check()

      const auth = modal.locator('fieldset').nth(2)
      await auth.locator('button').filter({ hasText: /add|添加/i }).click()
      const field = auth.locator('div.bg-gray-50').last()
      await field.locator('input[placeholder=\"app_id\"]').fill('auth_key')
      await field.locator('input[placeholder=\"App ID\"]').fill('Authentication Key')
      await field.locator('input[type=\"checkbox\"]').nth(1).check() // secret

      const req = modal.locator('fieldset').nth(3)
      await req.locator('input[type=\"text\"]').first().fill('https://api.deepl.com/v2/translate')
      // Add Authorization header row
      await req.locator('button').filter({ hasText: /add|添加/i }).first().click()
      const headerRows = req.locator('div.flex.gap-2.mb-1')
      await headerRows.nth(0).locator('input').nth(0).fill('Content-Type')
      await headerRows.nth(0).locator('input').nth(1).fill('application/json')
      await headerRows.nth(1).locator('input').nth(0).fill('Authorization')
      await headerRows.nth(1).locator('input').nth(1).fill('DeepL-Auth-Key {{auth.auth_key}}')

      await setRequestBodyJson(
        modal,
        JSON.stringify(
          {
            text: ['{{input.text}}'],
            target_lang: '{{input.target_lang}}',
            tag_handling: 'html',
          },
          null,
          2,
        ),
      )

      const res = modal.locator('fieldset').nth(4)
      const resInputs = res.locator('input[type=\"text\"]')
      await resInputs.first().fill('translations.0.text')
      await resInputs.last().fill('message')

      const constraints = modal.locator('fieldset').nth(5)
      // content types: check text
      await constraints
        .locator('label')
        .filter({ hasText: /^text$/i })
        .locator('input[type="checkbox"]')
        .first()
        .check()
      // content formats: check the 4 text formats
      for (const fmt of ['plain_text', 'rich_html', 'json_structured', 'serialized_php']) {
        await constraints
          .locator('label')
          .filter({ hasText: new RegExp(`^${fmt}$`) })
          .locator('input[type="checkbox"]')
          .check()
      }

      const modes = modal.locator('fieldset').nth(6)
      for (const [id, label, fmt] of [
        ['plain', 'Plain Text', 'plain_text'],
        ['html', 'HTML', 'rich_html'],
        ['json', 'JSON Structured', 'json_structured'],
        ['serialized', 'Serialized PHP', 'serialized_php'],
      ]) {
        await modes.locator('button').filter({ hasText: /添加翻译模式|add/i }).click()
        const card = modes.locator('div.bg-gray-50').last()
        await card.locator('input[type=\"text\"]').nth(0).fill(id)
        await card.locator('input[type=\"text\"]').nth(1).fill(label)
        await card
          .locator('label')
          .filter({ hasText: new RegExp(`^${fmt}$`) })
          .locator('input[type="checkbox"]')
          .check()
      }

      await modal.locator('button[type=\"submit\"]').click()
      await expect(modal).not.toBeVisible({ timeout: 10_000 })
    }

    // Add/patch ElevenLabs Dubbing template (async submit -> poll -> download)
    const elevenId = 'official-elevenlabs-dubbing-v1'
    await searchTemplateById(page, elevenId)
    const elevenExists = (await page.locator('tbody tr').filter({ hasText: elevenId }).count()) > 0
    const elevenModal = elevenExists ? await openEditModal(page, elevenId) : await openCreateModal(page)
    if (elevenModal) {
      if (!elevenExists) {
        const basic = elevenModal.locator('fieldset').first()
        const basicInputs = basic.locator('input')
        await basicInputs.nth(0).fill('ElevenLabs Dubbing (Async)')
        await basicInputs.nth(1).fill(elevenId)
        await basic.locator('select').first().selectOption('official') // owner
        // status: active
        await basic.locator('select').nth(1).selectOption('active')
        // supported_types: audio (outputs dubbed audio track)
        await basic
          .locator('label')
          .filter({ hasText: /^audio$/i })
          .locator('input[type="checkbox"]')
          .check()
        // supported_business_lines: non-text templates must not include i18n lines
        for (const bl of ['custom_model', 'post_content', 'taxonomy_content']) {
          await basic
            .locator('label')
            .filter({ hasText: new RegExp(`^${bl}$`, 'i') })
            .locator('input[type="checkbox"]')
            .check()
        }
        // api docs url
        await basic.locator('input[type="url"]').fill('https://elevenlabs.io/docs/api-reference/dubbing')

        const auth = elevenModal.locator('fieldset').nth(2)
        // auth mode defaults to key; define required api_key field
        await auth.locator('button').filter({ hasText: /add|添加/i }).click()
        const field = auth.locator('div.bg-gray-50').last()
        await field.locator('input[placeholder="app_id"]').fill('api_key')
        await field.locator('input[placeholder="App ID"]').fill('API Key')
        await field.locator('input[type="checkbox"]').nth(0).check() // required
        await field.locator('input[type="checkbox"]').nth(1).check() // secret
      } else {
        // Patch existing: force active + audio_translation
        const basic = elevenModal.locator('fieldset').first()
        await basic.locator('select').nth(1).selectOption('active')
        // component_type is the 3rd select in basic section: owner, status, type, vendor
        await basic.locator('select').nth(2).selectOption('audio_translation')
        // supported_types: ensure audio checked, video unchecked
        const audioBox = basic
          .locator('label')
          .filter({ hasText: /^audio$/i })
          .locator('input[type="checkbox"]')
        if ((await audioBox.count()) > 0) await audioBox.check()
        const videoBox = basic
          .locator('label')
          .filter({ hasText: /^video$/i })
          .locator('input[type="checkbox"]')
        if ((await videoBox.count()) > 0) await videoBox.uncheck().catch(() => {})
      }

      const req = elevenModal.locator('fieldset').nth(3)
      // method/body_type
      await req.locator('select').first().selectOption('POST')
      await req.locator('select').nth(1).selectOption('multipart')
      await req.locator('input[type="text"]').first().fill('https://api.elevenlabs.io/v1/dubbing')

      // headers: ensure xi-api-key exists and uses auth.api_key
      const headerRows = req.locator('div.flex.gap-2.mb-1')
      const rowsCount = await headerRows.count()
      let hasXi = false
      for (let i = 0; i < rowsCount; i += 1) {
        const k = (await headerRows.nth(i).locator('input').nth(0).inputValue().catch(() => '')).trim()
        if (k.toLowerCase() === 'xi-api-key') {
          hasXi = true
          await headerRows.nth(i).locator('input').nth(1).fill('{{auth.api_key}}')
        }
      }
      if (!hasXi) {
        await req.locator('button').filter({ hasText: /add|添加/i }).first().click()
        const headerRows2 = req.locator('div.flex.gap-2.mb-1')
        await headerRows2.nth(rowsCount).locator('input').nth(0).fill('xi-api-key')
        await headerRows2.nth(rowsCount).locator('input').nth(1).fill('{{auth.api_key}}')
      }

      // body
      await setRequestBodyJson(
        elevenModal,
        JSON.stringify(
          {
            file: '@file:{{input.source_ref}}',
            target_lang: '{{input.target_lang}}',
            source_lang: '{{input.source_lang}}',
          },
          null,
          2,
        ),
      )

      // Response: optional (async_poll provides output); set error_path for debug.
      const res = elevenModal.locator('fieldset').nth(4)
      await res.locator('input[type="text"]').last().fill('error.message')

      const constraints = elevenModal.locator('fieldset').nth(5)
      await constraints
        .locator('label')
        .filter({ hasText: /^audio$/i })
        .locator('input[type="checkbox"]')
        .first()
        .check()
      await constraints
        .locator('label')
        .filter({ hasText: /^media_ref$/i })
        .locator('input[type="checkbox"]')
        .check()

      const asyncPollJson = JSON.stringify(
        {
          job_id_path: 'dubbing_id',
          request: {
            method: 'GET',
            url: 'https://api.elevenlabs.io/v1/dubbing/{{computed.job_id}}',
            headers: {
              'xi-api-key': '{{auth.api_key}}',
            },
            body_type: 'none',
            body: {},
          },
          status_path: 'status',
          done_values: ['done', 'completed', 'ready', 'finished', 'dubbed', 'succeeded', 'success'],
          failed_values: ['failed', 'error', 'cancelled', 'canceled'],
          interval_seconds: 5,
          timeout_seconds: 1800,
          result_download: {
            method: 'GET',
            url: 'https://api.elevenlabs.io/v1/dubbing/{{computed.job_id}}/audio/{{input.target_lang}}',
            headers: {
              'xi-api-key': '{{auth.api_key}}',
            },
            body_type: 'none',
            filename: 'dubbing-{{computed.job_id}}-{{input.target_lang}}.mp3',
          },
        },
        null,
        2,
      )
      await setAsyncPollJson(elevenModal, asyncPollJson)

      await elevenModal.locator('button[type="submit"]').click()
      await expect(elevenModal).not.toBeVisible({ timeout: 10_000 })
    }
  })
})
