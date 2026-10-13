/**
 * Owned ATS review: real worker → pending hold → UI save → independent GET
 * → UI approve → exact HTTP callback. ATS/provider are loopback doubles,
 * not deployed WP, a real vendor, or a Tauri GUI.
 * seed: inline owned MockAtsSite, paired with GET/callback assertions below
 * covers: success | failure | boundary
 */
import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { expectApi } from '../lib/expect-api'
import {
  assertOwnedReviewLane,
  assertReviewCallback,
  assertReviewContent,
  assertReviewHeld,
  writeOwnedReviewEvidence,
  type ReviewExpected,
  type ReviewFields,
} from '../lib/owned-review'
import { MockAtsSite } from '../simulation/lib/mock-wp-site'
import { MockTranslateProvider, translatorTemplate } from '../simulation/lib/mock-provider'
import {
  CLIENT_BASE,
  apiGet,
  apiPost,
  bindSite,
  bootstrapDiscoveryTasks,
  createLocalComponent,
  enableDiscoveryTask,
  getReviewMode,
  listDiscoveryTasks,
  runWorkerOnce,
  saveComponentAuth,
  setReviewMode,
  unbindSite,
  verifySiteIdentity,
} from '../simulation/lib/sim-client'

// Fail during collection, before even a status request or fixture teardown.
assertOwnedReviewLane(process.env, CLIENT_BASE)

async function saveReviewModeUi(page: Page, request: APIRequestContext, wanted: boolean) {
  await page.goto(CLIENT_BASE)
  await page.getByRole('button', { name: /^(设置|Settings)$/ }).click()
  await page.getByTestId('settings-tab-worker').click()
  const toggle = page.getByTestId('settings-review-toggle')
  await expect(toggle).toBeVisible()
  if ((await getReviewMode(request)) !== wanted) await toggle.click()
  const pending = expectApi(page, {
    path: /\/api\/worker\/config$/,
    method: 'POST',
    requestSchema: 'worker-config.request',
    responseSchema: 'worker-config.response',
  })
  await page.getByTestId('settings-save-worker').click()
  const saved = await pending
  expect(saved.status).toBe(200)
  expect((saved.requestJson as { review_mode: boolean }).review_mode).toBe(wanted)
  expect((saved.responseJson as { success: boolean }).success).toBe(true)
  expect(await getReviewMode(request)).toBe(wanted)
  const readback = await apiGet(request, '/api/worker/config')
  expect(readback.success).toBe(true)
  if (wanted) {
    const steps = (readback.data.workflow_dsl as { steps: Array<{ id: string }> }).steps
    expect(steps.filter(step => step.id === 'human_review')).toHaveLength(1)
  }
}

test.describe('G3 review_mode owned local', () => {
  test('Settings UI saves and independently reads back review_mode, restoring in finally', async ({ page, request }) => {
    const original = await getReviewMode(request)
    try {
      await saveReviewModeUi(page, request, !original)
      await page.reload()
      await page.getByRole('button', { name: /^(设置|Settings)$/ }).click()
      await page.getByTestId('settings-tab-worker').click()
      await expect(page.getByTestId('settings-review-toggle')).toBeVisible()
      expect(await getReviewMode(request)).toBe(!original)
      await saveReviewModeUi(page, request, original)
    } finally {
      await setReviewMode(request, original)
      expect(await getReviewMode(request)).toBe(original)
    }
  })

  test('owned worker holds, UI saves three fields, GET persists, approve writes exactly once', async ({ page, request }, testInfo) => {
    const relationId = 9601
    const objectId = 7601
    const componentId = 'owned-review-translator'
    const raw: ReviewFields = {
      post_title: 'Owned review title',
      post_content: '<p>Owned review body.</p>',
      post_excerpt: 'Owned review excerpt',
    }
    const machine: ReviewFields = {
      post_title: '【zh_CN】Owned review title【/zh_CN】',
      post_content: '<p>【zh_CN】Owned review body.【/zh_CN】</p>',
      post_excerpt: '【zh_CN】Owned review excerpt【/zh_CN】',
    }
    const polished: ReviewFields = {
      post_title: '人工审阅标题（owned）',
      post_content: '<p>人工审阅正文，保留 HTML。</p>',
      post_excerpt: '人工审阅摘要（owned）',
    }
    const ats = new MockAtsSite({
      siteName: 'Owned Review ATS',
      routeSecret: 'owned_review_mock_secret',
      wpClientToken: 'owned_review_mock_device_token_012345',
      relation: { id: relationId, sourceLang: 'en_US', targetLang: 'zh_CN' },
      contentItems: [{
        objectId, postType: 'post', title: raw.post_title,
        content: raw.post_content, excerpt: raw.post_excerpt,
      }],
    })
    const provider = new MockTranslateProvider()
    const original = await getReviewMode(request)
    let bound = false
    try {
      await ats.start()
      await provider.start()
      const bind = await bindSite(request, {
        api_base_url: ats.baseUrl, wp_client_token: ats.wpClientToken, route_secret: ats.routeSecret,
      })
      expect(bind.success).toBe(true)
      bound = true
      const verified = await verifySiteIdentity(request, ats.baseUrl)
      expect(verified.success).toBe(true)
      expect(verified.data.plugin_identity).toBe('wpmmcc_ats')
      expect((await createLocalComponent(request, {
        id: componentId, name: 'Owned Review Translator', kind: 'text',
        templateJson: translatorTemplate(componentId, provider.translateUrl),
      })).success).toBe(true)
      expect((await saveComponentAuth(request, componentId, { api_key: 'owned-review-mock-key' })).success).toBe(true)
      await saveReviewModeUi(page, request, true)
      expect((await bootstrapDiscoveryTasks(request)).success).toBe(true)
      const tasks = (await listDiscoveryTasks(request)).filter(task => task.relation_id === relationId)
      expect(tasks).toHaveLength(1)
      expect((await enableDiscoveryTask(request, tasks[0].id, componentId)).success).toBe(true)
      const worker = await runWorkerOnce(request)

      const pending = await apiGet(request, '/api/items/pending-review')
      expect(pending.success).toBe(true)
      await testInfo.attach('owned-review-worker', {
        body: JSON.stringify({ worker, pending: pending.data, providerHits: provider.hits, atsPaths: ats.seenPaths }),
        contentType: 'application/json',
      })
      const items = (pending.data.items as Array<Record<string, unknown>>)
        .filter(item => item.relation_id === relationId)
      expect(items).toHaveLength(1)
      const itemId = Number(items[0].id)
      const jobId = Number(items[0].job_id)
      const expected: ReviewExpected = { itemId, jobId, relationId, objectId, raw, translated: machine }
      const before = await apiGet(request, `/api/items/${itemId}/content`)
      expect(before.success).toBe(true)
      const machineKey = assertReviewContent(before.data, expected)
      assertReviewHeld(ats.receivedCallbacks.length)
      expect(provider.hits.map(hit => hit.text).sort()).toEqual(Object.values(raw).sort())
      expect(provider.hits.every(hit => hit.source_lang === 'en_US' && hit.target_lang === 'zh_CN')).toBe(true)
      expect(ats.receivedAcks).toHaveLength(1)
      expect(ats.receivedAcks[0].outcome).toBe('completed')

      await page.goto(CLIENT_BASE)
      await page.getByRole('button', { name: /^(任务|Tasks)$/ }).click()
      await page.getByRole('button', { name: /^(待审任务|待审核|Pending( Review| Tasks)?)$/ }).click()
      await page.getByTestId(`pending-review-${itemId}`).click()
      for (const key of ['post_title', 'post_content', 'post_excerpt'] as const) {
        const field = page.getByTestId(`review-field-${key}`)
        await expect(field).toBeVisible()
        await expect(field).toBeEnabled()
        await expect(field).toHaveValue(machine[key])
        await field.fill(polished[key])
      }
      const save = expectApi(page, { path: `/api/items/${itemId}/translated`, method: 'PUT' })
      await page.getByTestId('review-save').click()
      const saved = await save
      expect(saved.status).toBe(200)
      expect((saved.responseJson as { success: boolean }).success).toBe(true)
      const submitted = (saved.requestJson as { content: Record<string, unknown> }).content
      for (const key of ['post_title', 'post_content', 'post_excerpt'] as const) {
        expect(submitted[key]).toBe(polished[key])
      }
      expected.translated = polished
      const readback = await apiGet(request, `/api/items/${itemId}/content`)
      expect(readback.success).toBe(true)
      const editedKey = assertReviewContent(readback.data, expected)
      expect(editedKey).not.toBe(machineKey)
      assertReviewHeld(ats.receivedCallbacks.length)
      await page.reload()
      await page.getByRole('button', { name: /^(任务|Tasks)$/ }).click()
      await page.getByRole('button', { name: /^(待审任务|待审核|Pending( Review| Tasks)?)$/ }).click()
      await page.getByTestId(`pending-review-${itemId}`).click()
      for (const key of ['post_title', 'post_content', 'post_excerpt'] as const) {
        await expect(page.getByTestId(`review-field-${key}`)).toHaveValue(polished[key])
      }

      const approve = expectApi(page, { path: `/api/items/${itemId}/approve`, method: 'POST' })
      await page.getByTestId('review-approve').click()
      const approved = await approve
      expect(approved.status).toBe(200)
      expect(approved.responseJson).toEqual({ success: true, data: { item_id: itemId, status: 'done' } })
      expect(ats.receivedCallbacks).toHaveLength(1)
      assertReviewCallback(ats.receivedCallbacks[0], expected, editedKey)
      expect(ats.appliedCallbacks).toBe(1)
      const done = await apiGet(request, `/api/jobs/${jobId}/items`)
      expect(done.success).toBe(true)
      const doneItems = (done.data.items as Array<Record<string, unknown>>).filter(item => item.id === itemId)
      expect(doneItems).toHaveLength(1)
      expect(doneItems[0].status).toBe('done')
      const after = await apiGet(request, '/api/items/pending-review')
      expect(after.success).toBe(true)
      expect((after.data.items as Array<Record<string, unknown>>).filter(item => item.id === itemId)).toHaveLength(0)
      const repeated = await apiPost(request, `/api/items/${itemId}/approve`)
      expect(repeated.success).toBe(false)
      expect(repeated.errorCode).toBe('INVALID_STATUS')
      expect(ats.receivedCallbacks).toHaveLength(1)
      expect(ats.appliedCallbacks).toBe(1)
      expect(provider.hits).toHaveLength(3)
      await testInfo.attach('owned-review-output', {
        path: writeOwnedReviewEvidence(process.env, {
          itemId, jobId, relationId, objectId, raw, polished,
          savedIdentityChanged: editedKey !== machineKey,
          callback: ats.receivedCallbacks[0], providerCalls: provider.hits.length,
          appliedCallbacks: ats.appliedCallbacks, repeatedApprove: repeated.errorCode,
          status: 'passed', dry_run: false, evidence_level: 'owned_client_mock_ats_provider_http',
        }),
        contentType: 'application/json',
      })
    } finally {
      try {
        try {
          if (bound) expect((await unbindSite(request, ats.baseUrl)).success).toBe(true)
        } finally {
          await setReviewMode(request, original)
          expect(await getReviewMode(request)).toBe(original)
        }
      } finally {
        await Promise.all([ats.stop(), provider.stop()])
      }
    }
  })
})
