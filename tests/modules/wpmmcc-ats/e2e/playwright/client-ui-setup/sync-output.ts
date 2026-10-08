/** Shared deep output leg: UI bind/pair/save/run, then an independent target oracle. */
import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { CLIENT_BASE, fillSitesManualAndTest, type SiteFixture } from './helpers';
import { listSyncPairs } from '../simulation/lib/sim-client';

export async function runDeepSyncOutput(
  page: Page,
  request: APIRequestContext,
  fixture: {
    source: SiteFixture;
    target: SiteFixture;
    name: string;
    pairingCode: (role: 'source' | 'target') => Promise<string>;
    assertTarget: () => Promise<number>;
  },
): Promise<{ pair_id: string; target_id: number }> {
  for (const site of [fixture.source, fixture.target]) await fillSitesManualAndTest(page, site);
  await page.goto(CLIENT_BASE);
  await page.getByRole('button', { name: /任务|Tasks/ }).first().click();
  await page.getByRole('button', { name: /跨站同步|Sync Pairs/ }).click();
  await page.getByRole('button', { name: /配对管理|Peer Pairing/ }).click();
  const dialog = page.getByRole('dialog');
  for (const [site, role] of [[fixture.source, 'source'], [fixture.target, 'target']] as const) {
    await page.locator('#pairing-domain').selectOption(site.api_base_url);
    await page.locator('#pairing-role').selectOption(role);
    await page.locator('#pairing-code').fill(await fixture.pairingCode(role));
    const pending = page.waitForResponse((response) => response.url().endsWith('/api/sync-pairs/pair')
      && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: /立即配对|Pair Now/ }).click();
    const response = await pending;
    expect(response.status()).toBe(200);
    expect((await response.json()).success).toBe(true);
    const credentials = (await listSyncPairs(request)).credentials;
    expect(credentials.some((credential) => credential.domain === site.api_base_url)).toBe(true);
  }
  await dialog.getByRole('button', { name: /关闭|Close/ }).click();
  await page.getByRole('button', { name: /新建同步对|Create Sync Pair/ }).first().click();
  await page.locator('#sync-pair-name').fill(fixture.name);
  await page.locator('#sync-pair-source').selectOption(fixture.source.api_base_url);
  await page.locator('#sync-pair-target').selectOption(fixture.target.api_base_url);
  await page.locator('#sync-pair-mode').selectOption('sync_only');
  await page.getByRole('button', { name: /保存|Save/ }).last().click();
  await expect(page.getByText(/同步对保存成功|saved successfully/).first()).toBeVisible();
  const saved = (await listSyncPairs(request)).pairs.find((pair) => pair.name === fixture.name);
  expect(saved).toBeDefined();
  expect(saved?.source_domain).toBe(fixture.source.api_base_url);
  expect(saved?.target_domain).toBe(fixture.target.api_base_url);
  expect(saved?.sync_mode).toBe('sync_only');
  const row = page.locator('div.bg-white.rounded-xl').filter({ hasText: fixture.name }).filter({
    has: page.getByRole('button', { name: /立即同步|Sync Now/ }),
  });
  const triggered = page.waitForResponse((response) => response.url().includes(`/api/sync-pairs/${saved!.id}/run`)
    && response.request().method() === 'POST');
  await row.getByRole('button', { name: /立即同步|Sync Now/ }).click();
  expect((await triggered).status()).toBe(200);
  let targetId = 0;
  await expect.poll(async () => {
    const current = (await listSyncPairs(request)).pairs.find((pair) => pair.id === saved!.id);
    if (current?.last_error) throw new Error('deep sync output failed; inspect pair outcome (secrets withheld)');
    targetId = await fixture.assertTarget();
    return targetId;
  }, { timeout: 90_000 }).toBeGreaterThan(0);
  await expect.poll(async () => {
    const current = (await listSyncPairs(request)).pairs.find((pair) => pair.id === saved!.id);
    return current?.last_error ? -1 : Number(current?.last_sync_count ?? 0);
  }, { timeout: 90_000 }).toBeGreaterThan(0);
  const finished = (await listSyncPairs(request)).pairs.find((pair) => pair.id === saved!.id);
  expect(Number(finished?.last_sync_count ?? 0)).toBeGreaterThan(0);
  expect(finished?.last_error ?? null).toBeNull();
  return { pair_id: saved!.id, target_id: targetId };
}
