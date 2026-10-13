import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import fs from 'node:fs';
import http from 'node:http';
import { type AddressInfo } from 'node:net';
import { execFileSync } from 'node:child_process';

export type OwnedSite = {
  name: string; prefix: string; base_url: string; home_url: string;
  admin_user: string; admin_pass: string; wp_client_token: string; route_secret: string;
};
const path = process.env.WPTSALL_OWNED_WP_CONTEXT;
if (!path) throw new Error('owned WordPress fixture required, never fall back to shared Lab');
export const owned = JSON.parse(fs.readFileSync(path, 'utf8')) as { owner: string; sites: OwnedSite[] };
expect(owned.owner).toMatch(/^[a-f0-9]{16}$/);
expect(owned.sites).toHaveLength(2);
expect(new Set(owned.sites.map((site) => site.name)).size).toBe(2);
for (const site of owned.sites) {
  expect(site.name).toMatch(new RegExp(`^wptsall-owned-${owned.owner}-[ab]$`));
  expect(site.prefix).toBe(`owned${owned.owner}${site.name.slice(-1)}_`);
  expect(site.home_url).toBe(`http://${site.name}.test`);
  const info = JSON.parse(execFileSync('docker', ['inspect', site.name],
    { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }))[0];
  expect(info.Config.Labels['com.wptsall.owned.run']).toBe(owned.owner);
  const port = info.NetworkSettings.Ports['80/tcp'][0].HostPort;
  expect(site.base_url).toBe(`http://127.0.0.1:${port}`);
}

export function wp(site: OwnedSite, php: string): string {
  expect(site.name).toMatch(new RegExp(`^wptsall-owned-${owned.owner}-[ab]$`));
  expect(site.prefix).toBe(`owned${owned.owner}${site.name.slice(-1)}_`);
  const label = execFileSync('docker', ['inspect', '-f',
    '{{index .Config.Labels "com.wptsall.owned.run"}}', site.name],
  { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }).trim();
  expect(label).toBe(owned.owner);
  return execFileSync('docker', ['exec', '-i', site.name, 'wp', '--allow-root', '--path=/var/www/html',
    'eval', "eval('?>'.stream_get_contents(STDIN));"], {
    input: `<?php\nif($GLOBALS['wpdb']->prefix !== ${JSON.stringify(site.prefix)}){exit(1);}\n${php}`,
    encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30_000,
  }).trim();
}

export function scalar(site: OwnedSite, sql: string): number {
  const value = wp(site, `global $wpdb;$value=$wpdb->get_var(${JSON.stringify(sql)});
    if($wpdb->last_error || null===$value){exit(1);}echo $value;`);
  if (!/^[0-9]+$/.test(value)) throw new Error('owned SQL scalar missing or malformed');
  return Number(value);
}

/** Browser-only forward proxy for the two owned container DNS origins. */
export async function session(browser: Browser): Promise<{ context: BrowserContext; close: () => Promise<void> }> {
  const routes = new Map(owned.sites.map((site) => [new URL(site.home_url).host, new URL(site.base_url)]));
  const proxy = http.createServer((request, response) => {
    let url: URL;
    try { url = new URL(request.url ?? '', `http://${request.headers.host}`); }
    catch { response.writeHead(400); response.end(); return; }
    const backend = routes.get(url.host);
    if (!backend || url.protocol !== 'http:') { response.writeHead(502); response.end(); return; }
    const upstream = http.request({
      hostname: backend.hostname, port: backend.port, method: request.method,
      path: url.pathname + url.search, headers: { ...request.headers, host: url.host },
    }, (result) => {
      response.writeHead(result.statusCode ?? 502, result.headers);
      result.pipe(response);
    });
    upstream.on('error', () => response.destroy());
    request.pipe(upstream);
  });
  await new Promise<void>((resolve) => proxy.listen(0, '127.0.0.1', resolve));
  let context: BrowserContext;
  try {
    context = await browser.newContext({
      proxy: { server: `http://127.0.0.1:${(proxy.address() as AddressInfo).port}` },
      viewport: { width: 1280, height: 800 },
    });
  } catch (error) {
    proxy.closeAllConnections();
    await new Promise<void>((resolve) => proxy.close(() => resolve()));
    throw error;
  }
  return { context, close: async () => {
    try { await context.close(); }
    catch (error) {
      // A test timeout may already have closed the context. Preserve its
      // primary stack instead of replacing it with a cleanup-only error.
      if (!/has been closed|Test ended/.test(String(error))) throw error;
    }
    finally {
      proxy.closeAllConnections();
      await new Promise<void>((resolve, reject) => proxy.close((error) => error ? reject(error) : resolve()));
    }
  } };
}

export async function login(page: Page, site: OwnedSite): Promise<void> {
  await page.goto(`${site.home_url}/wp-login.php?redirect_to=${encodeURIComponent(site.home_url + '/wp-admin/')}`);
  try {
    // Core's delayed username focus can interrupt keyboard filling. Verify
    // both fields before submitting, and compare booleans to keep values private.
    await expect.poll(async () => {
      await page.locator('#user_login').fill(site.admin_user);
      await page.locator('#user_pass').fill(site.admin_pass);
      return await page.locator('#user_login').inputValue() === site.admin_user
        && await page.locator('#user_pass').inputValue() === site.admin_pass;
    }, { message: 'owned login fields must match before submit' }).toBe(true);
    await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
    await expect(page.locator('#wpadminbar')).toBeVisible();
  } catch (error) {
    await page.locator('#user_login, #user_pass').evaluateAll((fields) => {
      for (const field of fields) {
        (field as HTMLInputElement).value = '';
        field.removeAttribute('value');
      }
    }).catch(() => {});
    throw error;
  }
}

export async function dashboard(page: Page, site: OwnedSite): Promise<void> {
  await page.goto(`${site.home_url}/wp-admin/admin.php?page=wpmmcc-sync`);
  await expect(page.locator('input[name="action"][value="wpmmcc_connect_peer_site"]')).toHaveCount(1);
  await maskCredentials(page);
}

export async function maskCredentials(page: Page): Promise<void> {
  // Owned test credentials need not appear in automatic failure snapshots.
  // This changes only the browser DOM, never WP options or submitted values.
  await page.locator('div.card').filter({ hasText: 'Site Identity & Credentials' }).locator('table').evaluate((table) => {
    const values = table.querySelectorAll('input[readonly], textarea');
    values.forEach((value) => {
      (value as HTMLInputElement).value = '[owned credential withheld]';
      value.setAttribute('value', '[owned credential withheld]');
      value.textContent = '[owned credential withheld]';
    });
  });
}
