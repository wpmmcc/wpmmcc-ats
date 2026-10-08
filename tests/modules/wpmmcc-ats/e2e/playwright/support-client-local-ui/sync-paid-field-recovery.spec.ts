/**
 * CLI-13 / TST-01 / J-07: real UI pair → SQLite runtime loader →
 * owned HTTP vendor title success/content 500 → retry only missing fields.
 *
 * catalog: WEBUI-MOD-sync-engine-discoverer-rs
 * catalog: WEBUI-MOD-db-sync-inflight-rs
 * catalog: WEBUI-API-PREFIX-api-sync-pairs
 * covers: success|failure|boundary
 * No real provider, shared client, website, or deployed WordPress is used.
 */
import { test, expect } from '@playwright/test';
import { MockWpmmccSite } from '../simulation/lib/mock-wp-site';
import { MockTranslateProvider, translatorTemplate } from '../simulation/lib/mock-provider';
import {
  CLIENT_BASE, apiDelete, apiPost, bindSite, createLocalComponent, listSyncPairs,
  pairSite, runSyncPair, saveComponentAuth, unbindSite, verifySiteIdentity,
} from '../simulation/lib/sim-client';
import { collectLogWindow, eventsNamed, markLogStart } from '../simulation/lib/sim-log-oracle';

const ownedBase = process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL;
if (!ownedBase || CLIENT_BASE !== ownedBase || ['8977', '8787'].includes(new URL(ownedBase).port)) {
  throw new Error('CLI-13 browser test requires the isolated owned local-ui lane');
}

test('CLI-13: UI 同步并翻译在正文失败后复用已保存标题，不翻译 meta/taxonomy', async ({ page, request }) => {
  test.setTimeout(150_000);
  const componentId = 'cli13-owned-paid-translator';
  const source = new MockWpmmccSite({
    siteUuid: 'cli13-source-0001', siteName: 'CLI13 Source',
    routeSecret: 'cli13-owned-route', wpClientToken: 'cli13-owned-source-fixture-token',
    posts: [{
      guid: 'cli13-paid-post', sourceId: 1,
      title: 'CLI13 title', content: '<p>CLI13 body</p>', excerpt: 'CLI13 excerpt',
      taxonomies: { category: [{ slug: 'keep-category', name: 'Keep category' }] },
      metaFields: { fixture_meta: 'Keep meta untranslated' },
    }],
  });
  const target = new MockWpmmccSite({
    siteUuid: 'cli13-target-0002', siteName: 'CLI13 Target',
    routeSecret: 'cli13-owned-target-route', wpClientToken: 'cli13-owned-target-fixture-token',
    posts: [],
  });
  const vendor = new MockTranslateProvider();
  let pairId = '';
  await source.start();
  await target.start();
  await vendor.start();
  try {
    for (const [site, role] of [[source, 'source'], [target, 'target']] as const) {
      const bound = await bindSite(request, {
        api_base_url: site.baseUrl, wp_client_token: site.wpClientToken, route_secret: site.routeSecret,
      });
      expect(bound.success, JSON.stringify(bound.raw)).toBe(true);
      const verified = await verifySiteIdentity(request, site.baseUrl);
      expect(verified.success, JSON.stringify(verified.raw)).toBe(true);
      expect(verified.data.plugin_identity).toBe('wpmmcc');
      const paired = await pairSite(request, {
        domain: site.baseUrl, pairing_code: site.generatePairingCode(), role,
      });
      expect(paired.success, JSON.stringify(paired.raw)).toBe(true);
    }
    const component = await createLocalComponent(request, {
      id: componentId, name: 'CLI13 owned paid translator', kind: 'text',
      templateJson: translatorTemplate(componentId, vendor.translateUrl),
    });
    expect(component.success, JSON.stringify(component.raw)).toBe(true);
    const auth = await saveComponentAuth(request, componentId, { api_key: 'cli13-owned-mock-key' });
    expect(auth.success, JSON.stringify(auth.raw)).toBe(true);

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /任务|Tasks/ }).first().click();
    await page.getByRole('button', { name: /跨站同步|Sync Pairs/ }).click();
    await page.getByRole('button', { name: /新建同步对|Create Sync Pair/ }).first().click();
    await page.locator('#sync-pair-name').fill('CLI13 owned paid field recovery');
    await page.locator('#sync-pair-source').selectOption(source.baseUrl);
    await page.locator('#sync-pair-target').selectOption(target.baseUrl);
    await page.locator('#sync-pair-mode').selectOption('sync_and_translate');
    await page.locator('#sync-pair-source-lang').fill('en_US');
    await page.locator('#sync-pair-target-lang').fill('zh_CN');
    await expect(page.locator('#sync-pair-translate-component option[value="' + componentId + '"]')).toHaveCount(1);
    await page.locator('#sync-pair-translate-component').selectOption(componentId);
    await page.getByRole('button', { name: /保存|Save/ }).last().click();
    await expect(page.getByText(/同步对保存成功|saved successfully/).first()).toBeVisible();
    const pair = (await listSyncPairs(request)).pairs.find((item) => item.name === 'CLI13 owned paid field recovery');
    expect(pair?.sync_mode).toBe('sync_and_translate');
    expect(pair?.translate_component_id).toBe(componentId);
    expect(pair?.source_lang).toBe('en_US');
    expect(pair?.target_lang).toBe('zh_CN');
    pairId = pair!.id;
    const mark = await markLogStart(request);
    vendor.injectFault(1, { status: 500, skip: 1 });
    await page.getByRole('button', { name: /立即同步|Sync Now/ }).first().click();
    await expect.poll(async () => {
      const current = (await listSyncPairs(request)).pairs.find((item) => item.id === pairId);
      return current?.last_error ?? '';
    }, { timeout: 30_000 }).toContain('翻译正文失败');
    expect(vendor.hits.map((hit) => hit.text)).toEqual(['CLI13 title', '<p>CLI13 body</p>']);
    expect(vendor.rejectedHits).toBe(1);
    expect(target.receivedPackets).toHaveLength(0);

    const retry = await runSyncPair(request, pairId);
    expect(retry.success, JSON.stringify(retry.raw)).toBe(true);
    await expect.poll(() => target.receivedPackets.length, { timeout: 30_000 }).toBe(1);
    await expect.poll(async () => {
      const current = (await listSyncPairs(request)).pairs.find((item) => item.id === pairId);
      return { count: current?.last_sync_count, error: current?.last_error ?? null };
    }).toEqual({ count: 1, error: null });
    expect(vendor.hits.map((hit) => hit.text)).toEqual([
      'CLI13 title', '<p>CLI13 body</p>', '<p>CLI13 body</p>', 'CLI13 excerpt',
    ]);
    expect(vendor.hits.every((hit) => hit.source_lang === 'en_US' && hit.target_lang === 'zh_CN')).toBe(true);
    const packet = target.receivedPackets[0];
    const entity = packet.entity as Record<string, unknown>;
    const fields = entity.core_fields as Record<string, unknown>;
    expect(fields.post_title).toBe('【zh_CN】CLI13 title【/zh_CN】');
    expect(fields.post_content).toBe('<p>【zh_CN】CLI13 body【/zh_CN】</p>');
    expect(fields.post_excerpt).toBe('【zh_CN】CLI13 excerpt【/zh_CN】');
    expect(entity.taxonomies).toEqual(source.posts[0].taxonomies);
    expect(entity.meta_fields).toEqual(source.posts[0].metaFields);
    expect(target.hmacFailures).toEqual([]);
    expect(packet.target_lang).toBe('zh_CN');

    const noopMark = await markLogStart(request);
    const noop = await runSyncPair(request, pairId);
    expect(noop.success, JSON.stringify(noop.raw)).toBe(true);
    await expect.poll(async () => {
      const log = await collectLogWindow(request, noopMark);
      return eventsNamed(log, 'sync_engine.pair_finished').filter((event) => event.detail.pair_id === pairId).length;
    }).toBe(1);
    expect(vendor.hits).toHaveLength(4);
    expect(target.receivedPackets).toHaveLength(1);
    const log = await collectLogWindow(request, mark);
    expect(eventsNamed(log, 'sync_engine.inflight_resumed').filter((event) => event.detail.pair_id === pairId)).toHaveLength(1);
  } finally {
    if (pairId) await apiPost(request, '/api/sync-pairs/delete', { id: pairId });
    for (const site of [source, target]) {
      await apiDelete(request, `/api/sync-pairs/credentials/${encodeURIComponent(site.baseUrl)}`);
      await unbindSite(request, site.baseUrl);
    }
    await vendor.stop();
    await source.stop();
    await target.stop();
  }
});
