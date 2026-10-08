/**
 * Client WebUI API helpers for the SIM lane specs.
 *
 * Thin typed wrappers around the client's local-mode REST surface, plus
 * polling helpers. All calls go over real HTTP to the lane-owned client.
 */
import { expect, type APIRequestContext, type Page } from '@playwright/test';

export const CLIENT_BASE =
  process.env.WPTSALL_SIMULATION_BASE_URL ||
  process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL ||
  'http://127.0.0.1:8977';

export interface ApiResult<T = Record<string, unknown>> {
  ok: boolean
  status: number
  success: boolean
  data: T
  errorCode?: string
  errorMessage?: string
  raw: Record<string, unknown>
}

async function call(
  request: APIRequestContext,
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  path: string,
  body?: unknown,
): Promise<ApiResult> {
  const res = await request.fetch(`${CLIENT_BASE}${path}`, {
    method,
    data: body === undefined ? undefined : JSON.stringify(body),
    headers: body === undefined ? undefined : { 'Content-Type': 'application/json' },
  });
  const raw = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  const error = (raw.error ?? {}) as Record<string, unknown>;
  return {
    ok: res.ok(),
    status: res.status(),
    success: raw.success === true,
    data: (raw.data ?? {}) as Record<string, unknown>,
    errorCode: error.code == null ? undefined : String(error.code),
    errorMessage: error.message == null ? undefined : String(error.message),
    raw,
  };
}

export function apiGet(request: APIRequestContext, path: string) {
  return call(request, 'GET', path);
}

export function apiPost(request: APIRequestContext, path: string, body?: unknown) {
  return call(request, 'POST', path, body ?? {});
}

export function apiPut(request: APIRequestContext, path: string, body: unknown) {
  return call(request, 'PUT', path, body);
}

export function apiDelete(request: APIRequestContext, path: string, body?: unknown) {
  return call(request, 'DELETE', path, body);
}

// -- status / identity -------------------------------------------------------

export async function getStatusData(request: APIRequestContext) {
  const res = await apiGet(request, '/api/status');
  expect(res.ok, `client /api/status HTTP ${res.status}`).toBe(true);
  return res.data;
}

export async function bindSite(
  request: APIRequestContext,
  site: { api_base_url: string; wp_client_token: string; route_secret: string },
) {
  return apiPost(request, '/api/domain-tokens/upsert', site);
}

export async function verifySiteIdentity(
  request: APIRequestContext,
  apiBaseUrl: string,
) {
  return apiPost(request, '/api/domain-tokens/test', { api_base_url: apiBaseUrl });
}

/** Remove a binding so later specs never probe a stopped mock site. */
export async function unbindSite(request: APIRequestContext, apiBaseUrl: string) {
  return apiPost(request, '/api/domain-tokens/delete', { api_base_url: apiBaseUrl });
}

export async function listBindings(request: APIRequestContext) {
  const status = await getStatusData(request);
  return (status.domain_token_bindings ?? []) as Array<{
    api_base_url?: string
    plugin_identity?: string | null
    identity_verified_at?: string | null
    token_prefix?: string
    route_secret_set?: boolean
  }>;
}

// -- sync pairs / pairing ----------------------------------------------------

export interface SyncPair {
  id: string
  name?: string
  source_domain: string
  target_domain: string
  sync_mode: string
  source_lang?: string
  target_lang?: string
  status: string
  last_sync_at?: number | null
  last_sync_count?: number | null
  last_error?: string | null
  translate_component_id?: string | null
  last_seen_source_id?: number | null
}

export async function listSyncPairs(request: APIRequestContext): Promise<{
  pairs: SyncPair[]
  credentials: Array<Record<string, unknown>>
  clientOriginUuid: string
}> {
  const res = await apiGet(request, '/api/sync-pairs');
  expect(res.ok, `GET /api/sync-pairs HTTP ${res.status}`).toBe(true);
  return {
    pairs: (res.data.pairs ?? []) as SyncPair[],
    credentials: (res.data.credentials ?? []) as Array<Record<string, unknown>>,
    clientOriginUuid: String(res.data.client_origin_uuid ?? ''),
  };
}

export async function createSyncPair(
  request: APIRequestContext,
  pair: {
    name?: string
    source_domain: string
    target_domain: string
    direction?: string
    sync_mode: string
    source_lang?: string
    target_lang?: string
    conflict_strategy?: string
    sync_frequency?: string
    post_types?: string[]
    translate_component_id?: string
  },
) {
  return apiPost(request, '/api/sync-pairs', pair);
}

export async function pairSite(
  request: APIRequestContext,
  body: { domain: string; pairing_code: string; role: 'source' | 'target' },
) {
  return apiPost(request, '/api/sync-pairs/pair', body);
}

export async function runSyncPair(request: APIRequestContext, pairId: string) {
  return apiPost(request, `/api/sync-pairs/${encodeURIComponent(pairId)}/run`);
}

export async function waitForPairSynced(
  request: APIRequestContext,
  pairId: string,
  expectCount: number,
  timeoutMs = 60_000,
): Promise<SyncPair> {
  let last: SyncPair | undefined;
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const { pairs } = await listSyncPairs(request);
    last = pairs.find((p) => p.id === pairId);
    if (last && Number(last.last_sync_count ?? 0) >= expectCount && !last.last_error) {
      return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  throw new Error(
    `sync pair ${pairId} did not reach count ${expectCount} within ${timeoutMs}ms; last state: ${JSON.stringify(last)}`,
  );
}

// -- worker / review pipeline ------------------------------------------------

export async function getReviewMode(request: APIRequestContext): Promise<boolean> {
  const res = await apiGet(request, '/api/worker/config');
  expect(res.ok, `GET /api/worker/config HTTP ${res.status}`).toBe(true);
  return Boolean((res.data as Record<string, unknown>).review_mode);
}

export async function setReviewMode(
  request: APIRequestContext,
  reviewMode: boolean,
): Promise<void> {
  const res = await apiPost(request, '/api/worker/config', { review_mode: reviewMode });
  expect(res.success, `POST /api/worker/config: ${JSON.stringify(res.raw)}`).toBe(true);
}

export async function bootstrapDiscoveryTasks(request: APIRequestContext) {
  return apiPost(request, '/api/discovery-tasks/bootstrap', {});
}

export interface DiscoveryTask {
  id: number
  domain?: string
  relation_id?: number
  enabled?: boolean
}

export async function listDiscoveryTasks(request: APIRequestContext): Promise<DiscoveryTask[]> {
  const res = await apiGet(request, '/api/discovery-tasks');
  expect(res.ok, `GET /api/discovery-tasks HTTP ${res.status}`).toBe(true);
  return (res.data.items ?? []) as DiscoveryTask[];
}

export async function enableDiscoveryTask(
  request: APIRequestContext,
  taskId: number,
  componentId: string,
) {
  return apiPut(request, `/api/discovery-tasks/${taskId}`, {
    concurrency: 1,
    batch_parallel: 1,
    per_page: 50,
    retry_max: 2,
    timeout_secs: 60,
    enabled: true,
    include_resync: false,
    selected_component_id: componentId,
  });
}

export async function listLocalComponents(
  request: APIRequestContext,
): Promise<Array<{ id: string; enabled?: boolean }>> {
  const res = await apiGet(request, '/api/components/local?per_page=500');
  expect(res.ok, `GET /api/components/local HTTP ${res.status}`).toBe(true);
  return (res.data.items ?? []) as Array<{ id: string; enabled?: boolean }>;
}

/**
 * Disable a local component through the real registry API (the loader
 * skips disabled components entirely — `local_runtime_skipped_disabled`).
 * Refuses (409 COMPONENT_IN_USE) only when a task-type/rule BINDING
 * references it; discovery-task pins are not bindings.
 */
export async function disableLocalComponent(
  request: APIRequestContext,
  componentId: string,
) {
  return apiPut(request, `/api/components/local/${encodeURIComponent(componentId)}`, {
    enabled: false,
  });
}

export async function createLocalComponent(
  request: APIRequestContext,
  component: {
    id: string
    name: string
    kind: string
    templateJson: Record<string, unknown>
  },
) {
  return apiPost(request, '/api/components/local', {
    id: component.id,
    name: component.name,
    kind: component.kind,
    enabled: true,
    template_json: component.templateJson,
  });
}

/**
 * Store a local component's auth values through the real bindings upsert
 * (the same route the Components page uses for its auth-values modal).
 * Without this, a component whose template declares required auth fields
 * cannot run — the runtime rejects it with INVALID_COMPONENT_ID.
 */
export function saveComponentAuth(
  request: APIRequestContext,
  componentId: string,
  auth: Record<string, string>,
) {
  return apiPost(request, '/api/components/bindings/upsert', {
    component_id: componentId,
    auth,
  });
}

export async function runWorkerOnce(request: APIRequestContext) {
  const res = await apiPost(request, '/api/worker/run-once', {});
  expect(res.success, `worker run-once: ${JSON.stringify(res.raw)}`).toBe(true);
  return res.data as Record<string, unknown>;
}

export interface TranslationItem {
  id: number
  job_id?: number
  status?: string
  relation_id?: number
}

export async function listJobs(request: APIRequestContext) {
  const res = await apiGet(request, '/api/jobs');
  expect(res.ok, `GET /api/jobs HTTP ${res.status}`).toBe(true);
  const data = res.data as Record<string, unknown>;
  return (data.items ?? []) as Array<Record<string, unknown>>;
}

export async function findPendingReviewItem(
  request: APIRequestContext,
  relationId: number,
): Promise<TranslationItem> {
  const jobs = await listJobs(request);
  // Multiple jobs can exist for one relation (relation-scan job + the
  // outbox fast-path job); search every candidate for the review item.
  const candidates = jobs.filter((j) => Number(j.relation_id ?? 0) === relationId);
  expect(
    candidates.length,
    `no client job for relation ${relationId}: ${JSON.stringify(jobs)}`,
  ).toBeGreaterThan(0);
  for (const job of candidates) {
    const res = await apiGet(
      request,
      `/api/jobs/${job.id}/items?status=pending_review&limit=20`,
    );
    if (!res.ok) continue;
    const items = (res.data.items ?? []) as TranslationItem[];
    const item = items.find((i) => Number(i.relation_id ?? 0) === relationId) ?? items[0];
    if (item) return item;
  }
  // Diagnostics: dump every item state for every candidate job so the
  // failure message shows where the pipeline actually stopped.
  const dump: Record<string, unknown> = {};
  for (const job of candidates) {
    const all = await apiGet(request, `/api/jobs/${job.id}/items?limit=50`);
    dump[`job_${job.id}`] = (all.data as Record<string, unknown>).items ?? [];
  }
  throw new Error(
    `no pending_review item for relation ${relationId}; all job items: ${JSON.stringify(dump)}`,
  );
}

export async function getItemContent(request: APIRequestContext, itemId: number) {
  const res = await apiGet(request, `/api/items/${itemId}/content`);
  expect(res.ok, `GET item content HTTP ${res.status}`).toBe(true);
  return res.data as Record<string, unknown>;
}

export async function saveTranslatedContent(
  request: APIRequestContext,
  itemId: number,
  content: Record<string, string>,
) {
  return apiPut(request, `/api/items/${itemId}/translated`, { content });
}

export async function approveItem(request: APIRequestContext, itemId: number) {
  return apiPost(request, `/api/items/${itemId}/approve`, {});
}

/** Wait until the mock ATS site has received at least N translation callbacks. */
export async function waitForAtsCallbacks(
  getCount: () => number,
  minCount: number,
  timeoutMs = 90_000,
): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (getCount() >= minCount) return;
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(
    `expected ≥${minCount} ATS translation callbacks within ${timeoutMs}ms; got ${getCount()}`,
  );
}

export async function findDoneItemForRelation(
  request: APIRequestContext,
  relationId: number,
): Promise<TranslationItem | { jobId: number; doneItems: number }> {
  const jobs = await listJobs(request);
  const candidates = jobs.filter((j) => Number(j.relation_id ?? 0) === relationId);
  for (const job of candidates) {
    const res = await apiGet(request, `/api/jobs/${job.id}/items?limit=50`);
    if (res.ok) {
      const items = (res.data.items ?? []) as TranslationItem[];
      const done = items.find((i) => String(i.status) === 'done');
      if (done) return done;
    }
    // Outbox fast-path may finalize the job with done_items without leaving
    // a listable item row in every storage mode — accept job progress.
    const doneItems = Number(job.done_items ?? 0);
    if (doneItems > 0 && String(job.status) === 'completed') {
      return { jobId: Number(job.id), doneItems };
    }
  }
  throw new Error(
    `no done progress for relation ${relationId}; jobs=${JSON.stringify(candidates.slice(0, 5))}`,
  );
}

// -- UI navigation -----------------------------------------------------------

export async function gotoNav(page: Page, name: RegExp) {
  await page.goto(CLIENT_BASE);
  await page.getByRole('button', { name }).first().click();
}

/** Bind a site through the real Sites modal UI and click Test. */
export async function bindSiteViaUi(
  page: Page,
  site: { baseUrl: string; wpClientToken: string; routeSecret: string },
): Promise<void> {
  await gotoNav(page, /站点|Sites/);
  await page.getByTestId('sites-add-site').click();
  await page.getByTestId('sites-modal-url').fill(site.baseUrl);
  await page.getByTestId('sites-modal-token').fill(site.wpClientToken);
  await page.getByTestId('sites-modal-route-secret').fill(site.routeSecret);
  await page.getByTestId('sites-modal-save').click();
  await expect(page.getByText(site.baseUrl).first()).toBeVisible({ timeout: 20_000 });
  // Prefer the Test button on the matching row when multiple sites exist.
  const row = page.locator('tr').filter({ hasText: site.baseUrl }).first();
  const testBtn = row.getByTestId('sites-test-connection');
  if (await testBtn.count()) {
    await testBtn.click();
  } else {
    await page.getByTestId('sites-test-connection').first().click();
  }
}
