/**
 * SIM-16: 负向旅程 — language_pack 失败族 + media 失败族（doc 24 §四.5
 * 剩余 P0 族；119 零负例清单的两族零触达事件首次获得端到端覆盖）。
 *
 * Every failure below is DESIGNED (injected at the mock wire) and every
 * journey must converge. The family map (client source, verified):
 *
 * DISCOVERY LANE (ATS site, language_pack family):
 *   T0  POSITIVE BASELINE — the relation carries i18n_config
 *       (translate_plugin_i18n, real plugin class-relation-config-service
 *       contract) so the client runs the plugin_i18n lane: fetch → claim →
 *       per-entry msgid translate → one i18n batch callback (Path B,
 *       /translation-callback with business_line + entries). This is the
 *       family's FIRST positive coverage — the whole lane was zero-touch
 *       before (no spec ever enabled i18n_config).
 *         - discoverer.rs: `language_pack_start` / `language_pack_page_
 *           fetched` / `language_pack_claimed` / `language_pack_submitted`
 *
 *   T1  language-pack content 500 → the fetch arm fails CLOSED (no claim,
 *       no translate, no callback — the lane breaks before any submit).
 *         - discoverer.rs: `discovery.language_pack_fetch_failed` (error)
 *
 *   T2  language-pack claim 500 → fail-closed exactly like the post claim
 *       (lp_items cleared — no unclaimed submission); 500 is NOT a
 *       permanent status so no scan circuit opens. Recovery: the next run
 *       re-claims (fault consumed) and converges.
 *         - discoverer.rs: `discovery.language_pack_claim_failed` (warn)
 *
 *   T3  A) provider 500 on ONE entry → entry-level failure WITHOUT arming
 *           the component cooldown (the lp path has no
 *           record_component_failure) → the REMAINING entry still
 *           translates and the partial batch submits (entry-level
 *           partial, not item-level all_fields_failed).
 *             - discoverer.rs: `discovery.language_pack_translate_failed`
 *       B) i18n callback 500 ×3 → 首次提交耗尽，持久化pipeline同轮重放
 *           同键送达；两条仅译一次，下一轮不重claim/重译。
 *             - discoverer.rs: `discovery.language_pack_callback_failed`
 *
 * MEDIA FAMILY (discovery lane, attachment-copy + media_ref arms):
 *   T4  POSITIVE — subtype `attachment` item with attachment_url (the
 *       no-provider attachment-copy lane, pipeline.rs
 *       build_attachment_copy_trace): authenticated download from the
 *       source origin → /media-upload (REAL contract: filename/relation/
 *       source headers + top-level ack) → callback with media_mappings
 *       (attachment_binary / attachment_source_copy). The mock serves
 *       plain HTTP so the client's honest advisory fires once.
 *         - submitter.rs: `media.upload_plain_http` (warn, whitelisted —
 *           the SIM lane's substrate IS http; the advisory is the product
 *           correctly flagging it)
 *
 *   T5  A) source download 500 → `media.download_failed` — the mapping
 *           keeps translated_ref (manual-queue fallback, REAL semantics:
 *           skip this media, the callback still applies) → the item
 *           completes DEGRADED but honest (no attachment_id).
 *       B) attachment_url at a FOREIGN origin → `media.source_copy_
 *           origin_rejected` (is_configured_wp_origin: scheme+host+port
 *           must match the site base) → no download attempt at all.
 *
 *   T6  media_ref field with an unresolvable value → the pipeline's
 *       FieldTranslationKind::MediaAsset arm cannot extract a source
 *       reference (no URL, no numeric id, no {field}_url companion) →
 *       field-level failure; the item's OTHER fields still translate
 *       (partial, non-blocking).
 *         - pipeline.rs: `discovery.media_ref_source_missing` (warn)
 *           + `discovery.partial_translation` (warn)
 *
 * PAIR LANE (WPMMCC site, media transfer):
 *   T7  /sync/media-chunk rejected mid-transfer (first of 3 chunks of a
 *       2.5 MiB asset) → transfer_media fails for the item → the run
 *       records the error (nothing reaches the target); the safe cursor
 *       keeps the entity rescannable → the next run re-transfers the
 *       whole file (fault consumed) and converges (assembled sha256
 *       preserved, URL rewritten, push lands).
 *         - shipper.rs: `sync_engine.media_chunk_rejected` (warn)
 *
 * Scope note (doc 24): `language_pack_no_component` is NOT reachable in
 * this lane (a single mock relation cannot express a second relation's
 * claimed component — the FL-9 selector guard); it is covered by the
 * FL-9 Rust regressions. `language_pack_claim_missing_items` /
 * `claim_parse_failed` / `task_params_invalid` / `review_*` are defensive
 * arms against response shapes the real plugin does not produce (or
 * fs/db failures) — not honestly mockable, documented as such.
 *
 * Cross-test contamination guards: every journey seeds FRESH objects and
 * a FRESH component (one component-level field failure arms a 30s
 * cooldown — a shared component would poison the next journey).
 */
import { createHash } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { MockAtsSite, MockWpmmccSite } from './lib/mock-wp-site';
import { MockTranslateProvider, translatorTemplate } from './lib/mock-provider';
import {
  apiPut,
  bindSite,
  bootstrapDiscoveryTasks,
  createLocalComponent,
  createSyncPair,
  disableLocalComponent,
  enableDiscoveryTask,
  getReviewMode,
  listDiscoveryTasks,
  listLocalComponents,
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
  expectNoUnexpected,
  markLogStart,
  type LogEvent,
  type LogMark,
} from './lib/sim-log-oracle';

test.describe('SIM-16: 负向旅程 — language_pack 失败族', () => {
  test.describe.configure({ mode: 'serial' });

  const RELATION_ID = 9761;
  const atsSite = new MockAtsSite({
    siteName: 'Sim16 Language Pack ATS',
    routeSecret: 'sim16route',
    wpClientToken: 'sim16lpwptokenaaaaaaaaaaaaaaaaaa1',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [],
    // The language-pack lane gate (Relation.i18n_config) — zero-touch
    // before SIM-16 because no mock relation ever carried these flags.
    i18nConfig: { translate_plugin_i18n: true },
    languagePackItems: [],
  });
  const provider = new MockTranslateProvider();
  let taskId = 0;

  const freshComponent = async (
    request: Parameters<typeof createLocalComponent>[0],
    id: string,
    name: string,
  ) => {
    const component = await createLocalComponent(request, {
      id,
      name,
      kind: 'text',
      templateJson: translatorTemplate(id, provider.translateUrl, name),
    });
    expect(component.success, JSON.stringify(component.raw)).toBe(true);
    const auth = await saveComponentAuth(request, id, { api_key: 'sim16-provider-key-000001' });
    expect(auth.success, JSON.stringify(component.raw)).toBe(true);
    const enable = await enableDiscoveryTask(request, taskId, id);
    expect(enable.success, JSON.stringify(enable.raw)).toBe(true);
  };

  test.beforeAll(async () => {
    await atsSite.start();
    await provider.start();
  });

  test.afterAll(async ({ request }) => {
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await setReviewMode(request, false).catch(() => {});
    await atsSite.stop();
    await provider.stop();
  });

  test('T0 language_pack 正向基线：i18n 车道全链（fetch → claim → 逐条翻译 → i18n 批回调）', async ({
    request,
  }) => {
    test.setTimeout(180_000);

    const bind = await bindSite(request, {
      api_base_url: atsSite.baseUrl,
      wp_client_token: atsSite.wpClientToken,
      route_secret: atsSite.routeSecret,
    });
    expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
    const verify = await verifySiteIdentity(request, atsSite.baseUrl);
    expect(verify.success, JSON.stringify(verify.raw)).toBe(true);

    await setReviewMode(request, false);
    expect(await getReviewMode(request)).toBe(false);

    const boot = await bootstrapDiscoveryTasks(request);
    expect(boot.success, JSON.stringify(boot.raw)).toBe(true);
    const tasks = await listDiscoveryTasks(request);
    const task = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID);
    expect(task, `discovery task missing: ${JSON.stringify(tasks)}`).toBeTruthy();
    taskId = Number(task!.id);

    await freshComponent(request, 'sim16-lp-baseline', 'SIM-16 LP Baseline Translator');

    atsSite.addLanguagePackItem({
      entryId: 8001,
      subtype: 'plugin',
      msgid: 'Save changes',
      textDomain: 'sim16-plugin',
    });
    atsSite.addLanguagePackItem({
      entryId: 8002,
      subtype: 'plugin',
      msgid: 'Delete permanently',
      textDomain: 'sim16-plugin',
    });

    const mark = await markLogStart(request);
    const hitsBefore = provider.hits.length;

    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length,
      1,
      60_000,
    );
    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T0 probe (baseline):',
      JSON.stringify({
        lpPagesServed: atsSite.lpPagesServed,
        lpClaims: atsSite.receivedLanguagePackClaims,
        providerHits: provider.hits.length - hitsBefore,
        callbacks: atsSite.receivedCallbacks.map((c) => c.payload),
        appliedCallbacks: atsSite.appliedCallbacks,
        events: window1.map((e) => `${e.level} ${e.event}`),
      }),
    );

    // Lane order: start → page fetched (2 entries) → claimed → submitted.
    // OBSERVED drain semantics: run_once iterates until an empty pass, so
    // a run WITH work always emits a SECOND language_pack_start for the
    // confirm pass — whose fetch returns the now-claimed-empty pool (no
    // page_fetched, no claim, no callback). Both carries carry the same
    // business_line; only the WORK pass proceeds past the fetch.
    expect(
      eventsNamed(window1, 'discovery.language_pack_start')
        .filter((e) => String(e.detail?.business_line) === 'plugin_i18n').length,
    ).toBe(2);
    const fetched = eventsNamed(window1, 'discovery.language_pack_page_fetched');
    expect(fetched.length).toBeGreaterThanOrEqual(1);
    expect(Number(fetched[0]!.detail?.items)).toBe(2);
    expect(eventsNamed(window1, 'discovery.language_pack_claimed').length).toBe(1);
    const submitted = eventsNamed(window1, 'discovery.language_pack_submitted');
    expect(submitted.length).toBe(1);
    expect(Number(submitted[0]!.detail?.entries)).toBe(2);

    // Per-entry msgid translation: exactly 2 provider hits.
    expect(provider.hits.length - hitsBefore).toBe(2);

    // Claim wire: the lp claim carries the entry ids.
    expect(atsSite.receivedLanguagePackClaims.at(-1)).toEqual([8001, 8002]);

    // Callback wire: ONE i18n Path-B callback with the batch entries.
    const lpCallback = atsSite.receivedCallbacks.at(-1)!;
    const payload = lpCallback.payload as Record<string, unknown>;
    expect(String(payload.business_line)).toBe('plugin_i18n');
    expect(Number(payload.relation_id)).toBe(RELATION_ID);
    const entries = payload.entries as Array<Record<string, unknown>>;
    expect(entries.length).toBe(2);
    expect(String(entries[0]!.msgstr)).toBe('【zh_CN】Save changes【/zh_CN】');
    expect(atsSite.appliedCallbacks).toBe(1);
    expectNoUnexpected(window1, 'SIM-16 T0 lp baseline', []);

    // Steady state: the claim pool removes the entries (30-minute window)
    // — a fresh run fetches an empty page and does NOTHING.
    const mark2 = await markLogStart(request);
    const hitsAfter = provider.hits.length;
    const appliedAfter = atsSite.appliedCallbacks;
    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 2_000));
    const window2 = await collectLogWindow(request, mark2);
    expect(provider.hits.length).toBe(hitsAfter);
    expect(atsSite.appliedCallbacks).toBe(appliedAfter);
    expect(eventsNamed(window2, 'discovery.language_pack_page_fetched').length).toBe(0);
    expectNoUnexpected(window2, 'SIM-16 T0 lp steady-state', []);
  });

  test('T1 language-pack 内容拉取 500 → fetch 臂失败关闭（不 claim/不翻译/不回写）→ 恢复轮收敛', async ({
    request,
  }) => {
    test.setTimeout(180_000);

    atsSite.addLanguagePackItem({
      entryId: 8011,
      subtype: 'plugin',
      msgid: 'Publish now',
      textDomain: 'sim16-plugin',
    });
    atsSite.addLanguagePackItem({
      entryId: 8012,
      subtype: 'plugin',
      msgid: 'Preview draft',
      textDomain: 'sim16-plugin',
    });
    atsSite.injectFault('lp-content', 1, { status: 500, code: 'server_error' });

    const mark = await markLogStart(request);
    const hitsBefore = provider.hits.length;
    const claimsBefore = atsSite.receivedLanguagePackClaims.length;
    const callbacksBefore = atsSite.receivedCallbacks.length;

    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 2_000));
    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T1 probe (fault run):',
      JSON.stringify({
        events: window1.map((e) => `${e.level} ${e.event}`),
        providerHits: provider.hits.length - hitsBefore,
        lpClaimsDelta: atsSite.receivedLanguagePackClaims.length - claimsBefore,
        rejectedCallbacks: atsSite.rejectedCallbacks,
      }),
    );

    // Fail-closed: the fetch error breaks the lane BEFORE the claim —
    // no claim, no translate, no callback.
    const fetchFailed = eventsNamed(window1, 'discovery.language_pack_fetch_failed');
    expect(fetchFailed.length).toBe(1);
    expect(String(fetchFailed[0]!.detail?.subtype)).toBe('plugin');
    expect(atsSite.receivedLanguagePackClaims.length).toBe(claimsBefore);
    expect(provider.hits.length).toBe(hitsBefore);
    expect(atsSite.receivedCallbacks.length).toBe(callbacksBefore);
    expectNoUnexpected(window1, 'SIM-16 T1 lp fetch fault', [
      'discovery.language_pack_fetch_failed',
    ]);

    // Recovery: the fault is consumed — the next run converges (claim +
    // translate both entries + ONE applied i18n callback).
    const mark2 = await markLogStart(request);
    const appliedBefore = atsSite.appliedCallbacks;
    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBefore,
      1,
      60_000,
    );
    const window2 = await collectLogWindow(request, mark2);
    expect(eventsNamed(window2, 'discovery.language_pack_fetch_failed').length).toBe(0);
    expect(atsSite.receivedLanguagePackClaims.at(-1)).toEqual([8011, 8012]);
    expect(atsSite.appliedCallbacks).toBe(appliedBefore + 1);
    const lpCallback = atsSite.receivedCallbacks.at(-1)!;
    const payload = lpCallback.payload as Record<string, unknown>;
    expect((payload.entries as Array<unknown>).length).toBe(2);
    expectNoUnexpected(window2, 'SIM-16 T1 lp recovery', []);
  });

  test('T2 language-pack claim 500 → 失败关闭（清空批次不提交）→ 恢复轮重 claim 收敛', async ({
    request,
  }) => {
    test.setTimeout(180_000);

    atsSite.addLanguagePackItem({
      entryId: 8021,
      subtype: 'plugin',
      msgid: 'Approve review',
      textDomain: 'sim16-plugin',
    });
    atsSite.addLanguagePackItem({
      entryId: 8022,
      subtype: 'plugin',
      msgid: 'Reject review',
      textDomain: 'sim16-plugin',
    });
    // The existing claim lever: with no post items in this site the lp
    // claim is the ONLY claim request, so the fault lands precisely.
    atsSite.injectFault('claim', 1, { status: 500, code: 'server_error' });

    const mark = await markLogStart(request);
    const hitsBefore = provider.hits.length;
    const callbacksBefore = atsSite.receivedCallbacks.length;

    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 2_000));
    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T2 probe (fault run):',
      JSON.stringify({
        events: window1.map((e) => `${e.level} ${e.event}`),
        rejectedClaims: atsSite.rejectedClaims,
        providerHits: provider.hits.length - hitsBefore,
      }),
    );

    // Fail-closed (mirrors the post claim contract): the rejected claim
    // clears the batch — "Do not submit unclaimed entries".
    expect(atsSite.rejectedClaims).toBe(1);
    expect(eventsNamed(window1, 'discovery.language_pack_claim_failed').length).toBe(1);
    expect(provider.hits.length).toBe(hitsBefore);
    expect(atsSite.receivedCallbacks.length).toBe(callbacksBefore);
    // A 500 is transient — no permanent-status scan circuit may open.
    expect(eventsNamed(window1, 'discovery.scan_circuit_open').length).toBe(0);
    expectNoUnexpected(window1, 'SIM-16 T2 lp claim fault', [
      'discovery.language_pack_claim_failed',
    ]);

    // Recovery: the fault is consumed; the entries were NOT claimed by
    // the rejected request, so the next run re-claims and converges.
    const mark2 = await markLogStart(request);
    const appliedBefore = atsSite.appliedCallbacks;
    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBefore,
      1,
      60_000,
    );
    const window2 = await collectLogWindow(request, mark2);
    expect(atsSite.receivedLanguagePackClaims.at(-1)).toEqual([8021, 8022]);
    expect(atsSite.appliedCallbacks).toBe(appliedBefore + 1);
    expectNoUnexpected(window2, 'SIM-16 T2 lp recovery', []);
  });

  test('T3 逐条翻译 500 → 条目级 partial + i18n 回调三次被拒 → 持久化同轮重放同键送达', async ({
    request,
  }) => {
    test.setTimeout(300_000);

    // ---- Phase A: ONE entry's translation fails — the lp path does NOT
    // arm the component cooldown, so the sibling entry still translates
    // and the partial batch submits (entry-level, not item-level).
    atsSite.addLanguagePackItem({
      entryId: 8031,
      subtype: 'plugin',
      msgid: 'First entry must fail',
      textDomain: 'sim16-plugin',
    });
    atsSite.addLanguagePackItem({
      entryId: 8032,
      subtype: 'plugin',
      msgid: 'Second entry must pass',
      textDomain: 'sim16-plugin',
    });
    provider.injectFault(1, { status: 500 });

    const markA = await markLogStart(request);
    const appliedBeforeA = atsSite.appliedCallbacks;
    const hitsBeforeA = provider.hits.length;

    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.appliedCallbacks - appliedBeforeA,
      1,
      60_000,
    );
    const windowA = await collectLogWindow(request, markA);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T3 probe (translate fault):',
      JSON.stringify({
        events: windowA.map((e) => `${e.level} ${e.event}`),
        providerHits: provider.hits.length - hitsBeforeA,
        rejectedHits: provider.rejectedHits,
        callbacks: atsSite.receivedCallbacks.at(-1)?.payload,
      }),
    );
    const translateFailed = eventsNamed(windowA, 'discovery.language_pack_translate_failed');
    expect(translateFailed.length).toBe(1);
    expect(Number(translateFailed[0]!.detail?.entry_id)).toBe(8031);
    // Both entries were attempted (no cooldown skip in the lp lane).
    expect(provider.hits.length - hitsBeforeA).toBe(2);
    // The sibling entry still shipped: the batch submitted with 1 entry.
    const submittedA = eventsNamed(windowA, 'discovery.language_pack_submitted');
    expect(submittedA.length).toBe(1);
    expect(Number(submittedA[0]!.detail?.entries)).toBe(1);
    const payloadA = atsSite.receivedCallbacks.at(-1)!.payload as Record<string, unknown>;
    expect((payloadA.entries as Array<Record<string, unknown>>)[0]!.entry_id).toBe(8032);
    expectNoUnexpected(windowA, 'SIM-16 T3A lp translate fault', [
      'discovery.language_pack_translate_failed',
    ]);

    // ---- Phase A2: the translate-failed entry (8031) is STILL
    // untranslated — only its claim lock holds it out of the pool. Model
    // the 30-minute window elapsing and let the entry converge on its own
    // (the provider fault is consumed), so the callback-failure phases
    // below start from a fully-converged pool and the batch-key equality
    // proof stays exact (after expiry only 8041/8042 can re-enter — the
    // real plugin's untranslated pool shrinks permanently on apply).
    atsSite.expireClaims('language_pack');
    const markA2 = await markLogStart(request);
    const appliedBeforeA2 = atsSite.appliedCallbacks;
    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.appliedCallbacks - appliedBeforeA2,
      1,
      60_000,
    );
    const windowA2 = await collectLogWindow(request, markA2);
    const payloadA2 = atsSite.receivedCallbacks.at(-1)!.payload as Record<string, unknown>;
    const entriesA2 = payloadA2.entries as Array<Record<string, unknown>>;
    expect(entriesA2.length).toBe(1);
    expect(Number(entriesA2[0]!.entry_id)).toBe(8031);
    expect(String(entriesA2[0]!.msgstr)).toBe('【zh_CN】First entry must fail【/zh_CN】');
    expectNoUnexpected(windowA2, 'SIM-16 T3A2 lp failed-entry recovery', []);

    // ---- Phase B: the first submission rejects three attempts. The
    // current durable pipeline then replays the saved batch in-run.
    // The legacy mock reproduces the old no-apply oracle failure too.
    atsSite.addLanguagePackItem({
      entryId: 8041,
      subtype: 'plugin',
      msgid: 'Callback must fail thrice',
      textDomain: 'sim16-plugin',
    });
    atsSite.addLanguagePackItem({
      entryId: 8042,
      subtype: 'plugin',
      msgid: 'Callback batch sibling',
      textDomain: 'sim16-plugin',
    });
    atsSite.injectFault('callback', 3, { status: 500, code: 'server_error' });

    const markB = await markLogStart(request);
    const appliedBeforeB = atsSite.appliedCallbacks;
    const rejectedKeysBefore = atsSite.rejectedCallbackKeys.length;
    const hitsBeforeB = provider.hits.length;

    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 5_000));
    const windowB = await collectLogWindow(request, markB);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T3 probe (callback fault):',
      JSON.stringify({
        events: windowB.map((e) => `${e.level} ${e.event}`),
        rejectedCallbackKeys: atsSite.rejectedCallbackKeys.slice(rejectedKeysBefore),
        appliedCallbacks: atsSite.appliedCallbacks,
      }),
    );
    expect(eventsNamed(windowB, 'discovery.language_pack_callback_failed').length).toBe(1);
    const rejectedKeys = atsSite.rejectedCallbackKeys.slice(rejectedKeysBefore);
    expect(rejectedKeys.length).toBe(3);
    // Retry-equivalence proof: the batch key is deterministic — all three
    // attempts carried the SAME key (and the recovery below re-delivers
    // exactly this key).
    expect(new Set(rejectedKeys).size).toBe(1);
    expect(rejectedKeys.every((k) => k.startsWith('lang-pack-'))).toBe(true);
    // Exactly one saved-batch replay applies; no retranslation on retry.
    expect(atsSite.appliedCallbacks).toBe(appliedBeforeB + 1);
    expect(provider.hits.length - hitsBeforeB).toBe(2);
    const delivered = atsSite.receivedCallbacks.at(-1)!;
    expect(delivered.idempotencyKey).toBe(rejectedKeys[0]!);
    expect(delivered.payload.entries).toEqual([
      { entry_id: 8041, msgstr: '【zh_CN】Callback must fail thrice【/zh_CN】' },
      { entry_id: 8042, msgstr: '【zh_CN】Callback batch sibling【/zh_CN】' },
    ]);
    expectNoUnexpected(windowB, 'SIM-16 T3B lp callback fault', [
      'discovery.language_pack_callback_failed',
      // The in-item i18n sync wrapper records the submission failure
      // (observed in the fault run) before the lane-level event.
      'pipeline.sync_i18n_failed',
      // retry_with_backoff (retry_max=2 → 3 attempts) logs each scheduled
      // backoff at WARNING between the callback attempts (FL-17: the
      // context-wrapped 5xx is now classified retryable).
      'task.retry_scheduled',
    ]);

    // A further run must not re-claim/retranslate the applied entries.
    const markC = await markLogStart(request);
    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.appliedCallbacks - appliedBeforeB,
      1,
      60_000,
    );
    const windowC = await collectLogWindow(request, markC);
    expect(atsSite.appliedCallbacks).toBe(appliedBeforeB + 1);
    expect(provider.hits.length - hitsBeforeB).toBe(2);
    expect(atsSite.receivedCallbacks.at(-1)!.idempotencyKey).toBe(rejectedKeys[0]!);
    expect(eventsNamed(windowC, 'discovery.language_pack_submitted')).toHaveLength(0);
    expectNoUnexpected(windowC, 'SIM-16 T3C lp recovery', []);
  });
});

test.describe('SIM-16: 负向旅程 — media 失败族（发现道附件复制 + media_ref）', () => {
  test.describe.configure({ mode: 'serial' });

  const RELATION_ID = 9771;
  const atsSite = new MockAtsSite({
    siteName: 'Sim16 Media ATS',
    routeSecret: 'sim16mediaroute',
    wpClientToken: 'sim16mediawptokenaaaaaaaaaaaaaa1',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [],
  });
  const provider = new MockTranslateProvider();
  let taskId = 0;

  const freshComponent = async (
    request: Parameters<typeof createLocalComponent>[0],
    id: string,
    name: string,
  ) => {
    const component = await createLocalComponent(request, {
      id,
      name,
      kind: 'text',
      templateJson: translatorTemplate(id, provider.translateUrl, name),
    });
    expect(component.success, JSON.stringify(component.raw)).toBe(true);
    const auth = await saveComponentAuth(request, id, { api_key: 'sim16-media-key-0000001' });
    expect(auth.success, JSON.stringify(component.raw)).toBe(true);
    const enable = await enableDiscoveryTask(request, taskId, id);
    expect(enable.success, JSON.stringify(enable.raw)).toBe(true);
  };

  test.beforeAll(async () => {
    await atsSite.start();
    await provider.start();
  });

  test.afterAll(async ({ request }) => {
    await unbindSite(request, atsSite.baseUrl).catch(() => {});
    await setReviewMode(request, false).catch(() => {});
    await atsSite.stop();
    await provider.stop();
  });

  test('T4 附件复制正向：源站下载 → /media-upload 认证上传 → media_mappings 回写', async ({
    request,
  }) => {
    test.setTimeout(180_000);

    const bind = await bindSite(request, {
      api_base_url: atsSite.baseUrl,
      wp_client_token: atsSite.wpClientToken,
      route_secret: atsSite.routeSecret,
    });
    expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
    const verify = await verifySiteIdentity(request, atsSite.baseUrl);
    expect(verify.success, JSON.stringify(verify.raw)).toBe(true);

    await setReviewMode(request, false);
    expect(await getReviewMode(request)).toBe(false);

    const boot = await bootstrapDiscoveryTasks(request);
    expect(boot.success, JSON.stringify(boot.raw)).toBe(true);
    const tasks = await listDiscoveryTasks(request);
    const task = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID);
    expect(task, `discovery task missing: ${JSON.stringify(tasks)}`).toBeTruthy();
    taskId = Number(task!.id);

    await freshComponent(request, 'sim16-media-copy', 'SIM-16 Media Copy Translator');

    // A small PNG (single-shot /media-upload path — chunking starts at
    // 50 MiB) + an attachment item pointing at this site's public file.
    const PNG_BYTES = Buffer.concat([
      Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
      Buffer.alloc(1024, 11),
    ]);
    atsSite.seedMediaFile('sim16-attachment.png', PNG_BYTES);
    atsSite.addContentItem(
      {
        objectId: 9101,
        postType: 'attachment',
        title: 'Sim16 attachment item',
        content: '',
        attachmentUrl: `${atsSite.baseUrl}/wp-content/uploads/sim16-attachment.png`,
      },
      // SCAN-LANE ONLY: an outbox row would translate the attachment like
      // an ordinary post and confound the copy-lane evidence.
      { outbox: false },
    );

    const mark = await markLogStart(request);
    await runWorkerOnce(request);
    await waitForAtsCallbacks(() => atsSite.receivedCallbacks.length, 1, 60_000);
    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T4 probe (attachment copy):',
      JSON.stringify({
        events: window1.map((e) => `${e.level} ${e.event}`),
        mediaDownloads: atsSite.mediaDownloads,
        mediaUploads: atsSite.mediaUploads,
        callback: atsSite.receivedCallbacks.at(-1)?.payload,
      }),
    );

    // Download + upload wire evidence (REAL /media-upload contract).
    expect(atsSite.mediaDownloads).toBe(1);
    expect(atsSite.mediaUploads.length).toBe(1);
    const upload = atsSite.mediaUploads[0]!;
    expect(upload.filename).toBe('sim16-attachment.png');
    expect(upload.sourceId).toBe(9101);
    expect(upload.relationId).toBe(RELATION_ID);
    expect(upload.sizeBytes).toBe(PNG_BYTES.length);

    // The callback carries the attachment-binary field result + the
    // media mapping with the server-issued attachment id.
    const payload = atsSite.receivedCallbacks.at(-1)!.payload as Record<string, unknown>;
    const fieldResults = payload.field_results as Array<Record<string, unknown>>;
    expect(
      fieldResults.filter(
        (f) => String(f.field) === 'attachment_binary'
          && String(f.detail) === 'attachment_source_copy'
          && String(f.transform_stage) === 'authenticated_source_copy',
      ).length,
    ).toBe(1);
    const mappings = payload.media_mappings as Array<Record<string, unknown>>;
    expect(mappings.length).toBe(1);
    expect(Number(mappings[0]!.attachment_id)).toBe(upload.attachmentId);
    expectNoUnexpected(window1, 'SIM-16 T4 attachment copy', [
      // The SIM lane's substrate is plain HTTP — the client's transport
      // advisory is the product correctly flagging it, not a failure.
      'media.upload_plain_http',
    ]);
  });

  test('T5 源下载 500 → 降级完成（translated_ref 保留/无附件）+ 外源 URL → origin 拒绝零下载', async ({
    request,
  }) => {
    test.setTimeout(180_000);

    // ---- Phase A: the public download is faulted — the media is SKIPPED
    // (manual-queue fallback keeps translated_ref) and the item still
    // completes with an honest no-attachment mapping.
    atsSite.seedMediaFile('sim16-download-fail.png', Buffer.alloc(512, 3));
    atsSite.addContentItem(
      {
        objectId: 9102,
        postType: 'attachment',
        title: 'Sim16 download failure item',
        content: '',
        attachmentUrl: `${atsSite.baseUrl}/wp-content/uploads/sim16-download-fail.png`,
      },
      { outbox: false },
    );
    atsSite.injectFault('media-download', 1, { status: 500, code: 'server_error' });

    const markA = await markLogStart(request);
    const uploadsBefore = atsSite.mediaUploads.length;
    const callbacksBefore = atsSite.receivedCallbacks.length;

    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBefore,
      1,
      60_000,
    );
    const windowA = await collectLogWindow(request, markA);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T5 probe (download fault):',
      JSON.stringify({
        events: windowA.map((e) => `${e.level} ${e.event}`),
        rejectedDownloads: atsSite.rejectedDownloads,
        mediaUploadsDelta: atsSite.mediaUploads.length - uploadsBefore,
        callback: atsSite.receivedCallbacks.at(-1)?.payload,
      }),
    );
    expect(atsSite.rejectedDownloads).toBe(1);
    expect(eventsNamed(windowA, 'media.download_failed').length).toBe(1);
    // No upload attempt for the failed download (skip — manual queue).
    expect(atsSite.mediaUploads.length).toBe(uploadsBefore);
    // Degraded-but-complete: the callback APPLIED with the source URL
    // kept as translated_ref and no attachment id.
    const payloadA = atsSite.receivedCallbacks.at(-1)!.payload as Record<string, unknown>;
    const mappingsA = payloadA.media_mappings as Array<Record<string, unknown>>;
    expect(mappingsA.length).toBe(1);
    expect(String(mappingsA[0]!.translated_ref)).toContain('sim16-download-fail.png');
    expect(mappingsA[0]!.attachment_id == null).toBe(true);
    expectNoUnexpected(windowA, 'SIM-16 T5A download fault', [
      'media.download_failed',
    ]);

    // ---- Phase B: a FOREIGN-origin attachment_url — the source-copy gate
    // (is_configured_wp_origin: scheme+host+port) rejects BEFORE any
    // download; the mapping keeps the URL, the item still completes.
    atsSite.addContentItem(
      {
        objectId: 9103,
        postType: 'attachment',
        title: 'Sim16 foreign origin item',
        content: '',
        // Different port → not the configured origin.
        attachmentUrl: 'http://127.0.0.1:9/sim16-foreign-asset.png',
      },
      { outbox: false },
    );

    const markB = await markLogStart(request);
    const downloadsBefore = atsSite.mediaDownloads;
    const callbacksBeforeB = atsSite.receivedCallbacks.length;
    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBeforeB,
      1,
      60_000,
    );
    const windowB = await collectLogWindow(request, markB);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T5 probe (foreign origin):',
      JSON.stringify({
        events: windowB.map((e) => `${e.level} ${e.event}`),
        mediaDownloadsDelta: atsSite.mediaDownloads - downloadsBefore,
      }),
    );
    expect(eventsNamed(windowB, 'media.source_copy_origin_rejected').length).toBe(1);
    // Zero download attempts for the foreign URL.
    expect(atsSite.mediaDownloads).toBe(downloadsBefore);
    const payloadB = atsSite.receivedCallbacks.at(-1)!.payload as Record<string, unknown>;
    const mappingsB = payloadB.media_mappings as Array<Record<string, unknown>>;
    expect(String(mappingsB[0]!.translated_ref)).toContain('127.0.0.1:9');
    expect(mappingsB[0]!.attachment_id == null).toBe(true);
    expectNoUnexpected(windowB, 'SIM-16 T5B foreign origin', [
      'media.source_copy_origin_rejected',
    ]);
  });

  test('T6 media_ref 字段不可解析 → 字段级失败（media_ref_source_missing）+ 其余字段照译 → partial 回写', async ({
    request,
  }) => {
    test.setTimeout(180_000);

    // This journey runs the task UNPINNED (selected_component_id cleared —
    // T4 pinned 'sim16-media-copy'): a pinned task scopes the registry to
    // that single component (task_scope.rs), so the media_ref format group
    // could never reach an image servant. Unpinned, the pipeline's
    // per-format-group selection (ISS-09) routes over the FULL registry:
    // the plain_text group lands on a text translator and the media_ref
    // group on the image servant below.
    const textComponent = await createLocalComponent(request, {
      id: 'sim16-media-ref',
      name: 'SIM-16 Media Ref Translator',
      kind: 'text',
      templateJson: translatorTemplate(
        'sim16-media-ref',
        provider.translateUrl,
        'SIM-16 Media Ref Translator',
      ),
    });
    expect(textComponent.success, JSON.stringify(textComponent.raw)).toBe(true);
    const textAuth = await saveComponentAuth(request, 'sim16-media-ref', {
      api_key: 'sim16-media-ref-key',
    });
    expect(textAuth.success, JSON.stringify(textAuth.raw)).toBe(true);

    // The media_ref GROUP's servant: local kind 'image' → the loader's
    // default supported_content_formats ["media_ref"] (loader.rs), so the
    // image task-type group selects it. It is never actually invoked for
    // THIS field — extract_media_reference_for_field fails first — so an
    // ordinary translator template is honest here.
    const imageServant = await createLocalComponent(request, {
      id: 'sim16-media-ref-image',
      name: 'SIM-16 Media Ref Image Servant',
      kind: 'image',
      templateJson: translatorTemplate(
        'sim16-media-ref-image',
        provider.translateUrl,
        'SIM-16 Media Ref Image Servant',
      ),
    });
    expect(imageServant.success, JSON.stringify(imageServant.raw)).toBe(true);
    const imageAuth = await saveComponentAuth(request, 'sim16-media-ref-image', {
      api_key: '***********************',
    });
    expect(imageAuth.success, JSON.stringify(imageAuth.raw)).toBe(true);

    // Clear the task's component pin (the real API: an empty
    // selected_component_id normalizes to NULL — discovery_tasks.rs).
    const unpinned = await apiPut(request, `/api/discovery-tasks/${taskId}`, {
      concurrency: 1,
      batch_parallel: 1,
      per_page: 50,
      retry_max: 2,
      timeout_secs: 60,
      enabled: true,
      include_resync: false,
      selected_component_id: '',
    });
    expect(unpinned.success, JSON.stringify(unpinned.raw)).toBe(true);

    // HERMETIC REGISTRY SCOPING (full-lane reality): the lane runs every
    // spec against ONE shared client, so the local component registry
    // carries every component any prior spec ever registered — including
    // STALE ones whose provider servers stopped with their spec's
    // afterAll (sim-13's secondary translator, sim-15's four stray
    // translators whose task pins later specs overwrote). An UNPINNED
    // task's anonymous fallback selects over ALL unclaimed ready
    // components, so in the full lane the title group can land on a dead
    // endpoint (connection refused → all_fields_failed masks the
    // journey's actual subject). Scope the registry for THIS journey:
    // disable every other local component through the real API (the
    // loader skips disabled components entirely). In a focused run the
    // list holds only this spec's components — same code, no-op except
    // its own describe's finished fixtures.
    const keepComponents = new Set(['sim16-media-ref', 'sim16-media-ref-image']);
    const registry = await listLocalComponents(request);
    for (const comp of registry) {
      if (keepComponents.has(comp.id) || comp.enabled === false) continue;
      const off = await disableLocalComponent(request, comp.id);
      // Strict: a 409 (COMPONENT_IN_USE) would mean a task-type/rule
      // BINDING exists — an explicit-layer selection this journey does
      // not control. Fail loudly instead of flaking silently.
      expect(off.success, JSON.stringify(off.raw)).toBe(true);
    }

    // A dedicated rule for a custom post type with a media_ref field (a
    // rule of object_name 'post' would lose the field-presence scoring to
    // the default post rule — a separate subtype makes the match
    // deterministic).
    atsSite.addRule({
      id: 2,
      model_id: 1,
      name: 'sim16-media-ref-fields',
      data_type: 'post',
      object_name: 'media_post',
      field_capabilities: {
        translate_fields: ['post_title', 'featured_image'],
      },
      translate_fields: ['post_title', 'featured_image'],
      related_taxonomies: [],
      field_content_formats: {
        post_title: 'plain_text',
        featured_image: 'media_ref',
      },
      field_storage_map: {
        post_title: 'post_column',
        featured_image: 'post_meta',
      },
      source_group: 'content_objects',
      routing_profile: 'standard',
      delivery_target: 'post_column',
      required_component_slots: ['text_translation'],
      required_content_formats: ['plain_text', 'media_ref'],
    });
    atsSite.addContentItem(
      {
        objectId: 9104,
        postType: 'media_post',
        title: 'Sim16 media ref post title',
        content: '<p>Media ref body.</p>',
        // Neither URL, nor numeric id, nor object — and no featured_image_url
        // companion in complete_data → extract_media_reference_for_field
        // returns None → media_ref_source_missing.
        extraData: { featured_image: 'not-a-media-ref' },
      },
      // SCAN-LANE ONLY: with an outbox row the item would ALSO run through
      // the outbox lane (a second pipeline pass + callback) and confound
      // the journey's field-level evidence.
      { outbox: false },
    );

    const mark = await markLogStart(request);
    const hitsBefore = provider.hits.length;
    await runWorkerOnce(request);
    await waitForAtsCallbacks(() => atsSite.receivedCallbacks.length, 1, 60_000);
    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T6 probe (media_ref missing):',
      JSON.stringify({
        events: window1.map((e) => `${e.level} ${e.event}`),
        providerHits: provider.hits.length - hitsBefore,
        callback: atsSite.receivedCallbacks.at(-1)?.payload,
      }),
    );

    // Field-level failure with the precise reason.
    const missingRef = eventsNamed(window1, 'discovery.media_ref_source_missing');
    expect(missingRef.length).toBe(1);
    expect(String(missingRef[0]!.detail?.field)).toBe('featured_image');
    // The item's OTHER fields still translated (non-blocking partial) —
    // at least the title hit the provider.
    expect(provider.hits.length - hitsBefore).toBeGreaterThanOrEqual(1);
    expect(eventsNamed(window1, 'discovery.partial_translation').length).toBe(1);
    // The callback applied with the failed field recorded honestly.
    const payload = atsSite.receivedCallbacks.at(-1)!.payload as Record<string, unknown>;
    const fieldResults = payload.field_results as Array<Record<string, unknown>>;
    expect(
      fieldResults.filter(
        (f) => String(f.field) === 'featured_image' && String(f.status) === 'failed',
      ).length,
    ).toBe(1);
    expect(
      fieldResults.filter(
        (f) => String(f.field) === 'post_title' && String(f.status) === 'success',
      ).length,
    ).toBe(1);
    expectNoUnexpected(window1, 'SIM-16 T6 media_ref missing', [
      'discovery.media_ref_source_missing',
      'discovery.partial_translation',
      // The unpinned task's per-format routing spans TWO components for
      // one item (text group + image servant) — the honest multi-component
      // audit of exactly the topology this journey builds.
      'discovery.multi_component_item',
    ]);
  });
});

test.describe('SIM-16: 负向旅程 — media 失败族（配对道分块转存）', () => {
  test.describe.configure({ mode: 'serial' });

  const MEDIA_GUID = 'sim16-post-media';
  /** 2.5 MiB → 3 chunks at the engine's 1 MiB MEDIA_CHUNK_BYTES. */
  const MEDIA_BYTES = Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    Buffer.alloc(2.5 * 1024 * 1024 - 8, 13),
  ]);
  const MEDIA_SHA256 = createHash('sha256').update(MEDIA_BYTES).digest('hex');

  const source = new MockWpmmccSite({
    siteUuid: 'sim16-source-uuid-0003',
    siteName: 'Sim16 Source Site',
    routeSecret: 'sim16srcpairsecret0000001',
    wpClientToken: 'sim16srcwptokenaaaaaaaaaaaaaaaa2',
    posts: [],
  });

  const target = new MockWpmmccSite({
    siteUuid: 'sim16-target-uuid-0004',
    siteName: 'Sim16 Target Site',
    routeSecret: 'sim16tgtpairsecret0000001',
    wpClientToken: 'sim16tgtwptokenaaaaaaaaaaaaaaaa2',
    posts: [],
  });

  let pairId = '';

  test.beforeAll(async () => {
    await source.start();
    await target.start();
  });

  test.afterAll(async ({ request }) => {
    const { listSyncPairs } = await import('./lib/sim-client');
    const { pairs } = await listSyncPairs(request).catch(() => ({
      pairs: [] as Array<{ id: string }>,
    }));
    for (const pair of pairs) {
      await (
        await import('./lib/sim-client')
      ).apiPost(request, '/api/sync-pairs/delete', { id: pair.id }).catch(() => {});
    }
    for (const site of [source, target]) {
      const { apiDelete, unbindSite: unbind } = await import('./lib/sim-client');
      await apiDelete(
        request,
        `/api/sync-pairs/credentials/${encodeURIComponent(site.baseUrl)}`,
      ).catch(() => {});
      await unbind(request, site.baseUrl).catch(() => {});
    }
    await source.stop();
    await target.stop();
  });

  /** pair-attributed events (FL-5 pair_id detail). */
  function forPair(events: LogEvent[], name: string, id: string): LogEvent[] {
    return eventsNamed(events, name).filter((e) => String(e.detail?.pair_id) === id);
  }

  async function waitForPairEvents(
    request: import('@playwright/test').APIRequestContext,
    mark: LogMark,
    names: string[],
    minCount: number,
    timeoutMs: number,
  ): Promise<LogEvent[]> {
    const { collectLogWindow: collect } = await import('./lib/sim-log-oracle');
    const deadline = Date.now() + timeoutMs;
    let window: LogEvent[] = [];
    while (Date.now() < deadline) {
      window = await collect(request, mark);
      const hits = window.filter(
        (e) => names.includes(e.event) && String(e.detail?.pair_id) === pairId,
      );
      if (hits.length >= minCount) return window;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    throw new Error(
      `SIM-16: timed out waiting for ${minCount} × [${names.join('|')}] for pair ${pairId};` +
        ` last window tail:\n${window.slice(-8).map((e) => `${e.level} ${e.event}`).join('\n')}`,
    );
  }

  test('T7 配对道 media-chunk 拒绝 → 转存失败零到达 → 安全游标回扫 → 全量重传收敛', async ({
    request,
  }) => {
    test.setTimeout(300_000);

    // Bind + verify both WPMMCC sites, then pair them through the real
    // one-time pairing-code handshake (the sim-14 topology flow — the
    // pairing-code comes from each site's admin surface, the client
    // derives + stores the HMAC shared secret).
    for (const site of [source, target]) {
      const bind = await bindSite(request, {
        api_base_url: site.baseUrl,
        wp_client_token: site.wpClientToken,
        route_secret: site.routeSecret,
      });
      expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
      const verify = await verifySiteIdentity(request, site.baseUrl);
      expect(verify.success, JSON.stringify(verify.raw)).toBe(true);
    }
    for (const [site, role] of [
      [source, 'source'],
      [target, 'target'],
    ] as const) {
      const res = await pairSite(request, {
        domain: site.baseUrl,
        pairing_code: site.generatePairingCode(),
        role,
      });
      expect(res.success, `pairing ${site.siteName} as ${role}: ${JSON.stringify(res.raw)}`)
        .toBe(true);
    }
    const created = await createSyncPair(request, {
      name: 'SIM-16 media chunk fault pair',
      source_domain: source.baseUrl,
      target_domain: target.baseUrl,
      source_lang: 'en_US',
      target_lang: 'zh_CN',
      sync_mode: 'sync_only',
    });
    expect(created.success, JSON.stringify(created.raw)).toBe(true);
    const { pairs } = await listSyncPairs(request);
    const pair = pairs.find(
      (p) => p.source_domain === source.baseUrl && p.target_domain === target.baseUrl,
    );
    expect(pair, `pair missing: ${JSON.stringify(pairs)}`).toBeTruthy();
    pairId = pair!.id;

    // A NEW source post carrying the 2.5 MiB asset (3 chunks at 1 MiB).
    source.posts.push({
      guid: MEDIA_GUID,
      sourceId: 50,
      title: 'Sim16 media chunk fault post',
      content: '<p>Body with a chunked asset.</p><img src="{{MEDIA}}/wp-content/uploads/sim16-media.png" alt="big"/>',
      excerpt: 'Media chunk fault excerpt',
    });
    source.seedMedia(MEDIA_GUID, 'sim16-media.png', MEDIA_BYTES);

    // ---- Fault run: the FIRST chunk upload is rejected → transfer_media
    // fails for the item → nothing reaches the target (no packet, no
    // assembly); the run records the error.
    target.injectFault('media-chunk', 1, { status: 500, code: 'wpmmcc_error' });

    const markA = await markLogStart(request);
    const runA = await runSyncPair(request, pairId);
    expect(runA.success, JSON.stringify(runA.raw)).toBe(true);
    const windowA = await waitForPairEvents(
      request,
      markA,
      ['sync_engine.pair_finished'],
      1,
      180_000,
    );
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T7 probe (chunk fault):',
      JSON.stringify({
        events: windowA.map((e) => `${e.level} ${e.event}`),
        rejectedMediaChunks: target.rejectedMediaChunks,
        receivedChunks: target.receivedChunks,
        assemblies: target.mediaAssemblies.length,
        packets: target.receivedPackets.length,
      }),
    );
    // The rejection is pair-attributed and visible.
    expect(target.rejectedMediaChunks).toBe(1);
    expect(forPair(windowA, 'sync_engine.media_chunk_rejected', pairId).length).toBe(1);
    expect(
      Number(forPair(windowA, 'sync_engine.media_chunk_rejected', pairId)[0]!.detail?.chunk_index),
    ).toBe(0);
    // Nothing landed: no assembly, no push for this entity.
    expect(target.mediaAssemblies.length).toBe(0);
    expect(target.receivedPackets.length).toBe(0);
    // The run itself finished (the item error is recorded on the pair).
    const { listSyncPairs: listPairs } = await import('./lib/sim-client');
    const { pairs: pairsA } = await listPairs(request);
    const pairAfterA = pairsA.find(
      (p) => p.source_domain === source.baseUrl && p.target_domain === target.baseUrl,
    )!;
    expect(String(pairAfterA.last_error ?? '')).toBeTruthy();
    const unresolved = forPair(windowA, 'sync_engine.inflight_unresolved', pairId);
    expect(unresolved).toHaveLength(1);
    expect(Number(unresolved[0]!.detail.units)).toBe(1);
    expect(unresolved[0]!.detail.sample).toEqual([
      { attempts: 1, has_relayed_packet: false, uuid: 'sim16-post-media' },
    ]);
    expectNoUnexpected(windowA, 'SIM-16 T7 chunk fault run', [
      'sync_engine.media_chunk_rejected',
      'sync_engine.inflight_unresolved',
      // The run's own summary logs at WARNING whenever error_count > 0
      // (sync_engine/discoverer.rs) — the honest record of the failed item.
      'sync_engine.pair_finished',
    ]);

    // ---- Recovery run: the fault is consumed; the safe cursor kept the
    // entity rescannable → the whole file re-transfers (3 clean chunks)
    // and the push lands with the rewritten attachment URL.
    const markB = await markLogStart(request);
    const runB = await runSyncPair(request, pairId);
    expect(runB.success, JSON.stringify(runB.raw)).toBe(true);
    const { waitForPairSynced: waitSynced } = await import('./lib/sim-client');
    await waitSynced(request, pairId, 1, 240_000);
    const windowB = await collectLogWindow(request, markB);
    // eslint-disable-next-line no-console
    console.log(
      'sim16 T7 probe (recovery):',
      JSON.stringify({
        events: windowB.map((e) => `${e.level} ${e.event}`),
        receivedChunks: target.receivedChunks,
        assemblies: target.mediaAssemblies,
        packets: target.receivedPackets.length,
      }),
    );
    expect(target.mediaAssemblies.length).toBe(1);
    const assembly = target.mediaAssemblies[0]!;
    expect(assembly.filename).toBe('sim16-media.png');
    expect(assembly.sha256).toBe(MEDIA_SHA256);
    expect(assembly.sizeBytes).toBe(MEDIA_BYTES.length);
    expect(forPair(windowB, 'sync_engine.media_assembled', pairId).length).toBe(1);
    // The relay landed with the rewritten URL (no source URL survives).
    const relayed = target.receivedPackets.at(-1) as Record<string, unknown>;
    const entity = relayed.entity as Record<string, unknown>;
    expect(String(entity.guid)).toBe(MEDIA_GUID);
    const content = String(
      (entity.core_fields as Record<string, string>).post_content,
    );
    expect(content.includes(`${target.baseUrl}/wp-content/uploads/sim16-media.png`)).toBe(true);
    expect(content.includes(source.baseUrl)).toBe(false);
    expectNoUnexpected(windowB, 'SIM-16 T7 recovery run', []);
  });
});
