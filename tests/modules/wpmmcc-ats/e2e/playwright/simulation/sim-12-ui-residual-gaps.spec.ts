/**
 * SIM-12: 残余 UI 旅程补齐 (doc 22 G-18 + G-13 的 providers-test UI 面)
 *
 * Three short real-UI journeys that had zero e2e:
 *
 *  1. KeyModal provider test button (`provider-test-btn`): a real
 *     POST /api/providers/test with a fake key → the vendor API (or the
 *     offline timeout) answers → the structured result renders in the
 *     modal. NOTE: the providers-test route generates an OpenAI-compatible
 *     template against the vendor's real base URL — there is no offline
 *     healthy path in this lane by design, so this journey asserts the
 *     full UI round trip with a healthy=false outcome (fake key).
 *  2. Logs page buttons: Load, event-prefix filter (server-side
 *     event_prefix filtering, doc 22 G-15), load-older, auto-refresh
 *     (10s poll), download, and Clear (run last — it truncates the lane
 *     client log).
 *  3. Sites page connection-pack import: paste a real wpmmcc connection
 *     pack (built from a live mock site's real credentials) → the site
 *     appears in the bound-sites list.
 */
import { test, expect } from '@playwright/test';
import { MockWpmmccSite } from './lib/mock-wp-site';
import { CLIENT_BASE, unbindSite, verifySiteIdentity } from './lib/sim-client';
import {
  collectLogWindow,
  eventsNamed,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

test.describe('SIM-12: KeyModal 测通 / Logs 全按钮 / Sites 导入配对码', () => {
  test.describe.configure({ mode: 'serial' });

  const PACK_SITE_UUID = 'sim12-pack-site-0001';
  const packSite = new MockWpmmccSite({
    siteUuid: PACK_SITE_UUID,
    siteName: 'Sim12 Pack Site',
    routeSecret: '*******************',
    wpClientToken: '*********************************',
    posts: [],
  });

  test.beforeAll(async () => {
    await packSite.start();
  });

  test.afterAll(async ({ request }) => {
    await unbindSite(request, packSite.baseUrl).catch(() => {});
    await packSite.stop();
  });

  test('KeyModal 一键测通：真实 POST + 结构化结果渲染', async ({ page, request }) => {
    test.setTimeout(120_000);
    const mark = await markLogStart(request);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /密钥|API Keys/ }).first().click();
    await page.getByRole('button', { name: /Vendor Keys/ }).click();
    await page.getByRole('button', { name: /添加 Key|Add Key/ }).first().click();

    // KeyModal has no role="dialog"; its backdrop is a div.fixed.inset-0
    // with role="button" (aria-label "Close Modal"). Scope to that overlay.
    const modal = page.locator('div.fixed.inset-0');
    await expect(modal).toBeVisible({ timeout: 20_000 });
    // en locale placeholders are literal "Placeholder Id"/"Placeholder
    // Vendor Id"; the auth textarea is labeled "Auth Values (JSON)".
    await modal.getByPlaceholder('Placeholder Id').fill('sim12-probe-key');
    await modal.getByPlaceholder('Placeholder Vendor Id').fill('sim12-probe');
    await modal
      .getByLabel(/Auth Values/i)
      .fill(JSON.stringify({ api_key: '********************' }));

    // One-click test → real POST /api/providers/test (OpenAI-compatible
    // template, fake key → healthy:false with a classified code).
    const testPending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/providers/test') &&
        response.request().method() === 'POST',
      { timeout: 90_000 },
    );
    await page.getByTestId('provider-test-btn').click();
    const tested = await testPending;
    expect(tested.ok(), `providers/test HTTP ${tested.status()}`).toBe(true);
    const body = (await tested.json()) as {
      success?: boolean;
      error?: { code?: string };
      data?: { healthy?: boolean; latency_ms?: number; vendor_id?: string };
    };
    // Fake key against a real vendor endpoint: the probe FAILS, and the
    // product contract for a failed probe is success:false with a
    // structured verdict — error.code classified (AUTH_REJECTED /
    // NETWORK_FAILED / PROVIDER_URL_BLOCKED / PROVIDER_TEST_FAILED) plus
    // data.healthy:false and measured latency. All four codes are valid
    // outcomes of the REAL round trip; what matters is the structure.
    expect(body.success).toBe(false);
    expect(body.error?.code, `probe error body: ${JSON.stringify(body)}`).toBeTruthy();
    expect(
      ['AUTH_REJECTED', 'NETWORK_FAILED', 'PROVIDER_URL_BLOCKED', 'PROVIDER_TEST_FAILED'],
    ).toContain(body.error!.code);
    expect(body.data?.healthy).toBe(false);
    expect(typeof body.data?.latency_ms).toBe('number');

    // The verdict renders inside the modal.
    await expect(page.getByTestId('provider-test-result')).toBeVisible({
      timeout: 20_000,
    });
    await expect(page.getByTestId('provider-test-result')).not.toBeEmpty();

    // ---- Log oracle: the probe event fired. Its warn variant
    // (ok:false, fake key) is the DESIGNED outcome — allowlisted.
    const window = await collectLogWindow(request, mark);
    expect(eventsNamed(window, 'providers.tested').length).toBe(1);
    expectNoUnexpected(window, 'SIM-12 keymodal-probe', ['providers.tested']);

    await page.getByRole('button', { name: /关闭|Close/ }).last().click();
  });

  test('Logs 页全按钮：加载 / 事件前缀过滤 / 翻页 / 自动刷新 / 下载', async ({
    page,
    request,
  }) => {
    test.setTimeout(120_000);
    const mark = await markLogStart(request);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /日志|Logs/ }).first().click();

    // Load → POST /api/logs/recent, lines render.
    const loadPending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/logs/recent') &&
        response.request().method() === 'POST',
      { timeout: 30_000 },
    );
    await page.getByTestId('logs-load').click();
    const loaded = await loadPending;
    expect(loaded.ok(), `logs/recent HTTP ${loaded.status()}`).toBe(true);
    const loadedBody = (await loaded.json()) as { data?: { lines?: string[] } };
    const firstLines = loadedBody.data?.lines ?? [];
    // The lane client logs its own startup (webui.*) and worker events —
    // non-empty is guaranteed after the journeys above.
    expect(firstLines.length).toBeGreaterThan(0);
    await expect(page.getByTestId('logs-showing')).toContainText(/\d+/);

    // Event-prefix filter (G-15): server-side event_prefix filtering. The
    // request body carries the prefix; every returned line's event field
    // starts with it.
    const prefix = 'webui.';
    await page.getByTestId('logs-event-filter').fill(prefix);
    const filterPending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/logs/recent') &&
        response.request().method() === 'POST' &&
        String(response.request().postData()).includes('event_prefix'),
      { timeout: 30_000 },
    );
    await page.getByTestId('logs-load').click();
    const filtered = await filterPending;
    expect(filtered.ok(), `filtered logs/recent HTTP ${filtered.status()}`).toBe(true);
    const filteredBody = (await filtered.json()) as { data?: { lines?: string[] } };
    const filteredLines = filteredBody.data?.lines ?? [];
    expect(filteredLines.length, 'webui.* events must exist in the lane log').toBeGreaterThan(
      0,
    );
    for (const line of filteredLines) {
      let event = '';
      try {
        event = String((JSON.parse(line) as Record<string, unknown>).event ?? '');
      } catch {
        event = '';
      }
      expect(
        event.startsWith(prefix),
        `line escaped the server-side prefix filter: ${line}`,
      ).toBe(true);
    }

    // Auto-refresh (G-15): enable → a fresh /api/logs/recent poll fires
    // within the 10s interval without any click.
    const autoPending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/logs/recent') &&
        response.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await page.getByTestId('logs-auto-refresh').check();
    const autoPolled = await autoPending;
    expect(autoPolled.ok()).toBe(true);
    await page.getByTestId('logs-auto-refresh').uncheck();

    // Download (client-side Blob export of the currently loaded lines).
    const downloadPending = page.waitForEvent('download', { timeout: 30_000 });
    await page.getByTestId('logs-download').click();
    const download = await downloadPending;
    expect(download.suggestedFilename()).toMatch(/\.jsonl$/);

    // Clear (G-15) — LAST, it truncates the lane client log file.
    const clearPending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/logs/clear') &&
        response.request().method() === 'POST',
      { timeout: 30_000 },
    );
    await page.getByTestId('logs-clear').click();
    const cleared = await clearPending;
    expect(cleared.ok(), `logs/clear HTTP ${cleared.status()}`).toBe(true);
    await expect(page.getByText(/日志已清空|Log file cleared/).first()).toBeVisible({
      timeout: 20_000,
    });
    // The view reset to the empty state.
    await expect(page.getByText(/点击「加载日志」|Click "Load Logs"/)).toBeVisible({
      timeout: 20_000,
    });

    // ---- Log oracle: the clear marker line is the only survivor; this
    // window's own actions raised no warn/error.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(window, [{ event: 'logs.cleared' }], 'SIM-12 logs-buttons');
    expectNoUnexpected(window, 'SIM-12 logs-buttons', []);
  });

  test('Sites 页导入连接包：真实 pack → 站点入列', async ({ page, request }) => {
    test.setTimeout(60_000);
    const mark = await markLogStart(request);

    // A real wpmmcc connection pack from a live mock site.
    const pack = {
      schema: 'wpmmcc-site-connection.v1',
      site_url: packSite.baseUrl,
      wp_client_token: packSite.wpClientToken,
      route_secret: packSite.routeSecret,
    };

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /站点|Sites/ }).first().click();

    await page.getByTestId('sites-import-pack-json').fill(JSON.stringify(pack, null, 2));
    await page.getByTestId('sites-import-device-label').fill('sim12-import-device');

    const importPending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/site-connections/import') &&
        response.request().method() === 'POST',
      { timeout: 30_000 },
    );
    await page.getByTestId('sites-import-pack-submit').click();
    const imported = await importPending;
    expect(imported.ok(), `import HTTP ${imported.status()}`).toBe(true);
    const importBody = (await imported.json()) as {
      success?: boolean;
      data?: { api_base_url?: string };
    };
    expect(importBody.success).toBe(true);
    expect(String(importBody.data?.api_base_url)).toContain(packSite.baseUrl.split('//')[1]!);

    // Success toast + the site appears in the bound-sites list.
    await expect(
      page.getByText(/已导入站点连接|Site connection imported/).first(),
    ).toBeVisible({ timeout: 20_000 });
    await expect(
      page.locator('table').getByText(packSite.baseUrl, { exact: false }).first(),
    ).toBeVisible({ timeout: 20_000 });

    // The bound site answers a real identity probe (wptsall-sites filter
    // chips render it as the wpmmcc plugin family).
    const verify = await verifySiteIdentity(request, packSite.baseUrl);
    expect(verify.success, JSON.stringify(verify.raw)).toBe(true);
    expect(String(verify.data.plugin_identity)).toBe('wpmmcc');

    // ---- Log oracle: the import is audited (runs after the Logs test
    // cleared the file — window starts from the post-clear marker).
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [{ event: 'credential.site_connection_imported' }],
      'SIM-12 sites-import',
    );
    expectNoUnexpected(window, 'SIM-12 sites-import', []);

    // Cleanup so later lanes never probe this mock.
    await unbindSite(request, packSite.baseUrl).catch(() => {});
  });
});
