/**
 * SIM-04: 双栏 Diff 审核润色与批准回写闭环旅程 (real journey, mock-only)
 *
 * The full reviewer loop against a mock ATS site, an in-spec mock
 * translation provider, and the lane-owned client — every stage is real:
 *
 *  1. A local translator component is created via the components API and
 *     bound to a discovery task for the mock site's relation.
 *  2. Review mode is enabled; one real worker run discovers the mock
 *     site's post, translates it through the mock provider, and lands a
 *     pending_review item (no writeback yet — review mode holds it).
 *  3. The reviewer opens Tasks → Pending Review → Review, polishes the
 *     machine translation in the UI and saves (PUT /api/items/:id/translated).
 *  4. "确认同步到 WP" (approve) pushes the polished version through the
 *     real WP callback to the mock site with an Idempotency-Key.
 *  5. Wire evidence: the mock site received exactly the human-polished
 *     text (not the machine translation), the item is `done`, and the
 *     pending queue no longer contains it.
 */
import { test, expect } from '@playwright/test';
import * as http from 'node:http';
import { MockAtsSite } from './lib/mock-wp-site';
import {
  CLIENT_BASE,
  apiGet,
  bindSite,
  bootstrapDiscoveryTasks,
  createLocalComponent,
  enableDiscoveryTask,
  findPendingReviewItem,
  getReviewMode,
  listDiscoveryTasks,
  runWorkerOnce,
  saveComponentAuth,
  setReviewMode,
  unbindSite,
  verifySiteIdentity,
} from './lib/sim-client';
import {
  collectLogWindow,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

/** Pending-review aggregation across every relation (doc 22 G-03/G-13). */
async function pendingReviewItems(request: import('@playwright/test').APIRequestContext) {
  const res = await apiGet(request, '/api/items/pending-review');
  expect(res.success, JSON.stringify(res.raw)).toBe(true);
  const items = ((res.data as Record<string, unknown>).items ??
    []) as Array<Record<string, unknown>>;
  return items;
}

test.describe('SIM-04: 双栏 Diff 审核润色与回写闭环旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const RELATION_ID = 9401;

  const atsSite = new MockAtsSite({
    siteName: 'Sim Review Site',
    routeSecret: '****************',
    wpClientToken: '******************************',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [
      {
        objectId: 7011,
        postType: 'post',
        title: 'Machine translation needs polish',
        content: '<p>The raw post body for the review journey.</p>',
        excerpt: 'Raw excerpt',
      },
    ],
  });

  // Second site for the reject / batch-reject extensions (G-03/G-13): a
  // distinct relation so the approve journey above is untouched.
  const RELATION_ID_B = 9402;
  const atsSiteB = new MockAtsSite({
    siteName: 'Sim Review Reject Site',
    routeSecret: '****************',
    wpClientToken: '******************************',
    relation: { id: RELATION_ID_B, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [
      {
        objectId: 7021,
        postType: 'post',
        title: 'Reject me with a reason',
        content: '<p>Single reject journey body.</p>',
        excerpt: 'Reject excerpt',
      },
      {
        objectId: 7022,
        postType: 'post',
        title: 'Batch reject me',
        content: '<p>Batch reject journey body.</p>',
        excerpt: 'Batch excerpt',
      },
    ],
  });

  // In-spec mock translation provider (OpenAI-compatible-ish body shape).
  const providerHits: Array<Record<string, unknown>> = [];
  let providerServer: http.Server | null = null;
  let providerPort = 0;

  test.beforeAll(async () => {
    await atsSite.start();

    providerServer = http.createServer((req, res) => {
      let body = '';
      req.on('data', (chunk) => (body += chunk));
      req.on('end', () => {
        let payload: Record<string, unknown> = {};
        try {
          payload = JSON.parse(body) as Record<string, unknown>;
        } catch {
          /* keep */
        }
        providerHits.push(payload);
        const text = String(payload.text ?? '');
        const out = JSON.stringify({
          translated_text: `[zh] ${text}`,
        });
        res.writeHead(200, {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(out).toString(),
        });
        res.end(out);
      });
    });
    await new Promise<void>((resolve) => {
      providerServer!.listen(0, '127.0.0.1', () => resolve());
    });
    providerPort = (providerServer.address() as import('node:net').AddressInfo).port;
  });

  test.afterAll(async ({ request }) => {
    // Unbind before stopping the mock so later specs never probe a dead
    // site, and leave review mode off for lanes sharing this client.
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await unbindSite(request, atsSiteB.baseUrl).catch(() => {});
    await setReviewMode(request, false).catch(() => {});
    await atsSite.stop();
    await atsSiteB.stop().catch(() => {});
    if (providerServer) {
      await new Promise<void>((resolve) => providerServer!.close(() => resolve()));
    }
  });

  test('绑定站点 + 建立本地翻译组件 + 开启审核模式', async ({ request }) => {
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
      id: 'sim04-review-translator',
      name: 'SIM-04 Review Translator',
      kind: 'text',
      templateJson: {
        id: 'sim04-review-translator',
        name: 'SIM-04 Review Translator',
        version: '1.0.0',
        type: 'text_translation',
        auth: { fields: [{ name: 'api_key', required: true }] },
        request: {
          method: 'POST',
          url: `http://127.0.0.1:${providerPort}/translate`,
          headers: {
            'Content-Type': 'application/json',
            Authorization: 'Bearer {{auth.api_key}}',
          },
          body: {
            text: '{{input.text}}',
            source_lang: '{{input.source_lang}}',
            target_lang: '{{input.target_lang}}',
          },
        },
        response: { translated_text_path: 'translated_text' },
      },
    });
    expect(component.success, JSON.stringify(component.raw)).toBe(true);

    // The template declares a required api_key auth field; store its value
    // through the real bindings upsert (Components page auth-values flow).
    const auth = await saveComponentAuth(request, 'sim04-review-translator', {
      api_key: 'sim04-mock-provider-key',
    });
    expect(auth.success, JSON.stringify(auth.raw)).toBe(true);

    await setReviewMode(request, true);
    expect(await getReviewMode(request)).toBe(true);
  });

  test('真实 worker 运行：发现 → 翻译 → 进入待审池（无回写）', async ({ request }) => {
    test.setTimeout(180_000);

    const boot = await bootstrapDiscoveryTasks(request);
    expect(boot.success, JSON.stringify(boot.raw)).toBe(true);

    const tasks = await listDiscoveryTasks(request);
    const task = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID);
    expect(task, `discovery task for relation ${RELATION_ID}: ${JSON.stringify(tasks)}`).toBeTruthy();

    const enable = await enableDiscoveryTask(request, task!.id, 'sim04-review-translator');
    expect(enable.success, JSON.stringify(enable.raw)).toBe(true);

    // eslint-disable-next-line no-console
    console.log(
      'sim04: tasks after enable =',
      JSON.stringify(await listDiscoveryTasks(request)),
    );

    const summary = await runWorkerOnce(request);
    // eslint-disable-next-line no-console
    console.log('sim04: worker run-once summary =', JSON.stringify(summary));

    // The item exists in pending_review (review mode holds the writeback).
    const item = await findPendingReviewItem(request, RELATION_ID);
    expect(Number(item.id)).toBeGreaterThan(0);

    // The mock provider really translated (machine output is provable).
    expect(providerHits.length).toBeGreaterThanOrEqual(1);

    // The lifecycle outbox row was acked completed on the wire (the
    // translation itself finished; only the writeback is held for review).
    expect(atsSite.receivedAcks.length).toBeGreaterThanOrEqual(1);
    expect(atsSite.receivedAcks.every((a) => a.outcome === 'completed')).toBe(true);

    // No callback reached the site while under review.
    expect(atsSite.receivedCallbacks.length).toBe(0);
  });

  test('审核员润色并批准：人工版本回写至站点（Idempotency-Key）', async ({
    page,
    request,
  }) => {
    test.setTimeout(180_000);
    const mark = await markLogStart(request);

    const item = await findPendingReviewItem(request, RELATION_ID);
    const itemId = Number(item.id);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /任务|Tasks/ }).first().click();
    await page.getByRole('button', { name: /待审核|Pending Review/ }).click();

    const row = page.locator('tr').filter({ hasText: String(itemId) }).first();
    await expect(row).toBeVisible({ timeout: 30_000 });
    await row.getByRole('button', { name: /审阅|Review/ }).click();

    await expect(page.getByTestId('review-save')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('review-approve')).toBeVisible({ timeout: 30_000 });

    // Human polish: edit the translated post_title through the dual-pane
    // editor (the editable field carries a stable testid per field key).
    const polished = '人工润色后的标题（SIM-04 journey）';
    const editable = page.getByTestId('review-field-post_title');
    await expect(editable).toBeVisible({ timeout: 15_000 });
    await expect(editable).not.toBeDisabled();

    const savePending = page.waitForResponse(
      (response) =>
        response.url().includes(`/api/items/${itemId}/translated`) &&
        response.request().method() === 'PUT',
      { timeout: 30_000 },
    );
    await editable.fill(polished);
    await page.getByTestId('review-save').first().click();
    const saved = await savePending;
    expect(saved.ok(), `save translated HTTP ${saved.status()}`).toBe(true);

    // Approve → real WP writeback of the polished version.
    const approvePending = page.waitForResponse(
      (response) =>
        response.url().includes(`/api/items/${itemId}/approve`) &&
        response.request().method() === 'POST',
      { timeout: 60_000 },
    );
    await page.getByTestId('review-approve').click();
    const approved = await approvePending;
    expect(approved.ok(), `approve HTTP ${approved.status()}`).toBe(true);
    const approveJson = (await approved.json()) as { success?: boolean };
    expect(approveJson.success).toBe(true);

    // ---- Wire evidence on the mock site.
    expect(atsSite.receivedCallbacks.length).toBeGreaterThanOrEqual(1);
    const callback = atsSite.receivedCallbacks[atsSite.receivedCallbacks.length - 1];
    expect(callback.idempotencyKey).toBeTruthy();
    expect(Number(callback.payload.relation_id)).toBe(RELATION_ID);
    const translatedFields = (callback.payload.translated_fields ??
      {}) as Record<string, string>;
    const polishedValues = Object.values(translatedFields).filter((v) =>
      v.includes('人工润色'),
    );
    expect(
      polishedValues.length,
      `polished text missing from callback: ${JSON.stringify(translatedFields)}`,
    ).toBeGreaterThan(0);

    // The item is done and left the pending queue (search every job for the
    // relation — the outbox fast-path job is separate from the relation-scan
    // job).
    const jobsRes = await apiGet(request, '/api/jobs');
    const jobsData = jobsRes.data as Record<string, unknown>;
    const candidateJobs = ((jobsData.items ?? []) as Array<Record<string, unknown>>).filter(
      (j) => Number(j.relation_id ?? 0) === RELATION_ID,
    );
    expect(candidateJobs.length, `jobs for relation ${RELATION_ID}`).toBeGreaterThan(0);
    let doneItem: Record<string, unknown> | undefined;
    for (const job of candidateJobs) {
      const itemsRes = await apiGet(request, `/api/jobs/${job.id}/items?limit=50`);
      const items = ((itemsRes.data as Record<string, unknown>).items ??
        []) as Array<{ id?: number; status?: string }>;
      doneItem = items.find((i) => Number(i.id) === itemId);
      if (doneItem) break;
    }
    expect(doneItem, `item ${itemId} missing from job items`).toBeTruthy();
    expect(String(doneItem!.status)).toBe('done');

    // ---- Log oracle: polish-save (which itself triggers a resync) →
    // approve, in the observed wire order. FO-1 (Wave-1 finding): the
    // approve-path writeback emits `pipeline.sync_done` but — unlike the
    // auto-translate path — NO `discovery.callback_sent` audit event; wire
    // evidence above proves the callback physically arrived. Asserted
    // as-is pending the batch fix.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'review.item_translated_saved' },
        { event: 'pipeline.sync_done' },
        { event: 'review.item_approved' },
      ],
      'SIM-04 polish-approve',
    );
    expectNoUnexpected(window, 'SIM-04 polish-approve', []);
  });

  // ====================================================================
  // G-03/G-13 extensions: single reject (with reason), batch reject, and
  // the /api/items/pending-review aggregation — all through the real UI.
  // ====================================================================

  test('拒绝站点准备 + 真实 worker：两条进入待审池（无回写）', async ({ request }) => {
    test.setTimeout(180_000);

    await atsSiteB.start();
    const bind = await bindSite(request, {
      api_base_url: atsSiteB.baseUrl,
      wp_client_token: atsSiteB.wpClientToken,
      route_secret: atsSiteB.routeSecret,
    });
    expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
    const verify = await verifySiteIdentity(request, atsSiteB.baseUrl);
    expect(verify.success, JSON.stringify(verify.raw)).toBe(true);

    // Review mode stays on from the approve journey (serial order).
    expect(await getReviewMode(request)).toBe(true);

    const boot = await bootstrapDiscoveryTasks(request);
    expect(boot.success, JSON.stringify(boot.raw)).toBe(true);
    const tasks = await listDiscoveryTasks(request);
    const taskB = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID_B);
    expect(
      taskB,
      `discovery task for relation ${RELATION_ID_B}: ${JSON.stringify(tasks)}`,
    ).toBeTruthy();
    const enable = await enableDiscoveryTask(request, taskB!.id, 'sim04-review-translator');
    expect(enable.success, JSON.stringify(enable.raw)).toBe(true);

    const summary = await runWorkerOnce(request);
    // eslint-disable-next-line no-console
    console.log('sim04b: worker run-once summary =', JSON.stringify(summary));

    // Aggregation endpoint: both items of relation B are pending, with
    // machine-translated proposals and no writeback to the site.
    const items = await pendingReviewItems(request);
    const relB = items.filter((i) => Number(i.relation_id ?? 0) === RELATION_ID_B);
    expect(relB.length, `relation ${RELATION_ID_B} pending items: ${JSON.stringify(items)}`)
      .toBe(2);
    expect(atsSiteB.receivedCallbacks.length).toBe(0);
  });

  test('单条拒绝（带理由）：出待审池、状态 rejected、无回写', async ({ page, request }) => {
    test.setTimeout(120_000);
    const mark = await markLogStart(request);

    const items = await pendingReviewItems(request);
    const target = items.find(
      (i) =>
        Number(i.relation_id ?? 0) === RELATION_ID_B && Number(i.wp_object_id ?? 0) === 7021,
    );
    expect(target, `single-reject item missing: ${JSON.stringify(items)}`).toBeTruthy();
    const itemId = Number(target!.id);
    const itemRowId = Number(target!.wp_object_id);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /任务|Tasks/ }).first().click();
    // Anchored full-name match: the tab is "Pending"/"待审核"; job cards
    // also carry "Pending Review" inside their accessible names.
    await page
      .getByRole('button', { name: /^(待审核|Pending( Review)?)$/ })
      .click();

    // The pending table's ID column shows the WP object id (7021), not the
    // internal translation-item id.
    const row = page.locator('tr').filter({ hasText: String(itemRowId) }).first();
    await expect(row).toBeVisible({ timeout: 30_000 });
    await row.getByRole('button', { name: /审阅|Review/ }).click();

    await expect(page.getByTestId('review-reject')).toBeVisible({ timeout: 30_000 });
    const rejectPending = page.waitForResponse(
      (response) =>
        response.url().includes(`/api/items/${itemId}/reject`) &&
        response.request().method() === 'POST',
      { timeout: 60_000 },
    );
    await page.getByTestId('review-reject').click();
    // Reject-confirm modal: type a human reason, then confirm.
    const reasonText = '语气不符合品牌要求（SIM-04 reject journey）';
    await page.locator('div[role="dialog"] textarea').fill(reasonText);
    await page.getByTestId('confirm-reject-btn').click();
    const rejected = await rejectPending;
    expect(rejected.ok(), `reject HTTP ${rejected.status()}`).toBe(true);
    const rejectJson = (await rejected.json()) as { success?: boolean };
    expect(rejectJson.success).toBe(true);

    // The item left the pending aggregation and never reached the site.
    const after = await pendingReviewItems(request);
    expect(
      after.some((i) => Number(i.id) === itemId),
      `rejected item still pending: ${JSON.stringify(after)}`,
    ).toBe(false);
    expect(atsSiteB.receivedCallbacks.length).toBe(0);

    // The item is recorded as rejected in its job (reason persisted
    // server-side; asserted at route-test level too).
    const jobsRes = await apiGet(request, '/api/jobs');
    const jobsData = jobsRes.data as Record<string, unknown>;
    const jobs = ((jobsData.items ?? []) as Array<Record<string, unknown>>).filter(
      (j) => Number(j.relation_id ?? 0) === RELATION_ID_B,
    );
    expect(jobs.length).toBeGreaterThan(0);
    let rejectedItem: Record<string, unknown> | undefined;
    for (const job of jobs) {
      const itemsRes = await apiGet(request, `/api/jobs/${job.id}/items?limit=50`);
      const jobItems = ((itemsRes.data as Record<string, unknown>).items ??
        []) as Array<{ id?: number; status?: string }>;
      rejectedItem = jobItems.find((i) => Number(i.id) === itemId);
      if (rejectedItem) break;
    }
    expect(rejectedItem, `item ${itemId} missing from relation-B job items`).toBeTruthy();
    expect(String(rejectedItem!.status)).toBe('rejected');

    // ---- Log oracle: reject recorded, no writeback callback anywhere.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [{ event: 'review.item_rejected' }],
      'SIM-04 single-reject',
    );
    expect(
      window.filter((e) => e.event === 'discovery.callback_sent').length,
      'rejected item must not trigger a writeback callback',
    ).toBe(0);
    expectNoUnexpected(window, 'SIM-04 single-reject', []);
  });

  test('批量拒绝：待审清空、无回写、批量接口真实响应', async ({ page, request }) => {
    test.setTimeout(120_000);
    const mark = await markLogStart(request);

    const before = await pendingReviewItems(request);
    const relB = before.filter((i) => Number(i.relation_id ?? 0) === RELATION_ID_B);
    expect(relB.length, `expected remaining relation-B item: ${JSON.stringify(before)}`)
      .toBe(1);
    const itemRowId = Number(relB[0]!.wp_object_id);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /任务|Tasks/ }).first().click();
    // Anchored full-name match (job cards also embed "Pending Review").
    await page
      .getByRole('button', { name: /^(待审核|Pending( Review)?)$/ })
      .click();

    // The pending table's ID column shows the WP object id.
    const row = page.locator('tr').filter({ hasText: String(itemRowId) }).first();
    await expect(row).toBeVisible({ timeout: 30_000 });
    await row.locator('input[type="checkbox"]').check();
    await expect(page.getByTestId('batch-reject')).toBeEnabled();
    await page.getByTestId('batch-reject').click();

    const batchPending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/items/batch-reject') &&
        response.request().method() === 'POST',
      { timeout: 60_000 },
    );
    await page
      .locator('div[role="dialog"] textarea')
      .fill('批量拒绝：机器翻译质量不达标（SIM-04 batch journey）');
    await page.getByTestId('task-confirm-reject-btn').click();
    const batched = await batchPending;
    expect(batched.ok(), `batch-reject HTTP ${batched.status()}`).toBe(true);
    const batchJson = (await batched.json()) as { success?: boolean; data?: unknown };
    expect(batchJson.success).toBe(true);

    // Aggregation is empty for relation B and the site never saw a callback.
    const after = await pendingReviewItems(request);
    expect(
      after.some((i) => Number(i.relation_id ?? 0) === RELATION_ID_B),
      `relation-B items still pending after batch reject: ${JSON.stringify(after)}`,
    ).toBe(false);
    expect(atsSiteB.receivedCallbacks.length).toBe(0);

    // ---- Log oracle: batch reject recorded, engine wrote nothing back.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [{ event: 'review.batch_rejected' }],
      'SIM-04 batch-reject',
    );
    expect(
      window.filter((e) => e.event === 'discovery.callback_sent').length,
      'batch-rejected items must not trigger writeback callbacks',
    ).toBe(0);
    expectNoUnexpected(window, 'SIM-04 batch-reject', []);
  });
});
