/**
 * SIM-13: 多站点组合拓扑（mesh）真实旅程
 *
 * The combined-topology journey doc 23/24 could not cover: ONE client
 * managing every plugin family and multiple pairs at once.
 *
 *   1. Four sites bound on one client: 1 ATS + 3 WPMMCC (A/B/C).
 *   2. Site B is paired in BOTH roles (target of A→B, source of B→C) —
 *      the multi-pair business case no other spec exercises.
 *   3. Chain pairs A→B and B→C are created and run CONCURRENTLY
 *      (Promise.all of two runSyncPair calls → two engine runs racing in
 *      the background, sharing the sync-state document).
 *   4. Dual translation providers live simultaneously: provider-1 serves
 *      the ATS relation's discovery task, provider-2's component is
 *      quick-tested and probed through /api/providers/test — and must
 *      NOT receive discovery traffic (binding isolation).
 *   5. Isolation: the ATS lane never touches WPMMCC data-plane paths and
 *      the pair engine never touches ATS paths, with all four sites live.
 *
 * Known-issue allowances (Wave-1 findings, see the remediation report):
 *   FL-2  MockAtsSite lacks the real plugin's /client/content/claim route
 *         → claim 404 retries to the 180-iteration cap (mock fidelity +
 *         missing 404 circuit-break).
 *   FL-3  Every iteration re-attempts the wpmmcc_ats lane against each
 *         WPMMCC-bound site → identity_mismatch storm (no per-run dedup).
 *   FL-5  sync_engine.* events carry no pair id → assertions here are
 *         COUNT-based, not per-pair (recorded as an observability gap).
 *   FL-7  In the full lane, discovery tasks from earlier specs point at
 *         sites their afterAll already STOPPED; each dead-domain relation
 *         burns run-once iterations (no fail-fast/circuit for dead
 *         domains) and can starve the 180-iteration budget before new
 *         relations are processed. The journey cleans them up through
 *         the real task-update API first (what a webmaster would do).
 *
 * Mock boundary note: the WPMMCC mock records received packets but does
 * not ingest them into its own content list, so B→C carries only B's
 * native posts. Whether relayed content re-propagates (loop protection)
 * is a real-plugin question for the lab lane, not the mock lane.
 */
import { test, expect } from '@playwright/test';
import { MockAtsSite, MockWpmmccSite } from './lib/mock-wp-site';
import { MockTranslateProvider, translatorTemplate } from './lib/mock-provider';
import {
  CLIENT_BASE,
  apiDelete,
  apiPost,
  apiPut,
  bindSite,
  bootstrapDiscoveryTasks,
  createLocalComponent,
  createSyncPair,
  enableDiscoveryTask,
  findDoneItemForRelation,
  getReviewMode,
  listDiscoveryTasks,
  listSyncPairs,
  pairSite,
  runSyncPair,
  runWorkerOnce,
  saveComponentAuth,
  setReviewMode,
  unbindSite,
  verifySiteIdentity,
  waitForAtsCallbacks,
  waitForPairSynced,
} from './lib/sim-client';
import {
  collectLogWindow,
  eventsNamed,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

test.describe('SIM-13: 多站点组合拓扑 mesh 旅程', () => {
  test.describe.configure({ mode: 'serial' });

  // FL-9 note: relation ids are CLIENT-global in the discovery-task table.
  // SIM-09 already seeds relation 9901 for its own mock site; reusing it
  // here made bootstrap create a SECOND task row for 9901 (the two rows
  // differ by domain) and a relation-only lookup picked SIM-09's dead
  // domain. Use a lane-unique relation id AND match by domain.
  const RELATION_ID = 13901;
  const COMP_PRIMARY = 'sim13-primary-translator';
  const COMP_SECONDARY = 'sim13-secondary-translator';

  const atsSite = new MockAtsSite({
    siteName: 'Sim13 Mesh ATS',
    routeSecret: '*****************',
    wpClientToken: '*******************************',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [
      {
        objectId: 99011,
        postType: 'post',
        title: 'Mesh auto translate title',
        content: '<p>Mesh auto translate body.</p>',
        excerpt: 'Mesh excerpt',
      },
    ],
  });

  const wpA = new MockWpmmccSite({
    siteUuid: 'sim13-wpa-uuid-0001',
    siteName: 'Sim13 Site A',
    routeSecret: '****************',
    wpClientToken: '******************************',
    posts: [
      {
        sourceId: 1,
        guid: 'sim13-a-post-1',
        postType: 'post',
        title: 'A native post one',
        content: '<p>A native body one.</p>',
      },
      {
        sourceId: 2,
        guid: 'sim13-a-post-2',
        postType: 'post',
        title: 'A native post two',
        content: '<p>A native body two.</p>',
      },
    ],
  });

  const wpB = new MockWpmmccSite({
    siteUuid: 'sim13-wpb-uuid-0002',
    siteName: 'Sim13 Site B',
    routeSecret: '****************',
    wpClientToken: '******************************',
    posts: [
      {
        sourceId: 1,
        guid: 'sim13-b-native',
        postType: 'post',
        title: 'B native post',
        content: '<p>B native body.</p>',
      },
    ],
  });

  const wpC = new MockWpmmccSite({
    siteUuid: 'sim13-wpc-uuid-0003',
    siteName: 'Sim13 Site C',
    routeSecret: '****************',
    wpClientToken: '******************************',
    posts: [],
  });

  const providerPrimary = new MockTranslateProvider();
  const providerSecondary = new MockTranslateProvider();

  test.beforeAll(async () => {
    await atsSite.start();
    await wpA.start();
    await wpB.start();
    await wpC.start();
    await providerPrimary.start();
    await providerSecondary.start();
  });

  test.afterAll(async ({ request }) => {
    const { pairs } = await listSyncPairs(request).catch(() => ({
      pairs: [] as Array<{ id: string }>,
    }));
    for (const pair of pairs) {
      await apiPost(request, '/api/sync-pairs/delete', { id: pair.id }).catch(() => {});
    }
    for (const site of [atsSite, wpA, wpB, wpC]) {
      await apiDelete(
        request,
        `/api/sync-pairs/credentials/${encodeURIComponent(site.baseUrl)}`,
      ).catch(() => {});
      await unbindSite(request, site.baseUrl).catch(() => {});
    }
    await setReviewMode(request, false).catch(() => {});
    await atsSite.stop();
    await wpA.stop();
    await wpB.stop();
    await wpC.stop();
    await providerPrimary.stop();
    await providerSecondary.stop();
  });

  test('四站绑定 + B 双角色三向配对 + 双 provider 组件在场', async ({ request }) => {
    test.setTimeout(180_000);
    const mark = await markLogStart(request);

    // Bind + verify all four sites on ONE client.
    for (const [site, identity] of [
      [atsSite, 'wpmmcc_ats'],
      [wpA, 'wpmmcc'],
      [wpB, 'wpmmcc'],
      [wpC, 'wpmmcc'],
    ] as const) {
      const bind = await bindSite(request, {
        api_base_url: site.baseUrl,
        wp_client_token: site.wpClientToken,
        route_secret: site.routeSecret,
      });
      expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
      const verify = await verifySiteIdentity(request, site.baseUrl);
      expect(verify.success, JSON.stringify(verify.raw)).toBe(true);
      expect(String(verify.data.plugin_identity)).toBe(identity);
    }

    // Three-way pairing with B in BOTH roles (target for A→B, source for
    // B→C). Observational: the second pairing of B must not break the
    // first (multi-pair business case).
    const pairings: Array<{ site: MockWpmmccSite; role: 'source' | 'target' }> = [
      { site: wpA, role: 'source' },
      { site: wpB, role: 'target' },
      { site: wpB, role: 'source' },
      { site: wpC, role: 'target' },
    ];
    for (const { site, role } of pairings) {
      const res = await pairSite(request, {
        domain: site.baseUrl,
        pairing_code: site.generatePairingCode(),
        role,
      });
      expect(res.success, `pairing ${site.siteName} as ${role}: ${JSON.stringify(res.raw)}`)
        .toBe(true);
    }

    // Dual provider components, both live with real auth.
    for (const [compId, provider] of [
      [COMP_PRIMARY, providerPrimary],
      [COMP_SECONDARY, providerSecondary],
    ] as const) {
      const comp = await createLocalComponent(request, {
        id: compId,
        name: `SIM-13 ${compId}`,
        kind: 'text',
        templateJson: translatorTemplate(compId, provider.translateUrl),
      });
      expect(comp.success, JSON.stringify(comp.raw)).toBe(true);
      expect((await saveComponentAuth(request, compId, { api_key: compId })).success).toBe(
        true,
      );
    }

    // Provider-2 gets a REAL quick-test through the component surface
    // (exercises the quick-test path with the secondary provider). The
    // handler takes auth inline (auth_values) — like the provider wizard.
    const quick = await apiPost(request, `/api/components/local/${COMP_SECONDARY}/quick-test`, {
      text: 'mesh quick test',
      source_lang: 'en_US',
      target_lang: 'zh_CN',
      auth_values: { api_key: 'sim13-secondary-quick-key' },
    });
    expect(quick.success, JSON.stringify(quick.raw)).toBe(true);
    expect(providerSecondary.hits.length).toBeGreaterThanOrEqual(1);

    // Structured probe through /api/providers/test for the secondary
    // vendor. Offline/unroutable vendor → the product contract is
    // success:false with a classified error.code + data.healthy:false
    // (same verdict semantics asserted in SIM-12).
    const probe = await apiPost(request, '/api/providers/test', {
      vendor_id: 'sim13-secondary-probe',
      auth_values: { api_key: '********************************' },
    });
    const probeBody = probe.raw as {
      error?: { code?: string };
      data?: { healthy?: boolean };
    };
    expect(probe.success).toBe(false);
    expect(probeBody.error?.code).toBeTruthy();
    expect(
      ['AUTH_REJECTED', 'NETWORK_FAILED', 'PROVIDER_URL_BLOCKED', 'PROVIDER_TEST_FAILED'],
    ).toContain(probeBody.error!.code);
    expect(Boolean(probeBody.data?.healthy)).toBe(false);

    // ---- Log oracle: pairing + components audited in order.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'credential.peer_stored' },
        { event: 'sync_pair.paired' },
        { event: 'component.created' },
        { event: 'component.quick_tested' },
      ],
      'SIM-13 four-site pairing',
    );
    // B paired in both roles → 4 pairing handshakes total.
    expect(eventsNamed(window, 'sync_pair.paired').length).toBe(4);
    expect(eventsNamed(window, 'credential.peer_stored').length).toBe(4);
    // providers.tested warn (offline probe) is the designed outcome.
    expectNoUnexpected(window, 'SIM-13 four-site pairing', ['providers.tested']);
  });

  test('链式同步对并发运行：A→B 与 B→C 同轮引擎 + 线缆证据', async ({ request }) => {
    test.setTimeout(240_000);
    const mark = await markLogStart(request);

    const p1 = await createSyncPair(request, {
      name: 'SIM-13 chain A→B',
      source_domain: wpA.baseUrl,
      target_domain: wpB.baseUrl,
      sync_mode: 'sync_only',
    });
    expect(p1.success, JSON.stringify(p1.raw)).toBe(true);
    const p2 = await createSyncPair(request, {
      name: 'SIM-13 chain B→C',
      source_domain: wpB.baseUrl,
      target_domain: wpC.baseUrl,
      sync_mode: 'sync_only',
    });
    expect(p2.success, JSON.stringify(p2.raw)).toBe(true);

    const { pairs } = await listSyncPairs(request);
    const pair1 = pairs.find(
      (p) => p.source_domain === wpA.baseUrl && p.target_domain === wpB.baseUrl,
    );
    const pair2 = pairs.find(
      (p) => p.source_domain === wpB.baseUrl && p.target_domain === wpC.baseUrl,
    );
    expect(pair1, `pair A→B missing: ${JSON.stringify(pairs)}`).toBeTruthy();
    expect(pair2, `pair B→C missing: ${JSON.stringify(pairs)}`).toBeTruthy();

    // CONCURRENT engine runs: both pairs race in the background.
    const [run1, run2] = await Promise.all([
      runSyncPair(request, pair1!.id),
      runSyncPair(request, pair2!.id),
    ]);
    expect(run1.success, JSON.stringify(run1.raw)).toBe(true);
    expect(run2.success, JSON.stringify(run2.raw)).toBe(true);

    const synced1 = await waitForPairSynced(request, pair1!.id, 2, 180_000);
    expect(Number(synced1.last_sync_count ?? 0)).toBe(2);
    expect(synced1.last_error ?? '').toBeFalsy();
    const synced2 = await waitForPairSynced(request, pair2!.id, 1, 180_000);
    expect(Number(synced2.last_sync_count ?? 0)).toBe(1);
    expect(synced2.last_error ?? '').toBeFalsy();

    // ---- Wire evidence: B received A's two native posts, HMAC-clean.
    expect(wpB.receivedPackets.length).toBe(2);
    const guidsAtB = wpB.receivedPackets.map(
      (p) => String((p.entity as Record<string, unknown>).guid),
    );
    expect(guidsAtB).toContain('sim13-a-post-1');
    expect(guidsAtB).toContain('sim13-a-post-2');
    expect(wpB.hmacFailures).toEqual([]);

    // C received B's native post (mock does not ingest relayed packets,
    // so only B-native content flows on — see the header note).
    expect(wpC.receivedPackets.length).toBe(1);
    const packetAtC = wpC.receivedPackets[0] as Record<string, unknown>;
    expect(String((packetAtC.entity as Record<string, unknown>).guid)).toBe('sim13-b-native');
    expect(wpC.hmacFailures).toEqual([]);

    // A is source-only: it must never receive pair traffic.
    expect(wpA.receivedPackets.length).toBe(0);
    // ATS site never sees pair data-plane paths.
    for (const path of atsSite.seenPaths) {
      expect(path.includes('/sync/')).toBe(false);
    }

    // ---- Log oracle: two concurrent engine chains. FL-5: sync_engine.*
    // events carry no pair id, so these are COUNT-based assertions.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'sync_pair.upserted' },
        { event: 'sync_engine.pair_started' },
        { event: 'sync_engine.digest_fetched' },
        { event: 'sync_engine.pull_batch_completed' },
        { event: 'sync_engine.push_ok' },
        { event: 'sync_engine.reconcile_completed' },
        { event: 'sync_engine.pair_finished' },
      ],
      'SIM-13 concurrent chain',
    );
    expect(eventsNamed(window, 'sync_engine.pair_started').length).toBe(2);
    expect(eventsNamed(window, 'sync_engine.digest_fetched').length).toBe(2);
    expect(eventsNamed(window, 'sync_engine.pull_batch_completed').length).toBe(2);
    expect(eventsNamed(window, 'sync_engine.reconcile_completed').length).toBe(2);
    expect(eventsNamed(window, 'sync_engine.pair_finished').length).toBe(2);
    // 2 pushes to B + 1 push to C.
    expect(eventsNamed(window, 'sync_engine.push_ok').length).toBe(3);
    expectNoUnexpected(window, 'SIM-13 concurrent chain', []);
  });

  test('同客户端 ATS 自动翻译：双 provider 在场 + 三 WPMMCC 零串扰', async ({
    request,
  }) => {
    test.setTimeout(240_000);
    const mark = await markLogStart(request);

    // FL-7 cleanup (real webmaster action): disable discovery tasks left
    // by earlier specs whose mock sites are now stopped — dead-domain
    // relations would burn the run-once iteration budget before this
    // relation is ever reached.
    const stale = await listDiscoveryTasks(request);
    for (const t of stale) {
      if (Number(t.relation_id ?? 0) === RELATION_ID) continue;
      const off = await apiPut(request, `/api/discovery-tasks/${t.id}`, {
        enabled: false,
      });
      expect(off.success, `disable stale task ${t.id}: ${JSON.stringify(off.raw)}`).toBe(true);
    }

    await setReviewMode(request, false);
    expect(await getReviewMode(request)).toBe(false);
    // FL-8 fixed (Wave-2): POST /api/worker/config now syncs the stored
    // `workflow_policy` default_mode whenever the legacy `review_mode` flag
    // is written, and GET reports the effective mode — the legacy toggle
    // is authoritative again. This journey guards that fix: a plain
    // setReviewMode(false) must actually park nothing even after SIM-10's
    // Settings-UI save persisted a policy JSON earlier in the lane.

    const boot = await bootstrapDiscoveryTasks(request);
    expect(boot.success, JSON.stringify(boot.raw)).toBe(true);
    const tasks = await listDiscoveryTasks(request);
    // Match by relation AND the live site's domain (FL-9: relation ids are
    // client-global; a relation-only match can pick a dead domain's task).
    const task = tasks.find(
      (t) =>
        Number(t.relation_id ?? 0) === RELATION_ID &&
        String(t.domain ?? '').includes(atsSite.baseUrl.split('//')[1]!),
    );
    expect(task, `discovery task missing: ${JSON.stringify(tasks)}`).toBeTruthy();
    const enable = await enableDiscoveryTask(request, task!.id, COMP_PRIMARY);
    expect(enable.success, JSON.stringify(enable.raw)).toBe(true);

    const wpPathsBefore = [wpA, wpB, wpC].map((s) => [...s.seenPaths]);
    await runWorkerOnce(request);
    await waitForAtsCallbacks(() => atsSite.receivedCallbacks.length, 1, 120_000);

    // Provider-1 served the translation; provider-2 got NOTHING (binding
    // isolation between the two live components).
    expect(providerPrimary.hits.length).toBeGreaterThanOrEqual(1);
    expect(providerSecondary.hits.filter((h) => !String(h.text ?? '').includes('mesh quick')).length)
      .toBe(0);

    // ATS writeback arrived with machine text.
    expect(atsSite.receivedCallbacks.length).toBeGreaterThanOrEqual(1);
    const callback = atsSite.receivedCallbacks[atsSite.receivedCallbacks.length - 1];
    expect(Number(callback.payload.relation_id)).toBe(RELATION_ID);
    const fields = (callback.payload.translated_fields ?? {}) as Record<string, string>;
    expect(Object.values(fields).filter((v) => v.includes('【zh_CN】')).length).toBeGreaterThan(0);

    // WPMMCC sites saw no ATS data-plane traffic during the run.
    for (const [site, before] of [
      [wpA, wpPathsBefore[0]],
      [wpB, wpPathsBefore[1]],
      [wpC, wpPathsBefore[2]],
    ] as const) {
      const fresh = site.seenPaths.slice(before.length);
      for (const path of fresh) {
        expect(path.includes('translation-callback')).toBe(false);
        expect(path.includes('content-changes')).toBe(false);
      }
    }

    const done = await findDoneItemForRelation(request, RELATION_ID);
    if ('doneItems' in done) {
      expect(done.doneItems).toBeGreaterThan(0);
    } else {
      expect(String(done.status)).toBe('done');
    }

    // ---- Log oracle: auto chain, product-real order. Sequence note
    // (Wave-2): `discovery.item_translated` is the terminal record
    // (translated AND delivered) and fires AFTER `discovery.callback_sent`;
    // the pre-Wave-2 reverse order only passed via the re-offer storm, and
    // FL-2b holds make each row execute exactly once now. FL-3 is fixed:
    // wpmmcc-bound domains are pre-filtered out of the ATS lane at task
    // generation (info worker.domain_lane_excluded, once per domain +
    // identity), so the identity_mismatch storm against the THREE wpmmcc
    // sites is gone and needs no allowance. FL-2a landed: MockAtsSite
    // implements the real plugin's claim contract, so the claim succeeds
    // and the radar runs with NO allowances.
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'ops.discovery_task_updated' },
        { event: 'job.created' },
        { event: 'discovery.callback_sent' },
        { event: 'discovery.item_translated' },
        { event: 'job.finalized' },
      ],
      'SIM-13 mesh auto-translate',
    );
    expect(
      eventsNamed(window, 'sync_engine.push_ok').length,
      'pair engine must stay silent during the ATS run',
    ).toBe(0);
    expectNoUnexpected(window, 'SIM-13 mesh auto-translate', []);
  });
});
