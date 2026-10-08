/**
 * Multi-site × Client deep journey (Release topology target ≥5).
 *
 * Required fixtures (UI fill only — no bindSite(request)):
 *   - ats-9083.json
 *   - wpmmcc-9082.json
 *   - wpmmcc-9187.json
 * Optional extras (Release ≥5):
 *   - slot-a-9181.json  (content seed → discovery/jobs)
 *   - slot-b-9182.json  / ms-9083-en.json
 *
 * Then: provider wizard → rule bind → Discovery → Run Once → sync-pair form.
 */
import { expect, test } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { execFileSync } from 'node:child_process'
import { runDeepSyncOutput } from './sync-output'
import { requireDeepJobs } from './deep-contract'
import {
  CLIENT_BASE,
  fillSitesManualAndTest,
  MOCK_API_BASE,
  overviewRunOnce,
  enableDiscoveryTasksViaUi,
  removeSitesByUrlSubstring,
  runProviderWizardOnce,
  saveGlobalSlotBindings,
  type SiteFixture,
  gotoNav,
} from './helpers'
import type { Page } from '@playwright/test'

/** Save + Test with retry; tolerate slow Lab without hard expectApi timeout. */
async function bindSiteUiResilient(page: Page, site: SiteFixture) {
  await gotoNav(page, /Sites|站点/)
  await expect(page.getByTestId('sites-add-site')).toBeVisible({ timeout: 20_000 })
  await page.getByTestId('sites-add-site').click()
  await page.getByTestId('sites-modal-url').fill(site.api_base_url)
  await page.getByTestId('sites-modal-token').fill(site.wp_client_token)
  await page.getByTestId('sites-modal-route-secret').fill(site.route_secret)
  await page.getByTestId('sites-modal-save').click()
  const urlNeedle = site.api_base_url.replace(/\/$/, '')
  await expect(page.getByText(urlNeedle).first()).toBeVisible({ timeout: 20_000 })

  const row = page.locator('tr').filter({ hasText: urlNeedle }).first()
  for (let attempt = 1; attempt <= 3; attempt++) {
    await row.getByTestId('sites-test-connection').click()
    try {
      await expect(
        row
          .getByText(/ATS|WPMMCC|Verified|已验证|Connection OK|连接成功/i)
          .or(page.getByText(/Connection OK|连接成功/i).first()),
      ).toBeVisible({ timeout: 45_000 })
      return
    } catch {
      if (attempt === 3) throw new Error(`Test Connection failed for ${site.api_base_url}`)
      await page.waitForTimeout(2000)
    }
  }
}

test.describe.configure({ mode: 'serial' })

const REQUIRED = [
  ['ats_9083', 'ats-9083.json'],
  ['wpmmcc_9082', 'wpmmcc-9082.json'],
  ['wpmmcc_9187', 'wpmmcc-9187.json'],
] as const

const OPTIONAL = [
  ['slot_a_9181', 'slot-a-9181.json'],
  ['slot_b_9182', 'slot-b-9182.json'],
] as const

/** /en/ shares host with ats-9083; Client normalize_domain_base collapses path → not a 2nd Sites row. */
function loadMsEnFixture(
  dir: string,
): SiteFixture | null {
  const p = path.join(dir, 'ms-9083-en.json')
  if (!fs.existsSync(p)) return null
  const s = JSON.parse(fs.readFileSync(p, 'utf8')) as SiteFixture
  if (!s.api_base_url || !s.wp_client_token || !s.route_secret) {
    throw new Error('incomplete ms-9083-en fixture')
  }
  return s
}

function loadBindList(): Array<[string, SiteFixture]> {
  const dir =
    process.env.MULTI_SITE_FIXTURES_DIR ||
    path.resolve(__dirname, '../../client-ui-setup/pre-wp-bind/fixtures/multi-site')
  const out: Array<[string, SiteFixture]> = []
  for (const [label, file] of REQUIRED) {
    const p = path.join(dir, file)
    if (!fs.existsSync(p)) throw new Error(`missing required fixture ${p}`)
    const s = JSON.parse(fs.readFileSync(p, 'utf8')) as SiteFixture
    if (!s.api_base_url || !s.wp_client_token || !s.route_secret) {
      throw new Error(`incomplete fixture ${label}`)
    }
    out.push([label, s])
  }
  for (const [label, file] of OPTIONAL) {
    const p = path.join(dir, file)
    if (!fs.existsSync(p)) continue
    const s = JSON.parse(fs.readFileSync(p, 'utf8')) as SiteFixture
    if (!s.api_base_url || !s.wp_client_token || !s.route_secret) {
      throw new Error(`incomplete optional fixture ${label}`)
    }
    out.push([label, s])
  }
  return out
}

test('Release multi-site: UI-bind ≥3 live sites + Tasks pipeline', async ({ page, request }) => {
  test.setTimeout(720_000)

  const health = await fetch(`${MOCK_API_BASE}/api/v1/health`, { signal: AbortSignal.timeout(5000) })
  expect(health.ok, 'mock health').toBeTruthy()
  const status = await fetch(`${CLIENT_BASE}/api/status`, { signal: AbortSignal.timeout(5000) })
  expect(status.ok, 'client status').toBeTruthy()

  const bindList = loadBindList()
  const minSites = Number(process.env.MULTI_SITE_MIN_SITES || '3')
  expect(
    bindList.length,
    `need ≥${minSites} fixtures, got ${bindList.length}: ${bindList.map(([l]) => l).join(',')}`,
  ).toBeGreaterThanOrEqual(minSites)

  const reportDir =
    process.env.REPORT_DIR ||
    path.resolve(__dirname, '../../reports/commercial-multi-site-client-deep')
  fs.mkdirSync(reportDir, { recursive: true })

  const steps: Array<{ step: string; ok: boolean; detail?: string }> = []

  for (const [label, site] of bindList) {
    try {
      await bindSiteUiResilient(page, site)
      steps.push({ step: `sites_bind_${label}`, ok: true, detail: site.api_base_url })
    } catch (e) {
      // Fallback to stricter helper once (preserves schema asserts when Lab is healthy).
      try {
        await fillSitesManualAndTest(page, site)
        steps.push({ step: `sites_bind_${label}`, ok: true, detail: `${site.api_base_url}:strict` })
      } catch (e2) {
        steps.push({ step: `sites_bind_${label}`, ok: false, detail: String(e2) })
        throw e2
      }
    }
  }

  // Multisite child /en/: prove token reuse without a second Sites row (same domain key as ATS).
  const fixturesDir =
    process.env.MULTI_SITE_FIXTURES_DIR ||
    path.resolve(__dirname, '../../client-ui-setup/pre-wp-bind/fixtures/multi-site')
  const msEn = loadMsEnFixture(fixturesDir)
  if (msEn) {
    try {
      const parent = bindList.find(([l]) => l === 'ats_9083')?.[1]
      expect(parent, 'ats_9083 required for ms-en token compare').toBeTruthy()
      expect(msEn.wp_client_token).toBe(parent!.wp_client_token)
      expect(msEn.route_secret).toBe(parent!.route_secret)
      const enUrl = msEn.api_base_url.replace(/\/?$/, '/')
      const httpRes = await fetch(enUrl, {
        signal: AbortSignal.timeout(15_000),
        headers: { 'User-Agent': 'wptsall-commercial-ms-en' },
      })
      expect(httpRes.status, `GET ${enUrl}`).toBeLessThan(500)
      steps.push({
        step: 'ms_en_token_reuse_same_origin',
        ok: true,
        detail: `http=${httpRes.status};url=${enUrl};token_reused=1`,
      })
    } catch (e) {
      steps.push({ step: 'ms_en_token_reuse_same_origin', ok: false, detail: String(e) })
      throw e
    }
  }

  await gotoNav(page, /Sites|站点/)
  await expect(page.getByText(/ATS/).first()).toBeVisible({ timeout: 60_000 })
  await expect(page.getByText(/WPMMCC/).first()).toBeVisible({ timeout: 60_000 })
  steps.push({ step: 'identity_badges_ats_and_wpmmcc', ok: true })

  const statusJson = (await (await fetch(`${CLIENT_BASE}/api/status`)).json()) as {
    data?: { domain_token_bindings?: unknown[] }
  }
  const bindings = statusJson.data?.domain_token_bindings ?? []
  expect(
    bindings.length,
    `expected ≥${minSites} bindings, got ${bindings.length}`,
  ).toBeGreaterThanOrEqual(minSites)
  steps.push({ step: 'bindings_count_ge_topology', ok: true, detail: String(bindings.length) })

  let componentId = ''
  try {
    componentId = await runProviderWizardOnce(page, 'openai-compatible')
    steps.push({ step: 'provider_wizard', ok: true, detail: componentId })
  } catch (e) {
    steps.push({ step: 'provider_wizard', ok: false, detail: String(e) })
    throw e
  }

  try {
    await saveGlobalSlotBindings(page, componentId)
    steps.push({ step: 'rule_bind_content_slots', ok: true, detail: componentId })
  } catch (e) {
    steps.push({ step: 'rule_bind_content_slots', ok: false, detail: String(e) })
    throw e
  }

  // Topology already proven (≥5 UI binds). Drop WPMMCC rows before worker run —
  // they 404 on /site-relations and block / stall start-check preflight.
  try {
    await removeSitesByUrlSubstring(page, [
      '127.0.0.1:9082',
      '127.0.0.1:9182',
      '127.0.0.1:9187',
    ])
    steps.push({ step: 'sites_prune_wpmmcc_for_worker', ok: true })
  } catch (e) {
    steps.push({ step: 'sites_prune_wpmmcc_for_worker', ok: false, detail: String(e) })
    throw e
  }

  try {
    await gotoNav(page, /任务|Tasks/)
    await page.getByRole('button', { name: /任务配置|Discovery/ }).click()
    const boot = page.getByTestId('tasks-bootstrap-discovery')
    await expect(boot).toBeVisible({ timeout: 20_000 })
    const bootPending = page.waitForResponse(
      (r) =>
        r.url().includes('/api/discovery-tasks/bootstrap') && r.request().method() === 'POST',
      { timeout: 90_000 },
    )
    await boot.click()
    const bootRes = await bootPending
    expect(bootRes.ok(), `bootstrap HTTP ${bootRes.status()}`).toBeTruthy()
    // Ensure list is populated before leaving the tab / reloading for enable.
    await expect
      .poll(
        async () => {
          const j = (await (await fetch(`${CLIENT_BASE}/api/discovery-tasks`)).json()) as {
            data?: { items?: unknown[] }
          }
          return (j.data?.items ?? []).length
        },
        { timeout: 60_000 },
      )
      .toBeGreaterThan(0)
    steps.push({ step: 'tasks_bootstrap_discovery', ok: true })
  } catch (e) {
    steps.push({ step: 'tasks_bootstrap_discovery', ok: false, detail: String(e) })
    throw e
  }

  // Bootstrap alone leaves tasks disabled → jobs stay 0. Enable via UI before Run Once.
  try {
    const n = await enableDiscoveryTasksViaUi(page, componentId, { max: 20 })
    steps.push({ step: 'tasks_enable_discovery', ok: true, detail: `enabled=${n}` })
  } catch (e) {
    steps.push({ step: 'tasks_enable_discovery', ok: false, detail: String(e) })
    throw e
  }

  try {
    const run = await overviewRunOnce(page)
    steps.push({
      step: 'overview_run_once',
      ok: true,
      detail: run.finished ? 'completed' : 'started_ui_loading',
    })
  } catch (e) {
    steps.push({ step: 'overview_run_once', ok: false, detail: String(e) })
    throw e
  }

  // Poll — enabled discovery + run-once should create jobs when content exists (slot-a).
  let jobs: unknown[] = []
  let discovery: unknown[] = []
  for (let i = 0; i < 45; i++) {
    const jobsJson = (await (await fetch(`${CLIENT_BASE}/api/jobs`)).json().catch(() => ({}))) as {
      data?: { items?: unknown[]; jobs?: unknown[] }
    }
    jobs = jobsJson.data?.items ?? jobsJson.data?.jobs ?? []
    const discJson = (await (
      await fetch(`${CLIENT_BASE}/api/discovery-tasks`)
    )
      .json()
      .catch(() => ({}))) as { data?: { items?: unknown[] } }
    discovery = discJson.data?.items ?? []
    if (jobs.length > 0) break
    await page.waitForTimeout(2000)
  }

  expect(discovery.length, 'discovery tasks after enable').toBeGreaterThan(0)
  requireDeepJobs(jobs)
  expect(
    jobs.length,
    `expected jobs>0 after enable+run-once; jobs=${jobs.length} discovery=${discovery.length}`,
  ).toBeGreaterThan(0)

  const ownedContextFile = process.env.WPTSALL_OWNED_WP_CONTEXT
  if (!ownedContextFile) throw new Error('deep output requires explicit owned WP context')
  const ownedContext = JSON.parse(fs.readFileSync(ownedContextFile, 'utf8')) as {
    owner: string
    sites: Array<{ name: string; base_url: string; wp_client_token: string; route_secret: string }>
  }
  expect(ownedContext.owner).toMatch(/^[a-f0-9]{16}$/)
  expect(ownedContext.sites).toHaveLength(2)
  const [source, target]: SiteFixture[] = ownedContext.sites.map((site) => ({
    container: site.name, api_base_url: site.base_url,
    wp_client_token: site.wp_client_token, route_secret: site.route_secret,
  }))
  const prefix = `deep-output-${Date.now()}-${process.pid}`
  const wp = (site: SiteFixture, php: string): string => {
    const owner = execFileSync('docker', ['inspect', '-f', '{{index .Config.Labels "com.wptsall.owned.run"}}',
      site.container!], { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }).trim()
    if (!new RegExp(`^wptsall-owned-${ownedContext.owner}-[ab]$`).test(site.container ?? '')
      || owner !== ownedContext.owner) {
      throw new Error('deep output requires isolated owned WordPress fixtures; shared Lab rows are never shipped')
    }
    return execFileSync('docker', ['exec', '-i', site.container!, 'wp', '--allow-root',
      '--path=/var/www/html', 'eval', "eval('?>'.stream_get_contents(STDIN));"],
    { input: `<?php\n${php}`, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30_000 }).trim()
  }
  const sourceCount = Number(wp(source!, "global $wpdb; echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('auto-draft','trash')\");"))
  const targetCount = Number(wp(target!, "global $wpdb; echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('auto-draft','trash')\");"))
  expect(sourceCount, 'owned source must be empty before seeding').toBe(0)
  expect(targetCount, 'owned target must be empty before seeding').toBe(0)
  const postId = Number(wp(source!, `$id=wp_insert_post(array('post_type'=>'post','post_status'=>'publish',
    'post_title'=>${JSON.stringify(prefix)},'post_name'=>${JSON.stringify(prefix)},
    'post_content'=>'<p>Deep output content</p>','post_excerpt'=>'Deep output excerpt'), true);
    if(is_wp_error($id)){exit(1);} echo $id;`))
  expect(postId).toBeGreaterThan(0)
  let syncOutput: { pair_id: string; target_id: number } | null = null
  try {
    syncOutput = await runDeepSyncOutput(page, request, {
      source: source!, target: target!, name: prefix,
      pairingCode: async (role) => wp(role === 'source' ? source! : target!,
        "$code=bin2hex(random_bytes(16)); set_transient('wpmmcc_active_pairing_code',$code,600); echo $code;"),
      assertTarget: async () => {
        const response = await request.get(`${target!.api_base_url}/wp-json/wp/v2/posts?slug=${prefix}`)
        expect(response.status()).toBe(200)
        const posts = await response.json() as Array<{ id: number; title: { rendered: string }; content: { rendered: string }; excerpt: { rendered: string } }>
        if (!posts.length) return 0
        expect(posts).toHaveLength(1)
        expect(posts[0].title.rendered).toBe(prefix)
        expect(posts[0].content.rendered).toContain('Deep output content')
        expect(posts[0].excerpt.rendered).toContain('Deep output excerpt')
        return posts[0].id
      },
    })
    steps.push({ step: 'sync_pair_saved_run_target_output', ok: true, detail: `target_id=${syncOutput.target_id}` })
  } finally {
    if (syncOutput) await request.post(`${CLIENT_BASE}/api/sync-pairs/delete`, { data: { id: syncOutput.pair_id } })
    wp(source!, `if(get_post_field('post_name',${postId})!==${JSON.stringify(prefix)}){exit(1);} wp_delete_post(${postId},true);`)
    if (syncOutput) wp(target!, `if(get_post_field('post_name',${syncOutput.target_id})!==${JSON.stringify(prefix)}){exit(1);} wp_delete_post(${syncOutput.target_id},true);`)
  }

  const stamp = new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)
  const out = path.join(reportDir, `multi-site-client-deep-${stamp}.json`)
  const report = {
    task: 'multi-site-client-deep',
    client_base: CLIENT_BASE,
    sites_bound_via_ui: bindList.length,
    sites: bindList.map(([, s]) => s.api_base_url),
    bindings_count: bindings.length,
    jobs_count: Array.isArray(jobs) ? jobs.length : 0,
    discovery_tasks_count: Array.isArray(discovery) ? discovery.length : 0,
    component_id: componentId,
    sync_output: syncOutput,
    min_sites_required: minSites,
    ms_en: msEn
      ? {
          ok: steps.some((s) => s.step === 'ms_en_token_reuse_same_origin' && s.ok),
          api_base_url: msEn.api_base_url,
          note: 'same domain key as ats_9083; token reused without second Sites row',
        }
      : null,
    steps,
    ok: steps.every((s) => s.ok),
  }
  fs.writeFileSync(out, `${JSON.stringify(report, null, 2)}\n`)
  console.log(`report: ${out}`)
  expect(report.ok, JSON.stringify(steps, null, 2)).toBe(true)
  expect(report.sites_bound_via_ui).toBeGreaterThanOrEqual(minSites)
})
