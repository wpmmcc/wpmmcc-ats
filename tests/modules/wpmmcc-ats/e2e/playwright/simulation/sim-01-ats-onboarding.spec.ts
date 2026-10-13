/**
 * SIM-01: ATS 开箱与客户端绑定握手旅程 (real journey, mock-only)
 *
 * Real webmaster onboarding flow against a mock ATS (wptsall) plugin site
 * and the lane-owned client:
 *
 *  1. The webmaster binds the site through the real Sites UI (URL, WP
 *     client token, route secret — the fields the ATS connection pack
 *     carries).
 *  2. The row initially shows the unverified state.
 *  3. "Test" triggers the real identity handshake: the client probes the
 *     site's signed /client/ping, verifies the response signature, and
 *     records plugin_identity=wpmmcc_ats.
 *  4. The Sites row flips to the ATS identity badge; the binding is
 *     persisted with identity_verified_at and route_secret_set.
 *
 * (The WP-admin wizard that MINTS the connection pack is plugin-side and
 * covered by the plugin lanes; this sim owns the client-side handshake.)
 */
import { test, expect } from '@playwright/test';
import { MockAtsSite } from './lib/mock-wp-site';
import { CLIENT_BASE, listBindings, unbindSite } from './lib/sim-client';

test.describe('SIM-01: ATS 开箱与客户端绑定握手旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const atsSite = new MockAtsSite({
    siteName: 'Sim ATS Site',
    routeSecret: 'sim01_ats_secret',
    wpClientToken: 'sim01_tok_ats_0123456789abcdef',
    relation: { id: 9001, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [],
  });

  test.beforeAll(async () => {
    await atsSite.start();
  });

  test.afterAll(async ({ request }) => {
    // Unbind before stopping the mock so later specs never probe a dead site.
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await atsSite.stop();
  });

  test('通过 Sites UI 绑定 ATS 站点并完成真实身份握手', async ({ page }) => {
    test.setTimeout(120_000);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /站点|Sites/ }).first().click();
    await page.getByTestId('sites-add-site').click();

    await page.getByTestId('sites-modal-url').fill(atsSite.baseUrl);
    await page.getByTestId('sites-modal-token').fill(atsSite.wpClientToken);
    await page.getByTestId('sites-modal-route-secret').fill(atsSite.routeSecret);
    await page.getByTestId('sites-modal-save').click();

    // The bound row appears, initially unverified.
    await expect(page.getByText(atsSite.baseUrl).first()).toBeVisible({
      timeout: 20_000,
    });

    // Real identity handshake via the row's Test button.
    await page.getByTestId('sites-test-connection').first().click();

    // The ATS identity badge replaces the unverified state.
    await expect(page.getByText(/ATS/).first()).toBeVisible({ timeout: 30_000 });

    // The mock site really served the signed identity ping.
    expect(atsSite.pingCount).toBeGreaterThanOrEqual(1);
    // ATS-lane isolation: only wptsall/v2 client endpoints were touched.
    for (const path of atsSite.seenPaths) {
      expect(path.startsWith('GET /wp-json/wptsall/v2/')).toBe(true);
    }
  });

  test('绑定契约落盘：wpmmcc_ats 身份 + 验证时间戳 + 密钥存在性', async ({ request }) => {
    const bindings = await listBindings(request);
    const binding = bindings.find((b) => b.api_base_url === atsSite.baseUrl);
    expect(binding, `binding missing: ${JSON.stringify(bindings)}`).toBeTruthy();
    expect(binding!.plugin_identity).toBe('wpmmcc_ats');
    expect(binding!.identity_verified_at).toBeTruthy();
    expect(binding!.route_secret_set).toBe(true);
    expect(binding!.token_prefix).toBeTruthy();
  });
});
