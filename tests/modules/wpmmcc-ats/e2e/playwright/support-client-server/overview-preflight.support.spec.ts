import { test, expect, type APIRequestContext, type Page, type Route } from '@playwright/test'
import { ensureClientWebUiLoggedIn } from './helpers/client-oauth-login'
import { ensureClientUiChinese } from './helpers/client-ui-locale'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const CLIENT_BASE = resolveSlotClientBase()

async function ensureLoggedIn(page: Page, _context: unknown, request: APIRequestContext) {
  await ensureClientWebUiLoggedIn(page, request)
}

async function gotoOverview(page: Page, request: APIRequestContext) {
  await request.post(`${CLIENT_BASE}/api/worker/stop`, { data: {} }).catch(() => null)
  await page.goto(CLIENT_BASE)
  await page.waitForSelector('nav.min-h-screen', { timeout: 20_000 })
  await ensureClientUiChinese(page)
  await expect(page.getByRole('heading', { name: '概览' })).toBeVisible()
  await expect(page.getByRole('button', { name: '启动循环' })).toBeVisible()
}

function blockingPreflightPayload() {
  return {
    success: true,
    data: {
      can_start: false,
      requires_confirmation: false,
      summary: {
        domains_checked: 1,
        relations_checked: 1,
        rules_checked: 1,
        fields_checked: 1,
        language_pack_lanes_checked: 0,
        blocking_missing_components: 1,
        confirm_missing_components: 0,
        auto_skip_missing_components: 0,
      },
      missing_components: [
        {
          api_base_url: 'https://blog.wpmm.cc',
          business_line: 'post_content',
          relation_id: 289,
          rule_id: 1019,
          source_group: 'content_object',
          routing_profile: 'post_content_default',
          delivery_target: 'object_writeback',
          object_name: 'attachment',
          field_name: '_wptsall_core_source_file_id',
          source_role: 'media_file',
          preflight_policy: 'block',
          missing_component_behavior: 'stop_task',
          severity: 'blocking',
          content_format: 'media_ref',
          required_slot_key: 'media_ref:document',
          suggested_task_type: 'document',
          input_artifact_kind: 'media_file',
          expected_output_artifact_kind: 'translated_media_file',
        },
      ],
    },
  }
}

function confirmPreflightPayload() {
  return {
    success: true,
    data: {
      can_start: true,
      requires_confirmation: true,
      summary: {
        domains_checked: 1,
        relations_checked: 1,
        rules_checked: 1,
        fields_checked: 1,
        language_pack_lanes_checked: 0,
        blocking_missing_components: 0,
        confirm_missing_components: 1,
        auto_skip_missing_components: 0,
      },
      missing_components: [
        {
          api_base_url: 'https://blog.wpmm.cc',
          business_line: 'plugin_i18n',
          relation_id: 289,
          rule_id: null,
          source_group: 'plugin_i18n',
          routing_profile: 'plugin_i18n_default',
          delivery_target: 'i18n_bundle_writeback',
          object_name: 'plugin-language-pack',
          field_name: 'plugin_i18n/messages.po',
          source_role: 'i18n_bundle',
          preflight_policy: 'warn',
          missing_component_behavior: 'confirm_continue',
          severity: 'confirm',
          content_format: 'plain_text',
          required_slot_key: 'plain_text',
          suggested_task_type: 'text',
          input_artifact_kind: 'text',
          expected_output_artifact_kind: 'translated_text',
        },
      ],
    },
  }
}

test.describe.configure({ mode: 'serial' })

test('Overview: 启动前检查遇到阻断缺失项时不允许继续启动', async ({ page, context, request }) => {
  await ensureLoggedIn(page, context, request)
  await gotoOverview(page, request)

  let startCalled = false
  await page.route('**/api/worker/start-check', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(blockingPreflightPayload()),
    })
  })
  await page.route('**/api/worker/start', async (route) => {
    startCalled = true
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { running: true, poll_seconds: 20 } }),
    })
  })

  await page.getByRole('button', { name: '启动循环' }).click()

  await expect(page.getByRole('heading', { name: '发现缺失组件' })).toBeVisible()
  await expect(page.getByText(/发现 1 个缺失组件/)).toBeVisible()
  await expect(page.getByText('媒体文件')).toBeVisible()
  await expect(page.getByText('阻断启动 / 停止任务')).toBeVisible()
  await expect(page.getByText('media_file -> translated_media_file')).toBeVisible()
  await expect(page.getByText('阻断').first()).toBeVisible()
  await expect(page.getByRole('button', { name: '继续执行' })).toHaveCount(0)

  await page.getByRole('button', { name: '取消' }).click()
  await expect(page.getByRole('heading', { name: '发现缺失组件' })).toBeHidden({ timeout: 10_000 })
  expect(startCalled).toBe(false)
})

test('Overview: 启动前检查要求确认时可继续启动并带 force=true', async ({ page, context, request }) => {
  await ensureLoggedIn(page, context, request)
  await gotoOverview(page, request)

  let startPayload: Record<string, unknown> | null = null
  await page.route('**/api/worker/start-check', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(confirmPreflightPayload()),
    })
  })
  await page.route('**/api/worker/start', async (route: Route) => {
    const raw = route.request().postData() ?? '{}'
    startPayload = JSON.parse(raw) as Record<string, unknown>
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { running: true, poll_seconds: 20 } }),
    })
  })

  await page.getByRole('button', { name: '启动循环' }).click()

  await expect(page.getByRole('heading', { name: '发现缺失组件' })).toBeVisible()
  await expect(page.getByText('plugin_i18n', { exact: true }).first()).toBeVisible()
  await expect(page.getByText(/Writeback: 语言包写回|写回: 语言包写回/).first()).toBeVisible()
  await expect(page.getByText('warn / confirm_continue').first()).toBeVisible()
  await expect(page.getByText(/Slot 标签: 纯文本|slot: 纯文本/).first()).toBeVisible()
  await expect(page.getByText('告警 / 确认后继续')).toBeVisible()
  await expect(page.getByText('确认').first()).toBeVisible()

  await page.getByRole('button', { name: '继续执行' }).click()

  await expect.poll(() => startPayload).not.toBeNull()
  expect(startPayload).toEqual({ force: true })
})
