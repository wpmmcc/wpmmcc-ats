import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import type { Server } from 'http';
import { MOCK_EDITABLE_TEMPLATE, startMockServer, stopMockServer } from './mock-server';
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const BASE = resolveSlotClientBase()
const COMPONENT_ID = 'mock-editable-v1';
const COMPONENT_NAME = 'Mock Editable Params';

test.describe.configure({ mode: 'serial' });

let mockServer: Server | null = null;
let skipReason: string | null = null;

async function cleanupComponent(request: APIRequestContext) {
  try {
    await request.post(`${BASE}/api/components/bindings/delete`, {
      data: { component_id: COMPONENT_ID },
    });
  } catch {
    // ignore cleanup errors
  }
  try {
    await request.delete(`${BASE}/api/components/local/${encodeURIComponent(COMPONENT_ID)}`);
  } catch {
    // ignore cleanup errors
  }
}

async function ensureZhCN(page: Page) {
  const zh = page.locator('button[data-locale="zh-CN"]');
  await expect(zh).toBeVisible({ timeout: 10_000 });
  if ((await zh.getAttribute('aria-pressed')) !== 'true') {
    await zh.click();
  }
  await expect(zh).toHaveAttribute('aria-pressed', 'true');
}

async function ensureLoggedIn(page: Page, request: APIRequestContext) {
  // P0-LF-05/07: the client runs local-first (control-plane flag 0) in this
  // lane — provider mocks are not the website. No website session is needed
  // or available: the default UI renders directly with no login affordance.
  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 15_000 });
  await ensureZhCN(page);
  const statusRes = await request.get(`${BASE}/api/status`);
  const status = await statusRes.json();
  expect(status?.data?.runtime_mode).toBe('local');
  // Local-first status omits website session fields; treat missing as not logged in.
  expect(Boolean(status?.data?.logged_in)).toBe(false);
}

test.beforeAll(async ({ request }) => {
  const statusRes = await request.get(`${BASE}/api/status`);
  if (!statusRes.ok()) {
    throw new Error(`Client is not ready at ${BASE}`);
  }
  const status = await statusRes.json();
  const serverBase = String(status?.data?.server_base ?? '');
  if (serverBase.includes('www.wpmm.cc')) {
    skipReason =
      'Client is running in real server mode (www.wpmm.cc). ' +
      'This spec is mock-only and requires WPTSALL_SERVER_BASE=http://127.0.0.1:8787.';
    return;
  }
  try {
    mockServer = await startMockServer(8787);
  } catch (err: any) {
    const message = String(err?.message ?? err ?? '');
    if (!message.includes('EADDRINUSE')) {
      throw err;
    }
    const probe = await fetch('http://127.0.0.1:8787/mock/check-overrides', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: 'probe', target_lang: 'zh_CN' }),
    }).catch(() => null);
    const probeJson = probe ? await probe.json().catch(() => null) : null;
    if (probeJson?.translated_text) {
      return;
    }
    throw new Error(
      'Port 8787 is already in use by a non-mock service. ' +
      'Stop that service or run tests in the dedicated mock environment first.',
    );
  }
});

test.afterAll(async ({ request }) => {
  await cleanupComponent(request);
  if (mockServer) {
    await stopMockServer(mockServer);
    mockServer = null;
  }
});

test('WebUI 覆盖参数：仅 editable_params 白名单字段生效', async ({ page, request }) => {
  test.skip(!!skipReason, skipReason || '');

  await cleanupComponent(request);
  await ensureLoggedIn(page, request);

  // Local-first UI create without template_json is rejected; seed via API with
  // the mock editable template, then exercise Auth override UI.
  const seed = await request.post(`${BASE}/api/components/local`, {
    data: {
      id: COMPONENT_ID,
      name: COMPONENT_NAME,
      kind: 'text',
      template_id: COMPONENT_ID,
      template_json: MOCK_EDITABLE_TEMPLATE,
      enabled: true,
    },
  });
  expect((await seed.json()).success).toBe(true);

  await page.goto(BASE);
  await ensureZhCN(page);
  await page.click('nav button:has-text("翻译组件")');
  await page.getByTestId('components-tab-my').click();

  const componentRow = page.locator('button.flex-1').filter({ hasText: COMPONENT_ID }).first();
  await expect(componentRow).toBeVisible({ timeout: 10_000 });
  await componentRow.click();
  await page.locator('button:has-text("编辑 Auth")').first().click();

  const modal = page.locator('div.fixed.inset-0').last();
  await expect(modal.locator('h4:has-text("Auth 凭据")')).toBeVisible();

  await modal.locator('input[placeholder="字段名"]').first().fill('api_key');
  await modal.locator('input[placeholder="值"]').first().fill('mock-auth-key');

  await expect(modal.locator('label:has-text("request.headers (JSON)")')).toBeVisible();
  await expect(modal.locator('label:has-text("request.body (JSON)")')).toBeVisible();
  await expect(modal.locator('label:has-text("request.url")')).toHaveCount(0);

  await modal
    .locator('label:has-text("request.headers (JSON)")')
    .locator('xpath=following-sibling::textarea[1]')
    .fill(JSON.stringify({
      'X-Allow': 'allow-new',
      'X-Blocked': 'blocked-new',
    }, null, 2));
  await modal
    .locator('label:has-text("request.body (JSON)")')
    .locator('xpath=following-sibling::textarea[1]')
    .fill(JSON.stringify({
      text: 'OVERRIDE_TEXT',
      target_lang: 'fr_FR',
    }, null, 2));

  await modal.locator('button:has-text("保存")').first().click();
  await expect(modal).toBeHidden({ timeout: 10_000 });

  const statusRes = await request.get(`${BASE}/api/status`);
  const status = await statusRes.json();
  const binding = status?.data?.component_bindings?.components?.[COMPONENT_ID];
  expect(binding).toBeTruthy();
  expect(binding?.request_overrides?.headers?.['X-Allow']).toBe('allow-new');
  expect(binding?.request_overrides?.headers?.['X-Blocked']).toBe('blocked-new');
  expect(binding?.request_overrides?.body?.text).toBe('OVERRIDE_TEXT');
  expect(binding?.request_overrides?.body?.target_lang).toBe('fr_FR');

  const testRes = await request.post(`${BASE}/api/components/test`, {
    data: {
      component_key: COMPONENT_ID,
      text: 'BASE_TEXT',
      source_lang: 'en_US',
      target_lang: 'zh_CN',
    },
  });
  const testData = await testRes.json();
  expect(testData?.success).toBe(true);
  const translated = testData?.data?.translated_text ?? '';
  expect(translated).toContain('text=OVERRIDE_TEXT');
  expect(translated).toContain('target=zh_CN');
  expect(translated).toContain('x_allow=allow-new');
  expect(translated).toContain('x_blocked=base-blocked');
});
