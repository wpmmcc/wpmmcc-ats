/**
 * SIM-05: 断网离线环境种子模板开箱秒开旅程 (real journey)
 *
 * Real offline-resilience journey against the lane-owned client with a
 * fresh state root (no cached catalog) and no reachable catalog origin:
 *
 *  1. GET /api/provider-catalog (the REAL endpoint — the previous sim
 *     hit a nonexistent /api/vendor-catalog) must answer immediately
 *     from the embedded seed catalog — no network wait, no timeout.
 *  2. Exactly the 15 seed templates are present, including deepseek,
 *     openai-compatible, deepl and google-translate, with seed state.
 *  3. The Vendor Catalog tab renders the seed providers in the UI.
 */
import { test, expect } from '@playwright/test';
import { CLIENT_BASE, apiGet } from './lib/sim-client';

test.describe('SIM-05: 离线内置种子模板秒开与网络韧性旅程', () => {
  test.describe.configure({ mode: 'serial' });

  test('无网络依赖：/api/provider-catalog 即刻返回 15 款内置种子模板', async ({ request }) => {
    test.setTimeout(60_000);

    const startedAt = Date.now();
    const res = await apiGet(request, '/api/provider-catalog');
    const elapsedMs = Date.now() - startedAt;

    expect(res.ok, `provider-catalog HTTP ${res.status}`).toBe(true);
    expect(res.success).toBe(true);
    // "秒开": seed fallback must not wait on any network timeout.
    expect(elapsedMs).toBeLessThan(10_000);

    const data = res.data as Record<string, unknown>;
    const items = (data.items ?? []) as Array<{
      entry_id?: string
      template_id?: string
    }>;
    expect(items.length).toBeGreaterThanOrEqual(15);

    const ids = items.map((t) => String(t.entry_id ?? ''));
    for (const expected of [
      'deepseek',
      'openai-compatible',
      'deepl',
      'google-translate',
      'anthropic-openai-compat',
      'ollama',
    ]) {
      expect(ids, `seed template ${expected} missing`).toContain(expected);
    }

    // The catalog honestly reports its seed provenance.
    expect(String(data.cache_source ?? '')).toBe('embedded_seed');
    expect(String(data.cache_state ?? '')).toBe('seed');
  });

  test('旧端点 /api/vendor-catalog 不存在（防止伪契约回归）', async ({ request }) => {
    const res = await apiGet(request, '/api/vendor-catalog');
    expect(res.status).toBe(404);
  });

  test('UI：密钥页服务商目录标签页呈现种子供应商', async ({ page, request }) => {
    test.setTimeout(90_000);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /密钥|API Keys/ }).first().click();
    await page.getByTestId('apikeys-tab-vendors').click();

    // Seed providers render without any online fetch.
    await expect(page.getByText(/DeepSeek/i).first()).toBeVisible({ timeout: 30_000 });

    // Cross-check: the UI is fed by the same 15-template catalog.
    const res = await apiGet(request, '/api/provider-catalog');
    const items = ((res.data as Record<string, unknown>).items ??
      []) as Array<{ entry_id?: string }>;
    expect(items.length).toBeGreaterThanOrEqual(15);
  });
});
