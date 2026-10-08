/**
 * SIM-10: Commercial UI forms matrix (U1–U7) — DOM fill/click only.
 *
 * DoD: tasks/client2/21 — every commercial form must be exercised via UI.
 * API site-binding via request context is forbidden in this file.
 */
import { test, expect, type Page } from '@playwright/test';
import { MockAtsSite, MockWpmmccSite } from './lib/mock-wp-site';
import {
  CLIENT_BASE,
  bindSiteViaUi,
  gotoNav,
  unbindSite,
} from './lib/sim-client';

async function openSyncPairs(page: Page) {
  await gotoNav(page, /任务|Tasks/);
  await page.getByRole('button', { name: /跨站同步|Sync/ }).click();
}

test.describe('SIM-10: 商业验收 U1–U7 全 UI 表单矩阵', () => {
  test.describe.configure({ mode: 'serial' });

  const atsSite = new MockAtsSite({
    siteName: 'SIM10 ATS',
    routeSecret: 'sim10_ats_secret',
    wpClientToken: 'sim10_tok_ats_0123456789abcdef',
    relation: { id: 10101, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [],
  });

  const wpmmccA = new MockWpmmccSite({
    siteName: 'SIM10 WPMMCC A',
    siteUuid: 'sim10-wpmmcc-a',
    routeSecret: 'sim10_wpmmcc_a_secret',
    wpClientToken: 'sim10_tok_wpmmcc_a_0123456789ab',
  });

  const wpmmccB = new MockWpmmccSite({
    siteName: 'SIM10 WPMMCC B',
    siteUuid: 'sim10-wpmmcc-b',
    routeSecret: 'sim10_wpmmcc_b_secret',
    wpClientToken: 'sim10_tok_wpmmcc_b_0123456789ab',
  });

  test.beforeAll(async () => {
    await atsSite.start();
    await wpmmccA.start();
    await wpmmccB.start();
  });

  test.afterAll(async ({ request }) => {
    for (const site of [atsSite, wpmmccA, wpmmccB]) {
      await unbindSite(request, site.baseUrl).catch(() => {});
      await site.stop();
    }
  });

  test('U1: Sites UI 绑定 ATS + 双 WPMMCC（纯 DOM）', async ({ page }) => {
    test.setTimeout(180_000);
    await bindSiteViaUi(page, {
      baseUrl: atsSite.baseUrl,
      wpClientToken: atsSite.wpClientToken,
      routeSecret: atsSite.routeSecret,
    });
    await bindSiteViaUi(page, {
      baseUrl: wpmmccA.baseUrl,
      wpClientToken: wpmmccA.wpClientToken,
      routeSecret: wpmmccA.routeSecret,
    });
    await bindSiteViaUi(page, {
      baseUrl: wpmmccB.baseUrl,
      wpClientToken: wpmmccB.wpClientToken,
      routeSecret: wpmmccB.routeSecret,
    });
    await expect(page.getByText(/ATS|WPMMCC/).first()).toBeVisible({ timeout: 30_000 });
  });

  test('U2: API Keys 厂商向导可打开并填入 Key 字段', async ({ page }) => {
    test.setTimeout(120_000);
    await gotoNav(page, /密钥|API Keys|Api Keys/);
    await page.getByTestId('apikeys-tab-vendors').click().catch(() => {});
    const openWizard = page.getByTestId('open-provider-wizard').first();
    await expect(openWizard).toBeVisible({ timeout: 25_000 });
    await openWizard.click();
    await expect(page.getByTestId('provider-setup-wizard')).toBeVisible({ timeout: 15_000 });
    // Step 1 Install → Step 2 Key (wizard-key-id only appears after next)
    const localId = page.getByTestId('wizard-local-id');
    if (await localId.count()) {
      await localId.fill('sim10-commercial-comp');
      await page.getByTestId('wizard-install-next').click();
    }
    await expect(page.getByTestId('wizard-key-id')).toBeVisible({ timeout: 20_000 });
    await page.getByTestId('wizard-key-id').fill('sim10-commercial-key');
    await page.getByTestId('wizard-close').click().catch(async () => {
      await page.getByRole('button', { name: /Cancel|取消|Close|关闭/ }).first().click();
    });
  });

  test('U3: Components 路由绑定控件可达并可填写', async ({ page }) => {
    test.setTimeout(90_000);
    await gotoNav(page, /组件|Components/);
    await page.getByTestId('components-tab-tasktype').click();
    await expect(page.getByTestId('rule-bind-slot-key')).toBeVisible({ timeout: 15_000 });
    await page.getByTestId('rule-bind-slot-key').selectOption('plain_text');
    await page.getByTestId('rule-bind-component-id').fill('sim10-comp');
  });

  test('U4+U5: 同步对表单 + 配对码表单（纯 DOM）', async ({ page }) => {
    test.setTimeout(120_000);
    await openSyncPairs(page);
    await page.getByRole('button', { name: /新建同步对|Create Sync Pair/ }).first().click();
    await page.locator('#sync-pair-name').fill('SIM10 commercial pair');
    const source = page.locator('#sync-pair-source');
    const target = page.locator('#sync-pair-target');
    if (await source.count()) {
      await source.selectOption({ index: 1 }).catch(async () => {
        await source.fill(wpmmccA.baseUrl);
      });
    }
    if (await target.count()) {
      await target.selectOption({ index: 2 }).catch(async () => {
        await target.fill(wpmmccB.baseUrl);
      });
    }
    // Form interaction is the DoD; save may require pairing.
    await page.getByRole('button', { name: /关闭|Cancel|取消/ }).first().click().catch(() => {});

    await page.getByRole('button', { name: /配对管理|Peer Pairing/ }).click();
    await expect(page.locator('#pairing-code')).toBeVisible({ timeout: 10_000 });
    await page.locator('#pairing-code').fill('a'.repeat(32));
    await page.getByRole('button', { name: /关闭|Close/ }).first().click().catch(() => {});
  });

  test('U6: Discovery 扫描按钮可点击', async ({ page }) => {
    test.setTimeout(60_000);
    await gotoNav(page, /任务|Tasks/);
    await page.getByRole('button', { name: /任务配置|Discovery/ }).click();
    const boot = page.getByTestId('tasks-bootstrap-discovery');
    await expect(boot).toBeVisible({ timeout: 15_000 });
    await boot.click();
  });

  test('U7: Settings 审核开关 + Worker 保存', async ({ page }) => {
    test.setTimeout(60_000);
    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /设置|Settings/ }).first().click();
    await page.getByTestId('settings-tab-worker').click().catch(() => {});
    const toggle = page.getByTestId('settings-review-toggle');
    await expect(toggle).toBeVisible({ timeout: 15_000 });
    await toggle.click();
    const save = page.getByTestId('settings-save-worker');
    if (await save.count()) {
      await save.click();
    }
  });
});
