/**
 * SIM-11: 跨站同步 Diff 人工审核批准推送闭环旅程 (real wire journey)
 *
 * The sync-review feature (pair-level `review_before_push`) end to end —
 * the last piece of doc 22 G-04: the feature had API + UI + route tests
 * but zero e2e journey.
 *
 *  1. Two mock WPMMCC sites (source with one seeded post + a media ref,
 *     target empty) are bound, identity-verified, and paired through the
 *     real Peer Pairing modal (same recipe as SIM-02).
 *  2. The sync pair is created THROUGH THE UI with the
 *     `review_before_push` checkbox checked — the feature under test.
 *  3. "Sync Now" runs the real engine: digest → pull → (translate) — but
 *     the relay push is HELD. Wire evidence: the target received zero
 *     packets; the item lands in the SyncPairsTab review inbox.
 *  4. The reviewer opens the Diff modal, approves through the real UI.
 *     Wire evidence: the target received exactly one HMAC-verified relay
 *     packet; the inbox is empty; the review doc records `pushed`.
 */
import { test, expect } from '@playwright/test';
import { MockWpmmccSite } from './lib/mock-wp-site';
import {
  CLIENT_BASE,
  apiDelete,
  apiGet,
  apiPost,
  bindSite,
  listSyncPairs,
  unbindSite,
  verifySiteIdentity,
} from './lib/sim-client';
import {
  collectLogWindow,
  eventsNamed,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

test.describe('SIM-11: 跨站同步 Diff 人工审核批准推送旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const SOURCE_UUID = 'sim11-source-uuid-0001';
  const TARGET_UUID = 'sim11-target-uuid-0002';
  const PNG_BYTES = Buffer.from([
    0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d,
    0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
  ]);

  const source = new MockWpmmccSite({
    siteUuid: SOURCE_UUID,
    siteName: 'Sim11 Source Site',
    routeSecret: '*******************',
    wpClientToken: '*********************************',
    posts: [
      {
        guid: 'sim11-post-review',
        sourceId: 21,
        title: 'Review before push me',
        content:
          '<p>Body held for human review.</p><img src="{{MEDIA}}/wp-content/uploads/sim11-review.png" alt="review"/>',
        excerpt: 'Review excerpt',
      },
    ],
  });

  const target = new MockWpmmccSite({
    siteUuid: TARGET_UUID,
    siteName: 'Sim11 Target Site',
    routeSecret: '*******************',
    wpClientToken: '*********************************',
    posts: [],
  });

  test.beforeAll(async () => {
    await source.start();
    await target.start();
  });

  test.afterAll(async ({ request }) => {
    // Mirror SIM-02 cleanup so later specs never probe dead sites.
    const { pairs } = await listSyncPairs(request).catch(() => ({
      pairs: [] as Array<{ id: string }>,
    }));
    for (const pair of pairs) {
      await apiPost(request, '/api/sync-pairs/delete', { id: pair.id }).catch(() => {});
    }
    for (const site of [source, target]) {
      await apiDelete(
        request,
        `/api/sync-pairs/credentials/${encodeURIComponent(site.baseUrl)}`,
      ).catch(() => {});
      await unbindSite(request, site.baseUrl).catch(() => {});
    }
    await source.stop();
    await target.stop();
  });

  async function openSyncPairsTab(page: import('@playwright/test').Page) {
    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /任务|Tasks/ }).first().click();
    await page.getByRole('button', { name: /跨站同步|Sync Pairs/ }).click();
  }

  /** GET /api/sync-review pending list (pair-agnostic view). */
  async function syncReviewItems(request: import('@playwright/test').APIRequestContext) {
    const res = await apiGet(request, '/api/sync-review');
    expect(res.success, JSON.stringify(res.raw)).toBe(true);
    const data = res.data as Record<string, unknown>;
    return {
      total: Number(data.total ?? 0),
      items: (data.items ?? []) as Array<Record<string, unknown>>,
    };
  }

  test('双站绑定 + UI 配对（配对码握手）', async ({ page, request }) => {
    test.setTimeout(120_000);
    const mark = await markLogStart(request);

    for (const site of [source, target]) {
      const bind = await bindSite(request, {
        api_base_url: site.baseUrl,
        wp_client_token: site.wpClientToken,
        route_secret: site.routeSecret,
      });
      expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
      const verify = await verifySiteIdentity(request, site.baseUrl);
      expect(verify.success, JSON.stringify(verify.raw)).toBe(true);
      expect(String(verify.data.plugin_identity)).toBe('wpmmcc');
    }

    await openSyncPairsTab(page);
    await page.getByRole('button', { name: /配对管理|Peer Pairing/ }).click();

    const sourceCode = source.generatePairingCode();
    await page.locator('#pairing-domain').selectOption(source.baseUrl);
    await page.locator('#pairing-role').selectOption('source');
    await page.locator('#pairing-code').fill(sourceCode);
    await page.getByRole('button', { name: /立即配对|Pair Now/ }).click();
    await expect(page.getByText(/配对成功|Paired:/).first()).toBeVisible({ timeout: 20_000 });

    const targetCode = target.generatePairingCode();
    await page.locator('#pairing-domain').selectOption(target.baseUrl);
    await page.locator('#pairing-role').selectOption('target');
    await page.locator('#pairing-code').fill(targetCode);
    await page.getByRole('button', { name: /立即配对|Pair Now/ }).click();
    await expect(page.getByText(/配对成功|Paired:/).first()).toBeVisible({ timeout: 20_000 });

    expect(source.receivedHandshakes.length).toBe(1);
    expect(target.receivedHandshakes.length).toBe(1);
    await page.getByRole('button', { name: /关闭|Close/ }).first().click();

    // ---- Log oracle: both pairing handshakes stored peer credentials and
    // recorded pair events (once per site role).
    const window = await collectLogWindow(request, mark);
    expect(eventsNamed(window, 'credential.peer_stored').length).toBe(2);
    expect(eventsNamed(window, 'sync_pair.paired').length).toBe(2);
    expectNoUnexpected(window, 'SIM-11 pairing', []);
  });

  test('UI 建立同步对（勾选推送前人工审核）并立即同步：推送被扣留', async ({
    page,
    request,
  }) => {
    test.setTimeout(180_000);
    const mark = await markLogStart(request);

    await openSyncPairsTab(page);
    await page.getByRole('button', { name: /新建同步对|Create Sync Pair/ }).first().click();
    await page.locator('#sync-pair-name').fill('SIM-11 review-before-push pair');
    await page.locator('#sync-pair-source').selectOption(source.baseUrl);
    await page.locator('#sync-pair-target').selectOption(target.baseUrl);
    // THE feature under test: the pair holds every relay push for human
    // review until each item is approved in the Diff inbox.
    await page.getByTestId('sync-review-before-push').check();
    await page.getByRole('button', { name: /保存|Save/ }).last().click();
    await expect(
      page.getByText(/同步对保存成功|saved successfully/).first(),
    ).toBeVisible({ timeout: 20_000 });

    const { pairs } = await listSyncPairs(request);
    const pair = pairs.find(
      (p) => p.source_domain === source.baseUrl && p.target_domain === target.baseUrl,
    );
    expect(pair, `pair not created: ${JSON.stringify(pairs)}`).toBeTruthy();
    expect(Boolean((pair as unknown as Record<string, unknown>).review_before_push)).toBe(true);

    await page.getByRole('button', { name: /立即同步|Sync Now/ }).first().click();
    await expect(
      page.getByText(/同步已开始后台执行|Sync started in the background/).first(),
    ).toBeVisible({ timeout: 20_000 });

    // The engine runs in the background. In review mode the relay push is
    // HELD (the run reports pending_review_count, not synced_count), so
    // wait on the review inbox itself rather than last_sync_count.
    let review = { total: 0, items: [] as Array<Record<string, unknown>> };
    const deadline = Date.now() + 120_000;
    while (Date.now() < deadline) {
      review = await syncReviewItems(request);
      if (review.total >= 1) break;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    expect(review.total, `sync-review inbox never received the item`).toBe(1);

    const { pairs: afterRun } = await listSyncPairs(request);
    const after = afterRun.find(
      (p) => p.source_domain === source.baseUrl && p.target_domain === target.baseUrl,
    );
    expect(after?.last_error ?? '').toBeFalsy();

    // ---- Wire evidence: the engine pulled + translated, but the relay
    // push is HELD — the target received nothing yet.
    expect(target.receivedPackets.length).toBe(0);

    // The item landed in the sync-review inbox instead.
    const item = review.items[0]!;
    expect(String(item.canonical_uuid)).toBe('sim11-post-review');
    expect(String(item.pair_id)).toBe(pair!.id);
    expect(String(item.status)).toBe('pending_review');

    // ---- Log oracle: the engine ran the inbound chain to pull, then the
    // run FINISHED with the relay push HELD. FO-2 (Wave-1 finding): in
    // review-hold mode the run emits no reconcile_completed and NO audit
    // event when the item parks in the sync-review inbox (park is silent);
    // asserted as-is pending the batch fix.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'sync_pair.upserted' },
        { event: 'sync_engine.pair_started' },
        { event: 'sync_engine.digest_fetched' },
        { event: 'sync_engine.pull_batch_completed' },
        { event: 'sync_engine.pair_finished' },
      ],
      'SIM-11 review-hold',
    );
    expect(eventsNamed(window, 'sync_engine.push_ok').length).toBe(0);
    expectNoUnexpected(window, 'SIM-11 review-hold', []);
  });

  test('Diff 审核批准：真实 HMAC 推送到达目标站 + 收件箱清空', async ({
    page,
    request,
  }) => {
    test.setTimeout(120_000);
    const mark = await markLogStart(request);

    await openSyncPairsTab(page);

    // The review inbox card lists the held item; open the Diff modal.
    // (The inbox line shows the proposal title; the modal header shows the
    // canonical UUID.)
    const inbox = page.getByTestId('sync-review-inbox');
    await expect(inbox).toBeVisible({ timeout: 30_000 });
    await expect(inbox.getByText('Review before push me')).toBeVisible();
    await inbox.getByRole('button', { name: /审核|Review/ }).click();

    const modal = page.getByTestId('sync-review-modal');
    await expect(modal).toBeVisible({ timeout: 30_000 });
    await expect(modal.getByText('sim11-post-review')).toBeVisible();

    await modal.locator('#sync-review-proposed-title').fill('Reviewed title');
    await modal.locator('#sync-review-proposed-content').fill('<p>Reviewed content.</p>');
    await modal.locator('#sync-review-proposed-excerpt').fill('Reviewed excerpt');
    await page.route('**/api/sync-review/**', async (route) => {
      if (route.request().method() === 'PUT') {
        await route.fulfill({
          status: 500, contentType: 'application/json',
          body: JSON.stringify({ success: false, error: { code: 'SAVE_UNAVAILABLE', message: 'Review save unavailable' } }),
        });
      } else {
        await route.continue();
      }
    });
    await page.getByTestId('sync-review-approve').click();
    await expect(page.getByText('Review save unavailable')).toBeVisible();
    expect(target.receivedPackets).toHaveLength(0);
    expect((await syncReviewItems(request)).total).toBe(1);
    await expect(modal.locator('#sync-review-proposed-title')).toHaveValue('Reviewed title');
    await expect(page.getByTestId('sync-review-approve')).toBeEnabled();
    await page.unroute('**/api/sync-review/**');
    const savedPending = page.waitForResponse(
      (response) => response.url().includes('/api/sync-review/') && response.request().method() === 'PUT',
    );
    // Approve must save the edits first, then send the real signed packet.
    const approvePending = page.waitForResponse(
      (response) =>
        response.url().includes('/api/sync-review/') &&
        response.url().includes('/approve') &&
        response.request().method() === 'POST',
      { timeout: 60_000 },
    );
    await page.getByTestId('sync-review-approve').click();
    const saved = await savedPending;
    expect(saved.ok(), `edited save HTTP ${saved.status()}`).toBe(true);
    const savedJson = await saved.json();
    expect(savedJson.success).toBe(true);
    expect(savedJson.data.item.proposed_title).toBe('Reviewed title');
    expect(savedJson.data.item.proposed_content).toBe('<p>Reviewed content.</p>');
    expect(savedJson.data.item.proposed_excerpt).toBe('Reviewed excerpt');
    const approved = await approvePending;
    expect(approved.ok(), `approve HTTP ${approved.status()}`).toBe(true);
    const approveJson = (await approved.json()) as {
      success?: boolean;
      data?: { status?: string };
    };
    expect(approveJson.success).toBe(true);
    expect(String(approveJson.data?.status)).toBe('pushed');

    // ---- Wire evidence on the target: exactly one relay packet with the
    // source origin context, fully HMAC-verified.
    expect(target.receivedPackets.length).toBe(1);
    const packet = target.receivedPackets[0] as Record<string, unknown>;
    const entity = packet.entity as Record<string, unknown>;
    expect(String(entity.guid)).toBe('sim11-post-review');
    expect(entity.core_fields).toMatchObject({
      post_title: 'Reviewed title',
      post_content: '<p>Reviewed content.</p>',
      post_excerpt: 'Reviewed excerpt',
    });
    const origin = packet.origin_context as Record<string, unknown>;
    expect(String(origin.origin_site_uuid)).toBe(SOURCE_UUID);
    expect(target.hmacFailures).toEqual([]);

    // The inbox is empty again and the review doc records the push.
    const after = await syncReviewItems(request);
    expect(after.total).toBe(0);

    // ---- Log oracle: approve released the held packet onto the wire
    // (observed order: the push completes, then the route records the
    // sync_review.item_approved audit line).
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [{ event: 'sync_review.item_updated' }, { event: 'sync_engine.push_ok' }, { event: 'sync_review.item_approved' }],
      'SIM-11 approve-push',
    );
    expectNoUnexpected(window, 'SIM-11 approve-push', []);
  });
});
