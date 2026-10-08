/**
 * TST-05 deployed output: owned WP sites, UI bind/pair/save/run, target REST.
 * Explicit fixture support leg, no new default live scope.
 * covers: success|failure|boundary
 */
import { test, expect } from '@playwright/test';
import { runDeepSyncOutput } from '../client-ui-setup/sync-output';
import { CLIENT_BASE, apiPost, apiDelete, unbindSite } from '../simulation/lib/sim-client';
import { owned, wp, type OwnedSite } from './helpers';

test('TST-05: deployed owned WP deep pair produces exact target title/content/excerpt', async ({ page, request }) => {
  test.setTimeout(180_000);
  if (!process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL
      || ['8977', '8787'].includes(new URL(CLIENT_BASE).port)) throw new Error('owned Client/WP required');
  const [source, target] = owned.sites;
  const marker = `deep-owned-${owned.owner}`;
  const postId = Number(wp(source, `$id=wp_insert_post(array('post_type'=>'post','post_status'=>'publish',
    'post_title'=>${JSON.stringify(marker)},'post_name'=>${JSON.stringify(marker)},
    'post_content'=>'<p>Owned deep body</p>','post_excerpt'=>'Owned deep excerpt'),true);
    if(is_wp_error($id)){exit(1);} echo $id;`));
  expect(postId).toBeGreaterThan(0);
  const fixture = (site: OwnedSite) => ({
    api_base_url: site.base_url, wp_client_token: site.wp_client_token, route_secret: site.route_secret,
  });
  let pairId = '';
  let targetId = 0;
  try {
    const before = await request.get(`${target.base_url}/wp-json/wp/v2/posts?slug=${marker}`);
    expect(before.status()).toBe(200);
    expect(await before.json()).toEqual([]);
    const output = await runDeepSyncOutput(page, request, {
      source: fixture(source), target: fixture(target), name: marker,
      pairingCode: async (role) => wp(role === 'source' ? source : target,
        "$code=bin2hex(random_bytes(16));set_transient('wpmmcc_active_pairing_code',$code,600);echo $code;"),
      assertTarget: async () => {
        const response = await request.get(`${target.base_url}/wp-json/wp/v2/posts?slug=${marker}`);
        expect(response.status()).toBe(200);
        const posts = await response.json() as Array<{ id: number; title: { rendered: string }; content: { rendered: string }; excerpt: { rendered: string } }>;
        if (!posts.length) return 0;
        expect(posts).toHaveLength(1);
        expect(posts[0].title.rendered).toBe(marker);
        expect(posts[0].content.rendered).toContain('Owned deep body');
        expect(posts[0].excerpt.rendered).toContain('Owned deep excerpt');
        targetId = posts[0].id;
        return targetId;
      },
    });
    pairId = output.pair_id;
    expect(output.target_id).toBe(targetId);
    expect(targetId).toBeGreaterThan(0);
    expect(Number(wp(target, `global $wpdb; echo $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name=%s",${JSON.stringify(marker)}));`))).toBe(1);
  } finally {
    // The entire WP prefixes belong to the wrapper and are dropped after this
    // explicit leg, including journal/mapping rows, even after a test failure.
    await Promise.allSettled([
      ...(pairId ? [apiPost(request, '/api/sync-pairs/delete', { id: pairId })] : []),
      ...[source, target].flatMap((site) => [
        apiDelete(request, `/api/sync-pairs/credentials/${encodeURIComponent(site.base_url)}`),
        unbindSite(request, site.base_url),
      ]),
    ]);
  }
});
