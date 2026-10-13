/**
 * SIM-08: 双插件对应 — ATS 自动翻译 vs WPMMCC 同步身份隔离
 *
 * Both plugins share one client. This journey proves:
 *   1. ATS site receives auto-translate writeback (translation-callback)
 *   2. WPMMCC site is bound and identity-verified but never sees ATS
 *      data-plane paths (no content-changes / translation-callback)
 *   3. Sites UI still shows both identity badges after the auto-translate run
 *
 * WPMMCC's native cross-site sync path is covered by SIM-02; here we assert
 * the dual-plugin auto-translate boundary that commercial users rely on.
 */
import { test, expect } from '@playwright/test';
import { MockAtsSite, MockWpmmccSite } from './lib/mock-wp-site';
import { MockTranslateProvider, translatorTemplate } from './lib/mock-provider';
import {
  CLIENT_BASE,
  bindSite,
  createLocalComponent,
  enableDiscoveryTask,
  listDiscoveryTasks,
  bootstrapDiscoveryTasks,
  runWorkerOnce,
  saveComponentAuth,
  setReviewMode,
  unbindSite,
  verifySiteIdentity,
  waitForAtsCallbacks,
} from './lib/sim-client';
import {
  collectLogWindow,
  eventsWithPrefix,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

test.describe('SIM-08: 双插件自动翻译隔离旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const RELATION_ID = 9801;
  const COMPONENT_ID = 'sim08-auto-translator';

  const atsSite = new MockAtsSite({
    siteName: 'Sim Dual ATS',
    routeSecret: 'sim08_ats_secret',
    wpClientToken: 'sim08_tok_ats_0123456789abcdef',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [
      {
        objectId: 8801,
        postType: 'post',
        title: 'Dual-plugin auto title',
        content: '<p>Dual-plugin auto body.</p>',
        excerpt: 'Dual excerpt',
      },
    ],
  });

  const wpmmccSite = new MockWpmmccSite({
    siteUuid: 'sim08-wpmmcc-uuid-0008',
    siteName: 'Sim Dual WPMMCC',
    routeSecret: 'sim08_sync_secret',
    wpClientToken: 'sim08_tok_sync_0123456789abcdef',
    posts: [
      {
        sourceId: 1,
        guid: 'sim08-post-1',
        postType: 'post',
        title: 'WPMMCC only post',
        content: '<p>Should not be auto-translated by ATS lane.</p>',
      },
    ],
  });

  const provider = new MockTranslateProvider();

  test.beforeAll(async () => {
    await atsSite.start();
    await wpmmccSite.start();
    await provider.start();
  });

  test.afterAll(async ({ request }) => {
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await unbindSite(request, wpmmccSite.baseUrl).catch(() => {});
    await setReviewMode(request, false).catch(() => {});
    await atsSite.stop();
    await wpmmccSite.stop();
    await provider.stop();
  });

  test('双站绑定 + ATS 自动翻译只回写 ATS', async ({ page, request }) => {
    test.setTimeout(240_000);
    const mark = await markLogStart(request);

    for (const site of [atsSite, wpmmccSite]) {
      const bind = await bindSite(request, {
        api_base_url: site.baseUrl,
        wp_client_token: site.wpClientToken,
        route_secret: site.routeSecret,
      });
      expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
      const verify = await verifySiteIdentity(request, site.baseUrl);
      expect(verify.success, JSON.stringify(verify.raw)).toBe(true);
    }

    const component = await createLocalComponent(request, {
      id: COMPONENT_ID,
      name: 'SIM-08 Auto Translator',
      kind: 'text',
      templateJson: translatorTemplate(COMPONENT_ID, provider.translateUrl),
    });
    expect(component.success, JSON.stringify(component.raw)).toBe(true);
    expect(
      (await saveComponentAuth(request, COMPONENT_ID, { api_key: 'sim08-key' })).success,
    ).toBe(true);

    await setReviewMode(request, false);

    const boot = await bootstrapDiscoveryTasks(request);
    expect(boot.success, JSON.stringify(boot.raw)).toBe(true);
    const tasks = await listDiscoveryTasks(request);
    const task = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID);
    expect(task, JSON.stringify(tasks)).toBeTruthy();
    expect(
      (await enableDiscoveryTask(request, task!.id, COMPONENT_ID)).success,
    ).toBe(true);

    const pathsBefore = [...wpmmccSite.seenPaths];
    await runWorkerOnce(request);
    await waitForAtsCallbacks(() => atsSite.receivedCallbacks.length, 1, 90_000);

    expect(atsSite.receivedCallbacks.length).toBeGreaterThanOrEqual(1);
    expect(provider.hits.length).toBeGreaterThanOrEqual(1);

    // WPMMCC must not receive ATS translation data-plane traffic.
    const newWpmmccPaths = wpmmccSite.seenPaths.slice(pathsBefore.length);
    for (const path of newWpmmccPaths) {
      expect(path.includes('translation-callback')).toBe(false);
      expect(path.includes('content-changes')).toBe(false);
      expect(path.includes('/wptsall/')).toBe(false);
    }
    // ATS callback path family only.
    for (const path of atsSite.seenPaths) {
      if (path.includes('translation-callback') || path.includes('content-changes')) {
        expect(path.startsWith('POST /wp-json/wptsall/v2/') || path.startsWith('GET /wp-json/wptsall/v2/')).toBe(
          true,
        );
      }
    }

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /站点|Sites/ }).first().click();
    await expect(page.getByText(/ATS/).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/WPMMCC/).first()).toBeVisible({ timeout: 20_000 });

    // ---- Log oracle: ATS auto path ran to writeback; the pair engine
    // (WPMMCC data plane) stayed completely silent in this window.
    // Sequence order note (Wave-2): `discovery.item_translated` is the
    // terminal record (translated AND delivered) and fires AFTER
    // `discovery.callback_sent` — the pre-Wave-2 reverse order only passed
    // via the re-offer storm; FL-2b holds make each row execute once.
    // FL-3 is fixed: task generation now pre-filters wpmmcc-bound domains
    // out of the ATS lane (info worker.domain_lane_excluded, once per
    // domain+identity), so identity_mismatch no longer appears here and
    // needs no allowance. FL-2a landed: MockAtsSite implements the real
    // plugin's claim contract, so the claim succeeds and the radar runs
    // with NO allowances.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'ops.discovery_bootstrapped' },
        { event: 'ops.discovery_task_updated' },
        { event: 'job.created' },
        { event: 'discovery.callback_sent' },
        { event: 'discovery.item_translated' },
        { event: 'job.finalized' },
      ],
      'SIM-08 dual-plugin isolation',
    );
    expect(
      eventsWithPrefix(window, 'sync_engine.').length,
      'WPMMCC pair engine must stay silent during ATS auto-translate',
    ).toBe(0);
    expectNoUnexpected(window, 'SIM-08 dual-plugin isolation', []);
  });
});
