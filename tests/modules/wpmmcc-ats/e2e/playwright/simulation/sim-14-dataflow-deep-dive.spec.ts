/**
 * SIM-14: 跨站同步数据流深水区 (real wire journey — doc 24 §四.3)
 *
 * The sync engine's deep data-flow semantics that no earlier spec drove
 * end to end, all asserted with wire evidence + the log oracle:
 *
 *  1. Change-detection fidelity: a mutated source post re-relays with
 *     FIELD-LEVEL equality (title/content/excerpt compared exactly
 *     against the source truth), and a no-change run re-pushes NOTHING
 *     (fingerprint stability → skipped, zero wire traffic).
 *  2. Deletion tombstones — both kinds the engine implements:
 *     (a) source digest reports post_status=trash → 'trash' tombstone;
 *     (b) hard source delete → reconcile rotation reports 'missing' →
 *         'delete' tombstone; the entity leaves the known-state map.
 *  3. Media large-file chunking end to end: a 2.5 MiB asset ships as 3
 *     HMAC-signed 1 MiB chunks under the REAL plugin completion contract
 *     (completed only on the final chunk), assembled sha256 preserved,
 *     and the relayed content rewritten to the target attachment URL.
 *  4. Fault-injection self-healing: a transient digest 500 and a push
 *     401 (HMAC-style rejection) fail their runs with visible audit
 *     events; the NEXT clean run converges with zero duplicates.
 *
 * Mock levers added for this spec (see mock-wp-site.ts): mutatePost /
 * trashPost / deletePost / injectFault + the real media-chunk assembly
 * contract (mediaAssemblies evidence).
 */
import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { MockWpmmccSite } from './lib/mock-wp-site';
import {
  apiDelete,
  apiPost,
  bindSite,
  createSyncPair,
  listSyncPairs,
  pairSite,
  runSyncPair,
  unbindSite,
  verifySiteIdentity,
  waitForPairSynced,
} from './lib/sim-client';
import {
  collectLogWindow,
  eventsNamed,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
  type LogEvent,
  type LogMark,
} from './lib/sim-log-oracle';

const FIDELITY_GUID = 'sim14-post-fidelity';
const TOMBSTONE_GUID = 'sim14-post-tombstone';
const RESILIENCE_GUID = 'sim14-post-resilience';
const MEDIA_GUID = 'sim14-post-media';
/** 2.5 MiB → 3 chunks at the engine's 1 MiB MEDIA_CHUNK_BYTES. */
const MEDIA_BYTES = Buffer.concat([
  Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  Buffer.alloc(2.5 * 1024 * 1024 - 8, 7),
]);
const MEDIA_SHA256 = createHash('sha256').update(MEDIA_BYTES).digest('hex');

const MUTATED = {
  title: 'Sim14 mutated title (r1)',
  content: '<p>Mutated body for field-level fidelity.</p>',
  excerpt: 'Mutated excerpt (r1)',
};

test.describe('SIM-14: 跨站同步数据流深水区旅程', () => {
  test.describe.configure({ mode: 'serial' });

  const source = new MockWpmmccSite({
    siteUuid: 'sim14-source-uuid-0001',
    siteName: 'Sim14 Source Site',
    routeSecret: '*******************',
    wpClientToken: '*********************************',
    posts: [
      {
        guid: FIDELITY_GUID,
        sourceId: 10,
        title: 'Sim14 fidelity post v0',
        content: '<p>Initial body v0.</p>',
        excerpt: 'Initial excerpt v0',
      },
      {
        guid: TOMBSTONE_GUID,
        sourceId: 20,
        title: 'Sim14 tombstone post v0',
        content: '<p>Tombstone journey body.</p>',
        excerpt: 'Tombstone excerpt',
      },
      {
        guid: RESILIENCE_GUID,
        sourceId: 30,
        title: 'Sim14 resilience post v0',
        content: '<p>Resilience journey body.</p>',
        excerpt: 'Resilience excerpt',
      },
    ],
  });

  const target = new MockWpmmccSite({
    siteUuid: 'sim14-target-uuid-0002',
    siteName: 'Sim14 Target Site',
    routeSecret: '*******************',
    wpClientToken: '*********************************',
    posts: [],
  });

  let pairId = '';

  test.beforeAll(async () => {
    await source.start();
    await target.start();
  });

  test.afterAll(async ({ request }) => {
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

  /** pair-attributed events (FL-5 made pair_id a first-class detail field). */
  function forPair(events: LogEvent[], name: string, id: string): LogEvent[] {
    return eventsNamed(events, name).filter((e) => String(e.detail?.pair_id) === id);
  }

  /**
   * Wait until the log shows `minCount` pair-attributed events of any of
   * `names` for this pair (the run-finished signal for zero-synced and
   * fault runs, where last_sync_count does not move).
   */
  async function waitForPairEvents(
    request: import('@playwright/test').APIRequestContext,
    mark: LogMark,
    names: string[],
    minCount: number,
    timeoutMs: number,
  ): Promise<LogEvent[]> {
    const deadline = Date.now() + timeoutMs;
    let window: LogEvent[] = [];
    while (Date.now() < deadline) {
      window = await collectLogWindow(request, mark);
      const hits = window.filter(
        (e) => names.includes(e.event) && String(e.detail?.pair_id) === pairId,
      );
      if (hits.length >= minCount) return window;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    throw new Error(
      `SIM-14: timed out waiting for ${minCount} × [${names.join('|')}] for pair ${pairId};` +
        ` last window tail:\n${window.slice(-8).map((e) => `${e.level} ${e.event}`).join('\n')}`,
    );
  }

  async function getPair(request: import('@playwright/test').APIRequestContext) {
    const { pairs } = await listSyncPairs(request);
    const pair = pairs.find(
      (p) => p.source_domain === source.baseUrl && p.target_domain === target.baseUrl,
    );
    expect(pair, `pair missing: ${JSON.stringify(pairs)}`).toBeTruthy();
    return pair!;
  }

  test('绑定配对 + 首轮全量同步（3 条目）+ pair 归属审计', async ({ request }) => {
    test.setTimeout(180_000);
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

    // Pair both sites through the real pairing-code handshake.
    for (const [site, role] of [
      [source, 'source'],
      [target, 'target'],
    ] as const) {
      const res = await pairSite(request, {
        domain: site.baseUrl,
        pairing_code: site.generatePairingCode(),
        role,
      });
      expect(res.success, JSON.stringify(res.raw)).toBe(true);
    }

    const created = await createSyncPair(request, {
      name: 'SIM-14 dataflow pair',
      source_domain: source.baseUrl,
      target_domain: target.baseUrl,
      sync_mode: 'sync_only',
    });
    expect(created.success, JSON.stringify(created.raw)).toBe(true);
    pairId = (await getPair(request)).id;

    const run = await runSyncPair(request, pairId);
    expect(run.success, JSON.stringify(run.raw)).toBe(true);

    const synced = await waitForPairSynced(request, pairId, 3, 180_000);
    expect(Number(synced.last_sync_count ?? 0)).toBe(3);
    expect(synced.last_error ?? '').toBeFalsy();

    // ---- Wire evidence: all three posts landed, HMAC-clean.
    expect(target.receivedPackets.length).toBe(3);
    expect(target.hmacFailures).toEqual([]);

    // ---- Log oracle: FL-5 pair_id attribution makes every engine event
    // attributable to THIS pair (no more count-only assertions).
    const window = await collectLogWindow(request, mark);
    expectEventSequence(
      window,
      [
        { event: 'credential.peer_stored' },
        { event: 'sync_pair.paired' },
        { event: 'sync_pair.upserted' },
        { event: 'sync_engine.pair_started' },
        { event: 'sync_engine.digest_fetched' },
        { event: 'sync_engine.pull_batch_completed' },
        { event: 'sync_engine.push_ok' },
        { event: 'sync_engine.reconcile_completed' },
        { event: 'sync_engine.pair_finished' },
      ],
      'SIM-14 first run',
    );
    expect(forPair(window, 'sync_engine.push_ok', pairId).length).toBe(3);
    expect(forPair(window, 'sync_engine.pair_finished', pairId).length).toBe(1);
    const finished = forPair(window, 'sync_engine.pair_finished', pairId)[0]!;
    expect(Number(finished.detail?.synced)).toBe(3);
    expect(Number(finished.detail?.skipped)).toBe(0);
    expectNoUnexpected(window, 'SIM-14 first run', []);
  });

  test('字段级保真再同步：source_newer 重推 + 逐字段相等 + 无变更零重推', async ({ request }) => {
    test.setTimeout(180_000);

    // ---- Phase A: mutate the source post. The digest is a pure keyset
    // cursor (real plugin contract), so the mutation is INVISIBLE to it —
    // the reconcile rotation's source_newer verdict is the update path.
    // The re-pulled packet must carry the mutated fields verbatim.
    const markA = await markLogStart(request);
    source.mutatePost(FIDELITY_GUID, MUTATED);

    const runA = await runSyncPair(request, pairId);
    expect(runA.success, JSON.stringify(runA.raw)).toBe(true);
    await waitForPairSynced(request, pairId, 4, 180_000);

    // Wire: exactly one new packet, and its entity fields are EXACTLY the
    // mutated source values (field-for-field, no translator in sync_only).
    expect(target.receivedPackets.length).toBe(4);
    const relayed = target.receivedPackets
      .filter(
        (p) => String((p.entity as Record<string, unknown>).guid) === FIDELITY_GUID,
      )
      .at(-1) as Record<string, unknown>;
    const entity = relayed.entity as Record<string, unknown>;
    const fields = entity.core_fields as Record<string, string>;
    expect(String(fields.post_title)).toBe(MUTATED.title);
    expect(String(fields.post_content)).toBe(MUTATED.content);
    expect(String(fields.post_excerpt)).toBe(MUTATED.excerpt);
    expect(target.hmacFailures).toEqual([]);

    const windowA = await collectLogWindow(request, markA);
    // The update path: digest scans nothing new, the rotation reports the
    // drift, the entity re-pulls and re-ships, the run converges.
    expectEventSequence(
      windowA,
      [
        { event: 'sync_engine.pair_started' },
        { event: 'sync_engine.digest_fetched' },
        { event: 'sync_engine.reconcile_completed' },
        { event: 'sync_engine.pull_batch_completed' },
        { event: 'sync_engine.push_ok' },
        { event: 'sync_engine.pair_finished' },
      ],
      'SIM-14 mutation run',
    );
    expect(forPair(windowA, 'sync_engine.push_ok', pairId).length).toBe(1);
    const finishedA = forPair(windowA, 'sync_engine.pair_finished', pairId)[0]!;
    expect(Number(finishedA.detail?.synced)).toBe(1);
    expect(Number(finishedA.detail?.scanned)).toBe(0);
    expectNoUnexpected(windowA, 'SIM-14 mutation run', []);

    // ---- Phase B: NO further change → the next run re-pushes NOTHING
    // (the rotation reports in_sync; the digest sees nothing new).
    const markB = await markLogStart(request);
    const before = target.receivedPackets.length;

    const runB = await runSyncPair(request, pairId);
    expect(runB.success, JSON.stringify(runB.raw)).toBe(true);
    const windowB = await waitForPairEvents(
      request,
      markB,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );

    // Zero wire traffic and zero push events for this pair.
    expect(target.receivedPackets.length).toBe(before);
    expect(forPair(windowB, 'sync_engine.push_ok', pairId).length).toBe(0);
    const finishedB = forPair(windowB, 'sync_engine.pair_finished', pairId)[0]!;
    expect(Number(finishedB.detail?.synced)).toBe(0);
    expect(Number(finishedB.detail?.scanned)).toBe(0);
    // The digest still scanned the source (keyset cursor, not a skip).
    expect(forPair(windowB, 'sync_engine.digest_fetched', pairId).length).toBe(1);
    expectNoUnexpected(windowB, 'SIM-14 no-change run', []);
  });

  test('删除墓碑双路径：trash 状态传播 + 源站硬删除 delete 墓碑', async ({ request }) => {
    test.setTimeout(180_000);

    // ---- Phase A: source trash of an ALREADY-SYNCED post. The keyset
    // digest cannot re-report it, so the trash propagates via the
    // source_newer rotation: the re-pulled packet carries entity.status
    // 'trash' verbatim (the target learns the post is trashed).
    const markA = await markLogStart(request);
    source.trashPost(TOMBSTONE_GUID);

    const runA = await runSyncPair(request, pairId);
    expect(runA.success, JSON.stringify(runA.raw)).toBe(true);
    const windowA = await waitForPairEvents(
      request,
      markA,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );

    const trashPacket = target.receivedPackets
      .filter(
        (p) => String((p.entity as Record<string, unknown>).guid) === TOMBSTONE_GUID,
      )
      .at(-1) as Record<string, unknown>;
    expect(
      String((trashPacket.entity as Record<string, unknown>).status),
    ).toBe('trash');
    const finishedA = forPair(windowA, 'sync_engine.pair_finished', pairId)[0]!;
    expect(Number(finishedA.detail?.synced)).toBe(1);
    expectNoUnexpected(windowA, 'SIM-14 trash propagation', []);

    // ---- Phase B: HARD delete at the source → the post vanishes from the
    // digest AND the reconcile-known set → reconcile rotation reports it
    // 'missing' → the engine relays a 'delete' tombstone and drops the
    // entity from known state.
    const markB = await markLogStart(request);
    source.deletePost(TOMBSTONE_GUID);

    const runB = await runSyncPair(request, pairId);
    expect(runB.success, JSON.stringify(runB.raw)).toBe(true);
    const windowB = await waitForPairEvents(
      request,
      markB,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );

    const deletePacket = target.receivedPackets.at(-1) as Record<string, unknown>;
    expect(String(deletePacket.action)).toBe('delete');
    expect(String((deletePacket.entity as Record<string, unknown>).guid)).toBe(
      TOMBSTONE_GUID,
    );
    const finishedB = forPair(windowB, 'sync_engine.pair_finished', pairId)[0]!;
    expect(Number(finishedB.detail?.deleted)).toBe(1);
    // The rotation asked the source about the still-known entity, then the
    // delete tombstone shipped.
    expectEventSequence(
      windowB,
      [
        { event: 'sync_engine.reconcile_completed' },
        { event: 'sync_engine.push_ok' },
        { event: 'sync_engine.pair_finished' },
      ],
      'SIM-14 delete tombstone',
    );
    expectNoUnexpected(windowB, 'SIM-14 delete tombstone', []);

    // ---- Phase C: a follow-up run must not repeat either propagation
    // (the entity left the known-state map on delete).
    const markC = await markLogStart(request);
    const packetsBefore = target.receivedPackets.length;
    const runC = await runSyncPair(request, pairId);
    expect(runC.success, JSON.stringify(runC.raw)).toBe(true);
    const windowC = await waitForPairEvents(
      request,
      markC,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );
    expect(target.receivedPackets.length).toBe(packetsBefore);
    expect(forPair(windowC, 'sync_engine.push_ok', pairId).length).toBe(0);
    expectNoUnexpected(windowC, 'SIM-14 tombstone idempotence', []);
  });

  test('媒体大文件分块端到端：3 块真实完成契约 + SHA 保真 + URL 重写', async ({ request }) => {
    test.setTimeout(240_000);
    const mark = await markLogStart(request);

    // A NEW source post carrying a 2.5 MiB asset (3 chunks at 1 MiB).
    source.posts.push({
      guid: MEDIA_GUID,
      sourceId: 40,
      title: 'Sim14 media chunking post',
      content: '<p>Body with a large asset.</p><img src="{{MEDIA}}/wp-content/uploads/sim14-media.png" alt="big"/>',
      excerpt: 'Media excerpt',
    });
    source.seedMedia(MEDIA_GUID, 'sim14-media.png', MEDIA_BYTES);

    const run = await runSyncPair(request, pairId);
    expect(run.success, JSON.stringify(run.raw)).toBe(true);
    await waitForPairSynced(request, pairId, 6, 240_000);

    // ---- Chunk wire evidence: the REAL completion contract — 3 chunk
    // uploads, ONE assembly, assembled sha256 == source asset sha256.
    expect(target.receivedChunks).toBe(3);
    expect(target.mediaAssemblies.length).toBe(1);
    const assembly = target.mediaAssemblies[0]!;
    expect(assembly.filename).toBe('sim14-media.png');
    expect(assembly.sha256).toBe(MEDIA_SHA256);
    expect(assembly.sizeBytes).toBe(MEDIA_BYTES.length);

    // ---- Relay wire evidence: the pushed packet's content is rewritten
    // to the TARGET attachment URL (exact per-asset map, not a domain
    // guess), and no source-site URL survives.
    const relayed = target.receivedPackets.at(-1) as Record<string, unknown>;
    const entity = relayed.entity as Record<string, unknown>;
    expect(String(entity.guid)).toBe(MEDIA_GUID);
    const content = String(
      (entity.core_fields as Record<string, string>).post_content,
    );
    const targetUrl = `${target.baseUrl}/wp-content/uploads/sim14-media.png`;
    expect(content.includes(targetUrl)).toBe(true);
    expect(content.includes(source.baseUrl)).toBe(false);
    expect(target.hmacFailures).toEqual([]);

    // ---- Log oracle: media_assembled fires ONCE with the chunk count.
    const window = await collectLogWindow(request, mark);
    const assembled = forPair(window, 'sync_engine.media_assembled', pairId);
    expect(assembled.length).toBe(1);
    expect(Number(assembled[0]!.detail?.chunks)).toBe(3);
    const finished = forPair(window, 'sync_engine.pair_finished', pairId)[0]!;
    expect(Number(finished.detail?.media)).toBe(1);
    expect(Number(finished.detail?.synced)).toBe(1);
    expectNoUnexpected(window, 'SIM-14 media chunking', []);
  });

  test('故障注入恢复：digest 5xx + push 401 → 下一轮干净运行自愈', async ({ request }) => {
    test.setTimeout(240_000);

    // Create pending work: mutate the resilience post (fingerprint bump).
    source.mutatePost(RESILIENCE_GUID, {
      title: 'Sim14 resilience post (r1)',
      content: '<p>Mutated body awaiting a clean run.</p>',
      excerpt: 'Mutated resilience excerpt',
    });

    // ---- Fault 1: digest 500 → the run fails fast with a visible audit
    // event; the pair records the error; NOTHING reaches the target.
    const markA = await markLogStart(request);
    source.injectFault('digest', 1, { status: 500, code: 'wpmmcc_error' });

    const runA = await runSyncPair(request, pairId);
    expect(runA.success, JSON.stringify(runA.raw)).toBe(true);
    const windowA = await waitForPairEvents(
      request,
      markA,
      ['sync_engine.pair_failed'],
      1,
      180_000,
    );
    const pairAfterA = await getPair(request);
    expect(String(pairAfterA.last_error ?? '')).toContain('digest');
    expect(target.receivedPackets.length).toBe(7);
    expect(forPair(windowA, 'sync_engine.digest_rejected', pairId).length).toBe(1);
    expectNoUnexpected(windowA, 'SIM-14 digest fault', [
      // Designed transient faults: the engine's rejection audits are the
      // journey's subject, not noise.
      'sync_engine.digest_rejected',
      'sync_engine.pair_failed',
    ]);

    // ---- Fault 2: push 401 (HMAC-style rejection) → the packet is seen
    // but rejected; the run records the item error. The push lands on the
    // TARGET site (the relay destination), not the source.
    const markB = await markLogStart(request);
    target.injectFault('push', 1, {
      status: 401,
      code: 'wpmmcc_signature_mismatch',
      message: 'HMAC verification failed',
    });

    const runB = await runSyncPair(request, pairId);
    expect(runB.success, JSON.stringify(runB.raw)).toBe(true);
    const windowB = await waitForPairEvents(
      request,
      markB,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );
    const pairAfterB = await getPair(request);
    expect(String(pairAfterB.last_error ?? '')).toBeTruthy();
    expect(target.rejectedPackets).toBe(1);
    // The rejected attempt never counts as an accepted packet.
    expect(target.receivedPackets.length).toBe(7);
    expect(forPair(windowB, 'sync_engine.push_rejected', pairId).length).toBe(1);
    // pair_finished is warning-level on an error run (error_count > 0).
    expectNoUnexpected(windowB, 'SIM-14 push fault', [
      'sync_engine.push_rejected',
      'sync_engine.pair_finished',
    ]);

    // ---- Recovery: a clean run converges — exactly one new packet, the
    // pair error clears, and the audit chain ends healthy.
    const markC = await markLogStart(request);
    const runC = await runSyncPair(request, pairId);
    expect(runC.success, JSON.stringify(runC.raw)).toBe(true);
    const synced = await waitForPairSynced(request, pairId, 7, 240_000);
    expect(synced.last_error ?? '').toBeFalsy();

    expect(target.receivedPackets.length).toBe(8);
    const healed = target.receivedPackets.at(-1) as Record<string, unknown>;
    expect(String((healed.entity as Record<string, unknown>).guid)).toBe(
      RESILIENCE_GUID,
    );
    const healedFields = (healed.entity as Record<string, unknown>)
      .core_fields as Record<string, string>;
    expect(String(healedFields.post_title)).toBe('Sim14 resilience post (r1)');

    const windowC = await collectLogWindow(request, markC);
    expectEventSequence(
      windowC,
      [
        { event: 'sync_engine.pair_started' },
        { event: 'sync_engine.digest_fetched' },
        { event: 'sync_engine.reconcile_completed' },
        { event: 'sync_engine.pull_batch_completed' },
        { event: 'sync_engine.push_ok' },
        { event: 'sync_engine.pair_finished' },
      ],
      'SIM-14 recovery run',
    );
    expectNoUnexpected(windowC, 'SIM-14 recovery run', []);
  });

  test('首次推送瞬态失败不丢条目：safe cursor 回扫自愈（FL-11）', async ({ request }) => {
    test.setTimeout(240_000);

    // A brand-NEW source post (id above the converged cursor) whose FIRST
    // push hits a transient 500. Pre-FL-11 the scan cursor was persisted
    // past the item before the ship, making it permanently invisible to
    // both the digest (keyset) and the rotation (only asks known
    // entities). The safe cursor must freeze below it so the next run
    // rescans and ships it — with no duplicates.
    const FIRSTSHIP_GUID = 'sim14-post-firstship';
    const markA = await markLogStart(request);
    source.posts.push({
      guid: FIRSTSHIP_GUID,
      sourceId: 50,
      title: 'Sim14 first-ship resilience post',
      content: '<p>First ship must survive a transient rejection.</p>',
      excerpt: 'First-ship excerpt',
    });
    target.injectFault('push', 1, { status: 500 });

    const runA = await runSyncPair(request, pairId);
    expect(runA.success, JSON.stringify(runA.raw)).toBe(true);
    const windowA = await waitForPairEvents(
      request,
      markA,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );

    // The rejected first ship is visible; the item never landed.
    expect(target.rejectedPackets).toBe(2); // 1 (T5) + this fault
    const pairAfterA = await getPair(request);
    expect(String(pairAfterA.last_error ?? '')).toBeTruthy();
    expect(
      target.receivedPackets.some(
        (p) => String((p.entity as Record<string, unknown>).guid) === FIRSTSHIP_GUID,
      ),
    ).toBe(false);
    expect(forPair(windowA, 'sync_engine.push_rejected', pairId).length).toBe(1);
    expectNoUnexpected(windowA, 'SIM-14 first-ship fault', [
      'sync_engine.push_rejected',
      'sync_engine.pair_finished',
    ]);

    // ---- Recovery: a clean run rescans from the frozen cursor, ships the
    // item exactly once, and converges.
    const markB = await markLogStart(request);
    const runB = await runSyncPair(request, pairId);
    expect(runB.success, JSON.stringify(runB.raw)).toBe(true);
    const synced = await waitForPairSynced(request, pairId, 8, 240_000);
    expect(synced.last_error ?? '').toBeFalsy();

    const firstshipPackets = target.receivedPackets.filter(
      (p) => String((p.entity as Record<string, unknown>).guid) === FIRSTSHIP_GUID,
    );
    expect(firstshipPackets.length).toBe(1);
    const fields = (firstshipPackets[0]!.entity as Record<string, unknown>)
      .core_fields as Record<string, string>;
    expect(String(fields.post_title)).toBe('Sim14 first-ship resilience post');

    const windowB = await collectLogWindow(request, markB);
    expectEventSequence(
      windowB,
      [
        { event: 'sync_engine.digest_fetched' },
        { event: 'sync_engine.pull_batch_completed' },
        { event: 'sync_engine.push_ok' },
        { event: 'sync_engine.reconcile_completed' },
        { event: 'sync_engine.pair_finished' },
      ],
      'SIM-14 first-ship recovery',
    );
    expectNoUnexpected(windowB, 'SIM-14 first-ship recovery', []);

    // ---- A follow-up run must not re-ship it (cursor converged).
    const markC = await markLogStart(request);
    const runC = await runSyncPair(request, pairId);
    expect(runC.success, JSON.stringify(runC.raw)).toBe(true);
    const windowC = await waitForPairEvents(
      request,
      markC,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );
    expect(
      target.receivedPackets.filter(
        (p) => String((p.entity as Record<string, unknown>).guid) === FIRSTSHIP_GUID,
      ).length,
    ).toBe(1);
    expect(forPair(windowC, 'sync_engine.push_ok', pairId).length).toBe(0);
    expectNoUnexpected(windowC, 'SIM-14 first-ship idempotence', []);
  });
});
