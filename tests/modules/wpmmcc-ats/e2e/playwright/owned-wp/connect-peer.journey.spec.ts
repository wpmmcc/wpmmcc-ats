/**
 * TST-04/07, WMC-07, J-08: actual Connect Peer form → handshake → Push/Pull.
 * No peer/journal/mapping seed. Owned WP prefixes are dropped by the wrapper.
 * output: pairs-with ../comprehensive/31-wpmmcc-peer-lifecycle.spec.ts
 * covers: success|failure|boundary
 */
import { test, expect, type Page } from '@playwright/test';
import { owned, wp, scalar, session, login, dashboard, maskCredentials, type OwnedSite } from './helpers';

test.describe.configure({ mode: 'serial' });
const [source, target] = owned.sites;
const marker = `peer-owned-${owned.owner}`;

const peers = (site: OwnedSite) => scalar(site, `SELECT COUNT(*) FROM ${site.prefix}wpmmcc_peers`);
const readPeer = (site: OwnedSite, uuid: string) => JSON.parse(wp(site, `global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare("SELECT id,peer_uuid,endpoint_url,direction,sync_mode,source_lang,
  target_lang,conflict_strategy,status FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid=%s",
  ${JSON.stringify(uuid)}),ARRAY_A);if($wpdb->last_error){exit(1);}echo wp_json_encode($row);`));
const uuid = (site: OwnedSite) => wp(site, "echo get_option('wpmmcc_origin_uuid','');");

async function fillConnect(page: Page, remote: OwnedSite, code: string, direction = 'bidirectional'): Promise<void> {
  await page.locator('#wpmmcc_remote_url').fill(remote.home_url);
  await page.locator('#wpmmcc_pairing_code').fill(code);
  await page.locator('#wpmmcc_peer_name').fill(marker);
  await page.locator('#wpmmcc_direction').selectOption(direction);
  expect(await page.locator('#wpmmcc_sync_mode option').evaluateAll((options) =>
    options.map((option) => (option as HTMLOptionElement).value))).toEqual(['sync_only']);
  await page.locator('#wpmmcc_sync_mode').selectOption('sync_only');
  await page.locator('#wpmmcc_conflict_strategy').selectOption('source_wins');
  await page.locator('#wpmmcc_source_lang').fill('fr_FR');
  await page.locator('#wpmmcc_target_lang').fill('de_DE');
  expect(await page.locator('#wpmmcc_remote_url').evaluate((input) => (input as HTMLInputElement).validity.valid)).toBe(true);
}

async function connect(sourcePage: Page, targetPage: Page, direction: string): Promise<void> {
  await dashboard(targetPage, target);
  const generate = targetPage.locator('form').filter({
    has: targetPage.locator('input[name="action"][value="wpmmcc_generate_pairing_code"]'),
  });
  await Promise.all([targetPage.waitForURL('**/*pairing_generated=1*'), generate.getByRole('button').click()]);
  const code = await targetPage.locator('tr').filter({ hasText: 'Active Pairing Code' })
    .locator('input[readonly]').inputValue();
  // Assert booleans, not the secret itself, so a failure never prints the code.
  expect(/^[a-f0-9]{32}$/.test(code)).toBe(true);
  expect(wp(target, "echo get_transient('wpmmcc_active_pairing_code');") === code).toBe(true);
  await maskCredentials(targetPage);
  await dashboard(sourcePage, source);
  await fillConnect(sourcePage, target, code, direction);
  await Promise.all([sourcePage.waitForURL('**/*peer_connected=1*'),
    sourcePage.getByRole('button', { name: /Connect Peer Site/ }).click()]);
  await maskCredentials(sourcePage);
}

test('TST-07: invalid pairing code and tampered Connect nonce reject without peer rows', async ({ browser }) => {
  const ownedSession = await session(browser);
  const page = await ownedSession.context.newPage();
  try {
    await login(page, source);
    await dashboard(page, source);
    const before = [peers(source), peers(target)];
    await fillConnect(page, target, '0'.repeat(32));
    const rejection = page.waitForURL('**/*connect_error=*', { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.getByRole('button', { name: /Connect Peer Site/ }).click({ timeout: 5_000 });
    await rejection;
    expect(new URL(page.url()).searchParams.get('connect_error')).not.toBeNull();
    expect([peers(source), peers(target)]).toEqual(before);
    await dashboard(page, source);
    await fillConnect(page, target, '1'.repeat(32));
    const form = page.locator('form').filter({
      has: page.locator('input[name="action"][value="wpmmcc_connect_peer_site"]'),
    });
    await form.locator('input[name="_wpnonce"]').evaluate((input) => {
      (input as HTMLInputElement).value = 'tampered-owned-nonce';
    });
    const pending = page.waitForResponse((response) => response.url().includes('/wp-admin/admin-post.php')
      && response.request().method() === 'POST');
    await form.getByRole('button', { name: /Connect Peer Site/ }).click();
    expect((await pending).status()).toBe(403);
    expect([peers(source), peers(target)]).toEqual(before);
  } finally { await ownedSession.close(); }
});

test('TST-07: real Generate Code and Connect persist every selected option on both WP sites', async ({ browser }) => {
  const ownedSession = await session(browser);
  const sourcePage = await ownedSession.context.newPage();
  const targetPage = await ownedSession.context.newPage();
  try {
    await login(sourcePage, source);
    await login(targetPage, target);
    const before = [peers(source), peers(target)];
    await connect(sourcePage, targetPage, 'push_only');
    expect([peers(source), peers(target)]).toEqual(before.map((count) => count + 1));
    const sourceRow = readPeer(source, uuid(target));
    const targetRow = readPeer(target, uuid(source));
    for (const row of [sourceRow, targetRow]) {
      expect(row.id).toMatch(/^[1-9][0-9]*$/);
      expect(row.sync_mode).toBe('sync_only');
      expect(row.source_lang).toBe('fr_FR');
      expect(row.target_lang).toBe('de_DE');
      expect(row.conflict_strategy).toBe('source_wins');
      expect(row.status).toBe('active');
    }
    expect(sourceRow.direction).toBe('push_only');
    expect(targetRow.direction).toBe('pull_only');
    expect(sourceRow.endpoint_url).toBe(`${target.home_url}/wp-json/wpmmcc/v1`);
    expect(targetRow.endpoint_url).toBe(`${source.home_url}/wp-json/wpmmcc/v1`);
    expect(wp(target, "echo false===get_transient('wpmmcc_active_pairing_code')?'consumed':'still-active';")).toBe('consumed');
    await sourcePage.reload();
    const row = sourcePage.locator('tr').filter({ hasText: marker });
    await expect(row).toHaveCount(1);
    await expect(row.getByRole('button', { name: 'Push Now', exact: true })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Pull Now', exact: true })).toBeVisible();
  } finally { await ownedSession.close(); }
});

test('TST-07: Push/Pull produce exact posts, ack consumes CDC and Disconnect cascades mappings', async ({ browser, request }) => {
  test.setTimeout(180_000);
  const ownedSession = await session(browser);
  const page = await ownedSession.context.newPage();
  const targetPage = await ownedSession.context.newPage();
  const insert = (site: OwnedSite, slug: string, body: string) => Number(wp(site,
    `$id=wp_insert_post(array('post_type'=>'post','post_status'=>'publish','post_title'=>${JSON.stringify(slug)},
    'post_name'=>${JSON.stringify(slug)},'post_content'=>${JSON.stringify(body)},'post_excerpt'=>'Owned peer excerpt'),true);
    if(is_wp_error($id)){exit(1);}echo $id;`));
  const post = async (site: OwnedSite, slug: string, body: string) => {
    const response = await request.get(`${site.base_url}/wp-json/wp/v2/posts?slug=${slug}`);
    expect(response.status()).toBe(200);
    const posts = await response.json();
    expect(posts).toHaveLength(1);
    expect(posts[0].title.rendered).toBe(slug);
    expect(posts[0].content.rendered).toContain(body);
    expect(posts[0].excerpt.rendered).toContain('Owned peer excerpt');
    return Number(posts[0].id);
  };
  try {
    const sourceUuid = uuid(source);
    const targetUuid = uuid(target);
    const pushedId = insert(source, marker, '<p>Owned peer push body</p>');
    expect(pushedId).toBeGreaterThan(0);
    const pendingSql = `SELECT COUNT(*) FROM ${source.prefix}wpmmcc_journal WHERE consumed=0 AND peer_uuid='${targetUuid}'`;
    expect(scalar(source, pendingSql)).toBeGreaterThan(0);
    await login(page, source);
    await login(targetPage, target);
    // A second real handshake updates the existing peer, without DB seeding.
    const peerCounts = [peers(source), peers(target)];
    await connect(page, targetPage, 'bidirectional');
    expect([peers(source), peers(target)]).toEqual(peerCounts);
    expect(readPeer(source, targetUuid).direction).toBe('bidirectional');
    expect(readPeer(target, sourceUuid).direction).toBe('bidirectional');
    const row = page.locator('tr').filter({ hasText: marker });
    await Promise.all([page.waitForURL('**/*push_done=1*'),
      row.getByRole('button', { name: 'Push Now', exact: true }).click()]);
    const targetId = await post(target, marker, 'Owned peer push body');
    expect(targetId).toBeGreaterThan(0);
    expect(scalar(source, pendingSql)).toBe(0);
    const pullMarker = marker + '-pull';
    expect(insert(target, pullMarker, '<p>Owned peer pull body</p>')).toBeGreaterThan(0);
    await dashboard(page, source);
    await Promise.all([page.waitForURL('**/*pull_done=1*'),
      page.locator('tr').filter({ hasText: marker }).getByRole('button', { name: 'Pull Now', exact: true }).click()]);
    expect(await post(source, pullMarker, 'Owned peer pull body')).toBeGreaterThan(0);
    const mapsSql = `SELECT COUNT(*) FROM ${source.prefix}wpmmcc_cross_mappings WHERE peer_uuid='${targetUuid}'`;
    expect(scalar(source, mapsSql)).toBeGreaterThan(0);
    const selfSql = `SELECT COUNT(*) FROM ${source.prefix}wpmmcc_cross_mappings WHERE peer_uuid=''`;
    const selfBefore = scalar(source, selfSql);
    expect(selfBefore).toBeGreaterThan(0);
    await dashboard(page, source);
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForURL('**/*peer_deleted=1*'),
      page.locator('tr').filter({ hasText: marker }).getByRole('button', { name: 'Disconnect', exact: true }).click()]);
    expect(readPeer(source, targetUuid)).toBeNull();
    expect(scalar(source, mapsSql)).toBe(0);
    expect(scalar(source, selfSql)).toBe(selfBefore);
    expect(scalar(source, `SELECT COUNT(*) FROM ${source.prefix}wpmmcc_journal WHERE event_type='peer_disconnect'
      AND consumed=1 AND peer_uuid='${targetUuid}'`)).toBe(1);
    expect(sourceUuid).not.toBe(targetUuid);
  } finally { await ownedSession.close(); }
});
