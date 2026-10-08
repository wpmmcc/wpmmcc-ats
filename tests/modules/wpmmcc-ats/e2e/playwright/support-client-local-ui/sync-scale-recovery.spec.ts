/**
 * TST-03 / CLI-01 / J-06: R1 boundary, boundary+1, 2*boundary+1.
 * Owned Client HTTP, SQLite bindings/JSON sync state and HMAC sites, not deployed WP.
 * covers: success|failure|boundary
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { MockWpmmccSite, seedPosts } from '../simulation/lib/mock-wp-site';
import {
  CLIENT_BASE, apiDelete, apiPost, bindSite, createSyncPair, listSyncPairs,
  pairSite, runSyncPair, unbindSite, verifySiteIdentity,
} from '../simulation/lib/sim-client';
import { collectLogWindow, eventsNamed, markLogStart } from '../simulation/lib/sim-log-oracle';

const ownedBase = process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL;
const stateFile = process.env.WPTSALL_SYNC_STATE_FILE;
if (!ownedBase || CLIENT_BASE !== ownedBase || ['8977', '8787'].includes(new URL(ownedBase).port)
    || !stateFile || !path.resolve(stateFile).includes('/wptsall-client-local-ui-lane.')) {
  throw new Error('scale recovery requires the isolated owned local-ui lane and sync state');
}

for (const count of [100, 101, 201]) {
  test(`TST-03: ${count} posts, only ID 82 media fails, safe cursor and exact recovery`, async ({ request }) => {
    test.setTimeout(180_000);
    const source = new MockWpmmccSite({
      siteUuid: `scale-source-${count}`, siteName: `Scale Source ${count}`,
      routeSecret: `scale-route-${count}`, wpClientToken: `scale-owned-source-${count}`,
      posts: [],
    });
    const target = new MockWpmmccSite({
      siteUuid: `scale-target-${count}`, siteName: `Scale Target ${count}`,
      routeSecret: `scale-target-route-${count}`, wpClientToken: `scale-owned-target-${count}`,
      posts: [],
    });
    const seeds = seedPosts(source, count);
    let pairId = '';
    await source.start();
    await target.start();
    try {
      const mediaGuid = seeds[81].guid;
      source.seedMedia(mediaGuid, `scale-${count}-only-82.png`, Buffer.from('owned-id-82-media'));
      for (const [site, role] of [[source, 'source'], [target, 'target']] as const) {
        expect((await bindSite(request, {
          api_base_url: site.baseUrl, wp_client_token: site.wpClientToken, route_secret: site.routeSecret,
        })).success).toBe(true);
        expect((await verifySiteIdentity(request, site.baseUrl)).data.plugin_identity).toBe('wpmmcc');
        expect((await pairSite(request, {
          domain: site.baseUrl, pairing_code: site.generatePairingCode(), role,
        })).success).toBe(true);
      }
      const created = await createSyncPair(request, {
        name: `R1 scale ${count}`, source_domain: source.baseUrl, target_domain: target.baseUrl,
        sync_mode: 'sync_only', conflict_strategy: 'lww',
      });
      expect(created.success, JSON.stringify(created.raw)).toBe(true);
      const pair = (await listSyncPairs(request)).pairs.find((item) => item.name === `R1 scale ${count}`);
      expect(pair).toBeDefined();
      pairId = pair!.id;

      const run = async () => {
        const mark = await markLogStart(request);
        expect((await runSyncPair(request, pairId)).success).toBe(true);
        await expect.poll(async () => eventsNamed(await collectLogWindow(request, mark), 'sync_engine.pair_finished')
          .filter((event) => event.detail.pair_id === pairId).length, { timeout: 90_000 }).toBe(1);
      };
      target.injectFault('media-chunk', 1, { status: 503 });
      await run();
      expect(target.rejectedMediaChunks).toBe(1);
      expect(target.receivedPackets).toHaveLength(count - 1);
      expect(target.receivedChunks).toBe(0);
      const failed = (await listSyncPairs(request)).pairs.find((item) => item.id === pairId);
      expect(failed?.last_error ?? '').not.toBe('');
      const state = JSON.parse(fs.readFileSync(stateFile!, 'utf8')) as {
        pairs: Record<string, { last_seen_source_id: number; known: Record<string, unknown> }>;
      };
      expect(state.pairs[pairId].last_seen_source_id).toBe(81);
      expect(Object.keys(state.pairs[pairId].known)).toHaveLength(count - 1);
      expect(state.pairs[pairId].known[mediaGuid]).toBeUndefined();
      expect(source.digestRequests.every((digest) => digest.limit === 100 && digest.ids.length <= 100)).toBe(true);
      expect(source.digestRequests.flatMap((digest) => digest.ids)).toEqual(seeds.map((seed) => seed.sourceId));
      if (count > 100) expect(source.digestRequests.length).toBeGreaterThanOrEqual(Math.ceil(count / 100));

      await run();
      expect(target.receivedPackets).toHaveLength(count);
      expect(target.receivedChunks).toBe(1);
      const guids = target.receivedPackets.map((packet) => (packet.entity as Record<string, unknown>).guid);
      expect(new Set(guids).size).toBe(count);
      expect(guids.slice().sort()).toEqual(seeds.map((seed) => seed.guid).sort());
      const recovered = JSON.parse(fs.readFileSync(stateFile!, 'utf8')) as typeof state;
      expect(recovered.pairs[pairId].last_seen_source_id).toBe(count);
      const final = (await listSyncPairs(request)).pairs.find((item) => item.id === pairId);
      expect(final?.last_error ?? null).toBeNull();
      expect(final?.last_sync_count).toBe(count);
      expect(source.hmacFailures).toEqual([]);
      expect(target.hmacFailures).toEqual([]);
      await run();
      expect(target.receivedPackets).toHaveLength(count);
      expect(target.receivedChunks).toBe(1);
    } finally {
      if (pairId) expect((await apiPost(request, '/api/sync-pairs/delete', { id: pairId })).success).toBe(true);
      for (const site of [source, target]) {
        await apiDelete(request, `/api/sync-pairs/credentials/${encodeURIComponent(site.baseUrl)}`);
        await unbindSite(request, site.baseUrl);
      }
      await source.stop();
      await target.stop();
    }
  });
}
