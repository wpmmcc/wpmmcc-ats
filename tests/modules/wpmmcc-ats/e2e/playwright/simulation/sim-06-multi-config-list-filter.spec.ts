/**
 * SIM-06: 多站点配置列表 + 身份筛选 + 域名搜索（真实 UI）
 *
 * Proves the webmaster multi-config path that was previously only unit-tested:
 *   1. Add ATS + WPMMCC sites through the Sites modal UI
 *   2. Identity badges appear for both plugin families
 *   3. Filter pills (ATS / WPMMCC / All) shrink the list
 *   4. Domain search filters the list
 *   5. History filter controls are present and fire list requests
 */
import { test, expect } from '@playwright/test';
import { MockAtsSite, MockWpmmccSite } from './lib/mock-wp-site';
import {
  CLIENT_BASE,
  bindSiteViaUi,
  unbindSite,
} from './lib/sim-client';

test.describe('SIM-06: 多配置列表筛选与历史过滤 UI', () => {
  test.describe.configure({ mode: 'serial' });

  const atsSite = new MockAtsSite({
    siteName: 'Sim Multi ATS',
    routeSecret: 'sim06_ats_secret',
    wpClientToken: 'sim06_tok_ats_0123456789abcdef',
    relation: { id: 9601, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [],
  });

  const wpmmccSite = new MockWpmmccSite({
    siteUuid: 'sim06-wpmmcc-uuid-0006',
    siteName: 'Sim Multi WPMMCC',
    routeSecret: 'sim06_sync_secret',
    wpClientToken: 'sim06_tok_sync_0123456789abcdef',
    posts: [],
  });

  test.beforeAll(async () => {
    await atsSite.start();
    await wpmmccSite.start();
  });

  test.afterAll(async ({ request }) => {
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await unbindSite(request, wpmmccSite.baseUrl).catch(() => {});
    await atsSite.stop();
    await wpmmccSite.stop();
  });

  test('UI 连续添加双插件站点并完成身份握手', async ({ page }) => {
    test.setTimeout(180_000);

    await bindSiteViaUi(page, {
      baseUrl: atsSite.baseUrl,
      wpClientToken: atsSite.wpClientToken,
      routeSecret: atsSite.routeSecret,
    });
    await expect(page.getByText(/ATS/).first()).toBeVisible({ timeout: 30_000 });

    await bindSiteViaUi(page, {
      baseUrl: wpmmccSite.baseUrl,
      wpClientToken: wpmmccSite.wpClientToken,
      routeSecret: wpmmccSite.routeSecret,
    });
    await expect(page.getByText(/WPMMCC/).first()).toBeVisible({ timeout: 30_000 });

    await expect(page.getByText(atsSite.baseUrl).first()).toBeVisible();
    await expect(page.getByText(wpmmccSite.baseUrl).first()).toBeVisible();
    expect(atsSite.pingCount).toBeGreaterThanOrEqual(1);
    expect(wpmmccSite.pingCount).toBeGreaterThanOrEqual(1);
  });

  test('身份筛选 pill + 域名搜索收缩列表', async ({ page }) => {
    test.setTimeout(60_000);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /站点|Sites/ }).first().click();

    await page.getByTestId('sites-filter-ats').click();
    await expect(page.getByText(atsSite.baseUrl).first()).toBeVisible();
    await expect(page.getByText(wpmmccSite.baseUrl)).toHaveCount(0);

    await page.getByTestId('sites-filter-wpmmcc').click();
    await expect(page.getByText(wpmmccSite.baseUrl).first()).toBeVisible();
    await expect(page.getByText(atsSite.baseUrl)).toHaveCount(0);

    await page.getByTestId('sites-filter-all').click();
    await expect(page.getByText(atsSite.baseUrl).first()).toBeVisible();
    await expect(page.getByText(wpmmccSite.baseUrl).first()).toBeVisible();

    // Domain search: unique port substring from ATS URL.
    const atsPort = new URL(atsSite.baseUrl).port;
    await page.getByTestId('sites-search').fill(atsPort);
    await expect(page.getByText(atsSite.baseUrl).first()).toBeVisible();
    await expect(page.getByText(wpmmccSite.baseUrl)).toHaveCount(0);

    await page.getByTestId('sites-search').fill('');
    await page.getByTestId('sites-filter-all').click();
    await expect(page.getByText(wpmmccSite.baseUrl).first()).toBeVisible();
  });

  test('History 筛选控件可见并触发 translations 列表请求', async ({ page }) => {
    test.setTimeout(60_000);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /历史|History/ }).first().click();

    await expect(page.getByTestId('status-filter')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('history-domain-filter')).toBeVisible();
    await expect(page.getByTestId('history-search-filter')).toBeVisible();

    const pending = page.waitForResponse(
      (r) =>
        r.url().includes('/api/translations') &&
        r.request().method() === 'GET' &&
        r.url().includes('status=failed'),
      { timeout: 20_000 },
    );
    await page.getByTestId('status-filter').selectOption('failed');
    const res = await pending;
    expect(res.ok(), `translations filter HTTP ${res.status()}`).toBe(true);

    await page.getByTestId('history-domain-filter').fill(atsSite.baseUrl);
    await page.getByTestId('history-clear-filters').click();
    await expect(page.getByTestId('history-domain-filter')).toHaveValue('');
  });
});
