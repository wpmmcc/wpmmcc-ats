/**
 * SIM-02: WPMMCC 纯后台双站无命令配对旅程 (real wire journey)
 *
 * Real webmaster journey against two mock WPMMCC sites and the lane-owned
 * client — every step is real HTTP/HMAC traffic or real UI interaction:
 *
 *  1. Both sites are bound and identity-verified as the WPMMCC plugin
 *     (signed /sync/ping handshake via POST /api/domain-tokens/test).
 *  2. Each site admin page issues a one-time pairing code (10-min TTL,
 *     single use) — the sim calls the site's generator directly.
 *  3. The webmaster opens the client WebUI → Tasks → Sync Pairs →
 *     Peer Pairing and pairs BOTH sites through the real modal (source
 *     role for the content source, target role for the relay target).
 *  4. The client performs the real /sync/handshake with each site; both
 *     sides derive the same HKDF-SHA256 shared secret; the sites record
 *     the client as an active peer.
 *  5. A sync pair is created through the UI and "Sync Now" triggers the
 *     real engine: digest → pull → media transfer → relay push, all
 *     HMAC-signed and verified by the mock target site.
 *  6. Wire evidence: the target site received the relayed packets with
 *     rewritten content URLs and the transferred media chunk; the second
 *     run is a no-op (cursor advanced, no duplicates).
 *
 * No WP-CLI, no database access, no page.route stubs.
 */
import { test, expect } from '@playwright/test';
import { MockWpmmccSite, derivePeerSharedSecret } from './lib/mock-wp-site';
import {
  CLIENT_BASE,
  apiDelete,
  apiPost,
  bindSite,
  listSyncPairs,
  runSyncPair,
  unbindSite,
  verifySiteIdentity,
  waitForPairSynced,
  type SyncPair,
} from './lib/sim-client';
import {
  collectLogWindow,
  eventsNamed,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

test.describe('SIM-02: WPMMCC 纯后台双站配对旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const SOURCE_UUID = 'sim02-source-uuid-0001';
  const TARGET_UUID = 'sim02-target-uuid-0002';
  const PNG_BYTES = Buffer.from([
    0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d,
    0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
  ]);

  const source = new MockWpmmccSite({
    siteUuid: SOURCE_UUID,
    siteName: 'Sim Source Site',
    routeSecret: 'sim02_secret_source',
    wpClientToken: 'sim02_tok_source_0123456789abcdef',
    posts: [
      {
        guid: 'sim02-post-alpha',
        sourceId: 11,
        title: 'Alpha: first synced post',
        content:
          '<p>Original body with media.</p><img src="{{MEDIA}}/wp-content/uploads/sim-alpha.png" alt="alpha"/>',
        excerpt: 'Alpha excerpt',
      },
      {
        guid: 'sim02-post-beta',
        sourceId: 12,
        title: 'Beta: second synced post',
        content: '<p>Beta body.</p>',
        excerpt: 'Beta excerpt',
      },
    ],
  });

  const target = new MockWpmmccSite({
    siteUuid: TARGET_UUID,
    siteName: 'Sim Target Site',
    routeSecret: 'sim02_secret_target',
    wpClientToken: 'sim02_tok_target_0123456789abcdef',
    posts: [],
  });

  test.beforeAll(async () => {
    await source.start();
    await target.start();
    source.seedMedia('sim02-post-alpha', 'sim-alpha.png', PNG_BYTES);
  });

  test.afterAll(async ({ request }) => {
    // Full cleanup so later specs never probe dead sites or re-run lanes:
    // delete the pair, forget both peer credentials, unbind both sites.
    const { pairs } = await listSyncPairs(request).catch(() => ({
      pairs: [] as SyncPair[],
      credentials: [],
      clientOriginUuid: '',
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

  test('站点绑定与身份验证：两站均识别为 WPMMCC 插件', async ({ request }) => {
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
      expect(site.pingCount).toBeGreaterThanOrEqual(1);
    }
  });

  test('UI 配对旅程：配对码握手 + 凭据落盘 + 方向镜像', async ({ page, request }) => {
    test.setTimeout(120_000);
    const sourceCode = source.generatePairingCode();
    const targetCode = target.generatePairingCode();

    await openSyncPairsTab(page);

    await page.getByRole('button', { name: /配对管理|Peer Pairing/ }).click();

    // No credentials stored yet: the modal's paired-sites list is empty.
    await expect(
      page.getByText(/暂无已配对站点|No paired sites yet/).first(),
    ).toBeVisible({ timeout: 20_000 });

    // ---- Pair the SOURCE site (client pulls from it).
    await page.locator('#pairing-domain').selectOption(source.baseUrl);
    await page.locator('#pairing-role').selectOption('source');
    await page.locator('#pairing-code').fill(sourceCode);
    await page.getByRole('button', { name: /立即配对|Pair Now/ }).click();
    await expect(page.getByText(/配对成功|Paired:/).first()).toBeVisible({
      timeout: 20_000,
    });

    // ---- Pair the TARGET site (client pushes to it).
    await page.locator('#pairing-domain').selectOption(target.baseUrl);
    await page.locator('#pairing-role').selectOption('target');
    await page.locator('#pairing-code').fill(targetCode);
    await page.getByRole('button', { name: /立即配对|Pair Now/ }).click();
    await expect(page.getByText(/配对成功|Paired:/).first()).toBeVisible({
      timeout: 20_000,
    });

    // Both credentials now show in the modal's paired-sites list, with
    // unpair affordances — the UI reflects the stored credentials. (The
    // domain <option> elements in the role selector stay hidden, so the
    // assertion targets the mono-font credential rows and the Unpair
    // buttons specifically.)
    await expect(
      page.locator('p.font-mono', { hasText: source.baseUrl }).first(),
    ).toBeVisible({ timeout: 20_000 });
    await expect(
      page.locator('p.font-mono', { hasText: target.baseUrl }).first(),
    ).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('button', { name: /取消配对|Unpair/ })).toHaveCount(2);
    await page.getByRole('button', { name: /关闭|Close/ }).first().click();

    // Handshakes really happened on the wire, with mirrored directions.
    expect(source.receivedHandshakes.length).toBe(1);
    expect(target.receivedHandshakes.length).toBe(1);
    expect(String(source.receivedHandshakes[0].requested_direction)).toBe('pull_only');
    expect(String(target.receivedHandshakes[0].requested_direction)).toBe('push_only');

    // Credentials are persisted client-side (public view via the API).
    const { credentials, clientOriginUuid } = await listSyncPairs(request);
    expect(clientOriginUuid.length).toBeGreaterThan(0);
    const domains = credentials.map((c) => String(c.domain));
    expect(domains).toContain(source.baseUrl);
    expect(domains).toContain(target.baseUrl);
    const sourceCred = credentials.find((c) => String(c.domain) === source.baseUrl)!;
    expect(String(sourceCred.negotiated_direction)).toBe('push_only');
    expect(String(sourceCred.paired_as)).toBe('source');
    const targetCred = credentials.find((c) => String(c.domain) === target.baseUrl)!;
    expect(String(targetCred.negotiated_direction)).toBe('pull_only');
    expect(String(targetCred.paired_as)).toBe('target');
  });

  test('配对码一次性：重放已被消费的配对码被站点拒绝', async ({ request }) => {
    const usedCode = source.generatePairingCode();
    const handshake = {
      origin_url: 'http://replay.probe',
      origin_name: 'Replay Probe',
      requested_direction: 'pull_only',
      source_lang: 'en_US',
      target_lang: 'zh_CN',
      sync_mode: 'sync_only',
    };
    const first = await request.post(`${source.baseUrl}/wp-json/wpmmcc/v1/sync/handshake`, {
      data: { pairing_code: usedCode, origin_uuid: 'sim02-replay-uuid-1', ...handshake },
    });
    expect(first.ok()).toBe(true);
    const second = await request.post(`${source.baseUrl}/wp-json/wpmmcc/v1/sync/handshake`, {
      data: { pairing_code: usedCode, origin_uuid: 'sim02-replay-uuid-2', ...handshake },
    });
    expect(second.status()).toBe(400);
    const body = (await second.json()) as { code?: string };
    expect(body.code).toBe('wpmmcc_invalid_pairing_code');
  });

  test('UI 建立同步对并立即同步：真实 digest→pull→media→relay-push 全链路', async ({
    page,
    request,
  }) => {
    test.setTimeout(180_000);
    const mark = await markLogStart(request);
    await openSyncPairsTab(page);

    await page.getByRole('button', { name: /新建同步对|Create Sync Pair/ }).first().click();
    await page.locator('#sync-pair-name').fill('SIM-02 journey pair');
    await page.locator('#sync-pair-source').selectOption(source.baseUrl);
    await page.locator('#sync-pair-target').selectOption(target.baseUrl);
    await page.getByRole('button', { name: /保存|Save/ }).last().click();
    await expect(page.getByText(/同步对保存成功|saved successfully/).first()).toBeVisible({
      timeout: 20_000,
    });

    const { pairs } = await listSyncPairs(request);
    const pair = pairs.find(
      (p) => p.source_domain === source.baseUrl && p.target_domain === target.baseUrl,
    );
    expect(pair, `pair not created: ${JSON.stringify(pairs)}`).toBeTruthy();

    // "Sync Now" → real engine run (fire-and-forget; poll for completion).
    await page.getByRole('button', { name: /立即同步|Sync Now/ }).first().click();
    await expect(
      page.getByText(/同步已开始后台执行|Sync started in the background/).first(),
    ).toBeVisible({ timeout: 20_000 });

    const synced = await waitForPairSynced(request, pair!.id, 2, 120_000);
    expect(Number(synced.last_sync_count ?? 0)).toBe(2);
    expect(synced.last_error ?? '').toBeFalsy();
    const firstRunAt = Number(synced.last_sync_at ?? 0);

    // ---- Wire evidence on the target site.
    expect(target.receivedPackets.length).toBe(2);
    const guids = target.receivedPackets.map(
      (p) => String((p.entity as Record<string, unknown>).guid),
    );
    expect(guids).toContain('sim02-post-alpha');
    expect(guids).toContain('sim02-post-beta');

    // Origin context preserved: the relay keeps the SOURCE site's origin.
    const alpha = target.receivedPackets.find(
      (p) => String((p.entity as Record<string, unknown>).guid) === 'sim02-post-alpha',
    )!;
    const origin = alpha.origin_context as Record<string, unknown>;
    expect(String(origin.origin_site_uuid)).toBe(SOURCE_UUID);
    expect(Number(origin.hop_count)).toBe(2);

    // Content URLs were rewritten source → target domain.
    const core = alpha.entity as Record<string, unknown>;
    const fields = core.core_fields as Record<string, unknown>;
    const content = String(fields.post_content);
    expect(content).not.toContain(source.baseUrl);
    expect(content).toContain(target.baseUrl);

    // Media was transferred to the target before the packet was pushed.
    expect(target.receivedChunks).toBe(1);

    // Every authenticated call passed full HMAC verification.
    expect(target.hmacFailures).toEqual([]);
    expect(source.hmacFailures).toEqual([]);

    // ---- Second run is a no-op: cursor advanced, nothing re-shipped.
    const runAgain = await runSyncPair(request, pair!.id);
    expect(runAgain.success, JSON.stringify(runAgain.raw)).toBe(true);
    let second: SyncPair | undefined;
    const deadline = Date.now() + 60_000;
    while (Date.now() < deadline) {
      const { pairs: after } = await listSyncPairs(request);
      second = after.find((p) => p.id === pair!.id);
      if (second && Number(second.last_sync_at ?? 0) > firstRunAt) break;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    expect(second, 'second run never completed').toBeTruthy();
    expect(Number(second!.last_sync_count ?? 0)).toBe(2);
    expect(second!.last_error ?? '').toBeFalsy();
    expect(target.receivedPackets.length).toBe(2);
    expect(target.receivedChunks).toBe(1);

    // ---- Log oracle: the engine's wire-level chain, in order (observed
    // wire order: media assembles between pull and push; reconcile is the
    // post-push summary), and a clean window (no warn/error in journey).
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'sync_pair.upserted' },
        { event: 'sync_engine.pair_started' },
        { event: 'sync_engine.digest_fetched' },
        { event: 'sync_engine.pull_batch_completed' },
        { event: 'sync_engine.media_assembled' },
        { event: 'sync_engine.push_ok' },
        { event: 'sync_engine.reconcile_completed' },
      ],
      'SIM-02 full-chain',
    );
    // Second run redigested and reconciled but pushed nothing new — cursor
    // advanced; the pair chain stayed silent afterwards.
    expectNoUnexpected(window, 'SIM-02 full-chain', []);
  });

  test('HKDF 派生与 PHP 插件参考向量一致（wire 兼容自证）', () => {
    // Mirrors the PHP hash_hkdf construction used by the plugin handshake.
    const secret = derivePeerSharedSecret(
      '9f1a2b3c4d5e6f708192a3b4c5d6e7f8',
      '11111111-1111-1111-1111-111111111111',
      '22222222-2222-2222-2222-222222222222',
    );
    expect(secret.toString('hex')).toBe(
      '7cd6844ad9f65531518ede47c92422ec411ff6b5cb8bf8a20b8c1ecd8b046b25',
    );
  });
});
