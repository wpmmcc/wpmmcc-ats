/**
 * E2E 集成测试：组件模板 → Mock 翻译 API
 *
 * 测试完整链路：
 *   Web UI → 创建绑定 → /api/components/test →
 *   Mock Server(:8787) 下载模板 →
 *   Mock Translate API(:9090) 执行翻译 →
 *   返回翻译结果
 *
 * 前置条件（所有测试必须满足）：
 *   1. Mock translate API 运行中:
 *        cd mock-translate-api && cargo run --release
 *
 *   2. Rust 客户端以以下环境变量运行:
 *        WPTSALL_WEB_UI=true
 *        WPTSALL_SERVER_BASE=http://127.0.0.1:8787
 *        WPTSALL_SKIP_SIGNATURE_CHECK=true
 *        cargo run --release
 *
 *   本测试文件会自动在 :8787 启动 Mock Server。
 *
 * 运行命令:
 *   cd dev-tool/e2e/playwright && npx playwright test -c playwright.client-mock-support.config.ts
 */

import { test, expect, type Page }  from '@playwright/test';
import type { Server }              from 'http';
import { MOCK_BEARER_TEMPLATE, startMockServer, stopMockServer } from './mock-server';
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

// ─── Constants ───────────────────────────────────────────────────────────────

const BASE           = resolveSlotClientBase()
const TRANSLATE_BASE = 'http://127.0.0.1:9090';
const COMPONENT_KEY  = 'mock-bearer-v1';
const MOCK_API_KEY   = 'mock-translate-dev-key-2026';

// ─── Serial execution ─────────────────────────────────────────────────────────

test.describe.configure({ mode: 'serial' });

// ─── Suite lifecycle ──────────────────────────────────────────────────────────

let mockServer: Server | null = null;

test.beforeAll(async () => {
  // ── 1. Verify mock translate API is running ───────────────────────────────
  let translateOk = false;
  try {
    const r = await fetch(`${TRANSLATE_BASE}/api/v1/health`, { signal: AbortSignal.timeout(3000) });
    translateOk = r.ok;
  } catch { /* not running */ }

  if (!translateOk) {
    throw new Error(
      '\n\n❌  Mock translate API not running at ' + TRANSLATE_BASE +
      '\n   Please start it first:\n' +
      '     cd mock-translate-api && cargo run --release\n',
    );
  }
  console.log('✅  Mock translate API confirmed at :9090');

  // ── 2. Verify Rust client is running ─────────────────────────────────────
  let clientOk = false;
  try {
    const r = await fetch(`${BASE}/api/status`, { signal: AbortSignal.timeout(3000) });
    clientOk = r.ok;
  } catch { /* not running */ }

  if (!clientOk) {
    throw new Error(
      '\n\n❌  WPTSALL Client not running at ' + BASE +
      '\n   Please start it with:\n' +
      '     WPTSALL_WEB_UI=true \\\n' +
      '     WPTSALL_SERVER_BASE=http://127.0.0.1:8787 \\\n' +
      '     WPTSALL_SKIP_SIGNATURE_CHECK=true \\\n' +
      '     cargo run --release\n',
    );
  }
  console.log('✅  WPTSALL Client confirmed at :8977');

  // ── 3. Start mock server at :8787 ────────────────────────────────────────
  mockServer = await startMockServer(8787);
  console.log('✅  Mock server started at :8787');
});

test.afterAll(async () => {
  // ── Clean up component binding ────────────────────────────────────────────
  try {
    await fetch(`${BASE}/api/components/bindings/delete`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ component_id: COMPONENT_KEY }),
    });
    console.log(`🧹  Component binding "${COMPONENT_KEY}" cleaned up`);
  } catch { /* ignore cleanup errors */ }

  try {
    await fetch(`${BASE}/api/components/local/${encodeURIComponent(COMPONENT_KEY)}`, {
      method: 'DELETE',
    });
    console.log(`🧹  Local component "${COMPONENT_KEY}" cleaned up`);
  } catch { /* ignore cleanup errors */ }

  // ── Stop mock server ──────────────────────────────────────────────────────
  if (mockServer) {
    await stopMockServer(mockServer);
    mockServer = null;
    console.log('🛑  Mock server stopped');
  }
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

async function ensureZhCN(page: Page): Promise<void> {
  const zh = page.locator('button[data-locale="zh-CN"]');
  await expect(zh).toBeVisible({ timeout: 10_000 });
  if ((await zh.getAttribute('aria-pressed')) !== 'true') {
    await zh.click();
  }
  await expect(zh).toHaveAttribute('aria-pressed', 'true');
}

async function goTo(page: Page, label: string): Promise<void> {
  await ensureZhCN(page);
  await page.click(`nav button:has-text("${label}")`);
  await page.waitForTimeout(300);
}

// ─── Test 1: 本地模式（无需官网登录） ────────────────────────────────────────

test('本地模式：无需官网登录即可进入默认界面', async ({ page }) => {
  await page.goto(BASE);
  await page.waitForLoadState('domcontentloaded');

  // P0-LF-04: the default UI has no login affordance; the mock provider lane
  // runs with the control-plane flag off (P0-LF-07) — mocks are not the website.
  await expect(page.locator('button:has-text("通过浏览器登录")')).toHaveCount(0);

  // Main page transitions straight to the local nav
  await page.waitForSelector('nav.min-h-screen', { timeout: 15_000 });

  // Confirm local mode via API
  const status = await fetch(`${BASE}/api/status`).then(r => r.json());
  expect(status?.data?.runtime_mode).toBe('local');
  expect(Boolean(status?.data?.logged_in)).toBe(false);
  console.log('✅  本地模式默认界面可用（无需官网登录）');
});

// ─── Test 2: 创建组件绑定 + Auth ─────────────────────────────────────────────

test('创建组件绑定：配置指向 Mock API 的 api_key', async ({ page }) => {
  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 5000 });

  // Local-first: bindings/upsert requires an existing local component first.
  const ensureLocal = await page.evaluate(
    async ({ base, componentKey, templateJson }) => {
      await fetch(`${base}/api/components/bindings/delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ component_id: componentKey }),
      }).catch(() => null);
      await fetch(`${base}/api/components/local/${encodeURIComponent(componentKey)}`, {
        method: 'DELETE',
      }).catch(() => null);
      const r = await fetch(`${base}/api/components/local`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: componentKey,
          name: 'Mock Bearer Token Translator',
          kind: 'text',
          template_id: componentKey,
          template_json: templateJson,
          enabled: true,
        }),
      });
      return r.json() as Promise<{ success: boolean; error?: { message: string } }>;
    },
    { base: BASE, componentKey: COMPONENT_KEY, templateJson: MOCK_BEARER_TEMPLATE },
  );
  expect(ensureLocal.success, JSON.stringify(ensureLocal)).toBe(true);

  // Create binding via the backend API (more reliable than UI navigation)
  const bindRes = await page.evaluate(
    async ([base, componentKey, apiKey]: string[]) => {
      const r = await fetch(`${base}/api/components/bindings/upsert`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          component_id: componentKey,
          auth:         { api_key: apiKey },
        }),
      });
      return r.json() as Promise<{ success: boolean; data?: { component_id: string } }>;
    },
    [BASE, COMPONENT_KEY, MOCK_API_KEY],
  );

  expect(bindRes.success, JSON.stringify(bindRes)).toBe(true);
  expect(bindRes.data?.component_id).toBe(COMPONENT_KEY);
  console.log(`✅  组件绑定创建成功: "${COMPONENT_KEY}"`);

  // Status redacts auth values; upsert response keeps the plaintext key.
  expect(bindRes.data && (bindRes.data as { auth?: { api_key?: string } }).auth?.api_key).toBe(
    MOCK_API_KEY,
  );
  const status = await fetch(`${BASE}/api/status`).then(r => r.json());
  const binding = status?.data?.component_bindings?.components?.[COMPONENT_KEY];
  expect(binding).toBeTruthy();
  // Status redacts api_key via sensitive_json_key → "[redacted]"
  // (redacted_component_bindings first writes "[configured]", then redact_sensitive_json wins).
  expect(binding?.auth?.api_key).toBe('[redacted]');
  console.log('✅  Auth 字段验证通过: api_key 已持久化（status 脱敏为 [redacted]）');
});

// ─── Test 3: 组件测试 → Mock Translate API → 验证结果 ───────────────────────

test('组件测试：调用 Mock Translate API 并验证翻译结果', async ({ page }) => {
  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 5000 });

  /**
   * Flow:
   *   /api/components/test
   *     → Rust backend downloads template from mock server (:8787)
   *     → Renders template vars: {{auth.api_key}}, {{input.text}} ...
   *     → POST http://127.0.0.1:9090/api/v1/translate/text
   *       Authorization: Bearer mock-translate-dev-key-2026
   *       { text: "Hello World", source_lang: "en_US", target_lang: "zh_CN" }
   *     → Mock API returns { translated_text: "【zh_CN】Hello World【/zh_CN】" }
   *     → Backend returns { success: true, data: { translated_text: "..." } }
   */
  const testRes = await page.evaluate(
    async ([base, componentKey]: string[]) => {
      const r = await fetch(`${base}/api/components/test`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          component_key: componentKey,
          text:          'Hello World',
          source_lang:   'en_US',
          target_lang:   'zh_CN',
        }),
      });
      return r.json() as Promise<{
        success: boolean;
        data?:   { translated_text: string; elapsed_ms: number; component_key: string };
        error?:  { code: string; message: string };
      }>;
    },
    [BASE, COMPONENT_KEY],
  );

  console.log('Component test response:', JSON.stringify(testRes, null, 2));

  // Top-level success flag
  expect(testRes.success).toBe(true);

  // Translated text present
  const translated = testRes.data?.translated_text ?? '';
  expect(typeof translated).toBe('string');
  expect(translated.length).toBeGreaterThan(0);

  // Mock API wraps text with 【lang】text【/lang】
  // Expected: 【zh_CN】Hello World【/zh_CN】
  expect(translated).toContain('Hello World');
  expect(translated).toContain('zh_CN');

  // Timing and metadata
  expect(typeof testRes.data?.elapsed_ms).toBe('number');
  expect(testRes.data?.component_key).toBe(COMPONENT_KEY);

  console.log(`✅  翻译结果: "${translated}"`);
  console.log('✅  翻译结果格式验证通过（包含原文和语言标记）');
});

// ─── Test 4: 验证翻译结果精确格式 ────────────────────────────────────────────

test('翻译结果格式：符合 Mock API 返回的 【lang】text【/lang】 格式', async ({ page }) => {
  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 5000 });

  // Use a short, deterministic test string
  const testTexts = [
    { text: 'Apple', expected: '【zh_CN】Apple【/zh_CN】' },
    { text: '123',   expected: '【zh_CN】123【/zh_CN】'   },
  ];

  for (const { text, expected } of testTexts) {
    const res = await page.evaluate(
      async ([base, componentKey, inputText]: string[]) => {
        const r = await fetch(`${base}/api/components/test`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({
            component_key: componentKey,
            text:          inputText,
            source_lang:   'en_US',
            target_lang:   'zh_CN',
          }),
        });
        return r.json() as Promise<{ success: boolean; data?: { translated_text: string } }>;
      },
      [BASE, COMPONENT_KEY, text],
    );

    expect(res.success).toBe(true);
    const got = res.data?.translated_text ?? '';
    expect(got).toBe(expected);
    console.log(`✅  "${text}" → "${got}"`);
  }
});

// ─── Test 5: UI 验证 — 翻译组件页展示绑定 ────────────────────────────────────

test('UI 验证：翻译组件页显示绑定条目并可展开 Auth 区块', async ({ page }) => {
  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 5000 });

  // Ensure a local component exists so the current "我的组件" tab can render
  // the component card that exposes the Auth section.
  await page.evaluate(
    async ({ base, componentKey, templateJson }) => {
      await fetch(`${base}/api/components/local/${encodeURIComponent(componentKey)}`, {
        method: 'DELETE',
      }).catch(() => null);
      await fetch(`${base}/api/components/local`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: componentKey,
          name: 'Mock Bearer Token Translator (Local Test)',
          kind: 'text',
          template_id: componentKey,
          template_json: templateJson,
          enabled: true,
        }),
      });
    },
    { base: BASE, componentKey: COMPONENT_KEY, templateJson: MOCK_BEARER_TEMPLATE },
  );

  // Navigate to 翻译组件 page
  await goTo(page, '翻译组件');
  await expect(page.locator('h2:has-text("翻译组件")')).toBeVisible();

  // Switch to 我的组件 tab
  await page.click('button:has-text("我的组件")');
  await page.waitForTimeout(300);

  // The status API confirms at least our binding exists
  const status = await fetch(`${BASE}/api/status`).then(r => r.json());
  const bindings = status?.data?.component_bindings?.components ?? {};
  const bindingKeys = Object.keys(bindings);

  expect(bindingKeys).toContain(COMPONENT_KEY);
  console.log(`✅  绑定列表包含 "${COMPONENT_KEY}"（共 ${bindingKeys.length} 个绑定）`);

  // Click the component row to expand it
  const componentRow = page.locator('button.flex-1').filter({ hasText: COMPONENT_KEY }).first();
  await expect(componentRow).toBeVisible({ timeout: 10_000 });
  await componentRow.click();
  await page.waitForTimeout(500);

  // Auth 凭据 section should be visible
  await expect(page.locator('span:has-text("Auth 凭据")').first()).toBeVisible();
  console.log('✅  Auth 凭据区块可见');
});

// ─── Test 6: 设置页 — 日志设置 Tab ──────────────────────────────────────────

test('设置页：日志设置 Tab 正常加载', async ({ page }) => {
  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 5000 });

  await goTo(page, '设置');
  await expect(page.locator('h2:has-text("设置")')).toBeVisible();

  // Navigate to 日志设置 tab
  await page.click('button:has-text("日志设置")');
  await page.waitForTimeout(500);

  // Toggle and select should be present
  const toggle = page.locator('button[role="switch"], button[aria-label*="日志"]').first();
  await expect(toggle).toBeVisible();

  const levelSelect = page.locator('select').filter({ hasText: 'info' }).first();
  await expect(levelSelect).toBeVisible();

  // Save button
  await expect(page.locator('button:has-text("保存设置")')).toBeVisible();

  // Load current settings
  const settingsRes = await fetch(`${BASE}/api/log-settings`).then(r => r.json());
  console.log('当前日志设置:', JSON.stringify(settingsRes?.data));
  expect(settingsRes.success).toBe(true);
  expect(typeof settingsRes.data.enabled).toBe('boolean');
  expect(typeof settingsRes.data.level).toBe('string');
  console.log('✅  日志设置页验证通过');
});
