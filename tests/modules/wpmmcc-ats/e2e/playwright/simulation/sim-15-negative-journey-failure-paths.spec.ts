/**
 * SIM-15: 负向旅程 — 发现任务失败族（doc 24 §四.5 P0 切片）
 *
 * Every failure below is DESIGNED (injected at the mock wire) and every
 * journey must converge. The family map (client source, verified):
 *
 *   T1  ATS claim 500          → 扫描道该轮失败关闭（fail-closed：清空本
 *       批次、不提交），outbox 道在同一轮把对象扛到完成（车道隔离）；
 *       run-once 多轮循环的下一轮 claim 成功（故障已消费）自愈。修复
 *       FL-12 后：已完成对象被扫描道跳过（不再重复翻译/重复回写）。
 *         - discoverer.rs: `discovery.content_claim_failed` (warn)
 *
 *   T2  provider 500 ×1        → 首字段（title）失败即触发组件退避，同条
 *       目其余字段被 component_backoff_skip 跳过 → all_fields_failed →
 *       outbox_execution_failed + retry-ack，行留站点；30s 退避到期后的
 *       恢复轮全量翻译 + 回写收敛（故障已消费）。
 *         - pipeline.rs: `discovery.field_translate_failed` (warn)
 *           + `component_backoff_skip` ×2 + `all_fields_failed` (error)
 *           + discoverer.rs `outbox_execution_failed` (warn)
 *
 *   T3  ATS callback 500 ×4 → 三次提交耗尽后持久化，同轮pipeline重放
 *       再拒一次、同键送达；三个字段仅译一次，下一轮零重复。
 *         - `discovery.callback_failed`、`task.retry_scheduled`
 *           + `discovery.callback_sent` / `pipeline.sync_done`
 *
 *   T4  坏源数据 + ack 500     → 无 job snapshot 的对象在 Provider 调用
 *       之前被拒（missing source_revision，不触发组件熔断）→ outbox
 *       Err 臂：`outbox_execution_failed` + retry-ack；注入的 ack 故障
 *       把 retry-ack 变成 `outbox_fail_ack_failed`，行留在站点。站点
 *       修复数据（补 snapshot）后，停车/退避到期 → 翻译 + 回写收敛，
 *       行由 2xx 回调在站点侧完成（真实插件 complete_outbox 契约）。
 *         - discoverer.rs: Err 臂 ack 失败 → `outbox_fail_ack_failed`
 *
 *   T5  provider 200 + `{}`    → 空输出（跳过前两字段，故障打在最后
 *       的 content 上 — 首字段失败会触发 T2 的整条目退避族）→
 *       输出校验型字段失败（`field_translate_failed`，非 HTTP 错误）
 *       + `partial_translation`，条目以 2/3 字段完成回写（部分失败
 *       不阻塞）。
 *         - pipeline.rs: `discovery.field_translate_failed` (warn)
 *           + `discovery.partial_translation` (warn)
 *
 * SIM-15 findings (fixed with this spec, see doc 24):
 *   FL-12  outbox 道从不写 materialized success record → 扫描道
 *          has_materialized_success_record 去重漏掉 outbox 完成的对象
 *          → run-once 后续轮重复翻译 + 重复回写（provider 双倍计费、
 *          双份站点写入）。修复：Completed 臂落 record。
 *   FL-13  outbox 道把 `_wptsall_outbox_id` 混进 complete_data 后再算
 *          snapshot hash → 同一对象两条道推出不同幂等键（跨道重试
 *          永远打不中站点幂等缓存）。修复：hash 排除 `_wptsall_*`
 *          客户端内部键（站点侧 `__wptsall_job_snapshot` 保留）。
 *   回放审计缺口（FO-1 同类）：pending/dedup 重放成功原先无
 *          callback_sent 事件 → 补 `via: pending_replay/dedup_replay`。
 *   Mock 保真度：ATS 回调接收器补齐真实插件契约 — Idempotency-Key
 *          缓存（同键同体 → 缓存重放；同键异体 → 409）+ 2xx 回调
 *          complete_outbox 行。
 *
 * Cross-test contamination guards: every journey seeds a FRESH object
 * (addContentItem) and binds a FRESH component (one field failure arms a
 * 30s component cooldown — a shared component would poison the next
 * journey's first run).
 */
import { test, expect } from '@playwright/test';
import { MockAtsSite } from './lib/mock-wp-site';
import { MockTranslateProvider, translatorTemplate } from './lib/mock-provider';
import {
  bindSite,
  bootstrapDiscoveryTasks,
  createLocalComponent,
  enableDiscoveryTask,
  findDoneItemForRelation,
  getReviewMode,
  listDiscoveryTasks,
  runWorkerOnce,
  saveComponentAuth,
  setReviewMode,
  unbindSite,
  verifySiteIdentity,
  waitForAtsCallbacks,
} from './lib/sim-client';
import {
  collectLogWindow,
  eventsNamed,
  expectEventSequence,
  expectNoUnexpected,
  markLogStart,
} from './lib/sim-log-oracle';

test.describe('SIM-15: 负向旅程 — 发现任务失败族', () => {
  test.describe.configure({ mode: 'serial' });

  const RELATION_ID = 9751;
  const SITE_OPTS = {
    siteName: 'Sim Negative Journey ATS',
    routeSecret: 'sim15route',
    wpClientToken: 'sim15-token-0123456789abcdef',
    relation: { id: RELATION_ID, sourceLang: 'en_US', targetLang: 'zh_CN' },
    contentItems: [] as Array<{
      objectId: number;
      postType: string;
      title: string;
      content: string;
      excerpt: string;
    }>,
  };

  const atsSite = new MockAtsSite(SITE_OPTS);
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
    const auth = await saveComponentAuth(request, id, { api_key: 'sim15-provider-key' });
    expect(auth.success, JSON.stringify(auth.raw)).toBe(true);
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

  test('T0 绑定站点 + 组件 + 引导发现任务（review 关闭）', async ({ request }) => {
    test.setTimeout(120_000);

    const bind = await bindSite(request, {
      api_base_url: atsSite.baseUrl,
      wp_client_token: atsSite.wpClientToken,
      route_secret: atsSite.routeSecret,
    });
    expect(bind.success, JSON.stringify(bind.raw)).toBe(true);
    const verify = await verifySiteIdentity(request, atsSite.baseUrl);
    expect(verify.success, JSON.stringify(verify.raw)).toBe(true);
    expect(String(verify.data.plugin_identity)).toBe('wpmmcc_ats');

    await setReviewMode(request, false);
    expect(await getReviewMode(request)).toBe(false);

    const boot = await bootstrapDiscoveryTasks(request);
    expect(boot.success, JSON.stringify(boot.raw)).toBe(true);
    const tasks = await listDiscoveryTasks(request);
    const task = tasks.find((t) => Number(t.relation_id ?? 0) === RELATION_ID);
    expect(task, `discovery task missing: ${JSON.stringify(tasks)}`).toBeTruthy();
    taskId = Number(task!.id);

    // Bind the task to the T1 component (freshComponent creates + auths +
    // enables with the task id captured above).
    await freshComponent(request, 'sim15-claim-guard', 'SIM-15 Claim Guard Translator');
  });

  test('T1 claim 500 → 扫描道失败关闭 + outbox 道隔离扛住 + 迟到 claim 不重复', async ({
    request,
  }) => {
    test.setTimeout(240_000);

    atsSite.addContentItem({
      objectId: 9001,
      postType: 'post',
      title: 'Sim15 claim failure title',
      content: '<p>Sim15 claim failure body.</p>',
      excerpt: 'Sim15 claim excerpt',
    });
    atsSite.injectFault('claim', 1, { status: 500, code: 'server_error' });

    const mark = await markLogStart(request);
    const callbacksBefore = atsSite.receivedCallbacks.length;
    const hitsBefore = provider.hits.length;

    await runWorkerOnce(request);
    // The outbox lane carries the object even though the scan lane's claim
    // was rejected (fail-closed: no submission from the scan lane).
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBefore,
      1,
      60_000,
    );

    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T1 probe (fault run):',
      JSON.stringify({
        rejectedClaims: atsSite.rejectedClaims,
        callbacks: atsSite.receivedCallbacks.map((c) => c.idempotencyKey),
        appliedCallbacks: atsSite.appliedCallbacks,
        idempotentReplays: atsSite.idempotentReplays,
        rejectedCallbacks: atsSite.rejectedCallbacks,
        providerHits: provider.hits.length - hitsBefore,
        receivedClaims: atsSite.receivedClaims,
        acks: atsSite.receivedAcks,
        pendingRows: atsSite.pendingOutboxCount,
        events: window1.map((e) => `${e.level} ${e.event}`),
      }),
    );
    // Fail-closed proof: the faulted claim pass cleared its batch and
    // submitted nothing (the warn + zero items processed on its pass).
    expect(atsSite.rejectedClaims).toBe(1);
    expect(eventsNamed(window1, 'discovery.content_claim_failed').length).toBe(1);
    // The outbox lane carried the object to completion in the SAME run
    // (lane isolation) — the completed-ack + delivered callback prove it.
    expect(eventsNamed(window1, 'discovery.outbox_executed').length).toBeGreaterThanOrEqual(1);
    // The run-once loop self-heals within the run: a LATER pass re-claims
    // (fault consumed) — content_claimed fires for the retry pass.
    expect(eventsNamed(window1, 'discovery.content_claimed').length).toBeGreaterThanOrEqual(1);
    // Whatever the later pass re-delivered, the site applied EXACTLY one
    // write for the object (idempotency cache, real plugin contract).
    expect(atsSite.appliedCallbacks).toBe(1);
    const done = await findDoneItemForRelation(request, RELATION_ID);
    if ('status' in done) expect(String(done.status)).toBe('done');
    expectNoUnexpected(window1, 'SIM-15 T1 claim-fault run', ['discovery.content_claim_failed']);

    // Steady state (claim lock now active): a fresh run must be a no-op —
    // no duplicate translation, no duplicate applied write.
    const mark2 = await markLogStart(request);
    const callbacksAfterFirst = atsSite.receivedCallbacks.length;
    const appliedAfterFirst = atsSite.appliedCallbacks;
    const hitsAfterFirst = provider.hits.length;
    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 2_000));
    const window2 = await collectLogWindow(request, mark2);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T1 steady-state probe:',
      JSON.stringify({
        receivedClaims: atsSite.receivedClaims,
        providerHitsDelta: provider.hits.length - hitsAfterFirst,
        appliedCallbacks: atsSite.appliedCallbacks,
        events: window2.map((e) => `${e.level} ${e.event}`),
      }),
    );
    expect(atsSite.receivedCallbacks.length).toBe(callbacksAfterFirst);
    expect(atsSite.appliedCallbacks).toBe(appliedAfterFirst);
    expectNoUnexpected(window2, 'SIM-15 T1 steady-state run', []);
  });

  test('T2 provider 500 ×1 → 字段失败 + 组件退避 → 整条目失败停车 → 恢复轮全量收敛', async ({
    request,
  }) => {
    test.setTimeout(300_000);

    await freshComponent(request, 'sim15-partial-field', 'SIM-15 Partial Field Translator');
    atsSite.addContentItem({
      objectId: 9002,
      postType: 'post',
      title: 'Sim15 partial failure title',
      content: '<p>Sim15 partial failure body.</p>',
      excerpt: 'Sim15 partial excerpt',
    });
    // Observed truth (SIM-15 probe): ONE component-level field failure arms
    // the component cooldown IMMEDIATELY, so the item's remaining fields
    // skip (component_backoff_skip) and the whole attempt fails
    // (all_fields_failed → outbox_execution_failed → retry-ack). The item
    // converges on the next run once the 30s cooldowns expire.
    provider.injectFault(1, { status: 500 });

    const mark = await markLogStart(request);
    const callbacksBefore = atsSite.receivedCallbacks.length;
    const appliedBefore = atsSite.appliedCallbacks;

    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 2_000));
    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T2 probe (fault run):',
      JSON.stringify({
        rejectedHits: provider.rejectedHits,
        providerHits: provider.hits.length,
        callbacks: atsSite.receivedCallbacks.length,
        appliedCallbacks: atsSite.appliedCallbacks,
        acks: atsSite.receivedAcks,
        pendingRows: atsSite.pendingOutboxCount,
        events: window1.map((e) => `${e.level} ${e.event}`),
      }),
    );
    // The designed failure family: field failure → cooldown skips the
    // remaining fields → whole item fails → retry-ack (clean) + row stays.
    expect(provider.rejectedHits).toBe(1);
    expect(eventsNamed(window1, 'discovery.field_translate_failed').length).toBe(1);
    expect(eventsNamed(window1, 'discovery.component_backoff_skip').length).toBe(2);
    expect(eventsNamed(window1, 'discovery.all_fields_failed').length).toBe(1);
    expect(eventsNamed(window1, 'discovery.outbox_execution_failed').length).toBe(1);
    expect(atsSite.pendingOutboxCount).toBe(1);
    expect(atsSite.appliedCallbacks).toBe(appliedBefore);
    expect(atsSite.receivedCallbacks.length).toBe(callbacksBefore);
    const retryAcks = atsSite.receivedAcks.filter((a) => a.outcome === 'retry');
    expect(retryAcks.length).toBe(1);
    expectNoUnexpected(window1, 'SIM-15 T2 field-failure run', [
      'discovery.field_translate_failed',
      'discovery.component_backoff_skip',
      'discovery.no_translation_output',
      'discovery.all_fields_failed',
      'discovery.outbox_execution_failed',
    ]);

    // Recovery: 30s item backoff + component cooldown + row hold expire,
    // the fault is consumed, the next run translates ALL fields cleanly.
    await new Promise((resolve) => setTimeout(resolve, 35_000));
    const mark2 = await markLogStart(request);
    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBefore,
      1,
      60_000,
    );
    const window2 = await collectLogWindow(request, mark2);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T2 probe (recovery run):',
      JSON.stringify({
        providerHits: provider.hits.length,
        callbacks: atsSite.receivedCallbacks.map((c) => c.idempotencyKey),
        appliedCallbacks: atsSite.appliedCallbacks,
        acks: atsSite.receivedAcks,
        pendingRows: atsSite.pendingOutboxCount,
        events: window2.map((e) => `${e.level} ${e.event}`),
      }),
    );
    const callback = atsSite.receivedCallbacks[atsSite.receivedCallbacks.length - 1];
    const fields = (callback.payload.translated_fields ?? {}) as Record<string, string>;
    const machine = Object.values(fields).filter((v) => v.includes('【zh_CN】'));
    expect(machine.length, `full recovery missing fields: ${JSON.stringify(fields)}`).toBe(3);
    expect(atsSite.appliedCallbacks).toBe(appliedBefore + 1);
    expect(atsSite.pendingOutboxCount).toBe(0);
    expectNoUnexpected(window2, 'SIM-15 T2 recovery run', []);
  });

  test('T3 callback 500 ×4 → 提交失败持久化 → 同轮重放同键送达，零重复翻译', async ({
    request,
  }) => {
    test.setTimeout(300_000);

    await freshComponent(request, 'sim15-cb-retry', 'SIM-15 Callback Retry Translator');
    atsSite.addContentItem({
      objectId: 9003,
      postType: 'post',
      title: 'Sim15 callback retry title',
      content: '<p>Sim15 callback retry body.</p>',
      excerpt: 'Sim15 callback retry excerpt',
    });
    // The current durable pipeline resumes its saved callback in-run.
    // Three faults exhaust the first submission; fault four consumes one
    // replay attempt before the SAME saved payload succeeds. No paid field
    // is translated again. The old single-shot/65s-hold oracle also failed
    // with the legacy mock; this changes the oracle, not product semantics.
    atsSite.injectFault('callback', 4, { status: 500 });

    const mark = await markLogStart(request);
    const callbacksBefore = atsSite.receivedCallbacks.length;
    const appliedBefore = atsSite.appliedCallbacks;
    const hitsBefore = provider.hits.length;

    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 2_000));
    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T3 probe (fault run):',
      JSON.stringify({
        rejectedCallbacks: atsSite.rejectedCallbacks,
        rejectedCallbackKeys: atsSite.rejectedCallbackKeys,
        callbacks: atsSite.receivedCallbacks.length,
        appliedCallbacks: atsSite.appliedCallbacks,
        providerHits: provider.hits.length,
        pendingRows: atsSite.pendingOutboxCount,
        events: window1.map((e) => `${e.level} ${e.event}`),
      }),
    );
    // Four rejected deliveries followed by exactly one applied write.
    expect(atsSite.rejectedCallbacks).toBe(4);
    expect(atsSite.rejectedCallbackKeys.length).toBe(4);
    const keySet = new Set(atsSite.rejectedCallbackKeys);
    expect(
      keySet.size,
      `rejected keys differ across attempts: ${JSON.stringify(atsSite.rejectedCallbackKeys)}`,
    ).toBe(1);
    expect(eventsNamed(window1, 'task.retry_scheduled').length).toBe(3);
    expect(eventsNamed(window1, 'discovery.callback_failed').length).toBe(1);
    expect(eventsNamed(window1, 'discovery.callback_retry_failed')).toHaveLength(0);
    expect(atsSite.receivedCallbacks.length).toBe(callbacksBefore + 1);
    expect(atsSite.appliedCallbacks).toBe(appliedBefore + 1);
    expect(atsSite.pendingOutboxCount).toBe(0);
    expect(provider.hits.length - hitsBefore).toBe(3);
    const delivered = atsSite.receivedCallbacks.at(-1)!;
    expect(delivered.idempotencyKey).toBe(atsSite.rejectedCallbackKeys[0]);
    expect(delivered.payload.translated_fields).toEqual({
      post_title: '【zh_CN】Sim15 callback retry title【/zh_CN】',
      post_content: '<p>【zh_CN】Sim15 callback retry body.【/zh_CN】</p>',
      post_excerpt: '【zh_CN】Sim15 callback retry excerpt【/zh_CN】',
    });
    expectEventSequence(
      window1,
      [
        { event: 'task.retry_scheduled' },
        { event: 'task.retry_scheduled' },
        { event: 'discovery.callback_failed' },
        { event: 'task.retry_scheduled' },
        { event: 'discovery.callback_sent' },
      ],
      'SIM-15 T3 retry-exhaustion-then-durable-replay',
    );
    expectNoUnexpected(window1, 'SIM-15 T3 callback-fault run', [
      'discovery.callback_failed',
      'task.retry_scheduled',
    ]);

    // A second run is a true no-op, not a retranslation after a timed hold.
    const mark2 = await markLogStart(request);
    await runWorkerOnce(request);
    const window2 = await collectLogWindow(request, mark2);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T3 probe (recovery run):',
      JSON.stringify({
        rejectedCallbacks: atsSite.rejectedCallbacks,
        callbacks: atsSite.receivedCallbacks.map((c) => c.idempotencyKey),
        appliedCallbacks: atsSite.appliedCallbacks,
        idempotentReplays: atsSite.idempotentReplays,
        pendingRows: atsSite.pendingOutboxCount,
        events: window2.map((e) => `${e.level} ${e.event}`),
      }),
    );
    expect(provider.hits.length - hitsBefore).toBe(3);
    expect(atsSite.receivedCallbacks.length).toBe(callbacksBefore + 1);
    expect(atsSite.appliedCallbacks).toBe(appliedBefore + 1);
    expect(atsSite.pendingOutboxCount).toBe(0);
    expect(eventsNamed(window2, 'discovery.callback_sent')).toHaveLength(0);
    expectNoUnexpected(window2, 'SIM-15 T3 recovery run', []);
  });

  test('T4 坏数据重试臂 + ack 500 → outbox_fail_ack_failed → 站点修复后收敛', async ({
    request,
  }) => {
    test.setTimeout(300_000);

    await freshComponent(request, 'sim15-ack-hold', 'SIM-15 Ack Hold Translator');
    // Broken source data: no job snapshot → the pipeline rejects the item
    // ("missing source_revision") BEFORE any provider call (no component
    // circuit), driving the outbox Err arm: outbox_execution_failed +
    // retry-ack — and the injected ack fault turns that retry-ack into
    // outbox_fail_ack_failed with the row left at the site.
    atsSite.addContentItem({
      objectId: 9004,
      postType: 'post',
      title: 'Sim15 broken snapshot title',
      content: '<p>Sim15 broken snapshot body.</p>',
      excerpt: 'Sim15 broken excerpt',
      omitJobSnapshot: true,
    });
    atsSite.injectFault('ack', 1, { status: 500 });

    const mark = await markLogStart(request);
    const callbacksBefore = atsSite.receivedCallbacks.length;
    const appliedBefore = atsSite.appliedCallbacks;

    await runWorkerOnce(request);
    await new Promise((resolve) => setTimeout(resolve, 2_000));

    const window1 = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T4 probe (fault run):',
      JSON.stringify({
        rejectedAcks: atsSite.rejectedAcks,
        acks: atsSite.receivedAcks,
        pendingRows: atsSite.pendingOutboxCount,
        appliedCallbacks: atsSite.appliedCallbacks,
        providerHits: provider.hits.length,
        events: window1.map((e) => `${e.level} ${e.event}`),
      }),
    );
    // The broken item failed visibly and the retry-ack was rejected.
    expect(
      eventsNamed(window1, 'discovery.outbox_execution_failed').length,
    ).toBeGreaterThanOrEqual(1);
    expect(eventsNamed(window1, 'discovery.outbox_fail_ack_failed').length).toBe(1);
    expect(atsSite.rejectedAcks).toBe(1);
    // The row stays at the site (no callback landed, ack rejected).
    expect(atsSite.pendingOutboxCount).toBe(1);
    expect(atsSite.appliedCallbacks).toBe(appliedBefore);
    expect(atsSite.receivedCallbacks.length).toBe(callbacksBefore);
    expectEventSequence(
      window1,
      [
        { event: 'discovery.outbox_execution_failed' },
        { event: 'discovery.outbox_fail_ack_failed' },
      ],
      'SIM-15 T4 retry-ack fault',
    );
    expectNoUnexpected(window1, 'SIM-15 T4 fault run', [
      'discovery.outbox_execution_failed',
      'discovery.outbox_fail_ack_failed',
    ]);

    // The site repairs the source data (snapshot injected); the client's
    // 30s re-offer hold plus the item backoff (up to 60s when both lanes
    // recorded the broken item) expire, then the row converges.
    atsSite.repairContentItem(9004);
    await new Promise((resolve) => setTimeout(resolve, 70_000));
    const mark2 = await markLogStart(request);
    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBefore,
      1,
      60_000,
    );
    const window2 = await collectLogWindow(request, mark2);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T4 probe (recovery run):',
      JSON.stringify({
        rejectedAcks: atsSite.rejectedAcks,
        acks: atsSite.receivedAcks,
        pendingRows: atsSite.pendingOutboxCount,
        appliedCallbacks: atsSite.appliedCallbacks,
        callbacks: atsSite.receivedCallbacks.map((c) => c.idempotencyKey),
        events: window2.map((e) => `${e.level} ${e.event}`),
      }),
    );
    // Converged: translated + callback applied + row completed (the 2xx
    // callback completes the row server-side — real plugin contract).
    expect(atsSite.appliedCallbacks).toBe(appliedBefore + 1);
    expect(atsSite.pendingOutboxCount).toBe(0);
    expectNoUnexpected(window2, 'SIM-15 T4 recovery run', []);
  });

  test('T5 provider 200 空输出 → 输出校验字段失败 + partial_translation 仍收敛', async ({ request }) => {
    test.setTimeout(240_000);

    await freshComponent(request, 'sim15-empty-output', 'SIM-15 Empty Output Translator');
    atsSite.addContentItem({
      objectId: 9005,
      postType: 'post',
      title: 'Sim15 empty output title',
      content: '<p>Sim15 empty output body.</p>',
      excerpt: 'Sim15 empty output excerpt',
    });
    // skip 2: title + content translate cleanly; the EMPTY 200 lands on
    // the LAST field (excerpt) — a first-field failure would arm the
    // component cooldown and block the remaining fields (T2's family).
    provider.injectFault(1, { mode: 'empty', skip: 2 });

    const mark = await markLogStart(request);
    const callbacksBefore = atsSite.receivedCallbacks.length;
    const rejectedHitsBefore = provider.rejectedHits;

    await runWorkerOnce(request);
    await waitForAtsCallbacks(
      () => atsSite.receivedCallbacks.length - callbacksBefore,
      1,
      60_000,
    );

    const window = await collectLogWindow(request, mark);
    // eslint-disable-next-line no-console
    console.log(
      'sim15 T5 probe:',
      JSON.stringify({
        rejectedHits: provider.rejectedHits,
        providerHits: provider.hits.length,
        callbacks: atsSite.receivedCallbacks.map((c) => c.idempotencyKey),
        appliedCallbacks: atsSite.appliedCallbacks,
        idempotentReplays: atsSite.idempotentReplays,
        fields: atsSite.receivedCallbacks[atsSite.receivedCallbacks.length - 1]?.payload
          .translated_fields,
        events: window.map((e) => `${e.level} ${e.event}`),
      }),
    );
    // HTTP 200 — the failure is output-validation, not transport (the
    // rejected counter is spec-cumulative, so assert the DELTA is zero).
    expect(provider.rejectedHits - rejectedHitsBefore).toBe(0);
    // Observed truth: the empty 200 surfaces as a field-level failure
    // (field_translate_failed, output-validation error) + the partial
    // translation warn — the item still completes on the healthy fields.
    expect(eventsNamed(window, 'discovery.field_translate_failed').length).toBe(1);
    expect(eventsNamed(window, 'discovery.partial_translation').length).toBe(1);
    // Partial delivery: the two healthy fields are machine-translated in
    // the callback; the empty-output field is absent (field order is
    // title → excerpt → content, so the fault lands on post_content).
    const callback = atsSite.receivedCallbacks[atsSite.receivedCallbacks.length - 1];
    const fields = (callback.payload.translated_fields ?? {}) as Record<string, string>;
    const machine = Object.values(fields).filter((v) => v.includes('【zh_CN】'));
    expect(machine.length, `partial callback fields: ${JSON.stringify(fields)}`).toBe(2);
    expect(fields.post_content).toBeUndefined();
    const done = await findDoneItemForRelation(request, RELATION_ID);
    if ('status' in done) expect(String(done.status)).toBe('done');
    expectNoUnexpected(window, 'SIM-15 T5 empty-output run', [
      'discovery.field_translate_failed',
      'discovery.partial_translation',
    ]);
  });
});
