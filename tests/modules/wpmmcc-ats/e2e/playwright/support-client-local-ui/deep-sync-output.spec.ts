/**
 * TST-05 output counterpart: no seeded pair and no swallowed form error.
 * Reuses the live deep journey's UI output leg with owned HMAC site oracles.
 * covers: success|failure|boundary
 */
import { test, expect } from '@playwright/test';
import { MockWpmmccSite, seedPosts } from '../simulation/lib/mock-wp-site';
import { runDeepSyncOutput } from '../client-ui-setup/sync-output';
import { CLIENT_BASE, apiDelete, apiPost, unbindSite } from '../simulation/lib/sim-client';

if (!process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL || ['8977', '8787'].includes(new URL(CLIENT_BASE).port)) {
  throw new Error('deep output contract requires the owned local-ui lane');
}

test('TST-05: deep output UI binding → pairing → saved pair → run → target fields', async ({ page, request }) => {
  test.setTimeout(180_000);
  const source = new MockWpmmccSite({
    siteUuid: 'deep-output-owned-source', siteName: 'Deep Output Source',
    routeSecret: 'deep-output-owned-route', wpClientToken: 'deep-output-owned-source-token',
    posts: [],
  });
  const target = new MockWpmmccSite({
    siteUuid: 'deep-output-owned-target', siteName: 'Deep Output Target',
    routeSecret: 'deep-output-owned-target-route', wpClientToken: 'deep-output-owned-target-token',
    posts: [],
  });
  const [post] = seedPosts(source, 1);
  let pairId = '';
  let outputFailed = false;
  let targetAssertions = 0;
  await source.start();
  await target.start();
  try {
    expect(target.receivedPackets).toHaveLength(0);
    const output = await runDeepSyncOutput(page, request, {
      source: { api_base_url: source.baseUrl, wp_client_token: source.wpClientToken, route_secret: source.routeSecret },
      target: { api_base_url: target.baseUrl, wp_client_token: target.wpClientToken, route_secret: target.routeSecret },
      name: 'TST-05 owned deep output',
      pairingCode: async (role) => (role === 'source' ? source : target).generatePairingCode(),
      assertTarget: async () => {
        targetAssertions += 1;
        const packet = target.receivedPackets.find((item) => (item.entity as Record<string, unknown>).guid === post.guid);
        if (!packet) return 0;
        const fields = (packet.entity as Record<string, unknown>).core_fields as Record<string, unknown>;
        expect(fields.post_title).toBe(post.title);
        expect(fields.post_content).toBe(post.content);
        expect(fields.post_excerpt).toBe(post.excerpt);
        return target.receivedPackets.indexOf(packet) + 1;
      },
    });
    pairId = output.pair_id;
    expect(output.target_id).toBe(1);
    expect(targetAssertions).toBeGreaterThan(0);
    expect(target.receivedPackets).toHaveLength(1);
    expect(source.hmacFailures).toEqual([]);
    expect(target.hmacFailures).toEqual([]);
  } catch (error) {
    outputFailed = true;
    throw error;
  } finally {
    const cleanup = await Promise.allSettled([
      ...(pairId ? [apiPost(request, '/api/sync-pairs/delete', { id: pairId })] : []),
      ...[source, target].flatMap((site) => [
        apiDelete(request, `/api/sync-pairs/credentials/${encodeURIComponent(site.baseUrl)}`),
        unbindSite(request, site.baseUrl),
      ]),
    ]);
    await Promise.all([source.stop(), target.stop()]);
    if (cleanup.some((result) => result.status === 'rejected') && !outputFailed) {
      console.log('owned deep cleanup rejected stages:', cleanup.flatMap((result, index) => {
        if (result.status !== 'rejected') return [];
        const message = String(result.reason?.message ?? '');
        const kind = /disposed|Test ended|Target closed/i.test(message) ? 'context_closed'
          : /timeout/i.test(message) ? 'timeout'
          : /ECONNRESET|socket hang up/i.test(message) ? 'connection_reset'
          : 'unclassified_transport';
        return [{ index, kind }];
      }));
      throw new Error('owned deep output cleanup failed');
    }
  }
});
