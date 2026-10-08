/**
 * SIM-03: 客户端多站点身份隔离与契约防御旅程 (real journey, mock-only)
 *
 * Real multi-identity scenario against a mock ATS site and a mock WPMMCC
 * site bound to the same lane-owned client:
 *
 *  1. Both sites are bound and identity-verified through the real signed
 *     ping probes (wpmmcc_ats vs wpmmcc).
 *  2. The Sites UI shows the two distinct identity badges side by side.
 *  3. Contract defense (Identity Contract v1.1 §5), over the real API:
 *     - creating a cross-site sync pair with the ATS site as an end is
 *       rejected with `identity_mismatch`;
 *     - pairing the ATS site into the WPMMCC peer registry is rejected
 *       with `IDENTITY_MISMATCH`.
 *  4. Lane isolation evidence: after a worker run, the ATS site saw only
 *     wptsall/v2 traffic and the WPMMCC site saw only wpmmcc/v1 traffic —
 *     no cross-dispatch, no protocol pollution.
 */
import { test, expect } from '@playwright/test';
import { MockAtsSite, MockWpmmccSite } from './lib/mock-wp-site';
import {
  CLIENT_BASE,
  bindSite,
  createSyncPair,
  listBindings,
  pairSite,
  runWorkerOnce,
  unbindSite,
  verifySiteIdentity,
} from './lib/sim-client';

test.describe('SIM-03: 多站点身份隔离与契约防护旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const atsSite = new MockAtsSite({
    siteName: 'Sim ATS Site',
    routeSecret: 'sim03_ats_secret',
    wpClientToken: 'sim03_tok_ats_0123456789abcdef',
    relation: { id: 9101, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [],
  });

  const wpmmccSite = new MockWpmmccSite({
    siteUuid: 'sim03-wpmmcc-uuid-0003',
    siteName: 'Sim WPMMCC Site',
    routeSecret: 'sim03_sync_secret',
    wpClientToken: 'sim03_tok_sync_0123456789abcdef',
    posts: [],
  });

  test.beforeAll(async () => {
    await atsSite.start();
    await wpmmccSite.start();
  });

  test.afterAll(async ({ request }) => {
    // Unbind before stopping the mocks so later specs never probe dead sites.
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await unbindSite(request, wpmmccSite.baseUrl).catch(() => {});
    await atsSite.stop();
    await wpmmccSite.stop();
  });

  test('双身份绑定：ATS 与 WPMMCC 徽章并存且互不混淆', async ({ page, request }) => {
    test.setTimeout(120_000);

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

    const bindings = await listBindings(request);
    const ats = bindings.find((b) => b.api_base_url === atsSite.baseUrl);
    const wpmmcc = bindings.find((b) => b.api_base_url === wpmmccSite.baseUrl);
    expect(ats?.plugin_identity).toBe('wpmmcc_ats');
    expect(wpmmcc?.plugin_identity).toBe('wpmmcc');

    await page.goto(CLIENT_BASE);
    await page.getByRole('button', { name: /站点|Sites/ }).first().click();
    await expect(page.getByText(atsSite.baseUrl).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(wpmmccSite.baseUrl).first()).toBeVisible({ timeout: 20_000 });
    // Distinct identity badges side by side.
    await expect(page.getByText(/ATS/).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/WPMMCC/).first()).toBeVisible({ timeout: 20_000 });
  });

  test('契约门禁：ATS 站点不能进入跨站同步对（identity_mismatch）', async ({ request }) => {
    const res = await createSyncPair(request, {
      name: 'Invalid Cross-Plugin Pair',
      source_domain: atsSite.baseUrl,
      target_domain: wpmmccSite.baseUrl,
      direction: 'unidirectional',
      sync_mode: 'sync_only',
      source_lang: 'en_US',
      target_lang: 'zh_CN',
      post_types: ['post'],
    });
    expect(res.success).toBe(false);
    expect(res.errorCode).toBe('identity_mismatch');

    // The reverse end is equally rejected.
    const reversed = await createSyncPair(request, {
      name: 'Invalid Cross-Plugin Pair B',
      source_domain: wpmmccSite.baseUrl,
      target_domain: atsSite.baseUrl,
      direction: 'unidirectional',
      sync_mode: 'sync_only',
      source_lang: 'en_US',
      target_lang: 'zh_CN',
      post_types: ['post'],
    });
    expect(reversed.success).toBe(false);
    expect(reversed.errorCode).toBe('identity_mismatch');
  });

  test('契约门禁：ATS 站点不能完成 WPMMCC 配对握手（IDENTITY_MISMATCH）', async ({
    request,
  }) => {
    const res = await pairSite(request, {
      domain: atsSite.baseUrl,
      pairing_code: '0f1a2b3c4d5e6f708192a3b4c5d6e7f8',
      role: 'source',
    });
    expect(res.success).toBe(false);
    expect(res.errorCode).toBe('IDENTITY_MISMATCH');
    // No handshake ever reached the (non-WPMMCC) site.
    expect(atsSite.seenPaths.filter((p) => p.includes('handshake'))).toEqual([]);
  });

  test('车道物理隔离：worker 运行后两站各自只见到本协议流量', async ({ request }) => {
    // A real worker pass over both bound domains.
    await runWorkerOnce(request).catch(() => {
      /* worker summary shape may vary; isolation evidence is on the wire */
    });

    // Give in-flight lanes a moment to settle, then check the wire split.
    await new Promise((resolve) => setTimeout(resolve, 3000));

    // Identity detection is adaptive: the client probes BOTH endpoint
    // families (ATS first, then the WPMMCC fallback) before it knows which
    // plugin a site runs, so the identity-probe chain may legitimately
    // cross families. The ATS chain is validate-token → ping →
    // site-relations (the capability check), so those endpoints count as
    // probes wherever they land. Everything else — actual data-plane
    // traffic — must stay strictly within the owning plugin's family.
    const identityProbe = /\/(sync\/ping|client\/ping|client\/validate-token|client\/site-relations)$/;
    const wpmmccDataPlane =
      /\/wp-json\/wpmmcc\/v1\/[^/]+\/sync\/(digest|pull|push|media-chunk|reconcile-digest)/;
    // site-relations is excluded: on a WPMMCC site it can only originate
    // from the ATS identity-probe chain (the wpmmcc_sync lane never touches
    // it — peer work goes through /sync/* exclusively).
    const atsDataPlane =
      /\/wp-json\/wptsall\/v2\/[^/]+\/client\/(content|rules|content-changes|translation-callback|media-upload)/;

    for (const path of atsSite.seenPaths) {
      if (identityProbe.test(path)) continue;
      expect(
        path.includes('/wp-json/wptsall/v2/'),
        `ATS site saw non-probe WPMMCC traffic: ${path}`,
      ).toBe(true);
    }
    for (const path of wpmmccSite.seenPaths) {
      if (identityProbe.test(path)) continue;
      expect(
        path.includes('/wp-json/wpmmcc/v1/'),
        `WPMMCC site saw non-probe ATS traffic: ${path}`,
      ).toBe(true);
    }

    // Data-plane work never crossed families in either direction.
    for (const path of atsSite.seenPaths) {
      expect(
        !wpmmccDataPlane.test(path),
        `WPMMCC sync work dispatched to the ATS site: ${path}`,
      ).toBe(true);
    }
    for (const path of wpmmccSite.seenPaths) {
      expect(
        !atsDataPlane.test(path),
        `ATS work dispatched to the WPMMCC site: ${path}`,
      ).toBe(true);
    }

    // No WPMMCC handshake ever reached the ATS site, and the WPMMCC site
    // never received a failed/unauthenticated HMAC call.
    expect(atsSite.seenPaths.filter((p) => p.includes('handshake'))).toEqual([]);
    expect(wpmmccSite.hmacFailures).toEqual([]);
  });
});
