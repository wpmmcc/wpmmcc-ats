/**
 * SIM-07: ATS 自动翻译即时回写（review_mode=off）真实旅程
 *
 * Counterpart to SIM-04 (review hold). This is the default commercial path:
 *   1. Bind ATS site + local translator component
 *   2. Ensure review_mode is OFF (Settings UI)
 *   3. Bootstrap discovery via Tasks UI → enable component
 *   4. Overview Run Once via UI
 *   5. Wire: mock provider translated + ATS received translation-callback
 *      with machine text; no pending_review hold
 */
import { test, expect } from '@playwright/test';
import { MockAtsSite } from './lib/mock-wp-site';
import { MockTranslateProvider, translatorTemplate } from './lib/mock-provider';
import {
  CLIENT_BASE,
  bindSite,
  createLocalComponent,
  enableDiscoveryTask,
  findDoneItemForRelation,
  getReviewMode,
  listDiscoveryTasks,
  saveComponentAuth,
  setReviewMode,
  unbindSite,
  verifySiteIdentity,
  waitForAtsCallbacks,
} from './lib/sim-client';
import {
  collectLogWindow,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

test.describe('SIM-07: ATS 自动翻译即时回写旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const RELATION_ID = 9701;
  const COMPONENT_ID = 'sim07-auto-translator';

  const atsSite = new MockAtsSite({
    siteName: 'Sim Auto Translate ATS',
    routeSecret: 'sim07_ats_secret',
    wpClientToken: 'sim07_tok_ats_0123456789abcdef',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [
      {
        objectId: 7701,
        postType: 'post',
        title: 'Auto translate title for SIM-07',
        content: '<p>Auto translate body for SIM-07.</p>',
        excerpt: 'Auto excerpt',
      },
    ],
  });

  const provider = new MockTranslateProvider();

  test.beforeAll(async () => {
    await atsSite.start();
    await provider.start();
  });

  test.afterAll(async ({ request }) => {
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await setReviewMode(request, false).catch(() => {});
    await atsSite.stop();
    await provider.stop();
  });

  test('绑定 ATS + 组件 + Settings 关闭审核模式', async ({ page, request }) => {
    test.setTimeout(120_000);

    const bind = await bindSite(request, {
      api_base_url: atsSite.baseUrl,
      wp_client_token: atsSite.wpClientToken,
      route_secret: atsSite.routeSecret,
    });
    expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
    const verify = await verifySiteIdentity(request, atsSite.baseUrl);
    expect(verify.success, JSON.stringify(verify.raw)).toBe(true);
    expect(String(verify.data.plugin_identity)).toBe('wpmmcc_ats');

    const component = await createLocalComponent(request, {
      id: COMPONENT_ID,
      name: 'SIM-07 Auto Translator',
      kind: 'text',
      templateJson: translatorTemplate(COMPONENT_ID, provider.translateUrl, 'SIM-07 Auto Translator'),
    });
    expect(component.success, JSON.stringify(component.raw)).toBe(true);
    const auth = await saveComponentAuth(request, COMPONENT_ID, {
      api_key: 'sim07-mock-provider-key',
    });
    expect(auth.success, JSON.stringify(auth.raw)).toBe(true);

    // Force review off via API first, then confirm Settings UI shows/saves auto mode.
    await setReviewMode(request, false);
    expect(await getReviewMode(request)).toBe(false);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /设置|Settings/ }).first().click();
    await page.getByTestId('settings-tab-worker').click().catch(() => {});
    const toggle = page.getByTestId('settings-review-toggle');
    await expect(toggle).toBeVisible({ timeout: 20_000 });
    // If somehow on, click off and save.
    const aria = await toggle.getAttribute('aria-label');
    if (aria && /禁用审核|Disable review|关闭/i.test(aria) === false && /启用审核|Enable review/i.test(aria)) {
      // aria says "enable review" means currently off — good.
    } else if (aria && /禁用审核|Disable review/i.test(aria)) {
      await toggle.click();
      await page.getByTestId('settings-save-worker').click();
    }
    expect(await getReviewMode(request)).toBe(false);
  });

  test('Tasks UI 扫描站点 + 启用组件 + Overview 立即运行 → 即时回写', async ({
    page,
    request,
  }) => {
    test.setTimeout(240_000);
    const mark = await markLogStart(request);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /任务|Tasks/ }).first().click();
    await page.getByRole('button', { name: /任务配置|Discovery/ }).click();

    const bootPending = page.waitForResponse(
      (r) =>
        r.url().includes('/api/discovery-tasks/bootstrap') &&
        r.request().method() === 'POST',
      { timeout: 60_000 },
    );
    await page.getByTestId('tasks-bootstrap-discovery').click();
    const bootRes = await bootPending;
    expect(bootRes.ok(), `bootstrap HTTP ${bootRes.status()}`).toBe(true);

    const tasks = await listDiscoveryTasks(request);
    const task = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID);
    expect(task, `discovery task missing: ${JSON.stringify(tasks)}`).toBeTruthy();

    const enable = await enableDiscoveryTask(request, task!.id, COMPONENT_ID);
    expect(enable.success, JSON.stringify(enable.raw)).toBe(true);

    // Refresh discovery tab so the enabled state is visible, then Run Once.
    await page.getByRole('button', { name: /概览|Overview/ }).first().click();
    const runPending = page.waitForResponse(
      (r) =>
        r.url().includes('/api/worker/run-once') && r.request().method() === 'POST',
      { timeout: 180_000 },
    );
    await page.getByTestId('overview-run-once').click();
    const runRes = await runPending;
    expect(runRes.ok(), `run-once HTTP ${runRes.status()}`).toBe(true);

    await waitForAtsCallbacks(() => atsSite.receivedCallbacks.length, 1, 90_000);

    expect(provider.hits.length).toBeGreaterThanOrEqual(1);
    expect(atsSite.receivedCallbacks.length).toBeGreaterThanOrEqual(1);
    const callback = atsSite.receivedCallbacks[atsSite.receivedCallbacks.length - 1];
    expect(callback.idempotencyKey).toBeTruthy();
    expect(Number(callback.payload.relation_id)).toBe(RELATION_ID);
    const fields = (callback.payload.translated_fields ?? {}) as Record<string, string>;
    const machine = Object.values(fields).filter((v) => v.includes('【zh_CN】'));
    expect(
      machine.length,
      `machine translation missing from callback: ${JSON.stringify(fields)}`,
    ).toBeGreaterThan(0);

    // Auto path: job progress shows completed writeback (not pending_review).
    const done = await findDoneItemForRelation(request, RELATION_ID);
    if ('doneItems' in done) {
      expect(done.doneItems).toBeGreaterThan(0);
    } else {
      expect(String(done.status)).toBe('done');
    }

    // ---- Log oracle: auto-translate chain, in the product's real order.
    // `discovery.item_translated` is the TERMINAL success record (translated
    // AND delivered): the discoverer emits `discovery.callback_sent` first,
    // then `discovery.item_translated`. The pre-Wave-2 spec asserted the
    // reverse order and only passed because the re-offer storm re-ran the
    // whole chain every iteration (a later iteration's callback_sent always
    // existed); with FL-2b's re-offer holds the row executes exactly once,
    // so the assertion now reflects the true single-execution order.
    // FL-2a landed: MockAtsSite implements the real plugin's claim contract
    // (30-minute lock, claimed items leave /content), so the claim succeeds
    // and the radar runs with NO allowances — any warn/error in this
    // window is a regression.
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
      'SIM-07 auto-translate',
    );
    expectNoUnexpected(window, 'SIM-07 auto-translate', []);
  });
});
