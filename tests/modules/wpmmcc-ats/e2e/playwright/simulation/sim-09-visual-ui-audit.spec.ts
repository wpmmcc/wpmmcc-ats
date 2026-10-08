/**
 * SIM-09: Client WebUI visual audit — seed dual-plugin data, walk every
 * primary page + key sub-tabs, capture full-page screenshots for human/AI
 * layout review. Failures still assert reachability (no blank/error shells).
 *
 * Screenshots land in:
 *   $WPTSALL_LANE_STATE_ROOT/visual-audit/   (copied into simulation report)
 *   or ./test-results/visual-audit/ as fallback
 */
import { test, expect, type Page } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { MockAtsSite, MockWpmmccSite } from './lib/mock-wp-site';
import { MockTranslateProvider, translatorTemplate } from './lib/mock-provider';
import {
  CLIENT_BASE,
  bindSite,
  createLocalComponent,
  saveComponentAuth,
  setReviewMode,
  unbindSite,
  verifySiteIdentity,
  bootstrapDiscoveryTasks,
  enableDiscoveryTask,
  listDiscoveryTasks,
} from './lib/sim-client';

const OUT_DIR =
  process.env.WPTSALL_LANE_STATE_ROOT
    ? path.join(process.env.WPTSALL_LANE_STATE_ROOT, 'visual-audit')
    : path.join(process.cwd(), 'test-results', 'visual-audit');

const NAV: Array<{ name: RegExp; shot: string; assert: RegExp }> = [
  { name: /概览|Overview/, shot: '01-overview', assert: /Worker|运行|概览|Overview|Run/i },
  { name: /站点|Sites/, shot: '02-sites', assert: /ATS|WPMMCC|添加|Add|站点|Sites/i },
  { name: /组件|Components/, shot: '03-components', assert: /组件|Component|Local|本地/i },
  { name: /API 密钥|API Keys|密钥/, shot: '04-apikeys', assert: /密钥|Key|Vendor|目录|Catalog|服务商/i },
  { name: /任务|Tasks/, shot: '05-tasks', assert: /任务|Job|Discovery|待审|Pending|同步/i },
  { name: /日志|Logs/, shot: '06-logs', assert: /日志|Log|级别|Level/i },
  { name: /历史|History/, shot: '07-history', assert: /历史|History|筛选|Filter|状态|Status/i },
  { name: /设置|Settings/, shot: '08-settings', assert: /设置|Settings|Worker|代理|Proxy|审核/i },
];

async function shot(page: Page, name: string) {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const file = path.join(OUT_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  // eslint-disable-next-line no-console
  console.log(`visual-audit: wrote ${file}`);
  return file;
}

async function gotoNav(page: Page, name: RegExp) {
  await page.goto(CLIENT_BASE);
  await page.getByRole('button', { name }).first().click();
  await page.waitForTimeout(400);
}

test.describe('SIM-09: 客户端全页视觉审计与截图', () => {
  test.describe.configure({ mode: 'serial' });

  const RELATION_ID = 9901;
  const COMPONENT_ID = 'sim09-visual-translator';

  const atsSite = new MockAtsSite({
    siteName: 'Visual ATS',
    routeSecret: 'sim09_ats_secret',
    wpClientToken: 'sim09_tok_ats_0123456789abcdef',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [
      {
        objectId: 9901,
        postType: 'post',
        title: 'Visual audit post',
        content: '<p>Body for visual audit.</p>',
        excerpt: 'excerpt',
      },
    ],
  });

  const wpmmccSite = new MockWpmmccSite({
    siteUuid: 'sim09-wpmmcc-uuid-0009',
    siteName: 'Visual WPMMCC',
    routeSecret: 'sim09_sync_secret',
    wpClientToken: 'sim09_tok_sync_0123456789abcdef',
    posts: [],
  });

  const provider = new MockTranslateProvider();

  test.beforeAll(async () => {
    await atsSite.start();
    await wpmmccSite.start();
    await provider.start();
    fs.mkdirSync(OUT_DIR, { recursive: true });
  });

  test.afterAll(async ({ request }) => {
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await unbindSite(request, wpmmccSite.baseUrl).catch(() => {});
    await setReviewMode(request, false).catch(() => {});
    await atsSite.stop();
    await wpmmccSite.stop();
    await provider.stop();
  });

  test('播种双插件站点 + 翻译组件（供页面有真实列表）', async ({ request }) => {
    test.setTimeout(120_000);
    for (const site of [atsSite, wpmmccSite]) {
      expect(
        (await bindSite(request, {
          api_base_url: site.baseUrl,
          wp_client_token: site.wpClientToken,
          route_secret: site.routeSecret,
        })).success,
      ).toBe(true);
      expect((await verifySiteIdentity(request, site.baseUrl)).success).toBe(true);
    }
    expect(
      (
        await createLocalComponent(request, {
          id: COMPONENT_ID,
          name: 'SIM-09 Visual Translator',
          kind: 'text',
          templateJson: translatorTemplate(COMPONENT_ID, provider.translateUrl),
        })
      ).success,
    ).toBe(true);
    expect(
      (await saveComponentAuth(request, COMPONENT_ID, { api_key: 'sim09-key' })).success,
    ).toBe(true);
    await setReviewMode(request, false);
    expect((await bootstrapDiscoveryTasks(request)).success).toBe(true);
    const tasks = await listDiscoveryTasks(request);
    const task = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID);
    if (task) {
      await enableDiscoveryTask(request, task.id, COMPONENT_ID);
    }
  });

  test('主导航八页截图 + 非空可达断言', async ({ page }) => {
    test.setTimeout(180_000);
    const manifest: Array<{ page: string; file: string; bodyLen: number }> = [];

    for (const entry of NAV) {
      await gotoNav(page, entry.name);
      const body = await page.locator('body').innerText();
      expect(body.length, `${entry.shot} blank`).toBeGreaterThan(40);
      expect(body, `${entry.shot} missing expected copy`).toMatch(entry.assert);
      // No fatal error banners that indicate a crashed shell.
      expect(body).not.toMatch(/UNKNOWN_PATH|Cannot read properties|Unhandled|TypeError/i);
      const file = await shot(page, entry.shot);
      manifest.push({ page: entry.shot, file, bodyLen: body.length });
    }

    fs.writeFileSync(
      path.join(OUT_DIR, 'manifest.json'),
      JSON.stringify({ outDir: OUT_DIR, pages: manifest, generatedAt: new Date().toISOString() }, null, 2),
    );
  });

  test('Sites 筛选态 + Tasks 子标签 + Settings Worker 截图', async ({ page }) => {
    test.setTimeout(120_000);

    await gotoNav(page, /站点|Sites/);
    await page.getByTestId('sites-filter-ats').click();
    await expect(page.getByText(atsSite.baseUrl).first()).toBeVisible();
    await shot(page, '02b-sites-filter-ats');
    await page.getByTestId('sites-filter-wpmmcc').click();
    await expect(page.getByText(wpmmccSite.baseUrl).first()).toBeVisible();
    await shot(page, '02c-sites-filter-wpmmcc');
    await page.getByTestId('sites-filter-all').click();
    await page.getByTestId('sites-add-site').click();
    await expect(page.getByTestId('sites-modal-url')).toBeVisible();
    const modalText = await page.locator('[role="dialog"], .fixed').filter({ has: page.getByTestId('sites-modal-url') }).first().innerText();
    // Fail closed on unfinished Title-Case i18n placeholders caught in visual QA.
    expect(modalText).not.toMatch(/Label Domain|Label Wp Token|Add Site Title|Placeholder Token New|Domain Hint/i);
    expect(modalText).toMatch(/Site URL|站点 URL|域名/i);
    await shot(page, '02d-sites-add-modal');
    await page.keyboard.press('Escape').catch(() => {});
    // Close modal if Escape did not (click outside / cancel).
    const cancel = page.getByRole('button', { name: /取消|Cancel|关闭|Close/ }).first();
    if (await cancel.count()) await cancel.click().catch(() => {});

    await gotoNav(page, /任务|Tasks/);
    for (const [label, shotName] of [
      [/任务配置|Discovery/, '05b-tasks-discovery'],
      [/待审|Pending/, '05c-tasks-pending'],
      [/跨站同步|Sync/, '05d-tasks-sync-pairs'],
      [/翻译任务|Jobs/, '05e-tasks-jobs'],
    ] as const) {
      const btn = page.getByRole('button', { name: label }).first();
      if (await btn.count()) {
        await btn.click();
        await page.waitForTimeout(300);
        await shot(page, shotName);
      }
    }

    await gotoNav(page, /设置|Settings/);
    await page.getByTestId('settings-tab-worker').click();
    await expect(page.getByTestId('settings-review-toggle')).toBeVisible({ timeout: 15_000 });
    await shot(page, '08b-settings-worker');

    await gotoNav(page, /API 密钥|API Keys|密钥/);
    const vendorsTab = page.getByTestId('apikeys-tab-vendors');
    if (await vendorsTab.count()) {
      await vendorsTab.click();
      await page.waitForTimeout(400);
      await shot(page, '04b-apikeys-vendors');
    }
  });
});
